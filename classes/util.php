<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

class util {
    /**
     * Canonical module durations in days as defined by curriculum.
     *
     * @var array<string, int>
     */
    public static $canonical_module_days = [
        'Linux Systems'    => 5,
        'Advanced C'       => 77,
        'C++ Programming'  => 13,
        'Data Structures'  => 29,
        'Microcontrollers' => 37,
        'Linux Internals'  => 33,
        'Qt / QML'         => 10,
        'ELARM'            => 10,
    ];

    /**
     * Canonical module names indexed by module sequence position (1 to 8).
     *
     * @var array<int, string>
     */
    public static $canonical_modules = [
        1 => 'Linux Systems',
        2 => 'Advanced C',
        3 => 'C++ Programming',
        4 => 'Data Structures',
        5 => 'Microcontrollers',
        6 => 'Linux Internals',
        7 => 'ELARM',
        8 => 'Qt / QML',
    ];

    /**
     * Check if the delivery mode represents an online batch.
     *
     * @param string|null $mode
     * @return bool
     */
    public static function is_online_mode(?string $mode): bool {
        if (empty($mode)) {
            return false;
        }
        return stripos((string)$mode, 'online') !== false;
    }

    /**
     * Check if a module name, code, or index corresponds to Qt / QML.
     *
     * @param string|int|null $name_or_code
     * @return bool
     */
    public static function is_qt_module($name_or_code): bool {
        if (empty($name_or_code)) {
            return false;
        }
        $clean = strtolower(trim((string)$name_or_code));
        if ($clean === '8' || $clean === 'module 8' || $clean === 'module8') {
            return true;
        }
        $stripped = preg_replace('/[^a-z0-9]/', '', $clean);
        return ($stripped === 'qtqml' || $stripped === 'qt' || $stripped === 'qml'
            || strpos($stripped, 'qtqml') !== false || strpos($clean, 'qt') !== false || strpos($clean, 'qml') !== false);
    }

    /**
     * Get canonical module titles by delivery mode.
     * For Online batches, Qt / QML is completely excluded (SR-3.2.1).
     *
     * @param string|null $deliverymode
     * @return array<int, string>
     */
    public static function get_canonical_modules(?string $deliverymode = 'Offline'): array {
        if (self::is_online_mode($deliverymode)) {
            return [
                1 => 'Linux Systems',
                2 => 'Advanced C',
                3 => 'C++ Programming',
                4 => 'Data Structures',
                5 => 'Microcontrollers',
                6 => 'Linux Internals',
                7 => 'ELARM',
            ];
        }
        return [
            1 => 'Linux Systems',
            2 => 'Advanced C',
            3 => 'C++ Programming',
            4 => 'Data Structures',
            5 => 'Microcontrollers',
            6 => 'Linux Internals',
            7 => 'ELARM',
            8 => 'Qt / QML',
        ];
    }

    /**
     * Get canonical module durations in days by delivery mode.
     *
     * @param string|null $deliverymode
     * @return array<int, int>
     */
    public static function get_canonical_days(?string $deliverymode = 'Offline'): array {
        if (self::is_online_mode($deliverymode)) {
            return [
                1 => 5, 2 => 77, 3 => 13, 4 => 29, 5 => 37, 6 => 33, 7 => 10
            ];
        }
        return [
            1 => 5, 2 => 77, 3 => 13, 4 => 29, 5 => 37, 6 => 33, 7 => 10, 8 => 10
        ];
    }

    /**
     * Filter module records for a specific delivery mode.
     * If online, Qt / QML is excluded and module positions re-indexed (SR-3.2.1).
     *
     * @param array $modules
     * @param string|null $deliverymode
     * @return array
     */
    public static function filter_modules_for_mode(array $modules, ?string $deliverymode): array {
        if (!self::is_online_mode($deliverymode)) {
            return $modules;
        }

        $filtered = [];
        $new_idx = 1;
        foreach ($modules as $m) {
            $m_name = $m['name'] ?? $m['courseshortname'] ?? '';
            $m_idx = $m['module'] ?? $new_idx;
            if (self::is_qt_module($m_name) || (is_numeric($m_idx) && (int)$m_idx === 8 && self::is_qt_module($m_name ?: '8'))) {
                continue;
            }
            $m['module'] = $new_idx++;
            $filtered[] = $m;
        }
        return $filtered;
    }


