<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Service layer for the MAAC page and MAAC summary cards.
 */
class maac_service {
    private $cached_role_course_ids = [];

    /**
     * Return full MAAC page data for a course.
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public function get_course_data(int $courseid, int $userid): array {
        $dataset = $this->build_course_dataset($courseid, $userid, 'maac');
        $canedit = $this->can_edit_maac_data($courseid, $userid);
        $canupdatetickets = $this->can_manage_tickets_for_course($courseid, $userid);
        return [
            'course' => $dataset['course'],
            'columns' => $dataset['columns'],
            'column_groups' => $dataset['column_groups'],
            'builtin_columns' => $dataset['builtin_columns'],
            'course_groups' => $dataset['course_groups'],
            'moodle_groups' => $dataset['moodle_groups'],
            'module_columns' => $dataset['module_columns'],
            'ticket_meta' => $dataset['ticket_meta'],
            'summary' => $dataset['summary'],
            'students' => $dataset['students'],
            'canedit' => $canedit,
            'canraise' => $this->can_raise_tickets_for_course($courseid, $userid),
            'canupdatetickets' => $canupdatetickets,
        ];
    }

    /**
     * Return only the summary payload used by the course tab card.
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public function get_course_summary(int $courseid, int $userid): array {
        $dataset = $this->build_course_dataset($courseid, $userid, 'summary');
        return [
            'course' => $dataset['course'],
            'summary' => $dataset['summary'],
            'columns' => $dataset['columns'],
            'column_groups' => $dataset['column_groups'],
            'builtin_columns' => $dataset['builtin_columns'],
            'course_groups' => $dataset['course_groups'],
        ];
    }

    /**
     * Save custom MAAC values for the provided course.
     *
     * @param int $courseid
     * @param int $userid
     * @param array $rows
     * @return void
     */
    public function save_course_data(int $courseid, int $userid, array $rows): void {
        global $DB;

        if (!$this->can_edit_maac_data($courseid, $userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'edit MAAC data');
        }

        $dataset = $this->build_course_dataset($courseid, $userid);
        $alloweduserids = array_column($dataset['students'], 'userid');
        $alloweduserids = array_fill_keys($alloweduserids, true);

        $columns = [];
        foreach (maac_columns_helper::get_columns() as $column) {
            $columns[$column['key']] = $column;
        }

        if (empty($rows) || empty($columns)) {
            return;
        }

        $existingrecords = [];
        if (!empty($alloweduserids)) {
            list($userinsql, $userparams) = $DB->get_in_or_equal(array_keys($alloweduserids), SQL_PARAMS_NAMED, 'maacuser');
            $sql = "SELECT id, userid, fieldkey
                      FROM {local_batchanalytics_maac}
                     WHERE courseid = :courseid
                       AND userid $userinsql";
            $params = ['courseid' => $courseid] + $userparams;
            $recordset = $DB->get_recordset_sql($sql, $params);
            foreach ($recordset as $record) {
                $existingrecords[(int)$record->userid][$record->fieldkey] = (int)$record->id;
            }
            $recordset->close();
        }

        $transaction = $DB->start_delegated_transaction();
        foreach ($rows as $row) {
            $targetuserid = (int)($row['userid'] ?? 0);
            if ($targetuserid <= 0 || !isset($alloweduserids[$targetuserid])) {
                continue;
            }

            $values = $row['values'] ?? [];
            if (!is_array($values)) {
                continue;
            }

            foreach ($columns as $fieldkey => $column) {
                if (!array_key_exists($fieldkey, $values)) {
                    continue;
                }

                $storedvalue = $this->normalise_value_for_storage($values[$fieldkey], $column);
                $existingid = (int)($existingrecords[$targetuserid][$fieldkey] ?? 0);

                if ($storedvalue === null) {
                    if ($existingid > 0) {
                        $DB->delete_records('local_batchanalytics_maac', ['id' => $existingid]);
                        unset($existingrecords[$targetuserid][$fieldkey]);
                    }
                    continue;
                }

                $record = (object)[
                    'courseid' => $courseid,
                    'userid' => $targetuserid,
                    'fieldkey' => $fieldkey,
                    'value' => $storedvalue,
                    'timemodified' => time(),
                ];

                if ($existingid > 0) {
                    $record->id = $existingid;
                    $DB->update_record('local_batchanalytics_maac', $record);
                } else {
                    $existingrecords[$targetuserid][$fieldkey] = (int)$DB->insert_record('local_batchanalytics_maac', $record);
                }
            }
        }
        $transaction->allow_commit();
    }

