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
 * Service class for the Student Details Page in local_batchanalytics v3.0.
 * Bridges Moodle identity, CRM student profile data, LMS course performance, and Counseling Notes.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_page_service {

    /**
     * Globally stored raw student CRM data from Zoho Child_Admission module.
     * Accessible across tabs, services, and helper methods.
     *
     * @var array<string, mixed>
     */
    public static array $crm_student_data = [];

    /**
     * Cache for college records fetched from Zoho CRM module "College".
     * @var array<string, array>
     */
    public static array $crm_college_cache = [];

    /**
     * Retrieve the globally stored student CRM data.
     *
     * @return array<string, mixed>
     */
    public static function get_crm_student_data(): array {
        return self::$crm_student_data;
    }

    /**
     * Check if a user has access to view the Student page.
     * Allowed personas: Assistant Manager, Mentor, SS Executive, SS Lead, Program Manager, Site Admins.
     *
     * @param int|null $userid
     * @return bool
     */
    public static function can_access(?int $userid = 0): bool {
        global $USER;
        $userid = (int)($userid ?? 0);
        if ($userid <= 0) {
            $userid = (int)$USER->id;
        }

        if (is_siteadmin($userid)) {
            return true;
        }

        $context = \context_system::instance();
        if (has_capability('local/batchanalytics:view', $context, $userid)
            || has_capability('local/batchanalytics:manage', $context, $userid)) {
            return true;
        }

        if (\local_batchanalytics\task_service::can_view_dashboard($userid)) {
            return true;
        }

        $personas = \local_batchanalytics\task_service::resolve_user_personas($userid);
        return !empty($personas['is_manager'])
            || !empty($personas['is_mentor'])
            || !empty($personas['is_sse'])
            || !empty($personas['is_sslead'])
            || !empty($personas['is_pm'])
            || !empty($personas['is_asst']);
    }

    /**
     * Check if a user has permission to view and add private Counseling Notes.
     * Allowed: Program Manager, SS Executive, SS Lead, Assistant Manager, Site Admins.
     * Students and unauthorized roles cannot access counseling notes.
     *
     * @param int|null $userid
     * @return bool
     */
    public static function can_manage_counseling_notes(?int $userid = 0): bool {
        global $USER;
        $userid = (int)($userid ?? 0);
        if ($userid <= 0) {
            $userid = (int)$USER->id;
        }

        if (is_siteadmin($userid)) {
            return true;
        }

        $context = \context_system::instance();
        if (has_capability('local/batchanalytics:manage', $context, $userid)) {
            return true;
        }

        $personas = \local_batchanalytics\task_service::resolve_user_personas($userid);
        return !empty($personas['is_manager'])
            || !empty($personas['is_pm'])
            || !empty($personas['is_sse'])
            || !empty($personas['is_sslead'])
            || !empty($personas['is_asst']);
    }

    /**
     * Get complete student profile and tabs payload.
     *
     * @param int $studentid Moodle User ID
     * @param int $batchid Optional class section ID
     * @param int $viewerid Current viewer user ID
     * @return array
     */
    public static function get_student_details(int $studentid, int $batchid = 0, int $viewerid = 0): array {
        global $DB, $USER;
        if ($viewerid <= 0) {
            $viewerid = (int)$USER->id;
        }

        $student_user = $DB->get_record('user', ['id' => $studentid, 'deleted' => 0]);
        if (!$student_user) {
            throw new \moodle_exception('invaliduser', 'error', '', 'Student record not found.');
        }

        $admission_id = trim($student_user->username);
        $fullname = trim(fullname($student_user));

        // Resolve Batch / Section metadata
        $sec = null;
        if ($batchid > 0 && $DB->get_manager()->table_exists('local_bm_classsection')) {
            $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
            if (!$sec) {
                $sec = $DB->get_record('local_bm_classsection', ['batchid' => $batchid]);
            }
        }
        if (!$sec && $DB->get_manager()->table_exists('local_bm_classsection')) {
            // Find class section by matching student's enrolled courses with moduledata
            $user_courses = enrol_get_users_courses((int)$student_user->id, true, 'id');
            if (!empty($user_courses)) {
                $cids = array_keys($user_courses);
                $all_secs = $DB->get_records('local_bm_classsection', null, 'id DESC');
                foreach ($all_secs as $s_cand) {
                    if (!empty($s_cand->moduledata)) {
                        $m_list = util::decode_module_data($s_cand->moduledata, true);
                        foreach ($m_list as $m_slot) {
                            $scid = (int)($m_slot['moodlecourseid'] ?? $m_slot['courseid'] ?? 0);
                            if (in_array($scid, $cids, true)) {
                                $sec = $s_cand;
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        $batch_code   = $sec ? util::clean_section_name($sec->name) : '-';
        $course_name  = $sec && !empty($sec->coursename) ? $sec->coursename : '-';
        $delivery_mode= $sec && !empty($sec->deliverymode) ? $sec->deliverymode : '-';

        // ---------------------------------------------------------------------
        // 1. Pull CRM Data using username (Admission ID) from Child_Admission
        // ---------------------------------------------------------------------
        $crm_record = self::fetch_crm_student_data($admission_id);
        $has_crm    = !empty($crm_record);

        // First name, Last name, and Email come strictly from LMS.
        $first_name = !empty($student_user->firstname) ? $student_user->firstname : '-';
        $last_name  = !empty($student_user->lastname) ? $student_user->lastname : '-';
        $email      = !empty($student_user->email) ? $student_user->email : '-';

        // Basic Info fields mapped from Zoho CRM Child_Admission module.
        $mobile            = !empty($crm_record['Mobile']) ? $crm_record['Mobile'] : (!empty($crm_record['Phone']) ? $crm_record['Phone'] : '-');
        $whatsapp          = !empty($crm_record['WhatsApp_Number']) ? $crm_record['WhatsApp_Number'] : (!empty($crm_record['Mobile']) ? $crm_record['Mobile'] : (!empty($crm_record['Phone']) ? $crm_record['Phone'] : '-'));
        $home_state        = !empty($crm_record['Home_State']) ? $crm_record['Home_State'] : (!empty($crm_record['State']) ? $crm_record['State'] : '-');
        $permanent_address = !empty($crm_record['Permanent_Address']) ? $crm_record['Permanent_Address'] : '-';
        $pin_code          = !empty($crm_record['Pin_Code']) ? $crm_record['Pin_Code'] : (!empty($crm_record['Zip_Code']) ? $crm_record['Zip_Code'] : '-');
        $parents_name      = !empty($crm_record['Parent_s_Name']) ? $crm_record['Parent_s_Name'] : (!empty($crm_record['Parents_Name']) ? $crm_record['Parents_Name'] : (!empty($crm_record['Father_Name']) ? $crm_record['Father_Name'] : '-'));
        $parents_phone     = !empty($crm_record['Parents_Mobile_Number']) ? $crm_record['Parents_Mobile_Number'] : (!empty($crm_record['Parents_Phone']) ? $crm_record['Parents_Phone'] : '-');
        $instagram         = !empty($crm_record['Instagram_Profile']) ? $crm_record['Instagram_Profile'] : (!empty($crm_record['Instagram']) ? $crm_record['Instagram'] : '-');
        $linkedin          = !empty($crm_record['LinkedIn_Profile']) ? $crm_record['LinkedIn_Profile'] : (!empty($crm_record['LinkedIn']) ? $crm_record['LinkedIn'] : '-');

        $basic_info = [
            'first_name'        => $first_name,
            'last_name'         => $last_name,
            'email'             => $email,
            'mobile'            => $mobile,
            'whatsapp'          => $whatsapp,
            'home_state'        => $home_state,
            'permanent_address' => $permanent_address,
            'pin_code'          => $pin_code,
            'parents_name'      => $parents_name,
            'parents_phone'     => $parents_phone,
            'instagram'         => $instagram,
            'linkedin'          => $linkedin,
        ];

        // ---------------------------------------------------------------------
        // Academic Info (Zoho CRM Child_Admission + College lookup)
        // ---------------------------------------------------------------------
        $class_x   = !empty($crm_record['Class_X_Score']) ? $crm_record['Class_X_Score'] : '-';
        $class_xii = !empty($crm_record['Class_XII_Score']) ? $crm_record['Class_XII_Score'] : '-';
        $diploma   = !empty($crm_record['Diploma_Score']) ? $crm_record['Diploma_Score'] : (!empty($crm_record['Diploma_Percentage']) ? $crm_record['Diploma_Percentage'] : '-');

        $btech_score  = !empty($crm_record['BE_BTech_Score']) ? $crm_record['BE_BTech_Score'] : '-';
        $btech_branch = !empty($crm_record['BE_BTech_Branch']) ? $crm_record['BE_BTech_Branch'] : '-';
        $btech_yop    = !empty($crm_record['BE_BTech_YoP']) ? $crm_record['BE_BTech_YoP'] : '-';

        // B.E / B.Tech College lookup: Colleges_Name.name and Colleges_Name.id
        $college_name = '';
        $college_id   = '';

        if (!empty($crm_record['Colleges_Name'])) {
            if (is_array($crm_record['Colleges_Name'])) {
                $college_name = trim((string)($crm_record['Colleges_Name']['name'] ?? ''));
                $college_id   = trim((string)($crm_record['Colleges_Name']['id'] ?? ''));
            } else if (is_object($crm_record['Colleges_Name'])) {
                $college_name = trim((string)($crm_record['Colleges_Name']->name ?? ''));
                $college_id   = trim((string)($crm_record['Colleges_Name']->id ?? ''));
            } else if (is_string($crm_record['Colleges_Name'])) {
                $college_name = trim($crm_record['Colleges_Name']);
            }
        }
        if ($college_name === '' && !empty($crm_record['College_Name'])) {
            $college_name = trim((string)$crm_record['College_Name']);
        }

        // Location (City) and State (College_State) from College module record
        $college_location = '';
        $college_state    = '';

        if ($college_id !== '') {
            $college_rec = self::fetch_crm_college_data($college_id);
            if (!empty($college_rec)) {
                if (!empty($college_rec['City'])) {
                    $college_location = trim((string)$college_rec['City']);
                }
                if (!empty($college_rec['College_State'])) {
                    $college_state = trim((string)$college_rec['College_State']);
                } else if (!empty($college_rec['State'])) {
                    $college_state = trim((string)$college_rec['State']);
                }
            }
        }

        if ($college_location === '') {
            $college_location = !empty($crm_record['College_Location']) ? trim((string)$crm_record['College_Location']) : '-';
        }
        if ($college_state === '') {
            $college_state = !empty($crm_record['College_State']) ? trim((string)$crm_record['College_State']) : '-';
        }
        if ($college_name === '') {
            $college_name = '-';
        }

        // M.E / M.Tech
        $mtech_score  = !empty($crm_record['ME_MTech_Score']) ? $crm_record['ME_MTech_Score'] : '-';
        $mtech_year   = !empty($crm_record['ME_MTech_YoP']) ? $crm_record['ME_MTech_YoP'] : '-';
        $mtech_branch = !empty($crm_record['ME_MTech_Branch']) ? $crm_record['ME_MTech_Branch'] : '-';

        $academic_info = [
            'class_x'        => $class_x,
            'class_xii'      => $class_xii,
            'diploma'        => $diploma,
            'btech_score'    => $btech_score,
            'btech_branch'   => $btech_branch,
            'btech_yop'      => $btech_yop,
            'btech_college'  => $college_name,
            'btech_location' => $college_location,
            'btech_state'    => $college_state,
            'mtech_score'    => $mtech_score,
            'mtech_year'     => $mtech_year,
            'mtech_branch'   => $mtech_branch,
        ];

        // ---------------------------------------------------------------------
        // 2. Pull Placement-Critical Data (LMS Pull & CRM Student Data)
        // ---------------------------------------------------------------------
        // Spot Awards from spot awards plugin (closed awards)
        $spot_awards = self::fetch_student_spot_awards((int)$student_user->id, $has_crm);

        $format_mock_score = static function($val): array {
            if ($val !== null && $val !== '') {
                $val_str = trim((string)$val);
                // Strip trailing % or /10 if already present in data
                $val_clean = rtrim($val_str, '% ');
                if (strpos($val_clean, '/') !== false) {
                    $parts = explode('/', $val_clean);
                    $val_clean = trim($parts[0]);
                }
                if ($val_clean !== '' && is_numeric($val_clean)) {
                    $num = (float)$val_clean;
                    $score_display = (floor($num) == $num) ? (string)(int)$num : (string)$num;
                    return [
                        'score_num' => $score_display,
                        'score'     => $score_display . '/10',
                    ];
                }
                if ($val_str !== '' && $val_str !== '—' && $val_str !== '-') {
                    return [
                        'score_num' => $val_str,
                        'score'     => $val_str,
                    ];
                }
            }
            return [
                'score_num' => '-',
                'score'     => '-',
            ];
        };

        $mock_adv_c = $format_mock_score($crm_record['Advanced_C_Score'] ?? null);
        $mock_mc    = $format_mock_score($crm_record['MC_Mock_Score3'] ?? null);
        $mock_ds    = $format_mock_score($crm_record['DS_Score'] ?? null);
        $mock_li    = $format_mock_score($crm_record['Linux_Internals_Score'] ?? null);

        $mock_scores = [
            [
                'name'      => 'Advanced C MOCK',
                'score'     => $mock_adv_c['score'],
                'score_num' => $mock_adv_c['score_num'],
            ],
            [
                'name'      => 'MC Mock Score',
                'score'     => $mock_mc['score'],
                'score_num' => $mock_mc['score_num'],
            ],
            [
                'name'      => 'Data Structures Score',
                'score'     => $mock_ds['score'],
                'score_num' => $mock_ds['score_num'],
            ],
            [
                'name'      => 'Linux Internals Score',
                'score'     => $mock_li['score'],
                'score_num' => $mock_li['score_num'],
            ],
        ];

        // Resolve PET Status, Score, and Attempt dynamically from Zoho CRM attempts (3rd -> 2nd -> 1st)
        $att1_res  = isset($crm_record['st_Attempt_Result_PET']) && $crm_record['st_Attempt_Result_PET'] !== null
            ? trim((string)$crm_record['st_Attempt_Result_PET']) : '';
        $att1_prog = isset($crm_record['st_Attempt_Programming_Score']) && $crm_record['st_Attempt_Programming_Score'] !== null
            ? trim((string)$crm_record['st_Attempt_Programming_Score']) : '';
        $att1_obj  = isset($crm_record['st_Attempt_Objective_Score']) && $crm_record['st_Attempt_Objective_Score'] !== null
            ? trim((string)$crm_record['st_Attempt_Objective_Score']) : '';

        $att2_res  = isset($crm_record['nd_Attempt_Result_PET']) && $crm_record['nd_Attempt_Result_PET'] !== null
            ? trim((string)$crm_record['nd_Attempt_Result_PET']) : '';
        $att2_prog = isset($crm_record['nd_Attempt_Programming_Score']) && $crm_record['nd_Attempt_Programming_Score'] !== null
            ? trim((string)$crm_record['nd_Attempt_Programming_Score']) : '';
        $att2_obj  = isset($crm_record['nd_Attempt_Objective_Score']) && $crm_record['nd_Attempt_Objective_Score'] !== null
            ? trim((string)$crm_record['nd_Attempt_Objective_Score']) : '';

        $att3_res  = isset($crm_record['rd_Attempt_Result_PET']) && $crm_record['rd_Attempt_Result_PET'] !== null
            ? trim((string)$crm_record['rd_Attempt_Result_PET']) : '';
        $att3_prog = isset($crm_record['rd_Attempt_Programming_Score']) && $crm_record['rd_Attempt_Programming_Score'] !== null
            ? trim((string)$crm_record['rd_Attempt_Programming_Score']) : '';
        $att3_obj  = isset($crm_record['rd_Attempt_Objective_Score']) && $crm_record['rd_Attempt_Objective_Score'] !== null
            ? trim((string)$crm_record['rd_Attempt_Objective_Score']) : '';

        $placement_eli = isset($crm_record['Placement_Eli']) && $crm_record['Placement_Eli'] !== null
            ? trim((string)$crm_record['Placement_Eli']) : '';

        $pet_status = '-';
        $pet_score = '-';
        $pet_attempt = '-';

        // Check 3rd attempt
        if ($att3_res !== '' || $att3_prog !== '' || $att3_obj !== '') {
            $pet_attempt = '3rd attempt';
            $pet_status = $att3_res !== '' ? $att3_res : ($placement_eli !== '' ? $placement_eli : '-');
            if ($att3_prog !== '' || $att3_obj !== '') {
                $total_num = (is_numeric($att3_prog) ? (float)$att3_prog : 0.0) + (is_numeric($att3_obj) ? (float)$att3_obj : 0.0);
                $pet_score = (string)(fmod($total_num, 1.0) == 0 ? (int)$total_num : round($total_num, 1));
            }
        } else if ($att2_res !== '' || $att2_prog !== '' || $att2_obj !== '') {
            $pet_attempt = '2nd attempt';
            $pet_status = $att2_res !== '' ? $att2_res : ($placement_eli !== '' ? $placement_eli : '-');
            if ($att2_prog !== '' || $att2_obj !== '') {
                $total_num = (is_numeric($att2_prog) ? (float)$att2_prog : 0.0) + (is_numeric($att2_obj) ? (float)$att2_obj : 0.0);
                $pet_score = (string)(fmod($total_num, 1.0) == 0 ? (int)$total_num : round($total_num, 1));
            }
        } else if ($att1_res !== '' || $att1_prog !== '' || $att1_obj !== '') {
            $pet_attempt = '1st attempt';
            $pet_status = $att1_res !== '' ? $att1_res : ($placement_eli !== '' ? $placement_eli : '-');
            if ($att1_prog !== '' || $att1_obj !== '') {
                $total_num = (is_numeric($att1_prog) ? (float)$att1_prog : 0.0) + (is_numeric($att1_obj) ? (float)$att1_obj : 0.0);
                $pet_score = (string)(fmod($total_num, 1.0) == 0 ? (int)$total_num : round($total_num, 1));
            }
        } else if ($placement_eli !== '') {
            $pet_status = $placement_eli;
        } else {
            $pet_status = '-';
            $pet_score = '-';
            $pet_attempt = '-';
        }

        // Determine dynamic status color and badge classes
        // Requirement: Cleared -> Green, Not Cleared -> Orange, Not Attented -> Red
        $pet_status_lower = strtolower($pet_status);
        if (strpos($pet_status_lower, 'cleared') !== false && strpos($pet_status_lower, 'not') === false) {
            $pet_status_color = '#15803d'; // Green
            $pet_badge_class  = 'pill-green';
            $pet_pdf_class    = 'pb-pet-green';
            $pet_hero_pill    = 'ba-pill-green';
            $pet_hero_pdf     = 'badge-green';
        } else if (strpos($pet_status_lower, 'not att') !== false) {
            $pet_status_color = '#dc2626'; // Red for Not Attented / Not Attended
            $pet_badge_class  = 'pill-red';
            $pet_pdf_class    = 'pb-pet-red';
            $pet_hero_pill    = 'ba-pill-red';
            $pet_hero_pdf     = 'badge-red';
        } else if (strpos($pet_status_lower, 'not cleared') !== false || strpos($pet_status_lower, 'fail') !== false) {
            $pet_status_color = '#ea580c'; // Orange for Not Cleared
            $pet_badge_class  = 'pill-orange';
            $pet_pdf_class    = 'pb-pet-orange';
            $pet_hero_pill    = 'ba-pill-orange';
            $pet_hero_pdf     = 'badge-orange';
        } else {
            $pet_status_color = '#6b7280'; // Gray / Neutral
            $pet_badge_class  = 'pill-neutral';
            $pet_pdf_class    = 'pb-pet-neutral';
            $pet_hero_pill    = 'ba-pill-neutral';
            $pet_hero_pdf     = 'badge-neutral';
        }

        // Check if CRM explicitly provides Power Track info (PowerTrack_Cohort or legacy fields)
        $powertrack_cohort = !empty($crm_record['PowerTrack_Cohort']) ? trim((string)$crm_record['PowerTrack_Cohort']) : '';
        if ($powertrack_cohort !== '') {
            $pt_val = (stripos($powertrack_cohort, 'power track') === false && stripos($powertrack_cohort, 'powertrack') === false)
                ? 'Power Track — ' . $powertrack_cohort
                : preg_replace('/^powertrack\s*[-—:]*\s*/i', 'Power Track — ', $powertrack_cohort);
            if (!in_array($pt_val, $spot_awards, true)) {
                $spot_awards[] = $pt_val;
            }
        } else if (!empty($crm_record['Power_Track'])) {
            $pt_val = trim((string)$crm_record['Power_Track']);
            if (stripos($pt_val, 'power track') === false && stripos($pt_val, 'powertrack') === false) {
                $pt_val = 'Power Track — ' . $pt_val;
            }
            if (!in_array($pt_val, $spot_awards, true)) {
                $spot_awards[] = $pt_val;
            }
        } else if (!empty($crm_record['Power_Track_Status'])) {
            $pt_val = trim((string)$crm_record['Power_Track_Status']);
            if (stripos($pt_val, 'power track') === false && stripos($pt_val, 'powertrack') === false) {
                $pt_val = 'Power Track — ' . $pt_val;
            }
            if (!in_array($pt_val, $spot_awards, true)) {
                $spot_awards[] = $pt_val;
            }
        }

        $placement_critical = [
            'pet_status'         => $pet_status,
            'pet_status_color'   => $pet_status_color,
            'pet_badge_class'    => $pet_badge_class,
            'pet_pdf_class'      => $pet_pdf_class,
            'pet_score'          => $pet_score,
            'pet_attempt'        => $pet_attempt,
            'spot_awards'        => $spot_awards,
            'powertrack_cohort'  => $powertrack_cohort,
            'mock_scores'        => $mock_scores,
        ];

        // ---------------------------------------------------------------------
        // 2.1 Dynamic Profile Card Hero Badges (PET, Spot Award, Power Track)
        // ---------------------------------------------------------------------
        $hero_badges = [];

        // 1. PET Dynamic Badge
        if (!empty($pet_status) && $pet_status !== '—' && $pet_status !== '-') {
            $att_suffix = '';
            if (!empty($pet_attempt) && $pet_attempt !== '—' && $pet_attempt !== '-') {
                $att_clean = trim($pet_attempt);
                if (stripos($att_clean, 'first') !== false || stripos($att_clean, '1st') !== false) {
                    $att_suffix = ' (1st attempt)';
                } else if (stripos($att_clean, 'second') !== false || stripos($att_clean, '2nd') !== false) {
                    $att_suffix = ' (2nd attempt)';
                } else if (stripos($att_clean, 'third') !== false || stripos($att_clean, '3rd') !== false) {
                    $att_suffix = ' (3rd attempt)';
                } else {
                    $att_suffix = ' (' . $att_clean . ')';
                }
            }

            $pill_cls = $pet_hero_pill;
            $pdf_cls  = $pet_hero_pdf;

            $pet_lbl = (stripos($pet_status, 'pet') === 0) ? $pet_status : ('PET: ' . $pet_status);
            $hero_badges[] = [
                'type'      => 'pet',
                'label'     => $pet_lbl . $att_suffix,
                'class'     => $pill_cls,
                'pdf_class' => $pdf_cls,
            ];
        }

        // 2. Spot Awards & Power Track Badges
        $spot_items = [];
        $pt_items   = [];
        foreach ($spot_awards as $saw) {
            if (stripos($saw, 'power track') !== false) {
                $pt_items[] = $saw;
            } else {
                $spot_items[] = $saw;
            }
        }

        // Spot Award dynamic count badge
        $spot_cnt = count($spot_items);
        if ($spot_cnt > 0) {
            $spot_text = ($spot_cnt > 1) ? "★ Spot Award ×{$spot_cnt}" : "★ Spot Award";
            $hero_badges[] = [
                'type'      => 'spot',
                'label'     => $spot_text,
                'class'     => 'ba-pill-amber',
                'pdf_class' => 'badge-amber',
                'count'     => $spot_cnt,
            ];
        }

        // Power Track badges (only rendered if student has Power Track recognition)
        foreach ($pt_items as $pt_item) {
            $hero_badges[] = [
                'type'      => 'power_track',
                'label'     => $pt_item,
                'class'     => 'ba-pill-purple',
                'pdf_class' => 'badge-purple',
            ];
        }

        // ---------------------------------------------------------------------
        // 3. Course Performance Data (LMS Pull)
        // ---------------------------------------------------------------------
        $performance_data = self::fetch_course_performance((int)$student_user->id, $sec);

        // ---------------------------------------------------------------------
        // 4. Counseling Notes
        // ---------------------------------------------------------------------
        $crm_record_id = !empty($crm_record['id']) ? (string)$crm_record['id'] : '';
        $counseling_notes = self::get_counseling_notes((int)$student_user->id, $crm_record_id);

        // Viewer Persona info for posting
        $viewer_personas = \local_batchanalytics\task_service::resolve_user_personas($viewerid);
        $viewer_user = ($viewerid === (int)$USER->id) ? $USER : $DB->get_record('user', ['id' => $viewerid]);
        $viewer_name = $viewer_user ? fullname($viewer_user) : 'User';
        $viewer_role_label = 'SS Executive';
        if (!empty($viewer_personas['is_pm'])) {
            $viewer_role_label = 'Program Manager';
        } else if (!empty($viewer_personas['is_asst'])) {
            $viewer_role_label = 'Assistant Manager';
        } else if (!empty($viewer_personas['is_mentor'])) {
            $viewer_role_label = 'Mentor';
        }

        // Initials Avatar
        $parts = preg_split('/\s+/', $fullname);
        $initials = count($parts) >= 2
            ? strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1))
            : strtoupper(mb_substr($fullname, 0, 2));

        return [
            'student_id'        => (int)$student_user->id,
            'admission_id'      => $admission_id,
            'fullname'          => $fullname,
            'initials'          => $initials,
            'batch_code'        => $batch_code,
            'batch_id'          => $sec ? (int)$sec->id : $batchid,
            'course_name'       => $course_name,
            'delivery_mode'     => $delivery_mode,
            'maac_rating'       => (!empty($performance_data['overall']['maac_rating_val']) && $performance_data['overall']['maac_rating_val'] !== '—' && $performance_data['overall']['maac_rating_val'] !== '-') ? $performance_data['overall']['maac_rating_val'] : '-',
            'overall_attendance'=> (!empty($performance_data['overall']['attendance']) && $performance_data['overall']['attendance'] !== '—' && $performance_data['overall']['attendance'] !== '-') ? $performance_data['overall']['attendance'] : '-',
            'total_projects'    => (!empty($performance_data['overall']['projects']) && $performance_data['overall']['projects'] !== '—' && $performance_data['overall']['projects'] !== '-') ? $performance_data['overall']['projects'] : '-',
            'hero_badges'       => $hero_badges,
            'basic_info'        => $basic_info,
            'academic_info'     => $academic_info,
            'placement_critical'=> $placement_critical,
            'course_performance'=> $performance_data,
            'counseling_notes'  => $counseling_notes,
            'crm_student_data'  => self::$crm_student_data,
            'can_manage_notes'  => self::can_manage_counseling_notes($viewerid),
            'viewer_name'       => $viewer_name,
            'viewer_role'       => $viewer_role_label,
        ];
    }

    /**
     * Get a valid Zoho CRM OAuth access token using credentials or cache.
     *
     * @param bool $force_refresh
     * @return string|null
     */
    public static function get_zoho_access_token(bool $force_refresh = false): ?string {
        if (!$force_refresh) {
            try {
                $cache = \cache::make('local_batchanalytics', 'zohotokendata');
                $cached_token = $cache->get('zoho_access_token');
                if (is_array($cached_token) && !empty($cached_token['token']) && ($cached_token['expires'] ?? 0) > time() + 60) {
                    return $cached_token['token'];
                }
            } catch (\Throwable $e) {}
        }

        $client_id     = trim((string)get_config('local_batchanalytics', 'zoho_client_id'));
        $client_secret = trim((string)get_config('local_batchanalytics', 'zoho_client_secret'));
        $refresh_token = trim((string)get_config('local_batchanalytics', 'zoho_refresh_token'));
        $accounts_url  = trim((string)get_config('local_batchanalytics', 'zoho_accounts_url')) ?: 'https://accounts.zoho.com';

        if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
            return null;
        }

        try {
            global $CFG;
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl();
            $token_url = rtrim($accounts_url, '/') . '/oauth/v2/token';
            $resp = $curl->post($token_url, [
                'refresh_token' => $refresh_token,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'grant_type'    => 'refresh_token',
            ]);
            $json = json_decode($resp, true);
            if (!empty($json['access_token'])) {
                $token = $json['access_token'];
                try {
                    $cache = \cache::make('local_batchanalytics', 'zohotokendata');
                    $cache->set('zoho_access_token', [
                        'token'   => $token,
                        'expires' => time() + (int)($json['expires_in'] ?? 3600),
                    ]);
                } catch (\Throwable $e) {}
                return $token;
            }
        } catch (\Throwable $e) {}

        return null;
    }

    /**
     * Get the Zoho CRM API base URL (e.g. https://www.zohoapis.in or https://www.zohoapis.com).
     *
     * @return string
     */
    public static function get_zoho_api_base_url(): string {
        $url = trim((string)get_config('local_batchanalytics', 'zoho_api_base_url'));
        return !empty($url) ? rtrim($url, '/') : 'https://www.zohoapis.com';
    }

    /**
     * Helper to resolve the Zoho CRM record ID for a given student Moodle user ID.
     *
     * @param int $studentid
     * @return string
     */
    public static function get_student_crm_record_id(int $studentid): string {
        global $DB;
        if (!empty(self::$crm_student_data['id'])) {
            return (string)self::$crm_student_data['id'];
        }
        $student_user = $DB->get_record('user', ['id' => $studentid]);
        if ($student_user) {
            $candidates = array_filter([
                !empty($student_user->username) ? trim($student_user->username) : '',
                !empty($student_user->idnumber) ? trim($student_user->idnumber) : '',
            ]);
            foreach ($candidates as $cand) {
                // If in-memory cache had no 'id', clear it to force a fresh lookup with 'id'
                if (!empty(self::$crm_student_data) && empty(self::$crm_student_data['id'])) {
                    self::$crm_student_data = [];
                }
                $record = self::fetch_crm_student_data($cand);
                if (!empty($record['id'])) {
                    return (string)$record['id'];
                }
            }
        }
        return '';
    }

    /**
     * Fetch student data from Zoho CRM Child_Admission module using username (Admission Number).
     * Stores the response globally in self::$crm_student_data for use across all tabs.
     *
     * @param string $username
     * @return array
     */
    public static function fetch_crm_student_data(string $username): array {
        if (empty($username)) {
            return [];
        }

        $username = trim($username);
        $lower    = strtolower($username);

        // If already cached in memory for this username and has 'id', return cached response.
        if (!empty(self::$crm_student_data) && isset(self::$crm_student_data['username']) && strtolower(self::$crm_student_data['username']) === $lower && !empty(self::$crm_student_data['id'])) {
            return self::$crm_student_data;
        }

        $record = [];

        // 1. Attempt direct Zoho CRM search on module Child_Admission with all required fields.
        try {
            $api_base_url = self::get_zoho_api_base_url();
            $module_name  = \local_batchanalytics\crm_fields_helper::get_student_module_api_name();
            $token        = self::get_zoho_access_token();

            // Search Child_Admission module using Admission_Number
            if ($token) {
                global $CFG;
                require_once($CFG->libdir . '/filelib.php');
                $curl = new \curl();
                $curl->setHeader([
                    "Authorization: Zoho-oauthtoken {$token}",
                ]);

                // All mapped fields from Child_Admission for Basic Info, Academic, and other tabs
                // IMPORTANT: 'id' must be the first field requested so Zoho CRM returns the record ID
                $fields = [
                    'id',
                    'Admission_Number',
                    // Basic Details fields requested by user
                    'Mobile',
                    'WhatsApp_Number',
                    'Home_State',
                    'Permanent_Address',
                    'Pin_Code',
                    'Parent_s_Name',
                    'Parents_Mobile_Number',
                    'Instagram_Profile',
                    'LinkedIn_Profile',
                    // Academic fields
                    'Class_X_Score',
                    'Class_XII_Score',
                    'Diploma_Score',
                    'BE_BTech_Score',
                    'BE_BTech_Branch',
                    'BE_BTech_YoP',
                    'College_Name',
                    'College_Location',
                    'College_State',
                    'ME_MTech_Score',
                    'ME_MTech_Branch',
                    'ME_MTech_YoP',
                    // Placement & Mock fields
                    'Advanced_C_Score',
                    'C_Score',
                    'DS_Score',
                    'Linux_Internals_Score',
                    'MC_Mock_Score3',
                    'Coach_Rating',
                    'MAAC_Rating',
                    'Placement_Eli',
                    'st_Attempt_Result_PET',
                    'nd_Attempt_Result_PET',
                    'rd_Attempt_Result_PET',
                    'st_Attempt_Programming_Score',
                    'st_Attempt_Objective_Score',
                    'nd_Attempt_Objective_Score',
                    'nd_Attempt_Programming_Score',
                    'rd_Attempt_Programming_Score',
                    'rd_Attempt_Objective_Score',
                    'CTC',
                    'PowerTrack_Cohort',
                    'Father_Name',
                ];

                $criteria = '(Admission_Number:equals:' . rawurlencode($username) . ')';
                $fields_param = implode(',', array_unique($fields));
                $search_url = rtrim($api_base_url, '/') . '/crm/v6/' . $module_name . '/search?criteria=' . $criteria . '&fields=' . rawurlencode($fields_param);

                $resp_body = $curl->get($search_url);
                $info = $curl->get_info();
                $http_code = (int)($info['http_code'] ?? 0);

                if ($http_code === 401) {
                    $token = self::get_zoho_access_token(true);
                    if ($token) {
                        $curl = new \curl();
                        $curl->setHeader(["Authorization: Zoho-oauthtoken {$token}"]);
                        $resp_body = $curl->get($search_url);
                        $info = $curl->get_info();
                        $http_code = (int)($info['http_code'] ?? 0);
                    }
                }

                if ($http_code >= 200 && $http_code < 300) {
                    $search_json = json_decode($resp_body, true);
                    if (!empty($search_json['data'][0]) && is_array($search_json['data'][0])) {
                        $record = $search_json['data'][0];
                        if (!empty($record['id'])) {
                            $record['id'] = (string)$record['id'];
                        }
                    }
                } else {
                    debugging("Zoho CRM Student Search failed (HTTP {$http_code}): {$resp_body}", DEBUG_DEVELOPER);
                }
            }
        } catch (\Throwable $e) {
            debugging("Zoho CRM Student Search exception: " . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // 2. Fallback to crmapi helper if direct request didn't find record
        if (empty($record)) {
            try {
                $crm = new \local_batchanalytics\crmapi();
                $records = $crm->get_students_details([$username]);
                if (!empty($records[$lower]) && is_array($records[$lower])) {
                    $record = $records[$lower];
                } else if (!empty($records[$username]) && is_array($records[$username])) {
                    $record = $records[$username];
                }
            } catch (\Throwable $e) {}
        }

        // If record was fetched from crmapi fallback without 'id', perform quick lookup for record ID
        if (!empty($record) && empty($record['id'])) {
            try {
                $token = self::get_zoho_access_token();
                if ($token) {
                    $curl = new \curl();
                    $curl->setHeader(["Authorization: Zoho-oauthtoken {$token}"]);
                    $api_base_url = self::get_zoho_api_base_url();
                    $module_name  = \local_batchanalytics\crm_fields_helper::get_student_module_api_name();
                    $search_url = rtrim($api_base_url, '/') . '/crm/v6/' . $module_name . '/search?criteria=(Admission_Number:equals:' . rawurlencode($username) . ')&fields=id';
                    $resp = $curl->get($search_url);
                    $json = json_decode($resp, true);
                    if (!empty($json['data'][0]['id'])) {
                        $record['id'] = (string)$json['data'][0]['id'];
                    }
                }
            } catch (\Throwable $e) {}
        }

        // Tag record with username
        if (!empty($record)) {
            $record['username'] = $username;
        }

        // Store response globally so it is accessible to all tabs
        self::$crm_student_data = $record;

        return self::$crm_student_data;
    }

    /**
     * Fetch a College record from Zoho CRM module "College" by ID.
     * Extracts City (Location) and College_State (State).
     *
     * @param string $college_id
     * @return array
     */
    public static function fetch_crm_college_data(string $college_id): array {
        $college_id = trim($college_id);
        if ($college_id === '') {
            return [];
        }

        if (isset(self::$crm_college_cache[$college_id])) {
            return self::$crm_college_cache[$college_id];
        }

        $record = [];
        try {
            $api_base_url = self::get_zoho_api_base_url();
            $token        = self::get_zoho_access_token();

            if ($token) {
                global $CFG;
                require_once($CFG->libdir . '/filelib.php');
                $curl = new \curl();
                $curl->setHeader([
                    "Authorization: Zoho-oauthtoken {$token}",
                ]);

                // Query specific record from module "College" by ID: /crm/v6/College/{id}
                $clean_id = preg_replace('/[^0-9]/', '', $college_id);
                $college_url = rtrim($api_base_url, '/') . '/crm/v6/College/' . $clean_id;

                $resp_body = $curl->get($college_url);
                $info = $curl->get_info();
                $http_code = (int)($info['http_code'] ?? 0);

                if ($http_code >= 200 && $http_code < 300) {
                    $res_json = json_decode($resp_body, true);
                    if (!empty($res_json['data'][0]) && is_array($res_json['data'][0])) {
                        $record = $res_json['data'][0];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fail open
        }

        self::$crm_college_cache[$college_id] = $record;
        return $record;
    }

    /**
     * Fetch closed merit spot awards for the student from spot award tables.
     * Matches the spot award query logic in module.php by checking nominations_sa_items / spotaward_nomination_items
     * joined with nominations_sa_nominations / spotaward_nominations, filtering strictly for closed status
     * (sni.status = 'closed') and extracting the closed award name.
     *
     * @param int $studentid
     * @param bool $has_crm
     * @return array
     */
    private static function fetch_student_spot_awards(int $studentid, bool $has_crm = false): array {
        global $DB;
        $awards = [];

        try {
            $dbman = $DB->get_manager();
            $items_tbl = $dbman->table_exists('nominations_sa_items') ? 'nominations_sa_items' : ($dbman->table_exists('spotaward_nomination_items') ? 'spotaward_nomination_items' : '');
            $noms_tbl  = $dbman->table_exists('nominations_sa_nominations') ? 'nominations_sa_nominations' : ($dbman->table_exists('spotaward_nominations') ? 'spotaward_nominations' : '');

            if ($items_tbl && $studentid > 0) {
                $item_cols = $DB->get_columns($items_tbl);
                $nom_cols  = !empty($noms_tbl) ? $DB->get_columns($noms_tbl) : [];

                // Replicate module.php closed status constraint: sni.status = 'closed'
                $status_clause = isset($item_cols['status']) ? "AND LOWER(sni.status) = 'closed'" : "";

                $selects = ['sni.id'];
                if (isset($item_cols['awardcategory'])) {
                    $selects[] = 'sni.awardcategory AS item_category';
                } else if (isset($item_cols['category'])) {
                    $selects[] = 'sni.category AS item_category';
                }

                if (isset($item_cols['awardname'])) {
                    $selects[] = 'sni.awardname AS item_awardname';
                }

                if (!empty($noms_tbl)) {
                    if (isset($nom_cols['awardname'])) {
                        $selects[] = 'sn.awardname AS nom_awardname';
                    } else if (isset($nom_cols['name'])) {
                        $selects[] = 'sn.name AS nom_name';
                    }
                    if (isset($nom_cols['awardcategory'])) {
                        $selects[] = 'sn.awardcategory AS nom_category';
                    } else if (isset($nom_cols['category'])) {
                        $selects[] = 'sn.category AS nom_category';
                    }

                    $sql = "SELECT " . implode(', ', $selects) . "
                              FROM {{$items_tbl}} sni
                              JOIN {{$noms_tbl}} sn ON sn.id = sni.nominationid
                             WHERE sni.studentid = :uid
                                   {$status_clause}
                          ORDER BY sni.id DESC";
                } else {
                    $sql = "SELECT " . implode(', ', $selects) . "
                              FROM {{$items_tbl}} sni
                             WHERE sni.studentid = :uid
                                   {$status_clause}
                          ORDER BY sni.id DESC";
                }

                $records = $DB->get_records_sql($sql, ['uid' => $studentid], 0, 20);
                foreach ($records as $r) {
                    $name = '';
                    if (!empty($r->item_category)) {
                        $name = trim((string)$r->item_category);
                    } else if (!empty($r->nom_awardname)) {
                        $name = trim((string)$r->nom_awardname);
                    } else if (!empty($r->nom_name)) {
                        $name = trim((string)$r->nom_name);
                    } else if (!empty($r->nom_category)) {
                        $name = trim((string)$r->nom_category);
                    } else if (!empty($r->item_awardname)) {
                        $name = trim((string)$r->item_awardname);
                    }

                    if ($name !== '') {
                        if (stripos($name, 'spot') === 0 || mb_strpos($name, '★') === 0) {
                            $badge = $name;
                        } else {
                            $badge = '★ Spot — ' . $name;
                        }
                        $awards[] = $badge;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Gracefully catch any DB errors and fall back
        }

        // De-duplicate multiple awards with identical names
        $awards = array_values(array_unique($awards));

        return $awards;
    }

    /**
     * Extract standardized curriculum module code (e.g. LS, ADVC, DS, MC, LI, ELARM, C)
     * from course shortname, module name, or fullname.
     *
     * @param string $shortname Course shortname (e.g. LS101:25023, DS104:23001, DS104:23005)
     * @param string $modulename Batch management module name (e.g. Data Structures)
     * @param string $fullname Course fullname
     * @return string Normalized uppercase module code (e.g. DS)
     */
    private static function extract_module_code(string $shortname, string $modulename = '', string $fullname = ''): string {
        $shortname = trim($shortname);

        // Core curriculum codes in order of precedence (longer codes first to prevent partial prefix match)
        $known_codes = ['ELARM', 'ADVC', 'LS', 'DS', 'MC', 'LI', 'C'];

        // 1. Check if shortname contains or matches any known core curriculum code
        foreach ($known_codes as $kc) {
            if (preg_match('/(^|[^A-Za-z])' . preg_quote($kc, '/') . '([^A-Za-z]|\d|$)/i', $shortname)) {
                return strtoupper($kc);
            }
        }

        // 2. Extract leading alphabetical characters until first digit (user rule: take string up to number reach)
        // Strips any batch prefix before letters (e.g. "23001_DS104" -> "DS104" -> "DS")
        $clean_short = preg_replace('/^[0-9_\-\s:]+/', '', $shortname);
        if (preg_match('/^([A-Za-z]+)/', $clean_short, $matches)) {
            return strtoupper($matches[1]);
        }

        // 3. Fallback to module name from moduledata or course fullname
        $name_to_check = !empty($modulename) ? $modulename : $fullname;
        if (!empty($name_to_check)) {
            foreach ($known_codes as $kc) {
                if (stripos($name_to_check, $kc) !== false) {
                    return strtoupper($kc);
                }
            }
            if (stripos($name_to_check, 'Data Structure') !== false) {
                return 'DS';
            }
            if (stripos($name_to_check, 'Advanced C') !== false) {
                return 'ADVC';
            }
            if (stripos($name_to_check, 'Linux System') !== false) {
                return 'LS';
            }
            if (stripos($name_to_check, 'Linux Internal') !== false) {
                return 'LI';
            }
            if (stripos($name_to_check, 'Microcontroller') !== false) {
                return 'MC';
            }
            if (stripos($name_to_check, 'Embedded') !== false || stripos($name_to_check, 'ARM') !== false) {
                return 'ELARM';
            }
        }

        $alpha_only = preg_replace('/[^A-Za-z]/', '', $shortname);
        return !empty($alpha_only) ? strtoupper(substr($alpha_only, 0, 4)) : 'MOD';
    }

    /**
     * Fetch student's overall grade percentage and activity stats for a given course.
     * Evaluates Gradebook Course Total and course activity submissions to accurately reflect student performance.
     *
     * @param int $courseid
     * @param int $studentid
     * @return array
     */
    private static function get_student_course_gradebook_summary(int $courseid, int $studentid): array {
        global $DB;

        $result = [
            'grade_pct'     => -1.0,
            'has_grade'     => false,
            'graded_count'  => 0,
            'timemodified'  => 0,
            'course_total'  => null,
        ];

        // 1. Try Course Total grade item
        $course_gi = $DB->get_record('grade_items', ['courseid' => $courseid, 'itemtype' => 'course']);
        if ($course_gi) {
            $range = (float)$course_gi->grademax - (float)$course_gi->grademin;
            $course_gg = $DB->get_record('grade_grades', ['itemid' => $course_gi->id, 'userid' => $studentid]);
            if ($course_gg) {
                $result['timemodified'] = (int)($course_gg->timemodified ?? 0);
                $final = ($course_gg->finalgrade !== null) ? (float)$course_gg->finalgrade : (($course_gg->rawgrade !== null) ? (float)$course_gg->rawgrade : null);
                if ($final !== null && $range > 0) {
                    $result['grade_pct'] = (($final - (float)$course_gi->grademin) / $range) * 100.0;
                    $result['has_grade'] = true;
                    $result['course_total'] = $result['grade_pct'];
                }
            }
        }

        // 2. Query activity grade items (mods/manuals) in the course for this student
        $act_sql = "
            SELECT gi.id, gi.grademin, gi.grademax, gg.finalgrade, gg.rawgrade, gg.timemodified
              FROM {grade_items} gi
              JOIN {grade_grades} gg ON gg.itemid = gi.id
             WHERE gi.courseid = :courseid
               AND gi.itemtype IN ('mod', 'manual')
               AND gg.userid = :userid
               AND (gg.finalgrade IS NOT NULL OR gg.rawgrade IS NOT NULL)
        ";
        $act_grades = $DB->get_records_sql($act_sql, ['courseid' => $courseid, 'userid' => $studentid]);
        $result['graded_count'] = count($act_grades);

        // Track latest activity submission/grade timestamp
        foreach ($act_grades as $ag) {
            if (!empty($ag->timemodified) && (int)$ag->timemodified > $result['timemodified']) {
                $result['timemodified'] = (int)$ag->timemodified;
            }
        }

        // If Course Total grade was not yet aggregated in Moodle, but student has graded activities (e.g. newly submitted in batch shift):
        if (!$result['has_grade'] && !empty($act_grades)) {
            $tot_earned = 0.0;
            $tot_max = 0.0;
            foreach ($act_grades as $ag) {
                $gmin = (float)$ag->grademin;
                $gmax = (float)$ag->grademax;
                $range = $gmax - $gmin;
                if ($range > 0) {
                    $val = ($ag->finalgrade !== null) ? (float)$ag->finalgrade : (float)$ag->rawgrade;
                    $tot_earned += ($val - $gmin);
                    $tot_max += $range;
                }
            }
            if ($tot_max > 0) {
                $result['grade_pct'] = ($tot_earned / $tot_max) * 100.0;
                $result['has_grade'] = true;
            }
        }

        return $result;
    }

    /**
     * Build Level 1 and Level 2 Course Performance metrics for the student.
     * Sourced from enrolled courses mapped to modules in Batch Management.
     * De-duplicates multiple courses for the same module by comparing Gradebook grades and picking the highest.
     *
     * @param int $studentid
     * @param \stdClass|null $sec
     * @return array
     */
    private static function fetch_course_performance(int $studentid, ?\stdClass $sec): array {
        global $DB;

        // 1. Resolve relevant class sections from Batch Management
        $all_sections = [];
        if ($sec && !empty($sec->id)) {
            $all_sections[(int)$sec->id] = $sec;
        }
        if ($DB->get_manager()->table_exists('local_bm_classsection')) {
            $all_db_sections = $DB->get_records('local_bm_classsection', null, 'id ASC');
            foreach ($all_db_sections as $adbs) {
                if (!isset($all_sections[(int)$adbs->id])) {
                    $all_sections[(int)$adbs->id] = $adbs;
                }
            }
        }

        // 2. Build a mapping of course ID -> module & class section metadata
        $bm_course_map = [];
        foreach ($all_sections as $s_item) {
            $clean_sec_name = trim(util::clean_section_name($s_item->name), "() \t\n\r\0\x0B");
            if (!empty($s_item->moduledata)) {
                $modules_list = util::decode_module_data($s_item->moduledata, true);
                foreach ($modules_list as $slot_idx => $m_slot) {
                    $cid = (int)($m_slot['moodlecourseid'] ?? $m_slot['courseid'] ?? 0);
                    if ($cid > 0 && !isset($bm_course_map[$cid])) {
                        $bm_course_map[$cid] = [
                            'section_id'   => (int)$s_item->id,
                            'section_name' => $clean_sec_name,
                            'module_name'  => $m_slot['name'] ?? '',
                            'slot_index'   => $slot_idx,
                            'courseid'     => $cid,
                        ];
                    }
                }
            }
        }

        // 3. Fetch all enrolled courses for this student (both active and suspended to catch batch shifts)
        $enrolled_courses = [];
        try {
            $enrolled_courses = enrol_get_users_courses($studentid, false, 'id, shortname, fullname, idnumber');
        } catch (\Throwable $e) {
            $enrolled_courses = [];
        }

        // 4. Filter: only consider courses mapped to modules in Batch Management (excluding test/exam courses)
        $mapped_modules = [];
        foreach ($enrolled_courses as $c) {
            $cid = (int)$c->id;
            if (isset($bm_course_map[$cid])) {
                $bm_info = $bm_course_map[$cid];
                $code = self::extract_module_code($c->shortname, $bm_info['module_name'], $c->fullname);

                $mapped_modules[] = [
                    'course'      => $c,
                    'courseid'    => $cid,
                    'code'        => $code,
                    'section_id'  => $bm_info['section_id'] ?? 0,
                    'section'     => $bm_info['section_name'],
                    'module_name' => $bm_info['module_name'],
                    'slot_index'  => $bm_info['slot_index'],
                ];
            }
        }

        // 4.1 Batch Shift Duplicate Course Resolution:
        // When a student has batch shifted (e.g. 23001 -> 23005) and has multiple course enrollments
        // for the same module (e.g. DS & DS), compare their Gradebook grades and pick ONLY the course
        // with the highest grade.
        $modules_by_code = [];
        foreach ($mapped_modules as $mod_item) {
            $code_key = $mod_item['code'];
            $modules_by_code[$code_key][] = $mod_item;
        }

        $deduped_modules = [];
        foreach ($modules_by_code as $code_key => $candidates) {
            if (count($candidates) === 1) {
                $deduped_modules[] = $candidates[0];
                continue;
            }

            // Multiple enrollments for the same module: pick the course with the highest Gradebook grade
            $best_candidate = null;
            $highest_grade = -2.0;
            $best_graded_count = -1;
            $best_time = -1;

            foreach ($candidates as $cand) {
                $cand_cid = (int)$cand['courseid'];
                $summary = self::get_student_course_gradebook_summary($cand_cid, $studentid);
                $cand['grade_summary'] = $summary;

                $grade_val = $summary['has_grade'] ? $summary['grade_pct'] : -1.0;
                $cand_count = $summary['graded_count'];
                $cand_time = $summary['timemodified'];

                $is_better = false;
                if ($best_candidate === null) {
                    $is_better = true;
                } else if ($grade_val > $highest_grade) {
                    // Pick course with highest grade
                    $is_better = true;
                } else if (abs($grade_val - $highest_grade) < 0.0001) {
                    // Tied grades:
                    // 1. Prefer candidate with more graded activities (re-submitted more work)
                    if ($cand_count > $best_graded_count) {
                        $is_better = true;
                    } else if ($cand_count === $best_graded_count) {
                        // 2. Prefer candidate with more recent grade update
                        if ($cand_time > $best_time) {
                            $is_better = true;
                        } else if ($cand_time === $best_time) {
                            // 3. Prefer candidate matching active section or newer course ID
                            if ($sec && !empty($sec->id) && !empty($cand['section_id']) && (int)$cand['section_id'] === (int)$sec->id) {
                                $is_better = true;
                            } else if ($cand_cid > (int)$best_candidate['courseid']) {
                                $is_better = true;
                            }
                        }
                    }
                }

                if ($is_better) {
                    $highest_grade = $grade_val;
                    $best_graded_count = $cand_count;
                    $best_time = $cand_time;
                    $best_candidate = $cand;
                }
            }

            if ($best_candidate !== null) {
                $deduped_modules[] = $best_candidate;
            }
        }

        $mapped_modules = $deduped_modules;

        // Sort mapped modules by slot_index so they appear in curriculum sequence
        usort($mapped_modules, static function($a, $b) {
            return ($a['slot_index'] ?? 0) <=> ($b['slot_index'] ?? 0);
        });

        // 5. Fallback if student has no mapped courses
        if (empty($mapped_modules)) {
            return [
                'overall' => [
                    'maac_rating'     => '-',
                    'maac_rating_val' => '-',
                    'attendance'      => '-',
                    'projects'        => '-',
                ],
                'modules' => [],
                'matrix'  => [],
            ];
        }

        // 6. Configured tracker categories from settings
        $act_service = new \local_batchanalytics\activity_tracker_service();
        $configured_categories = $act_service->get_configured_categories();
        $category_rows = [
            'Classwork'        => 'Classwork %',
            'Module Test'      => 'Module test %',
            'Objective Test'   => 'Objective test %',
            'Programming Test' => 'Programming test %',
            'Quiz'             => 'Quiz %',
            'Assignment'       => 'Assignments %',
        ];

        $mod_headers = [];
        $maac_ratings_out_of_9 = [];
        $maac_ratings_out_of_9_num = [];
        $attendance_vals = [];
        $attendance_pcts_numeric = [];
        $project_counts = [];
        $cat_grades = [];
        foreach ($category_rows as $ckey => $crow_label) {
            $cat_grades[$crow_label] = [];
        }

        $total_projects_count = 0;

        foreach ($mapped_modules as $mod_item) {
            $cid = (int)$mod_item['courseid'];
            $mod_headers[] = [
                'code'    => $mod_item['code'],
                'section' => $mod_item['section'],
            ];

            // 6.1 Course Total Grade & MAAC Rating (out of 9)
            $cand_summary = $mod_item['grade_summary'] ?? self::get_student_course_gradebook_summary($cid, $studentid);
            $course_total_pct = $cand_summary['has_grade'] ? $cand_summary['grade_pct'] : null;

            if ($course_total_pct !== null) {
                // MAAC Rating: out of 9
                $m_rating_9 = round(($course_total_pct / 100) * 9, 1);
                $maac_ratings_out_of_9[] = (string)$m_rating_9;
                $maac_ratings_out_of_9_num[] = $m_rating_9;
            } else {
                $maac_ratings_out_of_9[] = '-';
            }

            // 6.2 Attendance Percentage
            $att_sql = "
                SELECT gg.finalgrade, gg.rawgrade, gi.grademin, gi.grademax
                  FROM {grade_items} gi
                  JOIN {grade_grades} gg ON gg.itemid = gi.id
                 WHERE gi.courseid = :courseid
                   AND (gi.itemmodule = 'attendance' OR " . $DB->sql_like('gi.itemname', ':attlike', false) . ")
                   AND gg.userid = :userid
                   AND (gg.finalgrade IS NOT NULL OR gg.rawgrade IS NOT NULL)
            ";
            $att_recs = $DB->get_records_sql($att_sql, ['courseid' => $cid, 'attlike' => '%attend%', 'userid' => $studentid], 0, 1);
            $mod_att_pct = null;
            if (!empty($att_recs)) {
                $att_rec = reset($att_recs);
                $att_range = (float)$att_rec->grademax - (float)$att_rec->grademin;
                if ($att_range > 0) {
                    $att_val = ($att_rec->finalgrade !== null) ? (float)$att_rec->finalgrade : (float)$att_rec->rawgrade;
                    $mod_att_pct = round((($att_val - (float)$att_rec->grademin) / $att_range) * 100);
                }
            }
            if ($mod_att_pct !== null) {
                $attendance_vals[] = $mod_att_pct . '%';
                $attendance_pcts_numeric[] = $mod_att_pct;
            } else {
                $attendance_vals[] = '-';
            }

            // 6.3 Grade items by Category (Classwork, Tests, Quizzes, Assignments, Projects)
            $all_categories = $DB->get_records('grade_categories', ['courseid' => $cid]);
            $gi_sql = "
                SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.categoryid, gi.grademin, gi.grademax,
                       gg.finalgrade, gg.rawgrade
                  FROM {grade_items} gi
             LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                 WHERE gi.courseid = :courseid
                   AND gi.itemtype IN ('mod', 'manual')
            ";
            $grade_items = $DB->get_records_sql($gi_sql, ['courseid' => $cid, 'userid' => $studentid]);

            // Bucket items by resolved tracker category
            $cat_earned = [];
            $cat_maximum = [];
            $cat_graded_count = [];

            foreach ($grade_items as $gitem) {
                $track_cat = $act_service->resolve_grade_item_category($gitem, $all_categories);
                if (empty($track_cat)) {
                    continue;
                }

                $range = (float)$gitem->grademax - (float)$gitem->grademin;
                if ($range <= 0) {
                    continue;
                }

                if (!isset($cat_earned[$track_cat])) {
                    $cat_earned[$track_cat] = 0.0;
                    $cat_maximum[$track_cat] = 0.0;
                    $cat_graded_count[$track_cat] = 0;
                }

                if ($gitem->finalgrade !== null) {
                    $cat_earned[$track_cat] += ((float)$gitem->finalgrade - (float)$gitem->grademin);
                    $cat_maximum[$track_cat] += $range;
                    $cat_graded_count[$track_cat]++;
                } else if ($gitem->rawgrade !== null) {
                    $cat_earned[$track_cat] += ((float)$gitem->rawgrade - (float)$gitem->grademin);
                    $cat_maximum[$track_cat] += $range;
                    $cat_graded_count[$track_cat]++;
                }
            }

            // Project activities count that have been graded
            $mod_projects = (int)($cat_graded_count['Project'] ?? 0);
            $project_counts[] = (string)$mod_projects;
            $total_projects_count += $mod_projects;

            // Fill configured categories
            foreach ($category_rows as $ckey => $crow_label) {
                if (isset($cat_maximum[$ckey]) && $cat_maximum[$ckey] > 0) {
                    $pct = round(($cat_earned[$ckey] / $cat_maximum[$ckey]) * 100);
                    $cat_grades[$crow_label][] = $pct . '%';
                } else {
                    $cat_grades[$crow_label][] = '-';
                }
            }
        }

        // 7. Calculate Level 1 Overall Metrics (MAAC rating out of 9)
        $overall_maac_str = '-';
        $overall_maac_val = '-';
        if (!empty($maac_ratings_out_of_9_num)) {
            $avg_m9 = round(array_sum($maac_ratings_out_of_9_num) / count($maac_ratings_out_of_9_num), 1);
            $overall_maac_str = $avg_m9 . ' / 9';
            $overall_maac_val = (string)$avg_m9;
        }

        $overall_att_str = '-';
        if (!empty($attendance_pcts_numeric)) {
            $avg_att = round(array_sum($attendance_pcts_numeric) / count($attendance_pcts_numeric));
            $overall_att_str = $avg_att . '%';
        }

        $matrix = [
            'MAAC rating' => $maac_ratings_out_of_9,
            'Attendance %' => $attendance_vals,
        ];
        foreach ($cat_grades as $crow_label => $cvals) {
            $matrix[$crow_label] = $cvals;
        }
        $matrix['Projects'] = $project_counts;

        return [
            'overall' => [
                'maac_rating'     => $overall_maac_str,
                'maac_rating_val' => $overall_maac_val,
                'attendance'      => $overall_att_str,
                'projects'        => (string)$total_projects_count,
            ],
            'modules' => $mod_headers,
            'matrix'  => $matrix,
        ];
    }

    /**
    /**
     * Get private counseling notes for a student via Zoho CRM v8 Notes API.
     *
     * 1. Retrieve the Notes (GET Request)
     * Endpoint: GET https://{api-domain}/crm/v8/{module_api_name}/{record_id}/Notes
     * Headers: Authorization: Zoho-oauthtoken {your_access_token}
     *
     * @param int $studentid
     * @param string $crm_record_id Optional record id in Child_Admission module
     * @return array
     */
    public static function get_counseling_notes(int $studentid, string $crm_record_id = ''): array {
        global $CFG;

        if ($studentid <= 0) {
            return [];
        }

        if (empty($crm_record_id)) {
            $crm_record_id = self::get_student_crm_record_id($studentid);
        }

        $notes = [];
        $fetched_from_crm = false;

        // 1. Retrieve Notes from Zoho CRM if record_id is present
        if (!empty($crm_record_id)) {
            $token = self::get_zoho_access_token();
            if ($token) {
                try {
                    require_once($CFG->libdir . '/filelib.php');
                    $curl = new \curl();
                    $curl->setHeader([
                        "Authorization: Zoho-oauthtoken {$token}",
                    ]);
                    $api_base_url = self::get_zoho_api_base_url();
                    $module_name  = \local_batchanalytics\crm_fields_helper::get_student_module_api_name();
                    $notes_fields = 'id,Note_Title,Note_Content,Created_Time,Modified_Time,Created_By';
                    $notes_url_v8 = rtrim($api_base_url, '/') . '/crm/v8/' . rawurlencode($module_name) . '/' . rawurlencode($crm_record_id) . '/Notes?fields=' . $notes_fields . '&per_page=200';
                    $notes_url_v6 = rtrim($api_base_url, '/') . '/crm/v6/' . rawurlencode($module_name) . '/' . rawurlencode($crm_record_id) . '/Notes?fields=' . $notes_fields . '&per_page=200';

                    // Attempt v8 endpoint first
                    $resp = $curl->get($notes_url_v8);
                    $info = $curl->get_info();
                    $http_code = (int)($info['http_code'] ?? 0);

                    // 401 retry with forced token refresh
                    if ($http_code === 401) {
                        $token = self::get_zoho_access_token(true);
                        if ($token) {
                            $curl = new \curl();
                            $curl->setHeader(["Authorization: Zoho-oauthtoken {$token}"]);
                            $resp = $curl->get($notes_url_v8);
                            $info = $curl->get_info();
                            $http_code = (int)($info['http_code'] ?? 0);
                        }
                    }

                    // Fall back to v6 if v8 is not 2xx (e.g. 404 Not Found or version unsupported)
                    if ($http_code < 200 || $http_code >= 300) {
                        $resp_v6 = $curl->get($notes_url_v6);
                        $info_v6 = $curl->get_info();
                        $http_code_v6 = (int)($info_v6['http_code'] ?? 0);

                        if ($http_code_v6 === 401) {
                            $token = self::get_zoho_access_token(true);
                            if ($token) {
                                $curl = new \curl();
                                $curl->setHeader(["Authorization: Zoho-oauthtoken {$token}"]);
                                $resp_v6 = $curl->get($notes_url_v6);
                                $info_v6 = $curl->get_info();
                                $http_code_v6 = (int)($info_v6['http_code'] ?? 0);
                            }
                        }

                        if ($http_code_v6 >= 200 && $http_code_v6 < 300) {
                            $resp = $resp_v6;
                            $http_code = $http_code_v6;
                        } else {
                            debugging("Zoho CRM Notes fetch: v8 returned HTTP {$http_code} ({$resp}), v6 returned HTTP {$http_code_v6} ({$resp_v6})", DEBUG_DEVELOPER);
                        }
                    }

                    if ($http_code >= 200 && $http_code < 300) {
                        $fetched_from_crm = true;
                        if ($http_code !== 204 && !empty($resp)) {
                            $json = json_decode($resp, true);
                            if (!empty($json['data']) && is_array($json['data'])) {
                                foreach ($json['data'] as $crm_note) {
                                    $note_id = (string)($crm_note['id'] ?? '');
                                    if ($note_id === '') {
                                        continue;
                                    }
                                    $title = !empty($crm_note['Note_Title']) ? $crm_note['Note_Title'] : (!empty($crm_note['title']) ? $crm_note['title'] : (!empty($crm_note['Title']) ? $crm_note['Title'] : 'Counseling Notes - LMS'));
                                    $raw_content = (string)($crm_note['Note_Content'] ?? $crm_note['content'] ?? $crm_note['Content'] ?? '');
                                    $created_time_str = $crm_note['Created_Time'] ?? $crm_note['created_time'] ?? $crm_note['Modified_Time'] ?? $crm_note['modified_time'] ?? '';
                                    $timestamp = !empty($created_time_str) ? strtotime($created_time_str) : time();

                                    $author_name = 'Counselor';
                                    if (!empty($crm_note['Created_By'])) {
                                        if (is_array($crm_note['Created_By'])) {
                                            $author_name = $crm_note['Created_By']['name'] ?? 'Counselor';
                                        } else if (is_string($crm_note['Created_By'])) {
                                            $author_name = $crm_note['Created_By'];
                                        }
                                    } else if (!empty($crm_note['created_by'])) {
                                        if (is_array($crm_note['created_by'])) {
                                            $author_name = $crm_note['created_by']['name'] ?? 'Counselor';
                                        } else if (is_string($crm_note['created_by'])) {
                                            $author_name = $crm_note['created_by'];
                                        }
                                    } else if (!empty($crm_note['Owner'])) {
                                        if (is_array($crm_note['Owner'])) {
                                            $author_name = $crm_note['Owner']['name'] ?? 'Counselor';
                                        } else if (is_string($crm_note['Owner'])) {
                                            $author_name = $crm_note['Owner'];
                                        }
                                    }

                                    $clean_content = $raw_content;

                                    // Extract author if formatted as "\n By - <Author>"
                                    if (preg_match('/(?:^|\n)\s*By\s*-\s*(.+)$/is', $raw_content, $m)) {
                                        $parsed_author = trim($m[1]);
                                        if (!empty($parsed_author)) {
                                            $author_name = $parsed_author;
                                        }
                                        $clean_content = trim(preg_replace('/(?:^|\n)\s*By\s*-\s*.+$/is', '', $raw_content));
                                    }

                                    if ($clean_content === '') {
                                        $clean_content = $raw_content;
                                    }

                                    // 24-hour editability constraint
                                    $time_diff = time() - $timestamp;
                                    $is_editable = ($time_diff >= 0 && $time_diff <= 86400);
                                    $hours_left = max(1, (int)ceil((86400 - $time_diff) / 3600));

                                    $notes[] = [
                                        'id'           => $note_id,
                                        'title'        => $title,
                                        'author'       => $author_name,
                                        'role'         => 'Counselor',
                                        'timestamp'    => $timestamp,
                                        'date_str'     => userdate($timestamp, '%d %b %Y, %I:%M %p'),
                                        'raw_content'  => $raw_content,
                                        'body'         => $clean_content,
                                        'is_editable'  => $is_editable,
                                        'hours_left'   => $hours_left,
                                        'source'       => 'zoho',
                                    ];
                                }

                                // Sort notes so latest is at the top
                                usort($notes, static function($a, $b) {
                                    return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
                                });
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    debugging("Zoho CRM Notes fetch exception: " . $e->getMessage(), DEBUG_DEVELOPER);
                }
            } else {
                debugging("Zoho CRM Notes fetch: access token is unavailable", DEBUG_DEVELOPER);
            }
        } else {
            debugging("Zoho CRM Notes fetch: crm_record_id is empty for student ID {$studentid}", DEBUG_DEVELOPER);
        }

        // Cache live CRM notes to local dataroot
        if ($fetched_from_crm) {
            $dir = $CFG->dataroot . '/local_batchanalytics/counseling_notes';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $file = $dir . '/student_' . $studentid . '.json';
            file_put_contents($file, json_encode($notes, JSON_PRETTY_PRINT));
            return $notes;
        }

        // Fallback: Read from local dataroot storage if CRM was unreachable or offline
        $dir = $CFG->dataroot . '/local_batchanalytics/counseling_notes';
        $file = $dir . '/student_' . $studentid . '.json';
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                foreach ($decoded as &$note) {
                    $ts = !empty($note['timestamp']) ? (int)$note['timestamp'] : time();
                    $time_diff = time() - $ts;
                    $note['is_editable'] = ($time_diff >= 0 && $time_diff <= 86400);
                    $note['hours_left']  = max(1, (int)ceil((86400 - $time_diff) / 3600));
                }
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Add a private counseling note for a student and sync with Zoho CRM.
     *
     * 2. Create a New Note (POST Request)
     * Endpoint: POST https://{api-domain}/crm/v8/Notes
     * Headers: Authorization: Zoho-oauthtoken {token}, Content-Type: application/json
     * Payload:
     * {
     *   "data": [
     *     {
     *       "Note_Title": "Counseling Notes - LMS",
     *       "Note_Content": "<Note content>\n By - <LMS user full name>",
     *       "Parent_Id": "<record_id>",
     *       "se_module": "Child_Admission"
     *     }
     *   ]
     * }
     *
     * @param int $studentid
     * @param int $authorid
     * @param string $body
     * @return array Added note
     */
    public static function add_counseling_note(int $studentid, int $authorid, string $body): array {
        global $CFG, $DB, $USER;

        $body = trim($body);
        if ($studentid <= 0 || $body === '') {
            throw new \moodle_exception('invalidparams', 'local_batchanalytics', '', 'Invalid note content.');
        }

        if (!self::can_manage_counseling_notes($authorid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'Permission denied to add counseling note.');
        }

        $author_user = ($authorid === (int)$USER->id) ? $USER : $DB->get_record('user', ['id' => $authorid]);
        $author_name = $author_user ? fullname($author_user) : 'User';

        $personas = \local_batchanalytics\task_service::resolve_user_personas($authorid);
        $role_abbr = 'SSE';
        if (!empty($personas['is_pm'])) {
            $role_abbr = 'PM';
        } else if (!empty($personas['is_asst'])) {
            $role_abbr = 'AM';
        } else if (!empty($personas['is_manager'])) {
            $role_abbr = 'Manager';
        }

        $crm_record_id = self::get_student_crm_record_id($studentid);
        $token = self::get_zoho_access_token();
        $api_base_url = self::get_zoho_api_base_url();
        $module_name  = \local_batchanalytics\crm_fields_helper::get_student_module_api_name();

        $note_title = 'Counseling Notes - LMS';
        $full_note_content = $body . "\n By - " . $author_name;
        $note_id = '';
        $now = time();
        $posted_to_crm = false;

        if (!empty($crm_record_id) && $token) {
            try {
                require_once($CFG->libdir . '/filelib.php');
                $curl = new \curl();
                $curl->setHeader([
                    "Authorization: Zoho-oauthtoken {$token}",
                    'Content-Type: application/json',
                ]);

                // 1. Direct record route v8 (Mirrors the working GET /crm/v8/{module}/{record_id}/Notes endpoint)
                $nested_url_v8 = rtrim($api_base_url, '/') . '/crm/v8/' . rawurlencode($module_name) . '/' . rawurlencode($crm_record_id) . '/Notes';
                $nested_url_v6 = rtrim($api_base_url, '/') . '/crm/v6/' . rawurlencode($module_name) . '/' . rawurlencode($crm_record_id) . '/Notes';
                $record_payload = [
                    'data' => [
                        [
                            'Note_Title'   => $note_title,
                            'Note_Content' => $full_note_content,
                        ]
                    ]
                ];

                $resp = $curl->post($nested_url_v8, json_encode($record_payload));
                $info = $curl->get_info();
                $http_code = (int)($info['http_code'] ?? 0);

                if ($http_code === 401) {
                    $token = self::get_zoho_access_token(true);
                    if ($token) {
                        $curl = new \curl();
                        $curl->setHeader([
                            "Authorization: Zoho-oauthtoken {$token}",
                            'Content-Type: application/json',
                        ]);
                        $resp = $curl->post($nested_url_v8, json_encode($record_payload));
                        $info = $curl->get_info();
                        $http_code = (int)($info['http_code'] ?? 0);
                    }
                }

                $json = json_decode($resp, true);
                if ($http_code >= 200 && $http_code < 300 && !empty($json['data'][0]['status']) && strtolower((string)$json['data'][0]['status']) === 'success') {
                    $note_id = (string)($json['data'][0]['details']['id'] ?? $json['data'][0]['id'] ?? '');
                    if (!empty($note_id)) {
                        $posted_to_crm = true;
                        if (!empty($json['data'][0]['details']['Created_Time'])) {
                            $now = strtotime($json['data'][0]['details']['Created_Time']);
                        }
                    }
                }

                // 2. Direct record route v6
                if (!$posted_to_crm) {
                    $resp_v6 = $curl->post($nested_url_v6, json_encode($record_payload));
                    $info_v6 = $curl->get_info();
                    $http_code_v6 = (int)($info_v6['http_code'] ?? 0);
                    $json_v6 = json_decode($resp_v6, true);
                    if ($http_code_v6 >= 200 && $http_code_v6 < 300 && !empty($json_v6['data'][0]['status']) && strtolower((string)$json_v6['data'][0]['status']) === 'success') {
                        $note_id = (string)($json_v6['data'][0]['details']['id'] ?? $json_v6['data'][0]['id'] ?? '');
                        if (!empty($note_id)) {
                            $posted_to_crm = true;
                            if (!empty($json_v6['data'][0]['details']['Created_Time'])) {
                                $now = strtotime($json_v6['data'][0]['details']['Created_Time']);
                            }
                        }
                    }
                }

                // 3. Global /crm/v8/Notes with structured Parent_Id object
                if (!$posted_to_crm) {
                    $global_url_v8 = rtrim($api_base_url, '/') . '/crm/v8/Notes';
                    $global_payload_v8 = [
                        'data' => [
                            [
                                'Note_Title'   => $note_title,
                                'Note_Content' => $full_note_content,
                                'Parent_Id'    => [
                                    'id'     => $crm_record_id,
                                    'module' => [
                                        'api_name' => $module_name,
                                    ],
                                ],
                                'se_module'    => $module_name,
                            ]
                        ]
                    ];
                    $resp_g8 = $curl->post($global_url_v8, json_encode($global_payload_v8));
                    $info_g8 = $curl->get_info();
                    $http_code_g8 = (int)($info_g8['http_code'] ?? 0);
                    $json_g8 = json_decode($resp_g8, true);
                    if ($http_code_g8 >= 200 && $http_code_g8 < 300 && !empty($json_g8['data'][0]['status']) && strtolower((string)$json_g8['data'][0]['status']) === 'success') {
                        $note_id = (string)($json_g8['data'][0]['details']['id'] ?? $json_g8['data'][0]['id'] ?? '');
                        if (!empty($note_id)) {
                            $posted_to_crm = true;
                            if (!empty($json_g8['data'][0]['details']['Created_Time'])) {
                                $now = strtotime($json_g8['data'][0]['details']['Created_Time']);
                            }
                        }
                    }
                }

                // 4. Global /crm/v6/Notes with flat Parent_Id string
                if (!$posted_to_crm) {
                    $global_url_v6 = rtrim($api_base_url, '/') . '/crm/v6/Notes';
                    $global_payload_v6 = [
                        'data' => [
                            [
                                'Note_Title'   => $note_title,
                                'Note_Content' => $full_note_content,
                                'Parent_Id'    => $crm_record_id,
                                'se_module'    => $module_name,
                            ]
                        ]
                    ];
                    $resp_g6 = $curl->post($global_url_v6, json_encode($global_payload_v6));
                    $info_g6 = $curl->get_info();
                    $http_code_g6 = (int)($info_g6['http_code'] ?? 0);
                    $json_g6 = json_decode($resp_g6, true);
                    if ($http_code_g6 >= 200 && $http_code_g6 < 300 && !empty($json_g6['data'][0]['status']) && strtolower((string)$json_g6['data'][0]['status']) === 'success') {
                        $note_id = (string)($json_g6['data'][0]['details']['id'] ?? $json_g6['data'][0]['id'] ?? '');
                        if (!empty($note_id)) {
                            $posted_to_crm = true;
                            if (!empty($json_g6['data'][0]['details']['Created_Time'])) {
                                $now = strtotime($json_g6['data'][0]['details']['Created_Time']);
                            }
                        }
                    }
                }

                if (!$posted_to_crm) {
                    debugging("Zoho CRM Note POST failed across all attempts. Nested v8: {$resp}, Nested v6: " . ($resp_v6 ?? '') . ", Global v8: " . ($resp_g8 ?? '') . ", Global v6: " . ($resp_g6 ?? ''), DEBUG_DEVELOPER);
                }
            } catch (\Throwable $e) {
                debugging("Zoho CRM Note POST exception: " . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if (empty($note_id)) {
            $note_id = 'note_' . time() . '_' . mt_rand(100, 999);
        }

        $new_note = [
            'id'          => $note_id,
            'title'       => $note_title,
            'author'      => $author_name,
            'role'        => $role_abbr,
            'timestamp'   => $now,
            'date_str'    => userdate($now, '%d %b %Y, %I:%M %p'),
            'body'        => $body,
            'raw_content' => $full_note_content,
            'is_editable' => true,
            'hours_left'  => 24,
            'source'      => $posted_to_crm ? 'zoho' : 'local',
        ];

        // Prepend to local file storage cache
        $dir = $CFG->dataroot . '/local_batchanalytics/counseling_notes';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . '/student_' . $studentid . '.json';
        $existing = [];
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }
        array_unshift($existing, $new_note);
        file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT));

        return $new_note;
    }

    /**
     * Update an existing counseling note via Zoho CRM PUT request.
     * Enforces the 24-hour editability constraint from the time of note creation.
     *
     * 3. Update the Note (PUT Request)
     * Endpoint: PUT https://{api-domain}/crm/v8/Notes/{note_id}
     * Headers: Authorization: Zoho-oauthtoken {token}, Content-Type: application/json
     * Payload: { "data": [{ "Note_Title": "Counseling Notes - LMS", "Note_Content": "<Updated content>\n By - <LMS user full name>" }] }
     *
     * @param int $studentid
     * @param int $authorid
     * @param string $noteid
     * @param string $body
     * @return array Updated note
     */
    public static function update_counseling_note(int $studentid, int $authorid, string $noteid, string $body): array {
        global $CFG, $DB, $USER;

        $body = trim($body);
        $noteid = trim($noteid);
        if ($studentid <= 0 || empty($noteid) || $body === '') {
            throw new \moodle_exception('invalidparams', 'local_batchanalytics', '', 'Invalid note parameters.');
        }

        if (!self::can_manage_counseling_notes($authorid)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'Permission denied to update counseling note.');
        }

        $author_user = ($authorid === (int)$USER->id) ? $USER : $DB->get_record('user', ['id' => $authorid]);
        $author_name = $author_user ? fullname($author_user) : 'User';

        // Load existing notes from local cache/storage to verify 24h window
        $dir = $CFG->dataroot . '/local_batchanalytics/counseling_notes';
        $file = $dir . '/student_' . $studentid . '.json';
        $notes = [];
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $notes = $decoded;
            }
        }

        $target_note_index = -1;
        $created_timestamp = 0;
        foreach ($notes as $idx => $n) {
            if ((string)$n['id'] === $noteid) {
                $target_note_index = $idx;
                $created_timestamp = !empty($n['timestamp']) ? (int)$n['timestamp'] : 0;
                break;
            }
        }

        // Validate 24-hour editing window
        if ($created_timestamp > 0) {
            $diff = time() - $created_timestamp;
            if ($diff > 86400) {
                throw new \moodle_exception('error', 'local_batchanalytics', '', 'This note can no longer be edited as the 24-hour editing window has expired.');
            }
        }

        $note_title = 'Counseling Notes - LMS';
        $full_note_content = $body . "\n By - " . $author_name;

        // If it's a Zoho CRM Note ID (numeric), call Zoho CRM PUT API
        if (ctype_digit($noteid)) {
            $token = self::get_zoho_access_token();
            $api_base_url = self::get_zoho_api_base_url();
            if ($token) {
                require_once($CFG->libdir . '/filelib.php');
                $curl = new \curl();
                $curl->setHeader([
                    "Authorization: Zoho-oauthtoken {$token}",
                    'Content-Type: application/json',
                ]);
                $put_url_v8 = rtrim($api_base_url, '/') . '/crm/v8/Notes/' . rawurlencode($noteid);
                $put_url_v6 = rtrim($api_base_url, '/') . '/crm/v6/Notes/' . rawurlencode($noteid);
                $payload = [
                    'data' => [
                        [
                            'Note_Title'   => $note_title,
                            'Note_Content' => $full_note_content,
                        ]
                    ]
                ];

                $updated_on_crm = false;

                // 1. Try global PUT v8
                $resp = $curl->put($put_url_v8, json_encode($payload));
                $info = $curl->get_info();
                $http_code = (int)($info['http_code'] ?? 0);

                if ($http_code === 401) {
                    $token = self::get_zoho_access_token(true);
                    if ($token) {
                        $curl = new \curl();
                        $curl->setHeader([
                            "Authorization: Zoho-oauthtoken {$token}",
                            'Content-Type: application/json',
                        ]);
                        $resp = $curl->put($put_url_v8, json_encode($payload));
                        $info = $curl->get_info();
                        $http_code = (int)($info['http_code'] ?? 0);
                    }
                }

                $json = json_decode($resp, true);
                if ($http_code >= 200 && $http_code < 300 && !empty($json['data'][0]['status']) && strtolower((string)$json['data'][0]['status']) === 'success') {
                    $updated_on_crm = true;
                }

                // 2. Try global PUT v6
                if (!$updated_on_crm) {
                    $resp_v6 = $curl->put($put_url_v6, json_encode($payload));
                    $info_v6 = $curl->get_info();
                    $http_code_v6 = (int)($info_v6['http_code'] ?? 0);
                    $json_v6 = json_decode($resp_v6, true);
                    if ($http_code_v6 >= 200 && $http_code_v6 < 300 && !empty($json_v6['data'][0]['status']) && strtolower((string)$json_v6['data'][0]['status']) === 'success') {
                        $updated_on_crm = true;
                    }
                }

                // 3. Try record-nested PUT v8
                $crm_record_id = self::get_student_crm_record_id($studentid);
                $module_name   = \local_batchanalytics\crm_fields_helper::get_student_module_api_name();
                if (!$updated_on_crm && !empty($crm_record_id)) {
                    $nested_put_v8 = rtrim($api_base_url, '/') . '/crm/v8/' . rawurlencode($module_name) . '/' . rawurlencode($crm_record_id) . '/Notes/' . rawurlencode($noteid);
                    $resp_nested = $curl->put($nested_put_v8, json_encode($payload));
                    $info_nested = $curl->get_info();
                    $http_code_nested = (int)($info_nested['http_code'] ?? 0);
                    $json_nested = json_decode($resp_nested, true);
                    if ($http_code_nested >= 200 && $http_code_nested < 300 && !empty($json_nested['data'][0]['status']) && strtolower((string)$json_nested['data'][0]['status']) === 'success') {
                        $updated_on_crm = true;
                    }
                }

                if (!$updated_on_crm) {
                    debugging("Zoho CRM Note PUT warning: v8: {$resp}, v6: " . ($resp_v6 ?? '') . ", nested: " . ($resp_nested ?? ''), DEBUG_DEVELOPER);
                }
            }
        }

        // Update local note record
        $time_diff = time() - ($created_timestamp ?: time());
        $hours_left = max(1, (int)ceil((86400 - $time_diff) / 3600));

        if ($target_note_index >= 0) {
            $notes[$target_note_index]['body'] = $body;
            $notes[$target_note_index]['raw_content'] = $full_note_content;
            $notes[$target_note_index]['is_editable'] = ($time_diff <= 86400);
            $notes[$target_note_index]['hours_left'] = $hours_left;
            $updated_note = $notes[$target_note_index];
        } else {
            $updated_note = [
                'id'          => $noteid,
                'title'       => $note_title,
                'author'      => $author_name,
                'role'        => 'SSE',
                'timestamp'   => $created_timestamp ?: time(),
                'date_str'    => userdate($created_timestamp ?: time(), '%d %b %Y, %I:%M %p'),
                'body'        => $body,
                'raw_content' => $full_note_content,
                'is_editable' => ($time_diff <= 86400),
                'hours_left'  => $hours_left,
            ];
            $notes[] = $updated_note;
        }

        if (file_exists($file) || is_dir($dir)) {
            file_put_contents($file, json_encode($notes, JSON_PRETTY_PRINT));
        }

        return $updated_note;
    }

    /**
     * Generate and force-download a comprehensive PDF report for the student.
     *
     * @param int $studentid
     * @param int $batchid
     * @param int $viewerid
     */
    public static function generate_student_pdf(int $studentid, int $batchid = 0, int $viewerid = 0): void {
        student_pdf_service::generate_student_pdf($studentid, $batchid, $viewerid);
    }
}
