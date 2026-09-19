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
}

