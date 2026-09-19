<?php
// This file is part of Moodle - http://moodle.org/
// ... (License Header) ...

/**
 * Main page for local_batchanalytics
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

// Autoloading handles classes in 'classes/' folder
require_login();

$context = context_system::instance();
$userid = $USER->id;

// Capability-based permission check. Assign 'local/batchanalytics:view' to roles in Site administration > Users > Permissions > Define roles.
require_capability('local/batchanalytics:view', $context);

$can_manage = is_siteadmin($userid) || has_capability('local/batchanalytics:manage', $context);
$can_view_all_courses = $can_manage || has_capability('local/batchanalytics:viewallcourses', $context);

$moodledata = new \local_batchanalytics\moodledata();
$can_view_tickets = $moodledata->can_access_ticket_dashboard($userid);

/**
 * Return a safe CRM error for the browser.
 *
 * @param \Throwable $exception
 * @return array{error:string,errorcode:string}
 */
$format_crm_error = static function(\Throwable $exception): array {
    $message = $exception->getMessage();
    $lowercase = strtolower($message);
    $errorcode = 'crm_unavailable';
    $safeerror = 'CRM data is unavailable. Please retry later.';

    if (str_contains($lowercase, 'access token')) {
        $errorcode = 'crm_authorization';
        $safeerror = 'CRM authorization is unavailable. Check the Zoho CRM settings and retry.';
    } else if (preg_match('/http status (\d+)/i', $message, $matches)) {
        $httpcode = (int)$matches[1];
        if ($httpcode === 401 || $httpcode === 403) {
            $errorcode = 'crm_authorization';
            $safeerror = 'CRM authorization was rejected. Check the Zoho CRM settings and retry.';
        } else if ($httpcode === 429) {
            $errorcode = 'crm_rate_limited';
            $safeerror = 'Zoho CRM rate limit was reached. Please retry later.';
        } else if ($httpcode >= 400 && $httpcode < 500) {
            $errorcode = 'crm_request_rejected';
            $safeerror = 'Zoho CRM rejected this request. Check the CRM module and field settings.';
        } else {
            $errorcode = 'crm_upstream_unavailable';
            $safeerror = 'Zoho CRM is temporarily unavailable. Please retry later.';
        }
    } else if (str_contains($lowercase, 'invalid response')) {
        $errorcode = 'crm_invalid_response';
        $safeerror = 'Zoho CRM returned an invalid response. Please retry later.';
    }

    return [
        'error' => $safeerror,
        'errorcode' => $errorcode,
    ];
};

// Load restricted CRM fields for view-only users.
$restricted_crm_fields = [];
if (!$can_manage) {
    $restricted_crm_fields = \local_batchanalytics\crm_fields_helper::get_restricted_keys();
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// Course teachers can maintain the summary for courses to which they are assigned.
$can_edit_course_summary = static function($coursecontext) use ($can_manage, $userid): bool {
    if (!$coursecontext) {
        return false;
    }
    return $can_manage || has_capability('local/batchanalytics:editmaac', $coursecontext, $userid);
};

// ==================== API ENDPOINTS ====================

if ($action === 'syncmaaccrm') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new moodle_exception('invalidrequest');
        }
        require_sesskey();
        if (!$can_manage) {
            throw new moodle_exception('nopermissions', 'error', '', 'sync MAAC metrics to CRM');
        }

        $payload = optional_param('payload', '', PARAM_RAW);
        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !is_array($decoded['rows'] ?? null)) {
            throw new moodle_exception('invalidjson', 'error');
        }

        $synccolumns = \local_batchanalytics\maac_columns_helper::get_sync_columns();
        if (empty($synccolumns)) {
            echo json_encode(['error' => 'No MAAC sync columns are configured.']);
            die();
        }
        if (!\local_batchanalytics\util::check_crm_rate_limit($userid)) {
            echo json_encode(['error' => 'CRM request limit reached. Please try again later.']);
            die();
        }

        $crm = new \local_batchanalytics\crmapi();
        $result = ['updated' => 0, 'notfound' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($decoded['rows'] as $row) {
            $username = trim((string)($row['username'] ?? ''));
            $values = is_array($row['values'] ?? null) ? $row['values'] : [];
            if ($username === '') {
                $result['skipped']++;
                continue;
            }

            $fields = [];
            foreach ($synccolumns as $column) {
                $key = $column['key'];
                if (isset($values[$key]) && is_numeric($values[$key])) {
                    $fields[$column['crmfield']] = round((float)$values[$key], 2);
                }
            }
            if (empty($fields)) {
                $result['skipped']++;
                continue;
            }

            try {
                $record = $crm->get_student_details($username);
            } catch (\Throwable $e) {
                debugging('MAAC CRM lookup error: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $result['failed']++;
                continue;
            }
            if (empty($record['id'])) {
                $result['notfound']++;
                continue;
            }
            if ($crm->update_student_fields((string)$record['id'], $fields)) {
                $result['updated']++;
            } else {
                $result['failed']++;
            }
        }

        echo json_encode(['success' => true, 'result' => $result]);
    } catch (\Throwable $e) {
        error_log('MAAC CRM sync error: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['error' => 'Unable to sync MAAC metrics to CRM. Please check the Moodle error log.']);
    }
    die();
}

if ($action === 'getallbatches') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $mdata = new \local_batchanalytics\moodledata();
    $batches = $mdata->get_all_batches($userid);

    echo json_encode(['batches' => $batches]);
    die();
}

if ($action === 'searchcourses') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $keyword = optional_param('keyword', '', PARAM_TEXT);

    $mdata = new \local_batchanalytics\moodledata();
    $courses = $mdata->search_courses($keyword, $userid);

    echo json_encode(['courses' => $courses]);
    die();
}

if ($action === 'getbatchcourses') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $batchcode = optional_param('batchcode', '', PARAM_TEXT);

    $mdata = new \local_batchanalytics\moodledata();
    $courses = $mdata->get_courses_by_batch($batchcode, $userid);

    echo json_encode(['courses' => $courses]);
    die();
}

if ($action === 'savecoursesummary') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed. Please use POST.']);
            die();
        }

        if (!confirm_sesskey()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid session key. Please refresh the page and try again.']);
            die();
        }

        $courseid = required_param('courseid', PARAM_INT);
        $summary = optional_param('summary', '', PARAM_RAW_TRIMMED);
        $mdata = new \local_batchanalytics\moodledata();
        $course = $mdata->get_accessible_course($courseid, $userid);
        if (!$course) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to edit this course summary.']);
            die();
        }
        $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
        $caneditsummary = $can_edit_course_summary($coursecontext);

        if (!$coursecontext) {
            http_response_code(404);
            echo json_encode(['error' => 'Course not found.']);
            die();
        }

        if (!$caneditsummary) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to edit this course summary.']);
            die();
        }

        $summary = trim((string)$summary);
        \local_batchanalytics\course_summary_service::save($courseid, $summary);
        \local_batchanalytics\util::purge_batch_response_cache();

        echo json_encode([
            'success' => true,
            'courseid' => $courseid,
            'summary' => $summary,
        ]);
    } catch (\Throwable $e) {
        while (ob_get_level())
            ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        error_log('Course summary save error: ' . $e->getMessage());
        echo json_encode(['error' => 'Unable to save course summary. Please check the Moodle error log.']);
    }
    die();
}
// --- EXISTING CRM ENDPOINT (Simple Placement Status) ---
if ($action === 'getcrmdata') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Please use POST.']);
        die();
    }
    require_sesskey();

    if (!\local_batchanalytics\util::check_crm_rate_limit($userid)) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded. Please try again later.']);
        die();
    }

    $username = optional_param('username', '', PARAM_TEXT);
    $mdata = new \local_batchanalytics\moodledata();
    $allowedusernames = $mdata->filter_accessible_student_usernames([$username], $userid);
    if (empty($allowedusernames)) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to access this student.']);
        die();
    }
    $username = reset($allowedusernames);

    try {
        $crm = new \local_batchanalytics\crmapi();
        $record = $crm->get_student_details($username);
        $placement_status = 'Not Placed';
        if (!empty($record['Placement_Company'])) {
            if (is_array($record['Placement_Company']) && isset($record['Placement_Company']['name'])) {
                $placement_status = $record['Placement_Company']['name'];
            } elseif (is_string($record['Placement_Company'])) {
                $placement_status = $record['Placement_Company'];
            }
        }

        echo json_encode([
            'username' => $username,
            'placed_company' => $placement_status
        ]);
    } catch (\Throwable $e) {
        debugging('CRM API Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        http_response_code(502);
        echo json_encode([
            'error' => 'Unable to fetch CRM data. Please try again later.'
        ]);
    }
    die();
}

