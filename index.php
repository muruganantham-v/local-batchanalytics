<?php
// This file is part of Moodle - http://moodle.org/
// ... (License Header) ...

/**
 * Main page for local_batchanalytics
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

// Autoloading handles classes in 'classes/' folder
require_login();

$context = context_system::instance();
$userid = $USER->id;

// Capability-based permission check. Assign 'local/batchanalytics:view' to roles in Site administration > Users > Permissions > Define roles.
require_capability('local/batchanalytics:view', $context);

$can_manage = is_siteadmin($userid) || has_capability('local/batchanalytics:manage', $context);
$can_view_all_courses = $can_manage || has_capability('local/batchanalytics:viewallcourses', $context);

$moodledata = new \local_batchanalytics\moodledata();
$can_view_tickets = $moodledata->can_access_ticket_dashboard($userid);

/**
 * Return a safe CRM error for the browser.
 *
 * @param \Throwable $exception
 * @return array{error:string,errorcode:string}
 */
$format_crm_error = static function(\Throwable $exception): array {
    $message = $exception->getMessage();
    $lowercase = strtolower($message);
    $errorcode = 'crm_unavailable';
    $safeerror = 'CRM data is unavailable. Please retry later.';

    if (str_contains($lowercase, 'access token')) {
        $errorcode = 'crm_authorization';
        $safeerror = 'CRM authorization is unavailable. Check the Zoho CRM settings and retry.';
    } else if (preg_match('/http status (\d+)/i', $message, $matches)) {
        $httpcode = (int)$matches[1];
        if ($httpcode === 401 || $httpcode === 403) {
            $errorcode = 'crm_authorization';
            $safeerror = 'CRM authorization was rejected. Check the Zoho CRM settings and retry.';
        } else if ($httpcode === 429) {
            $errorcode = 'crm_rate_limited';
            $safeerror = 'Zoho CRM rate limit was reached. Please retry later.';
        } else if ($httpcode >= 400 && $httpcode < 500) {
            $errorcode = 'crm_request_rejected';
            $safeerror = 'Zoho CRM rejected this request. Check the CRM module and field settings.';
        } else {
            $errorcode = 'crm_upstream_unavailable';
            $safeerror = 'Zoho CRM is temporarily unavailable. Please retry later.';
        }
    } else if (str_contains($lowercase, 'invalid response')) {
        $errorcode = 'crm_invalid_response';
        $safeerror = 'Zoho CRM returned an invalid response. Please retry later.';
    }

    return [
        'error' => $safeerror,
        'errorcode' => $errorcode,
    ];
};

// Load restricted CRM fields for view-only users.
$restricted_crm_fields = [];
if (!$can_manage) {
    $restricted_crm_fields = \local_batchanalytics\crm_fields_helper::get_restricted_keys();
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// ==================== API ENDPOINTS ====================

if ($action === 'getptfdata') {
    while (ob_get_level())
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        echo json_encode(['error' => 'Method not allowed. Please use POST.']);
        die();
    }
    require_sesskey();

    if (!\local_batchanalytics\util::check_crm_rate_limit($userid)) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded. Please try again later.']);
        die();
    }

    $username = optional_param('username', '', PARAM_TEXT);
    $payload = optional_param('payload', '', PARAM_RAW);
    $usernames = '';

    // Support JSON payload for usernames array
    if ($payload !== '') {
        $decoded = json_decode($payload, true);
        if (is_array($decoded) && !empty($decoded['usernames'])) {
            $usernames = implode(',', $decoded['usernames']);
        }
    } else {
        $usernames = optional_param('usernames', '', PARAM_TEXT);
    }

    try {
        $crm = new \local_batchanalytics\crmapi();

        if (!empty($usernames)) {
            $usernameList = array_slice(array_filter(array_map('trim', explode(',', $usernames))), 0, 500);
            $mdata = new \local_batchanalytics\moodledata();
            $usernameList = $mdata->filter_accessible_student_usernames($usernameList, $userid);
            if (empty($usernameList)) {
                echo json_encode(['students' => []]);
                die();
            }
            $records = $crm->get_students_details($usernameList);

            $output = [];
            foreach ($usernameList as $u) {
                $lookup = strtolower($u);
                $record = $records[$lookup] ?? null;
                $company = 'Not Placed';
                if (!empty($record['Placement_Company'])) {
                    if (is_array($record['Placement_Company']) && isset($record['Placement_Company']['name'])) {
                        $company = $record['Placement_Company']['name'];
                    } elseif (is_string($record['Placement_Company'])) {
                        $company = $record['Placement_Company'];
                    }
                }
                $rec_data = $record;
                $rec_ctc = $rec_data['CTC'] ?? '-';
                if (!$can_manage && !empty($restricted_crm_fields)) {
                    $rec_data = \local_batchanalytics\crm_fields_helper::filter_crm_fields($rec_data, $restricted_crm_fields);
                    if (in_array('CTC', $restricted_crm_fields)) {
                        $rec_ctc = null;
                    }
                }
                $output[] = [
                    'username' => $u,
                    'placed_company' => $company,
                    'CTC' => $rec_ctc,
                    'data' => $rec_data
                ];
            }

            echo json_encode(['students' => $output]);
            die();
        }

        $mdata = new \local_batchanalytics\moodledata();
        $allowedusernames = $mdata->filter_accessible_student_usernames([$username], $userid);
        if (empty($allowedusernames)) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to access this student.']);
            die();
        }
        $username = reset($allowedusernames);

        $record = $crm->get_student_details($username);

        if (empty($record) || !is_array($record)) {
            echo json_encode([
                'username' => $username,
                'placed_company' => 'Not Placed',
                'CTC' => '-',
                'error' => get_string('crmconfigmissing', 'local_batchanalytics')
            ]);
            die();
        }

        // Handle Placement Company (Extract name if it's an object)
        $company = 'Not Placed';
        if (!empty($record['Placement_Company'])) {
            if (is_array($record['Placement_Company']) && isset($record['Placement_Company']['name'])) {
                $company = $record['Placement_Company']['name'];
            } elseif (is_string($record['Placement_Company'])) {
                $company = $record['Placement_Company'];
            }
        }

        // Build response dynamically from configured API fields.
        $data = ['username' => $username, 'placed_company' => $company];
        foreach (\local_batchanalytics\crm_fields_helper::get_api_field_keys() as $field_key) {
            if ($field_key === 'College_Name') {
                $data[$field_key] = $record['College_Name'] ?? ($record['Other_College_Name'] ?? '-');
            } else {
                $data[$field_key] = $record[$field_key] ?? '-';
            }
        }

        if (!$can_manage && !empty($restricted_crm_fields)) {
            $data = \local_batchanalytics\crm_fields_helper::filter_crm_fields($data, $restricted_crm_fields);
        }

        echo json_encode($data);
    } catch (\Throwable $e) {
        debugging('CRM API Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        http_response_code(502);
        echo json_encode($format_crm_error($e));
    }
    die();
}


