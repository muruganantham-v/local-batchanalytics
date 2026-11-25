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
 * Service to compute Placement Eligibility Test (PET) Qualification stats for a batch.
 *
 * Supports:
 *  1. Live Moodle PET Course with attempt tracking via groups (<batch_name>, <batch_name>_2nd, <batch_name>_3rd)
 *     and dual-section quiz evaluation (Objective pass mark default 40, Programming pass mark default 30).
 *  2. Zoho CRM student records fallback.
 *  3. Persistent database table local_batchanalytics_pet fallback.
 *  4. Prototype benchmark fallback for demo batch 26001A.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pet_qualification_service {

    /**
     * Compute PET qualification statistics for a given batch.
     *
     * @param string $batch_name e.g. "25002A" or "26002A"
     * @param int $batch_id Optional section or batch ID
     * @param array $students Enrolled students dataset ($students_data) or array of usernames / uids
     * @return array
     */
    public static function get_batch_pet_stats(string $batch_name, int $batch_id = 0, array $students = []): array {
        global $DB;

        $stats = [
            'has_pet_course'            => true,
            'pet_conducted'             => true,
            'total_students'            => 0,
            'pet_cleared'               => 0,
            'first_attempt_cleared'     => 0,
            'second_attempt_cleared'    => 0,
            'third_attempt_cleared'     => 0,
            'pet_not_cleared'           => 0,
            'first_attempt_not_cleared' => 0,
            'second_attempt_not_cleared'=> 0,
            'third_attempt_not_cleared' => 0,
            'not_appeared'              => 0,
            'not_cleared'               => 0,
            'qualified_pct'             => 0.0,
            'activity_name'             => 'Placement Eligibility Test (PET)',
        ];

        $bclean = trim(preg_replace('/^(batch|sec|section)[\s\-_]+/i', '', trim($batch_name)));

        // ---------------------------------------------------------------------
        // Strategy 1: Check Live Moodle PET Course and Groups / Quizzes
        // ---------------------------------------------------------------------
        $pet_course_id = self::resolve_pet_course_id();
        if ($pet_course_id > 0) {
            $course_stats = self::get_pet_course_stats($pet_course_id, $batch_name, $batch_id, $students);
            if ($course_stats !== null) {
                return $course_stats;
            }
        }

        // ---------------------------------------------------------------------
        // Strategy 2: Normalize student list and query Zoho CRM student details
        // ---------------------------------------------------------------------
        $usernames = [];
        $uids_to_resolve = [];
        $known_userids = [];

        foreach ($students as $item) {
            if (is_array($item)) {
                $un = trim((string)($item['username'] ?? ''));
                if ($un !== '') {
                    $usernames[] = $un;
                } else if (!empty($item['userid']) && is_numeric($item['userid'])) {
                    $uids_to_resolve[] = (int)$item['userid'];
                    $known_userids[] = (int)$item['userid'];
                } else if (!empty($item['id']) && is_string($item['id']) && !is_numeric($item['id'])) {
                    $usernames[] = trim($item['id']);
                } else if (!empty($item['id']) && is_numeric($item['id'])) {
                    $known_userids[] = (int)$item['id'];
                }
            } else if (is_object($item)) {
                $un = trim((string)($item->username ?? ''));
                if ($un !== '') {
                    $usernames[] = $un;
                } else if (!empty($item->id) && is_numeric($item->id)) {
                    $uids_to_resolve[] = (int)$item->id;
                    $known_userids[] = (int)$item->id;
                }
            } else if (is_string($item)) {
                $val = trim($item);
                if ($val !== '') {
                    if (is_numeric($val)) {
                        $uids_to_resolve[] = (int)$val;
                        $known_userids[] = (int)$val;
                    } else {
                        $usernames[] = $val;
                    }
                }
            } else if (is_int($item) && $item > 0) {
                $uids_to_resolve[] = $item;
                $known_userids[] = $item;
            }
        }

        if (!empty($uids_to_resolve)) {
            list($in, $params) = $DB->get_in_or_equal($uids_to_resolve);
            $db_users = $DB->get_records_select('user', "id $in AND deleted = 0", $params, '', 'id, username');
            foreach ($db_users as $u) {
                $un = trim((string)$u->username);
                if ($un !== '') {
                    $usernames[] = $un;
                }
            }
        }

        $usernames = array_values(array_unique(array_filter($usernames)));
        $total_count = !empty($students) ? count($students) : count($usernames);
        $stats['total_students'] = $total_count;

        $records = [];
        if (!empty($usernames)) {
            try {
                if (class_exists('\\local_batchanalytics\\crmapi')) {
                    $crm = new \local_batchanalytics\crmapi();
                    if (method_exists($crm, 'get_students_details')) {
                        $records = $crm->get_students_details($usernames);
                    }
                }
            } catch (\Throwable $e) {
                debugging('PET CRM fetch failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // ---------------------------------------------------------------------
        // Strategy 3: Check database table local_batchanalytics_pet if CRM empty
        // ---------------------------------------------------------------------
        if (empty($records) && $DB->get_manager()->table_exists('local_batchanalytics_pet')) {
            $db_pet = [];
            if ($batch_id > 0) {
                $db_pet = $DB->get_records('local_batchanalytics_pet', ['batchid' => $batch_id]);
                if (empty($db_pet)) {
                    $db_pet = $DB->get_records('local_batchanalytics_pet', ['sectionid' => $batch_id]);
                }
            }
            if (empty($db_pet) && !empty($known_userids)) {
                list($uin, $uparams) = $DB->get_in_or_equal($known_userids);
                $db_pet = $DB->get_records_select('local_batchanalytics_pet', "userid $uin", $uparams);
            }

            if (!empty($db_pet)) {
                $cleared_1st = 0; $cleared_2nd = 0; $cleared_3rd = 0;
                $not_cleared_1st = 0; $not_cleared_2nd = 0; $not_cleared_3rd = 0;
                $user_outcomes = [];

                foreach ($db_pet as $p) {
                    $att = (int)$p->attempt_number;
                    $st = strtolower(trim($p->status));
                    $uid = (int)$p->userid;
                    if ($att === 1) {
                        if ($st === 'cleared') $cleared_1st++; else $not_cleared_1st++;
                    } else if ($att === 2) {
                        if ($st === 'cleared') $cleared_2nd++; else $not_cleared_2nd++;
                    } else if ($att === 3) {
                        if ($st === 'cleared') $cleared_3rd++; else $not_cleared_3rd++;
                    }
                    if ($st === 'cleared') {
                        $user_outcomes[$uid] = 'cleared';
                    } else if (!isset($user_outcomes[$uid])) {
                        $user_outcomes[$uid] = 'not_cleared';
                    }
                }

                $total_cleared = 0;
                $total_not_cleared = 0;
                foreach ($user_outcomes as $u => $out) {
                    if ($out === 'cleared') $total_cleared++;
                    else $total_not_cleared++;
                }

                $not_app = max(0, $total_count - count($user_outcomes));

                $stats['total_students']            = $total_count > 0 ? $total_count : count($user_outcomes);
                $stats['pet_cleared']               = $total_cleared;
                $stats['first_attempt_cleared']     = $cleared_1st;
                $stats['second_attempt_cleared']    = $cleared_2nd;
                $stats['third_attempt_cleared']     = $cleared_3rd;
                $stats['pet_not_cleared']           = $total_not_cleared;
                $stats['first_attempt_not_cleared'] = $not_cleared_1st;
                $stats['second_attempt_not_cleared']= $not_cleared_2nd;
                $stats['third_attempt_not_cleared'] = $not_cleared_3rd;
                $stats['not_appeared']              = $not_app;
                $stats['not_cleared']               = $total_not_cleared;
                $stats['qualified_pct']             = $stats['total_students'] > 0
                    ? round(($total_cleared / $stats['total_students']) * 100, 1)
                    : 0.0;
                return $stats;
            }
        }

        // If CRM records empty, mark as not conducted
        if (empty($records)) {
            $stats['pet_conducted'] = false;
            $stats['not_appeared'] = $total_count;
            return $stats;
        }

        // ---------------------------------------------------------------------
        // Strategy 4: Compute attempt statistics from CRM fields
        // ---------------------------------------------------------------------
        $cleared_1st = 0;
        $cleared_2nd = 0;
        $cleared_3rd = 0;
        $not_cleared_1st = 0;
        $not_cleared_2nd = 0;
        $not_cleared_3rd = 0;
        $total_cleared = 0;
        $currently_not_cleared = 0;
        $not_appeared = 0;

        foreach ($usernames as $username) {
            $key = strtolower(trim($username));
            $rec = $records[$key] ?? null;

            if (!$rec || !is_array($rec)) {
                $not_appeared++;
                continue;
            }

            $att1 = trim((string)($rec['st_Attempt_Result_PET'] ?? ''));
            $att2 = trim((string)($rec['nd_Attempt_Result_PET'] ?? ''));
            $att3 = trim((string)($rec['rd_Attempt_Result_PET'] ?? ''));
            $placement_eli = trim((string)($rec['Placement_Eli'] ?? ''));

            if (strcasecmp($att1, 'Cleared') === 0) {
                $cleared_1st++;
            } else if (strcasecmp($att1, 'Not Cleared') === 0) {
                $not_cleared_1st++;
            }

            if (strcasecmp($att2, 'Cleared') === 0) {
                $cleared_2nd++;
            } else if (strcasecmp($att2, 'Not Cleared') === 0) {
                $not_cleared_2nd++;
            }

            if (strcasecmp($att3, 'Cleared') === 0) {
                $cleared_3rd++;
            } else if (strcasecmp($att3, 'Not Cleared') === 0) {
                $not_cleared_3rd++;
            }

            $is_student_cleared = (
                strcasecmp($att1, 'Cleared') === 0 ||
                strcasecmp($att2, 'Cleared') === 0 ||
                strcasecmp($att3, 'Cleared') === 0 ||
                strcasecmp($placement_eli, 'Cleared') === 0
            );

            if ($is_student_cleared) {
                $total_cleared++;
            } else {
                $has_appeared = (
                    strcasecmp($att1, 'Not Cleared') === 0 ||
                    strcasecmp($att2, 'Not Cleared') === 0 ||
                    strcasecmp($att3, 'Not Cleared') === 0
                );

                if ($has_appeared) {
                    $currently_not_cleared++;
                } else {
                    $not_appeared++;
                }
            }
        }

        // Prototype benchmark fallback for 26001A if no attempt results found
        if ($cleared_1st === 0 && $cleared_2nd === 0 && $cleared_3rd === 0 &&
            $not_cleared_1st === 0 && $not_cleared_2nd === 0 && $not_cleared_3rd === 0) {
            $stats['pet_conducted'] = false;
            $stats['total_students'] = $total_count;
            $stats['not_appeared'] = $total_count;
            return $stats;
        }

        $stats['total_students']            = $total_count;
        $stats['pet_cleared']               = $total_cleared;
        $stats['first_attempt_cleared']     = $cleared_1st;
        $stats['second_attempt_cleared']    = $cleared_2nd;
        $stats['third_attempt_cleared']     = $cleared_3rd;
        $stats['pet_not_cleared']           = $currently_not_cleared;
        $stats['first_attempt_not_cleared'] = $not_cleared_1st;
        $stats['second_attempt_not_cleared']= $not_cleared_2nd;
        $stats['third_attempt_not_cleared'] = $not_cleared_3rd;
        $stats['not_appeared']              = $not_appeared;
        $stats['not_cleared']               = $currently_not_cleared;
        $stats['qualified_pct']             = $total_count > 0
            ? round(($total_cleared / $total_count) * 100, 1)
            : 0.0;

        return $stats;
    }

    /**
     * Resolve the configured PET course ID.
     * Looks up admin setting local_batchanalytics/pet_course_id,
     * falls back to the course of pet_dedicated_quiz_id / pet_quiz_id,
     * and finally auto-detects courses matching "Placement Eligib%" or "PET".
     *
     * @return int
     */
    public static function resolve_pet_course_id(): int {
        global $DB;

        // 1. Explicit admin setting
        $course_id = (int)get_config('local_batchanalytics', 'pet_course_id');
        if ($course_id > 0 && $DB->record_exists('course', ['id' => $course_id])) {
            return $course_id;
        }

        // 2. From dedicated quiz or sponsor quiz setting
        $quiz_id = (int)get_config('local_batchanalytics', 'pet_dedicated_quiz_id');
        if ($quiz_id <= 0) {
            $quiz_id = (int)get_config('local_batchanalytics', 'pet_quiz_id');
        }
        if ($quiz_id > 0 && $DB->get_manager()->table_exists('quiz')) {
            $q = $DB->get_record('quiz', ['id' => $quiz_id], 'id, course');
            if ($q && !empty($q->course) && $DB->record_exists('course', ['id' => (int)$q->course])) {
                return (int)$q->course;
            }
        }

        // 3. Auto-detect by course fullname or shortname
        if ($DB->get_manager()->table_exists('course')) {
            $sql = "SELECT id FROM {course} 
                     WHERE id > 1 
                       AND (fullname LIKE '%Placement Eligib%' 
                            OR fullname LIKE '%Placement Eligibility%' 
                            OR shortname LIKE 'PET%' 
                            OR fullname LIKE '%PET%')
                     ORDER BY id ASC";
            $detected = $DB->get_record_sql($sql, [], IGNORE_MULTIPLE);
            if ($detected && !empty($detected->id)) {
                return (int)$detected->id;
            }
        }

        return 0;
    }

    /**
     * Find PET attempt groups in the configured PET course for a given batch.
     *
     * In the PET course:
     *  - 1st attempt: group named <batch_name> (e.g. "25002A" or "26002A")
     *  - 2nd attempt: group named <batch_name>_2nd (e.g. "25002A_2nd" or "26002A_2nd")
     *  - 3rd attempt: group named <batch_name>_3rd (e.g. "25002A_3rd" or "26002A_3rd")
     *
     * @param int $courseid
     * @param string $batch_name
     * @return array Associative array ['attempt1' => ?int, 'attempt2' => ?int, 'attempt3' => ?int]
     */
    public static function find_pet_groups(int $courseid, string $batch_name): array {
        global $DB;

        $res = ['attempt1' => null, 'attempt2' => null, 'attempt3' => null];
        if ($courseid <= 0 || !$DB->get_manager()->table_exists('groups')) {
            return $res;
        }

        $groups = $DB->get_records('groups', ['courseid' => $courseid]);
        if (empty($groups)) {
            return $res;
        }

        $bclean = trim(preg_replace('/^(batch|sec|section|essci|cohort)[\s\-_]+/i', '', trim($batch_name)));
        $bclean_esc = preg_quote($bclean, '/');
        $bname_esc = preg_quote(trim($batch_name), '/');

        foreach ($groups as $g) {
            $gname = trim($g->name);
            $gclean = trim(preg_replace('/^(batch|sec|section|essci|cohort)[\s\-_]+/i', '', $gname));

            // 3rd attempt: check 3rd before 1st (e.g. 25002A_3rd, 25002A - 3rd, 25002A (3rd), 25002A_attempt3)
            if (
                strcasecmp($gname, $bclean . '_3rd') === 0 ||
                strcasecmp($gclean, $bclean . '_3rd') === 0 ||
                strcasecmp($gname, $bclean . '_3') === 0 ||
                strcasecmp($gname, $bclean . '-3rd') === 0 ||
                strcasecmp($gname, $bclean . '_third') === 0 ||
                strcasecmp($gname, $batch_name . '_3rd') === 0 ||
                preg_match('/^' . $bclean_esc . '[\s\-_]*(3rd|3|third|attempt[\s\-_]*3)(\b|_)/i', $gname) ||
                preg_match('/^' . $bclean_esc . '[\s\-_]*(3rd|3|third|attempt[\s\-_]*3)(\b|_)/i', $gclean) ||
                preg_match('/^' . $bclean_esc . '[\s\-_]*\(3(rd)?\)$/i', $gname)
            ) {
                $res['attempt3'] = (int)$g->id;
            }
            // 2nd attempt: e.g. 25002A_2nd, 25002A_2, 25002A - 2nd, 25002A (2nd), 25002A_attempt2
            else if (
                strcasecmp($gname, $bclean . '_2nd') === 0 ||
                strcasecmp($gclean, $bclean . '_2nd') === 0 ||
                strcasecmp($gname, $bclean . '_2') === 0 ||
                strcasecmp($gname, $bclean . '-2nd') === 0 ||
                strcasecmp($gname, $bclean . '_second') === 0 ||
                strcasecmp($gname, $batch_name . '_2nd') === 0 ||
                preg_match('/^' . $bclean_esc . '[\s\-_]*(2nd|2|second|attempt[\s\-_]*2)(\b|_)/i', $gname) ||
                preg_match('/^' . $bclean_esc . '[\s\-_]*(2nd|2|second|attempt[\s\-_]*2)(\b|_)/i', $gclean) ||
                preg_match('/^' . $bclean_esc . '[\s\-_]*\(2(nd)?\)$/i', $gname)
            ) {
                $res['attempt2'] = (int)$g->id;
            }
            // 1st attempt: exact match with batch name or stripped name
            else if (
                strcasecmp($gname, $batch_name) === 0 ||
                strcasecmp($gname, $bclean) === 0 ||
                strcasecmp($gclean, $bclean) === 0 ||
                preg_match('/^' . $bclean_esc . '(\s*\(?1st(\s*attempt)?\)?)?$/i', $gname) ||
                preg_match('/^' . $bclean_esc . '(\s*\(?1st(\s*attempt)?\)?)?$/i', $gclean) ||
                preg_match('/^' . $bname_esc . '(\s*\(?1st(\s*attempt)?\)?)?$/i', $gname)
            ) {
                $res['attempt1'] = (int)$g->id;
            }
        }

        // Fallback: If attempt1 group not yet matched, try case-insensitive contains
        if (!$res['attempt1'] && $bclean !== '') {
            foreach ($groups as $g) {
                $gname = trim($g->name);
                if (stripos($gname, $bclean) !== false &&
                    stripos($gname, '_2nd') === false &&
                    stripos($gname, '_3rd') === false &&
                    stripos($gname, '_2') === false &&
                    stripos($gname, '_3') === false &&
                    stripos($gname, '2nd') === false &&
                    stripos($gname, '3rd') === false) {
                    $res['attempt1'] = (int)$g->id;
                    break;
                }
            }
        }

        return $res;
    }

    /**
     * Compute statistics dynamically from live Moodle PET course quizzes and groups.
     *
     * @param int $courseid PET Course ID
     * @param string $batch_name e.g. "25002A"
     * @param int $batch_id
     * @param array $students
     * @return array|null Returns array of stats, or null if no group or student attempts found for this batch
     */
    public static function get_pet_course_stats(int $courseid, string $batch_name, int $batch_id = 0, array $students = []): ?array {
        global $DB;

        if ($courseid <= 0 || !$DB->get_manager()->table_exists('quiz') || !$DB->get_manager()->table_exists('quiz_attempts')) {
            return null;
        }

        $groups = self::find_pet_groups($courseid, $batch_name);
        $g1_id = $groups['attempt1'];
        $g2_id = $groups['attempt2'];
        $g3_id = $groups['attempt3'];

        // If no group for this batch exists in the PET course, PET is not conducted for this batch
        if (!$g1_id && !$g2_id && !$g3_id) {
            $total_count = !empty($students) ? count($students) : 0;
            return [
                'has_pet_course'            => true,
                'pet_conducted'             => false,
                'total_students'            => $total_count,
                'pet_cleared'               => 0,
                'first_attempt_cleared'     => 0,
                'second_attempt_cleared'    => 0,
                'third_attempt_cleared'     => 0,
                'pet_not_cleared'           => 0,
                'first_attempt_not_cleared' => 0,
                'second_attempt_not_cleared'=> 0,
                'third_attempt_not_cleared' => 0,
                'not_appeared'              => $total_count,
                'not_cleared'               => 0,
                'qualified_pct'             => 0.0,
                'activity_name'             => 'Placement Eligibility Test (PET)',
            ];
        }

        // Collect student user IDs belonging to the batch PET groups
        $g1_uids = $g1_id ? array_keys($DB->get_records_menu('groups_members', ['groupid' => $g1_id], '', 'userid, userid as u2')) : [];
        $g2_uids = $g2_id ? array_keys($DB->get_records_menu('groups_members', ['groupid' => $g2_id], '', 'userid, userid as u2')) : [];
        $g3_uids = $g3_id ? array_keys($DB->get_records_menu('groups_members', ['groupid' => $g3_id], '', 'userid, userid as u2')) : [];

        $group_uids = array_values(array_unique(array_merge($g1_uids, $g2_uids, $g3_uids)));

        // Total students is strictly the distinct count of students in the PET groups for this batch
        $target_uids = $group_uids;

        if (empty($target_uids)) {
            return [
                'has_pet_course'            => true,
                'pet_conducted'             => false,
                'total_students'            => 0,
                'pet_cleared'               => 0,
                'first_attempt_cleared'     => 0,
                'second_attempt_cleared'    => 0,
                'third_attempt_cleared'     => 0,
                'pet_not_cleared'           => 0,
                'first_attempt_not_cleared' => 0,
                'second_attempt_not_cleared'=> 0,
                'third_attempt_not_cleared' => 0,
                'not_appeared'              => 0,
                'not_cleared'               => 0,
                'qualified_pct'             => 0.0,
                'activity_name'             => 'Placement Eligibility Test (PET)',
            ];
        }

        // Quick lookup maps for group memberships
        $g1_map = array_fill_keys($g1_uids, true);
        $g2_map = array_fill_keys($g2_uids, true);
        $g3_map = array_fill_keys($g3_uids, true);

        // Configurable pass marks: default Objective 40, Programming 30
        $obj_pass = (float)get_config('local_batchanalytics', 'pet_objective_pass_mark');
        if ($obj_pass <= 0.0) {
            $obj_pass = 40.0;
        }

        $prog_pass = (float)get_config('local_batchanalytics', 'pet_programming_pass_mark');
        if ($prog_pass <= 0.0) {
            $prog_pass = 30.0;
        }

        $total_students = count($target_uids);
        $cleared_1st = 0;
        $cleared_2nd = 0;
        $cleared_3rd = 0;
        $not_cleared_1st = 0;
        $not_cleared_2nd = 0;
        $not_cleared_3rd = 0;
        $total_cleared = 0;
        $total_not_cleared = 0;
        $not_appeared = 0;

        foreach ($target_uids as $uid) {
            // Determine attempt tier directly by group membership (latest tier takes precedence)
            if (isset($g3_map[$uid])) {
                $student_attempt_tier = 3;
            } else if (isset($g2_map[$uid])) {
                $student_attempt_tier = 2;
            } else {
                $student_attempt_tier = 1;
            }

            // Find finished quiz attempts in the PET course for this user (ordered latest first)
            $quiz_sql = "SELECT qa.*, q.name as quizname 
                         FROM {quiz_attempts} qa 
                         JOIN {quiz} q ON q.id = qa.quiz 
                         WHERE q.course = ? AND qa.userid = ? AND qa.preview = 0 AND qa.state = 'finished'
                         ORDER BY CASE WHEN qa.timefinish > 0 THEN qa.timefinish ELSE qa.timestart END DESC, qa.id DESC";
            $attempts = $DB->get_records_sql($quiz_sql, [$courseid, $uid]);

            if (empty($attempts)) {
                $not_appeared++;
                continue;
            }

            // Check attempts: if any attempt cleared both pass marks, student is marked cleared
            $is_cleared = false;
            foreach ($attempts as $cur_att) {
                $eval = self::evaluate_attempt($cur_att, $obj_pass, $prog_pass);
                if ($eval['passed']) {
                    $is_cleared = true;
                    break;
                }
            }

            if ($is_cleared) {
                $total_cleared++;
                if ($student_attempt_tier === 1) {
                    $cleared_1st++;
                } else if ($student_attempt_tier === 2) {
                    $cleared_2nd++;
                } else {
                    $cleared_3rd++;
                }
            } else {
                $total_not_cleared++;
                if ($student_attempt_tier === 1) {
                    $not_cleared_1st++;
                } else if ($student_attempt_tier === 2) {
                    $not_cleared_2nd++;
                } else {
                    $not_cleared_3rd++;
                }
            }
        }

        $qualified_pct = $total_students > 0 ? round(($total_cleared / $total_students) * 100, 1) : 0.0;

        $course_rec = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
        $activity_name = $course_rec ? $course_rec->fullname : 'Placement Eligibility Test (PET)';

        return [
            'has_pet_course'            => true,
            'pet_conducted'             => true,
            'total_students'            => $total_students,
            'pet_cleared'               => $total_cleared,
            'first_attempt_cleared'     => $cleared_1st,
            'second_attempt_cleared'    => $cleared_2nd,
            'third_attempt_cleared'     => $cleared_3rd,
            'pet_not_cleared'           => $total_not_cleared,
            'first_attempt_not_cleared' => $not_cleared_1st,
            'second_attempt_not_cleared'=> $not_cleared_2nd,
            'third_attempt_not_cleared' => $not_cleared_3rd,
            'not_appeared'              => $not_appeared,
            'not_cleared'               => $total_not_cleared,
            'qualified_pct'             => $qualified_pct,
            'activity_name'             => $activity_name,
        ];
    }

    /**
     * Evaluate a quiz attempt by calculating Objective and Programming section marks.
     *
     * @param \stdClass $attempt Record from quiz_attempts
     * @param float $obj_pass Required pass mark for Objective section
     * @param float $prog_pass Required pass mark for Programming section
     * @return array
     */
    public static function evaluate_attempt(\stdClass $attempt, float $obj_pass = 40.0, float $prog_pass = 30.0): array {
        global $DB;

        // 1. Get quiz sections to identify slot boundaries
        $sections = $DB->get_records('quiz_sections', ['quizid' => $attempt->quiz], 'firstslot ASC');
        $sec_list = array_values($sections);
        $sec_map = [];
        $has_explicit_sections = false;

        for ($i = 0; $i < count($sec_list); $i++) {
            $from = (int)$sec_list[$i]->firstslot;
            $to = isset($sec_list[$i + 1]) ? ((int)$sec_list[$i + 1]->firstslot - 1) : 999999;
            $heading = strtolower(trim($sec_list[$i]->heading ?? ''));
            $type = 'other';
            if (strpos($heading, 'objective') !== false || strpos($heading, 'obj') !== false) {
                $type = 'objective';
                $has_explicit_sections = true;
            } else if (strpos($heading, 'program') !== false || strpos($heading, 'coding') !== false || strpos($heading, 'prog') !== false) {
                $type = 'programming';
                $has_explicit_sections = true;
            } else {
                if (count($sec_list) >= 2) {
                    if ($i === 0) {
                        $type = 'objective';
                    } else if ($i === 1) {
                        $type = 'programming';
                    }
                }
            }
            $sec_map[] = ['type' => $type, 'from' => $from, 'to' => $to];
        }

        // 2. Fetch marks per question slot from question engine tables
        $obj_score = 0.0;
        $obj_max = 0.0;
        $prog_score = 0.0;
        $prog_max = 0.0;
        $total_score = 0.0;
        $total_max = 0.0;
        $slot_records = [];

        if ($DB->get_manager()->table_exists('question_attempts') && $DB->get_manager()->table_exists('question_attempt_steps')) {
            $sql = "SELECT qa.slot, qa.maxmark, qas.fraction, qas.state, q.qtype
                    FROM {question_attempts} qa
                    JOIN {question} q ON q.id = qa.questionid
                    JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                    JOIN (
                        SELECT questionattemptid, MAX(sequencenumber) as maxseq
                        FROM {question_attempt_steps}
                        GROUP BY questionattemptid
                    ) latest ON latest.questionattemptid = qas.questionattemptid AND latest.maxseq = qas.sequencenumber
                    WHERE qa.questionusageid = :usageid
                    ORDER BY qa.slot ASC";
            $slots = $DB->get_records_sql($sql, ['usageid' => $attempt->uniqueid]);
            $slot_records = array_values($slots);

            // Determine if question types distinguish objective vs programming
            $has_prog_qtype = false;
            foreach ($slot_records as $sl) {
                $qt = strtolower((string)$sl->qtype);
                if (in_array($qt, ['coderunner', 'vpl', 'essay', 'code', 'programming'])) {
                    $has_prog_qtype = true;
                    break;
                }
            }

            $num_slots = count($slot_records);
            $mid_point = (int)ceil($num_slots / 2);

            foreach ($slot_records as $idx => $sl) {
                $slot_num = (int)$sl->slot;
                $maxmark = (float)$sl->maxmark;
                $mark = !is_null($sl->fraction) ? ((float)$sl->fraction * $maxmark) : 0.0;
                $qt = strtolower((string)$sl->qtype);

                // Priority 1: Quiz section heading match
                $type = 'other';
                foreach ($sec_map as $sm) {
                    if ($slot_num >= $sm['from'] && $slot_num <= $sm['to']) {
                        if ($sm['type'] !== 'other') {
                            $type = $sm['type'];
                        }
                        break;
                    }
                }

                // Priority 2: Question type identification if sections not explicit
                if ($type === 'other') {
                    if (in_array($qt, ['coderunner', 'vpl', 'essay', 'code', 'programming'])) {
                        $type = 'programming';
                    } else if (in_array($qt, ['multichoice', 'truefalse', 'match', 'shortanswer', 'numerical'])) {
                        $type = 'objective';
                    }
                }

                // Priority 3: If still undetermined, partition slots evenly (first half Obj, second half Prog)
                if ($type === 'other') {
                    if ($num_slots > 1 && !$has_explicit_sections && !$has_prog_qtype) {
                        $type = ($idx < $mid_point) ? 'objective' : 'programming';
                    } else {
                        $type = 'objective';
                    }
                }

                if ($type === 'objective') {
                    $obj_score += $mark;
                    $obj_max += $maxmark;
                } else if ($type === 'programming') {
                    $prog_score += $mark;
                    $prog_max += $maxmark;
                }
                $total_score += $mark;
                $total_max += $maxmark;
            }
        }

        // If no programming slots were identified and multiple slots exist without explicit sections, partition 50/50
        if ($prog_max <= 0.0 && $obj_max > 0.0 && count($slot_records) > 1 && !$has_explicit_sections) {
            $mid = (int)ceil(count($slot_records) / 2);
            $obj_score = 0.0; $obj_max = 0.0;
            $prog_score = 0.0; $prog_max = 0.0;
            foreach ($slot_records as $idx => $sl) {
                $maxmark = (float)$sl->maxmark;
                $mark = !is_null($sl->fraction) ? ((float)$sl->fraction * $maxmark) : 0.0;
                if ($idx < $mid) {
                    $obj_score += $mark;
                    $obj_max += $maxmark;
                } else {
                    $prog_score += $mark;
                    $prog_max += $maxmark;
                }
            }
        }

        // Fallback if no question engine slots found but sumgrades is present
        if ($total_max <= 0.0 && !is_null($attempt->sumgrades)) {
            $total_score = (float)$attempt->sumgrades;
            $half_score = $total_score / 2;
            $obj_passed = ($half_score >= $obj_pass) || ($total_score >= ($obj_pass + $prog_pass));
            $prog_passed = ($half_score >= $prog_pass) || ($total_score >= ($obj_pass + $prog_pass));
            $passed = $obj_passed && $prog_passed;
            return [
                'obj_score'   => $half_score,
                'obj_max'     => 50.0,
                'prog_score'  => $half_score,
                'prog_max'    => 50.0,
                'obj_passed'  => $obj_passed,
                'prog_passed' => $prog_passed,
                'passed'      => $passed,
                'total_score' => $total_score,
            ];
        }

        // Section pass determination:
        // If a section doesn't exist on this quiz (e.g. max mark is 0), that section does not block passing
        $obj_passed = true;
        if ($obj_max > 0.0) {
            $obj_passed = ($obj_pass > $obj_max)
                ? (($obj_score / $obj_max * 100) >= $obj_pass)
                : ($obj_score >= $obj_pass);
        }

        $prog_passed = true;
        if ($prog_max > 0.0) {
            $prog_passed = ($prog_pass > $prog_max)
                ? (($prog_score / $prog_max * 100) >= $prog_pass)
                : ($prog_score >= $prog_pass);
        }

        $passed = ($obj_passed && $prog_passed);

        return [
            'obj_score'   => $obj_score,
            'obj_max'     => $obj_max,
            'prog_score'  => $prog_score,
            'prog_max'    => $prog_max,
            'obj_passed'  => $obj_passed,
            'prog_passed' => $prog_passed,
            'passed'      => $passed,
            'total_score' => $total_score,
        ];
    }

    /**
     * Get individual student's PET status badge from the PET course.
     *
     * @param int $userid
     * @param int $courseid
     * @return array|null
     */
    public static function get_student_pet_status_from_course(int $userid, int $courseid = 0): ?array {
        global $DB;

        if ($courseid <= 0) {
            $courseid = self::resolve_pet_course_id();
        }

        if ($courseid <= 0 || !$DB->get_manager()->table_exists('quiz_attempts')) {
            return null;
        }

        $obj_pass = (float)get_config('local_batchanalytics', 'pet_objective_pass_mark');
        if ($obj_pass <= 0.0) $obj_pass = 40.0;
        $prog_pass = (float)get_config('local_batchanalytics', 'pet_programming_pass_mark');
        if ($prog_pass <= 0.0) $prog_pass = 30.0;

        $pet_quiz_id = (int)get_config('local_batchanalytics', 'pet_dedicated_quiz_id');
        if ($pet_quiz_id <= 0) {
            $pet_quiz_id = (int)get_config('local_batchanalytics', 'pet_quiz_id');
        }

        $sql = "SELECT qa.*, q.name as quizname 
                FROM {quiz_attempts} qa 
                JOIN {quiz} q ON q.id = qa.quiz 
                WHERE q.course = ? AND qa.userid = ? AND qa.preview = 0 AND qa.state = 'finished' ";
        $params = [$courseid, $userid];
        if ($pet_quiz_id > 0) {
            $sql .= " AND qa.quiz = ? ";
            $params[] = $pet_quiz_id;
        }
        $sql .= " ORDER BY CASE WHEN qa.timefinish > 0 THEN qa.timefinish ELSE qa.timestart END ASC, qa.id ASC";
        $attempts = $DB->get_records_sql($sql, $params);

        if (empty($attempts)) {
            // Check if user is enrolled in any group in this course
            $in_any_group = $DB->record_exists_sql(
                "SELECT 1 FROM {groups_members} gm JOIN {groups} g ON g.id = gm.groupid WHERE g.courseid = ? AND gm.userid = ?",
                [$courseid, $userid]
            );
            if ($in_any_group) {
                return [
                    'status' => 'Not appeared',
                    'attempt' => 1,
                    'badge' => 'Not appeared',
                ];
            }
            return null;
        }

        $att_list = array_values($attempts);
        $total_att = count($att_list);
        $is_cleared = false;
        $cleared_att = 0;
        $failed_att = 0;

        for ($i = 0; $i < $total_att; $i++) {
            $cur_att = $att_list[$i];
            $att_num = min(3, $i + 1);
            if (!empty($cur_att->attempt) && (int)$cur_att->attempt > $att_num && (int)$cur_att->attempt <= 3) {
                $att_num = (int)$cur_att->attempt;
            }
            $eval = self::evaluate_attempt($cur_att, $obj_pass, $prog_pass);
            if ($eval['passed']) {
                $is_cleared = true;
                $cleared_att = $att_num;
                break;
            } else {
                $failed_att = $att_num;
            }
        }

        if ($is_cleared) {
            return [
                'status'  => 'Cleared',
                'attempt' => $cleared_att,
                'badge'   => 'Cleared (' . self::ordinal($cleared_att) . ' attempt)',
            ];
        }

        return [
            'status'  => 'Not Cleared',
            'attempt' => $failed_att,
            'badge'   => 'Not Cleared (' . self::ordinal($failed_att) . ' attempt)',
        ];
    }

    /**
     * Fallback canonical benchmark for prototype batch 26001A.
     *
     * @return array
     */
    public static function get_prototype_benchmark_stats(): array {
        return [
            'has_pet_course'            => true,
            'pet_conducted'             => true,
            'total_students'            => 58,
            'pet_cleared'               => 49,
            'first_attempt_cleared'     => 31,
            'second_attempt_cleared'    => 12,
            'third_attempt_cleared'     => 6,
            'pet_not_cleared'           => 6,
            'first_attempt_not_cleared' => 4,
            'second_attempt_not_cleared'=> 2,
            'third_attempt_not_cleared' => 0,
            'not_appeared'              => 3,
            'not_cleared'               => 6,
            'qualified_pct'             => 84.5,
            'activity_name'             => 'Placement Eligibility Test (PET)',
        ];
    }

    /**
     * Helper to format numbers with ordinal suffixes.
     *
     * @param int $num
     * @return string
     */
    protected static function ordinal(int $num): string {
        $ends = ['th','st','nd','rd','th','th','th','th','th','th'];
        if ((($num % 100) >= 11) && (($num % 100) <= 13)) {
            return $num . 'th';
        }
        return $num . $ends[$num % 10];
    }
}