if ($action === 'getptfdata') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        echo json_encode(['error' => 'Method not allowed. Please use POST.']);
        die();
    }
    require_sesskey();

    if (!\local_batchanalytics\util::check_crm_rate_limit($userid)) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded. Please try again later.']);
        die();
    }

    $username = optional_param('username', '', PARAM_TEXT);
    $payload = optional_param('payload', '', PARAM_RAW);
    $usernames = '';

    // Support JSON payload for usernames array
    if ($payload !== '') {
        $decoded = json_decode($payload, true);
        if (is_array($decoded) && !empty($decoded['usernames'])) {
            $usernames = implode(',', $decoded['usernames']);
        }
    } else {
        $usernames = optional_param('usernames', '', PARAM_TEXT);
    }

    try {
        $crm = new \local_batchanalytics\crmapi();

        if (!empty($usernames)) {
            $usernameList = array_slice(array_filter(array_map('trim', explode(',', $usernames))), 0, 500);
            $mdata = new \local_batchanalytics\moodledata();
            $usernameList = $mdata->filter_accessible_student_usernames($usernameList, $userid);
            if (empty($usernameList)) {
                echo json_encode(['students' => []]);
                die();
            }
            $records = $crm->get_students_details($usernameList);

            $output = [];
            foreach ($usernameList as $u) {
                $lookup = strtolower($u);
                $record = $records[$lookup] ?? null;
                $company = 'Not Placed';
                if (!empty($record['Placement_Company'])) {
                    if (is_array($record['Placement_Company']) && isset($record['Placement_Company']['name'])) {
                        $company = $record['Placement_Company']['name'];
                    } elseif (is_string($record['Placement_Company'])) {
                        $company = $record['Placement_Company'];
                    }
                }
                $rec_data = $record;
                $rec_ctc = $rec_data['CTC'] ?? '-';
                if (!$can_manage && !empty($restricted_crm_fields)) {
                    $rec_data = \local_batchanalytics\crm_fields_helper::filter_crm_fields($rec_data, $restricted_crm_fields);
                    if (in_array('CTC', $restricted_crm_fields)) {
                        $rec_ctc = null;
                    }
                }
                $output[] = [
                    'username' => $u,
                    'placed_company' => $company,
                    'CTC' => $rec_ctc,
                    'data' => $rec_data
                ];
            }

            echo json_encode(['students' => $output]);
            die();
        }

        $mdata = new \local_batchanalytics\moodledata();
        $allowedusernames = $mdata->filter_accessible_student_usernames([$username], $userid);
        if (empty($allowedusernames)) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to access this student.']);
            die();
        }
        $username = reset($allowedusernames);

        $record = $crm->get_student_details($username);

        if (empty($record) || !is_array($record)) {
            echo json_encode([
                'username' => $username,
                'placed_company' => 'Not Placed',
                'CTC' => '-',
                'error' => get_string('crmconfigmissing', 'local_batchanalytics')
            ]);
            die();
        }

        // Handle Placement Company (Extract name if it's an object)
        $company = 'Not Placed';
        if (!empty($record['Placement_Company'])) {
            if (is_array($record['Placement_Company']) && isset($record['Placement_Company']['name'])) {
                $company = $record['Placement_Company']['name'];
            } elseif (is_string($record['Placement_Company'])) {
                $company = $record['Placement_Company'];
            }
        }

        // Build response dynamically from configured API fields.
        $data = ['username' => $username, 'placed_company' => $company];
        foreach (\local_batchanalytics\crm_fields_helper::get_api_field_keys() as $field_key) {
            if ($field_key === 'College_Name') {
                $data[$field_key] = $record['College_Name'] ?? ($record['Other_College_Name'] ?? '-');
            } else {
                $data[$field_key] = $record[$field_key] ?? '-';
            }
        }

        if (!$can_manage && !empty($restricted_crm_fields)) {
            $data = \local_batchanalytics\crm_fields_helper::filter_crm_fields($data, $restricted_crm_fields);
        }

        echo json_encode($data);
    } catch (\Throwable $e) {
        debugging('CRM API Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        http_response_code(502);
        echo json_encode($format_crm_error($e));
    }
    die();
}

if ($action === 'getmentordetails') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        echo json_encode(['error' => 'Method not allowed. Please use POST.']);
        die();
    }
    require_sesskey();

    if (!\local_batchanalytics\util::check_crm_rate_limit($userid)) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded. Please try again later.']);
        die();
    }

    $batchgroup = optional_param('batchgroup', '', PARAM_TEXT);
    if ($batchgroup === '') {
        $batchgroup = optional_param('batchcode', '', PARAM_TEXT);
    }
    $mentorfields = \local_batchanalytics\crm_fields_helper::get_mentor_fields();
    $mentorgroups = \local_batchanalytics\crm_fields_helper::get_mentor_field_groups();

    try {
        $crm = new \local_batchanalytics\crmapi();
        $mentorrecord = $crm->get_mentor_details_by_batch_group($batchgroup);
        if (empty($mentorrecord) || !is_array($mentorrecord)) {
            $mentorrecord = [];
        }

        echo json_encode([
            'batchgroup' => $batchgroup,
            'mentorfields' => $mentorfields,
            'mentorgroups' => $mentorgroups,
            'mentorrecord' => $mentorrecord,
        ]);
    } catch (\Throwable $e) {
        debugging('Mentor CRM API Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        echo json_encode(['error' => 'An error occurred fetching mentor CRM data']);
    }
    die();
}

