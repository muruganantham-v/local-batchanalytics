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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/classes/cliq_notification_service.php');
require_once(__DIR__ . '/classes/cliq_workflow_engine.php');
require_once(__DIR__ . '/classes/cliq_recipient_resolver.php');

use local_batchanalytics\cliq_notification_service;
use local_batchanalytics\cliq_workflow_engine;
use local_batchanalytics\cliq_recipient_resolver;

require_login();
$context = context_system::instance();

// Must be Site Administrator or have local/batchanalytics:manage capability
if (!is_siteadmin() && !has_capability('local/batchanalytics:manage', $context)) {
    require_capability('moodle/site:config', $context);
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// -------------------------------------------------------------------------
// 1. AJAX Action Handlers
// -------------------------------------------------------------------------
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

if ($action === 'resettemplates') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();
        cliq_notification_service::reset_templates();
        echo json_encode(['success' => true, 'message' => 'Templates reset to default specifications.']);
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
$default_templates = cliq_notification_service::DEFAULT_TEMPLATES;
$placeholders = cliq_notification_service::PLACEHOLDER_DICTIONARY;
$workflow_rules = cliq_workflow_engine::get_rules();


$pm_count = 0;
$sse_count = 0;
$cm_count = 0;
$lm_count = 0;
foreach ($templates as $t) {
    if ($t['recipient'] === 'PM') $pm_count++;
    else if ($t['recipient'] === 'SSE') $sse_count++;
    else if ($t['recipient'] === 'CM') $cm_count++;
    else if ($t['recipient'] === 'LM') $lm_count++;
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
     data-default-templates="<?= s(json_encode($default_templates)) ?>"
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
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="10" rx="2"></rect>
                <circle cx="12" cy="5" r="2"></circle>
                <path d="M12 7v4"></path>
                <line x1="8" y1="16" x2="8" y2="16"></line>
                <line x1="16" y1="16" x2="16" y2="16"></line>
              </svg>
              Kajal Bot
            </span>
          </h1>
          <p class="ba-cliq-subtitle">
            Configure automated message copy, reminder schedules, and escalation rules for batch start, module events, checklist due dates, and attendance across all roles.
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

          <button type="button" id="ba-cliq-reset-all" class="ba-cliq-btn ba-cliq-btn-danger" title="Reset all 42 templates to default specifications">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
              <path d="M3 3v5h5"></path>
            </svg>
            Reset All to Defaults
          </button>

          <button type="button" id="ba-cliq-save-all" class="ba-cliq-btn ba-cliq-btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
              <polyline points="17 21 17 13 7 13 7 21"></polyline>
              <polyline points="7 3 7 8 15 8"></polyline>
            </svg>
            Save All Changes
          </button>
        </div>
      </div>

      <!-- Controls: Search & Tabs -->
      <div class="ba-cliq-controls">
        <div class="ba-cliq-search-wrap">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input type="text" id="ba-cliq-search" class="ba-cliq-search-input" placeholder="Search templates by ID, trigger, or keyword…">
        </div>

        <div class="ba-cliq-tabs">
          <div class="ba-cliq-tab active" data-tab="all">
            All <span class="ba-cliq-tab-count"><?= count($templates) ?></span>
          </div>
          <div class="ba-cliq-tab" data-tab="PM">
            Project Manager (PM) <span class="ba-cliq-tab-count"><?= $pm_count ?></span>
          </div>
          <div class="ba-cliq-tab" data-tab="SSE">
            SSE <span class="ba-cliq-tab-count"><?= $sse_count ?></span>
          </div>
          <div class="ba-cliq-tab" data-tab="CM">
            Class Mentor (CM) <span class="ba-cliq-tab-count"><?= $cm_count ?></span>
          </div>
          <div class="ba-cliq-tab" data-tab="LM">
            Lab Mentor (LM) <span class="ba-cliq-tab-count"><?= $lm_count ?></span>
          </div>
          <div class="ba-cliq-tab" data-tab="matrix" style="margin-left:8px; border-left:1px solid #cbd5e1; padding-left:14px;">
            Escalation Matrix
          </div>
          <div class="ba-cliq-tab" data-tab="placeholders">
            Placeholder Cheat-Sheet
          </div>
        </div>
      </div>
    </div>

    <!-- Templates List View -->
    <div class="ba-cliq-templates-list" id="ba-cliq-templates-list">
      <?php foreach ($templates as $t): ?>
        <?php
          $role_class = 'ba-cliq-role-' . $t['recipient'];
          $is_new = ($t['status'] === 'NEW');
        ?>
        <div class="ba-cliq-card" data-id="<?= s($t['id']) ?>" data-role="<?= s($t['recipient']) ?>">

          <div class="ba-cliq-card-header">
            <div class="ba-cliq-card-header-left">
              <span class="ba-cliq-id-badge"><?= s($t['id']) ?></span>
              <span class="ba-cliq-role-badge <?= s($role_class) ?>"><?= s($t['recipient_title']) ?></span>
              <span class="ba-cliq-trigger-title"><?= s($t['trigger']) ?></span>
              <span class="ba-cliq-status-badge <?= $is_new ? 'new' : '' ?>"><?= s($t['status']) ?></span>
            </div>

            <div class="ba-cliq-card-header-right">
              <?php if (!empty($t['escalation']) && $t['escalation'] !== '—'): ?>
                <span class="ba-cliq-escalation-info">
                  ⚠️ <?= s($t['escalation']) ?>
                </span>
              <?php endif; ?>

              <?php if (!empty($t['threshold_days'])): ?>
                <div style="display:flex; align-items:center; gap:6px; font-size:12px; color:#475569;">
                  <span>Threshold {n}:</span>
                  <input type="number" class="ba-cliq-threshold-input" value="<?= (int)$t['threshold_days'] ?>" min="0" max="30" style="width:50px; padding:2px 6px; font-size:12px; border:1px solid #cbd5e1; border-radius:4px;">
                  <span>day(s)</span>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="ba-cliq-card-body">
            <!-- Left: Message Editor -->
            <div class="ba-cliq-editor-col">
              <div class="ba-cliq-editor-toolbar">
                <span class="ba-cliq-toolbar-label">Message Template</span>
                <span style="font-size:11px; color:#64748b;">Click chip to insert placeholder</span>
              </div>

              <!-- Quick Placeholders for this template -->
              <div class="ba-cliq-chips-wrap">
                <span class="ba-cliq-chip" data-ph="{batch_id}">+ {batch_id}</span>
                <span class="ba-cliq-chip" data-ph="{module}">+ {module}</span>
                <span class="ba-cliq-chip" data-ph="{owner}">+ {owner}</span>
                <span class="ba-cliq-chip" data-ph="{activity}">+ {activity}</span>
                <span class="ba-cliq-chip" data-ph="{due_date}">+ {due_date}</span>
                <span class="ba-cliq-chip" data-ph="{delay_days}">+ {delay_days}</span>
                <span class="ba-cliq-chip" data-ph="{lms_link}">+ {lms_link}</span>
              </div>

              <textarea class="ba-cliq-textarea" rows="5" spellcheck="false"><?= s($t['template']) ?></textarea>

              <div class="ba-cliq-card-footer">
                <div style="display:flex; align-items:center; gap:12px;">
                  <button type="button" class="ba-cliq-reset-link ba-cliq-reset-single" data-id="<?= s($t['id']) ?>">
                    Reset this template
                  </button>
                  <button type="button" class="ba-cliq-test-single-btn" data-id="<?= s($t['id']) ?>" title="Test sending this message to Zoho Cliq">
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
          </div>

        </div>
      <?php endforeach; ?>
    </div>

    <!-- View: Escalation Matrix -->
    <div class="ba-cliq-matrix-card" id="ba-cliq-matrix-view" style="display:none;">
      <h2 style="font-size:18px; font-weight:700; color:#0f172a; margin-top:0;">5. Escalation Matrix</h2>
      <p style="font-size:13px; color:#64748b; margin-bottom:16px;">
        Cadence rules, reminder timing, and escalation thresholds for each activity category.
      </p>

      <table class="ba-cliq-table">
        <thead>
          <tr>
            <th>Activity Category</th>
            <th>Owner</th>
            <th>Reminder Before Due</th>
            <th>Overdue Message</th>
            <th>Escalate to PM After</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong>Module activity (classroom)</strong></td>
            <td>Class Mentor</td>
            <td>1 day before and on due date</td>
            <td>Daily</td>
            <td><span style="color:#dc2626; font-weight:600;">{n} = 2 days overdue</span></td>
            <td>Sent via CM-02 / CM-03 / PM-02</td>
          </tr>
          <tr>
            <td><strong>Lab activity</strong></td>
            <td>Lab Mentor</td>
            <td>1 day before and on due date</td>
            <td>Daily</td>
            <td><span style="color:#dc2626; font-weight:600;">{n} = 2 days overdue</span></td>
            <td>Sent via LM-02 / LM-03 / PM-02</td>
          </tr>
          <tr>
            <td><strong>SS activity</strong></td>
            <td>SSE</td>
            <td>1 day before and on due date</td>
            <td>Daily</td>
            <td><span style="color:#dc2626; font-weight:600;">{n} = 2 days overdue</span></td>
            <td>Sent via SSE-03 / SSE-04 / PM-02</td>
          </tr>
          <tr>
            <td><strong>Attendance</strong></td>
            <td>Class / Lab Mentor</td>
            <td>Same-day end-of-day nudge</td>
            <td>Next morning</td>
            <td><span style="color:#dc2626; font-weight:600;">{n} = 1 day overdue</span></td>
            <td>CM-07 / LM-07</td>
          </tr>
          <tr>
            <td><strong>Assessment evaluation</strong></td>
            <td>Class / Lab Mentor</td>
            <td>1 day before due</td>
            <td>Daily</td>
            <td><span style="color:#dc2626; font-weight:600;">{n} = 2 days overdue</span></td>
            <td>CM-09 / LM-08</td>
          </tr>
          <tr>
            <td><strong>Nomination approval</strong></td>
            <td>PM</td>
            <td>—</td>
            <td>Reminder to PM after 2 days pending</td>
            <td>—</td>
            <td>PM-07</td>
          </tr>
          <tr>
            <td><strong>Closure items</strong></td>
            <td>PM, SSE</td>
            <td>At batch end</td>
            <td>Daily</td>
            <td>—</td>
            <td>PM-08 / SSE-07</td>
          </tr>
        </tbody>
      </table>

      <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:12px 16px; margin-top:20px; font-size:13px; color:#1e40af;">
        <strong>Quiet hours:</strong> No bot messages outside <strong>09:00 AM – 07:00 PM</strong> (reminders are automatically queued for the next working morning).
      </div>
    </div>

    <!-- View: Placeholder Dictionary -->
    <div class="ba-cliq-matrix-card" id="ba-cliq-placeholders-view" style="display:none;">
      <h2 style="font-size:18px; font-weight:700; color:#0f172a; margin-top:0;">6. Placeholder Dictionary</h2>
      <p style="font-size:13px; color:#64748b; margin-bottom:16px;">
        All available dynamic variables that can be included in Kajal Bot messages.
      </p>

      <table class="ba-cliq-table">
        <thead>
          <tr>
            <th>Placeholder</th>
            <th>Meaning</th>
            <th>Example Value</th>
            <th style="width:100px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($placeholders as $ph => $meta): ?>
            <tr>
              <td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; color:#0f172a; font-weight:600;"><?= s($ph) ?></code></td>
              <td><?= s($meta['desc']) ?></td>
              <td style="color:#64748b;"><?= s($meta['sample']) ?></td>
              <td>
                <button type="button" class="ba-cliq-btn ba-cliq-btn-secondary ba-cliq-copy-ph" data-ph="<?= s($ph) ?>" style="padding:4px 8px; font-size:11px;">
                  Copy
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div> <!-- /.ba-cliq-shell -->

  <!-- Floating Toast Notification -->
  <div id="ba-cliq-toast" class="ba-cliq-toast"></div>

  <!-- Test Bot Message Modal -->
  <div class="ba-cliq-modal-overlay" id="ba-cliq-test-modal">
    <div class="ba-cliq-modal">
      <div class="ba-cliq-modal-header">
        <h3>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
          </svg>
          Test Zoho Cliq Bot Notification
        </h3>
        <button type="button" class="ba-cliq-modal-close" id="ba-cliq-modal-close-btn">&times;</button>
      </div>

      <div class="ba-cliq-modal-body">
        <div>
          <label class="ba-cliq-field-label">Target Bot URL &amp; Auth (GET zapikey)</label>
          <div class="ba-cliq-endpoint-pill" id="ba-cliq-modal-bot-url">
            <?= s(cliq_notification_service::get_bot_api_url()) ?>
          </div>
          <?php if (!cliq_notification_service::is_configured()): ?>
            <div style="margin-top:6px; color:#dc2626; font-size:12px; font-weight:500;">
              ⚠️ Zoho Cliq ZAPI Key is not configured yet. Please configure it in
              <a href="<?= s((new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics']))->out(false)) ?>" style="color:#2563eb; text-decoration:underline;">Plugin Settings</a>.
            </div>
          <?php endif; ?>
        </div>

        <div>
          <label for="ba-cliq-test-userids" class="ba-cliq-field-label">Recipient User Email(s) / Cliq User IDs (userids)</label>
          <input type="text" id="ba-cliq-test-userids" class="ba-cliq-input-text" value="<?= s($USER->email ?? '') ?>" placeholder="user@company.com, mentor@company.com">
          <div class="ba-cliq-field-help">
            Enter target email(s) or Cliq userids. Sent in payload as: <code>{ "text": messageText, "userids": [emails] }</code>
          </div>
        </div>

        <div>
          <label for="ba-cliq-test-message" class="ba-cliq-field-label">Message Text</label>
          <textarea id="ba-cliq-test-message" class="ba-cliq-input-text" rows="5" style="font-family:inherit; resize:vertical;"></textarea>
          <div class="ba-cliq-field-help">
            Payload format sent to Cliq: <code>{ "text": messageText, "userids": emails }</code>
          </div>
        </div>

        <!-- Result Box -->
        <div id="ba-cliq-modal-result" class="ba-cliq-result-box"></div>
      </div>

      <div class="ba-cliq-modal-footer">
        <button type="button" class="ba-cliq-btn ba-cliq-btn-secondary" id="ba-cliq-modal-cancel-btn">Close</button>
        <button type="button" class="ba-cliq-btn" id="ba-cliq-modal-send-btn" style="background:#059669; border-color:#059669; color:#fff;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="22" y1="2" x2="11" y2="13"></line>
            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
          </svg>
          Send to Zoho Cliq
        </button>
      </div>
    </div>
  </div>

</div> <!-- /.ba-cliq-page -->

<?php
echo $OUTPUT->footer();
