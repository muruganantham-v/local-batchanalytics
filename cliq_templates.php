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
 * Dedicated Admin Page for Zoho Cliq Message Templates (Subject & Body).
 * Provides collapsible section-by-section configuration for Mentor and SS activity notifications.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/classes/cliq_activity_notifier.php');

use local_batchanalytics\cliq_activity_notifier;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

admin_externalpage_setup('local_batchanalytics_cliq_templates');

$action = optional_param('action', '', PARAM_ALPHA);
$defs = cliq_activity_notifier::get_template_definitions();

// Handle form submission
if ($action === 'save' && data_submitted()) {
    require_sesskey();

    $templates_data = optional_param_array('templates', [], PARAM_RAW);

    foreach ($defs as $cat_key => $cat) {
        foreach ($cat['stages'] as $stg_key => $stg) {
            $key = $stg['key'];
            if (isset($templates_data[$key])) {
                $sub = trim((string)($templates_data[$key]['subject'] ?? ''));
                $bod = trim((string)($templates_data[$key]['body'] ?? ''));

                set_config('cliq_tpl_' . $key . '_subject', $sub, 'local_batchanalytics');
                set_config('cliq_tpl_' . $key . '_body', $bod, 'local_batchanalytics');
            }
        }
    }

    \core\notification::success(get_string('templates_saved_success', 'local_batchanalytics'));
    redirect(new moodle_url('/local/batchanalytics/cliq_templates.php'));
}

$is_enabled = cliq_activity_notifier::is_enabled();
$cfg_details = cliq_activity_notifier::get_config_details();

$pageurl = new moodle_url('/local/batchanalytics/cliq_templates.php');
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics']);

echo $OUTPUT->header();
?>

<style>
.cliq-tpl-container {
    max-width: 1200px;
    margin: 0 auto 60px auto;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.cliq-header-banner {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #ffffff;
    border-radius: 12px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
}
.cliq-header-banner h2 {
    margin: 0 0 8px 0;
    font-size: 24px;
    font-weight: 700;
    color: #ffffff;
    display: flex;
    align-items: center;
    gap: 10px;
}
.cliq-header-banner p {
    margin: 0;
    color: #94a3b8;
    font-size: 14px;
    line-height: 1.5;
}
.cliq-status-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 20px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}
.cliq-status-badges {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    font-size: 13px;
}
.cliq-badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.cliq-badge-success {
    background-color: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
}
.cliq-badge-warning {
    background-color: #fef3c7;
    color: #b45309;
    border: 1px solid #fde68a;
}
.cliq-badge-secondary {
    background-color: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
}
.cliq-top-actions {
    display: flex;
    gap: 8px;
}
.cliq-section-card {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    margin-bottom: 28px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.cliq-section-header {
    background: #f8fafc;
    padding: 18px 24px;
    border-bottom: 1px solid #e2e8f0;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    user-select: none;
    transition: background-color 0.15s ease;
}
.cliq-section-header:hover {
    background: #f1f5f9;
}
.cliq-section-title-group h3 {
    margin: 0 0 4px 0;
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.cliq-section-title-group p {
    margin: 0;
    font-size: 13px;
    color: #64748b;
}
.cliq-section-body {
    padding: 20px 24px;
}
.cliq-stage-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    margin-bottom: 16px;
    background: #ffffff;
    transition: border-color 0.2s, box-shadow 0.2s;
    overflow: hidden;
}
.cliq-stage-card:last-child {
    margin-bottom: 0;
}
.cliq-stage-card:hover {
    border-color: #cbd5e1;
}
.cliq-stage-header {
    padding: 14px 18px;
    background: #fdfdfd;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    user-select: none;
    border-bottom: 1px solid transparent;
    transition: background 0.15s;
}
.cliq-stage-card.expanded .cliq-stage-header {
    background: #f8fafc;
    border-bottom-color: #e2e8f0;
}
.cliq-stage-header:hover {
    background: #f1f5f9;
}
.cliq-stage-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.cliq-timing-tag {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 3px 8px;
    border-radius: 6px;
    color: #ffffff;
}
.cliq-stage-name {
    font-size: 15px;
    font-weight: 600;
    color: #1e293b;
}
.cliq-stage-right {
    display: flex;
    align-items: center;
    gap: 10px;
}
.cliq-recipient-pill {
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 12px;
}
.cliq-chevron {
    transition: transform 0.2s ease;
    color: #64748b;
    font-size: 14px;
}
.cliq-stage-card.expanded .cliq-chevron {
    transform: rotate(180deg);
}
.cliq-stage-body {
    display: none;
    padding: 20px;
    background: #ffffff;
}
.cliq-stage-card.expanded .cliq-stage-body {
    display: block;
}
.cliq-form-group {
    margin-bottom: 18px;
}
.cliq-form-group label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 13px;
    color: #334155;
}
.cliq-field-hint {
    font-size: 12px;
    font-weight: 400;
    color: #64748b;
}
.cliq-input-subject {
    font-size: 14px;
    font-weight: 500;
    color: #0f172a;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 8px 12px;
    width: 100%;
}
.cliq-textarea-body {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 13px;
    line-height: 1.5;
    color: #1e293b;
    background-color: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 10px 12px;
    width: 100%;
    resize: vertical;
}
.cliq-input-subject:focus, .cliq-textarea-body:focus {
    border-color: #3b82f6;
    background-color: #ffffff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}
.cliq-tokens-box {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    padding: 10px 14px;
    margin-top: 8px;
    margin-bottom: 16px;
}
.cliq-tokens-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 6px;
    display: block;
}
.cliq-token-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.cliq-token-chip {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-size: 12px;
    font-family: monospace;
    color: #0f172a;
    padding: 2px 7px;
    cursor: pointer;
    user-select: none;
    transition: all 0.15s ease;
}
.cliq-token-chip:hover {
    background: #e0f2fe;
    border-color: #0284c7;
    color: #0369a1;
}
.cliq-stage-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 12px;
    border-top: 1px solid #f1f5f9;
}
.cliq-preview-card {
    display: none;
    margin-top: 14px;
    padding: 14px 16px;
    background: #ffffff;
    border-left: 4px solid #0284c7;
    border-radius: 4px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}
