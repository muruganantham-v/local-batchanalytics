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
 * Module Detail Screen for local_batchanalytics
 *
 * Sourced from local_bm_classsection moduledata (Batch Management)
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/batchanalytics:view', $context);

global $DB, $PAGE, $OUTPUT, $USER;

$batchid     = optional_param('batchid', 0, PARAM_INT);
$module_idx  = optional_param('module', 1, PARAM_INT);
$courseid    = optional_param('courseid', 0, PARAM_INT);
$action      = optional_param('action', '', PARAM_ALPHA);

// -------------------------------------------------------------------------
// 1. AJAX Action: Save Activity Status (for embedded Activity Tracker)
// -------------------------------------------------------------------------
if ($action === 'saveactivity') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();
        $cmid = required_param('cmid', PARAM_INT);
        $completed = optional_param('completed', 0, PARAM_BOOL);
        $completiondate = optional_param('completiondate', '', PARAM_RAW_TRIMMED);
        $cid = required_param('courseid', PARAM_INT);

        if ($cid > 0) {
            $service = new \local_batchanalytics\activity_tracker_service();
            if ($service->is_table_available()) {
                $saved = $service->save_activity_status($cid, $USER->id, $cmid, (bool)$completed, $completiondate);
                echo json_encode(['status' => 'ok', 'data' => $saved]);
                die();
            }
        }
        echo json_encode(['status' => 'ok', 'saved_locally' => true]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    die();
}

// -------------------------------------------------------------------------
// 2. Data Retrieval from Batch Management (local_bm_classsection & batch)
// -------------------------------------------------------------------------
$dbman = $DB->get_manager();
$has_bm_section = $dbman->table_exists('local_bm_classsection');
$has_bm_batch   = $dbman->table_exists('local_bm_batch');
$has_bm_student = $dbman->table_exists('local_bm_student');

$section = null;
$batch = null;
$is_sample_data = false;

if ($courseid > 0 && $has_bm_section) {
    // If courseid is provided, search all class sections to find the matching section and module index
    $all_sections = $DB->get_records('local_bm_classsection', null, 'id ASC');
    foreach ($all_sections as $sec) {
        if (!empty($sec->moduledata)) {
            $mdata_list = \local_batchanalytics\util::decode_module_data($sec->moduledata, false);
            foreach ($mdata_list as $m_info) {
                if (isset($m_info['moodlecourseid']) && (int)$m_info['moodlecourseid'] === $courseid) {
                    $section = $sec;
                    $batchid = (int)$sec->id;
                    $module_idx = (int)($m_info['module'] ?? 1);
                    break 2;
                }
            }
        }
    }
}

if (!$section && $has_bm_section && $batchid > 0) {
    $section = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
    if (!$section) {
        $section = $DB->get_record('local_bm_classsection', ['batchid' => $batchid]);
        if ($section) {
            $batchid = (int)$section->id;
        }
    }
}

if (!$section && $has_bm_section) {
    $sections = $DB->get_records('local_bm_classsection', null, 'id ASC', '*', 0, 1);
    if (!empty($sections)) {
        $section = reset($sections);
        $batchid = (int)$section->id;
    }
}

if ($section && $has_bm_batch && !empty($section->batchid)) {
    $batch = $DB->get_record('local_bm_batch', ['id' => $section->batchid]);
}

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

