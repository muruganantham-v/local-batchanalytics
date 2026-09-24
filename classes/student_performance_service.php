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
        foreach (array_unique(array_filter(array_map('intval', $courseids))) as $courseid) {
            $this->append_course_custom_values($courseid, $viewerid, $userids, $customgroups, $customvalues, $trenddetails);
            foreach ($this->get_course_categories($courseid, $userids) as $category) {
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

        foreach ($students as &$student) {
            $userid = (int)($student['userid'] ?? 0);
            $student['categories'] = [];
            $overall = [];
            foreach ($columns as $key => $column) {
                $metric = $values[$userid][$key] ?? null;
                $grade = $metric && $metric['maximum'] > 0
                    ? round(($metric['earned'] / $metric['maximum']) * 100, 2)
                    : null;
                if ($grade !== null && !empty($column['ismaac'])) {
                    $grade = round($grade / 10, 1);
                }
                $completion = $metric && $metric['items'] > 0
                    ? round(($metric['completed'] / $metric['items']) * 100, 2)
                    : null;
                $student['categories'][$key] = ['grade' => $grade, 'completion' => $completion];
                if ($grade !== null && empty($column['ismaac'])) {
                    $overall[] = $grade;
                }
            }
            $student['grade'] = !empty($overall) ? round(array_sum($overall) / count($overall), 2) : null;
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
     * Reuse the Advanced Filter MAAC column configuration and formatted values.
     */
    private function append_course_custom_values(int $courseid, int $viewerid, array $userids, array &$groups, array &$values, array &$trenddetails): void {
        try {
            $dataset = (new maac_service())->get_course_data($courseid, $viewerid);
        } catch (\Throwable $e) {
            return;
        }
        foreach (($dataset['column_groups'] ?? []) as $group) {
            $groupname = trim((string)($group['name'] ?? ''));
            if ($groupname === '') {
                continue;
            }
            $groupkey = 'custom_' . substr(sha1(strtolower($groupname)), 0, 12);
            if (!isset($groups[$groupkey])) {
                $groups[$groupkey] = ['key' => $groupkey, 'label' => $groupname, 'columns' => []];
            }
            foreach (($group['columns'] ?? []) as $column) {
                $columnkey = (string)($column['key'] ?? '');
                if ($columnkey === '' || $columnkey === 'maac_rating') {
                    continue;
                }
                $groups[$groupkey]['columns'][$columnkey] = [
                    'key' => $columnkey,
                    'label' => (string)($column['label'] ?? $columnkey),
                    'type' => (string)($column['type'] ?? 'text'),
                ];
            }
        }
        $course = $dataset['course'] ?? [];
        $courselabel = (string)($course['shortname'] ?? $course['fullname'] ?? ('Course ' . $courseid));
        foreach (($dataset['students'] ?? []) as $student) {
            $userid = (int)($student['userid'] ?? 0);
            if (!in_array($userid, $userids, true)) {
                continue;
            }
            if (!empty($student['trend_details']) && is_array($student['trend_details'])) {
                $trenddetails[$userid][] = [
                    'courseid' => $courseid,
                    'course' => $courselabel,
                    'details' => $student['trend_details'],
                ];
            }
            foreach (($student['custom'] ?? []) as $key => $value) {
                if ($key === 'maac_rating' || $value === '' || $value === null) {
                    continue;
                }
                $values[$userid][$key][] = $value;
            }
        }

    }
    /**
     * Mirrors the Course tab Advanced Filter category, grade, and completion calculation.
     *
     * @param int $courseid
     * @param int[] $userids
     * @return array
     */
    private function get_course_categories(int $courseid, array $userids): array {
        global $DB;

        $itemssql = "SELECT gi.id AS itemid, gi.itemtype, gi.grademax, gi.grademin,
                            gc.id AS categoryid, gc.fullname AS categoryname
                       FROM {grade_items} gi
                  LEFT JOIN {grade_categories} gc ON gc.id = gi.categoryid
                      WHERE gi.courseid = :courseid
                        AND (gi.itemtype IN ('mod', 'manual') OR gi.itemtype = 'course')
                        AND gi.hidden = 0
                   ORDER BY gc.fullname, gi.itemname";
        $gradeitems = $DB->get_records_sql($itemssql, ['courseid' => $courseid]);
        $allcategories = $DB->get_records('grade_categories', ['courseid' => $courseid]);
        $categories = [];
        foreach ($gradeitems as $item) {
            if ($item->itemtype === 'course') {
                $name = 'MAAC Ratings';
            } else {
                $name = 'Uncategorized';
                if (!empty($item->categoryid) && isset($allcategories[$item->categoryid])) {
                    $category = $allcategories[$item->categoryid];
                    while ($category->depth > 2 && !empty($category->parent) && isset($allcategories[$category->parent])) {
                        $category = $allcategories[$category->parent];
                    }
                    if ($category->depth == 2) {
                        $name = $category->fullname;
                    }
                }
            }
            if (trim($name) === '' || $name === '?' || $name === 'Uncategorized') {
                continue;
            }
            if (!isset($categories[$name])) {
                $categories[$name] = [
                    'name' => $name,
                    'isattendance' => stripos($name, 'attend') !== false,
                    'items' => [],
                ];
            }
            $categories[$name]['items'][] = [
                'itemid' => (int)$item->itemid,
                'grademin' => (float)$item->grademin,
                'grademax' => (float)$item->grademax,
            ];
        }
        if (empty($categories)) {
            return [];
        }

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
}