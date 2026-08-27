<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Custom admin setting: drag-and-drop CRM fields table.
 *
 * Renders a sortable table where each row is one CRM field with:
 *   - Field Key  (Zoho CRM / computed key)
 *   - Display Label
 *   - Numeric checkbox  (numeric sort + right-align)
 *   - Restricted checkbox  (hidden from View-only users)
 *   - Delete button
 * Plus an "Add Field" button at the bottom.
 *
 * The whole table serialises to a single JSON blob stored in the configured setting.
 */
class admin_setting_crm_fields extends \admin_setting {

    /** @var array|null */
    private $defaultfields;

    public function __construct($name, $visiblename, $description, ?array $defaultfields = null) {
        // Pass '' as default - get_defaultsetting() overrides this.
        parent::__construct($name, $visiblename, $description, '');
        $this->defaultfields = $defaultfields;
    }

    public function get_setting() {
        return $this->config_read($this->name);
    }

    public function get_defaultsetting() {
        return json_encode($this->defaultfields ?? crm_fields_helper::get_default_fields());
    }

    public function write_setting($data) {
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return get_string('invalidjson', 'error');
        }
        $valid_types = ['text', 'lookup', 'date'];
        $clean = [];
        foreach ($decoded as $row) {
            $key = preg_replace('/[^A-Za-z0-9_]/', '', $row['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $type = in_array($row['type'] ?? '', $valid_types) ? $row['type'] : 'text';
            $clean[] = [
                'key'        => $key,
                'label'      => strip_tags($row['label'] ?? $key),
                'numeric'    => !empty($row['numeric']),
                'restricted' => !empty($row['restricted']),
                'type'       => $type,
            ];
        }
        $this->config_write($this->name, json_encode($clean));
        return '';
    }

    public function output_html($data, $query = '') {
        $id   = $this->get_id();
        $name = $this->get_full_name();

        if ($data === '' || $data === null) {
            $fields = $this->defaultfields ?? crm_fields_helper::get_default_fields();
        } else {
            $fields = json_decode($data, true);
            if (!is_array($fields)) {
                $fields = $this->defaultfields ?? crm_fields_helper::get_default_fields();
            }
        }

        // Hidden input holds the serialised JSON submitted on save.
        $form  = '<input type="hidden" id="' . $id . '" name="' . $name . '" ';
        $form .= 'value="' . htmlspecialchars(json_encode($fields), ENT_QUOTES) . '">';

        $form .= '<table id="' . $id . '_table" style="width:100%;border-collapse:collapse;margin-bottom:6px">';
        $form .= '<thead>';
        $form .= '<tr style="background:#f5f5f5;font-size:0.85em">';
        $form .= '<th style="width:28px"></th>';
        $form .= '<th style="text-align:left;padding:4px 6px">Field Key <span style="font-weight:normal;color:#888">(Zoho CRM name)</span></th>';
        $form .= '<th style="text-align:left;padding:4px 6px">Display Label</th>';
        $form .= '<th style="width:80px;text-align:center;padding:4px 6px">Type</th>';
        $form .= '<th style="width:70px;text-align:center;padding:4px 6px">Numeric</th>';
        $form .= '<th style="width:80px;text-align:center;padding:4px 6px">Restricted</th>';
        $form .= '<th style="width:36px"></th>';
        $form .= '</tr>';
        $form .= '</thead>';
        $form .= '<tbody id="' . $id . '_tbody">';

        foreach ($fields as $field) {
            $form .= $this->render_row($field);
        }

        $form .= '</tbody>';
        $form .= '</table>';

        $form .= '<button type="button" onclick="baCrmAddRow(\'' . $id . '\')" ';
        $form .= 'class="btn btn-secondary btn-sm">+ Add Field</button>';

        $form .= $this->inline_js($id);

        return format_admin_setting($this, $this->visiblename, $form, $this->description, false, '', null, $query);
    }

    // ------------------------------------------------------------------ //

    private function render_row(array $f): string {
        $key   = htmlspecialchars($f['key']   ?? '', ENT_QUOTES);
        $label = htmlspecialchars($f['label'] ?? '', ENT_QUOTES);
        $num   = !empty($f['numeric'])    ? ' checked' : '';
        $res   = !empty($f['restricted']) ? ' checked' : '';

        $s  = '<tr draggable="true" style="border-bottom:1px solid #e0e0e0;cursor:grab">';
        $s .= '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>';
        $s .= '<td style="padding:3px 4px"><input type="text" class="ba-crm-key form-control form-control-sm" ';
        $s .= 'value="' . $key . '" placeholder="e.g. Class_X_Score" style="font-family:monospace;font-size:0.85em"></td>';
        $s .= '<td style="padding:3px 4px"><input type="text" class="ba-crm-label form-control form-control-sm" ';
        $s .= 'value="' . $label . '" placeholder="e.g. Class X" style="font-size:0.85em"></td>';

        $type = htmlspecialchars($f['type'] ?? 'text', ENT_QUOTES);
        $s .= '<td style="text-align:center;padding:3px 4px">';
        $s .= '<select class="ba-crm-type form-control form-control-sm" style="font-size:0.8em;padding:1px 2px">';
        foreach (['text', 'lookup', 'date'] as $opt) {
            $sel = $type === $opt ? ' selected' : '';
            $s .= '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
        }
        $s .= '</select></td>';

        $s .= '<td style="text-align:center;padding:3px 4px"><input type="checkbox" class="ba-crm-numeric"' . $num . '></td>';
        $s .= '<td style="text-align:center;padding:3px 4px"><input type="checkbox" class="ba-crm-restricted"' . $res . '></td>';
        $s .= '<td style="text-align:center;padding:3px 4px">';
        $s .= '<button type="button" onclick="this.closest(\'tr\').remove()" ';
        $s .= 'class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button>';
        $s .= '</td>';
        $s .= '</tr>';
        return $s;
    }

    private function inline_js(string $id): string {
        // Using heredoc — the $id variable is interpolated, all JS is literal.
        return <<<ENDJS
<script>
(function () {
  var tbody  = document.getElementById('{$id}_tbody');
  var hidden = document.getElementById('{$id}');

  function serialize() {
    var rows   = tbody.querySelectorAll('tr');
    var result = [];
    rows.forEach(function (row) {
      var key = row.querySelector('.ba-crm-key').value.trim();
      if (!key) return;
      result.push({
        key:        key,
        label:      row.querySelector('.ba-crm-label').value.trim() || key,
        type:       row.querySelector('.ba-crm-type').value,
        numeric:    row.querySelector('.ba-crm-numeric').checked,
        restricted: row.querySelector('.ba-crm-restricted').checked
      });
    });
    hidden.value = JSON.stringify(result);
  }

  // Keep hidden input in sync on every change / keystroke.
  tbody.addEventListener('change', serialize);
  tbody.addEventListener('input',  serialize);

  // Serialise before the settings form submits.
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
  });

  tbody.addEventListener('dragover', function (e) {
    e.preventDefault();
    if (!dragging) return;
    var target = e.target.closest('tr');
    if (target && target !== dragging && target.parentNode === tbody) {
      var rect  = target.getBoundingClientRect();
      var after = e.clientY > rect.top + rect.height / 2;
      tbody.insertBefore(dragging, after ? target.nextSibling : target);
    }
  });
})();

function baCrmAddRow(id) {
  var tbody = document.getElementById(id + '_tbody');
  var tr    = document.createElement('tr');
  tr.setAttribute('draggable', 'true');
  tr.style.cssText = 'border-bottom:1px solid #e0e0e0;cursor:grab';
  tr.innerHTML =
    '<td style="text-align:center;color:#bbb;font-size:16px;user-select:none;padding:2px 4px">&#8942;&#8942;</td>'
    + '<td style="padding:3px 4px"><input type="text" class="ba-crm-key form-control form-control-sm"'
    + ' placeholder="e.g. New_Field" style="font-family:monospace;font-size:0.85em"></td>'
    + '<td style="padding:3px 4px"><input type="text" class="ba-crm-label form-control form-control-sm"'
    + ' placeholder="e.g. New Field" style="font-size:0.85em"></td>'
    + '<td style="text-align:center;padding:3px 4px"><select class="ba-crm-type form-control form-control-sm" style="font-size:0.8em;padding:1px 2px"><option value="text">text</option><option value="lookup">lookup</option><option value="date">date</option></select></td>'
    + '<td style="text-align:center;padding:3px 4px"><input type="checkbox" class="ba-crm-numeric"></td>'
    + '<td style="text-align:center;padding:3px 4px"><input type="checkbox" class="ba-crm-restricted"></td>'
    + '<td style="text-align:center;padding:3px 4px">'
    + '<button type="button" onclick="this.closest(\'tr\').remove()"'
    + ' class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button>'
    + '</td>';
  tbody.appendChild(tr);
  tr.querySelector('.ba-crm-key').focus();

  // Wire up live-sync for the new row.
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
