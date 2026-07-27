<?php
if (!empty($_REQUEST['action'])) {
    ob_start();
}

require_once(__DIR__ . '/../../config.php');

require_login();

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$course = get_course($courseid);
$context = context_course::instance($courseid);
$systemcontext = context_system::instance();
$canmanage = is_siteadmin() || has_capability('local/batchanalytics:manage', $systemcontext);
$canview = has_capability('local/batchanalytics:viewmaac', $context)
    || has_capability('local/batchanalytics:editmaac', $context);
$canedit = $canmanage || has_capability('local/batchanalytics:editmaac', $context);
if (!$canmanage && !$canview) {
    throw new required_capability_exception($context, 'local/batchanalytics:viewmaac', 'nopermissions', '');
}

$service = new \local_batchanalytics\activity_tracker_service();
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new moodle_exception('invalidrequest');
        }
        if (!confirm_sesskey()) {
            throw new moodle_exception('invalidsesskey');
        }
        if ($action === 'getdata') {
            $data = $service->get_course_data($courseid);
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data);
            die();
        }
        if ($action === 'save') {
            if (!$canedit) {
                throw new required_capability_exception($context, 'local/batchanalytics:editmaac', 'nopermissions', '');
            }
            $cmid = required_param('cmid', PARAM_INT);
            $completed = required_param('completed', PARAM_BOOL);
            $completiondate = optional_param('completiondate', '', PARAM_RAW_TRIMMED);
            $data = $service->save_activity_status($courseid, $USER->id, $cmid, (bool)$completed, $completiondate);
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data);
            die();
        }
        throw new moodle_exception('invalidrequest');
    } catch (\Throwable $e) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        error_log('Module Tracker API Error: ' . $e->getMessage());
        $message = $service->is_table_available()
            ? 'Unable to process the Module Tracker request. Please check the Moodle error log.'
            : get_string('activity_tracker_upgrade_required', 'local_batchanalytics');
        echo json_encode(['error' => $message]);
        die();
    }
}

// This is a standalone tracker page, not a course page, so omit course navigation.
$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/batchanalytics/activity_tracker.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('module_tracker', 'local_batchanalytics'));
$PAGE->set_heading('');
$styleurl = new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime(__DIR__ . '/styles.css')]);
$scripturl = new moodle_url('/local/batchanalytics/activity_tracker.js', ['v' => filemtime(__DIR__ . '/activity_tracker.js')]);
$courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
$maacurl = new moodle_url('/local/batchanalytics/maac.php', ['courseid' => $courseid]);
$PAGE->requires->css($styleurl);
$PAGE->requires->js($scripturl);

echo $OUTPUT->header();
echo '<div class="local-batchanalytics-activity-tracker" data-courseid="' . (int)$courseid
    . '" data-sesskey="' . sesskey() . '" data-courseurl="' . s($courseurl->out(false))
    . '" data-maacurl="' . s($maacurl->out(false)) . '" data-canedit="' . ($canedit ? '1' : '0') . '">';
echo '<div id="ba-activity-tracker-app"></div>';
echo '</div>';
echo $OUTPUT->footer();
