<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Custom admin setting for MAAC page editable columns.
 */
class admin_setting_maac_columns extends \admin_setting {
    /** @var int Max persisted field key length, leaving room for numeric suffixes. */
    private const MAX_FIELDKEY_LENGTH = 90;

    public function __construct($name, $visiblename, $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    public function get_setting() {
        return $this->config_read($this->name);
    }

    public function get_defaultsetting() {
        return json_encode(maac_columns_helper::get_default_settings_rows());
    }

    public function write_setting($data) {
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return get_string('invalidjson', 'error');
        }

        $clean = [];
        $usedkeys = [];
        $validtypes = ['number', 'dropdown', 'boolean', 'multi_feedback', 'formula'];

        foreach ($decoded as $row) {
            $issystem = maac_columns_helper::is_builtin_key((string)($row['key'] ?? ''));
            $label = strip_tags(trim($row['label'] ?? ''));
            if ($label === '' && !$issystem) {
                continue;
            }

            $type = in_array($row['type'] ?? '', $validtypes, true) ? $row['type'] : 'multi_feedback';
            $rawkey = trim($row['key'] ?? '');
            $key = $rawkey !== '' ? $rawkey : $this->build_key_from_label($label);
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower($key));
            if ($key === '') {
                $key = $this->build_key_from_label($label);
            }
            $key = $this->truncate_key($key);

            $basekey = $key;
            $suffix = 2;
            while (in_array($key, $usedkeys, true)) {
                $key = $this->truncate_key($basekey, '_' . $suffix) . '_' . $suffix;
                $suffix++;
            }

            $usedkeys[] = $key;
            $min = $this->normalise_number_bound($row['min'] ?? null, $type);
            $max = $this->normalise_number_bound($row['max'] ?? null, $type);
            if ($min !== null && $max !== null && $min > $max) {
                [$min, $max] = [$max, $min];
            }

            $record = [
                'key' => $key,
                'label' => $issystem && $label === '' ? 'MAAC Rating' : $label,
                'type' => $type,
                'formula' => $type === 'formula' ? trim((string)($row['formula'] ?? '')) : '',
                'options' => $this->normalise_options($row['options'] ?? [], $type),
                'selection' => $this->normalise_selection($row['selection'] ?? '', $type),
                'min' => $min,
                'max' => $max,
                'showinmaac' => true,
                'showinadvanced' => true,
                'maacsync' => in_array($type, ['number', 'formula'], true) && !empty($row['maacsync']),
                'crmfield' => in_array($type, ['number', 'formula'], true)
                    ? preg_replace('/[^A-Za-z0-9_]/', '', trim((string)($row['crmfield'] ?? '')))
                    : '',
                'excludefrommaac' => !$issystem && $type === 'number' && !empty($row['excludefrommaac']),
            ];

            if ($issystem) {
                $record['system'] = true;
                $record['locked'] = true;
            }

            $clean[] = $record;
        }

        if (empty($clean)) {
            $clean = maac_columns_helper::get_default_settings_rows();
        } else {
            $clean = maac_columns_helper::normalise_settings_rows($clean);
        }

