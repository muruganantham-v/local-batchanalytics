<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Cliq template configuration page.
 *
 * @package    local_batchanalytics
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/classes/cliq_service.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

admin_externalpage_setup('local_batchanalytics_cliq_templates');

$PAGE->set_url(new moodle_url('/local/batchanalytics/cliq_templates.php'));
$PAGE->set_title(get_string('configure_cliq_templates', 'local_batchanalytics'));
$PAGE->set_heading(get_string('configure_cliq_templates', 'local_batchanalytics'));

if (optional_param('submitbutton', '', PARAM_RAW) !== '') {
    require_sesskey();
    set_config(
        'cliq_ticket_raise_subject',
        optional_param('cliq_ticket_raise_subject', '', PARAM_RAW_TRIMMED),
        'local_batchanalytics'
    );
    set_config(
        'cliq_ticket_raise_body',
        optional_param('cliq_ticket_raise_body', '', PARAM_RAW),
        'local_batchanalytics'
    );
    set_config(
        'cliq_ticket_update_subject',
        optional_param('cliq_ticket_update_subject', '', PARAM_RAW_TRIMMED),
        'local_batchanalytics'
    );
    set_config(
        'cliq_ticket_update_body',
        optional_param('cliq_ticket_update_body', '', PARAM_RAW),
        'local_batchanalytics'
    );
    redirect(
        new moodle_url('/local/batchanalytics/cliq_templates.php'),
        get_string('cliq_templates_saved', 'local_batchanalytics'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$raise_subject = get_config('local_batchanalytics', 'cliq_ticket_raise_subject');
$raise_body = get_config('local_batchanalytics', 'cliq_ticket_raise_body');
$update_subject = get_config('local_batchanalytics', 'cliq_ticket_update_subject');
$update_body = get_config('local_batchanalytics', 'cliq_ticket_update_body');

if (trim((string)$raise_subject) === '') {
    $raise_subject = \local_batchanalytics\cliq_service::get_default_ticket_raise_subject();
}
if (trim((string)$raise_body) === '') {
    $raise_body = \local_batchanalytics\cliq_service::get_default_ticket_raise_body();
}
if (trim((string)$update_subject) === '') {
    $update_subject = \local_batchanalytics\cliq_service::get_default_ticket_update_subject();
}
if (trim((string)$update_body) === '') {
    $update_body = \local_batchanalytics\cliq_service::get_default_ticket_update_body();
}

$output = $PAGE->get_renderer('core');
$action = new moodle_url('/local/batchanalytics/cliq_templates.php');

$placeholders = array_map(static function(string $placeholder): string {
    return '{{' . $placeholder . '}}';
}, \local_batchanalytics\cliq_service::get_placeholders());

$form = html_writer::start_tag('form', ['method' => 'post', 'action' => $action->out(false), 'class' => 'local-batchanalytics-cliq-form']);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$form .= html_writer::tag('h3', get_string('cliq_available_placeholders', 'local_batchanalytics'));
$form .= html_writer::tag('p', get_string('cliq_available_placeholders_desc', 'local_batchanalytics'));
$form .= html_writer::tag('div', implode(' ', array_map(static function(string $placeholder): string {
    return html_writer::tag('code', s($placeholder));
}, $placeholders)), ['class' => 'local-batchanalytics-placeholder-list']);

$form .= html_writer::tag('h3', get_string('cliq_ticket_raise_template', 'local_batchanalytics'));
$form .= html_writer::tag('div',
    html_writer::tag('label', get_string('cliq_template_title', 'local_batchanalytics'), ['for' => 'id_cliq_ticket_raise_subject']) .
    html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'id_cliq_ticket_raise_subject',
        'name' => 'cliq_ticket_raise_subject',
        'value' => $raise_subject,
        'class' => 'form-control',
        'required' => 'required',
    ]),
    ['class' => 'form-group']
);
$form .= html_writer::tag('div',
    html_writer::tag('label', get_string('cliq_template_message_body', 'local_batchanalytics'), ['for' => 'id_cliq_ticket_raise_body']) .
    html_writer::tag('textarea', s($raise_body), [
        'id' => 'id_cliq_ticket_raise_body',
        'name' => 'cliq_ticket_raise_body',
        'class' => 'form-control',
        'rows' => 12,
        'required' => 'required',
    ]),
    ['class' => 'form-group']
);

$form .= html_writer::tag('h3', get_string('cliq_ticket_update_template', 'local_batchanalytics'));
$form .= html_writer::tag('div',
    html_writer::tag('label', get_string('cliq_template_title', 'local_batchanalytics'), ['for' => 'id_cliq_ticket_update_subject']) .
    html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'id_cliq_ticket_update_subject',
        'name' => 'cliq_ticket_update_subject',
        'value' => $update_subject,
        'class' => 'form-control',
        'required' => 'required',
    ]),
    ['class' => 'form-group']
);
$form .= html_writer::tag('div',
    html_writer::tag('label', get_string('cliq_template_message_body', 'local_batchanalytics'), ['for' => 'id_cliq_ticket_update_body']) .
    html_writer::tag('textarea', s($update_body), [
        'id' => 'id_cliq_ticket_update_body',
        'name' => 'cliq_ticket_update_body',
        'class' => 'form-control',
        'rows' => 12,
        'required' => 'required',
    ]),
    ['class' => 'form-group']
);
$form .= html_writer::tag('div',
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'name' => 'submitbutton',
        'value' => get_string('savechanges'),
        'class' => 'btn btn-primary',
    ]) . ' ' . html_writer::link(
        new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics']),
        get_string('cancel'),
        ['class' => 'btn btn-secondary']
    ),
    ['class' => 'form-group']
);
$form .= html_writer::end_tag('form');

echo $output->header();
echo $output->heading(get_string('configure_cliq_templates', 'local_batchanalytics'));
echo $form;
echo $output->footer();