if ($action === 'getnewbatchdata') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();

        $dbman = $DB->get_manager();
        $has_bm_batch = $dbman->table_exists('local_bm_batch');
        $has_bm_section = $dbman->table_exists('local_bm_classsection');
        $has_bm_student = $dbman->table_exists('local_bm_student');

        if (!$has_bm_batch) {
            echo json_encode([
                'stats' => [
                    'runningBatches' => 0,
                    'totalBatches' => 0,
                    'completedBatches' => 0,
                    'onlineBatches' => 0,
                    'offlineBatches' => 0,
                    'classMentors' => 0,
                    'labMentors' => 0,
                    'onSchedule' => 0,
                    'minorSlip' => 0,
                    'delayed' => 0,
                    'early' => 0,
                    'totalStudents' => 0
                ],
                'batches' => [],
                'filters' => [
                    'years' => [],
                    'courses' => [],
                    'modes' => [],
                    'batchNames' => []
                ]
            ]);
            die();
        }

        // Fetch all class sections as primary records
        $sections = $has_bm_section ? $DB->get_records('local_bm_classsection', null, 'id ASC') : [];

        // Fetch parent batches for metadata (coursename, deliverymode, startdate)
        $batches = $has_bm_batch ? $DB->get_records('local_bm_batch', null, 'startdate ASC, id ASC') : [];

        if (empty($sections) && empty($batches)) {
            echo json_encode([
                'stats' => [
                    'runningBatches' => 0,
                    'totalBatches' => 0,
                    'completedBatches' => 0,
                    'onlineBatches' => 0,
                    'offlineBatches' => 0,
                    'classMentors' => 0,
                    'labMentors' => 0,
                    'onSchedule' => 0,
                    'minorSlip' => 0,
                    'delayed' => 0,
                    'early' => 0,
                    'totalStudents' => 0
                ],
                'batches' => [],
                'filters' => [
                    'years' => [],
                    'courses' => [],
                    'modes' => [],
                    'batchNames' => []
                ]
            ]);
            die();
        }

        // Check if user is a Manager (site admin, manage capability, or viewallcourses capability)
        $currentuserid = (int)$USER->id;
        $is_manager = is_siteadmin($currentuserid)
            || has_capability('local/batchanalytics:manage', $context, $currentuserid)
            || has_capability('local/batchanalytics:viewallcourses', $context, $currentuserid);

        // Non-managers should only see class sections corresponding to courses/sections they are enrolled in or assigned to
        if (!$is_manager) {
            $user_courses = enrol_get_users_courses($currentuserid, true, ['id', 'fullname', 'shortname']);
            $user_course_ids = array_map('intval', array_keys($user_courses));
            $user_course_names = array_map(function($c) { return strtolower(trim($c->fullname)); }, $user_courses);
            $user_course_shortnames = array_map(function($c) { return strtolower(trim($c->shortname)); }, $user_courses);
            $user_fullname = trim(fullname($USER));

            $allowed_section_ids = [];

            if (!empty($sections)) {
                foreach ($sections as $s_item) {
                    $parent = !empty($s_item->batchid) && isset($batches[$s_item->batchid]) ? $batches[$s_item->batchid] : null;
                    if ((string)$s_item->maacexecutive === (string)$currentuserid
                        || (string)$s_item->pmmanager === (string)$currentuserid
                        || $s_item->maacexecutivename === $user_fullname
                        || $s_item->pmmanagername === $user_fullname) {
                        $allowed_section_ids[(int)$s_item->id] = true;
                        continue;
                    }
                    $s_modules = \local_batchanalytics\util::decode_module_data($s_item->moduledata ?? '', true);
                    if (!empty($s_modules)) {
                        foreach ($s_modules as $sm) {
                            $mcid = (int)($sm['moodlecourseid'] ?? 0);
                            if ($mcid > 0 && in_array($mcid, $user_course_ids, true)) {
                                $allowed_section_ids[(int)$s_item->id] = true;
                                break;
                            }
                            $mentors = [
                                (string)($sm['primarymentor'] ?? ''),
                                (string)($sm['secondarymentor'] ?? ''),
                                (string)($sm['labmentor1'] ?? ''),
                                (string)($sm['labmentor2'] ?? ''),
                                (string)($sm['labmentor3'] ?? '')
                            ];
                            if (in_array((string)$currentuserid, $mentors, true) || in_array($user_fullname, $mentors, true)) {
                                $allowed_section_ids[(int)$s_item->id] = true;
                                break;
                            }
                        }
                    }

                    if (empty($allowed_section_ids[(int)$s_item->id]) && $parent) {
                        $bcoursename = strtolower(trim((string)($parent->coursename ?? '')));
                        if ($bcoursename !== '' && (in_array($bcoursename, $user_course_names, true) || in_array($bcoursename, $user_course_shortnames, true))) {
                            $allowed_section_ids[(int)$s_item->id] = true;
                        }
                    }
                }

                $sections = array_filter($sections, function($sec) use ($allowed_section_ids) {
                    return !empty($allowed_section_ids[(int)$sec->id]);
                });
            }
        }

        // Fetch students per class section
        $studentcounts_by_section = [];
        if ($has_bm_student) {
            $sql = "SELECT classsectionid, COUNT(DISTINCT userid) AS cnt FROM {local_bm_student} GROUP BY classsectionid";
            foreach ($DB->get_records_sql($sql) as $row) {
                $studentcounts_by_section[(int)$row->classsectionid] = (int)$row->cnt;
            }

            if ($is_manager) {
                $studentcount = (int)$DB->count_records_sql("SELECT COUNT(DISTINCT userid) FROM {local_bm_student}");
            } else {
                $sec_ids_list = array_map('intval', array_keys($sections));
                if (!empty($sec_ids_list)) {
                    list($sin_sql, $sparams) = $DB->get_in_or_equal($sec_ids_list, SQL_PARAMS_NAMED, 'bm_cs');
                    $studentcount = (int)$DB->count_records_sql("SELECT COUNT(DISTINCT userid) FROM {local_bm_student} WHERE classsectionid $sin_sql", $sparams);
                } else {
                    $studentcount = 0;
                }
            }
        } else {
            $studentcount = 0;
        }

        $batchlist = [];
        $onlinecount = 0;
        $offlinecount = 0;
        $hybridcount = 0;
        $onschedulecount = 0;
        $minorslipcount = 0;
        $delayedcount = 0;
        $earlycount = 0;
        $classmentors = [];
        $labmentors = [];

        // Helper to resolve mentor IDs to user names and filter invalid placeholders
        $resolve_mentor = static function($val) {
            $val = trim((string)$val);
            if ($val === '' || $val === '0' || $val === '—' || strcasecmp($val, 'none') === 0 || strcasecmp($val, 'null') === 0) {
                return null;
            }
            if (is_numeric($val)) {
                $profile = \local_batchanalytics\util::get_user_profile_data((int)$val);
                if (!empty($profile['name'])) {
                    return $profile['name'];
                }
            }
            return $val;
        };

        // Primary loop: process each Class Section
        if (!empty($sections)) {
            foreach ($sections as $sec) {
                $parent = !empty($sec->batchid) && isset($batches[$sec->batchid]) ? $batches[$sec->batchid] : null;
                $sec_name = $sec->name ?: ($parent ? $parent->name : 'Section ' . $sec->id);
                $coursename = ($parent && !empty($parent->coursename)) ? $parent->coursename : 'Embedded Systems & IoT';
                $raw_mode = ($parent && !empty($parent->deliverymode)) ? $parent->deliverymode : 'Offline';
                $normalizedmode = $raw_mode !== '' ? ucfirst(strtolower(trim($raw_mode))) : 'Offline';
                $is_online = \local_batchanalytics\util::is_online_mode($normalizedmode);
                $type = ($parent && !empty($parent->submode)) ? ucfirst(strtolower($parent->submode)) : 'Regular';
                $startdate_ts = ($parent && !empty($parent->startdate)) ? (int)$parent->startdate : ((int)$sec->timecreated ?: time());
                $startformatted = userdate($startdate_ts, '%d %b %Y');
                $year = userdate($startdate_ts, '%Y');

                $sec_students = $studentcounts_by_section[(int)$sec->id] ?? 0;

                $modules = \local_batchanalytics\util::decode_module_data($sec->moduledata ?? '', true);
                if ($is_online) {
                    $modules = \local_batchanalytics\util::filter_modules_for_mode($modules, $normalizedmode);
                }

                $currentmodule = 'N/A';
                $currentmoduleidx = 1;
                $currentcourseid = 0;
                $totaldelta = 0;
                $has_modules = !empty($modules);
                $all_completed = $has_modules;
                $last_valid_module = null;
                $sec_classmentors = [];
                $sec_labmentors = [];

                foreach ($modules as $m) {
                    $last_valid_module = $m;

                    // Class Mentors
                    foreach (['primarymentor', 'secondarymentor'] as $cmk) {
                        if (!empty($m[$cmk])) {
                            $resolved = $resolve_mentor($m[$cmk]);
                            if ($resolved !== null) {
                                $classmentors[$resolved] = true;
                                $sec_classmentors[$resolved] = true;
                            }
                        }
                    }

                    // Lab Mentors
                    foreach (['labmentor1', 'labmentor2', 'labmentor3'] as $lmk) {
                        if (!empty($m[$lmk])) {
                            $resolved = $resolve_mentor($m[$lmk]);
                            if ($resolved !== null) {
                                $labmentors[$resolved] = true;
                                $sec_labmentors[$resolved] = true;
                            }
                        }
                    }

                    // Accumulate schedule delta from all modules in the section
                    if (isset($m['scheduledelta']) && is_numeric($m['scheduledelta'])) {
                        $totaldelta += (int)$m['scheduledelta'];
                    } else if (!empty($m['actualend']) && !empty($m['plannedend']) && is_numeric($m['actualend']) && is_numeric($m['plannedend']) && (int)$m['actualend'] > 100000 && (int)$m['plannedend'] > 100000) {
                        $diffdays = (int)round(((int)$m['actualend'] - (int)$m['plannedend']) / 86400);
                        $totaldelta += $diffdays;
                    }

                    $isdone = (!empty($m['actualend']) && (int)$m['actualend'] > 0);
                    if (!$isdone) {
                        $all_completed = false;
                    }

                    if ($currentmodule === 'N/A' && (!$isdone || count($modules) === 1)) {
                        $mod_name = !empty($m['name']) ? $m['name'] : (!empty($m['courseshortname']) ? $m['courseshortname'] : ('Module ' . $m['module']));
                        $currentmodule = $mod_name;
                        $currentmoduleidx = (int)$m['module'];
                        $currentcourseid = (int)($m['moodlecourseid'] ?? 0);
                    }
                }

                if ($has_modules && $currentmodule === 'N/A' && $last_valid_module !== null) {
                    $currentmodule = !empty($last_valid_module['name']) ? $last_valid_module['name'] : 'Completed';
                    $currentmoduleidx = (int)$last_valid_module['module'];
                    $currentcourseid = (int)($last_valid_module['moodlecourseid'] ?? 0);
                }

                $is_completed = ($has_modules && $all_completed);

                // Determine status based on total accumulated schedule delta
                $status_info = \local_batchanalytics\util::get_batch_status($totaldelta);
                $status = $status_info['status'];
                $statuslabel = $status_info['label'];
                $delaydays = $totaldelta;

                if ($status === 'delayed') {
                    $delayedcount++;
                } else if ($status === 'minor_slip') {
                    $minorslipcount++;
                } else if ($status === 'early') {
                    $earlycount++;
                } else {
                    $onschedulecount++;
                }

                $batchlist[] = [
                    'id' => (int)$sec->id,
                    'batchId' => (string)$sec_name,
                    'courseName' => (string)$coursename,
                    'mode' => $normalizedmode,
                    'type' => $type,
                    'startDate' => $startformatted,
                    'year' => $year,
                    'startdateTimestamp' => $startdate_ts,
                    'currentModule' => $currentmodule,
                    'moduleIdx' => $currentmoduleidx,
                    'courseId' => $currentcourseid,
                    'status' => $status,
                    'statusLabel' => $statuslabel,
                    'delayDays' => $delaydays,
                    'studentCount' => $sec_students,
                    'classMentors' => array_values(array_keys($sec_classmentors)),
                    'labMentors' => array_values(array_keys($sec_labmentors)),
                    'isCompleted' => $is_completed,
                    'sectionId' => (int)$sec->id,
                    'parentBatchId' => $parent ? (int)$parent->id : 0,
                    'parentBatchName' => $parent ? (string)$parent->name : ''
                ];
            }
        } else if (!empty($batches)) {
            // Fallback for batches if no class sections exist in table
            foreach ($batches as $b) {
                $mode = trim((string)($b->deliverymode ?? ''));
                $normalizedmode = $mode !== '' ? ucfirst(strtolower($mode)) : 'Offline';
                $is_online = \local_batchanalytics\util::is_online_mode($normalizedmode);
                $type = !empty($b->submode) ? ucfirst(strtolower($b->submode)) : 'Regular';
                $startdate_ts = !empty($b->startdate) ? (int)$b->startdate : 0;
                $startformatted = $startdate_ts > 0 ? userdate($startdate_ts, '%d %b %Y') : 'N/A';
                $year = $startdate_ts > 0 ? userdate($startdate_ts, '%Y') : date('Y');

                $status_info = \local_batchanalytics\util::get_batch_status(0);
                $onschedulecount++;

                $batchlist[] = [
                    'id' => (int)$b->id,
                    'batchId' => (string)($b->name ?? ''),
                    'courseName' => (string)($b->coursename ?? ''),
                    'mode' => $normalizedmode,
                    'type' => $type,
                    'startDate' => $startformatted,
                    'year' => $year,
                    'startdateTimestamp' => $startdate_ts,
                    'currentModule' => 'N/A',
                    'moduleIdx' => 1,
                    'courseId' => 0,
                    'status' => $status_info['status'],
                    'statusLabel' => $status_info['label'],
                    'delayDays' => 0,
                    'studentCount' => 0,
                    'classMentors' => [],
                    'labMentors' => [],
                    'isCompleted' => false,
                    'sectionId' => (int)$b->id
                ];
            }
        }

        // Sort batchlist with oldest date first (ascending order)
        usort($batchlist, static function($a, $b) {
            $tsA = (int)($a['startdateTimestamp'] ?? 0);
            $tsB = (int)($b['startdateTimestamp'] ?? 0);
            if ($tsA === $tsB) {
                return $a['id'] <=> $b['id'];
            }
            if ($tsA === 0) {
                return 1;
            }
            if ($tsB === 0) {
                return -1;
            }
            return $tsA <=> $tsB; // Oldest date first
        });

        $activebatches = 0;
        $completedbatches = 0;
        $onlinecount = 0;
        $offlinecount = 0;
        $hybridcount = 0;
        foreach ($batchlist as $bl) {
            if ($bl['isCompleted']) {
                $completedbatches++;
            } else {
                $activebatches++;
                if (strcasecmp((string)($bl['mode'] ?? ''), 'Online') === 0) {
                    $onlinecount++;
                } else if (strcasecmp((string)($bl['mode'] ?? ''), 'Offline') === 0) {
                    $offlinecount++;
                } else {
                    $hybridcount++;
                }
            }
        }

        $years = array_values(array_unique(array_filter(array_column($batchlist, 'year'))));
        sort($years);
        $courses = array_values(array_unique(array_filter(array_column($batchlist, 'courseName'))));
        sort($courses);
        $modes = array_values(array_unique(array_filter(array_column($batchlist, 'mode'))));
        sort($modes);
        $batchnames = array_values(array_unique(array_filter(array_column($batchlist, 'batchId'))));
        sort($batchnames);

        echo json_encode([
            'stats' => [
                'runningBatches' => $activebatches,
                'totalBatches' => count($batchlist),
                'completedBatches' => $completedbatches,
                'onlineBatches' => $onlinecount,
                'offlineBatches' => $offlinecount,
                'classMentors' => count($classmentors),
                'labMentors' => count($labmentors),
                'onSchedule' => $onschedulecount,
                'minorSlip' => $minorslipcount,
                'delayed' => $delayedcount,
                'early' => $earlycount,
                'totalStudents' => $studentcount
            ],
            'batches' => $batchlist,
            'filters' => [
                'years' => $years,
                'courses' => $courses,
                'modes' => $modes,
                'batchNames' => $batchnames
            ]
        ]);
        die();
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
        die();
    }
}