.cliq-preview-card.visible {
    display: block;
}
.cliq-preview-title {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.cliq-preview-body {
    font-size: 13px;
    color: #334155;
    white-space: pre-wrap;
    line-height: 1.5;
}
.cliq-bottom-bar {
    position: sticky;
    bottom: 20px;
    z-index: 99;
    background: rgba(15, 23, 42, 0.95);
    backdrop-filter: blur(8px);
    color: #ffffff;
    border-radius: 12px;
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
}
.cliq-bottom-left {
    font-size: 13px;
    color: #cbd5e1;
}
.cliq-bottom-right {
    display: flex;
    gap: 10px;
}
</style>

<div class="cliq-tpl-container">

    <!-- Top Banner -->
    <div class="cliq-header-banner">
        <h2>
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg>
            Zoho Cliq Notification Templates
        </h2>
        <p>
            Configure customizable message subjects and bodies for automated Zoho Cliq bot notifications.
            Cadence triggers include <strong>3 Days Before (T-3)</strong>, <strong>Due Today (T-0)</strong>, <strong>3 Days Overdue (T+3)</strong>, <strong>5th Day PM Escalation (T+5)</strong>, and <strong>Instant Completion Confirmation</strong>.
        </p>
    </div>

    <!-- Status & Master Controls -->
    <div class="cliq-status-bar">
        <div class="cliq-status-badges">
            <span><strong>Status:</strong></span>
            <?php if ($is_enabled): ?>
                <span class="cliq-badge-pill cliq-badge-success">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #16a34a; display: inline-block;"></span>
                    Zoho Cliq Active
                </span>
            <?php else: ?>
                <span class="cliq-badge-pill cliq-badge-warning">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #d97706; display: inline-block;"></span>
                    Notifications Disabled
                </span>
            <?php endif; ?>
            <span class="cliq-badge-pill cliq-badge-secondary">
                Cron: 09:00 AM Weekdays
            </span>
            <a href="<?php echo $settingsurl->out(); ?>" class="btn btn-sm btn-link text-decoration-none">
                ⚙️ Plugin Settings
            </a>
        </div>
        <div class="cliq-top-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-expand-all">
                📂 Expand All
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-collapse-all">
                📁 Collapse All
            </button>
            <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('cliq-templates-form').submit();">
                💾 Save All Changes
            </button>
        </div>
    </div>

    <form method="post" action="<?php echo $pageurl->out(); ?>" id="cliq-templates-form">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="save">

        <?php foreach ($defs as $cat_key => $cat): ?>
            <div class="cliq-section-card" id="section-<?php echo $cat_key; ?>">
                <!-- Section Header (Collapsible) -->
                <div class="cliq-section-header" onclick="toggleSection('<?php echo $cat_key; ?>')">
                    <div class="cliq-section-title-group">
                        <h3>
                            <?php if ($cat_key === 'mentor'): ?>
                                🎓 <?php echo htmlspecialchars($cat['title']); ?>
                            <?php else: ?>
                                💼 <?php echo htmlspecialchars($cat['title']); ?>
                            <?php endif; ?>
                            <span class="badge badge-secondary" style="font-size: 11px; font-weight: normal; vertical-align: middle;">
                                <?php echo count($cat['stages']); ?> Triggers
                            </span>
                        </h3>
                        <p><?php echo htmlspecialchars($cat['description']); ?></p>
                    </div>
                    <div>
                        <span class="cliq-chevron section-chevron" id="section-chevron-<?php echo $cat_key; ?>">▼</span>
                    </div>
                </div>

                <!-- Section Body containing Stage Cards -->
                <div class="cliq-section-body" id="section-body-<?php echo $cat_key; ?>">
                    <?php foreach ($cat['stages'] as $stg_idx => $stg):
                        $k = $stg['key'];
                        $cur_subject = cliq_activity_notifier::get_template_subject($k, $stg['default_subject']);
                        $cur_body    = cliq_activity_notifier::get_template_body($k, $stg['default_body']);
                        $is_custom   = ($cur_subject !== $stg['default_subject'] || $cur_body !== $stg['default_body']);
                    ?>
                        <div class="cliq-stage-card expanded" id="stage-card-<?php echo $k; ?>"
                             data-default-subject="<?php echo htmlspecialchars($stg['default_subject'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-default-body="<?php echo htmlspecialchars($stg['default_body'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-scope="<?php echo $cat['scope']; ?>">

                            <!-- Stage Card Header -->
                            <div class="cliq-stage-header" onclick="toggleStage('<?php echo $k; ?>')">
                                <div class="cliq-stage-left">
                                    <span class="cliq-timing-tag" style="background-color: <?php echo $stg['badge_color']; ?>;">
                                        <?php echo htmlspecialchars($stg['timing_badge']); ?>
                                    </span>
                                    <span class="cliq-stage-name">
                                        <?php echo htmlspecialchars($stg['title']); ?>
                                    </span>
                                </div>
                                <div class="cliq-stage-right">
                                    <span class="cliq-recipient-pill">
                                        👤 <?php echo htmlspecialchars($stg['recipient']); ?>
                                    </span>
                                    <span class="cliq-chevron">▼</span>
                                </div>
                            </div>

                            <!-- Stage Card Body -->
                            <div class="cliq-stage-body">
                                <!-- Subject Field -->
                                <div class="cliq-form-group">
                                    <label for="tpl-sub-<?php echo $k; ?>">
                                        <span>Message Subject / Cliq Card Title</span>
                                        <span class="cliq-field-hint">Rendered as bold card title header in Zoho Cliq</span>
                                    </label>
                                    <input type="text"
                                           class="cliq-input-subject cliq-active-target"
                                           id="tpl-sub-<?php echo $k; ?>"
                                           name="templates[<?php echo $k; ?>][subject]"
                                           value="<?php echo htmlspecialchars($cur_subject); ?>"
                                           placeholder="<?php echo htmlspecialchars($stg['default_subject']); ?>">
                                </div>

                                <!-- Body Field -->
                                <div class="cliq-form-group">
                                    <label for="tpl-bod-<?php echo $k; ?>">
                                        <span>Message Body</span>
                                        <span class="cliq-field-hint">Supports full multiline text and dynamic placeholders</span>
                                    </label>
                                    <textarea class="cliq-textarea-body cliq-active-target"
                                              id="tpl-bod-<?php echo $k; ?>"
                                              name="templates[<?php echo $k; ?>][body]"
                                              rows="6"
                                              placeholder="<?php echo htmlspecialchars($stg['default_body']); ?>"><?php echo htmlspecialchars($cur_body); ?></textarea>
                                </div>

                                <!-- Dynamic Placeholders Tokens -->
                                <div class="cliq-tokens-box">
                                    <span class="cliq-tokens-label">Click placeholder to insert at cursor position:</span>
                                    <div class="cliq-token-chips">
                                        <?php foreach ($stg['placeholders'] as $ph): ?>
                                            <button type="button"
                                                    class="cliq-token-chip"
                                                    onclick="insertPlaceholder('<?php echo $k; ?>', '<?php echo $ph; ?>')">
                                                + <?php echo htmlspecialchars($ph); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Stage Utilities -->
                                <div class="cliq-stage-actions">
                                    <div>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="resetStageToDefault('<?php echo $k; ?>')">
                                            🔄 Reset to Default
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info"
                                                onclick="toggleLivePreview('<?php echo $k; ?>')">
                                            👁️ Live Cliq Preview
                                        </button>
                                    </div>
                                </div>

                                <!-- Mock Cliq Card Preview -->
                                <div class="cliq-preview-card" id="preview-<?php echo $k; ?>" style="border-left-color: <?php echo $stg['badge_color']; ?>;">
                                    <div class="cliq-preview-title" id="preview-title-<?php echo $k; ?>">
                                        <!-- Rendered dynamically via JS -->
                                    </div>
                                    <div class="cliq-preview-body" id="preview-body-<?php echo $k; ?>">
                                        <!-- Rendered dynamically via JS -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Sticky Bottom Bar -->
        <div class="cliq-bottom-bar">
            <div class="cliq-bottom-left">
                💡 Changes take effect immediately on next scheduled evaluation and completion alerts.
            </div>
            <div class="cliq-bottom-right">
                <a href="<?php echo $settingsurl->out(); ?>" class="btn btn-outline-light btn-sm">
                    Cancel
                </a>
                <button type="submit" class="btn btn-primary btn-sm px-4" style="font-weight: 600;">
                    💾 Save All Templates
                </button>
            </div>
        </div>

    </form>
</div>

<script>
// Track last focused input or textarea for placeholder insertion
var lastFocusedElement = null;

document.addEventListener('DOMContentLoaded', function() {
    var targets = document.querySelectorAll('.cliq-active-target');
    targets.forEach(function(el) {
        el.addEventListener('focus', function() {
            lastFocusedElement = this;
        });
    });

    // Master Expand/Collapse
    document.getElementById('btn-expand-all').addEventListener('click', function() {
        document.querySelectorAll('.cliq-stage-card').forEach(function(card) {
            card.classList.add('expanded');
        });
        document.querySelectorAll('.cliq-section-body').forEach(function(sec) {
            sec.style.display = 'block';
        });
    });

    document.getElementById('btn-collapse-all').addEventListener('click', function() {
        document.querySelectorAll('.cliq-stage-card').forEach(function(card) {
            card.classList.remove('expanded');
        });
    });
});

// Toggle individual stage card accordion
function toggleStage(key) {
    var card = document.getElementById('stage-card-' + key);
    if (card) {
        card.classList.toggle('expanded');
    }
}

// Toggle entire category section
function toggleSection(catKey) {
    var body = document.getElementById('section-body-' + catKey);
    var chevron = document.getElementById('section-chevron-' + catKey);
    if (body) {
        if (body.style.display === 'none') {
            body.style.display = 'block';
            if (chevron) chevron.textContent = '▼';
        } else {
            body.style.display = 'none';
            if (chevron) chevron.textContent = '▶';
        }
    }
}

// Insert token at cursor into last focused input or stage's body textarea
function insertPlaceholder(stageKey, token) {
    var target = lastFocusedElement;
    var subEl = document.getElementById('tpl-sub-' + stageKey);
    var bodEl = document.getElementById('tpl-bod-' + stageKey);

    // If last focused isn't in this stage card, default to stage's body textarea
    if (!target || (target !== subEl && target !== bodEl)) {
        target = bodEl;
    }

    if (!target) return;

    var start = target.selectionStart || 0;
    var end = target.selectionEnd || 0;
    var val = target.value;

    target.value = val.substring(0, start) + token + val.substring(end);
    target.focus();
    target.selectionStart = target.selectionEnd = start + token.length;

    // Refresh live preview if currently visible
    var preview = document.getElementById('preview-' + stageKey);
    if (preview && preview.classList.contains('visible')) {
        updateLivePreview(stageKey);
    }
}

// Reset specific stage to factory defaults
function resetStageToDefault(key) {
    var card = document.getElementById('stage-card-' + key);
    if (!card) return;

    var defSub = card.getAttribute('data-default-subject');
    var defBod = card.getAttribute('data-default-body');

    var subEl = document.getElementById('tpl-sub-' + key);
    var bodEl = document.getElementById('tpl-bod-' + key);

    if (subEl) subEl.value = defSub;
    if (bodEl) bodEl.value = defBod;

    // Temporary highlight to confirm reset
    card.style.transition = 'box-shadow 0.3s ease';
    card.style.boxShadow = '0 0 0 3px rgba(34, 197, 94, 0.4)';
    setTimeout(function() {
        card.style.boxShadow = '';
    }, 800);

    // Update live preview if active
    updateLivePreview(key);
}

// Realistic mock sample data for live preview
var mockData = {
    '{batch_name}': '25002A',
    '{module_name}': 'Advance C Programming',
    '{task_name}': 'Quiz 1 Evaluation',
    '{activity_name}': 'Group Discussion 1',
    '{due_date}': '15 Oct 2026',
    '{mentor_name}': 'Prakash Narayanan',
    '{sse_name}': 'Divya Krishnan',
    '{pm_name}': 'Muruganantham V',
    '{overdue_days}': '5',
    '{completed_by}': 'Prakash Narayanan',
    '{completion_date}': '12 Oct 2026',
    '{link}': 'https://lms.example.com/local/batchanalytics/module.php?courseid=25',
    '{url}': 'https://lms.example.com/local/batchanalytics/module.php?courseid=25'
};

function renderMockText(str) {
    if (!str) return '';
    var res = str;
    for (var k in mockData) {
        if (mockData.hasOwnProperty(k)) {
            res = res.split(k).join(mockData[k]);
        }
    }
    return res;
}

// Toggle and render Mock Cliq Card Preview
function toggleLivePreview(key) {
    var preview = document.getElementById('preview-' + key);
    if (!preview) return;

    if (preview.classList.contains('visible')) {
        preview.classList.remove('visible');
    } else {
        updateLivePreview(key);
        preview.classList.add('visible');
    }
}

function updateLivePreview(key) {
    var subEl = document.getElementById('tpl-sub-' + key);
    var bodEl = document.getElementById('tpl-bod-' + key);

    var rawSub = subEl ? subEl.value : '';
    var rawBod = bodEl ? bodEl.value : '';

    var renderedSub = renderMockText(rawSub);
    var renderedBod = renderMockText(rawBod);

    var titleEl = document.getElementById('preview-title-' + key);
    var bodyEl = document.getElementById('preview-body-' + key);

    if (titleEl) titleEl.textContent = renderedSub;
    if (bodyEl) bodyEl.textContent = renderedBod;
}
</script>

<?php
echo $OUTPUT->footer();
