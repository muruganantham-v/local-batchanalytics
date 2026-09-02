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

$can_view_tickets = $can_manage || \local_batchanalytics\util::has_any_course_capability($userid, [
    'local/batchanalytics:viewtickets',
    'local/batchanalytics:managetickets',
    'local/batchanalytics:manageescalatedtickets',
]);

// The Ticket Dashboard also grants access to users assigned the configured
// Batch Manager role directly in a visible course.
if (!$can_view_tickets) {
    $batchmanagerroleid = (int)get_config('local_batchanalytics', 'batch_manager_role');
    if ($batchmanagerroleid > 0) {
        $can_view_tickets = $DB->record_exists_sql(
            "SELECT 1
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {course} c ON c.id = ctx.instanceid
              WHERE ra.userid = :userid
                AND ra.roleid = :roleid
                AND ctx.contextlevel = :coursecontextlevel
                AND c.visible = 1",
            [
                'userid' => $userid,
                'roleid' => $batchmanagerroleid,
                'coursecontextlevel' => CONTEXT_COURSE,
            ]
        );
    }
}

// Load restricted CRM fields for view-only users.
$restricted_crm_fields = [];
if (!$can_manage) {
    $restricted_crm_fields = \local_batchanalytics\crm_fields_helper::get_restricted_keys();
}

$action = optional_param('action', '', PARAM_ALPHA);

