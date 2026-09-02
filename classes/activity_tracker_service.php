<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Loads and stores course activity delivery status by Gradebook category.
 *
 * @package local_batchanalytics
 */
class activity_tracker_service {
    /** @var array|null Normalized aliases cached for this request. */
    private ?array $trackercategoryaliases = null;

    /**
     * @return bool
     */
    public function is_table_available(): bool {
        global $DB;

        return $DB->get_manager()->table_exists(new \xmldb_table('local_batchanalytics_activity_tracker'));
    }

    /**
     * @param int $courseid
     * @return array
     */
    public function get_course_data(int $courseid): array {
        global $DB;

        $this->require_table();
        $course = get_course($courseid);
        $activities = $this->get_course_activities($course);
        $saved = $DB->get_records('local_batchanalytics_activity_tracker', ['courseid' => $courseid], '',
            'id, cmid, completed, completiondate, timemodified');
        $savedbycmid = [];
        foreach ($saved as $record) {
            $savedbycmid[(int)$record->cmid] = $record;
        }

        $categories = [];
        foreach ($activities as $activity) {
            $categorykey = strtolower($activity['categoryname']);
            if (!isset($categories[$categorykey])) {
                $categories[$categorykey] = [
                    'id' => $categorykey,
                    'name' => $activity['categoryname'],
                    'activities' => [],
                    'completed' => 0,
                    'pending' => 0,
                ];
            }

            $record = $savedbycmid[(int)$activity['cmid']] ?? null;
            $completed = !empty($record->completed);
            $categories[$categorykey]['activities'][] = [
                'cmid' => (int)$activity['cmid'],
                'name' => $activity['name'],
                'completed' => $completed,
                'completiondate' => $record ? (string)$record->completiondate : '',
                'timemodified' => $record ? (int)$record->timemodified : 0,
            ];
            if ($completed) {
                $categories[$categorykey]['completed']++;
            } else {
                $categories[$categorykey]['pending']++;
            }
        }

        return [
            'course' => [
                'id' => (int)$course->id,
                'fullname' => format_string($course->fullname),
                'shortname' => format_string($course->shortname),
            ],
            'categories' => array_values($categories),
        ];
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @param int $cmid
     * @param bool $completed
     * @param string $completiondate
     * @return array
     */
    public function save_activity_status(int $courseid, int $userid, int $cmid, bool $completed,
            string $completiondate): array {
        global $DB;

        $this->require_table();
        $course = get_course($courseid);
        $activities = $this->get_course_activities($course);
        $available = array_column($activities, null, 'cmid');
        if (!isset($available[$cmid])) {
            throw new \moodle_exception('invalidactivity', 'local_batchanalytics');
        }

        $completiondate = $this->normalise_date($completiondate);
        $now = time();
        $record = $DB->get_record('local_batchanalytics_activity_tracker', [
            'courseid' => $courseid,
            'cmid' => $cmid,
        ], '*', IGNORE_MISSING);
        if ($record) {
            $record->completed = $completed ? 1 : 0;
            $record->completiondate = $completiondate;
            $record->modifiedby = $userid;
            $record->timemodified = $now;
            $DB->update_record('local_batchanalytics_activity_tracker', $record);
        } else {
            $record = (object)[
                'courseid' => $courseid,
                'cmid' => $cmid,
                'completed' => $completed ? 1 : 0,
                'completiondate' => $completiondate,
                'modifiedby' => $userid,
                'timemodified' => $now,
            ];
            $record->id = $DB->insert_record('local_batchanalytics_activity_tracker', $record);
        }

        return [
            'cmid' => $cmid,
            'completed' => !empty($record->completed),
            'completiondate' => (string)$record->completiondate,
            'timemodified' => (int)$record->timemodified,
        ];
    }

    /**
     * @param \stdClass $course
     * @return array
     */
    private function get_course_activities(\stdClass $course): array {
        global $DB;

        $items = $DB->get_records_sql("SELECT gi.id, gi.iteminstance, gi.itemmodule, gc.id AS categoryid,
                    gc.fullname AS categoryname
                FROM {grade_items} gi
                JOIN {grade_categories} gc ON gc.id = gi.categoryid
                WHERE gi.courseid = :courseid
                  AND gi.itemtype = 'mod'
                  AND gi.itemmodule <> ''
                ORDER BY gc.id, gi.id", ['courseid' => $course->id]);
        $allcategories = $DB->get_records('grade_categories', ['courseid' => $course->id]);
        $modinfo = get_fast_modinfo($course);
        $cms = [];
        foreach ($modinfo->cms as $cm) {
            $cms[$cm->modname . ':' . $cm->instance] = $cm;
        }

        $activities = [];
        $seen = [];
        foreach ($items as $item) {
            $category = $allcategories[(int)$item->categoryid] ?? null;
            $cm = $cms[$item->itemmodule . ':' . $item->iteminstance] ?? null;
            if (!$cm || isset($seen[$cm->id])) {
                continue;
            }

            $trackercategory = $this->get_category_ancestor_tracker_category($category, $allcategories);
            if ($trackercategory === null) {
                $trackercategory = $this->get_module_tracker_category(
                    (string)$item->itemmodule,
                    (string)$cm->name
                );
            }
            if ($trackercategory === null) {
                continue;
            }

            $seen[$cm->id] = true;
            $activities[] = [
                'cmid' => (int)$cm->id,
                'name' => format_string($cm->name),
                'categoryname' => $trackercategory,
            ];
        }

        return $activities;
    }

    /**
     * Return the first configured tracker category in the gradebook ancestry.
     *
     * @param \stdClass|null $category
     * @param array $allcategories
     * @return string|null
     */
    private function get_category_ancestor_tracker_category(?\stdClass $category, array $allcategories): ?string {
        $seen = [];
        while ($category && !isset($seen[$category->id])) {
            $seen[$category->id] = true;
            $trackercategory = $this->get_tracker_category((string)$category->fullname);
            if ($trackercategory !== null) {
                return $trackercategory;
            }
            if (empty($category->parent) || !isset($allcategories[$category->parent])) {
                break;
            }
            $category = $allcategories[$category->parent];
        }

        return null;
    }

    /** Return the tracker category inferred from a flat-gradebook activity. */
    private function get_module_tracker_category(string $module, string $activityname): ?string {
        if ($module === 'assign') {
            return $this->matches_tracker_category($activityname, 'Projects') ? 'Projects' : 'Assignments';
        }
        if ($module === 'quiz') {
            return 'Tests';
        }

        return $this->get_tracker_category($activityname);
    }

    /** Match a category name against the administrator-configured aliases. */
    private function get_tracker_category(string $categoryname): ?string {
        foreach ($this->get_tracker_category_aliases() as $trackercategory => $aliases) {
            if ($this->matches_tracker_category($categoryname, $trackercategory, $aliases)) {
                return $trackercategory;
            }
        }

        return null;
    }

    /** Check whether a name contains a complete configured category alias. */
    private function matches_tracker_category(string $value, string $trackercategory, ?array $aliases = null): bool {
        $aliases = $aliases ?? ($this->get_tracker_category_aliases()[$trackercategory] ?? []);
        $value = \core_text::strtolower(trim($value));
        foreach ($aliases as $alias) {
            if ($alias !== '' && preg_match('/(^|[^[:alnum:]])' . preg_quote($alias, '/')
                    . '($|[^[:alnum:]])/u', $value)) {
                return true;
            }
        }

        return false;
    }

    /** Load normalized aliases for the three canonical Module Tracker tabs. */
    private function get_tracker_category_aliases(): array {
        if ($this->trackercategoryaliases !== null) {
            return $this->trackercategoryaliases;
        }

        $defaults = [
            'Assignments' => 'assignment,assignments,lab assignment,lab assignments',
            'Tests' => 'test,tests,quiz,quizzes,assessment,assessments',
            'Projects' => 'project,projects',
        ];
        $aliases = [];
        foreach ($defaults as $trackercategory => $default) {
            $setting = 'module_tracker_' . strtolower(rtrim($trackercategory, 's')) . '_aliases';
            $value = (string)get_config('local_batchanalytics', $setting);
            if (trim($value) === '') {
                $value = $default;
            }
            $aliases[$trackercategory] = array_values(array_unique(array_filter(array_map(
                static function(string $alias): string {
                    return \core_text::strtolower(trim($alias));
                },
                explode(',', $value)
            ))));
        }

        $this->trackercategoryaliases = $aliases;
        return $this->trackercategoryaliases;
    }

    /**
     * @param string $value
     * @return string
     */
    private function normalise_date(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
                || $date->format('Y-m-d') !== $value) {
            throw new \moodle_exception('invalidcompletiondate', 'local_batchanalytics');
        }
        return $value;
    }

    /**
     * @return void
     */
    private function require_table(): void {
        if (!$this->is_table_available()) {
            throw new \moodle_exception('activity_tracker_upgrade_required', 'local_batchanalytics');
        }
    }
}
