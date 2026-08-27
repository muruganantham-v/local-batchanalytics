<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper for reading the CRM fields configuration.
 *
 * Stored as JSON in local_batchanalytics/crm_fields_config.
 * Each entry: { "key": "Field_Key", "label": "Display Label", "numeric": bool, "restricted": bool }
 *
 * "key"        — Zoho CRM field name (or a computed key like CALC_STATUS / placed_company).
 * "label"      — Column header shown in the UI.
 * "numeric"    — true → sort/align as number.
 * "restricted" — true → hidden from users with only the View capability.
 */
class crm_fields_helper {

    /** Computed keys that are never sent to the Zoho API. */
    private const COMPUTED_KEYS = ['CALC_STATUS', 'placed_company'];

    /**
     * Default field definitions — mirrors the previously hardcoded lists in
     * crmapi.php ($crm_fields) and simple.js (PTF_COLS_ALL).
     */
    public static function get_default_fields(): array {
        return [
            ['key' => 'Class_X_Score',                    'label' => 'Class X',                 'numeric' => true,  'restricted' => true,  'type' => 'text'],
            ['key' => 'Class_XII_Score',                   'label' => 'Class XII',               'numeric' => true,  'restricted' => true,  'type' => 'text'],
            ['key' => 'BE_BTech_Branch',                   'label' => 'BE Branch',               'numeric' => false, 'restricted' => true,  'type' => 'text'],
            ['key' => 'BE_BTech_Score',                    'label' => 'BE Score',                'numeric' => true,  'restricted' => true,  'type' => 'text'],
            ['key' => 'BE_BTech_YoP',                      'label' => 'BE YOP',                  'numeric' => false, 'restricted' => true,  'type' => 'text'],
            ['key' => 'College_Name',                      'label' => 'College Name',            'numeric' => false, 'restricted' => true,  'type' => 'text'],
            ['key' => 'ME_MTech_Score',                    'label' => 'ME Score',                'numeric' => true,  'restricted' => true,  'type' => 'text'],
            ['key' => 'ME_MTech_Branch',                   'label' => 'ME Branch',               'numeric' => false, 'restricted' => true,  'type' => 'text'],
            ['key' => 'ME_MTech_YoP',                      'label' => 'ME YOP',                  'numeric' => false, 'restricted' => true,  'type' => 'text'],
            ['key' => 'Home_State',                        'label' => 'Home State',              'numeric' => false, 'restricted' => true,  'type' => 'text'],
            ['key' => 'Total_Applied',                     'label' => 'Total Applied',           'numeric' => true,  'restricted' => true,  'type' => 'text'],
            ['key' => 'Last_Applied_Date',                 'label' => 'Last Applied Date',       'numeric' => false, 'restricted' => true,  'type' => 'date'],
            ['key' => 'Total_Shortlisted',                 'label' => 'Total Shortlisted',       'numeric' => true,  'restricted' => true,  'type' => 'text'],
            ['key' => 'Last_Shortlisted_Date',             'label' => 'Last Shortlisted Date',   'numeric' => false, 'restricted' => true,  'type' => 'date'],
            ['key' => 'Total_Technical_Interview_Cleared', 'label' => 'Tech Int Cleared',        'numeric' => true,  'restricted' => true,  'type' => 'text'],
            ['key' => 'Total_Written_Test_Cleared',        'label' => 'Written Tests Cleared',   'numeric' => true,  'restricted' => true,  'type' => 'text'],
            ['key' => 'Total_L1_Cleared',                  'label' => 'Total L1 Cleared',        'numeric' => true,  'restricted' => true,  'type' => 'text'],
            ['key' => 'Total_L2_Cleared',                  'label' => 'Total L2 Cleared',        'numeric' => true,  'restricted' => true,  'type' => 'text'],
            ['key' => 'Advanced_C_Score',                  'label' => 'Adv C Score',             'numeric' => true,  'restricted' => false, 'type' => 'text'],
            ['key' => 'Mentor_Name_C_Mock',                'label' => 'C Mentor',                'numeric' => false, 'restricted' => false, 'type' => 'lookup'],
            ['key' => 'C_Score',                           'label' => 'C++ Score',               'numeric' => true,  'restricted' => false, 'type' => 'text'],
            ['key' => 'Mentor_Name_C_Mock1',               'label' => 'C++ Mentor',              'numeric' => false, 'restricted' => false, 'type' => 'lookup'],
            ['key' => 'DS_Score',                          'label' => 'DS Score',                'numeric' => true,  'restricted' => false, 'type' => 'text'],
            ['key' => 'Mentor_Name_DS',                    'label' => 'DS Mentor',               'numeric' => false, 'restricted' => false, 'type' => 'lookup'],
            ['key' => 'Linux_Internals_Score',             'label' => 'Linux Score',             'numeric' => true,  'restricted' => false, 'type' => 'text'],
            ['key' => 'Mentor_Name_LI',                    'label' => 'Linux Mentor',            'numeric' => false, 'restricted' => false, 'type' => 'lookup'],
            ['key' => 'MC_Mock_Score3',                    'label' => 'MC Score',                'numeric' => true,  'restricted' => false, 'type' => 'text'],
            ['key' => 'Mentor_Name_MC_Mock',               'label' => 'MC Mentor',               'numeric' => false, 'restricted' => false, 'type' => 'lookup'],
            ['key' => 'Coach_Rating',                      'label' => 'Coach Rating',            'numeric' => true,  'restricted' => false, 'type' => 'text'],
            ['key' => 'MAAC_Rating',                       'label' => 'MAAC Rating',             'numeric' => true,  'restricted' => false, 'type' => 'text'],
            ['key' => 'Placement_Eli',                     'label' => 'PET Status',              'numeric' => false, 'restricted' => false, 'type' => 'text'],
            ['key' => 'CALC_STATUS',                       'label' => 'Placement Status',        'numeric' => false, 'restricted' => false, 'type' => 'text'],
            ['key' => 'placed_company',                    'label' => 'Placed Company',          'numeric' => false, 'restricted' => false, 'type' => 'text'],
            ['key' => 'CTC',                               'label' => 'Placed Package',          'numeric' => true,  'restricted' => true,  'type' => 'text'],
        ];
    }

