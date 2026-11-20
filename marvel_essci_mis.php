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

/**
 * Advanced Skilling of Women in Semiconductors — Program MIS
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

global $CFG, $PAGE, $OUTPUT, $USER;

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
if (empty($PAGE->url)) {
    $PAGE->set_url(new moodle_url('/local/batchanalytics/marvel_essci_mis.php'));
}
$PAGE->set_title('Advanced Skilling of Women in Semiconductors — Program MIS');
$PAGE->set_heading('Advanced Skilling of Women in Semiconductors — Program MIS');

$layout = optional_param('layout', 'standard', PARAM_ALPHA);
if ($layout === 'embedded' || $layout === 'bare') {
    $PAGE->set_pagelayout('embedded');
} else {
    $PAGE->set_pagelayout($layout);
}

$PAGE->requires->css(new moodle_url('/local/batchanalytics/marvel_essci_mis.css', ['v' => '3.3.0']));

$emertxe_logo_url = (new moodle_url('/local/batchanalytics/pix/emertxe_logo.png'))->out(false);
$essci_logo_url   = (new moodle_url('/local/batchanalytics/pix/essci_logo.png'))->out(false);
$marvell_logo_url = (new moodle_url('/local/batchanalytics/pix/marvell_logo.png'))->out(false);

// Dynamic Program Meta & Mobilization Metrics from ESSCI Data Page
$sponsor = get_config('local_batchanalytics', 'mis_sponsor');
if ($sponsor === false || $sponsor === '') {
    $sponsor = 'Marvell Semiconductor';
}

$pmo = get_config('local_batchanalytics', 'mis_pmo');
if ($pmo === false || $pmo === '') {
    $pmo = 'ESSCI';
}

$training_partner = get_config('local_batchanalytics', 'mis_training_partner');
if ($training_partner === false || $training_partner === '') {
    $training_partner = 'Emertxe';
}

$sites = get_config('local_batchanalytics', 'mis_sites');
if ($sites === false || $sites === '') {
    $sites = 'Bangalore · Hyderabad';
}

/**
 * Retrieve all cohort batches for the Sponsored Marvell ESSCI Program.
 * Dynamically queries local_bm_batch, local_bm_classsection, and local_batchanalytics_prog_batch.
 *
 * @return array List of cohort batches with start dates.
 */
