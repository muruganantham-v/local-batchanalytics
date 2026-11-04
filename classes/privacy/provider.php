<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_batchanalytics\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy Subsystem implementation for local_batchanalytics.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Return the metadata about user data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_batchanalytics_batch_notes',
            [
                'batchid'      => 'privacy:metadata:batch_notes:batchid',
                'userid'       => 'privacy:metadata:batch_notes:userid',
                'author'       => 'privacy:metadata:batch_notes:author',
                'body'         => 'privacy:metadata:batch_notes:body',
                'timecreated'  => 'privacy:metadata:batch_notes:timecreated',
                'timemodified' => 'privacy:metadata:batch_notes:timemodified',
                'modifiedby'   => 'privacy:metadata:batch_notes:modifiedby',
                'history'      => 'privacy:metadata:batch_notes:history',
            ],
            'privacy:metadata:batch_notes'
        );

        $collection->add_database_table(
            'local_batchanalytics_activity_tracker',
            [
                'courseid'     => 'privacy:metadata:activity_tracker:courseid',
                'cmid'         => 'privacy:metadata:activity_tracker:cmid',
                'sectionid'    => 'privacy:metadata:activity_tracker:sectionid',
                'completed'    => 'privacy:metadata:activity_tracker:completed',
                'modifiedby'   => 'privacy:metadata:activity_tracker:modifiedby',
                'timemodified' => 'privacy:metadata:activity_tracker:timemodified',
            ],
            'privacy:metadata:activity_tracker'
        );

        $collection->add_database_table(
            'local_batchanalytics_mentor_act',
            [
                'courseid'       => 'privacy:metadata:mentor_act:courseid',
                'selectedgroup'  => 'privacy:metadata:mentor_act:selectedgroup',
                'activitiesdata' => 'privacy:metadata:mentor_act:activitiesdata',
                'timemodified'   => 'privacy:metadata:mentor_act:timemodified',
            ],
            'privacy:metadata:mentor_act'
        );

        $collection->add_database_table(
            'local_batchanalytics_cliq_log',
            [
                'batchid'         => 'privacy:metadata:cliq_log:batchid',
                'courseid'        => 'privacy:metadata:cliq_log:courseid',
                'activity_type'   => 'privacy:metadata:cliq_log:activity_type',
                'activity_key'    => 'privacy:metadata:cliq_log:activity_key',
                'stage'           => 'privacy:metadata:cliq_log:stage',
                'recipient_email' => 'privacy:metadata:cliq_log:recipient_email',
                'userid'          => 'privacy:metadata:cliq_log:userid',
                'timesent'        => 'privacy:metadata:cliq_log:timesent',
                'status'          => 'privacy:metadata:cliq_log:status',
            ],
            'privacy:metadata:cliq_log'
        );

        $collection->add_external_location_link(
            'zohocliq',
            [
                'name'  => 'privacy:metadata:zohocliq:name',
                'email' => 'privacy:metadata:zohocliq:email',
            ],
            'privacy:metadata:zohocliq:summary'
        );

        $collection->add_external_location_link(
            'zohocrm',
            [
                'studentid' => 'privacy:metadata:zohocrm:studentid',
            ],
            'privacy:metadata:zohocrm:summary'
        );

        return $collection;
    }

    /**
     * Get the list of contexts where user data exists for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();

        // 1. Course contexts where user modified activity tracking records.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course} co ON co.id = c.instanceid AND c.contextlevel = :courselevel
                  JOIN {local_batchanalytics_activity_tracker} at ON at.courseid = co.id
                 WHERE at.modifiedby = :userid";
        $contextlist->add_from_sql($sql, ['courselevel' => CONTEXT_COURSE, 'userid' => $userid]);

        // 2. Course contexts where user modified mentor activities in JSON blob.
        $mentor_sql = "SELECT c.id
                         FROM {context} c
                         JOIN {course} co ON co.id = c.instanceid AND c.contextlevel = :courselevel
                         JOIN {local_batchanalytics_mentor_act} ma ON ma.courseid = co.id
                        WHERE (" . $DB->sql_like('ma.activitiesdata', ':userlike1') . "
                            OR " . $DB->sql_like('ma.activitiesdata', ':userlike2') . ")";
        $contextlist->add_from_sql($mentor_sql, [
            'courselevel' => CONTEXT_COURSE,
            'userlike1'   => '%"modifiedby":' . $userid . '%',
            'userlike2'   => '%"modifiedby": ' . $userid . '%',
        ]);

        // 3. Review notes are attached to batches and managed at the system context level.
        $notes_sql = "SELECT c.id
                        FROM {context} c
                        JOIN {local_batchanalytics_batch_notes} bn ON c.contextlevel = :syslevel
                       WHERE bn.userid = :userid";
        $contextlist->add_from_sql($notes_sql, ['syslevel' => CONTEXT_SYSTEM, 'userid' => $userid]);

        // 4. Zoho Cliq notification logs at system context level:
        // Match primarily by permanent userid, with fallback to recipient_email to prevent orphaning after email change.
        $user = $DB->get_record('user', ['id' => $userid], 'id, email');
        $user_email = ($user && !empty($user->email)) ? $user->email : '';

        $cliq_params = ['syslevel' => CONTEXT_SYSTEM, 'userid' => $userid];
        $cliq_where = 'cl.userid = :userid';
        if ($user_email !== '') {
            $cliq_where .= ' OR cl.recipient_email = :email';
            $cliq_params['email'] = $user_email;
        }

        $cliq_sql = "SELECT c.id
                       FROM {context} c
                       JOIN {local_batchanalytics_cliq_log} cl ON c.contextlevel = :syslevel
                      WHERE $cliq_where";
        $contextlist->add_from_sql($cliq_sql, $cliq_params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a specific context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();

        if ($context instanceof \context_course) {
            $sql = "SELECT modifiedby AS userid
                      FROM {local_batchanalytics_activity_tracker}
                     WHERE courseid = :courseid AND modifiedby > 0";
            $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);

            // Users who modified mentor activities for this course.
            $rec = $DB->get_record('local_batchanalytics_mentor_act', ['courseid' => $context->instanceid], 'id, activitiesdata');
            if ($rec && !empty($rec->activitiesdata)) {
                $items = json_decode($rec->activitiesdata, true);
                if (is_array($items)) {
                    $mod_uids = [];
                    foreach ($items as $item) {
                        $uid = (int)($item['modifiedby'] ?? 0);
                        if ($uid > 0) {
                            $mod_uids[] = $uid;
                        }
                    }
                    if (!empty($mod_uids)) {
                        $userlist->add_users(array_values(array_unique($mod_uids)));
                    }
                }
            }
        } else if ($context instanceof \context_system) {
            $sql = "SELECT userid
                      FROM {local_batchanalytics_batch_notes}
                     WHERE userid > 0";
            $userlist->add_from_sql('userid', $sql, []);

            // Cliq logs: match by permanent userid.
            $cliq_user_sql = "SELECT userid
                                FROM {local_batchanalytics_cliq_log}
                               WHERE userid > 0";
            $userlist->add_from_sql('userid', $cliq_user_sql, []);

            // Fallback for any legacy rows without userid: match by email.
            $cliq_legacy_sql = "SELECT u.id AS userid
                                  FROM {local_batchanalytics_cliq_log} cl
                                  JOIN {user} u ON LOWER(u.email) = LOWER(cl.recipient_email)
                                 WHERE (cl.userid = 0 OR cl.userid IS NULL) AND u.deleted = 0";
            $userlist->add_from_sql('userid', $cliq_legacy_sql, []);
        }
    }

    /**
     * Export all user data for the specified user in the provided contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        $userid = $user->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                // Export batch review notes written by user.
                $notes = $DB->get_records('local_batchanalytics_batch_notes', ['userid' => $userid]);
                if (!empty($notes)) {
                    $export_notes = [];
                    foreach ($notes as $n) {
                        $export_notes[] = [
                            'batchid'      => $n->batchid,
                            'body'         => $n->body,
                            'timecreated'  => transform::datetime($n->timecreated),
                            'timemodified' => !empty($n->timemodified) ? transform::datetime($n->timemodified) : '',
                            'history'      => $n->history ?? '',
                        ];
                    }
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_batchanalytics'), get_string('reviewnotes', 'local_batchanalytics')],
                        (object)['notes' => $export_notes]
                    );
                }

                // Export Cliq notification dispatch records for this user (by userid or email).
                $cliq_where = 'userid = :userid';
                $cliq_params = ['userid' => $userid];
                if (!empty($user->email)) {
                    $cliq_where .= ' OR recipient_email = :email';
                    $cliq_params['email'] = $user->email;
                }
                $logs = $DB->get_records_select('local_batchanalytics_cliq_log', $cliq_where, $cliq_params);
                if (!empty($logs)) {
                    $export_logs = [];
                    foreach ($logs as $l) {
                        $export_logs[] = [
                            'activity_type' => $l->activity_type,
                            'activity_key'  => $l->activity_key,
                            'stage'         => $l->stage,
                            'timesent'      => transform::datetime($l->timesent),
                            'status'        => $l->status,
                        ];
                    }
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_batchanalytics'), get_string('cliqnotifications', 'local_batchanalytics')],
                        (object)['notifications' => $export_logs]
                    );
                }
            } else if ($context instanceof \context_course) {
                // Export activity tracking completion stamps by this user.
                $tracker = $DB->get_records('local_batchanalytics_activity_tracker', [
                    'courseid'   => $context->instanceid,
                    'modifiedby' => $userid,
                ]);
                if (!empty($tracker)) {
                    $export_tracker = [];
                    foreach ($tracker as $t) {
                        $export_tracker[] = [
                            'cmid'           => $t->cmid,
                            'completed'      => transform::yesno($t->completed),
                            'completiondate' => $t->completiondate,
                            'timemodified'   => transform::datetime($t->timemodified),
                        ];
                    }
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_batchanalytics'), get_string('activitytracker', 'local_batchanalytics')],
                        (object)['activities' => $export_tracker]
                    );
                }

                // Export mentor activities modified by this user in this course.
                $mentor_act = $DB->get_record('local_batchanalytics_mentor_act', ['courseid' => $context->instanceid]);
                if ($mentor_act && !empty($mentor_act->activitiesdata)) {
                    $items = json_decode($mentor_act->activitiesdata, true);
                    if (is_array($items)) {
                        $user_activities = [];
                        foreach ($items as $item) {
                            if ((int)($item['modifiedby'] ?? 0) === $userid) {
                                $user_activities[] = [
                                    'key'            => $item['key'] ?? '',
                                    'name'           => $item['name'] ?? '',
                                    'completed'      => transform::yesno(!empty($item['completed'])),
                                    'completiondate' => $item['completiondate'] ?? '',
                                    'sectionid'      => $item['sectionid'] ?? 0,
                                    'timemodified'   => !empty($item['timemodified']) ? transform::datetime($item['timemodified']) : '',
                                ];
                            }
                        }
                        if (!empty($user_activities)) {
                            writer::with_context($context)->export_data(
                                [get_string('pluginname', 'local_batchanalytics'), get_string('mentoractivities', 'local_batchanalytics')],
                                (object)['activities' => $user_activities]
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context instanceof \context_course) {
            $DB->delete_records('local_batchanalytics_activity_tracker', ['courseid' => $context->instanceid]);
            $DB->delete_records('local_batchanalytics_mentor_act', ['courseid' => $context->instanceid]);
        } else if ($context instanceof \context_system) {
            $DB->delete_records('local_batchanalytics_batch_notes');
            $DB->delete_records('local_batchanalytics_cliq_log');
        }
    }

    /**
     * Delete all user data for the specified user in the provided contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        $userid = $user->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $DB->delete_records('local_batchanalytics_batch_notes', ['userid' => $userid]);
                // Delete Cliq logs by permanent userid OR current email to prevent orphaning after email change.
                $cliq_where = 'userid = :userid';
                $cliq_params = ['userid' => $userid];
                if (!empty($user->email)) {
                    $cliq_where .= ' OR recipient_email = :email';
                    $cliq_params['email'] = $user->email;
                }
                $DB->delete_records_select('local_batchanalytics_cliq_log', $cliq_where, $cliq_params);
            } else if ($context instanceof \context_course) {
                // Anonymize user from activity tracker completions.
                $DB->set_field(
                    'local_batchanalytics_activity_tracker',
                    'modifiedby',
                    0,
                    ['courseid' => $context->instanceid, 'modifiedby' => $userid]
                );

                // Anonymize user from mentor activities JSON blob.
                $mentor_act = $DB->get_record('local_batchanalytics_mentor_act', ['courseid' => $context->instanceid]);
                if ($mentor_act && !empty($mentor_act->activitiesdata)) {
                    $items = json_decode($mentor_act->activitiesdata, true);
                    if (is_array($items)) {
                        $changed = false;
                        foreach ($items as &$item) {
                            if ((int)($item['modifiedby'] ?? 0) === $userid) {
                                $item['modifiedby'] = 0;
                                $item['modifiedbyname'] = '';
                                $changed = true;
                            }
                        }
                        unset($item);
                        if ($changed) {
                            $mentor_act->activitiesdata = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
                            $mentor_act->timemodified = time();
                            $DB->update_record('local_batchanalytics_mentor_act', $mentor_act);
                        }
                    }
                }
            }
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        if ($context instanceof \context_system) {
            $DB->delete_records_select('local_batchanalytics_batch_notes', "userid $insql", $inparams);

            // Delete Cliq logs by permanent userid.
            $DB->delete_records_select('local_batchanalytics_cliq_log', "userid $insql", $inparams);

            // Also delete by email if any legacy records had userid = 0.
            $emails = $DB->get_fieldset_select('user', 'email', "id $insql AND email IS NOT NULL AND email != ''", $inparams);
            if (!empty($emails)) {
                [$emailsql, $emailparams] = $DB->get_in_or_equal($emails, SQL_PARAMS_NAMED, 'clqmail');
                $DB->delete_records_select('local_batchanalytics_cliq_log', "recipient_email $emailsql", $emailparams);
            }
        } else if ($context instanceof \context_course) {
            $inparams['courseid'] = $context->instanceid;
            $DB->execute(
                "UPDATE {local_batchanalytics_activity_tracker}
                    SET modifiedby = 0
                  WHERE courseid = :courseid AND modifiedby $insql",
                $inparams
            );

            // Anonymize mentor activities for multiple users.
            $mentor_act = $DB->get_record('local_batchanalytics_mentor_act', ['courseid' => $context->instanceid]);
            if ($mentor_act && !empty($mentor_act->activitiesdata)) {
                $items = json_decode($mentor_act->activitiesdata, true);
                if (is_array($items)) {
                    $userids_map = array_flip($userids);
                    $changed = false;
                    foreach ($items as &$item) {
                        $mid = (int)($item['modifiedby'] ?? 0);
                        if ($mid > 0 && isset($userids_map[$mid])) {
                            $item['modifiedby'] = 0;
                            $item['modifiedbyname'] = '';
                            $changed = true;
                        }
                    }
                    unset($item);
                    if ($changed) {
                        $mentor_act->activitiesdata = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
                        $mentor_act->timemodified = time();
                        $DB->update_record('local_batchanalytics_mentor_act', $mentor_act);
                    }
                }
            }
        }
    }
}