    /**
     * Return all field definitions from config, or defaults if not configured.
     */
    public static function get_fields(): array {
        $raw = get_config('local_batchanalytics', 'crm_fields_config');
        if (!empty($raw)) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed) && !empty($parsed)) {
                return $parsed;
            }
        }
        return self::get_default_fields();
    }

    /**
     * Return only the Zoho API field keys (excludes computed CALC_* / placed_company).
     */
    public static function get_api_field_keys(): array {
        $computed = self::COMPUTED_KEYS;
        $keys = [];
        foreach (self::get_fields() as $f) {
            if (!in_array($f['key'], $computed)) {
                $keys[] = $f['key'];
            }
        }
        return $keys;
    }

    /**
     * Return the keys of fields that should be hidden from View-only users.
     */
    public static function get_restricted_keys(): array {
        $keys = [];
        foreach (self::get_fields() as $f) {
            if (!empty($f['restricted'])) {
                $keys[] = $f['key'];
            }
        }
        return $keys;
    }
<<<<<<< HEAD

    /**
     * Strip restricted CRM fields from a data array.
     */
    public static function filter_crm_fields($data, $restricted_fields) {
        if (empty($data) || !is_array($data)) {
            return $data;
        }
        foreach ($restricted_fields as $field) {
            unset($data[$field]);
        }
        // Always strip debug fields for non-managers.
        unset($data['_debug_api_url']);
        unset($data['_debug_api_response']);
        return $data;
    }
    /**
     * Return the configured student CRM module API name.
     */
    public static function get_student_module_api_name(): string {
        $module = trim((string)get_config('local_batchanalytics', 'student_crm_module_api_name'));
        return $module !== '' ? $module : 'Child_Admission';
    }

    /**
     * Return the configured mentor CRM module API name.
     */
    public static function get_mentor_module_api_name(): string {
        return trim((string)get_config('local_batchanalytics', 'mentor_crm_module_api_name'));
    }

    /**
     * Return the CRM field used to find a mentor record by batch group name.
     */
    public static function get_mentor_batch_field_key(): string {
        return trim((string)get_config('local_batchanalytics', 'mentor_crm_batch_field_key'));
    }

    /**
     * Return mentor field definitions from config.
     */
    public static function get_mentor_fields(): array {
        $raw = get_config('local_batchanalytics', 'mentor_crm_fields_config');
        if (!empty($raw)) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }
        return [];
    }

    /**
     * Return mentor Zoho API field keys.
     */
    public static function get_mentor_api_field_keys(): array {
        $keys = [];
        foreach (self::get_mentor_fields() as $field) {
            $key = trim((string)($field['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * Normalize mentor CRM field groups from JSON setting rows.
     *
     * @param array $groups
     * @return array
     */
    public static function normalise_mentor_field_groups(array $groups): array {
        $available = array_fill_keys(self::get_mentor_api_field_keys(), true);
        $normalised = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $name = trim((string)($group['name'] ?? $group['title'] ?? ''));
            if ($name === '') {
                continue;
            }

            $fields = $group['fields'] ?? $group['columns'] ?? [];
            if (!is_array($fields)) {
                $fields = [];
            }

            $cleanfields = [];
            foreach ($fields as $field) {
                $field = trim((string)$field);
                if ($field !== '' && isset($available[$field])) {
                    $cleanfields[] = $field;
                }
            }

            if (empty($cleanfields)) {
                continue;
            }

            $normalised[] = [
                'name' => $name,
                'fields' => array_values(array_unique($cleanfields)),
            ];
        }

        return $normalised;
    }

    /**
     * Return mentor groups for the frontend. Supports the old line format as a fallback.
     */
    public static function get_mentor_field_groups(): array {
        $raw = (string)get_config('local_batchanalytics', 'mentor_crm_field_groups');
        if ($raw === '') {
            return [];
        }

        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            return array_map(static function(array $group): array {
                return [
                    'title' => $group['name'],
                    'fields' => $group['fields'],
                ];
            }, self::normalise_mentor_field_groups($parsed));
        }

        $groups = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) {
                continue;
            }
            [$title, $fields] = array_map('trim', explode('|', $line, 2));
            $groups[] = [
                'name' => $title,
                'fields' => array_values(array_filter(array_map(static function($field): string {
                    return trim((string)$field);
                }, explode(',', $fields)))),
            ];
        }

        return array_map(static function(array $group): array {
            return [
                'title' => $group['name'],
                'fields' => $group['fields'],
            ];
        }, self::normalise_mentor_field_groups($groups));
    }
=======
>>>>>>> origin/main
}
