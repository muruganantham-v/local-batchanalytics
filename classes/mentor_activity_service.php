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
 * Service for managing mentor operational activities, course grouping, and single-record JSON storage.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mentor_activity_service {

    /** @var string Table name storing mentor activity records */
    public const TABLE_NAME = 'local_batchanalytics_mentor_act';

    /** @var array Default pool of master mentor activities */
    public const DEFAULT_MASTER_ACTIVITIES = [
        'Assignment evaluation',
        'Project evaluation',
        'Spot award nomination - mid c',
        'Spot award nomination - End C',
        'Spot award nomination',
        'Power track nomination',
    ];

    /** @var string Default grouping rules text */
    public const DEFAULT_GROUPING_RULES = "Advanced C: Assignment evaluation, Project evaluation, Spot award nomination - mid c, Spot award nomination - End C, Power track nomination\nData Structure: Assignment evaluation, Project evaluation, Spot award nomination, Power track nomination\nDefault: Assignment evaluation, Project evaluation, Spot award nomination, Power track nomination";

    /**
     * Check if the database table exists.
     *
     * @return bool
     */
    public static function is_table_available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(self::TABLE_NAME);
    }

    /**
     * Get the master list of mentor activities configured in Site Admin.
     *
     * @return string[]
     */
    public static function get_master_activities(): array {
        $raw = get_config('local_batchanalytics', 'mentor_master_activities');
        if ($raw === false || trim((string)$raw) === '') {
            return self::DEFAULT_MASTER_ACTIVITIES;
        }

        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $activities = [];
            foreach ($decoded as $item) {
                $name = is_string($item) ? trim(strip_tags($item)) : (is_array($item) ? trim(strip_tags((string)($item['name'] ?? ''))) : '');
                if ($name !== '' && !in_array($name, $activities, true)) {
                    $activities[] = $name;
                }
            }
            if (!empty($activities)) {
                return $activities;
            }
        }

        $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
        $activities = [];
        foreach ($lines as $line) {
            $line = trim(strip_tags($line));
            if ($line !== '' && !in_array($line, $activities, true)) {
                $activities[] = $line;
            }
        }

        return !empty($activities) ? $activities : self::DEFAULT_MASTER_ACTIVITIES;
    }

    /**
     * Get the parsed course grouping rules.
     *
     * @return array<string, string[]> Associative array of group name => list of activity names.
     */
    public static function get_grouping_rules(): array {
        $raw = get_config('local_batchanalytics', 'mentor_activity_grouping');
        if ($raw === false || trim((string)$raw) === '') {
            $raw = self::DEFAULT_GROUPING_RULES;
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
                $acts = $item['activities'] ?? [];
                if (is_string($acts)) {
                    $acts = explode(',', $acts);
                }
                if (is_array($acts)) {
                    $clean_acts = [];
                    foreach ($acts as $a) {
                        $cleaned = trim(strip_tags((string)$a));
                        if ($cleaned !== '' && !in_array($cleaned, $clean_acts, true)) {
                            $clean_acts[] = $cleaned;
                        }
                    }
                    if (!empty($clean_acts)) {
                        $rules[$grp] = $clean_acts;
                    }
                }
            }
            if (!empty($rules)) {
                return $rules;
            }
        }

        $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
        $rules = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }

            [$group_name, $acts_str] = explode(':', $line, 2);
            $group_name = trim(strip_tags($group_name));
            if ($group_name === '') {
                continue;
            }

            $acts = array_map('trim', explode(',', $acts_str));
            $clean_acts = [];
            foreach ($acts as $a) {
                $cleaned = trim(strip_tags((string)$a));
                if ($cleaned !== '' && !in_array($cleaned, $clean_acts, true)) {
                    $clean_acts[] = $cleaned;
                }
            }

            if (!empty($clean_acts)) {
                $rules[$group_name] = $clean_acts;
            }
        }

        if (empty($rules)) {
            $rules['Default'] = self::get_master_activities();
        }

        return $rules;
    }

    /**
     * Get available group options for course settings select dropdown.
     *
     * @return array<string, string>
     */
    public static function get_available_group_options(): array {
        $options = ['' => get_string('mentor_activity_autodetect', 'local_batchanalytics')];
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

        $rec = $DB->get_record(self::TABLE_NAME, ['courseid' => $courseid], 'selectedgroup', IGNORE_MISSING);
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
        $rec = $DB->get_record(self::TABLE_NAME, ['courseid' => $courseid]);

        $now = time();
        if ($rec) {
            $rec->selectedgroup = $groupname;
            $rec->timemodified = $now;
            return $DB->update_record(self::TABLE_NAME, $rec);
        }

        $newrec = new \stdClass();
        $newrec->courseid = $courseid;
        $newrec->selectedgroup = $groupname;
        $newrec->activitiesdata = json_encode([]);
        $newrec->timemodified = $now;
        return (bool)$DB->insert_record(self::TABLE_NAME, $newrec);
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
     * Retrieve all mentor activities for a course with current completion status.
     *
     * @param int $courseid
     * @param string $coursename
     * @return array
     */
    public static function get_course_mentor_activities(int $courseid, string $coursename = ''): array {
        global $DB;

        $resolved = self::resolve_group_for_course($courseid, $coursename);
        $groupname = $resolved['group'];
        $rules = self::get_grouping_rules();

        $expected_activities = $rules[$groupname] ?? ($rules['Default'] ?? self::get_master_activities());

        $saved_map = [];
        $modifier_ids = [];

        if ($courseid > 0 && self::is_table_available()) {
            $rec = $DB->get_record(self::TABLE_NAME, ['courseid' => $courseid], 'activitiesdata', IGNORE_MISSING);
            if ($rec && !empty($rec->activitiesdata)) {
                $raw_list = json_decode($rec->activitiesdata, true);
                if (is_array($raw_list)) {
                    foreach ($raw_list as $item) {
                        if (!empty($item['name'])) {
                            $key = mb_strtolower(trim($item['name']));
                            $saved_map[$key] = $item;
                            if (!empty($item['modifiedby'])) {
                                $modifier_ids[] = (int)$item['modifiedby'];
                            }
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
            $users = $DB->get_records_select('user', "id $in_sql", $in_params, '', 'id, firstname, lastname');
            foreach ($users as $u) {
                $users_map[$u->id] = fullname($u);
            }
        }

        $activities = [];
        foreach ($expected_activities as $actname) {
            $key = mb_strtolower(trim($actname));
            $saved = $saved_map[$key] ?? null;

            $completed = !empty($saved['completed']);
            $completiondate = !empty($saved['completiondate']) ? (string)$saved['completiondate'] : '';
            $modifiedby = !empty($saved['modifiedby']) ? (int)$saved['modifiedby'] : 0;
            $timemodified = !empty($saved['timemodified']) ? (int)$saved['timemodified'] : 0;
            $modifiedbyname = $users_map[$modifiedby] ?? '';

            $activities[] = [
                'name'           => $actname,
                'completed'      => $completed,
                'completiondate' => $completiondate,
                'modifiedby'     => $modifiedby,
                'modifiedbyname' => $modifiedbyname,
                'timemodified'   => $timemodified,
            ];
        }

        return [
            'courseid'   => $courseid,
            'group'      => $groupname,
            'is_manual'  => $resolved['is_manual'],
            'activities' => $activities,
        ];
    }

    /**
     * Save an activity's completion status into the course's single JSON record.
     *
     * @param int $courseid
     * @param string $activityname
     * @param bool $completed
     * @param string $completiondate
     * @param int $userid
     * @return array
     * @throws \moodle_exception
     */
    public static function save_activity_status(
        int $courseid,
        string $activityname,
        bool $completed,
        string $completiondate,
        int $userid
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

        $rec = $DB->get_record(self::TABLE_NAME, ['courseid' => $courseid]);
        $activities_list = [];

        if ($rec && !empty($rec->activitiesdata)) {
            $activities_list = json_decode($rec->activitiesdata, true) ?: [];
        }

        $found = false;
        $updated_item = null;
        $key_target = mb_strtolower($activityname);

        foreach ($activities_list as &$item) {
            if (!empty($item['name']) && mb_strtolower(trim($item['name'])) === $key_target) {
                $item['completed'] = $completed ? 1 : 0;
                $item['completiondate'] = $date_val;
                $item['modifiedby'] = $userid;
                $item['timemodified'] = $now;
                $updated_item = $item;
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            $updated_item = [
                'name'           => $activityname,
                'completed'      => $completed ? 1 : 0,
                'completiondate' => $date_val,
                'modifiedby'     => $userid,
                'timemodified'   => $now,
            ];
            $activities_list[] = $updated_item;
        }

        $json_data = json_encode(array_values($activities_list), JSON_UNESCAPED_UNICODE);

        if ($rec) {
            $rec->activitiesdata = $json_data;
            $rec->timemodified = $now;
            $DB->update_record(self::TABLE_NAME, $rec);
        } else {
            $newrec = new \stdClass();
            $newrec->courseid = $courseid;
            $newrec->selectedgroup = '';
            $newrec->activitiesdata = $json_data;
            $newrec->timemodified = $now;
            $DB->insert_record(self::TABLE_NAME, $newrec);
        }

        return $updated_item;
    }
}
