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

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Custom admin setting button to open the Zoho Cliq Notification Templates configuration page.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_cliq_templates_button extends \admin_setting {

    public function __construct($name, $visiblename, $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    public function get_setting() {
        return true;
    }

    public function write_setting($data) {
        return '';
    }

    public function output_html($data, $query = '') {
        $url = new \moodle_url('/local/batchanalytics/cliq_templates.php');

        $html = '
        <div class="ba-cliq-setting-box" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; max-width:820px; margin-top:6px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-weight:700; font-size:14px; color:#1e293b; margin-bottom:4px; display:flex; align-items:center; gap:8px;">
                        <span>Kajal Bot Notification Templates</span>
                        <span style="background:#e0e7ff; color:#3730a3; font-size:11px; font-weight:600; padding:2px 8px; border-radius:12px;">42 Templates</span>
                    </div>
                    <div style="font-size:13px; color:#64748b; max-width:540px; line-height:1.45;">
                        Configure and customize automated message templates for batch start, module progress, operational checklist reminders, attendance nudges, and escalations across PM, SSE, Class Mentor, and Lab Mentor roles.
                    </div>
                </div>
                <div>
                    <a href="' . s($url->out(false)) . '" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px; font-weight:600; font-size:13px; padding:9px 18px; border-radius:6px; background:#1b6ec2; border-color:#1b6ec2; text-decoration:none; color:#fff; box-shadow:0 1px 2px rgba(0,0,0,0.06);">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                            <polyline points="15 3 21 3 21 9"></polyline>
                            <line x1="10" y1="14" x2="21" y2="3"></line>
                        </svg>
                        <span>View Cliq Templates</span>
                    </a>
                </div>
            </div>
        </div>';

        return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', '', $query);
    }
}
