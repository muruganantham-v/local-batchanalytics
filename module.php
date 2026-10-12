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
if (!has_capability('local/batchanalytics:view', $context) && !has_capability('block/batchanalytics:view', $context)) {
    if (!\local_batchanalytics\task_service::can_view_dashboard($USER->id)) {
        require_capability('local/batchanalytics:view', $context);
    }
}

global $DB, $PAGE, $OUTPUT, $USER;

$batchid     = optional_param('batchid', 0, PARAM_INT);
$sectionid   = optional_param('sectionid', 0, PARAM_INT);
$module_idx  = optional_param('module', 0, PARAM_INT);
$courseid    = optional_param('courseid', 0, PARAM_INT);
$action      = optional_param('action', '', PARAM_ALPHANUMEXT);

if ($batchid <= 0 && $sectionid > 0) {
    $batchid = $sectionid;
} else if ($sectionid <= 0 && $batchid > 0) {
    $sectionid = $batchid;
}
$requested_batchid = $batchid > 0 ? $batchid : $sectionid;

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
            if ($completed) {
                $pending_check = \local_batchanalytics\activity_tracker_service::check_pending_submissions($cid, $cmid);
                if ($pending_check['has_pending']) {
                    http_response_code(400);
                    echo json_encode([
                        'status'  => 'error',
                        'error'   => $pending_check['message'],
                        'details' => $pending_check['details']
                    ]);
                    die();
                }
            }

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

if ($action === 'save_mentor_activity' || $action === 'savementoractivity') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();
        $cid = optional_param('courseid', 0, PARAM_INT);
        if ($cid <= 0 && $courseid > 0) {
            $cid = $courseid;
        }
        $actname = optional_param('activityname', '', PARAM_RAW_TRIMMED);
        $actkey = optional_param('activitykey', '', PARAM_RAW_TRIMMED);
        $target_name = !empty($actname) ? $actname : $actkey;
        $completed = optional_param('completed', 0, PARAM_BOOL);
        $completiondate = optional_param('completiondate', '', PARAM_RAW_TRIMMED);
        $planned_ts = optional_param('plannedts', 0, PARAM_INT);
        $sectionid = optional_param('sectionid', 0, PARAM_INT);
        if ($sectionid <= 0 && $batchid > 0) {
            $sectionid = $batchid;
        }

        if ($cid > 0 && !empty($target_name)) {
            if ($completed) {
                $pending_check = \local_batchanalytics\activity_tracker_service::check_pending_submissions($cid, 0, $target_name, $sectionid);
                if ($pending_check['has_pending']) {
                    http_response_code(400);
                    echo json_encode([
                        'status'  => 'error',
                        'error'   => $pending_check['message'],
                        'details' => $pending_check['details']
                    ]);
                    die();
                }
            }

            $saved = \local_batchanalytics\mentor_activity_service::save_activity_status(
                $cid,
                $target_name,
                (bool)$completed,
                $completiondate,
                $USER->id,
                $sectionid
            );
            $action_info = \local_batchanalytics\mentor_activity_service::compute_action_status((bool)$completed, $planned_ts);
            $username = fullname($USER);
            echo json_encode([
                'status'         => 'ok',
                'data'           => $saved,
                'modifiedbyname' => $username,
                'action_label'   => $action_info['label'],
                'action_class'   => $action_info['class'],
                'action_status'  => $action_info['status'],
                'message'        => get_string('mentor_activities_saved', 'local_batchanalytics')
            ]);
            die();
        }
        throw new \moodle_exception('invalidcourseid');
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

// Priority 1: If requested section / batch ID was provided (e.g. from batch.php or previous module links),
// strictly preserve the parent batch/section the user came from.
if ($has_bm_section && $requested_batchid > 0) {
    $section = $DB->get_record('local_bm_classsection', ['id' => $requested_batchid]);
    if (!$section) {
        $section = $DB->get_record('local_bm_classsection', ['batchid' => $requested_batchid]);
    }
    if ($section) {
        $batchid = (int)$section->id;
    }
}

// If section is found from the requested parent batch:
// Determine the appropriate module index within this section.
if ($section && !empty($section->moduledata)) {
    $sec_raw_modules = \local_batchanalytics\util::decode_module_data($section->moduledata, false);

    // If caller specified an explicit module index ($module_idx > 0), respect it.
    // Otherwise, if a courseid was provided, find the module within this parent batch matching that courseid.
    if ($module_idx <= 0 && $courseid > 0) {
        foreach ($sec_raw_modules as $sidx => $minfo) {
            if (isset($minfo['moodlecourseid']) && (int)$minfo['moodlecourseid'] === $courseid) {
                $module_idx = (int)($minfo['module'] ?? ($sidx + 1));
                break;
            }
        }
    }
}