// Note: Task Dashboard API endpoints (action=get_task_data, action=complete_task)
// have been extracted and archived to local/task_tab_backup/ for Phase 2 implementation.

// ... (HTML Page rendering) ...

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/index.php'));
$PAGE->set_title(get_string('pluginname', 'local_batchanalytics'));
$PAGE->set_heading('');

$styleurl = new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime(__DIR__ . '/styles.css')]);
$newstyleurl = new moodle_url('/local/batchanalytics/new_analytics.css', ['v' => filemtime(__DIR__ . '/new_analytics.css')]);
$newscripturl = new moodle_url('/local/batchanalytics/new_analytics.js', ['v' => filemtime(__DIR__ . '/new_analytics.js')]);

$PAGE->requires->css($styleurl);
$PAGE->requires->css($newstyleurl);
$PAGE->requires->js($newscripturl);

echo $OUTPUT->header();

echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
echo '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">';


$crm_fields_config = \local_batchanalytics\crm_fields_helper::get_fields();
$mentor_crm_fields_config = \local_batchanalytics\crm_fields_helper::get_mentor_fields();
$mentor_crm_groups_config = \local_batchanalytics\crm_fields_helper::get_mentor_field_groups();
$maac_sync_columns = \local_batchanalytics\maac_columns_helper::get_sync_columns();
echo '<div class="local-batchanalytics-wrap" data-can-manage="' . ($can_manage ? '1' : '0') . '" data-import-maac-enabled="' . ((int)get_config('local_batchanalytics', 'import_maac_sheet') ? '1' : '0') . '" data-can-view-all-courses="' . ($can_view_all_courses ? '1' : '0') . '" data-can-view-tickets="' . ($can_view_tickets ? '1' : '0') . '" data-crm-fields="' . htmlspecialchars(json_encode($crm_fields_config), ENT_QUOTES) . '" data-mentor-crm-fields="' . htmlspecialchars(json_encode($mentor_crm_fields_config), ENT_QUOTES) . '" data-mentor-crm-groups="' . htmlspecialchars(json_encode($mentor_crm_groups_config), ENT_QUOTES) . '" data-maac-sync-columns="' . htmlspecialchars(json_encode($maac_sync_columns), ENT_QUOTES) . '" data-sesskey="' . sesskey() . '">';
echo '<div id="ba-toast-container" class="ba-toast-container"></div>';