    /**
     * Get module total days by name, course name, or module index.
     *
     * @param string|int $module_name_or_idx Module title or 1-based index
     * @param int $fallback Fallback days if not recognized
     * @return int
     */
    public static function get_module_total_days($module_name_or_idx, int $fallback = 10): int {
        if (is_numeric($module_name_or_idx)) {
            $idx = (int)$module_name_or_idx;
            $index_map = [
                1 => 5,
                2 => 77,
                3 => 13,
                4 => 29,
                5 => 37,
                6 => 33,
                7 => 10,
                8 => 10,
            ];
            if (isset($index_map[$idx])) {
                return $index_map[$idx];
            }
        }

        $raw = trim((string)$module_name_or_idx);
        if ($raw === '') {
            return $fallback > 0 ? $fallback : 10;
        }

        if (isset(self::$canonical_module_days[$raw])) {
            return self::$canonical_module_days[$raw];
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9\+]/i', '', $raw));
        $normalized_map = [
            'linuxsystems'     => 5,
            'advancedc'        => 77,
            'c++programming'   => 13,
            'c++'              => 13,
            'cppprogramming'   => 13,
            'cpp'              => 13,
            'datastructures'   => 29,
            'ds'               => 29,
            'microcontrollers' => 37,
            'microcontroller'  => 37,
            'linuxinternals'   => 33,
            'qtqml'            => 10,
            'qt'               => 10,
            'qml'              => 10,
            'elarm'            => 10,
        ];

        foreach ($normalized_map as $key => $days) {
            if ($normalized === $key || strpos($normalized, $key) !== false || strpos($key, $normalized) !== false) {
                return $days;
            }
        }

        return $fallback > 0 ? $fallback : 10;
    }

    /**
     * Calculate module status based on schedule delta and module total days.
     *
     * Rules:
     * - Delta < 0: Early (-Xd)
     * - Percentage < 7%: On schedule (Xd)
     * - Percentage 7% - 13%: Minor slip (Xd)
     * - Percentage > 13%: Delayed (Xd)
     *
     * @param int|float|string|null $delta Delay in days (or 'prog' or null)
     * @param string|int $module_name_or_idx Module name or index
     * @param int $fallback_total_days Optional fallback module days
     * @return array Status descriptor array
     */
    public static function get_module_status($delta, $module_name_or_idx = '', int $fallback_total_days = 0): array {
        $total_days = self::get_module_total_days($module_name_or_idx, $fallback_total_days);
        if ($total_days <= 0) {
            $total_days = 10;
        }

        if ($delta === 'prog') {
            return [
                'status' => 'in_progress',
                'label' => 'In progress',
                'short_label' => 'In progress',
                'chip_class' => 'a',
                'pill_class' => 'status-minor-slip',
                'dot_class' => 'dot-amber',
                'delta' => null,
                'percentage' => null,
                'total_days' => $total_days,
            ];
        }

        if ($delta === null || $delta === '' || (is_string($delta) && !is_numeric($delta))) {
            return [
                'status' => 'none',
                'label' => '—',
                'short_label' => '—',
                'chip_class' => 'muted',
                'pill_class' => '',
                'dot_class' => '',
                'delta' => null,
                'percentage' => null,
                'total_days' => $total_days,
            ];
        }

        $d = (int)$delta;

        if ($d < 0) {
            $early = abs($d);
            return [
                'status' => 'early',
                'label' => 'Early (-' . $early . 'd)',
                'short_label' => 'Early',
                'chip_class' => 'b',
                'pill_class' => 'status-early',
                'dot_class' => 'dot-blue',
                'delta' => $d,
                'percentage' => round(($d / $total_days) * 100, 2),
                'total_days' => $total_days,
            ];
        }

        $pct = ($d / $total_days) * 100;

        if ($pct < 7.0) {
            $days_text = ($d > 0) ? ('On schedule (+' . $d . 'd)') : 'On schedule (0d)';
            return [
                'status' => 'on_schedule',
                'label' => $days_text,
                'short_label' => 'On schedule',
                'chip_class' => 'g',
                'pill_class' => 'status-ok',
                'dot_class' => 'dot-green',
                'delta' => $d,
                'percentage' => round($pct, 2),
                'total_days' => $total_days,
            ];
        }

        if ($pct <= 13.0) {
            return [
                'status' => 'minor_slip',
                'label' => 'Minor slip (+' . $d . 'd)',
                'short_label' => 'Minor slip',
                'chip_class' => 'a',
                'pill_class' => 'status-minor-slip',
                'dot_class' => 'dot-amber',
                'delta' => $d,
                'percentage' => round($pct, 2),
                'total_days' => $total_days,
            ];
        }

        return [
            'status' => 'delayed',
            'label' => 'Delayed (+' . $d . 'd)',
            'short_label' => 'Delayed',
            'chip_class' => 'r',
            'pill_class' => 'status-delayed',
            'dot_class' => 'dot-red',
            'delta' => $d,
            'percentage' => round($pct, 2),
            'total_days' => $total_days,
        ];
    }

