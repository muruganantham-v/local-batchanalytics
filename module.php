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

$mode_param = optional_param('mode', '', PARAM_TEXT);
if (!empty($mode_param)) {
    $deliverymode = $mode_param;
} else if ($batch && !empty($batch->deliverymode)) {
    $deliverymode = $batch->deliverymode;
} else if ($section && !empty($section->deliverymode)) {
    $deliverymode = $section->deliverymode;
} else {
    $deliverymode = 'Offline';
}

$is_online = \local_batchanalytics\util::is_online_mode($deliverymode);

$canonical_modules = \local_batchanalytics\util::get_canonical_modules($deliverymode);
$canonical_days = \local_batchanalytics\util::get_canonical_days($deliverymode);

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

    $raw_modules = [];
    if (!empty($section->moduledata)) {
        $raw_modules = \local_batchanalytics\util::decode_module_data($section->moduledata, true);
        if ($is_online) {
            $raw_modules = \local_batchanalytics\util::filter_modules_for_mode($raw_modules, $deliverymode);
        }
    }
} else {
    $is_sample_data = true;
    $batchname = '26011B';
    $pmname = 'Ravi Kumar';
    $pmuser = null;
    $ssename = 'Anitha S';
    $sseuser = null;
    $raw_modules = [];
}

// Fallback module sequence if raw_modules is empty
if (empty($raw_modules)) {
    $raw_modules = [
        ['module' => 1, 'courseshortname' => 'Linux Systems',   'primarymentor' => 'Meera R',   'labmentor1' => '—',           'plannedstart' => '28 Jul 2026', 'plannedend' => '03 Aug 2026', 'actualstart' => '28 Jul 2026', 'actualend' => '03 Aug 2026', 'scheduledelta' => 0,      'planneddays' => 5,  'moodlecourseid' => 0],
        ['module' => 2, 'courseshortname' => 'Advanced C',       'primarymentor' => 'Suresh P',  'labmentor1' => 'Kiran R',     'plannedstart' => '04 Aug 2026', 'plannedend' => '28 Oct 2026', 'actualstart' => '04 Aug 2026', 'actualend' => '—',           'scheduledelta' => 'prog',  'planneddays' => 77, 'moodlecourseid' => 2],
        ['module' => 3, 'courseshortname' => 'C++ Programming',  'primarymentor' => '—',         'labmentor1' => '—',           'plannedstart' => '29 Oct 2026', 'plannedend' => '11 Nov 2026', 'actualstart' => '—',           'actualend' => '—',           'scheduledelta' => null,    'planneddays' => 13, 'moodlecourseid' => 0],
        ['module' => 4, 'courseshortname' => 'Data Structures',  'primarymentor' => '—',         'labmentor1' => '—',           'plannedstart' => '12 Nov 2026', 'plannedend' => '11 Dec 2026', 'actualstart' => '—',           'actualend' => '—',           'scheduledelta' => null,    'planneddays' => 29, 'moodlecourseid' => 0],
        ['module' => 5, 'courseshortname' => 'Microcontrollers', 'primarymentor' => '—',         'labmentor1' => '—',           'plannedstart' => '12 Dec 2026', 'plannedend' => '23 Jan 2027', 'actualstart' => '—',           'actualend' => '—',           'scheduledelta' => null,    'planneddays' => 37, 'moodlecourseid' => 0],
        ['module' => 6, 'courseshortname' => 'Linux Internals',  'primarymentor' => '—',         'labmentor1' => '—',           'plannedstart' => '24 Jan 2027', 'plannedend' => '27 Feb 2027', 'actualstart' => '—',           'actualend' => '—',           'scheduledelta' => null,    'planneddays' => 33, 'moodlecourseid' => 0],
        ['module' => 7, 'courseshortname' => 'ELARM',            'primarymentor' => '—',         'labmentor1' => '—',           'plannedstart' => '28 Feb 2027', 'plannedend' => '12 Apr 2027', 'actualstart' => '—',           'actualend' => '—',           'scheduledelta' => null,    'planneddays' => 10, 'moodlecourseid' => 0],
        ['module' => 8, 'courseshortname' => 'Qt / QML',         'primarymentor' => '—',         'labmentor1' => '—',           'plannedstart' => '13 Apr 2027', 'plannedend' => '25 Apr 2027', 'actualstart' => '—',           'actualend' => '—',           'scheduledelta' => null,    'planneddays' => 10, 'moodlecourseid' => 0]
    ];
    if ($is_online) {
        $raw_modules = \local_batchanalytics\util::filter_modules_for_mode($raw_modules, $deliverymode);
    }
}

