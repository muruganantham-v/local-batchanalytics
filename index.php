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
$USER_ID = $USER->id;

// Capability-based permission check. Assign 'local/batchanalytics:view' to roles in Site administration > Users > Permissions > Define roles.
require_capability('local/batchanalytics:view', $context);

$action = optional_param('action', '', PARAM_ALPHA);

// ==================== API ENDPOINTS ====================

if ($action === 'searchcourses') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $keyword = optional_param('keyword', '', PARAM_TEXT);

    $mdata = new \local_batchanalytics\moodledata();
    $courses = $mdata->search_courses($keyword, $USER_ID);

    echo json_encode(['courses' => $courses]);
    die();
}

if ($action === 'getbatchcourses') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $batchcode = optional_param('batchcode', '', PARAM_TEXT);

    $mdata = new \local_batchanalytics\moodledata();
    $courses = $mdata->get_courses_by_batch($batchcode, $USER_ID);

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
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $crm = new \local_batchanalytics\crmapi();
        $placement_status = $crm->search_student_placement($username);

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
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $crm = new \local_batchanalytics\crmapi();

        if (!empty($usernames)) {
            $usernameList = array_filter(array_map('trim', explode(',', $usernames)));
            $records = $crm->get_students_details($usernameList);

            $output = [];
            foreach ($usernameList as $u) {
                $record = $records[$u] ?? null;
                $company = 'Not Placed';
                if (!empty($record['Placement_Company'])) {
                    if (is_array($record['Placement_Company']) && isset($record['Placement_Company']['name'])) {
                        $company = $record['Placement_Company']['name'];
                    } elseif (is_string($record['Placement_Company'])) {
                        $company = $record['Placement_Company'];
                    }
                }
                $output[] = [
                    'username' => $u,
                    'placed_company' => $company,
                    'CTC' => $record['CTC'] ?? '-',
                    'data' => $record
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

        // Prepare JSON Response
        $data = [
            'username' => $username,
            'placed_company' => $company,
            'CTC' => $record['CTC'] ?? '-', // <--- Added CTC here

            // ... Keep all other existing fields ...
            'Class_X_Score' => $record['Class_X_Score'] ?? '-',
            'Class_XII_Score' => $record['Class_XII_Score'] ?? '-',
            'BE_BTech_Branch' => $record['BE_BTech_Branch'] ?? '-',
            'BE_BTech_Score' => $record['BE_BTech_Score'] ?? '-',
            'BE_BTech_YoP' => $record['BE_BTech_YoP'] ?? '-',
            'College_Name' => $record['College_Name'] ?? ($record['Other_College_Name'] ?? '-'),
            'ME_MTech_Score' => $record['ME_MTech_Score'] ?? '-',
            'ME_MTech_Branch' => $record['ME_MTech_Branch'] ?? '-',
            'ME_MTech_YoP' => $record['ME_MTech_YoP'] ?? '-',
            'Home_State' => $record['Home_State'] ?? '-',
            'Total_Applied' => $record['Total_Applied'] ?? '-',
            'Last_Applied_Date' => $record['Last_Applied_Date'] ?? '-',
            'Total_Shortlisted' => $record['Total_Shortlisted'] ?? '-',
            'Last_Shortlisted_Date' => $record['Last_Shortlisted_Date'] ?? '-',
            'Total_Technical_Interview_Cleared' => $record['Total_Technical_Interview_Cleared'] ?? '-',
            'Total_Written_Test_Cleared' => $record['Total_Written_Test_Cleared'] ?? '-',
            'Total_L1_Cleared' => $record['Total_L1_Cleared'] ?? '-',
            'Total_L2_Cleared' => $record['Total_L2_Cleared'] ?? '-',
            'Advanced_C_Score' => $record['Advanced_C_Score'] ?? '-',
            'Mentor_Name_C_Mock' => $record['Mentor_Name_C_Mock'] ?? '-',
            'C_Score' => $record['C_Score'] ?? '-',
            'Mentor_Name_C_Mock1' => $record['Mentor_Name_C_Mock1'] ?? '-',
            'DS_Score' => $record['DS_Score'] ?? '-',
            'Mentor_Name_DS' => $record['Mentor_Name_DS'] ?? '-',
            'Linux_Internals_Score' => $record['Linux_Internals_Score'] ?? '-',
            'Mentor_Name_LI' => $record['Mentor_Name_LI'] ?? '-',
            'MC_Mock_Score3' => $record['MC_Mock_Score3'] ?? '-',
            'Mentor_Name_MC_Mock' => $record['Mentor_Name_MC_Mock'] ?? '-',
            'Coach_Rating' => $record['Coach_Rating'] ?? '-',
            'MAAC_Rating' => $record['MAAC_Rating'] ?? '-',
            'Placement_Eli' => $record['Placement_Eli'] ?? '-'
        ];

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
    global $DB;

    $batchcode = optional_param('batchcode', '', PARAM_TEXT);

    $mdata = new \local_batchanalytics\moodledata();
    $courses = $mdata->get_courses_by_batch($batchcode, $USER_ID);

    $result = [
        'batchcode' => $batchcode,
        'courses' => [],
        'totalStudents' => 0,
        'totalCourses' => count($courses),
        'totalTeachers' => 0,
        'uniqueStudents' => [], // Will hold list of {name, username}
        'uniqueTeachers' => []
    ];

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
        $student_role = $DB->get_record('role', ['shortname' => 'student']);
        $student_role_id = $student_role ? $student_role->id : 5;

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

        // 3.5. CRM Batch lookup by up to 20 usernames at a time
        $students_usernames = array_map(function ($s) {
            return $s->username;
        }, $students);

        $crm_data_map = [];
        if (!empty($students_usernames)) {
            $crmapi = new \local_batchanalytics\crmapi();
            $crm_data_map = $crmapi->get_students_details($students_usernames);

            // Make lookup case-insensitive by keying lowercase username.
            $crm_data_map = array_change_key_case($crm_data_map, CASE_LOWER);
        }

        // merge CRM response back to the uniqueStudents list
        foreach ($result['uniqueStudents'] as &$stud) {
            $key = strtolower($stud['username']);
            $stud['crm'] = array_key_exists($key, $crm_data_map) ? $crm_data_map[$key] : null;
        }
        unset($stud);

        // 4. Organize Data
// 4. Organize Data
        // NEW: Fetch all categories to resolve parent/child relationships
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

        // 5. Calculate Grades
        foreach ($categories_data as $cat_name => &$cat_data) {
            $total_items_in_cat = count($cat_data['items']); // NEW: Get total assignments (e.g. 27)

            foreach ($students as $student) {
                $total_earned = 0;
                $total_max = 0;
                $items_completed = 0; // NEW: Track how many they actually did

                foreach ($cat_data['items'] as $item) {
                    $grade_rec = $DB->get_record_sql(
                        "SELECT finalgrade FROM {grade_grades} WHERE itemid = ? AND userid = ?",
                        [$item['itemid'], $student->userid]
                    );
                    $finalgrade = $grade_rec ? $grade_rec->finalgrade : null;

                    if ($finalgrade !== null) {
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

                $studentcrm = $crm_data_map[strtolower($student->username)] ?? null;

                $cat_data['studentGrades'][] = [
                    'userid' => $student->userid,
                    'fullname' => $student->fullname,
                    'username' => $student->username,
                    'percentage' => $percentage,
                    'completionRate' => $comp_rate, // NEW: Pass the true completion metric to JS
                    'totalEarned' => $total_earned,
                    'crm' => $studentcrm
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

echo '<div class="local-batchanalytics-wrap">';
// echo '<h2 class="ba-page-title">' . get_string('pluginname', 'local_batchanalytics') . '</h2>';
echo '<div id="ba-toast-container" class="ba-toast-container"></div>';

echo '<div class="ba-top-row">';
echo '<div class="ba-search-row">';
echo '<input id="ba-search" type="text" placeholder="Search batch code (e.g., 21, 22)...">';
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