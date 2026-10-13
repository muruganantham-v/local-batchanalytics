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
 * Admin setting for course grouping rules with selectable activities, configurable due days, and drag-and-drop.
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
        return json_encode(mentor_activity_service::DEFAULT_GROUPING_RULES);
    }

    public function write_setting($data) {
        $rules = $this->normalise_rules($data);
        if ($rules === null) {
            return get_string('invalidjson', 'error');
        }

        $this->config_write($this->name, json_encode($rules));

        // Automatically sync all existing courses mapped in active class sections to updated group rules
        try {
            mentor_activity_service::sync_all_courses_from_class_sections();
        } catch (\Throwable $e) {
            // Avoid failing the admin settings save if a background sync issue occurs
        }

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
                    $clean_acts = [];
                    foreach ($acts as $a) {
                        if ($a !== '') {
                            $clean_acts[] = [
                                'key'     => mentor_activity_service::slugify_key($a),
                                'name'    => $a,
                                'duedays' => 5,
                            ];
                        }
                    }
                    $rules[] = [
                        'group'      => $grp,
                        'activities' => $clean_acts,
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
            if (is_string($raw_acts)) {
                $raw_acts = explode(',', $raw_acts);
            }

            $clean_acts = [];
            $seen_act_keys = [];

            if (is_array($raw_acts)) {
                foreach ($raw_acts as $act) {
                    $name = '';
                    $act_key = '';
                    $duedays = 5;

                    if (is_string($act)) {
                        $name = trim(strip_tags($act));
                        $act_key = mentor_activity_service::slugify_key($name);
                    } else if (is_array($act)) {
                        $name = trim(strip_tags((string)($act['name'] ?? '')));
                        $act_key = trim(strip_tags((string)($act['key'] ?? '')));
                        if ($act_key === '' && $name !== '') {
                            $act_key = mentor_activity_service::slugify_key($name);
                        }
                        $duedays = isset($act['duedays']) ? (int)$act['duedays'] : 5;
                    }

                    if ($name !== '' && !isset($seen_act_keys[$act_key])) {
                        $seen_act_keys[$act_key] = true;
                        $clean_acts[] = [
                            'key'     => $act_key,
                            'name'    => $name,
                            'duedays' => max(0, $duedays),
                        ];
                    }
                }
            }

            $clean[] = [
                'group'      => $grp,
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
            $rules = mentor_activity_service::DEFAULT_GROUPING_RULES;
        }

        $master_activities = mentor_activity_service::get_master_activities();

        $form = '<input type="hidden" id="' . $id . '" name="' . $name . '" value="'
            . htmlspecialchars(json_encode($rules), ENT_QUOTES) . '">';
        $form .= '<table id="' . $id . '_table" style="width:100%;max-width:960px;border-collapse:collapse;margin-bottom:8px">';
        $form .= '<thead><tr style="background:#f5f5f5;font-size:0.85em">';
        $form .= '<th style="width:28px"></th>';
        $form .= '<th style="text-align:left;padding:6px 8px;width:28%">Course Match / Group Name</th>';
        $form .= '<th style="text-align:left;padding:6px 8px">Assigned Activities &amp; Due Days</th>';
        $form .= '<th style="width:36px"></th></tr></thead>';
        $form .= '<tbody id="' . $id . '_tbody">';
        foreach ($rules as $rule) {
            $form .= $this->render_row($rule, $master_activities);
        }
        $form .= '</tbody></table>';
        $form .= '<button type="button" onclick="baMentorGroupingAddRow(\'' . $id
            . '\')" class="btn btn-secondary btn-sm">+ Add Module Schedule</button>';
        $form .= $this->inline_js($id, $master_activities);

        return format_admin_setting($this, $this->visiblename, $form, $this->description, false, '', null, $query);
    }

    private function render_row(array $rule, array $all_acts): string {
        $group = htmlspecialchars((string)($rule['group'] ?? ''), ENT_QUOTES);
        $assigned_acts = (array)($rule['activities'] ?? []);

        // Track keys already assigned in this group
        $assigned_keys = [];
        foreach ($assigned_acts as $assigned) {
            $k = is_array($assigned) ? ($assigned['key'] ?? '') : (string)$assigned;
            if ($k !== '') {
                $assigned_keys[$k] = true;
            }
        }

        $html = '<tr class="ba-group-row" draggable="true" style="border-bottom:1px solid #e0e0e0;cursor:grab">';
        $html .= '<td class="ba-group-drag-handle" style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px;cursor:grab;">&#8942;&#8942;</td>';
        $html .= '<td style="padding:6px 8px;vertical-align:top">';
        $html .= '<input type="text" class="ba-mentor-group-name form-control form-control-sm" value="'
            . $group . '" placeholder="e.g. Advanced C" style="font-size:0.88em;font-weight:600;">';
        $html .= '<div style="font-size:11px;color:#6b7280;margin-top:4px;">Matches course name keywords or acts as a dropdown option.</div>';
        $html .= '</td>';

        $html .= '<td style="padding:6px 8px;vertical-align:top">';

        // Top toolbar: select activity + due days + add button (filter out already-assigned activities)
        $html .= '<div class="ba-act-add-bar" style="display:flex;align-items:center;gap:6px;margin-bottom:8px;flex-wrap:wrap;">';
        $html .= '<select class="ba-group-act-picker form-control form-control-sm" style="width:auto;min-width:240px;font-size:0.85em;">';

        $has_available = false;
        foreach ($all_acts as $act) {
            $act_key = htmlspecialchars($act['key'], ENT_QUOTES);
            $act_name = htmlspecialchars($act['name'], ENT_QUOTES);
            if (!isset($assigned_keys[$act['key']])) {
                $has_available = true;
                $html .= '<option value="' . $act_key . '" data-name="' . $act_name . '">'
                    . $act_name . ' (' . $act_key . ')'
                    . '</option>';
            }
        }
        if (!$has_available) {
            $html .= '<option value="" disabled selected>(All activities assigned)</option>';
        }
        $html .= '</select>';

        $html .= '<div style="display:inline-flex;align-items:center;gap:4px;">';
        $html .= '<span style="font-size:12px;color:#4b5563;">Due in:</span>';
        $html .= '<input type="number" min="0" class="ba-group-act-days-picker form-control form-control-sm" value="5" style="width:65px;padding:2px 5px;font-size:12px;">';
        $html .= '<span style="font-size:12px;color:#4b5563;">working days</span>';
        $html .= '</div>';

        $disabled_attr = $has_available ? '' : ' disabled';
        $html .= '<button type="button" class="btn btn-secondary btn-sm ba-btn-add-activity" onclick="baAddActivityToGroup(this);"' . $disabled_attr . ' style="padding:2px 8px;font-size:12px;">+ Add Activity</button>';
        $html .= '</div>';

        // Assigned activities table with drag handle column for reordering
        $html .= '<div class="ba-assigned-acts-wrap" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:4px 6px;">';
        $html .= '<table class="ba-assigned-acts-table" style="width:100%;border-collapse:collapse;">';
        $html .= '<thead><tr style="font-size:11px;color:#6b7280;border-bottom:1px solid #e5e7eb;text-align:left;">';
        $html .= '<th style="width:24px;"></th>';
        $html .= '<th style="padding:3px 6px;">Activity Name (Field API Key)</th>';
        $html .= '<th style="padding:3px 6px;width:140px;">Due Days (Working)</th>';
        $html .= '<th style="width:28px;"></th>';
        $html .= '</tr></thead>';
        $html .= '<tbody class="ba-assigned-acts-tbody">';

        foreach ($assigned_acts as $assigned) {
            $act_name = is_array($assigned) ? ($assigned['name'] ?? '') : (string)$assigned;
            $act_key = is_array($assigned) ? ($assigned['key'] ?? mentor_activity_service::slugify_key($act_name)) : mentor_activity_service::slugify_key($act_name);
            $duedays = is_array($assigned) && isset($assigned['duedays']) ? (int)$assigned['duedays'] : 5;

            $html .= '<tr class="ba-assigned-act-row" draggable="true" data-key="' . htmlspecialchars($act_key, ENT_QUOTES) . '" data-name="' . htmlspecialchars($act_name, ENT_QUOTES) . '" style="border-bottom:1px solid #f3f4f6;cursor:grab;">';
            $html .= '<td class="ba-assigned-act-drag" style="text-align:center;color:#bbb;font-size:14px;user-select:none;padding:2px 4px;cursor:grab;">&#8942;&#8942;</td>';
            $html .= '<td style="padding:4px 6px;">';
            $html .= '<span class="ba-assigned-act-name" style="font-weight:600;font-size:12px;color:#1f2937;">' . htmlspecialchars($act_name, ENT_QUOTES) . '</span>';
            $html .= '<span class="ba-assigned-act-key" style="font-family:monospace;font-size:11px;color:#6b7280;margin-left:4px;">(' . htmlspecialchars($act_key, ENT_QUOTES) . ')</span>';
            $html .= '</td>';
            $html .= '<td style="padding:4px 6px;">';
            $html .= '<div style="display:inline-flex;align-items:center;gap:4px;">';
            $html .= '<input type="number" min="0" class="ba-act-duedays form-control form-control-sm" value="' . $duedays . '" style="width:65px;padding:2px 5px;font-size:12px;">';
            $html .= '<span style="font-size:11px;color:#6b7280;">days</span>';
            $html .= '</div>';
            $html .= '</td>';
            $html .= '<td style="padding:4px 6px;text-align:center;">';
            $html .= '<button type="button" class="btn btn-sm btn-outline-danger" onclick="baRemoveActivityFromGroup(this);" style="padding:0 5px;line-height:1.2;font-size:12px;" title="Remove">&times;</button>';
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';
        $html .= '</td>';

        $html .= '<td style="text-align:center;padding:6px 8px;vertical-align:top">';
        $html .= '<button type="button" onclick="this.closest(\'tr.ba-group-row\').remove(); baTriggerGroupSerialize();" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button>';
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

  window.baTriggerGroupSerialize = function() {
    serialize();
  };

  function serialize() {
    var result = [];
    tbody.querySelectorAll('tr.ba-group-row').forEach(function (row) {
      var nameInput = row.querySelector('.ba-mentor-group-name');
      if (!nameInput) return;

      var groupName = nameInput.value.trim();
      if (!groupName) return;

      var assignedActs = [];
      row.querySelectorAll('.ba-assigned-act-row').forEach(function (actRow) {
        var key = actRow.getAttribute('data-key');
        var name = actRow.getAttribute('data-name');
        var daysInput = actRow.querySelector('.ba-act-duedays');
        var days = daysInput ? parseInt(daysInput.value, 10) : 5;
        if (isNaN(days) || days < 0) days = 0;

        if (key && name) {
          assignedActs.push({
            key: key,
            name: name,
            duedays: days
          });
        }
      });

      result.push({
        group: groupName,
        activities: assignedActs
      });
    });
    hidden.value = JSON.stringify(result);
  }

  tbody.addEventListener('change', serialize);
  tbody.addEventListener('input', serialize);
  var form = tbody.closest('form');
  if (form) form.addEventListener('submit', serialize);

  // Refresh single group picker to show only unassigned master activities
  window.baRefreshGroupPicker = function(groupRow) {
    if (!groupRow) return;
    var picker = groupRow.querySelector('.ba-group-act-picker');
    var addBtn = groupRow.querySelector('.ba-btn-add-activity');
    if (!picker) return;

    var assignedKeys = {};
    groupRow.querySelectorAll('.ba-assigned-act-row').forEach(function(r) {
      var k = r.getAttribute('data-key');
      if (k) assignedKeys[k] = true;
    });

    var curVal = picker.value;
    picker.innerHTML = '';
    var hasAvailable = false;

    currentMasterPool.forEach(function(act) {
      if (!assignedKeys[act.key]) {
        hasAvailable = true;
        var opt = document.createElement('option');
        opt.value = act.key;
        opt.setAttribute('data-name', act.name);
        opt.textContent = act.name + ' (' + act.key + ')';
        if (act.key === curVal) opt.selected = true;
        picker.appendChild(opt);
      }
    });

    if (!hasAvailable) {
      var emptyOpt = document.createElement('option');
      emptyOpt.value = '';
      emptyOpt.disabled = true;
      emptyOpt.selected = true;
      emptyOpt.textContent = '(All activities assigned)';
      picker.appendChild(emptyOpt);
      if (addBtn) addBtn.disabled = true;
    } else {
      if (addBtn) addBtn.disabled = false;
    }
  };

  function refreshAllGroupPickers() {
    tbody.querySelectorAll('tr.ba-group-row').forEach(function(row) {
      window.baRefreshGroupPicker(row);
    });
  }

  // Hook for when master activities pool is replaced / re-serialized
  window.baOnMentorActivitiesChanged = function (newActivitiesList) {
    if (!Array.isArray(newActivitiesList)) return;
    currentMasterPool = newActivitiesList;
    refreshAllGroupPickers();
  };

  // Hook for when a master activity is edited / renamed in real time
  window.baOnMasterActivityUpdated = function(oldKey, newKey, newName) {
    if (!oldKey) return;

    // Update in currentMasterPool array
    currentMasterPool.forEach(function(act) {
      if (act.key === oldKey) {
        if (newKey) act.key = newKey;
        if (newName) act.name = newName;
      }
    });

    // Update all assigned activities across all groups referencing oldKey
    tbody.querySelectorAll('.ba-assigned-act-row[data-key="' + oldKey.replace(/"/g, '\\"') + '"]').forEach(function(actRow) {
      if (newKey) actRow.setAttribute('data-key', newKey);
      if (newName) actRow.setAttribute('data-name', newName);

      var nameSpan = actRow.querySelector('.ba-assigned-act-name');
      var keySpan = actRow.querySelector('.ba-assigned-act-key');
      if (nameSpan && newName) nameSpan.textContent = newName;
      if (keySpan && newKey) keySpan.textContent = '(' + newKey + ')';
    });

    refreshAllGroupPickers();
    serialize();
  };

  // Hook for when a master activity is deleted
  window.baOnMasterActivityRemoved = function(removedKey) {
    if (!removedKey) return;

    // Remove from currentMasterPool
    currentMasterPool = currentMasterPool.filter(function(act) {
      return act.key !== removedKey;
    });

    // Remove from all assigned activities across groups
    tbody.querySelectorAll('.ba-assigned-act-row[data-key="' + removedKey.replace(/"/g, '\\"') + '"]').forEach(function(actRow) {
      var groupRow = actRow.closest('tr.ba-group-row');
      actRow.remove();
      if (groupRow) window.baRefreshGroupPicker(groupRow);
    });

    refreshAllGroupPickers();
    serialize();
  };

  // ---- HTML5 drag-and-drop reorder for GROUPS ----
  var draggingGroup = null;

  tbody.addEventListener('dragstart', function (e) {
    // If dragging an inner assigned activity or inside table wrap, do NOT drag the group
    if (e.target.closest('.ba-assigned-acts-wrap') || e.target.closest('.ba-assigned-act-row')) {
      return;
    }
    draggingGroup = e.target.closest('tr.ba-group-row');
    if (draggingGroup) {
      draggingGroup.style.opacity = '0.4';
      e.dataTransfer.effectAllowed = 'move';
    }
  });

  tbody.addEventListener('dragend', function (e) {
    if (draggingGroup) draggingGroup.style.opacity = '';
    draggingGroup = null;
    serialize();
  });

  tbody.addEventListener('dragover', function (e) {
    if (!draggingGroup) return;
    e.preventDefault();
    var target = e.target.closest('tr.ba-group-row');
    if (target && target !== draggingGroup && target.parentNode === tbody) {
      var rect = target.getBoundingClientRect();
      var after = e.clientY > rect.top + rect.height / 2;
      tbody.insertBefore(draggingGroup, after ? target.nextSibling : target);
    }
  });

  // ---- HTML5 drag-and-drop reorder for ASSIGNED ACTIVITIES inside a group ----
  var draggingAct = null;

  tbody.addEventListener('dragstart', function (e) {
    var actRow = e.target.closest('.ba-assigned-act-row');
    if (actRow) {
      draggingAct = actRow;
      draggingAct.style.opacity = '0.4';
      e.dataTransfer.effectAllowed = 'move';
      e.stopPropagation();
    }
  });

  tbody.addEventListener('dragend', function (e) {
    if (draggingAct) {
      draggingAct.style.opacity = '';
      draggingAct = null;
      serialize();
      e.stopPropagation();
    }
  });

  tbody.addEventListener('dragover', function (e) {
    if (!draggingAct) return;
    var targetAct = e.target.closest('.ba-assigned-act-row');
    if (targetAct && targetAct !== draggingAct && targetAct.parentNode === draggingAct.parentNode) {
      e.preventDefault();
      e.stopPropagation();
      var rect = targetAct.getBoundingClientRect();
      var after = e.clientY > rect.top + rect.height / 2;
      targetAct.parentNode.insertBefore(draggingAct, after ? targetAct.nextSibling : targetAct);
    }
  });

  window.baGetMasterActivitiesPool = function() {
    return currentMasterPool;
  };
})();

function baAddActivityToGroup(btn) {
  var bar = btn.closest('.ba-act-add-bar');
  var groupRow = btn.closest('tr.ba-group-row');
  if (!bar || !groupRow) return;

  var picker = bar.querySelector('.ba-group-act-picker');
  var daysPicker = bar.querySelector('.ba-group-act-days-picker');
  var actTbody = groupRow.querySelector('.ba-assigned-acts-tbody');

  if (!picker || !picker.selectedOptions || picker.selectedOptions.length === 0 || !actTbody) return;

  var selOpt = picker.selectedOptions[0];
  var key = selOpt.value;
  if (!key) return; // disabled "(All activities assigned)" placeholder

  var name = selOpt.getAttribute('data-name') || key;
  var days = daysPicker ? parseInt(daysPicker.value, 10) : 5;
  if (isNaN(days) || days < 0) days = 5;

  // Check if activity already exists in this group
  var existingRow = actTbody.querySelector('.ba-assigned-act-row[data-key="' + key.replace(/"/g, '\\"') + '"]');
  if (existingRow) {
    var dInput = existingRow.querySelector('.ba-act-duedays');
    if (dInput) dInput.value = days;
    existingRow.style.background = '#fef3c7';
    setTimeout(function() { existingRow.style.background = ''; }, 1000);
    baTriggerGroupSerialize();
    return;
  }

  var tr = document.createElement('tr');
  tr.className = 'ba-assigned-act-row';
  tr.setAttribute('draggable', 'true');
  tr.setAttribute('data-key', key);
  tr.setAttribute('data-name', name);
  tr.style.cssText = 'border-bottom:1px solid #f3f4f6;cursor:grab;';
  tr.innerHTML =
    '<td class="ba-assigned-act-drag" style="text-align:center;color:#bbb;font-size:14px;user-select:none;padding:2px 4px;cursor:grab;">&#8942;&#8942;</td>'
    + '<td style="padding:4px 6px;">'
    + '<span class="ba-assigned-act-name" style="font-weight:600;font-size:12px;color:#1f2937;">' + name.replace(/</g, '&lt;') + '</span>'
    + '<span class="ba-assigned-act-key" style="font-family:monospace;font-size:11px;color:#6b7280;margin-left:4px;">(' + key.replace(/</g, '&lt;') + ')</span>'
    + '</td>'
    + '<td style="padding:4px 6px;">'
    + '<div style="display:inline-flex;align-items:center;gap:4px;">'
    + '<input type="number" min="0" class="ba-act-duedays form-control form-control-sm" value="' + days + '" style="width:65px;padding:2px 5px;font-size:12px;">'
    + '<span style="font-size:11px;color:#6b7280;">days</span>'
    + '</div>'
    + '</td>'
    + '<td style="padding:4px 6px;text-align:center;">'
    + '<button type="button" class="btn btn-sm btn-outline-danger" onclick="baRemoveActivityFromGroup(this);" style="padding:0 5px;line-height:1.2;font-size:12px;" title="Remove">&times;</button>'
    + '</td>';

  actTbody.appendChild(tr);

  // Refresh picker for this group so the added activity is excluded
  if (typeof window.baRefreshGroupPicker === 'function') {
    window.baRefreshGroupPicker(groupRow);
  }

  baTriggerGroupSerialize();
}

function baRemoveActivityFromGroup(btn) {
  var row = btn.closest('.ba-assigned-act-row');
  var groupRow = btn.closest('tr.ba-group-row');
  if (row) {
    row.remove();
  }
  if (groupRow && typeof window.baRefreshGroupPicker === 'function') {
    window.baRefreshGroupPicker(groupRow);
  }
  baTriggerGroupSerialize();
}

function baMentorGroupingAddRow(id) {
  var tbody = document.getElementById(id + '_tbody');
  var masterPool = typeof window.baGetMasterActivitiesPool === 'function'
    ? window.baGetMasterActivitiesPool()
    : [];

  var optHtml = '';
  masterPool.forEach(function (act) {
    optHtml += '<option value="' + act.key.replace(/"/g, '&quot;') + '" data-name="' + act.name.replace(/"/g, '&quot;') + '">'
      + act.name.replace(/</g, '&lt;') + ' (' + act.key.replace(/</g, '&lt;') + ')'
      + '</option>';
  });
  if (!optHtml) {
    optHtml = '<option value="" disabled selected>(No activities in master pool)</option>';
  }

  var tr = document.createElement('tr');
  tr.className = 'ba-group-row';
  tr.setAttribute('draggable', 'true');
  tr.style.cssText = 'border-bottom:1px solid #e0e0e0;cursor:grab';
  tr.innerHTML =
    '<td class="ba-group-drag-handle" style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px;cursor:grab;">&#8942;&#8942;</td>'
    + '<td style="padding:6px 8px;vertical-align:top">'
    + '<input type="text" class="ba-mentor-group-name form-control form-control-sm" placeholder="e.g. Python Fullstack" style="font-size:0.88em;font-weight:600;">'
    + '<div style="font-size:11px;color:#6b7280;margin-top:4px;">Matches course name keywords or acts as a dropdown option.</div>'
    + '</td>'
    + '<td style="padding:6px 8px;vertical-align:top">'
    + '<div class="ba-act-add-bar" style="display:flex;align-items:center;gap:6px;margin-bottom:8px;flex-wrap:wrap;">'
    + '<select class="ba-group-act-picker form-control form-control-sm" style="width:auto;min-width:240px;font-size:0.85em;">'
    + optHtml
    + '</select>'
    + '<div style="display:inline-flex;align-items:center;gap:4px;">'
    + '<span style="font-size:12px;color:#4b5563;">Due in:</span>'
    + '<input type="number" min="0" class="ba-group-act-days-picker form-control form-control-sm" value="5" style="width:65px;padding:2px 5px;font-size:12px;">'
    + '<span style="font-size:12px;color:#4b5563;">working days</span>'
    + '</div>'
    + '<button type="button" class="btn btn-secondary btn-sm ba-btn-add-activity" onclick="baAddActivityToGroup(this);" style="padding:2px 8px;font-size:12px;">+ Add Activity</button>'
    + '</div>'
    + '<div class="ba-assigned-acts-wrap" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:4px 6px;">'
    + '<table class="ba-assigned-acts-table" style="width:100%;border-collapse:collapse;">'
    + '<thead><tr style="font-size:11px;color:#6b7280;border-bottom:1px solid #e5e7eb;text-align:left;">'
    + '<th style="width:24px;"></th>'
    + '<th style="padding:3px 6px;">Activity Name (Field API Key)</th>'
    + '<th style="padding:3px 6px;width:140px;">Due Days (Working)</th>'
    + '<th style="width:28px;"></th>'
    + '</tr></thead>'
    + '<tbody class="ba-assigned-acts-tbody"></tbody>'
    + '</table>'
    + '</div>'
    + '</td>'
    + '<td style="text-align:center;padding:6px 8px;vertical-align:top">'
    + '<button type="button" onclick="this.closest(\'tr.ba-group-row\').remove(); baTriggerGroupSerialize();" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button>'
    + '</td>';

  tbody.appendChild(tr);
  tr.querySelector('.ba-mentor-group-name').focus();
  baTriggerGroupSerialize();
}
</script>
ENDJS;
    }
}
