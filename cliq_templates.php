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
 * Zoho Cliq Dynamic Notification Templates & Workflow Rules Management Page.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_batchanalytics\cliq_notification_service;
use local_batchanalytics\cliq_workflow_engine;
use local_batchanalytics\cliq_recipient_resolver;
use local_batchanalytics\util;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// -------------------------------------------------------------------------
// 1. AJAX Action Handlers
// -------------------------------------------------------------------------
if ($action === 'savetemplate') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();
        $id = required_param('id', PARAM_ALPHANUMEXT);
        $title = optional_param('title', '', PARAM_TEXT);
        $recipient = optional_param('recipient', 'CM', PARAM_ALPHANUMEXT);
        $recipient_title = optional_param('recipient_title', '', PARAM_TEXT);
        $trigger = optional_param('trigger', 'Activity due', PARAM_TEXT);
        $template = required_param('template', PARAM_RAW_TRIMMED);
        $enabled = optional_param('enabled', 1, PARAM_INT);
        $threshold_days = optional_param('threshold_days', 0, PARAM_INT);
        $severity = optional_param('severity', 'info', PARAM_ALPHANUMEXT);

        // Workflow rule params
        $condition_metric = optional_param('condition_metric', 'days_before_due', PARAM_ALPHANUMEXT);
        $days_offset = optional_param('days_offset', 0, PARAM_INT);
        $recipient_role = optional_param('recipient_role', 'CM', PARAM_ALPHANUMEXT);
        $escalate_to = optional_param('escalate_to', '', PARAM_ALPHANUMEXT);
        $escalate_days = optional_param('escalate_days', 2, PARAM_INT);

        $template_data = [
            'id'              => $id,
            'title'           => $title ?: $id,
            'recipient'       => $recipient,
            'recipient_title' => $recipient_title ?: $recipient,
            'trigger'         => $trigger,
            'severity'        => $severity,
            'template'        => $template,
            'threshold_days'  => $threshold_days,
            'escalation'      => $escalate_to ? ("Escalate to " . strtoupper($escalate_to) . " after {$escalate_days}d") : '—',
            'enabled'         => !empty($enabled),
        ];

        cliq_notification_service::add_or_update_template($template_data);

        $rule_data = [
            'template_id'         => $id,
            'enabled'             => !empty($enabled) ? 1 : 0,
            'trigger_type'        => in_array($condition_metric, ['batch_created', 'module_completed', 'stage_transition', 'activity_completed']) ? 'event' : 'schedule',
            'condition_metric'    => $condition_metric,
            'condition_value'     => $days_offset,
            'recipient_type'      => $recipient_role,
            'escalate_to'         => $escalate_to ?: null,
            'escalate_days'       => $escalate_days,
            'quiet_hours_enabled' => 1,
        ];
        cliq_workflow_engine::save_rule($rule_data);

        echo json_encode(['success' => true, 'message' => 'Template and workflow rule saved successfully.']);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'deletetemplate') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();
        $id = required_param('id', PARAM_ALPHANUMEXT);
        cliq_notification_service::delete_template($id);
        cliq_workflow_engine::delete_rule($id);
        echo json_encode(['success' => true, 'message' => 'Template deleted successfully.']);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'savetemplates') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();
        $raw_templates = required_param('templates', PARAM_RAW);
        $decoded = json_decode($raw_templates, true);

        if (!is_array($decoded)) {
            throw new moodle_exception('invalidjson', 'error');
        }

        cliq_notification_service::save_templates($decoded);

        // Also save workflow rules if submitted
        $raw_rules = optional_param('rules', '', PARAM_RAW);
        if (!empty($raw_rules)) {
            $decoded_rules = json_decode($raw_rules, true);
            if (is_array($decoded_rules)) {
                cliq_workflow_engine::save_all_rules($decoded_rules);
            }
        }

        echo json_encode(['success' => true, 'message' => 'All templates and workflow rules saved successfully.']);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'clearall') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();
        cliq_notification_service::clear_all_templates();
        cliq_workflow_engine::clear_all_rules();
        echo json_encode(['success' => true, 'message' => 'All templates and workflow rules cleared.']);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'testsend') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();
        $message = required_param('message', PARAM_RAW);
        $userids = optional_param('userids', '', PARAM_RAW_TRIMMED);

        if (trim($message) === '') {
            throw new moodle_exception('error', '', '', null, 'Message text cannot be empty.');
        }

        $res = cliq_notification_service::send_cliq_message($message, $userids);
        echo json_encode([
            'success' => $res['success'],
            'http_code' => $res['http_code'],
            'response' => $res['response'],
            'url_used' => $res['url_used'],
            'message' => $res['success']
                ? 'Message delivered to Zoho Cliq successfully!'
                : ('Zoho Cliq delivery failed (HTTP ' . $res['http_code'] . '): ' . $res['response'])
        ]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'dryrun') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();
        $results = cliq_workflow_engine::evaluate_scheduled_workflows(true, true);
        echo json_encode(array_merge(['success' => true], $results));
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 2. Data Preparation
// -------------------------------------------------------------------------
$templates = cliq_notification_service::get_templates();
$placeholders = cliq_notification_service::PLACEHOLDER_DICTIONARY;
$workflow_rules = cliq_workflow_engine::get_rules();