    /**
     * Calculate batch status based on total accumulated delay in days.
     *
     * Rules:
     * - Delta < 0: Early (-Xd)
     * - Delta < 12 days: On schedule (Xd)
     * - Delta 12 - 23 days: Minor slip (Xd)
     * - Delta 24+ days: Delayed (Xd)
     *
     * @param int|float|string|null $delta Accumulated delay in days
     * @return array Status descriptor array
     */
    public static function get_batch_status($delta): array {
        if ($delta === null || $delta === '' || (is_string($delta) && !is_numeric($delta))) {
            return [
                'status' => 'none',
                'label' => '—',
                'short_label' => '—',
                'chip_class' => 'muted',
                'pill_class' => '',
                'dot_class' => '',
                'delta' => null,
            ];
        }

        $d = (int)$delta;

        if ($d < 0) {
            $early = abs($d);
            return [
                'status' => 'early',
                'label' => 'Early (-' . $early . 'd)',
                'short_label' => 'Early',
                'chip_class' => 'b',
                'pill_class' => 'status-early',
                'dot_class' => 'dot-blue',
                'delta' => $d,
            ];
        }

        if ($d < 12) {
            $days_text = ($d > 0) ? ('On schedule (+' . $d . 'd)') : 'On schedule (0d)';
            return [
                'status' => 'on_schedule',
                'label' => $days_text,
                'short_label' => 'On schedule',
                'chip_class' => 'g',
                'pill_class' => 'status-ok',
                'dot_class' => 'dot-green',
                'delta' => $d,
            ];
        }

        if ($d <= 23) {
            return [
                'status' => 'minor_slip',
                'label' => 'Minor slip (+' . $d . 'd)',
                'short_label' => 'Minor slip',
                'chip_class' => 'a',
                'pill_class' => 'status-minor-slip',
                'dot_class' => 'dot-amber',
                'delta' => $d,
            ];
        }

        return [
            'status' => 'delayed',
            'label' => 'Delayed (+' . $d . 'd)',
            'short_label' => 'Delayed',
            'chip_class' => 'r',
            'pill_class' => 'status-delayed',
            'dot_class' => 'dot-red',
            'delta' => $d,
        ];
    }

