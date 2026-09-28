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
     * Check if a user can view batch analytics dashboard or pages based on capabilities or assigned personas.
     *
     * @param int $userid
     * @return bool
     */
    public static function can_view_dashboard(int $userid): bool {
        $context = \context_system::instance();
        if (has_capability('block/batchanalytics:view', $context, $userid)) {
            return true;
        }
        $personas = self::resolve_user_personas($userid);
        return !empty($personas['is_manager'])
            || !empty($personas['is_mentor'])
            || !empty($personas['is_sse'])
            || !empty($personas['is_pm'])
            || !empty($personas['is_asst']);
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
        $username = $user_obj ? trim($user_obj->username) : '';
        $email    = $user_obj ? trim($user_obj->email) : '';

        $is_user_match = static function($val) use ($userid, $fullname, $username, $email): bool {
            if ($val === null || $val === false || trim((string)$val) === '') {
                return false;
            }
            $s = trim((string)$val);
            if ($s === (string)$userid) {
                return true;
            }
            if ($fullname !== '' && strcasecmp($s, $fullname) === 0) {
                return true;
            }
            if ($username !== '' && strcasecmp($s, $username) === 0) {
                return true;
            }
            if ($email !== '' && strcasecmp($s, $email) === 0) {
                return true;
            }
            return false;
        };

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
                if ($is_user_match($sec->maacexecutive) || $is_user_match($sec->maacexecutivename)) {
                    $assigned_sse = true;
                }
                // Program Manager check
                if ($is_user_match($sec->pmmanager) || $is_user_match($sec->pmmanagername)) {
                    $assigned_pm = true;
                }
                // Mentor check in moduledata
                $modules = util::decode_module_data($sec->moduledata ?? '', true);
                foreach ($modules as $m) {
                    $class_mentors = [$m['primarymentor'] ?? '', $m['secondarymentor'] ?? ''];
                    $lab_mentors   = [$m['labmentor1'] ?? '', $m['labmentor2'] ?? '', $m['labmentor3'] ?? ''];

                    foreach ($class_mentors as $cm) {
                        if ($is_user_match($cm)) {
                            $assigned_class_mentor = true;
                            break;
                        }
                    }
                    foreach ($lab_mentors as $lm) {
                        if ($is_user_match($lm)) {
                            $assigned_lab_mentor = true;
                            break;
                        }
                    }

                    if (!$assigned_class_mentor && $has_mentor_role) {
                        $cid = (int)($m['moodlecourseid'] ?? 0);
                        if ($cid > 0) {
                            $c_ctx = \context_course::instance($cid, IGNORE_MISSING);
                            if ($c_ctx && (is_enrolled($c_ctx, $userid) || has_capability('moodle/course:update', $c_ctx, $userid))) {
                                $assigned_class_mentor = true;
                            }
                        }
                    }
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
            'is_manager'            => $is_manager,
            'is_class_mentor'       => $is_class_mentor,
            'is_lab_mentor'         => $is_lab_mentor,
            'is_mentor'             => ($is_class_mentor || $is_lab_mentor),
            'is_sse'                => $is_sse,
            'is_pm'                 => $is_pm,
            'is_asst'               => $is_asst,
            'assigned_class_mentor' => $assigned_class_mentor,
            'assigned_lab_mentor'   => $assigned_lab_mentor,
            'assigned_sse'          => $assigned_sse,
            'assigned_pm'           => $assigned_pm,
            'has_mentor_role'       => $has_mentor_role,
            'has_sse_role'          => $has_sse_role,
            'has_pm_role'           => $has_pm_role,
            'has_asst_role'         => $has_asst_role,
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
        $user_username = $user_obj ? trim($user_obj->username) : '';
        $user_email    = $user_obj ? trim($user_obj->email) : '';

        $is_user_match = static function($val) use ($userid, $user_fullname, $user_username, $user_email): bool {
            if ($val === null || $val === false || trim((string)$val) === '') {
                return false;
            }
            $s = trim((string)$val);
            if ($s === (string)$userid) {
                return true;
            }
            if ($user_fullname !== '' && strcasecmp($s, $user_fullname) === 0) {
                return true;
            }
            if ($user_username !== '' && strcasecmp($s, $user_username) === 0) {
                return true;
            }
            if ($user_email !== '' && strcasecmp($s, $user_email) === 0) {
                return true;
            }
            return false;
        };

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
                'pm'     => 'Program Manager',
                'sse'    => 'SS / MAAC Executive',
                'class'  => 'Class Mentor',
                'lab'    => 'Lab Mentor',
                'asst'   => 'Assistant Manager',
                'all'    => 'All Roles',
            ];
        } else {
            if ($personas['is_sse']) {
                $available_roles['sse'] = 'SS / MAAC Executive';
            }
            if ($personas['is_pm']) {
                $available_roles['pm'] = 'Program Manager';
            }
            if ($personas['is_asst']) {
                $available_roles['asst'] = 'Assistant Manager';
            }
            if ($personas['is_class_mentor']) {
                $available_roles['class'] = 'Class Mentor';
            }
            if ($personas['is_lab_mentor']) {
                $available_roles['lab'] = 'Lab Mentor';
            }
            if (count($available_roles) > 1) {
                $available_roles['all'] = 'All Roles';
            }
            if (empty($available_roles)) {
                $available_roles['class'] = 'Class Mentor';
            }
        }

        // Resolve active viewing role based on user direct assignment, then configured role
        $active_role = $requested_role;
        if ($active_role !== '' && isset($available_roles[$active_role])) {
            // Keep requested role if explicitly requested.
        } else if ($personas['assigned_sse']) {
            $active_role = 'sse';
        } else if ($personas['assigned_pm']) {
            $active_role = 'pm';
        } else if ($personas['assigned_class_mentor']) {
            $active_role = 'class';
        } else if ($personas['assigned_lab_mentor']) {
            $active_role = 'lab';
        } else if ($personas['is_sse']) {
            $active_role = 'sse';
        } else if ($personas['is_pm']) {
            $active_role = 'pm';
        } else if ($personas['is_asst']) {
            $active_role = 'asst';
        } else if ($personas['is_class_mentor']) {
            $active_role = 'class';
        } else if ($personas['is_lab_mentor']) {
            $active_role = 'lab';
        } else if ($is_manager) {
            $active_role = 'pm';
        } else {
            $active_role = 'class';
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
            if ($active_role === 'sse') {
                if ($is_user_match($sec->maacexecutive) || $is_user_match($sec->maacexecutivename)) {
                    $match = true;
                } else if (!$personas['assigned_sse'] && ($personas['is_sse'] || $is_manager)) {
                    // Fallback to active batches if user has role but no specific batch tag
                    $match = true;
                }
            } else if ($active_role === 'pm') {
                if ($is_user_match($sec->pmmanager) || $is_user_match($sec->pmmanagername)) {
                    $match = true;
                } else if (!$personas['assigned_pm'] && ($personas['is_pm'] || $is_manager)) {
                    // Fallback to active batches if user has role but no specific batch tag
                    $match = true;
                }
            } else if ($active_role === 'asst') {
                $match = true;
            } else if ($active_role === 'class' || $active_role === 'lab') {
                $s_modules = util::decode_module_data($sec->moduledata ?? '', true);
                foreach ($s_modules as $sm) {
                    $mentors = ($active_role === 'class')
                        ? [($sm['primarymentor'] ?? ''), ($sm['secondarymentor'] ?? '')]
                        : [($sm['labmentor1'] ?? ''), ($sm['labmentor2'] ?? ''), ($sm['labmentor3'] ?? '')];

                    $is_mentor_in_mod = false;
                    foreach ($mentors as $m_cand) {
                        if ($is_user_match($m_cand)) {
                            $is_mentor_in_mod = true;
                            break;
                        }
                    }

                    if (!$is_mentor_in_mod && $personas['has_mentor_role']) {
                        $cid = (int)($sm['moodlecourseid'] ?? 0);
                        if ($cid > 0) {
                            $c_ctx = \context_course::instance($cid, IGNORE_MISSING);
                            if ($c_ctx && (is_enrolled($c_ctx, $userid) || has_capability('moodle/course:update', $c_ctx, $userid))) {
                                $is_mentor_in_mod = true;
                            }
                        }
                    }

                    if ($is_mentor_in_mod) {
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

                    // Check if mentor assigned to this specific module
                    $mentors = ($active_role === 'class')
                        ? [($sm['primarymentor'] ?? ''), ($sm['secondarymentor'] ?? '')]
                        : ($active_role === 'lab'
                            ? [($sm['labmentor1'] ?? ''), ($sm['labmentor2'] ?? ''), ($sm['labmentor3'] ?? '')]
                            : [($sm['primarymentor'] ?? ''), ($sm['secondarymentor'] ?? ''), ($sm['labmentor1'] ?? ''), ($sm['labmentor2'] ?? ''), ($sm['labmentor3'] ?? '')]);

                    $is_assigned = false;
                    foreach ($mentors as $m_cand) {
                        if ($is_user_match($m_cand)) {
                            $is_assigned = true;
                            break;
                        }
                    }

                    if (!$is_assigned && $personas['has_mentor_role']) {
                        $c_ctx = \context_course::instance($courseid, IGNORE_MISSING);
                        if ($c_ctx && (is_enrolled($c_ctx, $userid) || has_capability('moodle/course:update', $c_ctx, $userid))) {
                            $is_assigned = true;
                        }
                    }

                    if (!$is_assigned) {
                        continue;
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
                'lbl'   => 'Tasks due this week',
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
