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
            <?php echo get_string('cliq_tpl_banner_title', 'local_batchanalytics'); ?>
        </h2>
        <p>
            <?php echo get_string('cliq_tpl_banner_desc', 'local_batchanalytics'); ?>
        </p>
    </div>

    <!-- Status & Master Controls -->
    <div class="cliq-status-bar">
        <div class="cliq-status-badges">
            <span><strong><?php echo get_string('cliq_tpl_status', 'local_batchanalytics'); ?></strong></span>
            <?php if ($is_enabled): ?>
                <span class="cliq-badge-pill cliq-badge-success">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #16a34a; display: inline-block;"></span>
                    <?php echo get_string('cliq_tpl_active', 'local_batchanalytics'); ?>
                </span>
            <?php else: ?>
                <span class="cliq-badge-pill cliq-badge-warning">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #d97706; display: inline-block;"></span>
                    <?php echo get_string('cliq_tpl_disabled', 'local_batchanalytics'); ?>
                </span>
            <?php endif; ?>
            <span class="cliq-badge-pill cliq-badge-secondary">
                <?php echo get_string('cliq_tpl_cron_schedule', 'local_batchanalytics'); ?>
            </span>
            <a href="<?php echo $settingsurl->out(); ?>" class="btn btn-sm btn-link text-decoration-none">
                ⚙️ <?php echo get_string('cliq_tpl_plugin_settings', 'local_batchanalytics'); ?>
            </a>
        </div>
        <div class="cliq-top-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-expand-all" data-action="expand-all">
                📂 <?php echo get_string('cliq_tpl_expand_all', 'local_batchanalytics'); ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-collapse-all" data-action="collapse-all">
                📁 <?php echo get_string('cliq_tpl_collapse_all', 'local_batchanalytics'); ?>
            </button>
            <button type="button" class="btn btn-sm btn-primary" data-action="submit-form">
                💾 <?php echo get_string('cliq_tpl_save_all', 'local_batchanalytics'); ?>
            </button>
        </div>
    </div>

    <form method="post" action="<?php echo $pageurl->out(); ?>" id="cliq-templates-form">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="save">

        <?php foreach ($defs as $cat_key => $cat): ?>
            <div class="cliq-section-card" id="section-<?php echo $cat_key; ?>">
                <!-- Section Header (Collapsible) -->
                <div class="cliq-section-header" data-action="toggle-section" data-target="<?php echo $cat_key; ?>" style="cursor: pointer;">
                    <div class="cliq-section-title-group">
                        <h3>
                            <?php if ($cat_key === 'mentor'): ?>
                                🎓 <?php echo htmlspecialchars($cat['title']); ?>
                            <?php else: ?>
                                💼 <?php echo htmlspecialchars($cat['title']); ?>
                            <?php endif; ?>
                            <span class="badge badge-secondary" style="font-size: 11px; font-weight: normal; vertical-align: middle;">
                                <?php echo count($cat['stages']); ?> <?php echo get_string('cliq_tpl_triggers', 'local_batchanalytics'); ?>
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
                            <div class="cliq-stage-header" data-action="toggle-stage" data-target="<?php echo $k; ?>" style="cursor: pointer;">
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
                                        <span><?php echo get_string('cliq_tpl_field_subject', 'local_batchanalytics'); ?></span>
                                        <span class="cliq-field-hint"><?php echo get_string('cliq_tpl_field_subject_hint', 'local_batchanalytics'); ?></span>
                                    </label>
                                    <input type="text"
                                           class="cliq-input-subject cliq-active-target"
                                           id="tpl-sub-<?php echo $k; ?>"
                                           name="templates[<?php echo $k; ?>][subject]"
                                           data-stage-key="<?php echo $k; ?>"
                                           value="<?php echo htmlspecialchars($cur_subject); ?>"
                                           placeholder="<?php echo htmlspecialchars($stg['default_subject']); ?>">
                                </div>

                                <!-- Body Field -->
                                <div class="cliq-form-group">
                                    <label for="tpl-bod-<?php echo $k; ?>">
                                        <span><?php echo get_string('cliq_tpl_field_body', 'local_batchanalytics'); ?></span>
                                        <span class="cliq-field-hint"><?php echo get_string('cliq_tpl_field_body_hint', 'local_batchanalytics'); ?></span>
                                    </label>
                                    <textarea class="cliq-textarea-body cliq-active-target"
                                              id="tpl-bod-<?php echo $k; ?>"
                                              name="templates[<?php echo $k; ?>][body]"
                                              data-stage-key="<?php echo $k; ?>"
                                              rows="6"
                                              placeholder="<?php echo htmlspecialchars($stg['default_body']); ?>"><?php echo htmlspecialchars($cur_body); ?></textarea>
                                </div>

                                <!-- Dynamic Placeholders Tokens -->
                                <div class="cliq-tokens-box">
                                    <span class="cliq-tokens-label"><?php echo get_string('cliq_tpl_tokens_label', 'local_batchanalytics'); ?></span>
                                    <div class="cliq-token-chips">
                                        <?php foreach ($stg['placeholders'] as $ph): ?>
                                            <button type="button"
                                                    class="cliq-token-chip"
                                                    data-action="insert-placeholder"
                                                    data-target="<?php echo $k; ?>"
                                                    data-token="<?php echo htmlspecialchars($ph); ?>">
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
                                                data-action="reset-stage"
                                                data-target="<?php echo $k; ?>">
                                            🔄 <?php echo get_string('cliq_tpl_btn_reset', 'local_batchanalytics'); ?>
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info"
                                                data-action="toggle-preview"
                                                data-target="<?php echo $k; ?>">
                                            👁️ <?php echo get_string('cliq_tpl_btn_preview', 'local_batchanalytics'); ?>
                                        </button>
                                    </div>
                                </div>

                                <!-- Mock Cliq Card Preview -->
                                <div class="cliq-preview-card" id="preview-<?php echo $k; ?>" style="border-left-color: <?php echo $stg['badge_color']; ?>;">
                                    <div class="cliq-preview-title" id="preview-title-<?php echo $k; ?>">
                                        <!-- Rendered dynamically via AMD -->
                                    </div>
                                    <div class="cliq-preview-body" id="preview-body-<?php echo $k; ?>">
                                        <!-- Rendered dynamically via AMD -->
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
                💡 <?php echo get_string('cliq_tpl_footer_note', 'local_batchanalytics'); ?>
            </div>
            <div class="cliq-bottom-right">
                <a href="<?php echo $settingsurl->out(); ?>" class="btn btn-outline-light btn-sm">
                    <?php echo get_string('cliq_tpl_btn_cancel', 'local_batchanalytics'); ?>
                </a>
                <button type="submit" class="btn btn-primary btn-sm px-4" style="font-weight: 600;">
                    💾 <?php echo get_string('cliq_tpl_btn_save_bottom', 'local_batchanalytics'); ?>
                </button>
            </div>
        </div>

    </form>
</div>

<?php
$PAGE->requires->js_call_amd('local_batchanalytics/cliq_templates', 'init');
echo $OUTPUT->footer();
echo $OUTPUT->footer();
