<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Cliq message history page.
 *
 * @package    local_batchanalytics
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

/**
 * Extracts the batch token from the course naming pattern.
 *
 * @param string $coursefullname
 * @param string $courseshortname
 * @return string
 */
if (!function_exists('local_batchanalytics_cliq_history_batch_name')) {
    function local_batchanalytics_cliq_history_batch_name(string $coursefullname, string $courseshortname): string {
        $source = $coursefullname !== '' ? $coursefullname : $courseshortname;
        if (preg_match('/:\s*([^:]+)$/', $source, $matches)) {
            return trim($matches[1]);
        }
        return $courseshortname !== '' ? $courseshortname : ($coursefullname !== '' ? $coursefullname : '-');
    }
}

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

admin_externalpage_setup('local_batchanalytics_cliq_message_history');

$PAGE->set_url(new moodle_url('/local/batchanalytics/cliq_message_history.php'));
$PAGE->set_title(get_string('view_cliq_message_history', 'local_batchanalytics'));
$PAGE->set_heading(get_string('view_cliq_message_history', 'local_batchanalytics'));

$stylepath = $CFG->dirroot . '/local/batchanalytics/styles.css';
$scriptpath = $CFG->dirroot . '/local/batchanalytics/cliq_message_history.js';
if (file_exists($stylepath)) {
    $PAGE->requires->css(new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime($stylepath)]));
}
if (file_exists($scriptpath)) {
    $PAGE->requires->js(new moodle_url('/local/batchanalytics/cliq_message_history.js', ['v' => filemtime($scriptpath)]));
}

$output = $PAGE->get_renderer('core');
$dbman = $DB->get_manager();
$historyavailable = $dbman->table_exists(new xmldb_table('local_batchanalytics_cliq_history'));
$recordsdata = [];

if ($historyavailable) {
    $sql = "SELECT h.*,
                   c.fullname AS coursename,
                   c.shortname AS courseshortname,
                   " . $DB->sql_fullname('u.firstname', 'u.lastname') . " AS recipientname
              FROM {local_batchanalytics_cliq_history} h
         LEFT JOIN {course} c ON c.id = h.courseid
         LEFT JOIN {user} u ON u.id = h.recipientuserid
          ORDER BY h.timecreated DESC, h.id DESC";
    $records = $DB->get_records_sql($sql);

    foreach ($records as $record) {
        $coursename = trim((string)($record->coursename ?? ''));
        $courseshortname = trim((string)($record->courseshortname ?? ''));
        $course = $coursename !== '' ? $coursename : ($courseshortname !== '' ? $courseshortname : '-');
        $timecreated = (int)($record->timecreated ?? 0);

        $recordsdata[] = [
            'id' => (int)$record->id,
            'ticketid' => (int)($record->ticketid ?? 0),
            'courseid' => (int)($record->courseid ?? 0),
            'batch' => local_batchanalytics_cliq_history_batch_name($coursename, $courseshortname),
            'course' => $course,
            'courseshortname' => $courseshortname,
            'recipientname' => trim((string)($record->recipientname ?? '')) ?: '-',
            'recipientemail' => (string)($record->recipientemail ?? ''),
            'messagetype' => (string)($record->messagetype ?? ''),
            'subject' => (string)($record->subject ?? ''),
            'messagebody' => (string)($record->messagebody ?? ''),
            'status' => (string)($record->status ?? ''),
            'responsebody' => (string)($record->responsebody ?? ''),
            'errormessage' => (string)($record->errormessage ?? ''),
            'timecreated' => $timecreated,
            'date' => $timecreated > 0 ? userdate($timecreated) : '-',
            'datekey' => $timecreated > 0 ? userdate($timecreated, '%Y-%m-%d', 99, false) : '',
        ];
    }
}

echo $output->header();
echo html_writer::tag('h3', 'View message History', ['class' => 'ba-cliq-history-title']);

if (!$historyavailable) {
    echo $output->notification(get_string('cliq_history_table_missing', 'local_batchanalytics'), 'warning');
}

echo html_writer::div('', 'ba-cliq-history-app', ['id' => 'ba-cliq-history-app']);
echo html_writer::script('window.BA_CLIQ_HISTORY = ' . json_encode(
    $recordsdata,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) . ';');

echo html_writer::link(
    new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics']),
    get_string('cliq_back_to_settings', 'local_batchanalytics'),
    ['class' => 'btn btn-secondary ba-cliq-back-settings']
);

echo $output->footer();