$total_modules = count($raw_modules);
if ($module_idx < 1) $module_idx = 1;
if ($module_idx > $total_modules) $module_idx = $total_modules;

// Current active module record
$cur_mod = $raw_modules[$module_idx - 1] ?? [];
$mod_name = !empty($cur_mod['name']) ? $cur_mod['name'] : (!empty($cur_mod['courseshortname']) ? $cur_mod['courseshortname'] : ($canonical_modules[$module_idx] ?? ('Module ' . $module_idx)));

if ($is_online && \local_batchanalytics\util::is_qt_module($mod_name)) {
    $module_idx = $total_modules;
    $cur_mod = $raw_modules[$module_idx - 1] ?? [];
    $mod_name = !empty($cur_mod['name']) ? $cur_mod['name'] : (!empty($cur_mod['courseshortname']) ? $cur_mod['courseshortname'] : ($canonical_modules[$module_idx] ?? ('Module ' . $module_idx)));
}
$planned_days = !empty($cur_mod['planneddays']) ? (int)$cur_mod['planneddays'] : \local_batchanalytics\util::get_module_total_days($mod_name, $canonical_days[$module_idx] ?? 10);

if ($courseid <= 0 && !empty($cur_mod['moodlecourseid'])) {
    $courseid = (int)$cur_mod['moodlecourseid'];
}
if ($courseid > 0 && !$DB->record_exists('course', ['id' => $courseid])) {
    $courseid = 0;
}

