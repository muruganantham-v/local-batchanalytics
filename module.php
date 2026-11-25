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
// 1b. AJAX Action: Add / Reorder / Delete Lesson Plan Day
// -------------------------------------------------------------------------
if ($action === 'add_lesson_plan_day') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if (!\local_batchanalytics\program_service::has_write_permission($context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
        $cid = optional_param('courseid', 0, PARAM_INT);
        $sec_id = optional_param('sectionid', optional_param('batchid', 0, PARAM_INT), PARAM_INT);
        $after = optional_param('after', 'end', PARAM_RAW);
        $data = [
            'day_index' => optional_param('day_index', 0, PARAM_INT),
            'planned_date' => optional_param('planned_date', '', PARAM_TEXT),
            'actual_date' => optional_param('actual_date', '', PARAM_TEXT),
            'topic' => optional_param('topic', '', PARAM_RAW),
            'status' => optional_param('status', 'Pending', PARAM_TEXT),
        ];
        $id = \local_batchanalytics\lesson_plan_service::add_day($sec_id, $cid, $data, $after);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    die();
}

if ($action === 'reorder_lesson_plan_day') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if (!\local_batchanalytics\program_service::has_write_permission($context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
        $dayid = required_param('dayid', PARAM_INT);
        $direction = required_param('direction', PARAM_INT);
        $res = \local_batchanalytics\lesson_plan_service::reorder_day($dayid, $direction);
        echo json_encode(['success' => $res]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    die();
}

if ($action === 'delete_lesson_plan_day') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if (!\local_batchanalytics\program_service::has_write_permission($context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
        $dayid = required_param('dayid', PARAM_INT);
        $res = \local_batchanalytics\lesson_plan_service::delete_day($dayid);
        echo json_encode(['success' => $res]);
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
    $cid = optional_param('courseid', 0, PARAM_INT);
    $coursecontext = $cid > 0 ? \context_course::instance($cid, IGNORE_MISSING) : null;
    $can_write = is_siteadmin() 
        || \local_batchanalytics\program_service::can_write_operations($context)
        || ($coursecontext && \local_batchanalytics\program_service::can_write_operations($coursecontext));
    if (!$can_write) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
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

        $saved_files = [];
        foreach ($files_to_save as $idx => $f) {
            $clean_filename = clean_param($f['name'], PARAM_FILE);
            $itemid = (int)time() + (int)$idx;
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

                $saved_files[] = [
                    'filename' => $clean_filename,
                    'fileurl'  => $fileurl,
                    'filepath' => '/',
                    'filesize' => (int)$stored_file->get_filesize(),
                ];
            }
        }

        $session_meta = [
            'evidenceid'   => optional_param('evidenceid', 0, PARAM_INT),
            'session_type' => $session_type,
            'session_date' => $session_date,
            'caption'      => $caption_base,
            'topic'        => $caption_base,
            'week_index'   => optional_param('week_index', 0, PARAM_INT),
        ];
        $res = \local_batchanalytics\evidence_service::save_session_evidence($sec_id, $cid, $session_meta, $saved_files);

        // Fetch all media items for this session to allow immediate in-place viewer update
        $updated_items = [];
        if (!empty($res['evidenceid']) && $DB->get_manager()->table_exists(\local_batchanalytics\evidence_service::TABLE_MEDIA)) {
            $media_recs = $DB->get_records(\local_batchanalytics\evidence_service::TABLE_MEDIA, ['evidenceid' => $res['evidenceid']], 'sortorder ASC, id ASC');
            foreach ($media_recs as $m) {
                $is_vid = ($m->media_type === 'video') || (bool)preg_match('/\.(mp4|webm|mov|m4v|ogg)$/i', $m->filename);
                $updated_items[] = [
                    'id' => (int)$m->id,
                    'evidenceid' => (int)$m->evidenceid,
                    'filename' => $m->filename,
                    'fileurl' => $m->fileurl ?: '',
                    'caption' => $caption_base ?: $session_type,
                    'session_type' => $session_type,
                    'session_date' => $session_date,
                    'is_video' => $is_vid,
                    'timecreated' => (int)$m->timecreated,
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'count' => count($saved_files),
            'evidenceid' => $res['evidenceid'],
            'session_type' => $session_type,
            'items' => $updated_items,
        ]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    die();
}

if ($action === 'delete_evidence') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $cid = optional_param('courseid', 0, PARAM_INT);
    $coursecontext = $cid > 0 ? \context_course::instance($cid, IGNORE_MISSING) : null;
    $can_write = is_siteadmin() 
        || \local_batchanalytics\program_service::can_write_operations($context)
        || ($coursecontext && \local_batchanalytics\program_service::can_write_operations($coursecontext));
    if (!$can_write) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
        $evid = optional_param('evidenceid', 0, PARAM_INT);
        $mediaid = optional_param('mediaid', 0, PARAM_INT);
        $sec_id = optional_param('sectionid', optional_param('batchid', 0, PARAM_INT), PARAM_INT);
        $session_key = optional_param('session_key', '', PARAM_RAW);

        $remaining = 0;
        if ($mediaid > 0) {
            $mrec = $DB->get_record(\local_batchanalytics\evidence_service::TABLE_MEDIA, ['id' => $mediaid]);
            $parent_eid = $mrec ? (int)$mrec->evidenceid : $evid;
            \local_batchanalytics\evidence_service::delete_evidence_media($mediaid);
            if ($parent_eid > 0 && $DB->get_manager()->table_exists(\local_batchanalytics\evidence_service::TABLE_MEDIA)) {
                $remaining = (int)$DB->count_records(\local_batchanalytics\evidence_service::TABLE_MEDIA, ['evidenceid' => $parent_eid]);
            }
        } else if ($evid > 0) {
            \local_batchanalytics\evidence_service::delete_evidence_session($evid);
        } else if (!empty($session_key)) {
            if (strpos($session_key, 'sess_') === 0) {
                $eid = (int)str_replace('sess_', '', $session_key);
                \local_batchanalytics\evidence_service::delete_evidence_session($eid);
            } else if ($sec_id > 0) {
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
                    $records = $DB->get_records_select(\local_batchanalytics\evidence_service::TABLE, $where, $params);
                    foreach ($records as $r) {
                        \local_batchanalytics\evidence_service::delete_evidence_session($r->id);
                    }
                }
            }
        }
        echo json_encode(['success' => true, 'remaining' => $remaining]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    die();
}

if ($action === 'add_evidence_week') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $cid = optional_param('courseid', 0, PARAM_INT);
    $coursecontext = $cid > 0 ? \context_course::instance($cid, IGNORE_MISSING) : null;
    $can_write = is_siteadmin() 
        || \local_batchanalytics\program_service::can_write_operations($context)
        || ($coursecontext && \local_batchanalytics\program_service::can_write_operations($coursecontext));
    if (!$can_write) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
        $sec_id = optional_param('sectionid', optional_param('batchid', 0, PARAM_INT), PARAM_INT);
        $target_count = optional_param('target_count', 2, PARAM_INT);
        $new_week = \local_batchanalytics\evidence_service::add_evidence_week($sec_id, $cid, $target_count);
        echo json_encode(['success' => true, 'week' => $new_week]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    die();
}

if ($action === 'delete_evidence_week') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $cid = optional_param('courseid', 0, PARAM_INT);
    $coursecontext = $cid > 0 ? \context_course::instance($cid, IGNORE_MISSING) : null;
    $can_write = is_siteadmin() 
        || \local_batchanalytics\program_service::can_write_operations($context)
        || ($coursecontext && \local_batchanalytics\program_service::can_write_operations($coursecontext));
    if (!$can_write) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
        $sec_id = optional_param('sectionid', optional_param('batchid', 0, PARAM_INT), PARAM_INT);
        $w_idx = required_param('week_index', PARAM_INT);
        $deleted = \local_batchanalytics\evidence_service::delete_evidence_week($sec_id, $cid, $w_idx);
        if ($deleted) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Cannot delete week because evidence records are uploaded under it.']);
        }
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    die();
}

if ($action === 'edit_evidence_session') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $cid = optional_param('courseid', 0, PARAM_INT);
    $coursecontext = $cid > 0 ? \context_course::instance($cid, IGNORE_MISSING) : null;
    $can_write = is_siteadmin() 
        || \local_batchanalytics\program_service::can_write_operations($context)
        || ($coursecontext && \local_batchanalytics\program_service::can_write_operations($coursecontext));
    if (!$can_write) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: write access is restricted.']);
        die();
    }
    try {
        require_sesskey();
        $evidenceid = required_param('evidenceid', PARAM_INT);
        $week_index = required_param('week_index', PARAM_INT);
        $session_type = optional_param('session_type', 'Class', PARAM_ALPHANUMEXT);
        $session_date = optional_param('session_date', '', PARAM_TEXT);
        $caption = optional_param('caption', '', PARAM_TEXT);

        \local_batchanalytics\evidence_service::edit_evidence_session(
            $evidenceid,
            $week_index,
            $session_type,
            $session_date,
            $caption
        );

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

$is_sponsor_user = \local_batchanalytics\program_service::is_sponsor_user((int)$USER->id);
$is_sponsor_view = \local_batchanalytics\program_service::is_sponsor_view($context, (int)$USER->id);

// Check if this batch is an ESSCI / sponsored batch
$is_essci_batch = false;
if (!empty($batch) && !empty($batch->coursename) && (stripos($batch->coursename, 'essci') !== false || stripos($batch->coursename, 'marvell') !== false)) {
    $is_essci_batch = true;
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
$can_edit_mentor = is_siteadmin() || \local_batchanalytics\program_service::can_write_operations($context, $USER->id);
if (\local_batchanalytics\program_service::is_sponsor_user((int)$USER->id) && !is_siteadmin()) {
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
            $role_users = get_role_users(
                $studentroleids,
                $coursecontext,
                false,
                'ra.id AS raid, u.id, u.idnumber, u.username, u.firstname, u.lastname, u.email'
            );
            // Filter strictly through util::get_courses_student_uids to guarantee active enrolment and no staff leakage
            $valid_map = \local_batchanalytics\util::get_courses_student_uids([$courseid])[$courseid] ?? [];
            if (!empty($role_users)) {
                foreach ($role_users as $userrec) {
                    $uid = (int)$userrec->id;
                    if (isset($valid_map[$uid]) && !isset($enrolledstudents[$uid])) {
                        $enrolledstudents[$uid] = $userrec;
                    }
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
    $allowed_student_uids = !empty($enrolledstudents) ? array_keys($enrolledstudents) : [];
    $lesson_plan_days = \local_batchanalytics\lesson_plan_service::get_lesson_plan($sec_id, (int)$courseid);
    $evidence_data = \local_batchanalytics\evidence_service::get_evidence_data($sec_id, (int)$courseid);
    $attendance_matrix = \local_batchanalytics\biometric_service::get_attendance_matrix($sec_id, (int)$courseid, $allowed_student_uids);
    $hours_coverage = \local_batchanalytics\lesson_plan_service::get_hours_and_coverage($sec_id, (int)$courseid);

    // Resolve daily attendance register for selected session date (internal staff only, hidden for sponsors)
    $daily_attendance_register = [];
    $att_target_sess_id = optional_param('att_sess_id', 0, PARAM_INT);
    if (!$is_sponsor_user) {
        $att_target_date = optional_param('att_date', '', PARAM_TEXT);
        if (empty($att_target_date) && !empty($attendance_matrix['sessions'])) {
            $first_sess = reset($attendance_matrix['sessions']);
            $att_target_date = $first_sess['raw_date'] ?? date('Y-m-d');
            if ($att_target_sess_id <= 0 && !empty($first_sess['id'])) {
                $att_target_sess_id = (int)$first_sess['id'];
            }
        } else if (empty($att_target_date)) {
            $att_target_date = date('Y-m-d');
        }
        $daily_attendance_register = \local_batchanalytics\biometric_service::get_daily_attendance_register($sec_id, (int)$courseid, $att_target_date, $allowed_student_uids, $att_target_sess_id);
    }

    // Today's attendance stats strictly based on today's actual date
    $today_actual_date = date('Y-m-d');
    if (!$is_sponsor_user && !empty($att_target_date) && $att_target_date === $today_actual_date && !empty($daily_attendance_register['stats'])) {
        $today_attendance_stats = $daily_attendance_register['stats'];
    } else {
        $today_reg = \local_batchanalytics\biometric_service::get_daily_attendance_register($sec_id, (int)$courseid, $today_actual_date, $allowed_student_uids);
        $today_attendance_stats = $today_reg['stats'] ?? [];
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

$dash_v = file_exists(__DIR__ . '/dashboard.css') ? filemtime(__DIR__ . '/dashboard.css') : '3.5.0';
$style_v = file_exists(__DIR__ . '/styles.css') ? filemtime(__DIR__ . '/styles.css') : '3.5.0';
$styleurl = new moodle_url('/local/batchanalytics/styles.css', ['v' => (string)$style_v]);
$PAGE->requires->css($styleurl);
$dashboardstyleurl = new moodle_url('/local/batchanalytics/dashboard.css', ['v' => (string)$dash_v]);
$PAGE->requires->css($dashboardstyleurl);
$PAGE->requires->js_call_amd('local_batchanalytics/module', 'init');

echo $OUTPUT->header();

?>
<script>
(function() {
  var p = document.getElementById('page');
  if (p) p.classList.remove('drawers', 'drag-container');
})();
document.addEventListener('DOMContentLoaded', function() {
  var p = document.getElementById('page');
  if (p) p.classList.remove('drawers', 'drag-container');
});
</script>
<style>
/* Reset drawers & container to clean full-width */
#page,
#page.drawers,
.drawers.drag-container {
  overflow: visible !important;
  height: auto !important;
  background: transparent !important;
}

/* Full width and transparent container override across themes */
@media (min-width: 768px) {
  .pagelayout-standard #page.drawers .main-inner,
  body.limitedwidth #page.drawers .main-inner,
  body.path-local-batchanalytics #page.drawers .main-inner,
  body.path-local-batchanalytics.pagelayout-standard #page.drawers .main-inner,
  body.path-local-batchanalytics.limitedwidth #page.drawers .main-inner,
  body.path-local-batchanalytics #topofscroll.main-inner,
  body.path-local-batchanalytics .main-inner,
  #page.drawers .main-inner,
  #topofscroll.main-inner,
  .main-inner {
    max-width: 100% !important;
    width: 100% !important;
    background: transparent !important;
    box-shadow: none !important;
    border: none !important;
  }
}
#page.drawers .main-inner,
#topofscroll.main-inner,
.main-inner {
  max-width: 100% !important;
  width: 100% !important;
  background: transparent !important;
  box-shadow: none !important;
  border: none !important;
}
#page-content,
#region-main-box,
#region-main,
.local-batchanalytics-wrap {
  max-width: 100% !important;
  width: 100% !important;
  background: transparent !important;
  box-shadow: none !important;
  border: none !important;
  padding: 0 !important;
  margin: 0 !important;
}
.wrap {
  max-width: 100% !important;
  width: 100% !important;
  padding-left: 20px !important;
  padding-right: 20px !important;
  box-sizing: border-box !important;
}
.crumbbar {
  max-width: 100% !important;
  width: 100% !important;
  padding: 12px 0 6px 0 !important;
  margin: 0 !important;
  box-sizing: border-box !important;
  display: flex !important;
  align-items: center !important;
}
.crumbbar .crumb {
  padding-left: 0 !important;
  margin-left: 0 !important;
}
.shell,
.ba-module-page .shell {
  background: #ffffff !important;
  border-radius: 14px !important;
  box-shadow: 0 8px 30px rgba(30, 50, 70, 0.08) !important;
  border: 1px solid var(--line, #e3e6ea) !important;
  padding: 26px 28px 32px !important;
  margin: 14px auto 36px !important;
  box-sizing: border-box !important;
  max-width: 100% !important;
  width: 100% !important;
}

/* Lesson Plan Table & Alignment matching design screenshot */
.lp-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
}
.bio-note {
  font-size: 12px;
  color: #64748b;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.ba-lp-tablecard {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  overflow: hidden;
  margin-bottom: 24px;
}
.ba-lp-table-scroll {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  width: 100%;
}
.ba-lp-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  line-height: 1.45;
  min-width: 840px;
}
.ba-lp-table thead th {
  background: #f8fafc;
  color: #475569;
  font-size: 12px;
  font-weight: 700;
  padding: 12px 16px;
  border-bottom: 1px solid #e2e8f0;
  vertical-align: middle;
}
.ba-lp-table thead th.col-day {
  width: 60px;
  text-align: left;
  padding-left: 20px;
}
.ba-lp-table thead th.col-planned {
  width: 125px;
  text-align: left;
}
.ba-lp-table thead th.col-topic {
  min-width: 220px;
  text-align: left;
}
.ba-lp-table thead th.col-status {
  width: 120px;
  text-align: center;
}
.ba-lp-table thead th.col-actual {
  width: 125px;
  text-align: left;
}
.ba-lp-table thead th.col-class-mentor {
  width: 110px;
  text-align: center;
  line-height: 1.25;
}
.ba-lp-table thead th.col-lab-mentor {
  width: 110px;
  text-align: center;
  line-height: 1.25;
}
.ba-lp-table thead th.col-actions {
  width: 110px;
  text-align: center;
}
.ba-lp-table tbody td {
  padding: 13px 16px;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
  font-size: 13px;
  color: #1e293b;
}
.ba-lp-table tbody tr:last-child td {
  border-bottom: none;
}
.ba-lp-table tbody tr:hover {
  background: #f8fafc;
}
.ba-lp-table .lp-day {
  font-weight: 700;
  color: #0f172a;
  text-align: left;
  padding-left: 20px;
  font-size: 13.5px;
}
.ba-lp-table .lp-planned-date,
.ba-lp-table .lp-actual-date {
  white-space: nowrap;
  color: #334155;
  font-size: 13px;
}
.ba-lp-table .lp-topic {
  color: #1e293b;
  line-height: 1.45;
  word-break: break-word;
}
.lp-date input {
  width: 122px;
  padding: 6px 8px;
  border: 1px solid #cfd6dc;
  border-radius: 6px;
  font-size: 12px;
}

/* Status Pill Badges */
.stp {
  font-size: 11.5px;
  padding: 4px 13px;
  border-radius: 9999px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1.25;
  white-space: nowrap;
  letter-spacing: 0.1px;
}
.stp-done, .stp.done {
  background: #dcfce7;
  color: #15803d;
}
.stp-inp, .stp.inp {
  background: #eff6ff;
  color: #1d4ed8;
}
.stp-pend, .stp.pend {
  background: #f1f3f4;
  color: #64748b;
}

/* Biometric Mentor Attendance Pill Badges */
.att {
  font-size: 11.5px;
  font-weight: 600;
  padding: 4px 13px;
  border-radius: 9999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1.25;
  white-space: nowrap;
  letter-spacing: 0.1px;
}
.att-p {
  background: #dcfce7;
  color: #15803d;
}
.att-a {
  background: #fee2e2;
  color: #991b1b;
}
.att-s {
  background: #fef3c7;
  color: #b45309;
}
.att-m {
  background: #f1f5f9;
  color: #64748b;
}

/* Row Action Buttons (▲ ▼ ×) */
.mv {
  display: inline-flex;
  gap: 4px;
  align-items: center;
  justify-content: center;
}
.mv button {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  width: 26px;
  height: 26px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  cursor: pointer;
  color: #475569;
  line-height: 1;
  padding: 0;
  transition: all 0.15s ease;
}
.mv button:hover:not(:disabled) {
  background: #f8fafc;
  color: #0f172a;
  border-color: #cbd5e1;
}
.mv button:disabled {
  opacity: 0.35;
  cursor: not-allowed;
  pointer-events: none;
}
.mv button.ba-delete-lesson-plan-btn {
  color: #ef4444;
  font-size: 14px;
  font-weight: 700;
}
.mv button.ba-delete-lesson-plan-btn:hover:not(:disabled) {
  background: #fef2f2;
  border-color: #fecaca;
  color: #dc2626;
}

/* Smooth row highlight animation on move */
@keyframes baRowHighlight {
  0% { background-color: #ecfdf5; }
  100% { background-color: transparent; }
}
.ba-row-highlight {
  animation: baRowHighlight 1.2s ease-out;
}

#ba-confirm-del-day-btn:hover:not(:disabled) {
  background: #be123c !important;
}
#ba-confirm-del-day-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/* Modal Overlay & Form styles matching #dayOv */
.ov {
  position: fixed;
  inset: 0;
  background: rgba(30, 20, 30, 0.5);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 99999;
}
.ov.show {
  display: flex !important;
}
.ovbox {
  background: #fff;
  border-radius: 12px;
  width: 520px;
  max-width: 94vw;
  max-height: 90vh;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  display: flex;
  flex-direction: column;
}
.ovhead {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 13px 18px;
  border-bottom: 1px solid var(--line, #e3e6ea);
  background: #f4f6f8;
}
.ovhead .t {
  font-size: 13px;
  font-weight: 800;
  color: #565c62;
}
.ovhead .x {
  cursor: pointer;
  color: #9aa0a6;
  font-size: 20px;
  border: none;
  background: none;
  line-height: 1;
}
.mbody {
  padding: 20px 22px;
}
.frow {
  margin-bottom: 14px;
}
.frow.two {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-bottom: 14px;
}
.frow label {
  display: block;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  color: #64748b;
  font-weight: 700;
  margin-bottom: 6px;
}
.frow input, .frow select, .frow textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #cfd6dc;
  border-radius: 8px;
  font-size: 13px;
  font-family: inherit;
  background: #fff;
  color: #1e293b;
  box-sizing: border-box;
}
.frow textarea {
  min-height: 66px;
  resize: vertical;
}
.frow input:focus, .frow select:focus, .frow textarea:focus {
  outline: none;
  border-color: #0072ce;
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.12);
}
.frow .hint {
  font-size: 10.5px;
  color: #64748b;
  margin-top: 5px;
}
.mfoot {
  padding: 14px 18px;
  border-top: 1px solid var(--line, #e3e6ea);
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  background: #fafafa;
}
.btn-light {
  background: #eef0f2;
  color: #444;
  padding: 9px 16px;
  border: none;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
}
.btn-light:hover {
  background: #e2e5e8;
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

<?php
$breadcrumb_url = (new moodle_url('/local/batchanalytics/index.php', $is_sponsor ? ['viewmode' => 'sponsor'] : []))->out(false);
$breadcrumb_label = $is_sponsor ? 'Program MIS' : 'Home';
$batch_params = array_filter(['id' => $batchid, 'batchid' => $batchid, 'mode' => $deliverymode]);
if ($is_sponsor) {
    $batch_params['viewmode'] = 'sponsor';
}
$batch_breadcrumb_url = (new moodle_url('/local/batchanalytics/batch.php', $batch_params))->out(false);
?>
  <div class="wrap">
    <!-- Breadcrumb Bar in Prototype Style -->
    <div class="crumbbar">
      <span class="crumb">
        <a href="<?= s($breadcrumb_url) ?>"><?= s($breadcrumb_label) ?></a>
        <span class="crumb-sep">›</span>
        <a href="<?= s($batch_breadcrumb_url) ?>"><?= s($batchname) ?></a>
        <span class="crumb-sep">›</span>
        <b><?= s($mod_name) ?></b>
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
      <?php if (!empty($hours_coverage['mentor_attendance_class']) && $hours_coverage['mentor_attendance_class'] !== '—' && $hours_coverage['mentor_attendance_class'] !== '-'): ?>
        <div style="font-size:15px; font-weight:700; color:#16a34a; margin-top:4px;">Class <?= s($hours_coverage['mentor_attendance_class']) ?> | Lab <?= s($hours_coverage['mentor_attendance_lab'] ?? '—') ?></div>
      <?php else: ?>
        <div style="font-size:20px; font-weight:700; color:#64748b; margin-top:4px;">-</div>
      <?php endif; ?>
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
  <?php
    $req_tab = optional_param('tab', '', PARAM_ALPHANUMEXT);
    $active_tab = !empty($req_tab) ? $req_tab : 'lessonplan';
    $non_sponsor_active = !empty($req_tab) ? $req_tab : 'mentor';
  ?>
  <div class="tabs ba-module-tabs">
    <?php if ($is_sponsor): ?>
      <div class="tab <?= $active_tab === 'lessonplan' ? 'active' : '' ?>" data-tab="lessonplan" role="button" tabindex="0"><?= s(get_string('tab_lesson_plan', 'local_batchanalytics')) ?></div>
      <div class="tab <?= $active_tab === 'evidence' ? 'active' : '' ?>" data-tab="evidence" role="button" tabindex="0"><?= s(get_string('tab_class_evidence', 'local_batchanalytics')) ?></div>
      <div class="tab <?= $active_tab === 'stdattendance' ? 'active' : '' ?>" data-tab="stdattendance" role="button" tabindex="0"><?= s(get_string('tab_std_attendance', 'local_batchanalytics')) ?></div>
      <div class="tab <?= $active_tab === 'mstudents' ? 'active' : '' ?>" data-tab="mstudents" role="button" tabindex="0"><?= s(get_string('tab_student_perf', 'local_batchanalytics')) ?></div>
    <?php else: ?>
      <div class="tab <?= $non_sponsor_active === 'mentor' ? 'active' : '' ?>" data-tab="mentor" role="button" tabindex="0">Mentor Activities</div>
      <div class="tab <?= $non_sponsor_active === 'ssact' ? 'active' : '' ?>" data-tab="ssact" role="button" tabindex="0">SS Activities</div>
      <div class="tab <?= $non_sponsor_active === 'mstudents' ? 'active' : '' ?>" data-tab="mstudents" role="button" tabindex="0">Student Performance</div>
    <?php endif; ?>
  </div>

  <div class="ba-module-panels">

    <?php if ($is_sponsor): ?>
    <!-- 1. Lesson Plan Panel (Day-wise Syllabus Delivery) -->
    <div id="panel-lessonplan" class="panel <?= $active_tab === 'lessonplan' ? 'active' : '' ?>">
      <div class="panel-note">
        <b>Mentor-updated.</b> The class mentor records the <b>actual date</b> and <b>status</b> each day. Days can be <b>added in between</b> or <b>moved up/down</b>. <b>Class &amp; Lab mentor attendance is auto-fetched via the biometric reader.</b> Completed sessions roll up to the Hours &amp; Coverage above.
      </div>
      <div class="lp-bar">
        <?php if ($can_edit_mentor): ?>
          <button type="button" class="btn btn-primary" id="ba-open-lesson-plan-modal" style="padding:9px 16px; font-size:12.5px; font-weight:600; border-radius:8px; cursor:pointer;">+ Add day</button>
        <?php else: ?>
          <div></div>
        <?php endif; ?>
        <span class="bio-note">&#128100; Mentor attendance via biometric integration</span>
      </div>
      <div class="tablecard ba-lp-tablecard">
        <div class="ba-lp-table-scroll">
          <table class="ba-lp-table">
            <thead>
              <tr>
                <th class="col-day">Day</th>
                <th class="col-planned">Planned</th>
                <th class="col-topic">Topic / content</th>
                <th class="col-status">Status</th>
                <th class="col-actual">Actual date</th>
                <th class="col-class-mentor">Class<br>Mentor</th>
                <th class="col-lab-mentor">Lab<br>Mentor</th>
                <th class="col-actions"><span style="position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); border:0;">Actions</span></th>
              </tr>
            </thead>
            <tbody id="lpBody">
              <?php if (empty($lesson_plan_days)): ?>
                <tr><td colspan="8" class="ba-empty-cell" style="text-align:center; padding:32px 16px; color:#64748b; font-size:13.5px;">No lesson plan entries found.</td></tr>
              <?php else: ?>
                <?php
                $cm_uid = !empty($class_mentor_user->id) ? (int)$class_mentor_user->id : 0;
                $lm_uid = !empty($lab_mentor_user->id) ? (int)$lab_mentor_user->id : 0;
                foreach ($lesson_plan_days as $day):
                    $eff_date = !empty($day->actual_date) ? $day->actual_date : $day->planned_date;
                    $cm_stat = \local_batchanalytics\lesson_plan_service::resolve_mentor_biometric_attendance($cm_uid, $eff_date, (int)$courseid, (int)$sec_id, 'Class');
                    $lm_stat = \local_batchanalytics\lesson_plan_service::resolve_mentor_biometric_attendance($lm_uid, $eff_date, (int)$courseid, (int)$sec_id, 'Lab');

                    $st_class = 'stp-pend';
                    $st_label = 'Pending';
                    if (strcasecmp($day->status, 'Done') === 0) {
                        $st_class = 'stp-done';
                        $st_label = 'Done';
                    } else if (strcasecmp($day->status, 'In progress') === 0 || strcasecmp($day->status, 'inp') === 0 || strcasecmp($day->status, 'in-progress') === 0) {
                        $st_class = 'stp-inp';
                        $st_label = 'In progress';
                    }
                ?>
                  <tr data-dayid="<?= (int)$day->id ?>"
                      data-dayindex="<?= (int)$day->day_index ?>"
                      data-planned="<?= s($day->planned_date) ?>"
                      data-actual="<?= s($day->actual_date) ?>"
                      data-topic="<?= s($day->topic) ?>"
                      data-status="<?= s($st_label) ?>">
                    <td class="lp-day"><?= (int)$day->day_index ?></td>
                    <td class="lp-planned-date"><?= s($day->planned_date) ?></td>
                    <td class="lp-topic"><?= s($day->topic) ?></td>
                    <td style="text-align:center;"><span class="stp <?= $st_class ?>"><?= $st_label ?></span></td>
                    <td class="lp-actual-date"><?= !empty($day->actual_date) ? s($day->actual_date) : '<span class="muted">—</span>' ?></td>
                    <td style="text-align:center;">
                      <?php if (!empty($cm_stat['status']) && in_array(strtolower($cm_stat['status']), ['present', 'absent'])): ?>
                        <span class="att <?= strcasecmp($cm_stat['status'], 'Present') === 0 ? 'att-p' : 'att-a' ?>" title="<?= s($cm_stat['tooltip'] ?? '') ?>">
                          <?= s($cm_stat['status']) ?>
                        </span>
                      <?php else: ?>
                        <span style="color:#64748b; font-weight:600; font-size:14px;" title="<?= s($cm_stat['tooltip'] ?? 'Pending biometric integration') ?>">-</span>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                      <?php if (!empty($lm_stat['status']) && in_array(strtolower($lm_stat['status']), ['present', 'absent'])): ?>
                        <span class="att <?= strcasecmp($lm_stat['status'], 'Present') === 0 ? 'att-p' : 'att-a' ?>" title="<?= s($lm_stat['tooltip'] ?? '') ?>">
                          <?= s($lm_stat['status']) ?>
                        </span>
                      <?php else: ?>
                        <span style="color:#64748b; font-weight:600; font-size:14px;" title="<?= s($lm_stat['tooltip'] ?? 'Pending biometric integration') ?>">-</span>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                      <?php if ($can_edit_mentor): ?>
                        <div class="mv">
                          <button type="button" class="ba-lp-move-btn" data-dayid="<?= (int)$day->id ?>" data-dir="-1" title="Move up">&#9650;</button>
                          <button type="button" class="ba-lp-move-btn" data-dayid="<?= (int)$day->id ?>" data-dir="1" title="Move down">&#9660;</button>
                          <button type="button" class="ba-delete-lesson-plan-btn" data-dayid="<?= (int)$day->id ?>" title="Delete">&times;</button>
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- 2. Class Evidence Panel (Compliance Photo Tracking) -->
    <div id="panel-evidence" class="panel <?= $active_tab === 'evidence' ? 'active' : '' ?>">
      <div class="panel-note" style="margin-bottom:18px; font-size:13px; color:#475569;">
        <b>Class &amp; lab photographs</b> uploaded by the mentor on a set cadence (e.g. twice a week). Sponsors view the gallery read-only as evidence of delivery.
      </div>

      <!-- Weekly Compliance Tracker Strip -->
      <div style="display:flex; align-items:center; gap:12px; margin-bottom:22px; overflow-x:auto; padding-bottom:4px;">
        <?php foreach (($evidence_data['weekly_tracker'] ?? []) as $wk): ?>
          <div class="ba-week-tracker-pill" data-week="<?= (int)$wk['week'] ?>" data-target="<?= (int)$wk['target'] ?>" style="flex:0 0 auto; min-width:130px; max-width:170px; padding:12px 16px; border-radius:12px; border:1px solid #e2e8f0; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,0.02); position:relative;">
            <div class="ba-week-pill-top" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
              <div style="font-size:11px; font-weight:700; color:#64748b; letter-spacing:0.4px; text-transform:uppercase;"><?= s($wk['label']) ?></div>
              <?php if ($can_edit_mentor && (int)$wk['completed'] === 0): ?>
                <button type="button" class="ba-delete-week-btn" data-week="<?= (int)$wk['week'] ?>" title="Remove empty week" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:14px; line-height:1; padding:0 2px;" onmouseover="this.style.color='#ef4444';" onmouseout="this.style.color='#94a3b8';">&times;</button>
              <?php endif; ?>
            </div>
            <div class="ba-week-pill-stat" style="font-size:15px; font-weight:700; color:<?= $wk['status'] === 'complete' ? '#16a34a' : ($wk['status'] === 'partial' ? '#d97706' : '#b91c1c') ?>; display:flex; align-items:center; gap:4px;">
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

        <?php if ($can_edit_mentor): ?>
          <button type="button" id="ba-add-week-btn" style="flex:0 0 auto; display:inline-flex; align-items:center; gap:6px; height:68px; padding:0 20px; border-radius:12px; border:1.5px dashed #94a3b8; background:#f8fafc; color:#2563eb; font-size:13px; font-weight:700; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.borderColor='#2563eb'; this.style.background='#eff6ff';" onmouseout="this.style.borderColor='#94a3b8'; this.style.background='#f8fafc';">
            <i class="fa fa-plus-circle" style="font-size:15px;"></i>
            <span> Add Week</span>
          </button>
        <?php elseif (empty($evidence_data['weekly_tracker'])): ?>
          <div style="font-size:13px; color:#64748b; font-style:italic; padding:12px 0;">No weekly compliance targets configured for this module yet.</div>
        <?php endif; ?>
      </div>

      <!-- Photo / Video Evidence Gallery Grid -->
      <div id="ba-evidence-cards-grid" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:16px;">
        <?php if ($can_edit_mentor): ?>
          <!-- Upload Card (Dashed Border matching design) -->
          <div id="ba-upload-evidence-card" style="border:1.5px dashed #cbd5e1; border-radius:12px; background:#fff; height:180px; display:flex; flex-direction:column; align-items:center; justify-content:center; cursor:pointer; text-align:center; transition:all 0.2s;" onmouseover="this.style.borderColor='#94a3b8'; this.style.background='#f8fafc';" onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='#fff';">
            <div style="color:#64748b; font-size:20px; margin-bottom:8px;">
              <i class="fa fa-camera"></i>
            </div>
            <div style="font-size:13px; font-weight:600; color:#475569;">Upload class / lab photo</div>
          </div>
        <?php endif; ?>

        <?php if (!empty($evidence_data['photos'])): ?>
          <?php foreach ($evidence_data['photos'] as $p): ?>
            <?php
              $cover_is_video = !empty($p['cover_is_video']);
              $items_json = !empty($p['items']) ? json_encode($p['items']) : '[]';
              $items_cnt = (int)($p['items_count'] ?? 1);
              $photos_cnt = (int)($p['photo_count'] ?? 0);
              $videos_cnt = (int)($p['video_count'] ?? 0);
            ?>
            <div class="ba-evidence-card"
                 data-session-key="<?= s($p['session_key'] ?? '') ?>"
                 data-evidenceid="<?= (int)($p['id'] ?? 0) ?>"
                 data-week="<?= (int)($p['week_index'] ?? 1) ?>"
                 data-type="<?= s($p['session_type']) ?>"
                 data-date="<?= s($p['session_date']) ?>"
                 data-rawdate="<?= s($p['raw_date'] ?? $p['session_date']) ?>"
                 data-isodate="<?= s($p['iso_date'] ?? '') ?>"
                 data-caption="<?= s($p['caption']) ?>"
                 data-items="<?= htmlspecialchars($items_json, ENT_QUOTES, 'UTF-8') ?>"
                 style="border:1px solid #e2e8f0; border-radius:12px; background:#fff; overflow:hidden; height:180px; display:flex; flex-direction:column; position:relative; box-shadow:0 1px 3px rgba(0,0,0,0.02); cursor:pointer; transition:transform 0.15s, box-shadow 0.15s;"
                 onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.06)';"
                 onmouseout="this.style.transform='none'; this.style.boxShadow='0 1px 3px rgba(0,0,0,0.02)';">
              <!-- Media Area -->
              <div style="height:136px; position:relative; overflow:hidden; display:flex; align-items:center; justify-content:center; background:#0f172a;">
                <?php if ($cover_is_video): ?>
                  <video src="<?= s($p['cover_url']) ?><?= strpos($p['cover_url'], '#') === false ? '#t=0.5' : '' ?>" style="width:100%; height:100%; object-fit:cover;" preload="metadata" muted playsinline></video>
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

                <?php if ($can_edit_mentor && !empty($p['items'])): ?>
                  <!-- Top-right Actions: Edit & Delete buttons -->
                  <div style="position:absolute; top:6px; right:6px; display:flex; gap:6px; z-index:10;" onclick="event.stopPropagation();">
                    <button type="button" class="ba-edit-session-btn"
                            data-evidenceid="<?= (int)($p['id'] ?? 0) ?>"
                            data-week="<?= (int)($p['week_index'] ?? 1) ?>"
                            data-type="<?= s($p['session_type']) ?>"
                            data-rawdate="<?= s($p['raw_date'] ?? $p['session_date']) ?>"
                            data-isodate="<?= s($p['iso_date'] ?? '') ?>"
                            data-caption="<?= s($p['caption']) ?>"
                            title="Edit Session Details"
                            style="width:26px; height:26px; border-radius:50%; background:rgba(255,255,255,0.95); border:1px solid #cbd5e1; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:11px; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.12); transition:all 0.15s;"
                            onmouseover="this.style.background='#2563eb'; this.style.color='#fff'; this.style.borderColor='#1d4ed8';"
                            onmouseout="this.style.background='rgba(255,255,255,0.95)'; this.style.color='#2563eb'; this.style.borderColor='#cbd5e1';">
                      <i class="fa fa-pencil" style="pointer-events:none;"></i>
                    </button>
                    <button type="button" class="ba-delete-session-btn"
                            data-session-key="<?= s($p['session_key'] ?? '') ?>"
                            data-evidenceid="<?= (int)($p['id'] ?? 0) ?>"
                            data-week="<?= (int)($p['week_index'] ?? 1) ?>"
                            data-date="<?= s($p['session_date']) ?>"
                            title="Delete All Evidence for this Date"
                            style="width:26px; height:26px; border-radius:50%; background:rgba(255,255,255,0.95); border:1px solid #fecaca; color:#dc2626; display:flex; align-items:center; justify-content:center; font-size:11px; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.12); transition:all 0.15s;"
                            onmouseover="this.style.background='#dc2626'; this.style.color='#fff'; this.style.borderColor='#b91c1c';"
                            onmouseout="this.style.background='rgba(255,255,255,0.95)'; this.style.color='#dc2626'; this.style.borderColor='#fecaca';">
                      <i class="fa fa-trash" style="pointer-events:none;"></i>
                    </button>
                  </div>
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
        <?php else: ?>
          <?php if (!$can_edit_mentor): ?>
            <div style="grid-column:1 / -1; padding:36px 20px; text-align:center; background:#fff; border:1px dashed #cbd5e1; border-radius:12px; color:#64748b;">
              <i class="fa fa-picture-o" style="font-size:28px; color:#94a3b8; margin-bottom:8px; display:block;"></i>
              <div style="font-size:14px; font-weight:600; color:#475569;">No class or lab photos uploaded yet</div>
              <div style="font-size:12px; color:#94a3b8; margin-top:4px;">Photographs uploaded by the mentor will appear here as evidence of delivery.</div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- 3. Student Attendance Panel (Dynamic Sessions Grid with Check-in & Check-out Timings) -->
    <div id="panel-stdattendance" class="panel <?= $active_tab === 'stdattendance' ? 'active' : '' ?>">
      
      <!-- Top Action & Metric Bar: Cards on Left, Buttons on Right -->
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:18px;">
        
        <!-- Left Side: Table Top Summary Cards -->
        <div style="display:flex; gap:12px; align-items:stretch; flex-wrap:wrap;">
          
          <!-- Card 1: Average Attendance -->
          <div style="padding:10px 16px; background:#fff; border:1px solid #bbf7d0; border-left:4px solid #16a34a; border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,0.03); min-width:140px; display:flex; flex-direction:column; justify-content:center;">
            <div style="font-size:10.5px; color:#166534; font-weight:700; text-transform:uppercase; letter-spacing:0.4px;">Average Attendance</div>
            <div style="font-size:18px; font-weight:800; color:#15803d; margin-top:2px;">
              <?= (int)($attendance_matrix['average_attendance'] ?? 100) ?>%
            </div>
          </div>

          <!-- Card 2: Attendance Shortfall (<75%) -->
          <?php $sf_cnt = (int)($attendance_matrix['shortfall_count'] ?? 0); ?>
          <div style="padding:10px 16px; background:#fff; border:1px solid <?= $sf_cnt > 0 ? '#fecaca; border-left:4px solid #dc2626' : '#e2e8f0; border-left:4px solid #94a3b8' ?>; border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,0.03); min-width:165px; display:flex; flex-direction:column; justify-content:center;" title="Students whose overall module attendance is below the 75% certification & exam threshold">
            <div style="font-size:10.5px; color:<?= $sf_cnt > 0 ? '#991b1b' : '#475569' ?>; font-weight:700; text-transform:uppercase; letter-spacing:0.4px;">Attendance Shortfall (&lt;75%)</div>
            <div style="font-size:18px; font-weight:800; color:<?= $sf_cnt > 0 ? '#dc2626' : '#334155' ?>; margin-top:2px;">
              <?= $sf_cnt ?> <span style="font-size:12px; font-weight:600; color:#64748b;">students</span>
            </div>
          </div>

          <!-- Card 3: Today's Attendance Rate (Strictly based on today's actual date) -->
          <?php
            $today_present = (int)($today_attendance_stats['students_present'] ?? 0);
            $today_total = (int)($today_attendance_stats['total_students'] ?? (!empty($enrolledstudents) ? count($enrolledstudents) : 0));
            $today_rate = $today_total > 0 ? (int)round(($today_present / $today_total) * 100) : 0;
          ?>
          <div style="padding:10px 16px; background:#fff; border:1px solid #bfdbfe; border-left:4px solid #2563eb; border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,0.03); min-width:165px; display:flex; flex-direction:column; justify-content:center;" title="Biometric attendance recorded strictly for today (<?= s(date('d-M-Y')) ?>)">
            <div style="font-size:10.5px; color:#1e40af; font-weight:700; text-transform:uppercase; letter-spacing:0.4px;">Today's Attendance (<?= s(date('d M')) ?>)</div>
            <div style="font-size:18px; font-weight:800; color:#1d4ed8; margin-top:2px;">
              <?= $today_rate ?>%
              <span style="font-size:11.5px; color:#60a5fa; font-weight:600; margin-left:4px;">(<?= $today_present ?> / <?= $today_total ?> present)</span>
            </div>
          </div>

        </div>

        <!-- Right Side: View Toggle & Action Buttons -->
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          
          <!-- View Mode Toggle (Compact is Default) -->
          <div style="display:inline-flex; border:1px solid #cbd5e1; border-radius:8px; overflow:hidden; background:#f8fafc; box-shadow:0 1px 2px rgba(0,0,0,0.03);">
            <button type="button" id="ba-att-view-compact" class="btn btn-sm" style="font-size:12px; font-weight:700; padding:8px 14px; background:#3b82f6; color:#fff; border:none; cursor:pointer; display:flex; align-items:center; gap:5px;">
              <i class="fa fa-th"></i> Compact (P/A)
            </button>
            <button type="button" id="ba-att-view-timings" class="btn btn-sm" style="font-size:12px; font-weight:600; padding:8px 14px; background:#fff; color:#475569; border:none; cursor:pointer; display:flex; align-items:center; gap:5px;">
              <i class="fa fa-clock-o"></i> Check-In &amp; Check-Out
            </button>
          </div>

          <a href="<?= (new moodle_url('/local/batchanalytics/module.php', [
              'batchid' => $batchid,
              'courseid' => $courseid,
              'action' => 'export_attendance_pdf'
          ]))->out(false) ?>" class="btn btn-outline-secondary btn-sm" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:8px 14px; border-radius:8px;">
            <i class="fa fa-file-pdf-o"></i> Export (PDF)
          </a>

          <?php if (!$is_sponsor_user): ?>
            <a href="<?= (new moodle_url('/local/attendancepdf/daily_attendance.php', ['courseid' => $courseid]))->out(false) ?>" target="_blank" class="btn btn-outline-primary btn-sm" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:8px 14px; border-radius:8px;">
              <i class="fa fa-external-link"></i> Daily Biometric Tracker
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Matrix Table Card -->
      <div class="tablecard ba-performance-tablecard" style="margin-bottom:28px;">
        <div class="ba-performance-table-scroll">
          <table class="ba-attendance-matrix-table" id="ba-attendance-matrix-table" style="font-size:12px; text-align:center; width:100%; border-collapse:collapse;">
            <thead>
              <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                <th style="text-align:left; min-width:220px; padding:12px 16px; position:sticky; left:0; background:#f8fafc; z-index:2; border-right:1px solid #e2e8f0; font-weight:700; color:#1e293b; cursor:pointer; user-select:none;" id="ba-matrix-sort-student" class="sortable" title="Click to sort by Student Name">
                  Student <span class="ba-sort-icon" style="font-size:11px; color:#94a3b8; margin-left:4px;">↕</span>
                </th>
                <th style="width:75px; padding:10px 8px; border-right:1px solid #e2e8f0; text-align:center; font-weight:700; color:#1e293b;">Att %</th>
                <?php foreach (($attendance_matrix['sessions'] ?? []) as $sess): ?>
                  <th class="ba-sess-col" style="min-width:68px; padding:8px 4px; border-right:1px solid #f1f5f9; cursor:pointer; text-align:center;" title="<?= s($sess['description'] ?: "{$sess['code']} ({$sess['date']})") ?> (Click to view Daily Register)" onclick="filterDailyRegister('<?= s($sess['raw_date'] ?? '') ?>');">
                    <div style="font-weight:700; color:#0f172a; font-size:12px; line-height:1.2;"><?= s($sess['code']) ?></div>
                    <div style="font-size:10px; color:#475569; font-weight:600; margin-top:2px; white-space:nowrap; line-height:1.2;"><?= s($sess['date']) ?></div>
                    <div style="font-size:9.5px; color:#64748b; font-weight:500; margin-top:1px; white-space:nowrap; line-height:1.2;">(<?= s($sess['type'] ?? 'Class') ?>)</div>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($attendance_matrix['students'])): ?>
                <tr class="ba-matrix-empty-row">
                  <td colspan="<?= 2 + count($attendance_matrix['sessions'] ?? []) ?>" style="padding:28px; text-align:center; color:#64748b;">
                    <em>No student attendance records found for this module.</em>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach (($attendance_matrix['students'] ?? []) as $st): ?>
                  <tr class="ba-matrix-row" style="border-bottom:1px solid #f1f5f9;">
                    <td style="text-align:left; font-weight:600; padding:10px 16px; position:sticky; left:0; background:#fff; z-index:1; border-right:1px solid #e2e8f0;" data-student-name="<?= s(strtolower($st['name'])) ?>">
                      <a href="<?= (new moodle_url('/local/batchanalytics/student.php', array_filter(['id' => ($st['id'] ?? ($st['userid'] ?? 0)), 'batchid' => $batchid, 'courseid' => $courseid, 'module' => $module_idx, 'viewmode' => $is_sponsor ? 'sponsor' : null])))->out(false) ?>" style="color:var(--primary,#0072ce); text-decoration:none; font-size:13px; font-weight:600;">
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
              <?php endif; ?>
            </tbody>
            <tfoot>
              <tr class="ba-matrix-total-row" style="background:#f8fafc; border-top:2px solid #cbd5e1; font-weight:700;">
                <td style="text-align:left; font-weight:700; padding:10px 16px; position:sticky; left:0; background:#f8fafc; z-index:2; border-right:1px solid #e2e8f0; color:#0f172a; font-size:12px;">
                  Total Present / Enrolled
                </td>
                <td style="font-weight:700; padding:10px 6px; border-right:1px solid #e2e8f0; text-align:center; color:#0f172a; font-size:12px;">
                  <?= (int)($attendance_matrix['average_attendance'] ?? 100) ?>%
                </td>
                <?php foreach (($attendance_matrix['sessions'] ?? []) as $sess): ?>
                  <?php
                    $sess_code = $sess['code'];
                    $tot_str = $attendance_matrix['session_totals'][$sess_code] ?? '--';
                  ?>
                  <td class="ba-sess-cell" style="padding:6px 2px; border-right:1px solid #f8fafc; text-align:center; vertical-align:middle;">
                    <span style="display:inline-block; font-size:11px; font-weight:700; padding:3px 6px; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; border-radius:4px; white-space:nowrap;" title="Students present / Total enrolled for <?= s($sess_code) ?> (<?= s($sess['date']) ?>)">
                      <?= s($tot_str) ?>
                    </span>
                  </td>
                <?php endforeach; ?>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Attendance Matrix Pagination Controls -->
        <div class="ba-pagination-bar" id="ba-att-matrix-pagination-bar">
          <div class="ba-pagination-info" id="ba-att-matrix-pagination-info">
            <!-- Populated via module.js -->
          </div>
          <div class="ba-pagination-actions">
            <div class="ba-pagination-size-box">
              <label for="ba-att-matrix-page-size">Rows per page:</label>
              <select id="ba-att-matrix-page-size" class="ba-pagination-select">
                <option value="10" selected>10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="all">All</option>
              </select>
            </div>
            <div class="ba-pagination-btns" id="ba-att-matrix-pagination-btns">
              <!-- Rendered dynamically via module.js -->
            </div>
          </div>
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

          <!-- Date Selector with Interactive Session Calendar Picker -->
          <?php
            // Build a lookup map of available session dates for the calendar picker
            $session_dates_map = [];
            $curr_sess_code = '';
            $curr_sess_type = '';
            $target_day_sessions = [];
            foreach (($attendance_matrix['sessions'] ?? []) as $s_item) {
                if (!empty($s_item['raw_date'])) {
                    $raw_d = $s_item['raw_date'];
                    if (!isset($session_dates_map[$raw_d])) {
                        $session_dates_map[$raw_d] = [];
                    }
                    $session_dates_map[$raw_d][] = [
                        'id' => (int)$s_item['id'],
                        'code' => $s_item['code'],
                        'date' => $s_item['date'],
                        'type' => $s_item['type'] ?? 'Class',
                    ];
                    if ($raw_d === $att_target_date) {
                        $target_day_sessions[] = $s_item;
                        if ($att_target_sess_id > 0 && (int)$s_item['id'] === $att_target_sess_id) {
                            $curr_sess_code = $s_item['code'];
                            $curr_sess_type = $s_item['type'] ?? 'Class';
                        }
                    }
                }
            }
            if (empty($curr_sess_code) && !empty($target_day_sessions)) {
                $first_ds = reset($target_day_sessions);
                $curr_sess_code = $first_ds['code'];
                $curr_sess_type = $first_ds['type'] ?? 'Class';
            }
          ?>
          <div style="position:relative;" id="ba-session-calendar-wrapper" data-sessions='<?= json_encode($session_dates_map) ?>' data-current-date="<?= s($att_target_date) ?>">
            <div style="display:flex; align-items:center; flex-wrap:wrap; gap:8px;">
              <label style="font-size:12px; font-weight:700; color:#475569; margin:0;">Session Date:</label>
              <button type="button" id="ba-session-calendar-btn" class="btn btn-sm" style="background:#fff; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px; font-weight:600; padding:6px 12px; display:inline-flex; align-items:center; gap:8px; color:#0f172a; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                <i class="fa fa-calendar" style="color:#2563eb; font-size:13px;"></i>
                <span id="ba-selected-date-text"><?= s($daily_attendance_register['date_formatted'] ?? date('d-M-Y')) ?></span>
                <i class="fa fa-chevron-down" style="color:#94a3b8; font-size:10px; margin-left:2px;"></i>
              </button>
            </div>

            <!-- Calendar Popover: Only available session dates are highlighted and selectable -->
            <div id="ba-session-calendar-popover" style="display:none; position:absolute; right:0; top:calc(100% + 8px); background:#fff; border:1px solid #cbd5e1; border-radius:12px; box-shadow:0 10px 25px -5px rgba(15,23,42,0.15), 0 8px 10px -6px rgba(15,23,42,0.1); width:290px; padding:14px; z-index:1050;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; padding-bottom:8px; border-bottom:1px solid #f1f5f9;">
                <button type="button" id="ba-cal-prev-month" class="btn btn-sm btn-light" style="padding:2px 8px; border-radius:6px; font-weight:700; color:#475569; border:1px solid #e2e8f0; cursor:pointer;">&lsaquo;</button>
                <div id="ba-cal-month-year" style="font-weight:700; font-size:13px; color:#0f172a;"></div>
                <button type="button" id="ba-cal-next-month" class="btn btn-sm btn-light" style="padding:2px 8px; border-radius:6px; font-weight:700; color:#475569; border:1px solid #e2e8f0; cursor:pointer;">&rsaquo;</button>
              </div>

              <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:11px; font-weight:700; color:#64748b; margin-bottom:6px;">
                <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
              </div>

              <div id="ba-cal-days-grid" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:3px;"></div>

              <div style="margin-top:10px; padding-top:8px; border-top:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center; font-size:10px; color:#64748b;">
                <span style="display:inline-flex; align-items:center; gap:4px;">
                  <span style="width:8px; height:8px; border-radius:50%; background:#2563eb; display:inline-block;"></span> Available Session
                </span>
                <span style="color:#94a3b8;">Other dates disabled</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Daily Register Table -->
        <div class="table-responsive" style="margin:0;">
          <table class="table table-hover" id="ba-daily-punch-table" style="font-size:12px; margin-bottom:0; width:100%; border-collapse:collapse;">
            <thead style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
              <tr>
                <th style="padding:10px 14px; width:45px; text-align:center;">#</th>
                <th style="padding:10px 14px; cursor:pointer; user-select:none;" id="ba-punch-sort-student" class="sortable" title="Click to sort by Student Name">
                  Student Name <span class="ba-sort-icon" style="font-size:11px; color:#94a3b8; margin-left:4px;">↕</span>
                </th>
                <th style="padding:10px 14px; text-align:center; width:120px;">Check In</th>
                <th style="padding:10px 14px; text-align:center; width:120px;">Check Out</th>
                <th style="padding:10px 14px; text-align:center; width:110px;">Total Hours</th>
                <th style="padding:10px 14px; text-align:center; width:130px;">Day Status</th>
                <th style="padding:10px 14px;">Remarks</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($daily_attendance_register['students'])): ?>
                <tr class="ba-punch-empty-row">
                  <td colspan="7" style="padding:28px; text-align:center; color:#64748b;">
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
                  <tr class="ba-punch-row" style="border-bottom:1px solid #f1f5f9;">
                    <td class="ba-row-idx" style="padding:10px 14px; text-align:center; color:#64748b; font-weight:600;"><?= $reg_idx++ ?></td>
                    <td style="padding:10px 14px;" data-student-name="<?= s(strtolower($rs['name'])) ?>">
                      <strong style="color:#0f172a; font-size:13px;"><?= s($rs['name']) ?></strong>
                      <div style="font-size:11px; color:#64748b; font-family:monospace; margin-top:2px;">
                        PIN: <strong><?= s($rs['pin']) ?></strong><?= !empty($rs['email']) ? ' &middot; ' . s($rs['email']) : '' ?>
                      </div>
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

        <!-- Daily Biometric Punch Register Pagination Controls -->
        <div class="ba-pagination-bar" id="ba-daily-punch-pagination-bar">
          <div class="ba-pagination-info" id="ba-daily-punch-pagination-info">
            <!-- Populated via module.js -->
          </div>
          <div class="ba-pagination-actions">
            <div class="ba-pagination-size-box">
              <label for="ba-daily-punch-page-size">Rows per page:</label>
              <select id="ba-daily-punch-page-size" class="ba-pagination-select">
                <option value="10" selected>10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="all">All</option>
              </select>
            </div>
            <div class="ba-pagination-btns" id="ba-daily-punch-pagination-btns">
              <!-- Rendered dynamically via module.js -->
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>

    <!-- 1. Mentor Activities Panel (Operational Mentor Activities) -->
    <div id="panel-mentor" class="panel <?= $non_sponsor_active === 'mentor' ? 'active' : '' ?>">
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
    <div id="panel-ssact" class="panel <?= $non_sponsor_active === 'ssact' ? 'active' : '' ?>">
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
    <div id="panel-mstudents" class="panel <?= ($is_sponsor ? $active_tab : $non_sponsor_active) === 'mstudents' ? 'active' : '' ?>">
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
  </div> <!-- /.wrap -->