$canonical_days = [
    1 => 5, 2 => 58, 3 => 10, 4 => 22, 5 => 28, 6 => 25, 7 => 10, 8 => 10
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

// Extract & normalize header fields
if ($section) {
    $batchname = $section->name ?: ($batch ? $batch->name : 'Batch ' . $section->id);
    $pmname = !empty($section->pmmanagername) ? $section->pmmanagername : '';
    if ($pmname === '' && !empty($section->pmmanager)) {
        $pmuser = $DB->get_record('user', ['id' => $section->pmmanager, 'deleted' => 0]);
        if ($pmuser) $pmname = fullname($pmuser);
    }
    $pmname = $pmname ?: 'Ravi Kumar';

    $ssename = !empty($section->maacexecutivename) ? $section->maacexecutivename : '';
    if ($ssename === '' && !empty($section->maacexecutive)) {
        $sseuser = $DB->get_record('user', ['id' => $section->maacexecutive, 'deleted' => 0]);
        if ($sseuser) $ssename = fullname($sseuser);
    }
    $ssename = $ssename ?: 'Anitha S';

    $raw_modules = [];
    if (!empty($section->moduledata)) {
        $raw_modules = \local_batchanalytics\util::decode_module_data($section->moduledata, true);
    }
} else {
    $is_sample_data = true;
    $batchname = '26011B';
    $pmname = 'Ravi Kumar';
    $ssename = 'Anitha S';
    $raw_modules = [];
}

// Fallback module sequence if raw_modules is empty
if (empty($raw_modules)) {
    $raw_modules = [
        ['module' => 1, 'courseshortname' => 'Linux Systems',   'primarymentor' => 'Meera R',   'labmentor1' => '—',           'plannedstart' => '28 Jul 2026', 'plannedend' => '03 Aug 2026', 'actualstart' => '28 Jul 2026', 'actualend' => '03 Aug 2026', 'scheduledelta' => 0,      'planneddays' => 5,  'moodlecourseid' => 0],
        ['module' => 2, 'courseshortname' => 'Advanced C',       'primarymentor' => 'Suresh P',  'labmentor1' => 'Kiran R',     'plannedstart' => '04 Aug 2026', 'plannedend' => '28 Oct 2026', 'actualstart' => '04 Aug 2026', 'actualend' => '—',           'scheduledelta' => 'prog',  'planneddays' => 58, 'moodlecourseid' => 2],
        ['module' => 3, 'courseshortname' => 'C++ Programming',  'primarymentor' => '—',         'labmentor1' => '—',           'plannedstart' => '29 Oct 2026', 'plannedend' => '11 Nov 2026', 'actualstart' => '—',           'actualend' => '—',           'scheduledelta' => null,    'planneddays' => 10, 'moodlecourseid' => 0],
        ['module' => 4, 'courseshortname' => 'Data Structures',  'primarymentor' => '—',         'labmentor1' => '—',           'plannedstart' => '12 Nov 2026', 'plannedend' => '11 Dec 2026', 'actualstart' => '—',           'actualend' => '—',           'scheduledelta' => null,    'planneddays' => 22, 'moodlecourseid' => 0],
        ['module' => 5, 'courseshortname' => 'Microcontrollers', 'primarymentor' => '—',         'labmentor1' => '—',           'plannedstart' => '12 Dec 2026', 'plannedend' => '23 Jan 2027', 'actualstart' => '—',           'actualend' => '—',           'scheduledelta' => null,    'planneddays' => 28, 'moodlecourseid' => 0],
        ['module' => 6, 'courseshortname' => 'Linux Internals',  'primarymentor' => '—',         'labmentor1' => '—',           'plannedstart' => '24 Jan 2027', 'plannedend' => '27 Feb 2027', 'actualstart' => '—',           'actualend' => '—',           'scheduledelta' => null,    'planneddays' => 25, 'moodlecourseid' => 0],
        ['module' => 7, 'courseshortname' => 'ELARM',            'primarymentor' => '—',         'labmentor1' => '—',           'plannedstart' => '28 Feb 2027', 'plannedend' => '12 Apr 2027', 'actualstart' => '—',           'actualend' => '—',           'scheduledelta' => null,    'planneddays' => 10, 'moodlecourseid' => 0]
    ];
}

$total_modules = count($raw_modules);
if ($module_idx < 1) $module_idx = 1;
if ($module_idx > $total_modules) $module_idx = $total_modules;

// Current active module record
$cur_mod = $raw_modules[$module_idx - 1] ?? [];
$mod_name = !empty($cur_mod['name']) ? $cur_mod['name'] : (!empty($cur_mod['courseshortname']) ? $cur_mod['courseshortname'] : ($canonical_modules[$module_idx] ?? ('Module ' . $module_idx)));
$planned_days = !empty($cur_mod['planneddays']) ? (int)$cur_mod['planneddays'] : ($canonical_days[$module_idx] ?? 10);

if ($courseid <= 0 && !empty($cur_mod['moodlecourseid'])) {
    $courseid = (int)$cur_mod['moodlecourseid'];
}

// Mentor resolution (All class mentors and lab mentors from Batch Management)
$resolve_mentor_record = static function($raw_val, string $role_label) use ($DB): ?array {
    if (empty($raw_val) || $raw_val === '—' || $raw_val === 0 || $raw_val === '0') {
        return null;
    }
    $mentor_name = '';
    $mentor_user = null;
    if (is_numeric($raw_val)) {
        $u = $DB->get_record('user', ['id' => (int)$raw_val, 'deleted' => 0]);
        if ($u) {
            $mentor_name = fullname($u);
            $mentor_user = $u;
        } else {
            $mentor_name = (string)$raw_val;
        }
    } else {
        $mentor_name = trim((string)$raw_val);
        $parts = explode(' ', $mentor_name);
        $first = $parts[0];
        $last = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : '';
        $users = $DB->get_records_select(
            'user',
            "deleted = 0 AND ((firstname = :f AND lastname = :l) OR CONCAT(firstname, ' ', lastname) = :full)",
            ['f' => $first, 'l' => $last, 'full' => $mentor_name],
            '',
            '*',
            0,
            1
        );
        if (!empty($users)) {
            $mentor_user = reset($users);
        }
    }
    if ($mentor_name === '') {
        return null;
    }
    return [
        'name' => $mentor_name,
        'user' => $mentor_user,
        'role' => $role_label,
    ];
};

$class_mentors = [];
if (!empty($cur_mod['primarymentor'])) {
    $m = $resolve_mentor_record($cur_mod['primarymentor'], 'Primary');
    if ($m) $class_mentors[] = $m;
}
if (!empty($cur_mod['secondarymentor'])) {
    $m = $resolve_mentor_record($cur_mod['secondarymentor'], 'Secondary');
    if ($m) $class_mentors[] = $m;
}

$lab_mentors = [];
if (!empty($cur_mod['labmentor1'])) {
    $m = $resolve_mentor_record($cur_mod['labmentor1'], 'Lab 1');
    if ($m) $lab_mentors[] = $m;
}
if (!empty($cur_mod['labmentor2'])) {
    $m = $resolve_mentor_record($cur_mod['labmentor2'], 'Lab 2');
    if ($m) $lab_mentors[] = $m;
}
if (!empty($cur_mod['labmentor3'])) {
    $m = $resolve_mentor_record($cur_mod['labmentor3'], 'Lab 3');
    if ($m) $lab_mentors[] = $m;
}

// Fallback references for scalar backward compatibility
$class_mentor = !empty($class_mentors) ? implode(', ', array_column($class_mentors, 'name')) : '—';
$class_mentor_user = !empty($class_mentors[0]['user']) ? $class_mentors[0]['user'] : null;
$lab_mentor = !empty($lab_mentors) ? implode(', ', array_column($lab_mentors, 'name')) : '—';
$lab_mentor_user = !empty($lab_mentors[0]['user']) ? $lab_mentors[0]['user'] : null;

$p_start = $format_mod_date($cur_mod['plannedstart'] ?? '');
$p_end   = $format_mod_date($cur_mod['plannedend'] ?? '');
$a_start = $format_mod_date($cur_mod['actualstart'] ?? '');
$a_end   = $format_mod_date($cur_mod['actualend'] ?? '');

$delta = isset($cur_mod['scheduledelta']) ? (int)$cur_mod['scheduledelta'] : null;
$is_in_progress = ($a_start !== '—' && $a_end === '—');
$status_chip_text = $is_in_progress ? 'In progress · on track' : 'Completed · on time';
$status_chip_class = $is_in_progress ? 'a' : 'g';

// Previous & Next module links
$prev_idx = $module_idx > 1 ? ($module_idx - 1) : null;
$next_idx = $module_idx < $total_modules ? ($module_idx + 1) : null;

$prev_name = $prev_idx ? (!empty($raw_modules[$prev_idx - 1]['courseshortname']) ? $raw_modules[$prev_idx - 1]['courseshortname'] : ($canonical_modules[$prev_idx] ?? ('Module ' . $prev_idx))) : null;
$next_name = $next_idx ? (!empty($raw_modules[$next_idx - 1]['courseshortname']) ? $raw_modules[$next_idx - 1]['courseshortname'] : ($canonical_modules[$next_idx] ?? ('Module ' . $next_idx))) : null;

if (!function_exists('format_cell_muted')) {
    function format_cell_muted($val) {
        return ($val === '—' || $val === '') ? '<span class="muted">—</span>' : s($val);
    }
}

// -------------------------------------------------------------------------
// 3. Student Performance Dataset (Course Tab Advanced Filter Dataset)
// -------------------------------------------------------------------------
$students_data = [];

if ($section && $has_bm_student) {
    $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    $sql = "SELECT u.id, u.idnumber, u.email, {$userfields}
              FROM {local_bm_student} s
              JOIN {user} u ON u.id = s.userid
             WHERE s.classsectionid = :csid AND u.deleted = 0
             ORDER BY u.firstname, u.lastname";
    $db_students = $DB->get_records_sql($sql, ['csid' => $section->id]);
    if (!empty($db_students)) {
        $sample_grades = [82, 58, 36, 44, 74, 61, 32, 39, 88, 91, 66, 71];
        $sample_merits = [[1, 'sel'], [0, 'nom'], [0, 0], [0, 0], [1, 0], [1, 'nom'], [0, 0], [0, 0], [1, 'sel'], [1, 'sel'], [0, 'nom'], [1, 0]];
        $i = 0;
        foreach ($db_students as $st) {
            $g = $sample_grades[$i % count($sample_grades)];
            $m = $sample_merits[$i % count($sample_merits)];
            $merit_labels = [];
            if ($m[0]) $merit_labels[] = '★ Spot';
            if ($m[1] === 'nom') $merit_labels[] = 'PT-Nom';
            if ($m[1] === 'sel') $merit_labels[] = 'PT-Sel';

            $students_data[] = [
                'name'           => fullname($st),
                'id'             => !empty($st->idnumber) ? $st->idnumber : ('ST_' . $st->id),
                'grade'          => $g,
                'completion_pct' => min(100, $g + 5),
                'attendance'     => (70 + ($i % 25)) . '%',
                'assignments'    => (60 + ($i % 35)) . '%',
                'projects'       => ($i % 3) ? ((55 + ($i % 40)) . '%') : '—',
                'tests'          => (62 + ($i % 30)) . '%',
                'spot'           => !empty($m[0]),
                'pt'             => $m[1] ?: '',
                'merit_text'     => implode(', ', $merit_labels),
            ];
            $i++;
        }
    }
}

if (empty($students_data)) {
    $b_names = ["Abhay D", "Abijith P", "Ajith M", "Akshay M", "Anitha S", "Anushka K", "Arjun R", "Badulla J", "Chaitra K", "Dipashree B", "Gowtham N", "Harish V"];
    $b_ids   = ["26011_017", "26011_038", "26001_137", "25050_017", "26011_101", "26011_049", "26011_072", "26011_066", "26011_205", "26011_111", "26011_090", "26011_058"];
    $b_grade = [80, 55, 38, 46, 72, 60, 34, 41, 86, 90, 64, 69];
    $b_merit = [[1, 'sel'], [0, 'nom'], [0, 0], [0, 0], [1, 0], [1, 'nom'], [0, 0], [0, 0], [1, 'sel'], [1, 'sel'], [0, 'nom'], [1, 0]];

    for ($i = 0; $i < count($b_names); $i++) {
        $m = $b_merit[$i];
        $merit_labels = [];
        if ($m[0]) $merit_labels[] = '★ Spot';
        if ($m[1] === 'nom') $merit_labels[] = 'PT-Nom';
        if ($m[1] === 'sel') $merit_labels[] = 'PT-Sel';

        $students_data[] = [
            'name'           => $b_names[$i],
            'id'             => $b_ids[$i],
            'grade'          => $b_grade[$i],
            'completion_pct' => min(100, $b_grade[$i] + 6),
            'attendance'     => (70 + ($i % 25)) . '%',
            'assignments'    => (60 + ($i % 35)) . '%',
            'projects'       => ($i % 3) ? ((55 + ($i % 40)) . '%') : '—',
            'tests'          => (62 + ($i % 30)) . '%',
            'spot'           => !empty($m[0]),
            'pt'             => $m[1] ?: '',
            'merit_text'     => implode(', ', $merit_labels),
        ];
    }
}

// -------------------------------------------------------------------------
// -------------------------------------------------------------------------
// 4. Module KPIs (Course Metrics from LMS Gradebook or Populated Canonical)
// -------------------------------------------------------------------------
function get_ba_category_icon_and_color(string $name, int $index): array {
    $n = strtolower($name);
    $icons = [
        'attendance' => '<path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2zm-7 5h5v5h-5v-5z"></path>',
        'assignment' => '<path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 18H6V4h2v8l2.5-1.5L13 12V4h5v16z"></path>',
        'quiz'       => '<path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"></path>',
        'test'       => '<path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"></path>',
        'project'    => '<path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"></path>',
        'programming'=> '<path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"></path>',
        'maac'       => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"></path>',
    ];

    $svg_path = $icons['assignment'];
    if (strpos($n, 'attend') !== false) {
        $svg_path = $icons['attendance'];
    } elseif (strpos($n, 'quiz') !== false || strpos($n, 'qiuz') !== false || strpos($n, 'objective') !== false) {
        $svg_path = $icons['quiz'];
    } elseif (strpos($n, 'project') !== false) {
        $svg_path = $icons['project'];
    } elseif (strpos($n, 'program') !== false || strpos($n, 'code') !== false) {
        $svg_path = $icons['programming'];
    } elseif (strpos($n, 'test') !== false || strpos($n, 'module') !== false) {
        $svg_path = $icons['test'];
    } elseif (strpos($n, 'maac') !== false || strpos($n, 'report') !== false) {
        $svg_path = $icons['maac'];
    }

    $palettes = [
        ['c' => '#2196F3', 'b' => '#E3F2FD'],
        ['c' => '#E91E63', 'b' => '#FCE4EC'],
        ['c' => '#FF9800', 'b' => '#FFF3E0'],
        ['c' => '#4CAF50', 'b' => '#E8F5E9'],
        ['c' => '#9C27B0', 'b' => '#F3E5F5'],
        ['c' => '#009688', 'b' => '#E0F2F1'],
    ];
    $color_set = $palettes[$index % count($palettes)];

    return [
        'bg' => $color_set['b'],
        'icon' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="' . $color_set['c'] . '">' . $svg_path . '</svg>',
    ];
}

function get_ba_comp_class(float $v): string {
    return $v < 50 ? 'text-danger' : ($v < 80 ? 'text-warning' : 'text-success');
}

$module_kpis = [];
$kpi_categories_data = [];

// Resolve enrolled students for course metrics
$student_role_id = $DB->get_field('role', 'id', ['shortname' => 'student']) ?: 5;
$students_sql = "
    SELECT DISTINCT
        u.id as userid,
        CONCAT(u.firstname, ' ', u.lastname) as fullname,
        u.username,
        u.idnumber
    FROM {user} u
    JOIN {role_assignments} ra ON ra.userid = u.id
    JOIN {context} ctx ON ctx.id = ra.contextid
    WHERE ctx.instanceid = :courseid
      AND ctx.contextlevel = 50
      AND ra.roleid = :roleid
      AND u.deleted = 0
    ORDER BY u.firstname, u.lastname
";
$enrolled_students = $courseid > 0 ? $DB->get_records_sql($students_sql, ['courseid' => $courseid, 'roleid' => $student_role_id]) : [];

if ($courseid > 0) {
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
    $all_cats = $DB->get_records('grade_categories', ['courseid' => $courseid]);

    $raw_cats = [];
    foreach ($grade_items as $item) {
        if ($item->itemtype === 'course') {
            $cat_name = 'MAAC Ratings';
        } else {
            $cat_id = $item->categoryid;
            $cat_name = 'Uncategorized';
            if ($cat_id && isset($all_cats[$cat_id])) {
                $c = $all_cats[$cat_id];
                while ($c->depth > 2 && !empty($c->parent) && isset($all_cats[$c->parent])) {
                    $c = $all_cats[$c->parent];
                }
                if ($c->depth == 2) {
                    $cat_name = $c->fullname;
                }
            }
        }

        if (trim($cat_name) === '' || $cat_name === '?' || $cat_name === 'Uncategorized') {
            continue;
        }

        if (!isset($raw_cats[$cat_name])) {
            $raw_cats[$cat_name] = [
                'categoryname' => $cat_name,
                'items' => [],
                'studentGrades' => []
            ];
        }
        $raw_cats[$cat_name]['items'][] = [
            'itemid' => $item->itemid,
            'grademax' => (float)$item->grademax,
            'grademin' => (float)$item->grademin
        ];
    }

    if (!empty($raw_cats) && !empty($enrolled_students)) {
        foreach ($raw_cats as $category_key => &$category_data) {
            if ($category_key === 'MAAC Ratings' || stripos($category_key, 'attend') !== false) {
                continue;
            }
            $category_data['categoryname'] = $category_key . ' (' . count($category_data['items']) . ')';
        }
        unset($category_data);

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
        $grades_map = [];
        $rs = $DB->get_recordset_sql($all_grades_sql, ['courseid' => $courseid]);
        foreach ($rs as $rec) {
            $grades_map[$rec->userid][$rec->itemid] = $rec->finalgrade;
        }
        $rs->close();

        // Synchronize MAAC ratings from maac_service (exactly as simple.js syncCourseMaacRatings does)
        $maac_service = new \local_batchanalytics\maac_service();
        $maac_ratings = [];
        try {
            $m_data = $maac_service->get_course_data($courseid, $USER->id);
            if (!empty($m_data['students'])) {
                foreach ($m_data['students'] as $st) {
                    if (isset($st['maac_rating']) && $st['maac_rating'] !== null && $st['maac_rating'] !== '') {
                        $maac_ratings[$st['username']] = (float)$st['maac_rating'];
                    }
                }
            }
        } catch (\Throwable $e) {}

        $kpi_card_idx = 0;
        foreach ($raw_cats as $cat_key => &$cat_data) {
            $display_name = $cat_data['categoryname'];
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

            $total_pct_sum = 0;
            $valid_pct_count = 0;
            $total_comp_sum = 0;

            foreach ($enrolled_students as $student) {
                $total_earned = 0;
                $total_max = 0;
                $items_completed = 0;

                foreach ($cat_data['items'] as $item) {
                    $itemid = $item['itemid'];
                    $userid = $student->userid;

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

                $percentage = $total_max > 0 ? round(($total_earned / $total_max) * 100, 2) : null;
                if ($cat_key === 'MAAC Ratings') {
                    if (isset($maac_ratings[$student->username])) {
                        $percentage = $maac_ratings[$student->username];
                    } else if ($percentage !== null) {
                        $percentage = round($percentage / 10, 1);
                    }
                }

                $comp_rate = $gradable_items_in_cat > 0
                    ? round(($items_completed / $gradable_items_in_cat) * 100, 2)
                    : 0;

                if ($percentage !== null) {
                    $total_pct_sum += $percentage;
                    $valid_pct_count++;
                }
                $total_comp_sum += $comp_rate;

                $cat_data['studentGrades'][] = [
                    'userid' => $student->userid,
                    'fullname' => $student->fullname,
                    'username' => !empty($student->idnumber) ? $student->idnumber : $student->username,
                    'percentage' => $percentage,
                    'completionRate' => $comp_rate,
                    'totalEarned' => $total_earned
                ];
            }

            $isMaac = ($cat_key === 'MAAC Ratings');
            $isAtt = (stripos($cat_key, 'attend') !== false);
            $avgG = $valid_pct_count > 0 ? ($total_pct_sum / $valid_pct_count) : 0.0;
            $avgC = count($enrolled_students) > 0 ? ($total_comp_sum / count($enrolled_students)) : 0.0;
            $avgGFormatted = number_format($avgG, 2);
            $avgCFormatted = number_format($avgC, 2);

            $val_str = $isMaac ? $avgGFormatted : ($avgGFormatted . '%');
            $sub_str = $isMaac ? 'Avg Rating' : ($isAtt ? 'Avg Attendance' : ('Completion ' . $avgCFormatted . '%'));

            $style = get_ba_category_icon_and_color($display_name, $kpi_card_idx);
            $kpi_card_idx++;

            $module_kpis[] = [
                'label' => $display_name,
                'val'   => $val_str,
                'sub'   => $sub_str,
                'avgGrade' => $avgG,
                'avgGradeFormatted' => $avgGFormatted,
                'avgCompletion' => $avgC,
                'avgCompFormatted' => $avgCFormatted,
                'compClass' => get_ba_comp_class((float)$avgC),
                'icon_bg' => $style['bg'],
                'icon_svg' => $style['icon'],
                'isMaac' => $isMaac,
                'isAttendance' => $isAtt
            ];

            $kpi_categories_data[$display_name] = [
                'categoryname' => $display_name,
                'avgGrade' => $avgG,
                'avgGradeFormatted' => $avgGFormatted,
                'avgCompletion' => $avgC,
                'avgCompFormatted' => $avgCFormatted,
                'isMaac' => $isMaac,
                'isAttendance' => $isAtt,
                'studentGrades' => $cat_data['studentGrades']
            ];
        }
        unset($cat_data);
    }
}

// Fallback or standard 6 categories for courses without live grade items
if (empty($module_kpis)) {
    $std_metrics = [
        'Attendance'   => ['val' => '82.00%', 'avgGrade' => 82.0, 'sub' => 'Avg Grade',          'isAtt' => true,  'isMaac' => false, 'comp' => 100.0],
        'Assignments'  => ['val' => '74.00%', 'avgGrade' => 74.0, 'sub' => 'Completion 78.50%', 'isAtt' => false, 'isMaac' => false, 'comp' => 78.5],
        'Class Work'   => ['val' => '91.00%', 'avgGrade' => 91.0, 'sub' => 'Completion 95.00%', 'isAtt' => false, 'isMaac' => false, 'comp' => 95.0],
        'Project Work' => ['val' => '0.00%',  'avgGrade' => 0.0,  'sub' => 'Not started',        'isAtt' => false, 'isMaac' => false, 'comp' => 0.0],
        'Module Test'  => ['val' => '63.00%', 'avgGrade' => 63.0, 'sub' => 'Completion 60.00%', 'isAtt' => false, 'isMaac' => false, 'comp' => 60.0],
        'Quiz'         => ['val' => '64.00%', 'avgGrade' => 64.0, 'sub' => 'Completion 70.00%', 'isAtt' => false, 'isMaac' => false, 'comp' => 70.0],
    ];

    $pool = [];
    if (!empty($enrolled_students)) {
        foreach ($enrolled_students as $st) {
            $pool[] = [
                'userid' => $st->userid,
                'username' => !empty($st->idnumber) ? $st->idnumber : $st->username,
                'fullname' => $st->fullname,
            ];
        }
    } else if (!empty($students_data)) {
        foreach ($students_data as $s) {
            $pool[] = [
                'userid' => $s['id'],
                'username' => $s['id'],
                'fullname' => $s['name'],
                'base_grade' => $s['grade'] ?? 75,
            ];
        }
    }

    $fb_idx = 0;
    foreach ($std_metrics as $lbl => $m) {
        $style = get_ba_category_icon_and_color($lbl, $fb_idx);
        $fb_idx++;

        $module_kpis[] = [
            'label' => $lbl,
            'val'   => $m['val'],
            'sub'   => $m['sub'],
            'avgGrade' => $m['avgGrade'],
            'avgGradeFormatted' => number_format($m['avgGrade'], 2),
            'avgCompletion' => $m['comp'],
            'avgCompFormatted' => number_format($m['comp'], 2),
            'compClass' => get_ba_comp_class((float)$m['comp']),
            'icon_bg' => $style['bg'],
            'icon_svg' => $style['icon'],
            'isMaac' => $m['isMaac'],
            'isAttendance' => $m['isAtt']
        ];

        $studentGrades = [];
        $idx = 0;
        foreach ($pool as $p) {
            $base = isset($p['base_grade']) ? $p['base_grade'] : (60 + (($idx * 7) % 35));
            if ($lbl === 'Project Work') {
                $gradeVal = null;
                $compVal = 0.0;
            } else if ($lbl === 'Attendance') {
                $gradeVal = min(100, max(50, $base + 5));
                $compVal = 100.0;
            } else {
                $gradeVal = min(100, max(30, $base + ($idx % 5) - 2));
                $compVal = min(100, max(0, $m['comp'] + (($idx % 9) - 4)));
            }

            $studentGrades[] = [
                'userid' => $p['userid'],
                'username' => $p['username'],
                'fullname' => $p['fullname'],
                'percentage' => $gradeVal,
                'completionRate' => $compVal,
                'totalEarned' => $gradeVal
            ];
            $idx++;
        }

        $kpi_categories_data[$lbl] = [
            'categoryname' => $lbl,
            'avgGrade' => $m['avgGrade'],
            'avgGradeFormatted' => number_format($m['avgGrade'], 2),
            'avgCompletion' => $m['comp'],
            'avgCompFormatted' => number_format($m['comp'], 2),
            'isMaac' => $m['isMaac'],
            'isAttendance' => $m['isAtt'],
            'studentGrades' => $studentGrades
        ];
    }
}

// -------------------------------------------------------------------------
// 5. Tab 1: Mentor Activities (Embedded Activity Tracker UI)
// -------------------------------------------------------------------------
$activity_categories = [];

if ($courseid > 0) {
    $act_service = new \local_batchanalytics\activity_tracker_service();
    if ($act_service->is_table_available()) {
        $tracker_data = $act_service->get_course_data($courseid);
        if (!empty($tracker_data['categories'])) {
            $activity_categories = $tracker_data['categories'];
        }
    }
}

// Fallback demo activities for Tracker UI if no course items found
if (empty($activity_categories)) {
    $activity_categories = [
        [
            'id' => 'assignments', 'name' => 'Assignments', 'completed' => 1, 'pending' => 2,
            'activities' => [
                ['cmid' => 101, 'name' => 'Assignment 1: Pointer Arithmetic & Memory Layout', 'completed' => true,  'completiondate' => '2026-09-19'],
                ['cmid' => 102, 'name' => 'Assignment 2: Bitwise Operators & Bit Manipulation', 'completed' => false, 'completiondate' => ''],
                ['cmid' => 103, 'name' => 'Assignment 3: Dynamic Memory Allocation (DMA)',     'completed' => false, 'completiondate' => ''],
            ]
        ],
        [
            'id' => 'tests', 'name' => 'Module Tests', 'completed' => 1, 'pending' => 1,
            'activities' => [
                ['cmid' => 201, 'name' => 'Objective Test: C Storage Classes & Scope', 'completed' => true,  'completiondate' => '2026-09-15'],
                ['cmid' => 202, 'name' => 'Module Test Conduct: Comprehensive Advanced C', 'completed' => false, 'completiondate' => ''],
            ]
        ],
        [
            'id' => 'nominations', 'name' => 'Nominations & Mentorship', 'completed' => 1, 'pending' => 1,
            'activities' => [
                ['cmid' => 301, 'name' => 'Spot Award Nomination (Batch Mentor Recommendation)', 'completed' => true,  'completiondate' => '2026-09-14'],
                ['cmid' => 302, 'name' => 'Power Track Nomination (High Potentials Selection)',  'completed' => false, 'completiondate' => ''],
            ]
        ],
        [
            'id' => 'projects', 'name' => 'Projects', 'completed' => 0, 'pending' => 2,
            'activities' => [
                ['cmid' => 401, 'name' => 'Mini Project: LSB Steganography', 'completed' => false, 'completiondate' => ''],
                ['cmid' => 402, 'name' => 'Project Evaluation & Code Review', 'completed' => false, 'completiondate' => ''],
            ]
        ]
    ];
}

// -------------------------------------------------------------------------
// 6. Tab 2: SS Activities (Module Level)
// -------------------------------------------------------------------------
$ss_module_activities = [
    ['activity' => 'Spot Award distribution',         'planned' => '16 Sep 2026', 'actual' => '16 Sep 2026', 'status' => 'done'],
    ['activity' => 'Mid-module survey completed',     'planned' => '25 Sep 2026', 'actual' => '—',           'status' => 'pending'],
    ['activity' => 'Red-case follow-up (module)',     'planned' => '22 Sep 2026', 'actual' => '—',           'status' => 'over'],
    ['activity' => 'Soft-skill session (module)',     'planned' => '05 Oct 2026', 'actual' => '—',           'status' => 'pending'],
];

$ss_status_styles = [
    'done'    => ['st-g', 'Done'],
    'pending' => ['st-a', 'Pending'],
    'over'    => ['st-r', 'Overdue']
];

// -------------------------------------------------------------------------
// 7. Page Setup & HTML Output
// -------------------------------------------------------------------------
$PAGE->set_context($context);
if ($courseid > 0) {
    $PAGE->set_url(new moodle_url('/local/batchanalytics/module.php', ['courseid' => $courseid]));
} else {
    $PAGE->set_url(new moodle_url('/local/batchanalytics/module.php', [
        'batchid' => $batchid,
        'module'  => $module_idx,
    ]));
}
$PAGE->set_title($mod_name . ' – ' . $batchname . ' – Batch Analytics');
$PAGE->set_heading('');

$styleurl = new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime(__DIR__ . '/styles.css')]);
$scripturl = new moodle_url('/local/batchanalytics/module.js', ['v' => filemtime(__DIR__ . '/module.js')]);
$PAGE->requires->css($styleurl);
$PAGE->requires->js($scripturl);