    /**
     * Save a raised MAAC ticket for a student.
     *
     * @param int $courseid
     * @param int $userid
     * @param array $payload
     * @return array
     */
    public function save_ticket(int $courseid, int $userid, array $payload): array {
        global $DB;

        if (!$this->can_raise_tickets_for_course($courseid, $userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'raise tickets');
        }

        $dataset = $this->build_course_dataset($courseid, $userid);
        $studentuserid = (int)($payload['studentuserid'] ?? 0);
        $studentsbyid = [];
        foreach ($dataset['students'] as $student) {
            $studentsbyid[(int)$student['userid']] = $student;
        }

        if ($studentuserid <= 0 || !isset($studentsbyid[$studentuserid])) {
            throw new \moodle_exception('ticket_invalid_student', 'local_batchanalytics');
        }

        $ticketmeta = $dataset['ticket_meta'] ?? [];
        $ssteamroleid = (int)($ticketmeta['ss_team_role']['id'] ?? 0);
        if ($ssteamroleid <= 0) {
            throw new \moodle_exception('ticket_role_not_configured', 'local_batchanalytics');
        }

        $ssteamusers = array_fill_keys(array_map('intval', array_column($ticketmeta['ss_team_users'] ?? [], 'id')), true);
        if (empty($ssteamusers)) {
            throw new \moodle_exception('ticket_assignees_missing', 'local_batchanalytics');
        }

        $ssteamuserid = (int)($payload['ssteamuserid'] ?? 0);
        if ($ssteamuserid <= 0) {
            $ssteamuserid = (int)array_key_first($ssteamusers);
        }
        if (!isset($ssteamusers[$ssteamuserid])) {
            throw new \moodle_exception('ticket_assignees_missing', 'local_batchanalytics');
        }

        $tickettitle = clean_param(trim((string)($payload['tickettitle'] ?? '')), PARAM_TEXT);
        if ($tickettitle === '') {
            throw new \moodle_exception('required', 'error', '', get_string('ticket_title', 'local_batchanalytics'));
        }

        $ticketreason = clean_param(trim((string)($payload['ticketreason'] ?? '')), PARAM_TEXT);
        if ($ticketreason === '') {
            throw new \moodle_exception('required', 'error', '', get_string('ticket_reason', 'local_batchanalytics'));
        }

        $now = time();
        $record = (object)[
            'courseid' => $courseid,
            'studentuserid' => $studentuserid,
            'ssteamroleid' => $ssteamroleid,
            'ssteamuserid' => $ssteamuserid,
            'batchmanagerroleid' => 0,
            'batchmanageruserid' => 0,
            'tickettitle' => $tickettitle,
            'ticketreason' => $ticketreason,
            'status' => 'open',
            'priority' => 'low',
            'createdby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $ticketid = (int)$DB->insert_record('local_batchanalytics_ticket', $record);
        $this->log_ticket_event($ticketid, $userid, 'created', [
            'tostatus' => 'open',
            'topriority' => 'low',
        ]);
        $ticketcount = (int)$DB->count_records('local_batchanalytics_ticket', [
            'courseid' => $courseid,
            'studentuserid' => $studentuserid,
        ]);

        $userfrom = null;
        $ssteamuser = null;
        try {
            $userfrom = \core_user::get_user($userid);
            $ssteamuser = \core_user::get_user($ssteamuserid);
            
            $msg = new \core\message\message();
            $msg->component = 'local_batchanalytics';
            $msg->name = 'ticket_update';
            $msg->userfrom = $userfrom;
            $msg->subject = "New Ticket Raised: " . format_string($tickettitle);
            $msg->fullmessage = "A new ticket has been raised.\n\nTitle: " . $tickettitle . "\nReason: " . $ticketreason;
            $msg->fullmessageformat = FORMAT_PLAIN;
            $msg->fullmessagehtml = "<p>A new ticket has been raised.</p><p><strong>Title:</strong> " . s($tickettitle) . "</p><p><strong>Reason:</strong> " . s($ticketreason) . "</p>";
            $msg->smallmessage = "New ticket raised: " . $tickettitle;
            
            if ($ssteamuser && $ssteamuser->id != $userid) {
                $msg->userto = $ssteamuser;
                message_send($msg);
            }
        } catch (\Throwable $e) {
            // Ignore messaging errors
        }

        $record = (object)[
            'id' => $ticketid,
            'courseid' => $courseid,
            'studentuserid' => $studentuserid,
            'tickettitle' => $tickettitle,
            'ticketreason' => $ticketreason,
            'status' => 'open',
            'priority' => 'low',
            'resolutionfeedback' => '',
            'createdby' => $userid,
            'resolvedby' => 0,
            'ssteamuserid' => $ssteamuserid,
            'timemodified' => $now,
            'timeresolved' => 0,
            'timecreated' => $now,
            'ssteamfullname' => $ssteamuser ? fullname($ssteamuser) : '',
            'batchmanagerfullname' => '',
            'resolvedbyfullname' => '',
        ];

        return [
            'ticketid' => $ticketid,
            'studentuserid' => $studentuserid,
            'ticketcount' => $ticketcount,
            'ticket' => $this->format_maac_ticket_record($record, $userid, [[
                'eventtype' => 'created',
                'title' => 'Ticket created',
                'actorname' => $userfrom ? fullname($userfrom) : '-',
                'details' => ['Status: Open', 'Priority: Low'],
                'timecreated' => $now,
            ]]),
        ];
    }

    /**
     * Edit a newly raised MAAC ticket title/reason inside the allowed creator window.
     *
     * @param int $courseid
     * @param int $userid
     * @param array $payload
     * @return array
     */
    public function edit_maac_ticket(int $courseid, int $userid, array $payload): array {
        global $DB;

        $ticketid = (int)($payload['ticketid'] ?? 0);
        $ticket = $DB->get_record('local_batchanalytics_ticket', ['id' => $ticketid, 'courseid' => $courseid]);
        if (!$ticket) {
            throw new \moodle_exception('ticket_invalid', 'local_batchanalytics');
        }

        if (!$this->can_edit_own_ticket_from_maac($ticket, $userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'edit ticket');
        }

        $tickettitle = clean_param(trim((string)($payload['tickettitle'] ?? '')), PARAM_TEXT);
        $ticketreason = clean_param(trim((string)($payload['ticketreason'] ?? '')), PARAM_TEXT);
        if ($tickettitle === '' || $ticketreason === '') {
            throw new \moodle_exception('required', 'error');
        }

        $ticket->tickettitle = $tickettitle;
        $ticket->ticketreason = $ticketreason;
        $ticket->timemodified = time();
        $DB->update_record('local_batchanalytics_ticket', $ticket);
        $this->log_ticket_event($ticketid, $userid, 'edited', [
            'feedback' => 'Ticket title/reason edited by creator',
        ]);

        $ticket->ssteamfullname = '';
        $ticket->batchmanagerfullname = '';
        $ticket->resolvedbyfullname = '';
        $timeline = $this->get_ticket_timeline_events([$ticketid]);

        return [
            'ticketid' => $ticketid,
            'ticket' => $this->format_maac_ticket_record($ticket, $userid, $timeline[$ticketid] ?? []),
        ];
    }

    /**
     * Mark an opened dashboard ticket as in progress when the assigned SS Team user views it.
     *
     * @param int $ticketid
     * @param int $userid
     * @return array
     */
    public function mark_ticket_viewed(int $ticketid, int $userid): array {
        global $DB;

        $ticket = $DB->get_record('local_batchanalytics_ticket', ['id' => $ticketid]);
        if (!$ticket) {
            throw new \moodle_exception('ticket_invalid', 'local_batchanalytics');
        }

        $changed = false;
        if (((int)$ticket->ssteamuserid === $userid || $this->can_manage_tickets_for_course((int)$ticket->courseid, $userid)) && $this->normalise_ticket_status((string)$ticket->status) === 'open') {
            $ticket->status = 'in_progress';
            $ticket->timemodified = time();
            $DB->update_record('local_batchanalytics_ticket', $ticket);
            $this->log_ticket_event($ticketid, $userid, 'updated', [
                'fromstatus' => 'open',
                'tostatus' => 'in_progress',
            ]);
            $changed = true;
        }

        return [
            'ticketid' => $ticketid,
            'changed' => $changed,
            'status' => $this->get_ticket_status_label($this->normalise_ticket_status((string)$ticket->status)),
            'statuskey' => $this->normalise_ticket_status((string)$ticket->status),
            'timemodified' => (int)$ticket->timemodified,
        ];
    }

    /**
     * Mark an opened ticket as in progress when the assigned SS Team user views it.
     *
     * @param int $courseid
     * @param int $userid
     * @param array $payload
     * @return array
     */
    public function mark_maac_ticket_viewed(int $courseid, int $userid, array $payload): array {
        global $DB;

        $ticketid = (int)($payload['ticketid'] ?? 0);
        $ticket = $DB->get_record('local_batchanalytics_ticket', ['id' => $ticketid, 'courseid' => $courseid]);
        if (!$ticket) {
            throw new \moodle_exception('ticket_invalid', 'local_batchanalytics');
        }

        $changed = false;
        if (((int)$ticket->ssteamuserid === $userid || $this->can_manage_tickets_for_course((int)$ticket->courseid, $userid)) && $this->normalise_ticket_status((string)$ticket->status) === 'open') {
            $ticket->status = 'in_progress';
            $ticket->timemodified = time();
            $DB->update_record('local_batchanalytics_ticket', $ticket);
            $this->log_ticket_event($ticketid, $userid, 'updated', [
                'fromstatus' => 'open',
                'tostatus' => 'in_progress',
            ]);
            $changed = true;
        }

        $ticket->ssteamfullname = '';
        $ticket->batchmanagerfullname = '';
        $ticket->resolvedbyfullname = '';
        $timeline = $this->get_ticket_timeline_events([$ticketid]);

        return [
            'ticketid' => $ticketid,
            'changed' => $changed,
            'ticket' => $this->format_maac_ticket_record($ticket, $userid, $timeline[$ticketid] ?? []),
        ];
    }

    /**
     * Return ticket dashboard data grouped into page tabs.
     *
     * @param int $userid
     * @return array
     */
    public function get_ticket_dashboard_data(int $userid): array {
        $access = $this->build_ticket_access_scope($userid);
        $records = $this->get_ticket_dashboard_records($userid, $access);
        $formatted = [];
        foreach ($records as $record) {
            $formatted[] = $this->format_ticket_dashboard_record($record, $userid, $access);
        }

        $timelinebyticket = $this->get_ticket_timeline_events(array_column($formatted, 'id'));
        $dashboard = [];
        $mynew = [];
        $myresolved = [];

        foreach ($formatted as $ticket) {
            $ticket['timeline'] = $timelinebyticket[$ticket['id']] ?? ($ticket['timeline'] ?? []);
            $dashboard[] = $ticket;

            if ($ticket['statuskey'] !== 'resolved') {
                $mynew[] = $ticket;
            }

            if ($ticket['statuskey'] === 'resolved') {
                $myresolved[] = $ticket;
            }
        }

        return [
            'dashboard' => $dashboard,
            'mynew' => $mynew,
            'myresolved' => $myresolved,
            'access' => [
                'rolekey' => $access['rolekey'],
                'rolelabel' => $access['rolelabel'],
                'scopekey' => $access['scopekey'],
                'scopelabel' => $access['scopelabel'],
                'canmanage' => $access['canmanage'],
                'canresolve' => $access['canresolve'],
                'canupdatetickets' => $access['canresolve'],
            ],
        ];
    }

    /**
     * Resolve an existing ticket and persist feedback.
     *
     * @param int $ticketid
     * @param int $userid
     * @param string $feedback
     * @return array
     */
    public function update_ticket(int $ticketid, int $userid, array $payload): array {
        global $DB;

        $ticket = $DB->get_record('local_batchanalytics_ticket', ['id' => $ticketid]);
        if (!$ticket) {
            throw new \moodle_exception('ticket_invalid', 'local_batchanalytics');
        }

        if (!$this->can_update_ticket_record($ticket, $userid)) {
            throw new \moodle_exception('ticket_resolve_not_allowed', 'local_batchanalytics');
        }

        if ($this->normalise_ticket_status((string)$ticket->status) === 'resolved') {
            throw new \moodle_exception('ticket_already_resolved', 'local_batchanalytics');
        }

        $cleanfeedback = clean_param(trim((string)($payload['feedback'] ?? '')), PARAM_TEXT);
        if ($cleanfeedback === '') {
            throw new \moodle_exception('ticket_feedback_required', 'local_batchanalytics');
        }

        $previousstatuskey = $this->normalise_ticket_status((string)$ticket->status);
        $previousprioritykey = $this->normalise_ticket_priority((string)($ticket->priority ?? 'low'));
        $previousfeedback = trim((string)($ticket->resolutionfeedback ?? ''));
        $statuskey = $this->normalise_ticket_status((string)($payload['status'] ?? $ticket->status));
        $prioritykey = $this->normalise_ticket_priority((string)($payload['priority'] ?? ($ticket->priority ?? 'low')));
        $now = time();
        $ticket->status = $statuskey;
        $ticket->priority = $prioritykey;
        $ticket->resolutionfeedback = $cleanfeedback;
        if ($statuskey === 'resolved') {
            $ticket->resolvedby = $userid;
            $ticket->timeresolved = $now;
        } else {
            $ticket->resolvedby = 0;
            $ticket->timeresolved = 0;
        }
        $ticket->timemodified = $now;
        $DB->update_record('local_batchanalytics_ticket', $ticket);

        $eventtype = 'updated';
        if ($previousstatuskey !== 'resolved' && $statuskey === 'resolved') {
            $eventtype = 'resolved';
        } else if ($previousstatuskey === 'resolved' && $statuskey !== 'resolved') {
            $eventtype = 'reopened';
        }
        if (
            $previousstatuskey !== $statuskey ||
            $previousprioritykey !== $prioritykey ||
            $previousfeedback !== $cleanfeedback
        ) {
            $this->log_ticket_event($ticketid, $userid, $eventtype, [
                'fromstatus' => $previousstatuskey,
                'tostatus' => $statuskey,
                'frompriority' => $previousprioritykey,
                'topriority' => $prioritykey,
                'feedback' => $cleanfeedback,
            ]);

            if ($ticket->createdby && $ticket->createdby != $userid) {
                try {
                    $userfrom = \core_user::get_user($userid);
                    $userto = \core_user::get_user($ticket->createdby);
                    
                    if ($userfrom && $userto) {
                        $msg = new \core\message\message();
                        $msg->component = 'local_batchanalytics';
                        $msg->name = 'ticket_update';
                        $msg->userfrom = $userfrom;
                        $msg->userto = $userto;
                        $msg->subject = "Ticket Updated: " . format_string($ticket->tickettitle);
                        $msg->fullmessage = "Your ticket has been updated.\n\nStatus: " . $statuskey . "\nFeedback: " . $cleanfeedback;
                        $msg->fullmessageformat = FORMAT_PLAIN;
                        $msg->fullmessagehtml = "<p>Your ticket has been updated.</p><p><strong>Status:</strong> " . s($statuskey) . "</p><p><strong>Feedback:</strong> " . s($cleanfeedback) . "</p>";
                        $msg->smallmessage = "Ticket updated: " . $ticket->tickettitle;
                        message_send($msg);
                    }
                } catch (\Throwable $e) {
                    // Ignore messaging errors
                }
            }
        }

        return [
            'ticketid' => (int)$ticket->id,
            'status' => $statuskey,
            'priority' => $prioritykey,
        ];
    }

    /**
     * Backward-compatible wrapper for the older resolve endpoint.
     *
     * @param int $ticketid
     * @param int $userid
     * @param string $feedback
     * @return array
     */
    public function resolve_ticket(int $ticketid, int $userid, string $feedback): array {
        return $this->update_ticket($ticketid, $userid, [
            'status' => 'resolved',
            'priority' => 'low',
            'feedback' => $feedback,
        ]);
    }

    /**
     * Build the common course dataset.
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    private function build_course_dataset(int $courseid, int $userid, string $surface = 'maac'): array {
        global $DB;

        $moodledata = new moodledata();
        $course = $moodledata->get_accessible_course($courseid, $userid);
        if (!$course || !$this->can_view_maac_data($courseid, $userid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'MAAC course');
        }

        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $studentroleid = $studentrole ? (int)$studentrole->id : 5;

        $studentssql = "
            SELECT DISTINCT
                u.id AS userid,
                CONCAT(u.firstname, ' ', u.lastname) AS fullname,
                u.username,
                u.email
            FROM {user} u
            JOIN {role_assignments} ra ON ra.userid = u.id
            JOIN {context} ctx ON ctx.id = ra.contextid
            WHERE ctx.instanceid = :courseid
              AND ctx.contextlevel = 50
              AND ra.roleid = :roleid
              AND u.deleted = 0
            ORDER BY u.firstname, u.lastname
        ";
        $students = $DB->get_records_sql($studentssql, ['courseid' => $courseid, 'roleid' => $studentroleid]);
        $studentids = array_keys($students);

        $moodlegroups = [];
        $studentgroups = [];
        if (!empty($studentids)) {
            list($groupsinsql, $groupsparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED);
            $groupsparams['courseid'] = $courseid;
            $groupsql = "
                SELECT g.id, g.name, gm.userid
                  FROM {groups} g
                  JOIN {groups_members} gm ON gm.groupid = g.id
                 WHERE g.courseid = :courseid
                   AND gm.userid $groupsinsql
                 ORDER BY g.name, gm.userid
            ";
            $grouprecords = $DB->get_recordset_sql($groupsql, $groupsparams);
            foreach ($grouprecords as $record) {
                if (!isset($moodlegroups[$record->id])) {
                    $moodlegroups[$record->id] = [
                        'id' => (int)$record->id,
                        'name' => $record->name,
                    ];
                }

                if (!isset($studentgroups[$record->userid])) {
                    $studentgroups[$record->userid] = [];
                }

                $studentgroups[$record->userid][] = [
                    'id' => (int)$record->id,
                    'name' => $record->name,
                ];
            }
            $grouprecords->close();
        }

        $itemssql = "
            SELECT
                gi.id AS itemid,
                gi.itemtype,
                gi.grademin,
                gi.grademax,
                gc.id AS categoryid,
                gc.fullname AS categoryname
            FROM {grade_items} gi
            LEFT JOIN {grade_categories} gc ON gc.id = gi.categoryid
            WHERE gi.courseid = :courseid
              AND (gi.itemtype IN ('mod', 'manual') OR gi.itemtype = 'course')
            ORDER BY gc.fullname
        ";
        $gradeitems = $DB->get_records_sql($itemssql, ['courseid' => $courseid]);
        $allcategories = $DB->get_records('grade_categories', ['courseid' => $courseid]);

        $categories = [];
        $modulecolumns = [];
        foreach ($gradeitems as $item) {
            if ($item->itemtype === 'course') {
                $categoryname = 'MAAC Ratings';
            } else {
                $categoryname = 'Uncategorized';
                if ($item->categoryid && isset($allcategories[$item->categoryid])) {
                    $category = $allcategories[$item->categoryid];
                    while ($category->depth > 2 && !empty($category->parent) && isset($allcategories[$category->parent])) {
                        $category = $allcategories[$category->parent];
                    }
                    if ($category->depth == 2) {
                        $categoryname = $category->fullname;
                    }
                }
            }

            if (trim($categoryname) === '' || $categoryname === '?' || $categoryname === 'Uncategorized') {
                continue;
            }

            if (!isset($categories[$categoryname])) {
                $modulekey = $categoryname === 'MAAC Ratings'
                    ? 'maac_rating'
                    : 'module_' . substr(md5($categoryname), 0, 8);
                $categories[$categoryname] = [
                    'key' => $modulekey,
                    'items' => [],
                ];
                if ($categoryname !== 'MAAC Ratings') {
                    $modulecolumns[] = [
                        'key' => $modulekey,
                        'label' => $categoryname,
                        'isattendance' => stripos($categoryname, 'attend') !== false,
                    ];
                }
            }

            $categories[$categoryname]['items'][] = [
                'itemid' => (int)$item->itemid,
                'grademin' => (float)$item->grademin,
                'grademax' => (float)$item->grademax,
            ];
        }

        $gradesmap = [];
        $gradesql = "
            SELECT gg.userid, gg.itemid, gg.finalgrade
            FROM {grade_grades} gg
            JOIN {grade_items} gi ON gi.id = gg.itemid
            WHERE gi.courseid = :courseid
              AND gg.finalgrade IS NOT NULL
        ";
        $recordset = $DB->get_recordset_sql($gradesql, ['courseid' => $courseid]);
        foreach ($recordset as $record) {
            $gradesmap[$record->userid][$record->itemid] = (float)$record->finalgrade;
        }
        $recordset->close();

        $allcolumns = maac_columns_helper::get_settings_rows();
        $builtincolumns = maac_columns_helper::get_builtin_columns($surface);
        $columngroups = maac_column_groups_helper::get_resolved_groups($allcolumns, $surface);
        $columns = [];
        foreach ($columngroups as $group) {
            foreach (($group['columns'] ?? []) as $column) {
                $columns[$column['key']] = $column;
            }
        }
        $hastrendcolumn = isset($columns['trend']);
        $columns = array_values($columns);
        $customvalues = $this->get_custom_values($courseid, array_keys($students));
        foreach ($this->get_spot_award_nomination_values($courseid, array_keys($students)) as $studentid => $value) {
            $customvalues[$studentid]['spot_awards_nomination'] = $value;
        }

        $trenddetailsbyuser = [];
        if ($surface !== 'summary' || $hastrendcolumn) {
            $trenddetailsbyuser = $this->get_course_trend_values($courseid, array_keys($students));
            foreach ($trenddetailsbyuser as $studentid => $trendvalue) {
                $customvalues[$studentid]['trend'] = $trendvalue['label'] ?? 'Stable';
            }
        }

        if ($surface !== 'summary') {
            $ticketmeta = $this->build_ticket_meta($courseid);
            $ticketcounts = $this->get_ticket_counts($courseid, array_keys($students));
            $studenttickets = $this->get_tickets_by_student($courseid, array_keys($students), $userid);
        } else {
            $ticketmeta = [];
            $ticketcounts = [];
            $studenttickets = [];
        }

        $outputstudents = [];
        $maacsum = 0.0;
        $maaccount = 0;
        $performancesum = 0.0;
        $performancecount = 0;
        foreach ($students as $student) {
            $performancevalues = [];
            $maacrating = null;

            foreach ($categories as $categoryname => $categorydata) {
                $totalearned = 0.0;
                $totalmax = 0.0;

                foreach ($categorydata['items'] as $item) {
                    $itemid = $item['itemid'];
                    if (!isset($gradesmap[$student->userid][$itemid])) {
                        continue;
                    }

                    $totalearned += $gradesmap[$student->userid][$itemid];
                    if ($item['grademax'] > 0) {
                        $totalmax += $item['grademax'];
                    }
                }

                $percentage = $totalmax > 0 ? round(($totalearned / $totalmax) * 100, 2) : null;
                if ($categoryname === 'MAAC Ratings' && $percentage !== null) {
                    $maacrating = round($percentage / 10, 1);
                    continue;
                }

                if ($percentage !== null) {
                    $performancevalues[] = $percentage;
                }
            }

            $performancerating = !empty($performancevalues)
                ? round(array_sum($performancevalues) / count($performancevalues), 2)
                : 0.0;

            $studentcustom = $customvalues[$student->userid] ?? [];
            $adjustedmaacrating = $this->apply_maac_exclusions($maacrating ?? 0.0, $studentcustom, $allcolumns);
            $studentrow = [
                'userid' => (int)$student->userid,
                'fullname' => $student->fullname,
                'username' => $student->username,
                'email' => $student->email,
                'performance_rating' => $performancerating,
                'maac_rating' => $adjustedmaacrating,
                'groups' => $studentgroups[$student->userid] ?? [],
                'modules' => [],
                'custom' => [],
                'trend_details' => $trenddetailsbyuser[$student->userid]['details'] ?? [
                    'assignments' => [],
                    'quizzes' => [],
                    'projects' => [],
                    'attendance' => [],
                    'overall_trend' => 'stable',
                    'trend_window' => 20,
                    'attendance_window' => 5,
                ],
                'ticket_count' => (int)($ticketcounts[$student->userid] ?? 0),
                'tickets' => $studenttickets[$student->userid] ?? [],
            ];

            foreach ($categories as $categoryname => $categorydata) {
                if ($categoryname === 'MAAC Ratings') {
                    continue;
                }

                $modulepercentage = null;
                $modulecompletion = 0.0;
                $totalearned = 0.0;
                $totalmax = 0.0;
                $itemscompleted = 0;
                $totalitems = count($categorydata['items']);
                foreach ($categorydata['items'] as $item) {
                    $itemid = $item['itemid'];
                    if (!isset($gradesmap[$student->userid][$itemid])) {
                        continue;
                    }

                    $totalearned += $gradesmap[$student->userid][$itemid];
                    $itemscompleted++;
                    if ($item['grademax'] > 0) {
                        $totalmax += $item['grademax'];
                    }
                }
                if ($totalmax > 0) {
                    $modulepercentage = round(($totalearned / $totalmax) * 100, 2);
                }
                if ($totalitems > 0) {
                    $modulecompletion = round(($itemscompleted / $totalitems) * 100, 2);
                }
                $studentrow['modules'][$categorydata['key']] = [
                    'grade' => $modulepercentage,
                    'completion' => $modulecompletion,
                ];
            }

            foreach ($allcolumns as $column) {
                $value = $studentcustom[$column['key']] ?? '';
                $formattedvalue = $this->format_value_for_output($value, $column);
                $studentrow['custom'][$column['key']] = $formattedvalue;
            }

            $maacsum += (float)$studentrow['maac_rating'];
            $maaccount++;
            $performancesum += (float)$studentrow['performance_rating'];
            $performancecount++;
            $outputstudents[] = $studentrow;
        }

        $coursegroups = $this->build_course_groups($columngroups, $outputstudents);
        $summary = [
            'avg_maac_rating' => $maaccount ? round($maacsum / $maaccount, 2) : 0,
            'avg_performance_rating' => $performancecount ? round($performancesum / $performancecount, 2) : 0,
            'groups' => $coursegroups,
        ];

        return [
            'course' => [
                'id' => (int)$course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
            ],
            'columns' => $columns,
            'column_groups' => $columngroups,
            'builtin_columns' => $builtincolumns,
            'course_groups' => $coursegroups,
            'moodle_groups' => array_values($moodlegroups),
            'module_columns' => $modulecolumns,
            'ticket_meta' => $ticketmeta,
            'summary' => $summary,
            'students' => $outputstudents,
        ];
    }

    /**
     * Apply configured custom-number exclusions to the computed MAAC rating.
     *
     * @param float $maacrating
     * @param array $studentcustom
     * @param array $columns
     * @return float
     */
    private function apply_maac_exclusions(float $maacrating, array $studentcustom, array $columns): float {
        $adjusted = $maacrating;

        foreach ($columns as $column) {
            if (
                ($column['type'] ?? '') !== 'number' ||
                empty($column['excludefrommaac']) ||
                !array_key_exists($column['key'], $studentcustom)
            ) {
                continue;
            }

            $rawvalue = $studentcustom[$column['key']];
            if (is_array($rawvalue) || $rawvalue === '' || $rawvalue === null || !is_numeric($rawvalue)) {
                continue;
            }

            $adjusted -= (float)$rawvalue;
        }

        return round(max(0, min(10, $adjusted)), 2);
    }

    /**
     * Build course-level trend labels for students using the student dashboard logic.
     *
     * @param int $courseid
     * @param array $userids
     * @return array
     */
    private function get_course_trend_values(int $courseid, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        $settings = $this->get_trend_settings();
        $trendwindow = $settings['trend_window'];
        $attendancewindow = max(2, min($settings['attendance_window'], $trendwindow));
        $projectkeywords = ['project', 'mini project', 'final project', 'capstone', 'term project', 'group project'];

        $data = [];
        foreach ($userids as $userid) {
            $data[(int)$userid] = [
                'assignments' => [],
                'projects' => [],
                'quizzes' => [],
                'attendance_history' => [],
            ];
        }

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'trenduser');
        $params['courseid'] = $courseid;

        $sql = "SELECT gg.userid,
                       gg.finalgrade,
                       gg.timemodified,
                       gg.timecreated,
                       gi.grademin,
                       gi.grademax,
                       gi.itemname AS activityname,
                       gi.itemmodule,
                       gc.fullname AS categoryname
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gg.itemid = gi.id
             LEFT JOIN {grade_categories} gc ON gi.categoryid = gc.id
                 WHERE gg.userid $insql
                   AND gi.courseid = :courseid
                   AND gi.itemtype = 'mod'
                   AND gg.finalgrade IS NOT NULL
              ORDER BY COALESCE(gg.timemodified, gg.timecreated) ASC";
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            $userid = (int)$record->userid;
            $name = trim((string)($record->activityname ?? ''));
            if ($userid <= 0 || $name === '' || !isset($data[$userid])) {
                continue;
            }

            $date = !empty($record->timemodified) ? (int)$record->timemodified : (int)$record->timecreated;
            if ($date <= 0) {
                continue;
            }

            $activity = [
                'grade' => $this->normalise_grade_percentage(
                    (float)$record->finalgrade,
                    (float)$record->grademin,
                    (float)$record->grademax
                ),
                'date' => $date,
                'name' => $name,
            ];

            if ($record->itemmodule === 'quiz') {
                $data[$userid]['quizzes'][] = $activity;
                continue;
            }

            $namelower = strtolower($name);
            $categorylower = strtolower(trim((string)($record->categoryname ?? '')));
            $isproject = strpos($categorylower, 'project') !== false;
            if (!$isproject) {
                foreach ($projectkeywords as $keyword) {
                    if (strpos($namelower, $keyword) !== false) {
                        $isproject = true;
                        break;
                    }
                }
            }

            if ($isproject) {
                $data[$userid]['projects'][] = $activity;
            } else {
                $data[$userid]['assignments'][] = $activity;
            }
        }
        $records->close();