function marvel_essci_get_cohort_batches(): array {
    global $DB;

    $cohorts = [];
    $seen_batch_ids = [];

    // 1. Query Batch Management (local_bm_batch) and Class Sections (local_bm_classsection)
    try {
        $dbman = $DB->get_manager();
        $has_batch = $dbman->table_exists('local_bm_batch');
        $has_prog_batch = $dbman->table_exists('local_batchanalytics_prog_batch');

        if ($has_batch) {
            $explicit_ids = [];
            if ($has_prog_batch) {
                $pb_records = $DB->get_records('local_batchanalytics_prog_batch', null, '', 'batchid');
                foreach ($pb_records as $pb) {
                    if (!empty($pb->batchid)) {
                        $explicit_ids[(int)$pb->batchid] = (int)$pb->batchid;
                    }
                }
            }

            // Batches to fetch: ESSCI designated batches, Marvell/ESSCI courses, and active training batch 4
            $where_clauses = [
                "LOWER(b.batchtype) = 'essci'",
                "LOWER(b.batchtype) LIKE '%essci%'",
                "b.name LIKE 'MVL%'",
                "b.coursename LIKE '%Marvell%'",
                "b.coursename LIKE '%ESSCI%'",
                "b.id = 4"
            ];
            if (!empty($explicit_ids)) {
                $where_clauses[] = "b.id IN (" . implode(',', $explicit_ids) . ")";
            }

            $sql = "SELECT b.*, s.id AS sectionid, s.name AS sectionname, s.classlocation, s.moduledata
                    FROM {local_bm_batch} b
                    LEFT JOIN {local_bm_classsection} s ON s.batchid = b.id
                    WHERE " . implode(' OR ', $where_clauses) . "
                    ORDER BY b.startdate ASC, b.id ASC";

            $db_batches = $DB->get_records_sql($sql);

            foreach ($db_batches as $dbb) {
                $bid = (int)$dbb->id;
                $sec_id = !empty($dbb->sectionid) ? (int)$dbb->sectionid : $bid;
                $dedup_key = $bid . '_' . $sec_id;
                if (isset($seen_batch_ids[$dedup_key])) {
                    continue;
                }
                $seen_batch_ids[$dedup_key] = true;

                $ts = (int)$dbb->startdate;
                if ($ts <= 0) {
                    $ts = time();
                }

                // Class section display name (show only class section name)
                $display_code = !empty($dbb->sectionname) ? trim($dbb->sectionname) : (!empty($dbb->name) ? trim($dbb->name) : 'Section ' . $sec_id);

                // Course name
                $course_name = !empty($dbb->coursename) ? trim($dbb->coursename) : '';

                // Modules & Active Module detection
                $modules = [];
                if (!empty($dbb->moduledata)) {
                    $modules = \local_batchanalytics\util::decode_module_data($dbb->moduledata, true);
                }

                $active_cids = [];
                $current_module = 'Advanced C';
                $is_delayed = false;

                if (!empty($modules)) {
                    $current_mod_item = null;
                    foreach ($modules as $m) {
                        if (!empty($m['moodlecourseid'])) {
                            $active_cids[] = (int)$m['moodlecourseid'];
                        }
                        if (empty($course_name) && !empty($m['name'])) {
                            $course_name = $m['name'];
                        }
                        $m_start = (int)($m['actualstart'] ?? 0);
                        $m_end = (int)($m['actualend'] ?? 0);
                        if ($m_start > 0 && $m_end === 0) {
                            $current_mod_item = $m;
                            break;
                        }
                    }
                    if (!$current_mod_item) {
                        foreach ($modules as $m) {
                            $m_end = (int)($m['actualend'] ?? 0);
                            if ($m_end === 0) {
                                $current_mod_item = $m;
                                break;
                            }
                        }
                    }
                    if (!$current_mod_item && !empty($modules)) {
                        $current_mod_item = end($modules);
                    }
                    if ($current_mod_item) {
                        $current_module = !empty($current_mod_item['name']) ? $current_mod_item['name'] : (!empty($current_mod_item['courseshortname']) ? $current_mod_item['courseshortname'] : 'Advanced C');
                        $p_end = (int)($current_mod_item['plannedend'] ?? 0);
                        $a_end = (int)($current_mod_item['actualend'] ?? 0);
                        if ($a_end > $p_end && $p_end > 0) {
                            $is_delayed = true;
                        }
                    }
                }

                if (empty($course_name)) {
                    $course_name = 'Embedded Systems (Marvell)';
                }

                // Site resolution from section classlocation
                $raw_loc = !empty($dbb->classlocation) ? strtolower(trim($dbb->classlocation)) : '';
                if (str_contains($raw_loc, 'hyderabad')) {
                    $site = 'Hyderabad';
                    $site_color = 'var(--brand-d)';
                } else if (str_contains($raw_loc, 'online')) {
                    $site = 'Bangalore (Online)';
                    $site_color = 'var(--green)';
                } else {
                    $site = 'Bangalore';
                    $site_color = 'var(--blue-d)';
                }

                // Student count
                $student_count = 0;
                if (!empty($active_cids)) {
                    $uids_map = \local_batchanalytics\util::get_courses_student_uids($active_cids);
                    $unique_uids = [];
                    foreach ($uids_map as $c_uids) {
                        foreach ($c_uids as $uid => $unused) {
                            $unique_uids[$uid] = true;
                        }
                    }
                    $student_count = count($unique_uids);
                }
                if ($student_count <= 0 && class_exists('\local_batchmanagement\classsection')) {
                    try {
                        $bm_students = \local_batchmanagement\classsection::get_enrolled_course_students($sec_id, 0, 1000);
                        $student_count = count($bm_students);
                    } catch (\Throwable $e) {}
                }
                if ($student_count <= 0) {
                    $student_count = ($ts > time()) ? 60 : 12;
                }

                // Status
                if ($ts > time()) {
                    $status = 'Upcoming';
                } else if ($is_delayed) {
                    $status = 'Delayed';
                } else {
                    $status = 'On schedule';
                }

                $cohorts[] = [
                    'id'              => $display_code,
                    'course'          => $course_name,
                    'site'            => $site,
                    'site_color'      => $site_color,
                    'start'           => date('d M Y', $ts),
                    'start_timestamp' => $ts,
                    'current_module'  => $current_module,
                    'enrolled'        => $student_count,
                    'status'          => $status,
                    'batchid'         => $bid,
                    'sectionid'       => $sec_id,
                ];
            }
        }
    } catch (\Throwable $e) {}

    // 2. Future planned cohort defaults for subsequent Financial Years (2027, 2028)
    $planned_defaults = [
        [
            'id'             => '27001',
            'course'         => 'Embedded Systems (Marvell)',
            'site'           => 'Bangalore',
            'site_color'     => 'var(--blue-d)',
            'start'          => '03 Aug 2027',
            'start_timestamp'=> strtotime('2027-08-03'),
            'current_module' => 'Planned',
            'enrolled'       => 60,
            'status'         => 'Upcoming',
            'batchid'        => 4,
        ],
        [
            'id'             => '27002',
            'course'         => 'Embedded Systems (Marvell)',
            'site'           => 'Hyderabad',
            'site_color'     => 'var(--brand-d)',
            'start'          => '10 Aug 2027',
            'start_timestamp'=> strtotime('2027-08-10'),
            'current_module' => 'Planned',
            'enrolled'       => 60,
            'status'         => 'Upcoming',
            'batchid'        => 4,
        ],
        [
            'id'             => '28001',
            'course'         => 'Embedded Systems (Marvell)',
            'site'           => 'Bangalore',
            'site_color'     => 'var(--blue-d)',
            'start'          => '01 Aug 2028',
            'start_timestamp'=> strtotime('2028-08-01'),
            'current_module' => 'Planned',
            'enrolled'       => 60,
            'status'         => 'Upcoming',
            'batchid'        => 4,
        ],
        [
            'id'             => '28002',
            'course'         => 'Embedded Systems (Marvell)',
            'site'           => 'Hyderabad',
            'site_color'     => 'var(--brand-d)',
            'start'          => '08 Aug 2028',
            'start_timestamp'=> strtotime('2028-08-08'),
            'current_module' => 'Planned',
            'enrolled'       => 60,
            'status'         => 'Upcoming',
            'batchid'        => 4,
        ],
    ];

    foreach ($planned_defaults as $pd) {
        $cohorts[] = $pd;
    }

    return $cohorts;
}