    /**
     * Check whether a user has at least one enrolled-course capability instance.
     *
     * @param int $userid
     * @param array $capabilities
     * @return bool
     */
    public static function has_any_course_capability(int $userid, array $capabilities): bool {
        $courses = enrol_get_users_courses($userid, true, ['id']);
        foreach ($courses as $course) {
            $coursecontext = \context_course::instance((int)$course->id, IGNORE_MISSING);
            if (!$coursecontext) {
                continue;
            }
            foreach ($capabilities as $capability) {
                if (has_capability($capability, $coursecontext, $userid)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Build a short-lived MUC cache key for heavy batch responses.
     */
    public static function get_batch_cache_key(int $userid, string $batchcode, bool $can_view_all_courses): string {
        return sha1(json_encode([
            // Bump when the shape or calculation of the batch payload changes.
            'v' => 2,
            'u' => $userid,
            'c' => $can_view_all_courses,
            'b' => trim($batchcode)
        ]));
    }

    /**
     * Read a cached batch response from MUC.
     */
    public static function read_cached_batch_response(string $cachekey): ?array {
        try {
            $cache = \cache::make('local_batchanalytics', 'batchdata');
            $payload = $cache->get($cachekey);
            return is_array($payload) ? $payload : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Store a cached batch response in MUC.
     */
    public static function write_cached_batch_response(string $cachekey, array $payload): void {
        try {
            $cache = \cache::make('local_batchanalytics', 'batchdata');
            $cache->set($cachekey, $payload);
        } catch (\Exception $e) {
            // Ignore if cache is unavailable
        }
    }

    /**
     * Clear cached batch responses after course-level metadata changes.
     */
    public static function purge_batch_response_cache(): void {
        try {
            $cache = \cache::make('local_batchanalytics', 'batchdata');
            $cache->purge();
        } catch (\Exception $e) {
            // Ignore if cache is unavailable.
        }
    }

    /**
     * Check if the user has exceeded CRM API rate limit.
     * Allows max 30 requests per minute per user.
     */
    public static function check_crm_rate_limit(int $userid): bool {
        try {
            $cache = \cache::make('local_batchanalytics', 'crmratelimit');
            $key = 'user_' . $userid;
            $now = time();
            $window = $cache->get($key);
            $window = is_array($window) ? $window : [];
            $windowstart = (int)($window['windowstart'] ?? 0);
            $count = (int)($window['count'] ?? 0);
            if ($windowstart <= 0 || $now < $windowstart || ($now - $windowstart) >= 60) {
                $windowstart = $now;
                $count = 0;
            }
            if ($count >= 30) {
                return false;
            }
            $cache->set($key, [
                'windowstart' => $windowstart,
                'count' => $count + 1,
            ]);
            return true;
        } catch (\Exception $e) {
            // Fail open if cache is unavailable
            return true;
        }
    }

    /**
     * Decode moduledata JSON from local_bm_classsection.
     * Supports both the new slot-object format (module_1 to module_8) and legacy indexed arrays.
     *
     * @param string|array|null $moduledata_raw Raw JSON string or array.
     * @param bool $only_active When true, filters out unconfigured/empty module slots.
     * @return array Standardized array of module records.
     */
    public static function decode_module_data($moduledata_raw, bool $only_active = true): array {
        if (empty($moduledata_raw)) {
            return [];
        }
        if (is_string($moduledata_raw)) {
            $decoded = json_decode($moduledata_raw, true);
        } else if (is_array($moduledata_raw)) {
            $decoded = $moduledata_raw;
        } else {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];

        // Check if format is keyed by module_1, module_2, or module1, module2...
        $is_keyed = false;
        for ($i = 1; $i <= 8; $i++) {
            if (isset($decoded['module_' . $i]) || isset($decoded['module' . $i])) {
                $is_keyed = true;
                break;
            }
        }

        if ($is_keyed) {
            for ($i = 1; $i <= 8; $i++) {
                $slot = $decoded['module_' . $i] ?? $decoded['module' . $i] ?? null;
                if (!is_array($slot)) {
                    continue;
                }
                $prefix = 'module' . $i;
                $name = trim((string)($slot['name'] ?? $slot['courseshortname'] ?? $slot[$prefix . '_name'] ?? $slot[$prefix] ?? ''));
                $cid = (int)($slot['moodlecourseid'] ?? $slot[$prefix . '_moodlecourseid'] ?? 0);
                $pstart = !empty($slot['plannedstart']) && is_numeric($slot['plannedstart']) ? (int)$slot['plannedstart'] : (int)($slot[$prefix . '_plannedstart'] ?? 0);
                $pend = !empty($slot['plannedend']) && is_numeric($slot['plannedend']) ? (int)$slot['plannedend'] : (int)($slot[$prefix . '_plannedend'] ?? 0);
                $pdays = (int)($slot['planneddays'] ?? $slot[$prefix . '_planneddays'] ?? 0);
                $astart = !empty($slot['actualstart']) && is_numeric($slot['actualstart']) ? (int)$slot['actualstart'] : (int)($slot[$prefix . '_actualstart'] ?? 0);
                $aend = !empty($slot['actualend']) && is_numeric($slot['actualend']) ? (int)$slot['actualend'] : (int)($slot[$prefix . '_actualend'] ?? 0);
                $adays = (int)($slot['actualdays'] ?? $slot[$prefix . '_actualdays'] ?? 0);
                $delta = isset($slot['scheduledelta']) && is_numeric($slot['scheduledelta'])
                    ? (int)$slot['scheduledelta']
                    : (isset($slot[$prefix . '_scheduledelta']) && is_numeric($slot[$prefix . '_scheduledelta']) ? (int)$slot[$prefix . '_scheduledelta'] : null);
                $pm = trim((string)($slot['primarymentor'] ?? $slot[$prefix . '_primarymentor'] ?? ''));
                $sm = trim((string)($slot['secondarymentor'] ?? $slot[$prefix . '_secondarymentor'] ?? ''));
                $lm1 = trim((string)($slot['labmentor1'] ?? $slot[$prefix . '_labmentor1'] ?? ''));
                $lm2 = trim((string)($slot['labmentor2'] ?? $slot[$prefix . '_labmentor2'] ?? ''));
                $lm3 = trim((string)($slot['labmentor3'] ?? $slot[$prefix . '_labmentor3'] ?? ''));

                $has_data = ($name !== '' || $cid > 0 || $pstart > 0 || $astart > 0 || $pdays > 0 || ($pm !== '' && $pm !== '0' && $pm !== '—'));
                if ($only_active && !$has_data) {
                    continue;
                }

                $normalized[] = [
                    'module' => $i,
                    'name' => $name,
                    'courseshortname' => $name,
                    'moodlecourseid' => $cid,
                    'plannedstart' => $pstart,
                    'plannedend' => $pend,
                    'planneddays' => $pdays,
                    'actualstart' => $astart,
                    'actualend' => $aend,
                    'actualdays' => $adays,
                    'scheduledelta' => $delta,
                    'primarymentor' => $pm,
                    'secondarymentor' => $sm,
                    'labmentor1' => $lm1,
                    'labmentor2' => $lm2,
                    'labmentor3' => $lm3,
                ];
            }
        } else {
            foreach ($decoded as $pos => $slot) {
                if (!is_array($slot)) {
                    continue;
                }
                $modnum = isset($slot['module']) && is_numeric($slot['module']) ? (int)$slot['module'] : ($pos + 1);
                $name = trim((string)($slot['name'] ?? $slot['courseshortname'] ?? ''));
                $cid = (int)($slot['moodlecourseid'] ?? 0);
                $pstart = !empty($slot['plannedstart']) && is_numeric($slot['plannedstart']) ? (int)$slot['plannedstart'] : 0;
                $pend = !empty($slot['plannedend']) && is_numeric($slot['plannedend']) ? (int)$slot['plannedend'] : 0;
                $pdays = (int)($slot['planneddays'] ?? 0);
                $astart = !empty($slot['actualstart']) && is_numeric($slot['actualstart']) ? (int)$slot['actualstart'] : 0;
                $aend = !empty($slot['actualend']) && is_numeric($slot['actualend']) ? (int)$slot['actualend'] : 0;
                $adays = (int)($slot['actualdays'] ?? 0);
                $delta = isset($slot['scheduledelta']) && is_numeric($slot['scheduledelta']) ? (int)$slot['scheduledelta'] : null;
                $pm = trim((string)($slot['primarymentor'] ?? ''));
                $sm = trim((string)($slot['secondarymentor'] ?? ''));
                $lm1 = trim((string)($slot['labmentor1'] ?? ''));
                $lm2 = trim((string)($slot['labmentor2'] ?? ''));
                $lm3 = trim((string)($slot['labmentor3'] ?? ''));

                $has_data = ($name !== '' || $cid > 0 || $pstart > 0 || $astart > 0 || $pdays > 0 || ($pm !== '' && $pm !== '0' && $pm !== '—'));
                if ($only_active && !$has_data) {
                    continue;
                }

                $normalized[] = [
                    'module' => $modnum,
                    'name' => $name,
                    'courseshortname' => $name,
                    'moodlecourseid' => $cid,
                    'plannedstart' => $pstart,
                    'plannedend' => $pend,
                    'planneddays' => $pdays,
                    'actualstart' => $astart,
                    'actualend' => $aend,
                    'actualdays' => $adays,
                    'scheduledelta' => $delta,
                    'primarymentor' => $pm,
                    'secondarymentor' => $sm,
                    'labmentor1' => $lm1,
                    'labmentor2' => $lm2,
                    'labmentor3' => $lm3,
                ];
            }
        }

        return $normalized;
    }

    /**
     * Get user profile details (name, profile picture URL, user object) by userid or user object.
     * Caches in memory to avoid duplicate DB queries.
     *
     * @param int|string|\stdClass|null $userorid User ID, username, or user record.
     * @param int $size 1 for large (f1 ~100px), 2 for small (f2 ~35px)
     * @return array ['user' => \stdClass|null, 'userid' => int, 'name' => string, 'initials' => string, 'profileimageurl' => string]
     */
    public static function get_user_profile_data($userorid, int $size = 2): array {
        global $DB, $PAGE;

        static $cache = [];

        $user = null;
        $userid = 0;

        if (is_object($userorid) && isset($userorid->id)) {
            $user = $userorid;
            $userid = (int)$user->id;
        } else if (is_numeric($userorid) && (int)$userorid > 0) {
            $userid = (int)$userorid;
        } else if (is_string($userorid) && trim($userorid) !== '' && trim($userorid) !== '—') {
            $identifier = trim($userorid);
            if (is_numeric($identifier)) {
                $userid = (int)$identifier;
            } else {
                // Try looking up by username or idnumber
                $matched = $DB->get_record_select(
                    'user',
                    'deleted = 0 AND (username = :u OR idnumber = :id)',
                    ['u' => $identifier, 'id' => $identifier],
                    '*',
                    IGNORE_MULTIPLE
                );
                if ($matched) {
                    $user = $matched;
                    $userid = (int)$user->id;
                }
            }
        }

        if ($userid > 0 && isset($cache[$userid][$size])) {
            return $cache[$userid][$size];
        }

        // Ensure we have a complete user record with picture fields
        if (!$user && $userid > 0) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        } else if ($user && (!property_exists($user, 'picture') || !property_exists($user, 'imagealt'))) {
            $fresh = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
            if ($fresh) {
                $user = $fresh;
            }
        }

        $name = $user ? fullname($user) : '';
        $initials = '';
        if ($name !== '') {
            $parts = preg_split('/\s+/', trim($name));
            if (count($parts) >= 2) {
                $initials = strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
            } else {
                $initials = strtoupper(mb_substr($name, 0, 1));
            }
        }

        $profileurl = '';
        if ($user) {
            try {
                $up = new \user_picture($user);
                $up->size = $size;
                $profileurl = $up->get_url($PAGE)->out(false);
            } catch (\Throwable $e) {
                $profileurl = '';
            }
        }

        $data = [
            'user' => $user,
            'userid' => $userid,
            'name' => $name,
            'initials' => $initials ?: '?',
            'profileimageurl' => $profileurl,
        ];

        if ($userid > 0) {
            $cache[$userid][$size] = $data;
        }

        return $data;
    }

    /**
     * Render user avatar HTML.
     * Uses Moodle $OUTPUT->user_picture if user object exists,
     * with graceful styled initials fallback.
     *
     * @param int|string|\stdClass|null $userorid
     * @param int $size Pixel size (e.g. 35, 38, 30, 24)
     * @param string $extraclass Optional CSS class
     * @param string|null $fallbackname Optional name if user record is missing
     * @return string HTML markup
     */
    public static function render_user_avatar($userorid, int $size = 35, string $extraclass = '', ?string $fallbackname = null): string {
        global $OUTPUT;

        $profile = self::get_user_profile_data($userorid, $size <= 40 ? 2 : 1);
        $user = $profile['user'];
        $name = $profile['name'] ?: ($fallbackname ?: '');
        $initials = $profile['initials'];
        if ($initials === '?' && $name !== '') {
            $parts = preg_split('/\s+/', trim($name));
            $initials = count($parts) >= 2
                ? strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1))
                : strtoupper(mb_substr($name, 0, 1));
        }

        if ($user && $OUTPUT) {
            try {
                return $OUTPUT->user_picture($user, [
                    'size' => $size,
                    'link' => false,
                    'class' => trim('ba-user-avatar ' . $extraclass),
                ]);
            } catch (\Throwable $e) {
                // Fall back to image tag or initials markup
            }
        }

        if (!empty($profile['profileimageurl'])) {
            return '<img src="' . s($profile['profileimageurl']) . '" class="ba-user-avatar ' . s($extraclass) . '" style="width:' . (int)$size . 'px;height:' . (int)$size . 'px;border-radius:50%;object-fit:cover;" alt="' . s($name) . '">';
        }

        $fontsize = max(10, (int)round($size * 0.42));
        return '<span class="ba-user-avatar ba-avatar-initials ' . s($extraclass) . '" style="display:inline-flex;align-items:center;justify-content:center;width:' . (int)$size . 'px;height:' . (int)$size . 'px;border-radius:50%;font-size:' . $fontsize . 'px;font-weight:700;color:#fff;background:linear-gradient(135deg,#6366f1,#8b5cf6);flex-shrink:0;" title="' . s($name) . '">' . s($initials) . '</span>';
    }

    /**
     * Decode and extract soft skill activities from local_bm_classsection softskillsdata.
     *
     * @param string|null $softskillsdata_raw JSON string from local_bm_classsection.softskillsdata
     * @return array List of normalized activities with planned, actual, status, etc.
     */
    public static function decode_softskills_activities(?string $softskillsdata_raw): array {
        if (empty($softskillsdata_raw)) {
            return [];
        }

        $raw = json_decode($softskillsdata_raw, true);
        if (!is_array($raw) || empty($raw)) {
            return [];
        }

        // Canonical activity definition map: base key => display title
        $canonical_labels = [
            'SS_Induction'        => 'SS Induction',
            'AANCHOR_1'           => 'AANCHOR 1',
            'AANCHOR_2'           => 'AANCHOR 2',
            'Placement_Induction' => 'Placement Induction',
            'LinkedIn_workshop'   => 'LinkedIn workshop',
            'DISHA_Workshop_1'    => 'DISHA Workshop 1',
            'DISHA_Workshop_2'    => 'DISHA Workshop 2',
            'AANCHOR_3'           => 'AANCHOR 3',
            'AANCHOR_4'           => 'AANCHOR 4',
            'Closure_meeting'     => 'Closure meeting',
        ];

        $grouped = [];

        // Parse all keys in $raw case-insensitively
        foreach ($raw as $key => $val) {
            if (!is_scalar($val)) {
                continue;
            }
            $key = trim((string)$key);
            if (preg_match('/^(.+)_(planned|actual)$/i', $key, $m)) {
                $base = $m[1];
                $type = strtolower($m[2]); // 'planned' or 'actual'

                // Match base against canonical labels case-insensitively
                $matched_base = null;
                foreach ($canonical_labels as $canon_k => $canon_lbl) {
                    if (strcasecmp($canon_k, $base) === 0) {
                        $matched_base = $canon_k;
                        break;
                    }
                }
                if (!$matched_base) {
                    $matched_base = $base;
                }

                if (!isset($grouped[$matched_base])) {
                    $grouped[$matched_base] = [
                        'key'     => $matched_base,
                        'label'   => $canonical_labels[$matched_base] ?? ucwords(str_replace(['_', '-'], ' ', $matched_base)),
                        'planned' => 0,
                        'actual'  => 0,
                    ];
                }

                $ts = 0;
                if (is_numeric($val)) {
                    $ts = (int)$val;
                } else if (is_string($val) && trim($val) !== '') {
                    $parsed = strtotime($val);
                    if ($parsed !== false) {
                        $ts = $parsed;
                    }
                }
                $grouped[$matched_base][$type] = $ts;
            }
        }

        // Preserve canonical order first, then any extra keys
        $ordered = [];
        foreach ($canonical_labels as $canon_k => $canon_lbl) {
            if (isset($grouped[$canon_k])) {
                $ordered[] = $grouped[$canon_k];
                unset($grouped[$canon_k]);
            }
        }
        foreach ($grouped as $g) {
            $ordered[] = $g;
        }

        $today_start = strtotime('today midnight');
        $today_end   = $today_start + 86400;

        $results = [];
        foreach ($ordered as $entry) {
            $p = (int)$entry['planned'];
            $a = (int)$entry['actual'];

            if ($a > 0) {
                $status = 'g';
                $label  = 'Completed';
            } else if ($p > 0 && $p < $today_start) {
                $days   = max(1, floor(($today_start - $p) / 86400));
                $status = 'r';
                $label  = "Overdue ({$days}d)";
            } else if ($p >= $today_start && $p < $today_end) {
                $status = 'a';
                $label  = 'Due today';
            } else if ($p > 0) {
                $days   = max(1, floor(($p - $today_start) / 86400));
                $status = 'b';
                $label  = "Upcoming ({$days}d)";
            } else {
                $status = 'n';
                $label  = 'Not scheduled';
            }

            $p_str = $p > 0 ? userdate($p, '%d %b %Y') : '—';
            $a_str = $a > 0 ? userdate($a, '%d %b %Y') : '—';

            $results[] = [
                'key'          => $entry['key'],
                'activity'     => $entry['label'],
                'planned'      => $p,
                'actual'       => $a,
                'p_date'       => $p_str,
                'a_date'       => $a_str,
                'planned_date' => $p_str,
                'actual_date'  => $a_str,
                'status'       => $status,
                'label'        => $label,
            ];
        }

        return $results;
    }
}

