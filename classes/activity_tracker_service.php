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

    /**
     * Check if an activity or activities in a course have pending / ungraded submissions.
     *
     * @param int $courseid
     * @param int $cmid Specific course module ID (if checking single module in activity tracker)
     * @param string $activity_name Activity name or operational key (if checking mentor activity checklist)
     * @param int $sectionid Optional class section ID to isolate enrolled students
     * @return array{has_pending: bool, count: int, message: string, details: array}
     */
    public static function check_pending_submissions(
        int $courseid,
        int $cmid = 0,
        string $activity_name = '',
        int $sectionid = 0
    ): array {
        global $DB;

        if ($courseid <= 0) {
            return ['has_pending' => false, 'count' => 0, 'message' => '', 'details' => []];
        }

        // 1. Resolve student IDs if scoped to a class section
        $student_filter_sql = '';
        $student_params = [];
        if ($sectionid > 0 && $DB->get_manager()->table_exists('local_bm_student')) {
            $uids = $DB->get_fieldset_select('local_bm_student', 'studentid', 'classsectionid = :secid', ['secid' => $sectionid]);
            if (!empty($uids)) {
                [$in_sql, $in_params] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'bmstu');
                $student_filter_sql = " AND s.userid $in_sql";
                $student_params = $in_params;
            }
        }

        $pending_details = [];
        $total_pending = 0;

        // 2. If a specific course module $cmid is given, inspect that module
        if ($cmid > 0) {
            try {
                $modinfo = get_fast_modinfo($courseid);
                $cm = $modinfo->get_cm($cmid);
                if ($cm) {
                    $res = self::check_cm_pending_submissions($cm, $student_filter_sql, $student_params);
                    if ($res['has_pending']) {
                        return $res;
                    }
                }
            } catch (\Throwable $e) {
                // If course/cm cannot be loaded, continue gracefully.
            }
            return ['has_pending' => false, 'count' => 0, 'message' => '', 'details' => []];
        }

        // 3. If $activity_name is given (e.g. mentor activity like "Assignment evaluation" or specific activity name)
        $norm = mb_strtolower(trim($activity_name));
        if ($norm === '') {
            return ['has_pending' => false, 'count' => 0, 'message' => '', 'details' => []];
        }

        // Ignore non-submission operational activities (e.g. spot awards, nominations)
        if (str_contains($norm, 'nomination') || str_contains($norm, 'spot award') || str_contains($norm, 'power track')) {
            return ['has_pending' => false, 'count' => 0, 'message' => '', 'details' => []];
        }

        // Check if $activity_name matches an exact activity in the course
        try {
            $modinfo = get_fast_modinfo($courseid);
            foreach ($modinfo->cms as $cm) {
                if (mb_strtolower(trim($cm->name)) === $norm) {
                    $res = self::check_cm_pending_submissions($cm, $student_filter_sql, $student_params);
                    if ($res['has_pending']) {
                        return $res;
                    }
                    return ['has_pending' => false, 'count' => 0, 'message' => '', 'details' => []];
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        // 4. Category-level checks for mentor master activities:
        // A) Assignments & VPLs
        $check_assign = str_contains($norm, 'assign') || str_contains($norm, 'evaluation') || $norm === 'assignment_evaluation';
        $check_project = str_contains($norm, 'project');
        $check_quiz = str_contains($norm, 'quiz');

        if ($check_assign || $check_project) {
            // Check Moodle Assignments (mod_assign)
            if ($DB->get_manager()->table_exists('assign') && $DB->get_manager()->table_exists('assign_submission')) {
                $proj_sql = "";
                $assign_params = array_merge(['courseid' => $courseid], $student_params);
                if ($check_project && !$check_assign) {
                    $proj_sql = " AND " . $DB->sql_like('a.name', ':projname', false, false);
                    $assign_params['projname'] = '%project%';
                }

                $sql = "
                    SELECT a.id, a.name, COUNT(DISTINCT s.id) as pendingcount
                    FROM {assign} a
                    JOIN {assign_submission} s ON s.assignment = a.id
                    LEFT JOIN {assign_grades} g ON (g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber)
                    WHERE a.course = :courseid
                      AND s.status = 'submitted'
                      AND s.latest = 1
                      AND (g.grade IS NULL OR g.grade < 0 OR s.timemodified > g.timemodified)
                      $proj_sql
                      $student_filter_sql
                    GROUP BY a.id, a.name
                ";

                $assign_rows = $DB->get_records_sql($sql, $assign_params);
                foreach ($assign_rows as $row) {
                    $cnt = (int)$row->pendingcount;
                    if ($cnt > 0) {
                        $total_pending += $cnt;
                        $pending_details[] = $row->name . ' (' . $cnt . ' ungraded ' . ($cnt === 1 ? 'submission' : 'submissions') . ')';
                    }
                }
            }

            // Check VPLs (mod_vpl)
            if ($DB->get_manager()->table_exists('vpl') && $DB->get_manager()->table_exists('vpl_submissions')) {
                $vpl_params = array_merge(['courseid' => $courseid], $student_params);
                $proj_sql = "";
                if ($check_project && !$check_assign) {
                    $proj_sql = " AND " . $DB->sql_like('v.name', ':projname', false, false);
                    $vpl_params['projname'] = '%project%';
                }

                $vpl_stu_sql = str_replace('s.userid', 's.userid', $student_filter_sql);

                $sql = "
                    SELECT v.id, v.name, COUNT(DISTINCT s.id) as pendingcount
                    FROM {vpl} v
                    JOIN {vpl_submissions} s ON s.vpl = v.id
                    JOIN (
                        SELECT vpl, userid, MAX(id) as maxid
                        FROM {vpl_submissions}
                        GROUP BY vpl, userid
                    ) latest ON latest.maxid = s.id
                    WHERE v.course = :courseid
                      AND (s.dategraded = 0 OR s.dategraded IS NULL OR s.grade IS NULL)
                      $proj_sql
                      $vpl_stu_sql
                    GROUP BY v.id, v.name
                ";

                $vpl_rows = $DB->get_records_sql($sql, $vpl_params);
                foreach ($vpl_rows as $row) {
                    $cnt = (int)$row->pendingcount;
                    if ($cnt > 0) {
                        $total_pending += $cnt;
                        $pending_details[] = $row->name . ' (' . $cnt . ' un-evaluated ' . ($cnt === 1 ? 'submission' : 'submissions') . ')';
                    }
                }
            }
        }

        // B) Quizzes (mod_quiz)
        if ($check_quiz) {
            if ($DB->get_manager()->table_exists('quiz') && $DB->get_manager()->table_exists('quiz_attempts')) {
                $quiz_params = array_merge(['courseid' => $courseid], $student_params);
                $quiz_stu_sql = str_replace('s.userid', 'qa.userid', $student_filter_sql);

                // In-progress or overdue attempts
                $sql_prog = "
                    SELECT q.id, q.name, COUNT(DISTINCT qa.id) as pendingcount
                    FROM {quiz} q
                    JOIN {quiz_attempts} qa ON qa.quiz = q.id
                    WHERE q.course = :courseid
                      AND qa.preview = 0
                      AND qa.state IN ('inprogress', 'overdue')
                      $quiz_stu_sql
                    GROUP BY q.id, q.name
                ";
                $quiz_prog_rows = $DB->get_records_sql($sql_prog, $quiz_params);
                foreach ($quiz_prog_rows as $row) {
                    $cnt = (int)$row->pendingcount;
                    if ($cnt > 0) {
                        $total_pending += $cnt;
                        $pending_details[] = $row->name . ' (' . $cnt . ' in-progress ' . ($cnt === 1 ? 'attempt' : 'attempts') . ')';
                    }
                }

                // Attempts needing manual grading (e.g. essay questions)
                if ($DB->get_manager()->table_exists('question_attempts') && $DB->get_manager()->table_exists('question_attempt_steps')) {
                    $sql_manual = "
                        SELECT q.id, q.name, COUNT(DISTINCT qa.id) as pendingcount
                        FROM {quiz} q
                        JOIN {quiz_attempts} qa ON qa.quiz = q.id
                        JOIN {question_attempts} qatt ON qatt.questionusageid = qa.uniqueid
                        JOIN {question_attempt_steps} qas ON qas.questionattemptid = qatt.id
                        WHERE q.course = :courseid
                          AND qa.preview = 0
                          AND qa.state = 'finished'
                          AND qas.state = 'needsgrading'
                          $quiz_stu_sql
                        GROUP BY q.id, q.name
                    ";
                    $quiz_man_rows = $DB->get_records_sql($sql_manual, $quiz_params);
                    foreach ($quiz_man_rows as $row) {
                        $cnt = (int)$row->pendingcount;
                        if ($cnt > 0) {
                            $total_pending += $cnt;
                            $pending_details[] = $row->name . ' (' . $cnt . ' manual grading ' . ($cnt === 1 ? 'question' : 'questions') . ' pending)';
                        }
                    }
                }
            }
        }

        if ($total_pending > 0) {
            $msg = 'Cannot mark complete: ' . $total_pending . ' pending/ungraded ' .
                   ($total_pending === 1 ? 'submission' : 'submissions') . ' in: ' . implode(', ', array_slice($pending_details, 0, 3));
            if (count($pending_details) > 3) {
                $msg .= ' and ' . (count($pending_details) - 3) . ' more.';
            }

            return [
                'has_pending' => true,
                'count'       => $total_pending,
                'message'     => $msg,
                'details'     => $pending_details,
            ];
        }

        return ['has_pending' => false, 'count' => 0, 'message' => '', 'details' => []];
    }

    /**
     * Check pending submissions for a specific course module.
     *
     * @param \cm_info $cm
     * @param string $student_filter_sql
     * @param array $student_params
     * @return array
     */
    private static function check_cm_pending_submissions(\cm_info $cm, string $student_filter_sql, array $student_params): array {
        global $DB;

        $modname = $cm->modname;
        $instance = (int)$cm->instance;
        $name = format_string($cm->name);

        if ($modname === 'assign' && $DB->get_manager()->table_exists('assign_submission')) {
            $params = array_merge(['instance' => $instance], $student_params);
            $sql = "
                SELECT COUNT(DISTINCT s.id)
                FROM {assign_submission} s
                LEFT JOIN {assign_grades} g ON (g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber)
                WHERE s.assignment = :instance
                  AND s.status = 'submitted'
                  AND s.latest = 1
                  AND (g.grade IS NULL OR g.grade < 0 OR s.timemodified > g.timemodified)
                  $student_filter_sql
            ";
            $cnt = (int)$DB->count_records_sql($sql, $params);
            if ($cnt > 0) {
                return [
                    'has_pending' => true,
                    'count'       => $cnt,
                    'message'     => "Cannot mark complete: $cnt ungraded submission(s) in $name.",
                    'details'     => ["$name ($cnt ungraded submissions)"],
                ];
            }
        }

        if ($modname === 'vpl' && $DB->get_manager()->table_exists('vpl_submissions')) {
            $params = array_merge(['instance' => $instance], $student_params);
            $vpl_stu_sql = str_replace('s.userid', 's.userid', $student_filter_sql);
            $sql = "
                SELECT COUNT(DISTINCT s.id)
                FROM {vpl_submissions} s
                JOIN (
                    SELECT vpl, userid, MAX(id) as maxid
                    FROM {vpl_submissions}
                    GROUP BY vpl, userid
                ) latest ON latest.maxid = s.id
                WHERE s.vpl = :instance
                  AND (s.dategraded = 0 OR s.dategraded IS NULL OR s.grade IS NULL)
                  $vpl_stu_sql
            ";
            $cnt = (int)$DB->count_records_sql($sql, $params);
            if ($cnt > 0) {
                return [
                    'has_pending' => true,
                    'count'       => $cnt,
                    'message'     => "Cannot mark complete: $cnt un-evaluated submission(s) in $name.",
                    'details'     => ["$name ($cnt un-evaluated submissions)"],
                ];
            }
        }

        if ($modname === 'quiz' && $DB->get_manager()->table_exists('quiz_attempts')) {
            $quiz_stu_sql = str_replace('s.userid', 'qa.userid', $student_filter_sql);
            $params = array_merge(['instance' => $instance], $student_params);

            // In-progress or overdue attempts
            $sql_prog = "
                SELECT COUNT(DISTINCT qa.id)
                FROM {quiz_attempts} qa
                WHERE qa.quiz = :instance
                  AND qa.preview = 0
                  AND qa.state IN ('inprogress', 'overdue')
                  $quiz_stu_sql
            ";
            $prog_cnt = (int)$DB->count_records_sql($sql_prog, $params);

            // Needs manual grading
            $man_cnt = 0;
            if ($DB->get_manager()->table_exists('question_attempts') && $DB->get_manager()->table_exists('question_attempt_steps')) {
                $sql_man = "
                    SELECT COUNT(DISTINCT qa.id)
                    FROM {quiz_attempts} qa
                    JOIN {question_attempts} qatt ON qatt.questionusageid = qa.uniqueid
                    JOIN {question_attempt_steps} qas ON qas.questionattemptid = qatt.id
                    WHERE qa.quiz = :instance
                      AND qa.preview = 0
                      AND qa.state = 'finished'
                      AND qas.state = 'needsgrading'
                      $quiz_stu_sql
                ";
                $man_cnt = (int)$DB->count_records_sql($sql_man, $params);
            }

            $total_quiz = $prog_cnt + $man_cnt;
            if ($total_quiz > 0) {
                $reasons = [];
                if ($prog_cnt > 0) $reasons[] = "$prog_cnt in-progress attempt(s)";
                if ($man_cnt > 0) $reasons[] = "$man_cnt attempt(s) needing manual grading";
                return [
                    'has_pending' => true,
                    'count'       => $total_quiz,
                    'message'     => "Cannot mark complete: " . implode(' and ', $reasons) . " in $name.",
                    'details'     => ["$name (" . implode(', ', $reasons) . ")"],
                ];
            }
        }

        return ['has_pending' => false, 'count' => 0, 'message' => '', 'details' => []];
    }
}