// Batch Analytics Dashboard
echo '<div id="ba-top-tab-new" class="ba-top-tab-pane active">';
echo '  <div class="ba-new-dashboard">';

echo '    <!-- Header & Portfolio Stat Cards Container -->';
echo '    <div class="ba-portfolio-card">';
echo '      <div class="ba-portfolio-header">';
echo '        <h1 class="ba-portfolio-title">Emertxe <span class="ba-title-dash">–</span> Batch Analytics</h1>';
echo '        <div class="ba-portfolio-subtitle">Overview of all running batches</div>';
echo '        <div class="ba-portfolio-glance-bar">';
echo '          <span class="ba-glance-indicator"></span>';
echo '          <span class="ba-glance-label">PORTFOLIO AT A GLANCE</span>';
echo '        </div>';
echo '      </div>';

echo '      <!-- 10 Stat Cards in 5x2 Grid: Completed, Running, Online, Offline, Class Mentors | Lab Mentors, Early, On Schedule, Delayed, Students -->';
echo '      <div class="ba-portfolio-grid" id="ba-new-stats-grid">';
echo '        <!-- Card 1: Completed Batches -->';
echo '        <div class="ba-stat-box ba-new-stat-filter card-completed" data-batch-filter="completed" role="button" tabindex="0" aria-pressed="false" title="Filter completed cohorts">';
echo '          <div class="ba-stat-top">';
echo '            <div class="ba-stat-icon-wrap icon-bg-teal">';
echo '              <svg class="ba-stat-svg svg-teal" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
echo '            </div>';
echo '            <div class="ba-stat-sort-hint">&#x21C5;</div>';
echo '          </div>';
echo '          <div class="ba-stat-num" id="stat-completed-batches">--</div>';
echo '          <div class="ba-stat-lbl">Completed batches</div>';
echo '        </div>';

