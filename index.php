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

$can_manage = has_capability('local/batchanalytics:manage', $context);

// Load restricted CRM fields for view-only users.
$restricted_crm_fields = [];
if (!$can_manage) {
    $restricted_crm_fields = \local_batchanalytics\crm_fields_helper::get_restricted_keys();
}

/**
 * Strip restricted CRM fields from a data array.
 */
function filter_crm_fields($data, $restricted_fields) {
    if (empty($data) || !is_array($data)) {
        return $data;
    }
    foreach ($restricted_fields as $field) {
        unset($data[$field]);
    }
    // Always strip debug fields for non-managers.
    unset($data['_debug_api_url']);
    unset($data['_debug_api_response']);
    return $data;
}

$action = optional_param('action', '', PARAM_ALPHA);

// ==================== API ENDPOINTS ====================

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

// --- EXISTING CRM ENDPOINT (Simple Placement Status) ---
if ($action === 'getcrmdata') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $username = optional_param('username', '', PARAM_TEXT);

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
    } catch (Exception $e) {
        echo json_encode([
            'username' => $username,
            'placed_company' => 'Error',
            'debug' => $e->getMessage()
        ]);
    }
    die();
}

if ($action === 'getptfdata') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $username = optional_param('username', '', PARAM_TEXT);
    $usernames = optional_param('usernames', '', PARAM_TEXT);

    try {
        $crm = new \local_batchanalytics\crmapi();

        if (!empty($usernames)) {
            $usernameList = array_filter(array_map('trim', explode(',', $usernames)));
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
                    $rec_data = filter_crm_fields($rec_data, $restricted_crm_fields);
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
            $data = filter_crm_fields($data, $restricted_crm_fields);
        }

        echo json_encode($data);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    die();
}

if ($action === 'getbatchfulldata') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $batchcode = optional_param('batchcode', '', PARAM_TEXT);

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

    // Get student role ID once before the loop
    $student_role = $DB->get_record('role', ['shortname' => 'student']);
    $student_role_id = $student_role ? $student_role->id : 5;

    foreach ($courses as $course) {
        $courseid = $course['courseid'];

        // 1. Get Teachers
        $course_context = context_course::instance($courseid);
        $teachers_raw = get_role_users([3, 4], $course_context, true, 'ra.id, u.id as userid, u.firstname, u.lastname');

        $course_unique_teachers = [];
        foreach ($teachers_raw as $t) {
            $course_unique_teachers[$t->userid] = $t->firstname . ' ' . $t->lastname;
            $result['uniqueTeachers'][$t->userid] = $t->firstname . ' ' . $t->lastname;
        }
        $course_teacher_count = count($course_unique_teachers);

        // 2. Get Grade Items (Including Course Total)
        $items_sql = "
            SELECT
                gi.id as itemid, gi.itemname, gi.itemtype, gi.itemmodule, gi.grademax,
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
                'grademax' => $item->grademax
            ];
        }

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
                        
                        if ($item['grademax'] > 0) {
                            $total_earned += $finalgrade;
                            $total_max += $item['grademax'];
                        }
                    }
                }

                // Grade %: Only calculated on items they ACTUALLY submitted (Ignores unsubmitted)
                $percentage = $total_max > 0 ? round(($total_earned / $total_max) * 100, 2) : null;

                if ($cat_name === 'MAAC Ratings' && $percentage !== null) {
                    $percentage = round($percentage / 10, 1);
                }

                // Completion %: Items Completed / Total Items in Category (e.g., 10/27 = 37.04%)
                $comp_rate = $total_items_in_cat > 0 ? round(($items_completed / $total_items_in_cat) * 100, 2) : 0;

                // Remove individual student CRM appendage, it's not needed for the Moodle Grades API
                $cat_data['studentGrades'][] = [
                    'userid' => $student->userid,
                    'fullname' => $student->fullname,
                    'username' => $student->username,
                    'percentage' => $percentage,
                    'completionRate' => $comp_rate, // NEW: Pass the true completion metric to JS
                    'totalEarned' => $total_earned
                ];
            }
        }

        $result['courses'][] = [
            'courseid' => $courseid,
            'coursename' => $course['fullname'],
            'shortname' => $course['shortname'],
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

    echo json_encode($result);
    die();
}

// ... (HTML Page rendering remains the same) ...

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/index.php'));
$PAGE->set_title(get_string('pluginname', 'local_batchanalytics'));
$PAGE->set_heading(get_string('pluginname', 'local_batchanalytics'));

$PAGE->requires->css('/local/batchanalytics/styles.css');
$PAGE->requires->js('/local/batchanalytics/simple.js');

echo $OUTPUT->header();

$crm_fields_config = \local_batchanalytics\crm_fields_helper::get_fields();
echo '<div class="local-batchanalytics-wrap" data-can-manage="' . ($can_manage ? '1' : '0') . '" data-crm-fields="' . htmlspecialchars(json_encode($crm_fields_config), ENT_QUOTES) . '">';
// echo '<h2 class="ba-page-title">' . get_string('pluginname', 'local_batchanalytics') . '</h2>';
echo '<div id="ba-toast-container" class="ba-toast-container"></div>';

echo '<div class="ba-top-row">';
echo '<div class="ba-search-row">';
$search_placeholder = $can_manage ? 'Search batch code (e.g., 21, 22)...' : 'Filter batch groups...';
echo '<input id="ba-search" type="text" placeholder="' . $search_placeholder . '">';
echo '<button id="ba-search-btn" class="ba-btn">Search</button>';
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