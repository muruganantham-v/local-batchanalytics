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
 * Service for sponsored programs metadata, mobilization funnel, and cohort mappings.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class program_service {

    const TABLE_PROGRAM = 'local_batchanalytics_program';
    const TABLE_BATCHES = 'local_batchanalytics_prog_batch';

    /**
     * Check if a user is a sponsor user with view-only permissions.
     * Consolidates programsponsor and programmisviewer roles into a single sponsor view-only persona.
     *
     * @param int|null $userid
     * @return bool
     */
    public static function is_sponsor_user(?int $userid = null): bool {
        global $USER, $DB;
        $uid = $userid ?: (int)$USER->id;
        if ($uid <= 0 || is_siteadmin($uid)) {
            return false;
        }

        // Check if user holds sponsor-designated role (programsponsor or legacy programmisviewer)
        if ($DB->get_manager()->table_exists('role_assignments')) {
            $has_sponsor_role = $DB->record_exists_sql(
                "SELECT ra.id FROM {role_assignments} ra 
                 JOIN {role} r ON r.id = ra.roleid 
                 WHERE ra.userid = :uid 
                   AND (r.shortname = 'programsponsor' OR r.shortname = 'programmisviewer')",
                ['uid' => $uid]
            );
            if ($has_sponsor_role) {
                return true;
            }
        }

        $sys_ctx = \context_system::instance();
        return has_capability('local/batchanalytics:viewsponsorportal', $sys_ctx, $uid);
    }

    /**
     * Check if user has permission to perform write operations (upload evidence, edit lesson plans, edit data, add notes).
     * Strictly restricted to: programmanager, ssteam, manager, teacher, editingteacher (and site admin).
     * Users with only sponsor roles (programsponsor / programmisviewer) are strictly read-only.
     *
     * @param \context|null $context
     * @param int|null $userid
     * @return bool
     */
    public static function can_write_operations(?\context $context = null, ?int $userid = null): bool {
        global $USER, $DB, $SESSION;
        $uid = $userid ?: (int)$USER->id;
        if ($uid <= 0) {
            return false;
        }
        // In preview sponsor mode, write operations are disabled
        if (!empty($SESSION->ba_viewmode) && $SESSION->ba_viewmode === 'sponsor') {
            return false;
        }
        if (optional_param('viewmode', '', PARAM_ALPHANUMEXT) === 'sponsor') {
            return false;
        }
        if (is_siteadmin($uid)) {
            return true;
        }

        // Sponsors are strictly read-only
        if (self::is_sponsor_user($uid)) {
            return false;
        }

        $ctx = $context ?: \context_system::instance();

        // 1. Direct capability check
        if (has_capability('local/batchanalytics:manage', $ctx, $uid)) {
            return true;
        }

        // 2. Role-based check: programmanager, ssteam, manager, teacher, editingteacher
        if ($DB->get_manager()->table_exists('role_assignments')) {
            $write_roles = ['programmanager', 'ssteam', 'manager', 'teacher', 'editingteacher'];
            list($in_sql, $in_params) = $DB->get_in_or_equal($write_roles, SQL_PARAMS_NAMED, 'wr');
            $in_params['uid'] = $uid;

            $has_write_role = $DB->record_exists_sql(
                "SELECT ra.id FROM {role_assignments} ra 
                 JOIN {role} r ON r.id = ra.roleid 
                 WHERE ra.userid = :uid AND r.shortname $in_sql",
                $in_params
            );
            if ($has_write_role) {
                return true;
            }
        }

        // 3. Batch Management assignment: Program Manager or MAAC Executive
        if ($DB->get_manager()->table_exists('local_bm_classsection')) {
            $is_assigned = $DB->record_exists('local_bm_classsection', ['pmmanager' => (string)$uid])
                || $DB->record_exists('local_bm_classsection', ['maacexecutive' => (string)$uid]);
            if ($is_assigned) {
                return true;
            }
        }

        // 4. Fallback: Course-level mentor or teacher capability
        return has_capability('moodle/course:update', $ctx, $uid)
            || has_capability('local/batchanalytics:manageevidence', $ctx, $uid)
            || has_capability('local/batchanalytics:managelessonplan', $ctx, $uid)
            || has_capability('local/batchanalytics:editreviewnotes', $ctx, $uid);
    }

    /**
     * Consolidate dual sponsor roles into a single 'programsponsor' role.
     * Ensures user is assigned 'programsponsor' and removes redundant 'programmisviewer' assignment.
     *
     * @param int|null $userid
     * @return void
     */
    public static function consolidate_sponsor_role(?int $userid = null): void {
        global $USER, $DB;
        $uid = $userid ?: (int)$USER->id;
        if ($uid <= 0) {
            return;
        }

        if (!$DB->get_manager()->table_exists('role_assignments')) {
            return;
        }

        $sponsor_role = $DB->get_record('role', ['shortname' => 'programsponsor']);
        $viewer_role  = $DB->get_record('role', ['shortname' => 'programmisviewer']);

        if (!$sponsor_role) {
            return;
        }

        $context = \context_system::instance();

        // If user has viewer_role, ensure they have sponsor_role and remove viewer_role
        if ($viewer_role) {
            $has_viewer = $DB->get_record('role_assignments', ['userid' => $uid, 'roleid' => $viewer_role->id]);
            if ($has_viewer) {
                $has_sponsor = $DB->get_record('role_assignments', ['userid' => $uid, 'roleid' => $sponsor_role->id]);
                if (!$has_sponsor) {
                    role_assign($sponsor_role->id, $uid, $context->id);
                }
                role_unassign($viewer_role->id, $uid, $has_viewer->contextid);
            }
        }
    }

    /**
     * Check if the current context / user should see the sponsor portal view.
     *
     * @param \context $context
     * @param int|null $userid
     * @return bool
     */
    public static function is_sponsor_view(\context $context, ?int $userid = null): bool {
        global $USER, $DB, $SESSION;
        $uid = $userid ?: (int)$USER->id;
        if ($uid <= 0) {
            return false;
        }

        // Explicit URL override for demo/preview if requested
        $mode = optional_param('viewmode', '', PARAM_ALPHANUMEXT);
        if ($mode === 'sponsor') {
            if (isset($SESSION)) {
                $SESSION->ba_viewmode = 'sponsor';
            }
            return true;
        }
        if ($mode === 'internal') {
            if (isset($SESSION)) {
                $SESSION->ba_viewmode = 'internal';
            }
            return false;
        }
        if (isset($SESSION) && !empty($SESSION->ba_viewmode)) {
            if ($SESSION->ba_viewmode === 'sponsor') {
                return true;
            }
            if ($SESSION->ba_viewmode === 'internal' && is_siteadmin($uid)) {
                return false;
            }
        }

        // Check if user is a sponsor user
        if (self::is_sponsor_user($uid)) {
            return true;
        }

        // If user is site administrator or has write role, default to internal view unless viewmode=sponsor
        if (is_siteadmin($uid) || self::can_write_operations($context, $uid)) {
            return false;
        }

        return false;
    }

    /**
     * Retrieve program record by ID or code, with defaults for Marvell / ESSCI.
     *
     * @param int|string $identifier
     * @return object
     */
    public static function get_program($identifier = 0): object {
        global $DB;

        $record = null;
        if ($DB->get_manager()->table_exists(self::TABLE_PROGRAM)) {
            if (is_numeric($identifier) && (int)$identifier > 0) {
                $record = $DB->get_record(self::TABLE_PROGRAM, ['id' => (int)$identifier]);
            } else if (is_string($identifier) && trim($identifier) !== '') {
                $record = $DB->get_record(self::TABLE_PROGRAM, ['code' => trim($identifier)]);
            }

            if (!$record) {
                // Get the first active program if available
                $records = $DB->get_records(self::TABLE_PROGRAM, null, 'id ASC', '*', 0, 1);
                if (!empty($records)) {
                    $record = reset($records);
                }
            }
        }

        if (!$record) {
            // Default Marvell Semiconductor / ESSCI program prototype entity
            $record = (object)[
                'id' => 1,
                'code' => 'MARVELL_ESSCI_2026',
                'name' => 'Advanced Skilling of Women in Semiconductors',
                'tagline' => 'A joint initiative for high-impact semiconductor skills and career enablement',
                'sponsor_name' => 'Marvell Semiconductor',
                'pmo_name' => 'ESSCI (PMO / Skill Body)',
                'partner_name' => 'Emertxe Information Technologies',
                'sites' => 'Bangalore, Hyderabad',
                'program_window' => 'Aug 2026 – Mar 2027',
                'startdate' => 1785542400, // Aug 2026
                'enddate' => 1806451200,   // Mar 2027
                'candidates_reached' => 2480,
                'candidates_eligible' => 860,
                'test_participants' => 420,
                'interview_participants' => 286,
                'final_selects' => 120,
            ];
        }

        return $record;
    }

    /**
     * Get mapped cohorts for a program.
     * Sourced dynamically from Batch Management (local_bm_batch) where coursename is 'essci'.
     *
     * @param int $programid
     * @return array
     */
    public static function get_program_batches(int $programid = 1): array {
        global $DB;

        // 1. If explicit mappings exist in local_batchanalytics_prog_batch, use them
        if ($DB->get_manager()->table_exists(self::TABLE_BATCHES)) {
            $mappings = $DB->get_records(self::TABLE_BATCHES, ['programid' => $programid], 'id ASC');
            if (!empty($mappings)) {
                $has_sections = $DB->get_manager()->table_exists('local_bm_classsection');
                $has_batches = $DB->get_manager()->table_exists('local_bm_batch');

                foreach ($mappings as $m) {
                    $sec = null;
                    if ($has_sections && !empty($m->sectionid)) {
                        $sec = $DB->get_record('local_bm_classsection', ['id' => (int)$m->sectionid]);
                    }
                    $parent_b = null;
                    if ($has_batches && !empty($m->batchid)) {
                        $parent_b = $DB->get_record('local_bm_batch', ['id' => (int)$m->batchid]);
                    }

                    // Section display name: show ONLY class section name (strip any MVL prefix)
                    $clean_sec_name = '';
                    if ($sec && !empty($sec->name)) {
                        $clean_sec_name = trim($sec->name);
                    } else if (!empty($m->sponsor_batch_code)) {
                        $clean_sec_name = trim($m->sponsor_batch_code);
                    } else if ($parent_b && !empty($parent_b->name)) {
                        $clean_sec_name = trim($parent_b->name);
                    }
                    $clean_sec_name = preg_replace('/^MVL/i', '', $clean_sec_name);
                    $m->section_name = $clean_sec_name;
                    $m->sponsor_batch_code = $clean_sec_name;

                    // Course name & module
                    $course_name = '';
                    $current_module = 'Advanced C';
                    $active_cids = [];
                    if ($sec && !empty($sec->moduledata)) {
                        $mods = \local_batchanalytics\util::decode_module_data($sec->moduledata, true);
                        foreach ($mods as $mod) {
                            if (empty($course_name) && !empty($mod['coursename'])) {
                                $course_name = $mod['coursename'];
                            }
                            if (!empty($mod['name'])) {
                                $current_module = $mod['name'];
                            }
                            if (!empty($mod['moodlecourseid'])) {
                                $active_cids[] = (int)$mod['moodlecourseid'];
                            }
                        }
                    }
                    if (empty($course_name) && $parent_b && !empty($parent_b->coursename)) {
                        $course_name = $parent_b->coursename;
                    }
                    if (empty($course_name)) {
                        $course_name = 'Embedded Systems (ESSCI)';
                    }
                    $m->course = $course_name;
                    $m->courseName = $course_name;
                    $m->current_module = $current_module;
                    $m->currentModule = $current_module;

                    // Start date
                    $ts = 0;
                    if ($sec && !empty($sec->startdate)) {
                        $ts = is_numeric($sec->startdate) ? (int)$sec->startdate : strtotime((string)$sec->startdate);
                    } else if ($parent_b && !empty($parent_b->startdate)) {
                        $ts = (int)$parent_b->startdate;
                    }
                    $m->startdate = ($ts > 0) ? userdate($ts, '%d %b %Y') : '18 Jul 2026';
                    $m->startDate = $m->startdate;
                    $m->start_timestamp = $ts;

                    // Enrolled count
                    $student_count = 0;
                    if (!empty($active_cids)) {
                        list($insql, $inparams) = $DB->get_in_or_equal($active_cids);
                        $student_count = (int)$DB->count_records_sql(
                            "SELECT COUNT(DISTINCT ue.userid) 
                             FROM {user_enrolments} ue 
                             JOIN {enrol} e ON e.id = ue.enrolid 
                             WHERE e.courseid $insql AND ue.status = 0",
                            $inparams
                        );
                    }
                    if ($student_count <= 0 && $sec && class_exists('\local_batchmanagement\classsection')) {
                        try {
                            $bm_students = \local_batchmanagement\classsection::get_enrolled_course_students((int)$sec->id, 0, 1000);
                            $student_count = count($bm_students);
                        } catch (\Throwable $e) {}
                    }
                    if ($student_count <= 0) {
                        $student_count = 12;
                    }
                    $m->enrolled = $student_count;
                    $m->studentCount = $student_count;

                    // Status
                    $m->status = 'on_schedule';
                    $m->status_label = 'On schedule';
                }
                return array_values($mappings);
            }
        }

        // 2. Query Batch Management (local_bm_batch) for batches where coursename is 'essci'
        if ($DB->get_manager()->table_exists('local_bm_batch')) {
            $bm_batches = $DB->get_records_sql(
                "SELECT * FROM {local_bm_batch} 
                 WHERE LOWER(coursename) = 'essci' OR LOWER(coursename) LIKE '%essci%' 
                 ORDER BY id ASC"
            );

            if (!empty($bm_batches)) {
                $results = [];
                $has_sections = $DB->get_manager()->table_exists('local_bm_classsection');

                foreach ($bm_batches as $b) {
                    $sections = [];
                    if ($has_sections) {
                        $sections = $DB->get_records('local_bm_classsection', ['batchid' => $b->id], 'id ASC');
                    }
                    if (empty($sections)) {
                        $sections = [(object)[
                            'id' => $b->id,
                            'name' => $b->name,
                            'batchid' => $b->id,
                            'classlocation' => 'Bangalore',
                            'moduledata' => ''
                        ]];
                    }

                    foreach ($sections as $sec) {
                        $sec_name = !empty($sec->name) ? $sec->name : $b->name;
                        $coursename = !empty($b->coursename) ? $b->coursename : '';
                        $currentmodule = 'Advanced C';
                        $active_cids = [];

                        if (!empty($sec->moduledata)) {
                            $mods = \local_batchanalytics\util::decode_module_data($sec->moduledata, true);
                            foreach ($mods as $m) {
                                if (empty($coursename) && !empty($m['coursename'])) {
                                    $coursename = $m['coursename'];
                                }
                                if (!empty($m['name'])) {
                                    $currentmodule = $m['name'];
                                }
                                if (!empty($m['moodlecourseid'])) {
                                    $active_cids[] = (int)$m['moodlecourseid'];
                                }
                            }
                        }
                        if (empty($coursename)) {
                            $coursename = 'Embedded Systems (ESSCI)';
                        }

                        $site = !empty($sec->classlocation) ? $sec->classlocation : 'Bangalore';

                        $student_count = 0;
                        if (!empty($active_cids)) {
                            list($insql, $inparams) = $DB->get_in_or_equal($active_cids);
                            $student_count = (int)$DB->count_records_sql(
                                "SELECT COUNT(DISTINCT ue.userid) 
                                 FROM {user_enrolments} ue 
                                 JOIN {enrol} e ON e.id = ue.enrolid 
                                 WHERE e.courseid $insql AND ue.status = 0",
                                $inparams
                            );
                        }
                        if ($student_count < 0) {
                            $student_count = 0;
                        }

                        $results[] = (object)[
                            'id' => (int)$sec->id,
                            'programid' => $programid,
                            'batchid' => (int)$b->id,
                            'sectionid' => (int)$sec->id,
                            'site' => $site,
                            'sponsor_batch_code' => $sec_name,
                            'course' => $coursename,
                            'startdate' => $b->startdate > 0 ? userdate($b->startdate, '%d %b %Y') : (!empty($sec->timecreated) ? userdate($sec->timecreated, '%d %b %Y') : '—'),
                            'current_module' => $currentmodule,
                            'enrolled' => $student_count,
                            'status' => 'on_schedule',
                            'status_label' => 'On schedule · 0 days'
                        ];
                    }
                }

                return $results;
            }
        }

        return [];
    }

    /**
     * Sourcing mobilization funnel numbers.
     *
     * @param int $programid
     * @return array
     */
    public static function get_mobilization_funnel(int $programid = 1): array {
        $prog = self::get_program($programid);
        return [
            'total_reached' => (int)($prog->candidates_reached ?? 2480),
            'eligible_candidates' => (int)($prog->candidates_eligible ?? 860),
            'test_participants' => (int)($prog->test_participants ?? 420),
            'interview_participants' => (int)($prog->interview_participants ?? 286),
            'interview_selects_final' => (int)($prog->final_selects ?? 120),
        ];
    }
}

