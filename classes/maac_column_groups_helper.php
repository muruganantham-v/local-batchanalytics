<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper for MAAC custom column groups.
 */
class maac_column_groups_helper {

    /**
     * @return array
     */
    public static function get_default_groups(): array {
        return [
            [
                'name' => 'Academic Review',
                'columns' => ['plagiarism_rating', 'trend'],
                'showinmaac' => true,
                'showinadvanced' => true,
            ],
            [
                'name' => 'Recognition',
                'columns' => ['spot_award', 'power_track_student', 'spot_awards_nomination'],
                'showinmaac' => true,
                'showinadvanced' => true,
            ],
        ];
    }

    /**
     * Return configured groups or defaults.
     *
     * @return array
     */
    public static function get_groups(): array {
        $raw = get_config('local_batchanalytics', 'maac_column_groups');
        if (!empty($raw)) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed) && !empty($parsed)) {
                return self::normalise_groups($parsed);
            }
        }

        return self::get_default_groups();
    }

    /**
     * @param array $groups
     * @return array
     */
    public static function normalise_groups(array $groups): array {
        $normalized = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $columns = $group['columns'] ?? [];
            if (!is_array($columns)) {
                $columns = [];
            }

            $normalized[] = [
                'name' => trim((string)($group['name'] ?? '')),
                'columns' => array_values($columns),
                'showinmaac' => !array_key_exists('showinmaac', $group) || !empty($group['showinmaac']),
                'showinadvanced' => !array_key_exists('showinadvanced', $group) || !empty($group['showinadvanced']),
            ];
        }

        return $normalized;
    }

    /**
     * Resolve saved groups against available custom columns.
     * Any ungrouped columns are appended under an automatic fallback group.
     *
     * @param array|null $columns
     * @return array
     */
    public static function get_resolved_groups(?array $columns = null, string $surface = 'all'): array {
        $columns = $columns ?? maac_columns_helper::get_columns();
        $bykey = [];
        foreach ($columns as $column) {
            $bykey[$column['key']] = $column;
        }

        $resolved = [];
        $assigned = [];
        foreach (self::get_groups() as $group) {
            $name = trim((string)($group['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            foreach (($group['columns'] ?? []) as $key) {
                $assigned[$key] = true;
            }

            if (!self::is_group_visible_on_surface($group, $surface)) {
                continue;
            }

            $items = [];
            foreach (($group['columns'] ?? []) as $key) {
                if (isset($bykey[$key]) && maac_columns_helper::is_visible_on_surface($bykey[$key], $surface)) {
                    $items[] = $bykey[$key];
                }
            }

            if (!empty($items)) {
                $resolved[] = [
                    'name' => $name,
                    'columns' => $items,
                    'showinmaac' => !empty($group['showinmaac']),
                    'showinadvanced' => !empty($group['showinadvanced']),
                ];
            }
        }

        $remaining = [];
        foreach ($columns as $column) {
            if (
                ($column['key'] ?? '') !== 'maac_rating' &&
                !isset($assigned[$column['key']]) &&
                maac_columns_helper::is_visible_on_surface($column, $surface)
            ) {
                $remaining[] = $column;
            }
        }

        if (!empty($remaining)) {
            $hassystemcolumns = false;
            foreach ($remaining as $column) {
                if (!empty($column['system'])) {
                    $hassystemcolumns = true;
                    break;
                }
            }

            $resolved[] = [
                'name' => $hassystemcolumns ? 'Other Columns' : 'Other Custom Columns',
                'columns' => $remaining,
                'showinmaac' => true,
                'showinadvanced' => true,
            ];
        }

        return $resolved;
    }

    /**
     * @param array $group
     * @param string $surface
     * @return bool
     */
    private static function is_group_visible_on_surface(array $group, string $surface): bool {
        if ($surface === 'maac') {
            return !array_key_exists('showinmaac', $group) || !empty($group['showinmaac']);
        }

        if ($surface === 'advanced') {
            return !array_key_exists('showinadvanced', $group) || !empty($group['showinadvanced']);
        }

        return true;
    }
}
