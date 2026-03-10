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
        if (strlen($keyword) < 2) {
            return [];
        }

        $is_admin = is_siteadmin($userid);
        $context = \context_system::instance();
        $has_manager_cap = has_capability('moodle/site:config', $context, $userid);

        $user_roles = get_user_roles($context, $userid, true);
        $role_names = [];
        foreach ($user_roles as $role) {
            $role_names[] = $role->shortname;
        }

        $is_manager = in_array('manager', $role_names) || $has_manager_cap;

        if ($is_admin || $is_manager) {
            $sql = "SELECT id, fullname, shortname
                    FROM {course}
                    WHERE (fullname LIKE :keyword1 OR shortname LIKE :keyword2)
                    AND visible = 1
                    AND id > 1
                    ORDER BY fullname
                    LIMIT 200";

            $params = [
                'keyword1' => '%' . $DB->sql_like_escape($keyword) . '%',
                'keyword2' => '%' . $DB->sql_like_escape($keyword) . '%'
            ];

            $records = $DB->get_records_sql($sql, $params);
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

        $is_admin = is_siteadmin($userid);
        $context = \context_system::instance();
        $has_manager_cap = has_capability('moodle/site:config', $context, $userid);

        $user_roles = get_user_roles($context, $userid, true);
        $role_names = [];
        foreach ($user_roles as $role) {
            $role_names[] = $role->shortname;
        }

        $is_manager = in_array('manager', $role_names) || $has_manager_cap;

        if ($is_admin || $is_manager) {
            $sql = "SELECT id, fullname, shortname
                    FROM {course}
                    WHERE (fullname LIKE :batchcode1 OR shortname LIKE :batchcode2)
                    AND visible = 1
                    AND id > 1
                    ORDER BY fullname
                    LIMIT 200";

            $params = [
                'batchcode1' => '%' . $DB->sql_like_escape($batchcode) . '%',
                'batchcode2' => '%' . $DB->sql_like_escape($batchcode) . '%'
            ];

            $records = $DB->get_records_sql($sql, $params);
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
                $fullname_match = stripos($course->fullname, $batchcode) !== false;
                $shortname_match = stripos($course->shortname, $batchcode) !== false;

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
    public static function get_course_gradebook_raw($courseid)
    {
        global $DB;

        $sql = "
        SELECT 
            u.id AS userid,
            u.username,
            u.firstname,
            u.lastname,
            gc.fullname AS categoryname,
            gi.id AS gradeitemid,
            gi.itemname AS gradeitemname,
            gg.finalgrade,
            gi.grademax
        FROM {grade_items} gi
        JOIN {grade_categories} gc ON gc.id = gi.categoryid
        JOIN {grade_grades} gg ON gg.itemid = gi.id
        JOIN {user} u ON u.id = gg.userid
        WHERE gi.courseid = :courseid
          AND gi.itemtype = 'mod'
    ";

        return $DB->get_records_sql($sql, [
            'courseid' => $courseid
        ]);
    }


    public function get_student_username($userid)
    {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], 'username');
        return $user ? $user->username : null;
    }

}