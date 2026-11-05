<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Builds compact Student Performance data with the Course tab Advanced Filter
 * Gradebook calculation and category rules.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

class student_performance_service {
    /**
     * @param array $students Display rows identified by userid.
     * @param int[] $courseids One course for module view; linked courses for batch view.
     * @param int $viewerid Retained for the existing caller contract.
     * @return array{columns: array, students: array}
     */
    public function build(array $students, array $courseids, int $viewerid): array {
        $userids = [];
        foreach ($students as $student) {
            $userid = (int)($student['userid'] ?? 0);
            if ($userid > 0) {
                $userids[] = $userid;
            }
        }
        $userids = array_values(array_unique($userids));
        if (empty($userids)) {
            return ['columns' => [], 'students' => $students];
        }

        $columns = [];
        $values = [];
        $customgroups = [];
        $customvalues = [];
        $trenddetails = [];
        $course_totals = [];
        foreach (array_unique(array_filter(array_map('intval', $courseids))) as $courseid) {
            foreach ($this->get_course_categories($courseid, $userids, $course_totals) as $category) {
                $name = $category['name'];
                $key = 'category_' . substr(sha1(strtolower($name)), 0, 12);
                if (!isset($columns[$key])) {
                    $columns[$key] = [
                        'key' => $key,
                        'label' => $name,
                        'count' => 0,
                        'isattendance' => $category['isattendance'],
                        'ismaac' => $name === 'MAAC Ratings',
                    ];
                }
                $columns[$key]['count'] += $category['itemcount'];
                foreach ($category['students'] as $userid => $metric) {
                    if (!isset($values[$userid][$key])) {
                        $values[$userid][$key] = ['earned' => 0.0, 'maximum' => 0.0, 'completed' => 0, 'items' => 0];
                    }
                    $values[$userid][$key]['earned'] += $metric['earned'];
                    $values[$userid][$key]['maximum'] += $metric['maximum'];
                    $values[$userid][$key]['completed'] += $metric['completed'];
                    $values[$userid][$key]['items'] += $metric['items'];
                }
            }
        }

        // Reorder columns: configured categories in defined order, then others, and MAAC Ratings always last
        $ordered_keys = [];
        $configured_cats = (new activity_tracker_service())->get_configured_categories();
        $conf_names = array_keys($configured_cats);
        foreach ($conf_names as $cname) {
            $cname_clean = strtolower(trim($cname));
            foreach ($columns as $ckey => $col) {
                if (in_array($ckey, $ordered_keys, true) || !empty($col['ismaac'])) {
                    continue;
                }
                $label_clean = strtolower(trim($col['label']));
                if ($label_clean === $cname_clean
                    || rtrim($label_clean, 's') === rtrim($cname_clean, 's')
                    || stripos($label_clean, $cname_clean) !== false
                    || stripos($cname_clean, $label_clean) !== false) {
                    $ordered_keys[] = $ckey;
                    break;
                }
            }
        }
        foreach (array_keys($columns) as $ckey) {
            if (!in_array($ckey, $ordered_keys, true) && empty($columns[$ckey]['ismaac'])) {
                $ordered_keys[] = $ckey;
            }
        }
        foreach (array_keys($columns) as $ckey) {
            if (!empty($columns[$ckey]['ismaac'])) {
                $ordered_keys[] = $ckey;
            }
        }
        $reordered_columns = [];
        foreach ($ordered_keys as $ckey) {
            $reordered_columns[$ckey] = $columns[$ckey];
        }
        $columns = $reordered_columns;
        $student_attendance = $this->get_students_attendance($courseids, $userids);

        foreach ($students as &$student) {
            $userid = (int)($student['userid'] ?? 0);
            $student['attendance'] = isset($student_attendance[$userid]) ? $student_attendance[$userid] : null;
            $student['categories'] = [];
            $overall = [];
            $total_completed = 0;
            $total_items = 0;
            foreach ($columns as $key => $column) {
                $metric = $values[$userid][$key] ?? null;
                $grade = $metric && $metric['maximum'] > 0
                    ? round(($metric['earned'] / $metric['maximum']) * 100, 2)
                    : null;
                if (!empty($column['ismaac'])) {
                    // MAAC rating is based only on grade: ((grade / 100) * 9) and does not toggle
                    if ($grade !== null) {
                        $grade = round(($grade / 100) * 9, 2);
                    }
                    $completion = $grade;
                } else {
                    $completion = $metric && $metric['items'] > 0
                        ? round(($metric['completed'] / $metric['items']) * 100, 2)
                        : null;
                }
                $student['categories'][$key] = [
                    'grade' => $grade,
                    'completion' => $completion,
                    'completed' => $metric ? (int)($metric['completed'] ?? 0) : 0,
                    'items' => $metric ? (int)($metric['items'] ?? 0) : 0,
                ];
                if ($grade !== null && empty($column['ismaac']) && empty($column['isattendance'])) {
                    $overall[] = $grade;
                }
                if (empty($column['ismaac']) && empty($column['isattendance']) && !empty($metric['items'])) {
                    $total_completed += (int)($metric['completed'] ?? 0);
                    $total_items += (int)$metric['items'];
                }
            }
            // Use Moodle Course Total grade if available; fallback to category average
            if (isset($course_totals[$userid]) && $course_totals[$userid]['maximum'] > 0) {
                $student['grade'] = round(($course_totals[$userid]['earned'] / $course_totals[$userid]['maximum']) * 100, 2);
            } else {
                $student['grade'] = !empty($overall) ? round(array_sum($overall) / count($overall), 2) : null;
            }

            // Ensure MAAC rating uses student's average grade if course total grade was null
            foreach ($columns as $key => $column) {
                if (!empty($column['ismaac'])) {
                    if ($student['categories'][$key]['grade'] === null && $student['grade'] !== null) {
                        $maac_rating = round(($student['grade'] / 100) * 9, 2);
                        $student['categories'][$key]['grade'] = $maac_rating;
                        $student['categories'][$key]['completion'] = $maac_rating;
                    }
                }
            }
            // Overall activity completion percentage across all module activities
            $student['completion'] = $total_items > 0
                ? round(($total_completed / $total_items) * 100, 2)
                : 0.0;
            $student['completion_pct'] = $student['completion'];
            $student['completed_items'] = $total_completed;
            $student['total_items'] = $total_items;
            $student['custom'] = [];
            foreach ($customgroups as $group) {
                foreach ($group['columns'] as $column) {
                    $key = $column['key'];
                    $rawvals = $customvalues[$userid][$key] ?? [];
                    $isboolean = ($column['type'] ?? '') === 'boolean'
                        || ($column['datatype'] ?? '') === 'checkbox';
                    if (empty($rawvals)) {
                        $student['custom'][$key] = $isboolean ? 0 : '';
                        continue;
                    }
                    if ($key === 'trend') {
                        $student['custom'][$key] = end($rawvals);
                        continue;
                    }
                    if ($isboolean) {
                        $istrue = false;
                        foreach ($rawvals as $val) {
                            if (is_array($val)) {
                                foreach ($val as $subval) {
                                    if ($subval === 1 || $subval === '1' || $subval === true || $subval === 'true' || strtolower((string)$subval) === 'yes') {
                                        $istrue = true;
                                        break 2;
                                    }
                                }
                            } else if ($val === 1 || $val === '1' || $val === true || $val === 'true' || strtolower((string)$val) === 'yes') {
                                $istrue = true;
                                break;
                            }
                        }
                        $student['custom'][$key] = $istrue ? 1 : 0;
                        continue;
                    }
                    if (($column['type'] ?? '') === 'number' || ($column['type'] ?? '') === 'formula') {
                        $numericvals = [];
                        foreach ($rawvals as $val) {
                            if (is_numeric($val)) {
                                $numericvals[] = (float)$val;
                            }
                        }
                        if (!empty($numericvals)) {
                            $avg = round(array_sum($numericvals) / count($numericvals), 2);
                            $student['custom'][$key] = $avg;
                        } else {
                            $student['custom'][$key] = '';
                        }
                        continue;
                    }
                    $flattened = [];
                    foreach ($rawvals as $val) {
                        if (is_array($val)) {
                            foreach ($val as $sub) {
                                $flattened[] = $sub;
                            }
                        } else if (is_string($val)) {
                            $trimmed = trim($val);
                            if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
                                $decoded = json_decode($trimmed, true);
                                if (is_array($decoded)) {
                                    foreach ($decoded as $sub) {
                                        $flattened[] = $sub;
                                    }
                                } else {
                                    $flattened[] = $trimmed;
                                }
                            } else {
                                $flattened[] = $trimmed;
                            }
                        } else {
                            $flattened[] = $val;
                        }
                    }
                    if (!empty($flattened) && is_string($flattened[0])) {
                        $flattened = array_values(array_unique($flattened));
                    }
                    $student['custom'][$key] = !empty($flattened) ? $flattened : '';
                }
            }
            $student['trend_details'] = $trenddetails[$userid] ?? [];
        }
        unset($student);