if ($action === 'getbatchfulldata') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $batchcode = optional_param('batchcode', '', PARAM_TEXT);
    $cachekey = \local_batchanalytics\util::get_batch_cache_key($userid, $batchcode, $can_view_all_courses);
    $cachedpayload = \local_batchanalytics\util::read_cached_batch_response($cachekey);
    if ($cachedpayload !== null) {
        echo json_encode($cachedpayload);
        die();
    }

    $mdata = new \local_batchanalytics\moodledata();
    $courses = $mdata->get_courses_by_batch($batchcode, $userid);

    $result = [
        'batchcode' => $batchcode,
        'courses' => [],
        'totalStudents' => 0,
        'totalCourses' => count($courses),
        'totalTeachers' => 0,
        'uniqueStudents' => [], // Will hold list of {name, username}
        'uniqueTeachers' => []
    ];

    $student_role = $DB->get_record('role', ['shortname' => 'student']);
    if (!$student_role) {
        echo json_encode(['error' => 'Student role not found. Please verify role mappings.']);
        die();
    }
    $student_role_id = $student_role->id;

    // Resolve teacher roles once. get_role_users() includes inherited assignments.
    $teacher_roles = $DB->get_records_list('role', 'shortname', ['teacher', 'editingteacher'], '', 'id, shortname');
    $teacher_role_ids = array_keys($teacher_roles);

    $course_summaries = \local_batchanalytics\course_summary_service::get_for_courses(
        array_column($courses, 'courseid')
    );

    foreach ($courses as $course) {
        $courseid = $course['courseid'];

        // 1. Get Teachers, including assignments inherited from parent contexts.
        $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
        $teachers_raw = $coursecontext && !empty($teacher_role_ids)
            ? get_role_users($teacher_role_ids, $coursecontext, true, 'ra.id, u.id AS userid, u.firstname, u.lastname')
            : [];

        $course_unique_teachers = [];
        foreach ($teachers_raw as $t) {
            $course_unique_teachers[$t->userid] = $t->firstname . ' ' . $t->lastname;
            $result['uniqueTeachers'][$t->userid] = $t->firstname . ' ' . $t->lastname;
        }
        $course_teacher_count = count($course_unique_teachers);

        // 2. Get Grade Items (Including Course Total)
        $items_sql = "
            SELECT
                gi.id as itemid, gi.itemname, gi.itemtype, gi.itemmodule, gi.grademax, gi.grademin,
                gc.id as categoryid, gc.fullname as categoryname
            FROM {grade_items} gi
            LEFT JOIN {grade_categories} gc ON gc.id = gi.categoryid
            WHERE gi.courseid = :courseid
              AND (gi.itemtype IN ('mod', 'manual') OR gi.itemtype = 'course')
              AND gi.hidden = 0
            ORDER BY gc.fullname, gi.itemname
        ";
        $grade_items = $DB->get_records_sql($items_sql, ['courseid' => $courseid]);

        // 3. Get Students

        $students_sql = "
            SELECT DISTINCT
                u.id as userid,
                CONCAT(u.firstname, ' ', u.lastname) as fullname,
                u.username
            FROM {user} u
            JOIN {role_assignments} ra ON ra.userid = u.id
            JOIN {context} ctx ON ctx.id = ra.contextid
            WHERE ctx.instanceid = :courseid
              AND ctx.contextlevel = 50
              AND ra.roleid = :roleid
              AND u.deleted = 0
            ORDER BY u.firstname, u.lastname
        ";
        $students = $DB->get_records_sql($students_sql, ['courseid' => $courseid, 'roleid' => $student_role_id]);


        foreach ($students as $s) {
            // FIX: Store both name and username for the Ptf Data tab
            $result['uniqueStudents'][$s->userid] = [
                'fullname' => $s->fullname,
                'username' => $s->username
            ];
        }

        // Note: CRM data fetching has been moved outside this course loop to prevent cross-course overwriting

        // 4. Organize Data — Fetch all categories to resolve parent/child relationships
        $all_cats = $DB->get_records('grade_categories', ['courseid' => $courseid]);

        $categories_data = [];
        foreach ($grade_items as $item) {

            if ($item->itemtype === 'course') {
                $cat_name = 'MAAC Ratings';
            } else {
                $cat_id = $item->categoryid;
                $cat_name = 'Uncategorized';

                if ($cat_id && isset($all_cats[$cat_id])) {
                    $c = $all_cats[$cat_id];

                    // NEW: If it's a sub-category (Depth 3+), climb up the tree until we hit the Main Category (Depth 2)
                    while ($c->depth > 2 && !empty($c->parent) && isset($all_cats[$c->parent])) {
                        $c = $all_cats[$c->parent];
                    }

                    // Apply the Main Category name
                    if ($c->depth == 2) {
                        $cat_name = $c->fullname;
                    }
                }
            }

            // Skip unwanted, empty, or uncategorized items completely
            if (trim($cat_name) === '' || $cat_name === '?' || $cat_name === 'Uncategorized') {
                continue;
            }

            if (!isset($categories_data[$cat_name])) {
                $categories_data[$cat_name] = [
                    'categoryname' => $cat_name,
                    'items' => [],
                    'studentGrades' => []
                ];
            }
            $categories_data[$cat_name]['items'][] = [
                'itemid' => $item->itemid,
                'grademax' => (float)$item->grademax,
                'grademin' => (float)$item->grademin
            ];
        }

        foreach ($categories_data as $category_key => &$category_data) {
            if ($category_key === 'MAAC Ratings' || stripos($category_key, 'attend') !== false) {
                continue;
            }
            $category_data['categoryname'] = $category_key . ' (' . count($category_data['items']) . ')';
        }
        unset($category_data);

        // 5. Pre-fetch ALL grades for this course in a single query to prevent N+1 DB lookups!
        $all_grades_sql = "
            SELECT gg.id, gg.userid, gg.itemid, gg.finalgrade
            FROM {grade_grades} gg
            JOIN {grade_items} gi ON gi.id = gg.itemid
            WHERE gi.courseid = :courseid
              AND gi.hidden = 0
              AND gg.finalgrade IS NOT NULL
              AND gg.excluded = 0
              AND gg.hidden = 0
        ";

        $grades_map = []; // structure: $grades_map[userid][itemid] = finalgrade

        // Use a recordset instead of get_records_sql because get_records_sql uses the first column
        // as the array key, which means it would overwrite all but the last grade per user!
        $rs = $DB->get_recordset_sql($all_grades_sql, ['courseid' => $courseid]);
        if ($rs->valid()) {
            foreach ($rs as $rec) {
                 $grades_map[$rec->userid][$rec->itemid] = $rec->finalgrade;
            }
        }
        $rs->close();

        // 6. Calculate Grades
        foreach ($categories_data as $cat_name => &$cat_data) {
            $gradable_items_in_cat = 0;
            $category_total_max = 0;

            foreach ($cat_data['items'] as $item) {
                $gmax = $item['grademax'];
                $gmin = $item['grademin'];
                if ($gmax > $gmin) {
                    $gradable_items_in_cat++;
                    $category_total_max += ($gmax - $gmin);
                }
            }

            foreach ($students as $student) {
                $total_earned = 0;
                $total_max = 0;
                $items_completed = 0; // NEW: Track how many they actually did

                foreach ($cat_data['items'] as $item) {
                    $itemid = $item['itemid'];
                    $userid = $student->userid;

                    // Fetch grade instantly from memory instead of hitting the DB!
                    if (isset($grades_map[$userid]) && isset($grades_map[$userid][$itemid])) {
                        $finalgrade = $grades_map[$userid][$itemid];

                        $gmax = $item['grademax'];
                        $gmin = $item['grademin'];
                        if ($gmax > $gmin) {
                            $items_completed++;
                            $total_earned += ($finalgrade - $gmin);
                            $total_max += ($gmax - $gmin);
                        }
                    }
                }

                // Grade %: Only calculated on items they ACTUALLY submitted (Ignores unsubmitted)
                $percentage = $total_max > 0 ? round(($total_earned / $total_max) * 100, 2) : null;
                $advanced_filter_percentage = $category_total_max > 0
                    ? round(($total_earned / $category_total_max) * 100, 2)
                    : null;

                if ($cat_name === 'MAAC Ratings' && $percentage !== null) {
                    $percentage = round($percentage / 10, 1);
                }

                if ($cat_name === 'MAAC Ratings' && $advanced_filter_percentage !== null) {
                    $advanced_filter_percentage = round($advanced_filter_percentage / 10, 1);
                }

                // Completion %: completed gradeable items / gradeable items in category.
                $comp_rate = $gradable_items_in_cat > 0
                    ? round(($items_completed / $gradable_items_in_cat) * 100, 2)
                    : 0;

                // Remove individual student CRM appendage, it's not needed for the Moodle Grades API
                $cat_data['studentGrades'][] = [
                    'userid' => $student->userid,
                    'fullname' => $student->fullname,
                    'username' => $student->username,
                    'percentage' => $percentage,
                    'advancedFilterPercentage' => $advanced_filter_percentage,
                    'completionRate' => $comp_rate, // NEW: Pass the true completion metric to JS
                    'totalEarned' => $total_earned
                ];
            }
        }

        $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
        $result['courses'][] = [
            'courseid' => $courseid,
            'coursename' => $course['fullname'],
            'shortname' => $course['shortname'],
            'summary' => $course_summaries[$courseid] ?? '',
            'cansummaryedit' => $can_edit_course_summary($coursecontext),
            'categories' => array_values($categories_data),
            'studentCount' => count($students),
            'teacherCount' => $course_teacher_count
        ];
    }

    $result['totalStudents'] = count($result['uniqueStudents']);
    $result['totalTeachers'] = count($result['uniqueTeachers']);

    // CRM data is NOT fetched here — it's lazy-loaded by the frontend via getptfdata
    // when the user opens the CRM Data tab. This keeps the initial response fast.

    // FIX: Convert uniqueStudents to a clean array of objects instead of unsetting
    $result['uniqueStudents'] = array_values($result['uniqueStudents']);
    unset($result['uniqueTeachers']);

    \local_batchanalytics\util::write_cached_batch_response($cachekey, $result);
    echo json_encode($result);
    die();
}