</div> <!-- /.local-batchanalytics-wrap -->

<?php if ($can_edit_mentor): ?>
<!-- ADD LESSON-PLAN DAY MODAL -->
<div class="ov" id="dayOv">
  <div class="ovbox" style="width:520px;">
    <div class="ovhead">
      <span class="t">&#10133; Add lesson-plan day</span>
      <button type="button" class="x" id="ba-close-day-modal-x" aria-label="Close">&times;</button>
    </div>
    <form id="ba-form-add-day">
      <div class="mbody">
        <div class="frow">
          <label for="d_after">INSERT POSITION</label>
          <select id="d_after" name="after">
            <option value="end">— At the end —</option>
          </select>
        </div>
        <div class="frow two">
          <div>
            <label for="d_plan">PLANNED DATE</label>
            <input id="d_plan" name="planned_date" type="date" required>
          </div>
          <div>
            <label for="d_actual">ACTUAL DATE</label>
            <input id="d_actual" name="actual_date" type="date">
          </div>
        </div>
        <div class="frow">
          <label for="d_topic">TOPIC / CONTENT</label>
          <textarea id="d_topic" name="topic" placeholder="What the mentor plans to cover this day…" required></textarea>
        </div>
        <div class="frow">
          <label for="d_status">STATUS</label>
          <select id="d_status" name="status">
            <option value="Pending" selected>Pending</option>
            <option value="In progress">In progress</option>
            <option value="Done">Done</option>
          </select>
          <div class="hint">Class &amp; Lab mentor attendance is auto-fetched via the biometric reader — not set here.</div>
        </div>
      </div>
      <div class="mfoot">
        <button type="button" class="btn-light" id="ba-close-day-modal-btn">Cancel</button>
        <button type="submit" class="btn btn-primary" id="ba-save-day-btn" style="padding:9px 18px; font-size:12.5px; font-weight:600; border-radius:8px;">Add day</button>
      </div>
    </form>
  </div>