echo '        <!-- Card 2: Running Batches -->';
echo '        <div class="ba-stat-box ba-new-stat-filter card-running is-active" data-batch-filter="running" role="button" tabindex="0" aria-pressed="true" title="Filter active running batches">';
echo '          <div class="ba-stat-top">';
echo '            <div class="ba-stat-icon-wrap icon-bg-pink">';
echo '              <svg class="ba-stat-svg svg-pink" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"></line><line x1="4" y1="12" x2="20" y2="12"></line><line x1="4" y1="17" x2="20" y2="17"></line></svg>';
echo '            </div>';
echo '            <div class="ba-stat-sort-hint">&#x21C5;</div>';
echo '          </div>';
echo '          <div class="ba-stat-num" id="stat-running-batches">--</div>';
echo '          <div class="ba-stat-lbl">Running batches</div>';
echo '        </div>';

echo '        <!-- Card 3: Online Batches -->';
echo '        <div class="ba-stat-box ba-new-stat-filter card-online" data-batch-filter="online" role="button" tabindex="0" aria-pressed="false" title="Filter online batches">';
echo '          <div class="ba-stat-top">';
echo '            <div class="ba-stat-icon-wrap icon-bg-blue">';
echo '              <svg class="ba-stat-svg svg-blue" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"></circle><circle cx="12" cy="12" r="3" fill="currentColor"></circle></svg>';
echo '            </div>';
echo '            <div class="ba-stat-sort-hint">&#x21C5;</div>';
echo '          </div>';
echo '          <div class="ba-stat-num" id="stat-online-batches">--</div>';
echo '          <div class="ba-stat-lbl">Online batches</div>';
echo '        </div>';

