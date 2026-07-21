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
$PAGE->requires->css('/local/batchanalytics/styles.css');
$PAGE->requires->js_init_code(<<<'JS'
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.ba-cliq-template-toggle').forEach(function(button) {
        button.addEventListener('click', function() {
            const section = button.closest('.ba-cliq-template-section');
            if (!section) {
                return;
            }
            const collapsed = section.classList.toggle('is-collapsed');
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });
    });
});
JS);

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
    set_config(
        'cliq_ticket_resolved_subject',
        optional_param('cliq_ticket_resolved_subject', '', PARAM_RAW_TRIMMED),
        'local_batchanalytics'
    );
    set_config(
        'cliq_ticket_resolved_body',
        optional_param('cliq_ticket_resolved_body', '', PARAM_RAW),
        'local_batchanalytics'
    );
    set_config(
        'cliq_ticket_auto_pm_subject',
        optional_param('cliq_ticket_auto_pm_subject', '', PARAM_RAW_TRIMMED),
        'local_batchanalytics'
    );
    set_config(
        'cliq_ticket_auto_pm_body',
        optional_param('cliq_ticket_auto_pm_body', '', PARAM_RAW),
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
$resolved_subject = get_config('local_batchanalytics', 'cliq_ticket_resolved_subject');
$resolved_body = get_config('local_batchanalytics', 'cliq_ticket_resolved_body');
$autopm_subject = get_config('local_batchanalytics', 'cliq_ticket_auto_pm_subject');
$autopm_body = get_config('local_batchanalytics', 'cliq_ticket_auto_pm_body');

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
if (trim((string)$resolved_subject) === '') {
    $resolved_subject = \local_batchanalytics\cliq_service::get_default_ticket_resolved_subject();
}
if (trim((string)$resolved_body) === '') {
    $resolved_body = \local_batchanalytics\cliq_service::get_default_ticket_resolved_body();
}
if (trim((string)$autopm_subject) === '') {
    $autopm_subject = \local_batchanalytics\cliq_service::get_default_ticket_auto_pm_subject();
}
if (trim((string)$autopm_body) === '') {
    $autopm_body = \local_batchanalytics\cliq_service::get_default_ticket_auto_pm_body();
}

/**
 * Render a collapsible Cliq template section.
 *
 * @param string $title
 * @param string $subjectname
 * @param string $subjectvalue
 * @param string $bodyname
 * @param string $bodyvalue
 * @param bool $collapsed
 * @return string
 */
function local_batchanalytics_render_cliq_template_section(string $title, string $subjectname, string $subjectvalue,
        string $bodyname, string $bodyvalue, bool $collapsed = false): string {
    $subjectid = 'id_' . $subjectname;
    $bodyid = 'id_' . $bodyname;
    $sectionclasses = 'ba-cliq-template-section' . ($collapsed ? ' is-collapsed' : '');

    $toggle = html_writer::tag('button',
        html_writer::span($title) . html_writer::span('', 'ba-cliq-template-chevron'),
        [
            'type' => 'button',
            'class' => 'ba-cliq-template-toggle',
            'aria-expanded' => $collapsed ? 'false' : 'true',
        ]
    );

    $content = html_writer::tag('div',
        html_writer::tag('div',
            html_writer::tag('label', get_string('cliq_template_title', 'local_batchanalytics'), ['for' => $subjectid]) .
            html_writer::empty_tag('input', [
                'type' => 'text',
                'id' => $subjectid,
                'name' => $subjectname,
                'value' => $subjectvalue,
                'class' => 'form-control',
                'required' => 'required',
            ]),
            ['class' => 'form-group']
        ) .
        html_writer::tag('div',
            html_writer::tag('label', get_string('cliq_template_message_body', 'local_batchanalytics'), ['for' => $bodyid]) .
            html_writer::tag('textarea', s($bodyvalue), [
                'id' => $bodyid,
                'name' => $bodyname,
                'class' => 'form-control',
                'rows' => 12,
                'required' => 'required',
            ]),
            ['class' => 'form-group']
        ),
        ['class' => 'ba-cliq-template-content']
    );

    return html_writer::tag('section', $toggle . $content, ['class' => $sectionclasses]);
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

$form .= local_batchanalytics_render_cliq_template_section(
    get_string('cliq_ticket_raise_template', 'local_batchanalytics'),
    'cliq_ticket_raise_subject',
    $raise_subject,
    'cliq_ticket_raise_body',
    $raise_body
);
$form .= local_batchanalytics_render_cliq_template_section(
    get_string('cliq_ticket_update_template', 'local_batchanalytics'),
    'cliq_ticket_update_subject',
    $update_subject,
    'cliq_ticket_update_body',
    $update_body,
    true
);
$form .= local_batchanalytics_render_cliq_template_section(
    get_string('cliq_ticket_resolved_template', 'local_batchanalytics'),
    'cliq_ticket_resolved_subject',
    $resolved_subject,
    'cliq_ticket_resolved_body',
    $resolved_body,
    true
);
$form .= local_batchanalytics_render_cliq_template_section(
    get_string('cliq_ticket_auto_pm_template', 'local_batchanalytics'),
    'cliq_ticket_auto_pm_subject',
    $autopm_subject,
    'cliq_ticket_auto_pm_body',
    $autopm_body,
    true
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