        $this->config_write($this->name, json_encode($clean));
        return '';
    }

    public function output_html($data, $query = '') {
        $id = $this->get_id();
        $name = $this->get_full_name();
        $columns = json_decode((string)$data, true);
        if (!is_array($columns) || empty($columns)) {
            $columns = maac_columns_helper::get_settings_rows();
        } else {
            $columns = maac_columns_helper::normalise_settings_rows($columns);
        }

        $form = '<input type="hidden" id="' . $id . '" name="' . $name . '" ';
        $form .= 'value="' . htmlspecialchars(json_encode($columns), ENT_QUOTES) . '">';

        $form .= '<table id="' . $id . '_table" style="width:100%;border-collapse:collapse;margin-bottom:6px">';
        $form .= '<thead>';
        $form .= '<tr style="background:#f5f5f5;font-size:0.85em">';
        $form .= '<th style="width:28px"></th>';
        $form .= '<th style="text-align:left;padding:4px 6px">Column Name</th>';
        $form .= '<th style="width:130px;text-align:center;padding:4px 6px">Type</th>';
        $form .= '<th style="width:90px;text-align:center;padding:4px 6px">MAAC Sync</th>';
        $form .= '<th style="width:150px;text-align:left;padding:4px 6px">CRM Field API Name</th>';
        $form .= '<th style="width:160px;text-align:center;padding:4px 6px">Number Range</th>';
        $form .= '<th style="width:120px;text-align:center;padding:4px 6px">Select Mode</th>';
        $form .= '<th style="text-align:left;padding:4px 6px">Dropdown Options / Formula</th>';
        $form .= '<th style="width:36px"></th>';
        $form .= '</tr>';
        $form .= '</thead>';
        $form .= '<tbody id="' . $id . '_tbody">';

        foreach ($columns as $column) {
            $form .= $this->render_row($column, $columns);
        }

        $form .= '</tbody>';
        $form .= '</table>';
        $variables = array_map(static fn(array $column): string => '{' . $column['key'] . '}', $columns);
        $form .= '<button type="button" onclick="baMaacAddRow(\'' . $id . '\')" class="btn btn-secondary btn-sm">+ Add Column</button>';
        $form .= '<div style="margin-top:8px;font-size:12px;color:#64748b"><strong>Formula variables:</strong> ' . htmlspecialchars(implode(', ', $variables), ENT_QUOTES) . '</div>';
        $form .= $this->inline_js($id);

        return format_admin_setting($this, $this->visiblename, $form, $this->description, false, '', null, $query);
    }

    /**
     * @param string $label
     * @return string
     */
    private function build_key_from_label(string $label): string {
        $key = strtolower($label);
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        return $this->truncate_key(trim($key, '_'));
    }

    /**
     * @param string $key
     * @param string $suffix
     * @return string
     */
    private function truncate_key(string $key, string $suffix = ''): string {
        $maxlength = self::MAX_FIELDKEY_LENGTH - strlen($suffix);
        if ($maxlength < 1) {
            $maxlength = 1;
        }

        return substr($key, 0, $maxlength);
    }

    /**
     * @param array $column
     * @return string
     */
    private function render_row(array $column, array $availablecolumns): string {
        $key = htmlspecialchars($column['key'] ?? '', ENT_QUOTES);
        $label = htmlspecialchars($column['label'] ?? '', ENT_QUOTES);
        $type = htmlspecialchars($column['type'] ?? 'multi_feedback', ENT_QUOTES);
        $selection = htmlspecialchars($column['selection'] ?? 'single', ENT_QUOTES);
        $options = htmlspecialchars(implode(', ', $column['options'] ?? []), ENT_QUOTES);
        if ($type === 'formula') {
            $options = htmlspecialchars((string)($column['formula'] ?? ''), ENT_QUOTES);
        }
        $min = htmlspecialchars((string)($column['min'] ?? ''), ENT_QUOTES);
        $max = htmlspecialchars((string)($column['max'] ?? ''), ENT_QUOTES);
        $formula = htmlspecialchars((string)($column['formula'] ?? ''), ENT_QUOTES);
        $maacsync = !empty($column['maacsync']) ? ' checked' : '';
        $crmfield = htmlspecialchars((string)($column['crmfield'] ?? ''), ENT_QUOTES);
        $excludefrommaac = !empty($column['excludefrommaac']) ? ' checked' : '';
        $issystem = !empty($column['system']);
        $islocked = !empty($column['locked']) || $issystem;
        $disabled = $islocked ? ' disabled' : '';
        $readonly = $islocked ? ' readonly' : '';
        $syncsupported = in_array($type, ['number', 'formula'], true);
        $syncdisabled = $syncsupported ? '' : ' disabled';
        $systeminput = $issystem ? '<input type="hidden" class="ba-maac-system" value="1">' : '';
        $lockedbadge = $issystem ? '<div style="margin-top:4px;font-size:11px;color:#64748b">Default metric</div>' : '';

        $dragstyle = $islocked ? 'color:#cbd5e1' : 'color:#bbb';
        $rowcursor = $islocked ? 'default' : 'grab';
        $html = '<tr draggable="' . ($islocked ? 'false' : 'true') . '" style="border-bottom:1px solid #e0e0e0;cursor:' . $rowcursor . '">';
        $html .= '<td style="text-align:center;' . $dragstyle . ';font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>';
        $html .= '<td style="padding:3px 4px">';
        $html .= '<input type="hidden" class="ba-maac-key" value="' . $key . '">';
        $html .= $systeminput;
        $html .= '<input type="text" class="ba-maac-label form-control form-control-sm" ';
        $html .= 'value="' . $label . '" placeholder="e.g. Mentor Remarks" style="font-size:0.85em"' . $readonly . '>';
        $html .= $lockedbadge;
        $html .= '</td>';
        $variables = [];
        foreach ($availablecolumns as $availablecolumn) {
            if (empty($availablecolumn['system']) && ($availablecolumn['key'] ?? '') !== ($column['key'] ?? '')) {
                $variables[] = '{' . htmlspecialchars($availablecolumn['key'], ENT_QUOTES) . '}';
            }
        }
        $html .= '<td style="text-align:center;padding:3px 4px">';
        $html .= '<select class="ba-maac-type form-control form-control-sm" style="font-size:0.8em;padding:1px 2px"' . $disabled . '>';
        foreach ($this->get_type_options() as $option => $display) {
            $selected = $type === $option ? ' selected' : '';
            $html .= '<option value="' . $option . '"' . $selected . '>' . $display . '</option>';
        }
        $html .= '</select>';
        $html .= '</td>';
        $html .= '<td style="text-align:center;padding:3px 4px"><input type="checkbox" class="ba-maac-sync"' . $maacsync . $syncdisabled . '></td>';
        $html .= '<td style="padding:3px 4px"><input type="text" class="ba-maac-crm-field form-control form-control-sm" value="' . $crmfield . '" placeholder="e.g. MAAC_Rating" style="font-size:0.82em"' . $syncdisabled . '></td>';
        $html .= '<td style="padding:3px 4px">';
        $html .= '<input type="checkbox" class="ba-maac-exclude-maac" style="display:none"' . $excludefrommaac . '>';
        $html .= '<div style="display:flex;gap:6px;align-items:center">';
        $html .= '<input type="number" class="ba-maac-min form-control form-control-sm" value="' . $min . '" placeholder="Min" style="font-size:0.8em"' . $disabled . '>';
        $html .= '<span style="color:#94a3b8">to</span>';
        $html .= '<input type="number" class="ba-maac-max form-control form-control-sm" value="' . $max . '" placeholder="Max" style="font-size:0.8em"' . $disabled . '>';
        $html .= '</div>';
        $html .= '</td>';
        $html .= '<td style="text-align:center;padding:3px 4px">';
        $html .= '<select class="ba-maac-selection form-control form-control-sm" style="font-size:0.8em;padding:1px 2px"' . $disabled . '>';
        $selectionoptions = $type === 'multi_feedback' ? ['internal', 'external'] : ['single', 'multi'];
        foreach ($selectionoptions as $option) {
            $selected = $selection === $option ? ' selected' : '';
            $html .= '<option value="' . $option . '"' . $selected . '>' . $option . '</option>';
        }
        $html .= '</select>';
        $html .= '</td>';
        $html .= '<td style="padding:3px 4px">';
        if ($type === 'formula') {
            $html .= '<textarea class="ba-maac-options form-control form-control-sm" rows="2" placeholder="e.g. ({maac_rating} + {field_a}) / 2" style="font-size:0.82em;resize:vertical"' . $readonly . '>' . $options . '</textarea>';
        } else {
            $html .= '<input type="text" class="ba-maac-options form-control form-control-sm" ';
            $html .= 'value="' . $options . '" placeholder="Comma-separated dropdown options" style="font-size:0.82em"' . $readonly . '>';
        }
        $html .= '</td>';
        $html .= '<td style="text-align:center;padding:3px 4px">';
        if ($islocked) {
            $html .= '<span style="color:#94a3b8;font-size:12px">Locked</span>';
        } else {
            $html .= '<button type="button" class="btn btn-sm btn-danger ba-maac-remove" style="padding:1px 6px" title="Remove">&times;</button>';
        }
        $html .= '</td>';
        $html .= '</tr>';
        return $html;
    }

    /**
     * @return array
     */
    private function get_type_options(): array {
        return [
            'number' => 'Number',
            'dropdown' => 'Dropdown',
            'boolean' => 'Boolean',
            'multi_feedback' => 'Multi Feedback',
            'formula' => 'Formula',
        ];
    }

    /**
     * @param string $selection
     * @param string $type
     * @return string
     */
    private function normalise_selection(string $selection, string $type): string {
        $selection = strtolower(trim($selection));
        if ($type === 'multi_feedback') {
            return in_array($selection, ['internal', 'external'], true) ? $selection : 'internal';
        }
        if ($type === 'dropdown') {
            return in_array($selection, ['single', 'multi'], true) ? $selection : 'single';
        }
        return 'single';
    }

    /**
     * @param array|string $options
     * @param string $type
     * @return array
     */
    private function normalise_options($options, string $type): array {
        if ($type !== 'dropdown') {
            return [];
        }

        if (is_string($options)) {
            $options = explode(',', $options);
        }

        if (!is_array($options)) {
            return [];
        }

        $clean = [];
        foreach ($options as $option) {
            $value = strip_tags(trim((string)$option));
            if ($value !== '') {
                $clean[] = $value;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param mixed $value
     * @param string $type
     * @return float|null
     */
    private function normalise_number_bound($value, string $type): ?float {
        if ($type !== 'number' && $type !== 'formula') {
            return null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float)$value : null;
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

  function setSelectOptions(select, options, value) {
    var current = select.value;
    select.innerHTML = options.map(function (option) {
      return '<option value="' + option + '">' + option + '</option>';
    }).join('');
    select.value = options.indexOf(value) !== -1 ? value : (options.indexOf(current) !== -1 ? current : options[0]);
  }
  function syncRowState(row) {
    var type = row.querySelector('.ba-maac-type').value;
    var isSystem = !!row.querySelector('.ba-maac-system');
    var selection = row.querySelector('.ba-maac-selection');
    var options = row.querySelector('.ba-maac-options');
    var needsTextarea = type === 'formula';
    if (needsTextarea && options.tagName !== 'TEXTAREA') {
      var textarea = document.createElement('textarea');
      textarea.className = options.className;
      textarea.rows = 2;
      textarea.style.cssText = 'font-size:0.82em;resize:vertical';
      textarea.placeholder = 'e.g. ({maac_rating} + {field_a}) / 2';
      options.replaceWith(textarea);
      options = textarea;
    } else if (!needsTextarea && options.tagName === 'TEXTAREA') {
      var input = document.createElement('input');
      input.type = 'text';
      input.className = options.className;
      input.style.cssText = 'font-size:0.82em';
      input.placeholder = 'Comma-separated dropdown options';
      options.replaceWith(input);
      options = input;
    }
    var minField = row.querySelector('.ba-maac-min');
    var maxField = row.querySelector('.ba-maac-max');
    var excludeMaac = row.querySelector('.ba-maac-exclude-maac');
    var maacSync = row.querySelector('.ba-maac-sync');
    var crmField = row.querySelector('.ba-maac-crm-field');

    selection.disabled = isSystem || (type !== 'dropdown' && type !== 'multi_feedback');
    options.disabled = isSystem || (type !== 'dropdown' && type !== 'formula');
    minField.disabled = isSystem || (type !== 'number' && type !== 'formula');
    maxField.disabled = isSystem || (type !== 'number' && type !== 'formula');
    if (excludeMaac) {
      excludeMaac.disabled = isSystem || type !== 'number';
    }
    var syncSupported = type === 'number' || type === 'formula';
    if (maacSync) maacSync.disabled = !syncSupported;
    if (crmField) crmField.disabled = !syncSupported || !maacSync.checked;

    if (type === 'dropdown') {
      setSelectOptions(selection, ['single', 'multi'], selection.value === 'multi' ? 'multi' : 'single');
    } else if (type === 'multi_feedback') {
      setSelectOptions(selection, ['internal', 'external'], selection.value === 'external' ? 'external' : 'internal');
      options.value = '';
    } else if (type === 'formula') {
      setSelectOptions(selection, ['single'], 'single');
      options.placeholder = 'e.g. ({maac_rating} + {field_a}) / 2';
    } else {
      setSelectOptions(selection, ['single'], 'single');
      options.value = '';
    }
    if (type !== 'number' && type !== 'formula') {
      minField.value = '';
      maxField.value = '';
      if (excludeMaac) {
        excludeMaac.checked = false;
      }
      if (maacSync) maacSync.checked = false;
      if (crmField) crmField.value = '';
    }
  }

  function serialize() {
    var rows = tbody.querySelectorAll('tr');
    var result = [];
    rows.forEach(function (row) {
      var label = row.querySelector('.ba-maac-label').value.trim();
      if (!label) return;
      result.push({
        key: row.querySelector('.ba-maac-key').value.trim(),
        system: !!row.querySelector('.ba-maac-system'),
        label: label,
        type: row.querySelector('.ba-maac-type').value,
        formula: row.querySelector('.ba-maac-type').value === 'formula' ? row.querySelector('.ba-maac-options').value.trim() : '',
        min: row.querySelector('.ba-maac-min').value.trim(),
        max: row.querySelector('.ba-maac-max').value.trim(),
        selection: row.querySelector('.ba-maac-selection').value,
        maacsync: row.querySelector('.ba-maac-sync').checked,
        crmfield: row.querySelector('.ba-maac-crm-field').value.trim(),
        excludefrommaac: row.querySelector('.ba-maac-exclude-maac').checked,
        options: row.querySelector('.ba-maac-options').value
          .split(',')
          .map(function (opt) { return opt.trim(); })
          .filter(function (opt) { return opt !== ''; })
      });
    });
    hidden.value = JSON.stringify(result);
  }

  tbody.querySelectorAll('tr').forEach(syncRowState);
  tbody.addEventListener('change', function (e) {
    var row = e.target.closest('tr');
    if (row) {
      syncRowState(row);
    }
    serialize();
  });
  tbody.addEventListener('input', serialize);
  tbody.addEventListener('click', function (e) {
    var removeButton = e.target.closest('.ba-maac-remove');
    if (!removeButton) return;
    var row = removeButton.closest('tr');
    if (row) {
      row.remove();
      serialize();
    }
  });

  var form = tbody.closest('form');
  if (form) form.addEventListener('submit', serialize);

  var dragging = null;
  tbody.addEventListener('dragstart', function (e) {
    dragging = e.target.closest('tr');
    if (dragging && dragging.getAttribute('draggable') === 'true') {
      dragging.style.opacity = '0.4';
      e.dataTransfer.effectAllowed = 'move';
    } else {
      dragging = null;
      e.preventDefault();
    }
  });

  tbody.addEventListener('dragend', function () {
    if (dragging) dragging.style.opacity = '';
    if (dragging) serialize();
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

function baMaacAddRow(id) {
  var tbody = document.getElementById(id + '_tbody');
  var tr = document.createElement('tr');
  tr.setAttribute('draggable', 'true');
  tr.style.cssText = 'border-bottom:1px solid #e0e0e0;cursor:grab';
  tr.innerHTML =
    '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>'
    + '<td style="padding:3px 4px"><input type="hidden" class="ba-maac-key" value=""><input type="text" class="ba-maac-label form-control form-control-sm" placeholder="e.g. Mentor Remarks" style="font-size:0.85em"></td>'
    + '<td style="text-align:center;padding:3px 4px"><select class="ba-maac-type form-control form-control-sm" style="font-size:0.8em;padding:1px 2px"><option value="number">Number</option><option value="dropdown">Dropdown</option><option value="boolean">Boolean</option><option value="multi_feedback">Multi Feedback</option><option value="formula">Formula</option></select></td>'
    + '<td style="text-align:center;padding:3px 4px"><input type="checkbox" class="ba-maac-sync"></td>'
    + '<td style="padding:3px 4px"><input type="text" class="ba-maac-crm-field form-control form-control-sm" placeholder="e.g. MAAC_Rating" style="font-size:0.82em" disabled></td>'
    + '<td style="padding:3px 4px"><input type="checkbox" class="ba-maac-exclude-maac" style="display:none"><div style="display:flex;gap:6px;align-items:center"><input type="number" class="ba-maac-min form-control form-control-sm" placeholder="Min" style="font-size:0.8em"><span style="color:#94a3b8">to</span><input type="number" class="ba-maac-max form-control form-control-sm" placeholder="Max" style="font-size:0.8em"></div></td>'
    + '<td style="text-align:center;padding:3px 4px"><select class="ba-maac-selection form-control form-control-sm" style="font-size:0.8em;padding:1px 2px"><option value="single">single</option><option value="multi">multi</option></select></td>'
    + '<td style="padding:3px 4px"><input type="text" class="ba-maac-options form-control form-control-sm" placeholder="Comma-separated dropdown options" style="font-size:0.82em"></td>'
    + '<td style="text-align:center;padding:3px 4px"><button type="button" class="btn btn-sm btn-danger ba-maac-remove" style="padding:1px 6px" title="Remove">&times;</button></td>';
  tbody.appendChild(tr);
  tr.querySelector('.ba-maac-label').focus();
  tbody.dispatchEvent(new Event('change', { bubbles: true }));
}
</script>
ENDJS;
    }
}
