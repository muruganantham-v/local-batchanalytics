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
     * @param int $sectionid Optional class section ID
     * @return array
     */
    public function get_course_data(int $courseid, int $sectionid = 0): array {
        global $DB;

        $this->require_table();
        $this->sync_course_module_activities($courseid, $sectionid);
        $course = get_course($courseid);
        $activities = $this->get_course_activities($course);
        $saved = $DB->get_records('local_batchanalytics_activity_tracker', ['courseid' => $courseid], '',
            'id, cmid, completed, completiondate, modifiedby, timemodified');
        $savedbycmid = [];
        $modifier_ids = [];
        foreach ($saved as $record) {
            $savedbycmid[(int)$record->cmid] = $record;
            if (!empty($record->modifiedby)) {
                $modifier_ids[] = (int)$record->modifiedby;
            }
        }

        $users_map = [];
        if (!empty($modifier_ids)) {
            $modifier_ids = array_unique($modifier_ids);
            [$in_sql, $in_params] = $DB->get_in_or_equal($modifier_ids);
            $users = $DB->get_records_select('user', "id $in_sql", $in_params, '', '*');
            foreach ($users as $u) {
                $users_map[$u->id] = fullname($u);
            }
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
            $modby = $record ? (int)$record->modifiedby : 0;
            $categories[$categorykey]['activities'][] = [
                'cmid' => (int)$activity['cmid'],
                'name' => $activity['name'],
                'completed' => $completed,
                'completiondate' => $record ? (string)$record->completiondate : '',
                'timemodified' => $record ? (int)$record->timemodified : 0,
                'modifiedby' => $modby,
                'modifiedbyname' => ($completed && $modby === 0) ? 'System (Auto)' : ($users_map[$modby] ?? ''),
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
        if (stripos($activityname, 'project') !== false || preg_match('/\bp\d+\b/i', $activityname)) {
            $proj = $this->get_tracker_category('project');
            if ($proj !== null) {
                return $proj;
            }
        }
        if ($module === 'assign' || $module === 'vpl') {
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
     * Get evaluation metrics (pending, evaluated, latest grader, timestamp) for a course
     * categorized strictly according to Gradebook setup (Assignments vs Projects).
     *
     * @param int $courseid
     * @param string $target_type 'Assignment' or 'Project'
     * @param int $sectionid Optional section ID to filter enrolled students
     * @return array{target_type: string, matching_count: int, total_pending: int, total_evaluated: int, latest_grader: int, latest_graded_time: int, pending_details: array}
     */
    public static function get_evaluation_metrics(int $courseid, string $target_type, int $sectionid = 0): array {
        global $DB;

        if ($courseid <= 0) {
            return [
                'target_type'        => $target_type,
                'matching_count'     => 0,
                'total_pending'      => 0,
                'total_evaluated'    => 0,
                'latest_grader'      => 0,
                'latest_graded_time' => 0,
                'pending_details'    => [],
            ];
        }

        $act_service = new self();
        $cats = $DB->get_records('grade_categories', ['courseid' => $courseid]);
        $items = $DB->get_records_select('grade_items', "courseid = :cid AND itemtype = 'mod' AND hidden != 1", ['cid' => $courseid]);

        $student_filter_sql = '';
        $student_params = [];
        if ($sectionid > 0 && $DB->get_manager()->table_exists('local_bm_student')) {
            $uids = $DB->get_fieldset_select('local_bm_student', 'userid', 'classsectionid = :secid', ['secid' => $sectionid]);
            if (!empty($uids)) {
                $c_ctx = \context_course::instance($courseid, IGNORE_MISSING);
                $enrolled_ids = $c_ctx ? array_keys(get_enrolled_users($c_ctx, '', 0, 'u.id')) : [];
                $matching_uids = !empty($enrolled_ids) ? array_values(array_intersect($uids, $enrolled_ids)) : $uids;

                if (!empty($matching_uids) && count($matching_uids) >= 10 && count($matching_uids) >= (count($enrolled_ids) * 0.35)) {
                    [$in_sql, $in_params] = $DB->get_in_or_equal($matching_uids, SQL_PARAMS_NAMED, 'bmstu');
                    $student_filter_sql = " AND s.userid $in_sql";
                    $student_params = $in_params;
                }
            }
        }

        $matching_instances = ['assign' => [], 'vpl' => [], 'quiz' => []];
        $instance_names = [];
        $is_quiz_or_test_target = in_array($target_type, ['Quiz', 'Test', 'Module Test'], true);
        foreach ($items as $gi) {
            $cat = $act_service->resolve_grade_item_category($gi, $cats);
            $cat_match = ($cat === $target_type) || ($is_quiz_or_test_target && in_array($cat, ['Quiz', 'Test', 'Module Test'], true));
            if ($cat_match) {
                $matching_instances[$gi->itemmodule][] = (int)$gi->iteminstance;
                $instance_names[$gi->itemmodule . ':' . $gi->iteminstance] = $gi->itemname;
            }
        }

        // Smart fallback if no activities were explicitly categorized in the gradebook
        if (empty($matching_instances['assign']) && empty($matching_instances['vpl']) && empty($matching_instances['quiz'])) {
            foreach ($items as $gi) {
                if ($gi->itemmodule === 'quiz') {
                    if (in_array($target_type, ['Quiz', 'Test', 'Module Test'], true)) {
                        $matching_instances['quiz'][] = (int)$gi->iteminstance;
                        $instance_names['quiz:' . $gi->iteminstance] = $gi->itemname;
                    }
                    continue;
                }
                if (!in_array($gi->itemmodule, ['assign', 'vpl'], true)) {
                    continue;
                }
                $iname = (string)$gi->itemname;
                $is_proj = (stripos($iname, 'project') !== false || preg_match('/\bp\d+\b/i', $iname));
                if ($target_type === 'Project' && $is_proj) {
                    $matching_instances[$gi->itemmodule][] = (int)$gi->iteminstance;
                    $instance_names[$gi->itemmodule . ':' . $gi->iteminstance] = $gi->itemname;
                } else if ($target_type === 'Assignment' && !$is_proj) {
                    $matching_instances[$gi->itemmodule][] = (int)$gi->iteminstance;
                    $instance_names[$gi->itemmodule . ':' . $gi->iteminstance] = $gi->itemname;
                }
            }
        }

        $total_pending = 0;
        $total_evaluated = 0;
        $latest_grader = 0;
        $latest_graded_time = 0;
        $pending_details = [];
        $instance_pending = ['assign' => [], 'vpl' => [], 'quiz' => []];
        $instance_evaluated = ['assign' => [], 'vpl' => [], 'quiz' => []];

        // 1. Check Assignments (mod_assign)
        if (!empty($matching_instances['assign']) && $DB->get_manager()->table_exists('assign') && $DB->get_manager()->table_exists('assign_submission')) {
            [$in_sql, $in_params] = $DB->get_in_or_equal($matching_instances['assign'], SQL_PARAMS_NAMED, 'asg');
            $params = array_merge($in_params, $student_params);

            // Pending: submitted but not graded, or resubmitted after grading
            $sql_pend = "
                SELECT s.assignment, COUNT(DISTINCT s.id) as pendingcnt
                FROM {assign_submission} s
                LEFT JOIN {assign_grades} g ON (g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber)
                WHERE s.assignment $in_sql
                  AND s.status = 'submitted'
                  AND s.latest = 1
                  AND (g.grade IS NULL OR g.grade < 0 OR s.timemodified > g.timemodified)
                  $student_filter_sql
                GROUP BY s.assignment
            ";
            $pend_rows = $DB->get_records_sql($sql_pend, $params);
            foreach ($pend_rows as $row) {
                $cnt = (int)$row->pendingcnt;
                if ($cnt > 0) {
                    $instance_pending['assign'][(int)$row->assignment] = $cnt;
                    $total_pending += $cnt;
                    $name = $instance_names['assign:' . $row->assignment] ?? ('Assignment ' . $row->assignment);
                    $pending_details[] = $name . ' (' . $cnt . ' ungraded ' . ($cnt === 1 ? 'submission' : 'submissions') . ')';
                }
            }

            // Evaluated: submitted and graded without pending resubmissions
            $sql_eval = "
                SELECT g.id, g.assignment, g.grader, g.timemodified
                FROM {assign_submission} s
                JOIN {assign_grades} g ON (g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber)
                WHERE s.assignment $in_sql
                  AND s.status = 'submitted'
                  AND s.latest = 1
                  AND g.grade IS NOT NULL
                  AND g.grade >= 0
                  AND s.timemodified <= g.timemodified
                  $student_filter_sql
                ORDER BY g.timemodified DESC
            ";
            $eval_records = $DB->get_records_sql($sql_eval, $params);
            $total_evaluated += count($eval_records);
            foreach ($eval_records as $rec) {
                $asg_id = (int)$rec->assignment;
                $instance_evaluated['assign'][$asg_id] = ($instance_evaluated['assign'][$asg_id] ?? 0) + 1;
            }
            if (!empty($eval_records)) {
                $first = reset($eval_records);
                if ((int)$first->timemodified > $latest_graded_time) {
                    $latest_graded_time = (int)$first->timemodified;
                    if (!empty($first->grader)) {
                        $latest_grader = (int)$first->grader;
                    }
                }
            }
        }

        // 2. Check Virtual Programming Lab (mod_vpl)
        if (!empty($matching_instances['vpl']) && $DB->get_manager()->table_exists('vpl') && $DB->get_manager()->table_exists('vpl_submissions')) {
            [$in_sql, $in_params] = $DB->get_in_or_equal($matching_instances['vpl'], SQL_PARAMS_NAMED, 'vpl');
            [$in_sub_sql, $in_sub_params] = $DB->get_in_or_equal($matching_instances['vpl'], SQL_PARAMS_NAMED, 'vplsub');
            $vpl_stu_sql = str_replace('s.userid', 's.userid', $student_filter_sql);
            $params = array_merge($in_params, $in_sub_params, $student_params);

            $sql_vpl_pend = "
                SELECT s.vpl, COUNT(DISTINCT s.id) as pendingcnt
                FROM {vpl_submissions} s
                JOIN (
                    SELECT vpl, userid, MAX(id) as maxid
                    FROM {vpl_submissions}
                    WHERE vpl $in_sub_sql
                    GROUP BY vpl, userid
                ) latest ON latest.maxid = s.id
                WHERE s.vpl $in_sql
                  AND (s.dategraded = 0 OR s.dategraded IS NULL OR s.grade IS NULL)
                  $vpl_stu_sql
                GROUP BY s.vpl
            ";
            $vpl_pend_rows = $DB->get_records_sql($sql_vpl_pend, $params);
            foreach ($vpl_pend_rows as $row) {
                $cnt = (int)$row->pendingcnt;
                if ($cnt > 0) {
                    $instance_pending['vpl'][(int)$row->vpl] = $cnt;
                    $total_pending += $cnt;
                    $name = $instance_names['vpl:' . $row->vpl] ?? ('VPL ' . $row->vpl);
                    $pending_details[] = $name . ' (' . $cnt . ' un-evaluated ' . ($cnt === 1 ? 'submission' : 'submissions') . ')';
                }
            }

            [$in_sub_sql2, $in_sub_params2] = $DB->get_in_or_equal($matching_instances['vpl'], SQL_PARAMS_NAMED, 'vplsubeval');
            $params_eval = array_merge($in_params, $in_sub_params2, $student_params);

            $sql_vpl_eval = "
                SELECT s.id, s.vpl, s.dategraded
                FROM {vpl_submissions} s
                JOIN (
                    SELECT vpl, userid, MAX(id) as maxid
                    FROM {vpl_submissions}
                    WHERE vpl $in_sub_sql2
                    GROUP BY vpl, userid
                ) latest ON latest.maxid = s.id
                WHERE s.vpl $in_sql
                  AND s.dategraded > 0
                  AND s.grade IS NOT NULL
                  $vpl_stu_sql
                ORDER BY s.dategraded DESC
            ";
            $vpl_eval_rows = $DB->get_records_sql($sql_vpl_eval, $params_eval);
            $total_evaluated += count($vpl_eval_rows);
            foreach ($vpl_eval_rows as $row) {
                $v_id = (int)$row->vpl;
                $instance_evaluated['vpl'][$v_id] = ($instance_evaluated['vpl'][$v_id] ?? 0) + 1;
            }
            if (!empty($vpl_eval_rows)) {
                $first = reset($vpl_eval_rows);
                if ((int)$first->dategraded > $latest_graded_time) {
                    $latest_graded_time = (int)$first->dategraded;
                }
            }
        }

        // 3. Check Quizzes (mod_quiz)
        if (!empty($matching_instances['quiz']) && $DB->get_manager()->table_exists('quiz') && $DB->get_manager()->table_exists('quiz_attempts')) {
            [$in_sql, $in_params] = $DB->get_in_or_equal($matching_instances['quiz'], SQL_PARAMS_NAMED, 'qz');
            $quiza_stu_sql = str_replace('s.userid', 'quiza.userid', $student_filter_sql);
            $params = array_merge($in_params, $student_params);

            // In-progress or overdue attempts
            $sql_inprog = "
                SELECT quiza.quiz, COUNT(DISTINCT quiza.id) as pendingcnt
                FROM {quiz_attempts} quiza
                WHERE quiza.quiz $in_sql
                  AND quiza.preview = 0
                  AND quiza.state IN ('inprogress', 'overdue')
                  $quiza_stu_sql
                GROUP BY quiza.quiz
            ";
            $inprog_rows = $DB->get_records_sql($sql_inprog, $params);
            foreach ($inprog_rows as $row) {
                $cnt = (int)$row->pendingcnt;
                if ($cnt > 0) {
                    $q_id = (int)$row->quiz;
                    $instance_pending['quiz'][$q_id] = ($instance_pending['quiz'][$q_id] ?? 0) + $cnt;
                    $total_pending += $cnt;
                    $name = $instance_names['quiz:' . $q_id] ?? ('Quiz ' . $q_id);
                    $pending_details[] = $name . ' (' . $cnt . ' in-progress/overdue ' . ($cnt === 1 ? 'attempt' : 'attempts') . ')';
                }
            }

            // Attempts needing manual grading (e.g. essay questions)
            $needsgrade_attempt_ids = [];
            if ($DB->get_manager()->table_exists('question_attempts') && $DB->get_manager()->table_exists('question_attempt_steps')) {
                $sql_needsgrade = "
                    SELECT quiza.id, quiza.quiz
                    FROM {quiz_attempts} quiza
                    JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                    JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                        AND qas.sequencenumber = (
                            SELECT MAX(latestqas.sequencenumber)
                            FROM {question_attempt_steps} latestqas
                            WHERE latestqas.questionattemptid = qa.id
                        )
                    WHERE quiza.quiz $in_sql
                      AND quiza.preview = 0
                      AND quiza.state = 'finished'
                      AND qas.state = 'needsgrading'
                      $quiza_stu_sql
                    GROUP BY quiza.id, quiza.quiz
                ";
                $needsgrade_rows = $DB->get_records_sql($sql_needsgrade, $params);
                $quiz_needsgrade_counts = [];
                foreach ($needsgrade_rows as $row) {
                    $q_id = (int)$row->quiz;
                    $needsgrade_attempt_ids[(int)$row->id] = true;
                    $quiz_needsgrade_counts[$q_id] = ($quiz_needsgrade_counts[$q_id] ?? 0) + 1;
                    $instance_pending['quiz'][$q_id] = ($instance_pending['quiz'][$q_id] ?? 0) + 1;
                    $total_pending++;
                }
                foreach ($quiz_needsgrade_counts as $q_id => $cnt) {
                    if ($cnt > 0) {
                        $name = $instance_names['quiz:' . $q_id] ?? ('Quiz ' . $q_id);
                        $pending_details[] = $name . ' (' . $cnt . ' ungraded ' . ($cnt === 1 ? 'submission' : 'submissions') . ')';
                    }
                }
            }

            // Evaluated finished attempts (excluding attempts that still need manual grading)
            $sql_eval = "
                SELECT quiza.id, quiza.quiz, quiza.timefinish, quiza.timemodified
                FROM {quiz_attempts} quiza
                WHERE quiza.quiz $in_sql
                  AND quiza.preview = 0
                  AND quiza.state = 'finished'
                  AND quiza.sumgrades IS NOT NULL
                  $quiza_stu_sql
                ORDER BY quiza.timemodified DESC
            ";
            $eval_rows = $DB->get_records_sql($sql_eval, $params);
            foreach ($eval_rows as $row) {
                if (isset($needsgrade_attempt_ids[(int)$row->id])) {
                    continue;
                }
                $q_id = (int)$row->quiz;
                $instance_evaluated['quiz'][$q_id] = ($instance_evaluated['quiz'][$q_id] ?? 0) + 1;
                $total_evaluated++;
                $eval_time = max((int)$row->timefinish, (int)$row->timemodified);
                if ($eval_time > $latest_graded_time) {
                    $latest_graded_time = $eval_time;
                }
            }

            // Find latest manual grader if manual grading occurred
            if ($DB->get_manager()->table_exists('question_attempts') && $DB->get_manager()->table_exists('question_attempt_steps')) {
                $sql_latest_mgr = "
                    SELECT qas.userid, qas.timecreated
                    FROM {quiz_attempts} quiza
                    JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                    JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                        AND qas.sequencenumber = (
                            SELECT MAX(latestqas.sequencenumber)
                            FROM {question_attempt_steps} latestqas
                            WHERE latestqas.questionattemptid = qa.id
                        )
                    WHERE quiza.quiz $in_sql
                      AND quiza.preview = 0
                      AND quiza.state = 'finished'
                      AND qas.userid IS NOT NULL
                      AND qas.userid > 0
                      AND qas.userid != quiza.userid
                      $quiza_stu_sql
                    ORDER BY qas.timecreated DESC
                ";
                $latest_mgr_records = $DB->get_records_sql($sql_latest_mgr, $params, 0, 1);
                if (!empty($latest_mgr_records)) {
                    $mgr = reset($latest_mgr_records);
                    if ((int)$mgr->timecreated > $latest_graded_time) {
                        $latest_graded_time = (int)$mgr->timecreated;
                    }
                    $latest_grader = (int)$mgr->userid;
                }
            }
        }

        // Build structured items array with direct activity URLs
        $modinfo = null;
        try {
            $modinfo = get_fast_modinfo($courseid);
        } catch (\Throwable $e) {
            $modinfo = null;
        }

        $items = [];
        $activity_url = '';

        foreach ($matching_instances['assign'] as $asg_id) {
            $cm_inst = null;
            if ($modinfo) {
                foreach ($modinfo->cms as $c) {
                    if ($c->modname === 'assign' && (int)$c->instance === $asg_id) {
                        $cm_inst = $c;
                        break;
                    }
                }
            }
            $p_cnt = $instance_pending['assign'][$asg_id] ?? 0;
            $e_cnt = $instance_evaluated['assign'][$asg_id] ?? 0;
            $cm_url = $cm_inst ? $cm_inst->url->out(false) : '';
            $items[] = [
                'name'      => $instance_names['assign:' . $asg_id] ?? ('Assignment ' . $asg_id),
                'modname'   => 'assign',
                'cmid'      => $cm_inst ? (int)$cm_inst->id : 0,
                'url'       => $cm_url,
                'pending'   => $p_cnt,
                'completed' => $e_cnt,
                'total'     => $p_cnt + $e_cnt,
            ];
            if ($p_cnt > 0 && $activity_url === '' && $cm_url !== '') {
                $activity_url = $cm_url;
            }
        }

        foreach ($matching_instances['vpl'] as $vpl_id) {
            $cm_inst = null;
            if ($modinfo) {
                foreach ($modinfo->cms as $c) {
                    if ($c->modname === 'vpl' && (int)$c->instance === $vpl_id) {
                        $cm_inst = $c;
                        break;
                    }
                }
            }
            $p_cnt = $instance_pending['vpl'][$vpl_id] ?? 0;
            $e_cnt = $instance_evaluated['vpl'][$vpl_id] ?? 0;
            $cm_url = $cm_inst ? $cm_inst->url->out(false) : '';
            $items[] = [
                'name'      => $instance_names['vpl:' . $vpl_id] ?? ('VPL ' . $vpl_id),
                'modname'   => 'vpl',
                'cmid'      => $cm_inst ? (int)$cm_inst->id : 0,
                'url'       => $cm_url,
                'pending'   => $p_cnt,
                'completed' => $e_cnt,
                'total'     => $p_cnt + $e_cnt,
            ];
            if ($p_cnt > 0 && $activity_url === '' && $cm_url !== '') {
                $activity_url = $cm_url;
            }
        }

        foreach ($matching_instances['quiz'] as $qz_id) {
            $cm_inst = null;
            if ($modinfo) {
                foreach ($modinfo->cms as $c) {
                    if ($c->modname === 'quiz' && (int)$c->instance === $qz_id) {
                        $cm_inst = $c;
                        break;
                    }
                }
            }
            $p_cnt = $instance_pending['quiz'][$qz_id] ?? 0;
            $e_cnt = $instance_evaluated['quiz'][$qz_id] ?? 0;
            $cm_url = $cm_inst ? $cm_inst->url->out(false) : '';
            $items[] = [
                'name'      => $instance_names['quiz:' . $qz_id] ?? ('Quiz ' . $qz_id),
                'modname'   => 'quiz',
                'cmid'      => $cm_inst ? (int)$cm_inst->id : 0,
                'url'       => $cm_url,
                'pending'   => $p_cnt,
                'completed' => $e_cnt,
                'total'     => $p_cnt + $e_cnt,
            ];
            if ($p_cnt > 0 && $activity_url === '' && $cm_url !== '') {
                $activity_url = $cm_url;
            }
        }

        if ($activity_url === '' && !empty($items)) {
            foreach ($items as $it) {
                if (!empty($it['url'])) {
                    $activity_url = $it['url'];
                    break;
                }
            }
        }
        if ($activity_url === '') {
            $activity_url = (new \moodle_url('/local/batchanalytics/module.php', ['courseid' => $courseid]))->out(false);
        }

        return [
            'target_type'        => $target_type,
            'matching_count'     => count($matching_instances['assign']) + count($matching_instances['vpl']) + count($matching_instances['quiz']),
            'total_pending'      => $total_pending,
            'total_evaluated'    => $total_evaluated,
            'latest_grader'      => $latest_grader,
            'latest_graded_time' => $latest_graded_time,
            'pending_details'    => $pending_details,
            'activity_url'       => $activity_url,
            'items'              => $items,
        ];
    }

    /**
     * Automatically synchronize mentor evaluation activities completion state.
     * - If all assignments or projects are evaluated, marks activity completed with grader ID and date.
     * - If new/resubmitted student submissions arrive after completion, automatically reverts to not completed.
     *
     * @param int $courseid
     * @param int $sectionid
     * @return array
     */
    public static function sync_mentor_evaluation_status(int $courseid, int $sectionid = 0): array {
        global $DB;

        if ($courseid <= 0 || !class_exists('\local_batchanalytics\mentor_activity_service')) {
            return [];
        }

        if (!\local_batchanalytics\mentor_activity_service::is_table_available()) {
            return [];
        }

        $results = [];
        $eval_types = [
            'Assignment'  => ['key' => 'assignment_evaluation',  'name' => 'Assignment evaluation'],
            'Project'     => ['key' => 'project_evaluation',     'name' => 'Project evaluation'],
            'Quiz'        => ['key' => 'quiz_evaluation',        'name' => 'Quiz evaluation'],
            'Module Test' => ['key' => 'module_test_evaluation', 'name' => 'Module test evaluation'],
        ];

        // Fetch current saved record for this course
        $rec = $DB->get_record(\local_batchanalytics\mentor_activity_service::get_table_name(), ['courseid' => $courseid]);
        $saved_list = [];
        if ($rec && !empty($rec->activitiesdata)) {
            $raw_saved = json_decode($rec->activitiesdata, true) ?: [];
            foreach ($raw_saved as $item) {
                $saved_list[] = $item;
            }
        }

        $saved_by_key = [];
        foreach ($saved_list as $item) {
            $item_sec = isset($item['sectionid']) ? (int)$item['sectionid'] : 0;
            if ($sectionid <= 0 || $item_sec === $sectionid || $item_sec === 0) {
                $k = mb_strtolower(trim($item['key'] ?? ''));
                if ($k !== '') {
                    $saved_by_key[$k] = $item;
                }
            }
        }

        foreach ($eval_types as $type => $info) {
            $metrics = self::get_evaluation_metrics($courseid, $type, $sectionid);
            if ($metrics['matching_count'] <= 0) {
                continue;
            }

            $act_key = $info['key'];
            $act_name = $info['name'];
            $current_saved = $saved_by_key[$act_key] ?? null;
            $is_currently_complete = !empty($current_saved['completed']);

            // Case A: Submissions pending evaluation -> must NOT be completed
            if ($metrics['total_pending'] > 0) {
                if ($is_currently_complete) {
                    // Revert to incomplete because new/resubmitted submissions exist
                    \local_batchanalytics\mentor_activity_service::save_activity_status(
                        $courseid,
                        $act_name,
                        false,
                        '',
                        0,
                        $sectionid
                    );
                    $results[$act_key] = 'reverted_to_incomplete';
                }
            }
            // Case B: All submissions evaluated -> automatically mark completed
            else if ($metrics['total_pending'] === 0 && $metrics['total_evaluated'] > 0) {
                if (!$is_currently_complete || !empty($current_saved['is_auto']) || empty($current_saved['modifiedby'])) {
                    $eval_time = $metrics['latest_graded_time'] > 0 ? $metrics['latest_graded_time'] : time();
                    $eval_date = date('Y-m-d', $eval_time);

                    \local_batchanalytics\mentor_activity_service::save_activity_status(
                        $courseid,
                        $act_name,
                        true,
                        $eval_date,
                        0,
                        $sectionid,
                        true
                    );
                    $results[$act_key] = 'auto_completed';
                }
            }
        }

        return $results;
    }

    /**
     * Automatically synchronize individual course module activities completion status.
     * - Auto-completes if all submissions are evaluated (0 pending, >= 1 evaluated) with latest grader and date.
     * - Auto-reverts to incomplete (completed = 0) if student resubmits or un-evaluated submissions exist.
     *
     * @param int $courseid
     * @param int $sectionid Optional class section ID
     * @return array
     */
    public function sync_course_module_activities(int $courseid, int $sectionid = 0): array {
        global $DB;

        if ($courseid <= 0 || !$this->is_table_available()) {
            return [];
        }

        try {
            $course = get_course($courseid);
            $modinfo = get_fast_modinfo($course);
            $activities = $this->get_course_activities($course);
        } catch (\Throwable $e) {
            return [];
        }

        $student_filter_sql = '';
        $student_params = [];
        if ($sectionid > 0 && $DB->get_manager()->table_exists('local_bm_student')) {
            $uids = $DB->get_fieldset_select('local_bm_student', 'userid', 'classsectionid = :secid', ['secid' => $sectionid]);
            if (!empty($uids)) {
                [$in_sql, $in_params] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'bmstu');
                $student_filter_sql = " AND s.userid $in_sql";
                $student_params = $in_params;
            }
        }

        $saved = $DB->get_records('local_batchanalytics_activity_tracker', ['courseid' => $courseid]);
        $savedbycmid = [];
        foreach ($saved as $record) {
            $savedbycmid[(int)$record->cmid] = $record;
        }

        $results = [];

        foreach ($activities as $act) {
            $cmid = (int)$act['cmid'];
            $cm = $modinfo->get_cm($cmid);
            if (!$cm) {
                continue;
            }

            $modname = $cm->modname;
            $instance = (int)$cm->instance;
            $existing_rec = $savedbycmid[$cmid] ?? null;
            $is_completed = !empty($existing_rec->completed);

            $pending_count = 0;
            $evaluated_count = 0;
            $latest_grader = 0;
            $latest_graded_time = 0;

            if ($modname === 'assign' && $DB->get_manager()->table_exists('assign_submission')) {
                $params = array_merge(['instance' => $instance], $student_params);

                // Pending count (including resubmissions where s.timemodified > g.timemodified)
                $sql_pend = "
                    SELECT COUNT(DISTINCT s.id)
                    FROM {assign_submission} s
                    LEFT JOIN {assign_grades} g ON (g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber)
                    WHERE s.assignment = :instance
                      AND s.status = 'submitted'
                      AND s.latest = 1
                      AND (g.grade IS NULL OR g.grade < 0 OR s.timemodified > g.timemodified)
                      $student_filter_sql
                ";
                $pending_count = (int)$DB->count_records_sql($sql_pend, $params);

                // Evaluated count and latest grader
                $sql_eval = "
                    SELECT g.id, g.grader, g.timemodified
                    FROM {assign_submission} s
                    JOIN {assign_grades} g ON (g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber)
                    WHERE s.assignment = :instance
                      AND s.status = 'submitted'
                      AND s.latest = 1
                      AND g.grade IS NOT NULL
                      AND g.grade >= 0
                      AND s.timemodified <= g.timemodified
                      $student_filter_sql
                    ORDER BY g.timemodified DESC
                ";
                $eval_records = $DB->get_records_sql($sql_eval, $params);
                $evaluated_count = count($eval_records);
                if (!empty($eval_records)) {
                    $first = reset($eval_records);
                    $latest_graded_time = (int)$first->timemodified;
                    $latest_grader = (int)$first->grader;
                }
            } else if ($modname === 'vpl' && $DB->get_manager()->table_exists('vpl_submissions')) {
                $vpl_stu_sql = str_replace('s.userid', 's.userid', $student_filter_sql);
                $params = array_merge(['instance' => $instance], $student_params);

                $sql_vpl_pend = "
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
                $pending_count = (int)$DB->count_records_sql($sql_vpl_pend, $params);

                $sql_vpl_eval = "
                    SELECT s.id, s.dategraded
                    FROM {vpl_submissions} s
                    JOIN (
                        SELECT vpl, userid, MAX(id) as maxid
                        FROM {vpl_submissions}
                        GROUP BY vpl, userid
                    ) latest ON latest.maxid = s.id
                    WHERE s.vpl = :instance
                      AND s.dategraded > 0
                      AND s.grade IS NOT NULL
                      $vpl_stu_sql
                    ORDER BY s.dategraded DESC
                ";
                $eval_records = $DB->get_records_sql($sql_vpl_eval, $params);
                $evaluated_count = count($eval_records);
                if (!empty($eval_records)) {
                    $first = reset($eval_records);
                    $latest_graded_time = (int)$first->dategraded;
                }
            } else if ($modname === 'quiz' && $DB->get_manager()->table_exists('quiz_attempts')) {
                $quiz_stu_sql = str_replace('s.userid', 'qa.userid', $student_filter_sql);
                $params = array_merge(['instance' => $instance], $student_params);

                $sql_prog = "
                    SELECT COUNT(DISTINCT qa.id)
                    FROM {quiz_attempts} qa
                    WHERE qa.quiz = :instance
                      AND qa.preview = 0
                      AND qa.state IN ('inprogress', 'overdue')
                      $quiz_stu_sql
                ";
                $prog_cnt = (int)$DB->count_records_sql($sql_prog, $params);

                $man_cnt = 0;
                $needsgrade_qa_ids = [];
                if ($DB->get_manager()->table_exists('question_attempts') && $DB->get_manager()->table_exists('question_attempt_steps')) {
                    $sql_man = "
                        SELECT qa.id
                        FROM {quiz_attempts} qa
                        JOIN {question_attempts} qatt ON qatt.questionusageid = qa.uniqueid
                        JOIN {question_attempt_steps} qas ON qas.questionattemptid = qatt.id
                            AND qas.sequencenumber = (
                                SELECT MAX(latestqas.sequencenumber)
                                FROM {question_attempt_steps} latestqas
                                WHERE latestqas.questionattemptid = qatt.id
                            )
                        WHERE qa.quiz = :instance
                          AND qa.preview = 0
                          AND qa.state = 'finished'
                          AND qas.state = 'needsgrading'
                          $quiz_stu_sql
                        GROUP BY qa.id
                    ";
                    $needsgrade_qa_ids = $DB->get_records_sql($sql_man, $params);
                    $man_cnt = count($needsgrade_qa_ids);
                }
                $pending_count = $prog_cnt + $man_cnt;

                $sql_eval = "
                    SELECT qa.id, qa.timefinish, qa.timemodified
                    FROM {quiz_attempts} qa
                    WHERE qa.quiz = :instance
                      AND qa.preview = 0
                      AND qa.state = 'finished'
                      AND qa.sumgrades IS NOT NULL
                      $quiz_stu_sql
                    ORDER BY qa.timemodified DESC
                ";
                $eval_records = $DB->get_records_sql($sql_eval, $params);
                $filtered_eval = [];
                foreach ($eval_records as $rec) {
                    if (!isset($needsgrade_qa_ids[$rec->id])) {
                        $filtered_eval[] = $rec;
                    }
                }
                $evaluated_count = count($filtered_eval);
                if (!empty($filtered_eval)) {
                    $first = reset($filtered_eval);
                    $latest_graded_time = max((int)$first->timefinish, (int)$first->timemodified);
                }

                // Check latest manual grader if manual grading occurred
                if ($DB->get_manager()->table_exists('question_attempts') && $DB->get_manager()->table_exists('question_attempt_steps')) {
                    $sql_latest_mgr = "
                        SELECT qas.userid, qas.timecreated
                        FROM {quiz_attempts} qa
                        JOIN {question_attempts} qatt ON qatt.questionusageid = qa.uniqueid
                        JOIN {question_attempt_steps} qas ON qas.questionattemptid = qatt.id
                            AND qas.sequencenumber = (
                                SELECT MAX(latestqas.sequencenumber)
                                FROM {question_attempt_steps} latestqas
                                WHERE latestqas.questionattemptid = qatt.id
                            )
                        WHERE qa.quiz = :instance
                          AND qa.preview = 0
                          AND qa.state = 'finished'
                          AND qas.userid IS NOT NULL
                          AND qas.userid > 0
                          AND qas.userid != qa.userid
                          $quiz_stu_sql
                        ORDER BY qas.timecreated DESC
                    ";
                    $latest_mgr_records = $DB->get_records_sql($sql_latest_mgr, $params, 0, 1);
                    if (!empty($latest_mgr_records)) {
                        $mgr = reset($latest_mgr_records);
                        if ((int)$mgr->timecreated > $latest_graded_time) {
                            $latest_graded_time = (int)$mgr->timecreated;
                        }
                        $latest_grader = (int)$mgr->userid;
                    }
                }
            } else {
                continue;
            }

            // Case A: Pending submissions exist -> if marked completed, revert to incomplete!
            if ($pending_count > 0) {
                if ($is_completed && $existing_rec) {
                    $existing_rec->completed = 0;
                    $existing_rec->completiondate = '';
                    $existing_rec->modifiedby = 0;
                    $existing_rec->timemodified = time();
                    $DB->update_record('local_batchanalytics_activity_tracker', $existing_rec);
                    $results[$cmid] = 'reverted_to_incomplete';
                }
            }
            // Case B: 0 pending submissions and at least 1 evaluated -> auto-mark complete!
            else if ($pending_count === 0 && $evaluated_count > 0) {
                $eval_date = date('Y-m-d', $latest_graded_time > 0 ? $latest_graded_time : time());
                $grader_id = 0; // 0 indicates System (Auto)

                if (!$is_completed || empty($existing_rec->modifiedby) || empty($existing_rec->completiondate)) {
                    if ($existing_rec) {
                        $existing_rec->completed = 1;
                        $existing_rec->completiondate = $eval_date;
                        $existing_rec->modifiedby = $grader_id;
                        $existing_rec->timemodified = time();
                        $DB->update_record('local_batchanalytics_activity_tracker', $existing_rec);
                    } else {
                        $newrec = (object)[
                            'courseid'       => $courseid,
                            'cmid'           => $cmid,
                            'completed'      => 1,
                            'completiondate' => $eval_date,
                            'modifiedby'     => $grader_id,
                            'timemodified'   => time(),
                        ];
                        $DB->insert_record('local_batchanalytics_activity_tracker', $newrec);
                    }
                    $results[$cmid] = 'auto_completed';
                }
            }
        }

        return $results;
    }

    /**
     * Check if an activity or activities in a course have pending / ungraded submissions.
     *
     * @param int $courseid
     * @param int $cmid Specific course module ID (if checking single module in activity tracker)
     * @param string $activity_name Activity name or operational key (if checking mentor activity checklist)
     * @param int $sectionid Optional class section ID to isolate enrolled students
     * @return array{has_pending: bool, count: int, pending_count: int, completed_count: int, total_count: int, activity_name: string, activity_url: string, items: array, message: string, details: array}
     */
    public static function check_pending_submissions(
        int $courseid,
        int $cmid = 0,
        string $activity_name = '',
        int $sectionid = 0
    ): array {
        global $DB;

        if ($courseid <= 0) {
            return [
                'has_pending'     => false,
                'count'           => 0,
                'pending_count'   => 0,
                'completed_count' => 0,
                'total_count'     => 0,
                'activity_name'   => $activity_name,
                'activity_url'    => '',
                'items'           => [],
                'message'         => '',
                'details'         => [],
            ];
        }

        // 1. Resolve student IDs if scoped to a class section
        $student_filter_sql = '';
        $student_params = [];
        if ($sectionid > 0 && $DB->get_manager()->table_exists('local_bm_student')) {
            $uids = $DB->get_fieldset_select('local_bm_student', 'userid', 'classsectionid = :secid', ['secid' => $sectionid]);
            if (!empty($uids)) {
                $c_ctx = \context_course::instance($courseid, IGNORE_MISSING);
                $enrolled_ids = $c_ctx ? array_keys(get_enrolled_users($c_ctx, '', 0, 'u.id')) : [];
                $matching_uids = !empty($enrolled_ids) ? array_values(array_intersect($uids, $enrolled_ids)) : $uids;

                if (!empty($matching_uids) && count($matching_uids) >= 10 && count($matching_uids) >= (count($enrolled_ids) * 0.35)) {
                    [$in_sql, $in_params] = $DB->get_in_or_equal($matching_uids, SQL_PARAMS_NAMED, 'bmstu');
                    $student_filter_sql = " AND s.userid $in_sql";
                    $student_params = $in_params;
                }
            }
        }

        // 2. If a specific course module $cmid is given, inspect that module
        if ($cmid > 0) {
            try {
                $modinfo = get_fast_modinfo($courseid);
                $cm = $modinfo->get_cm($cmid);
                if ($cm) {
                    return self::check_cm_pending_submissions($cm, $student_filter_sql, $student_params);
                }
            } catch (\Throwable $e) {
                // If course/cm cannot be loaded, continue gracefully.
            }
            return [
                'has_pending'     => false,
                'count'           => 0,
                'pending_count'   => 0,
                'completed_count' => 0,
                'total_count'     => 0,
                'activity_name'   => $activity_name,
                'activity_url'    => '',
                'items'           => [],
                'message'         => '',
                'details'         => [],
            ];
        }

        // 3. If $activity_name is given
        $norm = mb_strtolower(trim($activity_name));
        if ($norm === '') {
            return [
                'has_pending'     => false,
                'count'           => 0,
                'pending_count'   => 0,
                'completed_count' => 0,
                'total_count'     => 0,
                'activity_name'   => '',
                'activity_url'    => '',
                'items'           => [],
                'message'         => '',
                'details'         => [],
            ];
        }

        // 3a. Spot Award nomination validation
        $is_spot_award = (strpos($norm, 'spot award') !== false || strpos($norm, 'spot_award') !== false);
        if ($is_spot_award) {
            $spot = self::check_spot_award_nominations($courseid, $activity_name);
            if (!$spot['nominated']) {
                return [
                    'has_pending'        => true,
                    'count'              => 1,
                    'pending_count'      => 1,
                    'completed_count'    => 0,
                    'total_count'        => 1,
                    'activity_name'      => $activity_name,
                    'activity_url'       => $spot['spot_award_url'],
                    'spot_award_url'     => $spot['spot_award_url'],
                    'is_spot_award'      => true,
                    'nominated'          => false,
                    'nominated_count'    => 0,
                    'nominated_students' => [],
                    'items'              => [],
                    'message'            => 'No students have been nominated for Spot Award in this course yet. Please nominate at least one student before marking this activity as complete.',
                    'details'            => ['reason' => 'spot_award_not_nominated'],
                ];
            }

            return [
                'has_pending'        => false,
                'count'              => 0,
                'pending_count'      => 0,
                'completed_count'    => $spot['nominated_count'],
                'total_count'        => $spot['nominated_count'],
                'activity_name'      => $activity_name,
                'activity_url'       => $spot['spot_award_url'],
                'spot_award_url'     => $spot['spot_award_url'],
                'is_spot_award'      => true,
                'nominated'          => true,
                'nominated_count'    => $spot['nominated_count'],
                'nominated_students' => $spot['nominated_students'],
                'items'              => [],
                'message'            => 'Spot Award nomination verified (' . $spot['nominated_count'] . ' student(s) nominated).',
                'details'            => ['reason' => 'spot_award_nominated'],
            ];
        }

        // Ignore non-submission operational activities (e.g. power track, other nominations)
        if (str_contains($norm, 'nomination') || str_contains($norm, 'power track')) {
            $fallback_url = (new \moodle_url('/local/batchanalytics/module.php', ['courseid' => $courseid]))->out(false);
            return [
                'has_pending'     => false,
                'count'           => 0,
                'pending_count'   => 0,
                'completed_count' => 0,
                'total_count'     => 0,
                'activity_name'   => $activity_name,
                'activity_url'    => $fallback_url,
                'items'           => [],
                'message'         => 'Operational milestone activity (no student submissions required).',
                'details'         => [],
            ];
        }

        // Check if $activity_name matches an exact activity in the course
        try {
            $modinfo = get_fast_modinfo($courseid);
            foreach ($modinfo->cms as $cm) {
                if (mb_strtolower(trim($cm->name)) === $norm) {
                    return self::check_cm_pending_submissions($cm, $student_filter_sql, $student_params);
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        // 4. Gradebook setup-based checks for mentor evaluation activities:
        $is_project = str_contains($norm, 'project') || $norm === 'project_evaluation';
        $is_assign  = (str_contains($norm, 'assign') || $norm === 'assignment_evaluation') && !$is_project;
        $is_quiz    = str_contains($norm, 'quiz') || str_contains($norm, 'test') ||
                      $norm === 'quiz_evaluation' || $norm === 'test_evaluation' ||
                      $norm === 'module_test_evaluation' || $norm === 'module_test_eveluation';

        // A) Projects (resolved via Gradebook categories)
        if ($is_project) {
            $metrics = self::get_evaluation_metrics($courseid, 'Project', $sectionid);
            $cnt = $metrics['total_pending'];
            $eval_cnt = $metrics['total_evaluated'];
            $has_pending = ($cnt > 0);

            if ($has_pending) {
                $msg = 'Cannot mark complete: ' . $cnt . ' pending/ungraded ' .
                       ($cnt === 1 ? 'submission' : 'submissions') . ' in: ' . implode(', ', array_slice($metrics['pending_details'], 0, 3));
                if (count($metrics['pending_details']) > 3) {
                    $msg .= ' and ' . (count($metrics['pending_details']) - 3) . ' more.';
                }
            } else {
                $msg = 'All project submissions have been evaluated (' . $eval_cnt . ' completed).';
            }

            return [
                'has_pending'     => $has_pending,
                'count'           => $cnt,
                'pending_count'   => $cnt,
                'completed_count' => $eval_cnt,
                'total_count'     => $cnt + $eval_cnt,
                'activity_name'   => 'Project evaluation',
                'activity_url'    => $metrics['activity_url'],
                'items'           => $metrics['items'],
                'message'         => $msg,
                'details'         => $metrics['pending_details'],
            ];
        }

        // B) Assignments (resolved via Gradebook categories)
        if ($is_assign) {
            $metrics = self::get_evaluation_metrics($courseid, 'Assignment', $sectionid);
            $cnt = $metrics['total_pending'];
            $eval_cnt = $metrics['total_evaluated'];
            $has_pending = ($cnt > 0);

            if ($has_pending) {
                $msg = 'Cannot mark complete: ' . $cnt . ' pending/ungraded ' .
                       ($cnt === 1 ? 'submission' : 'submissions') . ' in: ' . implode(', ', array_slice($metrics['pending_details'], 0, 3));
                if (count($metrics['pending_details']) > 3) {
                    $msg .= ' and ' . (count($metrics['pending_details']) - 3) . ' more.';
                }
            } else {
                $msg = 'All assignment submissions have been evaluated (' . $eval_cnt . ' completed).';
            }

            return [
                'has_pending'     => $has_pending,
                'count'           => $cnt,
                'pending_count'   => $cnt,
                'completed_count' => $eval_cnt,
                'total_count'     => $cnt + $eval_cnt,
                'activity_name'   => 'Assignment evaluation',
                'activity_url'    => $metrics['activity_url'],
                'items'           => $metrics['items'],
                'message'         => $msg,
                'details'         => $metrics['pending_details'],
            ];
        }

        // C) Quizzes & Tests (mod_quiz)
        if ($is_quiz) {
            if ($DB->get_manager()->table_exists('quiz') && $DB->get_manager()->table_exists('quiz_attempts')) {
                $quiz_params = array_merge(['courseid' => $courseid], $student_params);
                $quiz_stu_sql = str_replace('s.userid', 'qa.userid', $student_filter_sql);

                $pending_details = [];
                $total_pending = 0;
                $total_completed = 0;
                $quiz_items = [];
                $primary_url = '';

                $modinfo = null;
                try {
                    $modinfo = get_fast_modinfo($courseid);
                } catch (\Throwable $e) {
                    $modinfo = null;
                }

                $quizzes = $DB->get_records('quiz', ['course' => $courseid], 'id ASC');

                foreach ($quizzes as $q) {
                    $cm_q = null;
                    if ($modinfo) {
                        foreach ($modinfo->cms as $c) {
                            if ($c->modname === 'quiz' && (int)$c->instance === (int)$q->id) {
                                $cm_q = $c;
                                break;
                            }
                        }
                    }

                    $q_params = array_merge(['instance' => $q->id], $student_params);

                    // In-progress or overdue attempts
                    $sql_prog = "
                        SELECT COUNT(DISTINCT qa.id)
                        FROM {quiz_attempts} qa
                        WHERE qa.quiz = :instance
                          AND qa.preview = 0
                          AND qa.state IN ('inprogress', 'overdue')
                          $quiz_stu_sql
                    ";
                    $prog_cnt = (int)$DB->count_records_sql($sql_prog, $q_params);

                    // Attempts needing manual grading (e.g. essay questions)
                    $man_cnt = 0;
                    if ($DB->get_manager()->table_exists('question_attempts') && $DB->get_manager()->table_exists('question_attempt_steps')) {
                        $sql_man = "
                            SELECT COUNT(DISTINCT qa.id)
                            FROM {quiz_attempts} qa
                            JOIN {question_attempts} qatt ON qatt.questionusageid = qa.uniqueid
                            JOIN {question_attempt_steps} qas ON qas.questionattemptid = qatt.id
                                AND qas.sequencenumber = (
                                    SELECT MAX(latestqas.sequencenumber)
                                    FROM {question_attempt_steps} latestqas
                                    WHERE latestqas.questionattemptid = qatt.id
                                )
                            WHERE qa.quiz = :instance
                              AND qa.preview = 0
                              AND qa.state = 'finished'
                              AND qas.state = 'needsgrading'
                              $quiz_stu_sql
                        ";
                        $man_cnt = (int)$DB->count_records_sql($sql_man, $q_params);
                    }

                    $q_pend = $prog_cnt + $man_cnt;

                    // Completed attempts
                    $sql_comp = "
                        SELECT COUNT(DISTINCT qa.id)
                        FROM {quiz_attempts} qa
                        WHERE qa.quiz = :instance
                          AND qa.preview = 0
                          AND qa.state = 'finished'
                          AND qa.sumgrades IS NOT NULL
                          $quiz_stu_sql
                    ";
                    $comp_raw = (int)$DB->count_records_sql($sql_comp, $q_params);
                    $q_comp = max(0, $comp_raw - $man_cnt);

                    $q_url = $cm_q ? $cm_q->url->out(false) : '';
                    if ($q_pend > 0) {
                        $total_pending += $q_pend;
                        $reasons = [];
                        if ($prog_cnt > 0) $reasons[] = "$prog_cnt in-progress";
                        if ($man_cnt > 0) $reasons[] = "$man_cnt manual grading";
                        $pending_details[] = $q->name . ' (' . implode(', ', $reasons) . ')';
                        if ($primary_url === '' && $q_url !== '') {
                            $primary_url = $q_url;
                        }
                    }
                    $total_completed += $q_comp;

                    $quiz_items[] = [
                        'name'      => $q->name,
                        'modname'   => 'quiz',
                        'cmid'      => $cm_q ? (int)$cm_q->id : 0,
                        'url'       => $q_url,
                        'pending'   => $q_pend,
                        'completed' => $q_comp,
                        'total'     => $q_pend + $q_comp,
                    ];
                }

                if ($primary_url === '' && !empty($quiz_items)) {
                    foreach ($quiz_items as $qi) {
                        if (!empty($qi['url'])) {
                            $primary_url = $qi['url'];
                            break;
                        }
                    }
                }
                if ($primary_url === '') {
                    $primary_url = (new \moodle_url('/local/batchanalytics/module.php', ['courseid' => $courseid]))->out(false);
                }

                $has_pending = ($total_pending > 0);
                if ($has_pending) {
                    $msg = 'Cannot mark complete: ' . $total_pending . ' pending ' .
                           ($total_pending === 1 ? 'quiz attempt' : 'quiz attempts') . ' in: ' . implode(', ', array_slice($pending_details, 0, 3));
                    if (count($pending_details) > 3) {
                        $msg .= ' and ' . (count($pending_details) - 3) . ' more.';
                    }
                } else {
                    $msg = 'All quizzes and tests are fully evaluated (' . $total_completed . ' completed).';
                }

                return [
                    'has_pending'     => $has_pending,
                    'count'           => $total_pending,
                    'pending_count'   => $total_pending,
                    'completed_count' => $total_completed,
                    'total_count'     => $total_pending + $total_completed,
                    'activity_name'   => (str_contains($norm, 'test') ? 'Module test evaluation' : 'Quiz evaluation'),
                    'activity_url'    => $primary_url,
                    'items'           => $quiz_items,
                    'message'         => $msg,
                    'details'         => $pending_details,
                ];
            }
        }

        $fallback_url = (new \moodle_url('/local/batchanalytics/module.php', ['courseid' => $courseid]))->out(false);
        return [
            'has_pending'     => false,
            'count'           => 0,
            'pending_count'   => 0,
            'completed_count' => 0,
            'total_count'     => 0,
            'activity_name'   => $activity_name,
            'activity_url'    => $fallback_url,
            'items'           => [],
            'message'         => 'Ready to mark complete.',
            'details'         => [],
        ];
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
        $url = $cm->url ? $cm->url->out(false) : '';

        if ($modname === 'assign' && $DB->get_manager()->table_exists('assign_submission')) {
            $params = array_merge(['instance' => $instance], $student_params);
            $sql_pend = "
                SELECT COUNT(DISTINCT s.id)
                FROM {assign_submission} s
                LEFT JOIN {assign_grades} g ON (g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber)
                WHERE s.assignment = :instance
                  AND s.status = 'submitted'
                  AND s.latest = 1
                  AND (g.grade IS NULL OR g.grade < 0 OR s.timemodified > g.timemodified)
                  $student_filter_sql
            ";
            $pending_cnt = (int)$DB->count_records_sql($sql_pend, $params);

            $sql_eval = "
                SELECT COUNT(DISTINCT s.id)
                FROM {assign_submission} s
                JOIN {assign_grades} g ON (g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber)
                WHERE s.assignment = :instance
                  AND s.status = 'submitted'
                  AND s.latest = 1
                  AND g.grade IS NOT NULL
                  AND g.grade >= 0
                  AND s.timemodified <= g.timemodified
                  $student_filter_sql
            ";
            $eval_cnt = (int)$DB->count_records_sql($sql_eval, $params);

            $has_pending = ($pending_cnt > 0);
            $msg = $has_pending
                ? "Cannot mark complete: $pending_cnt ungraded submission(s) in $name."
                : "All submissions evaluated in $name ($eval_cnt completed).";

            return [
                'has_pending'     => $has_pending,
                'count'           => $pending_cnt,
                'pending_count'   => $pending_cnt,
                'completed_count' => $eval_cnt,
                'total_count'     => $pending_cnt + $eval_cnt,
                'activity_name'   => $name,
                'activity_url'    => $url,
                'items'           => [[
                    'name'      => $name,
                    'modname'   => 'assign',
                    'cmid'      => (int)$cm->id,
                    'url'       => $url,
                    'pending'   => $pending_cnt,
                    'completed' => $eval_cnt,
                    'total'     => $pending_cnt + $eval_cnt,
                ]],
                'message'         => $msg,
                'details'         => ["$name ($pending_cnt ungraded, $eval_cnt evaluated)"],
            ];
        }

        if ($modname === 'vpl' && $DB->get_manager()->table_exists('vpl_submissions')) {
            $params = array_merge(['instance' => $instance], $student_params);
            $vpl_stu_sql = str_replace('s.userid', 's.userid', $student_filter_sql);
            $sql_pend = "
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
            $pending_cnt = (int)$DB->count_records_sql($sql_pend, $params);

            $sql_eval = "
                SELECT COUNT(DISTINCT s.id)
                FROM {vpl_submissions} s
                JOIN (
                    SELECT vpl, userid, MAX(id) as maxid
                    FROM {vpl_submissions}
                    GROUP BY vpl, userid
                ) latest ON latest.maxid = s.id
                WHERE s.vpl = :instance
                  AND s.dategraded > 0
                  AND s.grade IS NOT NULL
                  $vpl_stu_sql
            ";
            $eval_cnt = (int)$DB->count_records_sql($sql_eval, $params);

            $has_pending = ($pending_cnt > 0);
            $msg = $has_pending
                ? "Cannot mark complete: $pending_cnt un-evaluated submission(s) in $name."
                : "All submissions evaluated in $name ($eval_cnt completed).";

            return [
                'has_pending'     => $has_pending,
                'count'           => $pending_cnt,
                'pending_count'   => $pending_cnt,
                'completed_count' => $eval_cnt,
                'total_count'     => $pending_cnt + $eval_cnt,
                'activity_name'   => $name,
                'activity_url'    => $url,
                'items'           => [[
                    'name'      => $name,
                    'modname'   => 'vpl',
                    'cmid'      => (int)$cm->id,
                    'url'       => $url,
                    'pending'   => $pending_cnt,
                    'completed' => $eval_cnt,
                    'total'     => $pending_cnt + $eval_cnt,
                ]],
                'message'         => $msg,
                'details'         => ["$name ($pending_cnt un-evaluated, $eval_cnt evaluated)"],
            ];
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
                        AND qas.sequencenumber = (
                            SELECT MAX(latestqas.sequencenumber)
                            FROM {question_attempt_steps} latestqas
                            WHERE latestqas.questionattemptid = qatt.id
                        )
                    WHERE qa.quiz = :instance
                      AND qa.preview = 0
                      AND qa.state = 'finished'
                      AND qas.state = 'needsgrading'
                      $quiz_stu_sql
                ";
                $man_cnt = (int)$DB->count_records_sql($sql_man, $params);
            }

            $pending_cnt = $prog_cnt + $man_cnt;

            // Completed attempts
            $sql_comp = "
                SELECT COUNT(DISTINCT qa.id)
                FROM {quiz_attempts} qa
                WHERE qa.quiz = :instance
                  AND qa.preview = 0
                  AND qa.state = 'finished'
                  AND qa.sumgrades IS NOT NULL
                  $quiz_stu_sql
            ";
            $comp_raw = (int)$DB->count_records_sql($sql_comp, $params);
            $eval_cnt = max(0, $comp_raw - $man_cnt);

            $has_pending = ($pending_cnt > 0);
            if ($has_pending) {
                $reasons = [];
                if ($prog_cnt > 0) $reasons[] = "$prog_cnt in-progress attempt(s)";
                if ($man_cnt > 0) $reasons[] = "$man_cnt attempt(s) needing manual grading";
                $msg = "Cannot mark complete: " . implode(' and ', $reasons) . " in $name.";
                $details = ["$name (" . implode(', ', $reasons) . ")"];
            } else {
                $msg = "All attempts completed in $name ($eval_cnt completed).";
                $details = ["$name ($eval_cnt completed attempts)"];
            }

            return [
                'has_pending'     => $has_pending,
                'count'           => $pending_cnt,
                'pending_count'   => $pending_cnt,
                'completed_count' => $eval_cnt,
                'total_count'     => $pending_cnt + $eval_cnt,
                'activity_name'   => $name,
                'activity_url'    => $url,
                'items'           => [[
                    'name'      => $name,
                    'modname'   => 'quiz',
                    'cmid'      => (int)$cm->id,
                    'url'       => $url,
                    'pending'   => $pending_cnt,
                    'completed' => $eval_cnt,
                    'total'     => $pending_cnt + $eval_cnt,
                ]],
                'message'         => $msg,
                'details'         => $details,
            ];
        }

        return [
            'has_pending'     => false,
            'count'           => 0,
            'pending_count'   => 0,
            'completed_count' => 1,
            'total_count'     => 1,
            'activity_name'   => $name,
            'activity_url'    => $url,
            'items'           => [[
                'name'      => $name,
                'modname'   => $modname,
                'cmid'      => (int)$cm->id,
                'url'       => $url,
                'pending'   => 0,
                'completed' => 1,
                'total'     => 1,
            ]],
            'message'         => "Activity ready to mark complete.",
            'details'         => [],
        ];
    }

    /**
     * Check if spot award nominations exist for a course in local_spotaward.
     *
     * @param int $courseid
     * @param string $activity_name
     * @return array
     */
    public static function check_spot_award_nominations(int $courseid, string $activity_name = ''): array {
        global $DB;

        $spot_award_url = (new \moodle_url('/local/spotaward/index.php', ['courseid' => $courseid]))->out(false);

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('spotaward_nominations') || !$dbman->table_exists('spotaward_nomination_items')) {
            return [
                'is_spot_award'      => true,
                'nominated'          => true, // Graceful fallback if spotaward plugin is not installed
                'nominated_count'    => 0,
                'nominated_students' => [],
                'spot_award_url'     => $spot_award_url,
            ];
        }

        $sql = "SELECT sni.id, sni.nominationid, sni.studentid, sni.awardcategory, sni.status,
                       u.firstname, u.lastname, u.idnumber, u.email
                  FROM {spotaward_nominations} sn
                  JOIN {spotaward_nomination_items} sni ON sni.nominationid = sn.id
                  JOIN {user} u ON u.id = sni.studentid
                 WHERE sn.courseid = :courseid
                   AND sni.status <> 'rejected'
                 ORDER BY sni.id DESC";

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        $students = [];
        foreach ($records as $rec) {
            $name_parts = array_filter([trim($rec->firstname ?? ''), trim($rec->lastname ?? '')]);
            $fullname = !empty($name_parts) ? implode(' ', $name_parts) : ('Student #' . $rec->studentid);
            $students[] = [
                'id'            => (int)$rec->id,
                'studentid'     => (int)$rec->studentid,
                'fullname'      => $fullname,
                'firstname'     => $rec->firstname,
                'lastname'      => $rec->lastname,
                'idnumber'      => $rec->idnumber,
                'awardcategory' => $rec->awardcategory,
                'status'        => $rec->status,
            ];
        }

        $norm = mb_strtolower(trim($activity_name));
        $is_mid = (strpos($norm, 'mid') !== false);
        $is_end = (strpos($norm, 'end') !== false);

        $filtered_students = $students;
        if ($is_mid) {
            $mid_students = array_values(array_filter($students, function($s) {
                return (stripos($s['awardcategory'], 'mid') !== false);
            }));
            if (!empty($mid_students)) {
                $filtered_students = $mid_students;
            }
        } else if ($is_end) {
            $end_students = array_values(array_filter($students, function($s) {
                return (stripos($s['awardcategory'], 'end') !== false);
            }));
            if (!empty($end_students)) {
                $filtered_students = $end_students;
            }
        }

        $count = count($filtered_students);

        return [
            'is_spot_award'      => true,
            'nominated'          => ($count > 0),
            'nominated_count'    => $count,
            'nominated_students' => $filtered_students,
            'spot_award_url'     => $spot_award_url,
        ];
    }
}
