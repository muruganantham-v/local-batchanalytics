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


global $DB, $PAGE, $OUTPUT, $USER;

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/batch.php'));

if (!has_capability('local/batchanalytics:view', $context)) {
    if (!\local_batchanalytics\task_service::can_view_dashboard($USER->id)) {
        require_capability('local/batchanalytics:view', $context);
    }
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action === 'addnote') {
    require_sesskey();

    header('Content-Type: application/json; charset=utf-8');
    try {
        $batchid = required_param('batchid', PARAM_INT);
        $note = required_param('note', PARAM_RAW);

        global $DB;
        $sec = null;
        if ($DB->get_manager()->table_exists('local_bm_classsection')) {
            $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
            if (!$sec) {
                $secs = $DB->get_records('local_bm_classsection', ['batchid' => $batchid], 'id ASC', '*', 0, 1);
                if (!empty($secs)) {
                    $sec = reset($secs);
                }
            }
        }
        if (!$sec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Batch not found']);
            exit;
        }

        // Verify capability: site admins, managers, SS Leads on any batch, or PM on own batch.
        $can_manage_all = is_siteadmin()
            || has_capability('moodle/site:config', $context)
            || has_capability('local/batchanalytics:manage', $context);
        $is_sslead = \local_batchanalytics\util::is_user_sslead($USER->id);
        $sec_assignment = \local_batchanalytics\util::get_section_user_assignment($sec, $USER->id, true);
        $is_assigned_pm = !empty($sec_assignment['is_pm']);

        if (!$can_manage_all) {
            require_capability('local/batchanalytics:editreviewnotes', $context);
        }

        // Enforce batch assignment scoping for review notes (PM on assigned batch, SS Lead on any batch).
        \local_batchanalytics\task_service::require_section_permission((int)$USER->id, $sec, 'addnote');

        // Strictly use the authenticated logged-in user
        $author = fullname($USER);
        $target_batchid = !empty($sec->id) ? (int)$sec->id : $batchid;

        $saved = \local_batchanalytics\batch_notes_service::add_note($target_batchid, (int)$USER->id, $author, $note);
        echo json_encode(['success' => true, 'note' => $saved]);
    } catch (\moodle_exception $e) {
        $is_permission = ($e instanceof \required_capability_exception)
            || ($e->errorcode === 'nopermissions')
            || (strpos($e->errorcode, 'nopermission') !== false);
        http_response_code($is_permission ? 403 : 400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'editnote') {
    require_sesskey();

    header('Content-Type: application/json; charset=utf-8');
    try {
        $batchid = required_param('batchid', PARAM_INT);
        $noteid = required_param('noteid', PARAM_INT);
        $note = required_param('note', PARAM_RAW);

        global $DB;

        // 1. Verify note exists
        $existing = $DB->get_record(\local_batchanalytics\batch_notes_service::TABLE, ['id' => $noteid]);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Note not found']);
            exit;
        }

        // 2. Resolve target batch section
        $sec = null;
        if ($DB->get_manager()->table_exists('local_bm_classsection')) {
            $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
            if (!$sec) {
                $secs = $DB->get_records('local_bm_classsection', ['batchid' => $batchid], 'id ASC', '*', 0, 1);
                if (!empty($secs)) {
                    $sec = reset($secs);
                }
            }
        }
        if (!$sec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Batch not found']);
            exit;
        }

        // 3. Strict Batch Ownership: Ensure the note belongs to this batch
        $matches_batch = ((int)$existing->batchid === (int)$batchid)
            || ((int)$existing->batchid === (int)$sec->id)
            || (!empty($sec->batchid) && (int)$existing->batchid === (int)$sec->batchid);

        if (!$matches_batch) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Note does not belong to the authorized batch']);
            exit;
        }

        // 4. Verify capability & scoping: assigned PM on own batch, SS Lead on any batch, or site admin/manager.
        $can_manage_all = is_siteadmin()
            || has_capability('moodle/site:config', $context)
            || has_capability('local/batchanalytics:manage', $context);
        $is_sslead = \local_batchanalytics\util::is_user_sslead($USER->id);
        $sec_assignment = \local_batchanalytics\util::get_section_user_assignment($sec, $USER->id, true);
        $is_assigned_pm = !empty($sec_assignment['is_pm']);

        if (!$can_manage_all) {
            require_capability('local/batchanalytics:editreviewnotes', $context);
        }

        \local_batchanalytics\task_service::require_section_permission((int)$USER->id, $sec, 'editnote');

        // 5. Latest-note check: Regular users (PM / SS Lead) can only edit the latest note.
        // Site administrators and full managers can edit any note.
        if (!$can_manage_all) {
            $is_latest = \local_batchanalytics\batch_notes_service::is_latest_note((int)$existing->batchid, $noteid);
            if (!$is_latest) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => get_string('only_latest_note_editable', 'local_batchanalytics')]);
                exit;
            }

            if ((int)$existing->userid !== (int)$USER->id) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You can only edit your own notes']);
                exit;
            }
        }

        $saved = \local_batchanalytics\batch_notes_service::update_note($noteid, $note, (int)$USER->id, fullname($USER));
        echo json_encode(['success' => true, 'note' => $saved]);
    } catch (\moodle_exception $e) {
        $is_permission = ($e instanceof \required_capability_exception)
            || ($e->errorcode === 'nopermissions')
            || (strpos($e->errorcode, 'nopermission') !== false);
        http_response_code($is_permission ? 403 : 400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_note_history') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_sesskey();
        $batchid = required_param('batchid', PARAM_INT);
        $noteid  = required_param('noteid', PARAM_INT);

        global $DB;

        // 1. Verify note exists
        $existing = $DB->get_record(\local_batchanalytics\batch_notes_service::TABLE, ['id' => $noteid]);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Note not found']);
            exit;
        }

        // 2. Resolve target batch section
        $sec = null;
        if ($DB->get_manager()->table_exists('local_bm_classsection')) {
            $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
            if (!$sec) {
                $secs = $DB->get_records('local_bm_classsection', ['batchid' => $batchid], 'id ASC', '*', 0, 1);
                if (!empty($secs)) {
                    $sec = reset($secs);
                }
            }
        }
        if (!$sec) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Batch not found']);
            exit;
        }

        // 3. Strict Batch Ownership: Ensure the note belongs to this batch
        $matches_batch = ((int)$existing->batchid === (int)$batchid)
            || ((int)$existing->batchid === (int)$sec->id)
            || (!empty($sec->batchid) && (int)$existing->batchid === (int)$sec->batchid);

        if (!$matches_batch) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Note does not belong to authorized batch']);
            exit;
        }

        // 4. View permission check
        $can_view_notes = has_capability('local/batchanalytics:viewreviewnotes', $context)
            || has_capability('local/batchanalytics:editreviewnotes', $context)
            || \local_batchanalytics\util::can_view_all_batches($context, $USER->id)
            || \local_batchanalytics\util::is_user_sslead($USER->id);

        if (!$can_view_notes) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No permission to view note history']);
            exit;
        }

        $history = \local_batchanalytics\batch_notes_service::get_note_history($noteid);
        echo json_encode(['success' => true, 'history' => $history]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$id = optional_param('id', optional_param('batchid', 0, PARAM_INT), PARAM_INT);

$is_manager = \local_batchanalytics\util::can_view_all_batches($context, $USER->id);

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

if ($has_bm_section && $id > 0) {
    $section = $DB->get_record('local_bm_classsection', ['id' => $id]);
    if (!$section) {
        $candidate_secs = $DB->get_records('local_bm_classsection', ['batchid' => $id], 'id ASC');
        if (!empty($candidate_secs)) {
            if (!$is_manager) {
                foreach ($candidate_secs as $sec_cand) {
                    $assignment = \local_batchanalytics\util::get_section_user_assignment($sec_cand, $USER);
                    if ($assignment['is_assigned']) {
                        $section = $sec_cand;
                        break;
                    }
                }
            }
            if (!$section) {
                $section = reset($candidate_secs);
            }
        }
    }
}

// Fallback: If requested ID is not found or ID is 0, pick the first available classsection record
if (!$section && $has_bm_section) {
    if ($is_manager) {
        $sections = $DB->get_records('local_bm_classsection', null, 'id ASC', '*', 0, 1);
        if (!empty($sections)) {
            $section = reset($sections);
        }
    } else {
        $all_secs = $DB->get_records('local_bm_classsection', null, 'id ASC');
        foreach ($all_secs as $sec_candidate) {
            $assignment = \local_batchanalytics\util::get_section_user_assignment($sec_candidate, $USER);
            if ($assignment['is_assigned']) {
                $section = $sec_candidate;
                break;
            }
        }
    }
}
if ($section) {
    $id = (int)$section->id;
}

if (!$is_manager) {
    if (!$section) {
        redirect(
            new \moodle_url('/local/batchanalytics/index.php'),
            get_string('nopermissiontoviewbatch', 'local_batchanalytics'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $assignment = \local_batchanalytics\util::get_section_user_assignment($section, $USER);
    if (!$assignment['is_assigned']) {
        redirect(
            new \moodle_url('/local/batchanalytics/index.php'),
            get_string('nopermissiontoviewbatch', 'local_batchanalytics'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

if ($section && $has_bm_batch && !empty($section->batchid)) {
    $batch = $DB->get_record('local_bm_batch', ['id' => $section->batchid]);
}

$batch_id = $section ? (int)$section->id : ($id > 0 ? (int)$id : 1);

// Extract & normalize batch header fields
if ($section) {
    $batchid_label  = \local_batchanalytics\util::clean_section_name($section->name ?: ($batch ? $batch->name : 'Batch ' . $section->id));
    $coursename     = ($batch && !empty($batch->coursename)) ? $batch->coursename : (!empty($section->coursename) ? $section->coursename : '—');
    $deliverymode   = ($batch && !empty($batch->deliverymode)) ? $batch->deliverymode : (!empty($section->deliverymode) ? $section->deliverymode : 'Offline');
    $submode        = ($batch && !empty($batch->submode)) ? $batch->submode : (!empty($section->submode) ? $section->submode : 'Regular');
    $startdate_ts   = ($batch && !empty($batch->startdate)) ? $batch->startdate : ($section->timecreated ?: 0);
    $startdate_str  = $startdate_ts > 0 ? userdate($startdate_ts, '%d %b %Y') : '—';

    // Program Manager resolution: prioritize resolving by userid
    $pmuser = null;
    $pmname = '';
    if (!empty($section->pmmanager) && is_numeric($section->pmmanager) && (int)$section->pmmanager > 0) {
        $pmprofile = \local_batchanalytics\util::get_user_profile_data((int)$section->pmmanager, 1);
        if (!empty($pmprofile['user'])) {
            $pmuser = $pmprofile['user'];
            $pmname = $pmprofile['name'];
        }
    }
    if (empty($pmname) && !empty($section->pmmanagername)) {
        $cleaned_pm = trim((string)$section->pmmanagername);
        if ($cleaned_pm !== '—' && $cleaned_pm !== '-' && $cleaned_pm !== '0' && strcasecmp($cleaned_pm, 'null') !== 0) {
            $pmname = $cleaned_pm;
        }
    }
    if (empty($pmname) || $pmname === '—' || $pmname === '-' || strcasecmp($pmname, 'Not Assigned') === 0) {
        $pmname = get_string('not_assigned', 'local_batchanalytics');
        $pmuser = null;
    }

    // MAAC Executive (SSE) resolution: prioritize resolving by userid
    $sseuser = null;
    $ssename = '';
    if (!empty($section->maacexecutive) && is_numeric($section->maacexecutive) && (int)$section->maacexecutive > 0) {
        $sseprofile = \local_batchanalytics\util::get_user_profile_data((int)$section->maacexecutive, 1);
        if (!empty($sseprofile['user'])) {
            $sseuser = $sseprofile['user'];
            $ssename = $sseprofile['name'];
        }
    }
    if (empty($ssename) && !empty($section->maacexecutivename)) {
        $cleaned_sse = trim((string)$section->maacexecutivename);
        if ($cleaned_sse !== '—' && $cleaned_sse !== '-' && $cleaned_sse !== '0' && strcasecmp($cleaned_sse, 'null') !== 0) {
            $ssename = $cleaned_sse;
        }
    }
    if (empty($ssename) || $ssename === '—' || $ssename === '-' || strcasecmp($ssename, 'Not Assigned') === 0) {
        $ssename = get_string('not_assigned', 'local_batchanalytics');
        $sseuser = null;
    }

    $mode_param = optional_param('mode', '', PARAM_TEXT);
    if (!empty($mode_param)) {
        $deliverymode = $mode_param;
    }

    $is_online = \local_batchanalytics\util::is_online_mode($deliverymode);

    // Module tracking decoding
    $raw_modules = [];
    if (!empty($section->moduledata)) {
        $raw_modules = \local_batchanalytics\util::decode_module_data($section->moduledata, true);
        if ($is_online) {
            $raw_modules = \local_batchanalytics\util::filter_modules_for_mode($raw_modules, $deliverymode);
        }
        // For mentor: only display the modules which are assigned to them!
        $raw_modules = \local_batchanalytics\util::filter_modules_for_user($raw_modules, $section, $USER, $is_manager);
    }
} else {
    $batchid_label  = $id > 0 ? ('Batch ' . $id) : 'Batch';
    $coursename     = '—';
    $deliverymode   = 'Offline';
    $submode        = '—';
    $startdate_str  = '—';
    $pmname         = get_string('not_assigned', 'local_batchanalytics');
    $pmuser         = null;
    $ssename        = get_string('not_assigned', 'local_batchanalytics');
    $sseuser        = null;
    $raw_modules    = [];
    $is_online      = false;
}

// -------------------------------------------------------------------------
// 2. Build Schedule Rows
// -------------------------------------------------------------------------
$schedule_rows = [];

$canonical_modules = \local_batchanalytics\util::get_canonical_modules($deliverymode);

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
        $course_id = !empty($mod['moodlecourseid']) ? (int)$mod['moodlecourseid'] : (!empty($mod['courseid']) ? (int)$mod['courseid'] : 0);
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
        if ($is_online && \local_batchanalytics\util::is_qt_module($m_name)) {
            continue; // Qt / QML must be excluded entirely for online batches (SR-3.2.1)
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

        $delta = null;
        $mod_pdays = !empty($mod['planneddays']) && is_numeric($mod['planneddays']) ? (int)$mod['planneddays'] : 0;
        $mod_adays = !empty($mod['actualdays']) && is_numeric($mod['actualdays']) ? (int)$mod['actualdays'] : 0;
        if ($mod_pdays > 0 && $mod_adays > 0) {
            $delta = $mod_adays - $mod_pdays;
        } else if (!empty($mod['actualend']) && !empty($mod['plannedend']) && is_numeric($mod['actualend']) && is_numeric($mod['plannedend']) && (int)$mod['actualend'] > 100000 && (int)$mod['plannedend'] > 100000) {
            $delta = (int)round(((int)$mod['actualend'] - (int)$mod['plannedend']) / 86400);
        } else if (isset($mod['scheduledelta']) && is_numeric($mod['scheduledelta'])) {
            $delta = (int)$mod['scheduledelta'];
        }

        if ($a_start !== '—' && $a_end === '—') {
            $delay_code = 'prog';
        } else if ($delta !== null && ($p_start !== '—' || $a_start !== '—')) {
            $delay_code = $delta;
        } else {
            $delay_code = null;
        }

        $schedule_rows[] = [
            'name'          => $m_name,
            'mod_idx'       => $mod_idx,
            'class_mentor'  => $class_mentor,
            'class_mentors' => $class_mentors,
            'lab_mentor'    => $lab_mentor,
            'lab_mentors'   => $lab_mentors,
            'p_start'       => $p_start,
            'p_end'         => $p_end,
            'a_start'       => $a_start,
            'a_end'         => $a_end,
            'delay'         => $delay_code,
            'scheduledelta' => $delta,
            'courseid'      => $course_id,
            'days'          => !empty($mod['planneddays']) ? (int)$mod['planneddays'] : \local_batchanalytics\util::get_module_total_days($m_name, 10),
        ];
    }
} else {
    $schedule_rows = [];
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
    $cur_module = !empty($schedule_rows[0]['name']) ? $schedule_rows[0]['name'] : '—';
}

$tentative_end = '—';
if (!empty($schedule_rows)) {
    $last_sr = end($schedule_rows);
    if (!empty($last_sr['p_end']) && $last_sr['p_end'] !== '—') {
        $tentative_end = $last_sr['p_end'];
    }
}

// -------------------------------------------------------------------------
// 3. Batch-Level SS Activities (dynamically from local_bm_classsection softskillsdata)
// -------------------------------------------------------------------------
$ss_activities = [];
if ($section && !empty($section->softskillsdata)) {
    $ss_activities = \local_batchanalytics\util::decode_softskills_activities($section->softskillsdata);
}


// -------------------------------------------------------------------------
$students_data = [];

$all_batch_modules = (!empty($section) && !empty($section->moduledata))
    ? \local_batchanalytics\util::decode_module_data($section->moduledata, true)
    : $raw_modules;

$all_modules_list = !empty($raw_modules) ? array_merge($all_batch_modules, $raw_modules) : $all_batch_modules;
$performance_courseids = [];
foreach ($all_modules_list as $bm) {
    $cid = !empty($bm['moodlecourseid']) ? (int)$bm['moodlecourseid'] : (!empty($bm['courseid']) ? (int)$bm['courseid'] : 0);
    if ($cid > 0) {
        $performance_courseids[] = $cid;
    }
}
$performance_courseids = array_values(array_unique($performance_courseids));
$spot_award_counts = [];
$spot_items_tbl = $DB->get_manager()->table_exists('nominations_sa_items') ? 'nominations_sa_items' : ($DB->get_manager()->table_exists('spotaward_nomination_items') ? 'spotaward_nomination_items' : '');
$spot_noms_tbl = $DB->get_manager()->table_exists('nominations_sa_nominations') ? 'nominations_sa_nominations' : ($DB->get_manager()->table_exists('spotaward_nominations') ? 'spotaward_nominations' : '');

if (!empty($performance_courseids) && !empty($spot_items_tbl) && !empty($spot_noms_tbl)) {
    list($incourses, $spotparams) = $DB->get_in_or_equal($performance_courseids, SQL_PARAMS_NAMED, 'spotcourse');
    $sql = "SELECT sni.studentid, COUNT(sni.id) AS awardcount
              FROM {{$spot_items_tbl}} sni
              JOIN {{$spot_noms_tbl}} sn ON sn.id = sni.nominationid
             WHERE sn.courseid $incourses
               AND sni.status = 'closed'
          GROUP BY sni.studentid";
    foreach ($DB->get_records_sql($sql, $spotparams) as $record) {
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

// Fetch and merge enrolled students across all linked courses in the batch.
$enrolledstudents = [];
if (!empty($performance_courseids)) {
    foreach ($performance_courseids as $enrolledcourseid) {
        $coursecontext = context_course::instance($enrolledcourseid, IGNORE_MISSING);
        if (!$coursecontext) {
            continue;
        }
        $course_students = get_enrolled_users($coursecontext, '', 0, 'u.id, u.idnumber, u.username, u.firstname, u.lastname, u.email');
        foreach ($course_students as $studentrecord) {
            $enrolledstudents[(int)$studentrecord->id] = $studentrecord;
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
// Reuse the Course-tab Advanced Filter Gradebook dataset across linked modules.
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
// Check review notes permissions
$has_edit_notes_cap = has_capability('local/batchanalytics:editreviewnotes', $context);

// Program Manager can add/edit notes on their assigned batch.
$is_assigned_pm = false;
if ($section) {
    $sec_assignment = \local_batchanalytics\util::get_section_user_assignment($section, $USER->id, true);
    $is_assigned_pm = !empty($sec_assignment['is_pm']);
}

// SS Lead can add notes and edit their notes for ANY batch.
$is_sslead = \local_batchanalytics\util::is_user_sslead($USER->id);

$can_manage_all_notes = is_siteadmin()
    || has_capability('moodle/site:config', $context)
    || has_capability('local/batchanalytics:manage', $context);

// Edit notes allowed for: assigned PM on this batch, SS Lead on any batch, or Admin/Manager (subject to editreviewnotes capability).
$can_edit_notes = $can_manage_all_notes || ($has_edit_notes_cap && ($is_assigned_pm || $is_sslead));

// View notes allowed for: anyone with view capability, assigned PM, SS Lead, or Admin/Manager.
$can_view_notes = has_capability('local/batchanalytics:viewreviewnotes', $context)
    || $can_edit_notes;

$display_notes = [];
$has_real_notes = false;
if ($can_view_notes) {
    $db_notes = \local_batchanalytics\batch_notes_service::get_notes($batch_id);
    if (!empty($db_notes)) {
        $display_notes = $db_notes;
        $has_real_notes = true;
    }
}

// Determine logged-in user's identity and role for posting review notes
$current_author_name = fullname($USER);
if ($can_manage_all_notes) {
    $current_author_role = 'Administrator';
} else if ($is_assigned_pm) {
    $current_author_role = 'Program Manager';
} else if ($is_sslead) {
    $current_author_role = 'SS Lead';
} else {
    $roles = get_user_roles($context, $USER->id, true);
    if (!empty($roles)) {
        $first_role = reset($roles);
        $current_author_role = role_get_name($first_role, $context);
    } else {
        $current_author_role = 'Reviewer';
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
    if ($sr['delay'] !== 'prog' && is_numeric($sr['delay'])) {
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

$styleurl = new moodle_url('/local/batchanalytics/styles.css');
$PAGE->requires->css($styleurl);
$dashboardstyleurl = new moodle_url('/local/batchanalytics/dashboard.css');
$PAGE->requires->css($dashboardstyleurl);
$PAGE->requires->js_call_amd('local_batchanalytics/batch', 'init');

// Use the same configured CRM field list and explicit capability check as index.php.
$crm_fields_config = \local_batchanalytics\crm_fields_helper::get_fields();
$batch_can_manage = is_siteadmin($USER->id)
    || has_capability('local/batchanalytics:manage', $context)
    || has_capability('local/batchanalytics:viewfullcrmdata', $context);
$batch_can_view_crm = $batch_can_manage
    || has_capability('local/batchanalytics:viewcrmdata', $context);
$crm_index_url = (new moodle_url('/local/batchanalytics/index.php'))->out(false);

echo $OUTPUT->header();

?>

<div class="local-batchanalytics-wrap ba-batch-page" id="ba-batch-detail-container"
  data-batchid="<?= (int)$batch_id ?>"
  data-sesskey="<?= sesskey() ?>"
  data-students="<?= s(json_encode($students_data)) ?>"
  data-performance-columns="<?= s(json_encode($performance_columns)) ?>"
  data-performance-custom-groups="<?= s(json_encode($performance_custom_groups)) ?>"
  data-crm-fields="<?= htmlspecialchars(json_encode($crm_fields_config), ENT_QUOTES) ?>"
  data-can-manage="<?= $batch_can_manage ? '1' : '0' ?>"
  data-can-manage-all="<?= !empty($can_manage_all_notes) ? '1' : '0' ?>"
  data-can-view-crm="<?= $batch_can_view_crm ? '1' : '0' ?>"
  data-crm-index-url="<?= s($crm_index_url) ?>"
  data-note-strings="<?= s(json_encode([
      'edit'        => get_string('btn_edit_note', 'local_batchanalytics'),
      'save'        => get_string('btn_save_note', 'local_batchanalytics'),
      'cancel'      => get_string('btn_cancel_note', 'local_batchanalytics'),
      'saving'      => get_string('saving_note', 'local_batchanalytics'),
      'latest'      => get_string('latest_note', 'local_batchanalytics'),
      'edited'      => get_string('edited', 'local_batchanalytics'),
      'viewHistory' => get_string('view_note_history', 'local_batchanalytics'),
      'historyTitle'=> get_string('note_history_title', 'local_batchanalytics'),
      'verOriginal' => get_string('version_original', 'local_batchanalytics'),
      'close'       => get_string('close', 'local_batchanalytics'),
  ])) ?>">

  <!-- Breadcrumb Bar -->
  <div class="crumbbar">
    <span class="crumb">
      <a href="<?= s((new moodle_url('/local/batchanalytics/index.php'))->out(false)) ?>">Home</a>
      <span class="crumb-sep">›</span>
      <b><?= s($batchid_label) ?></b>
    </span>
  </div>


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
            <div class="role"><?= s(get_string('role_program_manager', 'local_batchanalytics')) ?></div>
            <div class="pwrap">
              <div class="pav">
                <?= \local_batchanalytics\util::render_user_avatar($pmuser, 38, 'pav-avatar', $pmname) ?>
              </div>
              <span class="name"><?= s($pmname) ?></span>
            </div>
          </div>
          <div class="person">
            <div class="role"><?= s(get_string('role_ss_executive', 'local_batchanalytics')) ?></div>
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
        <button type="button" class="tab active" data-tab="sched"><?= s(get_string('tab_schedule', 'local_batchanalytics')) ?></button>
        <button type="button" class="tab" data-tab="ss"><?= s(get_string('tab_ss_activities', 'local_batchanalytics')) ?></button>
        <button type="button" class="tab" data-tab="students"><?= s(get_string('tab_student_performance', 'local_batchanalytics')) ?></button>
        <?php if ($can_view_notes || $can_edit_notes): ?>
          <button type="button" class="tab" data-tab="notes"><?= s(get_string('tab_review_notes', 'local_batchanalytics')) ?></button>
        <?php endif; ?>
        <?php if ($batch_can_view_crm): ?>
          <button type="button" class="tab" data-tab="crm" id="ba-crm-tab-btn"><?= s(get_string('tab_crm_data', 'local_batchanalytics')) ?></button>
        <?php endif; ?>
      </div>

      <!-- Panels Container -->
      <div class="ba-batch-panels">

        <!-- 1. Schedule Panel -->
        <div id="panel-sched" class="panel active">
          <div class="panel-note">
            <?= get_string('sched_panel_note', 'local_batchanalytics') ?>
          </div>
          <div class="tablecard">
            <table>
              <thead>
                <tr>
                  <th><?= s(get_string('col_module', 'local_batchanalytics')) ?></th>
                  <th><?= s(get_string('col_class_mentor', 'local_batchanalytics')) ?></th>
                  <th><?= s(get_string('col_lab_mentor', 'local_batchanalytics')) ?></th>
                  <th><?= s(get_string('col_plan_start', 'local_batchanalytics')) ?></th>
                  <th><?= s(get_string('col_plan_end', 'local_batchanalytics')) ?></th>
                  <th><?= s(get_string('col_actual_start', 'local_batchanalytics')) ?></th>
                  <th><?= s(get_string('col_actual_end', 'local_batchanalytics')) ?></th>
                  <th><?= s(get_string('col_delay', 'local_batchanalytics')) ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($schedule_rows)): ?>
                  <tr><td colspan="9" class="muted ba-empty-cell"><?= s(get_string('no_modules_configured', 'local_batchanalytics')) ?></td></tr>
                <?php else: ?>
                  <?php foreach ($schedule_rows as $idx => $r): ?>
                    <?php
                      $has_link = ($r['delay'] !== null);
                      $mod_num = !empty($r['mod_idx']) ? (int)$r['mod_idx'] : ($idx + 1);
                      $course_linked = (!empty($r['courseid']) && (int)$r['courseid'] > 0);
                      if ($course_linked) {
                          $course_linked = $DB->record_exists('course', ['id' => (int)$r['courseid']]);
                      }
                      $module_tracker_params = array_filter([
                          'courseid'  => $course_linked ? (int)$r['courseid'] : null,
                          'batchid'   => (int)$batch_id,
                          'sectionid' => (int)($section ? $section->id : $batch_id),
                          'module'    => $mod_num,
                          'mode'      => $deliverymode,
                      ]);
                      $module_tracker_url = (new moodle_url('/local/batchanalytics/module.php', $module_tracker_params))->out(false);
                    ?>
                    <tr>
                      <td>
                        <?php if ($course_linked): ?>
                          <a href="<?= s($module_tracker_url) ?>" class="val"><?= s($r['name']) ?></a>
                        <?php else: ?>
                          <span class="val" title="No course mapped for this module"><?= s($r['name']) ?></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!empty($r['class_mentors'])): ?>
                          <div class="ba-mentors-col">
                            <?php foreach ($r['class_mentors'] as $cm): ?>
                              <div class="ba-table-mentor">
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
                        <?php if ($course_linked): ?>
                          <a href="<?= s($module_tracker_url) ?>" class="viewbtn"><?= s(get_string('view_module', 'local_batchanalytics')) ?></a>
                        <?php else: ?>
                          <button type="button" class="viewbtn disabled" disabled title="No course mapped for this module"><?= s(get_string('view_module', 'local_batchanalytics')) ?></button>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 2. SS Activities Panel -->
        <div id="panel-ss" class="panel">
          <div class="panel-note">
            <?= get_string('ss_panel_note', 'local_batchanalytics') ?>
          </div>
          <div class="tablecard">
            <table>
              <thead>
                <tr>
                  <th><?= s(get_string('col_activity', 'local_batchanalytics')) ?></th>
                  <th><?= s(get_string('col_planned_date', 'local_batchanalytics')) ?></th>
                  <th><?= s(get_string('col_actual_date', 'local_batchanalytics')) ?></th>
                  <th><?= s(get_string('col_status', 'local_batchanalytics')) ?></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($ss_activities)): ?>
                  <tr><td colspan="4" class="muted ba-empty-cell"><?= s(get_string('no_ss_activities', 'local_batchanalytics')) ?></td></tr>
                <?php else: ?>
                <?php foreach ($ss_activities as $act): ?>
                  <tr>
                    <td><span class="val"><?= s($act['activity']) ?></span></td>
                    <td class="date"><?= s($act['p_date']) ?></td>
                    <td class="date"><?= format_cell_muted($act['a_date']) ?></td>
                    <td><span class="st st-<?= s($act['status']) ?>"><?= s($act['label']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 3. Student Performance Panel -->
        <div id="panel-students" class="panel">
          <div class="panel-note">
            <?= get_string('perf_panel_note', 'local_batchanalytics') ?>
          </div>
          <div class="filterbar">
            <div class="toggle">
              <span class="on" id="tg-grade">Grade</span>
              <span id="tg-pct">Completion %</span>
            </div>
            <button type="button" class="exp" id="ba-btn-export"><?= s(get_string('btn_export_filtered', 'local_batchanalytics')) ?></button>
          </div>

          <!-- Dynamic Banding Cards -->
          <div class="bandrow" id="ba-bandrow"></div>

          <!-- Students Table -->
          <div class="tablecard ba-performance-tablecard">
            <div class="ba-performance-table-scroll">
              <table>
              <thead>
                <tr>
                  <th class="sortable" data-sort="band" title="Sort by Band"><?= s(get_string('col_band', 'local_batchanalytics')) ?></th>
                  <th class="sortable ba-performance-student-head" data-sort="student" title="Sort by Student Name"><?= s(get_string('col_student', 'local_batchanalytics')) ?></th>
                  <th class="sortable ba-performance-overall-head" data-sort="grade" title="Sort by Grade"><?= s(get_string('col_grade', 'local_batchanalytics')) ?></th>
                  <th class="sortable" data-sort="attendance" title="Sort by Attendance"><?= s(get_string('col_attendance', 'local_batchanalytics')) ?></th>
                  <?php foreach ($performance_columns as $column): ?>
                    <th class="sortable ba-performance-group-module" data-sort="category:<?= s($column['key']) ?>" title="Sort by <?= s($column['label']) ?>"><?= s($column['label']) ?></th>
                  <?php endforeach; ?>
                  <th class="sortable" data-sort="merit" title="Sort by Merit"><?= s(get_string('col_merit', 'local_batchanalytics')) ?></th>
                </tr>
              </thead>
              <tbody id="ba-stuBody">
                <!-- Populated dynamically with pagination via batch.js -->
              </tbody>
              </table>
            </div>

            <!-- Student Performance Pagination Controls -->
            <div class="ba-pagination-bar" id="ba-pagination-bar">
              <div class="ba-pagination-info" id="ba-pagination-info">
                <!-- Populated via batch.js -->
              </div>
              <div class="ba-pagination-actions">
                <div class="ba-pagination-size-box">
                  <label for="ba-page-size"><?= s(get_string('rows_per_page', 'local_batchanalytics')) ?></label>
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

        <?php if ($batch_can_view_crm): ?>
        <!-- 5. CRM Data Panel -->
        <div id="panel-crm" class="panel">
          <div class="panel-note">
            <?= get_string('crm_panel_note', 'local_batchanalytics') ?>
          </div>

          <!-- CRM Filter + Table Layout -->
          <div class="ba-crm-layout">

            <!-- Filter Sidebar -->
            <div class="ba-crm-sidebar">
              <div class="ba-crm-sidebar-header">
                <span class="ba-crm-sidebar-title"><?= s(get_string('filters', 'local_batchanalytics')) ?></span>
                <span onclick="baBatchCrm.resetFilters()" class="ba-crm-reset-link"><?= s(get_string('filters_reset_all', 'local_batchanalytics')) ?></span>
              </div>

              <!-- Search -->
              <div class="ba-crm-filter-group">
                <label class="ba-crm-filter-label"><?= s(get_string('search_name_id', 'local_batchanalytics')) ?></label>
                <input type="text" id="ba-crm-f-search" class="ba-crm-input-text" placeholder="Search..." oninput="baBatchCrm.applyFilters()">
              </div>

              <!-- Placement Status -->
              <div class="ba-crm-filter-group" id="ba-crm-placement-wrap">
                <label class="ba-crm-filter-label"><?= s(get_string('placement_status', 'local_batchanalytics')) ?></label>
                <select id="ba-crm-f-placement" class="ba-crm-select" onchange="baBatchCrm.applyFilters()">
                  <option value="">All</option>
                  <option value="Placed">Placed</option>
                  <option value="Not Placed">Not Placed</option>
                </select>
              </div>

              <!-- PET Status -->
              <div class="ba-crm-filter-group">
                <label class="ba-crm-filter-label"><?= s(get_string('pet_status', 'local_batchanalytics')) ?></label>
                <select id="ba-crm-f-pet" class="ba-crm-select" onchange="baBatchCrm.applyFilters()">
                  <option value="">All</option>
                </select>
              </div>

              <!-- MAAC Rating Range -->
              <div class="ba-crm-filter-group">
                <label class="ba-crm-filter-label"><?= s(get_string('maac_rating', 'local_batchanalytics')) ?></label>
                <div class="ba-crm-range-row">
                  <input type="number" id="ba-crm-f-maac-min" class="ba-crm-input-range" value="0" min="0" max="10" step="0.1" onchange="baBatchCrm.applyFilters()" placeholder="Min">
                  <span class="ba-crm-range-sep">–</span>
                  <input type="number" id="ba-crm-f-maac-max" class="ba-crm-input-range" value="10" min="0" max="10" step="0.1" onchange="baBatchCrm.applyFilters()" placeholder="Max">
                </div>
              </div>

              <!-- Adv C Mock Score -->
              <div class="ba-crm-filter-group">
                <label class="ba-crm-filter-label"><?= s(get_string('adv_c_mock_score', 'local_batchanalytics')) ?></label>
                <div class="ba-crm-range-row">
                  <input type="number" id="ba-crm-f-advc-min" class="ba-crm-input-range" value="0" min="0" max="100" step="1" onchange="baBatchCrm.applyFilters()" placeholder="Min">
                  <span class="ba-crm-range-sep">–</span>
                  <input type="number" id="ba-crm-f-advc-max" class="ba-crm-input-range" value="100" min="0" max="100" step="1" onchange="baBatchCrm.applyFilters()" placeholder="Max">
                </div>
              </div>

              <!-- Year of Passing -->
              <div class="ba-crm-filter-group" id="ba-crm-yop-wrap">
                <label class="ba-crm-filter-label"><?= s(get_string('year_of_passing', 'local_batchanalytics')) ?></label>
                <div id="ba-crm-f-yop-list" class="ba-crm-checkbox-list">
                  <span class="ba-crm-loading-text"><?= s(get_string('loading_crm_data', 'local_batchanalytics')) ?></span>
                </div>
              </div>

              <!-- Home State -->
              <div class="ba-crm-filter-group ba-crm-filter-group-sm" id="ba-crm-state-wrap">
                <label class="ba-crm-filter-label"><?= s(get_string('home_state', 'local_batchanalytics')) ?></label>
                <div id="ba-crm-f-state-list" class="ba-crm-checkbox-list ba-crm-checkbox-list-lg">
                  <span class="ba-crm-loading-text"><?= s(get_string('loading_crm_data', 'local_batchanalytics')) ?></span>
                </div>
              </div>
            </div>

            <!-- Main Table Area -->
            <div class="ba-crm-main">
              <div class="ba-crm-toolbar">
                <span id="ba-crm-count" class="ba-crm-count"><?= s(get_string('loading_students', 'local_batchanalytics')) ?></span>
                <div class="ba-crm-toolbar-actions">
                  <button type="button" id="ba-crm-retry-btn" onclick="baBatchCrm.retryCrm()" class="viewbtn disabled ba-is-hidden"><?= s(get_string('retry_crm_data', 'local_batchanalytics')) ?></button>
                  <button type="button" id="ba-crm-export-btn" onclick="baBatchCrm.exportCsv()" class="exp"><?= s(get_string('export_csv', 'local_batchanalytics')) ?></button>
                </div>
              </div>
              <div class="tablecard">
                <table id="ba-crm-table">
                  <thead id="ba-crm-thead"><tr><th><?= s(get_string('col_student', 'local_batchanalytics')) ?></th></tr></thead>
                  <tbody id="ba-crm-tbody">
                    <tr><td colspan="20" class="ba-crm-cell-empty"><?= s(get_string('click_crm_tab_to_load', 'local_batchanalytics')) ?></td></tr>
                  </tbody>
                </table>
              </div>
              <div class="ba-pagination-bar" id="ba-crm-pagination-bar">
                <div class="ba-pagination-info" id="ba-crm-pagination-info">
                  <!-- Populated via batch.js -->
                </div>
                <div class="ba-pagination-actions">
                  <div class="ba-pagination-size-box">
                    <label for="ba-crm-page-size"><?= s(get_string('rows_per_page', 'local_batchanalytics')) ?></label>
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
        <?php endif; ?>

        <?php if ($can_view_notes || $can_edit_notes): ?>
        <!-- 4. Review Notes Panel -->
        <div id="panel-notes" class="panel">
          <div class="panel-note">
            <?= get_string('notes_panel_note', 'local_batchanalytics') ?>
          </div>

          <?php if ($can_edit_notes): ?>
          <div class="addnote">
            <textarea id="ba-note-text" placeholder="<?= s(get_string('add_review_note_placeholder', 'local_batchanalytics')) ?>"></textarea>
            <div class="row addnote-row">
              <span class="who">Posting as <b><?= s($current_author_name) ?></b> · <?= s($current_author_role) ?></span>
              <button type="button" id="ba-btn-addnote" data-author="<?= s($current_author_name) ?>"><?= s(get_string('btn_add_note', 'local_batchanalytics')) ?></button>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($can_view_notes): ?>
          <div id="ba-note-list">
            <?php 
            $first_note = true;
            foreach ($display_notes as $nt): 
                $is_latest = $first_note && !empty($has_real_notes);
                $first_note = false;
            ?>
              <div class="note-item<?= empty($has_real_notes) ? ' note-item-demo' : '' ?>" data-note-id="<?= s($nt['id']) ?>">
                <div class="meta">
                  <div class="meta-left">
                    <span class="note-who"><?= s($nt['who']) ?></span>
                    <span class="note-sep">·</span>
                    <span class="note-date"><?= s($nt['date']) ?></span>
                    <?php if ($is_latest): ?>
                      <span class="ba-latest-badge"><?= s(get_string('latest_note', 'local_batchanalytics')) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($nt['is_edited'])): ?>
                      <button type="button" class="ba-btn-note-history" data-note-id="<?= s($nt['id']) ?>" title="<?= s(get_string('view_note_history', 'local_batchanalytics')) ?>">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                        <span><?= s(get_string('edited', 'local_batchanalytics')) ?></span>
                      </button>
                    <?php endif; ?>
                  </div>
                  <?php 
                  $can_manage_all_notes = is_siteadmin()
                      || has_capability('moodle/site:config', $context)
                      || has_capability('local/batchanalytics:manage', $context);
                  $can_edit_this_note = $can_edit_notes && (
                      $can_manage_all_notes || ($is_latest && (int)($nt['userid'] ?? 0) === (int)$USER->id)
                  );
                  if ($can_edit_this_note): 
                  ?>
                    <button type="button" class="ba-btn-edit-note" data-note-id="<?= s($nt['id']) ?>" title="<?= s(get_string('btn_edit_note', 'local_batchanalytics')) ?>">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg><?= s(get_string('btn_edit_note', 'local_batchanalytics')) ?>
                    </button>
                  <?php endif; ?>
                </div>
                <div class="body" data-raw-text="<?= s($nt['body']) ?>"><?= nl2br(s($nt['body'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="ba-note-permission-msg">
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