</div>

<!-- DELETE LESSON-PLAN DAY CONFIRMATION MODAL -->
<div class="ov" id="ba-delete-day-modal" role="dialog" aria-modal="true" aria-labelledby="ba-del-modal-title">
  <div class="ovbox" style="width:480px; max-width:94vw;">
    <div class="ovhead" style="background:#fff1f2; border-bottom:1px solid #fecdd3;">
      <span class="t" id="ba-del-modal-title" style="color:#be123c; display:flex; align-items:center; gap:8px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="3 6 5 6 21 6"></polyline>
          <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
          <line x1="10" y1="11" x2="10" y2="17"></line>
          <line x1="14" y1="11" x2="14" y2="17"></line>
        </svg>
        Delete Lesson Plan Day
      </span>
      <button type="button" class="x" id="ba-close-del-modal-x" aria-label="Close" style="color:#9f1239;">&times;</button>
    </div>
    <div class="mbody" style="padding:20px 22px;">
      <p style="margin:0 0 14px 0; font-size:13.5px; color:#475569; line-height:1.5;">
        Are you sure you want to delete this lesson plan day? This action cannot be undone.
      </p>

      <!-- Details Summary Card -->
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; margin-bottom:8px;">
        <table style="width:100%; border-collapse:collapse; font-size:13px; line-height:1.45;">
          <tbody>
            <tr>
              <td style="width:110px; padding:5px 0; color:#64748b; font-weight:600; vertical-align:top;">Day:</td>
              <td id="ba-del-day-num" style="padding:5px 0; color:#0f172a; font-weight:700;">—</td>
            </tr>
            <tr>
              <td style="padding:5px 0; color:#64748b; font-weight:600; vertical-align:top;">Planned Date:</td>
              <td id="ba-del-planned-date" style="padding:5px 0; color:#1e293b; font-weight:500;">—</td>
            </tr>
            <tr>
              <td style="padding:5px 0; color:#64748b; font-weight:600; vertical-align:top;">Actual Date:</td>
              <td id="ba-del-actual-date" style="padding:5px 0; color:#1e293b; font-weight:500;">—</td>
            </tr>
            <tr>
              <td style="padding:5px 0; color:#64748b; font-weight:600; vertical-align:top;">Topic / content:</td>
              <td id="ba-del-topic" style="padding:5px 0; color:#1e293b; font-weight:500; word-break:break-word;">—</td>
            </tr>
            <tr>
              <td style="padding:5px 0; color:#64748b; font-weight:600; vertical-align:middle;">Status:</td>
              <td id="ba-del-status" style="padding:5px 0; vertical-align:middle;">—</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="mfoot" style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:12px 20px; display:flex; justify-content:flex-end; gap:10px;">
      <button type="button" class="btn-light" id="ba-cancel-del-day-btn" style="padding:8px 18px; font-size:13px; font-weight:600; border-radius:8px;">Cancel</button>
      <button type="button" class="btn" id="ba-confirm-del-day-btn" style="background:#e11d48; color:#ffffff; border:none; padding:8px 20px; font-size:13px; font-weight:600; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:background 0.15s ease;">
        <span id="ba-del-btn-spinner" style="display:none;">⏳</span>
        <span id="ba-del-btn-text">Delete</span>
      </button>
    </div>
  </div>
