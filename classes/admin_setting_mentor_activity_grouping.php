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
 * Admin setting for course grouping rules with group name, multi-select activities, and drag-and-drop reordering.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_mentor_activity_grouping extends \admin_setting {

    public function __construct($name, $visiblename, $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    public function get_setting() {
        return $this->config_read($this->name);
    }

    public function get_defaultsetting() {
        $default_rules = [
            [
                'group' => 'Advanced C',
                'activities' => [
                    'Assignment evaluation',
                    'Project evaluation',
                    'Spot award nomination - mid c',
                    'Spot award nomination - End C',
                    'Power track nomination',
                ],
            ],
            [
                'group' => 'Data Structure',
                'activities' => [
                    'Assignment evaluation',
                    'Project evaluation',
                    'Spot award nomination',
                    'Power track nomination',
                ],
            ],
            [
                'group' => 'Default',
                'activities' => [
                    'Assignment evaluation',
                    'Project evaluation',
                    'Spot award nomination',
                    'Power track nomination',
                ],
            ],
        ];
        return json_encode($default_rules);
    }

    public function write_setting($data) {
        $rules = $this->normalise_rules($data);
        if ($rules === null) {
            return get_string('invalidjson', 'error');
        }

        $this->config_write($this->name, json_encode($rules));
        return '';
    }

    /**
     * Normalise rules from either JSON or legacy colon-separated lines.
     *
     * @param mixed $data
     * @return array|null
     */
    private function normalise_rules($data): ?array {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                // Parse legacy format
                $lines = preg_split('/\r\n|\r|\n/', $data);
                $rules = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, ':') === false) {
                        continue;
                    }
                    [$grp, $acts_str] = explode(':', $line, 2);
                    $grp = trim(strip_tags($grp));
                    if ($grp === '') {
                        continue;
                    }
                    $acts = array_map('trim', explode(',', $acts_str));
                    $acts = array_values(array_filter($acts, static function($a) {
                        return $a !== '';
                    }));
                    $rules[] = [
                        'group' => $grp,
                        'activities' => $acts,
                    ];
                }
                $data = $rules;
            }
        }

        if (!is_array($data)) {
            return null;
        }

        $clean = [];
        $seen_groups = [];

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $grp = trim(strip_tags((string)($item['group'] ?? '')));
            if ($grp === '') {
                continue;
            }

            $key = mb_strtolower($grp);
            if (isset($seen_groups[$key])) {
                continue;
            }
            $seen_groups[$key] = true;

            $raw_acts = $item['activities'] ?? [];
            if (!is_array($raw_acts)) {
                if (is_string($raw_acts)) {
                    $raw_acts = explode(',', $raw_acts);
                } else {
                    $raw_acts = [];
                }
            }

            $clean_acts = [];
            foreach ($raw_acts as $act) {
                $a = trim(strip_tags((string)$act));
                if ($a !== '' && !in_array($a, $clean_acts, true)) {
                    $clean_acts[] = $a;
                }
            }

            $clean[] = [
                'group' => $grp,
                'activities' => $clean_acts,
            ];
        }

        return $clean;
    }

    public function output_html($data, $query = '') {
        $id = $this->get_id();
        $name = $this->get_full_name();

        $rules = $this->normalise_rules($data);
        if ($rules === null || empty($rules)) {
            $rules = json_decode($this->get_defaultsetting(), true);
        }

        $master_activities = mentor_activity_service::get_master_activities();

        $form = '<input type="hidden" id="' . $id . '" name="' . $name . '" value="'
            . htmlspecialchars(json_encode($rules), ENT_QUOTES) . '">';
        $form .= '<table id="' . $id . '_table" style="width:100%;max-width:850px;border-collapse:collapse;margin-bottom:8px">';
        $form .= '<thead><tr style="background:#f5f5f5;font-size:0.85em">';
        $form .= '<th style="width:28px"></th>';
        $form .= '<th style="text-align:left;padding:6px 8px;width:32%">Course Match / Group Name</th>';
        $form .= '<th style="text-align:left;padding:6px 8px">Assigned Activities (Multi-Select)</th>';
        $form .= '<th style="width:36px"></th></tr></thead>';
        $form .= '<tbody id="' . $id . '_tbody">';
        foreach ($rules as $rule) {
            $form .= $this->render_row($rule, $master_activities);
        }
        $form .= '</tbody></table>';
        $form .= '<button type="button" onclick="baMentorGroupingAddRow(\'' . $id
            . '\')" class="btn btn-secondary btn-sm">+ Add Group Rule</button>';
        $form .= $this->inline_js($id, $master_activities);

        return format_admin_setting($this, $this->visiblename, $form, $this->description, false, '', null, $query);
    }

    private function render_row(array $rule, array $all_acts): string {
        $group = htmlspecialchars((string)($rule['group'] ?? ''), ENT_QUOTES);
        $selected_acts = array_map('trim', (array)($rule['activities'] ?? []));

        $html = '<tr draggable="true" style="border-bottom:1px solid #e0e0e0;cursor:grab">';
        $html .= '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>';
        $html .= '<td style="padding:4px 6px;vertical-align:top">';
        $html .= '<input type="text" class="ba-mentor-group-name form-control form-control-sm" value="'
            . $group . '" placeholder="e.g. Advanced C" style="font-size:0.88em;font-weight:600;">';
        $html .= '<div style="font-size:11px;color:#6b7280;margin-top:4px;">Matches course name keywords or acts as a dropdown option.</div>';
        $html .= '</td>';

        $html .= '<td style="padding:4px 6px;vertical-align:top">';
        $html .= '<select multiple class="ba-mentor-group-acts form-control form-control-sm" size="5" style="min-height:110px;font-size:0.85em;padding:4px;">';

        // Include any selected activities even if not in master list currently
        $combined_acts = array_unique(array_merge($all_acts, $selected_acts));

        foreach ($combined_acts as $act) {
            $isSelected = in_array(trim($act), $selected_acts, true);
            $html .= '<option value="' . htmlspecialchars($act, ENT_QUOTES) . '"'
                . ($isSelected ? ' selected' : '') . '>'
                . htmlspecialchars($act, ENT_QUOTES)
                . '</option>';
        }

        $html .= '</select>';
        $html .= '<div style="font-size:11px;color:#6b7280;margin-top:3px;display:flex;justify-content:space-between;">'
            . '<span>Click items to toggle · Hold Ctrl/Cmd for multi-select</span>'
            . '<span class="ba-group-selected-count" style="font-weight:600;color:#1e40af;">' . count($selected_acts) . ' selected</span>'
            . '</div>';
        $html .= '</td>';

        $html .= '<td style="text-align:center;padding:4px 6px;vertical-align:top">';
        $html .= '<button type="button" onclick="this.closest(\'tr\').remove();" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button>';
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    private function inline_js(string $id, array $master_activities): string {
        $master_json = json_encode($master_activities);
        return <<<ENDJS
<script>
(function () {
  var tbody = document.getElementById('{$id}_tbody');
  var hidden = document.getElementById('{$id}');
  var currentMasterPool = {$master_json};

  function updateSelectedCount(selectEl) {
    var count = 0;
    for (var i = 0; i < selectEl.options.length; i++) {
      if (selectEl.options[i].selected) count++;
    }
    var tr = selectEl.closest('tr');
    if (tr) {
      var badge = tr.querySelector('.ba-group-selected-count');
      if (badge) badge.textContent = count + ' selected';
    }
  }

  function serialize() {
    var result = [];
    tbody.querySelectorAll('tr').forEach(function (row) {
      var nameInput = row.querySelector('.ba-mentor-group-name');
      var selectEl = row.querySelector('.ba-mentor-group-acts');
      if (!nameInput || !selectEl) return;

      var groupName = nameInput.value.trim();
      if (!groupName) return;

      var selectedActs = [];
      for (var i = 0; i < selectEl.options.length; i++) {
        if (selectEl.options[i].selected) {
          selectedActs.push(selectEl.options[i].value);
        }
      }

      result.push({
        group: groupName,
        activities: selectedActs
      });
    });
    hidden.value = JSON.stringify(result);
  }

  // Toggle on mousedown without needing Ctrl
  tbody.addEventListener('mousedown', function (e) {
    if (e.target.tagName === 'OPTION') {
      e.preventDefault();
      var opt = e.target;
      opt.selected = !opt.selected;
      var sel = opt.closest('select');
      if (sel) {
        updateSelectedCount(sel);
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  });

  tbody.addEventListener('change', function (e) {
    if (e.target.classList.contains('ba-mentor-group-acts')) {
      updateSelectedCount(e.target);
    }
    serialize();
  });
  tbody.addEventListener('input', serialize);
  var form = tbody.closest('form');
  if (form) form.addEventListener('submit', serialize);

  // Hook for live updates when master pool changes above
  window.baOnMentorActivitiesChanged = function (newActivitiesList) {
    if (!Array.isArray(newActivitiesList)) return;
    currentMasterPool = newActivitiesList;

    tbody.querySelectorAll('tr').forEach(function (row) {
      var selectEl = row.querySelector('.ba-mentor-group-acts');
      if (!selectEl) return;

      var currentlySelected = [];
      for (var i = 0; i < selectEl.options.length; i++) {
        if (selectEl.options[i].selected) {
          currentlySelected.push(selectEl.options[i].value);
        }
      }

      // Rebuild options preserving selection
      selectEl.innerHTML = '';
      var allOptions = [];
      newActivitiesList.forEach(function (a) {
        if (allOptions.indexOf(a) === -1) allOptions.push(a);
      });
      currentlySelected.forEach(function (a) {
        if (allOptions.indexOf(a) === -1) allOptions.push(a);
      });

      allOptions.forEach(function (act) {
        var opt = document.createElement('option');
        opt.value = act;
        opt.textContent = act;
        if (currentlySelected.indexOf(act) !== -1) {
          opt.selected = true;
        }
        selectEl.appendChild(opt);
      });

      updateSelectedCount(selectEl);
    });
    serialize();
  };

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

  window.baGetMasterActivitiesPool = function() {
    return currentMasterPool;
  };
})();

function baMentorGroupingAddRow(id) {
  var tbody = document.getElementById(id + '_tbody');
  var masterPool = typeof window.baGetMasterActivitiesPool === 'function'
    ? window.baGetMasterActivitiesPool()
    : [];

  var optHtml = '';
  masterPool.forEach(function (act) {
    optHtml += '<option value="' + act.replace(/"/g, '&quot;') + '" selected>'
      + act.replace(/</g, '&lt;').replace(/>/g, '&gt;')
      + '</option>';
  });

  var tr = document.createElement('tr');
  tr.setAttribute('draggable', 'true');
  tr.style.cssText = 'border-bottom:1px solid #e0e0e0;cursor:grab';
  tr.innerHTML =
    '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>'
    + '<td style="padding:4px 6px;vertical-align:top">'
    + '<input type="text" class="ba-mentor-group-name form-control form-control-sm" placeholder="e.g. Python Fullstack" style="font-size:0.88em;font-weight:600;">'
    + '<div style="font-size:11px;color:#6b7280;margin-top:4px;">Matches course name keywords or acts as a dropdown option.</div>'
    + '</td>'
    + '<td style="padding:4px 6px;vertical-align:top">'
    + '<select multiple class="ba-mentor-group-acts form-control form-control-sm" size="5" style="min-height:110px;font-size:0.85em;padding:4px;">'
    + optHtml
    + '</select>'
    + '<div style="font-size:11px;color:#6b7280;margin-top:3px;display:flex;justify-content:space-between;">'
    + '<span>Click items to toggle · Hold Ctrl/Cmd for multi-select</span>'
    + '<span class="ba-group-selected-count" style="font-weight:600;color:#1e40af;">' + masterPool.length + ' selected</span>'
    + '</div>'
    + '</td>'
    + '<td style="text-align:center;padding:4px 6px;vertical-align:top">'
    + '<button type="button" onclick="this.closest(\'tr\').remove();" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button>'
    + '</td>';

  tbody.appendChild(tr);
  tr.querySelector('.ba-mentor-group-name').focus();

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
