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

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/util.php');
require_once(__DIR__ . '/mentor_activity_service.php');
require_once(__DIR__ . '/activity_tracker_service.php');

/**
 * Service for operational role resolution, To-Do task generation,
 * and completion tracking for Mentors, SS Executives, PMs, and AMs.
 *
 * @package    local_batchanalytics
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
        $val = get_config('local_batchanalytics', $setting_name);
        if ($val === false || $val === null || trim((string)$val) === '') {
            $val = get_config('block_batchanalytics', $setting_name);
        }
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
        if ((has_capability('local/batchanalytics:view', $context, $userid) || has_capability('block/batchanalytics:view', $context, $userid))) {
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
                            if ($c_ctx && is_enrolled($c_ctx, $userid)) {
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

        // Normalize requested role to the 4 operational types: mentors, sspm, am, admin
        $normalized_role = trim($requested_role);
        if (in_array($normalized_role, ['class', 'lab', 'mentor'], true)) {
            $normalized_role = 'mentors';
        } else if (in_array($normalized_role, ['sse', 'pm'], true)) {
            $normalized_role = 'sspm';
        } else if (in_array($normalized_role, ['asst', 'assistant'], true)) {
            $normalized_role = 'am';
        } else if (in_array($normalized_role, ['all'], true)) {
            $normalized_role = 'admin';
        }

        // Define the 4 operational types: admin, mentors, sspm, am
        $is_siteadmin = is_siteadmin($userid);
        $available_roles = [];
        if ($is_siteadmin) {
            $available_roles = [
                'admin'   => 'Admin',
                'mentors' => 'Mentors',
                'sspm'    => 'SS / PM',
                'am'      => 'Assistant Manager',
            ];
            $default_role = 'admin';
        } else {
            if ($personas['is_asst']) {
                $available_roles['am'] = 'Assistant Manager';
            }
            if ($personas['is_sse'] || $personas['is_pm'] || $personas['assigned_sse'] || $personas['assigned_pm']) {
                $available_roles['sspm'] = 'SS / PM';
            }
            if ($personas['is_mentor']) {
                $available_roles['mentors'] = 'Mentors';
            }

            // Fallback for managers with no explicit persona mapped
            if (empty($available_roles) && $is_manager) {
                $available_roles = [
                    'am'      => 'Assistant Manager',
                    'sspm'    => 'SS / PM',
                    'mentors' => 'Mentors',
                ];
            }

            if (empty($available_roles)) {
                $available_roles['mentors'] = 'Mentors';
                $default_role = 'mentors';
            } else if ($personas['is_asst']) {
                $default_role = 'am';
            } else if ($personas['assigned_sse'] || $personas['assigned_pm'] || $personas['is_sse'] || $personas['is_pm']) {
                $default_role = 'sspm';
            } else if ($personas['is_mentor']) {
                $default_role = 'mentors';
            } else {
                $default_role = array_key_first($available_roles);
            }
        }

        $active_role = ($normalized_role !== '' && isset($available_roles[$normalized_role])) ? $normalized_role : $default_role;

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
            if ($active_role === 'admin' || $active_role === 'am') {
                $match = true;
            } else if ($active_role === 'sspm') {
                if ($is_user_match($sec->maacexecutive) || $is_user_match($sec->maacexecutivename)
                    || $is_user_match($sec->pmmanager) || $is_user_match($sec->pmmanagername)) {
                    $match = true;
                } else if (!$personas['assigned_sse'] && !$personas['assigned_pm'] && ($personas['is_sse'] || $personas['is_pm'] || $is_manager)) {
                    $match = true;
                }
            } else if ($active_role === 'mentors') {
                $s_modules = util::decode_module_data($sec->moduledata ?? '', true);
                foreach ($s_modules as $sm) {
                    $mentors = [
                        $sm['primarymentor'] ?? '',
                        $sm['secondarymentor'] ?? '',
                        $sm['labmentor1'] ?? '',
                        $sm['labmentor2'] ?? '',
                        $sm['labmentor3'] ?? ''
                    ];

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
                            if ($c_ctx && is_enrolled($c_ctx, $userid)) {
                                $is_mentor_in_mod = true;
                            }
                        }
                    }

                    if ($is_mentor_in_mod) {
                        $match = true;
                        break;
                    }
                }
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
        // 1. MENTOR ACTIVITIES (for Mentors or Admin)
        // ---------------------------------------------------------------------
        if ($active_role === 'mentors' || $active_role === 'admin') {
            foreach ($filtered_sections as $sec) {
                $s_modules = util::decode_module_data($sec->moduledata ?? '', true);
                foreach ($s_modules as $sm) {
                    $courseid = (int)($sm['moodlecourseid'] ?? 0);
                    if ($courseid <= 0) {
                        continue;
                    }

                    if ($active_role === 'mentors') {
                        $mentors = [
                            $sm['primarymentor'] ?? '',
                            $sm['secondarymentor'] ?? '',
                            $sm['labmentor1'] ?? '',
                            $sm['labmentor2'] ?? '',
                            $sm['labmentor3'] ?? ''
                        ];

                        $is_assigned = false;
                        foreach ($mentors as $m_cand) {
                            if ($is_user_match($m_cand)) {
                                $is_assigned = true;
                                break;
                            }
                        }

                        if (!$is_assigned && $personas['has_mentor_role']) {
                            $c_ctx = \context_course::instance($courseid, IGNORE_MISSING);
                            if ($c_ctx && is_enrolled($c_ctx, $userid)) {
                                $is_assigned = true;
                            }
                        }

                        if (!$is_assigned) {
                            continue;
                        }
                    }

                    $mod_name = $sm['name'] ?? 'Module';
                    $mod_p_start = (int)($sm['plannedstart'] ?? 0);

                    try {
                        $mdata = mentor_activity_service::get_course_mentor_activities($courseid, $mod_name, $mod_p_start, (int)$sec->id);
                        $activities = $mdata['activities'] ?? [];

                        foreach ($activities as $act) {
                            if (!empty($act['completed'])) {
                                continue;
                            }
                            $act_k = mb_strtolower(trim($act['key'] ?? ''));
                            $act_n = mb_strtolower(trim($act['name'] ?? ''));
                            if (in_array($act_k, ['test_evaluation', 'module_test_eveluation', 'module_test_evaluation'], true) ||
                                (strpos($act_n, 'test') !== false && strpos($act_n, 'eval') !== false)) {
                                continue;
                            }

                            $p_ts = (int)($act['planned_ts'] ?? 0);
                            if ($p_ts <= 0) {
                                continue;
                            }

                            $act_name = $act['name'];
                            $batch_name = $sec->name ?: ('Batch ' . $sec->id);

                            $dest_url = (new \moodle_url('/local/batchanalytics/module.php', [
                                'courseid'  => $courseid,
                                'sectionid' => $sec->id,
                                'batchid'   => $sec->id,
                                'module'    => (int)($sm['module'] ?? 1),
                            ]))->out(false);

                            if ($p_ts < $today_midnight) {
                                $days = max(1, (int)floor(($today_midnight - $p_ts) / 86400));
                                $todo_list[] = [
                                    'id'            => 'mentor_' . $sec->id . '_' . $courseid . '_' . $act['key'],
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
                                    'id'            => 'mentor_' . $sec->id . '_' . $courseid . '_' . $act['key'],
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
                                    'id'            => 'mentor_' . $sec->id . '_' . $courseid . '_' . $act['key'],
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
                            }

                            // Forthcoming: strictly next 7 days only
                            if ($p_ts >= $today_midnight && $p_ts <= $next_week_end) {
                                $days = (int)floor(($p_ts - $today_midnight) / 86400);
                                $forthcoming_list[] = [
                                    'title' => $act_name . ' — ' . $mod_name,
                                    'meta'  => 'Batch ' . $batch_name . ' · ' . ($days === 0 ? 'today' : 'in ' . $days . ' days'),
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
        // 2. SS / PM ACTIVITIES (for SS / PM or Admin)
        // ---------------------------------------------------------------------
        if ($active_role === 'sspm' || $active_role === 'admin') {
            foreach ($filtered_sections as $sec) {
                if (empty($sec->softskillsdata)) {
                    continue;
                }

                $ss_list = util::decode_softskills_activities($sec->softskillsdata);
                $batch_name = $sec->name ?: ('Batch ' . $sec->id);
                $dest_url = (new \moodle_url('/local/batchanalytics/batch.php', ['id' => $sec->id]))->out(false);

                foreach ($ss_list as $ss) {
                    $act_name = $ss['activity'];
                    $p_ts = (int)($ss['planned'] ?? 0);
                    $a_ts = (int)($ss['actual'] ?? 0);

                    if ($a_ts > 0 || $p_ts <= 0) {
                        continue; // Already completed or unassigned
                    }

                    if ($p_ts < $today_midnight) {
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
                    }

                    // Forthcoming: strictly next 7 days only
                    if ($p_ts >= $today_midnight && $p_ts <= $next_week_end) {
                        $days = (int)floor(($p_ts - $today_midnight) / 86400);
                        $forthcoming_list[] = [
                            'title' => $act_name,
                            'meta'  => 'Batch ' . $batch_name . ' · ' . ($days === 0 ? 'today' : 'in ' . $days . ' days'),
                            'ts'    => $p_ts,
                        ];
                    }
                }
            }
        }

        // ---------------------------------------------------------------------
        // 3. ASSISTANT MANAGER (AM) MODULE ACTIVITIES (for AM or Admin)
        // Includes: Update closer date, update mentor name, update schedule, enter start date
        // ---------------------------------------------------------------------
        if ($active_role === 'am' || $active_role === 'admin') {
            foreach ($filtered_sections as $sec) {
                if (empty($sec->moduledata)) {
                    continue;
                }

                $modules = util::decode_module_data($sec->moduledata, true);
                if (empty($modules)) {
                    continue;
                }

                $batch_name = $sec->name ?: ('Batch ' . $sec->id);
                $edit_tracker_url = (new \moodle_url('/local/batchmanagement/edit_moduletracker.php', ['id' => $sec->id]))->out(false);

                $module_keys = array_keys($modules);
                $num_modules = count($module_keys);

                for ($i = 0; $i < $num_modules; $i++) {
                    $mod_key = $module_keys[$i];
                    $m = $modules[$mod_key];
                    $mod_name = $m['name'] ?? ('Module ' . ($i + 1));
                    $cid = (int)($m['moodlecourseid'] ?? 0);
                    $p_start = (int)($m['plannedstart'] ?? 0);
                    $p_end = (int)($m['plannedend'] ?? 0);
                    $a_start = (int)($m['actualstart'] ?? 0);
                    $a_end = (int)($m['actualend'] ?? 0);
                    $primary_mentor = trim((string)($m['primarymentor'] ?? ''));

                    // Example 1: Update closer date — {Module Name}
                    // If the module planned end date is over/due but actual end date is empty
                    if ($p_end > 0 && empty($a_end)) {
                        if ($p_end < $today_midnight) {
                            $days = max(1, (int)floor(($today_midnight - $p_end) / 86400));
                            $urgency = 1;
                            $status_class = 'over';
                            $status_label = 'Overdue ' . $days . 'd';
                        } else if ($p_end < $today_end) {
                            $urgency = 2;
                            $status_class = 'today';
                            $status_label = 'Due today';
                        } else if ($p_end <= $next_week_end) {
                            $days = max(1, (int)floor(($p_end - $today_midnight) / 86400));
                            $urgency = 3;
                            $status_class = 'soon';
                            $status_label = 'Due in ' . $days . 'd';
                        } else {
                            $urgency = 0;
                        }

                        if ($urgency > 0) {
                            $closer_url = $edit_tracker_url . '#id_module_' . $mod_key . '_actualend';
                            $todo_list[] = [
                                'id'            => 'am_closer_' . $sec->id . '_' . $mod_key,
                                'title'         => 'Update closer date — ' . $mod_name,
                                'meta'          => 'Batch ' . $batch_name . ' · Planned end ' . userdate($p_end, '%d %b %Y'),
                                'batch_name'    => $batch_name,
                                'urgency_order' => $urgency,
                                'status_class'  => $status_class,
                                'status_label'  => $status_label,
                                'dest_type'     => 'section',
                                'dest_url'      => $closer_url,
                                'action_type'   => 'am_closer',
                                'action_mode'   => 'redirect',
                                'action_url'    => $closer_url,
                                'btn_label'     => 'Update closer date →',
                                'batchid'       => (int)$sec->id,
                                'courseid'      => $cid,
                                'act_key'       => (string)$mod_key,
                                'act_name'      => 'Update closer date — ' . $mod_name,
                                'planned_ts'    => $p_end,
                            ];

                            if ($p_end >= $today_midnight && $p_end <= $next_week_end) {
                                $days = (int)floor(($p_end - $today_midnight) / 86400);
                                $forthcoming_list[] = [
                                    'title' => 'Update closer date — ' . $mod_name,
                                    'meta'  => 'Batch ' . $batch_name . ' · ' . ($days === 0 ? 'today' : 'in ' . $days . ' days'),
                                    'ts'    => $p_end,
                                ];
                            }

                            // Also: If module is ending / upcoming closer date, add "Assign mentor for next module" if not assigned
                            if (($i + 1) < $num_modules) {
                                $next_mod_key = $module_keys[$i + 1];
                                $next_m = $modules[$next_mod_key];
                                $next_primary_mentor = trim((string)($next_m['primarymentor'] ?? ''));
                                $next_a_end = (int)($next_m['actualend'] ?? 0);

                                if (empty($next_a_end) && ($next_primary_mentor === '' || $next_primary_mentor === '0' || strtolower($next_primary_mentor) === 'none')) {
                                    $next_mod_name = $next_m['name'] ?? ('Module ' . ($i + 2));
                                    $next_cid = (int)($next_m['moodlecourseid'] ?? 0);
                                    $next_mentor_url = $edit_tracker_url . '#id_module_' . $next_mod_key . '_primarymentor';

                                    $todo_list[] = [
                                        'id'            => 'am_next_mentor_' . $sec->id . '_' . $next_mod_key,
                                        'title'         => 'Assign mentor for next module — ' . $next_mod_name,
                                        'meta'          => 'Batch ' . $batch_name . ' · ' . $mod_name . ' ending, next module mentor unassigned',
                                        'batch_name'    => $batch_name,
                                        'urgency_order' => $urgency,
                                        'status_class'  => $status_class,
                                        'status_label'  => ($urgency === 1 ? 'Mentor missing' : $status_label),
                                        'dest_type'     => 'section',
                                        'dest_url'      => $next_mentor_url,
                                        'action_type'   => 'am_mentor',
                                        'action_mode'   => 'redirect',
                                        'action_url'    => $next_mentor_url,
                                        'btn_label'     => 'Assign mentor →',
                                        'batchid'       => (int)$sec->id,
                                        'courseid'      => $next_cid,
                                        'act_key'       => (string)$next_mod_key,
                                        'act_name'      => 'Assign mentor for next module — ' . $next_mod_name,
                                        'planned_ts'    => $p_end,
                                    ];

                                    if ($p_end >= $today_midnight && $p_end <= $next_week_end) {
                                        $forthcoming_list[] = [
                                            'title' => 'Assign mentor for next module — ' . $next_mod_name,
                                            'meta'  => 'Batch ' . $batch_name . ' · ' . ($days === 0 ? 'today' : 'in ' . $days . ' days'),
                                            'ts'    => $p_end,
                                        ];
                                    }
                                }
                            }
                        }
                    }

                    // Example 2: Update mentor name — {Module Name}
                    // Check if already queued via previous module's "Assign mentor for next module"
                    $already_has_next_mentor = false;
                    foreach ($todo_list as $existing_t) {
                        if ($existing_t['id'] === 'am_next_mentor_' . $sec->id . '_' . $mod_key) {
                            $already_has_next_mentor = true;
                            break;
                        }
                    }

                    if (!$already_has_next_mentor && empty($a_end) && ($primary_mentor === '' || $primary_mentor === '0' || strtolower($primary_mentor) === 'none')) {
                        if ($p_start > 0) {
                            if ($p_start < $today_midnight) {
                                $days = max(1, (int)floor(($today_midnight - $p_start) / 86400));
                                $urgency = 1;
                                $status_class = 'over';
                                $status_label = 'Mentor missing';
                            } else if ($p_start < $today_end) {
                                $urgency = 2;
                                $status_class = 'today';
                                $status_label = 'Due today';
                            } else if ($p_start <= $next_week_end) {
                                $days = max(1, (int)floor(($p_start - $today_midnight) / 86400));
                                $urgency = 3;
                                $status_class = 'soon';
                                $status_label = 'Due in ' . $days . 'd';
                            } else {
                                $urgency = 0;
                            }

                            if ($urgency > 0) {
                                $mentor_url = $edit_tracker_url . '#id_module_' . $mod_key . '_primarymentor';
                                $todo_list[] = [
                                    'id'            => 'am_mentor_' . $sec->id . '_' . $mod_key,
                                    'title'         => 'Update mentor name — ' . $mod_name,
                                    'meta'          => 'Batch ' . $batch_name . ' · Primary mentor unassigned',
                                    'batch_name'    => $batch_name,
                                    'urgency_order' => $urgency,
                                    'status_class'  => $status_class,
                                    'status_label'  => $status_label,
                                    'dest_type'     => 'section',
                                    'dest_url'      => $mentor_url,
                                    'action_type'   => 'am_mentor',
                                    'action_mode'   => 'redirect',
                                    'action_url'    => $mentor_url,
                                    'btn_label'     => 'Update mentor name →',
                                    'batchid'       => (int)$sec->id,
                                    'courseid'      => $cid,
                                    'act_key'       => (string)$mod_key,
                                    'act_name'      => 'Update mentor name — ' . $mod_name,
                                    'planned_ts'    => $p_start,
                                ];

                                if ($p_start >= $today_midnight && $p_start <= $next_week_end) {
                                    $days = (int)floor(($p_start - $today_midnight) / 86400);
                                    $forthcoming_list[] = [
                                        'title' => 'Update mentor name — ' . $mod_name,
                                        'meta'  => 'Batch ' . $batch_name . ' · ' . ($days === 0 ? 'today' : 'in ' . $days . ' days'),
                                        'ts'    => $p_start,
                                    ];
                                }
                            }
                        }
                    }

                    // Class section update: Enter actual start date
                    if ($p_start > 0 && empty($a_start) && empty($a_end)) {
                        if ($p_start < $today_midnight) {
                            $days = max(1, (int)floor(($today_midnight - $p_start) / 86400));
                            $urgency = 1;
                            $status_class = 'over';
                            $status_label = 'Overdue ' . $days . 'd';
                        } else if ($p_start < $today_end) {
                            $urgency = 2;
                            $status_class = 'today';
                            $status_label = 'Due today';
                        } else if ($p_start <= $next_week_end) {
                            $days = max(1, (int)floor(($p_start - $today_midnight) / 86400));
                            $urgency = 3;
                            $status_class = 'soon';
                            $status_label = 'Due in ' . $days . 'd';
                        } else {
                            $urgency = 0;
                        }

                        if ($urgency > 0) {
                            $start_url = $edit_tracker_url . '#id_module_' . $mod_key . '_actualstart';
                            $todo_list[] = [
                                'id'            => 'am_start_' . $sec->id . '_' . $mod_key,
                                'title'         => 'Enter actual start date — ' . $mod_name,
                                'meta'          => 'Batch ' . $batch_name . ' · Planned start ' . userdate($p_start, '%d %b %Y'),
                                'batch_name'    => $batch_name,
                                'urgency_order' => $urgency,
                                'status_class'  => $status_class,
                                'status_label'  => $status_label,
                                'dest_type'     => 'section',
                                'dest_url'      => $start_url,
                                'action_type'   => 'am_start',
                                'action_mode'   => 'redirect',
                                'action_url'    => $start_url,
                                'btn_label'     => 'Enter start date →',
                                'batchid'       => (int)$sec->id,
                                'courseid'      => $cid,
                                'act_key'       => (string)$mod_key,
                                'act_name'      => 'Enter actual start date — ' . $mod_name,
                                'planned_ts'    => $p_start,
                            ];
                        }
                    }

                    // Example 3: Update schedule — {Next Module Name}
                    // If a module is completed and another module is pending
                    if ($a_end > 0 && ($i + 1) < $num_modules) {
                        $next_mod_key = $module_keys[$i + 1];
                        $next_m = $modules[$next_mod_key];
                        $next_a_end = (int)($next_m['actualend'] ?? 0);

                        // If the next module is pending completion
                        if (empty($next_a_end)) {
                            $next_mod_name = $next_m['name'] ?? ('Module ' . ($i + 2));
                            $next_cid = (int)($next_m['moodlecourseid'] ?? 0);
                            $next_p_start = (int)($next_m['plannedstart'] ?? 0);

                            if ($next_p_start <= 0) {
                                $urgency = 1;
                                $status_class = 'over';
                                $status_label = 'Schedule required';
                                $sched_ts = $a_end;
                            } else if ($next_p_start < $today_midnight) {
                                $days = max(1, (int)floor(($today_midnight - $next_p_start) / 86400));
                                $urgency = 1;
                                $status_class = 'over';
                                $status_label = 'Overdue ' . $days . 'd';
                                $sched_ts = $next_p_start;
                            } else if ($next_p_start < $today_end) {
                                $urgency = 2;
                                $status_class = 'today';
                                $status_label = 'Due today';
                                $sched_ts = $next_p_start;
                            } else if ($next_p_start <= $next_week_end) {
                                $days = max(1, (int)floor(($next_p_start - $today_midnight) / 86400));
                                $urgency = 3;
                                $status_class = 'soon';
                                $status_label = 'Due in ' . $days . 'd';
                                $sched_ts = $next_p_start;
                            } else {
                                $urgency = 3;
                                $status_class = 'soon';
                                $days = max(1, (int)floor(($next_p_start - $today_midnight) / 86400));
                                $status_label = 'In ' . $days . 'd';
                                $sched_ts = $next_p_start;
                            }

                            $sched_url = $edit_tracker_url . '#id_module_' . $next_mod_key . '_plannedstart';
                            $todo_list[] = [
                                'id'            => 'am_sched_' . $sec->id . '_' . $next_mod_key,
                                'title'         => 'Update schedule — ' . $next_mod_name,
                                'meta'          => 'Batch ' . $batch_name . ' · ' . $mod_name . ' completed, next module pending',
                                'batch_name'    => $batch_name,
                                'urgency_order' => $urgency,
                                'status_class'  => $status_class,
                                'status_label'  => $status_label,
                                'dest_type'     => 'section',
                                'dest_url'      => $sched_url,
                                'action_type'   => 'am_sched',
                                'action_mode'   => 'redirect',
                                'action_url'    => $sched_url,
                                'btn_label'     => 'Update schedule →',
                                'batchid'       => (int)$sec->id,
                                'courseid'      => $next_cid,
                                'act_key'       => (string)$next_mod_key,
                                'act_name'      => 'Update schedule — ' . $next_mod_name,
                                'planned_ts'    => $sched_ts,
                            ];

                            if ($sched_ts >= $today_midnight && $sched_ts <= $next_week_end) {
                                $days = (int)floor(($sched_ts - $today_midnight) / 86400);
                                $forthcoming_list[] = [
                                    'title' => 'Update schedule — ' . $next_mod_name,
                                    'meta'  => 'Batch ' . $batch_name . ' · ' . ($days === 0 ? 'today' : 'in ' . $days . ' days'),
                                    'ts'    => $sched_ts,
                                ];
                            }
                        }
                    }
                }
            }
        }

        // Deduplicate tasks by ID
        $dedup = [];
        $unique_todos = [];
        foreach ($todo_list as $t) {
            if (!isset($dedup[$t['id']])) {
                $dedup[$t['id']] = true;
                $unique_todos[] = $t;
            }
        }
        $todo_list = $unique_todos;

        // Sort To-Do list: Overdue first (1), Due today (2), Due soon (3), then by planned timestamp
        usort($todo_list, static function($a, $b) {
            if ($a['urgency_order'] !== $b['urgency_order']) {
                return $a['urgency_order'] <=> $b['urgency_order'];
            }
            return $a['planned_ts'] <=> $b['planned_ts'];
        });

        // Sort Forthcoming strictly by date ascending
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
        $role_labels = [
            'admin'   => 'Admin · Operational Cockpit (All Activities)',
            'mentors' => 'Mentors · Module Activities',
            'sspm'    => 'SS / PM · Soft Skills Activities',
            'am'      => 'Assistant Manager · Module Operations',
        ];
        $role_base = $role_labels[$active_role] ?? 'Operational View';
        $role_subtitle = $role_base . ' · ' . $batches_count . ' ' . ($batches_count === 1 ? 'batch' : 'batches');

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

        // Collect distinct batches for filtering
        $batch_options = [];
        foreach ($todo_list as $t) {
            $bid = (string)($t['batchid'] ?? '');
            $bname = trim((string)($t['batch_name'] ?? ''));
            if ($bid !== '' && $bname !== '' && !isset($batch_options[$bid])) {
                $batch_options[$bid] = [
                    'id'   => $t['batchid'],
                    'name' => $bname,
                ];
            }
        }
        foreach ($filtered_sections as $sec) {
            $bid = (string)$sec->id;
            $bname = trim((string)($sec->name ?: ('Batch ' . $sec->id)));
            if (!isset($batch_options[$bid])) {
                $batch_options[$bid] = [
                    'id'   => (int)$sec->id,
                    'name' => $bname,
                ];
            }
        }
        usort($batch_options, static function($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });

        return [
            'greeting'         => $greeting,
            'role_subtitle'    => $role_subtitle,
            'active_role'      => $active_role,
            'can_switch_roles' => ($is_manager || count($available_roles) > 1),
            'available_roles'  => $available_roles,
            'glance'           => $glance,
            'batches'          => array_values($batch_options),
            'todo'             => $todo_list,
            'forthcoming'      => array_slice($forthcoming_list, 0, 10),
            'total_pending'    => count($todo_list),
        ];
    }

    /**
     * Validate whether an activity can be completed, returning pending and completed counts, activity URL, and items.
     *
     * @param int $userid
     * @param string $action_type 'mentor', 'ss', 'am_start', etc.
     * @param array $params
     * @return array
     */
    public static function validate_task_completion(int $userid, string $action_type, array $params): array {
        global $DB;

        if ($action_type === 'mentor') {
            $courseid = (int)($params['courseid'] ?? 0);
            $act_name = trim((string)($params['act_name'] ?? ''));
            $batchid  = (int)($params['batchid'] ?? 0);
            $cmid     = (int)($params['cmid'] ?? 0);

            if ($courseid <= 0 && $cmid <= 0) {
                return [
                    'success'         => false,
                    'message'         => 'Invalid course ID or module ID',
                    'has_pending'     => false,
                    'pending_count'   => 0,
                    'completed_count' => 0,
                    'can_complete'    => false,
                ];
            }

            $pending_check = activity_tracker_service::check_pending_submissions($courseid, $cmid, $act_name, $batchid);

            return [
                'success'         => true,
                'action_type'     => 'mentor',
                'has_pending'     => $pending_check['has_pending'],
                'count'           => $pending_check['count'],
                'pending_count'   => $pending_check['pending_count'],
                'completed_count' => $pending_check['completed_count'],
                'total_count'     => $pending_check['total_count'],
                'activity_name'   => $pending_check['activity_name'] ?: $act_name,
                'activity_url'    => $pending_check['activity_url'],
                'items'           => $pending_check['items'] ?? [],
                'message'         => $pending_check['message'],
                'details'         => $pending_check['details'],
                'can_complete'    => !$pending_check['has_pending'],
            ];
        }

        // Non-mentor activities (e.g. SS milestone dates, PM checklist, AM dates)
        return [
            'success'         => true,
            'action_type'     => $action_type,
            'has_pending'     => false,
            'count'           => 0,
            'pending_count'   => 0,
            'completed_count' => 0,
            'total_count'     => 0,
            'activity_name'   => $params['act_name'] ?? '',
            'activity_url'    => '',
            'items'           => [],
            'message'         => 'Milestone activity. Ready to mark complete.',
            'details'         => [],
            'can_complete'    => true,
        ];
    }

    /**
     * Mark an operational activity complete.
     *
     * @param int $userid
     * @param string $action_type 'mentor', 'ss', 'am_start', 'am_end', or 'am_mentor'
     * @param array $params
     * @return array
     * @throws \moodle_exception
     */
    public static function mark_activity_complete(int $userid, string $action_type, array $params): array {
        global $DB;

        if ($action_type === 'mentor') {
            $courseid = (int)($params['courseid'] ?? 0);
            $act_name = trim((string)($params['act_name'] ?? ''));
            $batchid  = (int)($params['batchid'] ?? 0);
            $cmid     = (int)($params['cmid'] ?? 0);
            if ($courseid <= 0 || $act_name === '') {
                throw new \moodle_exception('invalidparams', 'local_batchanalytics');
            }

            // Check if there are pending / ungraded submissions before completing
            $pending_check = activity_tracker_service::check_pending_submissions($courseid, $cmid, $act_name, $batchid);
            if ($pending_check['has_pending']) {
                return [
                    'success'         => false,
                    'error'           => $pending_check['message'],
                    'message'         => $pending_check['message'],
                    'count'           => $pending_check['count'],
                    'pending_count'   => $pending_check['pending_count'],
                    'completed_count' => $pending_check['completed_count'],
                    'total_count'     => $pending_check['total_count'],
                    'activity_url'    => $pending_check['activity_url'],
                    'items'           => $pending_check['items'] ?? [],
                    'details'         => $pending_check['details'],
                ];
            }

            $updated = mentor_activity_service::save_activity_status($courseid, $act_name, true, date('Y-m-d'), $userid, $batchid);
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
                throw new \moodle_exception('invalidparams', 'local_batchanalytics');
            }

            $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
            if (!$sec) {
                throw new \moodle_exception('invalidbatch', 'local_batchanalytics');
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

        if ($action_type === 'am_start' || $action_type === 'am_end' || $action_type === 'am_closer' || $action_type === 'am_mentor' || $action_type === 'am_sched') {
            $batchid = (int)($params['batchid'] ?? 0);
            $mod_key = trim((string)($params['act_key'] ?? ''));
            if ($batchid <= 0 || $mod_key === '') {
                throw new \moodle_exception('invalidparams', 'local_batchanalytics');
            }

            $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
            if (!$sec) {
                throw new \moodle_exception('invalidbatch', 'local_batchanalytics');
            }

            $modules = json_decode($sec->moduledata ?? '', true);
            if (!is_array($modules) || !isset($modules[$mod_key])) {
                throw new \moodle_exception('invalidmodule', 'local_batchanalytics');
            }

            $now = time();
            if ($action_type === 'am_start') {
                $modules[$mod_key]['actualstart'] = $now;
            } else if ($action_type === 'am_end' || $action_type === 'am_closer') {
                $modules[$mod_key]['actualend'] = $now;
                if (empty($modules[$mod_key]['actualstart'])) {
                    $modules[$mod_key]['actualstart'] = (int)($modules[$mod_key]['plannedstart'] ?? $now);
                }
            } else if ($action_type === 'am_mentor') {
                if (empty($modules[$mod_key]['primarymentor'])) {
                    $modules[$mod_key]['primarymentor'] = (string)$userid;
                }
            } else if ($action_type === 'am_sched') {
                if (empty($modules[$mod_key]['plannedstart'])) {
                    $modules[$mod_key]['plannedstart'] = $now;
                }
            }

            $sec->moduledata = json_encode($modules);
            $sec->timemodified = $now;
            $DB->update_record('local_bm_classsection', $sec);

            return [
                'success' => true,
                'type'    => $action_type,
                'batchid' => $batchid,
                'mod_key' => $mod_key,
                'now'     => $now,
            ];
        }

        throw new \moodle_exception('invalidaction', 'local_batchanalytics');
    }
}