        foreach ($columns as &$column) {
            if (!$column['isattendance'] && empty($column['ismaac'])) {
                $column['label'] .= ' (' . $column['count'] . ')';
            }
        }
        unset($column);

        $customgroups = array_values($customgroups);
        foreach ($customgroups as &$customgroup) {
            $customgroup['columns'] = array_values($customgroup['columns']);
        }
        unset($customgroup);

        return [
            'columns' => array_values($columns),
            'customgroups' => $customgroups,
            'students' => $students,
        ];
    }

    /**
     * Mirrors the Course tab Advanced Filter category, grade, and completion calculation.
     * Uses Moodle Gradebook native categories and aggregated grades for 100% parity.
     *
     * @param int $courseid
     * @param int[] $userids
     * @param array $course_totals [userid => ['earned' => float, 'maximum' => float]]
     * @return array
     */
    private function get_course_categories(int $courseid, array $userids, array &$course_totals = []): array {
        global $DB;

        $allcategories = $DB->get_records('grade_categories', ['courseid' => $courseid]);
        if (empty($allcategories)) {
            return [];
        }

        // Fetch top-level categories under course root (depth = 2) ordered by grade_item sortorder
        $sql_top = "
            SELECT gc.id, gc.fullname, gi.id AS gradeitemid, gi.grademax, gi.grademin, gi.sortorder
              FROM {grade_categories} gc
              JOIN {grade_items} gi ON gi.iteminstance = gc.id AND gi.itemtype = 'category'
             WHERE gc.courseid = :courseid AND gc.depth = 2
          ORDER BY gi.sortorder ASC, gc.id ASC
        ";
        $topcats = $DB->get_records_sql($sql_top, ['courseid' => $courseid]);

        // If top-level categories exist, use Gradebook-First aggregation
        if (!empty($topcats)) {
            // Find all descendant category IDs for each top category
            $cat_descendants = [];
            foreach ($topcats as $tc) {
                $descendants = [(int)$tc->id];
                foreach ($allcategories as $c) {
                    $path_parts = array_map('intval', array_filter(explode('/', trim((string)$c->path, '/'))));
                    if (in_array((int)$tc->id, $path_parts, true)) {
                        $descendants[] = (int)$c->id;
                    }
                }
                $cat_descendants[$tc->id] = array_values(array_unique($descendants));
            }

            // Fetch all gradable activity items in the course (mod, manual)
            $act_items_sql = "
                SELECT gi.id, gi.categoryid, gi.grademax, gi.grademin
                  FROM {grade_items} gi
                 WHERE gi.courseid = :courseid
                   AND gi.itemtype IN ('mod', 'manual')
            ";
            $all_act_items = $DB->get_records_sql($act_items_sql, ['courseid' => $courseid]);

            $topcat_items = [];
            foreach ($topcats as $tc) {
                $topcat_items[$tc->id] = [];
            }
            foreach ($all_act_items as $item) {
                foreach ($cat_descendants as $tcid => $desc_ids) {
                    if (in_array((int)$item->categoryid, $desc_ids, true)) {
                        $topcat_items[$tcid][] = $item;
                        break;
                    }
                }
            }

            // Fetch category grades
            list($usersql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
            $params['courseid'] = $courseid;

            $cat_item_ids = array_map(function($tc) { return (int)$tc->gradeitemid; }, $topcats);
            list($cat_gi_sql, $cat_gi_params) = $DB->get_in_or_equal($cat_item_ids, SQL_PARAMS_NAMED, 'catgi');
            $all_params = array_merge($params, $cat_gi_params);

            $cat_grades_sql = "
                SELECT gg.userid, gg.itemid, gg.finalgrade
                  FROM {grade_grades} gg
                 WHERE gg.itemid $cat_gi_sql
                   AND gg.userid $usersql
                   AND gg.finalgrade IS NOT NULL
                   AND gg.hidden = 0
            ";
            $cat_grades_rs = $DB->get_recordset_sql($cat_grades_sql, $all_params);
            $user_cat_grades = [];
            foreach ($cat_grades_rs as $row) {
                $user_cat_grades[(int)$row->userid][(int)$row->itemid] = (float)$row->finalgrade;
            }
            $cat_grades_rs->close();

            // Fetch activity grades to count completed items per user
            $act_item_ids = array_keys($all_act_items);
            $user_act_completed = [];
            if (!empty($act_item_ids)) {
                list($act_gi_sql, $act_gi_params) = $DB->get_in_or_equal($act_item_ids, SQL_PARAMS_NAMED, 'actgi');
                $act_params = array_merge($params, $act_gi_params);
                $act_grades_sql = "
                    SELECT gg.userid, gg.itemid
                      FROM {grade_grades} gg
                     WHERE gg.itemid $act_gi_sql
                       AND gg.userid $usersql
                       AND gg.finalgrade IS NOT NULL
                       AND gg.hidden = 0
                ";
                $act_grades_rs = $DB->get_recordset_sql($act_grades_sql, $act_params);
                foreach ($act_grades_rs as $row) {
                    $user_act_completed[(int)$row->userid][(int)$row->itemid] = true;
                }
                $act_grades_rs->close();
            }

            // Build categories structure
            $categories = [];
            foreach ($topcats as $tc) {
                $cname = trim($tc->fullname);
                if (stripos($cname, 'attend') !== false) {
                    continue; // Attendance has dedicated column
                }

                $items_in_cat = $topcat_items[$tc->id] ?? [];
                $itemcount = count($items_in_cat);
                $grademax = (float)$tc->grademax;
                $grademin = (float)$tc->grademin;
                $range = $grademax - $grademin;

                $student_metrics = [];
                foreach ($userids as $userid) {
                    $has_grade = isset($user_cat_grades[$userid][$tc->gradeitemid]);
                    $finalgrade = $has_grade ? $user_cat_grades[$userid][$tc->gradeitemid] : null;

                    $completed_count = 0;
                    foreach ($items_in_cat as $it) {
                        if (!empty($user_act_completed[$userid][$it->id])) {
                            $completed_count++;
                        }
                    }

                    if ($has_grade && $range > 0) {
                        $student_metrics[$userid] = [
                            'earned' => $finalgrade - $grademin,
                            'maximum' => $range,
                            'completed' => $completed_count,
                            'items' => $itemcount,
                        ];
                    } else {
                        $student_metrics[$userid] = [
                            'earned' => 0.0,
                            'maximum' => 0.0,
                            'completed' => $completed_count,
                            'items' => $itemcount,
                        ];
                    }
                }

                $categories[$cname] = [
                    'name' => $cname,
                    'isattendance' => false,
                    'ismaac' => false,
                    'itemcount' => $itemcount,
                    'students' => $student_metrics,
                ];
            }

            // Add Course Total for Course Grade and MAAC Ratings
            $course_gi = $DB->get_record('grade_items', ['courseid' => $courseid, 'itemtype' => 'course']);
            if ($course_gi) {
                $course_range = (float)$course_gi->grademax - (float)$course_gi->grademin;
                $c_grades_sql = "
                    SELECT gg.userid, gg.finalgrade
                      FROM {grade_grades} gg
                     WHERE gg.itemid = :itemid
                       AND gg.userid $usersql
                       AND gg.finalgrade IS NOT NULL
                       AND gg.hidden = 0
                ";
                $c_rs = $DB->get_recordset_sql($c_grades_sql, ['itemid' => $course_gi->id] + $params);
                $user_course_grades = [];
                foreach ($c_rs as $r) {
                    $user_course_grades[(int)$r->userid] = (float)$r->finalgrade;
                }
                $c_rs->close();

                $maac_metrics = [];
                foreach ($userids as $userid) {
                    $has_c = isset($user_course_grades[$userid]);
                    $cg = $has_c ? $user_course_grades[$userid] : null;
                    if ($has_c && $course_range > 0) {
                        $earned = $cg - (float)$course_gi->grademin;
                        $maac_metrics[$userid] = [
                            'earned' => $earned,
                            'maximum' => $course_range,
                            'completed' => 1,
                            'items' => 1,
                        ];
                        if (!isset($course_totals[$userid])) {
                            $course_totals[$userid] = ['earned' => 0.0, 'maximum' => 0.0];
                        }
                        $course_totals[$userid]['earned'] += $earned;
                        $course_totals[$userid]['maximum'] += $course_range;
                    } else {
                        $maac_metrics[$userid] = [
                            'earned' => 0.0,
                            'maximum' => 0.0,
                            'completed' => 0,
                            'items' => 1,
                        ];
                    }
                }

                $categories['MAAC Ratings'] = [
                    'name' => 'MAAC Ratings',
                    'isattendance' => false,
                    'ismaac' => true,
                    'itemcount' => 1,
                    'students' => $maac_metrics,
                ];
            }

            return $categories;
        }

        // Fallback for courses without top-level grade categories:
        $act_service = new activity_tracker_service();
        $configured_categories = $act_service->get_configured_categories();

        $itemssql = "SELECT gi.id AS itemid, gi.itemtype, gi.itemmodule, gi.itemname, gi.grademax, gi.grademin,
                            gc.id AS categoryid, gc.fullname AS categoryname
                       FROM {grade_items} gi
                  LEFT JOIN {grade_categories} gc ON gc.id = gi.categoryid
                      WHERE gi.courseid = :courseid
                        AND (gi.itemtype IN ('mod', 'manual') OR gi.itemtype = 'course')
                        AND gi.hidden = 0
                   ORDER BY gi.sortorder ASC, gi.id ASC";
        $gradeitems = $DB->get_records_sql($itemssql, ['courseid' => $courseid]);

        $categories = [];
        foreach (array_keys($configured_categories) as $cname) {
            $categories[$cname] = [
                'name' => $cname,
                'ismaac' => false,
                'isattendance' => false,
                'items' => [],
            ];
        }

        $maac_category = null;
        foreach ($gradeitems as $item) {
            if ($item->itemtype === 'course') {
                if ($maac_category === null) {
                    $maac_category = [
                        'name' => 'MAAC Ratings',
                        'ismaac' => true,
                        'isattendance' => false,
                        'items' => [],
                    ];
                }
                $maac_category['items'][] = [
                    'itemid' => (int)$item->itemid,
                    'grademin' => (float)$item->grademin,
                    'grademax' => (float)$item->grademax,
                ];
                continue;
            }

            $matched = $act_service->resolve_grade_item_category($item, $allcategories);
            if ($matched !== null && isset($categories[$matched])) {
                $categories[$matched]['items'][] = [
                    'itemid' => (int)$item->itemid,
                    'grademin' => (float)$item->grademin,
                    'grademax' => (float)$item->grademax,
                ];
            }
        }

        $active_categories = array_filter($categories, static function(array $cat): bool {
            return !empty($cat['items']);
        });

        if ($maac_category !== null && !empty($maac_category['items'])) {
            $active_categories['MAAC Ratings'] = $maac_category;
        }

        if (empty($active_categories)) {
            return [];
        }

        $categories = $active_categories;

        list($usersql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'performanceuser');
        $params['courseid'] = $courseid;
        $gradessql = "SELECT gg.userid, gg.itemid, gg.finalgrade
                        FROM {grade_grades} gg
                        JOIN {grade_items} gi ON gi.id = gg.itemid
                       WHERE gi.courseid = :courseid
                         AND gi.hidden = 0
                         AND gg.finalgrade IS NOT NULL
                         AND gg.excluded = 0
                         AND gg.hidden = 0
                         AND gg.userid $usersql";
        $grades = [];
        $recordset = $DB->get_recordset_sql($gradessql, $params);
        foreach ($recordset as $record) {
            $grades[(int)$record->userid][(int)$record->itemid] = (float)$record->finalgrade;
        }
        $recordset->close();

        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            return [];
        }
        $enrolled_users = get_enrolled_users($coursecontext, '', 0, 'u.id');
        $enrolled_map = array_fill_keys(array_map('intval', array_keys($enrolled_users)), true);

        foreach ($categories as &$category) {
            $category['itemcount'] = 0;
            $category['students'] = [];
            $maximum = 0.0;
            foreach ($category['items'] as $item) {
                $range = $item['grademax'] - $item['grademin'];
                if ($range > 0) {
                    $category['itemcount']++;
                    $maximum += $range;
                }
            }
            foreach ($userids as $userid) {
                if (empty($enrolled_map[$userid]) && empty($grades[$userid])) {
                    continue;
                }
                $earned = 0.0;
                $completed = 0;
                foreach ($category['items'] as $item) {
                    $itemid = $item['itemid'];
                    if (!isset($grades[$userid][$itemid])) {
                        continue;
                    }
                    $range = $item['grademax'] - $item['grademin'];
                    if ($range <= 0) {
                        continue;
                    }
                    $earned += $grades[$userid][$itemid] - $item['grademin'];
                    $completed++;
                }
                $category['students'][$userid] = [
                    'earned' => $earned,
                    'maximum' => $maximum,
                    'completed' => $completed,
                    'items' => $category['itemcount'],
                ];
            }
        }
        unset($category);

        return array_values($categories);
    }

    /**
     * Fetch attendance percentage for each user across the given courses.
     *
     * @param int[] $courseids
     * @param int[] $userids
     * @return array<int, float> [userid => attendance_percentage]
     */
    private function get_students_attendance(array $courseids, array $userids): array {
        global $DB;

        $valid_courseids = array_unique(array_filter(array_map('intval', $courseids)));
        if (empty($valid_courseids) || empty($userids)) {
            return [];
        }

        list($cin_sql, $cparams) = $DB->get_in_or_equal($valid_courseids, SQL_PARAMS_NAMED, 'attcourse');
        list($uin_sql, $uparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'attuser');
        $attparams = array_merge($cparams, $uparams);

        $attlike = $DB->sql_like('gi.itemname', ':attlike', false);
        $attparams['attlike'] = '%attend%';

        $attsql = "
            SELECT gg.userid, gg.finalgrade, gi.grademin, gi.grademax
              FROM {grade_items} gi
              JOIN {grade_grades} gg ON gg.itemid = gi.id
             WHERE gi.courseid $cin_sql
               AND (gi.itemmodule = 'attendance' OR $attlike)
               AND gi.hidden = 0
               AND gg.finalgrade IS NOT NULL
               AND gg.excluded = 0
               AND gg.hidden = 0
               AND gg.userid $uin_sql
        ";

        $attrecords = $DB->get_recordset_sql($attsql, $attparams);
        $user_atts = [];
        foreach ($attrecords as $rec) {
            $range = (float)$rec->grademax - (float)$rec->grademin;
            if ($range > 0) {
                $pct = (((float)$rec->finalgrade - (float)$rec->grademin) / $range) * 100;
                $user_atts[(int)$rec->userid][] = max(0.0, min(100.0, $pct));
            }
        }
        $attrecords->close();

        $result = [];
        foreach ($user_atts as $uid => $pct_list) {
            if (!empty($pct_list)) {
                $result[$uid] = round(array_sum($pct_list) / count($pct_list), 2);
            }
        }

        return $result;
    }
}