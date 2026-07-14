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

/**
 * Data access class for local_batchanalytics
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

class moodledata
{

    /**
     * Check system-level full access to the plugin.
     *
     * @param int $userid
     * @return bool
     */
    private function can_manage_all(int $userid): bool {
        $context = \context_system::instance();
        return is_siteadmin($userid) || has_capability('local/batchanalytics:manage', $context, $userid);
    }

    /**
     * Check whether the user can search/view all matching courses.
     *
     * @param int $userid
     * @return bool
     */
    private function can_view_all_courses(int $userid): bool {
        $context = \context_system::instance();
        return $this->can_manage_all($userid) || has_capability('local/batchanalytics:viewallcourses', $context, $userid);
    }

    /**
     * Check whether the user can view an enrolled course through this plugin.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private function can_view_enrolled_course(int $courseid, int $userid): bool {
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        return $context && has_capability('local/batchanalytics:viewenrolledcourses', $context, $userid);
    }
    /**
     * Get allowed course keywords from settings.
     * @return array Array of lowercase keyword strings, empty if no filter configured.
     */
    private function get_allowed_keywords() {
        $config = get_config('local_batchanalytics', 'allowed_course_keywords');
        if (empty($config)) {
            return [];
        }
        return array_filter(array_map(function($k) {
            return strtolower(trim($k));
        }, explode(',', $config)));
    }

    /**
     * Check if a course name matches any of the allowed keywords.
     * @param string $fullname
     * @param array $keywords
     * @return bool
     */
    private function course_matches_keywords($fullname, $keywords) {
        if (empty($keywords)) {
            return true;
        }
        $lower = strtolower($fullname);
        foreach ($keywords as $kw) {
            if (stripos($lower, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build SQL WHERE clause and params for allowed course keywords.
     * @param array $keywords
     * @param string $prefix Unique param prefix to avoid collisions.
     * @return array [$sql_fragment, $params] - empty string if no filter.
     */
    private function build_keywords_sql($keywords, $prefix = 'kw') {
        global $DB;
        if (empty($keywords)) {
            return ['', []];
        }
        $conditions = [];
        $params = [];
        foreach ($keywords as $i => $kw) {
            $paramname = $prefix . $i;
            $conditions[] = $DB->sql_like('fullname', ':' . $paramname, false);
            $params[$paramname] = '%' . $DB->sql_like_escape($kw) . '%';
        }
        return ['AND (' . implode(' OR ', $conditions) . ')', $params];
    }

    /**
     * Extract the batch/group code from a course name or shortname.
     *
     * Examples:
     * - "Advanced C : 25001" => "25001"
     * - "Data Structure : 25001" => "25001"
     *
     * @param array $course
     * @return string
     */
    private function extract_course_group_label(array $course): string {
        $name = strpos($course['fullname'], ':') !== false ? $course['fullname'] : $course['shortname'];
        $parts = array_filter(array_map('trim', explode(':', $name)), function($part) {
            return $part !== '';
        });

        if (empty($parts)) {
            return '';
        }

        return (string) end($parts);
    }

    /**
     * Search courses by keyword with role-based filtering
     *
     * @param string $keyword
     * @param int $userid
     * @return array
     */
    public function search_courses($keyword, $userid)
    {
        global $DB;

        $keyword = trim($keyword);
        if (strlen($keyword) < 1) {
            return [];
        }

        $can_manage = $this->can_view_all_courses((int)$userid);
        $allowed_keywords = $this->get_allowed_keywords();

        if ($can_manage) {
            list($kw_sql, $kw_params) = $this->build_keywords_sql($allowed_keywords, 'skw');

            $sql = "SELECT id, fullname, shortname
                    FROM {course}
                    WHERE (fullname LIKE :keyword1 OR shortname LIKE :keyword2)
                    AND visible = 1
                    AND id > 1
                    $kw_sql
                    ORDER BY fullname";

            $params = [
                'keyword1' => '%' . $DB->sql_like_escape($keyword) . '%',
                'keyword2' => '%' . $DB->sql_like_escape($keyword) . '%'
            ];
            $params = array_merge($params, $kw_params);

            $records = $DB->get_records_sql($sql, $params, 0, 200);
            $courses = [];
            foreach ($records as $record) {
                $courses[] = [
                    'courseid' => (int) $record->id,
                    'fullname' => $record->fullname,
                    'shortname' => $record->shortname
                ];
            }
            return $courses;
        } else {
            $enrolled_courses = enrol_get_users_courses($userid, true, ['id', 'fullname', 'shortname']);
            $courses = [];
            foreach ($enrolled_courses as $course) {
                if ($course->id <= 1) {
                    continue;
                }
                if (!$this->can_view_enrolled_course((int)$course->id, (int)$userid)) {
                    continue;
                }
                if (!$this->course_matches_keywords($course->fullname, $allowed_keywords)) {
                    continue;
                }
                $fullname_match = stripos($course->fullname, $keyword) !== false;
                $shortname_match = stripos($course->shortname, $keyword) !== false;

                if ($fullname_match || $shortname_match) {
                    $courses[] = [
                        'courseid' => (int) $course->id,
                        'fullname' => $course->fullname,
                        'shortname' => $course->shortname
                    ];
                }
            }

            usort($courses, function ($a, $b) {
                return strcmp($a['fullname'], $b['fullname']);
            });

            return array_slice($courses, 0, 200);
        }
    }

    /**
     * Get all unique batch groups the user can access.
     * Extracts batch codes from course names using the "Batch XX:" or shortname pattern.
     *
     * @param int $userid
     * @return array Array of ['code' => string, 'count' => int]
     */
    public function get_all_batches($userid)
    {
        global $DB;

        $can_manage = $this->can_view_all_courses((int)$userid);
        $allowed_keywords = $this->get_allowed_keywords();

        if ($can_manage) {
            list($kw_sql, $kw_params) = $this->build_keywords_sql($allowed_keywords, 'bkw');

            $sql = "SELECT id, fullname, shortname
                    FROM {course}
                    WHERE visible = 1 AND id > 1
                    $kw_sql
                    ORDER BY fullname";
            $records = $DB->get_records_sql($sql, $kw_params);
            $courses = [];
            foreach ($records as $record) {
                $courses[] = [
                    'fullname' => $record->fullname,
                    'shortname' => $record->shortname
                ];
            }
        } else {
            $enrolled_courses = enrol_get_users_courses($userid, true, ['id', 'fullname', 'shortname']);
            $courses = [];
            foreach ($enrolled_courses as $course) {
                if ($course->id <= 1) {
                    continue;
                }
                if (!$this->can_view_enrolled_course((int)$course->id, (int)$userid)) {
                    continue;
                }
                if (!$this->course_matches_keywords($course->fullname, $allowed_keywords)) {
                    continue;
                }
                $courses[] = [
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname
                ];
            }
        }

        // Extract unique batch/group codes from course names.
        $batches = [];
        foreach ($courses as $c) {
            $code = $this->extract_course_group_label($c);
            if ($code === '') {
                continue;
            }
            if (!isset($batches[$code])) {
                $batches[$code] = ['count' => 0, 'searchtext' => ''];
            }
            $batches[$code]['count']++;
            $batches[$code]['searchtext'] .= ' ' . ($c['fullname'] ?? '') . ' ' . ($c['shortname'] ?? '');
        }

        ksort($batches);

        $result = [];
        foreach ($batches as $code => $data) {
            $result[] = [
                'code' => (string) $code,
                'count' => (int) $data['count'],
                'searchtext' => trim($data['searchtext']),
            ];
        }

        return $result;
    }

    /**
     * Get courses by batch code with role-based filtering
     *
     * @param string $batchcode
     * @param int $userid
     * @return array
     */
    public function get_courses_by_batch($batchcode, $userid)
    {
        global $DB;

        $batchcode = trim($batchcode);
        if (strlen($batchcode) < 1) {
            return [];
        }

        $can_manage = $this->can_view_all_courses((int)$userid);
        $allowed_keywords = $this->get_allowed_keywords();

        if ($can_manage) {
            list($kw_sql, $kw_params) = $this->build_keywords_sql($allowed_keywords, 'ckw');

            $sql = "SELECT id, fullname, shortname
                    FROM {course}
                    WHERE (fullname LIKE :batchcode1 OR shortname LIKE :batchcode2)
                    AND visible = 1
                    AND id > 1
                    $kw_sql
                    ORDER BY fullname";

            $params = [
                'batchcode1' => '%' . $DB->sql_like_escape($batchcode) . '%',
                'batchcode2' => '%' . $DB->sql_like_escape($batchcode) . '%'
            ];
            $params = array_merge($params, $kw_params);

            $records = $DB->get_records_sql($sql, $params, 0, 200);
            $courses = [];
            foreach ($records as $record) {
                $course = [
                    'courseid' => (int) $record->id,
                    'fullname' => $record->fullname,
                    'shortname' => $record->shortname
                ];
                if (strcasecmp($this->extract_course_group_label($course), $batchcode) === 0) {
                    $courses[] = $course;
                }
            }
            return $courses;
        } else {
            $enrolled_courses = enrol_get_users_courses($userid, true, ['id', 'fullname', 'shortname']);
            $courses = [];
            foreach ($enrolled_courses as $course) {
                if ($course->id <= 1) {
                    continue;
                }
                if (!$this->can_view_enrolled_course((int)$course->id, (int)$userid)) {
                    continue;
                }
                if (!$this->course_matches_keywords($course->fullname, $allowed_keywords)) {
                    continue;
                }
                $course_data = [
                    'courseid' => (int) $course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname
                ];

                if (strcasecmp($this->extract_course_group_label($course_data), $batchcode) === 0) {
                    $courses[] = $course_data;
                }
            }

            usort($courses, function ($a, $b) {
                return strcmp($a['fullname'], $b['fullname']);
            });

            return array_slice($courses, 0, 200);
        }
    }

    /**
     * Return a course record if the user is allowed to access it through this plugin.
     *
     * @param int $courseid
     * @param int $userid
     * @return \stdClass|null
     */
    public function get_accessible_course(int $courseid, int $userid): ?\stdClass {
        global $DB;

        if ($courseid <= 1 || $userid <= 0) {
            return null;
        }

        $course = $DB->get_record('course', ['id' => $courseid, 'visible' => 1], 'id, fullname, shortname');
        if (!$course) {
            return null;
        }

        $canmanage = $this->can_view_all_courses((int)$userid);
        $allowedkeywords = $this->get_allowed_keywords();

        if (!$this->course_matches_keywords($course->fullname, $allowedkeywords)) {
            return null;
        }

        if ($canmanage) {
            return $course;
        }

        $enrolledcourses = enrol_get_users_courses($userid, true, ['id']);
        return isset($enrolledcourses[$courseid]) && $this->can_view_enrolled_course($courseid, $userid) ? $course : null;
    }

}
