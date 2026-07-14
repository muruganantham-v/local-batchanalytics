<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Admin setting for grouping MAAC custom columns.
 */
class admin_setting_maac_column_groups extends \admin_setting {

    public function __construct($name, $visiblename, $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    public function get_setting() {
        return $this->config_read($this->name);
    }

    public function get_defaultsetting() {
        return json_encode(maac_column_groups_helper::get_default_groups());
    }

    public function write_setting($data) {
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return get_string('invalidjson', 'error');
        }

        $validkeys = array_column(maac_columns_helper::get_settings_rows(), 'key');
        $validlookup = array_fill_keys($validkeys, true);
        $clean = [];

        foreach ($decoded as $row) {
            $name = strip_tags(trim($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $columns = [];
            foreach (($row['columns'] ?? []) as $columnkey) {
                if (isset($validlookup[$columnkey])) {
                    $columns[] = $columnkey;
                }
            }

            if (empty($columns)) {
                continue;
            }

            $clean[] = [
                'name' => $name,
                'columns' => array_values(array_unique($columns)),
                'showinmaac' => !empty($row['showinmaac']),
                'showinadvanced' => !empty($row['showinadvanced']),
            ];
        }

        $clean = maac_column_groups_helper::normalise_groups($clean);
        $this->config_write($this->name, json_encode($clean));
        return '';
    }

    public function output_html($data, $query = '') {
        $id = $this->get_id();
        $name = $this->get_full_name();
        $groups = json_decode((string)$data, true);
        if (!is_array($groups) || empty($groups)) {
            $groups = maac_column_groups_helper::get_default_groups();
        } else {
            $groups = maac_column_groups_helper::normalise_groups($groups);
        }

        $columns = maac_columns_helper::get_settings_rows();
        $optionshtml = '';
        foreach ($columns as $column) {
            $optionshtml .= '<option value="' . htmlspecialchars($column['key'], ENT_QUOTES) . '">'
                . htmlspecialchars($column['label'], ENT_QUOTES)
                . '</option>';
        }

        $form = '<input type="hidden" id="' . $id . '" name="' . $name . '" value="'
            . htmlspecialchars(json_encode($groups), ENT_QUOTES) . '">';

        $form .= '<table id="' . $id . '_table" style="width:100%;border-collapse:collapse;margin-bottom:6px">';
        $form .= '<thead><tr style="background:#f5f5f5;font-size:0.85em">';
        $form .= '<th style="width:28px"></th>';
        $form .= '<th style="text-align:left;padding:4px 6px">Group Name</th>';
        $form .= '<th style="width:110px;text-align:center;padding:4px 6px">Show in MAAC</th>';
        $form .= '<th style="width:130px;text-align:center;padding:4px 6px">Show in Advanced</th>';
        $form .= '<th style="text-align:left;padding:4px 6px">Columns</th>';
        $form .= '<th style="width:36px"></th>';
        $form .= '</tr></thead>';
        $form .= '<tbody id="' . $id . '_tbody">';
        foreach ($groups as $group) {
            $form .= $this->render_row($group, $optionshtml);
        }
        $form .= '</tbody></table>';
        $form .= '<button type="button" onclick="baMaacAddGroup(\'' . $id . '\')" class="btn btn-secondary btn-sm">+ Add Group</button>';
        $form .= '<template id="' . $id . '_options">' . $optionshtml . '</template>';
        $form .= $this->inline_js($id);

        return format_admin_setting($this, $this->visiblename, $form, $this->description, false, '', null, $query);
    }

    /**
     * @param array $group
     * @param string $optionshtml
     * @return string
     */
    private function render_row(array $group, string $optionshtml): string {
        $name = htmlspecialchars($group['name'] ?? '', ENT_QUOTES);
        $selected = array_fill_keys($group['columns'] ?? [], true);
        $showinmaac = !empty($group['showinmaac']) ? ' checked' : '';
        $showinadvanced = !empty($group['showinadvanced']) ? ' checked' : '';

        $options = '';
        foreach (maac_columns_helper::get_settings_rows() as $column) {
            $isselected = isset($selected[$column['key']]) ? ' selected' : '';
            $options .= '<option value="' . htmlspecialchars($column['key'], ENT_QUOTES) . '"' . $isselected . '>'
                . htmlspecialchars($column['label'], ENT_QUOTES) . '</option>';
        }

        $html = '<tr draggable="true" style="border-bottom:1px solid #e0e0e0;cursor:grab">';
        $html .= '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>';
        $html .= '<td style="padding:3px 4px"><input type="text" class="ba-maac-group-name form-control form-control-sm" value="'
            . $name . '" placeholder="e.g. Placement Tracking" style="font-size:0.85em"></td>';
        $html .= '<td style="text-align:center;padding:3px 4px"><input type="checkbox" class="ba-maac-group-show-maac"' . $showinmaac . '></td>';
        $html .= '<td style="text-align:center;padding:3px 4px"><input type="checkbox" class="ba-maac-group-show-advanced"' . $showinadvanced . '></td>';
        $html .= '<td style="padding:3px 4px"><select class="ba-maac-group-columns form-control form-control-sm" multiple size="4" style="min-height:88px;font-size:0.82em">'
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
      var name = row.querySelector('.ba-maac-group-name').value.trim();
      if (!name) return;
      var columns = Array.from(row.querySelector('.ba-maac-group-columns').selectedOptions).map(function (opt) {
        return opt.value;
      });
      if (columns.length === 0) return;
      result.push({
        name: name,
        showinmaac: row.querySelector('.ba-maac-group-show-maac').checked,
        showinadvanced: row.querySelector('.ba-maac-group-show-advanced').checked,
        columns: columns
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

function baMaacAddGroup(id) {
  var tbody = document.getElementById(id + '_tbody');
  var options = document.getElementById(id + '_options').innerHTML;
  var tr = document.createElement('tr');
  tr.setAttribute('draggable', 'true');
  tr.style.cssText = 'border-bottom:1px solid #e0e0e0;cursor:grab';
  tr.innerHTML =
    '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>'
    + '<td style="padding:3px 4px"><input type="text" class="ba-maac-group-name form-control form-control-sm" placeholder="e.g. Placement Tracking" style="font-size:0.85em"></td>'
    + '<td style="text-align:center;padding:3px 4px"><input type="checkbox" class="ba-maac-group-show-maac" checked></td>'
    + '<td style="text-align:center;padding:3px 4px"><input type="checkbox" class="ba-maac-group-show-advanced" checked></td>'
    + '<td style="padding:3px 4px"><select class="ba-maac-group-columns form-control form-control-sm" multiple size="4" style="min-height:88px;font-size:0.82em">' + options + '</select></td>'
    + '<td style="text-align:center;padding:3px 4px"><button type="button" onclick="this.closest(\'tr\').remove()" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button></td>';
  tbody.appendChild(tr);
  tr.querySelector('.ba-maac-group-name').focus();

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