        if ($DB->get_manager()->table_exists('attendance')) {
            list($attendanceinsql, $attendanceparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'atttrenduser');
            $attendanceparams['atttrendcourseid'] = $courseid;
            $attendanceparams['atttrendcurrenttime'] = time();
            $attendancesql = "SELECT asess.id AS sessionid,
                                     asess.sessdate,
                                     al.studentid,
                                     al.id AS logid,
                                     astatus.grade,
                                     astatus.acronym
                                FROM {attendance_sessions} asess
                                JOIN {attendance} att ON att.id = asess.attendanceid
                           LEFT JOIN {attendance_log} al
                                  ON al.sessionid = asess.id
                                 AND al.studentid $attendanceinsql
                           LEFT JOIN {attendance_statuses} astatus ON astatus.id = al.statusid
                               WHERE att.course = :atttrendcourseid
                                 AND asess.sessdate < :atttrendcurrenttime
                            ORDER BY asess.sessdate ASC, al.studentid ASC, al.id DESC";
            $records = $DB->get_recordset_sql($attendancesql, $attendanceparams);
            $seenattendance = [];
            foreach ($records as $record) {
                $userid = (int)($record->studentid ?? 0);
                $sessionid = (int)$record->sessionid;
                if ($userid <= 0 || $sessionid <= 0 || !isset($data[$userid])) {
                    continue;
                }

                $key = $sessionid . ':' . $userid;
                if (isset($seenattendance[$key])) {
                    continue;
                }
                $seenattendance[$key] = true;

                if (empty($record->logid)) {
                    continue;
                }

                $attendancegrade = isset($record->grade) ? (float)$record->grade : null;
                $attendancestatus = strtoupper(trim((string)($record->acronym ?? '')));
                $ispresent = $attendancegrade !== null
                    ? ($attendancegrade > 0)
                    : in_array($attendancestatus, ['P', 'E'], true);
                if ($attendancestatus === '') {
                    $attendancestatus = $ispresent ? 'P' : 'A';
                }

                $data[$userid]['attendance_history'][] = [
                    'date' => (int)$record->sessdate,
                    'present' => $ispresent,
                    'status' => $attendancestatus,
                ];
            }
            $records->close();
        }

