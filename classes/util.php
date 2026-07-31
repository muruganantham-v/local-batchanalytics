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
            $count = (int)$cache->get($key);
            if ($count >= 30) {
                return false;
            }
            $cache->set($key, $count + 1);
            return true;
        } catch (\Exception $e) {
            // Fail open if cache is unavailable
            return true;
        }
    }
}
