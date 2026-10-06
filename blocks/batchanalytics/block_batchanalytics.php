<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Batch Analytics Dashboard and Course block.
 * Renders individual To-Do tasks for Mentors, SS Executives, PMs, and Managers.
 *
 * @package    block_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class block_batchanalytics extends block_base {

    /**
     * Initialise the block.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_batchanalytics');
    }

    /**
     * Hide block header to remove default title card when not in edit mode.
     *
     * @return bool
     */
    public function hide_header() {
        return empty($this->page->user_is_editing);
    }

    /**
     * Locations where this block can be added.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'my'          => true,  // User Dashboard (/my/)
            'course-view' => true,  // Course view pages
            'site'        => true,  // Frontpage / Site home
        ];
    }

    /**
     * Whether this block has global configuration settings.
     *
     * @return bool
     */
    public function has_config() {
        return false;
    }

    /**
     * Allow multiple instances in the same context?
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * Generate the block content.
     *
     * @return stdClass
     */
    public function get_content() {
        global $USER, $PAGE, $CFG;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        if (!isloggedin() || isguestuser()) {
            return $this->content;
        }

        // Verify that local_batchanalytics is present
        $local_task_service = $CFG->dirroot . '/local/batchanalytics/classes/task_service.php';
        if (!file_exists($local_task_service)) {
            $this->content->text = '<div class="alert alert-warning">local_batchanalytics plugin is required.</div>';
            return $this->content;
        }
        require_once($local_task_service);

        $context = context_system::instance();
        $can_view = is_siteadmin($USER->id)
            || has_capability('block/batchanalytics:view', $context)
            || has_capability('local/batchanalytics:view', $context)
            || \local_batchanalytics\task_service::can_view_dashboard((int)$USER->id);

        if (!$can_view) {
            return $this->content;
        }

        $courseid = 0;
        if (!empty($PAGE->course) && (int)$PAGE->course->id > 1) {
            $courseid = (int)$PAGE->course->id;
        }

        // Course page context: render course-scoped mentor checklist summary
        if ($courseid > 0) {
            $moduleurl = new moodle_url('/local/batchanalytics/module.php', ['courseid' => $courseid]);
            $coursename = format_string($PAGE->course->fullname);

            $html = '<div class="ba-block-widget" style="font-family:\'Poppins\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif; color:#0f172a; font-size:13px; line-height:1.5;">';
            $html .= '<div style="margin-bottom:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px;">';
            $html .= '  <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700; letter-spacing:0.5px;">Current Course</div>';
            $html .= '  <div style="font-weight:600; color:#0f172a; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="' . s($coursename) . '">' . s($coursename) . '</div>';
            $html .= '</div>';

            // Check mentor activities for this course
            try {
                $mdata = \local_batchanalytics\mentor_activity_service::get_course_mentor_activities($courseid, $coursename);
                $activities = $mdata['activities'] ?? [];
                $pending = 0;
                $overdue = 0;
                $completed = 0;
                foreach ($activities as $act) {
                    $status = $act['action_status'] ?? '';
                    if ($status === 'completed') {
                        $completed++;
                    } else if (str_starts_with($status, 'overdue')) {
                        $overdue++;
                    } else if ($status === 'pending') {
                        $pending++;
                    }
                }

                $html .= '<div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; margin-bottom:14px; text-align:center;">';
                $html .= '  <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:8px 4px;">';
                $html .= '    <div style="font-size:18px; font-weight:800; color:#166534;">' . $completed . '</div>';
                $html .= '    <div style="font-size:11px; color:#15803d; font-weight:600;">Done</div>';
                $html .= '  </div>';
                $html .= '  <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:8px 4px;">';
                $html .= '    <div style="font-size:18px; font-weight:800; color:#b45309;">' . $pending . '</div>';
                $html .= '    <div style="font-size:11px; color:#b45309; font-weight:600;">Pending</div>';
                $html .= '  </div>';
                $html .= '  <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:8px 4px;">';
                $html .= '    <div style="font-size:18px; font-weight:800; color:#b91c1c;">' . $overdue . '</div>';
                $html .= '    <div style="font-size:11px; color:#b91c1c; font-weight:600;">Overdue</div>';
                $html .= '  </div>';
                $html .= '</div>';
            } catch (\Throwable $e) {
                // Ignore gracefully if not populated
            }

            $html .= '<a href="' . s($moduleurl->out(false)) . '" style="display:flex; align-items:center; justify-content:center; gap:6px; background:#0f172a; color:#ffffff; font-weight:600; font-size:12.5px; padding:9px 14px; border-radius:8px; text-decoration:none; transition:background 0.2s;">';
            $html .= '  <span>View Module Analytics</span>';
            $html .= '  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>';
            $html .= '</a>';
            $html .= '</div>';

        } else {
            // Dashboard (/my/) & Frontpage context: Render To-Do task dashboard
            $PAGE->requires->css(new moodle_url('/blocks/batchanalytics/block_task_dashboard.css', ['v' => filemtime(__DIR__ . '/block_task_dashboard.css')]));
            $PAGE->requires->js(new moodle_url('/blocks/batchanalytics/block_task_dashboard.js', ['v' => filemtime(__DIR__ . '/block_task_dashboard.js')]));

            $apiurl = (new moodle_url('/local/batchanalytics/index.php'))->out(false);
            $dashdata = \local_batchanalytics\task_service::get_dashboard_data((int)$USER->id);

            $html = '
<div class="block-batchanalytics-wrap ba-task-dash-wrap" id="ba-task-dash-container"
     data-sesskey="' . s(sesskey()) . '"
     data-api-url="' . s($apiurl) . '">

  <script type="application/json" id="ba-dash-initial-data">' . json_encode($dashdata, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . '</script>

  <!-- Header Section with Greeting and Auto-detected Role Subtitle -->
  <div class="greet">
    <div>
      <h1 id="ba-dash-greeting">' . s($dashdata['greeting']) . '</h1>
      <div class="sub" id="ba-dash-rolesub">' . s($dashdata['role_subtitle']) . '</div>
    </div>';

            if (!empty($dashdata['can_switch_roles']) && count($dashdata['available_roles']) > 1) {
                $html .= '
    <div class="ph-role-switch" style="align-self: flex-start;">
      <select id="ba-role-switcher" class="filter-select" title="Switch Operational Role" aria-label="Switch Operational Role" style="padding: 7px 12px; font-weight: 600; font-size: 13px; border-radius: 8px; border: 1px solid #cbd5e1; background: #ffffff; color: #1e293b; cursor: pointer;">';
                foreach ($dashdata['available_roles'] as $rk => $rlbl) {
                    $sel = ($rk === ($dashdata['active_role'] ?? '')) ? ' selected' : '';
                    $html .= '<option value="' . s($rk) . '"' . $sel . '>' . s($rlbl) . '</option>';
                }
                $html .= '
      </select>
    </div>';
            }

            $html .= '
  </div>

  <!-- Glance Stat Cards -->
  <div class="glance" id="ba-dash-glance">';
            foreach ($dashdata['glance'] as $g) {
                $alert_class = !empty($g['alert']) ? ' alert gt-alert' : '';
                $html .= '<div class="gt' . $alert_class . '">';
                $html .= '  <div class="v">' . s($g['val']) . '</div>';
                $html .= '  <div class="k">' . s($g['lbl']) . '</div>';
                $html .= '</div>';
            }
            $html .= '</div>

  <!-- 2 Columns: Left = My To-Do, Right = Forthcoming -->
  <div class="cols">
    <!-- Left Column: My To-Do -->
    <div class="dpanel">
      <div class="ph">
        <h2>My To-Do <span class="badge" id="ba-dash-todo-count">' . count($dashdata['todo']) . ' pending</span></h2>
        <div class="ph-filters">
          <select class="filter-select" id="ba-batch-filter" title="Filter by Batch" aria-label="Filter by Batch">
            <option value="all">All batches</option>';
            foreach (($dashdata['batches'] ?? []) as $b) {
                $html .= '<option value="' . s($b['id']) . '">' . s($b['name']) . '</option>';
            }
            $html .= '
          </select>
          <select class="filter-select" id="ba-todo-filter" title="Filter by Task Status" aria-label="Filter by Task Status">
            <option value="all">All tasks</option>
            <option value="overdue">Overdue only</option>
            <option value="today">Due today</option>
            <option value="soon">Due next 7 days</option>
          </select>
        </div>
      </div>
      <div id="ba-dash-todo-list">';

            if (empty($dashdata['todo'])) {
                $html .= '<div class="empty-box">✓ No pending tasks matching this filter</div>';
            } else {
                $initial_todos = array_slice($dashdata['todo'], 0, 5);
                foreach ($initial_todos as $t) {
                    $dest_label = ($t['dest_type'] === 'batch') ? 'Go to batch →' : (($t['dest_type'] === 'section') ? 'Go to class section →' : 'Go to module →');
                    $html .= '<div class="todo" id="todo-row-' . s($t['id']) . '">';
                    $html .= '  <div class="body">';
                    $html .= '    <div class="t">' . s($t['title']) . '</div>';
                    $html .= '    <div class="m">' . s($t['meta']) . '</div>';
                    $html .= '    <a href="' . s($t['dest_url']) . '" class="go">' . s($dest_label) . '</a>';
                    $html .= '  </div>';
                    $html .= '  <div class="actions">';
                    $html .= '    <span class="due ' . s($t['status_class']) . '">' . s($t['status_label']) . '</span>';
                    if (!empty($t['action_mode']) && $t['action_mode'] === 'redirect') {
                        $btn_lbl = !empty($t['btn_label']) ? $t['btn_label'] : 'Update →';
                        $act_url = !empty($t['action_url']) ? $t['action_url'] : $t['dest_url'];
                        $html .= '    <a href="' . s($act_url) . '" class="mc-btn2 mc-btn-link">' . s($btn_lbl) . '</a>';
                    } else if (($dashdata['active_role'] ?? '') !== 'admin' && ($dashdata['active_role'] ?? '') !== 'pm' && empty($dashdata['is_pm'])) {
                        $html .= '    <button type="button" class="mc-btn2" data-task-id="' . s($t['id']) . '">Mark Complete</button>';
                    }
                    $html .= '  </div>';
                    $html .= '</div>';
                }
            }

            $html .= '
      </div>
      <div id="ba-dash-todo-pagination" class="ba-pagination-wrap">
        <!-- Pagination controls dynamically rendered -->
      </div>
    </div>

    <!-- Right Column: Forthcoming -->
    <div class="dpanel">
      <div class="ph">
        <h2>Forthcoming</h2>
        <span class="badge">Next 7 days</span>
      </div>
      <div id="ba-dash-fc-list">';

            if (empty($dashdata['forthcoming'])) {
                $html .= '<div class="empty-box">Nothing scheduled in the next 7 days</div>';
            } else {
                foreach ($dashdata['forthcoming'] as $f) {
                    $html .= '<div class="fc">';
                    $html .= '  <div class="t">' . s($f['title']) . '</div>';
                    $html .= '  <div class="m">' . s($f['meta']) . '</div>';
                    $html .= '</div>';
                }
            }

            $html .= '
      </div>
    </div>
  </div>';

            if (($dashdata['active_role'] ?? '') !== 'admin') {
                $html .= '
  <!-- Mark Complete Confirmation Modal -->
  <div class="ba-task-modal-overlay" id="ba-task-modal-overlay">
    <div class="ba-task-modal-box">
      <div class="ba-modal-header">
        <h3 id="ba-modal-title">Mark Activity Complete</h3>
        <button type="button" class="ba-modal-close-btn" id="ba-modal-btn-close" aria-label="Close modal">&times;</button>
      </div>
      <p class="ba-modal-sub">Check submissions and confirm completion of this activity:</p>
      <div class="act-name" id="ba-modal-task-name">-</div>

      <!-- Dynamic validation container -->
      <div class="ba-modal-validation-body" id="ba-modal-validation-body">
        <div class="ba-modal-loading">
          <div class="ba-spinner"></div>
          <span>Checking pending submissions and evaluations...</span>
        </div>
      </div>

      <div class="mbtns" id="ba-modal-actions">
        <button type="button" class="cancel" id="ba-modal-btn-cancel">Cancel</button>
        <a href="#" target="_blank" class="mc-btn-goto-act" id="ba-modal-btn-goto" style="display:none;">Go to Activity ↗</a>
        <button type="button" class="confirm" id="ba-modal-btn-confirm" disabled>Confirm Complete</button>
      </div>
    </div>
  </div>';
            }

            $html .= '
</div>
';
        }

        $this->content->text = $html;
        return $this->content;
    }
}
