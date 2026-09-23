<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Adapts the Course-tab Advanced Filter Gradebook dataset for the compact
 * Student Performance tables on batch and module pages.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

class student_performance_service {
    /**
     * Build dynamic category columns and student values from the Advanced Filter source.
     *
     * @param array $students Display rows keyed or identified by userid.
     * @param int[] $courseids One course for module view; linked courses for batch view.
     * @param int $viewerid Current user, used by the Advanced Filter access checks.
     * @return array{columns: array, students: array}
     */
    public function build(array $students, array $courseids, int $viewerid): array {
        $datasets = [];
        foreach (array_unique(array_filter(array_map('intval', $courseids))) as $courseid) {
            try {
                $data = (new maac_service())->get_course_data($courseid, $viewerid);
                if (!empty($data['module_columns']) && !empty($data['students'])) {
                    $datasets[] = $data;
                }
            } catch (\Throwable $e) {
                // A linked course that is unavailable must not block the remaining courses.
            }
        }

        if (empty($datasets)) {
            return ['columns' => [], 'students' => $students];
        }

        $columns = [];
        $sourcecolumns = [];
        foreach ($datasets as $datasetindex => $dataset) {
            foreach ($dataset['module_columns'] as $sourcecolumn) {
                $label = trim((string)($sourcecolumn['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $isattendance = !empty($sourcecolumn['isattendance']);
                $name = preg_replace('/\s+\(\d+\)$/', '', $label);
                $key = 'category_' . substr(sha1(strtolower($name)), 0, 12);
                if (!isset($columns[$key])) {
                    $columns[$key] = [
                        'key' => $key,
                        'label' => $name,
                        'count' => 0,
                        'isattendance' => $isattendance,
                    ];
                }
                $columns[$key]['count'] += $this->get_activity_count($label, $isattendance);
                $sourcecolumns[$datasetindex][$sourcecolumn['key']] = $key;
            }
        }

        $datasetstudents = [];
        foreach ($datasets as $datasetindex => $dataset) {
            foreach ($dataset['students'] as $student) {
                $datasetstudents[$datasetindex][(int)$student['userid']] = $student;
            }
        }

        foreach ($students as &$student) {
            $userid = (int)($student['userid'] ?? 0);
            $totals = [];
            foreach ($columns as $key => $column) {
                $totals[$key] = ['grade' => 0.0, 'completion' => 0.0, 'weight' => 0];
            }
            foreach ($datasets as $datasetindex => $dataset) {
                $sourcedata = $datasetstudents[$datasetindex][$userid] ?? null;
                if (!$sourcedata) {
                    continue;
                }
                foreach (($sourcecolumns[$datasetindex] ?? []) as $sourcekey => $key) {
                    $metric = $sourcedata['modules'][$sourcekey] ?? null;
                    if (!$metric || !isset($metric['grade']) || $metric['grade'] === null) {
                        continue;
                    }
                    $weight = max(1, $this->get_activity_count(
                        $this->get_source_label($datasets[$datasetindex]['module_columns'], $sourcekey),
                        !empty($columns[$key]['isattendance'])
                    ));
                    $totals[$key]['grade'] += (float)$metric['grade'] * $weight;
                    $totals[$key]['completion'] += (float)($metric['completion'] ?? 0) * $weight;
                    $totals[$key]['weight'] += $weight;
                }
            }

            $student['categories'] = [];
            $overall = [];
            foreach ($columns as $key => $column) {
                $total = $totals[$key];
                $grade = $total['weight'] ? round($total['grade'] / $total['weight'], 2) : null;
                $completion = $total['weight'] ? round($total['completion'] / $total['weight'], 2) : null;
                $student['categories'][$key] = ['grade' => $grade, 'completion' => $completion];
                if ($grade !== null) {
                    $overall[] = $grade;
                }
            }
            $student['grade'] = !empty($overall) ? round(array_sum($overall) / count($overall), 2) : null;
        }
        unset($student);

        foreach ($columns as &$column) {
            if (!$column['isattendance']) {
                $column['label'] .= ' (' . $column['count'] . ')';
            }
        }
        unset($column);

        return ['columns' => array_values($columns), 'students' => $students];
    }

    private function get_activity_count(string $label, bool $isattendance): int {
        if ($isattendance) {
            return 1;
        }
        return preg_match('/\((\d+)\)$/', $label, $matches) ? max(1, (int)$matches[1]) : 1;
    }

    private function get_source_label(array $columns, string $key): string {
        foreach ($columns as $column) {
            if (($column['key'] ?? '') === $key) {
                return (string)($column['label'] ?? '');
            }
        }
        return '';
    }
}