// Priority 2: If no section was found via requested batch/section id, but a courseid is provided,
// search across class sections to find the section and module mapping.
if (!$section && $courseid > 0 && $has_bm_section) {
    $all_sections = $DB->get_records('local_bm_classsection', null, 'id ASC');
    foreach ($all_sections as $sec) {
        if (!empty($sec->moduledata)) {
            $mdata_list = \local_batchanalytics\util::decode_module_data($sec->moduledata, false);
            foreach ($mdata_list as $sidx => $m_info) {
                if (isset($m_info['moodlecourseid']) && (int)$m_info['moodlecourseid'] === $courseid) {
                    $section = $sec;
                    $batchid = (int)$sec->id;
                    if ($module_idx <= 0) {
                        $module_idx = (int)($m_info['module'] ?? ($sidx + 1));
                    }
                    break 2;
                }
            }
        }
    }
}

// Priority 3: Fallback if still no section is identified: pick the first available classsection record
if (!$section && $has_bm_section) {
    $sections = $DB->get_records('local_bm_classsection', null, 'id ASC', '*', 0, 1);
    if (!empty($sections)) {
        $section = reset($sections);
        $batchid = (int)$section->id;
    }
}

if ($module_idx <= 0) {
    $module_idx = 1;
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
    $batchname = \local_batchanalytics\util::clean_section_name($section->name ?: ($batch ? $batch->name : 'Batch ' . $section->id));
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
        $pmname = !empty($section->pmmanagername) ? $section->pmmanagername : '—';
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
        $ssename = !empty($section->maacexecutivename) ? $section->maacexecutivename : '—';
    }

    $raw_modules = [];
    if (!empty($section->moduledata)) {
        $raw_modules = \local_batchanalytics\util::decode_module_data($section->moduledata, true);
        if ($is_online) {
            $raw_modules = \local_batchanalytics\util::filter_modules_for_mode($raw_modules, $deliverymode);
        }
    }
} else {
    $batchname = $id > 0 ? ('Batch ' . $id) : 'Batch';
    $pmname = '—';
    $pmuser = null;
    $ssename = '—';
    $sseuser = null;
    $raw_modules = [];
}

$is_manager = \local_batchanalytics\util::can_view_all_batches($context, $USER->id);