if ($action === 'getnewbatchdata') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();

        $dbman = $DB->get_manager();
        $has_bm_batch = $dbman->table_exists('local_bm_batch');
        $has_bm_section = $dbman->table_exists('local_bm_classsection');
        $has_bm_student = $dbman->table_exists('local_bm_student');

        if (!$has_bm_batch) {
            echo json_encode([
                'stats' => [
                    'runningBatches' => 0,
                    'totalBatches' => 0,
                    'completedBatches' => 0,
                    'onlineBatches' => 0,
                    'offlineBatches' => 0,
                    'classMentors' => 0,
                    'labMentors' => 0,
                    'onSchedule' => 0,
                    'delayed' => 0,
                    'early' => 0,
                    'totalStudents' => 0
                ],
                'batches' => [],
                'filters' => [
                    'years' => [],
                    'courses' => [],
                    'modes' => [],
                    'batchNames' => []
                ]
            ]);
            die();
        }

        // Fetch all batches sorted by startdate ASC (oldest date first)
        $batches = $DB->get_records('local_bm_batch', null, 'startdate ASC, id ASC');

        // Fetch all class sections if table exists
        $sectionsbybatch = [];
        if ($has_bm_section) {
            $sections = $DB->get_records('local_bm_classsection', null, 'id ASC');
            foreach ($sections as $sec) {
                $sectionsbybatch[$sec->batchid][] = $sec;
            }
        }

        // Fetch total unique students
        $studentcount = $has_bm_student
            ? (int)$DB->count_records_sql("SELECT COUNT(DISTINCT userid) FROM {local_bm_student}")
            : 0;

        $batchlist = [];
        $onlinecount = 0;
        $offlinecount = 0;
        $hybridcount = 0;
        $onschedulecount = 0;
        $delayedcount = 0;
        $earlycount = 0;
        $classmentors = [];
        $labmentors = [];

        // Helper to resolve mentor IDs to user names and filter invalid placeholders
        $resolve_mentor = static function($val) use ($DB) {
            $val = trim((string)$val);
            if ($val === '' || $val === '0' || $val === '—' || strcasecmp($val, 'none') === 0 || strcasecmp($val, 'null') === 0) {
                return null;
            }
            if (is_numeric($val)) {
                $u = $DB->get_record('user', ['id' => (int)$val, 'deleted' => 0], 'id, firstname, lastname');
                if ($u) {
                    return fullname($u);
                }
            }
            return $val;
        };

        foreach ($batches as $b) {
            $mode = trim((string)($b->deliverymode ?? ''));
            if (strcasecmp($mode, 'Online') === 0) {
                $onlinecount++;
            } else if (strcasecmp($mode, 'Offline') === 0) {
                $offlinecount++;
            } else {
                $hybridcount++;
            }

            $batch_sections = $sectionsbybatch[$b->id] ?? [];
            $sec = !empty($batch_sections) ? $batch_sections[0] : null;

            $currentmodule = 'N/A';
            $currentmoduleidx = 1;
            $currentcourseid = 0;
            $status = 'on_schedule';
            $statuslabel = 'On schedule';
            $delaydays = 0;
            $totaldelta = 0;
            $batch_has_modules = false;
            $batch_all_completed = true;
            $last_valid_module = null;

            foreach ($batch_sections as $curr_sec) {
                $modules = \local_batchanalytics\util::decode_module_data($curr_sec->moduledata ?? '', true);
                if (empty($modules)) {
                    continue;
                }
                $batch_has_modules = true;

                foreach ($modules as $m) {
                    $last_valid_module = $m;

                    // Class Mentors
                    foreach (['primarymentor', 'secondarymentor'] as $cmk) {
                        if (!empty($m[$cmk])) {
                            $resolved = $resolve_mentor($m[$cmk]);
                            if ($resolved !== null) {
                                $classmentors[$resolved] = true;
                            }
                        }
                    }

                    // Lab Mentors
                    foreach (['labmentor1', 'labmentor2', 'labmentor3'] as $lmk) {
                        if (!empty($m[$lmk])) {
                            $resolved = $resolve_mentor($m[$lmk]);
                            if ($resolved !== null) {
                                $labmentors[$resolved] = true;
                            }
                        }
                    }

                    // Accumulate schedule delta from all modules in the batch
                    if (isset($m['scheduledelta']) && is_numeric($m['scheduledelta'])) {
                        $totaldelta += (int)$m['scheduledelta'];
                    } else if (!empty($m['actualend']) && !empty($m['plannedend']) && is_numeric($m['actualend']) && is_numeric($m['plannedend']) && (int)$m['actualend'] > 100000 && (int)$m['plannedend'] > 100000) {
                        $diffdays = (int)round(((int)$m['actualend'] - (int)$m['plannedend']) / 86400);
                        $totaldelta += $diffdays;
                    }

                    $isdone = (!empty($m['actualend']) && (int)$m['actualend'] > 0);
                    if (!$isdone) {
                        $batch_all_completed = false;
                    }

                    if ($currentmodule === 'N/A' && (!$isdone || count($modules) === 1)) {
                        $mod_name = !empty($m['name']) ? $m['name'] : (!empty($m['courseshortname']) ? $m['courseshortname'] : ('Module ' . $m['module']));
                        $currentmodule = $mod_name;
                        $currentmoduleidx = (int)$m['module'];
                        $currentcourseid = (int)($m['moodlecourseid'] ?? 0);
                    }
                }
            }

            if ($batch_has_modules && $currentmodule === 'N/A' && $last_valid_module !== null) {
                $currentmodule = !empty($last_valid_module['name']) ? $last_valid_module['name'] : 'Completed';
                $currentmoduleidx = (int)$last_valid_module['module'];
                $currentcourseid = (int)($last_valid_module['moodlecourseid'] ?? 0);
            }

            $is_completed = ($batch_has_modules && $batch_all_completed);

            // Determine status based on total accumulated schedule delta
            if ($totaldelta > 0) {
                $status = 'delayed';
                $delaydays = $totaldelta;
                $statuslabel = 'Delayed by ' . $totaldelta . ' day' . ($totaldelta > 1 ? 's' : '');
                $delayedcount++;
            } else if ($totaldelta < 0) {
                $status = 'early';
                $delaydays = $totaldelta;
                $earlydays = abs($totaldelta);
                $statuslabel = 'Early by ' . $earlydays . ' day' . ($earlydays > 1 ? 's' : '');
                $earlycount++;
            } else {
                $status = 'on_schedule';
                $delaydays = 0;
                $statuslabel = 'On schedule';
                $onschedulecount++;
            }

            $batchstudents = $has_bm_student
                ? (int)$DB->count_records_sql("SELECT COUNT(DISTINCT userid) FROM {local_bm_student} WHERE batchid = :bid", ['bid' => $b->id])
                : 0;

            $startformatted = !empty($b->startdate) ? userdate($b->startdate, '%d %b %Y') : 'N/A';
            $year = !empty($b->startdate) ? userdate($b->startdate, '%Y') : date('Y');

            $batchlist[] = [
                'id' => (int)$b->id,
                'batchId' => (string)($b->name ?? ''),
                'courseName' => (string)($b->coursename ?? ''),
                'mode' => !empty($b->deliverymode) ? ucfirst(strtolower($b->deliverymode)) : 'Offline',
                'type' => !empty($b->submode) ? ucfirst(strtolower($b->submode)) : 'Regular',
                'startDate' => $startformatted,
                'year' => $year,
                'startdateTimestamp' => !empty($b->startdate) ? (int)$b->startdate : 0,
                'currentModule' => $currentmodule,
                'moduleIdx' => $currentmoduleidx,
                'courseId' => $currentcourseid,
                'status' => $status,
                'statusLabel' => $statuslabel,
                'delayDays' => $delaydays,
                'studentCount' => $batchstudents,
                'isCompleted' => $is_completed,
                'sectionId' => $sec ? (int)$sec->id : (int)$b->id
            ];
        }

        // Sort batchlist with oldest date first (ascending order)
        usort($batchlist, static function($a, $b) {
            $tsA = (int)($a['startdateTimestamp'] ?? 0);
            $tsB = (int)($b['startdateTimestamp'] ?? 0);
            if ($tsA === $tsB) {
                return $a['id'] <=> $b['id'];
            }
            if ($tsA === 0) {
                return 1;
            }
            if ($tsB === 0) {
                return -1;
            }
            return $tsA <=> $tsB; // Oldest date first
        });

        $activebatches = 0;
        $completedbatches = 0;
        foreach ($batchlist as $bl) {
            if ($bl['isCompleted']) {
                $completedbatches++;
            } else {
                $activebatches++;
            }
        }

        $years = array_values(array_unique(array_filter(array_column($batchlist, 'year'))));
        sort($years);
        $courses = array_values(array_unique(array_filter(array_column($batchlist, 'courseName'))));
        sort($courses);
        $modes = array_values(array_unique(array_filter(array_column($batchlist, 'mode'))));
        sort($modes);
        $batchnames = array_values(array_unique(array_filter(array_column($batchlist, 'batchId'))));
        sort($batchnames);

        echo json_encode([
            'stats' => [
                'runningBatches' => $activebatches,
                'totalBatches' => count($batchlist),
                'completedBatches' => $completedbatches,
                'onlineBatches' => $onlinecount,
                'offlineBatches' => $offlinecount,
                'classMentors' => count($classmentors),
                'labMentors' => count($labmentors),
                'onSchedule' => $onschedulecount,
                'delayed' => $delayedcount,
                'early' => $earlycount,
                'totalStudents' => $studentcount
            ],
            'batches' => $batchlist,
            'filters' => [
                'years' => $years,
                'courses' => $courses,
                'modes' => $modes,
                'batchNames' => $batchnames
            ]
        ]);
        die();
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
        die();
    }
}

