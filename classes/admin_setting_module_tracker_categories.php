<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Admin setting for the configurable Module Tracker category tabs.
 */
class admin_setting_module_tracker_categories extends \admin_setting {

    public function __construct($name, $visiblename, $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    public function get_setting() {
        return $this->config_read($this->name);
    }

    public function get_defaultsetting() {
        return json_encode(activity_tracker_service::get_default_tracker_categories());
    }

    public function write_setting($data) {
        $categories = $this->normalise_categories(json_decode((string)$data, true));
        if ($categories === null) {
            return get_string('invalidjson', 'error');
        }

        $this->config_write($this->name, json_encode($categories));
        return '';
    }

    public function output_html($data, $query = '') {
        $id = $this->get_id();
        $name = $this->get_full_name();
        $categories = $this->normalise_categories(json_decode((string)$data, true));
        if ($categories === null || empty($categories)) {
            $categories = activity_tracker_service::get_legacy_tracker_categories();
        }

        $form = '<input type="hidden" id="' . $id . '" name="' . $name . '" value="'
            . htmlspecialchars(json_encode($categories), ENT_QUOTES) . '">';
        $form .= '<table id="' . $id . '_table" style="width:100%;border-collapse:collapse;margin-bottom:6px">';
        $form .= '<thead><tr style="background:#f5f5f5;font-size:0.85em">';
        $form .= '<th style="text-align:left;padding:4px 6px;width:34%">Category name</th>';
        $form .= '<th style="text-align:left;padding:4px 6px">Comma-separated Gradebook category names</th>';
        $form .= '<th style="width:36px"></th></tr></thead>';
        $form .= '<tbody id="' . $id . '_tbody">';
        foreach ($categories as $category) {
            $form .= $this->render_row($category);
        }
        $form .= '</tbody></table>';
        $form .= '<button type="button" onclick="baModuleTrackerAddCategory(\'' . $id
            . '\')" class="btn btn-secondary btn-sm">+ Add Category</button>';
        $form .= $this->inline_js($id);

        return format_admin_setting($this, $this->visiblename, $form, $this->description, false, '', null, $query);
    }

    /**
     * @param mixed $categories
     * @return array|null
     */
    private function normalise_categories($categories): ?array {
        if (!is_array($categories)) {
            return null;
        }

        $clean = [];
        $names = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $name = strip_tags(trim((string)($category['name'] ?? '')));
            $aliases = (string)($category['aliases'] ?? '');
            if ($name === '') {
                continue;
            }

            $namekey = \core_text::strtolower($name);
            if (isset($names[$namekey])) {
                continue;
            }
            $names[$namekey] = true;

            $cleanaliases = array_values(array_unique(array_filter(array_map(
                static function(string $alias): string {
                    return \core_text::strtolower(trim($alias));
                },
                explode(',', $aliases)
            ))));
            if (empty($cleanaliases)) {
                continue;
            }
            $clean[] = [
                'name' => $name,
                'aliases' => implode(', ', $cleanaliases),
            ];
        }

        return $clean;
    }

    /**
     * @param array $category
     * @return string
     */
    private function render_row(array $category): string {
        $name = htmlspecialchars((string)($category['name'] ?? ''), ENT_QUOTES);
        $aliases = htmlspecialchars((string)($category['aliases'] ?? ''), ENT_QUOTES);
        $html = '<tr style="border-bottom:1px solid #e0e0e0">';
        $html .= '<td style="padding:3px 4px"><input type="text" class="ba-module-tracker-category-name form-control form-control-sm" value="'
            . $name . '" placeholder="e.g. Assignments" style="font-size:0.85em"></td>';
        $html .= '<td style="padding:3px 4px"><input type="text" class="ba-module-tracker-category-aliases form-control form-control-sm" value="'
            . $aliases . '" placeholder="e.g. assignment, lab assignment" style="font-size:0.85em"></td>';
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
    var result = [];
    tbody.querySelectorAll('tr').forEach(function (row) {
      var name = row.querySelector('.ba-module-tracker-category-name').value.trim();
      var aliases = row.querySelector('.ba-module-tracker-category-aliases').value.trim();
      if (name && aliases) result.push({ name: name, aliases: aliases });
    });
    hidden.value = JSON.stringify(result);
  }

  tbody.addEventListener('change', serialize);
  tbody.addEventListener('input', serialize);
  var form = tbody.closest('form');
  if (form) form.addEventListener('submit', serialize);
})();

function baModuleTrackerAddCategory(id) {
  var tbody = document.getElementById(id + '_tbody');
  var tr = document.createElement('tr');
  tr.style.cssText = 'border-bottom:1px solid #e0e0e0';
  tr.innerHTML =
    '<td style="padding:3px 4px"><input type="text" class="ba-module-tracker-category-name form-control form-control-sm" placeholder="e.g. Assignments" style="font-size:0.85em"></td>'
    + '<td style="padding:3px 4px"><input type="text" class="ba-module-tracker-category-aliases form-control form-control-sm" placeholder="e.g. assignment, lab assignment" style="font-size:0.85em"></td>'
    + '<td style="text-align:center;padding:3px 4px"><button type="button" onclick="this.closest(\'tr\').remove()" class="btn btn-sm btn-danger" style="padding:1px 6px" title="Remove">&times;</button></td>';
  tbody.appendChild(tr);
  tr.querySelector('.ba-module-tracker-category-name').focus();
}
</script>
ENDJS;
    }
}
