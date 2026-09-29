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

/**
 * Service for managing mentor operational activities, course grouping, due days, and single-record JSON storage.
 *
 * @package    block_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mentor_activity_service {

    /** @var string Table name storing mentor activity records */
    public const TABLE_NAME = 'block_batchanalytics_mentor_act';

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
        if ($dbman->table_exists('block_batchanalytics_mentor_act')) {
            $tbl = 'block_batchanalytics_mentor_act';
        } else {
            $tbl = 'local_batchanalytics_mentor_act';
        }
        return $tbl;
    }

    /** @var array Default pool of master mentor activities with field API keys */
    public const DEFAULT_MASTER_ACTIVITIES = [
        ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation'],
        ['key' => 'project_evaluation', 'name' => 'Project evaluation'],
        ['key' => 'spot_award_nomination_mid_c', 'name' => 'Spot award nomination - mid c'],
        ['key' => 'spot_award_nomination_end_c', 'name' => 'Spot award nomination - End C'],
        ['key' => 'spot_award_nomination', 'name' => 'Spot award nomination'],
        ['key' => 'power_track_nomination', 'name' => 'Power track nomination'],
    ];

    /** @var array Default grouping rules with due days */
    public const DEFAULT_GROUPING_RULES = [
        [
            'group' => 'Advanced C',
            'activities' => [
                ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation', 'duedays' => 5],
                ['key' => 'project_evaluation', 'name' => 'Project evaluation', 'duedays' => 15],
                ['key' => 'spot_award_nomination_mid_c', 'name' => 'Spot award nomination - mid c', 'duedays' => 10],
                ['key' => 'spot_award_nomination_end_c', 'name' => 'Spot award nomination - End C', 'duedays' => 20],
                ['key' => 'power_track_nomination', 'name' => 'Power track nomination', 'duedays' => 15],
            ],
        ],
        [
            'group' => 'Data Structure',
            'activities' => [
                ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation', 'duedays' => 5],
                ['key' => 'project_evaluation', 'name' => 'Project evaluation', 'duedays' => 15],
                ['key' => 'spot_award_nomination', 'name' => 'Spot award nomination', 'duedays' => 10],
                ['key' => 'power_track_nomination', 'name' => 'Power track nomination', 'duedays' => 15],
            ],
        ],
        [
            'group' => 'Default',
            'activities' => [
                ['key' => 'assignment_evaluation', 'name' => 'Assignment evaluation', 'duedays' => 5],
                ['key' => 'project_evaluation', 'name' => 'Project evaluation', 'duedays' => 15],
                ['key' => 'spot_award_nomination', 'name' => 'Spot award nomination', 'duedays' => 10],
                ['key' => 'power_track_nomination', 'name' => 'Power track nomination', 'duedays' => 15],
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

        $today_midnight = strtotime('today midnight');
        if ($planned_ts <= 0 || $today_midnight <= $planned_ts) {
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
        if ($raw === false || trim((string)$raw) === '') {
            return [];
        }

        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $activities = [];
            $seen_keys = [];
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
            if (!empty($activities)) {
                return $activities;
            }
        }

        // Fallback for line-separated strings
        $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
        $activities = [];
        $seen = [];
        foreach ($lines as $line) {
            $name = trim(strip_tags($line));
            if ($name !== '' && !isset($seen[$name])) {
                $seen[$name] = true;
                $activities[] = [
                    'key'  => self::slugify_key($name),
                    'name' => $name,
                ];
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
        if ($raw === false || trim((string)$raw) === '') {
            return [];
        }
        $decoded = json_decode((string)$raw, true);

        if (is_array($decoded)) {
            $rules = [];
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

            if (!empty($rules)) {
                return $rules;
            }
        }

        return [];
    }

    /**
     * Get available group options for course settings select dropdown.
     *
     * @return array<string, string>
     */
    public static function get_available_group_options(): array {
        $options = ['' => get_string('mentor_activity_autodetect', 'block_batchanalytics')];
        $rules = self::get_grouping_rules();
        foreach (array_keys($rules) as $grp) {
            $options[$grp] = $grp;
        }
        return $options;
    }

    /**
     * Get the manually selected group for a course, if configured.
     *
     * @param int $courseid
     * @return string
     */
    public static function get_course_selected_group(int $courseid): string {
        global $DB;
        if ($courseid <= 0 || !self::is_table_available()) {
            return '';
        }

        $rec = $DB->get_record(self::get_table_name(), ['courseid' => $courseid], 'selectedgroup', IGNORE_MISSING);
        return $rec && !empty($rec->selectedgroup) ? trim($rec->selectedgroup) : '';
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
     * First checks if a manual group was chosen in course settings.
     * Otherwise matches course fullname/shortname against defined group names.
     *
     * @param int $courseid
     * @param string $coursename
     * @return array{group: string, is_manual: bool}
     */
    public static function resolve_group_for_course(int $courseid, string $coursename = ''): array {
        global $DB;

        if ($courseid > 0) {
            $selected = self::get_course_selected_group($courseid);
            if ($selected !== '') {
                return ['group' => $selected, 'is_manual' => true];
            }

            if ($coursename === '') {
                $course = $DB->get_record('course', ['id' => $courseid], 'fullname, shortname', IGNORE_MISSING);
                if ($course) {
                    $coursename = $course->fullname . ' ' . $course->shortname;
                }
            }
        }

        $rules = self::get_grouping_rules();
        $haystack = mb_strtolower($coursename);

        // Pattern match against group names (except Default)
        foreach (array_keys($rules) as $grp) {
            if (strcasecmp($grp, 'Default') === 0) {
                continue;
            }
            if (mb_stripos($haystack, mb_strtolower($grp)) !== false) {
                return ['group' => $grp, 'is_manual' => false];
            }
        }

        // Fallback to Default group
        if (isset($rules['Default'])) {
            return ['group' => 'Default', 'is_manual' => false];
        }

        $first = array_key_first($rules);
        return ['group' => $first ?: 'Default', 'is_manual' => false];
    }

    /**
     * Retrieve all mentor activities for a course with due dates and completion status.
     *
     * @param int $courseid
     * @param string $coursename
     * @param int $mod_p_start_ts Module planned start timestamp.
     * @return array
     */
    public static function get_course_mentor_activities(
        int $courseid,
        string $coursename = '',
        int $mod_p_start_ts = 0,
        int $sectionid = 0
    ): array {
        global $DB;

        $resolved = self::resolve_group_for_course($courseid, $coursename);
        $groupname = $resolved['group'];
        $rules = self::get_grouping_rules();

        $expected_activities = $rules[$groupname] ?? ($rules['Default'] ?? []);
        if (empty($expected_activities)) {
            $master = self::get_master_activities();
            if (!empty($master)) {
                $expected_activities = array_map(static function($a) {
                    return [
                        'key'     => $a['key'],
                        'name'    => $a['name'],
                        'duedays' => 5,
                    ];
                }, $master);
            }
        }

        if (empty($expected_activities)) {
            return [
                'courseid'        => $courseid,
                'group'           => $groupname,
                'is_manual'       => $resolved['is_manual'],
                'mod_p_start_ts'  => $mod_p_start_ts,
                'sectionid'       => $sectionid,
                'activities'      => [],
            ];
        }

        $saved_map = [];
        $modifier_ids = [];

        if ($courseid > 0 && self::is_table_available()) {
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
            $modifiedby = !empty($saved['modifiedby']) ? (int)$saved['modifiedby'] : 0;
            $timemodified = !empty($saved['timemodified']) ? (int)$saved['timemodified'] : 0;
            $modifiedbyname = $users_map[$modifiedby] ?? '';

            $action_info = self::compute_action_status($completed, $planned_ts);

            $activities[] = [
                'key'                    => $act_key,
                'name'                   => $act_name,
                'duedays'                => $duedays,
                'planned_date'           => $planned_date,
                'planned_date_formatted' => $planned_date_formatted,
                'planned_ts'             => $planned_ts,
                'is_overdue'             => ($action_info['status'] === 'overdue'),
                'overdue_days'           => $action_info['overdue_days'],
                'action_status'          => $action_info['status'],
                'action_label'           => $action_info['label'],
                'action_class'           => $action_info['class'],
                'completed'              => $completed,
                'completiondate'         => $completiondate,
                'modifiedby'             => $modifiedby,
                'modifiedbyname'         => $modifiedbyname,
                'timemodified'           => $timemodified,
                'sectionid'              => $sectionid,
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
        int $sectionid = 0
    ): array {
        global $DB;

        if ($courseid <= 0 || !self::is_table_available()) {
            throw new \moodle_exception('invalidcourseid');
        }

        $activityname = trim($activityname);
        if ($activityname === '') {
            throw new \moodle_exception('invalidactivity', 'block_batchanalytics');
        }

        $now = time();
        $date_val = '';
        if ($completed) {
            $date_val = !empty($completiondate) ? trim($completiondate) : date('Y-m-d', $now);
        }

        $rec = $DB->get_record(self::TABLE_NAME, ['courseid' => $courseid]);
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
                $item['modifiedby'] = $userid;
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
                'modifiedby'     => $userid,
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

        return $updated_item;
    }
}