        $values = [];
        foreach ($data as $userid => $trenddata) {
            $changes = [];

            foreach (['assignments', 'quizzes', 'projects'] as $key) {
                $points = $trenddata[$key];
                usort($points, static function(array $left, array $right): int {
                    return ((int)$left['date']) <=> ((int)$right['date']);
                });
                if (count($points) > $trendwindow) {
                    $points = array_slice($points, -$trendwindow);
                }

                $componenttrend = $this->calculate_component_trend($points);
                if ($componenttrend !== null && isset($componenttrend['change']) && is_numeric($componenttrend['change'])) {
                    $changes[] = (float)$componenttrend['change'];
                }
            }

            $attendancepoints = [];
            if (!empty($trenddata['attendance_history'])) {
                usort($trenddata['attendance_history'], static function(array $left, array $right): int {
                    return ((int)$left['date']) <=> ((int)$right['date']);
                });
                $attendancepoints = array_slice($trenddata['attendance_history'], -$trendwindow);
            }

            $attendancesummary = $this->calculate_attendance_summary($attendancepoints, $attendancewindow);
            if (
                $attendancesummary !== null &&
                (int)($attendancesummary['previousWindowSize'] ?? 0) > 0 &&
                isset($attendancesummary['currentRate'], $attendancesummary['previousRate'])
            ) {
                $attendancechange = (float)$attendancesummary['currentRate'] - (float)$attendancesummary['previousRate'];
                if (!is_nan($attendancechange)) {
                    $changes[] = round($attendancechange, 1);
                }
            }

            $overalltrend = 'stable';
            if (!empty($changes)) {
                $averagechange = array_sum($changes) / count($changes);
                $overalltrend = $this->classify_trend_change($averagechange);
            }

            $values[$userid] = [
                'label' => $this->get_trend_status_label($overalltrend),
                'details' => [
                    'assignments' => $trenddata['assignments'],
                    'quizzes' => $trenddata['quizzes'],
                    'projects' => $trenddata['projects'],
                    'attendance' => $attendancepoints,
                    'overall_trend' => $overalltrend,
                    'trend_window' => $trendwindow,
                    'attendance_window' => $attendancewindow,
                ],
            ];
        }

