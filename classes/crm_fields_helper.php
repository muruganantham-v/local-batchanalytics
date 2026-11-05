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

    /**
     * Map of derived output fields to their underlying CRM source fields.
     */
    public const DERIVED_FIELD_MAP = [
        'placed_company' => 'Placement_Company',
        'CTC'            => 'CTC',
    ];

    /**
     * Return all restricted keys, including derived counterparts.
     *
     * If Placement_Company is restricted, placed_company is also restricted.
     * If placed_company is restricted, Placement_Company is also restricted.
     *
     * @param array|null $restricted_keys Optional specific restricted keys to expand. If null, loads from config.
     * @return array
     */
    public static function get_all_restricted_keys(?array $restricted_keys = null): array {
        if ($restricted_keys === null) {
            $restricted_keys = self::get_restricted_keys();
        }
        $all = $restricted_keys;
        foreach (self::DERIVED_FIELD_MAP as $derived => $source) {
            if (in_array($source, $restricted_keys, true) && !in_array($derived, $all, true)) {
                $all[] = $derived;
            }
            if (in_array($derived, $restricted_keys, true) && !in_array($source, $all, true)) {
                $all[] = $source;
            }
        }
        return array_values(array_unique($all));
    }

    /**
     * Check if a field or its counterpart in DERIVED_FIELD_MAP is restricted.
     *
     * @param string $field Field name to check.
     * @param array $restricted_fields Configured restricted fields.
     * @return bool
     */
    public static function is_field_restricted(string $field, array $restricted_fields): bool {
        if (empty($restricted_fields)) {
            return false;
        }
        $all = self::get_all_restricted_keys($restricted_fields);
        return in_array($field, $all, true);
    }

    /**
     * Strip restricted CRM fields from a data array.
     */
    public static function filter_crm_fields($data, $restricted_fields) {
        if (empty($data) || !is_array($data)) {
            return $data;
        }
        $all_restricted = self::get_all_restricted_keys((array)$restricted_fields);
        foreach ($all_restricted as $field) {
            unset($data[$field]);
        }
        // Always strip debug fields for non-managers.
        unset($data['_debug_api_url']);
        unset($data['_debug_api_response']);
        return $data;
    }

    /**
     * Extract placement company string from a raw CRM record.
     *
     * @param array|null $record
     * @return string
     */
    public static function extract_placement_company(?array $record): string {
        if (empty($record) || empty($record['Placement_Company'])) {
            return 'Not Placed';
        }
        $val = $record['Placement_Company'];
        if (is_array($val) && isset($val['name'])) {
            return (string)$val['name'];
        }
        if (is_string($val)) {
            return $val;
        }
        return 'Not Placed';
    }

    /**
     * Build the filtered student CRM data array for bulk API responses.
     *
     * @param string $username Student username.
     * @param array|null $record Raw Zoho record.
     * @param bool $can_manage Whether user has management/full CRM capability.
     * @param array $restricted_fields Array of restricted field keys.
     * @return array{username:string, placed_company:?string, CTC:?string, data:array}
     */
    public static function format_student_crm_data(
        string $username,
        ?array $record,
        bool $can_manage,
        array $restricted_fields = []
    ): array {
        $company = self::extract_placement_company($record);
        $rec_data = is_array($record) ? $record : [];
        $rec_ctc = $rec_data['CTC'] ?? '-';

        if (!$can_manage && !empty($restricted_fields)) {
            $rec_data = self::filter_crm_fields($rec_data, $restricted_fields);
            if (self::is_field_restricted('Placement_Company', $restricted_fields)) {
                $company = null;
            }
            if (self::is_field_restricted('CTC', $restricted_fields)) {
                $rec_ctc = null;
            }
        }

        return [
            'username'       => $username,
            'placed_company' => $company,
            'CTC'            => $rec_ctc,
            'data'           => $rec_data,
        ];
    }

    /**
     * Build the single-student CRM response array.
     *
     * @param string $username Student username.
     * @param array|null $record Raw Zoho record.
     * @param bool $can_manage Whether user has management/full CRM capability.
     * @param array $restricted_fields Array of restricted field keys.
     * @return array
     */
    public static function format_single_student_payload(
        string $username,
        ?array $record,
        bool $can_manage,
        array $restricted_fields = []
    ): array {
        $company = self::extract_placement_company($record);
        $data = ['username' => $username, 'placed_company' => $company];

        if (is_array($record)) {
            foreach (self::get_api_field_keys() as $field_key) {
                if ($field_key === 'College_Name') {
                    $data[$field_key] = $record['College_Name'] ?? ($record['Other_College_Name'] ?? '-');
                } else {
                    $data[$field_key] = $record[$field_key] ?? '-';
                }
            }
        }

        if (!$can_manage && !empty($restricted_fields)) {
            $data = self::filter_crm_fields($data, $restricted_fields);
            if (self::is_field_restricted('Placement_Company', $restricted_fields)) {
                $data['placed_company'] = null;
            }
            if (self::is_field_restricted('CTC', $restricted_fields)) {
                $data['CTC'] = null;
            }
        }

        return $data;
    }

    /**
     * Return the configured student CRM module API name.
     */
    public static function get_student_module_api_name(): string {
        $module = trim((string)get_config('local_batchanalytics', 'student_crm_module_api_name'));
        return $module !== '' ? $module : 'Child_Admission';
    }
}
