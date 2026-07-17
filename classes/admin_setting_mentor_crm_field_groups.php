<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Admin setting for grouping Mentor CRM fields.
 */
class admin_setting_mentor_crm_field_groups extends \admin_setting {

    public function __construct($name, $visiblename, $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    public function get_setting() {
        return $this->config_read($this->name);
    }

    public function get_defaultsetting() {
        return json_encode([]);
    }

    public function write_setting($data) {
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return get_string('invalidjson', 'error');
        }

        $validfieldkeys = array_column(crm_fields_helper::get_mentor_fields(), 'key');
        $validfieldlookup = array_fill_keys($validfieldkeys, true);
        $cleangroups = [];

        foreach ($decoded as $mentorgroup) {
            $groupname = strip_tags(trim($mentorgroup['name'] ?? ''));
            if ($groupname === '') {
                continue;
            }

            $selectedmentorfields = [];
            foreach (($mentorgroup['fields'] ?? $mentorgroup['columns'] ?? []) as $fieldkey) {
                if (isset($validfieldlookup[$fieldkey])) {
                    $selectedmentorfields[] = $fieldkey;
                }
            }

            if (empty($selectedmentorfields)) {
                continue;
            }

            $cleangroups[] = [
                'name' => $groupname,
                'fields' => array_values(array_unique($selectedmentorfields)),
            ];
        }

        $this->config_write($this->name, json_encode($cleangroups));
        return '';
    }

    public function output_html($data, $query = '') {
        $id = $this->get_id();
        $name = $this->get_full_name();
        $mentorgroups = crm_fields_helper::normalise_mentor_field_groups(json_decode((string)$data, true) ?: []);
        $mentorfields = crm_fields_helper::get_mentor_fields();

        $optionshtml = $this->render_options($mentorfields);
        $form = '<input type="hidden" id="' . $id . '" name="' . $name . '" value="'
            . htmlspecialchars(json_encode($mentorgroups), ENT_QUOTES) . '">';

        if ($optionshtml === '') {
            $form .= '<div class="alert alert-info">Configure Mentor CRM fields first, then save changes to select fields in groups.</div>';
        }

        $form .= '<table id="' . $id . '_table" style="width:100%;border-collapse:collapse;margin-bottom:6px">';
        $form .= '<thead><tr style="background:#f5f5f5;font-size:0.85em">';
        $form .= '<th style="width:28px"></th>';
        $form .= '<th style="text-align:left;padding:4px 6px">Group Name</th>';
        $form .= '<th style="text-align:left;padding:4px 6px">Fields</th>';
        $form .= '<th style="width:36px"></th>';
        $form .= '</tr></thead>';
        $form .= '<tbody id="' . $id . '_tbody">';
        foreach ($mentorgroups as $mentorgroup) {
            $form .= $this->render_row($mentorgroup, $mentorfields);
        }
        $form .= '</tbody></table>';
        $form .= '<button type="button" onclick="baMentorCrmAddGroup(\'' . $id . '\')" class="btn btn-secondary btn-sm">+ Add Group</button>';
        $form .= '<template id="' . $id . '_options">' . $optionshtml . '</template>';
        $form .= $this->inline_js($id);

        return format_admin_setting($this, $this->visiblename, $form, $this->description, false, '', null, $query);
    }

    /**
     * @param array $mentorfields
     * @param array $selectedfields
     * @return string
     */
    private function render_options(array $mentorfields, array $selectedfields = []): string {
        $selectedlookup = array_fill_keys($selectedfields, true);
        $options = '';
        foreach ($mentorfields as $mentorfield) {
            $key = trim((string)($mentorfield['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = trim((string)($mentorfield['label'] ?? $key));
            $isselected = isset($selectedlookup[$key]) ? ' selected' : '';
            $options .= '<option value="' . htmlspecialchars($key, ENT_QUOTES) . '"' . $isselected . '>'
                . htmlspecialchars($label, ENT_QUOTES)
                . '</option>';
        }
        return $options;
    }

    /**
     * @param array $mentorgroup
     * @param array $mentorfields
     * @return string
     */
    private function render_row(array $mentorgroup, array $mentorfields): string {
        $groupname = htmlspecialchars($mentorgroup['name'] ?? $mentorgroup['title'] ?? '', ENT_QUOTES);
        $selectedfields = $mentorgroup['fields'] ?? $mentorgroup['columns'] ?? [];
        $options = $this->render_options($mentorfields, $selectedfields);

        $html = '<tr draggable="true" style="border-bottom:1px solid #e0e0e0;cursor:grab">';
        $html .= '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>';
        $html .= '<td style="padding:3px 4px"><input type="text" class="ba-mentor-crm-group-name form-control form-control-sm" value="'
            . $groupname . '" placeholder="e.g. Module 1" style="font-size:0.85em"></td>';
        $html .= '<td style="padding:3px 4px"><select class="ba-mentor-crm-group-fields form-control form-control-sm" multiple size="4" style="min-height:88px;font-size:0.82em">'
            . $options . '</select></td>';
        $html .= '<td style="text-align:center;padding:3px 4px"><button type="button" onclick="this.closest(\'tr\').remove()" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button></td>';
        $html .= '</tr>';
        return $html;
    }

    /**
     * @param string $id
     * @return string
     */
    private function inline_js(string $id): string {
        return <<<ENDJS
<script>
(function () {
  var tbody = document.getElementById('{$id}_tbody');
  var hidden = document.getElementById('{$id}');

  function serialize() {
    var rows = tbody.querySelectorAll('tr');
    var result = [];
    rows.forEach(function (row) {
      var groupName = row.querySelector('.ba-mentor-crm-group-name').value.trim();
      if (!groupName) return;
      var selectedFields = Array.from(row.querySelector('.ba-mentor-crm-group-fields').selectedOptions).map(function (opt) {
        return opt.value;
      });
      if (selectedFields.length === 0) return;
      result.push({
        name: groupName,
        fields: selectedFields
      });
    });
    hidden.value = JSON.stringify(result);
  }

  tbody.addEventListener('change', serialize);
  tbody.addEventListener('input', serialize);

  var form = tbody.closest('form');
  if (form) form.addEventListener('submit', serialize);

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

function baMentorCrmAddGroup(id) {
  var tbody = document.getElementById(id + '_tbody');
  var options = document.getElementById(id + '_options').innerHTML;
  var tr = document.createElement('tr');
  tr.setAttribute('draggable', 'true');
  tr.style.cssText = 'border-bottom:1px solid #e0e0e0;cursor:grab';
  tr.innerHTML =
    '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>'
    + '<td style="padding:3px 4px"><input type="text" class="ba-mentor-crm-group-name form-control form-control-sm" placeholder="e.g. Module 1" style="font-size:0.85em"></td>'
    + '<td style="padding:3px 4px"><select class="ba-mentor-crm-group-fields form-control form-control-sm" multiple size="4" style="min-height:88px;font-size:0.82em">' + options + '</select></td>'
    + '<td style="text-align:center;padding:3px 4px"><button type="button" onclick="this.closest(\'tr\').remove()" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button></td>';
  tbody.appendChild(tr);
  tr.querySelector('.ba-mentor-crm-group-name').focus();

  tr.addEventListener('change', function () {
    tr.closest('tbody').dispatchEvent(new Event('change', { bubbles: true }));
  });
  tr.addEventListener('input', function () {
    tr.closest('tbody').dispatchEvent(new Event('input', { bubbles: true }));
  });
}
</script>
ENDJS;
    }
}