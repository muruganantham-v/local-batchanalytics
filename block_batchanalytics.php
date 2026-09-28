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

        // Include dashboard CSS.
        $PAGE->requires->css(new moodle_url('/blocks/batchanalytics/dashboard.css'));

        $courseid = 0;
        if (!empty($PAGE->course) && (int)$PAGE->course->id > 1) {
            $courseid = (int)$PAGE->course->id;
        }

        $html = '<div class="ba-block-widget" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif; color:#1e293b; font-size:13px; line-height:1.5;">';

        // Course page context.
        if ($courseid > 0) {
            $moduleurl = new moodle_url('/blocks/batchanalytics/module.php', ['courseid' => $courseid]);
            $coursename = format_string($PAGE->course->fullname);

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

        } else {
            // Dashboard / Frontpage context.
            $homeurl = new moodle_url('/blocks/batchanalytics/index.php');

            // Quick summary counts if bm tables exist.
            $totalbatches = 0;
            $dbman = $DB->get_manager();
            if ($dbman->table_exists('local_bm_classsection')) {
                $totalbatches = (int)$DB->count_records('local_bm_classsection');
            } else if ($dbman->table_exists('local_bm_batch')) {
                $totalbatches = (int)$DB->count_records('local_bm_batch');
            }

            $html .= '<div style="margin-bottom:12px; background:linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%); border:1px solid #dbeafe; border-radius:8px; padding:12px;">';
            $html .= '  <div style="display:flex; justify-content:space-between; align-items:center;">';
            $html .= '    <div>';
            $html .= '      <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700; letter-spacing:0.5px;">Active Batches</div>';
            $html .= '      <div style="font-size:22px; font-weight:800; color:#1e40af; line-height:1.2;">' . $totalbatches . '</div>';
            $html .= '    </div>';
            $html .= '    <div style="background:#dbeafe; color:#1e40af; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center;">';
            $html .= '      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"></path><path d="m19 9-5 5-4-4-3 3"></path></svg>';
            $html .= '    </div>';
            $html .= '  </div>';
            $html .= '  <div style="font-size:11px; color:#64748b; margin-top:6px;">Real-time performance, attendance &amp; mentor tracking.</div>';
            $html .= '</div>';

            $html .= '<a href="' . s($homeurl->out(false)) . '" style="display:flex; align-items:center; justify-content:center; gap:6px; background:#1e293b; color:#ffffff; font-weight:600; font-size:12px; padding:9px 12px; border-radius:6px; text-decoration:none; transition:background 0.2s; box-shadow:0 1px 2px rgba(0,0,0,0.05);" onmouseover="this.style.background=\'#334155\'" onmouseout="this.style.background=\'#1e293b\'">';
            $html .= '  <span>Open Batch Analytics</span>';
            $html .= '  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>';
            $html .= '</a>';
        }

        $html .= '</div>';

        $this->content->text = $html;
        return $this->content;
    }
}