</div>

<!-- Modal: Upload Class / Lab Photos & Videos (Multiple Support) -->
<div id="ba-evidence-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; backdrop-filter:blur(2px);">
  <div style="background:#fff; border-radius:14px; max-width:540px; width:92%; padding:24px 28px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); position:relative;">
    <!-- Modal Header -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <div style="display:flex; align-items:center; gap:8px; font-size:16px; font-weight:700; color:#1e293b;">
        <i class="fa fa-camera" style="font-size:16px; color:#475569;"></i>
        <span>Upload class / lab photos &amp; videos</span>
      </div>
      <button type="button" class="ba-modal-btn-close-circle" id="ba-close-ev-modal-x" aria-label="Close" title="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"></line>
          <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
      </button>
    </div>

    <form id="ba-form-evidence" enctype="multipart/form-data">
      <!-- Row 1: Week, Session Type & Session Date -->
      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:16px;">
        <div>
          <label style="font-size:11px; font-weight:700; color:#475569; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:6px; display:block;">WEEK</label>
          <select id="ev-week-index" class="form-control" style="width:100%; border-radius:8px; border:1px solid #cbd5e1; height:40px; font-size:13px; color:#1e293b; background:#fff;">
            <?php
              $incomplete_weeks = [];
              if (!empty($evidence_data['weekly_tracker'])) {
                  foreach ($evidence_data['weekly_tracker'] as $wk) {
                      if ((int)$wk['completed'] < (int)$wk['target']) {
                          $incomplete_weeks[] = $wk;
                      }
                  }
              }
            ?>
            <?php if (!empty($incomplete_weeks)): ?>
              <?php foreach ($incomplete_weeks as $wk): ?>
                <option value="<?= (int)$wk['week'] ?>" <?= !empty($wk['is_current']) ? 'selected' : '' ?>>Week <?= (int)$wk['week'] ?> (<?= (int)$wk['completed'] ?>/<?= (int)$wk['target'] ?>)</option>
              <?php endforeach; ?>
            <?php else: ?>
              <option value="" disabled selected>All weeks completed (+ Add Week to upload)</option>
            <?php endif; ?>
          </select>
        </div>
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

      <!-- Real-time Date Conflict Warning Banner -->
      <div id="ev-date-conflict-warning" style="display:none; padding:10px 14px; border-radius:8px; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; font-size:12px; margin-bottom:14px; align-items:center; gap:8px;">
        <i class="fa fa-exclamation-triangle" style="font-size:14px; flex-shrink:0;"></i>
        <span id="ev-date-conflict-msg"></span>
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
        <div style="font-size:14px; font-weight:700; color:#1e293b;" id="ev-dropzone-prompt">Drag &amp; drop photos or videos here</div>
        <div style="font-size:12px; color:#64748b; margin-top:4px;">or click to choose files &middot; JPG, PNG, WEBP, MP4, WEBM, MOV</div>
        <input type="file" id="ev-file" name="evidence_files[]" multiple accept="image/*,video/*" style="display:none;">
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
        <button type="submit" class="btn btn-primary" id="ba-upload-ev-btn" style="background:#4f87e8; border:none; color:#fff; font-size:13px; font-weight:600; padding:8px 24px; border-radius:8px; cursor:pointer;">Upload files</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit Class / Lab Evidence Session -->