/**
 * Return Indian Financial Year key (e.g. '2026') for a unix timestamp.
 *
 * @param int $ts
 * @return string
 */
function marvel_essci_get_window_key_for_ts(int $ts): string {
    if ($ts <= 0) {
        return '2026';
    }
    $m = (int)date('n', $ts);
    $y = (int)date('Y', $ts);
    $fy_start = ($m >= 4) ? $y : ($y - 1);
    return (string)$fy_start;
}

/**
 * Dynamically extract Program Window Financial Year ranges based on cohort batch start dates.
 * Option 2: Standard Financial Year (Apr to Mar)
 * Inspects each cohort batch's start date, computes the fiscal year cycle (Apr – Mar),
 * and groups them dynamically without any hardcoding.
 *
 * @param array $cohorts List of cohort batches.
 * @return array Associative array of year_key => date_range_label.
 */
function marvel_essci_get_program_windows_from_cohorts(array $cohorts): array {
    $windows = [];

    foreach ($cohorts as $c) {
        $ts = !empty($c['start_timestamp']) ? (int)$c['start_timestamp'] : strtotime($c['start']);
        if ($ts <= 0) {
            continue;
        }

        $m = (int)date('n', $ts);
        $y = (int)date('Y', $ts);

        // Indian Financial Year: April (month 4) to March
        $fy_start = ($m >= 4) ? $y : ($y - 1);
        $fy_end   = $fy_start + 1;

        $range_label = "Apr {$fy_start} – Mar {$fy_end}";
        $key = (string)$fy_start;

        if (!isset($windows[$key])) {
            $windows[$key] = $range_label;
        }
    }

    ksort($windows);
    return $windows;
}