$pm_count = 0;
$sse_count = 0;
$cm_count = 0;
$lm_count = 0;
$am_count = 0;
foreach ($templates as $t) {
    $rec = $t['recipient'] ?? '';
    if ($rec === 'PM') $pm_count++;
    else if ($rec === 'SSE') $sse_count++;
    else if ($rec === 'CM') $cm_count++;
    else if ($rec === 'LM') $lm_count++;
    else if ($rec === 'AM') $am_count++;
}

// -------------------------------------------------------------------------
// 3. Page Setup
// -------------------------------------------------------------------------
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/cliq_templates.php'));
$PAGE->set_title('Zoho Cliq Notification Templates – Batch Analytics');
$PAGE->set_heading('Zoho Cliq Notification Templates');

// Breadcrumb
$PAGE->navbar->add(get_string('pluginname', 'local_batchanalytics'), new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics']));
$PAGE->navbar->add('Zoho Cliq Templates');

$css_url = new moodle_url('/local/batchanalytics/cliq_templates.css', ['v' => filemtime(__DIR__ . '/cliq_templates.css')]);
$js_url  = new moodle_url('/local/batchanalytics/cliq_templates.js', ['v' => filemtime(__DIR__ . '/cliq_templates.js')]);
$PAGE->requires->css($css_url);
$PAGE->requires->js($js_url);

echo $OUTPUT->header();

echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
echo '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">';
?>

<div class="ba-cliq-page" id="ba-cliq-templates-container"
     data-sesskey="<?= sesskey() ?>"
     data-placeholders="<?= s(json_encode($placeholders)) ?>"
     data-rules="<?= s(json_encode($workflow_rules)) ?>"
     data-cliq-configured="<?= cliq_notification_service::is_configured() ? '1' : '0' ?>"
     data-bot-url="<?= s(cliq_notification_service::get_bot_api_url()) ?>"
     data-user-email="<?= s($USER->email ?? '') ?>">

  <div class="ba-cliq-shell">

    <!-- Header Section -->
    <div class="ba-cliq-header">
      <div class="ba-cliq-header-top">
        <div class="ba-cliq-title-wrap">
          <h1>
            <span>Zoho Cliq Notification Templates</span>
            <span class="ba-cliq-bot-badge">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"></path>
              </svg>
              Dynamic Workflow Engine
            </span>
          </h1>
          <p class="ba-cliq-subtitle">
            Configure dynamic notifications, recipient roles, trigger conditions, and escalation metrics across the batch lifecycle.
          </p>
        </div>

        <div class="ba-cliq-actions">
          <a href="<?= s((new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics']))->out(false)) ?>" class="ba-cliq-btn ba-cliq-btn-secondary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="19" y1="12" x2="5" y2="12"></line>
              <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Back to Plugin Settings
          </a>

          <button type="button" id="ba-cliq-open-add-modal" class="ba-cliq-btn ba-cliq-btn-primary" title="Create a new dynamic notification template">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="12" y1="5" x2="12" y2="19"></line>
              <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            Add Template
          </button>

          <button type="button" id="ba-cliq-open-dryrun-modal" class="ba-cliq-btn ba-cliq-btn-secondary" title="Simulate workflow conditions against active live batches">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            Dry-Run Workflow
          </button>

          <button type="button" id="ba-cliq-open-test-modal" class="ba-cliq-btn" style="background:#059669; border-color:#059669; color:#fff;" title="Send a test notification message to Zoho Cliq">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
            </svg>
            Test Bot Message
          </button>

          <?php if (!empty($templates)): ?>
            <button type="button" id="ba-cliq-clear-all" class="ba-cliq-btn ba-cliq-btn-danger" title="Clear all configured templates">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="3 6 5 6 21 6"></polyline>
                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
              </svg>
              Clear All
            </button>

            <button type="button" id="ba-cliq-save-all" class="ba-cliq-btn ba-cliq-btn-primary">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                <polyline points="17 21 17 13 7 13 7 21"></polyline>
                <polyline points="7 3 7 8 15 8"></polyline>
              </svg>
              Save All Changes
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Controls: Search & Tabs -->
      <div class="ba-cliq-controls">
        <div class="ba-cliq-search-wrap">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input type="text" id="ba-cliq-search" class="ba-cliq-search-input" placeholder="Search templates by ID, title, or keywords…">
        </div>

        <div class="ba-cliq-tabs">
          <div class="ba-cliq-tab active" data-tab="all">
            All <span class="ba-cliq-tab-count"><?= count($templates) ?></span>
          </div>
          <div class="ba-cliq-tab" data-tab="PM">
            Program Manager (PM) <span class="ba-cliq-tab-count"><?= $pm_count ?></span>
          </div>
          <div class="ba-cliq-tab" data-tab="SSE">
            Student Success Exec (SSE) <span class="ba-cliq-tab-count"><?= $sse_count ?></span>
          </div>
          <div class="ba-cliq-tab" data-tab="CM">
            Class Mentor (CM) <span class="ba-cliq-tab-count"><?= $cm_count ?></span>
          </div>
          <div class="ba-cliq-tab" data-tab="LM">
            Lab Mentor (LM) <span class="ba-cliq-tab-count"><?= $lm_count ?></span>
          </div>
          <div class="ba-cliq-tab" data-tab="AM">
            Assistant Manager (AM) <span class="ba-cliq-tab-count"><?= $am_count ?></span>
          </div>
          <div class="ba-cliq-tab" data-tab="placeholders" style="margin-left:8px; border-left:1px solid #cbd5e1; padding-left:14px;">
            Placeholder Cheat-Sheet
          </div>
        </div>
      </div>
    </div>

    <!-- Empty State View (When no templates exist) -->
    <?php if (empty($templates)): ?>
      <div class="ba-cliq-empty-state" id="ba-cliq-empty-state">
        <div class="ba-cliq-empty-icon">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="1.8">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
          </svg>
        </div>
        <h3 class="ba-cliq-empty-title">No Cliq Notification Templates Configured</h3>
        <p class="ba-cliq-empty-desc">
          All hardcoded templates have been cleared. You can now dynamically create customized notification templates with tailored trigger conditions, recipient roles, and escalation metrics.
        </p>
        <button type="button" class="ba-cliq-btn ba-cliq-btn-primary ba-cliq-open-add-modal-btn">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
          </svg>
          Create Your First Template
        </button>
      </div>
    <?php endif; ?>

    <!-- Templates List View -->
    <div class="ba-cliq-templates-list" id="ba-cliq-templates-list" <?= empty($templates) ? 'style="display:none;"' : '' ?>>
      <?php foreach ($templates as $t): ?>
        <?php
          $role = $t['recipient'] ?? 'CM';
          $role_class = 'ba-cliq-role-' . $role;
          $r = $workflow_rules[$t['id']] ?? [
              'enabled' => !empty($t['enabled']) ? 1 : 0,
              'trigger_type' => 'schedule',
              'condition_metric' => 'days_before_due',
              'condition_value' => (int)($t['threshold_days'] ?? 1),
              'recipient_type' => $role,
              'escalate_to' => '',
              'escalate_days' => 2,
          ];
        ?>
        <div class="ba-cliq-card" data-id="<?= s($t['id']) ?>" data-role="<?= s($role) ?>" data-template="<?= s(json_encode($t)) ?>" data-rule="<?= s(json_encode($r)) ?>">

          <div class="ba-cliq-card-header">
            <div class="ba-cliq-card-header-left">
              <span class="ba-cliq-id-badge"><?= s($t['id']) ?></span>
              <span class="ba-cliq-role-badge <?= s($role_class) ?>"><?= s($t['recipient_title'] ?? $role) ?></span>
              <span class="ba-cliq-trigger-title"><?= s($t['title'] ?? ($t['trigger'] ?? '')) ?></span>
            </div>

            <div class="ba-cliq-card-header-right">
              <button type="button" class="ba-cliq-btn-sm ba-cliq-card-edit-btn" data-id="<?= s($t['id']) ?>" title="Edit template details and workflow rules">
                ✏️ Edit
              </button>
              <button type="button" class="ba-cliq-btn-sm ba-cliq-card-dup-btn" data-id="<?= s($t['id']) ?>" title="Duplicate template">
                📋 Clone
              </button>
              <button type="button" class="ba-cliq-btn-sm ba-cliq-btn-danger ba-cliq-card-del-btn" data-id="<?= s($t['id']) ?>" title="Delete template">
                🗑️ Delete
              </button>
            </div>
          </div>

          <div class="ba-cliq-card-body">
            <!-- Left: Message Editor -->
            <div class="ba-cliq-editor-col">
              <div class="ba-cliq-editor-toolbar">
                <span class="ba-cliq-toolbar-label">Message Body</span>
                <span style="font-size:11px; color:#64748b;">Click chip to insert placeholder</span>
              </div>

              <!-- Quick Placeholders for this template -->
              <div class="ba-cliq-chips-wrap">
                <span class="ba-cliq-chip" data-ph="{batch_id}">+ {batch_id}</span>
                <span class="ba-cliq-chip" data-ph="{course_name}">+ {course_name}</span>
                <span class="ba-cliq-chip" data-ph="{module}">+ {module}</span>
                <span class="ba-cliq-chip" data-ph="{owner}">+ {owner}</span>
                <span class="ba-cliq-chip" data-ph="{activity}">+ {activity}</span>
                <span class="ba-cliq-chip" data-ph="{due_date}">+ {due_date}</span>
                <span class="ba-cliq-chip" data-ph="{delay_days}">+ {delay_days}</span>
                <span class="ba-cliq-chip" data-ph="{escalate_to}">+ {escalate_to}</span>
                <span class="ba-cliq-chip" data-ph="{lms_link}">+ {lms_link}</span>
              </div>

              <textarea class="ba-cliq-textarea" rows="5" spellcheck="false"><?= s($t['template']) ?></textarea>

              <div class="ba-cliq-card-footer">
                <div style="display:flex; align-items:center; gap:12px;">
                  <button type="button" class="ba-cliq-test-single-btn ba-cliq-btn-sm" data-id="<?= s($t['id']) ?>" title="Test sending this message to Zoho Cliq">
                    ⚡ Test Send
                  </button>
                </div>
                <span style="color:#94a3b8; font-size:11px;">Supports **bold** &amp; emojis</span>
              </div>
            </div>

            <!-- Right: Zoho Cliq Chat Live Preview -->
            <div class="ba-cliq-preview-col">
              <div class="ba-cliq-preview-label">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                  <circle cx="12" cy="12" r="3"></circle>
                </svg>
                Zoho Cliq Chat Preview
              </div>

              <div class="ba-cliq-bubble-wrap">
                <div class="ba-cliq-avatar" title="Kajal Bot">
                  KB
                </div>
                <div class="ba-cliq-msg-content">
                  <div class="ba-cliq-msg-header">
                    <span class="ba-cliq-sender-name">Kajal Bot</span>
                    <span class="ba-cliq-bot-tag">BOT</span>
                    <span class="ba-cliq-msg-time">Today at 09:00 AM</span>
                  </div>
                  <div class="ba-cliq-rendered-text">
                    <!-- Populated in real-time via cliq_templates.js -->
                  </div>
                </div>
              </div>
            </div>
          </div> <!-- /.ba-cliq-card-body -->

          <!-- Workflow & Trigger Rules Panel -->
          <div class="ba-cliq-workflow-panel">
            <div class="ba-cliq-workflow-header">
              <span class="ba-cliq-workflow-title">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="12" cy="12" r="10"></circle>
                  <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                Workflow Automation &amp; Dynamic Triggering
              </span>
              <label class="ba-cliq-switch-label" title="Enable or disable automated background execution for this template">
                <input type="checkbox" class="ba-cliq-rule-enabled" <?= !empty($r['enabled']) ? 'checked' : '' ?>>
                <span>Automated Workflow Active</span>
              </label>
            </div>

            <div class="ba-cliq-workflow-grid">
              <div class="ba-cliq-workflow-field">
                <label>Trigger Condition</label>
                <select class="ba-cliq-select ba-cliq-rule-metric">
                  <option value="days_before_due" <?= ($r['condition_metric'] === 'days_before_due') ? 'selected' : '' ?>>Days Before Due Date</option>
                  <option value="on_due_date" <?= ($r['condition_metric'] === 'on_due_date') ? 'selected' : '' ?>>On Due Date (Morning)</option>
                  <option value="days_overdue" <?= ($r['condition_metric'] === 'days_overdue') ? 'selected' : '' ?>>Days Overdue (Daily Reminder)</option>
                  <option value="attendance_missing" <?= ($r['condition_metric'] === 'attendance_missing') ? 'selected' : '' ?>>Daily Attendance Missing</option>
                  <option value="batch_created" <?= ($r['condition_metric'] === 'batch_created') ? 'selected' : '' ?>>Batch Created (Lifecycle)</option>
                  <option value="module_started" <?= ($r['condition_metric'] === 'module_started') ? 'selected' : '' ?>>Module Started (Lifecycle)</option>
                  <option value="activity_completed" <?= ($r['condition_metric'] === 'activity_completed') ? 'selected' : '' ?>>Activity Completed (Confirmation)</option>
                  <option value="stage_transition" <?= ($r['condition_metric'] === 'stage_transition') ? 'selected' : '' ?>>Stage Transition (Handoff)</option>
                  <option value="batch_closure" <?= ($r['condition_metric'] === 'batch_closure') ? 'selected' : '' ?>>Batch Closure / Completed</option>
                </select>
              </div>

              <div class="ba-cliq-workflow-field">
                <label>Threshold {n} Days</label>
                <input type="number" class="ba-cliq-input-small ba-cliq-rule-cval" value="<?= (int)($r['condition_value'] ?? 0) ?>" min="0" max="30">
              </div>

              <div class="ba-cliq-workflow-field">
                <label>To Whom (Recipient)</label>
                <select class="ba-cliq-select ba-cliq-rule-recipient">
                  <option value="CM" <?= ($r['recipient_type'] === 'CM') ? 'selected' : '' ?>>Class Mentor (CM)</option>
                  <option value="LM" <?= ($r['recipient_type'] === 'LM') ? 'selected' : '' ?>>Lab Mentor (LM)</option>
                  <option value="SSE" <?= ($r['recipient_type'] === 'SSE') ? 'selected' : '' ?>>Student Success Exec (SSE)</option>
                  <option value="PM" <?= ($r['recipient_type'] === 'PM') ? 'selected' : '' ?>>Program Manager (PM)</option>
                  <option value="AM" <?= ($r['recipient_type'] === 'AM') ? 'selected' : '' ?>>Assistant Manager (AM)</option>
                </select>
              </div>

              <div class="ba-cliq-workflow-field">
                <label>Escalate To (If Overdue)</label>
                <select class="ba-cliq-select ba-cliq-rule-escalateto">
                  <option value="" <?= empty($r['escalate_to']) ? 'selected' : '' ?>>No Escalation</option>
                  <option value="PM" <?= (($r['escalate_to'] ?? '') === 'PM') ? 'selected' : '' ?>>Escalate to PM</option>
                  <option value="AM" <?= (($r['escalate_to'] ?? '') === 'AM') ? 'selected' : '' ?>>Escalate to AM</option>
                </select>
              </div>

              <div class="ba-cliq-workflow-field">
                <label>Escalate After</label>
                <div style="display:flex; align-items:center; gap:4px;">
                  <input type="number" class="ba-cliq-input-small ba-cliq-rule-escalatedays" value="<?= (int)($r['escalate_days'] ?? 2) ?>" min="1" max="30">
                  <span style="font-size:11px; color:#64748b;">days</span>
                </div>
              </div>
            </div>
          </div>

        </div>
      <?php endforeach; ?>
    </div>

    <!-- View: Placeholder Cheat-Sheet -->
    <div class="ba-cliq-matrix-card" id="ba-cliq-placeholders-view" style="display:none;">
      <h2 style="font-size:18px; font-weight:700; color:#0f172a; margin-top:0;">Placeholder Dictionary</h2>
      <p style="font-size:13px; color:#64748b; margin-bottom:16px;">
        Use these placeholders inside message templates. The notification engine resolves them dynamically per batch and activity.
      </p>

      <table class="ba-cliq-table">
        <thead>
          <tr>
            <th style="width:200px;">Placeholder Tag</th>
            <th>Description</th>
            <th style="width:280px;">Sample Resolved Value</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($placeholders as $tag => $info): ?>
            <tr>
              <td><code><?= s($tag) ?></code></td>
              <td><?= s($info['desc']) ?></td>
              <td style="color:#0f172a; font-weight:500;"><?= s($info['sample']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div> <!-- /.ba-cliq-shell -->

  <!-- Toast Notification -->
  <div id="ba-cliq-toast" class="ba-cliq-toast"></div>

  <!-- Modal 1: Add / Edit Template Modal -->
  <div class="ba-cliq-modal-backdrop" id="ba-cliq-template-modal">
    <div class="ba-cliq-modal" style="max-width: 680px;">
      <div class="ba-cliq-modal-header">
        <h3 class="ba-cliq-modal-title" id="ba-cliq-tmpl-modal-title">Add Notification Template</h3>
        <button type="button" class="ba-cliq-modal-close" id="ba-cliq-tmpl-modal-close">&times;</button>
      </div>
      <div class="ba-cliq-modal-body">
        <input type="hidden" id="ba-cliq-tmpl-form-mode" value="add">

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:14px;">
          <div>
            <label class="ba-cliq-field-label">Template ID *</label>
            <input type="text" id="ba-cliq-tmpl-id" class="ba-cliq-input-text" placeholder="e.g. BATCH-START, CM-ALERT-01">
            <div class="ba-cliq-field-help">Unique uppercase identifier (A-Z, 0-9, dashes).</div>
          </div>
          <div>
            <label class="ba-cliq-field-label">Template Title *</label>
            <input type="text" id="ba-cliq-tmpl-title" class="ba-cliq-input-text" placeholder="e.g. Activity Due Reminder">
            <div class="ba-cliq-field-help">Descriptive name for managers and mentors.</div>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:14px;">
          <div>
            <label class="ba-cliq-field-label">Primary Recipient Role</label>
            <select id="ba-cliq-tmpl-recipient" class="ba-cliq-input-text">
              <option value="CM">Class Mentor (Theory)</option>
              <option value="LM">Lab Mentor (Practical)</option>
              <option value="PM">Program Manager (PM)</option>
              <option value="SSE">Student Success Executive (SSE)</option>
              <option value="AM">Assistant Manager (AM)</option>
            </select>
          </div>
          <div>
            <label class="ba-cliq-field-label">Severity / Icon Style</label>
            <select id="ba-cliq-tmpl-severity" class="ba-cliq-input-text">
              <option value="info">ℹ️ Info</option>
              <option value="reminder">🔔 Reminder</option>
              <option value="overdue">⏰ Overdue</option>
              <option value="escalation">🚨 Escalation</option>
              <option value="confirmation">✅ Confirmation</option>
              <option value="milestone">🎉 Milestone</option>
            </select>
          </div>
        </div>

        <!-- Workflow Trigger Rule Box -->
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin-bottom:14px;">
          <div style="font-weight:700; font-size:12px; color:#1e293b; text-transform:uppercase; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
            <span>⚡ Workflow Trigger &amp; Escalation Metrics</span>
          </div>
          <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap:12px; margin-bottom:10px;">
            <div>
              <label class="ba-cliq-field-label" style="font-size:11px;">Trigger Condition</label>
              <select id="ba-cliq-tmpl-condition" class="ba-cliq-input-text" style="font-size:12px;">
                <option value="days_before_due">Days Before Due Date</option>
                <option value="on_due_date">On Due Date (Due Today)</option>
                <option value="days_overdue">Days Overdue (Incomplete)</option>
                <option value="attendance_missing">Daily Attendance Missing</option>
                <option value="batch_created">Batch Created (Lifecycle)</option>
                <option value="module_started">Module Started (Lifecycle)</option>
                <option value="activity_completed">Activity Completed (Confirmation)</option>
                <option value="stage_transition">Stage Transition (Handoff)</option>
                <option value="batch_closure">Batch Closure (Completion)</option>
              </select>
            </div>
            <div>
              <label class="ba-cliq-field-label" style="font-size:11px;">Offset / Threshold Days {n}</label>
              <input type="number" id="ba-cliq-tmpl-offset" class="ba-cliq-input-text" style="font-size:12px;" value="1" min="0" max="30">
            </div>
          </div>

          <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap:12px;">
            <div>
              <label class="ba-cliq-field-label" style="font-size:11px;">Escalate Overdue To</label>
              <select id="ba-cliq-tmpl-escalate-to" class="ba-cliq-input-text" style="font-size:12px;">
                <option value="">None (No Escalation)</option>
                <option value="PM">Program Manager (PM)</option>
                <option value="AM">Assistant Manager (AM)</option>
              </select>
            </div>
            <div>
              <label class="ba-cliq-field-label" style="font-size:11px;">Escalate After {n} Days Overdue</label>
              <input type="number" id="ba-cliq-tmpl-escalate-days" class="ba-cliq-input-text" style="font-size:12px;" value="2" min="1" max="30">
            </div>
          </div>
        </div>

        <!-- Message Text -->
        <div style="margin-bottom:14px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
            <label class="ba-cliq-field-label">Message Body Template *</label>
            <span style="font-size:11px; color:#64748b;">Click chip to insert:</span>
          </div>
          <div class="ba-cliq-chips-wrap" style="margin-bottom:8px;">
            <span class="ba-cliq-modal-chip" data-ph="{batch_id}">+ {batch_id}</span>
            <span class="ba-cliq-modal-chip" data-ph="{course_name}">+ {course_name}</span>
            <span class="ba-cliq-modal-chip" data-ph="{module}">+ {module}</span>
            <span class="ba-cliq-modal-chip" data-ph="{activity}">+ {activity}</span>
            <span class="ba-cliq-modal-chip" data-ph="{owner}">+ {owner}</span>
            <span class="ba-cliq-modal-chip" data-ph="{due_date}">+ {due_date}</span>
            <span class="ba-cliq-modal-chip" data-ph="{delay_days}">+ {delay_days}</span>
            <span class="ba-cliq-modal-chip" data-ph="{escalate_to}">+ {escalate_to}</span>
            <span class="ba-cliq-modal-chip" data-ph="{lms_link}">+ {lms_link}</span>
          </div>
          <textarea id="ba-cliq-tmpl-message" class="ba-cliq-input-text" rows="5" style="font-family:inherit; resize:vertical;" placeholder="Write message body here... e.g. 🔔 Reminder: {activity} for {batch_id} is due on {due_date}."></textarea>
        </div>

        <div style="display:flex; align-items:center; gap:8px;">
          <input type="checkbox" id="ba-cliq-tmpl-enabled" checked style="cursor:pointer; width:16px; height:16px;">
          <label for="ba-cliq-tmpl-enabled" style="font-size:13px; font-weight:600; color:#1e293b; cursor:pointer;">
            Active (Enable this template in automated workflow engine)
          </label>
        </div>
      </div>
      <div class="ba-cliq-modal-footer">
        <button type="button" class="ba-cliq-btn ba-cliq-btn-secondary" id="ba-cliq-tmpl-modal-cancel">Cancel</button>
        <button type="button" class="ba-cliq-btn ba-cliq-btn-primary" id="ba-cliq-tmpl-modal-save">Save Template</button>
      </div>
    </div>
  </div>

  <!-- Modal 2: Test Bot Message Modal -->
  <div class="ba-cliq-modal-backdrop" id="ba-cliq-test-modal">
    <div class="ba-cliq-modal">
      <div class="ba-cliq-modal-header">
        <h3 class="ba-cliq-modal-title">Test Zoho Cliq Bot Notification</h3>
        <button type="button" class="ba-cliq-modal-close" id="ba-cliq-modal-close-btn">&times;</button>
      </div>

      <div class="ba-cliq-modal-body">
        <div>
          <label class="ba-cliq-field-label">Target Bot Webhook / API Endpoint</label>
          <input type="text" class="ba-cliq-input-text" readonly value="<?= s(cliq_notification_service::get_bot_api_url()) ?>" style="background:#f1f5f9; color:#475569; font-family:monospace; font-size:12px;">
          <?php if (!cliq_notification_service::is_configured()): ?>
            <div style="color:#b91c1c; font-size:12px; margin-top:4px;">
              ⚠️ Zoho Cliq ZAPI Key is not configured yet. Please configure it in
              <a href="<?= s((new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics']))->out(false)) ?>" style="color:#2563eb; text-decoration:underline;">Plugin Settings</a>.
            </div>
          <?php endif; ?>
        </div>

        <div>
          <label for="ba-cliq-test-userids" class="ba-cliq-field-label">Recipient User Email(s) / Cliq User IDs (userids)</label>
          <input type="text" id="ba-cliq-test-userids" class="ba-cliq-input-text" value="<?= s($USER->email ?? '') ?>" placeholder="user@company.com, mentor@company.com or leave blank">
          <div class="ba-cliq-field-help">
            Enter target email(s) or Cliq user ID(s) (optional). Leave blank to broadcast to the bot channel / subscribers. Sent in payload as: <code>{ "text": messageText, "userids": "email1,email2" }</code>
          </div>
        </div>

        <div>
          <label for="ba-cliq-test-message" class="ba-cliq-field-label">Message Text</label>
          <textarea id="ba-cliq-test-message" class="ba-cliq-input-text" rows="5" style="font-family:inherit; resize:vertical;"></textarea>
          <div class="ba-cliq-field-help">
            Payload format sent to Cliq: <code>{ "text": messageText }</code>
          </div>
        </div>

        <!-- Result Box -->
        <div id="ba-cliq-modal-result" class="ba-cliq-result-box"></div>
      </div>

      <div class="ba-cliq-modal-footer">
        <button type="button" class="ba-cliq-btn ba-cliq-btn-secondary" id="ba-cliq-modal-cancel-btn">Cancel</button>
        <button type="button" class="ba-cliq-btn ba-cliq-btn-primary" id="ba-cliq-modal-send-btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="22" y1="2" x2="11" y2="13"></line>
            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
          </svg>
          Send Test Message
        </button>
      </div>
    </div>
  </div>

  <!-- Modal 3: Dry-Run Simulation Modal -->
  <div class="ba-cliq-modal-backdrop" id="ba-cliq-dryrun-modal">
    <div class="ba-cliq-modal" style="max-width: 840px;">
      <div class="ba-cliq-modal-header">
        <h3 class="ba-cliq-modal-title">⚡ Dry-Run Workflow Simulation</h3>
        <button type="button" class="ba-cliq-modal-close" id="ba-cliq-dryrun-close-btn">&times;</button>
      </div>

      <div class="ba-cliq-modal-body">
        <p style="font-size:13px; color:#475569; margin:0 0 14px 0;">
          Simulates the automated workflow engine across all currently active batches and planned mentor activities without dispatching live messages to Zoho Cliq.
        </p>

        <div id="ba-cliq-dryrun-summary" style="display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap;">
          <!-- Populated dynamically via JS -->
        </div>

        <div id="ba-cliq-dryrun-results" style="max-height: 440px; overflow-y:auto; display:flex; flex-direction:column; gap:12px;">
          <!-- Notification match items rendered here -->
        </div>
      </div>

      <div class="ba-cliq-modal-footer" style="justify-content:space-between;">
        <button type="button" class="ba-cliq-btn ba-cliq-btn-secondary" id="ba-cliq-dryrun-refresh-btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="23 4 23 10 17 10"></polyline>
            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
          </svg>
          Re-scan Live Batches
        </button>
        <button type="button" class="ba-cliq-btn ba-cliq-btn-primary" id="ba-cliq-dryrun-cancel-btn">Close Simulation</button>
      </div>
    </div>
  </div>

</div> <!-- /.ba-cliq-page -->

<?php
echo $OUTPUT->footer();