        return $values;
    }

    /**
     * @return array{trend_window:int,attendance_window:int}
     */
    private function get_trend_settings(): array {
        $trendwindow = (int)get_config('local_batchanalytics', 'trend_window');
        $attendancewindow = (int)get_config('local_batchanalytics', 'attendance_window');
        $trendwindow = min(100, max(5, $trendwindow > 0 ? $trendwindow : 20));
        $attendancewindow = min(20, max(3, $attendancewindow > 0 ? $attendancewindow : 5));

        return [
            'trend_window' => $trendwindow,
            'attendance_window' => $attendancewindow,
        ];
    }

    /**
     * Mirror the student dashboard frontend component trend calculation.
     *
     * @param array $grades
     * @return array|null
     */
    private function calculate_component_trend(array $grades): ?array {
        $count = count($grades);
        if ($count === 1) {
            $singlegrade = isset($grades[0]['grade']) ? (float)$grades[0]['grade'] : NAN;
            if (is_nan($singlegrade)) {
                return null;
            }

            $singlegrade = round($singlegrade, 1);
            return [
                'start' => $singlegrade,
                'latest' => $singlegrade,
                'change' => 0.0,
                'method' => 'single-point',
            ];
        }

        if ($count < 4) {
            if ($count < 2) {
                return null;
            }

            $firstgrade = isset($grades[0]['grade']) ? (float)$grades[0]['grade'] : NAN;
            $lastgrade = isset($grades[$count - 1]['grade']) ? (float)$grades[$count - 1]['grade'] : NAN;
            if (is_nan($firstgrade) || is_nan($lastgrade)) {
                return null;
            }

            return [
                'start' => round($firstgrade, 1),
                'latest' => round($lastgrade, 1),
                'change' => round($lastgrade - $firstgrade, 1),
                'method' => 'first-last',
            ];
        }

        $split = (int)floor($count / 2);
        $olderhalf = array_slice($grades, 0, $split);
        $recenthalf = array_slice($grades, $split);

        $oldergrades = [];
        foreach ($olderhalf as $grade) {
            $value = isset($grade['grade']) ? (float)$grade['grade'] : NAN;
            if (!is_nan($value)) {
                $oldergrades[] = $value;
            }
        }

        $recentgrades = [];
        foreach ($recenthalf as $grade) {
            $value = isset($grade['grade']) ? (float)$grade['grade'] : NAN;
            if (!is_nan($value)) {
                $recentgrades[] = $value;
            }
        }

        if (empty($oldergrades) || empty($recentgrades)) {
            return null;
        }

        $olderavg = array_sum($oldergrades) / count($oldergrades);
        $recentavg = array_sum($recentgrades) / count($recentgrades);

        return [
            'start' => round($olderavg, 1),
            'latest' => round($recentavg, 1),
            'change' => round($recentavg - $olderavg, 1),
            'method' => 'half-vs-half',
        ];
    }

    /**
     * Mirror the student dashboard frontend attendance summary.
     *
     * @param array $attendancepoints
     * @param int $windowsize
     * @return array|null
     */
    private function calculate_attendance_summary(array $attendancepoints, int $windowsize): ?array {
        if (empty($attendancepoints)) {
            return null;
        }

        $size = $windowsize > 0 ? $windowsize : 5;
        $totalpoints = count($attendancepoints);
        $countpresent = static function(array $points): int {
            return array_reduce($points, static function(int $sum, array $item): int {
                return $sum + (!empty($item['present']) ? 1 : 0);
            }, 0);
        };

        if ($totalpoints < 2) {
            $onlycount = $countpresent($attendancepoints);
            return [
                'windowSize' => $totalpoints,
                'currentCount' => $onlycount,
                'currentRate' => $totalpoints > 0 ? round(($onlycount / $totalpoints) * 100) : 0,
                'previousWindowSize' => 0,
                'previousCount' => 0,
                'previousRate' => 0,
                'delta' => null,
                'comparisonMode' => 'insufficient',
            ];
        }

        $recent = array_slice($attendancepoints, -$size);
        $currentcount = $countpresent($recent);
        $currenttotal = count($recent);

        $previousstart = max(0, $totalpoints - ($size * 2));
        $previouslength = max(0, ($totalpoints - $size) - $previousstart);
        $previous = array_slice($attendancepoints, $previousstart, $previouslength);
        $previouscount = $countpresent($previous);
        $previoustotal = count($previous);

        $mincomparableprevious = max(2, (int)ceil($currenttotal / 2));
        if ($previoustotal === 0 || $previoustotal < $mincomparableprevious) {
            $split = (int)floor($totalpoints / 2);
            $older = array_slice($attendancepoints, 0, $split);
            $newer = array_slice($attendancepoints, $split);
            if (!empty($older) && !empty($newer)) {
                $oldercount = $countpresent($older);
                $newercount = $countpresent($newer);
                return [
                    'windowSize' => count($newer),
                    'currentCount' => $newercount,
                    'currentRate' => round(($newercount / count($newer)) * 100),
                    'previousWindowSize' => count($older),
                    'previousCount' => $oldercount,
                    'previousRate' => round(($oldercount / count($older)) * 100),
                    'delta' => $newercount - $oldercount,
                    'comparisonMode' => 'adaptive-half',
                ];
            }
        }

        return [
            'windowSize' => $currenttotal,
            'currentCount' => $currentcount,
            'currentRate' => $currenttotal > 0 ? round(($currentcount / $currenttotal) * 100) : 0,
            'previousWindowSize' => $previoustotal,
            'previousCount' => $previouscount,
            'previousRate' => $previoustotal > 0 ? round(($previouscount / $previoustotal) * 100) : 0,
            'delta' => $previoustotal > 0 ? ($currentcount - $previouscount) : null,
            'comparisonMode' => 'window',
        ];
    }

    /**
     * @param float $finalgrade
     * @param float $grademin
     * @param float $grademax
     * @return float
     */
    private function normalise_grade_percentage(float $finalgrade, float $grademin, float $grademax): float {
        $range = $grademax - $grademin;

        if ($range > 0) {
            $percentage = (($finalgrade - $grademin) / $range) * 100;
        } else if ($grademax > 0) {
            $percentage = ($finalgrade / $grademax) * 100;
        } else {
            $percentage = 0.0;
        }

        return round($percentage, 1);
    }

    /**
     * @param array $points
     * @return float|null
     */
    private function calculate_trend_change(array $points): ?float {
        $count = count($points);
        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return 0.0;
        }

        if ($count < 4) {
            $first = (float)$points[0]['grade'];
            $last = (float)$points[$count - 1]['grade'];
            return $last - $first;
        }

        $split = (int)floor($count / 2);
        $older = array_slice($points, 0, $split);
        $recent = array_slice($points, $split);
        if (empty($older) || empty($recent)) {
            return null;
        }

        $olderavg = array_sum(array_map(static function(array $point): float {
            return (float)$point['grade'];
        }, $older)) / count($older);
        $recentavg = array_sum(array_map(static function(array $point): float {
            return (float)$point['grade'];
        }, $recent)) / count($recent);

        return $recentavg - $olderavg;
    }

    /**
     * @param float|null $change
     * @return string
     */
    private function classify_trend_change(?float $change): string {
        if ($change === null) {
            return 'stable';
        }

        if ($change > 5) {
            return 'up';
        }

        if ($change < -5) {
            return 'down';
        }

        return 'stable';
    }

    /**
     * @param string $trend
     * @return string
     */
    private function get_trend_status_label(string $trend): string {
        if ($trend === 'up') {
            return 'Improving';
        }

        if ($trend === 'down') {
            return 'Declining';
        }

        return 'Stable';
    }

    /**
     * @param int $userid
     * @return array
     */
    private function get_ticket_dashboard_records(int $userid, array $access): array {
        global $DB;

        $params = [];
        $wheresql = '';
        if (empty($access['canviewall'])) {
            $visiblecourseids = array_keys($access['visiblecourseids'] ?? []);
            if (empty($visiblecourseids)) {
                return [];
            }
            list($insql, $params) = $DB->get_in_or_equal($visiblecourseids, SQL_PARAMS_NAMED, 'ticketcourse');
            $wheresql = "WHERE t.courseid $insql";
        }

        $sql = "SELECT t.*,
                       c.fullname AS coursename,
                       c.shortname AS courseshortname,
                       CONCAT(stu.firstname, ' ', stu.lastname) AS studentfullname,
                       stu.username AS studentusername,
                       stu.email AS studentemail,
                       CONCAT(cb.firstname, ' ', cb.lastname) AS createdbyfullname,
                       cb.email AS createdbyemail,
                       CONCAT(ss.firstname, ' ', ss.lastname) AS ssteamfullname,
                       CONCAT(bm.firstname, ' ', bm.lastname) AS batchmanagerfullname,
                       CONCAT(rb.firstname, ' ', rb.lastname) AS resolvedbyfullname
                  FROM {local_batchanalytics_ticket} t
             LEFT JOIN {course} c ON c.id = t.courseid
             LEFT JOIN {user} stu ON stu.id = t.studentuserid
             LEFT JOIN {user} cb ON cb.id = t.createdby
             LEFT JOIN {user} ss ON ss.id = t.ssteamuserid
             LEFT JOIN {user} bm ON bm.id = t.batchmanageruserid
             LEFT JOIN {user} rb ON rb.id = t.resolvedby
                  $wheresql
              ORDER BY t.timecreated DESC, t.id DESC";

        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * @param \stdClass $record
     * @param int $userid
     * @param array $access
     * @return array
     */
    private function format_ticket_dashboard_record(\stdClass $record, int $userid, array $access): array {
        $statuskey = $this->normalise_ticket_status((string)$record->status);
        $courseid = (int)$record->courseid;
        $isassigned = (int)$record->ssteamuserid === $userid;
        $canmanagecourse = !empty($access['canmanage']) || !empty($access['manageablecourseids'][$courseid]);
        $canupdate = $statuskey !== 'resolved' && $canmanagecourse;
        $prioritykey = $this->normalise_ticket_priority((string)($record->priority ?? 'low'));

        $accessrole = 'Viewer';
        if (!empty($access['canmanage'])) {
            $accessrole = 'Admin / Manager';
        } else if (!empty($access['manageablecourseids'][$courseid])) {
            $accessrole = 'Ticket Manager';
        } else if ($isassigned) {
            $accessrole = 'Assigned SS Team';
        }

        $actionlabel = 'View';
        if ($canupdate) {
            $actionlabel = 'Resolve';
        }

        return [
            'id' => (int)$record->id,
            'courseid' => $courseid,
            'coursename' => trim((string)$record->coursename),
            'courseshortname' => trim((string)($record->courseshortname ?? '')),
            'batchname' => $this->extract_batch_name(
                trim((string)$record->coursename),
                trim((string)($record->courseshortname ?? '')),
            ),
            'studentuserid' => (int)$record->studentuserid,
            'studentname' => trim((string)$record->studentfullname),
            'studentusername' => trim((string)$record->studentusername),
            'studentemail' => trim((string)($record->studentemail ?? '')),
            'raisedby' => trim((string)$record->createdbyfullname),
            'raisedbyemail' => trim((string)($record->createdbyemail ?? '')),
            'raisedto' => $this->build_raised_to_label((string)$record->ssteamfullname, ''),
            'tickettitle' => (string)$record->tickettitle,
            'ticketreason' => (string)$record->ticketreason,
            'status' => $this->get_ticket_status_label($statuskey),
            'statuskey' => $statuskey,
            'priority' => ucfirst($prioritykey),
            'prioritykey' => $prioritykey,
            'timecreated' => (int)$record->timecreated,
            'timemodified' => (int)$record->timemodified,
            'ismine' => $isassigned,
            'isassigned' => $isassigned,
            'isinrolecourse' => $canmanagecourse || $isassigned,
            'canresolve' => $canupdate,
            'canedit' => $canupdate,
            'actionlabel' => $actionlabel,
            'accessrole' => $accessrole,
            'ssteamfullname' => trim((string)$record->ssteamfullname),
            'batchmanagerfullname' => trim((string)$record->batchmanagerfullname),
            'resolutionfeedback' => trim((string)($record->resolutionfeedback ?? '')),
            'resolvedby' => (int)($record->resolvedby ?? 0),
            'resolvedbyfullname' => trim((string)($record->resolvedbyfullname ?? '')),
            'timeresolved' => (int)($record->timeresolved ?? 0),
            'timeline' => [],
        ];
    }

    /**
     * @param string $coursename
     * @param string $shortname
     * @return string
     */
    private function extract_batch_name(string $coursename, string $shortname): string {
        $source = strpos($coursename, ':') !== false ? $coursename : $shortname;
        $source = trim($source !== '' ? $source : $coursename);
        if ($source === '') {
            return '-';
        }

        $parts = array_filter(array_map('trim', explode(':', $source)), static function(string $part): bool {
            return $part !== '';
        });
        if (empty($parts)) {
            return '-';
        }

        return (string)end($parts);
    }

    /**
     * @param int $userid
     * @return bool
     */
    private function can_manage_all(int $userid): bool {
        $context = \context_system::instance();
        return is_siteadmin($userid) || has_capability('local/batchanalytics:manage', $context, $userid);
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private function can_view_maac_data(int $courseid, int $userid): bool {
        if ($this->can_manage_all($userid)) {
            return true;
        }

        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        return $coursecontext && (
            has_capability('local/batchanalytics:viewmaac', $coursecontext, $userid) ||
            has_capability('local/batchanalytics:editmaac', $coursecontext, $userid)
        );
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private function can_view_tickets_for_course(int $courseid, int $userid): bool {
        if ($this->can_manage_all($userid)) {
            return true;
        }

        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        return $coursecontext && (
            has_capability('local/batchanalytics:viewtickets', $coursecontext, $userid) ||
            has_capability('local/batchanalytics:managetickets', $coursecontext, $userid)
        );
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private function can_manage_tickets_for_course(int $courseid, int $userid): bool {
        if ($this->can_manage_all($userid)) {
            return true;
        }

        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        return $coursecontext && has_capability('local/batchanalytics:managetickets', $coursecontext, $userid);
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private function can_raise_tickets_for_course(int $courseid, int $userid): bool {
        return $this->can_manage_tickets_for_course($courseid, $userid) || $this->can_edit_maac_data($courseid, $userid);
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private function can_edit_maac_data(int $courseid, int $userid): bool {
        if (is_siteadmin($userid)) {
            return true;
        }

        $systemcontext = \context_system::instance();
        if (has_capability('local/batchanalytics:manage', $systemcontext, $userid)) {
            return true;
        }

        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        return $coursecontext && has_capability('local/batchanalytics:editmaac', $coursecontext, $userid);
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private function can_update_tickets_for_course(int $courseid, int $userid): bool {
        return $this->can_manage_tickets_for_course($courseid, $userid);
    }

    /**
     * @param int $userid
     * @return array
     */
    private function build_ticket_access_scope(int $userid): array {
        $canmanageall = $this->can_manage_all($userid);
        $visiblecourseids = $this->get_accessible_ticket_course_ids($userid, false);
        $manageablecourseids = $this->get_accessible_ticket_course_ids($userid, true);

        $rolekey = 'viewer';
        $rolelabel = 'View Access';
        if ($canmanageall) {
            $rolekey = 'manager';
            $rolelabel = 'Admin / Manager';
        } else if (!empty($manageablecourseids)) {
            $rolekey = 'ticket_manager';
            $rolelabel = 'Ticket Manager';
        } else if (!empty($visiblecourseids)) {
            $rolekey = 'ticket_viewer';
            $rolelabel = 'Ticket Viewer';
        }

        if ($canmanageall) {
            $scopekey = 'all';
            $scopelabel = 'Showing all tickets.';
        } else if (!empty($visiblecourseids)) {
            $scopekey = 'enrolled';
            $scopelabel = 'Showing tickets for your permitted enrolled courses.';
        } else {
            $scopekey = 'none';
            $scopelabel = 'Ticket dashboard access is limited by ticket permissions.';
        }

        return [
            'canmanage' => $canmanageall,
            'canviewall' => $canmanageall,
            'canresolve' => $canmanageall || !empty($manageablecourseids),
            'rolekey' => $rolekey,
            'rolelabel' => $rolelabel,
            'scopekey' => $scopekey,
            'scopelabel' => $scopelabel,
            'visiblecourseids' => $canmanageall ? [] : $visiblecourseids,
            'manageablecourseids' => $canmanageall ? [] : $manageablecourseids,
            'batchmanagercourseids' => [],
            'ssteamcourseids' => [],
        ];
    }

    /**
     * @param int $userid
     * @param int $roleid
     * @return array
     */
    private function get_user_role_course_ids(int $userid, int $roleid): array {
        global $DB;

        if ($userid <= 0 || $roleid <= 0) {
            return [];
        }

        $cachekey = $userid . '_' . $roleid;
        if (isset($this->cached_role_course_ids[$cachekey])) {
            return $this->cached_role_course_ids[$cachekey];
        }

        $moodledata = new moodledata();

        $sql = "SELECT DISTINCT ctx.instanceid AS courseid
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ra.userid = :userid
                   AND ra.roleid = :roleid
                   AND ctx.contextlevel = 50";

        $records = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'roleid' => $roleid,
        ]);
        $courseids = [];
        foreach ($records as $record) {
            $courseid = (int)$record->courseid;
            if ($courseid > 0 && $moodledata->get_accessible_course($courseid, $userid)) {
                $courseids[$courseid] = true;
            }
        }

        $this->cached_role_course_ids[$cachekey] = $courseids;
        return $courseids;
    }

    /**
     * @param int $userid
     * @param bool $manageonly
     * @return array
     */
    private function get_accessible_ticket_course_ids(int $userid, bool $manageonly = false): array {
        if ($this->can_manage_all($userid)) {
            return [];
        }

        $moodledata = new moodledata();
        $courses = enrol_get_users_courses($userid, true, ['id', 'fullname', 'shortname']);
        $courseids = [];

        foreach ($courses as $course) {
            $courseid = (int)$course->id;
            if (!$moodledata->get_accessible_course($courseid, $userid)) {
                continue;
            }

            $allowed = $manageonly
                ? $this->can_manage_tickets_for_course($courseid, $userid)
                : $this->can_view_tickets_for_course($courseid, $userid);
            if ($allowed) {
                $courseids[$courseid] = true;
            }
        }

        return $courseids;
    }

    /**
     * @param \stdClass $ticket
     * @param int $userid
     * @return bool
     */
    private function can_update_ticket_record(\stdClass $ticket, int $userid): bool {
        return $this->can_manage_tickets_for_course((int)($ticket->courseid ?? 0), $userid);
    }

    /**
     * @param string $status
     * @return string
     */
    private function normalise_ticket_status(string $status): string {
        $status = strtolower(trim($status));
        if ($status === 'resolved') {
            return 'resolved';
        }
        if ($status === 'in_progress' || $status === 'in progress') {
            return 'in_progress';
        }

        return 'open';
    }

    /**
     * @param string $statuskey
     * @return string
     */
    private function get_ticket_status_label(string $statuskey): string {
        if ($statuskey === 'resolved') {
            return 'Resolved';
        }
        if ($statuskey === 'in_progress') {
            return 'In Progress';
        }

        return 'Open';
    }

    /**
     * @param string $priority
     * @return string
     */
    private function normalise_ticket_priority(string $priority): string {
        $priority = strtolower(trim($priority));
        if (in_array($priority, ['medium', 'high'], true)) {
            return $priority;
        }

        return 'low';
    }

    /**
     * @return bool
     */
    private function ticket_event_table_exists(): bool {
        global $DB;

        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        $exists = $DB->get_manager()->table_exists(new \xmldb_table('local_batchanalytics_ticket_event'));
        return $exists;
    }

    /**
     * @param int $ticketid
     * @param int $userid
     * @param string $eventtype
     * @param array $data
     * @return void
     */
    private function log_ticket_event(int $ticketid, int $userid, string $eventtype, array $data = []): void {
        global $DB;

        if ($ticketid <= 0 || !$this->ticket_event_table_exists()) {
            return;
        }

        $record = (object)[
            'ticketid' => $ticketid,
            'userid' => $userid,
            'eventtype' => trim($eventtype) !== '' ? trim($eventtype) : 'updated',
            'fromstatus' => $data['fromstatus'] ?? null,
            'tostatus' => $data['tostatus'] ?? null,
            'frompriority' => $data['frompriority'] ?? null,
            'topriority' => $data['topriority'] ?? null,
            'feedback' => $data['feedback'] ?? null,
            'timecreated' => time(),
        ];

        $DB->insert_record('local_batchanalytics_ticket_event', $record);
    }

    /**
     * @param array $ticketids
     * @return array
     */
    private function get_ticket_timeline_events(array $ticketids): array {
        global $DB;

        if (empty($ticketids) || !$this->ticket_event_table_exists()) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal(array_values(array_unique(array_map('intval', $ticketids))), SQL_PARAMS_NAMED);
        $sql = "SELECT e.*,
                       CONCAT(u.firstname, ' ', u.lastname) AS actorfullname
                  FROM {local_batchanalytics_ticket_event} e
             LEFT JOIN {user} u ON u.id = e.userid
                 WHERE e.ticketid $insql
              ORDER BY e.timecreated ASC, e.id ASC";

        $records = $DB->get_recordset_sql($sql, $params);
        $events = [];
        foreach ($records as $record) {
            $ticketid = (int)$record->ticketid;
            if (!isset($events[$ticketid])) {
                $events[$ticketid] = [];
            }
            $events[$ticketid][] = $this->format_ticket_timeline_event($record);
        }
        $records->close();

        return $events;
    }

    /**
     * @param \stdClass $record
     * @return array
     */
    private function format_ticket_timeline_event(\stdClass $record): array {
        $eventtype = strtolower(trim((string)($record->eventtype ?? 'updated')));
        $actorname = trim((string)($record->actorfullname ?? ''));
        $title = 'Ticket updated';
        if ($eventtype === 'created') {
            $title = 'Ticket created';
        } else if ($eventtype === 'resolved') {
            $title = 'Ticket resolved';
        } else if ($eventtype === 'reopened') {
            $title = 'Ticket reopened';
        }

        $details = [];
        $fromstatus = $this->normalise_ticket_status((string)($record->fromstatus ?? ''));
        $tostatus = $this->normalise_ticket_status((string)($record->tostatus ?? ''));
        if ($tostatus !== 'open' || !empty($record->fromstatus)) {
            if (!empty($record->fromstatus) && $fromstatus !== $tostatus) {
                $details[] = 'Status: ' . $this->get_ticket_status_label($fromstatus) . ' -> ' . $this->get_ticket_status_label($tostatus);
            } else if (!empty($record->tostatus)) {
                $details[] = 'Status: ' . $this->get_ticket_status_label($tostatus);
            }
        }

        $frompriority = $this->normalise_ticket_priority((string)($record->frompriority ?? 'low'));
        $topriority = $this->normalise_ticket_priority((string)($record->topriority ?? 'low'));
        if (!empty($record->topriority)) {
            if (!empty($record->frompriority) && $frompriority !== $topriority) {
                $details[] = 'Priority: ' . ucfirst($frompriority) . ' -> ' . ucfirst($topriority);
            } else {
                $details[] = 'Priority: ' . ucfirst($topriority);
            }
        }

        $feedback = trim((string)($record->feedback ?? ''));
        if ($feedback !== '') {
            $details[] = 'Feedback: ' . $feedback;
        }

        return [
            'eventtype' => $eventtype,
            'title' => $title,
            'actorname' => $actorname !== '' ? $actorname : '-',
            'details' => $details,
            'timecreated' => (int)($record->timecreated ?? 0),
        ];
    }


    /**
     * @param string $ssteam
     * @param string $batchmanager
     * @return string
     */
    private function build_raised_to_label(string $ssteam, string $batchmanager): string {
        $names = array_values(array_unique(array_filter([
            trim($ssteam),
            trim($batchmanager),
        ])));

        return empty($names) ? '-' : implode(' / ', $names);
    }

    /**
     * @param int $courseid
     * @param array $userids
     * @return array
     */
    private function get_ticket_counts(int $courseid, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        $sql = "SELECT studentuserid, COUNT(1) AS ticketcount
                  FROM {local_batchanalytics_ticket}
                 WHERE courseid = :courseid
                   AND studentuserid $insql
              GROUP BY studentuserid";
        $records = $DB->get_recordset_sql($sql, $params);
        $counts = [];
        foreach ($records as $record) {
            $counts[(int)$record->studentuserid] = (int)$record->ticketcount;
        }
        $records->close();

        return $counts;
    }

    /**
     * @param \stdClass $record
     * @param int $userid
     * @param array $timeline
     * @return array
     */
    private function format_maac_ticket_record(\stdClass $record, int $userid, array $timeline = []): array {
        $statuskey = $this->normalise_ticket_status((string)$record->status);
        $prioritykey = $this->normalise_ticket_priority((string)($record->priority ?? 'low'));

        return [
            'id' => (int)$record->id,
            'title' => $record->tickettitle,
            'reason' => $record->ticketreason,
            'status' => $this->get_ticket_status_label($statuskey),
            'statuskey' => $statuskey,
            'priority' => ucfirst($prioritykey),
            'prioritykey' => $prioritykey,
            'resolutionfeedback' => trim((string)($record->resolutionfeedback ?? '')),
            'createdby' => (int)($record->createdby ?? 0),
            'resolvedby' => (int)($record->resolvedby ?? 0),
            'ssteamuserid' => (int)($record->ssteamuserid ?? 0),
            'canedit' => $this->can_edit_own_ticket_from_maac($record, $userid),
            'timemodified' => (int)($record->timemodified ?? 0),
            'timeresolved' => (int)($record->timeresolved ?? 0),
            'timecreated' => (int)$record->timecreated,
            'ssteamfullname' => trim((string)($record->ssteamfullname ?? '')),
            'batchmanagerfullname' => trim((string)($record->batchmanagerfullname ?? '')),
            'resolvedbyfullname' => trim((string)($record->resolvedbyfullname ?? '')),
            'timeline' => $timeline,
        ];
    }

    /**
     * @param \stdClass $ticket
     * @param int $userid
     * @return bool
     */
    private function can_edit_own_ticket_from_maac(\stdClass $ticket, int $userid): bool {
        $courseid = (int)($ticket->courseid ?? 0);
        if ($courseid <= 0 || !$this->can_edit_maac_data($courseid, $userid)) {
            return false;
        }

        if ((int)($ticket->createdby ?? 0) !== $userid) {
            return false;
        }

        if ((time() - (int)($ticket->timecreated ?? 0)) > DAYSECS) {
            return false;
        }

        return $this->normalise_ticket_status((string)($ticket->status ?? 'open')) === 'open'
            && $this->normalise_ticket_priority((string)($ticket->priority ?? 'low')) === 'low'
            && trim((string)($ticket->resolutionfeedback ?? '')) === ''
            && (int)($ticket->resolvedby ?? 0) <= 0;
    }

    /**
     * @param int $courseid
     * @param array $userids
     * @return array
     */
    private function get_tickets_by_student(int $courseid, array $userids, int $userid): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        $sql = "SELECT t.id,
                       t.courseid,
                       t.studentuserid,
                       t.tickettitle,
                       t.ticketreason,
                       t.status,
                       t.priority,
                       t.resolutionfeedback,
                       t.createdby,
                       t.resolvedby,
                       t.ssteamuserid,
                       t.timemodified,
                       t.timeresolved,
                       t.timecreated,
                       CONCAT(ss.firstname, ' ', ss.lastname) AS ssteamfullname,
                       CONCAT(bm.firstname, ' ', bm.lastname) AS batchmanagerfullname,
                       CONCAT(rb.firstname, ' ', rb.lastname) AS resolvedbyfullname
                  FROM {local_batchanalytics_ticket} t
             LEFT JOIN {user} ss ON ss.id = t.ssteamuserid
             LEFT JOIN {user} bm ON bm.id = t.batchmanageruserid
             LEFT JOIN {user} rb ON rb.id = t.resolvedby
                 WHERE t.courseid = :courseid
                   AND t.studentuserid $insql
              ORDER BY t.timecreated DESC, t.id DESC";

        $records = $DB->get_recordset_sql($sql, $params);
        $tickets = [];
        $ticketids = [];
        foreach ($records as $record) {
            $studentuserid = (int)$record->studentuserid;
            if (!isset($tickets[$studentuserid])) {
                $tickets[$studentuserid] = [];
            }

            $ticketids[] = (int)$record->id;
            $ticketdata = $this->format_maac_ticket_record($record, $userid, []);
            $tickets[$studentuserid][] = $ticketdata;
        }
        $records->close();

        $timelinebyticket = $this->get_ticket_timeline_events($ticketids);
        foreach ($tickets as $studentuserid => $studenttickets) {
            foreach ($studenttickets as $index => $ticket) {
                $ticketid = (int)$ticket['id'];
                if (!empty($timelinebyticket[$ticketid])) {
                    $tickets[$studentuserid][$index]['timeline'] = $timelinebyticket[$ticketid];
                }
            }
        }

        return $tickets;
    }

    /**
     * @param int $courseid
     * @return array
     */
    private function build_ticket_meta(int $courseid): array {
        $ssteamroleid = (int)get_config('local_batchanalytics', 'ss_team_role');

        return [
            'ss_team_role' => $this->get_role_descriptor($ssteamroleid, 'SS Team'),
            'ss_team_users' => $this->get_course_role_users($courseid, $ssteamroleid),
        ];
    }

    /**
     * @param int $roleid
     * @param string $fallbacklabel
     * @return array
     */
    private function get_role_descriptor(int $roleid, string $fallbacklabel): array {
        global $DB;

        if ($roleid <= 0) {
            return [
                'id' => 0,
                'label' => $fallbacklabel,
            ];
        }

        $role = $DB->get_record('role', ['id' => $roleid], 'id, shortname, name');
        if (!$role) {
            return [
                'id' => 0,
                'label' => $fallbacklabel,
            ];
        }

        $label = trim(($role->name ?: $role->shortname) ?: $fallbacklabel);
        return [
            'id' => (int)$role->id,
            'label' => $label,
        ];
    }

    /**
     * @param int $courseid
     * @param int $roleid
     * @return array
     */
    private function get_course_role_users(int $courseid, int $roleid): array {
        global $DB;

        if ($roleid <= 0) {
            return [];
        }

        $sql = "
            SELECT DISTINCT
                u.id,
                CONCAT(u.firstname, ' ', u.lastname) AS fullname,
                u.username,
                u.email
            FROM {user} u
            JOIN {role_assignments} ra ON ra.userid = u.id
            JOIN {context} ctx ON ctx.id = ra.contextid
            WHERE ctx.instanceid = :courseid
              AND ctx.contextlevel = 50
              AND ra.roleid = :roleid
              AND u.deleted = 0
            ORDER BY u.firstname, u.lastname
        ";
        $users = [];
        foreach ($DB->get_records_sql($sql, ['courseid' => $courseid, 'roleid' => $roleid]) as $record) {
            $users[] = [
                'id' => (int)$record->id,
                'fullname' => $record->fullname,
                'username' => $record->username,
                'email' => $record->email,
            ];
        }

        return $users;
    }

    /**
     * Load saved custom values for course + users.
     *
     * @param int $courseid
     * @param array $userids
     * @return array
     */
    private function get_custom_values(int $courseid, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        $sql = "SELECT userid, fieldkey, value
                  FROM {local_batchanalytics_maac}
                 WHERE courseid = :courseid
                   AND userid $insql";

        $records = $DB->get_recordset_sql($sql, $params);
        $values = [];
        foreach ($records as $record) {
            $values[(int)$record->userid][$record->fieldkey] = $record->value;
        }
        $records->close();

        return $values;
    }

    /**
     * Load closed spot award nominations for the provided course + users.
     *
     * @param int $courseid
     * @param array $userids
     * @return array
     */
    private function get_spot_award_nomination_values(int $courseid, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        $dbman = $DB->get_manager();
        if (
            !$dbman->table_exists(new \xmldb_table('spotaward_nomination_items')) ||
            !$dbman->table_exists(new \xmldb_table('spotaward_nominations'))
        ) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'spotstudent');
        $params['courseid'] = $courseid;
        $params['closedstatus'] = 'closed';

        $sql = "SELECT
                    i.studentid,
                    i.awardcategory AS itemawardcategory,
                    n.awardcategory AS nominationawardcategory
                  FROM {spotaward_nomination_items} i
                  JOIN {spotaward_nominations} n
                    ON n.id = i.nominationid
                 WHERE i.studentid $insql
                   AND i.status = :closedstatus
                   AND n.courseid = :courseid
              ORDER BY i.studentid ASC, i.id ASC";

        $categoriesbystudent = [];
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            $studentid = (int)$record->studentid;
            $category = trim((string)($record->itemawardcategory ?: $record->nominationawardcategory));
            if ($studentid <= 0 || $category === '') {
                continue;
            }

            if (!isset($categoriesbystudent[$studentid])) {
                $categoriesbystudent[$studentid] = [];
            }
            if (!isset($categoriesbystudent[$studentid][$category])) {
                $categoriesbystudent[$studentid][$category] = 0;
            }

            $categoriesbystudent[$studentid][$category]++;
        }
        $records->close();

        $values = [];
        foreach ($categoriesbystudent as $studentid => $categories) {
            $items = [];
            foreach ($categories as $category => $count) {
                $items[] = $count > 1 ? $category . ' (' . $count . ')' : $category;
            }

            if (!empty($items)) {
                $values[$studentid] = json_encode($items);
            }
        }

        return $values;
    }

    /**
     * @param mixed $value
     * @param array $column
     * @return string|null
     */
    private function normalise_value_for_storage($value, array $column): ?string {
        $type = $column['type'] ?? 'text';
        if ($type === 'boolean') {
            return !empty($value) ? '1' : '0';
        }

        if ($type === 'multi_feedback') {
            $values = is_array($value) ? $value : [$value];
            $clean = [];
            foreach ($values as $entry) {
                if (is_array($entry)) {
                    $text = isset($entry['text']) ? clean_param(trim((string)$entry['text']), PARAM_TEXT) : '';
                    $date = isset($entry['date']) ? clean_param(trim((string)$entry['date']), PARAM_TEXT) : '';
                    $added_at = isset($entry['added_at']) ? (int)$entry['added_at'] : null;
                    if ($text !== '') {
                        $obj = ['date' => $date, 'text' => $text];
                        if ($added_at) {
                            $obj['added_at'] = $added_at;
                        }
                        $clean[] = $obj;
                    }
                } else {
                    $entry = is_string($entry) ? trim($entry) : (string)$entry;
                    if ($entry !== '') {
                        $clean[] = ['date' => date('Y-m-d'), 'text' => clean_param($entry, PARAM_TEXT)];
                    }
                }
            }
            return empty($clean) ? null : json_encode(array_values($clean));
        }

        if ($type === 'dropdown' && ($column['selection'] ?? 'single') === 'multi') {
            $options = $column['options'] ?? [];
            $values = is_array($value) ? $value : [$value];
            $clean = [];
            foreach ($values as $selected) {
                $selected = is_string($selected) ? trim($selected) : (string)$selected;
                if ($selected !== '' && in_array($selected, $options, true)) {
                    $clean[] = $selected;
                }
            }
            $clean = array_values(array_unique($clean));
            return empty($clean) ? null : json_encode(array_values($clean));
        }

        $value = is_string($value) ? trim($value) : (string)$value;
        if ($value === '') {
            return null;
        }

        if ($type === 'number') {
            if (!is_numeric($value)) {
                return null;
            }

            $numeric = (float)$value;
            $min = $column['min'] ?? null;
            $max = $column['max'] ?? null;
            if ($min !== null && $numeric < (float)$min) {
                $numeric = (float)$min;
            }

            if ($max !== null && $numeric > (float)$max) {
                $numeric = (float)$max;
            }

            return (string)(0 + $numeric);
        }

        if ($type === 'date') {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        }

        if ($type === 'dropdown') {
            $options = $column['options'] ?? [];
            return in_array($value, $options, true) ? $value : null;
        }

        return clean_param($value, PARAM_TEXT);
    }

    /**
     * @param string $value
     * @param array $column
     * @return mixed
     */
    private function format_value_for_output(string $value, array $column) {
        $type = $column['type'] ?? 'text';
        if ($type === 'boolean') {
            return $value === '1' ? 1 : 0;
        }

        if ($type === 'number') {
            return $value === '' ? '' : (float)$value;
        }

        if (
            ($type === 'dropdown' && ($column['selection'] ?? 'single') === 'multi') ||
            $type === 'list'
        ) {
            if ($value === '') {
                return [];
            }
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map('strval', $decoded), static function(string $item): bool {
                    return trim($item) !== '';
                }));
            }
            return trim($value) === '' ? [] : [$value];
        }

        if ($type === 'multi_feedback') {
            if ($value === '') {
                return [];
            }
            $decoded = json_decode($value, true);
            return is_array($decoded) ? array_values($decoded) : [];
        }

        return $value;
    }

    /**
     * @param array $groups
     * @param array $students
     * @return array
     */
    private function build_course_groups(array $groups, array $students): array {
        $result = [];
        foreach ($groups as $group) {
            $items = [];
            foreach (($group['columns'] ?? []) as $column) {
                $items[] = [
                    'label' => $column['label'],
                    'summary' => $this->summarise_column($column, $students),
                ];
            }

            $result[] = [
                'name' => $group['name'],
                'items' => $items,
            ];
        }

        return $result;
    }

    /**
     * @param array $column
     * @param array $students
     * @return string
     */
    private function summarise_column(array $column, array $students): string {
        $key = $column['key'];
        $type = $column['type'];

        if ($key === 'trend') {
            $counts = [
                'Improving' => 0,
                'Stable' => 0,
                'Declining' => 0,
            ];

            foreach ($students as $student) {
                $value = trim((string)($student['custom'][$key] ?? ''));
                if ($value !== '' && isset($counts[$value])) {
                    $counts[$value]++;
                }
            }

            $parts = [];
            foreach (['Improving', 'Declining', 'Stable'] as $label) {
                $parts[] = $counts[$label] . ' ' . $label;
            }

            return implode(' | ', $parts);
        }

        if ($type === 'number') {
            $values = [];
            foreach ($students as $student) {
                $value = $student['custom'][$key] ?? '';
                if ($value !== '' && is_numeric($value)) {
                    $values[] = (float)$value;
                }
            }
            return empty($values) ? '0% Avg' : round(array_sum($values) / count($values), 2) . '% Avg';
        }

        if ($type === 'boolean') {
            $count = 0;
            foreach ($students as $student) {
                if (!empty($student['custom'][$key])) {
                    $count++;
                }
            }
            return $count . ' Students';
        }

        if ($type === 'dropdown') {
            $count = 0;
            foreach ($students as $student) {
                $value = $student['custom'][$key] ?? '';
                if (is_array($value)) {
                    if (!empty($value)) {
                        $count++;
                    }
                } else if ($value !== '') {
                    $count++;
                }
            }
            return $count . ' Students';
        }

        if ($type === 'multi_feedback') {
            $count = 0;
            foreach ($students as $student) {
                $value = $student['custom'][$key] ?? [];
                if (is_array($value) && !empty($value)) {
                    $count++;
                }
            }
            return $count . ' Filled';
        }

        $count = 0;
        foreach ($students as $student) {
            $value = $student['custom'][$key] ?? '';
            if ($value !== '' && $value !== []) {
                $count++;
            }
        }
        return $count . ' Filled';
    }

    /**
     * @param mixed $value
     * @param string $type
     * @return bool
     */
    private function is_truthy_value($value, string $type): bool {
        if ($type === 'boolean') {
            return (string)$value === '1' || $value === 1 || $value === true;
        }

        return !empty($value);
    }
}
