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
 * Admin setting for managing the master pool of mentor activities with drag-and-drop reordering.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_mentor_master_activities extends \admin_setting {

    public function __construct($name, $visiblename, $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    public function get_setting() {
        return $this->config_read($this->name);
    }

    public function get_defaultsetting() {
        return json_encode(mentor_activity_service::DEFAULT_MASTER_ACTIVITIES);
    }

    public function write_setting($data) {
        $activities = $this->normalise_activities($data);
        if ($activities === null) {
            return get_string('invalidjson', 'error');
        }

        $this->config_write($this->name, json_encode($activities));
        return '';
    }

    /**
     * Parse raw string / json into a clean list of unique activity names.
     *
     * @param mixed $data
     * @return string[]|null
     */
    private function normalise_activities($data): ?array {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                $lines = preg_split('/\r\n|\r|\n/', $data);
                $data = $lines;
            }
        }

        if (!is_array($data)) {
            return null;
        }

        $clean = [];
        foreach ($data as $item) {
            $name = '';
            if (is_string($item)) {
                $name = trim(strip_tags($item));
            } else if (is_array($item) && isset($item['name'])) {
                $name = trim(strip_tags((string)$item['name']));
            }
            if ($name !== '' && !in_array($name, $clean, true)) {
                $clean[] = $name;
            }
        }

        return $clean;
    }

    public function output_html($data, $query = '') {
        $id = $this->get_id();
        $name = $this->get_full_name();

        $activities = $this->normalise_activities($data);
        if ($activities === null || empty($activities)) {
            $activities = mentor_activity_service::DEFAULT_MASTER_ACTIVITIES;
        }

        $form = '<input type="hidden" id="' . $id . '" name="' . $name . '" value="'
            . htmlspecialchars(json_encode($activities), ENT_QUOTES) . '">';
        $form .= '<table id="' . $id . '_table" style="width:100%;max-width:680px;border-collapse:collapse;margin-bottom:8px">';
        $form .= '<thead><tr style="background:#f5f5f5;font-size:0.85em">';
        $form .= '<th style="width:28px"></th>';
        $form .= '<th style="text-align:left;padding:6px 8px">Activity Name</th>';
        $form .= '<th style="width:36px"></th></tr></thead>';
        $form .= '<tbody id="' . $id . '_tbody">';
        foreach ($activities as $act) {
            $form .= $this->render_row($act);
        }
        $form .= '</tbody></table>';
        $form .= '<button type="button" onclick="baMentorMasterAddRow(\'' . $id
            . '\')" class="btn btn-secondary btn-sm">+ Add Activity</button>';
        $form .= $this->inline_js($id);

        return format_admin_setting($this, $this->visiblename, $form, $this->description, false, '', null, $query);
    }

    private function render_row(string $actname): string {
        $name = htmlspecialchars($actname, ENT_QUOTES);
        $html = '<tr draggable="true" style="border-bottom:1px solid #e0e0e0;cursor:grab">';
        $html .= '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>';
        $html .= '<td style="padding:4px 6px"><input type="text" class="ba-mentor-master-act-name form-control form-control-sm" value="'
            . $name . '" placeholder="e.g. Assignment evaluation" style="font-size:0.88em"></td>';
        $html .= '<td style="text-align:center;padding:4px 6px"><button type="button" onclick="this.closest(\'tr\').remove();" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button></td>';
        $html .= '</tr>';
        return $html;
    }

    private function inline_js(string $id): string {
        return <<<ENDJS
<script>
(function () {
  var tbody = document.getElementById('{$id}_tbody');
  var hidden = document.getElementById('{$id}');

  function serialize() {
    var result = [];
    tbody.querySelectorAll('tr').forEach(function (row) {
      var input = row.querySelector('.ba-mentor-master-act-name');
      if (!input) return;
      var val = input.value.trim();
      if (val && result.indexOf(val) === -1) {
        result.push(val);
      }
    });
    hidden.value = JSON.stringify(result);

    // Notify grouping rules settings to update their multi-select options
    if (typeof window.baOnMentorActivitiesChanged === 'function') {
      window.baOnMentorActivitiesChanged(result);
    }
  }

  tbody.addEventListener('change', serialize);
  tbody.addEventListener('input', serialize);
  var form = tbody.closest('form');
  if (form) form.addEventListener('submit', serialize);

  // ---- HTML5 drag-and-drop reorder ----
  var dragging = null;

  tbody.addEventListener('dragstart', function (e) {
    dragging = e.target.closest('tr');
    if (dragging) {
      dragging.style.opacity = '0.4';
      e.dataTransfer.effectAllowed = 'move';
    }
  });

  tbody.addEventListener('dragend', function () {
    if (dragging) dragging.style.opacity = '';
    dragging = null;
    serialize();
  });

  tbody.addEventListener('dragover', function (e) {
    e.preventDefault();
    if (!dragging) return;
    var target = e.target.closest('tr');
    if (target && target !== dragging && target.parentNode === tbody) {
      var rect = target.getBoundingClientRect();
      var after = e.clientY > rect.top + rect.height / 2;
      tbody.insertBefore(dragging, after ? target.nextSibling : target);
    }
  });
})();

function baMentorMasterAddRow(id) {
  var tbody = document.getElementById(id + '_tbody');
  var tr = document.createElement('tr');
  tr.setAttribute('draggable', 'true');
  tr.style.cssText = 'border-bottom:1px solid #e0e0e0;cursor:grab';
  tr.innerHTML =
    '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>'
    + '<td style="padding:4px 6px"><input type="text" class="ba-mentor-master-act-name form-control form-control-sm" placeholder="e.g. New Activity" style="font-size:0.88em"></td>'
    + '<td style="text-align:center;padding:4px 6px"><button type="button" onclick="this.closest(\'tr\').remove();" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button></td>';
  tbody.appendChild(tr);
  tr.querySelector('.ba-mentor-master-act-name').focus();

  tr.addEventListener('change', function () {
    tbody.dispatchEvent(new Event('change', { bubbles: true }));
  });
  tr.addEventListener('input', function () {
    tbody.dispatchEvent(new Event('input', { bubbles: true }));
  });
}
</script>
ENDJS;
    }
}
