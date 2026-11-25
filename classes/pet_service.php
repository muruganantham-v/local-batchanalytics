<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Service for Placement Eligibility Test (PET) telemetry and certification tracking.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pet_service {

    const TABLE = 'local_batchanalytics_pet';

    /**
     * Get batch-level PET summary stats.
     * Sourced dynamically through pet_qualification_service using live Moodle PET course quizzes & groups,
     * Zoho CRM data, or local_batchanalytics_pet persistent table.
     *
     * @param int $batchid
     * @param int $sectionid
     * @param array $students
     * @return array
     */
    public static function get_batch_pet_summary(int $batchid, int $sectionid = 0, array $students = []): array {
        global $DB;

        $target_sectionid = $sectionid > 0 ? $sectionid : $batchid;
        $batch_name = '';

        if ($target_sectionid > 0 && $DB->get_manager()->table_exists('local_bm_classsection')) {
            $sec = $DB->get_record('local_bm_classsection', ['id' => $target_sectionid]);
            if (!$sec) {
                $secs = $DB->get_records('local_bm_classsection', ['batchid' => $target_sectionid], 'id ASC', '*', 0, 1);
                if (!empty($secs)) {
                    $sec = reset($secs);
                }
            }
            if ($sec && !empty($sec->name)) {
                $batch_name = $sec->name;
            }
        }

        if ($batch_name === '' && $target_sectionid > 0 && $DB->get_manager()->table_exists('local_bm_batch')) {
            $b = $DB->get_record('local_bm_batch', ['id' => $target_sectionid]);
            if ($b && !empty($b->batchname)) {
                $batch_name = $b->batchname;
            }
        }

        $stats = pet_qualification_service::get_batch_pet_stats($batch_name, $target_sectionid, $students);

        return [
            'pet_conducted'  => !empty($stats['pet_conducted']),
            'qualified_pct'  => number_format($stats['qualified_pct'], 1) . '%',
            'total_students' => (int)$stats['total_students'],
            'pet_cleared'    => (int)$stats['pet_cleared'],
            'first_attempt'  => (int)$stats['first_attempt_cleared'],
            'second_attempt' => (int)$stats['second_attempt_cleared'],
            'third_attempt'  => (int)$stats['third_attempt_cleared'],
            'not_appeared'   => (int)$stats['not_appeared'],
        ];
    }

    /**
     * Get student PET status badge.
     *
     * @param int $userid
     * @param int $batchid
     * @return array
     */
    public static function get_student_pet_status(int $userid, int $batchid = 0): array {
        global $DB;

        // 1. Try from live PET course
        $course_status = pet_qualification_service::get_student_pet_status_from_course($userid);
        if ($course_status !== null) {
            return $course_status;
        }

        // 2. Try persistent table local_batchanalytics_pet
        if ($DB->get_manager()->table_exists(self::TABLE)) {
            $record = $DB->get_record(self::TABLE, ['userid' => $userid]);
            if ($record) {
                return [
                    'status'  => $record->status,
                    'attempt' => (int)$record->attempt_number,
                    'badge'   => $record->status . ' (' . self::ordinal((int)$record->attempt_number) . ' attempt)',
                ];
            }
        }

        // If prototype mock user (id <= 0), provide demo benchmark badge
        if ($userid <= 0) {
            return [
                'status'  => 'Cleared',
                'attempt' => 1,
                'badge'   => 'Cleared (1st attempt)',
            ];
        }

        return [
            'status'  => 'Not appeared',
            'attempt' => 1,
            'badge'   => 'Not appeared',
        ];
    }

    /**
     * Helper to format numbers with ordinal suffixes.
     *
     * @param int $num
     * @return string
     */
    protected static function ordinal(int $num): string {
        $ends = ['th','st','nd','rd','th','th','th','th','th','th'];
        if ((($num % 100) >= 11) && (($num % 100) <= 13)) {
            return $num . 'th';
        }
        return $num . $ends[$num % 10];
    }
}
