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
 * Admin setting for managing the master pool of mentor activities with API names and drag-and-drop reordering.
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
        global $DB;

        $activities = $this->normalise_activities($data);
        if ($activities === null) {
            return get_string('invalidjson', 'error');
        }

        // Detect renamed and removed keys compared to current config
        $old_raw = $this->config_read($this->name);
        $old_activities = [];
        if ($old_raw !== false && trim((string)$old_raw) !== '') {
            $decoded = json_decode((string)$old_raw, true);
            if (is_array($decoded)) {
                $old_activities = $decoded;
            }
        }

        $this->config_write($this->name, json_encode($activities));

        // If old activities existed, check for renamed keys to migrate saved completions and group mappings
        if (!empty($old_activities)) {
            $old_keys = [];
            foreach ($old_activities as $oa) {
                if (!empty($oa['key'])) {
                    $old_keys[$oa['key']] = $oa['name'] ?? $oa['key'];
                }
            }

            $new_keys = [];
            foreach ($activities as $na) {
                if (!empty($na['key'])) {
                    $new_keys[$na['key']] = $na['name'] ?? $na['key'];
                }
            }

            // Sync with course grouping rules in config
            $grp_raw = util::get_config_val('mentor_activity_grouping');
            if ($grp_raw !== false && trim((string)$grp_raw) !== '') {
                $rules = json_decode((string)$grp_raw, true);
                if (is_array($rules)) {
                    $rules_modified = false;
                    foreach ($rules as &$rule) {
                        if (!isset($rule['activities']) || !is_array($rule['activities'])) {
                            continue;
                        }
                        $filtered_acts = [];
                        foreach ($rule['activities'] as $ract) {
                            $rkey = $ract['key'] ?? '';
                            if (isset($new_keys[$rkey])) {
                                // Update name if master name changed
                                $ract['name'] = $new_keys[$rkey];
                                $filtered_acts[] = $ract;
                            } else {
                                $rules_modified = true;
                            }
                        }
                        $rule['activities'] = $filtered_acts;
                    }
                    unset($rule);

                    if ($rules_modified) {
                        set_config('mentor_activity_grouping', json_encode($rules), 'local_batchanalytics');
                    }
                }
            }
        }

        return '';
    }

    /**
     * Parse raw string / json into a clean list of unique activities with key and name.
     *
     * @param mixed $data
     * @return array<int, array{key: string, name: string}>|null
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
        $seen = [];
        foreach ($data as $item) {
            $name = '';
            $key = '';
            if (is_string($item)) {
                $name = trim(strip_tags($item));
                $key = mentor_activity_service::slugify_key($name);
            } else if (is_array($item)) {
                $name = trim(strip_tags((string)($item['name'] ?? '')));
                $key = trim(strip_tags((string)($item['key'] ?? '')));
                if ($key === '' && $name !== '') {
                    $key = mentor_activity_service::slugify_key($name);
                }
            }

            if ($name !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $clean[] = [
                    'key'  => $key,
                    'name' => $name,
                ];
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
        $form .= '<table id="' . $id . '_table" style="width:100%;max-width:760px;border-collapse:collapse;margin-bottom:8px">';
        $form .= '<thead><tr style="background:#f5f5f5;font-size:0.85em">';
        $form .= '<th style="width:28px"></th>';
        $form .= '<th style="text-align:left;padding:6px 8px;width:38%">Stored Field API Name</th>';
        $form .= '<th style="text-align:left;padding:6px 8px">Activity Display Name</th>';
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

    private function render_row(array $act): string {
        $key = htmlspecialchars((string)($act['key'] ?? ''), ENT_QUOTES);
        $name = htmlspecialchars((string)($act['name'] ?? ''), ENT_QUOTES);

        $html = '<tr draggable="true" data-prev-key="' . $key . '" data-prev-name="' . $name . '" style="border-bottom:1px solid #e0e0e0;cursor:grab">';
        $html .= '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>';
        $html .= '<td style="padding:4px 6px"><input type="text" class="ba-mentor-master-act-key form-control form-control-sm" value="'
            . $key . '" placeholder="e.g. assignment_evaluation" style="font-family:monospace;font-size:0.85em"></td>';
        $html .= '<td style="padding:4px 6px"><input type="text" class="ba-mentor-master-act-name form-control form-control-sm" value="'
            . $name . '" placeholder="e.g. Assignment evaluation" style="font-size:0.88em"></td>';
        $html .= '<td style="text-align:center;padding:4px 6px"><button type="button" onclick="baMentorMasterRemoveRow(this);" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button></td>';
        $html .= '</tr>';
        return $html;
    }

    private function inline_js(string $id): string {
        return <<<ENDJS
<script>
(function () {
  var tbody = document.getElementById('{$id}_tbody');
  var hidden = document.getElementById('{$id}');

  function slugify(text) {
    return text.toString().toLowerCase().trim()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '');
  }

  function serialize() {
    var result = [];
    var seen = {};
    tbody.querySelectorAll('tr').forEach(function (row) {
      var keyInput = row.querySelector('.ba-mentor-master-act-key');
      var nameInput = row.querySelector('.ba-mentor-master-act-name');
      if (!nameInput) return;

      var name = nameInput.value.trim();
      var key = keyInput ? keyInput.value.trim() : '';
      if (!name) return;

      if (!key) {
        key = slugify(name);
        if (keyInput) keyInput.value = key;
      }

      if (!seen[key]) {
        seen[key] = true;
        result.push({ key: key, name: name });
      }
    });
    hidden.value = JSON.stringify(result);

    // Notify grouping rules setting to sync activities dropdown and available options
    if (typeof window.baOnMentorActivitiesChanged === 'function') {
      window.baOnMentorActivitiesChanged(result);
    }
  }

  tbody.addEventListener('change', function (e) {
    handleFieldUpdate(e.target);
    serialize();
  });

  tbody.addEventListener('input', function (e) {
    if (e.target.classList.contains('ba-mentor-master-act-name')) {
      var tr = e.target.closest('tr');
      var keyInput = tr ? tr.querySelector('.ba-mentor-master-act-key') : null;
      if (keyInput && (!keyInput.value || keyInput.dataset.auto === '1')) {
        keyInput.value = slugify(e.target.value);
        keyInput.dataset.auto = '1';
      }
    }
    handleFieldUpdate(e.target);
    serialize();
  });

  function handleFieldUpdate(el) {
    var tr = el ? el.closest('tr') : null;
    if (!tr) return;

    var keyInput = tr.querySelector('.ba-mentor-master-act-key');
    var nameInput = tr.querySelector('.ba-mentor-master-act-name');
    var newKey = keyInput ? keyInput.value.trim() : '';
    var newName = nameInput ? nameInput.value.trim() : '';
    var prevKey = tr.getAttribute('data-prev-key') || '';
    var prevName = tr.getAttribute('data-prev-name') || '';

    if (prevKey && (newKey !== prevKey || newName !== prevName)) {
      if (typeof window.baOnMasterActivityUpdated === 'function') {
        window.baOnMasterActivityUpdated(prevKey, newKey, newName);
      }
      tr.setAttribute('data-prev-key', newKey);
      tr.setAttribute('data-prev-name', newName);
    } else if (!prevKey && newKey) {
      tr.setAttribute('data-prev-key', newKey);
      tr.setAttribute('data-prev-name', newName);
    }
  }

  window.baMentorMasterRemoveRow = function (btn) {
    var tr = btn.closest('tr');
    if (!tr) return;

    var nameInput = tr.querySelector('.ba-mentor-master-act-name');
    var keyInput = tr.querySelector('.ba-mentor-master-act-key');
    var actName = nameInput ? nameInput.value.trim() : '';
    var actKey = keyInput ? keyInput.value.trim() : '';
    var displayName = actName || actKey || 'this activity';

    var confirmMsg = 'Are you sure you want to delete "' + displayName + '"?\\n\\nIt will also be removed from all course grouping rules.';
    if (!confirm(confirmMsg)) {
      return;
    }

    var keyToRemove = actKey || tr.getAttribute('data-prev-key') || '';
    tr.remove();

    if (keyToRemove && typeof window.baOnMasterActivityRemoved === 'function') {
      window.baOnMasterActivityRemoved(keyToRemove);
    }

    serialize();
  };

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

window.baMentorMasterAddRow = function (id) {
  var tbody = document.getElementById(id + '_tbody');
  var tr = document.createElement('tr');
  tr.setAttribute('draggable', 'true');
  tr.setAttribute('data-prev-key', '');
  tr.setAttribute('data-prev-name', '');
  tr.style.cssText = 'border-bottom:1px solid #e0e0e0;cursor:grab';
  tr.innerHTML =
    '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>'
    + '<td style="padding:4px 6px"><input type="text" class="ba-mentor-master-act-key form-control form-control-sm" placeholder="e.g. new_activity" style="font-family:monospace;font-size:0.85em"></td>'
    + '<td style="padding:4px 6px"><input type="text" class="ba-mentor-master-act-name form-control form-control-sm" placeholder="e.g. New Activity" style="font-size:0.88em"></td>'
    + '<td style="text-align:center;padding:4px 6px"><button type="button" onclick="baMentorMasterRemoveRow(this);" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button></td>';
  tbody.appendChild(tr);

  var nameInput = tr.querySelector('.ba-mentor-master-act-name');
  var keyInput = tr.querySelector('.ba-mentor-master-act-key');
  if (keyInput) keyInput.dataset.auto = '1';
  if (nameInput) nameInput.focus();

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
