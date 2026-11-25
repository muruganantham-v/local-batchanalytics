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

use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper class to render the ESSCI Program MIS view in index.php.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class essci_dashboard_helper {

    /**
     * Retrieve all cohort batches for the Sponsored Marvell ESSCI Program.
     * Strictly performs INNER JOIN with local_bm_classsection so only batches
     * with valid class sections are displayed (parity with regular dashboard).
     *
     * @return array List of cohort batches.
     */
    public static function get_cohort_batches(): array {
        global $DB;

        $cohorts = [];
        $seen_batch_ids = [];

        try {
            $dbman = $DB->get_manager();
            $has_batch = $dbman->table_exists('local_bm_batch');
            $has_section = $dbman->table_exists('local_bm_classsection');

            if ($has_batch && $has_section) {
                $has_batch_loc = $dbman->field_exists('local_bm_batch', 'location');

                // Batches to fetch: ESSCI designated batches by coursename or Marvell course/name
                $where_clauses = [
                    "LOWER(b.coursename) = 'essci'",
                    "LOWER(b.coursename) LIKE '%essci%'",
                    "b.name LIKE 'MVL%'",
                    "b.coursename LIKE '%Marvell%'"
                ];

                // Strictly INNER JOIN to ensure only batches with class sections are retrieved
                $sql = "SELECT b.*, s.id AS sectionid, s.name AS sectionname, s.classlocation, s.moduledata
                        FROM {local_bm_batch} b
                        INNER JOIN {local_bm_classsection} s ON s.batchid = b.id
                        WHERE " . implode(' OR ', $where_clauses) . "
                        ORDER BY b.startdate ASC, s.id ASC";

                $db_batches = $DB->get_records_sql($sql);

                foreach ($db_batches as $dbb) {
                    $bid = (int)$dbb->id;
                    $sec_id = (int)$dbb->sectionid;
                    $dedup_key = $bid . '_' . $sec_id;
                    if (isset($seen_batch_ids[$dedup_key])) {
                        continue;
                    }
                    $seen_batch_ids[$dedup_key] = true;

                    $ts = (int)$dbb->startdate;
                    if ($ts <= 0) {
                        $ts = time();
                    }

                    // Class section display name
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

                    // Site resolution from batch management location column (fallback to '-' if not present)
                    if (!empty($has_batch_loc) && !empty($dbb->location) && trim($dbb->location) !== '') {
                        $site = trim($dbb->location);
                        $site_color = (stripos($site, 'hyderabad') !== false) ? 'var(--brand-d)' : 'var(--blue-d)';
                    } else {
                        $site = '-';
                        $site_color = '#64748b';
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
                    if ($student_count < 0) {
                        $student_count = 0;
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

        return $cohorts;
    }

    /**
     * Return Indian Financial Year key (e.g. '2026') for a unix timestamp.
     *
     * @param int $ts
     * @return string
     */
    public static function get_window_key_for_ts(int $ts): string {
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
     *
     * @param array $cohorts List of cohort batches.
     * @return array Associative array of year_key => date_range_label.
     */
    public static function get_program_windows_from_cohorts(array $cohorts): array {
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

    /**
     * Render the complete ESSCI Program MIS dashboard view.
     *
     * @param \context $context
     * @param int $userid
     * @return void
     */
    public static function render(\context $context, int $userid): void {
        global $CFG, $PAGE, $OUTPUT, $USER, $DB;

        $PAGE->set_title('Advanced Skilling of Women in Semiconductors — Program MIS');
        $PAGE->set_heading('');

        $mis_css_v = file_exists(__DIR__ . '/../marvel_essci_mis.css') ? filemtime(__DIR__ . '/../marvel_essci_mis.css') : '3.5.0';
        $PAGE->requires->css(new moodle_url('/local/batchanalytics/marvel_essci_mis.css', ['v' => (string)$mis_css_v]));
        $dash_v = file_exists(__DIR__ . '/../dashboard.css') ? filemtime(__DIR__ . '/../dashboard.css') : '3.5.0';
        $PAGE->requires->css(new moodle_url('/local/batchanalytics/dashboard.css', ['v' => (string)$dash_v]));
        $style_v = file_exists(__DIR__ . '/../styles.css') ? filemtime(__DIR__ . '/../styles.css') : '3.5.0';
        $PAGE->requires->css(new moodle_url('/local/batchanalytics/styles.css', ['v' => (string)$style_v]));
        $PAGE->requires->js_call_amd('local_batchanalytics/marvel_essci_mis', 'init', ['page' => 'home']);

        $emertxe_logo_url = (new moodle_url('/local/batchanalytics/pix/emertxe_logo.png'))->out(false);
        $essci_logo_url   = (new moodle_url('/local/batchanalytics/pix/essci_logo.png'))->out(false);
        $marvell_logo_url = (new moodle_url('/local/batchanalytics/pix/marvell_logo.png'))->out(false);

        // Dynamic Program Meta & Mobilization Metrics
        $sponsor = get_config('local_batchanalytics', 'mis_sponsor') ?: 'Marvell Semiconductor';
        $pmo = get_config('local_batchanalytics', 'mis_pmo') ?: 'ESSCI';
        $training_partner = get_config('local_batchanalytics', 'mis_training_partner') ?: 'Emertxe';

        // Sites dynamically extracted from batch management (local_bm_batch -> location)
        $sites = '-';
        try {
            if ($DB->get_manager()->table_exists('local_bm_batch') && $DB->get_manager()->field_exists('local_bm_batch', 'location')) {
                $loc_records = $DB->get_records_sql(
                    "SELECT DISTINCT location FROM {local_bm_batch} 
                     WHERE (LOWER(coursename) = 'essci' OR LOWER(coursename) LIKE '%essci%') 
                       AND location IS NOT NULL AND TRIM(location) != ''"
                );
                if (!empty($loc_records)) {
                    $loc_names = [];
                    foreach ($loc_records as $lr) {
                        if (!empty($lr->location) && trim($lr->location) !== '') {
                            $loc_names[] = trim($lr->location);
                        }
                    }
                    if (!empty($loc_names)) {
                        $sites = implode(' · ', array_unique($loc_names));
                    }
                }
            }
        } catch (\Throwable $e) {
            $sites = '-';
        }

        $cohort_batches = self::get_cohort_batches();
        $program_windows = self::get_program_windows_from_cohorts($cohort_batches);
        $selected_window_year = optional_param('window', '2026', PARAM_ALPHANUM);
        if (!isset($program_windows[$selected_window_year])) {
            $keys = array_keys($program_windows);
            $selected_window_year = !empty($keys) ? $keys[0] : '2026';
        }

        $program_title = get_config('local_batchanalytics', 'mis_program_title') ?: 'Advanced Skilling of Women in Semiconductors';
        $slogan = get_config('local_batchanalytics', 'mis_slogan') ?: 'Driving Inclusion and Innovation for a future-ready Workforce';

        $candidates_reached     = (int)(get_config('local_batchanalytics', 'mis_candidates_reached') ?: 2480);
        $eligible_candidates    = (int)(get_config('local_batchanalytics', 'mis_eligible_candidates') ?: 860);
        $test_participants      = (int)(get_config('local_batchanalytics', 'mis_test_participants') ?: 420);
        $interview_participants = (int)(get_config('local_batchanalytics', 'mis_interview_participants') ?: 286);
        $interview_selects      = (int)(get_config('local_batchanalytics', 'mis_interview_selects') ?: 120);

        echo $OUTPUT->header();
        ?>
        <script>
        (function() {
          var p = document.getElementById('page');
          if (p) p.classList.remove('drawers', 'drag-container');
        })();
        document.addEventListener('DOMContentLoaded', function() {
          var p = document.getElementById('page');
          if (p) p.classList.remove('drawers', 'drag-container');
        });
        </script>
        <style>
        /* Reset drawers & container to clean full-width */
        #page,
        #page.drawers,
        .drawers.drag-container,
        #page-wrapper {
          overflow: visible !important;
          height: auto !important;
          background: transparent !important;
        }

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
        .wrap {
          max-width: 100% !important;
          width: 100% !important;
          padding-left: 20px !important;
          padding-right: 20px !important;
          box-sizing: border-box !important;
        }
        .crumbbar {
          max-width: 100% !important;
          width: 100% !important;
          padding: 12px 0 6px 0 !important;
          margin: 0 !important;
          box-sizing: border-box !important;
          display: flex !important;
          align-items: center !important;
        }
        .crumbbar .crumb {
          padding-left: 0 !important;
          margin-left: 0 !important;
          font-size: 13px !important;
          color: #64748b !important;
        }
        .crumbbar a {
          color: #12395f !important;
          text-decoration: none !important;
          font-weight: 500 !important;
        }
        .crumbbar a:hover {
          text-decoration: underline !important;
        }
        .crumbbar b {
          color: #0f172a !important;
          font-weight: 700 !important;
        }
        .crumb-sep {
          margin: 0 8px !important;
          color: #94a3b8 !important;
          font-weight: 400 !important;
        }
        .shell,
        .ba-essci-dashboard .shell {
          background: #ffffff !important;
          border-radius: 14px !important;
          box-shadow: 0 8px 30px rgba(30, 50, 70, 0.08) !important;
          border: 1px solid var(--line, #e3e6ea) !important;
          padding: 26px 28px 32px !important;
          margin: 14px auto 36px !important;
          box-sizing: border-box !important;
          max-width: 100% !important;
          width: 100% !important;
        }
        .phdr .meta {
          display: grid !important;
          grid-template-columns: repeat(5, 1fr) !important;
          gap: 16px !important;
          align-items: center !important;
          background: #ffffff !important;
          border: 1px solid #e2e8f0 !important;
          border-radius: 12px !important;
          padding: 14px 20px !important;
          width: 100% !important;
          box-sizing: border-box !important;
        }
        .phdr .meta > div {
          min-width: 0 !important;
          display: flex !important;
          flex-direction: column !important;
          justify-content: center !important;
        }
        .phdr .meta .k {
          font-size: 11px !important;
          text-transform: uppercase !important;
          letter-spacing: 0.5px !important;
          color: #64748b !important;
          font-weight: 700 !important;
          margin-bottom: 4px !important;
          white-space: nowrap !important;
          overflow: hidden !important;
          text-overflow: ellipsis !important;
        }
        .phdr .meta .v {
          font-size: 13.5px !important;
          font-weight: 700 !important;
          color: #0f172a !important;
          margin-top: 1px !important;
          white-space: nowrap !important;
          overflow: hidden !important;
          text-overflow: ellipsis !important;
        }
        .phdr .meta .v-select {
          font-family: inherit !important;
          font-size: 13px !important;
          font-weight: 700 !important;
          color: #0f172a !important;
          margin-top: 1px !important;
          background-color: #ffffff !important;
          border: 1px solid #cbd5e1 !important;
          border-radius: 6px !important;
          padding: 4px 26px 4px 10px !important;
          cursor: pointer !important;
          outline: none !important;
          appearance: none !important;
          -webkit-appearance: none !important;
          background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e") !important;
          background-repeat: no-repeat !important;
          background-position: right 8px center !important;
          background-size: 12px !important;
          transition: border-color .15s ease, box-shadow .15s ease !important;
          width: 100% !important;
          max-width: 100% !important;
          box-sizing: border-box !important;
        }
        .metric-card {
          background: #ffffff !important;
          border: 1px solid #e2e8f0 !important;
        }
        thead th,
        table.metrics th {
          background: #ffffff !important;
          border-bottom: 2px solid #e2e8f0 !important;
        }
        @media(max-width:1100px){ .phdr .meta{ grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)) !important; gap:14px !important; } }
        @media(max-width:640px){ .phdr .meta{ grid-template-columns:repeat(2, 1fr) !important; gap:12px !important; } }
        @media(max-width:420px){ .phdr .meta{ grid-template-columns:1fr !important; } }
        </style>

        <?php if (is_siteadmin($USER->id) || has_capability('local/batchanalytics:manage', $context)): ?>
          <div class="wrap" style="margin-bottom:0; padding-top:14px;">
            <div style="padding:12px 20px; background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; display:flex; justify-content:space-between; align-items:center; font-size:13px; color:#166534; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-weight:700;"><i class="fa fa-check-circle"></i> Preview Mode:</span>
                <span>Viewing as Marvell &amp; ESSCI Program Sponsor.</span>
              </div>
              <a href="<?= s((new moodle_url('/local/batchanalytics/index.php', ['viewmode' => 'internal']))->out(false)) ?>" class="btn btn-sm btn-outline-secondary" style="background:#fff; color:#1e293b; border:1px solid #cbd5e1; text-decoration:none; font-size:12px; padding:5px 14px; display:inline-flex; align-items:center; gap:6px; border-radius:6px; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                <i class="fa fa-exchange"></i> Switch to Internal Operations View
              </a>
            </div>
          </div>
        <?php endif; ?>

        <div class="local-batchanalytics-wrap ba-essci-dashboard" id="ba-sponsor-dashboard-container">
          <div class="wrap">
            <div class="shell">
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
            <?php if (is_siteadmin($USER->id) || \local_batchanalytics\program_service::has_write_permission($context)): ?>
              <a href="marvel_essci_data.php" class="viewbtn" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:6px 14px;">✎ Edit Data</a>
            <?php endif; ?>
           </div>

           <div class="metrics-grid">
             <div class="metric-card">
               <div class="m-label">Total Candidates Reached</div>
               <div class="m-val"><?= number_format($candidates_reached) ?></div>
             </div>
             <div class="metric-card">
               <div class="m-label">Eligible Candidates</div>
               <div class="m-val"><?= number_format($eligible_candidates) ?></div>
             </div>
             <div class="metric-card">
               <div class="m-label">Test Participants</div>
               <div class="m-val"><?= number_format($test_participants) ?></div>
             </div>
             <div class="metric-card">
               <div class="m-label">Interview Participants</div>
               <div class="m-val"><?= number_format($interview_participants) ?></div>
             </div>
             <div class="metric-card highlight">
               <div class="m-label">Interview Selects (Final)</div>
               <div class="m-val"><?= number_format($interview_selects) ?></div>
             </div>
           </div>

           <div class="divider"></div>
           <?php
             $initial_cohorts_count = 0;
             foreach ($cohort_batches as $c) {
                 $c_start_ts = !empty($c['start_timestamp']) ? (int)$c['start_timestamp'] : strtotime($c['start'] ?? '');
                 if (self::get_window_key_for_ts($c_start_ts) === (string)$selected_window_year) {
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
                  $c_wkey = self::get_window_key_for_ts($c_start_ts);
                  if ($c_wkey !== (string)$selected_window_year && !empty($c['start_timestamp'])) continue;
                  $c_status = $c['status'] ?? 'On schedule';
                  $c_stclass = $c_status === 'On schedule' ? 'st-g' : ($c_status === 'Upcoming' ? 'st-a' : 'st-r');
                  $c_secparam = !empty($c['sectionid']) ? $c['sectionid'] : (!empty($c['batchid']) ? $c['batchid'] : 5);
                  $c_burl = (new moodle_url('/local/batchanalytics/batch.php', ['id' => $c_secparam, 'mode' => 'Offline', 'viewmode' => 'sponsor']))->out(false);
                ?>
                <tr>
                  <td><span class="bid"><?= s($c['id']) ?></span></td>
                  <td><?= s($c['course']) ?></td>
                  <td><?php if (!empty($c['site']) && $c['site'] !== '-'): ?><span class="sitepill"><i style="background:<?= s($c['site_color'] ?? 'var(--blue-d)') ?>"></i><?= s($c['site']) ?></span><?php else: ?><span style="color:#64748b;">-</span><?php endif; ?></td>
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
          </div> <!-- /#v-home -->
        </div> <!-- /.shell -->
      </div> <!-- /.wrap -->
    </div> <!-- /.local-batchanalytics-wrap -->

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
    }
}