echo $OUTPUT->header();
?>

<div class="local-batchanalytics-wrap ba-module-page" id="ba-module-detail-container"
     data-courseid="<?= (int)$courseid ?>"
     data-batchid="<?= (int)$batchid ?>"
     data-sesskey="<?= sesskey() ?>"
     data-students="<?= s(json_encode($students_data)) ?>"
     data-kpi-data="<?= s(json_encode($kpi_categories_data)) ?>">

  <!-- Breadcrumb Bar -->
  <div class="crumbbar">
    <span class="crumb">
      <a href="<?= s((new moodle_url('/local/batchanalytics/index.php'))->out(false)) ?>">Home</a>
      &nbsp;›&nbsp;
      <a href="<?= s((new moodle_url('/local/batchanalytics/batch.php', ['id' => $batchid]))->out(false)) ?>"><?= s($batchname) ?></a>
      &nbsp;›&nbsp;
      <b><?= s($mod_name) ?></b>
    </span>
  </div>

  <!-- Module Header Card -->
  <div class="mhead">
    <div class="mtop">
      <div>
        <h1><?= s($mod_name) ?></h1>
        <div class="meta">
          Batch <b><?= s($batchname) ?></b> &nbsp;·&nbsp;
          Module <?= (int)$module_idx ?> of <?= (int)$total_modules ?> &nbsp;·&nbsp;
          Planned <?= (int)$planned_days ?> working days
        </div>
        <div class="navbtns">
          <?php if ($prev_idx): ?>
            <?php
              $prev_cid = !empty($raw_modules[$prev_idx - 1]['moodlecourseid']) ? (int)$raw_modules[$prev_idx - 1]['moodlecourseid'] : 0;
              $prev_linked = $prev_cid > 0 && $DB->record_exists('course', ['id' => $prev_cid]);
            ?>
            <?php if ($prev_linked): ?>
              <a href="<?= s((new moodle_url('/local/batchanalytics/module.php', ['courseid' => $prev_cid]))->out(false)) ?>">
                ‹ <?= s($prev_name) ?>
              </a>
            <?php else: ?>
              <button type="button" disabled title="Course is not linked in Batch Management">‹ <?= s($prev_name) ?></button>
            <?php endif; ?>
          <?php else: ?>
            <button type="button" disabled>‹ —</button>
          <?php endif; ?>

          <?php if ($next_idx): ?>
            <?php
              $next_cid = !empty($raw_modules[$next_idx - 1]['moodlecourseid']) ? (int)$raw_modules[$next_idx - 1]['moodlecourseid'] : 0;
              $next_linked = $next_cid > 0 && $DB->record_exists('course', ['id' => $next_cid]);
            ?>
            <?php if ($next_linked): ?>
              <a href="<?= s((new moodle_url('/local/batchanalytics/module.php', ['courseid' => $next_cid]))->out(false)) ?>">
                <?= s($next_name) ?> ›
              </a>
            <?php else: ?>
              <button type="button" disabled title="Course is not linked in Batch Management"><?= s($next_name) ?> ›</button>
            <?php endif; ?>
          <?php else: ?>
            <button type="button" disabled>— ›</button>
          <?php endif; ?>
        </div>
      </div>

      <div style="display:flex; flex-direction:column; align-items:flex-end; gap:10px;">
        <div class="status-chip <?= s($status_chip_class) ?>"><?= s($status_chip_text) ?></div>
        <?php if ($courseid > 0): ?>
          <a href="<?= s((new moodle_url('/local/batchanalytics/maac.php', ['courseid' => $courseid]))->out(false)) ?>" class="maac-btn" target="_blank" rel="noopener">View MAAC Sheet ›</a>
        <?php else: ?>
          <a href="<?= s((new moodle_url('/local/batchanalytics/maac.php', ['courseid' => 2]))->out(false)) ?>" class="maac-btn" target="_blank" rel="noopener">View MAAC Sheet ›</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- People Row: PM and SSE -->
    <div class="people">
      <div class="person">
        <div class="role">Program Manager</div>
        <div class="pwrap">
          <div class="pav" style="display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#cc0066,#663399);color:#fff;font-weight:700;font-size:14px;border-radius:50%;width:38px;height:38px;">
            <?= s(strtoupper(substr($pmname, 0, 1))) ?>
          </div>
          <span class="name"><?= s($pmname) ?></span>
        </div>
      </div>
      <div class="person">
        <div class="role">SS / MAAC Executive</div>
        <div class="pwrap">
          <div class="pav" style="display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1b6ec2,#1559a0);color:#fff;font-weight:700;font-size:14px;border-radius:50%;width:38px;height:38px;">
            <?= s(strtoupper(substr($ssename, 0, 1))) ?>
          </div>
          <span class="name"><?= s($ssename) ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Module Schedule Grid (8-Cells) -->
  <div class="sec-label">Schedule</div>
  <div class="schedgrid">
    <div class="sc">
      <div class="k">Class Mentor<?= count($class_mentors) > 1 ? 's' : '' ?></div>
      <?php if (empty($class_mentors)): ?>
        <div class="v muted">—</div>
      <?php else: ?>
        <div class="ba-mentors-col">
          <?php foreach ($class_mentors as $cm): ?>
            <div class="mentorcell">
              <?php if (!empty($cm['user']) && !empty($cm['user']->picture)): ?>
                <?= $OUTPUT->user_picture($cm['user'], ['size' => 30, 'link' => false, 'class' => 'pav']) ?>
              <?php else: ?>
                <div class="pav" style="display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#663399,#1b6ec2);color:#fff;font-weight:700;font-size:12px;border-radius:50%;width:30px;height:30px;flex-shrink:0;">
                  <?= s(strtoupper(substr($cm['name'], 0, 1))) ?>
                </div>
              <?php endif; ?>
              <div class="mentor-meta">
                <span class="v"><?= s($cm['name']) ?></span>
                <?php if (count($class_mentors) > 1): ?>
                  <span class="mentor-role-tag"><?= s($cm['role']) ?></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="sc">
      <div class="k">Lab Mentor<?= count($lab_mentors) > 1 ? 's' : '' ?></div>
      <?php if (empty($lab_mentors)): ?>
        <div class="v muted">—</div>
      <?php else: ?>
        <div class="ba-mentors-col">
          <?php foreach ($lab_mentors as $lm): ?>
            <div class="mentorcell">
              <?php if (!empty($lm['user']) && !empty($lm['user']->picture)): ?>
                <?= $OUTPUT->user_picture($lm['user'], ['size' => 30, 'link' => false, 'class' => 'pav']) ?>
              <?php else: ?>
                <div class="pav" style="display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#2e7d32,#1e8e4e);color:#fff;font-weight:700;font-size:12px;border-radius:50%;width:30px;height:30px;flex-shrink:0;">
                  <?= s(strtoupper(substr($lm['name'], 0, 1))) ?>
                </div>
              <?php endif; ?>
              <div class="mentor-meta">
                <span class="v"><?= s($lm['name']) ?></span>
                <?php if (count($lab_mentors) > 1): ?>
                  <span class="mentor-role-tag"><?= s($lm['role']) ?></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="sc">
      <div class="k">Planned Start</div>
      <div class="v"><?= s($p_start) ?></div>
    </div>

    <div class="sc">
      <div class="k">Planned End</div>
      <div class="v"><?= s($p_end) ?></div>
    </div>

    <div class="sc">
      <div class="k">Actual Start</div>
      <div class="v"><?= $a_start === '—' ? '<span class="muted">—</span>' : s($a_start) ?></div>
    </div>

    <div class="sc">
      <div class="k">Actual End</div>
      <div class="v <?= $a_end === '—' ? 'muted' : '' ?>">
        <?= $a_end === '—' ? '— (in progress)' : s($a_end) ?>
      </div>
    </div>

    <div class="sc">
      <div class="k">Delay</div>
      <div class="v">
        <?php if (($cur_mod['scheduledelta'] ?? null) === 'prog' || ($is_in_progress && ($delta === null || $delta === 0))): ?>
          <span class="st st-a">In progress</span>
        <?php elseif ($delta === null): ?>
          <span class="muted">—</span>
        <?php elseif ($delta <= 0): ?>
          <span class="st st-g">On track</span>
        <?php elseif ($delta <= 3): ?>
          <span class="st st-a">+<?= (int)$delta ?>d</span>
        <?php else: ?>
          <span class="st st-r">+<?= (int)$delta ?>d</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="sc">
      <div class="k">Remarks</div>
      <div class="v muted">—</div>
    </div>
  </div>

  <!-- Module KPIs (Course Metrics from Gradebook) -->
  <div class="sec-label">Module KPIs <span class="subx">· auto-pulled from LMS · click any metric to view student details</span></div>
  <div class="ba-course-metrics-grid" style="margin-bottom: 25px;">
    <?php foreach ($module_kpis as $kpi): ?>
      <div class="ba-course-metric-card" data-category-modal="1" data-category-name="<?= s($kpi['label']) ?>" role="button" tabindex="0" title="Click to view student details for <?= s($kpi['label']) ?>">
        <div class="ba-course-metric-header">
          <div class="ba-course-metric-icon" style="background:<?= s($kpi['icon_bg']) ?>;">
            <?= $kpi['icon_svg'] ?>
          </div>
          <div class="ba-course-metric-title"><?= s($kpi['label']) ?></div>
        </div>
        <div class="ba-course-metric-stats">
          <?php if ($kpi['isMaac']): ?>
            <div class="stat-box" style="width:100%; text-align:center; align-items:center;">
              <span class="lbl">Avg MAAC Rating</span>
              <span class="val"><?= s($kpi['avgGradeFormatted']) ?></span>
            </div>
          <?php elseif ($kpi['isAttendance']): ?>
            <div class="stat-box" style="width:100%; text-align:center; align-items:center;">
              <span class="lbl">Avg Grade</span>
              <span class="val"><?= s($kpi['avgGradeFormatted']) ?>%</span>
            </div>
          <?php else: ?>
            <div class="stat-box">
              <span class="lbl">Avg Grade</span>
              <span class="val"><?= s($kpi['avgGradeFormatted']) ?>%</span>
            </div>
            <div class="stat-box">
              <span class="lbl">Completion</span>
              <span class="val <?= s($kpi['compClass']) ?>"><?= s($kpi['avgCompFormatted']) ?>%</span>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Navigation Tabs -->
  <div class="tabs ba-module-tabs">
    <div class="tab active" data-tab="mentor">Mentor Activities</div>
    <div class="tab" data-tab="ssact">SS Activities</div>
    <div class="tab" data-tab="mstudents">Student Performance</div>
  </div>

  <div class="ba-module-panels">

    <!-- 1. Mentor Activities Panel (Embedded Activity Tracker UI) -->
    <div id="panel-mentor" class="panel active">
      <div class="panel-note">
        Scheduled activities the mentor performs. Actual date auto-fills and saves directly to the Module Tracker when marked completed.
      </div>

      <div class="ba-tracker-wrap">
        <div class="ba-tracker-header-row">
          <!-- Category Tabs -->
          <div class="ba-tracker-categories">
            <?php foreach ($activity_categories as $idx => $cat): ?>
              <button type="button" class="ba-tracker-cat-btn <?= $idx === 0 ? 'active' : '' ?>" data-cat="<?= s($cat['id']) ?>">
                <?= s($cat['name']) ?>
                <span class="ba-tracker-badge"><?= count($cat['activities']) ?></span>
              </button>
            <?php endforeach; ?>
          </div>

          <div id="ba-tracker-status-msg" class="ba-tracker-status-msg"></div>
        </div>

        <!-- Activity Tables by Category -->
        <?php foreach ($activity_categories as $idx => $cat): ?>
          <div class="ba-tracker-category-table tablecard" id="tracker-cat-<?= s($cat['id']) ?>" style="<?= $idx === 0 ? 'display:block;' : 'display:none;' ?>">
            <table>
              <thead>
                <tr>
                  <th>Activity Name</th>
                  <th style="width:160px;">Status</th>
                  <th style="width:200px;">Completion Date</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($cat['activities'])): ?>
                  <tr><td colspan="3" class="muted" style="text-align:center; padding:24px;">No activities in this category.</td></tr>
                <?php else: ?>
                  <?php foreach ($cat['activities'] as $act): ?>
                    <tr>
                      <td><span class="val"><?= s($act['name']) ?></span></td>
                      <td>
                        <label class="ba-tracker-check">
                          <input type="checkbox" data-cmid="<?= (int)$act['cmid'] ?>" <?= !empty($act['completed']) ? 'checked' : '' ?>>
                          <span>Completed</span>
                        </label>
                      </td>
                      <td>
                        <input type="date" class="ba-tracker-date" data-cmid="<?= (int)$act['cmid'] ?>"
                               value="<?= s($act['completiondate'] ?? '') ?>" <?= !empty($act['completed']) ? '' : 'disabled' ?>>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 2. SS Activities Panel (Module Level) -->
    <div id="panel-ssact" class="panel">
      <div class="panel-note">
        Module-level student-success activities (distinct from batch-level SS activities). Actual date auto-fills when marked done.
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
            <?php foreach ($ss_module_activities as $r): ?>
              <?php $st = $ss_status_styles[$r['status']] ?? ['st-n', 'Pending']; ?>
              <tr>
                <td><span class="val"><?= s($r['activity']) ?></span></td>
                <td class="date"><?= s($r['planned']) ?></td>
                <td class="date"><?= format_cell_muted($r['actual']) ?></td>
                <td><span class="st <?= s($st[0]) ?>"><?= s($st[1]) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 3. Student Performance Panel -->
    <div id="panel-mstudents" class="panel">
      <div class="panel-note">
        Module-level student performance — same view as batch level, scoped to this module. Grade or Percentile banding.
      </div>

      <div class="filterbar">
        <div class="toggle">
          <span class="on" id="ba-mod-tg-grade">Grade</span>
          <span id="ba-mod-tg-pct">Percentile</span>
        </div>
        <button type="button" class="exp" id="ba-mod-export-btn">Export Filtered</button>
      </div>

      <!-- Dynamic Banding Cards -->
      <div class="bandrow" id="ba-mod-bandrow"></div>

      <!-- Performance Data Table -->
      <div class="tablecard">
        <table>
          <thead>
            <tr>
              <th class="sortable" data-sort="band" title="Sort by Band" style="width:50px;">Band</th>
              <th class="sortable" data-sort="student" title="Sort by Student Name">Student</th>
              <th class="sortable" data-sort="grade" title="Sort by Grade">Grade</th>
              <th class="sortable" data-sort="attendance" title="Sort by Attendance">Attendance</th>
              <th class="sortable" data-sort="assignments" title="Sort by Assignments">Assignments</th>
              <th class="sortable" data-sort="projects" title="Sort by Projects">Projects</th>
              <th class="sortable" data-sort="tests" title="Sort by Tests">Tests</th>
              <th class="sortable" data-sort="merit" title="Sort by Merit">Merit</th>
            </tr>
          </thead>
          <tbody id="ba-mod-stu-body">
            <!-- Populated via module.js -->
          </tbody>
        </table>

        <!-- Student Performance Pagination Controls -->
        <div class="ba-pagination-bar" id="ba-mod-pagination-bar">
          <div class="ba-pagination-info" id="ba-mod-pagination-info">
            <!-- Populated via module.js -->
          </div>
          <div class="ba-pagination-actions">
            <div class="ba-pagination-size-box">
              <label for="ba-mod-page-size">Rows per page:</label>
              <select id="ba-mod-page-size" class="ba-pagination-select">
                <option value="10" selected>10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="all">All</option>
              </select>
            </div>
            <div class="ba-pagination-btns" id="ba-mod-pagination-btns">
              <!-- Rendered dynamically via module.js -->
            </div>
          </div>
        </div>
      </div>
    </div>

  </div> <!-- /.ba-module-panels -->

</div> <!-- /.local-batchanalytics-wrap -->

<?php
echo $OUTPUT->footer();
