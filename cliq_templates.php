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
$settingsurl = new moodle_url('/admin/category.php', ['category' => 'local_batchanalytics']);
$zohourl = new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics_zoho']);

echo $OUTPUT->header();
?>

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

<?php
$cliq_js = <<<JS
var lastFocusedElement = null;

var targets = document.querySelectorAll('.cliq-active-target');
targets.forEach(function(el) {
    el.addEventListener('focus', function() {
        lastFocusedElement = this;
    });
});

var btnExpand = document.getElementById('btn-expand-all');
if (btnExpand) {
    btnExpand.addEventListener('click', function() {
        document.querySelectorAll('.cliq-stage-card').forEach(function(card) {
            card.classList.add('expanded');
        });
        document.querySelectorAll('.cliq-section-body').forEach(function(sec) {
            sec.style.display = 'block';
        });
    });
}

var btnCollapse = document.getElementById('btn-collapse-all');
if (btnCollapse) {
    btnCollapse.addEventListener('click', function() {
        document.querySelectorAll('.cliq-stage-card').forEach(function(card) {
            card.classList.remove('expanded');
        });
    });
}

window.toggleStage = function(key) {
    var card = document.getElementById('stage-card-' + key);
    if (card) {
        card.classList.toggle('expanded');
    }
};

window.toggleSection = function(catKey) {
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
};

window.insertPlaceholder = function(stageKey, token) {
    var target = lastFocusedElement;
    var subEl = document.getElementById('tpl-sub-' + stageKey);
    var bodEl = document.getElementById('tpl-bod-' + stageKey);

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

    var preview = document.getElementById('preview-' + stageKey);
    if (preview && preview.classList.contains('visible')) {
        window.updateLivePreview(stageKey);
    }
};

window.resetStageToDefault = function(key) {
    var card = document.getElementById('stage-card-' + key);
    if (!card) return;

    var defSub = card.getAttribute('data-default-subject');
    var defBod = card.getAttribute('data-default-body');

    var subEl = document.getElementById('tpl-sub-' + key);
    var bodEl = document.getElementById('tpl-bod-' + key);

    if (subEl) subEl.value = defSub;
    if (bodEl) bodEl.value = defBod;

    card.style.transition = 'box-shadow 0.3s ease';
    card.style.boxShadow = '0 0 0 3px rgba(34, 197, 94, 0.4)';
    setTimeout(function() {
        card.style.boxShadow = '';
    }, 800);

    window.updateLivePreview(key);
};

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

window.toggleLivePreview = function(key) {
    var preview = document.getElementById('preview-' + key);
    if (!preview) return;

    if (preview.classList.contains('visible')) {
        preview.classList.remove('visible');
    } else {
        window.updateLivePreview(key);
        preview.classList.add('visible');
    }
};

window.updateLivePreview = function(key) {
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
};
JS;
$PAGE->requires->js_amd_inline($cliq_js);
echo $OUTPUT->footer();