echo '        <!-- Card 4: Offline Batches -->';
echo '        <div class="ba-stat-box ba-new-stat-filter card-offline" data-batch-filter="offline" role="button" tabindex="0" aria-pressed="false" title="Filter offline in-person batches">';
echo '          <div class="ba-stat-top">';
echo '            <div class="ba-stat-icon-wrap icon-bg-green">';
echo '              <svg class="ba-stat-svg svg-green" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="3.5" y="3.5" width="17" height="17" rx="3"></rect><circle cx="12" cy="12" r="2.5" fill="currentColor"></circle></svg>';
echo '            </div>';
echo '            <div class="ba-stat-sort-hint">&#x21C5;</div>';
echo '          </div>';
echo '          <div class="ba-stat-num" id="stat-offline-batches">--</div>';
echo '          <div class="ba-stat-lbl">Offline batches</div>';
echo '        </div>';

echo '        <!-- Card 5: Class Mentors -->';
echo '        <div class="ba-stat-box card-class-mentors" title="Assigned classroom instructors">';
echo '          <div class="ba-stat-top">';
echo '            <div class="ba-stat-icon-wrap icon-bg-purple">';
echo '              <svg class="ba-stat-svg svg-purple" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>';
echo '            </div>';
echo '            <div class="ba-stat-sort-hint">&#x21C5;</div>';
echo '          </div>';
echo '          <div class="ba-stat-num" id="stat-class-mentors">--</div>';
echo '          <div class="ba-stat-lbl">Class mentors</div>';
echo '        </div>';