<div id="ba-edit-evidence-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; backdrop-filter:blur(2px);">
  <div style="background:#fff; border-radius:14px; max-width:520px; width:92%; padding:24px 28px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); position:relative;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <div style="display:flex; align-items:center; gap:8px; font-size:16px; font-weight:700; color:#1e293b;">
        <i class="fa fa-pencil" style="font-size:15px; color:#2563eb;"></i>
        <span>Edit Class Evidence Session</span>
      </div>
      <button type="button" class="ba-modal-btn-close-circle" id="ba-close-edit-modal-x" aria-label="Close" title="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"></line>
          <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
      </button>
    </div>

    <form id="ba-form-edit-evidence">
      <input type="hidden" id="edit-ev-id" value="">
      
      <!-- Date Conflict Warning in Edit Modal -->
      <div id="edit-ev-conflict-warning" style="display:none; padding:10px 14px; border-radius:8px; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; font-size:12px; margin-bottom:14px; align-items:center; gap:8px;">
        <i class="fa fa-exclamation-triangle" style="font-size:14px; flex-shrink:0;"></i>
        <span id="edit-ev-conflict-msg"></span>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:16px;">
        <div>
          <label style="font-size:11px; font-weight:700; color:#475569; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:6px; display:block;">WEEK</label>
          <select id="edit-ev-week" class="form-control" style="width:100%; border-radius:8px; border:1px solid #cbd5e1; height:40px; font-size:13px; color:#1e293b; background:#fff;">
            <?php foreach (($evidence_data['weekly_tracker'] ?? []) as $wk): ?>
              <option value="<?= (int)$wk['week'] ?>">Week <?= (int)$wk['week'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-size:11px; font-weight:700; color:#475569; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:6px; display:block;">SESSION TYPE</label>
          <select id="edit-ev-type" class="form-control" style="width:100%; border-radius:8px; border:1px solid #cbd5e1; height:40px; font-size:13px; color:#1e293b; background:#fff;">
            <option value="Class">Class</option>
            <option value="Lab">Lab</option>
          </select>
        </div>
        <div>
          <label style="font-size:11px; font-weight:700; color:#475569; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:6px; display:block;">SESSION DATE</label>
          <input type="date" id="edit-ev-date" class="form-control" style="width:100%; border-radius:8px; border:1px solid #cbd5e1; height:40px; font-size:13px; color:#1e293b; background:#fff;">
        </div>
      </div>

      <div style="margin-bottom:20px;">
        <label style="font-size:11px; font-weight:700; color:#475569; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:6px; display:block;">CAPTION</label>
        <input type="text" id="edit-ev-caption" class="form-control" placeholder="e.g. Pointers class &middot; lab batch A" style="width:100%; border-radius:8px; border:1px solid #cbd5e1; height:40px; font-size:13px; color:#1e293b; background:#fff;">
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn" id="ba-close-edit-modal-btn" style="border:1px solid #cbd5e1; background:#f8fafc; color:#334155; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; cursor:pointer;">Cancel</button>
        <button type="submit" class="btn btn-primary" id="ba-save-edit-ev-btn" style="background:#2563eb; border:none; color:#fff; font-size:13px; font-weight:600; padding:8px 24px; border-radius:8px; cursor:pointer;">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Custom Dialog Modal (Replaces native browser alert and confirm) -->
<div id="ba-custom-dialog-modal" style="display:none; position:fixed; z-index:9999999; left:0; top:0; width:100%; height:100%; background:rgba(15,23,42,0.65); align-items:center; justify-content:center; backdrop-filter:blur(3px);">
  <div style="background:#fff; border-radius:16px; max-width:420px; width:92%; padding:30px 24px 24px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.3); text-align:center; position:relative; animation:ba-pop-scale 0.2s ease-out;">
    <div id="ba-dlg-icon-wrap" style="width:56px; height:56px; border-radius:50%; margin:0 auto 16px; display:flex; align-items:center; justify-content:center; font-size:24px;"></div>
    <h4 id="ba-dlg-title" style="font-size:17px; font-weight:700; color:#1e293b; margin-bottom:8px;"></h4>
    <p id="ba-dlg-message" style="font-size:13px; color:#64748b; line-height:1.5; margin-bottom:22px;"></p>
    <div id="ba-dlg-actions" style="display:flex; justify-content:center; gap:12px;"></div>
  </div>
</div>

<!-- Modal: Evidence Upload Progress & Success Confirmation with Animation -->
<div id="ba-evidence-success-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.55); align-items:center; justify-content:center; backdrop-filter:blur(3px);">
  <div style="background:#fff; border-radius:18px; max-width:440px; width:92%; padding:36px 30px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); text-align:center; position:relative; overflow:hidden;">
    <button type="button" class="ba-modal-btn-close-circle" id="ba-close-ev-success-modal-x" aria-label="Close" title="Close" style="position:absolute; top:14px; right:14px; width:30px; height:30px; border-radius:50%; background:#f1f5f9; border:none; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#64748b;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="18" y1="6" x2="6" y2="18"></line>
        <line x1="6" y1="6" x2="18" y2="18"></line>
      </svg>
    </button>
    
    <!-- Animated Ring / Tick Container -->
    <div style="position:relative; width:96px; height:96px; margin:0 auto 20px; display:flex; align-items:center; justify-content:center;">
      <svg width="96" height="96" viewBox="0 0 96 96" style="transform: rotate(-90deg);">
        <!-- Background Track -->
        <circle cx="48" cy="48" r="40" fill="none" stroke="#f1f5f9" stroke-width="7" />
        <!-- Progress Stroke (Circumference = 2 * PI * 40 = 251.32) -->
        <circle id="ba-upload-progress-circle" cx="48" cy="48" r="40" fill="none" stroke="#3b82f6" stroke-width="7"
                stroke-linecap="round" stroke-dasharray="251.32" stroke-dashoffset="251.32"
                style="transition: stroke-dashoffset 0.18s ease-out, stroke 0.35s ease;" />
      </svg>

      <!-- Center Elements: Dynamic Percentage vs Animated Tick -->
      <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;">
        <!-- Percentage Readout -->
        <div id="ba-ev-percent-display" style="display:flex; flex-direction:column; align-items:center; justify-content:center;">
          <span id="ba-ev-percent-val" style="font-size:20px; font-weight:800; color:#1e293b; line-height:1;">0%</span>
          <span style="font-size:9px; font-weight:700; color:#3b82f6; text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">uploading</span>
        </div>
        <!-- Animated Tick (Green checkmark drawn via SVG stroke-dashoffset) -->
        <div id="ba-ev-tick-display" style="display:none; width:64px; height:64px; border-radius:50%; background:#dcfce7; align-items:center; justify-content:center;">
          <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline id="ba-ev-tick-path" points="20 6 9 17 4 12" style="stroke-dasharray:48; stroke-dashoffset:48; transition: stroke-dashoffset 0.45s cubic-bezier(0.65, 0, 0.45, 1);"></polyline>
          </svg>
        </div>
      </div>
    </div>

    <!-- Heading & Subtitle -->
    <h3 id="ba-ev-status-heading" style="font-size:18px; font-weight:700; color:#1e293b; margin-bottom:6px; transition:all 0.2s;">Uploading photos &amp; videos...</h3>
    <p id="ba-ev-progress-detail" style="font-size:13px; color:#64748b; margin-bottom:20px;">0% uploaded &middot; preparing files</p>

    <!-- Linear Progress Bar -->
    <div id="ba-ev-linear-container" style="width:100%; margin-bottom:22px; padding:0 8px;">
      <div style="width:100%; height:8px; border-radius:999px; background:#f1f5f9; overflow:hidden; border:1px solid #e2e8f0;">
        <div id="ba-ev-linear-bar" style="width:0%; height:100%; border-radius:999px; background:linear-gradient(90deg, #3b82f6, #60a5fa, #3b82f6); background-size:200% 100%; animation:ba-shimmer-linear 1.8s infinite linear; transition:width 0.18s ease-out, background 0.3s ease;"></div>
      </div>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px; font-size:11px; color:#64748b; font-weight:500;">
        <span id="ba-ev-bytes-stats">0.0 MB / 0.0 MB</span>
        <span id="ba-ev-percent-sub" style="font-weight:700; color:#3b82f6;">0%</span>
      </div>
    </div>

    <!-- Action Buttons -->
    <div>
      <button type="button" id="ba-ev-success-done-btn" style="display:none; background:#1d68d8; border:none; color:#fff; font-size:14px; font-weight:700; padding:11px 44px; border-radius:10px; cursor:pointer; min-width:140px; box-shadow:0 4px 12px rgba(29,104,216,0.25); transition:all 0.2s;">
        Done
      </button>
      <button type="button" id="ba-ev-retry-btn" style="display:none; background:#ef4444; border:none; color:#fff; font-size:13px; font-weight:600; padding:9px 24px; border-radius:8px; cursor:pointer;">
        Close &amp; Retry
      </button>
    </div>

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

    <!-- Slim Linear Upload Progress Bar (Active when adding media in viewer) -->
    <div id="ba-lightbox-upload-progress-container" style="display:none; width:100%; height:3px; background:#334155; position:relative; overflow:hidden;">
      <div id="ba-lightbox-upload-progress-bar" style="width:0%; height:100%; background:linear-gradient(90deg, #3b82f6, #60a5fa, #3b82f6); background-size:200% 100%; transition:width 0.15s ease-out;"></div>
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
    <input type="file" id="ba-lightbox-add-file-input" multiple accept="image/*,video/*" style="display:none;">

  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const dayOvModal = document.getElementById('dayOv');
  const evModal = document.getElementById('ba-evidence-modal');
  const openLpBtn = document.getElementById('ba-open-lesson-plan-modal');
  const closeLpBtn = document.getElementById('ba-close-day-modal-btn');
  const closeLpBtnX = document.getElementById('ba-close-day-modal-x');
  const openEvCard = document.getElementById('ba-upload-evidence-card');
  const openEvBtn = document.getElementById('ba-open-evidence-modal');
  const closeEvBtn = document.getElementById('ba-close-ev-modal');
  const closeEvBtnX = document.getElementById('ba-close-ev-modal-x');
  const sesskey = '<?= sesskey() ?>';
  const batchId = <?= (int)$batchid ?>;
  const sectionId = <?= (int)($section ? $section->id : ($sectionid > 0 ? $sectionid : $batchid)) ?>;
  const courseId = <?= (int)$courseid ?>;

  function refreshAfterDropdown() {
    const afterSel = document.getElementById('d_after');
    if (!afterSel) return;
    let opts = '<option value="end">— At the end —</option>';
    const rows = document.querySelectorAll('#lpBody tr[data-dayid]');
    rows.forEach(r => {
      const did = r.getAttribute('data-dayid');
      const dnum = r.getAttribute('data-dayindex');
      const topic = r.getAttribute('data-topic') || (r.querySelector('.lp-topic') ? r.querySelector('.lp-topic').textContent.trim() : '');
      const topicSnippet = topic ? ` (${topic.substring(0, 24)}${topic.length > 24 ? '…' : ''})` : '';
      if (did && dnum) {
        opts += `<option value="${did}">After day ${dnum}${topicSnippet}</option>`;
      }
    });
    afterSel.innerHTML = opts;
  }

  function openDayModal() {
    if (!dayOvModal) return;
    refreshAfterDropdown();
    const dPlan = document.getElementById('d_plan');
    const dActual = document.getElementById('d_actual');
    const dTopic = document.getElementById('d_topic');
    const dStatus = document.getElementById('d_status');
    if (dPlan) dPlan.value = '';
    if (dActual) dActual.value = '';
    if (dTopic) dTopic.value = '';
    if (dStatus) dStatus.value = 'Pending';
    dayOvModal.classList.add('show');
  }

  function closeDayModal() {
    if (dayOvModal) {
      dayOvModal.classList.remove('show');
    }
  }

  if (openLpBtn) {
    openLpBtn.addEventListener('click', openDayModal);
  }
  if (closeLpBtn) {
    closeLpBtn.addEventListener('click', closeDayModal);
  }
  if (closeLpBtnX) {
    closeLpBtnX.addEventListener('click', closeDayModal);
  }
  if (dayOvModal) {
    dayOvModal.addEventListener('click', function(e) {
      if (e.target === dayOvModal) {
        closeDayModal();
      }
    });
  }

  // Open Evidence Upload Modal from Card or Header button
  function openEvidenceModal() {
    const weekSel = document.getElementById('ev-week-index');
    if (weekSel && (!weekSel.value || weekSel.value === '')) {
      showCustomAlert({
        title: 'All Weeks Completed',
        message: 'All configured weeks for this module have already met their required compliance target (2/2). Click "+ Add Week" to add a new week before uploading.',
        type: 'info'
      });
      return;
    }
    if (evModal) {
      evModal.style.display = 'flex';
      selectedEvidenceFiles = [];
      renderSelectedFiles();
      validateUploadFormConflict();
    }
  }
  if (openEvCard) {
    openEvCard.addEventListener('click', openEvidenceModal);
  }
  if (openEvBtn) {
    openEvBtn.addEventListener('click', openEvidenceModal);
  }

  function closeEvidenceModal(clear = true) {
    if (evModal) {
      evModal.style.display = 'none';
      if (clear) {
        selectedEvidenceFiles = [];
        renderSelectedFiles();
      }
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
        fileInput.value = '';
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
      if (this.files && this.files.length > 0) {
        for (let i = 0; i < this.files.length; i++) {
          selectedEvidenceFiles.push(this.files[i]);
        }
        renderSelectedFiles();
      }
    });
  }

  // Handle lesson plan submission matching #dayOv
  const addDayForm = document.getElementById('ba-form-add-day');
  if (addDayForm) {
    addDayForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const saveBtn = document.getElementById('ba-save-day-btn');
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Adding...';
      }
      const after = document.getElementById('d_after') ? document.getElementById('d_after').value : 'end';
      const plan = document.getElementById('d_plan') ? document.getElementById('d_plan').value : '';
      const actual = document.getElementById('d_actual') ? document.getElementById('d_actual').value : '';
      const topic = document.getElementById('d_topic') ? document.getElementById('d_topic').value : '';
      const status = document.getElementById('d_status') ? document.getElementById('d_status').value : 'Pending';

      const fd = new FormData();
      fd.append('sesskey', sesskey);
      fd.append('action', 'add_lesson_plan_day');
      fd.append('batchid', batchId);
      fd.append('sectionid', sectionId);
      fd.append('courseid', courseId);
      fd.append('after', after);
      fd.append('planned_date', plan);
      fd.append('actual_date', actual);
      fd.append('topic', topic);
      fd.append('status', status);

      fetch('module.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            window.location.hash = 'panel-lessonplan';
            window.location.reload();
          } else {
            alert('Error: ' + (data.error || 'Failed to add lesson plan day'));
            if (saveBtn) {
              saveBtn.disabled = false;
              saveBtn.textContent = 'Add day';
            }
          }
        })
        .catch(err => {
          alert('Network error adding lesson plan day');
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Add day';
          }
        });
    });
  }

  // Helper to re-index Day numbers and data-dayindex in the DOM sequentially (1, 2, 3...)
  function renumberLessonPlanRows() {
    const tbody = document.getElementById('lpBody');
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr[data-dayid]');
    rows.forEach((row, idx) => {
      const dayNum = idx + 1;
      row.setAttribute('data-dayindex', dayNum);
      const dayCell = row.querySelector('.lp-day');
      if (dayCell) {
        dayCell.textContent = dayNum;
      }
    });
  }

  // Helper to maintain enabled/disabled states of ▲ / ▼ buttons
  function updateMoveButtonsState() {
    const tbody = document.getElementById('lpBody');
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr[data-dayid]');
    const total = rows.length;
    rows.forEach((row, idx) => {
      const upBtn = row.querySelector('.ba-lp-move-btn[data-dir="-1"]');
      const downBtn = row.querySelector('.ba-lp-move-btn[data-dir="1"]');
      if (upBtn) {
        upBtn.disabled = (idx === 0);
      }
      if (downBtn) {
        downBtn.disabled = (idx === total - 1);
      }
    });
  }

  // Initialize move button states & sequential day numbers on load
  renumberLessonPlanRows();
  updateMoveButtonsState();

  // Flag to guard against race conditions on rapid reordering clicks
  let isReordering = false;

  // Handle lesson plan day reordering (Move Up / Down) smoothly via AJAX without full page reload
  const lpTbody = document.getElementById('lpBody');
  if (lpTbody) {
    lpTbody.addEventListener('click', function(e) {
      const moveBtn = e.target.closest('.ba-lp-move-btn');
      if (moveBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (moveBtn.disabled || isReordering) return;

        const row = moveBtn.closest('tr[data-dayid]');
        if (!row) return;

        const dayId = moveBtn.getAttribute('data-dayid');
        const dir = parseInt(moveBtn.getAttribute('data-dir'), 10);
        if (!dayId || isNaN(dir)) return;

        const targetRow = (dir < 0) ? row.previousElementSibling : row.nextElementSibling;
        if (!targetRow || !targetRow.hasAttribute('data-dayid')) return;

        isReordering = true;

        // Smooth in-place DOM swap immediately for instant UI feedback
        if (dir < 0) {
          row.parentNode.insertBefore(row, targetRow);
        } else {
          row.parentNode.insertBefore(targetRow, row);
        }

        // Renumber Day column immediately so Day 1, Day 2... remain sequential
        renumberLessonPlanRows();

        // Trigger subtle highlight animation on swapped row
        row.classList.remove('ba-row-highlight');
        void row.offsetWidth; // Force reflow
        row.classList.add('ba-row-highlight');

        // Update button states & Add Day modal dropdown
        updateMoveButtonsState();
        refreshAfterDropdown();

        // Persist new order asynchronously via AJAX
        const fd = new FormData();
        fd.append('sesskey', sesskey);
        fd.append('action', 'reorder_lesson_plan_day');
        fd.append('dayid', dayId);
        fd.append('direction', dir);

        fetch('module.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            isReordering = false;
            if (!data.success) {
              // Revert DOM swap on failure
              if (dir < 0) {
                targetRow.parentNode.insertBefore(targetRow, row);
              } else {
                row.parentNode.insertBefore(row, targetRow);
              }
              renumberLessonPlanRows();
              updateMoveButtonsState();
              refreshAfterDropdown();
              alert('Unable to move day: ' + (data.error || 'Operation failed'));
            }
          })
          .catch(() => {
            isReordering = false;
            // Revert DOM swap on network failure
            if (dir < 0) {
              targetRow.parentNode.insertBefore(targetRow, row);
            } else {
              row.parentNode.insertBefore(row, targetRow);
            }
            renumberLessonPlanRows();
            updateMoveButtonsState();
            refreshAfterDropdown();
            alert('Network error moving day');
          });
        return;
      }

      // Handle lesson plan deletion button click -> opens custom modal with day details
      const delBtn = e.target.closest('.ba-delete-lesson-plan-btn');
      if (delBtn) {
        e.preventDefault();
        e.stopPropagation();
        const row = delBtn.closest('tr[data-dayid]');
        if (!row) return;
        openDeleteDayModal(row);
        return;
      }
    });
  }

  // Delete Lesson Plan Day Modal logic
  let pendingDeleteRow = null;
  let pendingDeleteDayId = null;
  const delDayModal = document.getElementById('ba-delete-day-modal');
  const cancelDelBtn = document.getElementById('ba-cancel-del-day-btn');
  const closeDelBtnX = document.getElementById('ba-close-del-modal-x');
  const confirmDelBtn = document.getElementById('ba-confirm-del-day-btn');

  function openDeleteDayModal(row) {
    if (!delDayModal || !row) return;
    pendingDeleteRow = row;
    pendingDeleteDayId = row.getAttribute('data-dayid');

    const dayNum = row.getAttribute('data-dayindex') || (row.querySelector('.lp-day') ? row.querySelector('.lp-day').textContent.trim() : '—');
    const plannedDate = row.getAttribute('data-planned') || (row.querySelector('.lp-planned-date') ? row.querySelector('.lp-planned-date').textContent.trim() : '—');
    const actualDate = row.getAttribute('data-actual') || (row.querySelector('.lp-actual-date') ? row.querySelector('.lp-actual-date').textContent.trim() : '—');
    const topic = row.getAttribute('data-topic') || (row.querySelector('.lp-topic') ? row.querySelector('.lp-topic').textContent.trim() : '—');
    const statusPill = row.querySelector('.stp');

    const elDayNum = document.getElementById('ba-del-day-num');
    const elPlanned = document.getElementById('ba-del-planned-date');
    const elActual = document.getElementById('ba-del-actual-date');
    const elTopic = document.getElementById('ba-del-topic');
    const elStatus = document.getElementById('ba-del-status');

    if (elDayNum) elDayNum.textContent = 'Day ' + dayNum;
    if (elPlanned) elPlanned.textContent = plannedDate || '—';
    if (elActual) elActual.textContent = actualDate || '—';
    if (elTopic) elTopic.textContent = topic || '—';
    if (elStatus) {
      if (statusPill) {
        elStatus.innerHTML = statusPill.outerHTML;
      } else {
        elStatus.textContent = '—';
      }
    }

    delDayModal.classList.add('show');
  }

  function closeDeleteDayModal() {
    if (delDayModal) {
      delDayModal.classList.remove('show');
    }
    pendingDeleteRow = null;
    pendingDeleteDayId = null;
    if (confirmDelBtn) {
      confirmDelBtn.disabled = false;
    }
    const spinner = document.getElementById('ba-del-btn-spinner');
    if (spinner) spinner.style.display = 'none';
  }

  if (cancelDelBtn) cancelDelBtn.addEventListener('click', closeDeleteDayModal);
  if (closeDelBtnX) closeDelBtnX.addEventListener('click', closeDeleteDayModal);
  if (delDayModal) {
    delDayModal.addEventListener('click', function(e) {
      if (e.target === delDayModal) {
        closeDeleteDayModal();
      }
    });
  }

  // Close modals on Escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
      closeDeleteDayModal();
      closeDayModal();
    }
  });

  if (confirmDelBtn) {
    confirmDelBtn.addEventListener('click', function() {
      if (!pendingDeleteDayId || !pendingDeleteRow) {
        closeDeleteDayModal();
        return;
      }

      const dayId = pendingDeleteDayId;
      const rowToDelete = pendingDeleteRow;
      const spinner = document.getElementById('ba-del-btn-spinner');

      confirmDelBtn.disabled = true;
      if (spinner) spinner.style.display = 'inline';

      const fd = new FormData();
      fd.append('sesskey', sesskey);
      fd.append('action', 'delete_lesson_plan_day');
      fd.append('dayid', dayId);

      fetch('module.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          confirmDelBtn.disabled = false;
          if (spinner) spinner.style.display = 'none';

          if (data.success) {
            closeDeleteDayModal();

            // Smooth fade-out and slide transition without reloading page
            rowToDelete.style.transition = 'opacity 0.22s ease, transform 0.22s ease';
            rowToDelete.style.opacity = '0';
            rowToDelete.style.transform = 'translateX(12px)';

            setTimeout(() => {
              rowToDelete.remove();

              // Check if any rows remain
              const remainingRows = document.querySelectorAll('#lpBody tr[data-dayid]');
              if (remainingRows.length === 0) {
                const tbody = document.getElementById('lpBody');
                if (tbody) {
                  tbody.innerHTML = '<tr><td colspan="8" class="ba-empty-cell" style="text-align:center; padding:32px 16px; color:#64748b; font-size:13.5px;">No lesson plan entries found.</td></tr>';
                }
                refreshAfterDropdown();
              } else {
                renumberLessonPlanRows();
                updateMoveButtonsState();
                refreshAfterDropdown();
              }
            }, 220);
          } else {
            alert('Error deleting day: ' + (data.error || 'Operation failed'));
          }
        })
        .catch(() => {
          confirmDelBtn.disabled = false;
          if (spinner) spinner.style.display = 'none';
          alert('Network error deleting day');
        });
    });
  }

  // -------------------------------------------------------------------------
  // Custom Modal Dialog Helpers (replaces native window.alert and confirm)
  // -------------------------------------------------------------------------
  const customDlgModal = document.getElementById('ba-custom-dialog-modal');
  const customDlgTitle = document.getElementById('ba-dlg-title');
  const customDlgMsg = document.getElementById('ba-dlg-message');
  const customDlgIcon = document.getElementById('ba-dlg-icon-wrap');
  const customDlgActions = document.getElementById('ba-dlg-actions');

  function showCustomConfirm(opts) {
    if (!customDlgModal) {
      if (confirm(opts.message || opts.title)) opts.onConfirm && opts.onConfirm();
      return;
    }
    customDlgTitle.textContent = opts.title || 'Confirm Action';
    customDlgMsg.textContent = opts.message || '';
    customDlgIcon.innerHTML = opts.iconHtml || '<i class="fa fa-exclamation-triangle" style="color:#e11d48;"></i>';
    customDlgIcon.style.background = opts.iconBg || '#ffe4e6';

    customDlgActions.innerHTML = `
      <button type="button" id="ba-dlg-cancel-btn" style="padding:9px 20px; font-size:13px; font-weight:600; border-radius:8px; border:1px solid #cbd5e1; background:#f8fafc; color:#334155; cursor:pointer;">Cancel</button>
      <button type="button" id="ba-dlg-confirm-btn" style="padding:9px 24px; font-size:13px; font-weight:600; border-radius:8px; border:none; background:${opts.confirmColor || '#e11d48'}; color:#fff; cursor:pointer;">${opts.confirmText || 'Confirm'}</button>
    `;
    customDlgModal.style.display = 'flex';
    document.getElementById('ba-dlg-cancel-btn').onclick = function() {
      customDlgModal.style.display = 'none';
    };
    document.getElementById('ba-dlg-confirm-btn').onclick = function() {
      customDlgModal.style.display = 'none';
      if (typeof opts.onConfirm === 'function') opts.onConfirm();
    };
  }

  function showCustomAlert(opts) {
    if (!customDlgModal) {
      alert(opts.message || opts.title);
      return;
    }
    const isError = opts.type === 'error';
    customDlgTitle.textContent = opts.title || (isError ? 'Error' : 'Notice');
    customDlgMsg.textContent = opts.message || '';
    customDlgIcon.innerHTML = isError
      ? '<i class="fa fa-times-circle" style="color:#dc2626;"></i>'
      : (opts.type === 'info' ? '<i class="fa fa-info-circle" style="color:#2563eb;"></i>' : '<i class="fa fa-exclamation-triangle" style="color:#d97706;"></i>');
    customDlgIcon.style.background = isError ? '#fee2e2' : (opts.type === 'info' ? '#dbeafe' : '#fef3c7');

    customDlgActions.innerHTML = `
      <button type="button" id="ba-dlg-ok-btn" style="padding:9px 32px; font-size:13px; font-weight:600; border-radius:8px; border:none; background:#2563eb; color:#fff; cursor:pointer;">OK</button>
    `;
    customDlgModal.style.display = 'flex';
    document.getElementById('ba-dlg-ok-btn').onclick = function() {
      customDlgModal.style.display = 'none';
    };
  }

  // Close custom dialog on backdrop click
  if (customDlgModal) {
    customDlgModal.addEventListener('click', function(e) {
      if (e.target === customDlgModal) customDlgModal.style.display = 'none';
    });
  }

  // -------------------------------------------------------------------------
  // Existing Evidence Sessions & Date Conflict Validation
  // -------------------------------------------------------------------------
  const existingSessions = <?= json_encode(array_map(function($p) {
      return [
          'id' => (int)$p['id'],
          'week' => (int)($p['week_index'] ?? 0),
          'date' => $p['session_date'],
          'raw_date' => $p['raw_date'] ?? $p['session_date'],
          'iso_date' => $p['iso_date'] ?? '',
          'type' => $p['session_type'],
      ];
  }, $evidence_data['photos'] ?? [])) ?>;

  function formatDateToNorm(isoDateStr) {
    if (!isoDateStr) return '';
    const parts = isoDateStr.split('-');
    if (parts.length === 3) {
      const d = parseInt(parts[2], 10);
      const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      const mIdx = parseInt(parts[1], 10) - 1;
      return `${String(d).padStart(2, '0')} ${months[mIdx] || ''}`.trim();
    }
    return isoDateStr;
  }

  function checkDateConflict(dateStr, targetWeek, ignoreId = 0) {
    if (!dateStr || !targetWeek) return null;
    const norm = formatDateToNorm(dateStr);
    for (let s of existingSessions) {
      if (ignoreId > 0 && s.id === ignoreId) continue;
      const sNorm = s.date.trim().toLowerCase();
      const nNorm = norm.trim().toLowerCase();
      if (sNorm === nNorm || s.raw_date === dateStr || s.iso_date === dateStr) {
        if (parseInt(s.week, 10) !== parseInt(targetWeek, 10)) {
          return s; // conflict with different week!
        }
      }
    }
    return null;
  }

  const evDateInput = document.getElementById('ev-session-date');
  const evWeekInput = document.getElementById('ev-week-index');
  const evConflictWarn = document.getElementById('ev-date-conflict-warning');
  const evConflictMsg = document.getElementById('ev-date-conflict-msg');

  function validateUploadFormConflict() {
    if (!evDateInput || !evWeekInput) return true;
    const dt = evDateInput.value;
    const wk = evWeekInput.value;
    if (!dt || !wk) return true;
    const conflict = checkDateConflict(dt, wk);
    if (conflict) {
      if (evConflictWarn && evConflictMsg) {
        evConflictMsg.textContent = `Date conflict: ${formatDateToNorm(dt)} is already assigned to Week ${conflict.week}. A date cannot belong to multiple weeks.`;
        evConflictWarn.style.display = 'flex';
      }
      return false;
    } else {
      if (evConflictWarn) evConflictWarn.style.display = 'none';
      return true;
    }
  }

  if (evDateInput) evDateInput.addEventListener('change', validateUploadFormConflict);
  if (evWeekInput) evWeekInput.addEventListener('change', validateUploadFormConflict);

  // -------------------------------------------------------------------------
  // Handle photo/video evidence submission with live percentage progress bar
  // -------------------------------------------------------------------------
  let activeMainXhr = null;
  let isMainUploading = false;
  let mainUploadPercent = 0;
  const evForm = document.getElementById('ba-form-evidence');
  if (evForm) {
    evForm.addEventListener('submit', function(e) {
      e.preventDefault();
      if (selectedEvidenceFiles.length === 0) {
        showCustomAlert({
          title: 'No Files Selected',
          message: 'Please choose or drag & drop at least one photo or video to upload.',
          type: 'warning'
        });
        return;
      }

      if (!validateUploadFormConflict()) {
        const dt = evDateInput ? evDateInput.value : '';
        const conflict = checkDateConflict(dt, evWeekInput ? evWeekInput.value : 0);
        showCustomAlert({
          title: 'Date Conflict',
          message: `The date ${formatDateToNorm(dt)} is already assigned to Week ${conflict ? conflict.week : ''}. A date cannot belong to multiple weeks. Please choose a different date or select that week.`,
          type: 'error'
        });
        return;
      }

      // Capture copy of files before closing dialog
      const filesToUpload = [...selectedEvidenceFiles];
      closeEvidenceModal(false);

      const successModal = document.getElementById('ba-evidence-success-modal');
      const progressRing = document.getElementById('ba-upload-progress-circle');
      const percentDisplay = document.getElementById('ba-ev-percent-display');
      const percentVal = document.getElementById('ba-ev-percent-val');
      const percentSub = document.getElementById('ba-ev-percent-sub');
      const tickDisplay = document.getElementById('ba-ev-tick-display');
      const tickPath = document.getElementById('ba-ev-tick-path');
      const heading = document.getElementById('ba-ev-status-heading');
      const subDetail = document.getElementById('ba-ev-progress-detail');
      const linearBar = document.getElementById('ba-ev-linear-bar');
      const bytesStats = document.getElementById('ba-ev-bytes-stats');
      const linearContainer = document.getElementById('ba-ev-linear-container');
      const doneBtn = document.getElementById('ba-ev-success-done-btn');
      const retryBtn = document.getElementById('ba-ev-retry-btn');

      const circumference = 251.32; // 2 * PI * 40

      // Reset modal to initial uploading progress state
      if (progressRing) {
        progressRing.setAttribute('stroke', '#3b82f6');
        progressRing.style.strokeDashoffset = circumference;
      }
      if (percentDisplay) percentDisplay.style.display = 'flex';
      if (percentVal) percentVal.textContent = '0%';
      if (percentSub) percentSub.textContent = '0%';
      if (tickDisplay) tickDisplay.style.display = 'none';
      if (tickPath) tickPath.style.strokeDashoffset = '48';
      if (heading) heading.textContent = 'Uploading photos & videos...';
      if (linearContainer) linearContainer.style.display = 'block';
      if (linearBar) {
        linearBar.style.width = '0%';
        linearBar.style.background = 'linear-gradient(90deg, #3b82f6, #60a5fa, #3b82f6)';
        linearBar.style.animation = 'ba-shimmer-linear 1.8s infinite linear';
      }
      if (doneBtn) doneBtn.style.display = 'none';
      if (retryBtn) retryBtn.style.display = 'none';

      let totalBytes = 0;
      filesToUpload.forEach(f => { totalBytes += f.size || 0; });
      const totalMbStr = (totalBytes / (1024 * 1024)).toFixed(1);
      if (subDetail) {
        subDetail.textContent = `Uploading ${filesToUpload.length} file${filesToUpload.length > 1 ? 's' : ''} (${totalMbStr} MB)...`;
      }
      if (bytesStats) {
        bytesStats.textContent = `0.0 MB / ${totalMbStr} MB`;
      }

      if (successModal) {
        successModal.style.display = 'flex';
      }

      const fd = new FormData();
      fd.append('sesskey', sesskey);
      fd.append('action', 'upload_evidence');
      fd.append('batchid', batchId);
      fd.append('sectionid', sectionId);
      fd.append('courseid', courseId);
      fd.append('week_index', document.getElementById('ev-week-index')?.value || '1');
      fd.append('session_type', document.getElementById('ev-session-type').value);
      fd.append('session_date', document.getElementById('ev-session-date').value);
      fd.append('caption', document.getElementById('ev-caption').value);

      filesToUpload.forEach(file => {
        fd.append('evidence_files[]', file);
      });

      const xhr = new XMLHttpRequest();
      activeMainXhr = xhr;
      isMainUploading = true;
      mainUploadPercent = 0;
      xhr.open('POST', 'module.php', true);

      let targetPercent = 0;

      // Track live upload progress
      xhr.upload.addEventListener('progress', function(ev) {
        if (ev.lengthComputable) {
          targetPercent = Math.min(95, Math.round((ev.loaded / ev.total) * 100));
          mainUploadPercent = targetPercent;
          const offset = circumference - (targetPercent / 100) * circumference;
          if (progressRing) progressRing.style.strokeDashoffset = offset;
          if (percentVal) percentVal.textContent = targetPercent + '%';
          if (percentSub) percentSub.textContent = targetPercent + '%';
          if (linearBar) linearBar.style.width = targetPercent + '%';

          const uploadedMb = (ev.loaded / (1024 * 1024)).toFixed(1);
          const totalMb = (ev.total / (1024 * 1024)).toFixed(1);
          if (bytesStats) bytesStats.textContent = `${uploadedMb} MB / ${totalMb} MB`;
          if (subDetail) {
            subDetail.textContent = `Uploading ${filesToUpload.length} file${filesToUpload.length > 1 ? 's' : ''} &middot; ${targetPercent}%`;
          }
        }
      });

      xhr.onload = function() {
        let data = null;
        try {
          data = JSON.parse(xhr.responseText);
        } catch (err) {
          data = null;
        }

        if (xhr.status === 200 && data && data.success) {
          // Smooth stepping from current percentage to 100%
          let p = targetPercent;
          const animTimer = setInterval(function() {
            p += 4;
            if (p >= 100) {
              p = 100;
              clearInterval(animTimer);
              isMainUploading = false;
              activeMainXhr = null;

              // 100% Reached - Set emerald green
              if (progressRing) {
                progressRing.style.strokeDashoffset = '0';
                progressRing.setAttribute('stroke', '#16a34a');
              }
              if (percentVal) percentVal.textContent = '100%';
              if (percentSub) percentSub.textContent = '100%';
              if (linearBar) {
                linearBar.style.width = '100%';
                linearBar.style.background = '#16a34a';
                linearBar.style.animation = 'none';
              }
              if (bytesStats) bytesStats.textContent = `${totalMbStr} MB / ${totalMbStr} MB`;

              // Morph percentage to animated green tick
              setTimeout(function() {
                if (percentDisplay) percentDisplay.style.display = 'none';
                if (tickDisplay) {
                  tickDisplay.style.display = 'flex';
                  tickDisplay.style.animation = 'ba-pop-scale 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards';
                }
                if (tickPath) {
                  setTimeout(function() {
                    tickPath.style.strokeDashoffset = '0';
                  }, 40);
                }
                if (heading) heading.textContent = 'Photos & videos uploaded successfully';
                const count = data.count || filesToUpload.length || 1;
                const typeStr = (data.session_type || 'class').toLowerCase();
                if (subDetail) {
                  subDetail.textContent = `${count} ${typeStr} file${count > 1 ? 's' : ''} (${totalMbStr} MB) added to the class-evidence gallery.`;
                }
                if (doneBtn) {
                  doneBtn.style.display = 'inline-block';
                  doneBtn.style.animation = 'ba-pop-scale 0.3s ease forwards';
                }
                // Clear selection
                selectedEvidenceFiles = [];
                renderSelectedFiles();
              }, 250);

            } else {
              const off = circumference - (p / 100) * circumference;
              if (progressRing) progressRing.style.strokeDashoffset = off;
              if (percentVal) percentVal.textContent = p + '%';
              if (percentSub) percentSub.textContent = p + '%';
              if (linearBar) linearBar.style.width = p + '%';
            }
          }, 25);

        } else {
          // Upload failed
          isMainUploading = false;
          activeMainXhr = null;
          if (progressRing) progressRing.setAttribute('stroke', '#ef4444');
          if (heading) heading.textContent = 'Upload failed';
          const errMsg = (data && data.error) ? data.error : 'An error occurred during upload.';
          if (subDetail) subDetail.textContent = errMsg;
          if (retryBtn) retryBtn.style.display = 'inline-block';
        }
      };

      xhr.onerror = function() {
        isMainUploading = false;
        activeMainXhr = null;
        if (progressRing) progressRing.setAttribute('stroke', '#ef4444');
        if (heading) heading.textContent = 'Network error';
        if (subDetail) subDetail.textContent = 'Please check your connection and try again.';
        if (retryBtn) retryBtn.style.display = 'inline-block';
      };

      xhr.onabort = function() {
        isMainUploading = false;
        activeMainXhr = null;
      };

      xhr.send(fd);
    });
  }

  // -------------------------------------------------------------------------
  // Smooth Evidence Refresh (No full-page reloads, seamless UX)
  // -------------------------------------------------------------------------
  let isRefreshingEvidence = false;
  function smoothRefreshEvidence(callback) {
    if (isRefreshingEvidence) return;
    isRefreshingEvidence = true;

    const panel = document.getElementById('panel-evidence');
    if (panel) {
      panel.style.transition = 'opacity 0.15s ease';
      panel.style.opacity = '0.5';
    }

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('tab', 'evidence');

    fetch(currentUrl.toString(), { credentials: 'same-origin' })
      .then(r => r.text())
      .then(html => {
        isRefreshingEvidence = false;
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newPanel = doc.getElementById('panel-evidence');

        if (newPanel && panel) {
          panel.innerHTML = newPanel.innerHTML;
          panel.style.opacity = '1';
        } else if (panel) {
          panel.style.opacity = '1';
        }

        // Update modal week dropdowns
        const newEvWeek = doc.getElementById('ev-week-index');
        const curEvWeek = document.getElementById('ev-week-index');
        if (newEvWeek && curEvWeek) curEvWeek.innerHTML = newEvWeek.innerHTML;

        const newEditWeek = doc.getElementById('edit-ev-week');
        const curEditWeek = document.getElementById('edit-ev-week');
        if (newEditWeek && curEditWeek) curEditWeek.innerHTML = newEditWeek.innerHTML;

        // Rebind all dynamic listeners & conflict data
        bindEvidenceListeners();
        updateExistingSessionsFromDOM();

        if (typeof callback === 'function') callback();
      })
      .catch(err => {
        isRefreshingEvidence = false;
        if (panel) panel.style.opacity = '1';
        if (typeof callback === 'function') callback();
      });
  }

  function updateExistingSessionsFromDOM() {
    existingSessions.length = 0;
    document.querySelectorAll('.ba-evidence-card').forEach(card => {
      existingSessions.push({
        id: parseInt(card.getAttribute('data-evidenceid') || '0', 10),
        week: parseInt(card.getAttribute('data-week') || '0', 10),
        type: card.getAttribute('data-type') || 'Class',
        date: card.getAttribute('data-date') || '',
        raw_date: card.getAttribute('data-rawdate') || '',
        iso_date: card.getAttribute('data-isodate') || ''
      });
    });
  }

  function updateWeekTrackersDOM() {
    document.querySelectorAll('.ba-week-tracker-pill').forEach(pill => {
      const wk = pill.getAttribute('data-week');
      const target = parseInt(pill.getAttribute('data-target') || '2', 10);
      const cardsForWeek = document.querySelectorAll(`.ba-evidence-card[data-week="${wk}"]`);
      const completed = cardsForWeek.length;

      const statEl = pill.querySelector('.ba-week-pill-stat');
      if (statEl) {
        if (completed >= target) {
          statEl.style.color = '#16a34a';
          statEl.innerHTML = `<span style="font-size:13px;">✔</span> ${completed} / ${target}`;
        } else if (completed > 0) {
          statEl.style.color = '#d97706';
          statEl.innerHTML = `${completed} / ${target}`;
        } else {
          statEl.style.color = '#b91c1c';
          statEl.innerHTML = `${completed} / ${target} due`;
        }
      }

      const topBar = pill.querySelector('.ba-week-pill-top');
      if (topBar && canEditMentor) {
        let delBtn = topBar.querySelector('.ba-delete-week-btn');
        if (completed === 0 && !delBtn) {
          delBtn = document.createElement('button');
          delBtn.type = 'button';
          delBtn.className = 'ba-delete-week-btn';
          delBtn.setAttribute('data-week', wk);
          delBtn.title = 'Remove empty week';
          delBtn.style.cssText = 'background:none; border:none; color:#94a3b8; cursor:pointer; font-size:14px; line-height:1; padding:0 2px;';
          delBtn.innerHTML = '&times;';
          delBtn.onmouseover = function() { this.style.color = '#ef4444'; };
          delBtn.onmouseout = function() { this.style.color = '#94a3b8'; };
          topBar.appendChild(delBtn);
          bindEvidenceListeners();
        } else if (completed > 0 && delBtn) {
          delBtn.remove();
        }
      }
    });
  }

  function syncEvidenceModalDropdowns() {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('tab', 'evidence');
    fetch(currentUrl.toString(), { credentials: 'same-origin' })
      .then(r => r.text())
      .then(html => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        const newEvWeek = doc.getElementById('ev-week-index');
        const curEvWeek = document.getElementById('ev-week-index');
        if (newEvWeek && curEvWeek) curEvWeek.innerHTML = newEvWeek.innerHTML;

        const newEditWeek = doc.getElementById('edit-ev-week');
        const curEditWeek = document.getElementById('edit-ev-week');
        if (newEditWeek && curEditWeek) curEditWeek.innerHTML = newEditWeek.innerHTML;
      })
      .catch(() => {});
  }

  function animateCardDeletion(cardEl, onComplete) {
    if (!cardEl) {
      if (typeof onComplete === 'function') onComplete();
      return;
    }
    const grid = document.getElementById('ba-evidence-cards-grid');
    const allCards = Array.from(document.querySelectorAll('.ba-evidence-card'));
    const cardIndex = allCards.indexOf(cardEl);
    const subsequentCards = cardIndex >= 0 ? allCards.slice(cardIndex + 1) : [];

    // 1. Record First positions (F in FLIP)
    const firstPositions = subsequentCards.map(c => c.getBoundingClientRect());

    // 2. Animate the deleting card fading out and scaling down
    cardEl.style.transition = 'opacity 0.2s cubic-bezier(0.4, 0, 0.2, 1), transform 0.2s cubic-bezier(0.4, 0, 0.2, 1)';
    cardEl.style.opacity = '0';
    cardEl.style.transform = 'scale(0.85)';

    setTimeout(() => {
      // 3. Remove card element from DOM flow
      cardEl.remove();

      // Update week tracker count badges in header immediately
      updateWeekTrackersDOM();

      // Update existingSessions in memory
      updateExistingSessionsFromDOM();

      // 4. Record Last positions (L in FLIP) and Invert (I in FLIP)
      subsequentCards.forEach((c, i) => {
        const first = firstPositions[i];
        const last = c.getBoundingClientRect();
        const deltaX = first.left - last.left;
        const deltaY = first.top - last.top;

        c.style.transition = 'none';
        c.style.transform = `translate(${deltaX}px, ${deltaY}px)`;
      });

      // Force layout reflow
      if (grid) grid.offsetHeight;

      // 5. Play (P in FLIP): smoothly glide remaining cards into new grid positions
      subsequentCards.forEach(c => {
        c.style.transition = 'transform 0.32s cubic-bezier(0.2, 0.8, 0.2, 1)';
        c.style.transform = 'translate(0, 0)';
      });

      setTimeout(() => {
        subsequentCards.forEach(c => {
          c.style.transition = '';
          c.style.transform = '';
        });
        if (typeof onComplete === 'function') onComplete();
      }, 340);
    }, 180);
  }

  // Handle Success Modal Done button (Smooth update)
  const successDoneBtn = document.getElementById('ba-ev-success-done-btn');
  if (successDoneBtn) {
    successDoneBtn.addEventListener('click', function() {
      const successModal = document.getElementById('ba-evidence-success-modal');
      if (successModal) successModal.style.display = 'none';
      smoothRefreshEvidence();
    });
  }

  // Handle Success Modal Close X button with validation during upload
  const closeSuccessX = document.getElementById('ba-close-ev-success-modal-x');
  const evSuccessModalEl = document.getElementById('ba-evidence-success-modal');

  function requestCloseSuccessModal() {
    if (isMainUploading) {
      showCustomConfirm({
        title: 'Upload in Progress',
        message: `Photos and videos are currently uploading (${mainUploadPercent}% completed). Are you sure you want to cancel the upload?`,
        confirmText: 'Cancel Upload',
        confirmColor: '#dc2626',
        onConfirm: function() {
          if (activeMainXhr) {
            try { activeMainXhr.abort(); } catch (err) {}
            activeMainXhr = null;
          }
          isMainUploading = false;
          if (evSuccessModalEl) evSuccessModalEl.style.display = 'none';
          smoothRefreshEvidence();
        }
      });
      return;
    }
    if (evSuccessModalEl) evSuccessModalEl.style.display = 'none';
    smoothRefreshEvidence();
  }

  if (closeSuccessX) {
    closeSuccessX.addEventListener('click', requestCloseSuccessModal);
  }
  if (evSuccessModalEl) {
    evSuccessModalEl.addEventListener('click', function(e) {
      if (e.target === evSuccessModalEl) {
        requestCloseSuccessModal();
      }
    });
  }

  // Handle Retry button
  const retryBtn = document.getElementById('ba-ev-retry-btn');
  if (retryBtn) {
    retryBtn.addEventListener('click', function() {
      const successModal = document.getElementById('ba-evidence-success-modal');
      if (successModal) successModal.style.display = 'none';
      if (evModal) evModal.style.display = 'flex';
    });
  }

  // -------------------------------------------------------------------------
  // Handle Edit Evidence Session Modal
  // -------------------------------------------------------------------------
  const editModal = document.getElementById('ba-edit-evidence-modal');
  const editForm = document.getElementById('ba-form-edit-evidence');
  const editEvId = document.getElementById('edit-ev-id');
  const editEvWeek = document.getElementById('edit-ev-week');
  const editEvType = document.getElementById('edit-ev-type');
  const editEvDate = document.getElementById('edit-ev-date');
  const editEvCaption = document.getElementById('edit-ev-caption');
  const editConflictWarn = document.getElementById('edit-ev-conflict-warning');
  const editConflictMsg = document.getElementById('edit-ev-conflict-msg');
  const closeEditModalBtn = document.getElementById('ba-close-edit-modal-btn');
  const closeEditModalX = document.getElementById('ba-close-edit-modal-x');

  function closeEditEvidenceModal() {
    if (editModal) editModal.style.display = 'none';
    if (editConflictWarn) editConflictWarn.style.display = 'none';
  }
  if (closeEditModalBtn) closeEditModalBtn.addEventListener('click', closeEditEvidenceModal);
  if (closeEditModalX) closeEditModalX.addEventListener('click', closeEditEvidenceModal);
  if (editModal) {
    editModal.addEventListener('click', function(e) {
      if (e.target === editModal) closeEditEvidenceModal();
    });
  }

  if (editForm) {
    editForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const evid = parseInt(editEvId.value, 10);
      const wk = editEvWeek.value;
      const dt = editEvDate.value;
      const conflict = checkDateConflict(dt, wk, evid);
      if (conflict) {
        if (editConflictWarn && editConflictMsg) {
          editConflictMsg.textContent = `Date conflict: ${formatDateToNorm(dt)} is already assigned to Week ${conflict.week}.`;
          editConflictWarn.style.display = 'flex';
        }
        showCustomAlert({
          title: 'Date Conflict',
          message: `The date ${formatDateToNorm(dt)} is already assigned to Week ${conflict.week}. A date cannot belong to multiple weeks.`,
          type: 'error'
        });
        return;
      }

      const saveBtn = document.getElementById('ba-save-edit-ev-btn');
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';
      }

      const fd = new FormData();
      fd.append('sesskey', sesskey);
      fd.append('action', 'edit_evidence_session');
      fd.append('batchid', batchId);
      fd.append('sectionid', sectionId);
      fd.append('courseid', courseId);
      fd.append('evidenceid', evid);
      fd.append('week_index', wk);
      fd.append('session_type', editEvType.value);
      fd.append('session_date', dt);
      fd.append('caption', editEvCaption.value);

      fetch('module.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Changes';
          }
          if (data && data.success) {
            closeEditEvidenceModal();
            smoothRefreshEvidence();
          } else {
            showCustomAlert({
              title: 'Error Saving',
              message: data && data.error ? data.error : 'Failed to update session.',
              type: 'error'
            });
          }
        })
        .catch(err => {
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Changes';
          }
          showCustomAlert({
            title: 'Network Error',
            message: 'Failed to update session. Please try again.',
            type: 'error'
          });
        });
    });
  }

  // -------------------------------------------------------------------------
  // Dynamic Evidence Elements Binding (Rebound on every smooth update)
  // -------------------------------------------------------------------------
  function bindEvidenceListeners() {
    // 1. Edit buttons on cards
    document.querySelectorAll('.ba-edit-session-btn').forEach(btn => {
      btn.onclick = function(e) {
        e.stopPropagation();
        e.preventDefault();
        const evid = this.getAttribute('data-evidenceid');
        const week = this.getAttribute('data-week');
        const type = this.getAttribute('data-type');
        const isodate = this.getAttribute('data-isodate');
        const caption = this.getAttribute('data-caption');

        if (editEvId) editEvId.value = evid || '';
        if (editEvWeek) editEvWeek.value = week || '1';
        if (editEvType) editEvType.value = type || 'Class';
        if (editEvDate) {
          editEvDate.value = isodate || (new Date().toISOString().split('T')[0]);
        }
        if (editEvCaption) editEvCaption.value = caption || '';
        if (editConflictWarn) editConflictWarn.style.display = 'none';
        if (editModal) editModal.style.display = 'flex';
      };
    });

    // 2. Delete session buttons on cards
    document.querySelectorAll('.ba-delete-session-btn').forEach(btn => {
      btn.onclick = function(e) {
        e.stopPropagation();
        e.preventDefault();
        const cardEl = this.closest('.ba-evidence-card');
        const sessKey = this.getAttribute('data-session-key');
        const evid = this.getAttribute('data-evidenceid');
        const sDate = this.getAttribute('data-date') || 'this date';

        showCustomConfirm({
          title: 'Delete Evidence Session?',
          message: `Are you sure you want to delete all photos and videos for ${sDate}? This action cannot be undone.`,
          confirmText: 'Delete Evidence',
          confirmColor: '#dc2626',
          onConfirm: function() {
            // Smoothly remove card from DOM and glide remaining cards into place immediately
            animateCardDeletion(cardEl, () => {
              const delId = parseInt(evid || '0', 10);
              const idx = existingSessions.findIndex(s => (delId > 0 && s.id === delId) || s.session_key === sessKey);
              if (idx >= 0) existingSessions.splice(idx, 1);
            });

            const fd = new FormData();
            fd.append('sesskey', sesskey);
            fd.append('action', 'delete_evidence');
            if (evid && parseInt(evid, 10) > 0) {
              fd.append('evidenceid', evid);
            }
            fd.append('session_key', sessKey);
            fd.append('batchid', batchId);
            fd.append('sectionid', sectionId);
            fd.append('courseid', courseId);

            fetch('module.php', { method: 'POST', body: fd })
              .then(r => r.json())
              .then(data => {
                if (data && data.success) {
                  syncEvidenceModalDropdowns();
                } else {
                  showCustomAlert({
                    title: 'Delete Failed',
                    message: data && data.error ? data.error : 'Unknown error',
                    type: 'error'
                  });
                  smoothRefreshEvidence();
                }
              })
              .catch(() => {
                showCustomAlert({
                  title: 'Network Error',
                  message: 'Failed to delete session evidence.',
                  type: 'error'
                });
                smoothRefreshEvidence();
              });
          }
        });
      };
    });

    // 3. Card click -> open session album
    document.querySelectorAll('.ba-evidence-card').forEach(card => {
      card.onclick = function(e) {
        if (e.target.closest('.ba-edit-session-btn') || e.target.closest('.ba-delete-session-btn')) return;
        const rawItems = this.getAttribute('data-items');
        let items = [];
        try {
          items = JSON.parse(rawItems || '[]');
        } catch (err) {
          items = [];
        }
        if (items.length === 0) return;

        openSessionAlbum({
          evidenceId: parseInt(this.getAttribute('data-evidenceid') || '0', 10),
          sessionKey: this.getAttribute('data-session-key') || '',
          sessionType: this.getAttribute('data-type') || '',
          sessionDate: this.getAttribute('data-date') || '',
          rawDate: this.getAttribute('data-rawdate') || '',
          isoDate: this.getAttribute('data-isodate') || '',
          weekIndex: parseInt(this.getAttribute('data-week') || '1', 10),
          caption: this.getAttribute('data-caption') || '',
          items: items
        });
      };
    });

    // 4. Add Week button
    const addWkBtn = document.getElementById('ba-add-week-btn');
    if (addWkBtn) {
      addWkBtn.onclick = function(e) {
        e.preventDefault();
        addWkBtn.disabled = true;
        addWkBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Adding...';
        const fd = new FormData();
        fd.append('sesskey', sesskey);
        fd.append('action', 'add_evidence_week');
        fd.append('batchid', batchId);
        fd.append('sectionid', sectionId);
        fd.append('courseid', courseId);
        fetch('module.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data && data.success) {
              smoothRefreshEvidence();
            } else {
              addWkBtn.disabled = false;
              addWkBtn.innerHTML = '<i class="fa fa-plus-circle"></i> Add Week';
              showCustomAlert({
                title: 'Error Adding Week',
                message: data && data.error ? data.error : 'Failed to create new week.',
                type: 'error'
              });
            }
          })
          .catch(() => {
            addWkBtn.disabled = false;
            addWkBtn.innerHTML = '<i class="fa fa-plus-circle"></i> Add Week';
            showCustomAlert({ title: 'Network Error', message: 'Server error while adding week.', type: 'error' });
          });
      };
    }

    // 5. Delete empty week buttons
    document.querySelectorAll('.ba-delete-week-btn').forEach(btn => {
      btn.onclick = function(e) {
        e.stopPropagation();
        e.preventDefault();
        const wk = this.getAttribute('data-week');
        showCustomConfirm({
          title: `Remove Week ${wk}?`,
          message: `Are you sure you want to remove Week ${wk}? This week will be deleted from this module's compliance tracking.`,
          confirmText: 'Delete Week',
          confirmColor: '#dc2626',
          onConfirm: function() {
            const fd = new FormData();
            fd.append('sesskey', sesskey);
            fd.append('action', 'delete_evidence_week');
            fd.append('week_index', wk);
            fd.append('batchid', batchId);
            fd.append('sectionid', sectionId);
            fd.append('courseid', courseId);
            fetch('module.php', { method: 'POST', body: fd })
              .then(r => r.json())
              .then(data => {
                if (data && data.success) {
                  smoothRefreshEvidence();
                } else {
                  showCustomAlert({
                    title: 'Cannot Delete Week',
                    message: data && data.error ? data.error : 'This week cannot be deleted because evidence is uploaded under it.',
                    type: 'error'
                  });
                }
              })
              .catch(() => {
                showCustomAlert({ title: 'Network Error', message: 'Server error while removing week. Please try again.', type: 'error' });
              });
          }
        });
      };
    });

    // 6. Upload card
    const upCard = document.getElementById('ba-upload-evidence-card');
    if (upCard) {
      upCard.onclick = openEvidenceModal;
    }
  }

  // -------------------------------------------------------------------------
  // Lightbox & Session Album handling (with Instant In-Viewer + Add Media)
  // -------------------------------------------------------------------------
  let currentSessionItems = [];
  let currentSessionData = null;
  let currentItemIndex = 0;
  const canEditMentor = <?= $can_edit_mentor ? 'true' : 'false' ?>;

  let activeAddMediaXhr = null;
  let isAddMediaUploading = false;
  let addMediaPercent = 0;

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
  const lbAddInput = document.getElementById('ba-lightbox-add-file-input');
  if (lbAddInput) {
    lbAddInput.addEventListener('change', function() {
      if (isAddMediaUploading) return;
      const selectedFiles = Array.from(this.files || []);
      this.value = '';
      if (!selectedFiles || selectedFiles.length === 0) return;
      handleLightboxAddFiles(selectedFiles);
    });
  }

  function renderShowcaseItem(idx) {
    if (!currentSessionItems || currentSessionItems.length === 0) return;
    if (idx < 0) idx = currentSessionItems.length - 1;
    if (idx >= currentSessionItems.length) idx = 0;
    currentItemIndex = idx;

    const item = currentSessionItems[currentItemIndex];
    if (lightboxCounter) lightboxCounter.textContent = `${currentItemIndex + 1} / ${currentSessionItems.length}`;
    if (!isAddMediaUploading && lightboxFilename) lightboxFilename.textContent = item.caption || item.filename || '';
    if (lightboxDownload) lightboxDownload.href = item.fileurl;

    if (lightboxMedia) {
      if (item.is_video) {
        lightboxMedia.innerHTML = `<video src="${item.fileurl}" controls autoplay style="max-height:55vh; max-width:100%; border-radius:8px;"></video>`;
      } else {
        lightboxMedia.innerHTML = `<img src="${item.fileurl}" alt="${item.caption || item.filename}" style="max-height:55vh; max-width:100%; object-fit:contain; border-radius:8px;">`;
      }
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

    // Show delete button only for real media
    if (lightboxDeleteBtn) {
      const isProto = !item.id || isNaN(item.id) || (typeof item.id === 'string' && item.id.startsWith('p_'));
      lightboxDeleteBtn.style.display = isProto ? 'none' : 'inline-flex';
    }
  }

  function renderFilmstrip() {
    if (!lightboxFilmstrip) return;
    lightboxFilmstrip.innerHTML = '';
    lightboxFilmstrip.style.display = 'flex';

    currentSessionItems.forEach((it, idx) => {
      const thumb = document.createElement('div');
      thumb.className = 'ba-filmstrip-thumb';
      thumb.style.cssText = 'width:64px; height:64px; border-radius:8px; overflow:hidden; border:2px solid ' + (idx === currentItemIndex ? '#3b82f6' : 'transparent') + '; opacity:' + (idx === currentItemIndex ? '1' : '0.65') + '; flex-shrink:0; cursor:pointer; position:relative; background:#000; transition:all 0.15s;';
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
      thumb.onclick = function() {
        renderShowcaseItem(idx);
      };
      lightboxFilmstrip.appendChild(thumb);
    });

    // Append + Add Media tile for mentors
    if (canEditMentor) {
      const addTile = document.createElement('div');
      addTile.id = 'ba-filmstrip-add-media-tile';
      addTile.title = isAddMediaUploading ? 'Uploading media... Hover to cancel' : 'Add more photos or videos to this session';
      addTile.style.cssText = 'width:64px; height:64px; border-radius:8px; flex-shrink:0; cursor:pointer; position:relative; overflow:hidden; display:flex; flex-direction:column; align-items:center; justify-content:center; transition:all 0.2s; ' +
        (isAddMediaUploading ? 'border:2px solid #3b82f6; background:#0f172a; color:#fff;' : 'border:2px dashed #64748b; background:rgba(255,255,255,0.06); color:#94a3b8;');

      if (isAddMediaUploading) {
        addTile.innerHTML = getAddMediaTileUploadingHtml(addMediaPercent);
        setupAddTileHoverEvents(addTile);
      } else {
        addTile.innerHTML = '<i class="fa fa-plus" style="font-size:18px;"></i><span style="font-size:9px; font-weight:700; margin-top:3px; text-transform:uppercase; letter-spacing:0.3px;">Add</span>';
        addTile.onmouseover = function() { if (!isAddMediaUploading) { this.style.borderColor = '#3b82f6'; this.style.color = '#fff'; this.style.background = 'rgba(59,130,246,0.18)'; } };
        addTile.onmouseout = function() { if (!isAddMediaUploading) { this.style.borderColor = '#64748b'; this.style.color = '#94a3b8'; this.style.background = 'rgba(255,255,255,0.06)'; } };
        addTile.onclick = function(e) {
          e.stopPropagation();
          triggerLightboxAddMedia();
        };
      }
      lightboxFilmstrip.appendChild(addTile);
    }
  }

  function getAddMediaTileUploadingHtml(pct) {
    const radius = 18;
    const circ = 113.1;
    const offset = circ - (pct / 100) * circ;
    return `
      <div class="ba-tile-progress-view" style="width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; pointer-events:none;">
        <svg width="40" height="40" viewBox="0 0 44 44" style="transform:rotate(-90deg);">
          <circle cx="22" cy="22" r="${radius}" fill="none" stroke="#334155" stroke-width="3"></circle>
          <circle id="ba-tile-progress-ring" cx="22" cy="22" r="${radius}" fill="none" stroke="#3b82f6" stroke-width="3.5" stroke-linecap="round" stroke-dasharray="${circ}" stroke-dashoffset="${offset}" style="transition:stroke-dashoffset 0.15s ease-out;"></circle>
        </svg>
        <div id="ba-tile-progress-text" style="position:absolute; font-size:10px; font-weight:800; color:#fff; letter-spacing:-0.2px;">${pct}%</div>
      </div>
      <div class="ba-tile-cancel-view" style="display:none; position:absolute; inset:0; background:rgba(220,38,38,0.94); flex-direction:column; align-items:center; justify-content:center; border-radius:6px; cursor:pointer;" title="Click to cancel upload">
        <i class="fa fa-times" style="font-size:16px; color:#fff;"></i>
        <span style="font-size:9px; font-weight:700; color:#fff; margin-top:2px; text-transform:uppercase; letter-spacing:0.3px;">Cancel</span>
      </div>
    `;
  }

  function setupAddTileHoverEvents(tile) {
    if (!tile) return;
    tile.onmouseenter = function() {
      if (!isAddMediaUploading) return;
      const progView = tile.querySelector('.ba-tile-progress-view');
      const cancelView = tile.querySelector('.ba-tile-cancel-view');
      if (progView) progView.style.display = 'none';
      if (cancelView) cancelView.style.display = 'flex';
      tile.style.borderColor = '#ef4444';
      tile.style.boxShadow = '0 0 8px rgba(239,68,68,0.4)';
    };
    tile.onmouseleave = function() {
      if (!isAddMediaUploading) return;
      const progView = tile.querySelector('.ba-tile-progress-view');
      const cancelView = tile.querySelector('.ba-tile-cancel-view');
      if (progView) progView.style.display = 'flex';
      if (cancelView) cancelView.style.display = 'none';
      tile.style.borderColor = '#3b82f6';
      tile.style.boxShadow = 'none';
    };
    tile.onclick = function(e) {
      e.stopPropagation();
      if (isAddMediaUploading) {
        cancelAddMediaUpload();
      } else {
        triggerLightboxAddMedia();
      }
    };
  }

  function cancelAddMediaUpload() {
    if (activeAddMediaXhr) {
      try { activeAddMediaXhr.abort(); } catch (err) {}
      activeAddMediaXhr = null;
    }
    isAddMediaUploading = false;
    addMediaPercent = 0;
    hideLightboxUploadProgress();
    resetAddMediaTile();
  }

  function resetAddMediaTile() {
    const addTile = document.getElementById('ba-filmstrip-add-media-tile');
    if (!addTile) return;
    addTile.title = 'Add more photos or videos to this session';
    addTile.style.border = '2px dashed #64748b';
    addTile.style.background = 'rgba(255,255,255,0.06)';
    addTile.style.color = '#94a3b8';
    addTile.style.boxShadow = 'none';
    addTile.innerHTML = '<i class="fa fa-plus" style="font-size:18px;"></i><span style="font-size:9px; font-weight:700; margin-top:3px; text-transform:uppercase; letter-spacing:0.3px;">Add</span>';
    addTile.onmouseover = function() { if (!isAddMediaUploading) { this.style.borderColor = '#3b82f6'; this.style.color = '#fff'; this.style.background = 'rgba(59,130,246,0.18)'; } };
    addTile.onmouseout = function() { if (!isAddMediaUploading) { this.style.borderColor = '#64748b'; this.style.color = '#94a3b8'; this.style.background = 'rgba(255,255,255,0.06)'; } };
    addTile.onclick = function(e) {
      e.stopPropagation();
      triggerLightboxAddMedia();
    };
  }

  function showLightboxUploadProgress(fileCount) {
    const lProgContainer = document.getElementById('ba-lightbox-upload-progress-container');
    const lProgBar = document.getElementById('ba-lightbox-upload-progress-bar');
    if (lProgContainer) lProgContainer.style.display = 'block';
    if (lProgBar) {
      lProgBar.style.width = '0%';
      lProgBar.style.background = 'linear-gradient(90deg, #3b82f6, #60a5fa, #3b82f6)';
    }
    if (lightboxFilename) {
      lightboxFilename.innerHTML = `<span style="color:#60a5fa; font-weight:600;"><i class="fa fa-spinner fa-spin" style="margin-right:6px;"></i>Uploading ${fileCount} file${fileCount > 1 ? 's' : ''} &middot; <span id="ba-lb-status-pct">0%</span></span>`;
    }
  }

  function hideLightboxUploadProgress() {
    const lProgContainer = document.getElementById('ba-lightbox-upload-progress-container');
    if (lProgContainer) lProgContainer.style.display = 'none';
    if (currentSessionItems && currentSessionItems[currentItemIndex]) {
      const item = currentSessionItems[currentItemIndex];
      if (lightboxFilename) lightboxFilename.textContent = item.caption || item.filename || '';
    } else {
      if (lightboxFilename) lightboxFilename.textContent = '';
    }
  }

  function triggerLightboxAddMedia() {
    if (isAddMediaUploading) return;
    let input = document.getElementById('ba-lightbox-add-file-input');
    if (!input) {
      input = document.createElement('input');
      input.type = 'file';
      input.id = 'ba-lightbox-add-file-input';
      input.multiple = true;
      input.accept = 'image/*,video/*';
      input.style.display = 'none';
      input.addEventListener('change', function() {
        if (isAddMediaUploading) return;
        const selectedFiles = Array.from(this.files || []);
        this.value = '';
        if (!selectedFiles || selectedFiles.length === 0) return;
        handleLightboxAddFiles(selectedFiles);
      });
      document.body.appendChild(input);
    }
    input.value = '';
    input.click();
  }

  function handleLightboxAddFiles(files) {
    if (isAddMediaUploading) return;
    if (!files || files.length === 0) return;
    if (!currentSessionData || !currentSessionData.evidenceId) {
      showCustomAlert({ title: 'Error', message: 'Session ID not found.', type: 'error' });
      return;
    }

    isAddMediaUploading = true;
    addMediaPercent = 0;

    const addTile = document.getElementById('ba-filmstrip-add-media-tile');
    if (addTile) {
      addTile.title = 'Uploading media... Hover to cancel';
      addTile.style.border = '2px solid #3b82f6';
      addTile.style.background = '#0f172a';
      addTile.style.color = '#fff';
      addTile.innerHTML = getAddMediaTileUploadingHtml(0);
      setupAddTileHoverEvents(addTile);
    }
    showLightboxUploadProgress(files.length);

    const fd = new FormData();
    fd.append('sesskey', sesskey);
    fd.append('action', 'upload_evidence');
    fd.append('batchid', batchId);
    fd.append('sectionid', sectionId);
    fd.append('courseid', courseId);
    fd.append('evidenceid', currentSessionData.evidenceId);
    fd.append('week_index', currentSessionData.weekIndex || 1);
    fd.append('session_type', currentSessionData.sessionType || 'Class');
    fd.append('session_date', currentSessionData.isoDate || currentSessionData.rawDate || currentSessionData.sessionDate || '');
    fd.append('caption', currentSessionData.caption || '');

    for (let i = 0; i < files.length; i++) {
      fd.append('evidence_files[]', files[i]);
    }

    const xhr = new XMLHttpRequest();
    activeAddMediaXhr = xhr;
    const circumference = 113.1; // 2 * Math.PI * 18

    xhr.upload.addEventListener('progress', function(ev) {
      if (ev.lengthComputable) {
        const pct = Math.min(99, Math.round((ev.loaded / ev.total) * 100));
        addMediaPercent = pct;
        const offset = circumference - (pct / 100) * circumference;

        const ring = document.getElementById('ba-tile-progress-ring');
        const txt = document.getElementById('ba-tile-progress-text');
        if (ring) ring.style.strokeDashoffset = offset;
        if (txt) txt.textContent = pct + '%';

        const lBar = document.getElementById('ba-lightbox-upload-progress-bar');
        if (lBar) lBar.style.width = pct + '%';

        const uploadedMb = (ev.loaded / (1024 * 1024)).toFixed(1);
        const totalMb = (ev.total / (1024 * 1024)).toFixed(1);
        const statusPct = document.getElementById('ba-lb-status-pct');
        if (statusPct) {
          statusPct.textContent = `${pct}% (${uploadedMb} MB / ${totalMb} MB)`;
        }
      }
    });

    xhr.onload = function() {
      let data = null;
      try {
        data = JSON.parse(xhr.responseText);
      } catch (err) {
        data = null;
      }

      if (xhr.status === 200 && data && data.success && data.items && data.items.length > 0) {
        // 100% Transition
        addMediaPercent = 100;
        const ring = document.getElementById('ba-tile-progress-ring');
        const txt = document.getElementById('ba-tile-progress-text');
        const lBar = document.getElementById('ba-lightbox-upload-progress-bar');
        if (ring) {
          ring.style.strokeDashoffset = '0';
          ring.setAttribute('stroke', '#16a34a');
        }
        if (txt) {
          txt.textContent = '100%';
          txt.style.color = '#16a34a';
        }
        if (lBar) {
          lBar.style.width = '100%';
          lBar.style.background = '#16a34a';
        }

        const tileProgView = addTile ? addTile.querySelector('.ba-tile-progress-view') : null;
        if (tileProgView) {
          setTimeout(function() {
            tileProgView.innerHTML = '<i class="fa fa-check" style="font-size:18px; color:#16a34a;"></i><span style="font-size:9px; font-weight:700; color:#16a34a; margin-top:2px;">Added!</span>';
          }, 120);
        }

        setTimeout(function() {
          isAddMediaUploading = false;
          activeAddMediaXhr = null;
          currentSessionItems = data.items;
          currentSessionData.items = data.items;
          currentItemIndex = currentSessionItems.length - 1; // view newly added item
          renderFilmstrip();
          renderShowcaseItem(currentItemIndex);
          hideLightboxUploadProgress();
          // Background update of gallery grid
          smoothRefreshEvidence();
        }, 450);

      } else if (xhr.status === 200 && data && data.success) {
        isAddMediaUploading = false;
        activeAddMediaXhr = null;
        hideLightboxUploadProgress();
        resetAddMediaTile();
        smoothRefreshEvidence();
        closeLightbox();
      } else {
        isAddMediaUploading = false;
        activeAddMediaXhr = null;
        hideLightboxUploadProgress();
        resetAddMediaTile();
        showCustomAlert({
          title: 'Upload Failed',
          message: data && data.error ? data.error : 'Failed to add media to session.',
          type: 'error'
        });
      }
    };

    xhr.onerror = function() {
      isAddMediaUploading = false;
      activeAddMediaXhr = null;
      hideLightboxUploadProgress();
      resetAddMediaTile();
      showCustomAlert({
        title: 'Network Error',
        message: 'Failed to upload files. Please check connection and try again.',
        type: 'error'
      });
    };

    xhr.onabort = function() {
      isAddMediaUploading = false;
      activeAddMediaXhr = null;
      hideLightboxUploadProgress();
      resetAddMediaTile();
    };

    xhr.open('POST', 'module.php', true);
    xhr.send(fd);
  }

  function openSessionAlbum(sessionData) {
    const items = sessionData.items || [];
    if (items.length === 0) return;

    currentSessionItems = items;
    currentSessionData = sessionData;
    currentItemIndex = 0;

    if (lightboxTitle) lightboxTitle.textContent = `${sessionData.sessionDate}`;
    if (lightboxCaption) lightboxCaption.textContent = sessionData.caption || '';
    if (lightboxTypePill) lightboxTypePill.textContent = sessionData.sessionType;

    renderFilmstrip();
    renderShowcaseItem(0);
    if (lightboxModal) lightboxModal.style.display = 'flex';
  }

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
      requestCloseLightbox();
    }
  });

  // Delete current file inside lightbox (In-place viewer update + background grid sync)
  if (lightboxDeleteBtn) {
    lightboxDeleteBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      e.preventDefault();
      if (!currentSessionItems || currentSessionItems.length === 0) return;
      const currentItem = currentSessionItems[currentItemIndex];
      const isProto = !currentItem.id || isNaN(currentItem.id) || (typeof currentItem.id === 'string' && currentItem.id.startsWith('p_'));
      if (isProto) return;

      showCustomConfirm({
        title: `Delete this ${currentItem.is_video ? 'video' : 'photo'}?`,
        message: `Are you sure you want to delete this ${currentItem.is_video ? 'video' : 'photo'}? This action cannot be undone.`,
        confirmText: 'Delete File',
        confirmColor: '#dc2626',
        onConfirm: function() {
          const fd = new FormData();
          fd.append('sesskey', sesskey);
          fd.append('action', 'delete_evidence');
          fd.append('mediaid', currentItem.id);
          if (currentItem.evidenceid) {
            fd.append('evidenceid', currentItem.evidenceid);
          }
          fd.append('batchid', batchId);
          fd.append('sectionid', sectionId);
          fd.append('courseid', courseId);

          fetch('module.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
              if (data && data.success) {
                // Remove item from in-memory session items
                currentSessionItems.splice(currentItemIndex, 1);
                if (currentSessionItems.length > 0) {
                  currentItemIndex = Math.min(currentItemIndex, currentSessionItems.length - 1);
                  renderFilmstrip();
                  renderShowcaseItem(currentItemIndex);

                  // Update card in background grid in-place
                  const card = document.querySelector(`.ba-evidence-card[data-evidenceid="${currentItem.evidenceid}"]`) ||
                               (currentSessionData ? document.querySelector(`.ba-evidence-card[data-session-key="${currentSessionData.sessionKey}"]`) : null);
                  if (card) {
                    card.setAttribute('data-items', JSON.stringify(currentSessionItems));
                    const img = card.querySelector('img');
                    if (img && currentSessionItems[0] && !currentSessionItems[0].is_video) {
                      img.src = currentSessionItems[0].fileurl;
                    }
                  }
                  syncEvidenceModalDropdowns();
                } else {
                  const card = document.querySelector(`.ba-evidence-card[data-evidenceid="${currentItem.evidenceid}"]`) ||
                               (currentSessionData ? document.querySelector(`.ba-evidence-card[data-session-key="${currentSessionData.sessionKey}"]`) : null);
                  closeLightbox();
                  if (card) {
                    animateCardDeletion(card);
                  }
                  syncEvidenceModalDropdowns();
                }
              } else {
                showCustomAlert({
                  title: 'Delete Failed',
                  message: data && data.error ? data.error : 'Unknown error',
                  type: 'error'
                });
              }
            })
            .catch(err => {
              showCustomAlert({
                title: 'Network Error',
                message: 'Failed to delete file. Please try again.',
                type: 'error'
              });
            });
        }
      });
    });
  }

  function closeLightbox() {
    if (lightboxModal) {
      lightboxModal.style.display = 'none';
      if (lightboxMedia) lightboxMedia.innerHTML = '';
      currentSessionItems = [];
      currentSessionData = null;
      hideLightboxUploadProgress();
      if (activeAddMediaXhr) {
        try { activeAddMediaXhr.abort(); } catch (err) {}
        activeAddMediaXhr = null;
      }
      isAddMediaUploading = false;
      addMediaPercent = 0;
    }
  }

  function requestCloseLightbox() {
    if (isAddMediaUploading) {
      showCustomConfirm({
        title: 'Upload in Progress',
        message: `Media files are currently uploading (${addMediaPercent}% completed). Are you sure you want to cancel the upload and close the viewer?`,
        confirmText: 'Cancel Upload & Close',
        confirmColor: '#dc2626',
        onConfirm: function() {
          if (activeAddMediaXhr) {
            try { activeAddMediaXhr.abort(); } catch (err) {}
            activeAddMediaXhr = null;
          }
          isAddMediaUploading = false;
          addMediaPercent = 0;
          hideLightboxUploadProgress();
          closeLightbox();
        }
      });
      return;
    }
    closeLightbox();
  }

  if (closeLightboxBtn && lightboxModal) {
    closeLightboxBtn.addEventListener('click', requestCloseLightbox);
    lightboxModal.addEventListener('click', function(e) {
      if (e.target === lightboxModal) {
        requestCloseLightbox();
      }
    });
  }

  // Prevent accidental page close during active upload
  window.addEventListener('beforeunload', function(e) {
    if (isAddMediaUploading || isMainUploading) {
      e.preventDefault();
      e.returnValue = 'Upload in progress. Are you sure you want to leave?';
      return e.returnValue;
    }
  });

  // Initial binding of evidence elements
  bindEvidenceListeners();
});

// Quick jump to date in Daily Register
function filterDailyRegister(dateStr) {
  if (!dateStr) return;
  const url = new URL(window.location.href);
  url.searchParams.set('att_date', dateStr);
  url.searchParams.delete('att_sess_id');
  url.searchParams.set('tab', 'stdattendance');
  url.hash = 'panel-stdattendance';
  window.location.href = url.toString();
}
</script>

<?php
echo $OUTPUT->footer();
