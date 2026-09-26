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

        $categoryorder = $this->get_tracker_category_aliases();
        $categories = [];
        foreach (array_keys($categoryorder) as $catname) {
            $categorykey = strtolower($catname);
            $categories[$categorykey] = [
                'id' => $categorykey,
                'name' => $catname,
                'activities' => [],
                'completed' => 0,
                'pending' => 0,
            ];
        }

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

        // Only return categories that have activities in this course, maintaining configured sequence
        $activecategories = array_values(array_filter($categories, static function(array $cat): bool {
            return !empty($cat['activities']);
        }));

        return [
            'course' => [
                'id' => (int)$course->id,
                'fullname' => format_string($course->fullname),
                'shortname' => format_string($course->shortname),
            ],
            'categories' => $activecategories,
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
                ORDER BY gi.id ASC", ['courseid' => $course->id]);
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
        $activitycategory = $this->get_tracker_category($activityname);
        if ($activitycategory !== null) {
            return $activitycategory;
        }
        if ($module === 'assign') {
            return $this->get_tracker_category('assignment');
        }
        if ($module === 'quiz') {
            return $this->get_tracker_category('quiz') ?? $this->get_tracker_category('test');
        }

        return null;
    }

    /**
     * Get the configured tracker category names and their aliases.
     *
     * @return array [CategoryName => [alias1, alias2, ...], ...]
     */
    public function get_configured_categories(): array {
        return $this->get_tracker_category_aliases();
    }

    /**
     * Resolve the configured tracker category for a grade item and its gradebook category.
     * Attendance activities are ignored (return null).
     *
     * @param \stdClass $item Grade item record (with itemname, itemmodule, categoryid, etc.)
     * @param array $allcategories Course grade categories map [id => category]
     * @return string|null Configured category name, or null if unassigned
     */
    public function resolve_grade_item_category(\stdClass $item, array $allcategories = []): ?string {
        $itemname = (string)($item->itemname ?? '');
        $itemmodule = (string)($item->itemmodule ?? '');

        // Attendance is separated from activity categories
        if ($itemmodule === 'attendance' || stripos($itemname, 'attend') !== false) {
            return null;
        }

        $category = !empty($item->categoryid) && isset($allcategories[$item->categoryid])
            ? $allcategories[$item->categoryid]
            : null;
        if ($category && stripos((string)$category->fullname, 'attend') !== false) {
            return null;
        }

        $trackercategory = $this->get_category_ancestor_tracker_category($category, $allcategories);
        if ($trackercategory !== null) {
            return $trackercategory;
        }

        return $this->get_module_tracker_category($itemmodule, $itemname);
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

    /** Load normalized aliases for the configured Module Tracker tabs. */
    private function get_tracker_category_aliases(): array {
        if ($this->trackercategoryaliases !== null) {
            return $this->trackercategoryaliases;
        }

        $categories = json_decode((string)get_config('local_batchanalytics', 'module_tracker_categories'), true);
        if (!is_array($categories) || empty($categories)) {
            $categories = self::get_legacy_tracker_categories();
        } else {
            // Ensure all 5 standard default categories are present if an older config was saved.
            $existingnames = [];
            foreach ($categories as $cat) {
                if (is_array($cat) && !empty($cat['name'])) {
                    $existingnames[strtolower(trim((string)$cat['name']))] = true;
                }
            }
            foreach (self::get_default_tracker_categories() as $default) {
                $defname = strtolower(trim($default['name']));
                if (!isset($existingnames[$defname])) {
                    $categories[] = $default;
                }
            }
        }

        $canonicalmap = [
            'assignments' => 'Assignment',
            'classworks'  => 'Classwork',
            'templates'   => 'Template',
            'projects'    => 'Project',
        ];

        $aliases = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $trackercategory = trim((string)($category['name'] ?? ''));
            $lowername = strtolower($trackercategory);
            if (isset($canonicalmap[$lowername])) {
                $trackercategory = $canonicalmap[$lowername];
            }
            $value = (string)($category['aliases'] ?? '');
            if ($trackercategory === '' || trim($value) === '') {
                continue;
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
     * @return array
     */
    public static function get_default_tracker_categories(): array {
        return [
            ['name' => 'Assignment', 'aliases' => 'assignment, assignments, lab assignment, lab assignments'],
            ['name' => 'Classwork',  'aliases' => 'classwork, classworks, class work, class works, cw'],
            ['name' => 'Template',   'aliases' => 'template, templates, template program, template programs'],
            ['name' => 'Project',    'aliases' => 'project, projects, mini project, major project'],
            ['name' => 'Tests',      'aliases' => 'test, tests, quiz, quizzes, assessment, assessments, exam, exams, module test'],
        ];
    }

    /**
     * Use the old per-tab settings until the new category setting is saved.
     *
     * @return array
     */
    public static function get_legacy_tracker_categories(): array {
        $defaults = self::get_default_tracker_categories();
        $categories = [];
        foreach ($defaults as $default) {
            $setting = 'module_tracker_' . strtolower(rtrim($default['name'], 's')) . '_aliases';
            $aliases = (string)get_config('local_batchanalytics', $setting);
            $categories[] = [
                'name' => $default['name'],
                'aliases' => trim($aliases) !== '' ? $aliases : $default['aliases'],
            ];
        }
        return $categories;
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
