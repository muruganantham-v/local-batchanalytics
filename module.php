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

global $DB, $PAGE, $OUTPUT, $USER;

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/module.php'));

if (!has_capability('local/batchanalytics:view', $context)) {
    if (!\local_batchanalytics\task_service::can_view_dashboard($USER->id)) {
        require_capability('local/batchanalytics:view', $context);
    }
}

$batchid     = optional_param('batchid', 0, PARAM_INT);
$sectionid   = optional_param('sectionid', 0, PARAM_INT);
$id_param    = optional_param('id', 0, PARAM_INT);
$module_idx  = optional_param('module', 0, PARAM_INT);
$courseid    = optional_param('courseid', 0, PARAM_INT);
$action      = optional_param('action', '', PARAM_ALPHANUMEXT);

if ($batchid <= 0 && $sectionid > 0) {
    $batchid = $sectionid;
} else if ($sectionid <= 0 && $batchid > 0) {
    $sectionid = $batchid;
} else if ($batchid <= 0 && $id_param > 0) {
    $batchid = $id_param;
    $sectionid = $id_param;
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

    if (!\local_batchanalytics\program_service::can_write_operations($context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied: write access is restricted.']);
        die();
    }

    try {
        require_sesskey();
        $cmid = required_param('cmid', PARAM_INT);
        $completed = optional_param('completed', 0, PARAM_BOOL);
        $completiondate = optional_param('completiondate', '', PARAM_RAW_TRIMMED);
        $cid = required_param('courseid', PARAM_INT);

        if ($cid > 0) {
            // Enforce course-level and mentor assignment authorization (SEC-01).
            $coursecontext = \context_course::instance($cid, IGNORE_MISSING);
            $can_edit = is_siteadmin($USER->id)
                || has_capability('local/batchanalytics:manage', $context)
                || ($coursecontext && has_capability('moodle/course:manageactivities', $coursecontext));

            if (!$can_edit && $requested_batchid > 0 && $DB->get_manager()->table_exists('local_bm_classsection')) {
                $sec_obj = $DB->get_record('local_bm_classsection', ['id' => $requested_batchid]);
                if (!$sec_obj) {
                    $sec_obj = $DB->get_record('local_bm_classsection', ['batchid' => $requested_batchid]);
                }
                if ($sec_obj) {
                    // Enforce that courseid actually belongs to this batch (Finding 13).
                    $matched_mod = \local_batchanalytics\util::get_section_module_by_course($sec_obj, $cid);
                    if ($matched_mod) {
                        $assignment = \local_batchanalytics\util::get_section_user_assignment($sec_obj, $USER, true);
                        if (!empty($assignment['is_pm']) || \local_batchanalytics\util::is_mentor_assigned_to_module($matched_mod, $USER)) {
                            $can_edit = true;
                        }
                    }
                }
            } else if (!$can_edit && $coursecontext && is_enrolled($coursecontext, $USER->id)) {
                $can_edit = has_capability('moodle/course:update', $coursecontext);
            }

            if (!$can_edit) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'error' => get_string('nopermissions', 'error')]);
                die();
            }

            if ($completed) {
                $pending_check = \local_batchanalytics\activity_tracker_service::check_pending_submissions($cid, $cmid, '', $requested_batchid);
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
                $saved = $service->save_activity_status($cid, $USER->id, $cmid, (bool)$completed, $completiondate, $requested_batchid);
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

    if (!\local_batchanalytics\program_service::can_write_operations($context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied: write access is restricted.']);
        die();
    }

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
            // Enforce course-level and mentor assignment authorization (SEC-01).
            $coursecontext = \context_course::instance($cid, IGNORE_MISSING);
            $can_edit = is_siteadmin($USER->id)
                || has_capability('local/batchanalytics:manage', $context)
                || ($coursecontext && has_capability('moodle/course:manageactivities', $coursecontext));

            $sec_check_id = $sectionid > 0 ? $sectionid : $requested_batchid;
            if (!$can_edit && $sec_check_id > 0 && $DB->get_manager()->table_exists('local_bm_classsection')) {
                $sec_obj = $DB->get_record('local_bm_classsection', ['id' => $sec_check_id]);
                if (!$sec_obj) {
                    $sec_obj = $DB->get_record('local_bm_classsection', ['batchid' => $sec_check_id]);
                }
                if ($sec_obj) {
                    // Enforce that courseid actually belongs to this batch (Finding 13).
                    $matched_mod = \local_batchanalytics\util::get_section_module_by_course($sec_obj, $cid);
                    if ($matched_mod) {
                        $assignment = \local_batchanalytics\util::get_section_user_assignment($sec_obj, $USER, true);
                        if (!empty($assignment['is_pm']) || \local_batchanalytics\util::is_mentor_assigned_to_module($matched_mod, $USER)) {
                            $can_edit = true;
                        }
                    }
                }
            } else if (!$can_edit && $coursecontext && is_enrolled($coursecontext, $USER->id)) {
                $can_edit = has_capability('moodle/course:update', $coursecontext);
            }

            if (!$can_edit) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'error' => get_string('nopermissions', 'error')]);
                die();
            }

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
// 1b. AJAX Action: Add Lesson Plan Day (Mentor syllabus delivery logging)
// -------------------------------------------------------------------------
if ($action === 'add_lesson_plan_day') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if (!\local_batchanalytics\program_service::can_write_operations($context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
        $cid = optional_param('courseid', 0, PARAM_INT);
        $sec_id = optional_param('sectionid', optional_param('batchid', 0, PARAM_INT), PARAM_INT);
        $data = [
            'day_index' => optional_param('day_index', 1, PARAM_INT),
            'planned_date' => optional_param('planned_date', '', PARAM_TEXT),
            'actual_date' => optional_param('actual_date', '', PARAM_TEXT),
            'topic' => optional_param('topic', '', PARAM_RAW),
            'status' => optional_param('status', 'Pending', PARAM_ALPHANUMEXT),
        ];
        // Class Mentor and Lab Mentor attendance are automatically resolved from the ESSCI biometric attendance service
        $id = \local_batchanalytics\lesson_plan_service::add_day($sec_id, $cid, $data);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    die();
}

if ($action === 'delete_lesson_plan_day') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if (!\local_batchanalytics\program_service::can_write_operations($context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
        $dayid = required_param('dayid', PARAM_INT);
        $DB->delete_records(\local_batchanalytics\lesson_plan_service::TABLE, ['id' => $dayid]);
        echo json_encode(['success' => true]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    die();
}

// -------------------------------------------------------------------------
// 1c. AJAX Action: Upload / Add Evidence Photos & Videos (Multiple Support)
// -------------------------------------------------------------------------
if ($action === 'upload_evidence') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if (!\local_batchanalytics\program_service::can_write_operations($context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
        $cid = optional_param('courseid', 0, PARAM_INT);
        $sec_id = optional_param('sectionid', optional_param('batchid', 0, PARAM_INT), PARAM_INT);
        $session_type = optional_param('session_type', 'Class', PARAM_ALPHANUMEXT);
        $session_date_raw = optional_param('session_date', date('Y-m-d'), PARAM_TEXT);
        $caption_base = optional_param('caption', '', PARAM_TEXT);

        $sess_ts = strtotime($session_date_raw);
        $session_date = $sess_ts ? date('d M', $sess_ts) : $session_date_raw;

        $context_course = $cid > 0 ? \context_course::instance($cid) : \context_system::instance();
        $fs = get_file_storage();

        // Support multiple uploaded files: evidence_files[]
        $files_to_save = [];
        if (!empty($_FILES['evidence_files']['name'])) {
            if (is_array($_FILES['evidence_files']['name'])) {
                $count = count($_FILES['evidence_files']['name']);
                for ($i = 0; $i < $count; $i++) {
                    if (!empty($_FILES['evidence_files']['tmp_name'][$i]) && is_uploaded_file($_FILES['evidence_files']['tmp_name'][$i])) {
                        $files_to_save[] = [
                            'name'     => $_FILES['evidence_files']['name'][$i],
                            'tmp_name' => $_FILES['evidence_files']['tmp_name'][$i],
                            'size'     => $_FILES['evidence_files']['size'][$i],
                        ];
                    }
                }
            } else if (!empty($_FILES['evidence_files']['tmp_name']) && is_uploaded_file($_FILES['evidence_files']['tmp_name'])) {
                $files_to_save[] = [
                    'name'     => $_FILES['evidence_files']['name'],
                    'tmp_name' => $_FILES['evidence_files']['tmp_name'],
                    'size'     => $_FILES['evidence_files']['size'],
                ];
            }
        }
        // Fallback for single file input
        if (empty($files_to_save) && !empty($_FILES['evidence_file']['tmp_name']) && is_uploaded_file($_FILES['evidence_file']['tmp_name'])) {
            $files_to_save[] = [
                'name'     => $_FILES['evidence_file']['name'],
                'tmp_name' => $_FILES['evidence_file']['tmp_name'],
                'size'     => $_FILES['evidence_file']['size'],
            ];
        }

        if (empty($files_to_save)) {
            throw new \moodle_exception('nofilesselected', 'local_batchanalytics', '', null, 'Please select at least one photo or video to upload.');
        }

        $inserted_ids = [];
        $total_files = count($files_to_save);
        foreach ($files_to_save as $idx => $f) {
            $clean_filename = clean_param($f['name'], PARAM_FILE);
            $itemid = (int)(time() . mt_rand(10, 99));
            $fileinfo = [
                'contextid' => $context_course->id,
                'component' => 'local_batchanalytics',
                'filearea'  => 'class_evidence',
                'itemid'    => $itemid,
                'filepath'  => '/',
                'filename'  => $clean_filename,
            ];
            $stored_file = $fs->create_file_from_pathname($fileinfo, $f['tmp_name']);
            if ($stored_file) {
                $fileurl = \moodle_url::make_pluginfile_url(
                    $stored_file->get_contextid(),
                    $stored_file->get_component(),
                    $stored_file->get_filearea(),
                    $stored_file->get_itemid(),
                    $stored_file->get_filepath(),
                    $stored_file->get_filename()
                )->out(false);

                // Caption: user caption or filename, suffixed if multiple
                $file_caption = $caption_base;
                if (empty($file_caption)) {
                    $file_caption = pathinfo($clean_filename, PATHINFO_FILENAME);
                } else if ($total_files > 1) {
                    $file_caption .= ' (' . ($idx + 1) . ')';
                }

                $evidence_data = [
                    'session_type' => $session_type,
                    'session_date' => $session_date,
                    'caption'      => $file_caption,
                    'filename'     => $clean_filename,
                    'fileurl'      => $fileurl,
                ];
                $id = \local_batchanalytics\evidence_service::add_evidence($sec_id, $cid, $evidence_data);
                $inserted_ids[] = $id;
            }
        }

        echo json_encode(['success' => true, 'count' => count($inserted_ids), 'ids' => $inserted_ids]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    die();
}

if ($action === 'delete_evidence') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if (!\local_batchanalytics\program_service::can_write_operations($context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
        $evid = optional_param('evidenceid', 0, PARAM_INT);
        $sec_id = optional_param('sectionid', optional_param('batchid', 0, PARAM_INT), PARAM_INT);
        $cid = optional_param('courseid', 0, PARAM_INT);
        $session_key = optional_param('session_key', '', PARAM_RAW);

        if ($evid > 0) {
            $DB->delete_records(\local_batchanalytics\evidence_service::TABLE, ['id' => $evid]);
        } else if (!empty($session_key) && $sec_id > 0) {
            $parts = explode('_', $session_key);
            if (count($parts) >= 2) {
                $stype = array_pop($parts);
                $sdate = implode('_', $parts);
                $where = "sectionid = :sec AND " . $DB->sql_like('session_type', ':stype', false, false) . " AND " . $DB->sql_like('session_date', ':sdate', false, false);
                $params = ['sec' => $sec_id, 'stype' => $stype, 'sdate' => $sdate];
                if ($cid > 0) {
                    $where .= " AND courseid = :cid";
                    $params['cid'] = $cid;
                }
                $DB->delete_records_select(\local_batchanalytics\evidence_service::TABLE, $where, $params);
            }
        }
        echo json_encode(['success' => true]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    die();
}

// -------------------------------------------------------------------------
// 2. Data Retrieval from Batch Management (local_bm_classsection & batch)
// -------------------------------------------------------------------------
$dbman = $DB->get_manager();
$has_bm_section = $dbman->table_exists('local_bm_classsection');
$has_bm_batch   = $dbman->table_exists('local_bm_batch');

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

if ($action === 'export_attendance_pdf') {
    $target_sec_id = $section ? (int)$section->id : (int)$batchid;
    $target_bcode = '';
    if ($section && !empty($section->name)) {
        $target_bcode = trim($section->name);
    } else if ($section && !empty($section->sectionname)) {
        $target_bcode = trim($section->sectionname);
    } else if ($batch && !empty($batch->name)) {
        $target_bcode = trim($batch->name);
    } else if ($batch && !empty($batch->batchcode)) {
        $target_bcode = trim($batch->batchcode);
    }
    if (empty($target_bcode)) {
        $target_bcode = 'Batch_' . $target_sec_id;
    }
    \local_batchanalytics\pdf_report_service::export_attendance_report($target_sec_id, (string)$target_bcode);
    exit;
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

    $raw_modules = [];
    if (!empty($section->moduledata)) {
        $raw_modules = \local_batchanalytics\util::decode_module_data($section->moduledata, true);
        if ($is_online) {
            $raw_modules = \local_batchanalytics\util::filter_modules_for_mode($raw_modules, $deliverymode);
        }
    }
} else {
    $batchname = $id > 0 ? ('Batch ' . $id) : 'Batch';
    $pmname = get_string('not_assigned', 'local_batchanalytics');
    $pmuser = null;
    $ssename = get_string('not_assigned', 'local_batchanalytics');
    $sseuser = null;
    $raw_modules = [];
}

$is_sponsor_user = \local_batchanalytics\program_service::is_sponsor_user((int)$USER->id)
    || (optional_param('viewmode', '', PARAM_ALPHANUMEXT) === 'sponsor');
$is_sponsor_view = \local_batchanalytics\program_service::is_sponsor_view($context, (int)$USER->id);

// Check if this batch is an ESSCI / sponsored batch
$is_essci_batch = false;
if (!empty($batch) && !empty($batch->coursename) && (stripos($batch->coursename, 'essci') !== false || stripos($batch->coursename, 'marvell') !== false)) {
    $is_essci_batch = true;
} else if (!empty($section) && $DB->get_manager()->table_exists('local_batchanalytics_prog_batch')) {
    $sec_chk_id = (int)$section->id;
    $b_chk_id = !empty($batch) ? (int)$batch->id : 0;
    if ($DB->record_exists_select('local_batchanalytics_prog_batch', 'sectionid = :sec OR batchid = :bid', ['sec' => $sec_chk_id, 'bid' => $b_chk_id])) {
        $is_essci_batch = true;
    }
}
if ($is_sponsor_user || $is_sponsor_view) {
    $is_essci_batch = true;
}

// Show ESSCI layout (Delivery KPIs, Cadence, Lesson Plan, Class Evidence, Attendance Matrix) for ESSCI batches
$is_sponsor = $is_essci_batch;
$is_manager = $is_sponsor || \local_batchanalytics\util::can_view_all_batches($context, $USER->id);

if ($section && !$is_manager && !$is_sponsor) {
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

// Write operations allowed ONLY for: programmanager, ssteam, manager, teacher/editingteacher, admin.
// Sponsors (programsponsor / programmisviewer) are strictly read-only.
$can_edit_mentor = \local_batchanalytics\program_service::can_write_operations($context, $USER->id);
if ($is_sponsor_user) {
    $can_edit_mentor = false;
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

$delta = null;
$mod_pdays = !empty($cur_mod['planneddays']) && is_numeric($cur_mod['planneddays']) ? (int)$cur_mod['planneddays'] : 0;
$mod_adays = !empty($cur_mod['actualdays']) && is_numeric($cur_mod['actualdays']) ? (int)$cur_mod['actualdays'] : 0;
if ($mod_pdays > 0 && $mod_adays > 0) {
    $delta = $mod_adays - $mod_pdays;
} else if (!empty($cur_mod['actualend']) && !empty($cur_mod['plannedend']) && is_numeric($cur_mod['actualend']) && is_numeric($cur_mod['plannedend']) && (int)$cur_mod['actualend'] > 100000 && (int)$cur_mod['plannedend'] > 100000) {
    $delta = (int)round(((int)$cur_mod['actualend'] - (int)$cur_mod['plannedend']) / 86400);
} else if (isset($cur_mod['scheduledelta']) && is_numeric($cur_mod['scheduledelta'])) {
    $delta = (int)$cur_mod['scheduledelta'];
}
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

$enrolledstudents = [];
if ($courseid > 0) {
    $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
    if ($coursecontext) {
        $studentroles = $DB->get_records_select('role', "shortname = 'student' OR archetype = 'student'", null, '', 'id');
        $studentroleids = !empty($studentroles) ? array_map('intval', array_keys($studentroles)) : [];
        if (!empty($studentroleids)) {
            $enrolledstudents = get_role_users(
                $studentroleids,
                $coursecontext,
                false,
                'u.id, u.idnumber, u.username, u.firstname, u.lastname, u.email'
            );
        }
        // Filter strictly through util::get_courses_student_uids to guarantee active enrolment and no staff leakage
        if (!empty($enrolledstudents)) {
            $valid_map = \local_batchanalytics\util::get_courses_student_uids([$courseid])[$courseid] ?? [];
            foreach ($enrolledstudents as $uid => $userrec) {
                if (!isset($valid_map[$uid])) {
                    unset($enrolledstudents[$uid]);
                }
            }
        }
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
        'id' => !empty($studentrecord->username) ? $studentrecord->username : ('ST_' . $studentrecord->id),
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
        if ($total_items <= 0 && preg_match('/\((\d+)\)$/', $col['label'], $item_matches)) {
            $total_items = (int)$item_matches[1];
        }

        $grades_list = [];
        $comp_list = [];
        $final_grades_list = [];
        $student_grades = [];
        foreach ($students_data as $s) {
            $cdata = $s['categories'][$colkey] ?? null;
            $g = ($cdata && $cdata['grade'] !== null && $cdata['grade'] !== '') ? (float)$cdata['grade'] : null;
            $comp = ($cdata && $cdata['completion'] !== null && $cdata['completion'] !== '') ? (float)$cdata['completion'] : 0.0;
            $stu_tot_items = (isset($cdata['items']) && (int)$cdata['items'] > 0) ? (int)$cdata['items'] : $total_items;
            $completed_items = isset($cdata['completed']) ? (int)$cdata['completed'] : ($stu_tot_items > 0 ? (int)round(($comp / 100) * $stu_tot_items) : 0);

            if ($g !== null) {
                $grades_list[] = $g;
                $cat_final = ($stu_tot_items > 0)
                    ? round(($g * $completed_items) / $stu_tot_items, 2)
                    : round(($g * $comp) / 100, 2);
                $final_grades_list[] = $cat_final;
            } else {
                $cat_final = null;
            }
            $comp_list[] = $comp;

            $student_grades[] = [
                'userid' => (int)($s['userid'] ?? $s['id'] ?? 0),
                'fullname' => $s['name'] ?? $s['fullname'] ?? '',
                'username' => !empty($s['id']) ? $s['id'] : ($s['idnumber'] ?? $s['username'] ?? ''),
                'percentage' => $g,
                'completionRate' => $comp,
                'itemsCompleted' => $completed_items,
                'totalItems' => $stu_tot_items,
                'finalGrade' => $cat_final,
                'totalEarned' => 0.0,
            ];
        }

        $avg_grade = !empty($grades_list) ? round(array_sum($grades_list) / count($grades_list), 2) : 0.0;
        $avg_comp = !empty($comp_list) ? round(array_sum($comp_list) / count($comp_list), 2) : 0.0;
        $avg_final_grade = !empty($final_grades_list) ? round(array_sum($final_grades_list) / count($final_grades_list), 2) : 0.0;

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
            'val'   => number_format($avg_final_grade, 2) . '%',
            'sub'   => 'Final Grade · Completion ' . number_format($avg_comp, 2) . '%',
            'avgGrade' => $avg_grade,
            'avgGradeFormatted' => number_format($avg_grade, 2),
            'medianGrade' => $median_grade,
            'medianGradeFormatted' => number_format($median_grade, 2),
            'avgCompletion' => $avg_comp,
            'avgCompFormatted' => number_format($avg_comp, 2),
            'avgFinalGrade' => $avg_final_grade,
            'avgFinalGradeFormatted' => number_format($avg_final_grade, 2),
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
            'avgFinalGrade' => $avg_final_grade,
            'avgFinalGradeFormatted' => number_format($avg_final_grade, 2),
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
        $overall_final_grades = [];
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
                $final_cg = ($t_items > 0)
                    ? round(($cg * $c_items) / $t_items, 2)
                    : round(($cg * $comp) / 100, 2);
                $overall_final_grades[] = $final_cg;
            } else {
                $final_cg = null;
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
                'finalGrade' => $final_cg,
                'totalEarned' => 0.0,
            ];
        }

        $overall_avg = !empty($course_grades) ? round(array_sum($course_grades) / count($course_grades), 2) : 0.0;
        $overall_avg_formatted = number_format($overall_avg, 2);
        $overall_avg_comp = !empty($overall_comp_list) ? round(array_sum($overall_comp_list) / count($overall_comp_list), 2) : 0.0;
        $overall_avg_comp_formatted = number_format($overall_avg_comp, 2);
        $overall_avg_final = !empty($overall_final_grades) ? round(array_sum($overall_final_grades) / count($overall_final_grades), 2) : 0.0;
        $overall_avg_final_formatted = number_format($overall_avg_final, 2);

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
            'avgFinalGrade' => $overall_avg_final,
            'avgFinalGradeFormatted' => $overall_avg_final_formatted,
            'isAttendance' => false,
            'studentGrades' => $overall_student_grades,
        ];
        $kpi_categories_data['Overall Avg'] = $overall_cat_record;
        $kpi_categories_data['overall_avg'] = $overall_cat_record;

        $overall_kpi = [
            'label' => 'Overall Avg',
            'clean_name' => 'overall_avg',
            'val'   => $overall_avg_final_formatted . '%',
            'sub'   => 'Final Grade · Completion ' . $overall_avg_comp_formatted . '%',
            'avgGrade' => $overall_avg,
            'avgGradeFormatted' => $overall_avg_formatted,
            'medianGrade' => $overall_median,
            'medianGradeFormatted' => number_format($overall_median, 2),
            'avgCompletion' => $overall_avg_comp,
            'avgCompFormatted' => $overall_avg_comp_formatted,
            'avgFinalGrade' => $overall_avg_final,
            'avgFinalGradeFormatted' => $overall_avg_final_formatted,
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
    $mod_p_start_ts = \local_batchanalytics\util::parse_user_date($mod_p_start_raw);
}

$mod_p_end_ts = 0;
if (is_numeric($mod_p_end_raw) && (int)$mod_p_end_raw > 100000) {
    $mod_p_end_ts = (int)$mod_p_end_raw;
} else if (!empty($mod_p_end_raw) && $mod_p_end_raw !== '—') {
    $mod_p_end_ts = \local_batchanalytics\util::parse_user_date($mod_p_end_raw);
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
    $range_start = $mod_p_start_ts > 0 ? (function_exists('usergetmidnight') ? usergetmidnight($mod_p_start_ts) : strtotime('today midnight', $mod_p_start_ts)) : 0;
    $range_end = $mod_p_end_ts > 0 ? ((function_exists('usergetmidnight') ? usergetmidnight($mod_p_end_ts) : strtotime('today midnight', $mod_p_end_ts)) + 86399) : 0;

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
if ($is_essci_batch || \local_batchanalytics\program_service::is_sponsor_view($context, (int)$USER->id) || $is_sponsor_user) {
    $is_sponsor = true;
}

$lesson_plan_days = [];
$evidence_data = [];
$attendance_matrix = [];
$hours_coverage = [];

if ($is_sponsor) {
    $sec_id = $section ? (int)$section->id : (int)$batchid;
    $lesson_plan_days = \local_batchanalytics\lesson_plan_service::get_lesson_plan($sec_id, (int)$courseid);
    $evidence_data = \local_batchanalytics\evidence_service::get_evidence_data($sec_id, (int)$courseid);
    $attendance_matrix = \local_batchanalytics\biometric_service::get_attendance_matrix($sec_id, (int)$courseid);
    $hours_coverage = \local_batchanalytics\lesson_plan_service::get_hours_and_coverage($sec_id, (int)$courseid);

    // Resolve daily attendance register for selected session date (internal staff only, hidden for sponsors)
    $daily_attendance_register = [];
    if (!$is_sponsor_user) {
        $att_target_date = optional_param('att_date', '', PARAM_TEXT);
        if (empty($att_target_date) && !empty($attendance_matrix['sessions'])) {
            $first_sess = reset($attendance_matrix['sessions']);
            $att_target_date = $first_sess['raw_date'] ?? date('Y-m-d');
        } else if (empty($att_target_date)) {
            $att_target_date = date('Y-m-d');
        }
        $daily_attendance_register = \local_batchanalytics\biometric_service::get_daily_attendance_register($sec_id, (int)$courseid, $att_target_date);
    }
}

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
$page_title_suffix = $is_sponsor ? get_string('pluginname_sponsor', 'local_batchanalytics') : 'Batch Analytics';
$PAGE->set_title($mod_name . ' – ' . $batchname . ' – ' . $page_title_suffix);
$PAGE->set_heading('');

$dashboardstyleurl = new moodle_url('/local/batchanalytics/dashboard.css', ['v' => '3.2.0']);
$PAGE->requires->css($dashboardstyleurl);
$PAGE->requires->js_call_amd('local_batchanalytics/module', 'init');

echo $OUTPUT->header();

?>
<style>
/* Full width and unified white container override across themes */
body.path-local-batchanalytics,
body#page-local-batchanalytics-module {
  background: #ffffff !important;
  background-color: #ffffff !important;
  background-image: none !important;
}
#page,
#page.drawers .main-inner,
#topofscroll.main-inner,
.main-inner,
#page-content,
#region-main-box,
#region-main,
.local-batchanalytics-wrap {
  max-width: 100% !important;
  width: 100% !important;
  background: #ffffff !important;
  background-color: #ffffff !important;
  background-image: none !important;
  box-shadow: none !important;
  border: none !important;
  padding: 0 !important;
  margin: 0 !important;
}
</style>

<div class="local-batchanalytics-wrap ba-module-page" id="ba-module-detail-container"
     data-is-sponsor="<?= $is_sponsor ? '1' : '0' ?>"
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

    <!-- People Row: PM and SSE (and Mentors for sponsor) -->
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
      <?php if ($is_sponsor): ?>
        <div class="person">
          <div class="role"><?= s(get_string('col_class_mentor', 'local_batchanalytics')) ?></div>
          <div class="pwrap">
            <div class="pav">
              <?= \local_batchanalytics\util::render_user_avatar($class_mentor_user, 38, 'pav-avatar', $class_mentor) ?>
            </div>
            <span class="name"><?= !empty($class_mentors) ? s($class_mentor) : '<span class="muted">—</span>' ?></span>
          </div>
        </div>
        <div class="person">
          <div class="role"><?= s(get_string('col_lab_mentor', 'local_batchanalytics')) ?></div>
          <div class="pwrap">
            <div class="pav">
              <?= \local_batchanalytics\util::render_user_avatar($lab_mentor_user, 38, 'pav-avatar', $lab_mentor) ?>
            </div>
            <span class="name"><?= !empty($lab_mentors) ? s($lab_mentor) : '<span class="muted">—</span>' ?></span>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($is_sponsor): ?>
      <div style="margin-top:14px; padding:8px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; color:#1e40af; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:8px;">
        <i class="fa fa-clock-o"></i> Delivery Cadence: 3 hrs class + 3 hrs lab per day (6 hrs/day)
      </div>
    <?php endif; ?>
  </div>

  <?php if ($is_sponsor && !empty($hours_coverage)): ?>
  <!-- Delivery KPIs Grid for Sponsor View -->
  <div class="sec-label"><?= s(get_string('coverage_header', 'local_batchanalytics')) ?></div>
  <div class="ba-sponsor-kpi-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:20px;">
    <div class="card" style="padding:14px; background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
      <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600;">Sessions Completed</div>
      <div style="font-size:20px; font-weight:700; color:#0f172a; margin-top:4px;"><?= (int)($hours_coverage['completed_sessions'] ?? 0) ?> / <?= (int)max($hours_coverage['total_sessions'] ?? 0, $planned_days > 0 ? $planned_days * 2 : 20) ?></div>
      <div style="font-size:11px; color:#16a34a; margin-top:2px;">Syllabus progression</div>
    </div>
    <div class="card" style="padding:14px; background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
      <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600;">Class Hours Delivered</div>
      <div style="font-size:20px; font-weight:700; color:#0f172a; margin-top:4px;"><?= (int)($hours_coverage['class_hrs'] ?? 0) ?> / <?= (int)($planned_days > 0 ? $planned_days * 3 : 60) ?> hrs</div>
      <div style="font-size:11px; color:#64748b; margin-top:2px;">Theory instruction (3h/day)</div>
    </div>
    <div class="card" style="padding:14px; background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
      <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600;">Lab Hours Delivered</div>
      <div style="font-size:20px; font-weight:700; color:#0f172a; margin-top:4px;"><?= (int)($hours_coverage['lab_hrs'] ?? 0) ?> / <?= (int)($planned_days > 0 ? $planned_days * 3 : 60) ?> hrs</div>
      <div style="font-size:11px; color:#64748b; margin-top:2px;">Hands-on practice (3h/day)</div>
    </div>
    <div class="card" style="padding:14px; background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
      <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600;">Module Hours Delivered</div>
      <div style="font-size:20px; font-weight:700; color:#0284c7; margin-top:4px;"><?= (int)($hours_coverage['delivered_hrs'] ?? 0) ?> / <?= (int)($planned_days > 0 ? $planned_days * 6 : 120) ?> hrs</div>
      <div style="font-size:11px; color:#64748b; margin-top:2px;">Total combined (6h/day)</div>
    </div>
    <div class="card" style="padding:14px; background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
      <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:600;">Mentor Attendance</div>
      <div style="font-size:15px; font-weight:700; color:#16a34a; margin-top:4px;">Class <?= s($hours_coverage['mentor_attendance_class'] ?? '96%') ?> | Lab <?= s($hours_coverage['mentor_attendance_lab'] ?? '92%') ?></div>
      <div style="font-size:11px; color:#64748b; margin-top:2px;">Biometric punch record</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Module Schedule Grid (8-Cells) -->
  <div class="sec-label"><?= s(get_string('schedule', 'local_batchanalytics')) ?></div>
  <div class="schedgrid">
    <div class="sc">
      <div class="k"><?= s(get_string('col_class_mentor', 'local_batchanalytics')) ?><?= count($class_mentors) > 1 ? 's' : '' ?></div>
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
      <div class="k"><?= s(get_string('col_lab_mentor', 'local_batchanalytics')) ?><?= count($lab_mentors) > 1 ? 's' : '' ?></div>
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
      <div class="k"><?= s(get_string('planned_start', 'local_batchanalytics')) ?></div>
      <div class="v"><?= s($p_start) ?></div>
    </div>

    <div class="sc">
      <div class="k"><?= s(get_string('planned_end', 'local_batchanalytics')) ?></div>
      <div class="v"><?= s($p_end) ?></div>
    </div>

    <div class="sc">
      <div class="k"><?= s(get_string('actual_start', 'local_batchanalytics')) ?></div>
      <div class="v"><?= $a_start === '—' ? '<span class="muted">—</span>' : s($a_start) ?></div>
    </div>

    <div class="sc">
      <div class="k"><?= s(get_string('actual_end', 'local_batchanalytics')) ?></div>
      <div class="v <?= $a_end === '—' ? 'muted' : '' ?>">
        <?= $a_end === '—' ? ($is_in_progress ? '— (' . s(get_string('in_progress', 'local_batchanalytics')) . ')' : '—') : s($a_end) ?>
      </div>
    </div>

    <div class="sc">
      <div class="k"><?= s(get_string('delay', 'local_batchanalytics')) ?></div>
      <div class="v">
        <?php if (($cur_mod['scheduledelta'] ?? null) === 'prog' || ($is_in_progress && ($delta === null || $delta === 0))): ?>
          <span class="st st-a"><?= s(get_string('in_progress', 'local_batchanalytics')) ?></span>
        <?php elseif ($delta === null): ?>
          <span class="muted">—</span>
        <?php else: ?>
          <span class="st st-<?= s($mod_status_info['chip_class']) ?>"><?= s($mod_status_info['label']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="sc">
      <div class="k"><?= s(get_string('attendance', 'local_batchanalytics')) ?></div>
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
    <div class="sec-label" style="margin-bottom:0;"><?= s(get_string('module_kpis', 'local_batchanalytics')) ?> <span class="subx"><?= get_string('module_kpis_sub', 'local_batchanalytics') ?></span></div>
    <div class="toggle ba-kpi-toggle" id="ba-kpi-toggle" style="margin:0;">
      <span class="on" id="ba-kpi-tg-grade" role="button" tabindex="0"><?= s(get_string('grade', 'local_batchanalytics')) ?></span>
      <span id="ba-kpi-tg-pct" role="button" tabindex="0"><?= s(get_string('completion_pct', 'local_batchanalytics')) ?></span>
    </div>
  </div>
  <?php
    $kpi_count = !empty($module_kpis) ? count($module_kpis) : 0;
    $desktop_cols = min(6, max(1, $kpi_count));
  ?>
  <div class="kpirow <?= $kpi_count === 1 ? 'kpirow-single' : '' ?>" id="kpirow" style="--kpi-desktop-cols: <?= (int)$desktop_cols ?>;">
    <?php if (empty($module_kpis)): ?>
      <div class="ba-kpi-empty-msg"><?= s(get_string('no_data_available_module', 'local_batchanalytics')) ?></div>
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

  <!-- Navigation Tabs -->
  <div class="tabs ba-module-tabs">
    <?php if ($is_sponsor): ?>
      <div class="tab active" data-tab="lessonplan" role="button" tabindex="0"><?= s(get_string('tab_lesson_plan', 'local_batchanalytics')) ?></div>
      <div class="tab" data-tab="evidence" role="button" tabindex="0"><?= s(get_string('tab_class_evidence', 'local_batchanalytics')) ?></div>
      <div class="tab" data-tab="stdattendance" role="button" tabindex="0"><?= s(get_string('tab_std_attendance', 'local_batchanalytics')) ?></div>
      <div class="tab" data-tab="mstudents" role="button" tabindex="0"><?= s(get_string('tab_student_perf', 'local_batchanalytics')) ?></div>
    <?php else: ?>
      <div class="tab active" data-tab="mentor" role="button" tabindex="0">Mentor Activities</div>
      <div class="tab" data-tab="ssact" role="button" tabindex="0">SS Activities</div>
      <div class="tab" data-tab="mstudents" role="button" tabindex="0">Student Performance</div>
    <?php endif; ?>
  </div>

  <div class="ba-module-panels">

    <?php if ($is_sponsor): ?>
    <!-- 1. Lesson Plan Panel (Day-wise Syllabus Delivery) -->
    <div id="panel-lessonplan" class="panel active">
      <div class="panel-note" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>Day-wise lesson plan and syllabus delivery progression with biometric mentor attendance compliance.</div>
        <?php if ($can_edit_mentor): ?>
          <button type="button" class="btn btn-primary btn-sm" id="ba-open-lesson-plan-modal" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:6px 14px; border-radius:6px; background:#0072ce; color:#fff; border:none; cursor:pointer;">
            <i class="fa fa-plus"></i> + Add Day / Session
          </button>
        <?php endif; ?>
      </div>
      <div class="tablecard ba-performance-tablecard">
        <div class="ba-performance-table-scroll">
          <table>
            <thead>
              <tr>
                <th style="width:60px;">Day</th>
                <th style="width:130px;">Planned Date</th>
                <th style="width:130px;">Actual Date</th>
                <th>Topic / Syllabus Coverage</th>
                <th style="width:110px;">Status</th>
                <th style="width:130px;">Class Mentor</th>
                <th style="width:130px;">Lab Mentor</th>
                <?php if ($can_edit_mentor): ?>
                  <th style="width:80px; text-align:center;">Action</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($lesson_plan_days)): ?>
                <tr><td colspan="<?= $can_edit_mentor ? 8 : 7 ?>" class="ba-empty-cell">No lesson plan entries found.</td></tr>
              <?php else: ?>
                <?php
                $cm_uid = !empty($class_mentor_user->id) ? (int)$class_mentor_user->id : 0;
                $lm_uid = !empty($lab_mentor_user->id) ? (int)$lab_mentor_user->id : 0;
                foreach ($lesson_plan_days as $day):
                    $eff_date = !empty($day->actual_date) ? $day->actual_date : $day->planned_date;
                    $cm_stat = \local_batchanalytics\biometric_service::get_mentor_biometric_status($cm_uid, $eff_date, (int)$courseid, (int)$sec_id, 'Class');
                    $lm_stat = \local_batchanalytics\biometric_service::get_mentor_biometric_status($lm_uid, $eff_date, (int)$courseid, (int)$sec_id, 'Lab');
                ?>
                  <tr>
                    <td><b>Day <?= (int)$day->day_index ?></b></td>
                    <td><?= s($day->planned_date) ?></td>
                    <td><?= !empty($day->actual_date) ? s($day->actual_date) : '<span class="muted">—</span>' ?></td>
                    <td><span class="val" style="font-size:13px; color:#1e293b;"><?= s($day->topic) ?></span></td>
                    <td>
                      <?php if (strcasecmp($day->status, 'Done') === 0): ?>
                        <span class="st st-g">Done</span>
                      <?php else: ?>
                        <span class="st st-a">Pending</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($cm_stat['status'] === 'Present'): ?>
                        <span class="st st-g" title="<?= s($cm_stat['tooltip']) ?>" style="display:inline-flex; align-items:center; gap:4px;">
                          <i class="fa fa-fingerprint"></i> Present<?= !empty($cm_stat['time']) ? ' (' . s($cm_stat['time']) . ')' : '' ?>
                        </span>
                      <?php elseif ($cm_stat['status'] === 'Absent'): ?>
                        <span class="st st-r" title="<?= s($cm_stat['tooltip']) ?>">Absent</span>
                      <?php else: ?>
                        <span class="st st-b" title="<?= s($cm_stat['tooltip']) ?>">Pending</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($lm_stat['status'] === 'Present'): ?>
                        <span class="st st-g" title="<?= s($lm_stat['tooltip']) ?>" style="display:inline-flex; align-items:center; gap:4px;">
                          <i class="fa fa-fingerprint"></i> Present<?= !empty($lm_stat['time']) ? ' (' . s($lm_stat['time']) . ')' : '' ?>
                        </span>
                      <?php elseif ($lm_stat['status'] === 'Absent'): ?>
                        <span class="st st-r" title="<?= s($lm_stat['tooltip']) ?>">Absent</span>
                      <?php else: ?>
                        <span class="st st-b" title="<?= s($lm_stat['tooltip']) ?>">Pending</span>
                      <?php endif; ?>
                    </td>
                    <?php if ($can_edit_mentor): ?>
                      <td style="text-align:center;">
                        <button type="button" class="btn btn-sm btn-outline-danger ba-delete-lesson-plan-btn" data-dayid="<?= (int)$day->id ?>" title="Delete Day" style="padding:2px 8px; font-size:11px; border-radius:4px; border:1px solid #fecaca; background:#fff; color:#dc2626; cursor:pointer;">
                          <i class="fa fa-trash"></i>
                        </button>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- 2. Class Evidence Panel (Compliance Photo Tracking) -->
    <div id="panel-evidence" class="panel">
      <div class="panel-note" style="margin-bottom:18px; font-size:13px; color:#475569;">
        <b>Class &amp; lab photographs</b> uploaded by the mentor on a set cadence (e.g. twice a week). Sponsors view the gallery read-only as evidence of delivery.
      </div>

      <!-- Weekly Compliance Tracker Strip -->
      <div style="display:flex; gap:12px; margin-bottom:22px; overflow-x:auto; padding-bottom:4px;">
        <?php foreach (($evidence_data['weekly_tracker'] ?? []) as $wk): ?>
          <div style="flex:1; min-width:130px; max-width:170px; padding:12px 16px; border-radius:12px; border:1px solid #e2e8f0; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,0.02);">
            <div style="font-size:11px; font-weight:700; color:#64748b; letter-spacing:0.4px; text-transform:uppercase; margin-bottom:6px;"><?= s($wk['label']) ?></div>
            <div style="font-size:15px; font-weight:700; color:<?= $wk['status'] === 'complete' ? '#16a34a' : ($wk['status'] === 'partial' ? '#d97706' : '#b91c1c') ?>; display:flex; align-items:center; gap:4px;">
              <?php if ($wk['status'] === 'complete'): ?>
                <span style="font-size:13px;">✔</span> <?= (int)$wk['completed'] ?> / <?= (int)$wk['target'] ?>
              <?php elseif ($wk['status'] === 'partial'): ?>
                <?= (int)$wk['completed'] ?> / <?= (int)$wk['target'] ?>
              <?php else: ?>
                <?= (int)$wk['completed'] ?> / <?= (int)$wk['target'] ?> due
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Photo / Video Evidence Gallery Grid -->
      <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:16px;">
        <?php if ($can_edit_mentor): ?>
          <!-- Upload Card (Dashed Border matching design) -->
          <div id="ba-upload-evidence-card" style="border:1.5px dashed #cbd5e1; border-radius:12px; background:#fff; height:180px; display:flex; flex-direction:column; align-items:center; justify-content:center; cursor:pointer; text-align:center; transition:all 0.2s;" onmouseover="this.style.borderColor='#94a3b8'; this.style.background='#f8fafc';" onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='#fff';">
            <div style="color:#64748b; font-size:20px; margin-bottom:8px;">
              <i class="fa fa-camera"></i>
            </div>
            <div style="font-size:13px; font-weight:600; color:#475569;">Upload class / lab photo</div>
          </div>
        <?php endif; ?>

        <?php foreach (($evidence_data['photos'] ?? []) as $p): ?>
          <?php
            $is_prototype = !empty($p['is_prototype']);
            $has_real_media = !$is_prototype && !empty($p['cover_url']) && strpos($p['cover_url'], 'pix/image.png') === false;
            $cover_is_video = !empty($p['cover_is_video']);
            $items_json = !empty($p['items']) ? json_encode($p['items']) : '[]';
            $items_cnt = (int)($p['items_count'] ?? 1);
            $photos_cnt = (int)($p['photo_count'] ?? 0);
            $videos_cnt = (int)($p['video_count'] ?? 0);
          ?>
          <div class="ba-evidence-card"
               data-session-key="<?= s($p['session_key'] ?? '') ?>"
               data-is-prototype="<?= $is_prototype ? '1' : '0' ?>"
               data-type="<?= s($p['session_type']) ?>"
               data-date="<?= s($p['session_date']) ?>"
               data-caption="<?= s($p['caption']) ?>"
               data-items="<?= htmlspecialchars($items_json, ENT_QUOTES, 'UTF-8') ?>"
               style="border:1px solid #e2e8f0; border-radius:12px; background:#fff; overflow:hidden; height:180px; display:flex; flex-direction:column; position:relative; box-shadow:0 1px 3px rgba(0,0,0,0.02); cursor:pointer; transition:transform 0.15s, box-shadow 0.15s;"
               onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.06)';"
               onmouseout="this.style.transform='none'; this.style.boxShadow='0 1px 3px rgba(0,0,0,0.02)';">
            <!-- Media / Watermark Area -->
            <div style="height:136px; position:relative; overflow:hidden; display:flex; align-items:center; justify-content:center; background:<?= $has_real_media ? '#0f172a' : 'repeating-linear-gradient(135deg, #f1f5f9, #f1f5f9 12px, #e8edf3 12px, #e8edf3 24px)' ?>;">
              <?php if ($has_real_media): ?>
                <?php if ($cover_is_video): ?>
                  <video src="<?= s($p['cover_url']) ?>" style="width:100%; height:100%; object-fit:cover;" preload="metadata" muted></video>
                  <div style="position:absolute; width:36px; height:36px; border-radius:50%; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; color:#fff; font-size:15px; pointer-events:none;">
                    <i class="fa fa-play" style="margin-left:2px;"></i>
                  </div>
                <?php else: ?>
                  <img src="<?= s($p['cover_url']) ?>" alt="<?= s($p['caption']) ?>" style="width:100%; height:100%; object-fit:cover;">
                <?php endif; ?>

                <!-- Date badge on top-left of real media -->
                <div style="position:absolute; top:8px; left:8px; background:rgba(15,23,42,0.85); color:#fff; font-size:11px; font-weight:700; padding:3px 9px; border-radius:6px; backdrop-filter:blur(3px); letter-spacing:0.3px; display:flex; align-items:center; gap:5px; box-shadow:0 1px 3px rgba(0,0,0,0.3);">
                  <i class="fa fa-calendar-o" style="font-size:10px;"></i>
                  <span><?= s($p['session_type']) ?> &middot; <?= s($p['session_date']) ?></span>
                </div>

                <!-- Multi-item count badge (e.g. 5 photos · 3 videos) -->
                <?php if ($items_cnt > 1): ?>
                  <div style="position:absolute; bottom:8px; right:8px; background:rgba(15,23,42,0.85); color:#fff; font-size:11px; font-weight:600; padding:3px 9px; border-radius:12px; display:flex; align-items:center; gap:6px; backdrop-filter:blur(3px); box-shadow:0 2px 4px rgba(0,0,0,0.3);">
                    <?php if ($photos_cnt > 0): ?>
                      <span><i class="fa fa-camera"></i> <?= $photos_cnt ?></span>
                    <?php endif; ?>
                    <?php if ($videos_cnt > 0): ?>
                      <span><i class="fa fa-video-camera"></i> <?= $videos_cnt ?></span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

              <?php else: ?>
                <div style="color:#94a3b8; font-size:13px; font-weight:500; letter-spacing:0.2px;">
                  <?= s($p['session_type']) ?> · <?= s($p['session_date']) ?>
                </div>
              <?php endif; ?>

              <?php if ($can_edit_mentor && !$is_prototype && !empty($p['items'])): ?>
                <button type="button" class="ba-delete-session-btn" data-session-key="<?= s($p['session_key'] ?? '') ?>" title="Delete All Evidence for this Date" style="position:absolute; top:6px; right:6px; width:26px; height:26px; border-radius:50%; background:rgba(255,255,255,0.9); border:1px solid #fecaca; color:#dc2626; display:flex; align-items:center; justify-content:center; font-size:11px; cursor:pointer;" onclick="event.stopPropagation();">
                  <i class="fa fa-trash"></i>
                </button>
              <?php endif; ?>
            </div>

            <!-- Footer Caption Bar -->
            <div style="height:44px; padding:8px 14px; background:#fff; display:flex; align-items:center; justify-content:space-between; border-top:1px solid #f1f5f9; gap:8px;">
              <span style="font-size:13px; font-weight:500; color:#334155; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= s($p['caption']) ?>">
                <?= s($p['caption']) ?>
              </span>
              <span style="font-size:11px; padding:2px 8px; border-radius:10px; background:#f8fafc; border:1px solid #e2e8f0; color:#64748b; font-weight:500; flex-shrink:0;">
                <?= s($p['session_type']) ?>
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 3. Student Attendance Matrix Panel (S1-S18 Grid with Check-in & Check-out Timings) -->
    <div id="panel-stdattendance" class="panel">
      <div class="panel-note" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
        <div style="font-size:13px; color:#475569;">
          <b>Day-wise biometric punch matrix</b> across delivered sessions (S1–S18). Live Check-In and Check-Out timestamps synchronized from biometric readers.
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <!-- View Mode Toggle (Compact is Default) -->
          <div style="display:inline-flex; border:1px solid #cbd5e1; border-radius:8px; overflow:hidden; background:#f8fafc; box-shadow:0 1px 2px rgba(0,0,0,0.03);">
            <button type="button" id="ba-att-view-compact" class="btn btn-sm" style="font-size:12px; font-weight:700; padding:6px 14px; background:#3b82f6; color:#fff; border:none; cursor:pointer; display:flex; align-items:center; gap:5px;">
              <i class="fa fa-th"></i> Compact (P/A)
            </button>
            <button type="button" id="ba-att-view-timings" class="btn btn-sm" style="font-size:12px; font-weight:600; padding:6px 14px; background:#fff; color:#475569; border:none; cursor:pointer; display:flex; align-items:center; gap:5px;">
              <i class="fa fa-clock-o"></i> Check-In &amp; Check-Out
            </button>
          </div>

          <a href="<?= (new moodle_url('/local/batchanalytics/module.php', [
              'batchid' => $batchid,
              'courseid' => $courseid,
              'action' => 'export_attendance_pdf'
          ]))->out(false) ?>" class="btn btn-outline-secondary btn-sm" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:6px 12px; border-radius:8px;">
            <i class="fa fa-file-pdf-o"></i> Export Matrix (PDF)
          </a>

          <?php if (!$is_sponsor_user): ?>
            <a href="<?= (new moodle_url('/local/attendancepdf/daily_attendance.php', ['courseid' => $courseid]))->out(false) ?>" target="_blank" class="btn btn-outline-primary btn-sm" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:6px 12px; border-radius:8px;">
              <i class="fa fa-external-link"></i> Daily Biometric Tracker
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Attendance Summary Bar -->
      <div style="display:flex; gap:16px; margin-bottom:18px; flex-wrap:wrap;">
        <div style="padding:10px 18px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; display:flex; align-items:center; gap:10px;">
          <div>
            <div style="font-size:11px; color:#166534; font-weight:700; text-transform:uppercase; letter-spacing:0.4px;">AVERAGE ATTENDANCE</div>
            <strong style="font-size:18px; color:#15803d;"><?= $attendance_matrix['average_attendance'] ?? 86 ?>%</strong>
          </div>
        </div>
        <div style="padding:10px 18px; background:<?= ($attendance_matrix['shortfall_count'] ?? 0) > 0 ? '#fef2f2; border:1px solid #fecaca;' : '#f8fafc; border:1px solid #e2e8f0;' ?>; border-radius:8px; display:flex; align-items:center; gap:10px;">
          <div>
            <div style="font-size:11px; color:<?= ($attendance_matrix['shortfall_count'] ?? 0) > 0 ? '#991b1b' : '#475569' ?>; font-weight:700; text-transform:uppercase; letter-spacing:0.4px;">ATTENDANCE SHORTFALL (&lt;75%)</div>
            <strong style="font-size:18px; color:<?= ($attendance_matrix['shortfall_count'] ?? 0) > 0 ? '#dc2626' : '#334155' ?>;"><?= (int)($attendance_matrix['shortfall_count'] ?? 0) ?> students</strong>
          </div>
        </div>
        <?php if (!$is_sponsor_user && !empty($daily_attendance_register['stats'])): ?>
          <?php $dst = $daily_attendance_register['stats']; ?>
          <div style="padding:10px 18px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; display:flex; align-items:center; gap:10px;">
            <div>
              <div style="font-size:11px; color:#1e40af; font-weight:700; text-transform:uppercase; letter-spacing:0.4px;">TODAY'S ATTENDANCE RATE</div>
              <strong style="font-size:18px; color:#1d4ed8;">
                <?= ($dst['total_students'] > 0) ? round(($dst['students_present'] / $dst['total_students']) * 100) : 0 ?>%
              </strong>
              <span style="font-size:12px; color:#60a5fa; font-weight:600; margin-left:4px;">(<?= (int)$dst['students_present'] ?> / <?= (int)$dst['total_students'] ?> present)</span>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Matrix Table Card -->
      <div class="tablecard ba-performance-tablecard" style="margin-bottom:28px;">
        <div class="ba-performance-table-scroll">
          <table class="ba-attendance-matrix-table" style="font-size:12px; text-align:center; width:100%; border-collapse:collapse;">
            <thead>
              <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                <th style="text-align:left; min-width:180px; padding:10px 14px; position:sticky; left:0; background:#f8fafc; z-index:2; border-right:1px solid #e2e8f0;">Student</th>
                <th style="width:75px; padding:10px 8px; border-right:1px solid #e2e8f0;">Att %</th>
                <?php foreach (($attendance_matrix['sessions'] ?? []) as $sess): ?>
                  <th class="ba-sess-col" style="min-width:44px; padding:6px 2px; border-right:1px solid #f1f5f9; cursor:pointer;" title="<?= s($sess['description'] ?? $sess['date']) ?> (Click to view Daily Register)" onclick="filterDailyRegister('<?= s($sess['raw_date'] ?? '') ?>');">
                    <div style="font-weight:700; color:#1e293b;"><?= s($sess['code']) ?></div>
                    <div style="font-size:9px; color:#64748b; font-weight:normal; margin-top:1px; text-transform:uppercase;"><?= s($sess['date']) ?></div>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach (($attendance_matrix['students'] ?? []) as $st): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                  <td style="text-align:left; font-weight:600; padding:8px 12px; position:sticky; left:0; background:#fff; z-index:1; border-right:1px solid #e2e8f0;">
                    <a href="<?= (new moodle_url('/local/batchanalytics/student.php', ['id' => ($st['id'] ?? ($st['userid'] ?? 0)), 'batchid' => $batchid]))->out(false) ?>" style="color:var(--primary,#0072ce); text-decoration:none;">
                      <?= s($st['name']) ?>
                    </a>
                  </td>
                  <td style="font-weight:700; padding:8px 6px; border-right:1px solid #e2e8f0; color:<?= $st['attendance_pct'] < 75 ? '#dc2626' : ($st['attendance_pct'] >= 85 ? '#16a34a' : '#d97706') ?>;">
                    <?= (int)$st['attendance_pct'] ?>%
                  </td>
                  <?php foreach (($attendance_matrix['sessions'] ?? []) as $sess): ?>
                    <?php
                      $p_raw = $st['sessions'][$sess['code']] ?? 'P';
                      $status = is_array($p_raw) ? ($p_raw['status'] ?? 'P') : (string)$p_raw;
                      $check_in = is_array($p_raw) ? ($p_raw['check_in'] ?? '--') : '--';
                      $check_out = is_array($p_raw) ? ($p_raw['check_out'] ?? '--') : '--';
                      $total_hrs = is_array($p_raw) ? ($p_raw['total_hours'] ?? '--') : '--';
                      $day_status = is_array($p_raw) ? ($p_raw['day_status'] ?? '') : '';
                      $remarks = is_array($p_raw) ? ($p_raw['remarks'] ?? '') : '';

                      // Badge styling
                      $badge_bg = '#dcfce7';
                      $badge_color = '#15803d';
                      if ($status === 'A') {
                          $badge_bg = '#fee2e2';
                          $badge_color = '#b91c1c';
                      } else if ($status === 'L') {
                          $badge_bg = '#fef3c7';
                          $badge_color = '#92400e';
                      } else if ($status === '—') {
                          $badge_bg = '#f1f5f9';
                          $badge_color = '#64748b';
                      }

                      $tooltip = htmlspecialchars("{$st['name']} · {$sess['code']} ({$sess['date']})\nCheck In: {$check_in}\nCheck Out: {$check_out}\nHours: {$total_hrs}\nStatus: {$day_status}\n{$remarks}");
                    ?>
                    <td class="ba-sess-cell" style="padding:4px 2px; border-right:1px solid #f8fafc; vertical-align:middle;" title="<?= $tooltip ?>">
                      
                      <!-- Compact View Mode (Default: Compact 22x22 badge) -->
                      <div class="ba-att-compact-block" style="display:inline-block;">
                        <span style="display:inline-block; width:22px; height:22px; line-height:22px; border-radius:4px; font-weight:700; font-size:11px; background:<?= $badge_bg ?>; color:<?= $badge_color ?>;">
                          <?= s($status) ?>
                        </span>
                      </div>

                      <!-- Timings View Mode (Hidden by default, shown when toggled) -->
                      <div class="ba-att-timing-block" style="display:none; flex-direction:column; align-items:center; gap:2px; padding:2px 0;">
                        <!-- Status Pill -->
                        <span style="display:inline-block; width:22px; height:20px; line-height:20px; border-radius:4px; font-weight:700; font-size:11px; background:<?= $badge_bg ?>; color:<?= $badge_color ?>;">
                          <?= s($status) ?>
                        </span>

                        <?php if ($status === 'P' || $status === 'L'): ?>
                          <div style="font-size:10px; font-weight:700; color:#166534; white-space:nowrap; line-height:1.2; margin-top:2px;">
                            <span style="color:#22c55e;">↓</span> <?= s($check_in) ?>
                          </div>
                          <?php if ($check_out !== '--'): ?>
                            <div style="font-size:10px; font-weight:700; color:#1e40af; white-space:nowrap; line-height:1.2;">
                              <span style="color:#3b82f6;">↑</span> <?= s($check_out) ?>
                            </div>
                          <?php endif; ?>
                          <?php if ($total_hrs !== '--'): ?>
                            <span style="font-size:9px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:0 4px; color:#475569; font-weight:600; margin-top:1px;">
                              <?= s($total_hrs) ?>
                            </span>
                          <?php endif; ?>
                        <?php elseif ($status === 'A'): ?>
                          <div style="font-size:10px; color:#dc2626; font-weight:700; line-height:1.2; margin-top:3px;">
                            Absent
                          </div>
                        <?php else: ?>
                          <div style="color:#94a3b8; font-size:12px; margin-top:2px;">—</div>
                        <?php endif; ?>
                      </div>

                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if (!$is_sponsor_user): ?>
      <!-- ===================================================================== -->
      <!-- DAILY BIOMETRIC PUNCH REGISTER (Added from biometric plugin)           -->
      <!-- ===================================================================== -->
      <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.03); overflow:hidden;">
        <!-- Register Header Bar -->
        <div style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
          <div>
            <div style="display:flex; align-items:center; gap:8px;">
              <span style="font-size:18px;">📅</span>
              <h3 style="margin:0; font-size:15px; font-weight:700; color:#1e293b;">
                Daily Biometric Punch Register
              </h3>
              <span style="font-size:12px; font-weight:600; color:#3b82f6; background:#eff6ff; border:1px solid #bfdbfe; padding:2px 8px; border-radius:12px;">
                <?= s($daily_attendance_register['date_formatted'] ?? date('d-M-Y')) ?> (<?= s($daily_attendance_register['day_name'] ?? date('l')) ?>)
              </span>
            </div>
            <div style="font-size:12px; color:#64748b; margin-top:3px;">
              Detailed biometric terminal punch logs, check-in, check-out and work hours for this session date.
            </div>
          </div>

          <!-- Date Selector Form -->
          <form method="get" action="module.php" style="margin:0; display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="courseid" value="<?= (int)$courseid ?>">
            <input type="hidden" name="batchid" value="<?= (int)$batchid ?>">
            <input type="hidden" name="sectionid" value="<?= (int)$sec_id ?>">
            <input type="hidden" name="module" value="<?= (int)$module_idx ?>">
            <input type="hidden" name="mode" value="<?= s($deliverymode) ?>">
            
            <label style="font-size:12px; font-weight:700; color:#475569; margin:0;">Session Date:</label>
            <input type="date" name="att_date" value="<?= s($att_target_date) ?>" class="form-control form-control-sm" style="width:145px; font-size:12px; font-weight:600; border-radius:6px; border:1px solid #cbd5e1;" onchange="this.form.submit();">
            <button type="submit" class="btn btn-sm btn-primary" style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:6px;">Go</button>
          </form>
        </div>

        <!-- Quick Session Date Buttons -->
        <?php if (!empty($attendance_matrix['sessions'])): ?>
          <div style="padding:10px 20px; background:#fff; border-bottom:1px solid #f1f5f9; display:flex; gap:8px; overflow-x:auto; align-items:center;">
            <span style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; flex-shrink:0;">Select Session:</span>
            <?php foreach ($attendance_matrix['sessions'] as $s_pill): ?>
              <?php
                $is_curr_pill = ($s_pill['raw_date'] ?? '') === $att_target_date;
                $pill_url = new moodle_url('/local/batchanalytics/module.php', [
                    'courseid' => $courseid,
                    'batchid' => $batchid,
                    'sectionid' => $sec_id,
                    'module' => $module_idx,
                    'mode' => $deliverymode,
                    'att_date' => $s_pill['raw_date'] ?? '',
                ]);
                $pill_url->set_anchor('panel-stdattendance');
              ?>
              <a href="<?= $pill_url->out(false) ?>" style="font-size:11px; font-weight:700; padding:3px 9px; border-radius:6px; text-decoration:none; white-space:nowrap; <?= $is_curr_pill ? 'background:#3b82f6; color:#fff;' : 'background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;' ?>">
                <?= s($s_pill['code']) ?> (<?= s($s_pill['date']) ?>)
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Daily Register Table -->
        <div class="table-responsive" style="margin:0;">
          <table class="table table-hover" style="font-size:12px; margin-bottom:0; width:100%; border-collapse:collapse;">
            <thead style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
              <tr>
                <th style="padding:10px 14px; width:45px; text-align:center;">#</th>
                <th style="padding:10px 14px;">Student Name</th>
                <th style="padding:10px 14px; width:130px;">Day &amp; Date</th>
                <th style="padding:10px 14px; text-align:center; width:120px;">Check In</th>
                <th style="padding:10px 14px; text-align:center; width:120px;">Check Out</th>
                <th style="padding:10px 14px; text-align:center; width:110px;">Total Hours</th>
                <th style="padding:10px 14px; text-align:center; width:130px;">Day Status</th>
                <th style="padding:10px 14px;">Remarks</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($daily_attendance_register['students'])): ?>
                <tr>
                  <td colspan="8" style="padding:28px; text-align:center; color:#64748b;">
                    <em>No student punch records found for <?= s($daily_attendance_register['date_formatted'] ?? $att_target_date) ?>.</em>
                  </td>
                </tr>
              <?php else: ?>
                <?php $reg_idx = 1; ?>
                <?php foreach ($daily_attendance_register['students'] as $rs): ?>
                  <?php
                    $stat_bg = '#fee2e2';
                    $stat_color = '#991b1b';
                    if ($rs['day_status'] === 'Full Day' || $rs['day_status'] === 'Present (Manual)') {
                        $stat_bg = '#dcfce7';
                        $stat_color = '#166534';
                    } else if (strpos($rs['day_status'], 'Late') !== false) {
                        $stat_bg = '#fef3c7';
                        $stat_color = '#92400e';
                    } else if (strpos($rs['day_status'], 'Half Day') !== false) {
                        $stat_bg = '#ffedd5';
                        $stat_color = '#9a3412';
                    }
                  ?>
                  <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:10px 14px; text-align:center; color:#64748b; font-weight:600;"><?= $reg_idx++ ?></td>
                    <td style="padding:10px 14px;">
                      <strong style="color:#0f172a; font-size:13px;"><?= s($rs['name']) ?></strong>
                      <div style="font-size:11px; color:#64748b; font-family:monospace; margin-top:2px;">
                        PIN: <strong><?= s($rs['pin']) ?></strong><?= !empty($rs['email']) ? ' &middot; ' . s($rs['email']) : '' ?>
                      </div>
                    </td>
                    <td style="padding:10px 14px; color:#334155; font-weight:600;">
                      <?= s($daily_attendance_register['day_name'] ?? '') ?><br/>
                      <span style="font-size:11px; color:#64748b; font-weight:normal;"><?= s($daily_attendance_register['date_formatted'] ?? '') ?></span>
                    </td>
                    <td style="padding:10px 14px; text-align:center;">
                      <?php if (!empty($rs['check_in']) && $rs['check_in'] !== '--'): ?>
                        <span style="font-weight:700; color:#166534; font-size:13px;">🟢 <?= s($rs['check_in']) ?></span>
                      <?php else: ?>
                        <span style="color:#94a3b8; font-weight:600;">--</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:10px 14px; text-align:center;">
                      <?php if (!empty($rs['check_out']) && $rs['check_out'] !== '--'): ?>
                        <span style="font-weight:700; color:#1e40af; font-size:13px;">🔵 <?= s($rs['check_out']) ?></span>
                      <?php else: ?>
                        <span style="color:#94a3b8; font-weight:600;">--</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:10px 14px; text-align:center;">
                      <?php if (!empty($rs['total_hours']) && $rs['total_hours'] !== '--'): ?>
                        <span style="background:#f8fafc; color:#0f172a; border:1px solid #cbd5e1; font-weight:700; font-size:12px; padding:3px 8px; border-radius:6px; display:inline-block;">
                          <?= s($rs['total_hours']) ?>
                        </span>
                      <?php else: ?>
                        <span style="color:#94a3b8;">--</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:10px 14px; text-align:center;">
                      <span style="background:<?= $stat_bg ?>; color:<?= $stat_color ?>; font-weight:700; font-size:11px; padding:4px 10px; border-radius:12px; display:inline-block;">
                        <?= s($rs['day_status']) ?>
                      </span>
                    </td>
                    <td style="padding:10px 14px; font-size:12px; color:#475569;">
                      <?= s($rs['remarks']) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>

    <!-- 1. Mentor Activities Panel (Operational Mentor Activities) -->
    <div id="panel-mentor" class="panel active">
      <div class="panel-note" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
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
    <?php endif; ?>

    <!-- Student Performance Panel -->
    <div id="panel-mstudents" class="panel">
      <div class="panel-note">
        Module-level student performance — same view as batch level, scoped to this module. Grade or Completion % banding.
      </div>

      <div class="filterbar">
        <?php if (!$is_sponsor): ?>
        <div class="toggle">
          <span class="on" id="ba-mod-tg-grade">Grade</span>
          <span id="ba-mod-tg-pct">Completion %</span>
        </div>
        <?php else: ?>
        <div class="toggle">
          <span class="on" id="ba-mod-tg-pct">Completion %</span>
        </div>
        <?php endif; ?>
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
                <?php if (!$is_sponsor): ?>
                <th class="sortable ba-performance-overall-head" data-sort="grade" title="Sort by Grade">Grade</th>
                <?php endif; ?>
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

<?php if ($can_edit_mentor): ?>
<!-- Modal: Add Lesson Plan Day -->
<div id="ba-lesson-plan-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:10px; max-width:540px; width:92%; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); position:relative;">
    <h3 style="margin-top:0; font-size:17px; font-weight:700; color:#1e293b;">Log Day-Wise Lesson Plan</h3>
    <form id="ba-form-lesson-plan">
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Day Number</label>
          <input type="number" id="lp-day-index" class="form-control" value="<?= count($lesson_plan_days ?? []) + 1 ?>" min="1" max="100" required style="width:100%;">
        </div>
        <div>
          <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Status</label>
          <select id="lp-status" class="form-control" style="width:100%;">
            <option value="Done">Done</option>
            <option value="Pending" selected>Pending</option>
          </select>
        </div>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
        <div>
          <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Planned Date</label>
          <input type="text" id="lp-planned-date" class="form-control" placeholder="e.g. 16 Nov 2026" required style="width:100%;">
        </div>
        <div>
          <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Actual Date</label>
          <input type="text" id="lp-actual-date" class="form-control" placeholder="e.g. 16 Nov 2026" style="width:100%;">
        </div>
      </div>
      <div style="margin-bottom:12px;">
        <label style="font-size:12px; font-weight:600; color:#475569; display:block; margin-bottom:4px;">Topic / Syllabus Coverage</label>
        <textarea id="lp-topic" class="form-control" rows="2" placeholder="Details of syllabus delivered..." required style="width:100%;"></textarea>
      </div>
      <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:10px 14px; margin-bottom:18px; font-size:12px; color:#166534; display:flex; align-items:center; gap:8px;">
        <i class="fa fa-fingerprint" style="font-size:18px; color:#16a34a; flex-shrink:0;"></i>
        <span>Class &amp; Lab Mentor attendance is automatically verified from the <b>ESSCI Biometric Attendance Report</b> for the session date.</span>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" class="btn btn-secondary" id="ba-close-lp-modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="ba-save-lp-btn">Save Session</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Upload Class / Lab Photos & Videos (Multiple Support) -->
<div id="ba-evidence-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; backdrop-filter:blur(2px);">
  <div style="background:#fff; border-radius:14px; max-width:540px; width:92%; padding:24px 28px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); position:relative;">
    <!-- Modal Header -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <div style="display:flex; align-items:center; gap:8px; font-size:16px; font-weight:700; color:#1e293b;">
        <i class="fa fa-camera" style="font-size:16px; color:#475569;"></i>
        <span>Upload class / lab photos</span>
      </div>
      <button type="button" id="ba-close-ev-modal-x" style="background:none; border:none; font-size:22px; color:#94a3b8; cursor:pointer; line-height:1; padding:0 4px;" title="Close">&times;</button>
    </div>

    <form id="ba-form-evidence" enctype="multipart/form-data">
      <!-- Row 1: Session Type & Session Date -->
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div>
          <label style="font-size:11px; font-weight:700; color:#475569; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:6px; display:block;">SESSION TYPE</label>
          <select id="ev-session-type" class="form-control" style="width:100%; border-radius:8px; border:1px solid #cbd5e1; height:40px; font-size:13px; color:#1e293b; background:#fff;">
            <option value="Class" selected>Class</option>
            <option value="Lab">Lab</option>
          </select>
        </div>
        <div>
          <label style="font-size:11px; font-weight:700; color:#475569; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:6px; display:block;">SESSION DATE</label>
          <input type="date" id="ev-session-date" class="form-control" value="<?= date('Y-m-d') ?>" style="width:100%; border-radius:8px; border:1px solid #cbd5e1; height:40px; font-size:13px; color:#1e293b; background:#fff;">
        </div>
      </div>

      <!-- Drag & Drop Upload Zone -->
      <div id="ev-dropzone" style="border:1.5px dashed #cbd5e1; border-radius:10px; padding:28px 20px; text-align:center; background:#fbfcfe; margin-bottom:16px; cursor:pointer; transition:all 0.2s;">
        <div style="margin-bottom:8px;">
          <svg width="44" height="44" viewBox="0 0 48 48" fill="none" style="display:inline-block;">
            <rect x="8" y="22" width="32" height="18" rx="4" fill="#ede9fe" stroke="#a78bfa" stroke-width="2"/>
            <path d="M8 29H18C19.2 29 20.2 30.2 20.8 31.5C21.2 32.4 22.1 33 23 33H25C25.9 33 26.8 32.4 27.2 31.5C27.8 30.2 28.8 29 30 29H40" stroke="#818cf8" stroke-width="2" fill="#e0e7ff"/>
            <path d="M24 24V9M24 9L17.5 15.5M24 9L30.5 15.5" stroke="#ef4444" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div style="font-size:14px; font-weight:700; color:#1e293b;" id="ev-dropzone-prompt">Drag &amp; drop photos here</div>
        <div style="font-size:12px; color:#64748b; margin-top:4px;">or click to choose files &middot; JPG, PNG, MP4, WEBM</div>
        <input type="file" id="ev-file" name="evidence_files[]" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime" style="display:none;">
        <div id="ev-selected-files-list" style="margin-top:14px; display:none; max-height:120px; overflow-y:auto; text-align:left; flex-direction:column; gap:6px;"></div>
      </div>

      <!-- Row 2: Caption (Optional) -->
      <div style="margin-bottom:20px;">
        <label style="font-size:11px; font-weight:700; color:#475569; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:6px; display:block;">CAPTION (OPTIONAL)</label>
        <input type="text" id="ev-caption" class="form-control" placeholder="e.g. Pointers class &middot; lab batch A" style="width:100%; border-radius:8px; border:1px solid #cbd5e1; height:40px; font-size:13px; color:#1e293b; background:#fff;">
      </div>

      <!-- Modal Footer -->
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn" id="ba-close-ev-modal" style="border:1px solid #cbd5e1; background:#f8fafc; color:#334155; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; cursor:pointer;">Cancel</button>
        <button type="submit" class="btn btn-primary" id="ba-upload-ev-btn" style="background:#4f87e8; border:none; color:#fff; font-size:13px; font-weight:600; padding:8px 24px; border-radius:8px; cursor:pointer;">Upload photos</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Lightbox Modal for Photo / Video Session Album Preview -->
<div id="ba-lightbox-modal" style="display:none; position:fixed; z-index:999999; left:0; top:0; width:100%; height:100%; background:rgba(15,23,42,0.92); align-items:center; justify-content:center; backdrop-filter:blur(6px);">
  <div style="position:relative; max-width:960px; width:94%; max-height:95vh; display:flex; flex-direction:column; background:#1e293b; border-radius:14px; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);">
    
    <!-- Top Header Bar inside Lightbox -->
    <div style="padding:14px 20px; background:#0f172a; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #334155;">
      <div>
        <div style="display:flex; align-items:center; gap:8px;">
          <span id="ba-lightbox-type-pill" style="font-size:11px; font-weight:700; padding:2px 8px; border-radius:10px; background:#3b82f6; color:#fff; text-transform:uppercase;">Class</span>
          <span id="ba-lightbox-session-title" style="font-size:15px; font-weight:700; color:#fff;">17 Nov 2026</span>
        </div>
        <div id="ba-lightbox-session-caption" style="font-size:12px; color:#94a3b8; margin-top:2px;">Pointers class · lab batch A</div>
      </div>
      <div style="display:flex; align-items:center; gap:12px;">
        <span id="ba-lightbox-counter-badge" style="font-size:12px; font-weight:600; color:#cbd5e1; background:#334155; padding:3px 10px; border-radius:12px;">1 / 8</span>
        <button type="button" id="ba-close-lightbox" style="background:none; border:none; font-size:24px; color:#cbd5e1; cursor:pointer; line-height:1; padding:0 4px;" title="Close">&times;</button>
      </div>
    </div>

    <!-- Main Media Showcase Viewport -->
    <div style="position:relative; width:100%; height:55vh; min-height:360px; background:#000; display:flex; align-items:center; justify-content:center; overflow:hidden;">
      <!-- Prev Button -->
      <button type="button" id="ba-lightbox-prev-btn" style="position:absolute; left:16px; z-index:10; width:42px; height:42px; border-radius:50%; background:rgba(15,23,42,0.75); border:1px solid rgba(255,255,255,0.2); color:#fff; font-size:18px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;" title="Previous (Left arrow)">
        <i class="fa fa-chevron-left"></i>
      </button>

      <!-- Media Display Area -->
      <div id="ba-lightbox-media-container" style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; overflow:hidden;"></div>

      <!-- Next Button -->
      <button type="button" id="ba-lightbox-next-btn" style="position:absolute; right:16px; z-index:10; width:42px; height:42px; border-radius:50%; background:rgba(15,23,42,0.75); border:1px solid rgba(255,255,255,0.2); color:#fff; font-size:18px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;" title="Next (Right arrow)">
        <i class="fa fa-chevron-right"></i>
      </button>
    </div>

    <!-- Info & Controls Strip below Main Media -->
    <div style="padding:10px 20px; background:#0f172a; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #1e293b;">
      <div id="ba-lightbox-current-filename" style="font-size:12px; color:#cbd5e1; font-weight:500; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:400px;"></div>
      <div style="display:flex; align-items:center; gap:8px;">
        <a id="ba-lightbox-download-btn" href="#" download class="btn btn-sm btn-primary" style="padding:4px 12px; font-size:12px; font-weight:600; border-radius:6px; display:inline-flex; align-items:center; gap:6px; background:#3b82f6; border:none; color:#fff; text-decoration:none;">
          <i class="fa fa-download"></i> Download File
        </a>
        <?php if ($can_edit_mentor): ?>
          <button type="button" id="ba-lightbox-delete-current-btn" class="btn btn-sm btn-outline-danger" style="padding:4px 10px; font-size:12px; font-weight:600; border-radius:6px; display:inline-flex; align-items:center; gap:4px; background:rgba(239,68,68,0.1); border:1px solid #ef4444; color:#ef4444; cursor:pointer;" title="Delete this file">
            <i class="fa fa-trash"></i> Delete
          </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Bottom Thumbnail Filmstrip (All files in this session) -->
    <div id="ba-lightbox-filmstrip" style="padding:12px 20px; background:#1e293b; display:flex; gap:10px; overflow-x:auto; border-top:1px solid #334155;"></div>

  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const lpModal = document.getElementById('ba-lesson-plan-modal');
  const evModal = document.getElementById('ba-evidence-modal');
  const openLpBtn = document.getElementById('ba-open-lesson-plan-modal');
  const closeLpBtn = document.getElementById('ba-close-lp-modal');
  const openEvCard = document.getElementById('ba-upload-evidence-card');
  const openEvBtn = document.getElementById('ba-open-evidence-modal');
  const closeEvBtn = document.getElementById('ba-close-ev-modal');
  const closeEvBtnX = document.getElementById('ba-close-ev-modal-x');
  const sesskey = '<?= sesskey() ?>';
  const batchId = <?= (int)$batchid ?>;
  const courseId = <?= (int)$courseid ?>;

  if (openLpBtn && lpModal) {
    openLpBtn.addEventListener('click', () => { lpModal.style.display = 'flex'; });
  }
  if (closeLpBtn && lpModal) {
    closeLpBtn.addEventListener('click', () => { lpModal.style.display = 'none'; });
  }

  // Open Evidence Upload Modal from Card or Header button
  function openEvidenceModal() {
    if (evModal) {
      evModal.style.display = 'flex';
      selectedEvidenceFiles = [];
      renderSelectedFiles();
    }
  }
  if (openEvCard) {
    openEvCard.addEventListener('click', openEvidenceModal);
  }
  if (openEvBtn) {
    openEvBtn.addEventListener('click', openEvidenceModal);
  }

  function closeEvidenceModal() {
    if (evModal) {
      evModal.style.display = 'none';
      selectedEvidenceFiles = [];
      renderSelectedFiles();
    }
  }
  if (closeEvBtn) closeEvBtn.addEventListener('click', closeEvidenceModal);
  if (closeEvBtnX) closeEvBtnX.addEventListener('click', closeEvidenceModal);

  // Drag & drop and multiple file selection handling
  let selectedEvidenceFiles = [];
  const dropzone = document.getElementById('ev-dropzone');
  const fileInput = document.getElementById('ev-file');
  const filesListContainer = document.getElementById('ev-selected-files-list');

  function renderSelectedFiles() {
    if (!filesListContainer) return;
    if (selectedEvidenceFiles.length === 0) {
      filesListContainer.style.display = 'none';
      filesListContainer.innerHTML = '';
      return;
    }
    filesListContainer.style.display = 'flex';
    filesListContainer.innerHTML = '';
    selectedEvidenceFiles.forEach((f, idx) => {
      const isVid = f.type.startsWith('video/') || /\.(mp4|webm|mov|m4v)$/i.test(f.name);
      const icon = isVid ? 'fa fa-video-camera' : 'fa fa-image';
      const sizeStr = (f.size / (1024 * 1024)).toFixed(1) + ' MB';

      const chip = document.createElement('div');
      chip.style.cssText = 'display:flex; align-items:center; justify-content:space-between; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:6px 10px; font-size:12px;';
      chip.innerHTML = `
        <div style="display:flex; align-items:center; gap:8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
          <i class="${icon}" style="color:${isVid ? '#8b5cf6' : '#3b82f6'};"></i>
          <span style="font-weight:600; color:#1e293b;">${f.name}</span>
          <span style="color:#94a3b8; font-size:11px;">(${sizeStr})</span>
        </div>
        <button type="button" data-remove-idx="${idx}" style="background:none; border:none; color:#ef4444; font-size:14px; cursor:pointer; padding:0 4px; line-height:1;" title="Remove">&times;</button>
      `;
      chip.querySelector('button').addEventListener('click', function(e) {
        e.stopPropagation();
        const rIdx = parseInt(this.getAttribute('data-remove-idx'), 10);
        selectedEvidenceFiles.splice(rIdx, 1);
        renderSelectedFiles();
      });
      filesListContainer.appendChild(chip);
    });
  }

  if (dropzone && fileInput) {
    dropzone.addEventListener('click', function(e) {
      if (e.target.tagName !== 'BUTTON' && !e.target.closest('button')) {
        fileInput.click();
      }
    });

    dropzone.addEventListener('dragover', function(e) {
      e.preventDefault();
      dropzone.style.borderColor = '#3b82f6';
      dropzone.style.background = '#eff6ff';
    });
    ['dragleave', 'dragend'].forEach(ev => {
      dropzone.addEventListener(ev, function(e) {
        e.preventDefault();
        dropzone.style.borderColor = '#cbd5e1';
        dropzone.style.background = '#fbfcfe';
      });
    });

    dropzone.addEventListener('drop', function(e) {
      e.preventDefault();
      dropzone.style.borderColor = '#cbd5e1';
      dropzone.style.background = '#fbfcfe';
      if (e.dataTransfer && e.dataTransfer.files) {
        for (let i = 0; i < e.dataTransfer.files.length; i++) {
          selectedEvidenceFiles.push(e.dataTransfer.files[i]);
        }
        renderSelectedFiles();
      }
    });

    fileInput.addEventListener('change', function() {
      for (let i = 0; i < this.files.length; i++) {
        selectedEvidenceFiles.push(this.files[i]);
      }
      this.value = '';
      renderSelectedFiles();
    });
  }

  // Handle lesson plan submission
  const lpForm = document.getElementById('ba-form-lesson-plan');
  if (lpForm) {
    lpForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const saveBtn = document.getElementById('ba-save-lp-btn');
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';
      const fd = new FormData();
      fd.append('sesskey', sesskey);
      fd.append('action', 'add_lesson_plan_day');
      fd.append('batchid', batchId);
      fd.append('sectionid', <?= (int)($section ? $section->id : $batchid) ?>);
      fd.append('courseid', courseId);
      fd.append('day_index', document.getElementById('lp-day-index').value);
      fd.append('status', document.getElementById('lp-status').value);
      fd.append('planned_date', document.getElementById('lp-planned-date').value);
      fd.append('actual_date', document.getElementById('lp-actual-date').value);
      fd.append('topic', document.getElementById('lp-topic').value);

      fetch('module.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            window.location.reload();
          } else {
            alert('Error: ' + (data.error || 'Failed to save lesson plan day'));
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Session';
          }
        })
        .catch(err => {
          alert('Network error saving session');
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save Session';
        });
    });
  }

  // Handle lesson plan deletion
  document.querySelectorAll('.ba-delete-lesson-plan-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      if (!confirm('Are you sure you want to delete this lesson plan day?')) return;
      const dayId = this.getAttribute('data-dayid');
      const fd = new FormData();
      fd.append('sesskey', sesskey);
      fd.append('action', 'delete_lesson_plan_day');
      fd.append('dayid', dayId);
      fetch('module.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            window.location.reload();
          } else {
            alert('Error deleting day');
          }
        });
    });
  });

  // Handle photo/video evidence submission
  const evForm = document.getElementById('ba-form-evidence');
  if (evForm) {
    evForm.addEventListener('submit', function(e) {
      e.preventDefault();
      if (selectedEvidenceFiles.length === 0) {
        alert('Please select or drag & drop at least one photo or video to upload.');
        return;
      }
      const uploadBtn = document.getElementById('ba-upload-ev-btn');
      uploadBtn.disabled = true;
      uploadBtn.textContent = `Uploading (${selectedEvidenceFiles.length} files)...`;
      const fd = new FormData();
      fd.append('sesskey', sesskey);
      fd.append('action', 'upload_evidence');
      fd.append('batchid', batchId);
      fd.append('sectionid', <?= (int)($section ? $section->id : $batchid) ?>);
      fd.append('courseid', courseId);
      fd.append('session_type', document.getElementById('ev-session-type').value);
      fd.append('session_date', document.getElementById('ev-session-date').value);
      fd.append('caption', document.getElementById('ev-caption').value);

      selectedEvidenceFiles.forEach(file => {
        fd.append('evidence_files[]', file);
      });

      fetch('module.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            window.location.hash = 'panel-evidence';
            window.location.reload();
          } else {
            alert('Error: ' + (data.error || 'Failed to upload photo evidence'));
            uploadBtn.disabled = false;
            uploadBtn.textContent = 'Upload photos';
          }
        })
        .catch(err => {
          alert('Network error uploading photo');
          uploadBtn.disabled = false;
          uploadBtn.textContent = 'Upload photos';
        });
    });
  }

  // Handle entire session evidence deletion
  document.querySelectorAll('.ba-delete-session-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      const sessKey = this.getAttribute('data-session-key');
      if (!confirm('Are you sure you want to delete all photos and videos for this date?')) return;
      const fd = new FormData();
      fd.append('sesskey', sesskey);
      fd.append('action', 'delete_evidence');
      fd.append('session_key', sessKey);
      fd.append('batchid', batchId);
      fd.append('sectionid', <?= (int)($section ? $section->id : $batchid) ?>);
      fd.append('courseid', courseId);
      fetch('module.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            window.location.hash = 'panel-evidence';
            window.location.reload();
          } else {
            alert('Error deleting session evidence');
          }
        });
    });
  });

  // Lightbox & Session Album handling
  let currentSessionItems = [];
  let currentItemIndex = 0;

  const lightboxModal = document.getElementById('ba-lightbox-modal');
  const lightboxMedia = document.getElementById('ba-lightbox-media-container');
  const lightboxTitle = document.getElementById('ba-lightbox-session-title');
  const lightboxCaption = document.getElementById('ba-lightbox-session-caption');
  const lightboxTypePill = document.getElementById('ba-lightbox-type-pill');
  const lightboxCounter = document.getElementById('ba-lightbox-counter-badge');
  const lightboxFilename = document.getElementById('ba-lightbox-current-filename');
  const lightboxDownload = document.getElementById('ba-lightbox-download-btn');
  const lightboxDeleteBtn = document.getElementById('ba-lightbox-delete-current-btn');
  const lightboxFilmstrip = document.getElementById('ba-lightbox-filmstrip');
  const closeLightboxBtn = document.getElementById('ba-close-lightbox');
  const prevBtn = document.getElementById('ba-lightbox-prev-btn');
  const nextBtn = document.getElementById('ba-lightbox-next-btn');

  function renderShowcaseItem(idx) {
    if (!currentSessionItems || currentSessionItems.length === 0) return;
    if (idx < 0) idx = currentSessionItems.length - 1;
    if (idx >= currentSessionItems.length) idx = 0;
    currentItemIndex = idx;

    const item = currentSessionItems[currentItemIndex];
    lightboxCounter.textContent = `${currentItemIndex + 1} / ${currentSessionItems.length}`;
    lightboxFilename.textContent = item.caption || item.filename || '';
    lightboxDownload.href = item.fileurl;

    if (item.is_video) {
      lightboxMedia.innerHTML = `<video src="${item.fileurl}" controls autoplay style="max-height:55vh; max-width:100%; border-radius:8px;"></video>`;
    } else {
      lightboxMedia.innerHTML = `<img src="${item.fileurl}" alt="${item.caption || item.filename}" style="max-height:55vh; max-width:100%; object-fit:contain; border-radius:8px;">`;
    }

    // Update filmstrip highlight
    if (lightboxFilmstrip) {
      const thumbs = lightboxFilmstrip.querySelectorAll('.ba-filmstrip-thumb');
      thumbs.forEach((t, i) => {
        if (i === currentItemIndex) {
          t.style.borderColor = '#3b82f6';
          t.style.opacity = '1';
        } else {
          t.style.borderColor = 'transparent';
          t.style.opacity = '0.65';
        }
      });
    }

    if (prevBtn) prevBtn.style.display = currentSessionItems.length > 1 ? 'flex' : 'none';
    if (nextBtn) nextBtn.style.display = currentSessionItems.length > 1 ? 'flex' : 'none';
  }

  function openSessionAlbum(sessionData) {
    const items = sessionData.items || [];
    if (items.length === 0) return;

    currentSessionItems = items;
    currentItemIndex = 0;

    lightboxTitle.textContent = `${sessionData.sessionDate}`;
    lightboxCaption.textContent = sessionData.caption || '';
    lightboxTypePill.textContent = sessionData.sessionType;

    // Render filmstrip
    if (lightboxFilmstrip) {
      lightboxFilmstrip.innerHTML = '';
      if (items.length > 1) {
        lightboxFilmstrip.style.display = 'flex';
        items.forEach((it, idx) => {
          const thumb = document.createElement('div');
          thumb.className = 'ba-filmstrip-thumb';
          thumb.style.cssText = 'width:64px; height:64px; border-radius:8px; overflow:hidden; border:2px solid transparent; flex-shrink:0; cursor:pointer; position:relative; background:#000; transition:all 0.15s;';
          if (it.is_video) {
            thumb.innerHTML = `
              <video src="${it.fileurl}" style="width:100%; height:100%; object-fit:cover; pointer-events:none;"></video>
              <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.3); color:#fff; font-size:12px;">
                <i class="fa fa-play"></i>
              </div>
            `;
          } else {
            thumb.innerHTML = `<img src="${it.fileurl}" style="width:100%; height:100%; object-fit:cover; pointer-events:none;">`;
          }
          thumb.addEventListener('click', () => {
            renderShowcaseItem(idx);
          });
          lightboxFilmstrip.appendChild(thumb);
        });
      } else {
        lightboxFilmstrip.style.display = 'none';
      }
    }

    renderShowcaseItem(0);
    lightboxModal.style.display = 'flex';
  }

  // Click on date card -> open session album
  document.querySelectorAll('.ba-evidence-card').forEach(card => {
    card.addEventListener('click', function() {
      const rawItems = this.getAttribute('data-items');
      let items = [];
      try {
        items = JSON.parse(rawItems || '[]');
      } catch (e) {
        items = [];
      }
      if (items.length === 0) return;

      openSessionAlbum({
        sessionKey: this.getAttribute('data-session-key') || '',
        sessionType: this.getAttribute('data-type') || '',
        sessionDate: this.getAttribute('data-date') || '',
        caption: this.getAttribute('data-caption') || '',
        items: items
      });
    });
  });

  // Prev / Next button handlers
  if (prevBtn) {
    prevBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      renderShowcaseItem(currentItemIndex - 1);
    });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      renderShowcaseItem(currentItemIndex + 1);
    });
  }

  // Keyboard navigation for Lightbox
  document.addEventListener('keydown', function(e) {
    if (!lightboxModal || lightboxModal.style.display !== 'flex') return;
    if (e.key === 'ArrowLeft') {
      renderShowcaseItem(currentItemIndex - 1);
    } else if (e.key === 'ArrowRight') {
      renderShowcaseItem(currentItemIndex + 1);
    } else if (e.key === 'Escape') {
      closeLightbox();
    }
  });

  // Delete current file inside lightbox
  if (lightboxDeleteBtn) {
    lightboxDeleteBtn.addEventListener('click', function() {
      if (!currentSessionItems || currentSessionItems.length === 0) return;
      const currentItem = currentSessionItems[currentItemIndex];
      if (!confirm(`Are you sure you want to delete this ${currentItem.is_video ? 'video' : 'photo'}?`)) return;

      const fd = new FormData();
      fd.append('sesskey', sesskey);
      fd.append('action', 'delete_evidence');
      fd.append('evidenceid', currentItem.id);
      fetch('module.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            window.location.hash = 'panel-evidence';
            window.location.reload();
          } else {
            alert('Error deleting item');
          }
        });
    });
  }

  function closeLightbox() {
    if (lightboxModal) {
      lightboxModal.style.display = 'none';
      if (lightboxMedia) lightboxMedia.innerHTML = '';
      currentSessionItems = [];
    }
  }

  if (closeLightboxBtn && lightboxModal) {
    closeLightboxBtn.addEventListener('click', closeLightbox);
    lightboxModal.addEventListener('click', function(e) {
      if (e.target === lightboxModal) {
        closeLightbox();
      }
    });
  }

  // Attendance Matrix View Mode Toggle (Timings vs Compact)
  const attTimingsBtn = document.getElementById('ba-att-view-timings');
  const attCompactBtn = document.getElementById('ba-att-view-compact');
  if (attTimingsBtn && attCompactBtn) {
    attTimingsBtn.addEventListener('click', function() {
      document.querySelectorAll('.ba-att-timing-block').forEach(el => el.style.display = 'flex');
      document.querySelectorAll('.ba-att-compact-block').forEach(el => el.style.display = 'none');
      document.querySelectorAll('.ba-sess-col').forEach(el => el.style.minWidth = '96px');
      attTimingsBtn.style.background = '#3b82f6';
      attTimingsBtn.style.color = '#fff';
      attTimingsBtn.style.fontWeight = '700';
      attCompactBtn.style.background = '#fff';
      attCompactBtn.style.color = '#475569';
      attCompactBtn.style.fontWeight = '600';
    });
    attCompactBtn.addEventListener('click', function() {
      document.querySelectorAll('.ba-att-timing-block').forEach(el => el.style.display = 'none');
      document.querySelectorAll('.ba-att-compact-block').forEach(el => el.style.display = 'inline-block');
      document.querySelectorAll('.ba-sess-col').forEach(el => el.style.minWidth = '44px');
      attCompactBtn.style.background = '#3b82f6';
      attCompactBtn.style.color = '#fff';
      attCompactBtn.style.fontWeight = '700';
      attTimingsBtn.style.background = '#fff';
      attTimingsBtn.style.color = '#475569';
      attTimingsBtn.style.fontWeight = '600';
    });
  }
});

// Quick jump to date in Daily Register
function filterDailyRegister(dateStr) {
  if (!dateStr) return;
  const url = new URL(window.location.href);
  url.searchParams.set('att_date', dateStr);
  url.hash = 'panel-stdattendance';
  window.location.href = url.toString();
}
</script>

<?php
echo $OUTPUT->footer();
