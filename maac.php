<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

if ($courseid <= 0) {
    if ($action !== '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid course ID']);
        die();
    }
    throw new moodle_exception('invalidcourseid');
}

$service = new \local_batchanalytics\maac_service();

if ($action !== '') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($action === 'summary') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new moodle_exception('invalidrequest');
            }
            require_sesskey();
            echo json_encode($service->get_course_summary($courseid, $USER->id));
            die();
        }

        if ($action === 'getdata') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new moodle_exception('invalidrequest');
            }
            require_sesskey();
            echo json_encode($service->get_course_data($courseid, $USER->id));
            die();
        }

        if ($action === 'savedata') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new moodle_exception('invalidrequest');
            }
            require_sesskey();
            $payload = optional_param('payload', '', PARAM_RAW);
            if (strlen($payload) > 1048576) {
                echo json_encode(['error' => 'Payload too large']);
                die();
            }
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                echo json_encode(['error' => 'Invalid JSON payload']);
                die();
            }
            $rows = is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [];
            $service->save_course_data($courseid, $USER->id, $rows);
            echo json_encode(['status' => 'ok']);
            die();
        }

        if ($action === 'saveticket') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new moodle_exception('invalidrequest');
            }
            require_sesskey();
            $payload = optional_param('payload', '', PARAM_RAW);
            if (strlen($payload) > 1048576) {
                echo json_encode(['error' => 'Payload too large']);
                die();
            }
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                echo json_encode(['error' => 'Invalid JSON payload']);
                die();
            }
            $result = $service->save_ticket($courseid, $USER->id, $decoded);
            echo json_encode([
                'status' => 'ok',
                'message' => get_string('ticket_saved', 'local_batchanalytics'),
                'ticket' => $result,
            ]);
            die();
        }
        if ($action === 'editticket') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new moodle_exception('invalidrequest');
            }
            require_sesskey();
            $payload = optional_param('payload', '', PARAM_RAW);
            if (strlen($payload) > 1048576) {
                echo json_encode(['error' => 'Payload too large']);
                die();
            }
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                echo json_encode(['error' => 'Invalid JSON payload']);
                die();
            }
            $result = $service->edit_maac_ticket($courseid, $USER->id, $decoded);
            echo json_encode([
                'status' => 'ok',
                'message' => 'Ticket updated successfully',
                'ticket' => $result,
            ]);
            die();
        }

        if ($action === 'viewticket') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new moodle_exception('invalidrequest');
            }
            require_sesskey();
            $payload = optional_param('payload', '', PARAM_RAW);
            if (strlen($payload) > 1048576) {
                echo json_encode(['error' => 'Payload too large']);
                die();
            }
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                echo json_encode(['error' => 'Invalid JSON payload']);
                die();
            }
            $result = $service->mark_maac_ticket_viewed($courseid, $USER->id, $decoded);
            echo json_encode([
                'status' => 'ok',
                'ticket' => $result,
            ]);
            die();
        }

        echo json_encode(['error' => 'Unknown action']);
    } catch (\Throwable $e) {
        debugging('MAAC API Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        echo json_encode(['error' => 'An error occurred processing your request.']);
    }
    die();
}

$courseinfo = $service->get_course_summary($courseid, $USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/maac.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('maac_page_title', 'local_batchanalytics'));
$PAGE->set_heading('');
$styleurl = new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime(__DIR__ . '/styles.css')]);
$scripturl = new moodle_url('/local/batchanalytics/maac.js', ['v' => filemtime(__DIR__ . '/maac.js')]);
$PAGE->requires->css($styleurl);
$PAGE->requires->js($scripturl);

echo $OUTPUT->header();
echo '<div class="local-batchanalytics-maac" data-courseid="' . (int)$courseid . '" data-sesskey="' . sesskey() . '">';
echo '<div id="ba-maac-app" class="ba-maac-app"></div>';
echo '</div>';
echo $OUTPUT->footer();
