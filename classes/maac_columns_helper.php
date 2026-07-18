<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper for reading the MAAC custom columns configuration.
 */
class maac_columns_helper {

    /** @var string[] */
    private const SURFACES = ['maac', 'advanced'];

    /**
     * Default editable columns for the MAAC page.
     *
     * @return array
     */
    public static function get_default_columns(): array {
        return [
            self::normalise_column([
                'key' => 'plagiarism_rating',
                'label' => 'Plagiarism Rating',
                'type' => 'number',
                'options' => [],
                'selection' => 'single',
                'min' => 0,
                'max' => 100,
            ]),
            self::normalise_column([
                'key' => 'spot_award',
                'label' => 'Spot Award',
                'type' => 'boolean',
                'options' => [],
                'selection' => 'single',
                'min' => null,
                'max' => null,
            ]),
            self::normalise_column([
                'key' => 'power_track_student',
                'label' => 'Power Track Student',
                'type' => 'boolean',
                'options' => [],
                'selection' => 'single',
                'min' => null,
                'max' => null,
            ]),
        ];
    }

    /**
     * Built-in display rows shown in settings but not stored as editable custom values.
     *
     * @return array
     */
    public static function get_builtin_setting_rows(): array {
        return [
            self::normalise_column([
                'key' => 'maac_rating',
                'label' => 'MAAC Rating',
                'type' => 'number',
                'options' => [],
                'selection' => 'single',
                'min' => 0,
                'max' => 10,
                'system' => true,
                'locked' => true,
            ]),
            self::normalise_column([
                'key' => 'spot_awards_nomination',
                'label' => 'Spot Awards',
                'type' => 'list',
                'options' => [],
                'selection' => 'multi',
                'min' => null,
                'max' => null,
                'system' => true,
                'locked' => true,
            ]),
            self::normalise_column([
                'key' => 'trend',
                'label' => 'Trend',
                'type' => 'text',
                'options' => [],
                'selection' => 'single',
                'min' => null,
                'max' => null,
                'system' => true,
                'locked' => true,
            ]),
        ];
    }

    /**
     * Default settings rows including built-in display rows.
     *
     * @return array
     */
    public static function get_default_settings_rows(): array {
        return array_merge(self::get_builtin_setting_rows(), self::get_default_columns());
    }

    /**
     * Return normalized settings rows including built-in display rows.
     *
     * @return array
     */
    public static function get_settings_rows(): array {
        $raw = get_config('local_batchanalytics', 'maac_custom_columns');
        $parsed = !empty($raw) ? json_decode($raw, true) : null;
        if (!is_array($parsed) || empty($parsed)) {
            return self::get_default_settings_rows();
        }

        return self::normalise_settings_rows($parsed);
    }

    /**
     * Normalize settings rows and ensure required built-in rows are present.
     *
     * @param array $rows
     * @return array
     */
    public static function normalise_settings_rows(array $rows): array {
        $normalized = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $column = self::normalise_column($row);
            if ($column['key'] === '') {
                continue;
            }

            if (isset($seen[$column['key']])) {
                continue;
            }

            $normalized[] = $column;
            $seen[$column['key']] = true;
        }

        foreach (self::get_builtin_setting_rows() as $builtin) {
            if (isset($seen[$builtin['key']])) {
                foreach ($normalized as $index => $column) {
                    if ($column['key'] === $builtin['key']) {
                        $normalized[$index] = self::normalise_column(array_merge($column, $builtin, [
                            'showinmaac' => $column['showinmaac'] ?? $builtin['showinmaac'],
                            'showinadvanced' => $column['showinadvanced'] ?? $builtin['showinadvanced'],
                        ]));
                        break;
                    }
                }
                continue;
            }

            array_unshift($normalized, $builtin);
        }

        return array_values($normalized);
    }

    /**
     * Return configured editable MAAC columns or defaults if not configured.
     *
     * @return array
     */
    public static function get_columns(): array {
        return array_values(array_filter(self::get_settings_rows(), static function(array $column): bool {
            return empty($column['system']);
        }));
    }

    /**
     * Return editable custom columns visible on the provided surface.
     *
     * @param string $surface
     * @return array
     */
    public static function get_columns_for_surface(string $surface): array {
        return array_values(array_filter(self::get_columns(), static function(array $column) use ($surface): bool {
            return self::is_visible_on_surface($column, $surface);
        }));
    }

    /**
     * Return built-in display rows visible on the provided surface.
     *
     * @param string|null $surface
     * @return array
     */
    public static function get_builtin_columns(?string $surface = null): array {
        $builtins = array_values(array_filter(self::get_settings_rows(), static function(array $column): bool {
            return !empty($column['system']);
        }));

        if ($surface === null || !in_array($surface, self::SURFACES, true)) {
            return $builtins;
        }

        return array_values(array_filter($builtins, static function(array $column) use ($surface): bool {
            return self::is_visible_on_surface($column, $surface);
        }));
    }

    /**
     * @param array $column
     * @param string $surface
     * @return bool
     */
    public static function is_visible_on_surface(array $column, string $surface): bool {
        if ($surface === 'maac') {
            return !array_key_exists('showinmaac', $column) || !empty($column['showinmaac']);
        }

        if ($surface === 'advanced') {
            return !array_key_exists('showinadvanced', $column) || !empty($column['showinadvanced']);
        }

        return true;
    }

    /**
     * @param array $column
     * @return array
     */
    private static function normalise_column(array $column): array {
        $options = $column['options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }

        $type = (string)($column['type'] ?? 'multi_feedback');
        $selection = strtolower(trim((string)($column['selection'] ?? 'single')));
        if ($type === 'multi_feedback') {
            $selection = in_array($selection, ['internal', 'external'], true) ? $selection : 'internal';
        } else if ($type === 'dropdown') {
            $selection = in_array($selection, ['single', 'multi'], true) ? $selection : 'single';
        } else {
            $selection = 'single';
        }

        return [
            'key' => trim((string)($column['key'] ?? '')),
            'label' => trim((string)($column['label'] ?? '')),
            'type' => $type,
            'options' => array_values($options),
            'selection' => $selection,
            'min' => array_key_exists('min', $column) ? $column['min'] : null,
            'max' => array_key_exists('max', $column) ? $column['max'] : null,
            'showinmaac' => !array_key_exists('showinmaac', $column) || !empty($column['showinmaac']),
            'showinadvanced' => !array_key_exists('showinadvanced', $column) || !empty($column['showinadvanced']),
            'excludefrommaac' => !empty($column['excludefrommaac']),
            'system' => !empty($column['system']),
            'locked' => !empty($column['locked']),
        ];
    }
}
