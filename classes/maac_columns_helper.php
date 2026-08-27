<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper for reading the MAAC custom columns configuration.
 */
class maac_columns_helper {

    /** @var string[] */
    private const SURFACES = ['maac', 'advanced'];
    private const BUILTIN_KEYS = ['maac_rating', 'spot_awards_nomination', 'trend'];

    /** Return whether this is a built-in MAAC display column. */
    public static function is_builtin_key(string $key): bool {
        return in_array($key, self::BUILTIN_KEYS, true);
    }

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

            // Stored settings from older versions may incorrectly mark a
            // custom field as locked. Only canonical built-ins may be locked.
            if (!self::is_builtin_key((string)($row['key'] ?? ''))) {
                unset($row['system'], $row['locked']);
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
                            'maacsync' => $column['maacsync'] ?? $builtin['maacsync'],
                            'crmfield' => $column['crmfield'] ?? $builtin['crmfield'],
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

    /** Return numeric MAAC columns configured for CRM average sync. */
    public static function get_sync_columns(): array {
        return array_values(array_filter(self::get_settings_rows(), static function(array $column): bool {
            return !empty($column['maacsync'])
                && in_array($column['type'] ?? '', ['number', 'formula'], true)
                && !empty($column['crmfield']);
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
            'formula' => trim((string)($column['formula'] ?? '')),
            'options' => array_values($options),
            'selection' => $selection,
            'min' => array_key_exists('min', $column) ? $column['min'] : null,
            'max' => array_key_exists('max', $column) ? $column['max'] : null,
            'showinmaac' => true,
            'showinadvanced' => true,
            'maacsync' => !empty($column['maacsync']),
            'crmfield' => preg_replace('/[^A-Za-z0-9_]/', '', trim((string)($column['crmfield'] ?? ''))),
            'excludefrommaac' => !empty($column['excludefrommaac']),
            'system' => !empty($column['system']),
            'locked' => !empty($column['locked']),
        ];
    }

    /** Calculate configured formula columns from a student's stored custom values. */
    public static function calculate_formula_values(array $values, array $columns): array {
        $bykey = array_column($columns, null, 'key');
        $resolved = [];
        $resolving = [];
        $resolve = function(string $key) use (&$resolve, &$resolved, &$resolving, $values, $bykey): ?float {
            if (array_key_exists($key, $resolved)) {
                return $resolved[$key];
            }
            if (!isset($bykey[$key]) || isset($resolving[$key])) {
                return null;
            }
            $resolving[$key] = true;
            $column = $bykey[$key];
            if (($column['type'] ?? '') === 'formula') {
                $value = self::evaluate_formula((string)($column['formula'] ?? ''), $resolve);
            } else {
                $raw = $values[$key] ?? 0;
                $value = is_numeric($raw) ? (float)$raw : 0.0;
            }
            unset($resolving[$key]);
            $resolved[$key] = $value;
            return $value;
        };
        foreach ($columns as $column) {
            if (($column['type'] ?? '') === 'formula') {
                $value = $resolve($column['key']);
                $values[$column['key']] = $value === null ? '' : $value;
            }
        }
        return $values;
    }

    private static function evaluate_formula(string $formula, callable $resolve): ?float {
        $compact = preg_replace('/\s+/', '', $formula);
        if ($compact === '') return null;
        preg_match_all('/\{[a-z0-9_]+\}|\d+(?:\.\d+)?|[()+\-*\/]/i', $compact, $matches);
        $tokens = $matches[0] ?? [];
        if (implode('', $tokens) !== $compact) return null;
        $output = []; $operators = []; $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2]; $previous = null;
        foreach ($tokens as $token) {
            if (is_numeric($token) || $token[0] === '{') { $output[] = $token; }
            else if ($token === '(') { $operators[] = $token; }
            else if ($token === ')') { while (!empty($operators) && end($operators) !== '(') $output[] = array_pop($operators); if (array_pop($operators) !== '(') return null; }
            else { if ($token === '-' && ($previous === null || isset($precedence[$previous]) || $previous === '(')) $output[] = '0'; while (!empty($operators) && end($operators) !== '(' && $precedence[end($operators)] >= $precedence[$token]) $output[] = array_pop($operators); $operators[] = $token; }
            $previous = $token;
        }
        while (!empty($operators)) { $operator = array_pop($operators); if ($operator === '(') return null; $output[] = $operator; }
        $stack = [];
        foreach ($output as $token) {
            if (isset($precedence[$token])) { $right = array_pop($stack); $left = array_pop($stack); if ($left === null || $right === null || ($token === '/' && (float)$right == 0.0)) return null; $stack[] = match ($token) { '+' => $left + $right, '-' => $left - $right, '*' => $left * $right, '/' => $left / $right }; }
            else if ($token[0] === '{') { $value = $resolve(substr($token, 1, -1)); if ($value === null) return null; $stack[] = $value; }
            else { $stack[] = (float)$token; }
        }
        return count($stack) === 1 && is_finite((float)$stack[0]) ? round((float)$stack[0], 4) : null;
    }
}
