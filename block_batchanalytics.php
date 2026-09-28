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
 * Batch Analytics Dashboard and Course block.
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
     * Locations where this block can be added.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'my' => true,             // User Dashboard (/my/)
            'course-view' => true,    // Course view pages
            'site' => true,           // Frontpage / Site home
        ];
    }

    /**
     * Whether this block has global configuration settings.
     *
     * @return bool
     */
    public function has_config() {
        return true;
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
        global $USER, $PAGE, $OUTPUT, $DB;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        if (!isloggedin() || isguestuser()) {
            return $this->content;
        }

        $context = context_system::instance();
        if (!is_siteadmin() && !has_capability('block/batchanalytics:view', $context)) {
            return $this->content;
        }

        $courseid = 0;
        if (!empty($PAGE->course) && (int)$PAGE->course->id > 1) {
            $courseid = (int)$PAGE->course->id;
        }

        // Course page context: render course-scoped mentor checklist summary
        if ($courseid > 0) {
            $PAGE->requires->css(new moodle_url('/blocks/batchanalytics/dashboard.css', ['v' => filemtime(__DIR__ . '/dashboard.css')]));

            $moduleurl = new moodle_url('/blocks/batchanalytics/module.php', ['courseid' => $courseid]);
            $coursename = format_string($PAGE->course->fullname);

            $html = '<div class="ba-block-widget" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif; color:#1e293b; font-size:13px; line-height:1.5;">';
            $html .= '<div style="margin-bottom:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">';
            $html .= '  <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700; letter-spacing:0.5px;">Current Course</div>';
            $html .= '  <div style="font-weight:600; color:#0f172a; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="' . s($coursename) . '">' . s($coursename) . '</div>';
            $html .= '</div>';

            // Check mentor activities for this course.
            try {
                $mdata = \block_batchanalytics\mentor_activity_service::get_course_mentor_activities($courseid);
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

                $html .= '<div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:6px; margin-bottom:14px; text-align:center;">';
                $html .= '  <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:6px 4px;">';
                $html .= '    <div style="font-size:16px; font-weight:700; color:#166534;">' . $completed . '</div>';
                $html .= '    <div style="font-size:10px; color:#15803d; font-weight:600;">Done</div>';
                $html .= '  </div>';
                $html .= '  <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:6px 4px;">';
                $html .= '    <div style="font-size:16px; font-weight:700; color:#b45309;">' . $pending . '</div>';
                $html .= '    <div style="font-size:10px; color:#b45309; font-weight:600;">Pending</div>';
                $html .= '  </div>';
                $html .= '  <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:6px 4px;">';
                $html .= '    <div style="font-size:16px; font-weight:700; color:#b91c1c;">' . $overdue . '</div>';
                $html .= '    <div style="font-size:10px; color:#b91c1c; font-weight:600;">Overdue</div>';
                $html .= '  </div>';
                $html .= '</div>';
            } catch (\Throwable $e) {
                // Ignore gracefully if tables not populated yet.
            }

            $html .= '<a href="' . s($moduleurl->out(false)) . '" style="display:flex; align-items:center; justify-content:center; gap:6px; background:#1e293b; color:#ffffff; font-weight:600; font-size:12px; padding:8px 12px; border-radius:6px; text-decoration:none; transition:background 0.2s;" onmouseover="this.style.background=\'#334155\'" onmouseout="this.style.background=\'#1e293b\'">';
            $html .= '  <span>View Module Analytics</span>';
            $html .= '  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>';
            $html .= '</a>';
            $html .= '</div>';

        } else {
            // Dashboard (/my/) & Frontpage context: Render full prototype Home page
            $PAGE->requires->css(new moodle_url('/blocks/batchanalytics/block_home.css', ['v' => filemtime(__DIR__ . '/block_home.css')]));
            $PAGE->requires->js(new moodle_url('/blocks/batchanalytics/block_home.js', ['v' => filemtime(__DIR__ . '/block_home.js')]));

            $fullcockpiturl = new moodle_url('/blocks/batchanalytics/index.php');
            $apiurl = (new moodle_url('/blocks/batchanalytics/index.php'))->out(false);

            $html = '
<div class="block-batchanalytics-wrap ba-block-home-wrap" id="ba-block-home-container"
     data-sesskey="' . s(sesskey()) . '"
     data-api-url="' . s($apiurl) . '">

  <div class="shell">

    <!-- Header Section -->
    <div class="ba-block-header">
      <div>
        <h1>Emertxe <span class="accent">–</span> Batch Analytics</h1>
        <div class="sub">Overview of all running batches</div>
      </div>
      <div>
        <a href="' . s($fullcockpiturl->out(false)) . '" class="viewbtn" title="Open full-screen Batch Analytics cockpit">
          <span>Full Cockpit ↗</span>
        </a>
      </div>
    </div>

    <!-- Portfolio At a Glance -->
    <div class="sec-label">Portfolio at a glance</div>
    <div class="tiles" id="ba-block-tiles">
      <!-- Populated dynamically via block_home.js -->
    </div>

    <div class="divider"></div>

    <!-- Search & Filters Card -->
    <div class="searchcard">
      <div class="searchrow">
        <input type="text" id="ba-block-search" placeholder="Search batch code (e.g., 26011, 26022)…">
        <button type="button" class="btn btn-primary" id="ba-block-btn-search">Search</button>
      </div>
      <div class="flabel">Filters</div>
      <div class="filters">
        <select id="ba-block-f-year"><option value="">Year — All</option></select>
        <select id="ba-block-f-batch"><option value="">Batch No — All</option></select>
        <select id="ba-block-f-course"><option value="">Course — All</option></select>
        <select id="ba-block-f-mode"><option value="">Mode — All</option><option value="Online">Online</option><option value="Offline">Offline</option></select>
      </div>
    </div>

    <!-- Sub-tabs (Running vs Completed) -->
    <div class="listtabs">
      <div class="ltab active" id="ba-block-tab-running" role="button" tabindex="0">Current Running Batches</div>
      <div class="ltab" id="ba-block-tab-completed" role="button" tabindex="0">Completed Batches</div>
    </div>

    <!-- Results State -->
    <div id="ba-block-resultsState">
      <div class="rhead">
        <span class="title"><span class="bar"></span><span id="ba-block-resultsTitle">Current Running Batches</span></span>
        <span class="pageinfo" id="ba-block-pageInfo">Loading batches...</span>
      </div>
      <div class="tablecard">
        <table>
          <thead id="ba-block-listHead"></thead>
          <tbody id="ba-block-rowsBody">
            <tr><td colspan="8" style="text-align:center; padding:24px; color:#64748b;">Loading batches...</td></tr>
          </tbody>
        </table>
      </div>
      <div class="pager" id="ba-block-pager"></div>
    </div>

    <!-- Empty State -->
    <div id="ba-block-emptyState" class="empty">
      <h2>No matching batch found</h2>
      <p>We couldn’t find a batch for that code. Check it and search again.</p>
    </div>

  </div> <!-- /.shell -->

</div> <!-- /.ba-block-home-wrap -->
';
        }

        $this->content->text = $html;
        return $this->content;
    }
}
