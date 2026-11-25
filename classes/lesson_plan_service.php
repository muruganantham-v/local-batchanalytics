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
            $records = $DB->get_records_select(self::TABLE, $sql, $params, 'sortorder ASC, id ASC');
            if (!empty($records)) {
                return array_values($records);
            }
        }

        return [];
    }

    /**
     * Format date input (e.g. YYYY-MM-DD from HTML5 input) into readable UI format (e.g. 17 Aug 2026).
     *
     * @param string $val
     * @return string
     */
    public static function format_display_date(string $val): string {
        $val = trim($val);
        if (empty($val)) {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $val)) {
            $ts = strtotime($val);
            if ($ts !== false) {
                return date('j M Y', $ts);
            }
        }
        return $val;
    }

    /**
     * Add a lesson plan day.
     *
     * @param int $sectionid
     * @param int $courseid
     * @param array $data
     * @param string $after Insert position ('end' or target day ID)
     * @return int
     */
    public static function add_day(int $sectionid, int $courseid, array $data, string $after = 'end'): int {
        global $DB;

        if (!$DB->get_manager()->table_exists(self::TABLE)) {
            return 0;
        }

        // 1. Determine sortorder based on insert position
        $sortorder = 0;
        if ($after === 'end' || empty($after)) {
            $max_so = $DB->get_field_sql(
                "SELECT MAX(sortorder) FROM {" . self::TABLE . "} WHERE sectionid = :sectionid AND courseid = :courseid",
                ['sectionid' => $sectionid, 'courseid' => $courseid]
            );
            $sortorder = ($max_so !== false && $max_so !== null) ? ((int)$max_so + 1) : 0;
        } else {
            // Find target record by ID or day_index
            $target = null;
            if (is_numeric($after)) {
                $target = $DB->get_record(self::TABLE, ['id' => (int)$after, 'sectionid' => $sectionid, 'courseid' => $courseid]);
                if (!$target) {
                    $target = $DB->get_record(self::TABLE, ['day_index' => (int)$after, 'sectionid' => $sectionid, 'courseid' => $courseid]);
                }
            }
            if ($target) {
                $target_so = (int)$target->sortorder;
                // Shift subsequent records
                $DB->execute(
                    "UPDATE {" . self::TABLE . "} SET sortorder = sortorder + 1 WHERE sectionid = :sectionid AND courseid = :courseid AND sortorder > :target_so",
                    ['sectionid' => $sectionid, 'courseid' => $courseid, 'target_so' => $target_so]
                );
                $sortorder = $target_so + 1;
            } else {
                $max_so = $DB->get_field_sql(
                    "SELECT MAX(sortorder) FROM {" . self::TABLE . "} WHERE sectionid = :sectionid AND courseid = :courseid",
                    ['sectionid' => $sectionid, 'courseid' => $courseid]
                );
                $sortorder = ($max_so !== false && $max_so !== null) ? ((int)$max_so + 1) : 0;
            }
        }

        // 2. Day index (Day number)
        $day_index = !empty($data['day_index']) ? (int)$data['day_index'] : 0;
        if ($day_index <= 0) {
            $max_day = $DB->get_field_sql(
                "SELECT MAX(day_index) FROM {" . self::TABLE . "} WHERE sectionid = :sectionid AND courseid = :courseid",
                ['sectionid' => $sectionid, 'courseid' => $courseid]
            );
            $day_index = ($max_day !== false && $max_day !== null) ? ((int)$max_day + 1) : 1;
        }

        $plan_date = self::format_display_date($data['planned_date'] ?? '');
        $act_date = self::format_display_date($data['actual_date'] ?? '');

        // 3. Mentor Biometric Attendance - decoupled modular resolution
        $cm_res = self::resolve_mentor_biometric_attendance(0, $act_date ?: $plan_date, $courseid, $sectionid, 'Class');
        $lm_res = self::resolve_mentor_biometric_attendance(0, $act_date ?: $plan_date, $courseid, $sectionid, 'Lab');
        $class_att = !empty($data['class_mentor_att']) ? $data['class_mentor_att'] : ($cm_res['status'] ?? '-');
        $lab_att = !empty($data['lab_mentor_att']) ? $data['lab_mentor_att'] : ($lm_res['status'] ?? '-');

        $record = (object)[
            'sectionid' => $sectionid,
            'courseid' => $courseid,
            'day_index' => $day_index,
            'planned_date' => clean_param($plan_date, PARAM_TEXT),
            'actual_date' => clean_param($act_date, PARAM_TEXT),
            'topic' => clean_param($data['topic'] ?? '', PARAM_RAW),
            'status' => clean_param($data['status'] ?? 'Pending', PARAM_TEXT),
            'class_mentor_att' => clean_param($class_att, PARAM_TEXT),
            'lab_mentor_att' => clean_param($lab_att, PARAM_TEXT),
            'sortorder' => $sortorder,
            'timemodified' => time(),
        ];

        $newid = (int)$DB->insert_record(self::TABLE, $record);

        // Normalize sortorder (0, 1, ...) and day_index (1, 2, ...) sequentially for all records in this section & course
        $scope_params = ['sectionid' => $sectionid];
        $scope_sql = "sectionid = :sectionid";
        if ($courseid > 0) {
            $scope_params['courseid'] = $courseid;
            $scope_sql .= " AND courseid = :courseid";
        }
        $all_recs = array_values($DB->get_records_select(self::TABLE, $scope_sql, $scope_params, 'sortorder ASC, id ASC'));
        foreach ($all_recs as $pos => $r) {
            $DB->set_field(self::TABLE, 'sortorder', $pos, ['id' => $r->id]);
            $DB->set_field(self::TABLE, 'day_index', $pos + 1, ['id' => $r->id]);
        }

        return $newid;
    }

    /**
     * Move a lesson plan day up (-1) or down (+1) in sort order.
     *
     * @param int $dayid
     * @param int $direction -1 for up, 1 for down
     * @return bool
     */
    public static function reorder_day(int $dayid, int $direction): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists(self::TABLE)) {
            return false;
        }

        $curr = $DB->get_record(self::TABLE, ['id' => $dayid]);
        if (!$curr) {
            return false;
        }

        $sec_id = (int)$curr->sectionid;
        $cid = (int)$curr->courseid;

        $params = ['sectionid' => $sec_id];
        $sql = "sectionid = :sectionid";
        if ($cid > 0) {
            $params['courseid'] = $cid;
            $sql .= " AND courseid = :courseid";
        }

        // Fetch all records for this section/course in current order.
        $records = array_values($DB->get_records_select(
            self::TABLE,
            $sql,
            $params,
            'sortorder ASC, id ASC'
        ));

        $idx = -1;
        foreach ($records as $i => $r) {
            if ((int)$r->id === $dayid) {
                $idx = $i;
                break;
            }
        }

        if ($idx === -1) {
            return false;
        }

        $target_idx = ($direction < 0) ? ($idx - 1) : ($idx + 1);
        if ($target_idx < 0 || $target_idx >= count($records)) {
            return false; // Already at boundary.
        }

        // Swap positions.
        $target = $records[$target_idx];
        $records[$target_idx] = $curr;
        $records[$idx] = $target;

        // Persist clean normalized sortorder (0, 1, 2, ...) and day_index (1, 2, 3, ...)
        foreach ($records as $pos => $rec) {
            $DB->set_field(self::TABLE, 'sortorder', $pos, ['id' => $rec->id]);
            $DB->set_field(self::TABLE, 'day_index', $pos + 1, ['id' => $rec->id]);
        }

        return true;
    }

    /**
     * Delete a lesson plan day.
     *
     * @param int $dayid
     * @return bool
     */
    public static function delete_day(int $dayid): bool {
        global $DB;
        if ($DB->get_manager()->table_exists(self::TABLE)) {
            $rec = $DB->get_record(self::TABLE, ['id' => $dayid]);
            $res = $DB->delete_records(self::TABLE, ['id' => $dayid]);
            if ($res && $rec) {
                // Re-normalize sortorder and day_index for remaining records in this section/course.
                $sec_id = (int)$rec->sectionid;
                $cid = (int)$rec->courseid;
                $params = ['sectionid' => $sec_id];
                $sql = "sectionid = :sectionid";
                if ($cid > 0) {
                    $params['courseid'] = $cid;
                    $sql .= " AND courseid = :courseid";
                }
                $remaining = array_values($DB->get_records_select(
                    self::TABLE,
                    $sql,
                    $params,
                    'sortorder ASC, id ASC'
                ));
                foreach ($remaining as $pos => $r) {
                    $DB->set_field(self::TABLE, 'sortorder', $pos, ['id' => $r->id]);
                    $DB->set_field(self::TABLE, 'day_index', $pos + 1, ['id' => $r->id]);
                }
            }
            return (bool)$res;
        }
        return false;
    }

    /**
     * Modular resolver for mentor attendance from custom biometric plugin.
     * Ready for plug-and-play connection when the user supplies custom attendance plugin details.
     *
     * @param int $userid
     * @param string $date
     * @param int $courseid
     * @param int $sectionid
     * @param string $role
     * @return array
     */
    public static function resolve_mentor_biometric_attendance(int $userid = 0, string $date = '', int $courseid = 0, int $sectionid = 0, string $role = 'Class'): array {
        // Pending biometric plugin integration - default placeholder is "-"
        return [
            'status' => '-',
            'time' => '',
            'tooltip' => 'Pending biometric reader integration',
        ];
    }

    /**
     * Compute aggregate delivered hours & coverage for the batch/module.
     * Delivered, Class, and Lab hours are calculated dynamically from mod_attendance session descriptions.
     * Target hours is calculated dynamically from planned days (planneddays * 6 hrs) with fallback to admin settings.
     * Modules covered is calculated from Batch Management moduledata.
     *
     * @param int $sectionid
     * @param int $courseid
     * @return array
     */
    public static function get_hours_and_coverage(int $sectionid, int $courseid = 0): array {
        global $DB;

        // 1. Resolve module progression, target hours, and course IDs from Batch Management
        $total_modules = 1;
        $modules_covered = 0;
        $course_ids = [];
        $dynamic_target_hrs = 0.0;

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
                        // Option A: Accumulate dynamic target hours from planned days (6 hrs/day: 3h class + 3h lab)
                        $pdays = !empty($m['planneddays']) ? (float)$m['planneddays'] : 0.0;
                        if ($courseid > 0) {
                            if ($cid === $courseid && $pdays > 0) {
                                $dynamic_target_hrs = $pdays * 6.0;
                            }
                        } else {
                            if ($pdays > 0) {
                                $dynamic_target_hrs += $pdays * 6.0;
                            }
                        }
                    }
                }
            }
        }

        // Target hours: dynamic calculation from planned duration (Option A) with fallback to admin setting / 570
        if ($dynamic_target_hrs > 0) {
            $target_hrs = (int)round($dynamic_target_hrs);
        } else {
            $target_hrs = (int)(get_config('local_batchanalytics', 'target_hours') ?: 570);
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

        // Mentor biometric attendance to be calculated from external biometric plugin.
        // Kept as placeholder '—' until the specific source plugin is provided.
        $mentor_att_class = '—';
        $mentor_att_lab = '—';

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

