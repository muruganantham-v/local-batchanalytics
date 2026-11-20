<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Service for day-wise module lesson planning, syllabus progression,
 * and delivery hour rollups for sponsor compliance.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_plan_service {

    const TABLE = 'local_batchanalytics_lesson_plan';

    /**
     * Retrieve day-wise lesson plan for a section and course module.
     *
     * @param int $sectionid
     * @param int $courseid
     * @return array
     */
    public static function get_lesson_plan(int $sectionid, int $courseid = 0): array {
        global $DB;

        if ($DB->get_manager()->table_exists(self::TABLE)) {
            $params = ['sectionid' => $sectionid];
            $sql = "sectionid = :sectionid";
            if ($courseid > 0) {
                $params['courseid'] = $courseid;
                $sql .= " AND courseid = :courseid";
            }
            $records = $DB->get_records_select(self::TABLE, $sql, $params, 'sortorder ASC, day_index ASC');
            if (!empty($records)) {
                return array_values($records);
            }
        }

        return [];
    }

    /**
     * Prototype syllabus days matching the inventory document.
     *
     * @param int $sectionid
     * @param int $courseid
     * @return array
     */
    protected static function get_default_prototype_days(int $sectionid, int $courseid): array {
        $topics = [
            ['day' => 1, 'planned' => '17 Aug 2026', 'actual' => '17 Aug 2026', 'topic' => 'C intro, LMS orientation · language types (high/low) · features, keywords, datatypes · number systems & conversions · signed/unsigned char range', 'status' => 'Done', 'class_att' => 'Present', 'lab_att' => 'Present'],
            ['day' => 2, 'planned' => '18 Aug 2026', 'actual' => '18 Aug 2026', 'topic' => 'printf / scanf, format specifiers · compilation & execution, compiler vs interpreter · operators · if family · practice programs', 'status' => 'Done', 'class_att' => 'Present', 'lab_att' => 'Present'],
            ['day' => 3, 'planned' => '19 Aug 2026', 'actual' => '19 Aug 2026', 'topic' => 'switch-case (all properties) · loops: while / do-while / for · pre & post ++ / -- · 7-8 programs', 'status' => 'Done', 'class_att' => 'Present', 'lab_att' => 'Present'],
            ['day' => 4, 'planned' => '20 Aug 2026', 'actual' => '20 Aug 2026', 'topic' => 'factorial, 2^n series · break / continue · prime with optimization · nested loops · 2-3 assignments', 'status' => 'Done', 'class_att' => 'Present', 'lab_att' => 'Present'],
            ['day' => 5, 'planned' => '21 Aug 2026', 'actual' => '21 Aug 2026', 'topic' => 'VS Code setup · nested-loop 2D patterns (11-12) · assignment: perfect number', 'status' => 'Done', 'class_att' => 'Present', 'lab_att' => 'Present'],
            ['day' => 6, 'planned' => '22 Aug 2026', 'actual' => '22 Aug 2026', 'topic' => 'pattern problems · operator precedence & associativity · ternary (4 problems) · goto-label · assignments', 'status' => 'Done', 'class_att' => 'Present', 'lab_att' => 'Present'],
            ['day' => 7, 'planned' => '24 Aug 2026', 'actual' => '24 Aug 2026', 'topic' => 'swap integers · arrays: declare/init, print/scan · sum/avg, search, largest/smallest, reverse · armstrong, n primes', 'status' => 'Done', 'class_att' => 'Present', 'lab_att' => 'Present'],
            ['day' => 8, 'planned' => '25 Aug 2026', 'actual' => '25 Aug 2026', 'topic' => 'n primes solution · bubble sort · more array problems · surprise test 1 + evaluation & discussion', 'status' => 'Done', 'class_att' => 'Present', 'lab_att' => 'Sub'],
            ['day' => 9, 'planned' => '26 Aug 2026', 'actual' => '26 Aug 2026', 'topic' => 'type modifiers (signed/unsigned/short/long) · typecasting, promotion/demotion · rank order · SDLC', 'status' => 'Done', 'class_att' => 'Present', 'lab_att' => 'Present'],
            ['day' => 10, 'planned' => '27 Aug 2026', 'actual' => '', 'topic' => 'Functions in C · parameter passing (call by value) · return values · function prototypes', 'status' => 'Pending', 'class_att' => 'Present', 'lab_att' => 'Present'],
            ['day' => 11, 'planned' => '28 Aug 2026', 'actual' => '', 'topic' => 'Storage classes (auto, static, extern, register) · scope, visibility and lifetime', 'status' => 'Pending', 'class_att' => 'Present', 'lab_att' => 'Present'],
            ['day' => 12, 'planned' => '29 Aug 2026', 'actual' => '', 'topic' => 'Pointers fundamentals · memory addresses · pointer declaration and dereferencing', 'status' => 'Pending', 'class_att' => 'Present', 'lab_att' => 'Present'],
        ];

        $result = [];
        foreach ($topics as $idx => $t) {
            $result[] = (object)[
                'id' => ($idx + 1),
                'sectionid' => $sectionid,
                'courseid' => $courseid,
                'day_index' => $t['day'],
                'planned_date' => $t['planned'],
                'actual_date' => $t['actual'],
                'topic' => $t['topic'],
                'status' => $t['status'],
                'class_mentor_att' => $t['class_att'],
                'lab_mentor_att' => $t['lab_att'],
                'sortorder' => $idx,
                'timemodified' => time(),
            ];
        }
        return $result;
    }

    /**
     * Add a lesson plan day.
     * Mentor attendance is automatically resolved from the ESSCI biometric attendance service
     * if not provided.
     *
     * @param int $sectionid
     * @param int $courseid
     * @param array $data
     * @return int
     */
    public static function add_day(int $sectionid, int $courseid, array $data): int {
        global $DB;

        $class_att = $data['class_mentor_att'] ?? '';
        $lab_att   = $data['lab_mentor_att'] ?? '';

        // Auto-resolve mentor attendance from ESSCI biometric database when omitted
        if (empty($class_att) || empty($lab_att)) {
            $cm_uid = 0;
            $lm_uid = 0;
            if ($sectionid > 0 && $DB->get_manager()->table_exists('local_bm_classsection')) {
                $sec = $DB->get_record('local_bm_classsection', ['id' => $sectionid]);
                if ($sec && !empty($sec->moduledata)) {
                    $mods = \local_batchanalytics\util::decode_module_data($sec->moduledata, true);
                    $target_mod = null;
                    foreach ($mods as $m) {
                        $mcid = !empty($m['moodlecourseid']) ? (int)$m['moodlecourseid'] : (!empty($m['courseid']) ? (int)$m['courseid'] : 0);
                        if ($mcid > 0 && $mcid === $courseid) {
                            $target_mod = $m;
                            break;
                        }
                    }
                    if (!$target_mod && !empty($mods)) {
                        $target_mod = reset($mods);
                    }
                    if ($target_mod) {
                        if (!empty($target_mod['primarymentor'])) {
                            $p = \local_batchanalytics\util::get_user_profile_data($target_mod['primarymentor']);
                            $cm_uid = !empty($p['user']->id) ? (int)$p['user']->id : 0;
                        }
                        if (!empty($target_mod['labmentor1'])) {
                            $p = \local_batchanalytics\util::get_user_profile_data($target_mod['labmentor1']);
                            $lm_uid = !empty($p['user']->id) ? (int)$p['user']->id : 0;
                        }
                    }
                }
            }

            $eff_date = !empty($data['actual_date']) ? $data['actual_date'] : ($data['planned_date'] ?? '');
            if (empty($class_att)) {
                $bio_cm = \local_batchanalytics\biometric_service::get_mentor_biometric_status($cm_uid, $eff_date, $courseid, $sectionid, 'Class');
                $class_att = $bio_cm['status'] ?? 'Pending';
            }
            if (empty($lab_att)) {
                $bio_lm = \local_batchanalytics\biometric_service::get_mentor_biometric_status($lm_uid, $eff_date, $courseid, $sectionid, 'Lab');
                $lab_att = $bio_lm['status'] ?? 'Pending';
            }
        }

        $record = (object)[
            'sectionid' => $sectionid,
            'courseid' => $courseid,
            'day_index' => (int)($data['day_index'] ?? 1),
            'planned_date' => clean_param($data['planned_date'] ?? '', PARAM_TEXT),
            'actual_date' => clean_param($data['actual_date'] ?? '', PARAM_TEXT),
            'topic' => clean_param($data['topic'] ?? '', PARAM_RAW),
            'status' => clean_param($data['status'] ?? 'Pending', PARAM_ALPHANUMEXT),
            'class_mentor_att' => clean_param($class_att, PARAM_ALPHANUMEXT),
            'lab_mentor_att' => clean_param($lab_att, PARAM_ALPHANUMEXT),
            'sortorder' => (int)($data['sortorder'] ?? 0),
            'timemodified' => time(),
        ];

        if ($DB->get_manager()->table_exists(self::TABLE)) {
            return (int)$DB->insert_record(self::TABLE, $record);
        }
        return 0;
    }

    /**
     * Compute aggregate delivered hours & coverage for the batch/module.
     * Delivered, Class, and Lab hours are calculated dynamically from mod_attendance session descriptions.
     * Target hours is pulled from settings (local_batchanalytics/target_hours).
     * Modules covered is calculated from Batch Management moduledata.
     *
     * @param int $sectionid
     * @param int $courseid
     * @return array
     */
    public static function get_hours_and_coverage(int $sectionid, int $courseid = 0): array {
        global $DB;

        // 1. Configured target hours from admin settings (default 570)
        $target_hrs = (int)(get_config('local_batchanalytics', 'target_hours') ?: 570);

        // 2. Resolve module progression and course IDs from Batch Management
        $total_modules = 1;
        $modules_covered = 0;
        $course_ids = [];

        if ($sectionid > 0 && $DB->get_manager()->table_exists('local_bm_classsection')) {
            $sec = $DB->get_record('local_bm_classsection', ['id' => $sectionid]);
            if (!$sec) {
                $secs = $DB->get_records('local_bm_classsection', ['batchid' => $sectionid], 'id ASC', '*', 0, 1);
                if (!empty($secs)) {
                    $sec = reset($secs);
                }
            }

            if ($sec && !empty($sec->moduledata)) {
                $mods = \local_batchanalytics\util::decode_module_data($sec->moduledata, true);
                if (!empty($mods)) {
                    $total_modules = count($mods);
                    foreach ($mods as $m) {
                        $cid = !empty($m['moodlecourseid']) ? (int)$m['moodlecourseid'] : (!empty($m['courseid']) ? (int)$m['courseid'] : 0);
                        if ($cid > 0) {
                            $course_ids[] = $cid;
                        }
                        if (!empty($m['actualend']) && (int)$m['actualend'] > 0) {
                            $modules_covered++;
                        }
                    }
                }
            }
        }

        if ($courseid > 0) {
            $eval_cids = [$courseid];
        } else {
            $eval_cids = !empty($course_ids) ? $course_ids : ($courseid > 0 ? [$courseid] : []);
        }

        // 3. Query attendance sessions from mod_attendance
        $class_hrs = 0.0;
        $lab_hrs = 0.0;
        $delivered_sessions_cnt = 0;
        $total_sessions_cnt = 0;
        $mentor_taken_class = 0;
        $mentor_taken_lab = 0;
        $total_class_sess = 0;
        $total_lab_sess = 0;
        $has_att_data = false;

        if (!empty($eval_cids) && $DB->get_manager()->table_exists('attendance') && $DB->get_manager()->table_exists('attendance_sessions')) {
            list($insql, $params) = $DB->get_in_or_equal($eval_cids);
            $sessions = $DB->get_records_sql(
                "SELECT s.id, s.description, s.duration, s.lasttaken, s.sessdate, s.lasttakenby 
                 FROM {attendance_sessions} s 
                 JOIN {attendance} a ON a.id = s.attendanceid 
                 WHERE a.course $insql 
                 ORDER BY s.sessdate ASC",
                $params
            );

            if (!empty($sessions)) {
                $has_att_data = true;
                $total_sessions_cnt = count($sessions);
                $now = time();

                foreach ($sessions as $s) {
                    $desc = strip_tags(strtolower($s->description ?? ''));
                    // Check if description specifies Class or Lab (also checking 'lap' per user notes)
                    $is_lab = (strpos($desc, 'lab') !== false || strpos($desc, 'lap') !== false);
                    $dur = $s->duration > 0 ? ((float)$s->duration / 3600.0) : 1.5;
                    $is_delivered = (!empty($s->lasttaken) && (int)$s->lasttaken > 0) || (!empty($s->sessdate) && (int)$s->sessdate <= $now);

                    if ($is_lab) {
                        $total_lab_sess++;
                        if ($is_delivered) {
                            $lab_hrs += $dur;
                            $delivered_sessions_cnt++;
                            if (!empty($s->lasttakenby)) {
                                $mentor_taken_lab++;
                            }
                        }
                    } else {
                        $total_class_sess++;
                        if ($is_delivered) {
                            $class_hrs += $dur;
                            $delivered_sessions_cnt++;
                            if (!empty($s->lasttakenby)) {
                                $mentor_taken_class++;
                            }
                        }
                    }
                }
            }
        }

        // 4. Fallback to lesson plan table if no mod_attendance sessions are configured yet
        if (!$has_att_data) {
            $days = self::get_lesson_plan($sectionid, $courseid);
            if (!empty($days)) {
                $total_sessions_cnt = count($days);
                foreach ($days as $d) {
                    if (strcasecmp($d->status ?? '', 'Done') === 0 || !empty($d->actual_date)) {
                        $delivered_sessions_cnt++;
                    }
                }
                $class_hrs = $delivered_sessions_cnt * 3.0;
                $lab_hrs = $delivered_sessions_cnt * 3.0;
            }
        }

        $delivered_hrs = round($class_hrs + $lab_hrs, 1);
        $class_hrs = round($class_hrs, 1);
        $lab_hrs = round($lab_hrs, 1);

        $mentor_att_class = $total_class_sess > 0 ? round(($mentor_taken_class / $total_class_sess) * 100) . '%' : '100%';
        $mentor_att_lab = $total_lab_sess > 0 ? round(($mentor_taken_lab / $total_lab_sess) * 100) . '%' : '100%';

        return [
            'target_hrs' => $target_hrs,
            'delivered_hrs' => $delivered_hrs,
            'class_hrs' => $class_hrs,
            'lab_hrs' => $lab_hrs,
            'completed_sessions' => $delivered_sessions_cnt,
            'total_sessions' => $total_sessions_cnt,
            'modules_covered' => min($modules_covered, $total_modules),
            'total_modules' => $total_modules,
            'mentor_attendance_class' => $mentor_att_class,
            'mentor_attendance_lab' => $mentor_att_lab,
        ];
    }
}

