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

namespace block_batchanalytics;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/util.php');
require_once(__DIR__ . '/mentor_activity_service.php');

/**
 * Service for operational role resolution, To-Do task generation,
 * and completion tracking for Mentors, SS Executives, PMs, and AMs.
 *
 * @package    block_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_service {

    /**
     * Get configured role IDs for a given setting name.
     *
     * @param string $setting_name
     * @return int[]
     */
    public static function get_configured_role_ids(string $setting_name): array {
        $val = get_config('block_batchanalytics', $setting_name);
        if ($val === false || $val === null || trim((string)$val) === '') {
            return [];
        }
        $raw = is_array($val) ? $val : explode(',', (string)$val);
        $roleids = [];
        foreach ($raw as $item) {
            $id = (int)trim((string)$item);
            if ($id > 0) {
                $roleids[] = $id;
            }
        }
        return array_values(array_unique($roleids));
    }

    /**
     * Check if a user holds any of the configured roles in system or course contexts.
     *
     * @param int $userid
     * @param string $setting_name
     * @return bool
     */
    public static function user_has_configured_role(int $userid, string $setting_name): bool {
        global $DB;
        $roleids = self::get_configured_role_ids($setting_name);
        if (empty($roleids)) {
            return false;
        }
        [$in_sql, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        $params['uid'] = $userid;
        return $DB->record_exists_select('role_assignments', "userid = :uid AND roleid $in_sql", $params);
    }

    /**
     * Resolve the operational personas and permissions for a user.
     *
     * @param int $userid
     * @return array
     */
    public static function resolve_user_personas(int $userid): array {
        global $DB, $USER;

        $user_obj = ($userid === (int)$USER->id) ? $USER : $DB->get_record('user', ['id' => $userid]);
        $fullname = $user_obj ? trim(fullname($user_obj)) : '';

        $context = \context_system::instance();
        $is_manager = is_siteadmin($userid)
            || has_capability('block/batchanalytics:manage', $context, $userid)
            || has_capability('local/batchanalytics:manage', $context, $userid);

        // Role config matches
        $has_mentor_role = self::user_has_configured_role($userid, 'mentor_roles');
        $has_sse_role    = self::user_has_configured_role($userid, 'ssexecutive_roles');
        $has_pm_role     = self::user_has_configured_role($userid, 'program_manager_roles');
        $has_asst_role   = self::user_has_configured_role($userid, 'assistant_manager_roles');

        // Check Batch Management direct assignments in local_bm_classsection
        $assigned_sse = false;
        $assigned_pm  = false;
        $assigned_class_mentor = false;
        $assigned_lab_mentor   = false;

        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_bm_classsection')) {
            $sections = $DB->get_records('local_bm_classsection', null, '', 'id, maacexecutive, maacexecutivename, pmmanager, pmmanagername, moduledata');
            foreach ($sections as $sec) {
                // SS / MAAC Executive check
                if ((string)$sec->maacexecutive === (string)$userid || ($fullname !== '' && $sec->maacexecutivename === $fullname)) {
                    $assigned_sse = true;
                }
                // Program Manager check
                if ((string)$sec->pmmanager === (string)$userid || ($fullname !== '' && $sec->pmmanagername === $fullname)) {
                    $assigned_pm = true;
                }
                // Mentor check in moduledata
                $modules = util::decode_module_data($sec->moduledata ?? '', true);
                foreach ($modules as $m) {
                    $class_mentors = [(string)($m['primarymentor'] ?? ''), (string)($m['secondarymentor'] ?? '')];
                    $lab_mentors   = [(string)($m['labmentor1'] ?? ''), (string)($m['labmentor2'] ?? ''), (string)($m['labmentor3'] ?? '')];

                    if (in_array((string)$userid, $class_mentors, true) || ($fullname !== '' && in_array($fullname, $class_mentors, true))) {
                        $assigned_class_mentor = true;
                    }
                    if (in_array((string)$userid, $lab_mentors, true) || ($fullname !== '' && in_array($fullname, $lab_mentors, true))) {
                        $assigned_lab_mentor = true;
                    }
                }
            }
        }

        // Check if teacher in any course
        if (!$assigned_class_mentor && !$has_mentor_role) {
            $editingteacher_roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
            $teacher_roleid        = (int)$DB->get_field('role', 'id', ['shortname' => 'teacher']);
            $mentor_roleid         = (int)$DB->get_field('role', 'id', ['shortname' => 'mentor_resource_access']);
            $teacher_rids = array_values(array_filter([$editingteacher_roleid, $teacher_roleid, $mentor_roleid]));
            if (!empty($teacher_rids)) {
                [$tsql, $tparams] = $DB->get_in_or_equal($teacher_rids, SQL_PARAMS_NAMED, 'tr');
                $tparams['uid'] = $userid;
                if ($DB->record_exists_select('role_assignments', "userid = :uid AND roleid $tsql", $tparams)) {
                    $has_mentor_role = true;
                }
            }
        }

        // Composite personas
        $is_class_mentor = $assigned_class_mentor || $has_mentor_role;
        $is_lab_mentor   = $assigned_lab_mentor;
        $is_sse          = $assigned_sse || $has_sse_role;
        $is_pm           = $assigned_pm || $has_pm_role;
        $is_asst         = $has_asst_role;

        return [
            'is_manager'      => $is_manager,
            'is_class_mentor' => $is_class_mentor,
            'is_lab_mentor'   => $is_lab_mentor,
            'is_mentor'       => ($is_class_mentor || $is_lab_mentor),
            'is_sse'          => $is_sse,
            'is_pm'           => $is_pm,
            'is_asst'         => $is_asst,
        ];
    }

    /**
     * Fetch complete To-Do dashboard data for a user and role.
     *
     * @param int $userid
     * @param string $requested_role
     * @return array
     */
    public static function get_dashboard_data(int $userid, string $requested_role = ''): array {
        global $DB, $USER;

        $user_obj = ($userid === (int)$USER->id) ? $USER : $DB->get_record('user', ['id' => $userid]);
        $firstname = $user_obj ? ($user_obj->firstname ?: fullname($user_obj)) : 'User';
        $user_fullname = $user_obj ? trim(fullname($user_obj)) : '';

        // Time-based greeting
        $hour = (int)date('G');
        if ($hour < 12) {
            $greeting_prefix = 'Good morning';
        } else if ($hour < 17) {
            $greeting_prefix = 'Good afternoon';
        } else {
            $greeting_prefix = 'Good evening';
        }
        $greeting = $greeting_prefix . ', ' . $firstname;

        $personas = self::resolve_user_personas($userid);
        $is_manager = $personas['is_manager'];

        // Available roles for switcher
        $available_roles = [];
        if ($is_manager) {
            $available_roles = [
                'class'  => 'Class Mentor',
                'lab'    => 'Lab Mentor',
                'sse'    => 'SS / MAAC Executive',
                'pm'     => 'Program Manager',
                'asst'   => 'Assistant Manager',
                'all'    => 'All Roles',
            ];
        } else {
            if ($personas['is_class_mentor']) {
                $available_roles['class'] = 'Class Mentor';
            }
            if ($personas['is_lab_mentor']) {
                $available_roles['lab'] = 'Lab Mentor';
            }
            if ($personas['is_sse']) {
                $available_roles['sse'] = 'SS / MAAC Executive';
            }
            if ($personas['is_pm']) {
                $available_roles['pm'] = 'Program Manager';
            }
            if ($personas['is_asst']) {
                $available_roles['asst'] = 'Assistant Manager';
            }
            if (count($available_roles) > 1) {
                $available_roles['all'] = 'All Roles';
            }
            if (empty($available_roles)) {
                $available_roles['class'] = 'Class Mentor';
            }
        }

        // Resolve active viewing role
        $active_role = $requested_role;
        if ($active_role !== '' && isset($available_roles[$active_role])) {
            // Keep requested role if valid
        } else if ($personas['is_class_mentor']) {
            $active_role = 'class';
        } else if ($personas['is_lab_mentor']) {
            $active_role = 'lab';
        } else if ($personas['is_sse']) {
            $active_role = 'sse';
        } else if ($personas['is_pm']) {
            $active_role = 'pm';
        } else if ($personas['is_asst']) {
            $active_role = 'asst';
        } else if (isset($available_roles['all'])) {
            $active_role = 'all';
        } else {
            $active_role = array_key_first($available_roles) ?: 'class';
        }

        // Fetch batches and class sections
        $dbman = $DB->get_manager();
        $sections = [];
        $batches = [];
        if ($dbman->table_exists('local_bm_classsection')) {
            $sections = $DB->get_records('local_bm_classsection', null, 'id ASC');
        }
        if ($dbman->table_exists('local_bm_batch')) {
            $batches = $DB->get_records('local_bm_batch', null, 'id ASC');
        }

        $assigned_batch_ids = [];
        $assigned_section_ids = [];

        // Role-specific filtering of sections
        $filtered_sections = [];
        foreach ($sections as $sec) {
            $match = false;
            if ($is_manager && in_array($active_role, ['all', 'pm', 'asst'], true)) {
                $match = true;
            } else if ($active_role === 'sse') {
                if ($is_manager || (string)$sec->maacexecutive === (string)$userid || ($user_fullname !== '' && $sec->maacexecutivename === $user_fullname)) {
                    $match = true;
                }
            } else if ($active_role === 'pm') {
                if ($is_manager || (string)$sec->pmmanager === (string)$userid || ($user_fullname !== '' && $sec->pmmanagername === $user_fullname)) {
                    $match = true;
                }
            } else if ($active_role === 'class' || $active_role === 'lab') {
                $s_modules = util::decode_module_data($sec->moduledata ?? '', true);
                foreach ($s_modules as $sm) {
                    $mentors = ($active_role === 'class')
                        ? [(string)($sm['primarymentor'] ?? ''), (string)($sm['secondarymentor'] ?? '')]
                        : [(string)($sm['labmentor1'] ?? ''), (string)($sm['labmentor2'] ?? ''), (string)($sm['labmentor3'] ?? '')];

                    if ($is_manager || in_array((string)$userid, $mentors, true) || ($user_fullname !== '' && in_array($user_fullname, $mentors, true))) {
                        $match = true;
                        break;
                    }
                }
            } else {
                $match = true;
            }

            if ($match) {
                $filtered_sections[$sec->id] = $sec;
                $assigned_section_ids[(int)$sec->id] = true;
                if (!empty($sec->batchid)) {
                    $assigned_batch_ids[(int)$sec->batchid] = true;
                }
            }
        }

        // Student count
        $student_count = 0;
        if (!empty($assigned_section_ids) && $dbman->table_exists('local_bm_student')) {
            [$sec_sql, $sec_params] = $DB->get_in_or_equal(array_keys($assigned_section_ids));
            $student_count = (int)$DB->count_records_select('local_bm_student', "classsectionid $sec_sql", $sec_params);
        }

        $today_midnight = strtotime('today midnight');
        $today_end      = $today_midnight + 86400;
        $next_week_end  = $today_midnight + (7 * 86400);

        $todo_list = [];
        $forthcoming_list = [];

        // ---------------------------------------------------------------------
        // 1. MENTOR ACTIVITIES (for Class Mentor / Lab Mentor / All Roles)
        // ---------------------------------------------------------------------
        if (in_array($active_role, ['class', 'lab', 'all'], true)) {
            foreach ($filtered_sections as $sec) {
                $s_modules = util::decode_module_data($sec->moduledata ?? '', true);
                foreach ($s_modules as $sm) {
                    $courseid = (int)($sm['moodlecourseid'] ?? 0);
                    if ($courseid <= 0) {
                        continue;
                    }

                    // Check if mentor assigned
                    if (!$is_manager) {
                        $mentors = ($active_role === 'class')
                            ? [(string)($sm['primarymentor'] ?? ''), (string)($sm['secondarymentor'] ?? '')]
                            : ($active_role === 'lab'
                                ? [(string)($sm['labmentor1'] ?? ''), (string)($sm['labmentor2'] ?? ''), (string)($sm['labmentor3'] ?? '')]
                                : [(string)($sm['primarymentor'] ?? ''), (string)($sm['secondarymentor'] ?? ''), (string)($sm['labmentor1'] ?? ''), (string)($sm['labmentor2'] ?? ''), (string)($sm['labmentor3'] ?? '')]);

                        if (!in_array((string)$userid, $mentors, true) && ($user_fullname === '' || !in_array($user_fullname, $mentors, true))) {
                            continue;
                        }
                    }

                    $mod_name = $sm['name'] ?? 'Module';
                    $mod_p_start = (int)($sm['plannedstart'] ?? 0);

                    try {
                        $mdata = mentor_activity_service::get_course_mentor_activities($courseid, $mod_name, $mod_p_start);
                        $activities = $mdata['activities'] ?? [];

                        foreach ($activities as $act) {
                            if (!empty($act['completed'])) {
                                continue;
                            }

                            $p_ts = (int)($act['planned_ts'] ?? 0);
                            $act_name = $act['name'];
                            $batch_name = $sec->name ?: ('Batch ' . $sec->id);

                            $dest_url = (new \moodle_url('/blocks/batchanalytics/module.php', [
                                'courseid' => $courseid,
                                'batchid'  => $sec->id,
                            ]))->out(false);

                            if ($p_ts > 0 && $p_ts < $today_midnight) {
                                $days = max(1, (int)floor(($today_midnight - $p_ts) / 86400));
                                $todo_list[] = [
                                    'id'            => 'mentor_' . $courseid . '_' . $act['key'],
                                    'title'         => $act_name . ' — ' . $mod_name,
                                    'meta'          => 'Batch ' . $batch_name . ' · ' . $act['planned_date_formatted'],
                                    'batch_name'    => $batch_name,
                                    'urgency_order' => 1,
                                    'status_class'  => 'over',
                                    'status_label'  => 'Overdue ' . $days . 'd',
                                    'dest_type'     => 'module',
                                    'dest_url'      => $dest_url,
                                    'action_type'   => 'mentor',
                                    'courseid'      => $courseid,
                                    'batchid'       => (int)$sec->id,
                                    'act_key'       => $act['key'],
                                    'act_name'      => $act_name,
                                    'planned_ts'    => $p_ts,
                                ];
                            } else if ($p_ts >= $today_midnight && $p_ts < $today_end) {
                                $todo_list[] = [
                                    'id'            => 'mentor_' . $courseid . '_' . $act['key'],
                                    'title'         => $act_name . ' — ' . $mod_name,
                                    'meta'          => 'Batch ' . $batch_name . ' · due today',
                                    'batch_name'    => $batch_name,
                                    'urgency_order' => 2,
                                    'status_class'  => 'today',
                                    'status_label'  => 'Due today',
                                    'dest_type'     => 'module',
                                    'dest_url'      => $dest_url,
                                    'action_type'   => 'mentor',
                                    'courseid'      => $courseid,
                                    'batchid'       => (int)$sec->id,
                                    'act_key'       => $act['key'],
                                    'act_name'      => $act_name,
                                    'planned_ts'    => $p_ts,
                                ];
                            } else if ($p_ts >= $today_end && $p_ts <= $next_week_end) {
                                $days = max(1, (int)floor(($p_ts - $today_midnight) / 86400));
                                $todo_list[] = [
                                    'id'            => 'mentor_' . $courseid . '_' . $act['key'],
                                    'title'         => $act_name . ' — ' . $mod_name,
                                    'meta'          => 'Batch ' . $batch_name . ' · in ' . $days . ' days',
                                    'batch_name'    => $batch_name,
                                    'urgency_order' => 3,
                                    'status_class'  => 'soon',
                                    'status_label'  => 'Due in ' . $days . 'd',
                                    'dest_type'     => 'module',
                                    'dest_url'      => $dest_url,
                                    'action_type'   => 'mentor',
                                    'courseid'      => $courseid,
                                    'batchid'       => (int)$sec->id,
                                    'act_key'       => $act['key'],
                                    'act_name'      => $act_name,
                                    'planned_ts'    => $p_ts,
                                ];
                            } else if ($p_ts > $next_week_end) {
                                $days = (int)floor(($p_ts - $today_midnight) / 86400);
                                $forthcoming_list[] = [
                                    'title' => $act_name . ' — ' . $mod_name,
                                    'meta'  => 'Batch ' . $batch_name . ' · in ' . $days . ' days',
                                    'ts'    => $p_ts,
                                ];
                            }
                        }
                    } catch (\Throwable $e) {
                        // Skip gracefully if course mentor activity error
                    }
                }
            }
        }

        // ---------------------------------------------------------------------
        // 2. SS ACTIVITIES (for SS Executive / Program Manager / Assistant Manager / All)
        // ---------------------------------------------------------------------
        if (in_array($active_role, ['sse', 'pm', 'asst', 'all'], true)) {
            foreach ($filtered_sections as $sec) {
                if (empty($sec->softskillsdata)) {
                    continue;
                }

                $ss_list = util::decode_softskills_activities($sec->softskillsdata);
                $batch_name = $sec->name ?: ('Batch ' . $sec->id);
                $dest_url = (new \moodle_url('/blocks/batchanalytics/batch.php', ['id' => $sec->id]))->out(false);

                foreach ($ss_list as $ss) {
                    $act_name = $ss['activity'];
                    $p_ts = (int)($ss['planned'] ?? 0);
                    $a_ts = (int)($ss['actual'] ?? 0);

                    if ($a_ts > 0) {
                        continue; // Already completed
                    }

                    if ($p_ts > 0 && $p_ts < $today_midnight) {
                        $days = max(1, (int)floor(($today_midnight - $p_ts) / 86400));
                        $todo_list[] = [
                            'id'            => 'ss_' . $sec->id . '_' . $ss['key'],
                            'title'         => $act_name,
                            'meta'          => 'Batch ' . $batch_name . ' · ' . $ss['p_date'],
                            'batch_name'    => $batch_name,
                            'urgency_order' => 1,
                            'status_class'  => 'over',
                            'status_label'  => 'Overdue ' . $days . 'd',
                            'dest_type'     => 'batch',
                            'dest_url'      => $dest_url,
                            'action_type'   => 'ss',
                            'courseid'      => 0,
                            'batchid'       => (int)$sec->id,
                            'act_key'       => $ss['key'],
                            'act_name'      => $act_name,
                            'planned_ts'    => $p_ts,
                        ];
                    } else if ($p_ts >= $today_midnight && $p_ts < $today_end) {
                        $todo_list[] = [
                            'id'            => 'ss_' . $sec->id . '_' . $ss['key'],
                            'title'         => $act_name,
                            'meta'          => 'Batch ' . $batch_name . ' · due today',
                            'batch_name'    => $batch_name,
                            'urgency_order' => 2,
                            'status_class'  => 'today',
                            'status_label'  => 'Due today',
                            'dest_type'     => 'batch',
                            'dest_url'      => $dest_url,
                            'action_type'   => 'ss',
                            'courseid'      => 0,
                            'batchid'       => (int)$sec->id,
                            'act_key'       => $ss['key'],
                            'act_name'      => $act_name,
                            'planned_ts'    => $p_ts,
                        ];
                    } else if ($p_ts >= $today_end && $p_ts <= $next_week_end) {
                        $days = max(1, (int)floor(($p_ts - $today_midnight) / 86400));
                        $todo_list[] = [
                            'id'            => 'ss_' . $sec->id . '_' . $ss['key'],
                            'title'         => $act_name,
                            'meta'          => 'Batch ' . $batch_name . ' · in ' . $days . ' days',
                            'batch_name'    => $batch_name,
                            'urgency_order' => 3,
                            'status_class'  => 'soon',
                            'status_label'  => 'Due in ' . $days . 'd',
                            'dest_type'     => 'batch',
                            'dest_url'      => $dest_url,
                            'action_type'   => 'ss',
                            'courseid'      => 0,
                            'batchid'       => (int)$sec->id,
                            'act_key'       => $ss['key'],
                            'act_name'      => $act_name,
                            'planned_ts'    => $p_ts,
                        ];
                    } else if ($p_ts > $next_week_end) {
                        $days = (int)floor(($p_ts - $today_midnight) / 86400);
                        $forthcoming_list[] = [
                            'title' => $act_name,
                            'meta'  => 'Batch ' . $batch_name . ' · in ' . $days . ' days',
                            'ts'    => $p_ts,
                        ];
                    }
                }
            }
        }

        // Sort To-Do list: Overdue first (1), Due today (2), Due soon (3), then by planned timestamp
        usort($todo_list, static function($a, $b) {
            if ($a['urgency_order'] !== $b['urgency_order']) {
                return $a['urgency_order'] <=> $b['urgency_order'];
            }
            return $a['planned_ts'] <=> $b['planned_ts'];
        });

        // Sort Forthcoming by date
        usort($forthcoming_list, static function($a, $b) {
            return $a['ts'] <=> $b['ts'];
        });

        // Glance stats calculation
        $tasks_due_week = 0;
        $overdue_count = 0;
        foreach ($todo_list as $t) {
            if ($t['status_class'] === 'over') {
                $overdue_count++;
                $tasks_due_week++;
            } else if ($t['status_class'] === 'today' || $t['status_class'] === 'soon') {
                $tasks_due_week++;
            }
        }

        $batches_count = count($filtered_sections);
        $role_label = $available_roles[$active_role] ?? 'Operational View';
        $role_subtitle = $role_label . ' · ' . $batches_count . ' ' . ($batches_count === 1 ? 'batch' : 'batches') . ' assigned';

        $glance = [
            [
                'val'   => (string)$tasks_due_week,
                'lbl'   => 'Due this week',
                'alert' => 0,
            ],
            [
                'val'   => (string)$overdue_count,
                'lbl'   => 'Overdue',
                'alert' => ($overdue_count > 0 ? 1 : 0),
            ],
            [
                'val'   => (string)$batches_count,
                'lbl'   => 'Batches',
                'alert' => 0,
            ],
            [
                'val'   => $student_count > 0 ? number_format($student_count) : '—',
                'lbl'   => 'Students',
                'alert' => 0,
            ],
        ];

        return [
            'greeting'         => $greeting,
            'role_subtitle'    => $role_subtitle,
            'active_role'      => $active_role,
            'can_switch_roles' => ($is_manager || count($available_roles) > 1),
            'available_roles'  => $available_roles,
            'glance'           => $glance,
            'todo'             => $todo_list,
            'forthcoming'      => array_slice($forthcoming_list, 0, 10),
            'total_pending'    => count($todo_list),
        ];
    }

    /**
     * Mark an operational activity complete.
     *
     * @param int $userid
     * @param string $action_type 'mentor' or 'ss'
     * @param array $params
     * @return array
     * @throws \moodle_exception
     */
    public static function mark_activity_complete(int $userid, string $action_type, array $params): array {
        global $DB;

        if ($action_type === 'mentor') {
            $courseid = (int)($params['courseid'] ?? 0);
            $act_name = trim((string)($params['act_name'] ?? ''));
            if ($courseid <= 0 || $act_name === '') {
                throw new \moodle_exception('invalidparams', 'block_batchanalytics');
            }
            $updated = mentor_activity_service::save_activity_status($courseid, $act_name, true, date('Y-m-d'), $userid);
            return [
                'success' => true,
                'type'    => 'mentor',
                'item'    => $updated,
            ];
        }

        if ($action_type === 'ss') {
            $batchid = (int)($params['batchid'] ?? 0);
            $act_key = trim((string)($params['act_key'] ?? ''));
            if ($batchid <= 0 || $act_key === '') {
                throw new \moodle_exception('invalidparams', 'block_batchanalytics');
            }

            $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
            if (!$sec) {
                throw new \moodle_exception('invalidbatch', 'block_batchanalytics');
            }

            $raw_data = json_decode($sec->softskillsdata ?? '', true);
            $now = time();
            $target_base = strtolower(trim($act_key));

            if (is_array($raw_data)) {
                $updated = false;
                // Check key-based format: ${base}_Actual
                foreach ($raw_data as $k => $v) {
                    if (preg_match('/^(.+)_(planned|actual)$/i', (string)$k, $m)) {
                        $base = strtolower($m[1]);
                        if ($base === $target_base) {
                            $raw_data[$m[1] . '_Actual'] = $now;
                            $updated = true;
                            break;
                        }
                    }
                }
                // Check object list format: [{activity: ..., actual: ...}]
                if (!$updated) {
                    foreach ($raw_data as &$item) {
                        if (is_array($item)) {
                            $name = strtolower(trim($item['activity'] ?? $item['name'] ?? ''));
                            $key  = strtolower(trim($item['key'] ?? ''));
                            if ($key === $target_base || $name === $target_base) {
                                $item['actual'] = $now;
                                $updated = true;
                                break;
                            }
                        }
                    }
                    unset($item);
                }

                if (!$updated) {
                    $raw_data[$act_key . '_Actual'] = $now;
                }

                $sec->softskillsdata = json_encode($raw_data);
                $sec->timemodified = $now;
                $DB->update_record('local_bm_classsection', $sec);

                return [
                    'success'   => true,
                    'type'      => 'ss',
                    'batchid'   => $batchid,
                    'actual_ts' => $now,
                ];
            }
        }

        throw new \moodle_exception('invalidaction', 'block_batchanalytics');
    }
}