// Mentor resolution (All class mentors and lab mentors from Batch Management)
$resolve_mentor_record = static function($raw_val, string $role_label) use ($DB): ?array {
    if (empty($raw_val) || $raw_val === '—' || $raw_val === 0 || $raw_val === '0') {
        return null;
    }
    $profile = \local_batchanalytics\util::get_user_profile_data($raw_val);
    $mentor_name = $profile['name'];
    $mentor_user = $profile['user'];
    if (!$mentor_user && !is_numeric($raw_val)) {
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
            $mentor_name = fullname($mentor_user);
        }
    }
    if ($mentor_name === '') {
        return null;
    }
    return [
        'name'            => $mentor_name,
        'user'            => $mentor_user,
        'profileimageurl' => $profile['profileimageurl'] ?? '',
        'initials'        => $profile['initials'] ?? '',
        'role'            => $role_label,
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

$delta = isset($cur_mod['scheduledelta']) && is_numeric($cur_mod['scheduledelta']) ? (int)$cur_mod['scheduledelta'] : null;
$is_in_progress = ($a_start !== '—' && $a_end === '—');
$is_completed = ($a_end !== '—');

$mod_days = \local_batchanalytics\util::get_module_total_days($mod_name, $planned_days);
$mod_status_info = \local_batchanalytics\util::get_module_status($delta, $mod_name, $mod_days);

if ($is_in_progress && ($delta === null || $delta === 0)) {
    $status_chip_text = 'In progress · On schedule';
    $status_chip_class = 'g';
} else if ($is_in_progress) {
    $status_chip_text = 'In progress · ' . $mod_status_info['label'];
    $status_chip_class = $mod_status_info['chip_class'];
} else if ($is_completed) {
    $status_chip_text = 'Completed · ' . $mod_status_info['label'];
    $status_chip_class = $mod_status_info['chip_class'];
} else if ($delta !== null) {
    $status_chip_text = $mod_status_info['label'];
    $status_chip_class = $mod_status_info['chip_class'];
} else {
    $status_chip_text = 'Not started';
    $status_chip_class = 'n';
}

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

$spot_award_counts = [];
if ($courseid > 0 && $DB->get_manager()->table_exists('spotaward_nominations') && $DB->get_manager()->table_exists('spotaward_nomination_items')) {
    $sql = "SELECT sni.studentid, COUNT(sni.id) AS awardcount
              FROM {spotaward_nomination_items} sni
              JOIN {spotaward_nominations} sn ON sn.id = sni.nominationid
             WHERE sn.courseid = :courseid
               AND sni.status = 'closed'
          GROUP BY sni.studentid";
    foreach ($DB->get_records_sql($sql, ['courseid' => $courseid]) as $record) {
        $spot_award_counts[(int)$record->studentid] = (int)$record->awardcount;
    }
}

$enrolledstudents = [];
if ($courseid > 0) {
    $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
    $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
    if ($studentroleid > 0 && $coursecontext) {
        $enrolledstudents = get_role_users(
            $studentroleid,
            $coursecontext,
            false,
            'u.id, u.idnumber, u.username, u.firstname, u.lastname, u.email'
        );
    }
}

foreach ($enrolledstudents as $studentrecord) {
    $spot_count = (int)($spot_award_counts[$studentrecord->id] ?? 0);
    $merit_labels = $spot_count > 0 ? [str_repeat('★', $spot_count) . ' Spot'] : [];
    $profile = \local_batchanalytics\util::get_user_profile_data($studentrecord, 2);
    $students_data[] = [
        'userid' => (int)$studentrecord->id,
        'name' => $profile['name'],
        'profileimageurl' => $profile['profileimageurl'],
        'initials' => $profile['initials'],
        'id' => !empty($studentrecord->idnumber) ? $studentrecord->idnumber : ('ST_' . $studentrecord->id),
        'grade' => null,
        'completion_pct' => null,
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
// Reuse the Course-tab Advanced Filter Gradebook dataset for this table.
$performance_data = (new \local_batchanalytics\student_performance_service())->build(
    $students_data,
    $courseid > 0 ? [$courseid] : [],
    (int)$USER->id
);
$students_data = $performance_data['students'];
$performance_columns = $performance_data['columns'];
$performance_custom_groups = $performance_data['customgroups'] ?? [];

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
    // 1. Fetch depth-2 gradebook categories ordered by their gradebook sequence (gi_cat.sortorder)
    $cat_sql = "
        SELECT gc.id, gc.fullname, gc.depth, gi_cat.sortorder
        FROM {grade_categories} gc
        LEFT JOIN {grade_items} gi_cat ON gi_cat.courseid = gc.courseid AND gi_cat.itemtype = 'category' AND gi_cat.iteminstance = gc.id
        WHERE gc.courseid = :courseid AND gc.depth = 2
        ORDER BY gi_cat.sortorder ASC, gc.id ASC
    ";
    $depth2_cats = $DB->get_records_sql($cat_sql, ['courseid' => $courseid]);
    $all_cats = $DB->get_records('grade_categories', ['courseid' => $courseid]);

    // 2. Fetch all mod and manual grade items that are not permanently hidden
    $items_sql = "
        SELECT
            gi.id as itemid, gi.itemname, gi.itemtype, gi.itemmodule, gi.grademax, gi.grademin,
            gc.id as categoryid, gc.fullname as categoryname
        FROM {grade_items} gi
        LEFT JOIN {grade_categories} gc ON gc.id = gi.categoryid
        WHERE gi.courseid = :courseid
          AND gi.itemtype IN ('mod', 'manual')
          AND gi.hidden != 1
        ORDER BY gi.sortorder ASC, gi.id ASC
    ";
    $grade_items = $DB->get_records_sql($items_sql, ['courseid' => $courseid]);

    // 3. Initialize depth-2 categories
    $raw_cats = [];
    foreach ($depth2_cats as $cat) {
        $cname = trim($cat->fullname);
        if ($cname === '' || $cname === '?') {
            continue;
        }
        $raw_cats[$cat->id] = [
            'categoryid'   => $cat->id,
            'categoryname' => $cname,
            'items'        => [],
            'studentGrades'=> []
        ];
    }

    // 4. Map grade items into depth-2 categories
    foreach ($grade_items as $item) {
        $cat_id = $item->categoryid;
        if ($cat_id && isset($all_cats[$cat_id])) {
            $c = $all_cats[$cat_id];
            while ($c->depth > 2 && !empty($c->parent) && isset($all_cats[$c->parent])) {
                $c = $all_cats[$c->parent];
            }
            if ($c->depth == 2 && isset($raw_cats[$c->id])) {
                $raw_cats[$c->id]['items'][] = [
                    'itemid' => $item->itemid,
                    'grademax' => (float)$item->grademax,
                    'grademin' => (float)$item->grademin
                ];
            }
        }
    }

    // Filter out categories that have no grade items
    $raw_cats = array_filter($raw_cats, function($cdata) {
        return !empty($cdata['items']);
    });

    if (!empty($raw_cats) && !empty($enrolled_students)) {
        foreach ($raw_cats as &$category_data) {
            $category_data['displayname'] = $category_data['categoryname'] . ' (' . count($category_data['items']) . ')';
        }
        unset($category_data);

        $all_grades_sql = "
            SELECT gg.id, gg.userid, gg.itemid, gg.finalgrade
            FROM {grade_grades} gg
            JOIN {grade_items} gi ON gi.id = gg.itemid
            WHERE gi.courseid = :courseid
              AND gi.hidden != 1
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

        $kpi_card_idx = 0;
        foreach ($raw_cats as $cat_id => &$cat_data) {
            $display_name = $cat_data['displayname'];
            $cat_clean_name = $cat_data['categoryname'];
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
            $total_final_grade_sum = 0;
            $valid_final_count = 0;

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
                $comp_rate = $gradable_items_in_cat > 0
                    ? round(($items_completed / $gradable_items_in_cat) * 100, 2)
                    : 0;

                if ($gradable_items_in_cat > 0) {
                    $final_grade = ($percentage !== null)
                        ? round(($percentage * $items_completed) / $gradable_items_in_cat, 2)
                        : 0.0;
                } else {
                    $final_grade = $percentage;
                }

                if ($percentage !== null) {
                    $total_pct_sum += $percentage;
                    $valid_pct_count++;
                }
                $total_comp_sum += $comp_rate;

                if ($final_grade !== null) {
                    $total_final_grade_sum += $final_grade;
                    $valid_final_count++;
                }

                $cat_data['studentGrades'][] = [
                    'userid' => $student->userid,
                    'fullname' => $student->fullname,
                    'username' => !empty($student->idnumber) ? $student->idnumber : $student->username,
                    'percentage' => $percentage,
                    'completionRate' => $comp_rate,
                    'itemsCompleted' => $items_completed,
                    'totalItems' => $gradable_items_in_cat,
                    'finalGrade' => $final_grade,
                    'totalEarned' => $total_earned
                ];
            }

            $avgG = $valid_pct_count > 0 ? ($total_pct_sum / $valid_pct_count) : 0.0;
            $avgC = count($enrolled_students) > 0 ? ($total_comp_sum / count($enrolled_students)) : 0.0;
            $avgFinalG = $valid_final_count > 0 ? ($total_final_grade_sum / $valid_final_count) : 0.0;
            $avgGFormatted = number_format($avgG, 2);
            $avgCFormatted = number_format($avgC, 2);
            $avgFinalGFormatted = number_format($avgFinalG, 2);

            $style = get_ba_category_icon_and_color($display_name, $kpi_card_idx);
            $kpi_card_idx++;

            $module_kpis[] = [
                'label' => $display_name,
                'clean_name' => $cat_clean_name,
                'val'   => $avgCFormatted . '%',
                'sub'   => 'Completion ' . $avgCFormatted . '%',
                'avgGrade' => $avgG,
                'avgGradeFormatted' => $avgGFormatted,
                'avgCompletion' => $avgC,
                'avgCompFormatted' => $avgCFormatted,
                'avgFinalGrade' => $avgFinalG,
                'avgFinalGradeFormatted' => $avgFinalGFormatted,
                'compClass' => get_ba_comp_class((float)$avgC),
                'icon_bg' => $style['bg'],
                'icon_svg' => $style['icon'],
                'isMaac' => false,
                'isAttendance' => false
            ];

            $cat_data_record = [
                'categoryname' => $display_name,
                'totalItems' => $gradable_items_in_cat,
                'avgGrade' => $avgG,
                'avgGradeFormatted' => $avgGFormatted,
                'avgCompletion' => $avgC,
                'avgCompFormatted' => $avgCFormatted,
                'avgFinalGrade' => $avgFinalG,
                'avgFinalGradeFormatted' => $avgFinalGFormatted,
                'isMaac' => false,
                'isAttendance' => false,
                'studentGrades' => $cat_data['studentGrades']
            ];
            $kpi_categories_data[$display_name] = $cat_data_record;
            $kpi_categories_data[$cat_clean_name] = $cat_data_record;
        }
        unset($cat_data);
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

// -------------------------------------------------------------------------
// 6. Tab 2: SS Activities (Module Level, filtered by module planned date range)
// -------------------------------------------------------------------------
$ss_module_activities = [];

$mod_p_start_raw = $cur_mod['plannedstart'] ?? 0;
$mod_p_end_raw = $cur_mod['plannedend'] ?? 0;

$mod_p_start_ts = 0;
if (is_numeric($mod_p_start_raw) && (int)$mod_p_start_raw > 100000) {
    $mod_p_start_ts = (int)$mod_p_start_raw;
} else if (!empty($mod_p_start_raw) && $mod_p_start_raw !== '—') {
    $parsed = strtotime($mod_p_start_raw);
    if ($parsed !== false) $mod_p_start_ts = $parsed;
}

$mod_p_end_ts = 0;
if (is_numeric($mod_p_end_raw) && (int)$mod_p_end_raw > 100000) {
    $mod_p_end_ts = (int)$mod_p_end_raw;
} else if (!empty($mod_p_end_raw) && $mod_p_end_raw !== '—') {
    $parsed = strtotime($mod_p_end_raw);
    if ($parsed !== false) $mod_p_end_ts = $parsed;
}

$today_mid = strtotime('today midnight');
$today_next = $today_mid + 86400;

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

$ss_mod_raw = $section ? json_decode($section->softskillsdata ?? '{}', true) : [];

if (is_array($ss_mod_raw) && $mod_p_start_ts > 0 && $mod_p_end_ts > 0) {
    $range_start = strtotime('today midnight', $mod_p_start_ts);
    $range_end = strtotime('today midnight', $mod_p_end_ts) + 86399;

    foreach ($softskills_items_def as $item) {
        $k = $item['key'];
        $p = (int)($ss_mod_raw[$k . '_planned'] ?? 0);
        $a = (int)($ss_mod_raw[$k . '_actual'] ?? 0);

        // Filter: activity planned date falls between module planned start and end date
        if ($p >= $range_start && $p <= $range_end) {
            if ($a > 0) {
                $status = 'g';
                $label = 'Completed';
            } else if ($p < $today_mid) {
                $days = max(1, floor(($today_mid - $p) / 86400));
                $status = 'r';
                $label = "Overdue ({$days}d)";
            } else if ($p < $today_next) {
                $status = 'a';
                $label = 'Due today';
            } else {
                $days = max(1, floor(($p - $today_mid) / 86400));
                $status = 'b';
                $label = "Upcoming ({$days}d)";
            }

            $ss_module_activities[] = [
                'activity' => $item['label'],
                'planned'  => date('d M Y', $p),
                'actual'   => $a > 0 ? date('d M Y', $a) : '—',
                'status'   => $status,
                'label'    => $label,
            ];
        }
    }
}

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
$newstyleurl = new moodle_url('/local/batchanalytics/new_analytics.css', ['v' => filemtime(__DIR__ . '/new_analytics.css')]);
$scripturl = new moodle_url('/local/batchanalytics/module.js', ['v' => filemtime(__DIR__ . '/module.js')]);
$PAGE->requires->css($styleurl);
$PAGE->requires->css($newstyleurl);
$PAGE->requires->js($scripturl);

echo $OUTPUT->header();

echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
echo '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">';

?>

<div class="local-batchanalytics-wrap ba-module-page" id="ba-module-detail-container"
     data-courseid="<?= (int)$courseid ?>"
     data-batchid="<?= (int)$batchid ?>"
     data-sesskey="<?= sesskey() ?>"
     data-students="<?= s(json_encode($students_data)) ?>"
     data-performance-columns="<?= s(json_encode($performance_columns)) ?>"
     data-performance-custom-groups="<?= s(json_encode($performance_custom_groups)) ?>"
     data-kpi-data="<?= s(json_encode($kpi_categories_data)) ?>">

  <!-- Breadcrumb Bar in Prototype Style -->
  <div class="crumbbar">
    <span class="crumb">
      <a href="<?= s((new moodle_url('/local/batchanalytics/index.php'))->out(false)) ?>">Home</a>
      <span style="color:#cbd5e1; margin:0 6px;">›</span>
      <a href="<?= s((new moodle_url('/local/batchanalytics/batch.php', array_filter(['id' => $batchid, 'mode' => $deliverymode])))->out(false)) ?>"><?= s($batchname) ?></a>
      <span style="color:#cbd5e1; margin:0 6px;">›</span>
      <b><?= s($mod_name) ?></b>
    </span>
  </div>

  <div class="shell">

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
              <a href="<?= s((new moodle_url('/local/batchanalytics/module.php', array_filter(['courseid' => $prev_cid, 'batchid' => $batchid > 0 ? $batchid : null, 'mode' => $deliverymode])))->out(false)) ?>">
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
              <a href="<?= s((new moodle_url('/local/batchanalytics/module.php', array_filter(['courseid' => $next_cid, 'batchid' => $batchid > 0 ? $batchid : null, 'mode' => $deliverymode])))->out(false)) ?>">
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
              <?= \local_batchanalytics\util::render_user_avatar($cm['user'] ?? null, 30, 'pav', $cm['name']) ?>
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
              <?= \local_batchanalytics\util::render_user_avatar($lm['user'] ?? null, 30, 'pav', $lm['name']) ?>
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
        <?php else: ?>
          <span class="st st-<?= s($mod_status_info['chip_class']) ?>"><?= s($mod_status_info['label']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="sc">
      <div class="k">Remarks</div>
      <div class="v muted">—</div>
    </div>
  </div>

  <!-- Module KPIs (Gradebook category completion rate of all students) -->
  <div class="sec-label">Module KPIs <span class="subx">· auto-pulled from LMS · common to mentors &amp; SS team</span></div>
  <div class="kpirow" id="kpirow">
    <?php if (empty($module_kpis)): ?>
      <div class="ba-kpi-empty-msg">No data available for this module.</div>
    <?php else: ?>
      <?php foreach ($module_kpis as $kpi): ?>
        <?php
          $val_display = $kpi['val'];
        ?>
        <div class="kpi" data-category-modal="1" data-category-name="<?= s($kpi['label']) ?>" role="button" tabindex="0" title="Click to view student details for <?= s($kpi['label']) ?>" style="cursor:pointer;">
          <div class="kv"><?= s($val_display) ?></div>
          <div class="kl"><?= s($kpi['label']) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Navigation Tabs (Prototype Style) -->
  <div class="tabs ba-module-tabs">
    <div class="tab active" data-tab="mentor" role="button" tabindex="0">Mentor Activities</div>
    <div class="tab" data-tab="ssact" role="button" tabindex="0">SS Activities</div>
    <div class="tab" data-tab="mstudents" role="button" tabindex="0">Student Performance</div>
  </div>

  <div class="ba-module-panels">

    <!-- 1. Mentor Activities Panel (Embedded Activity Tracker UI) -->
    <div id="panel-mentor" class="panel active">
      <div class="panel-note">
        Scheduled activities the mentor performs. Actual date auto-fills and saves directly to the Module Tracker when marked completed.
      </div>

<?php if (empty($activity_categories)): ?>
        <div class="tablecard">
          <div class="muted" style="text-align:center; padding:24px;">No mentor activities are configured for this module.</div>
        </div>
      <?php else: ?>
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
      <?php endif; ?>
    </div>

    <!-- 2. SS Activities Panel (Module Level) -->
    <div id="panel-ssact" class="panel">
      <div class="panel-note">
        Soft skill activities scheduled between this module's planned start date (<b><?= s($p_start) ?></b>) and planned end date (<b><?= s($p_end) ?></b>).
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
            <?php if (empty($ss_module_activities)): ?>
              <tr><td colspan="4" class="muted" style="text-align:center; padding:24px;">No soft skill activities scheduled during this module's duration (<?= s($p_start) ?> – <?= s($p_end) ?>).</td></tr>
            <?php else: ?>
              <?php foreach ($ss_module_activities as $r): ?>
                <tr>
                  <td><span class="val"><?= s($r['activity']) ?></span></td>
                  <td class="date"><?= s($r['planned']) ?></td>
                  <td class="date"><?= format_cell_muted($r['actual']) ?></td>
                  <td><span class="st st-<?= s($r['status']) ?>"><?= s($r['label']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
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
              <th class="sortable ba-performance-student-head" data-sort="student" title="Sort by Student Name">Student</th>
              <th class="sortable ba-performance-overall-head" data-sort="grade" title="Sort by Grade">Grade</th>
              <?php foreach ($performance_columns as $column): ?>
                <th class="sortable ba-performance-group-module" data-sort="category:<?= s($column['key']) ?>" title="Sort by <?= s($column['label']) ?>"><?= s($column['label']) ?></th>
              <?php endforeach; ?>
              <?php foreach ($performance_custom_groups as $group): ?>
                <?php foreach ($group['columns'] as $column): ?>
                  <th class="ba-performance-custom-col ba-performance-group-<?= s($group['key']) ?>" data-custom-key="<?= s($column['key']) ?>"><?= s($column['label']) ?></th>
                <?php endforeach; ?>
              <?php endforeach; ?>
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

  </div> <!-- /.shell -->

</div> <!-- /.local-batchanalytics-wrap -->

<?php
echo $OUTPUT->footer();