echo '        <!-- Card 6: Lab Mentors -->';
echo '        <div class="ba-stat-box card-lab-mentors" title="Assigned lab and technical assistants">';
echo '          <div class="ba-stat-top">';
echo '            <div class="ba-stat-icon-wrap icon-bg-purple">';
echo '              <svg class="ba-stat-svg svg-purple" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>';
echo '            </div>';
echo '            <div class="ba-stat-sort-hint">&#x21C5;</div>';
echo '          </div>';
echo '          <div class="ba-stat-num" id="stat-lab-mentors">--</div>';
echo '          <div class="ba-stat-lbl">Lab mentors</div>';
echo '        </div>';

echo '        <!-- Card 7: Early (Tinted background & blue text) -->';
echo '        <button type="button" class="ba-stat-box ba-schedule-status-filter card-early" data-schedule-filter="early" aria-pressed="false" title="Filter batches running ahead of schedule">';
echo '          <div class="ba-stat-top">';
echo '            <div class="ba-stat-icon-wrap icon-bg-cyan">';
echo '              <svg class="ba-stat-svg svg-cyan" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>';
echo '            </div>';
echo '            <div class="ba-stat-sort-hint">&#x21C5;</div>';
echo '          </div>';
echo '          <div class="ba-stat-num text-early-blue" id="stat-early">--</div>';
echo '          <div class="ba-stat-lbl">Early</div>';
echo '        </button>';

echo '        <!-- Card 8: On Schedule -->';
echo '        <button type="button" class="ba-stat-box ba-schedule-status-filter card-on-schedule" data-schedule-filter="on_schedule" aria-pressed="false" title="Filter batches on track">';
echo '          <div class="ba-stat-top">';
echo '            <div class="ba-stat-icon-wrap icon-bg-emerald">';
echo '              <svg class="ba-stat-svg svg-emerald" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
echo '            </div>';
echo '            <div class="ba-stat-sort-hint">&#x21C5;</div>';
echo '          </div>';
echo '          <div class="ba-stat-num" id="stat-on-schedule">--</div>';
echo '          <div class="ba-stat-lbl">On schedule</div>';
echo '        </button>';

echo '        <!-- Card: Minor Slip (Tinted background & amber text) -->';
echo '        <button type="button" class="ba-stat-box ba-schedule-status-filter card-minor-slip" data-schedule-filter="minor_slip" aria-pressed="false" title="Filter batches with minor slips (12-23 days)">';
echo '          <div class="ba-stat-top">';
echo '            <div class="ba-stat-icon-wrap icon-bg-amber">';
echo '              <svg class="ba-stat-svg svg-amber" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
echo '            </div>';
echo '            <div class="ba-stat-sort-hint">&#x21C5;</div>';
echo '          </div>';
echo '          <div class="ba-stat-num text-amber-orange" id="stat-minor-slip">--</div>';
echo '          <div class="ba-stat-lbl">Minor slip</div>';
echo '        </button>';