if ($section && !$is_manager) {
    $assignment = \local_batchanalytics\util::get_section_user_assignment($section, $USER);
    if (!$assignment['is_assigned']) {
        redirect(
            new \moodle_url('/local/batchanalytics/index.php'),
            get_string('nopermissiontoviewbatch', 'local_batchanalytics'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    // If user is a mentor (not PM, not SSE), ensure they only access a module assigned to them.
    if (!$assignment['is_pm'] && !$assignment['is_sse']) {
        $cur_mod_check = $raw_modules[$module_idx - 1] ?? null;
        if (!$cur_mod_check || !\local_batchanalytics\util::is_mentor_assigned_to_module($cur_mod_check, $USER)) {
            // Find first module assigned to this mentor in this batch
            foreach ($raw_modules as $mod_candidate) {
                if (\local_batchanalytics\util::is_mentor_assigned_to_module($mod_candidate, $USER)) {
                    $module_idx = (int)($mod_candidate['module'] ?? 1);
                    break;
                }
            }
        }
    }
}

$total_modules = count($raw_modules);
if ($total_modules === 0) {
    $module_idx = 0;
    $cur_mod = [];
    $mod_name = '—';
    $planned_days = 0;
} else {
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
}

if (!empty($cur_mod['moodlecourseid'])) {
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

if (!$is_manager && $section) {
    $assignment = \local_batchanalytics\util::get_section_user_assignment($section, $USER);
    if (!$assignment['is_pm'] && !$assignment['is_sse']) {
        // Mentor view: find previous and next among assigned modules only
        $assigned_indices = [];
        foreach ($raw_modules as $rm) {
            if (\local_batchanalytics\util::is_mentor_assigned_to_module($rm, $USER)) {
                $assigned_indices[] = (int)($rm['module'] ?? 1);
            }
        }
        $prev_idx = null;
        $next_idx = null;
        $curr_pos = array_search($module_idx, $assigned_indices);
        if ($curr_pos !== false) {
            if ($curr_pos > 0) {
                $prev_idx = $assigned_indices[$curr_pos - 1];
            }
            if ($curr_pos < count($assigned_indices) - 1) {
                $next_idx = $assigned_indices[$curr_pos + 1];
            }
        }
    }
}

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
$spot_items_tbl = $DB->get_manager()->table_exists('nominations_sa_items') ? 'nominations_sa_items' : ($DB->get_manager()->table_exists('spotaward_nomination_items') ? 'spotaward_nomination_items' : '');
$spot_noms_tbl = $DB->get_manager()->table_exists('nominations_sa_nominations') ? 'nominations_sa_nominations' : ($DB->get_manager()->table_exists('spotaward_nominations') ? 'spotaward_nominations' : '');

if ($courseid > 0 && !empty($spot_items_tbl) && !empty($spot_noms_tbl)) {
    $sql = "SELECT sni.studentid, COUNT(sni.id) AS awardcount
              FROM {{$spot_items_tbl}} sni
              JOIN {{$spot_noms_tbl}} sn ON sn.id = sni.nominationid
             WHERE sn.courseid = :courseid
               AND sni.status = 'closed'
          GROUP BY sni.studentid";
    foreach ($DB->get_records_sql($sql, ['courseid' => $courseid]) as $record) {
        $spot_award_counts[(int)$record->studentid] = (int)$record->awardcount;
    }
}

$sec_student_uids = [];
if ($section && $has_bm_student) {
    $sec_student_uids = $DB->get_fieldset_select(
        'local_bm_student',
        'userid',
        'classsectionid = :secid',
        ['secid' => $section->id]
    );
    if (empty($sec_student_uids) && !empty($section->batchid)) {
        $sec_student_uids = $DB->get_fieldset_select(
            'local_bm_student',
            'userid',
            'batchid = :bid',
            ['bid' => $section->batchid]
        );
    }
    $sec_student_uids = array_values(array_filter(array_map('intval', $sec_student_uids)));
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
} else if (!empty($sec_student_uids)) {
    // Only if module has no mapped course ($courseid <= 0), fallback to section students.
    list($in_sec, $sec_params) = $DB->get_in_or_equal($sec_student_uids, SQL_PARAMS_NAMED, 'secstu');
    $enrolledstudents = $DB->get_records_select(
        'user',
        "id $in_sec AND deleted = 0",
        $sec_params,
        'firstname ASC, lastname ASC',
        'id, idnumber, username, firstname, lastname, email'
    );
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
        'report'     => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"></path>',
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
    } elseif (strpos($n, 'report') !== false) {
        $svg_path = $icons['report'];
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

if ($courseid > 0 && !empty($performance_columns) && !empty($students_data)) {
    $kpi_card_idx = 0;
    foreach ($performance_columns as $col) {
        if (!empty($col['isattendance']) || !empty($col['ismaac'])) {
            continue;
        }

        $colkey = $col['key'];
        $display_name = $col['label'];
        $cat_clean_name = preg_replace('/\s*\(\d+\)$/', '', $col['label']);
        $total_items = (int)($col['count'] ?? 0);

        $grades_list = [];
        $comp_list = [];
        $student_grades = [];

        foreach ($students_data as $s) {
            $cdata = $s['categories'][$colkey] ?? null;
            $g = ($cdata && $cdata['grade'] !== null && $cdata['grade'] !== '') ? (float)$cdata['grade'] : null;
            $comp = ($cdata && $cdata['completion'] !== null && $cdata['completion'] !== '') ? (float)$cdata['completion'] : 0.0;
            $completed_items = $total_items > 0 ? (int)round(($comp / 100) * $total_items) : 0;

            if ($g !== null) {
                $grades_list[] = $g;
            }
            $comp_list[] = $comp;

            $student_grades[] = [
                'userid' => (int)($s['userid'] ?? $s['id'] ?? 0),
                'fullname' => $s['name'] ?? $s['fullname'] ?? '',
                'username' => !empty($s['id']) ? $s['id'] : ($s['idnumber'] ?? $s['username'] ?? ''),
                'percentage' => $g,
                'completionRate' => $comp,
                'itemsCompleted' => $completed_items,
                'totalItems' => $total_items,
                'finalGrade' => $g,
                'totalEarned' => 0.0,
            ];
        }

        $avg_grade = !empty($grades_list) ? round(array_sum($grades_list) / count($grades_list), 2) : 0.0;
        $avg_comp = !empty($comp_list) ? round(array_sum($comp_list) / count($comp_list), 2) : 0.0;

        // Median Grade
        $sorted_grades = $grades_list;
        sort($sorted_grades);
        $cnt_g = count($sorted_grades);
        if ($cnt_g > 0) {
            $mid = (int)floor(($cnt_g - 1) / 2);
            $median_grade = ($cnt_g % 2 === 0)
                ? round(($sorted_grades[$mid] + $sorted_grades[$mid + 1]) / 2, 2)
                : round($sorted_grades[$mid], 2);
        } else {
            $median_grade = 0.0;
        }

        // Assign student category rank percentiles
        usort($student_grades, static function($a, $b) {
            $scA = $a['percentage'] !== null ? (float)$a['percentage'] : -1.0;
            $scB = $b['percentage'] !== null ? (float)$b['percentage'] : -1.0;
            return $scB <=> $scA;
        });
        $tot_s = count($student_grades);
        $prev_sc = null;
        $prev_p = null;
        foreach ($student_grades as $sidx => &$sg) {
            $sc = $sg['percentage'];
            if ($sc === null) {
                $p_val = 0;
            } else {
                $p_val = $tot_s <= 1 ? 100 : (int)round((($tot_s - $sidx - 1) / ($tot_s - 1)) * 100);
                if ($prev_sc !== null && $sc == $prev_sc) {
                    $p_val = $prev_p;
                }
            }
            $sg['percentile'] = $p_val;
            $prev_sc = $sc;
            $prev_p = $p_val;
        }
        unset($sg);

        $style = get_ba_category_icon_and_color($cat_clean_name, $kpi_card_idx);
        $kpi_card_idx++;

        $module_kpis[] = [
            'label' => $display_name,
            'clean_name' => $cat_clean_name,
            'val'   => number_format($avg_grade, 2) . '%',
            'sub'   => 'Avg Grade · Completion ' . number_format($avg_comp, 2) . '%',
            'avgGrade' => $avg_grade,
            'avgGradeFormatted' => number_format($avg_grade, 2),
            'medianGrade' => $median_grade,
            'medianGradeFormatted' => number_format($median_grade, 2),
            'avgCompletion' => $avg_comp,
            'avgCompFormatted' => number_format($avg_comp, 2),
            'avgFinalGrade' => $avg_grade,
            'avgFinalGradeFormatted' => number_format($avg_grade, 2),
            'compClass' => get_ba_comp_class((float)$avg_comp),
            'icon_bg' => $style['bg'],
            'icon_svg' => $style['icon'],
            'isAttendance' => false,
        ];

        $cat_data_record = [
            'categoryname' => $display_name,
            'totalItems' => $total_items,
            'avgGrade' => $avg_grade,
            'avgGradeFormatted' => number_format($avg_grade, 2),
            'medianGrade' => $median_grade,
            'medianGradeFormatted' => number_format($median_grade, 2),
            'avgCompletion' => $avg_comp,
            'avgCompFormatted' => number_format($avg_comp, 2),
            'avgFinalGrade' => $avg_grade,
            'avgFinalGradeFormatted' => number_format($avg_grade, 2),
            'isAttendance' => false,
            'studentGrades' => $student_grades,
        ];
        $kpi_categories_data[$display_name] = $cat_data_record;
        $kpi_categories_data[$cat_clean_name] = $cat_data_record;
    }

    if (!empty($module_kpis)) {
        // Overall Avg KPI Card
        $course_grades = [];
        $overall_comp_list = [];
        $overall_student_grades = [];
        $total_course_items = 0;

        foreach ($students_data as $s) {
            $cg = ($s['grade'] !== null && $s['grade'] !== '') ? (float)$s['grade'] : null;
            $comp = ($s['completion'] !== null && $s['completion'] !== '') ? (float)$s['completion'] : 0.0;
            $c_items = (int)($s['completed_items'] ?? 0);
            $t_items = (int)($s['total_items'] ?? 0);
            if ($t_items > $total_course_items) {
                $total_course_items = $t_items;
            }

            if ($cg !== null) {
                $course_grades[] = $cg;
            }
            $overall_comp_list[] = $comp;

            $overall_student_grades[] = [
                'userid' => (int)($s['userid'] ?? $s['id'] ?? 0),
                'fullname' => $s['name'] ?? $s['fullname'] ?? '',
                'username' => !empty($s['id']) ? $s['id'] : ($s['idnumber'] ?? $s['username'] ?? ''),
                'percentage' => $cg,
                'completionRate' => $comp,
                'itemsCompleted' => $c_items,
                'totalItems' => $t_items,
                'finalGrade' => $cg,
                'totalEarned' => 0.0,
            ];
        }

        $overall_avg = !empty($course_grades) ? round(array_sum($course_grades) / count($course_grades), 2) : 0.0;
        $overall_avg_formatted = number_format($overall_avg, 2);
        $overall_avg_comp = !empty($overall_comp_list) ? round(array_sum($overall_comp_list) / count($overall_comp_list), 2) : 0.0;
        $overall_avg_comp_formatted = number_format($overall_avg_comp, 2);

        // Overall Median
        $sorted_cg = $course_grades;
        sort($sorted_cg);
        $cnt_cg = count($sorted_cg);
        if ($cnt_cg > 0) {
            $mid = (int)floor(($cnt_cg - 1) / 2);
            $overall_median = ($cnt_cg % 2 === 0)
                ? round(($sorted_cg[$mid] + $sorted_cg[$mid + 1]) / 2, 2)
                : round($sorted_cg[$mid], 2);
        } else {
            $overall_median = 0.0;
        }

        // Overall rank percentiles
        usort($overall_student_grades, static function($a, $b) {
            $scA = $a['percentage'] !== null ? (float)$a['percentage'] : -1.0;
            $scB = $b['percentage'] !== null ? (float)$b['percentage'] : -1.0;
            return $scB <=> $scA;
        });
        $tot_os = count($overall_student_grades);
        $prev_osc = null;
        $prev_op = null;
        foreach ($overall_student_grades as $osidx => &$osg) {
            $sc = $osg['percentage'];
            if ($sc === null) {
                $p_val = 0;
            } else {
                $p_val = $tot_os <= 1 ? 100 : (int)round((($tot_os - $osidx - 1) / ($tot_os - 1)) * 100);
                if ($prev_osc !== null && $sc == $prev_osc) {
                    $p_val = $prev_op;
                }
            }
            $osg['percentile'] = $p_val;
            $prev_osc = $sc;
            $prev_op = $p_val;
        }
        unset($osg);

        $overall_cat_record = [
            'categoryname' => 'Overall Avg',
            'totalItems' => $total_course_items,
            'avgGrade' => $overall_avg,
            'avgGradeFormatted' => $overall_avg_formatted,
            'medianGrade' => $overall_median,
            'medianGradeFormatted' => number_format($overall_median, 2),
            'avgCompletion' => $overall_avg_comp,
            'avgCompFormatted' => $overall_avg_comp_formatted,
            'avgFinalGrade' => $overall_avg,
            'avgFinalGradeFormatted' => $overall_avg_formatted,
            'isAttendance' => false,
            'studentGrades' => $overall_student_grades,
        ];
        $kpi_categories_data['Overall Avg'] = $overall_cat_record;
        $kpi_categories_data['overall_avg'] = $overall_cat_record;

        $overall_kpi = [
            'label' => 'Overall Avg',
            'clean_name' => 'overall_avg',
            'val'   => $overall_avg_formatted . '%',
            'sub'   => 'Avg Grade · Completion ' . $overall_avg_comp_formatted . '%',
            'avgGrade' => $overall_avg,
            'avgGradeFormatted' => $overall_avg_formatted,
            'medianGrade' => $overall_median,
            'medianGradeFormatted' => number_format($overall_median, 2),
            'avgCompletion' => $overall_avg_comp,
            'avgCompFormatted' => $overall_avg_comp_formatted,
            'avgFinalGrade' => $overall_avg,
            'avgFinalGradeFormatted' => $overall_avg_formatted,
            'compClass' => get_ba_comp_class((float)$overall_avg_comp),
            'icon_bg' => '#eff6ff',
            'icon_svg' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="#2563eb"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L12 14.17l7.59-7.59L21 8l-9 9z"/></svg>',
            'isAttendance' => false,
            'isOverall' => true,
        ];
        array_unshift($module_kpis, $overall_kpi);
    }
}

// Calculate overall attendance percentage for this module course (displayed in schedule strip)
$enrolled_userids = array_map(function($s) { return (int)($s['userid'] ?? $s['id'] ?? 0); }, $students_data);
$course_attendance_pct = \local_batchanalytics\util::get_course_attendance_percentage($courseid, $enrolled_userids);

// Module Planned Date timestamps (used for both Mentor Activities due dates and SS Activities)
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

// -------------------------------------------------------------------------
// 5. Tab 1: Mentor Activities (Operational Mentor Activities)
// -------------------------------------------------------------------------
$mentor_activity_data = [
    'courseid' => $courseid,
    'group' => 'Default',
    'is_manual' => false,
    'activities' => []
];

if ($courseid > 0) {
    $sec_id = ($section && !empty($section->id)) ? (int)$section->id : ($batchid > 0 ? $batchid : 0);
    $mentor_activity_data = \local_batchanalytics\mentor_activity_service::get_course_mentor_activities($courseid, $mod_name, $mod_p_start_ts, $sec_id);
}

// -------------------------------------------------------------------------
// 6. Soft Skills Activities for this Module
// -------------------------------------------------------------------------
$all_ss_activities = [];
$ss_module_activities = [];

if ($section && !empty($section->softskillsdata)) {
    $all_ss_activities = \local_batchanalytics\util::decode_softskills_activities($section->softskillsdata);
    $range_start = $mod_p_start_ts > 0 ? strtotime('today midnight', $mod_p_start_ts) : 0;
    $range_end = $mod_p_end_ts > 0 ? (strtotime('today midnight', $mod_p_end_ts) + 86399) : 0;

    foreach ($all_ss_activities as &$act) {
        $is_in_module = ($range_start > 0 && $range_end > 0 && $act['planned'] > 0
            && $act['planned'] >= $range_start && $act['planned'] <= $range_end);
        $act['is_current_module'] = $is_in_module;
        if ($is_in_module) {
            $ss_module_activities[] = $act;
        }
    }
    unset($act);
}

// -------------------------------------------------------------------------
// 7. Page Setup & HTML Output
// -------------------------------------------------------------------------
$PAGE->set_context($context);
$page_params = [];
if ($batchid > 0) {
    $page_params['batchid'] = $batchid;
    $page_params['sectionid'] = $batchid;
}
if ($module_idx > 0) {
    $page_params['module'] = $module_idx;
}
if ($courseid > 0) {
    $page_params['courseid'] = $courseid;
}
if (!empty($deliverymode)) {
    $page_params['mode'] = $deliverymode;
}
$PAGE->set_url(new moodle_url('/local/batchanalytics/module.php', $page_params));
$PAGE->set_title($mod_name . ' – ' . $batchname . ' – Batch Analytics');
$PAGE->set_heading('');

$styleurl = new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime(__DIR__ . '/styles.css')]);
$dashboardstyleurl = new moodle_url('/local/batchanalytics/dashboard.css', ['v' => filemtime(__DIR__ . '/dashboard.css')]);
$scripturl = new moodle_url('/local/batchanalytics/module.js', ['v' => filemtime(__DIR__ . '/module.js')]);
$PAGE->requires->css($styleurl);
$PAGE->requires->css($dashboardstyleurl);
$PAGE->requires->js($scripturl);

echo $OUTPUT->header();

echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
echo '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">';

?>

<div class="local-batchanalytics-wrap ba-module-page" id="ba-module-detail-container"
     data-courseid="<?= (int)$courseid ?>"
     data-batchid="<?= (int)$batchid ?>"
     data-sectionid="<?= (int)$batchid ?>"
     data-module="<?= (int)$module_idx ?>"
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
      <a href="<?= s((new moodle_url('/local/batchanalytics/batch.php', array_filter(['id' => $batchid, 'batchid' => $batchid, 'mode' => $deliverymode])))->out(false)) ?>"><?= s($batchname) ?></a>
      <span style="color:#cbd5e1; margin:0 6px;">›</span>
      <?php if ($courseid > 0): ?>
        <a href="<?= s((new moodle_url('/course/view.php', ['id' => $courseid]))->out(false)) ?>" target="_blank" rel="noopener noreferrer" title="Open course" style="font-weight:700; color:inherit; text-decoration:none;"><b><?= s($mod_name) ?></b></a>
      <?php else: ?>
        <b><?= s($mod_name) ?></b>
      <?php endif; ?>
    </span>
  </div>

  <div class="shell">

  <!-- Module Header Card -->
  <div class="mhead">
    <div class="mtop">
      <div>
        <h1>
          <?php if ($courseid > 0): ?>
            <a href="<?= s((new moodle_url('/course/view.php', ['id' => $courseid]))->out(false)) ?>" class="ba-module-title-link" target="_blank" rel="noopener noreferrer" title="Open course: <?= s($mod_name) ?>">
              <?= s($mod_name) ?>
            </a>
          <?php else: ?>
            <?= s($mod_name) ?>
          <?php endif; ?>
        </h1>
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
              $prev_url = (new moodle_url('/local/batchanalytics/module.php', array_filter([
                  'batchid'   => $batchid > 0 ? $batchid : null,
                  'sectionid' => $batchid > 0 ? $batchid : null,
                  'module'    => $prev_idx,
                  'courseid'  => $prev_cid > 0 ? $prev_cid : null,
                  'mode'      => $deliverymode,
              ])))->out(false);
            ?>
            <a href="<?= s($prev_url) ?>">
              ‹ <?= s($prev_name) ?>
            </a>
          <?php else: ?>
            <button type="button" disabled>‹ —</button>
          <?php endif; ?>

          <?php if ($next_idx): ?>
            <?php
              $next_cid = !empty($raw_modules[$next_idx - 1]['moodlecourseid']) ? (int)$raw_modules[$next_idx - 1]['moodlecourseid'] : 0;
              $next_linked = $next_cid > 0 && $DB->record_exists('course', ['id' => $next_cid]);
              $next_url = (new moodle_url('/local/batchanalytics/module.php', array_filter([
                  'batchid'   => $batchid > 0 ? $batchid : null,
                  'sectionid' => $batchid > 0 ? $batchid : null,
                  'module'    => $next_idx,
                  'courseid'  => $next_cid > 0 ? $next_cid : null,
                  'mode'      => $deliverymode,
              ])))->out(false);
            ?>
            <a href="<?= s($next_url) ?>">
              <?= s($next_name) ?> ›
            </a>
          <?php else: ?>
            <button type="button" disabled>— ›</button>
          <?php endif; ?>
        </div>
      </div>

      <div style="display:flex; flex-direction:column; align-items:flex-end; gap:10px;">
        <div class="status-chip <?= s($status_chip_class) ?>"><?= s($status_chip_text) ?></div>
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
        <?= $a_end === '—' ? ($is_in_progress ? '— (in progress)' : '—') : s($a_end) ?>
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
      <div class="k">Attendance</div>
      <div class="v">
        <?php if ($course_attendance_pct !== null): ?>
          <span class="st st-<?= $course_attendance_pct >= 80 ? 'g' : ($course_attendance_pct >= 60 ? 'a' : 'r') ?>"><?= $course_attendance_pct ?>%</span>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Module KPIs (Gradebook category performance) -->
  <div class="ba-kpi-section-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
    <div class="sec-label" style="margin-bottom:0;">Module KPIs <span class="subx">· auto-pulled from LMS · common to mentors &amp; SS team</span></div>
    <div class="toggle ba-kpi-toggle" id="ba-kpi-toggle" style="margin:0;">
      <span class="on" id="ba-kpi-tg-grade" role="button" tabindex="0">Grade</span>
      <span id="ba-kpi-tg-pct" role="button" tabindex="0">Completion %</span>
    </div>
  </div>
  <?php
    $kpi_count = !empty($module_kpis) ? count($module_kpis) : 0;
    $desktop_cols = min(6, max(1, $kpi_count));
  ?>
  <div class="kpirow <?= $kpi_count === 1 ? 'kpirow-single' : '' ?>" id="kpirow" style="--kpi-desktop-cols: <?= (int)$desktop_cols ?>;">
    <?php if (empty($module_kpis)): ?>
      <div class="ba-kpi-empty-msg">No data available for this module.</div>
    <?php else: ?>
      <?php foreach ($module_kpis as $kpi): ?>
        <div class="kpi" data-category-modal="1" data-category-name="<?= s($kpi['label']) ?>"
             data-grade="<?= s($kpi['avgFinalGradeFormatted']) ?>%"
             data-percentile="<?= s($kpi['avgCompFormatted']) ?>%"
             data-is-overall="<?= !empty($kpi['isOverall']) ? '1' : '0' ?>"
             role="button" tabindex="0" title="Click to view student details for <?= s($kpi['label']) ?>" style="cursor:pointer;">
          <div class="kv"><?= s($kpi['avgFinalGradeFormatted']) ?>%</div>
          <div class="kl"><?= !empty($kpi['isOverall']) ? 'Avg Grade' : s($kpi['label']) ?></div>
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

    <!-- 1. Mentor Activities Panel (Operational Mentor Activities) -->
    <div id="panel-mentor" class="panel active">
      <div class="panel-note" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <!-- <div>
          Key operational activities performed by mentors for this module. Marking completed auto-fills today's date and saves instantly.
        </div>
        <div class="ba-mentor-group-badge" style="display:inline-flex; align-items:center; gap:6px; background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; padding:4px 10px; border-radius:14px; font-size:12px; font-weight:600;">
          <span style="opacity:0.8;">Group:</span>
          <span><?= s($mentor_activity_data['group']) ?></span>
          <span style="font-size:11px; font-weight:normal; opacity:0.75;"><?= $mentor_activity_data['is_manual'] ? '(Course Setting)' : '(Auto-matched)' ?></span>
        </div> -->
      </div>

      <?php if (empty($mentor_activity_data['activities'])): ?>
        <div class="tablecard">
          <div class="muted" style="text-align:center; padding:24px;"><?= get_string('mentor_activities_empty', 'local_batchanalytics') ?></div>
        </div>
      <?php else: ?>
      <div class="ba-tracker-wrap">
        <div class="ba-tracker-header-row" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
          <div style="font-size:13px; font-weight:600; color:#374151;">
            Checklist (<?= count($mentor_activity_data['activities']) ?> activities)
          </div>
          <div id="ba-mentor-status-msg" class="ba-tracker-status-msg"></div>
        </div>

        <div class="tablecard ba-mentor-tablecard ba-mentor-table-scroll">
          <table class="ba-mentor-table">
            <thead>
              <tr>
                <th>Activity Name</th>
                <th style="width:160px;">Planned Due Date</th>
                <th style="width:170px;">Completion Date</th>
                <th style="width:145px;">Action</th>
                <th style="width:170px;">Last Updated By</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($mentor_activity_data['activities'] as $act): ?>
                <tr data-planned-ts="<?= (int)$act['planned_ts'] ?>">
                  <td>
                    <span class="val" style="font-weight:600; color:#1f2937;"><?= s($act['name']) ?></span>
                  </td>
                  <td>
                    <?php if (!empty($act['planned_date_formatted']) && $act['planned_date_formatted'] !== '—'): ?>
                      <div style="display:flex; align-items:center; gap:6px;">
                        <span class="val" style="font-weight:600; font-size:13px; color:#374151;"><?= s($act['planned_date_formatted']) ?></span>
                        <span class="muted" style="font-size:11px;">(+<?= (int)$act['duedays'] ?>d)</span>
                      </div>
                    <?php else: ?>
                      <span class="muted">+<?= (int)$act['duedays'] ?> working days</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($act['completiondate_formatted']) && $act['completiondate_formatted'] !== '—'): ?>
                      <span class="val" style="font-weight:600; font-size:13px; color:#374151;"><?= s($act['completiondate_formatted']) ?></span>
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="ba-mentor-action-badge st <?= s($act['action_class']) ?>" data-actname="<?= s($act['name']) ?>">
                      <?= s($act['action_label']) ?>
                    </span>
                  </td>
                  <td>
                    <span class="ba-mentor-updatedby" data-actname="<?= s($act['name']) ?>" style="font-size:12px; color:#4b5563;">
                      <?= !empty($act['modifiedbyname']) ? s($act['modifiedbyname']) : '<span class="muted">—</span>' ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- 2. SS Activities Panel (Module Level) -->
    <div id="panel-ssact" class="panel">
      <div class="panel-note" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <div>
          Soft skills activities scheduled during this module's planned date range (<b><?= s($p_start) ?></b> – <b><?= s($p_end) ?></b>).
        </div>
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
              <tr><td colspan="4" class="muted" style="text-align:center; padding:24px;">No soft skill activities are scheduled within this module's planned date range (<?= s($p_start) ?> – <?= s($p_end) ?>).</td></tr>
            <?php else: ?>
              <?php foreach ($ss_module_activities as $r): ?>
                <tr>
                  <td><span class="val"><?= s($r['activity']) ?></span></td>
                  <td class="date"><?= s($r['p_date']) ?></td>
                  <td class="date"><?= format_cell_muted($r['a_date']) ?></td>
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
        Module-level student performance — same view as batch level, scoped to this module. Grade or Completion % banding.
      </div>

      <div class="filterbar">
        <div class="toggle">
          <span class="on" id="ba-mod-tg-grade">Grade</span>
          <span id="ba-mod-tg-pct">Completion %</span>
        </div>
        <button type="button" class="exp" id="ba-mod-export-btn">Export Filtered</button>

      </div>

      <!-- Dynamic Banding Cards -->
      <div class="bandrow" id="ba-mod-bandrow"></div>

      <!-- Performance Data Table -->
      <div class="tablecard ba-performance-tablecard">
        <div class="ba-performance-table-scroll">
          <table>
            <thead>
              <tr>
                <th class="sortable" data-sort="band" title="Sort by Band" style="width:50px;">Band</th>
                <th class="sortable ba-performance-student-head" data-sort="student" title="Sort by Student Name">Student</th>
                <th class="sortable ba-performance-overall-head" data-sort="grade" title="Sort by Grade">Grade</th>
                <th class="sortable" data-sort="attendance" title="Sort by Attendance">Attendance</th>
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
        </div>

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
