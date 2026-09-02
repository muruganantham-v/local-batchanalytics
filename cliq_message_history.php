<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);
admin_externalpage_setup('local_batchanalytics_cliq_message_history');

$page = max(0, optional_param('page', 0, PARAM_INT));
$viewid = optional_param('viewid', 0, PARAM_INT);
$filters = [
    'batch' => optional_param('batch', '', PARAM_TEXT),
    'course' => optional_param('course', '', PARAM_TEXT),
    'recipient' => optional_param('recipient', '', PARAM_TEXT),
    'subject' => optional_param('subject', '', PARAM_TEXT),
    'status' => optional_param('status', '', PARAM_ALPHANUMEXT),
    'datefrom' => optional_param('datefrom', '', PARAM_RAW_TRIMMED),
    'dateto' => optional_param('dateto', '', PARAM_RAW_TRIMMED),
];
$perpage = 50;

$PAGE->set_url(new moodle_url('/local/batchanalytics/cliq_message_history.php'));
$PAGE->set_title(get_string('view_cliq_message_history', 'local_batchanalytics'));
$PAGE->set_heading(get_string('view_cliq_message_history', 'local_batchanalytics'));
$stylepath = $CFG->dirroot . '/local/batchanalytics/styles.css';
if (file_exists($stylepath)) {
    $PAGE->requires->css(new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime($stylepath)]));
}

$output = $PAGE->get_renderer('core');
$historytable = new xmldb_table('local_batchanalytics_cliq_history');
$historyavailable = $DB->get_manager()->table_exists($historytable);
$where = [];
$params = [];
$fullname = $DB->sql_fullname('u.firstname', 'u.lastname');
foreach (['batch', 'course'] as $field) {
    if ($filters[$field] !== '') {
        $key = $field . 'like';
        $params[$key] = '%' . $filters[$field] . '%';
        $where[] = '(' . $DB->sql_like('c.fullname', ':' . $key, false) . ' OR '
            . $DB->sql_like('c.shortname', ':' . $key, false) . ')';
    }
}
if ($filters['recipient'] !== '') {
    $params['recipientlike'] = '%' . $filters['recipient'] . '%';
    $where[] = '(' . $DB->sql_like($fullname, ':recipientlike', false) . ' OR '
        . $DB->sql_like('h.recipientemail', ':recipientlike', false) . ')';
}
if ($filters['subject'] !== '') {
    $params['subjectlike'] = '%' . $filters['subject'] . '%';
    $where[] = $DB->sql_like('h.subject', ':subjectlike', false);
}
if ($filters['status'] !== '') {
    $params['status'] = $filters['status'];
    $where[] = 'h.status = :status';
}
foreach (['datefrom' => '>=', 'dateto' => '<'] as $field => $operator) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$field])) {
        $timestamp = strtotime($filters[$field] . ($field === 'dateto' ? ' +1 day' : ' 00:00:00'));
        if ($timestamp !== false) {
            $params[$field] = $timestamp;
            $where[] = 'h.timecreated ' . $operator . ' :' . $field;
        }
    }
}
$from = ' FROM {local_batchanalytics_cliq_history} h
          LEFT JOIN {course} c ON c.id = h.courseid
          LEFT JOIN {user} u ON u.id = h.recipientuserid';
$wheresql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
$records = [];
$total = 0;
if ($historyavailable) {
    $total = (int)$DB->count_records_sql('SELECT COUNT(1)' . $from . $wheresql, $params);
    $sql = 'SELECT h.id, h.ticketid, h.courseid, h.recipientemail, h.messagetype, h.subject, h.status, h.timecreated,
                   c.fullname AS coursename, c.shortname AS courseshortname, ' . $fullname . ' AS recipientname'
        . $from . $wheresql . ' ORDER BY h.timecreated DESC, h.id DESC';
    $records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);
}

echo $output->header();
echo html_writer::tag('h3', get_string('view_cliq_message_history', 'local_batchanalytics'), ['class' => 'ba-cliq-history-title']);
if (!$historyavailable) {
    echo $output->notification(get_string('cliq_history_table_missing', 'local_batchanalytics'), 'warning');
    echo $output->footer();
    exit;
}

