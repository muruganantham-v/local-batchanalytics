<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Ticket template configuration page.
 *
 * @package    local_batchanalytics
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

admin_externalpage_setup('local_batchanalytics_ticket_templates');

$PAGE->set_url(new moodle_url('/local/batchanalytics/ticket_templates.php'));
$PAGE->set_title(get_string('manage_ticket_templates', 'local_batchanalytics'));
$PAGE->set_heading(get_string('manage_ticket_templates', 'local_batchanalytics'));
$PAGE->requires->css('/local/batchanalytics/styles.css');
$PAGE->requires->js_init_code(<<<'JS'
document.addEventListener('DOMContentLoaded', function() {
    var hidden = document.getElementById('ba-ticket-templates-json');
    var form = hidden ? hidden.closest('form') : null;
    var tbody = document.querySelector('[data-ticket-template-tbody]');
    var addBtn = document.querySelector('[data-ticket-template-add]');
    var modal = document.getElementById('ba-ticket-template-modal');
    var modalTitle = document.getElementById('ba-ticket-template-modal-title');
    var titleInput = document.getElementById('ba-ticket-template-title');
    var bodyInput = document.getElementById('ba-ticket-template-body');
    var viewTitle = document.getElementById('ba-ticket-template-view-title');
    var viewBody = document.getElementById('ba-ticket-template-view-body');
    var editArea = document.getElementById('ba-ticket-template-edit-area');
    var viewArea = document.getElementById('ba-ticket-template-view-area');
    var saveBtn = document.getElementById('ba-ticket-template-save');
    var cancelBtn = document.getElementById('ba-ticket-template-cancel');
    var closeBtn = document.getElementById('ba-ticket-template-close');
    var templates = [];
    var activeId = null;
    var activeMode = 'view';
    var isSubmitting = false;

    if (!hidden || !tbody || !modal) {
        return;
    }

    try {
        templates = JSON.parse(hidden.value || '[]');
        if (!Array.isArray(templates)) {
            templates = [];
        }
    } catch (e) {
        templates = [];
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(match) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[match];
        });
    }

    function makeId() {
        return 'template_' + Date.now() + '_' + Math.random().toString(16).slice(2);
    }

    function formatDate(timestamp) {
        var value = Number(timestamp || 0);
        if (!value) {
            return '-';
        }
        var date = new Date(value * 1000);
        if (Number.isNaN(date.getTime())) {
            return '-';
        }
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return String(date.getDate()).padStart(2, '0') + '-' + months[date.getMonth()] + '-' + date.getFullYear();
    }

    function syncHidden() {
        hidden.value = JSON.stringify(templates);
    }

    function submitTemplates() {
        syncHidden();
        if (!form || isSubmitting) {
            return;
        }
        isSubmitting = true;
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function renderTable() {
        syncHidden();
        if (!templates.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="ba-cliq-empty">No ticket templates configured.</td></tr>';
            return;
        }

        tbody.innerHTML = templates.map(function(template) {
            return '<tr data-template-id="' + escapeHtml(template.id) + '">' +
                '<td>' + escapeHtml(template.title || '-') + '</td>' +
                '<td><span class="ba-ticket-template-description">' + escapeHtml(template.body || '-') + '</span></td>' +
                '<td>' + escapeHtml(formatDate(template.timecreated)) + '</td>' +
                '<td><div class="ba-ticket-actions-cell ba-ticket-template-actions">' +
                    '<button type="button" class="btn btn-sm btn-secondary" data-ticket-template-view="' + escapeHtml(template.id) + '">View</button>' +
                    '<button type="button" class="btn btn-sm btn-primary" data-ticket-template-edit="' + escapeHtml(template.id) + '">Edit</button>' +
                    '<button type="button" class="btn btn-sm btn-danger" data-ticket-template-delete="' + escapeHtml(template.id) + '">Delete</button>' +
                '</div></td>' +
            '</tr>';
        }).join('');
    }

    function findTemplate(id) {
        return templates.find(function(template) {
            return String(template.id) === String(id);
        }) || null;
    }

    function openModal(mode, id) {
        activeMode = mode;
        activeId = id || null;
        var template = activeId ? findTemplate(activeId) : null;
        var isView = mode === 'view';

        modalTitle.textContent = isView ? 'View Ticket Template' : (template ? 'Edit Ticket Template' : 'Add Ticket Template');
        editArea.hidden = isView;
        viewArea.hidden = !isView;
        saveBtn.hidden = isView;

        if (isView && template) {
            viewTitle.textContent = template.title || '-';
            viewBody.textContent = template.body || '-';
        } else {
            titleInput.value = template ? (template.title || '') : '';
            bodyInput.value = template ? (template.body || '') : '';
        }

        modal.hidden = false;
        if (!isView) {
            titleInput.focus();
        }
    }

    function closeModal() {
        modal.hidden = true;
        activeId = null;
        activeMode = 'view';
    }

    addBtn.addEventListener('click', function() {
        openModal('edit', null);
    });

    tbody.addEventListener('click', function(event) {
        var view = event.target.closest('[data-ticket-template-view]');
        var edit = event.target.closest('[data-ticket-template-edit]');
        var del = event.target.closest('[data-ticket-template-delete]');
        if (view) {
            openModal('view', view.dataset.ticketTemplateView);
            return;
        }
        if (edit) {
            openModal('edit', edit.dataset.ticketTemplateEdit);
            return;
        }
        if (del) {
            if (!window.confirm('Delete this ticket template?')) {
                return;
            }
            templates = templates.filter(function(template) {
                return String(template.id) !== String(del.dataset.ticketTemplateDelete);
            });
            renderTable();
            submitTemplates();
        }
    });

    saveBtn.addEventListener('click', function() {
        var title = titleInput.value.trim();
        var body = bodyInput.value.trim();
        if (!title || !body) {
            alert('Ticket title and description are required.');
            return;
        }
        var now = Math.floor(Date.now() / 1000);
        var template = activeId ? findTemplate(activeId) : null;
        if (template) {
            template.title = title;
            template.body = body;
            template.timemodified = now;
        } else {
            templates.push({
                id: makeId(),
                title: title,
                body: body,
                timecreated: now,
                timemodified: now
            });
        }
        renderTable();
        closeModal();
        submitTemplates();
    });

    [cancelBtn, closeBtn].forEach(function(button) {
        button.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', function(event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    if (form) {
        form.addEventListener('submit', function() {
            syncHidden();
        });
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    renderTable();
});
JS);

/**
 * Return configured ticket templates.
 *
 * @return array
 */
function local_batchanalytics_get_ticket_templates_config(): array {
    $raw = get_config('local_batchanalytics', 'ticket_templates');
    $decoded = !empty($raw) ? json_decode($raw, true) : [];
    if (!is_array($decoded)) {
        return [];
    }

    $now = time();
    $templates = [];
    foreach ($decoded as $index => $template) {
        if (!is_array($template)) {
            continue;
        }
        $title = trim((string)($template['title'] ?? ''));
        $body = trim((string)($template['body'] ?? ''));
        if ($title === '' && $body === '') {
            continue;
        }
        $timecreated = (int)($template['timecreated'] ?? 0);
        if ($timecreated <= 0) {
            $timecreated = $now;
        }
        $templates[] = [
            'id' => clean_param((string)($template['id'] ?? ('template_' . ($index + 1))), PARAM_ALPHANUMEXT),
            'title' => $title,
            'body' => $body,
            'timecreated' => $timecreated,
            'timemodified' => max((int)($template['timemodified'] ?? 0), $timecreated),
        ];
    }

    return $templates;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_sesskey();

    $decoded = json_decode(optional_param('templates_json', '[]', PARAM_RAW), true);
    $templates = [];
    $now = time();

    if (is_array($decoded)) {
        foreach ($decoded as $index => $template) {
            if (!is_array($template)) {
                continue;
            }
            $title = clean_param(trim((string)($template['title'] ?? '')), PARAM_TEXT);
            $body = clean_param(trim((string)($template['body'] ?? '')), PARAM_RAW_TRIMMED);
            if ($title === '' && $body === '') {
                continue;
            }
            $timecreated = (int)($template['timecreated'] ?? 0);
            if ($timecreated <= 0) {
                $timecreated = $now;
            }
            $templates[] = [
                'id' => clean_param((string)($template['id'] ?? ('template_' . ($index + 1))), PARAM_ALPHANUMEXT),
                'title' => $title,
                'body' => $body,
                'timecreated' => $timecreated,
                'timemodified' => max((int)($template['timemodified'] ?? 0), $timecreated),
            ];
        }
    }

    set_config('ticket_templates', json_encode(array_values($templates)), 'local_batchanalytics');
    redirect(
        new moodle_url('/local/batchanalytics/ticket_templates.php'),
        get_string('ticket_templates_saved', 'local_batchanalytics'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$templates = local_batchanalytics_get_ticket_templates_config();
$action = new moodle_url('/local/batchanalytics/ticket_templates.php');
$output = $PAGE->get_renderer('core');
$templatesjson = json_encode(array_values($templates));

$form = html_writer::start_tag('form', ['method' => 'post', 'action' => $action->out(false)]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$form .= html_writer::tag('p', get_string('ticket_templates_desc', 'local_batchanalytics'), ['class' => 'ba-ticket-template-desc']);
$form .= html_writer::empty_tag('input', [
    'type' => 'hidden',
    'id' => 'ba-ticket-templates-json',
    'name' => 'templates_json',
    'value' => $templatesjson,
]);
$form .= html_writer::tag('div',
    html_writer::tag('button', get_string('add_ticket_template', 'local_batchanalytics'), [
        'type' => 'button',
        'class' => 'btn btn-primary',
        'data-ticket-template-add' => '1',
    ]),
    ['class' => 'ba-top-actions']
);
$form .= html_writer::start_tag('div', ['class' => 'ba-cliq-table-wrap']);
$form .= html_writer::start_tag('table', ['class' => 'generaltable ba-cliq-table']);
$form .= html_writer::tag('thead', html_writer::tag('tr',
    html_writer::tag('th', 'Ticket title') .
    html_writer::tag('th', 'Ticket description') .
    html_writer::tag('th', 'Created date') .
    html_writer::tag('th', 'Action')
));
$form .= html_writer::tag('tbody', '', ['data-ticket-template-tbody' => '1']);
$form .= html_writer::end_tag('table');
$form .= html_writer::end_tag('div');
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
    ['class' => 'form-group ba-ticket-template-save-row']
);
$form .= html_writer::end_tag('form');

$modal = html_writer::start_tag('div', [
    'id' => 'ba-ticket-template-modal',
    'class' => 'ba-modal-overlay',
    'hidden' => 'hidden',
]);
$modal .= html_writer::start_tag('div', ['class' => 'ba-modal-container ba-cliq-modal']);
$modal .= html_writer::tag('div',
    html_writer::tag('h3', '', ['id' => 'ba-ticket-template-modal-title']) .
    html_writer::tag('button', '&times;', [
        'type' => 'button',
        'class' => 'ba-modal-close',
        'id' => 'ba-ticket-template-close',
        'aria-label' => get_string('modal_close', 'local_batchanalytics'),
    ]),
    ['class' => 'ba-modal-header']
);
$modal .= html_writer::start_tag('div', ['class' => 'ba-modal-body ba-cliq-modal-body']);
$modal .= html_writer::tag('div',
    html_writer::tag('div',
        html_writer::tag('span', 'Ticket title') . html_writer::tag('h4', '', ['id' => 'ba-ticket-template-view-title']),
        ['class' => 'ba-cliq-message-section']
    ) .
    html_writer::tag('div',
        html_writer::tag('span', 'Ticket description') . html_writer::tag('pre', '', ['id' => 'ba-ticket-template-view-body']),
        ['class' => 'ba-cliq-message-section']
    ),
    ['id' => 'ba-ticket-template-view-area']
);
$modal .= html_writer::tag('div',
    html_writer::tag('div',
        html_writer::tag('label', 'Ticket title', ['for' => 'ba-ticket-template-title']) .
        html_writer::empty_tag('input', [
            'type' => 'text',
            'id' => 'ba-ticket-template-title',
            'class' => 'form-control',
        ]),
        ['class' => 'form-group']
    ) .
    html_writer::tag('div',
        html_writer::tag('label', 'Ticket description', ['for' => 'ba-ticket-template-body']) .
        html_writer::tag('textarea', '', [
            'id' => 'ba-ticket-template-body',
            'class' => 'form-control',
            'rows' => 7,
        ]),
        ['class' => 'form-group']
    ),
    ['id' => 'ba-ticket-template-edit-area']
);
$modal .= html_writer::end_tag('div');
$modal .= html_writer::tag('div',
    html_writer::tag('button', get_string('cancel'), [
        'type' => 'button',
        'class' => 'btn btn-secondary',
        'id' => 'ba-ticket-template-cancel',
    ]) . ' ' .
    html_writer::tag('button', get_string('savechanges'), [
        'type' => 'button',
        'class' => 'btn btn-primary',
        'id' => 'ba-ticket-template-save',
    ]),
    ['class' => 'ba-ticket-view-footer ba-ticket-right-actions']
);
$modal .= html_writer::end_tag('div');
$modal .= html_writer::end_tag('div');

echo $output->header();
echo $output->heading(get_string('manage_ticket_templates', 'local_batchanalytics'));
echo $form;
echo $modal;
echo $output->footer();