if ($action === 'get_task_data') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        global $USER, $DB;
        $currentuserid = (int)$USER->id;
        $user_fullname = trim(fullname($USER));

        $ssteamroleid = (int)get_config('local_batchanalytics', 'ss_team_role');
        $batchmanagerroleid = (int)get_config('local_batchanalytics', 'batch_manager_role');

        $is_siteadmin = is_siteadmin($currentuserid);
        $can_manage_all = $is_siteadmin || has_capability('local/batchanalytics:manage', $context);

        // Check user roles
        $has_ssteam_role = false;
        if ($ssteamroleid > 0) {
            $has_ssteam_role = $DB->record_exists('role_assignments', ['roleid' => $ssteamroleid, 'userid' => $currentuserid]);
        }
        $assigned_as_ssteam = $DB->record_exists_select('local_bm_classsection', "maacexecutive = :uid1 OR maacexecutivename = :fn1", ['uid1' => (string)$currentuserid, 'fn1' => $user_fullname]);
        $is_ssteam_user = $has_ssteam_role || $assigned_as_ssteam;

        $has_bm_role = false;
        if ($batchmanagerroleid > 0) {
            $has_bm_role = $DB->record_exists('role_assignments', ['roleid' => $batchmanagerroleid, 'userid' => $currentuserid]);
        }
        $assigned_as_pm = $DB->record_exists_select('local_bm_classsection', "pmmanager = :uid2 OR pmmanagername = :fn2", ['uid2' => (string)$currentuserid, 'fn2' => $user_fullname]);
        $is_bm_user = $has_bm_role || $assigned_as_pm;

        // Build role switcher options
        $available_roles = [];
        if ($can_manage_all || ($is_ssteam_user && $is_bm_user)) {
            $available_roles[] = ['id' => 'sse', 'label' => 'MAAC Executive'];
            $available_roles[] = ['id' => 'pm', 'label' => 'Batch Manager'];
            $available_roles[] = ['id' => 'all', 'label' => 'All Batches'];
        } else if ($is_ssteam_user) {
            $available_roles[] = ['id' => 'sse', 'label' => 'MAAC Executive'];
        } else if ($is_bm_user) {
            $available_roles[] = ['id' => 'pm', 'label' => 'Batch Manager'];
        } else {
            $available_roles[] = ['id' => 'all', 'label' => 'All Batches'];
        }

        $view_role = optional_param('view_role', 'auto', PARAM_ALPHA);
        if ($view_role === 'auto' || empty($view_role)) {
            if ($is_ssteam_user && !$is_bm_user) {
                $view_role = 'sse';
            } else if ($is_bm_user && !$is_ssteam_user) {
                $view_role = 'pm';
            } else if ($is_ssteam_user) {
                $view_role = 'sse';
            } else if ($is_bm_user) {
                $view_role = 'pm';
            } else {
                $view_role = 'all';
            }
        }

        // Query sections
        $params = [];
        $where_clauses = [];

        if (!$can_manage_all && $view_role === 'sse') {
            $where_clauses[] = "(s.maacexecutive = :userid OR s.maacexecutivename = :fullname)";
            $params['userid'] = (string)$currentuserid;
            $params['fullname'] = $user_fullname;
        } else if (!$can_manage_all && $view_role === 'pm') {
            $where_clauses[] = "(s.pmmanager = :userid OR s.pmmanagername = :fullname)";
            $params['userid'] = (string)$currentuserid;
            $params['fullname'] = $user_fullname;
        }

        $wsql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
        $sql = "
            SELECT s.id, s.name as sectionname, s.batchid, b.name as batchname, b.coursename,
                   s.maacexecutive, s.maacexecutivename, s.pmmanager, s.pmmanagername, s.softskillsdata
            FROM {local_bm_classsection} s
            LEFT JOIN {local_bm_batch} b ON b.id = s.batchid
            {$wsql}
            ORDER BY s.id ASC
        ";
        $sections = $DB->get_records_sql($sql, $params);

        // If specific user filter returned empty (e.g. name formatting mismatch), fallback to all assigned sections
        if (empty($sections) && !$can_manage_all) {
            $sql_fallback = "
                SELECT s.id, s.name as sectionname, s.batchid, b.name as batchname, b.coursename,
                       s.maacexecutive, s.maacexecutivename, s.pmmanager, s.pmmanagername, s.softskillsdata
                FROM {local_bm_classsection} s
                LEFT JOIN {local_bm_batch} b ON b.id = s.batchid
                ORDER BY s.id ASC
            ";
            $sections = $DB->get_records_sql($sql_fallback);
        }

        // 26 soft skill activities defined in batch management
        $softskills_items = [
            ['key' => 'ss_induction', 'label' => 'SS Induction'],
            ['key' => 'softskill_1', 'label' => 'Soft skill 1'],
            ['key' => 'placement_induction', 'label' => 'Placement Induction'],
            ['key' => 'softskill_2', 'label' => 'Soft skill 2'],
            ['key' => 'softskill_3', 'label' => 'Soft skill 3'],
            ['key' => 'motivation_talk_pms', 'label' => 'Motivation Talk by PMs'],
            ['key' => 'softskill_4', 'label' => 'Soft skill 4'],
            ['key' => 'softskill_5', 'label' => 'Soft skill 5'],
            ['key' => 'softskill_6', 'label' => 'Soft skill 6'],
            ['key' => 'feedback_1', 'label' => 'Feed back 1'],
            ['key' => 'pet_scheduling_announcement', 'label' => 'PET Scheduling and Announcement'],
            ['key' => 'softskill_7', 'label' => 'Soft skill 7'],
            ['key' => 'feedback_2', 'label' => 'Feed back 2'],
            ['key' => 'softskill_8', 'label' => 'Soft skill 8'],
            ['key' => 'pet_1', 'label' => 'PET 1'],
            ['key' => 'disha_1', 'label' => 'Disha 1'],
            ['key' => 'disha_2', 'label' => 'Disha 2'],
            ['key' => 'disha_3', 'label' => 'Disha 3'],
            ['key' => 'feedback_3', 'label' => 'Feed back 3'],
            ['key' => 'softskill_9', 'label' => 'Soft skill 9'],
            ['key' => 'pet_2', 'label' => 'PET 2'],
            ['key' => 'softskill_10', 'label' => 'Soft skill 10'],
            ['key' => 'pet_3', 'label' => 'PET 3'],
            ['key' => 'softskill_11', 'label' => 'Soft skill 11'],
            ['key' => 'softskill_12', 'label' => 'Soft skill 12'],
            ['key' => 'closure_certificate_distribution', 'label' => 'Closure & Certificate distribution'],
        ];

        $today_start = strtotime('today midnight');
        $today_end = $today_start + 86400;
        $week_end = $today_start + (7 * 86400);

        $todos = [];
        $forthcoming = [];
        $overdue_count = 0;
        $due_this_week_count = 0;
        $batch_ids = [];
        $section_ids = [];

        foreach ($sections as $s) {
            $batch_ids[$s->batchid] = true;
            $section_ids[$s->id] = true;

            $ssdata = json_decode($s->softskillsdata ?? '{}', true);
            if (!is_array($ssdata)) {
                continue;
            }

            foreach ($softskills_items as $item) {
                $k = $item['key'];
                $planned = (int)($ssdata[$k . '_planned'] ?? 0);
                $actual = (int)($ssdata[$k . '_actual'] ?? 0);

                if ($planned <= 0 || $actual > 0) {
                    continue; // Skip unplanned or already completed
                }

                $days_diff = (int)floor(($planned - $today_start) / 86400);

                $task_item = [
                    'section_id' => (int)$s->id,
                    'batch_id' => (int)$s->batchid,
                    'batch_name' => $s->batchname ?: 'Batch ' . $s->batchid,
                    'section_name' => $s->sectionname,
                    'activity_key' => $k,
                    'activity_label' => $item['label'],
                    'planned_timestamp' => $planned,
                    'planned_date_formatted' => date('d M Y', $planned),
                ];

                if ($planned < $today_start) {
                    // Overdue
                    $days_over = max(1, abs($days_diff));
                    $task_item['due_class'] = 'over';
                    $task_item['due_text'] = "Overdue {$days_over}d";
                    $task_item['sort_order'] = 1000000000 + $planned;
                    $todos[] = $task_item;
                    $overdue_count++;
                } else if ($planned < $today_end) {
                    // Due today
                    $task_item['due_class'] = 'today';
                    $task_item['due_text'] = 'Due today';
                    $task_item['sort_order'] = 2000000000 + $planned;
                    $todos[] = $task_item;
                    $due_this_week_count++;
                } else if ($planned <= $week_end) {
                    // Due in next 7 days
                    $days_due = max(1, $days_diff);
                    $task_item['due_class'] = 'soon';
                    $task_item['due_text'] = "Due in {$days_due}d";
                    $task_item['sort_order'] = 3000000000 + $planned;
                    $todos[] = $task_item;
                    $due_this_week_count++;

                    $fc_item = $task_item;
                    $fc_item['time_relative'] = "in {$days_due} days";
                    $forthcoming[] = $fc_item;
                } else {
                    // Forthcoming beyond 7 days
                    $days_due = $days_diff;
                    $task_item['time_relative'] = "in {$days_due} days";
                    $task_item['sort_order'] = 4000000000 + $planned;
                    if (count($forthcoming) < 10) {
                        $forthcoming[] = $task_item;
                    }
                }
            }
        }

        // Sort todos by urgency: overdue first (oldest to newest), then due today, then due soon
        usort($todos, function($a, $b) {
            return $a['sort_order'] <=> $b['sort_order'];
        });

        // Sort forthcoming chronologically
        usort($forthcoming, function($a, $b) {
            return $a['planned_timestamp'] <=> $b['planned_timestamp'];
        });

        // Student count
        $total_students = 0;
        if (!empty($section_ids)) {
            list($sec_in, $sec_params) = $DB->get_in_or_equal(array_keys($section_ids));
            $total_students = (int)$DB->count_records_select('local_bm_student', "classsectionid $sec_in", $sec_params);
        }

        // Subtitle text
        $batch_count = count($batch_ids);
        if ($view_role === 'sse') {
            $subtitle = "SS / MAAC Executive · {$batch_count} batches assigned";
        } else if ($view_role === 'pm') {
            $subtitle = "Batch Manager · {$batch_count} batches overseen";
        } else {
            $subtitle = "Portfolio Overview · {$batch_count} batches monitored";
        }

        echo json_encode([
            'user' => [
                'id' => $currentuserid,
                'name' => $user_fullname,
                'firstname' => $USER->firstname,
            ],
            'role_info' => [
                'current_role' => $view_role,
                'available_roles' => $available_roles,
                'subtitle' => $subtitle,
                'hint' => 'Role configured in Settings'
            ],
            'glance' => [
                'due_this_week' => $due_this_week_count,
                'overdue' => $overdue_count,
                'batches' => $batch_count,
                'students' => number_format($total_students),
            ],
            'todos' => $todos,
            'forthcoming' => array_slice($forthcoming, 0, 10),
        ]);
        die();
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
        die();
    }
}

