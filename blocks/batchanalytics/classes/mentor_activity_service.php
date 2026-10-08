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

/**
 * Service for managing mentor operational activities, course grouping, due days, and single-record JSON storage.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mentor_activity_service {

    /** @var string Table name storing mentor activity records */
    public const TABLE_NAME = 'local_batchanalytics_mentor_act';

    /**
     * Get the resolved active table name (supports legacy table fallback).
     *
     * @return string
     */
    public static function get_table_name(): string {
        global $DB;
        static $tbl = null;
        if ($tbl !== null) {
            return $tbl;
        }
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_batchanalytics_mentor_act')) {
            $tbl = 'local_batchanalytics_mentor_act';
        } else if ($dbman->table_exists('block_batchanalytics_mentor_act')) {
            $tbl = 'block_batchanalytics_mentor_act';
        } else {
            $tbl = 'local_batchanalytics_mentor_act';
        }
        return $tbl;
    }

    /** @var array Default pool of master mentor activities with field API keys */
    public const DEFAULT_MASTER_ACTIVITIES = [
        ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation', 'desc' => 'Assignment evaluation', 'category' => 'Evaluation'],
        ['key' => 'project_evaluation', 'name' => 'Project evaluation', 'desc' => 'Project evaluation', 'category' => 'Evaluation'],
        ['key' => 'spot_award_nomination_mid_c', 'name' => 'Spot award nomination - mid c', 'desc' => 'Spot award nomination - mid c', 'category' => 'Award'],
        ['key' => 'spot_award_nomination_end_c', 'name' => 'Spot award nomination - End C', 'desc' => 'Spot award nomination - End C', 'category' => 'Award'],
        ['key' => 'spot_award_nomination', 'name' => 'Spot award nomination', 'desc' => 'Spot award nomination', 'category' => 'Award'],
        ['key' => 'power_track_nomination', 'name' => 'Power track nomination', 'desc' => 'Power track nomination', 'category' => 'Nomination'],
        ['key' => 'module_test_evaluation', 'name' => 'Module test evaluation', 'desc' => 'Module test evaluation', 'category' => 'Evaluation'],
        ['key' => 'quiz_evaluation', 'name' => 'Quiz evaluation', 'desc' => 'Quiz evaluation', 'category' => 'Evaluation'],
    ];

    /** @var array Default grouping rules with due days */
    public const DEFAULT_GROUPING_RULES = [
        [
            'group' => 'Linux Systems',
            'activities' => [
                ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation', 'duedays' => 3],
                ['key' => 'quiz_evaluation', 'name' => 'Quiz evaluation', 'duedays' => 4],
                ['key' => 'spot_award_nomination', 'name' => 'Spot award nomination', 'duedays' => 5],
            ],
        ],
        [
            'group' => 'Advanced C',
            'activities' => [
                ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation', 'duedays' => 5],
                ['key' => 'project_evaluation', 'name' => 'Project evaluation', 'duedays' => 10],
                ['key' => 'spot_award_nomination_mid_c', 'name' => 'Spot award nomination - mid c', 'duedays' => 15],
                ['key' => 'spot_award_nomination_end_c', 'name' => 'Spot award nomination - End C', 'duedays' => 20],
                ['key' => 'power_track_nomination', 'name' => 'Power track nomination', 'duedays' => 25],
                ['key' => 'quiz_evaluation', 'name' => 'Quiz evaluation', 'duedays' => 30],
            ],
        ],
        [
            'group' => 'Data Structure',
            'activities' => [
                ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation', 'duedays' => 5],
                ['key' => 'project_evaluation', 'name' => 'Project evaluation', 'duedays' => 15],
                ['key' => 'quiz_evaluation', 'name' => 'Quiz evaluation', 'duedays' => 12],
            ],
        ],
        [
            'group' => 'MicroController',
            'activities' => [
                ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation', 'duedays' => 5],
                ['key' => 'project_evaluation', 'name' => 'Project evaluation', 'duedays' => 10],
                ['key' => 'quiz_evaluation', 'name' => 'Quiz evaluation', 'duedays' => 12],
            ],
        ],
        [
            'group' => 'C++',
            'activities' => [
                ['key' => 'quiz_evaluation', 'name' => 'Quiz evaluation', 'duedays' => 5],
            ],
        ],
        [
            'group' => 'Linux Internals and TCP/IP Networking',
            'activities' => [
                ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation', 'duedays' => 5],
                ['key' => 'project_evaluation', 'name' => 'Project evaluation', 'duedays' => 10],
                ['key' => 'quiz_evaluation', 'name' => 'Quiz evaluation', 'duedays' => 15],
            ],
        ],
        [
            'group' => 'Qt / QML',
            'activities' => [
                ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation', 'duedays' => 5],
                ['key' => 'project_evaluation', 'name' => 'Project evaluation', 'duedays' => 8],
                ['key' => 'quiz_evaluation', 'name' => 'Quiz evaluation', 'duedays' => 7],
            ],
        ],
        [
            'group' => 'ELARM',
            'activities' => [
                ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation', 'duedays' => 5],
                ['key' => 'project_evaluation', 'name' => 'Project evaluation', 'duedays' => 8],
                ['key' => 'quiz_evaluation', 'name' => 'Quiz evaluation', 'duedays' => 7],
            ],
        ],
    ];

    /**
     * Check if the database table exists.
     *
     * @return bool
     */
    public static function is_table_available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(self::get_table_name());
    }

    /**
     * Helper to slugify an activity name into a clean API field key.
     *
     * @param string $name
     * @return string
     */
    public static function slugify_key(string $name): string {
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', trim($name));
        $slug = strtolower(trim($slug, '_'));
        return $slug ?: 'act_' . substr(md5($name), 0, 8);
    }

    /**
     * Calculate target date by adding N working days (excluding Saturdays and Sundays).
     *
     * @param int $start_ts Start timestamp (midnight).
     * @param int $working_days Number of working days from start date.
     * @return int Calculated timestamp.
     */
    public static function add_working_days(int $start_ts, int $working_days): int {
        if ($start_ts <= 0) {
            return 0;
        }

        $curr = strtotime('today midnight', $start_ts);
        // If start date itself is on a weekend, roll forward to Monday first
        $dow = (int)date('w', $curr);
        if ($dow === 6) { // Saturday
            $curr = strtotime('+2 days', $curr);
        } else if ($dow === 0) { // Sunday
            $curr = strtotime('+1 day', $curr);
        }

        $working_days = max(0, $working_days);
        $added = 0;
        while ($added < $working_days) {
            $curr = strtotime('+1 day', $curr);
            $w = (int)date('w', $curr);
            if ($w !== 0 && $w !== 6) { // Skip Sunday (0) and Saturday (6)
                $added++;
            }
        }

        return $curr;
    }

    /**
     * Compute action status, label, and CSS class for a mentor activity.
     * Displays:
     * - 'Completed' (st-g) if completed
     * - 'Upcoming' (st-b) if planned due date is future or not set
     * - 'Pending' (st-a) if overdue by less than 5 days
     * - 'Overdue (X days)' (st-r) if overdue by 5 or more days
     *
     * @param bool $completed Whether the activity is completed.
     * @param int $planned_ts Planned due date timestamp.
     * @return array{status: string, label: string, class: string, overdue_days: int}
     */
    public static function compute_action_status(bool $completed, int $planned_ts): array {
        if ($completed) {
            return [
                'status'       => 'completed',
                'label'        => 'Completed',
                'class'        => 'st-g',
                'overdue_days' => 0,
            ];
        }

        if ($planned_ts <= 0) {
            return [
                'status'       => 'upcoming',
                'label'        => 'Upcoming',
                'class'        => 'st-b',
                'overdue_days' => 0,
            ];
        }

        $today_midnight = strtotime('today midnight');
        $today_end      = $today_midnight + 86400;

        if ($planned_ts >= $today_midnight && $planned_ts < $today_end) {
            return [
                'status'       => 'due_today',
                'label'        => 'Due Today',
                'class'        => 'st-a',
                'overdue_days' => 0,
            ];
        }

        if ($planned_ts >= $today_end) {
            return [
                'status'       => 'upcoming',
                'label'        => 'Upcoming',
                'class'        => 'st-b',
                'overdue_days' => 0,
            ];
        }

        $overdue_days = (int)round(($today_midnight - $planned_ts) / 86400);
        if ($overdue_days < 5) {
            return [
                'status'       => 'pending',
                'label'        => 'Pending',
                'class'        => 'st-a',
                'overdue_days' => $overdue_days,
            ];
        }

        $days_str = $overdue_days === 1 ? '1 day' : $overdue_days . ' days';
        return [
            'status'       => 'overdue',
            'label'        => 'Overdue (' . $days_str . ')',
            'class'        => 'st-r',
            'overdue_days' => $overdue_days,
        ];
    }

    /**
     * Get the master list of mentor activities configured in Site Admin.
     * Returns an array of items: [['key' => '...', 'name' => '...'], ...]
     *
     * @return array<int, array{key: string, name: string}>
     */
    public static function get_master_activities(): array {
        $raw = util::get_config_val('mentor_master_activities');
        $activities = [];
        $seen_keys = [];

        if ($raw !== false && trim((string)$raw) !== '') {
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_string($item)) {
                        $name = trim(strip_tags($item));
                        $key = self::slugify_key($name);
                    } else if (is_array($item)) {
                        $name = trim(strip_tags((string)($item['name'] ?? '')));
                        $key = trim(strip_tags((string)($item['key'] ?? '')));
                        if ($key === '' && $name !== '') {
                            $key = self::slugify_key($name);
                        }
                    } else {
                        continue;
                    }

                    if ($name !== '' && !isset($seen_keys[$key])) {
                        $seen_keys[$key] = true;
                        $activities[] = [
                            'key'  => $key,
                            'name' => $name,
                        ];
                    }
                }
            } else {
                $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
                foreach ($lines as $line) {
                    $name = trim(strip_tags($line));
                    if ($name !== '') {
                        $key = self::slugify_key($name);
                        if (!isset($seen_keys[$key])) {
                            $seen_keys[$key] = true;
                            $activities[] = [
                                'key'  => $key,
                                'name' => $name,
                            ];
                        }
                    }
                }
            }
        }

        // Always ensure default master activities (including quiz_evaluation & test_evaluation) are included
        foreach (self::DEFAULT_MASTER_ACTIVITIES as $def) {
            if (!isset($seen_keys[$def['key']])) {
                $seen_keys[$def['key']] = true;
                $activities[] = $def;
            }
        }

        return $activities;
    }

    /**
     * Get the parsed course grouping rules with due days.
     * Returns associative array: group name => list of activity objects.
     *
     * @return array<string, array<int, array{key: string, name: string, duedays: int}>>
     */
    public static function get_grouping_rules(): array {
        $raw = util::get_config_val('mentor_activity_grouping');
        $rules = [];

        if ($raw !== false && trim((string)$raw) !== '') {
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $grp = trim(strip_tags((string)($item['group'] ?? '')));
                    if ($grp === '') {
                        continue;
                    }
                    $raw_acts = $item['activities'] ?? [];
                    if (is_string($raw_acts)) {
                        $raw_acts = explode(',', $raw_acts);
                    }

                    $clean_acts = [];
                    $seen_keys = [];

                    if (is_array($raw_acts)) {
                        foreach ($raw_acts as $act) {
                            if (is_string($act)) {
                                $name = trim(strip_tags($act));
                                $key = self::slugify_key($name);
                                $duedays = 5;
                            } else if (is_array($act)) {
                                $name = trim(strip_tags((string)($act['name'] ?? '')));
                                $key = trim(strip_tags((string)($act['key'] ?? '')));
                                if ($key === '' && $name !== '') {
                                    $key = self::slugify_key($name);
                                }
                                $duedays = isset($act['duedays']) ? (int)$act['duedays'] : 5;
                            } else {
                                continue;
                            }

                            if ($name !== '' && !isset($seen_keys[$key])) {
                                $seen_keys[$key] = true;
                                $clean_acts[] = [
                                    'key'     => $key,
                                    'name'    => $name,
                                    'duedays' => max(0, $duedays),
                                ];
                            }
                        }
                    }

                    if (!empty($clean_acts)) {
                        $rules[$grp] = $clean_acts;
                    }
                }
            }
        }

        foreach (self::DEFAULT_GROUPING_RULES as $def_grp) {
            if (isset($def_grp['group']) && isset($def_grp['activities']) && !isset($rules[$def_grp['group']])) {
                $rules[$def_grp['group']] = $def_grp['activities'];
            }
        }

        return $rules;
    }

    /**
     * Get available group options for course settings select dropdown.
     *
     * @return array<string, string>
     */
    public static function get_available_group_options(): array {
        $options = ['none' => get_string('mentor_activity_none', 'local_batchanalytics')];
        $rules = self::get_grouping_rules();
        foreach (array_keys($rules) as $grp) {
            $options[$grp] = $grp;
        }
        return $options;
    }

    /**
     * Match a group name against a class section module name.
     * Supports exact match, case-insensitive substring match, and normalized keyword match.
     *
     * @param string $group Group name configured in Site Admin.
     * @param string $modname Module name from class section moduledata.
     * @return bool
     */
    public static function match_group_to_module_name(string $group, string $modname): bool {
        $g = trim($group);
        $m = trim($modname);

        if ($g === '' || $m === '') {
            return false;
        }

        if (strcasecmp($g, $m) === 0) {
            return true;
        }

        // Exact word match using regex with word boundaries
        $pattern = '/\b' . preg_quote($g, '/') . '\b/i';
        if (@preg_match($pattern, $m)) {
            return true;
        }

        // Substring match if group name is at least 3 characters
        if (mb_strlen($g) >= 3 && (mb_stripos($m, $g) !== false || mb_stripos($g, $m) !== false)) {
            return true;
        }

        // Word-stem match (e.g. "Advance C" vs "Advanced C", "Data Structures" vs "Data Structure")
        $stem = static function(string $str): array {
            $words = preg_split('/[^a-zA-Z0-9]+/', mb_strtolower($str), -1, PREG_SPLIT_NO_EMPTY);
            $stemmed = array_map(static function($w) {
                return preg_replace('/(ed|ing|s)$/', '', $w);
            }, $words);
            return array_values(array_filter($stemmed));
        };

        $stem_g = $stem($g);
        $stem_m = $stem($m);

        if (!empty($stem_g) && !empty($stem_m)) {
            $all_found = true;
            foreach ($stem_g as $w) {
                if (mb_strlen($w) <= 1) {
                    $found_word = false;
                    foreach ($stem_m as $mw) {
                        if ($mw === $w) {
                            $found_word = true;
                            break;
                        }
                    }
                    if (!$found_word) {
                        $all_found = false;
                        break;
                    }
                } else {
                    $found_sub = false;
                    foreach ($stem_m as $mw) {
                        if (mb_stripos($mw, $w) !== false || mb_stripos($w, $mw) !== false) {
                            $found_sub = true;
                            break;
                        }
                    }
                    if (!$found_sub) {
                        $all_found = false;
                        break;
                    }
                }
            }
            if ($all_found) {
                return true;
            }
        }

        $aliases = [
            'Linux Systems' => ['ls', 'linux system', 'linux systems', 'linux'],
            'Advanced C'    => ['adv c', 'advc', 'advance c', 'advanced c', 'c programming'],
            'Data Structure'=> ['ds', 'data structure', 'data structures', 'dsa'],
            'MicroController'=> ['mc', 'microcontroller', 'microcontrollers', 'micro controller', 'micro-controller'],
            'Linux Internals and TCP/IP Networking' => ['li', 'linux internals', 'linux internal', 'internals', 'tcp/ip', 'networking', 'linux internals & tcp/ip networking'],
            'Qt / QML'      => ['qt', 'qml', 'qt / qml', 'qt/qml', 'qt and qml', 'qt & qml'],
            'ELARM'         => ['elarm'],
            'C++'           => ['c++', 'cpp', 'c plus plus'],
        ];
        if (isset($aliases[$g])) {
            $m_clean = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/', ' ', $m)));
            $m_tokens = array_filter(explode(' ', $m_clean));
            foreach ($aliases[$g] as $al) {
                if (in_array($al, $m_tokens, true)) {
                    return true;
                }
                if (strpos($al, ' ') !== false && strpos($m_clean, $al) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find the best matching mentor activity group for a given module name.
     * Checks exact match first, then normalized / alias / substring match.
     *
     * @param string $modname Module name.
     * @return string Matched group name or empty string if no match.
     */
    public static function find_matching_group_for_module(string $modname): string {
        $modname = trim($modname);
        if ($modname === '') {
            return '';
        }

        $rules = self::get_grouping_rules();
        if (empty($rules)) {
            return '';
        }

        // 1. Exact case-insensitive match (both names are the same)
        foreach (array_keys($rules) as $grp) {
            if (strcasecmp($grp, $modname) === 0) {
                return $grp;
            }
        }

        // 2. Normalized keyword / alias / stem match
        foreach (array_keys($rules) as $grp) {
            if (self::match_group_to_module_name($grp, $modname)) {
                return $grp;
            }
        }

        return '';
    }

    /**
     * Find the module name mapped to a given course ID in local_bm_classsection or course metadata.
     *
     * @param int $courseid Moodle course ID.
     * @return string Module name or empty string if not mapped.
     */
    public static function get_module_name_for_course(int $courseid): string {
        global $DB;

        if ($courseid <= 0) {
            return '';
        }

        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_bm_classsection')) {
            $sections = $DB->get_records_select(
                'local_bm_classsection',
                $DB->sql_like('moduledata', ':pattern'),
                ['pattern' => '%' . $courseid . '%'],
                'id DESC'
            );

            if (empty($sections)) {
                $sections = $DB->get_records('local_bm_classsection', null, 'id DESC');
            }

            foreach ($sections as $sec) {
                $modules = util::decode_module_data($sec->moduledata, false);
                foreach ($modules as $mod) {
                    $cid = (int)($mod['moodlecourseid'] ?? $mod['courseid'] ?? 0);
                    if ($cid === $courseid) {
                        $name = trim((string)($mod['name'] ?? $mod['courseshortname'] ?? ''));
                        if ($name !== '') {
                            return $name;
                        }
                    }
                }
            }
        }

        // Fallback: check Moodle course fullname/shortname if it matches a known group
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', IGNORE_MISSING);
        if ($course) {
            $fn = trim((string)$course->fullname);
            $sn = trim((string)$course->shortname);
            $rules = self::get_grouping_rules();
            foreach (array_keys($rules) as $grp) {
                if (strcasecmp($grp, $fn) === 0 || strcasecmp($grp, $sn) === 0) {
                    return $grp;
                }
            }
            foreach (array_keys($rules) as $grp) {
                if (self::match_group_to_module_name($grp, $fn) || self::match_group_to_module_name($grp, $sn)) {
                    return $grp;
                }
            }
        }

        return '';
    }

    /**
     * Check class section module names mapped to this course and update course setting if matched.
     *
     * @param int $courseid
     * @return string Matched group name or empty string.
     */
    public static function sync_group_from_class_sections_for_course(int $courseid): string {
        if ($courseid <= 0 || !self::is_table_available()) {
            return '';
        }

        $mod_name = self::get_module_name_for_course($courseid);
        if ($mod_name === '') {
            return '';
        }

        $matched = self::find_matching_group_for_module($mod_name);
        if ($matched !== '') {
            self::save_course_selected_group($courseid, $matched);
            return $matched;
        }

        return '';
    }

    /**
     * Sync and update all courses mapped in class sections whose module names match configured mentor groups.
     * Directly updates the course setting page mentor activity in database.
     *
     * @return int Count of courses updated.
     */
    public static function sync_all_courses_from_class_sections(): int {
        global $DB;

        if (!self::is_table_available()) {
            return 0;
        }

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_bm_classsection')) {
            return 0;
        }

        $rules = self::get_grouping_rules();
        if (empty($rules)) {
            return 0;
        }

        $sections = $DB->get_records('local_bm_classsection', null, 'id ASC');
        $updated_count = 0;

        foreach ($sections as $sec) {
            $modules = util::decode_module_data($sec->moduledata, false);
            foreach ($modules as $mod) {
                $cid = (int)($mod['moodlecourseid'] ?? $mod['courseid'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }

                $mod_name = trim((string)($mod['name'] ?? $mod['courseshortname'] ?? ''));
                if ($mod_name === '') {
                    continue;
                }

                $matched = self::find_matching_group_for_module($mod_name);
                if ($matched !== '' && isset($rules[$matched])) {
                    $rec = $DB->get_record(self::get_table_name(), ['courseid' => $cid], 'selectedgroup', IGNORE_MISSING);
                    $existing = $rec && !empty($rec->selectedgroup) ? trim($rec->selectedgroup) : '';
                    if ($existing !== $matched) {
                        self::save_course_selected_group($cid, $matched);
                        $updated_count++;
                    }
                }
            }
        }

        return $updated_count;
    }

    /**
     * Get the selected group for a course.
     * Automatically selects and saves the group based on the mapped class section module name.
     *
     * @param int $courseid
     * @return string
     */
    public static function get_course_selected_group(int $courseid): string {
        global $DB;
        if ($courseid <= 0 || !self::is_table_available()) {
            return '';
        }

        $rules = self::get_grouping_rules();

        // 1. Check existing saved record in database
        $rec = $DB->get_record(self::get_table_name(), ['courseid' => $courseid], 'selectedgroup', IGNORE_MISSING);
        if ($rec && !empty($rec->selectedgroup)) {
            $selected = trim($rec->selectedgroup);
            if ($selected !== '' && $selected !== '0' && strcasecmp($selected, 'none') !== 0 && isset($rules[$selected])) {
                return $selected;
            }
        }

        // 2. Auto-select based on the module name mapped to this course in local_bm_classsection
        $auto_group = self::sync_group_from_class_sections_for_course($courseid);
        if ($auto_group !== '' && isset($rules[$auto_group])) {
            return $auto_group;
        }

        return '';
    }

    /**
     * Save the selected group for a course.
     *
     * @param int $courseid
     * @param string $groupname
     * @return bool
     */
    public static function save_course_selected_group(int $courseid, string $groupname): bool {
        global $DB;
        if ($courseid <= 0 || !self::is_table_available()) {
            return false;
        }

        $groupname = trim($groupname);
        $rec = $DB->get_record(self::get_table_name(), ['courseid' => $courseid]);

        $now = time();
        if ($rec) {
            $rec->selectedgroup = $groupname;
            $rec->timemodified = $now;
            return $DB->update_record(self::get_table_name(), $rec);
        }

        $newrec = new \stdClass();
        $newrec->courseid = $courseid;
        $newrec->selectedgroup = $groupname;
        $newrec->activitiesdata = json_encode([]);
        $newrec->timemodified = $now;
        return (bool)$DB->insert_record(self::get_table_name(), $newrec);
    }

    /**
     * Resolve the group for a given course.
     * Automatically selects the group based on the module name mapped to the course.
     * If no module name or mapped group matches, returns empty string indicating no mentor activities.
     *
     * @param int $courseid
     * @param string $coursename Module name or course name.
     * @return array{group: string, is_manual: bool}
     */
    public static function resolve_group_for_course(int $courseid, string $coursename = ''): array {
        $rules = self::get_grouping_rules();

        // 1. If coursename (which is passed as the module name) is provided, match directly
        if ($coursename !== '') {
            $matched = self::find_matching_group_for_module($coursename);
            if ($matched !== '' && isset($rules[$matched])) {
                // Ensure this is saved for the course as well if not already set
                if ($courseid > 0) {
                    $saved = self::get_course_selected_group($courseid);
                    if ($saved !== $matched) {
                        self::save_course_selected_group($courseid, $matched);
                    }
                }
                return ['group' => $matched, 'is_manual' => true];
            }
        }

        // 2. If courseid is valid, check DB or auto-resolve from class section module mapping
        if ($courseid > 0) {
            $selected = self::get_course_selected_group($courseid);
            if ($selected !== '' && $selected !== '0' && strcasecmp($selected, 'none') !== 0 && isset($rules[$selected])) {
                return ['group' => $selected, 'is_manual' => true];
            }
        }

        return ['group' => '', 'is_manual' => false];
    }

    /**
     * Retrieve all mentor activities for a course with due dates and completion status.
     *
     * @param int $courseid
     * @param string $coursename
     * @param int $mod_p_start_ts Module planned start timestamp.
     * @param int $sectionid Optional class section ID for isolating section-specific activities.
     * @return array
     */
    public static function get_course_mentor_activities(
        int $courseid,
        string $coursename = '',
        int $mod_p_start_ts = 0,
        int $sectionid = 0,
        bool $sync_evaluations = true
    ): array {
        global $DB;

        $resolved = self::resolve_group_for_course($courseid, $coursename);
        $groupname = $resolved['group'];
        $rules = self::get_grouping_rules();

        if ($groupname === '' || empty($rules[$groupname])) {
            return [
                'courseid'        => $courseid,
                'group'           => '',
                'is_manual'       => false,
                'mod_p_start_ts'  => $mod_p_start_ts,
                'sectionid'       => $sectionid,
                'activities'      => [],
            ];
        }

        $expected_activities = $rules[$groupname];

        $saved_map = [];
        $modifier_ids = [];

        if ($courseid > 0 && self::is_table_available()) {
            // Automatically synchronize evaluation completion & reversion based on gradebook setup and submissions.
            if ($sync_evaluations) {
                try {
                    require_once(__DIR__ . '/activity_tracker_service.php');
                    activity_tracker_service::sync_mentor_evaluation_status($courseid, $sectionid);
                } catch (\Throwable $e) {
                    // Graceful fallback if sync fails.
                }
            }

            $rec = $DB->get_record(self::get_table_name(), ['courseid' => $courseid], 'activitiesdata', IGNORE_MISSING);
            if ($rec && !empty($rec->activitiesdata)) {
                $raw_list = json_decode($rec->activitiesdata, true);
                if (is_array($raw_list)) {
                    foreach ($raw_list as $item) {
                        $item_sec = isset($item['sectionid']) ? (int)$item['sectionid'] : 0;
                        if ($sectionid > 0) {
                            if ($item_sec === $sectionid || ($item_sec === 0 && !isset($saved_map[mb_strtolower(trim($item['key'] ?? ''))]))) {
                                if (!empty($item['key'])) {
                                    $saved_map[mb_strtolower(trim($item['key']))] = $item;
                                }
                                if (!empty($item['name'])) {
                                    $saved_map[mb_strtolower(trim($item['name']))] = $item;
                                }
                            }
                        } else {
                            if (!empty($item['key'])) {
                                $saved_map[mb_strtolower(trim($item['key']))] = $item;
                            }
                            if (!empty($item['name'])) {
                                $saved_map[mb_strtolower(trim($item['name']))] = $item;
                            }
                        }
                        if (!empty($item['modifiedby'])) {
                            $modifier_ids[] = (int)$item['modifiedby'];
                        }
                    }
                }
            }
        }

        // Preload user names for modifiers
        $users_map = [];
        if (!empty($modifier_ids)) {
            $modifier_ids = array_unique($modifier_ids);
            [$in_sql, $in_params] = $DB->get_in_or_equal($modifier_ids);
            $users = $DB->get_records_select('user', "id $in_sql", $in_params, '', '*');
            foreach ($users as $u) {
                $users_map[$u->id] = fullname($u);
            }
        }

        $activities = [];
        foreach ($expected_activities as $act) {
            $act_name = $act['name'];
            $act_key = $act['key'] ?? self::slugify_key($act_name);
            $duedays = isset($act['duedays']) ? (int)$act['duedays'] : 5;

            // Calculate planned due date excluding Saturdays & Sundays
            $planned_ts = 0;
            $planned_date = '';
            $planned_date_formatted = '—';

            if ($mod_p_start_ts > 0) {
                $planned_ts = self::add_working_days($mod_p_start_ts, $duedays);
                $planned_date = date('Y-m-d', $planned_ts);
                $planned_date_formatted = date('d M Y', $planned_ts);
            }

            $lookup_key = mb_strtolower(trim($act_key));
            $lookup_name = mb_strtolower(trim($act_name));
            $saved = $saved_map[$lookup_key] ?? ($saved_map[$lookup_name] ?? null);

            $completed = !empty($saved['completed']);
            $completiondate = !empty($saved['completiondate']) ? (string)$saved['completiondate'] : '';
            $completiondate_formatted = '—';
            if ($completed && !empty($completiondate) && $completiondate !== '—') {
                $comp_ts = is_numeric($completiondate) ? (int)$completiondate : strtotime($completiondate);
                if ($comp_ts > 0) {
                    $completiondate_formatted = userdate($comp_ts, '%d %b %Y');
                }
            }
            $modifiedby = !empty($saved['modifiedby']) ? (int)$saved['modifiedby'] : 0;
            $timemodified = !empty($saved['timemodified']) ? (int)$saved['timemodified'] : 0;
            $is_auto = !empty($saved['is_auto']) || (!empty($saved['modifiedbyname']) && $saved['modifiedbyname'] === 'System (Auto)') || ($completed && $modifiedby === 0);
            if ($is_auto) {
                $modifiedbyname = 'System (Auto)';
            } else if (!empty($users_map[$modifiedby])) {
                $modifiedbyname = $users_map[$modifiedby];
            } else if (!empty($saved['modifiedbyname'])) {
                $modifiedbyname = $saved['modifiedbyname'];
            } else {
                $modifiedbyname = '';
            }

            $action_info = self::compute_action_status($completed, $planned_ts);

            $activities[] = [
                'key'                      => $act_key,
                'name'                     => $act_name,
                'duedays'                  => $duedays,
                'planned_date'             => $planned_date,
                'planned_date_formatted'   => $planned_date_formatted,
                'planned_ts'               => $planned_ts,
                'is_overdue'               => ($action_info['status'] === 'overdue'),
                'overdue_days'             => $action_info['overdue_days'],
                'action_status'            => $action_info['status'],
                'action_label'             => $action_info['label'],
                'action_class'             => $action_info['class'],
                'completed'                => $completed,
                'completiondate'           => $completiondate,
                'completiondate_formatted' => $completiondate_formatted,
                'modifiedby'               => $modifiedby,
                'modifiedbyname'           => $modifiedbyname,
                'timemodified'             => $timemodified,
                'sectionid'                => $sectionid,
            ];
        }

        return [
            'courseid'        => $courseid,
            'group'           => $groupname,
            'is_manual'       => $resolved['is_manual'],
            'mod_p_start_ts'  => $mod_p_start_ts,
            'sectionid'       => $sectionid,
            'activities'      => $activities,
        ];
    }

    /**
     * Save an activity's completion status into the course's single JSON record.
     *
     * @param int $courseid
     * @param string $activityname Activity name or key.
     * @param bool $completed
     * @param string $completiondate
     * @param int $userid
     * @param int $sectionid Optional class section id for section-specific tracking.
     * @return array
     * @throws \moodle_exception
     */
    public static function save_activity_status(
        int $courseid,
        string $activityname,
        bool $completed,
        string $completiondate,
        int $userid,
        int $sectionid = 0,
        bool $is_auto = false
    ): array {
        global $DB;

        if ($courseid <= 0 || !self::is_table_available()) {
            throw new \moodle_exception('invalidcourseid');
        }

        $activityname = trim($activityname);
        if ($activityname === '') {
            throw new \moodle_exception('invalidactivity', 'local_batchanalytics');
        }

        $now = time();
        $date_val = '';
        if ($completed) {
            $date_val = !empty($completiondate) ? trim($completiondate) : date('Y-m-d', $now);
        }

        $rec = $DB->get_record(self::get_table_name(), ['courseid' => $courseid]);
        $activities_list = [];

        if ($rec && !empty($rec->activitiesdata)) {
            $activities_list = json_decode($rec->activitiesdata, true) ?: [];
        }

        $found = false;
        $updated_item = null;
        $target_key = mb_strtolower(self::slugify_key($activityname));
        $target_name = mb_strtolower($activityname);

        foreach ($activities_list as &$item) {
            $item_key = !empty($item['key']) ? mb_strtolower(trim($item['key'])) : '';
            $item_name = !empty($item['name']) ? mb_strtolower(trim($item['name'])) : '';
            $item_sec = isset($item['sectionid']) ? (int)$item['sectionid'] : 0;

            $sec_match = ($sectionid <= 0) || ($item_sec === $sectionid) || ($item_sec === 0);

            if (($item_key === $target_key || $item_name === $target_name || $item_key === $target_name) && $sec_match) {
                $item['completed'] = $completed ? 1 : 0;
                $item['completiondate'] = $date_val;
                $item['modifiedby'] = $is_auto ? 0 : $userid;
                $item['is_auto'] = $is_auto ? 1 : 0;
                $item['modifiedbyname'] = $is_auto ? 'System (Auto)' : '';
                $item['timemodified'] = $now;
                if ($sectionid > 0) {
                    $item['sectionid'] = $sectionid;
                }
                $updated_item = $item;
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            $updated_item = [
                'key'            => self::slugify_key($activityname),
                'name'           => $activityname,
                'completed'      => $completed ? 1 : 0,
                'completiondate' => $date_val,
                'modifiedby'     => $is_auto ? 0 : $userid,
                'is_auto'        => $is_auto ? 1 : 0,
                'modifiedbyname' => $is_auto ? 'System (Auto)' : '',
                'timemodified'   => $now,
                'sectionid'      => $sectionid,
            ];
            $activities_list[] = $updated_item;
        }

        $json_data = json_encode(array_values($activities_list), JSON_UNESCAPED_UNICODE);

        if ($rec) {
            $rec->activitiesdata = $json_data;
            $rec->timemodified = $now;
            $DB->update_record(self::get_table_name(), $rec);
        } else {
            $newrec = new \stdClass();
            $newrec->courseid = $courseid;
            $newrec->selectedgroup = '';
            $newrec->activitiesdata = $json_data;
            $newrec->timemodified = $now;
            $DB->insert_record(self::get_table_name(), $newrec);
        }

        if ($completed) {
            try {
                require_once(__DIR__ . '/cliq_activity_notifier.php');
                cliq_activity_notifier::send_completion_alert('mentor', $sectionid, $courseid, $activityname, $userid);
            } catch (\Throwable $e) {
                // Silently avoid interrupting the save response
            }
        }

        return $updated_item;
    }
}
