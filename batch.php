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

/**
 * Batch Detail Screen for local_batchanalytics
 *
 * Sourced from local_bm_classsection and local_bm_batch (Batch Management)
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/batch_notes_service.php');

require_login();

$context = context_system::instance();
require_capability('local/batchanalytics:view', $context);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action === 'addnote') {
    require_sesskey();
    require_capability('local/batchanalytics:editreviewnotes', $context);

    header('Content-Type: application/json; charset=utf-8');
    try {
        $batchid = required_param('batchid', PARAM_INT);
        $note = required_param('note', PARAM_RAW);
        // Strictly use the authenticated logged-in user
        $author = fullname($USER);

        $saved = \local_batchanalytics\batch_notes_service::add_note($batchid, (int)$USER->id, $author, $note);
        echo json_encode(['success' => true, 'note' => $saved]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$id = optional_param('id', optional_param('batchid', 0, PARAM_INT), PARAM_INT);

// -------------------------------------------------------------------------
// 1. Data Retrieval from Batch Management (local_bm_classsection / local_bm_batch)
// -------------------------------------------------------------------------
global $DB, $PAGE, $OUTPUT, $USER;

$dbman = $DB->get_manager();
$has_bm_section = $dbman->table_exists('local_bm_classsection');
$has_bm_batch   = $dbman->table_exists('local_bm_batch');
$has_bm_student = $dbman->table_exists('local_bm_student');

$section = null;
$batch = null;
$is_sample_data = false;

if ($has_bm_section && $id > 0) {
    $section = $DB->get_record('local_bm_classsection', ['id' => $id]);
    if (!$section) {
        $section = $DB->get_record('local_bm_classsection', ['batchid' => $id]);
    }
}

// Fallback: If requested ID is not found or ID is 0, pick the first available classsection record
if (!$section && $has_bm_section) {
    $sections = $DB->get_records('local_bm_classsection', null, 'id ASC', '*', 0, 1);
    if (!empty($sections)) {
        $section = reset($sections);
    }
}
if ($section) {
    $id = (int)$section->id;
}

if ($section && $has_bm_batch && !empty($section->batchid)) {
    $batch = $DB->get_record('local_bm_batch', ['id' => $section->batchid]);
}

$batch_id = $section ? (int)$section->id : ($id > 0 ? (int)$id : 1);

// Extract & normalize batch header fields
if ($section) {
    $batchid_label  = $section->name ?: ($batch ? $batch->name : 'Batch ' . $section->id);
    $coursename     = ($batch && !empty($batch->coursename)) ? $batch->coursename : 'Embedded Systems & IoT';
    $deliverymode   = ($batch && !empty($batch->deliverymode)) ? $batch->deliverymode : 'Offline';
    $submode        = ($batch && !empty($batch->submode)) ? $batch->submode : 'Regular';
    $startdate_ts   = ($batch && !empty($batch->startdate)) ? $batch->startdate : ($section->timecreated ?: time());
    $startdate_str  = userdate($startdate_ts, '%d %b %Y');

    // Program Manager resolution: prioritize resolving by userid
    $pmuser = null;
    if (!empty($section->pmmanager) && is_numeric($section->pmmanager) && (int)$section->pmmanager > 0) {
        $pmprofile = \local_batchanalytics\util::get_user_profile_data((int)$section->pmmanager, 1);
        if (!empty($pmprofile['user'])) {
            $pmuser = $pmprofile['user'];
            $pmname = $pmprofile['name'];
        }
    }
    if (empty($pmname)) {
        $pmname = !empty($section->pmmanagername) ? $section->pmmanagername : 'Ravi Kumar';
    }

    // MAAC Executive (SSE) resolution: prioritize resolving by userid
    $sseuser = null;
    if (!empty($section->maacexecutive) && is_numeric($section->maacexecutive) && (int)$section->maacexecutive > 0) {
        $sseprofile = \local_batchanalytics\util::get_user_profile_data((int)$section->maacexecutive, 1);
        if (!empty($sseprofile['user'])) {
            $sseuser = $sseprofile['user'];
            $ssename = $sseprofile['name'];
        }
    }
    if (empty($ssename)) {
        $ssename = !empty($section->maacexecutivename) ? $section->maacexecutivename : 'Anitha S';
    }

    // Module tracking decoding
    $raw_modules = [];
    if (!empty($section->moduledata)) {
        $raw_modules = \local_batchanalytics\util::decode_module_data($section->moduledata, true);
    }
} else {
    // Graceful fallback sample data conforming to prototype
    $is_sample_data = true;
    $batchid_label  = '26011B';
    $coursename     = 'Embedded Systems & IoT';
    $deliverymode   = 'Offline';
    $submode        = 'Regular';
    $startdate_str  = '28 Jul 2026';
    $pmname         = 'Ravi Kumar';
    $pmuser         = null;
    $ssename        = 'Anitha S';
    $sseuser        = null;
    $raw_modules    = [];
}

// -------------------------------------------------------------------------
// 2. Build Schedule Rows
// -------------------------------------------------------------------------
$schedule_rows = [];

$canonical_modules = [
    1 => 'Linux Systems',
    2 => 'Advanced C',
    3 => 'C++ Programming',
    4 => 'Data Structures',
    5 => 'Microcontrollers',
    6 => 'Linux Internals',
    7 => 'ELARM',
    8 => 'Qt / QML',
];

$format_mod_date = static function($val): string {
    if (empty($val) || $val === '—' || $val === 0 || $val === '0') {
        return '—';
    }
    if (is_numeric($val) && (int)$val > 100000) {
        return userdate((int)$val, '%d %b %Y');
    }
    return (string)$val;
};

if (!empty($raw_modules)) {
    foreach ($raw_modules as $mod) {
        $mod_idx = (int)($mod['module'] ?? 1);
        $course_id = !empty($mod['moodlecourseid']) ? (int)$mod['moodlecourseid'] : 0;
        $m_name = !empty($mod['name']) ? $mod['name'] : (!empty($mod['courseshortname']) ? $mod['courseshortname'] : '');
        if ($m_name === '' && $course_id > 0) {
            $c_rec = $DB->get_record('course', ['id' => $course_id], 'id, fullname, shortname');
            if ($c_rec) {
                $m_name = $c_rec->fullname ?: $c_rec->shortname;
            }
        }
        if ($m_name === '') {
            $m_name = $canonical_modules[$mod_idx] ?? ('Module ' . $mod_idx);
        }

        // Resolve Class Mentor(s) with deduplication and validation
        $class_mentors = [];
        $class_mentors_arr = [];
        $seen_cm = [];
        foreach (['primarymentor', 'secondarymentor'] as $cf) {
            if (!empty($mod[$cf]) && $mod[$cf] !== '—') {
                $cm_data = \local_batchanalytics\util::get_user_profile_data($mod[$cf]);
                $mname = $cm_data['name'];
                if ($mname === '' && !is_numeric($mod[$cf])) {
                    $mname = trim((string)$mod[$cf]);
                    $cm_data['name'] = $mname;
                }
                if ($mname !== '') {
                    $dedup_key = $cm_data['userid'] > 0 ? ('u_' . $cm_data['userid']) : ('n_' . strtolower($mname));
                    if (empty($seen_cm[$dedup_key])) {
                        $seen_cm[$dedup_key] = true;
                        $class_mentors[] = $cm_data;
                        $class_mentors_arr[] = $mname;
                    }
                }
            }
        }
        $class_mentor = !empty($class_mentors_arr) ? implode(', ', $class_mentors_arr) : '—';

        // Resolve Lab Mentor(s) with deduplication and validation
        $lab_mentors = [];
        $lab_mentors_arr = [];
        $seen_lm = [];
        foreach (['labmentor1', 'labmentor2', 'labmentor3'] as $lf) {
            if (!empty($mod[$lf]) && $mod[$lf] !== '—') {
                $lm_data = \local_batchanalytics\util::get_user_profile_data($mod[$lf]);
                $lmname = $lm_data['name'];
                if ($lmname === '' && !is_numeric($mod[$lf])) {
                    $lmname = trim((string)$mod[$lf]);
                    $lm_data['name'] = $lmname;
                }
                if ($lmname !== '') {
                    $dedup_key = $lm_data['userid'] > 0 ? ('u_' . $lm_data['userid']) : ('n_' . strtolower($lmname));
                    if (empty($seen_lm[$dedup_key])) {
                        $seen_lm[$dedup_key] = true;
                        $lab_mentors[] = $lm_data;
                        $lab_mentors_arr[] = $lmname;
                    }
                }
            }
        }
        $lab_mentor = !empty($lab_mentors_arr) ? implode(', ', $lab_mentors_arr) : '—';

        $p_start = $format_mod_date($mod['plannedstart'] ?? '');
        $p_end   = $format_mod_date($mod['plannedend'] ?? '');
        $a_start = $format_mod_date($mod['actualstart'] ?? '');
        $a_end   = $format_mod_date($mod['actualend'] ?? '');

        $delta = isset($mod['scheduledelta']) ? (int)$mod['scheduledelta'] : null;
        if ($a_start !== '—' && $a_end === '—') {
            $delay_code = 'prog';
        } else if ($delta !== null && ($p_start !== '—' || $a_start !== '—')) {
            $delay_code = $delta;
        } else {
            $delay_code = null;
        }

        $schedule_rows[] = [
            'name'          => $m_name,
            'class_mentor'  => $class_mentor,
            'class_mentors' => $class_mentors,
            'lab_mentor'    => $lab_mentor,
            'lab_mentors'   => $lab_mentors,
            'p_start'       => $p_start,
            'p_end'         => $p_end,
            'a_start'       => $a_start,
            'a_end'         => $a_end,
            'delay'         => $delay_code,
            'courseid'      => $course_id,
            'days'          => !empty($mod['planneddays']) ? (int)$mod['planneddays'] : \local_batchanalytics\util::get_module_total_days($m_name, 10),
        ];
    }
} else {
    // Canonical default sequence from curriculum
    $schedule_rows = [
        [
            'name' => 'Linux Systems', 'class_mentor' => 'Meera R', 'lab_mentor' => '—',
            'p_start' => '28 Jul 2026', 'p_end' => '03 Aug 2026', 'a_start' => '28 Jul 2026', 'a_end' => '03 Aug 2026',
            'delay' => 0, 'courseid' => 0, 'days' => 5
        ],
        [
            'name' => 'Advanced C', 'class_mentor' => 'Suresh P', 'lab_mentor' => 'Kiran R',
            'p_start' => '04 Aug 2026', 'p_end' => '28 Oct 2026', 'a_start' => '04 Aug 2026', 'a_end' => '01 Nov 2026',
            'delay' => 4, 'courseid' => 2, 'days' => 77
        ],
        [
            'name' => 'C++ Programming', 'class_mentor' => 'Meera R', 'lab_mentor' => '—',
            'p_start' => '02 Nov 2026', 'p_end' => '15 Nov 2026', 'a_start' => '02 Nov 2026', 'a_end' => '16 Nov 2026',
            'delay' => 1, 'courseid' => 0, 'days' => 13
        ],
        [
            'name' => 'Data Structures', 'class_mentor' => 'Meera R', 'lab_mentor' => '—',
            'p_start' => '17 Nov 2026', 'p_end' => '16 Dec 2026', 'a_start' => '17 Nov 2026', 'a_end' => '19 Dec 2026',
            'delay' => 3, 'courseid' => 3, 'days' => 29
        ],
        [
            'name' => 'Microcontrollers', 'class_mentor' => 'Suresh P', 'lab_mentor' => 'Kiran R',
            'p_start' => '20 Dec 2026', 'p_end' => '26 Jan 2027', 'a_start' => '20 Dec 2026', 'a_end' => '31 Jan 2027',
            'delay' => 5, 'courseid' => 10, 'days' => 37
        ],
        [
            'name' => 'Linux Internals', 'class_mentor' => 'Meera R', 'lab_mentor' => '—',
            'p_start' => '01 Feb 2027', 'p_end' => '06 Mar 2027', 'a_start' => '01 Feb 2027', 'a_end' => '12 Mar 2027',
            'delay' => 6, 'courseid' => 8, 'days' => 33
        ],
        [
            'name' => 'ELARM', 'class_mentor' => 'Suresh P', 'lab_mentor' => '—',
            'p_start' => '13 Mar 2027', 'p_end' => '23 Mar 2027', 'a_start' => '13 Mar 2027', 'a_end' => '21 Mar 2027',
            'delay' => -2, 'courseid' => 0, 'days' => 10
        ],
        [
            'name' => 'Qt / QML', 'class_mentor' => 'Meera R', 'lab_mentor' => '—',
            'p_start' => '22 Mar 2027', 'p_end' => '01 Apr 2027', 'a_start' => '22 Mar 2027', 'a_end' => '—',
            'delay' => 'prog', 'courseid' => 0, 'days' => 10
        ]
    ];
}

// Determine active module and tentative end date
$cur_module = '';
foreach ($schedule_rows as $row) {
    if ($row['delay'] === 'prog') {
        $cur_module = $row['name'];
        break;
    }
}
if ($cur_module === '' && !empty($schedule_rows)) {
    foreach ($schedule_rows as $row) {
        if ($row['a_end'] === '—') {
            $cur_module = $row['name'];
            break;
        }
    }
}
if ($cur_module === '') {
    $cur_module = !empty($schedule_rows[0]['name']) ? $schedule_rows[0]['name'] : 'Advanced C';
}

$tentative_end = '12 Apr 2027';
if (!empty($schedule_rows)) {
    $last_sr = end($schedule_rows);
    if (!empty($last_sr['p_end']) && $last_sr['p_end'] !== '—') {
        $tentative_end = $last_sr['p_end'];
    }
}

// -------------------------------------------------------------------------
// 3. Batch-Level SS Activities (from local_bm_classsection softskillsdata)
// -------------------------------------------------------------------------
$softskills_items_def = [
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

$ss_activities = [];
$now_today_start = strtotime('today midnight');
$now_today_end = $now_today_start + 86400;

$ss_raw = $section ? json_decode($section->softskillsdata ?? '{}', true) : [];
if (is_array($ss_raw)) {
    foreach ($softskills_items_def as $item) {
        $k = $item['key'];
        $p = (int)($ss_raw[$k . '_planned'] ?? 0);
        $a = (int)($ss_raw[$k . '_actual'] ?? 0);

        if ($p <= 0 && $a <= 0) {
            continue;
        }

        $p_formatted = $p > 0 ? userdate($p, '%d %b %Y') : '—';
        $a_formatted = $a > 0 ? userdate($a, '%d %b %Y') : '—';

        if ($a > 0) {
            $status = 'g';
            $label = 'Completed';
        } else if ($p > 0 && $p < $now_today_start) {
            $days = max(1, floor(($now_today_start - $p) / 86400));
            $status = 'r';
            $label = "Overdue ({$days}d)";
        } else if ($p > 0 && $p < $now_today_end) {
            $status = 'a';
            $label = 'Due today';
        } else {
            $days = $p > 0 ? max(1, floor(($p - $now_today_start) / 86400)) : 0;
            $status = 'b';
            $label = $days > 0 ? "Upcoming ({$days}d)" : 'Upcoming';
        }

        $ss_activities[] = [
            'activity' => $item['label'],
            'p_date'   => $p_formatted,
            'a_date'   => $a_formatted,
            'status'   => $status,
            'label'    => $label,
            'planned_ts' => $p,
        ];
    }
}

// Fallback if no soft skills planned in section
if (empty($ss_activities)) {
    $ss_activities = [
        ['activity' => 'SS Induction',        'p_date' => '07 Aug 2026', 'a_date' => '07 Aug 2026', 'status' => 'g', 'label' => 'Completed', 'planned_ts' => 0],
        ['activity' => 'AANCHOR 1',           'p_date' => '10 Aug 2026', 'a_date' => '10 Aug 2026', 'status' => 'g', 'label' => 'Completed', 'planned_ts' => 0],
        ['activity' => 'Placement Induction', 'p_date' => '04 Sep 2026', 'a_date' => '—',           'status' => 'r', 'label' => 'Overdue',   'planned_ts' => 0],
        ['activity' => 'Soft skill 1',        'p_date' => '28 Oct 2026', 'a_date' => '—',           'status' => 'b', 'label' => 'Upcoming',  'planned_ts' => 0],
    ];
}

// -------------------------------------------------------------------------
// 4. Student Performance Data
// -------------------------------------------------------------------------
$students_data = [];

$performance_courseids = array_values(array_unique(array_filter(array_map('intval', array_column($raw_modules, 'moodlecourseid')))));
$spot_award_counts = [];
if (!empty($performance_courseids) && $DB->get_manager()->table_exists('spotaward_nominations') && $DB->get_manager()->table_exists('spotaward_nomination_items')) {
    list($incourses, $spotparams) = $DB->get_in_or_equal($performance_courseids, SQL_PARAMS_NAMED, 'spotcourse');
    $sql = "SELECT sni.studentid, COUNT(sni.id) AS awardcount
              FROM {spotaward_nomination_items} sni
              JOIN {spotaward_nominations} sn ON sn.id = sni.nominationid
             WHERE sn.courseid $incourses
               AND sni.status = 'closed'
          GROUP BY sni.studentid";
    foreach ($DB->get_records_sql($sql, $spotparams) as $record) {
        $spot_award_counts[(int)$record->studentid] = (int)$record->awardcount;
    }
}

$enrolledstudents = [];
$studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
if ($studentroleid > 0) {
    foreach ($performance_courseids as $enrolledcourseid) {
        $coursecontext = context_course::instance($enrolledcourseid, IGNORE_MISSING);
        if (!$coursecontext) continue;
        foreach (get_role_users($studentroleid, $coursecontext, false, 'u.id, u.idnumber, u.username, u.firstname, u.lastname, u.email') as $studentrecord) {
            $enrolledstudents[$studentrecord->id] = $studentrecord;
        }
    }
}

foreach ($enrolledstudents as $studentrecord) {
    $spot_count = (int)($spot_award_counts[$studentrecord->id] ?? 0);
    $merit_labels = $spot_count > 0 ? [str_repeat('★', $spot_count) . ' Spot'] : [];
    $profile = \local_batchanalytics\util::get_user_profile_data($studentrecord, 2);
    $students_data[] = [
        'userid' => (int)$studentrecord->id,
        'username' => !empty($studentrecord->username) ? $studentrecord->username : '',
        'name' => $profile['name'],
        'profileimageurl' => $profile['profileimageurl'],
        'initials' => $profile['initials'],
        'id' => !empty($studentrecord->idnumber) ? $studentrecord->idnumber : ('ST_' . $studentrecord->id),
        'grade' => null,
        'attendance' => '—',
        'assignments' => '—',
        'projects' => '—',
        'tests' => '—',
        'spot_count' => $spot_count,
        'spot' => ($spot_count > 0),
        'pt' => '',
        'merit_text' => implode(', ', $merit_labels),
    ];
}
// If no DB students found, supply rich sample dataset matching prototype
$allow_sample_students = false;
if ($allow_sample_students && empty($students_data)) {
    $b_names = ["Abhay D", "Abijith P", "Ajith M", "Akshay M", "Anitha S", "Anushka K", "Arjun R", "Badulla J", "Chaitra K", "Dipashree B", "Gowtham N", "Harish V"];
    $b_ids   = ["26011_017", "26011_038", "26001_137", "25050_017", "26011_101", "26011_049", "26011_072", "26011_066", "26011_205", "26011_111", "26011_090", "26011_058"];
    $b_grade = [82, 58, 36, 44, 74, 61, 32, 39, 88, 91, 66, 71];
    $b_merit = [[1, 'sel'], [0, 'nom'], [0, 0], [0, 0], [1, 0], [1, 'nom'], [0, 0], [0, 0], [1, 'sel'], [1, 'sel'], [0, 'nom'], [1, 0]];

    for ($i = 0; $i < count($b_names); $i++) {
        $m = $b_merit[$i];
        $merit_labels = [];
        if ($m[0]) $merit_labels[] = '★ Spot';
        if ($m[1] === 'nom') $merit_labels[] = 'PT-Nom';
        if ($m[1] === 'sel') $merit_labels[] = 'PT-Sel';

        $students_data[] = [
            'userid'          => 0,
            'name'            => $b_names[$i],
            'profileimageurl' => '',
            'initials'        => strtoupper(substr($b_names[$i], 0, 1)),
            'id'              => $b_ids[$i],
            'grade'           => $b_grade[$i],
            'attendance'      => (70 + ($i % 25)) . '%',
            'assignments'     => (60 + ($i % 35)) . '%',
            'projects'        => ($i % 3) ? ((55 + ($i % 40)) . '%') : '—',
            'tests'           => (62 + ($i % 30)) . '%',
            'spot'            => !empty($m[0]),
            'pt'              => $m[1] ?: '',
            'merit_text'      => implode(', ', $merit_labels),
        ];
    }
}

// Reuse the Course-tab Advanced Filter Gradebook dataset across linked modules.
$performance_courseids = array_filter(array_map('intval', array_column($raw_modules, 'moodlecourseid')));
$performance_data = (new \local_batchanalytics\student_performance_service())->build(
    $students_data,
    $performance_courseids,
    (int)$USER->id
);
$students_data = $performance_data['students'];
$performance_columns = $performance_data['columns'];
$performance_custom_groups = $performance_data['customgroups'] ?? [];

// -------------------------------------------------------------------------
// 5. Review Notes
// -------------------------------------------------------------------------
$initial_notes = [
    [
        'date' => '12 Sep 2026, 4:30 PM',
        'who'  => 'Ravi Kumar',
        'body' => 'Advanced C running to plan. Flagged 4 middle-band students for extra MAAC touchpoints; SSE to follow up on 2 red cases.'
    ],
    [
        'date' => '05 Sep 2026, 5:10 PM',
        'who'  => 'Ravi Kumar',
        'body' => 'Assignment evaluation slipped by 2 days last week — mentor notified, now caught up. Power Track nominations to be finalised by module end.'
    ],
    [
        'date' => '29 Aug 2026, 4:45 PM',
        'who'  => 'Ravi Kumar',
        'body' => 'Batch healthy overall. Attendance at 82%. Placement induction scheduled for D35.'
    ]
];

// Check review notes permissions
$can_view_notes = has_capability('local/batchanalytics:viewreviewnotes', $context);
$can_edit_notes = has_capability('local/batchanalytics:editreviewnotes', $context);

if ($can_view_notes) {
    $db_notes = \local_batchanalytics\batch_notes_service::get_notes($batch_id);
    if (!empty($db_notes)) {
        $display_notes = $db_notes;
        $has_real_notes = true;
    } else {
        $display_notes = $initial_notes;
        $has_real_notes = false;
    }
} else {
    $display_notes = [];
    $has_real_notes = false;
}

// Determine logged-in user's identity and role for posting review notes
$current_author_name = fullname($USER);
if (is_siteadmin()) {
    $current_author_role = 'Administrator';
} else if ($section && !empty($section->pmmanager) && (int)$USER->id === (int)$section->pmmanager) {
    $current_author_role = 'Program Manager';
} else if ($section && !empty($section->maacexecutive) && (int)$USER->id === (int)$section->maacexecutive) {
    $current_author_role = 'SS / MAAC Executive';
} else {
    $roles = get_user_roles($context, $USER->id, true);
    if (!empty($roles)) {
        $first_role = reset($roles);
        $current_author_role = role_get_name($first_role, $context);
    } else {
        $current_author_role = 'Program Manager';
    }
}

// Helper formatting functions
function format_delay_chip($d, $mod_name = '', $mod_days = 0) {
    if ($d === 'prog') {
        return '<span class="st st-a">In progress</span>';
    }
    if ($d === null) {
        return '<span class="muted">—</span>';
    }
    $ms = \local_batchanalytics\util::get_module_status($d, $mod_name, $mod_days);
    return '<span class="st st-' . s($ms['chip_class']) . '">' . s($ms['label']) . '</span>';
}

if (!function_exists('format_cell_muted')) {
    function format_cell_muted($val) {
        return ($val === '—' || $val === '') ? '<span class="muted">—</span>' : s($val);
    }
}

// -------------------------------------------------------------------------
// 6. Page Output Setup
// -------------------------------------------------------------------------
$batch_total_delay = 0;
$batch_has_delay = false;
foreach ($schedule_rows as $sr) {
    if (is_numeric($sr['delay'])) {
        $batch_total_delay += (int)$sr['delay'];
        $batch_has_delay = true;
    }
}
$batch_status_info = \local_batchanalytics\util::get_batch_status($batch_has_delay ? $batch_total_delay : 0);
$batch_status_chip_class = $batch_status_info['chip_class'];
if ($batch_total_delay < 0) {
    $batch_status_chip_text = 'Early · -' . abs($batch_total_delay) . ' days';
} else if ($batch_total_delay < 12) {
    $batch_status_chip_text = ($batch_total_delay > 0) ? ('On schedule · +' . $batch_total_delay . ' days') : 'On schedule · 0 days';
} else if ($batch_total_delay <= 23) {
    $batch_status_chip_text = 'Minor slip · +' . $batch_total_delay . ' days';
} else {
    $batch_status_chip_text = 'Delayed · +' . $batch_total_delay . ' days';
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/batch.php', ['id' => $id]));
$PAGE->set_title('Batch ' . $batchid_label . ' – Batch Analytics');
$PAGE->set_heading('');

// Load Plugin CSS and JavaScript
$styleurl = new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime(__DIR__ . '/styles.css')]);
$newstyleurl = new moodle_url('/local/batchanalytics/new_analytics.css', ['v' => filemtime(__DIR__ . '/new_analytics.css')]);
$scripturl = new moodle_url('/local/batchanalytics/batch.js', ['v' => filemtime(__DIR__ . '/batch.js')]);
$PAGE->requires->css($styleurl);
$PAGE->requires->css($newstyleurl);
$PAGE->requires->js($scripturl);

// Use the same configured CRM field list and explicit capability check as index.php.
$crm_fields_config = \local_batchanalytics\crm_fields_helper::get_fields();
$batch_can_manage = is_siteadmin($USER->id) || has_capability('local/batchanalytics:manage', $context);
$crm_index_url = (new moodle_url('/local/batchanalytics/index.php'))->out(false);

echo $OUTPUT->header();

echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
echo '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">';

?>

<div class="local-batchanalytics-wrap ba-batch-page" id="ba-batch-detail-container"
  data-batchid="<?= (int)$batch_id ?>"
  data-sesskey="<?= sesskey() ?>"
  data-students="<?= s(json_encode($students_data)) ?>"
  data-performance-columns="<?= s(json_encode($performance_columns)) ?>"
  data-performance-custom-groups="<?= s(json_encode($performance_custom_groups)) ?>"
  data-crm-fields="<?= htmlspecialchars(json_encode($crm_fields_config), ENT_QUOTES) ?>"
  data-can-manage="<?= $batch_can_manage ? '1' : '0' ?>"
  data-crm-index-url="<?= s($crm_index_url) ?>">

  <!-- Breadcrumb Bar -->
  <div class="crumbbar">
    <span class="crumb">
      <a href="<?= s((new moodle_url('/local/batchanalytics/index.php'))->out(false)) ?>">Home</a>
      <span style="color:#cbd5e1; margin:0 6px;">›</span>
      <b><?= s($batchid_label) ?></b>
    </span>
  </div>

  <?php if ($is_sample_data): ?>
    <div class="ba-notice-banner" style="background:#fff3cd; color:#856404; padding:10px 16px; border-radius:8px; margin:10px 0 16px; border:1px solid #ffeeba; font-size:13px;">
      ℹ️ Displaying demo data for <b>Batch <?= s($batchid_label) ?></b> (Class Section record #<?= (int)$id ?> was not found in <code>mdl_local_bm_classsection</code>).
    </div>
  <?php endif; ?>

  <div class="shell">

    <!-- Batch Header Card in Prototype Style -->
    <div class="bhead">
        <div class="top">
          <div>
            <h1>Batch <?= s($batchid_label) ?></h1>
            <div class="idrow">
              <span class="pill pill-course"><?= s($coursename) ?></span>
              <span class="pill mode-<?= strtolower(s($deliverymode)) ?>"><?= s($deliverymode) ?></span>
              <span class="pill type-<?= strtolower(s($submode)) ?>"><?= s($submode) ?></span>
              <span class="pill pill-date">Start <?= s($startdate_str) ?></span>
            </div>
            <div class="curmod">Current module: <b><?= s($cur_module) ?></b> &nbsp;·&nbsp; Tentative end <?= s($tentative_end) ?></div>
          </div>
          <div class="status-chip <?= $batch_status_chip_class ?>"><?= $batch_status_chip_text ?></div>
        </div>

        <!-- Constant Roles: Program Manager and SS / MAAC Executive -->
        <div class="people">
          <div class="person">
            <div class="role">Program Manager</div>
            <div class="pwrap">
              <div class="pav">
                <?= \local_batchanalytics\util::render_user_avatar($pmuser, 38, 'pav-avatar', $pmname) ?>
              </div>
              <span class="name"><?= s($pmname) ?></span>
            </div>
          </div>
          <div class="person">
            <div class="role">SS / MAAC Executive</div>
            <div class="pwrap">
              <div class="pav">
                <?= \local_batchanalytics\util::render_user_avatar($sseuser, 38, 'pav-avatar', $ssename) ?>
              </div>
              <span class="name"><?= s($ssename) ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Navigation Tabs in Prototype Underline Style -->
      <div class="tabs ba-batch-tabs">
        <button type="button" class="tab active" data-tab="sched">Schedule</button>
        <button type="button" class="tab" data-tab="ss">SS Activities</button>
        <button type="button" class="tab" data-tab="students">Student Performance</button>
        <?php if ($can_view_notes || $can_edit_notes): ?>
          <button type="button" class="tab" data-tab="notes">Review Notes</button>
        <?php endif; ?>
        <button type="button" class="tab" data-tab="crm" id="ba-crm-tab-btn">CRM Data</button>
      </div>

      <!-- Panels Container -->
      <div class="ba-batch-panels">

        <!-- 1. Schedule Panel -->
        <div id="panel-sched" class="panel active">
          <div class="panel-note">
            One row per module in the batch's configured sequence. <b>Delay</b> is colour-coded. Open any module for its detail.
          </div>
          <div class="tablecard">
            <table>
              <thead>
                <tr>
                  <th>Module</th>
                  <th>Class Mentor</th>
                  <th>Lab Mentor</th>
                  <th>Plan Start</th>
                  <th>Plan End</th>
                  <th>Actual Start</th>
                  <th>Actual End</th>
                  <th>Delay</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($schedule_rows as $idx => $r): ?>
                  <?php $has_link = ($r['delay'] !== null); ?>
                  <tr>
                    <td><span class="val"><?= s($r['name']) ?></span></td>
                    <td>
                      <?php if (!empty($r['class_mentors'])): ?>
                        <div class="ba-mentors-col">
                          <?php foreach ($r['class_mentors'] as $cm): ?>
                            <div class="ba-table-mentor">
                              <?= \local_batchanalytics\util::render_user_avatar($cm['user'] ?: $cm['name'], 24, 'ba-mentor-mini-avatar', $cm['name']) ?>
                              <span><?= s($cm['name']) ?></span>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php else: ?>
                        <?= format_cell_muted($r['class_mentor']) ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($r['lab_mentors'])): ?>
                        <div class="ba-mentors-col">
                          <?php foreach ($r['lab_mentors'] as $lm): ?>
                            <div class="ba-table-mentor">
                              <?= \local_batchanalytics\util::render_user_avatar($lm['user'] ?: $lm['name'], 24, 'ba-mentor-mini-avatar', $lm['name']) ?>
                              <span><?= s($lm['name']) ?></span>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php else: ?>
                        <?= format_cell_muted($r['lab_mentor']) ?>
                      <?php endif; ?>
                    </td>
                    <td class="date"><?= s($r['p_start']) ?></td>
                    <td class="date"><?= s($r['p_end']) ?></td>
                    <td class="date"><?= format_cell_muted($r['a_start']) ?></td>
                    <td class="date"><?= format_cell_muted($r['a_end']) ?></td>
                    <td><?= format_delay_chip($r['delay'], $r['name'], $r['days']) ?></td>
                    <td class="actioncell">
                      <?php
                        $course_linked = (!empty($r['courseid']) && (int)$r['courseid'] > 0);
                        if ($course_linked) {
                            $course_linked = $DB->record_exists('course', ['id' => (int)$r['courseid']]);
                        }
                      ?>
                      <?php if ($course_linked): ?>
                        <a href="<?= s((new moodle_url('/local/batchanalytics/module.php', ['courseid' => (int)$r['courseid'], 'batchid' => (int)$batch_id]))->out(false)) ?>" class="viewbtn">View Module Tracker</a>
                      <?php else: ?>
                        <button type="button" class="viewbtn disabled" disabled title="Course is not linked in Batch Management">View Module Tracker</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 2. SS Activities Panel -->
        <div id="panel-ss" class="panel">
          <div class="panel-note">
            Batch-level soft skill activities from Batch Management. Displays planned date, actual completion date, and current status.
          </div>
          <div class="tablecard">
            <table>
              <thead>
                <tr>
                  <th>Activity</th>
                  <th>Planned Date</th>
                  <th>Actual Date</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ss_activities as $act): ?>
                  <tr>
                    <td><span class="val"><?= s($act['activity']) ?></span></td>
                    <td class="date"><?= s($act['p_date']) ?></td>
                    <td class="date"><?= format_cell_muted($act['a_date']) ?></td>
                    <td><span class="st st-<?= s($act['status']) ?>"><?= s($act['label']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 3. Student Performance Panel -->
        <div id="panel-students" class="panel">
          <div class="panel-note">
            Two ways to band the same students: <b>Grade</b> (by actual score) or <b>Percentile</b> (default 15/70/15 by rank). Merit and core signals stay the same; only the banding changes.
          </div>
          <div class="filterbar">
            <div class="toggle">
              <span class="on" id="tg-grade">Grade</span>
              <span id="tg-pct">Percentile</span>
            </div>
            <button type="button" class="exp" id="ba-btn-export">Export Filtered</button>
          </div>

          <!-- Dynamic Banding Cards -->
          <div class="bandrow" id="ba-bandrow"></div>

          <!-- Students Table -->
          <div class="tablecard">
            <table>
              <thead>
                <tr>
                  <th class="sortable" data-sort="band" title="Sort by Band">Band</th>
                  <th class="sortable ba-performance-student-head" data-sort="student" title="Sort by Student Name">Student</th>
                  <th class="sortable ba-performance-overall-head" data-sort="grade" title="Sort by Grade">Grade</th>
                  <?php foreach ($performance_columns as $column): ?>
                    <th class="sortable ba-performance-group-module" data-sort="category:<?= s($column['key']) ?>" title="Sort by <?= s($column['label']) ?>"><?= s($column['label']) ?></th>
                  <?php endforeach; ?>
                  <th class="sortable" data-sort="merit" title="Sort by Merit">Merit</th>
                </tr>
              </thead>
              <tbody id="ba-stuBody">
                <!-- Populated dynamically with pagination via batch.js -->
              </tbody>
            </table>

            <!-- Student Performance Pagination Controls -->
            <div class="ba-pagination-bar" id="ba-pagination-bar">
              <div class="ba-pagination-info" id="ba-pagination-info">
                <!-- Populated via batch.js -->
              </div>
              <div class="ba-pagination-actions">
                <div class="ba-pagination-size-box">
                  <label for="ba-page-size">Rows per page:</label>
                  <select id="ba-page-size" class="ba-pagination-select">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="all">All</option>
                  </select>
                </div>
                <div class="ba-pagination-btns" id="ba-pagination-btns">
                  <!-- Rendered dynamically via batch.js -->
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 5. CRM Data Panel -->
        <div id="panel-crm" class="panel">
          <div class="panel-note">
            CRM data for all students in this batch, fetched live from Zoho CRM. Use the filters to narrow by placement status, PET eligibility, scores, and more.
          </div>

          <!-- CRM Filter + Table Layout -->
          <div class="ba-crm-layout" style="display:flex; gap:18px; align-items:flex-start;">

            <!-- Filter Sidebar -->
            <div class="ba-crm-sidebar" style="min-width:220px; max-width:240px; flex-shrink:0; background:var(--card,#fff); border:1px solid var(--line,#e2e8f0); border-radius:10px; padding:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <span style="font-weight:700; font-size:13px; color:var(--txt,#1e293b);">Filters</span>
                <span onclick="baBatchCrm.resetFilters()" style="cursor:pointer; color:var(--accent,#4f46e5); font-size:12px; font-weight:600;">Reset All</span>
              </div>

              <!-- Search -->
              <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">Search Name / ID</label>
                <input type="text" id="ba-crm-f-search" placeholder="Search..." oninput="baBatchCrm.applyFilters()"
                  style="width:100%; box-sizing:border-box; border:1px solid #e2e8f0; border-radius:6px; padding:6px 8px; font-size:12px;">
              </div>

              <!-- Placement Status -->
              <div style="margin-bottom:12px;" id="ba-crm-placement-wrap">
                <label style="font-size:12px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">Placement Status</label>
                <select id="ba-crm-f-placement" onchange="baBatchCrm.applyFilters()"
                  style="width:100%; border:1px solid #e2e8f0; border-radius:6px; padding:6px 8px; font-size:12px;">
                  <option value="">All</option>
                  <option value="Placed">Placed</option>
                  <option value="Not Placed">Not Placed</option>
                </select>
              </div>

              <!-- PET Status -->
              <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">PET Status</label>
                <select id="ba-crm-f-pet" onchange="baBatchCrm.applyFilters()"
                  style="width:100%; border:1px solid #e2e8f0; border-radius:6px; padding:6px 8px; font-size:12px;">
                  <option value="">All</option>
                </select>
              </div>

              <!-- MAAC Rating Range -->
              <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">MAAC Rating</label>
                <div style="display:flex; gap:6px; align-items:center;">
                  <input type="number" id="ba-crm-f-maac-min" value="0" min="0" max="10" step="0.1" onchange="baBatchCrm.applyFilters()"
                    style="width:60px; border:1px solid #e2e8f0; border-radius:6px; padding:5px 6px; font-size:12px;" placeholder="Min">
                  <span style="color:#94a3b8;">–</span>
                  <input type="number" id="ba-crm-f-maac-max" value="10" min="0" max="10" step="0.1" onchange="baBatchCrm.applyFilters()"
                    style="width:60px; border:1px solid #e2e8f0; border-radius:6px; padding:5px 6px; font-size:12px;" placeholder="Max">
                </div>
              </div>

              <!-- Adv C Mock Score -->
              <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">Adv C Mock Score</label>
                <div style="display:flex; gap:6px; align-items:center;">
                  <input type="number" id="ba-crm-f-advc-min" value="0" min="0" max="100" step="1" onchange="baBatchCrm.applyFilters()"
                    style="width:60px; border:1px solid #e2e8f0; border-radius:6px; padding:5px 6px; font-size:12px;" placeholder="Min">
                  <span style="color:#94a3b8;">–</span>
                  <input type="number" id="ba-crm-f-advc-max" value="100" min="0" max="100" step="1" onchange="baBatchCrm.applyFilters()"
                    style="width:60px; border:1px solid #e2e8f0; border-radius:6px; padding:5px 6px; font-size:12px;" placeholder="Max">
                </div>
              </div>

              <!-- Year of Passing -->
              <div style="margin-bottom:12px;" id="ba-crm-yop-wrap">
                <label style="font-size:12px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">Year of Passing</label>
                <div id="ba-crm-f-yop-list" style="max-height:110px; overflow-y:auto; border:1px solid #eee; padding:6px 8px; border-radius:6px; font-size:12px;">
                  <span style="color:#94a3b8;">Loading CRM data...</span>
                </div>
              </div>

              <!-- Home State -->
              <div style="margin-bottom:4px;" id="ba-crm-state-wrap">
                <label style="font-size:12px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">Home State</label>
                <div id="ba-crm-f-state-list" style="max-height:130px; overflow-y:auto; border:1px solid #eee; padding:6px 8px; border-radius:6px; font-size:12px;">
                  <span style="color:#94a3b8;">Loading CRM data...</span>
                </div>
              </div>
            </div>

            <!-- Main Table Area -->
            <div style="flex:1; min-width:0;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <span id="ba-crm-count" style="font-weight:600; font-size:13px; color:#64748b;">Loading students...</span>
                <div style="display:flex; gap:8px;">
                  <button type="button" id="ba-crm-retry-btn" onclick="baBatchCrm.retryCrm()" class="viewbtn disabled" style="display:none;">Retry CRM Data</button>
                  <button type="button" id="ba-crm-export-btn" onclick="baBatchCrm.exportCsv()" class="exp">Export CSV</button>
                </div>
              </div>
              <div class="tablecard" style="overflow-x:auto;">
                <table id="ba-crm-table" style="min-width:700px;">
                  <thead id="ba-crm-thead"><tr><th>Student</th></tr></thead>
                  <tbody id="ba-crm-tbody">
                    <tr><td colspan="20" style="text-align:center; padding:24px; color:#94a3b8;">Click the CRM Data tab to load student CRM data.</td></tr>
                  </tbody>
                </table>
              </div>
              <div class="ba-pagination-bar" id="ba-crm-pagination-bar">
                <div class="ba-pagination-info" id="ba-crm-pagination-info">
                  <!-- Populated via batch.js -->
                </div>
                <div class="ba-pagination-actions">
                  <div class="ba-pagination-size-box">
                    <label for="ba-crm-page-size">Rows per page:</label>
                    <select id="ba-crm-page-size" class="ba-pagination-select">
                      <option value="10" selected>10</option>
                      <option value="25">25</option>
                      <option value="50">50</option>
                      <option value="all">All</option>
                    </select>
                  </div>
                  <div class="ba-pagination-btns" id="ba-crm-pagination-btns">
                    <!-- Rendered dynamically via batch.js -->
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <?php if ($can_view_notes || $can_edit_notes): ?>
        <!-- 4. Review Notes Panel -->
        <div id="panel-notes" class="panel">
          <div class="panel-note">
            Notes captured by the Program Manager during batch reviews. Newest first. Each note is timestamped with the author.
          </div>

          <?php if ($can_edit_notes): ?>
          <div class="addnote">
            <textarea id="ba-note-text" placeholder="Add a review note for this batch…"></textarea>
            <div class="row addnote-row">
              <span class="who">Posting as <b><?= s($current_author_name) ?></b> · <?= s($current_author_role) ?></span>
              <button type="button" id="ba-btn-addnote" data-author="<?= s($current_author_name) ?>">Add note</button>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($can_view_notes): ?>
          <div id="ba-note-list">
            <?php foreach ($display_notes as $nt): ?>
              <div class="note-item<?= empty($has_real_notes) ? ' note-item-demo' : '' ?>">
                <div class="meta"><b><?= s($nt['who']) ?></b> · <?= s($nt['date']) ?></div>
                <div class="body"><?= nl2br(s($nt['body'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="ba-note-permission-msg" style="padding: 16px; background: #f8fafc; border: 1px dashed var(--line); border-radius: 8px; color: var(--mute, #64748b); font-size: 13px;">
            <?= s(get_string('nopermissiontoviewnotes', 'local_batchanalytics')) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

  </div> <!-- /.ba-batch-panels -->

  </div> <!-- /.shell -->

</div> <!-- /.local-batchanalytics-wrap -->

<?php
echo $OUTPUT->footer();
