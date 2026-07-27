<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Loads and stores course activity delivery status by Gradebook category.
 *
 * @package local_batchanalytics
 */
class activity_tracker_service {
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
            $categoryid = (int)$activity['categoryid'];
            if (!isset($categories[$categoryid])) {
                $categories[$categoryid] = [
                    'id' => $categoryid,
                    'name' => $activity['categoryname'],
                    'activities' => [],
                    'completed' => 0,
                    'pending' => 0,
                ];
            }

            $record = $savedbycmid[(int)$activity['cmid']] ?? null;
            $completed = !empty($record->completed);
            $categories[$categoryid]['activities'][] = [
                'cmid' => (int)$activity['cmid'],
                'name' => $activity['name'],
                'completed' => $completed,
                'completiondate' => $record ? (string)$record->completiondate : '',
                'timemodified' => $record ? (int)$record->timemodified : 0,
            ];
            if ($completed) {
                $categories[$categoryid]['completed']++;
            } else {
                $categories[$categoryid]['pending']++;
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
            while ($category && $category->depth > 2 && !empty($category->parent)
                    && isset($allcategories[$category->parent])) {
                $category = $allcategories[$category->parent];
            }
            if (!$category || (int)$category->depth !== 2) {
                continue;
            }

            $trackercategory = $this->get_tracker_category((string)$category->fullname);
            if ($trackercategory === null) {
                continue;
            }

            $cm = $cms[$item->itemmodule . ':' . $item->iteminstance] ?? null;
            if (!$cm || isset($seen[$cm->id])) {
                continue;
            }
            $seen[$cm->id] = true;
            $activities[] = [
                'cmid' => (int)$cm->id,
                'name' => format_string($cm->name),
                'categoryid' => (int)$category->id,
                'categoryname' => $trackercategory,
            ];
        }

        return $activities;
    }

    /**
     * Limits the tracker to the Gradebook categories used for course delivery.
     *
     * @param string $categoryname
     * @return string|null
     */
    private function get_tracker_category(string $categoryname): ?string {
        $name = strtolower(trim($categoryname));
        if (in_array($name, ['assignment', 'assignments'], true)) {
            return 'Assignments';
        }
        if (in_array($name, ['test', 'tests'], true)) {
            return 'Tests';
        }
        if (in_array($name, ['project', 'projects'], true)) {
            return 'Projects';
        }
        return null;
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