echo '        <!-- Card 9: Delayed (Tinted background & red text) -->';
echo '        <button type="button" class="ba-stat-box ba-schedule-status-filter card-delayed" data-schedule-filter="delayed" aria-pressed="false" title="Filter batches needing attention">';
echo '          <div class="ba-stat-top">';
echo '            <div class="ba-stat-icon-wrap icon-bg-red">';
echo '              <svg class="ba-stat-svg svg-red" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
echo '            </div>';
echo '            <div class="ba-stat-sort-hint">&#x21C5;</div>';
echo '          </div>';
echo '          <div class="ba-stat-num text-delayed-red" id="stat-delayed">--</div>';
echo '          <div class="ba-stat-lbl">Delayed</div>';
echo '        </button>';

echo '        <!-- Card 10: Students (current) -->';
echo '        <div class="ba-stat-box card-students" title="Total active enrolled learners">';
echo '          <div class="ba-stat-top">';
echo '            <div class="ba-stat-icon-wrap icon-bg-indigo">';
echo '              <svg class="ba-stat-svg svg-indigo" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>';
echo '            </div>';
echo '            <div class="ba-stat-sort-hint">&#x21C5;</div>';
echo '          </div>';
echo '          <div class="ba-stat-num" id="stat-total-students">--</div>';
echo '          <div class="ba-stat-lbl">Students (current)</div>';
echo '        </div>';
echo '      </div>';
echo '    </div>';

echo '    <!-- Search & Filters Controls Card -->';
echo '    <div class="ba-new-card ba-new-controls-card">';
echo '      <div class="ba-new-search-row">';
echo '        <input type="text" id="ba-new-search-input" class="ba-search-input-field" placeholder="Search section or batch code (e.g., 26011A, 25002A)..." />';
echo '        <button id="ba-new-reset-btn" type="button" class="ba-btn-blue-solid" title="Clear all active filters">Reset</button>';
echo '      </div>';
echo '      <div class="ba-filters-label">FILTERS</div>';
echo '      <div class="ba-new-filters-row">';
echo '        <select id="ba-filter-year" class="ba-new-select"><option value="">Year — All</option></select>';
echo '        <select id="ba-filter-batch" class="ba-new-select"><option value="">Section / Batch — All</option></select>';
echo '        <select id="ba-filter-course" class="ba-new-select"><option value="">Course — All</option></select>';
echo '        <select id="ba-filter-mode" class="ba-new-select"><option value="">Mode — All</option></select>';
echo '      </div>';
echo '    </div>';

echo '    <!-- Subtabs Navigation -->';
echo '    <div class="ba-new-subtabs-bar">';
echo '      <button type="button" class="ba-new-subtab active" data-subtab="running">';
echo '        Current Running Batches';
echo '      </button>';
echo '      <button type="button" class="ba-new-subtab" data-subtab="completed">';
echo '        Completed Batches';
echo '      </button>';
echo '    </div>';

echo '    <!-- Table Section Header -->';
echo '    <div class="ba-table-header-row">';
echo '      <div class="ba-table-title-wrap">';
echo '        <span class="ba-glance-indicator"></span>';
echo '        <h2 class="ba-table-title" id="ba-table-title-text">Current Running Batches</h2>';
echo '      </div>';
echo '      <div class="ba-table-showing-info" id="ba-new-pagination-info">Showing 0 of 0</div>';
echo '    </div>';

echo '    <!-- Main Table Container -->';
echo '    <div class="ba-new-table-card">';
echo '      <div class="ba-new-table-wrapper">';
echo '        <table class="ba-new-table" id="ba-new-batch-table">';
echo '          <thead>';
echo '            <tr>';
echo '              <th>BATCH / SECTION</th>';
echo '              <th>COURSE</th>';
echo '              <th>MODE</th>';
echo '              <th>TYPE</th>';
echo '              <th>START</th>';
echo '              <th>CURRENT MODULE</th>';
echo '              <th>STATUS</th>';
echo '              <th style="text-align:right;"></th>';
echo '            </tr>';
echo '          </thead>';
echo '          <tbody id="ba-new-table-body">';
echo '            <tr>';
echo '              <td colspan="8" class="ba-new-loading">Loading batch analytics...</td>';
echo '            </tr>';
echo '          </tbody>';
echo '        </table>';
echo '      </div>';

echo '      <!-- Pagination Footer -->';
echo '      <div class="ba-new-pagination-bar">';
echo '        <div class="ba-new-pagination-controls" id="ba-new-pagination-controls">';
echo '        </div>';
echo '      </div>';
echo '    </div>';

echo '  </div>';
echo '</div>'; // #ba-top-tab-new

echo '</div>'; // .local-batchanalytics-wrap

echo $OUTPUT->footer();
?>