if ($action === 'complete_task') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new moodle_exception('invalidrequest');
        }
        require_sesskey();

        $sectionid = required_param('sectionid', PARAM_INT);
        $activitykey = required_param('activity_key', PARAM_ALPHANUMEXT);

        $section = $DB->get_record('local_bm_classsection', ['id' => $sectionid]);
        if (!$section) {
            throw new moodle_exception('invalidrecord', 'local_batchanalytics', '', 'Section not found');
        }

        $softskills = json_decode($section->softskillsdata ?? '{}', true);
        if (!is_array($softskills)) {
            $softskills = [];
        }

        $actual_time = time();
        $actual_field = $activitykey . '_actual';
        $softskills[$actual_field] = $actual_time;

        if (class_exists('\local_batchmanagement\classsection')) {
            try {
                $obj = (object)[
                    'softskillsdata' => json_encode($softskills),
                ];
                \local_batchmanagement\classsection::update($sectionid, $obj);
            } catch (\Throwable $te) {
                $upd = new \stdClass();
                $upd->id = $sectionid;
                $upd->softskillsdata = json_encode($softskills);
                $upd->timemodified = $actual_time;
                $DB->update_record('local_bm_classsection', $upd);
            }
        } else {
            $upd = new \stdClass();
            $upd->id = $sectionid;
            $upd->softskillsdata = json_encode($softskills);
            $upd->timemodified = $actual_time;
            $DB->update_record('local_bm_classsection', $upd);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Activity marked as completed.',
            'sectionid' => $sectionid,
            'activity_key' => $activitykey,
            'actual_timestamp' => $actual_time,
            'actual_formatted' => date('d M Y, H:i', $actual_time)
        ]);
        die();
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
        die();
    }
}