$formurl = new moodle_url('/local/batchanalytics/cliq_message_history.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl->out(false), 'class' => 'ba-cliq-history-panel']);
echo html_writer::tag('h4', 'Cliq Message History');
echo html_writer::start_div('ba-cliq-filter-grid');
foreach (['batch' => 'Batch', 'course' => 'Course', 'recipient' => 'Recipient', 'subject' => 'Subject',
        'datefrom' => 'Date From', 'dateto' => 'Date To'] as $name => $label) {
    $type = strpos($name, 'date') === 0 ? 'date' : 'search';
    echo html_writer::tag('label', html_writer::tag('span', $label) . html_writer::empty_tag('input', [
        'type' => $type, 'name' => $name, 'value' => $filters[$name],
    ]), ['class' => 'ba-cliq-filter-field']);
}
echo html_writer::tag('label', html_writer::tag('span', 'Status') . html_writer::select(
    ['' => 'All statuses', 'success' => 'Success', 'failed' => 'Failed', 'no user found' => 'No user found'],
    'status', $filters['status'], false
), ['class' => 'ba-cliq-filter-field']);
echo html_writer::tag('div', html_writer::tag('span', '&nbsp;') . html_writer::tag('button', 'Apply filters', [
    'type' => 'submit', 'class' => 'btn btn-primary',
]), ['class' => 'ba-cliq-filter-field']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

if (empty($records)) {
    echo html_writer::div($total ? 'No messages match this page.' : get_string('cliq_message_history_empty', 'local_batchanalytics'), 'ba-cliq-empty');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'ba-cliq-table';
    $table->head = ['Course', 'Recipient', 'Date', 'Subject', 'Status', 'Action'];
    foreach ($records as $record) {
        $course = trim((string)$record->coursename) ?: (trim((string)$record->courseshortname) ?: '-');
        $recipient = html_writer::tag('strong', trim((string)$record->recipientname) ?: '-')
            . html_writer::tag('span', s((string)$record->recipientemail));
        $viewurl = new moodle_url('/local/batchanalytics/cliq_message_history.php', array_merge($filters, [
            'page' => $page, 'viewid' => $record->id,
        ]));
        $table->data[] = [s($course), html_writer::div($recipient, 'ba-cliq-recipient'), userdate((int)$record->timecreated),
            s((string)$record->subject), s(ucfirst((string)$record->status)),
            html_writer::link($viewurl, 'View Message', ['class' => 'ba-btn ba-btn-sm ba-btn-view'])];
    }
    echo html_writer::div(html_writer::table($table), 'ba-cliq-table-wrap');
}
$baseparams = array_filter($filters, static fn($value) => $value !== '');
echo $output->paging_bar($total, $page, $perpage, new moodle_url('/local/batchanalytics/cliq_message_history.php', $baseparams));

if ($viewid > 0) {
    $detail = $DB->get_record('local_batchanalytics_cliq_history', ['id' => $viewid], '*', IGNORE_MISSING);
    if ($detail) {
        $closeurl = new moodle_url('/local/batchanalytics/cliq_message_history.php', array_merge($baseparams, ['page' => $page]));
        echo html_writer::start_div('ba-modal-overlay ba-cliq-modal-overlay');
        echo html_writer::start_div('ba-modal-container ba-cliq-modal');
        echo html_writer::div(html_writer::tag('h3', 'View Message') . html_writer::link($closeurl, '&times;', ['class' => 'ba-modal-close']), 'ba-modal-header');
        echo html_writer::start_div('ba-modal-body ba-cliq-modal-body');
        foreach (['Subject' => $detail->subject, 'Message Body' => $detail->messagebody,
                'Response / Error' => trim((string)$detail->responsebody . "\n" . (string)$detail->errormessage)] as $label => $value) {
            echo html_writer::div(html_writer::tag('span', $label) . html_writer::tag('pre', s($value ?: '-')), 'ba-cliq-message-section');
        }
        echo html_writer::end_div() . html_writer::end_div() . html_writer::end_div();
    }
}
echo html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics']),
    get_string('cliq_back_to_settings', 'local_batchanalytics'), ['class' => 'btn btn-secondary ba-cliq-back-settings']);
echo $output->footer();
