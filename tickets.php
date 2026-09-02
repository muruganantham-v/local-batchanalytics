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
require_capability('local/batchanalytics:view', $context);

/**
 * Check whether the current user can access the ticket dashboard.
 *
 * @param int $userid
 * @return bool
 */
function local_batchanalytics_can_access_tickets(int $userid): bool {
    $moodledata = new \local_batchanalytics\moodledata();
    return $moodledata->can_access_ticket_dashboard($userid);
}

if (!local_batchanalytics_can_access_tickets($USER->id)) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('ticket_dashboard', 'local_batchanalytics'));
}

$action = optional_param('action', '', PARAM_ALPHA);
$service = new \local_batchanalytics\maac_service();

if ($action !== '') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($action === 'gettickets') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new moodle_exception('invalidrequest');
            }
            require_sesskey();
            echo json_encode($service->get_ticket_dashboard_data($USER->id));
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
            $ticketid = (int)($decoded['ticketid'] ?? 0);
            $result = $service->mark_ticket_viewed($ticketid, $USER->id);
            echo json_encode([
                'status' => 'ok',
                'ticket' => $result,
            ]);
            die();
        }
        if ($action === 'escalateticket') {
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
            $ticketid = (int)($decoded['ticketid'] ?? 0);
            $result = $service->escalate_ticket_to_pm($ticketid, $USER->id);
            echo json_encode([
                'status' => 'ok',
                'message' => 'Ticket escalated to PM successfully',
                'ticket' => $result,
            ]);
            die();
        }
        if ($action === 'updateticket') {
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
            $ticketid = (int)($decoded['ticketid'] ?? 0);
            $result = $service->update_ticket($ticketid, $USER->id, is_array($decoded) ? $decoded : []);
            echo json_encode([
                'status' => 'ok',
                'message' => get_string('ticket_update_success', 'local_batchanalytics'),
                'ticket' => $result,
            ]);
            die();
        }

        echo json_encode(['error' => 'Unknown action']);
    } catch (\Throwable $e) {
        global $CFG;

        error_log('Ticket API Error: ' . $e->getMessage());
        $message = 'An error occurred processing your request.';
        if (!empty($CFG->debug) && $CFG->debug >= DEBUG_DEVELOPER) {
            $message .= ' ' . $e->getMessage();
        }
        echo json_encode(['error' => $message]);
    }
    die();
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/tickets.php'));
$PAGE->set_title(get_string('ticket_dashboard_title', 'local_batchanalytics'));
$PAGE->set_heading(get_string('ticket_dashboard_title', 'local_batchanalytics'));

$styleurl = new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime(__DIR__ . '/styles.css')]);
$scripturl = new moodle_url('/local/batchanalytics/tickets.js', ['v' => filemtime(__DIR__ . '/tickets.js')]);
$PAGE->requires->css($styleurl);
$PAGE->requires->js($scripturl);

echo $OUTPUT->header();
echo '<div class="local-batchanalytics-wrap local-batchanalytics-tickets" data-sesskey="' . sesskey() . '">';
echo '<div id="ba-ticket-app" class="ba-ticket-app"></div>';
echo '</div>';
echo $OUTPUT->footer();