// ... (HTML Page rendering) ...

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/index.php'));
$PAGE->set_title(get_string('pluginname', 'local_batchanalytics'));
$PAGE->set_heading('');

$styleurl = new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime(__DIR__ . '/styles.css')]);
$newstyleurl = new moodle_url('/local/batchanalytics/new_analytics.css', ['v' => filemtime(__DIR__ . '/new_analytics.css')]);
$taskstyleurl = new moodle_url('/local/batchanalytics/task_dashboard.css', ['v' => filemtime(__DIR__ . '/task_dashboard.css')]);
$scripturl = new moodle_url('/local/batchanalytics/simple.js', ['v' => filemtime(__DIR__ . '/simple.js')]);
$newscripturl = new moodle_url('/local/batchanalytics/new_analytics.js', ['v' => filemtime(__DIR__ . '/new_analytics.js')]);
$taskscripturl = new moodle_url('/local/batchanalytics/task_dashboard.js', ['v' => filemtime(__DIR__ . '/task_dashboard.js')]);

$PAGE->requires->css($styleurl);
$PAGE->requires->css($newstyleurl);
$PAGE->requires->css($taskstyleurl);
$PAGE->requires->js($scripturl);
$PAGE->requires->js($newscripturl);
$PAGE->requires->js($taskscripturl);

echo $OUTPUT->header();

$crm_fields_config = \local_batchanalytics\crm_fields_helper::get_fields();
$mentor_crm_fields_config = \local_batchanalytics\crm_fields_helper::get_mentor_fields();
$mentor_crm_groups_config = \local_batchanalytics\crm_fields_helper::get_mentor_field_groups();
$maac_sync_columns = \local_batchanalytics\maac_columns_helper::get_sync_columns();
echo '<div class="local-batchanalytics-wrap" data-can-manage="' . ($can_manage ? '1' : '0') . '" data-import-maac-enabled="' . ((int)get_config('local_batchanalytics', 'import_maac_sheet') ? '1' : '0') . '" data-can-view-all-courses="' . ($can_view_all_courses ? '1' : '0') . '" data-can-view-tickets="' . ($can_view_tickets ? '1' : '0') . '" data-crm-fields="' . htmlspecialchars(json_encode($crm_fields_config), ENT_QUOTES) . '" data-mentor-crm-fields="' . htmlspecialchars(json_encode($mentor_crm_fields_config), ENT_QUOTES) . '" data-mentor-crm-groups="' . htmlspecialchars(json_encode($mentor_crm_groups_config), ENT_QUOTES) . '" data-maac-sync-columns="' . htmlspecialchars(json_encode($maac_sync_columns), ENT_QUOTES) . '" data-sesskey="' . sesskey() . '">';
echo '<div id="ba-toast-container" class="ba-toast-container"></div>';

// Top-Level Primary Navigation Bar (Task | New Batch Analytics | Batch Analytics)
echo '<div class="ba-top-nav-tabs-bar">';
echo '  <button type="button" class="ba-top-nav-tab active" data-top-tab="task"><span class="ba-tab-icon">📋</span> Task</button>';
echo '  <button type="button" class="ba-top-nav-tab" data-top-tab="new"><span class="ba-tab-icon">⚡</span> New Batch Analytics</button>';
echo '  <button type="button" class="ba-top-nav-tab" data-top-tab="old"><span class="ba-tab-icon">📁</span> Batch Analytics</button>';
echo '</div>';

// TASK TAB PANE (Active by default on left)
echo '<div id="ba-top-tab-task" class="ba-top-tab-pane active">';
echo '  <div id="task-dashboard-root"></div>';
echo '</div>';

// NEW BATCH ANALYTICS TAB PANE
echo '<div id="ba-top-tab-new" class="ba-top-tab-pane" style="display:none">';
echo '  <div class="ba-new-dashboard">';

echo '    <!-- Stat Cards Grid (7 Cards: 3 Delivery Cards + 4 Metrics Cards) -->';
echo '    <div class="ba-new-stats-container" id="ba-new-stats-grid">';
echo '      <div class="ba-stats-row-top">';
echo '        <div class="ba-new-stat-card card-blue">';
echo '          <div class="ba-new-stat-header">';
echo '            <span class="ba-new-stat-title">Running Batches</span>';
echo '            <span class="ba-new-stat-icon icon-blue">📊</span>';
echo '          </div>';
echo '          <div class="ba-new-stat-value" id="stat-running-batches">--</div>';
echo '          <div class="ba-new-stat-footer">Total Active Batches</div>';
echo '        </div>';

echo '        <div class="ba-new-stat-card card-green">';
echo '          <div class="ba-new-stat-header">';
echo '            <span class="ba-new-stat-title">Online Batches</span>';
echo '            <span class="ba-new-stat-icon icon-green">💻</span>';
echo '          </div>';
echo '          <div class="ba-new-stat-value" id="stat-online-batches">--</div>';
echo '          <div class="ba-new-stat-footer">Virtual Classrooms</div>';
echo '        </div>';

echo '        <div class="ba-new-stat-card card-purple">';
echo '          <div class="ba-new-stat-header">';
echo '            <span class="ba-new-stat-title">Offline Batches</span>';
echo '            <span class="ba-new-stat-icon icon-purple">🏫</span>';
echo '          </div>';
echo '          <div class="ba-new-stat-value" id="stat-offline-batches">--</div>';
echo '          <div class="ba-new-stat-footer">In-Person Campus</div>';
echo '        </div>';
echo '      </div>';

echo '      <div class="ba-stats-row-bottom">';
echo '        <div class="ba-new-stat-card card-teal">';
echo '          <div class="ba-new-stat-header">';
echo '            <span class="ba-new-stat-title">Class Mentors</span>';
echo '            <span class="ba-new-stat-icon icon-teal">👨‍🏫</span>';
echo '          </div>';
echo '          <div class="ba-new-stat-value" id="stat-class-mentors">--</div>';
echo '          <div class="ba-new-stat-footer">Active Instructors</div>';
echo '        </div>';

