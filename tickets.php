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
    $systemcontext = context_system::instance();
    if (is_siteadmin($userid) || has_capability('local/batchanalytics:manage', $systemcontext, $userid)) {
        return true;
    }

    $courses = enrol_get_users_courses($userid, true, ['id']);
    foreach ($courses as $course) {
        $coursecontext = context_course::instance((int)$course->id, IGNORE_MISSING);
        if (!$coursecontext) {
            continue;
        }
        if (
            has_capability('local/batchanalytics:viewtickets', $coursecontext, $userid) ||
            has_capability('local/batchanalytics:managetickets', $coursecontext, $userid)
        ) {
            return true;
        }
    }

    return false;
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
            echo json_encode($service->get_ticket_dashboard_data($USER->id));
            die();
        }

        if ($action === 'resolveticket') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new moodle_exception('invalidrequest');
            }
            require_sesskey();
            $payload = optional_param('payload', '', PARAM_RAW);
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                echo json_encode(['error' => 'Invalid JSON payload']);
                die();
            }
            $ticketid = (int)($decoded['ticketid'] ?? 0);
            $feedback = (string)($decoded['feedback'] ?? '');
            $result = $service->resolve_ticket($ticketid, $USER->id, $feedback);
            echo json_encode([
                'status' => 'ok',
                'message' => get_string('ticket_resolve_success', 'local_batchanalytics'),
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
        if ($action === 'updateticket') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new moodle_exception('invalidrequest');
            }
            require_sesskey();
            $payload = optional_param('payload', '', PARAM_RAW);
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
        debugging('Ticket API Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        echo json_encode(['error' => 'An error occurred processing your request.']);
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
