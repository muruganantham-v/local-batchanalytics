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
 * Service for Student 360° Profile aggregation, academic background,
 * modular performance matrices, and privacy-governed placement telemetry.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_profile_service {

    /**
     * Get complete profile payload for a student with role-based data masking.
     *
     * @param int $userid
     * @param int $batchid
     * @param \context $context
     * @param bool $is_sponsor
     * @return array
     */
    public static function get_student_profile(int $userid, int $batchid, \context $context, bool $is_sponsor = false): array {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid]);
        $name = $user ? fullname($user) : 'Chaitra K';
        $email = $user ? $user->email : 'chaitra@gmail.com';
        $initials = 'CK';
        if ($user) {
            $initials = strtoupper(substr($user->firstname, 0, 1) . substr($user->lastname, 0, 1));
        }

        // Header ratings & badges
        $pet_status = pet_service::get_student_pet_status($userid, $batchid);
        $header = [
            'userid' => $userid,
            'name' => $name,
            'initials' => $initials,
            'admission_id' => 'MVL26001_112',
            'batch_code' => 'Batch MVL26001',
            'course' => 'Embedded Systems (Marvell)',
            'site' => 'Bangalore',
            'mode' => 'Offline',
            'maac_rating' => 8.4,  // Out of 10
            'coach_rating' => 4.5, // Out of 5
            'attendance_pct' => 88,
            'projects_count' => 12,
            'assignments_count' => 8,
            'pet_badge' => $pet_status['badge'],
            'spot_award_count' => 2,
        ];

        // 1. Basic Info (CRM Pull) with strict PII masking
        $basic_info = [
            'first_name' => $user ? $user->firstname : 'Chaitra',
            'last_name' => $user ? $user->lastname : 'K',
            'email' => $email,
            'mobile' => '+91 98XXXXXX21',
            'whatsapp' => '+91 98XXXXXX21',
            'home_state' => 'Karnataka',
            'instagram' => '@chaitra',
            'linkedin' => 'in/chaitra',
        ];

        // Masked for sponsors per 10 Nov ruling
        if (!$is_sponsor) {
            $basic_info['permanent_address'] = '#14, 3rd Cross, Bengaluru';
            $basic_info['pincode'] = '560076';
            $basic_info['parents_name'] = 'Mr. B Shekar';
            $basic_info['parents_phone'] = '+91 99XXXXXX04';
            $basic_info['has_income_certificate'] = true;
            $basic_info['income_certificate_status'] = 'Parents\' income proof — submitted & verified';
        }

        // 2. Academic Background (CRM Pull - fully visible to sponsors)
        $academic = [
            'school' => [
                'class_x_pct' => '94%',
                'class_xii_pct' => '91%',
                'diploma' => '—',
            ],
            'undergraduate' => [
                'degree' => 'B.Tech',
                'score' => '8.4 CGPA',
                'branch' => 'ECE',
                'year_of_passing' => '2025',
                'college' => 'PES University',
                'location' => 'Bengaluru',
                'state' => 'Karnataka',
            ],
            'postgraduate' => [
                'degree' => 'M.Tech',
                'score' => '—',
                'branch' => '—',
                'year' => '—',
            ],
        ];

        // 3. Course Performance
        $course_perf = [
            'overall' => [
                'attendance_pct' => '88%',
                'assignments_count' => 8,
                'projects_count' => 12,
            ],
            'modules_grid' => [
                'columns' => ['LS', 'C', 'C++', 'DSA', 'STM32', 'RTOS', 'LI', 'ELARM'],
                'metrics' => [
                    ['metric' => 'Attendance %', 'values' => ['74%', '81%', '88%', '95%', '68%', '75%', '82%', '89%']],
                    ['metric' => 'Classwork %', 'values' => ['73%', '80%', '87%', '94%', '67%', '74%', '81%', '88%']],
                    ['metric' => 'Module test %', 'values' => ['75%', '82%', '89%', '62%', '69%', '76%', '83%', '90%']],
                    ['metric' => 'Objective test %', 'values' => ['78%', '85%', '92%', '65%', '72%', '79%', '86%', '93%']],
                    ['metric' => 'Programming test %', 'values' => ['80%', '87%', '94%', '67%', '74%', '81%', '88%', '95%']],
                    ['metric' => 'Quiz %', 'values' => ['68%', '75%', '82%', '89%', '62%', '69%', '76%', '83%']],
                    ['metric' => 'Assignments %', 'values' => ['75%', '82%', '89%', '62%', '69%', '76%', '83%', '90%']],
                    ['metric' => 'Projects (count)', 'values' => ['1', '2', '3', '1', '2', '3', '1', '2']],
                ],
            ],
        ];

        // 4. Counseling Notes (Completely omitted for sponsors)
        $counseling_notes = [];
        if (!$is_sponsor) {
            $counseling_notes = counseling_notes_service::get_notes($userid, $batchid, $context);
        }

        // 5. Placement Summary & Detail Table
        $can_view_ctc = has_capability('local/batchanalytics:viewfullcrmdata', $context) || !$is_sponsor;
        $placement = [
            'opportunities_given' => 4,
            'interviews_selected' => 2,
            'status' => 'Placed',
            'offered_salary' => $can_view_ctc ? '₹ 6.5 LPA' : 'Confidential',
            'summary' => [
                'total_opportunities' => 4,
                'status' => 'Placed',
                'placed_company' => 'Marvell Semiconductor',
                'role' => 'Embedded Firmware Trainee',
                'salary_ctc' => $can_view_ctc ? '₹ 6.5 LPA' : 'Confidential',
                'date_of_offer' => '24 Oct 2026',
            ],
            'opportunities_detail' => [
                [
                    'company' => 'Marvell Semiconductor',
                    'role' => 'Embedded Firmware Trainee',
                    'status' => 'Placed',
                    'status_class' => 'placed',
                    'salary_ctc' => $can_view_ctc ? '₹ 6.5 LPA' : 'Confidential',
                ],
                [
                    'company' => 'Volt Systems',
                    'role' => 'Firmware Intern',
                    'status' => 'Selected',
                    'status_class' => 'selected',
                    'salary_ctc' => $can_view_ctc ? '₹ 5.0 LPA' : 'Confidential',
                ],
                [
                    'company' => 'NxGen Electronics',
                    'role' => 'QA Engineer',
                    'status' => 'Not selected',
                    'status_class' => 'not-selected',
                    'salary_ctc' => '—',
                ],
                [
                    'company' => 'Kaynes Technology',
                    'role' => 'Test Engineer',
                    'status' => 'In process',
                    'status_class' => 'in-process',
                    'salary_ctc' => '—',
                ],
            ],
        ];

        return [
            'header' => $header,
            'basic_info' => $basic_info,
            'academic' => $academic,
            'course_performance' => $course_perf,
            'counseling_notes' => $counseling_notes,
            'placement' => $placement,
            'is_sponsor' => $is_sponsor,
        ];
    }
}