// Course teachers can maintain the summary for courses to which they are assigned.
$can_edit_course_summary = static function($coursecontext) use ($can_manage, $userid, $DB): bool {
    if (!$coursecontext) {
        return false;
    }
    if ($can_manage || has_capability('local/batchanalytics:editmaac', $coursecontext, $userid)) {
        return true;
    }

    return $DB->record_exists_sql(
        "SELECT 1
           FROM {role_assignments} ra
           JOIN {role} r ON r.id = ra.roleid
          WHERE ra.userid = :userid
            AND ra.contextid = :contextid
            AND r.shortname IN ('teacher', 'editingteacher')",
        ['userid' => $userid, 'contextid' => $coursecontext->id]
    );
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

            $record = $crm->get_student_details($username);
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
        set_config('course_summary_' . $courseid, $summary, 'local_batchanalytics');
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
        echo json_encode([
            'username' => $username,
            'placed_company' => 'Error',
            'debug' => 'An error occurred fetching CRM data'
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
        echo json_encode(['error' => 'An error occurred fetching CRM data']);
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

    // Pre-fetch all teachers for all batch courses to avoid N+1 queries
    $teachers_by_course = [];
    if (!empty($courses)) {
        $courseids = array_column($courses, 'courseid');
        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'tid');
        $teachers_sql = "
            SELECT DISTINCT ctx.instanceid as courseid, u.id as userid, u.firstname, u.lastname
            FROM {user} u
            JOIN {role_assignments} ra ON ra.userid = u.id
            JOIN {context} ctx ON ctx.id = ra.contextid
            WHERE ctx.instanceid $insql
              AND ctx.contextlevel = 50
              AND ra.roleid IN (3, 4)
              AND u.deleted = 0
        ";
        $teachers_rs = $DB->get_recordset_sql($teachers_sql, $inparams);
        if ($teachers_rs->valid()) {
            foreach ($teachers_rs as $t) {
                $teachers_by_course[$t->courseid][] = $t;
            }
        }
        $teachers_rs->close();
    }

    foreach ($courses as $course) {
        $courseid = $course['courseid'];

        // 1. Get Teachers (From pre-fetched map)
        $teachers_raw = $teachers_by_course[$courseid] ?? [];

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
            WHERE gi.courseid = :courseid AND gg.finalgrade IS NOT NULL
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
            $total_items_in_cat = count($cat_data['items']); // NEW: Get total assignments (e.g. 27)
            $category_total_max = 0;

            foreach ($cat_data['items'] as $item) {
                $gmax = $item['grademax'];
                $gmin = $item['grademin'];
                if ($gmax > $gmin) {
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
                        $items_completed++; // Student submitted this item

                        $gmax = $item['grademax'];
                        $gmin = $item['grademin'];
                        if ($gmax > $gmin) {
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

                // Completion %: Items Completed / Total Items in Category (e.g., 10/27 = 37.04%)
                $comp_rate = $total_items_in_cat > 0 ? round(($items_completed / $total_items_in_cat) * 100, 2) : 0;

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
            'summary' => (string)get_config('local_batchanalytics', 'course_summary_' . $courseid),
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

// ... (HTML Page rendering remains the same) ...

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/index.php'));
$PAGE->set_title(get_string('pluginname', 'local_batchanalytics'));
$PAGE->set_heading(get_string('pluginname', 'local_batchanalytics'));

$styleurl = new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime(__DIR__ . '/styles.css')]);
$scripturl = new moodle_url('/local/batchanalytics/simple.js', ['v' => filemtime(__DIR__ . '/simple.js')]);
$PAGE->requires->css($styleurl);
$PAGE->requires->js($scripturl);

echo $OUTPUT->header();

$crm_fields_config = \local_batchanalytics\crm_fields_helper::get_fields();
$mentor_crm_fields_config = \local_batchanalytics\crm_fields_helper::get_mentor_fields();
$mentor_crm_groups_config = \local_batchanalytics\crm_fields_helper::get_mentor_field_groups();
$maac_sync_columns = \local_batchanalytics\maac_columns_helper::get_sync_columns();
echo '<div class="local-batchanalytics-wrap" data-can-manage="' . ($can_manage ? '1' : '0') . '" data-import-maac-enabled="' . ((int)get_config('local_batchanalytics', 'import_maac_sheet') ? '1' : '0') . '" data-can-view-all-courses="' . ($can_view_all_courses ? '1' : '0') . '" data-can-view-tickets="' . ($can_view_tickets ? '1' : '0') . '" data-crm-fields="' . htmlspecialchars(json_encode($crm_fields_config), ENT_QUOTES) . '" data-mentor-crm-fields="' . htmlspecialchars(json_encode($mentor_crm_fields_config), ENT_QUOTES) . '" data-mentor-crm-groups="' . htmlspecialchars(json_encode($mentor_crm_groups_config), ENT_QUOTES) . '" data-maac-sync-columns="' . htmlspecialchars(json_encode($maac_sync_columns), ENT_QUOTES) . '" data-sesskey="' . sesskey() . '">';
// echo '<h2 class="ba-page-title">' . get_string('pluginname', 'local_batchanalytics') . '</h2>';
echo '<div id="ba-toast-container" class="ba-toast-container"></div>';

echo '<div class="ba-top-row">';
echo '<div class="ba-search-row">';
$search_placeholder = 'Search course name (e.g., Advanced C)...';
echo '<input id="ba-search" type="text" placeholder="' . $search_placeholder . '">';
echo '<button id="ba-search-btn" class="ba-btn">Search</button>';
echo '</div>';
echo '<div class="ba-top-actions">';
if ($can_view_tickets) {
    echo '<a href="' . new moodle_url('/local/batchanalytics/tickets.php') . '" class="ba-btn ba-btn-view">' . get_string('ticket_dashboard', 'local_batchanalytics') . '</a>';
}
echo '</div>';
echo '</div>';

echo '<div class="ba-batch-card">';
echo '<label class="ba-label">SELECT BATCH GROUP</label>';
echo '<select id="ba-batch" class="ba-select">';
echo '<option value="">-- Select a Batch --</option>';
echo '</select>';
echo '</div>';

echo '<div id="ba-tabs-wrapper" class="ba-tabs-wrapper" style="display:none">';
echo '  <ul class="ba-tabs-nav" id="batchTabs"></ul>';
echo '  <div class="ba-tabs-content" id="batchTabsContent"></div>';
echo '</div>';

echo '</div>'; // .local-batchanalytics-wrap

echo $OUTPUT->footer();
?>