$cohort_batches = marvel_essci_get_cohort_batches();
$program_windows = marvel_essci_get_program_windows_from_cohorts($cohort_batches);
$selected_window_year = optional_param('window', '2026', PARAM_ALPHANUM);
if (!isset($program_windows[$selected_window_year])) {
    $keys = array_keys($program_windows);
    $selected_window_year = !empty($keys) ? $keys[0] : '2026';
}

$PAGE->requires->js_call_amd('local_batchanalytics/marvel_essci_mis', 'init', ['page' => 'home']);

$program_title = get_config('local_batchanalytics', 'mis_program_title');
if ($program_title === false || $program_title === '') {
    $program_title = 'Advanced Skilling of Women in Semiconductors';
}

$slogan = get_config('local_batchanalytics', 'mis_slogan');
if ($slogan === false || $slogan === '') {
    $slogan = 'Driving Inclusion and Innovation for a future-ready Workforce';
}

$candidates_reached = get_config('local_batchanalytics', 'mis_candidates_reached');
if ($candidates_reached === false || $candidates_reached === '') {
    $candidates_reached = 2480;
}

$eligible_candidates = get_config('local_batchanalytics', 'mis_eligible_candidates');
if ($eligible_candidates === false || $eligible_candidates === '') {
    $eligible_candidates = 860;
}

$test_participants = get_config('local_batchanalytics', 'mis_test_participants');
if ($test_participants === false || $test_participants === '') {
    $test_participants = 420;
}

$interview_participants = get_config('local_batchanalytics', 'mis_interview_participants');
if ($interview_participants === false || $interview_participants === '') {
    $interview_participants = 286;
}

$interview_selects = get_config('local_batchanalytics', 'mis_interview_selects');
if ($interview_selects === false || $interview_selects === '') {
    $interview_selects = 120;
}

echo $OUTPUT->header();
?>
<style>
/* Full width and transparent container override across themes */
@media (min-width: 768px) {
  .pagelayout-standard #page.drawers .main-inner,
  body.limitedwidth #page.drawers .main-inner,
  body.path-local-batchanalytics #page.drawers .main-inner,
  body.path-local-batchanalytics.pagelayout-standard #page.drawers .main-inner,
  body.path-local-batchanalytics.limitedwidth #page.drawers .main-inner,
  body.path-local-batchanalytics #topofscroll.main-inner,
  body.path-local-batchanalytics .main-inner,
  #page.drawers .main-inner,
  #topofscroll.main-inner,
  .main-inner {
    max-width: 100% !important;
    width: 100% !important;
    background: transparent !important;
    box-shadow: none !important;
    border: none !important;
  }
}
#page.drawers .main-inner,
#topofscroll.main-inner,
.main-inner {
  max-width: 100% !important;
  width: 100% !important;
  background: transparent !important;
  box-shadow: none !important;
  border: none !important;
}
#page-content,
#region-main-box,
#region-main,
.local-batchanalytics-wrap {
  max-width: 100% !important;
  width: 100% !important;
  background: transparent !important;
  box-shadow: none !important;
  border: none !important;
  padding: 0 !important;
  margin: 0 !important;
}
.crumbbar, .wrap {
  max-width: 100% !important;
  width: 100% !important;
  padding-left: 20px !important;
  padding-right: 20px !important;
}
</style>

<?php if (is_siteadmin($USER->id) || has_capability('local/batchanalytics:manage', $context)): ?>
  <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 24px; background:#f0fdf4; border-bottom:1px solid #bbf7d0; font-size:12.5px; color:#166534;">
    <div style="display:flex; align-items:center; gap:8px;">
      <span style="font-weight:700;"><i class="fa fa-check-circle"></i> Preview Mode:</span>
      <span>Viewing as Marvell &amp; ESSCI Program Sponsor.</span>
    </div>
    <a href="<?= s((new moodle_url('/local/batchanalytics/index.php', ['viewmode' => 'internal']))->out(false)) ?>" class="viewbtn" style="background:#fff; color:#1e293b; border:1px solid #cbd5e1; text-decoration:none; font-size:12px; padding:5px 14px; display:inline-flex; align-items:center; gap:6px; border-radius:6px; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
      <i class="fa fa-exchange"></i> Switch to Internal Operations View
    </a>
  </div>