echo '        <div class="ba-new-stat-card card-indigo">';
echo '          <div class="ba-new-stat-header">';
echo '            <span class="ba-new-stat-title">Lab Mentors</span>';
echo '            <span class="ba-new-stat-icon icon-indigo">🔬</span>';
echo '          </div>';
echo '          <div class="ba-new-stat-value" id="stat-lab-mentors">--</div>';
echo '          <div class="ba-new-stat-footer">Technical Assistants</div>';
echo '        </div>';

echo '        <div class="ba-new-stat-card card-emerald">';
echo '          <div class="ba-new-stat-header">';
echo '            <span class="ba-new-stat-title">Schedule Status</span>';
echo '            <span class="ba-new-stat-icon icon-emerald">⏱️</span>';
echo '          </div>';
echo '          <div class="ba-new-stat-value"><span id="stat-on-schedule" class="text-success" title="On Schedule">--</span> <span class="stat-sep">/</span> <span id="stat-delayed" class="text-danger" title="Delayed">--</span> <span class="stat-sep">/</span> <span id="stat-early" class="text-primary" title="Early">--</span></div>';
echo '          <div class="ba-new-stat-footer"><span class="badge-status-dot dot-green"></span> On Schedule / <span class="badge-status-dot dot-red"></span> Delayed / <span class="badge-status-dot dot-blue"></span> Early</div>';
echo '        </div>';

echo '        <div class="ba-new-stat-card card-orange">';
echo '          <div class="ba-new-stat-header">';
echo '            <span class="ba-new-stat-title">Total Students</span>';
echo '            <span class="ba-new-stat-icon icon-orange">👥</span>';
echo '          </div>';
echo '          <div class="ba-new-stat-value" id="stat-total-students">--</div>';
echo '          <div class="ba-new-stat-footer">Enrolled Learners</div>';
echo '        </div>';
echo '      </div>';
echo '    </div>';

echo '    <!-- Search & Filters Controls Card -->';
echo '    <div class="ba-new-card ba-new-controls-card">';
echo '      <div class="ba-new-controls-header">';
echo '        <div class="ba-controls-title-wrap">';
echo '          <span class="ba-controls-badge">⚡ Filter & Search</span>';
echo '          <span class="ba-controls-subtitle">Filter cohorts across programs, dates, and delivery modes</span>';
echo '        </div>';
echo '        <button id="ba-new-reset-btn" type="button" class="ba-new-btn-secondary" title="Clear all active filters"><span class="btn-icon">🔄</span> Reset Filters</button>';
echo '      </div>';
echo '      <div class="ba-new-controls-body">';
echo '        <div class="ba-new-search-box">';
echo '          <span class="ba-search-icon">🔍</span>';
echo '          <input type="text" id="ba-new-search-input" placeholder="Search by batch name, course title, or module..." />';
echo '        </div>';
echo '        <div class="ba-new-filters-row">';
echo '          <div class="ba-new-filter-group">';
echo '            <label for="ba-filter-year">Year</label>';
echo '            <select id="ba-filter-year" class="ba-new-select"><option value="">All Years</option></select>';
echo '          </div>';
echo '          <div class="ba-new-filter-group">';
echo '            <label for="ba-filter-batch">Batch No.</label>';
echo '            <select id="ba-filter-batch" class="ba-new-select"><option value="">All Batches</option></select>';
echo '          </div>';
echo '          <div class="ba-new-filter-group">';
echo '            <label for="ba-filter-course">Course</label>';
echo '            <select id="ba-filter-course" class="ba-new-select"><option value="">All Courses</option></select>';
echo '          </div>';
echo '          <div class="ba-new-filter-group">';
echo '            <label for="ba-filter-mode">Mode</label>';
echo '            <select id="ba-filter-mode" class="ba-new-select"><option value="">All Modes</option></select>';
echo '          </div>';
echo '        </div>';
echo '      </div>';
echo '    </div>';

echo '    <!-- Main Table Container -->';
echo '    <div class="ba-new-card ba-new-table-card">';
echo '      <div class="ba-new-subtabs-wrap">';
echo '        <div class="ba-new-subtabs">';
echo '          <button type="button" class="ba-new-subtab active" data-subtab="running">';
echo '            <span class="subtab-dot dot-running"></span> Current Running Batches <span class="ba-count-pill" id="ba-cnt-running">0</span>';
echo '          </button>';
echo '          <button type="button" class="ba-new-subtab" data-subtab="completed">';
echo '            <span class="subtab-dot dot-completed"></span> Completed Batches <span class="ba-count-pill" id="ba-cnt-completed">0</span>';
echo '          </button>';
echo '        </div>';
echo '      </div>';

echo '      <div class="ba-new-table-wrapper">';
echo '        <table class="ba-new-table" id="ba-new-batch-table">';
echo '          <thead>';
echo '            <tr>';
echo '              <th style="width:50px;">SI.</th>';
echo '              <th>Batch ID</th>';
echo '              <th>Course Name</th>';
echo '              <th>Mode</th>';
echo '              <th>Batch Type</th>';
echo '              <th>Start Date</th>';
echo '              <th>Current Running Module</th>';
echo '              <th>Status</th>';
echo '              <th style="text-align:center;">Action</th>';
echo '            </tr>';
echo '          </thead>';
echo '          <tbody id="ba-new-table-body">';
echo '            <tr>';
echo '              <td colspan="9" class="ba-new-loading">Loading batch analytics...</td>';
echo '            </tr>';
echo '          </tbody>';
echo '        </table>';
echo '      </div>';

echo '      <!-- Pagination Footer -->';
echo '      <div class="ba-new-pagination-bar">';
echo '        <div class="ba-new-pagination-info" id="ba-new-pagination-info">';
echo '          Showing 0-0 of 0 entries';
echo '        </div>';
echo '        <div class="ba-new-pagination-controls" id="ba-new-pagination-controls">';
echo '        </div>';
echo '      </div>';
echo '    </div>';

echo '  </div>';
echo '</div>'; // #ba-top-tab-new

// OLD BATCH ANALYTICS TAB PANE
echo '<div id="ba-top-tab-old" class="ba-top-tab-pane" style="display:none">';
echo '  <div class="ba-top-row">';
echo '    <div class="ba-search-row">';
$search_placeholder = 'Search course name (e.g., Advanced C)...';
echo '      <input id="ba-search" type="text" placeholder="' . $search_placeholder . '">';
echo '      <button id="ba-search-btn" class="ba-btn">Search</button>';
echo '    </div>';
echo '    <div class="ba-top-actions">';
if ($can_view_tickets) {
    echo '      <a href="' . new moodle_url('/local/batchanalytics/tickets.php') . '" class="ba-btn ba-btn-view">' . get_string('ticket_dashboard', 'local_batchanalytics') . '</a>';
}
echo '    </div>';
echo '  </div>';

echo '  <div class="ba-batch-card">';
echo '    <label class="ba-label">SELECT BATCH GROUP</label>';
echo '    <select id="ba-batch" class="ba-select">';
echo '      <option value="">-- Select a Batch --</option>';
echo '    </select>';
echo '  </div>';

echo '  <div id="ba-tabs-wrapper" class="ba-tabs-wrapper" style="display:none">';
echo '    <ul class="ba-tabs-nav" id="batchTabs"></ul>';
echo '    <div class="ba-tabs-content" id="batchTabsContent"></div>';
echo '  </div>';
echo '</div>'; // #ba-top-tab-old

echo '</div>'; // .local-batchanalytics-wrap

echo $OUTPUT->footer();
?>