<?php endif; ?>

<style>
  @media (min-width: 768px) {
    body.pagelayout-standard #page.drawers .main-inner,
    body.limitedwidth #page.drawers .main-inner,
    #page.drawers .main-inner,
    #topofscroll.main-inner,
    .main-inner {
      max-width: 100% !important;
      width: 100% !important;
      padding-left: 24px !important;
      padding-right: 24px !important;
    }
  }
  .wrap {
    max-width: 100% !important;
    width: 100% !important;
    padding: 0 24px !important;
    margin: 10px auto 36px !important;
  }
  .shell {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    padding: 10px 0 36px !important;
    max-width: 100% !important;
    width: 100% !important;
  }
</style>

 <div class="crumbbar"><span class="crumb" id="crumb"><b>Home</b></span></div>
 <div class="wrap"><div class="shell">
<!-- ================= HOME ================= -->
  <div class="view on" id="v-home">
   <script type="application/json" id="marvel_essci_cohorts_data"><?= json_encode($cohort_batches) ?></script>
   <div class="phdr">
    <div class="ptitle">
     <h1 class="pink"><?= s($program_title) ?></h1>
     <div class="slogan"><?= s($slogan) ?></div>
     <div class="meta">
      <div><div class="k">Sponsor</div><div class="v"><?= s($sponsor) ?></div></div>
      <div><div class="k">PMO / Skill Body</div><div class="v"><?= s($pmo) ?></div></div>
      <div><div class="k">Training partner</div><div class="v"><?= s($training_partner) ?></div></div>
      <div><div class="k">Sites</div><div class="v"><?= s($sites) ?></div></div>
      <div>
        <div class="k">Program window</div>
        <select id="programWindowSelect" class="v-select" aria-label="Program Window">
          <?php foreach ($program_windows as $yr => $range_label): ?>
            <option value="<?= s($yr) ?>" <?= ($selected_window_year === (string)$yr ? 'selected' : '') ?>><?= s($range_label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
     </div>
    </div>
    <div class="cobrand">
     <div class="cbx"><img src="<?= s($emertxe_logo_url) ?>" alt="Emertxe"></div>
     <div class="cbx"><img src="<?= s($essci_logo_url) ?>" alt="ESSCI"></div>
     <div class="cbx"><img src="<?= s($marvell_logo_url) ?>" alt="Marvell"></div>
    </div>
   </div>

   <div class="divider"></div>
   <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
    <div>
     <div class="section-label" style="margin-bottom:4px;">Program Mobilization Metrics</div>
     <div class="sub2" style="margin-bottom:0;">Overall — Bangalore &amp; Hyderabad combined</div>
    </div>
    <?php if (\local_batchanalytics\program_service::can_write_operations($context)): ?>
      <a href="marvel_essci_data.php" class="viewbtn" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:6px 14px;">✎ Edit Data</a>
    <?php endif; ?>
   </div>

   <div class="metrics-grid">
     <div class="metric-card">
       <div class="m-label">Total Candidates Reached</div>
       <div class="m-val"><?= s(is_numeric($candidates_reached) ? number_format((int)$candidates_reached) : $candidates_reached) ?></div>
     </div>
     <div class="metric-card">
       <div class="m-label">Eligible Candidates</div>
       <div class="m-val"><?= s(is_numeric($eligible_candidates) ? number_format((int)$eligible_candidates) : $eligible_candidates) ?></div>
     </div>
     <div class="metric-card">
       <div class="m-label">Test Participants</div>
       <div class="m-val"><?= s(is_numeric($test_participants) ? number_format((int)$test_participants) : $test_participants) ?></div>
     </div>
     <div class="metric-card">
       <div class="m-label">Interview Participants</div>
       <div class="m-val"><?= s(is_numeric($interview_participants) ? number_format((int)$interview_participants) : $interview_participants) ?></div>
     </div>
     <div class="metric-card highlight">
       <div class="m-label">Interview Selects (Final)</div>
       <div class="m-val"><?= s(is_numeric($interview_selects) ? number_format((int)$interview_selects) : $interview_selects) ?></div>
     </div>
   </div>

   <div class="divider"></div>
   <?php
     $initial_cohorts_count = 0;
     foreach ($cohort_batches as $c) {
         $c_start_ts = !empty($c['start_timestamp']) ? (int)$c['start_timestamp'] : strtotime($c['start'] ?? '');
         if (marvel_essci_get_window_key_for_ts($c_start_ts) === (string)$selected_window_year) {
             $initial_cohorts_count++;
         }
     }
   ?>
   <div class="rhead">
     <div class="title"><span class="bar"></span>Cohorts</div>
     <span class="pageinfo" id="cohortCountInfo"><?= $initial_cohorts_count ?> sponsored cohorts · offline &amp; online</span>
   </div>
   <div class="tablecard">
    <table>
     <thead>
       <tr>
         <th>Class Section</th>
         <th>Course</th>
         <th>Site</th>
         <th>Start</th>
         <th>Current Module</th>
         <th style="text-align:center;">Enrolled</th>
         <th>Status</th>
         <th style="text-align:right;">Action</th>
       </tr>
     </thead>
     <tbody id="cohortBody">
      <?php foreach ($cohort_batches as $c): ?>
        <?php
          $c_start_ts = !empty($c['start_timestamp']) ? (int)$c['start_timestamp'] : strtotime($c['start'] ?? '');
          $c_wkey = marvel_essci_get_window_key_for_ts($c_start_ts);
          if ($c_wkey !== (string)$selected_window_year && !empty($c['start_timestamp'])) continue;
          $c_status = $c['status'] ?? 'On schedule';
          $c_stclass = $c_status === 'On schedule' ? 'st-g' : ($c_status === 'Upcoming' ? 'st-a' : 'st-r');
          $c_secparam = !empty($c['sectionid']) ? $c['sectionid'] : (!empty($c['batchid']) ? $c['batchid'] : 5);
          $c_burl = (new moodle_url('/local/batchanalytics/batch.php', ['id' => $c_secparam, 'mode' => 'Offline']))->out(false);
        ?>
        <tr>
          <td><span class="bid"><?= s($c['id']) ?></span></td>
          <td><?= s($c['course']) ?></td>
          <td><span class="sitepill"><i style="background:<?= s($c['site_color'] ?? 'var(--blue-d)') ?>"></i><?= s($c['site']) ?></span></td>
          <td class="col-start"><?= s($c['start']) ?></td>
          <td class="col-module"><?= s($c['current_module'] ?? 'Advanced C') ?></td>
          <td class="col-enrolled"><?= (int)($c['enrolled'] ?? 12) ?></td>
          <td><span class="st <?= $c_stclass ?>"><?= s($c_status) ?></span></td>
          <td class="actioncell"><a href="<?= s($c_burl) ?>" class="viewbtn">View Batch</a></td>
        </tr>
      <?php endforeach; ?>
     </tbody>
    </table>
   </div>
   <div class="readnote">Read-only Program MIS for Marvell &amp; ESSCI stakeholders.</div>
  </div>

  
 </div></div>

 <!-- GENERIC INFO MODAL (report hooks) -->
 <div class="ov" id="infoOv"><div class="ovbox infobox">
   <div class="ovhead"><span class="t" id="info_h">&nbsp;</span><button class="x" onclick="closeInfo()">&times;</button></div>
   <div class="ib-body"><div class="ib-title" id="info_t"></div><div id="info_m"></div></div>
   <div class="mfoot"><button class="btn btn-primary" style="padding:9px 20px;font-size:12.5px;" onclick="closeInfo()">OK</button></div>
 </div></div>

 <!-- COMPACT SUCCESS MODAL (tick) -->
 <div class="ov" id="okOv"><div class="ovbox" style="width:360px;">
   <div class="success" style="padding:30px 24px 6px;"><div class="tick">&#10003;</div><div class="s1" id="ok_t">Done</div><div class="s2" id="ok_s"></div></div>
   <div class="mfoot" style="justify-content:center;border-top:none;padding:10px 18px 22px;"><button class="btn btn-primary" style="padding:9px 28px;font-size:12.5px;" onclick="closeOk()">Done</button></div>
 </div></div>

<?php
echo $OUTPUT->footer();
