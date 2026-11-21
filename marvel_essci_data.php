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
 * ESSCI Data Page — Configure and edit program metadata and mobilization metrics.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

global $CFG, $PAGE, $OUTPUT, $USER, $DB;

require_login();

$context = context_system::instance();
$PAGE->set_context($context);

// Strictly restrict access to authorized write roles: programmanager, ssteam, manager, teacher/editingteacher, admin
if (!\local_batchanalytics\program_service::can_write_operations($context)) {
    throw new \moodle_exception('nopermissions', 'error', '', 'Access denied: write operations are restricted to Program Managers, SS Team, Managers, and Mentors.');
}
$PAGE->set_url(new moodle_url('/local/batchanalytics/marvel_essci_data.php'));
$PAGE->set_title('ESSCI Data Page — Program MIS');
$PAGE->set_heading('ESSCI Data Page — Program MIS');

$layout = optional_param('layout', 'standard', PARAM_ALPHA);
if ($layout === 'embedded' || $layout === 'bare') {
    $PAGE->set_pagelayout('embedded');
} else {
    $PAGE->set_pagelayout($layout);
}

$PAGE->requires->css(new moodle_url('/local/batchanalytics/marvel_essci_mis.css', ['v' => '3.1.0']));

$saved = optional_param('saved', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// Handle form submission
if ($action === 'save' && data_submitted() && confirm_sesskey()) {
    $sponsor          = required_param('sponsor', PARAM_TEXT);
    $pmo              = required_param('pmo', PARAM_TEXT);
    $training_partner = required_param('training_partner', PARAM_TEXT);
    $sites            = optional_param('sites', 'Bangalore · Hyderabad', PARAM_TEXT);

    $candidates_reached     = optional_param('candidates_reached', 0, PARAM_INT);
    $eligible_candidates    = optional_param('eligible_candidates', 0, PARAM_INT);
    $test_participants      = optional_param('test_participants', 0, PARAM_INT);
    $interview_participants = optional_param('interview_participants', 0, PARAM_INT);
    $interview_selects      = optional_param('interview_selects', 0, PARAM_INT);

    set_config('mis_sponsor', trim($sponsor), 'local_batchanalytics');
    set_config('mis_pmo', trim($pmo), 'local_batchanalytics');
    set_config('mis_training_partner', trim($training_partner), 'local_batchanalytics');
    set_config('mis_sites', trim($sites), 'local_batchanalytics');

    set_config('mis_candidates_reached', $candidates_reached, 'local_batchanalytics');
    set_config('mis_eligible_candidates', $eligible_candidates, 'local_batchanalytics');
    set_config('mis_test_participants', $test_participants, 'local_batchanalytics');
    set_config('mis_interview_participants', $interview_participants, 'local_batchanalytics');
    set_config('mis_interview_selects', $interview_selects, 'local_batchanalytics');

    // Handle batch mappings
    $selected_batches = optional_param_array('selected_batches', [], PARAM_INT);
    if ($DB->get_manager()->table_exists('local_batchanalytics_prog_batch')) {
        $DB->delete_records('local_batchanalytics_prog_batch', ['programid' => 1]);
        foreach ($selected_batches as $sbid) {
            $sec = $DB->get_record('local_bm_classsection', ['batchid' => $sbid], 'id, classlocation');
            $loc = (!empty($sec->classlocation) && stripos($sec->classlocation, 'hyderabad') !== false) ? 'Hyderabad' : 'Bangalore';
            $DB->insert_record('local_batchanalytics_prog_batch', (object)[
                'programid' => 1,
                'batchid' => $sbid,
                'sectionid' => !empty($sec->id) ? (int)$sec->id : 0,
                'site' => $loc,
                'sponsor_batch_code' => 'MVL' . $sbid,
            ]);
        }
    }

    redirect(new moodle_url('/local/batchanalytics/marvel_essci_data.php', ['saved' => 1]));
}

// Retrieve current stored data with sensible defaults
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

<div class="crumbbar"><span class="crumb" id="crumb"><a href="marvel_essci_mis.php">Home</a> &nbsp;›&nbsp; <b>ESSCI Data Page</b></span></div>

<div class="wrap"><div class="shell">
  <div class="phdr" style="margin-bottom:20px; align-items:flex-start;">
    <div class="ptitle">
      <h1 class="pink">ESSCI Program Data Entry</h1>
      <div class="slogan">Enter and update program stakeholders and candidate mobilization metrics. Saved changes are dynamically displayed on the Home dashboard.</div>
    </div>
    <div style="display:flex; gap:10px;">
      <a href="marvel_essci_mis.php" class="viewbtn" style="text-decoration:none; padding:9px 18px; font-size:13px;">View Dashboard</a>
    </div>
  </div>

  <?php if ($saved): ?>
    <div class="alert alert-success" style="border-radius:10px; margin-bottom:24px; padding:14px 18px; font-weight:600; display:flex; align-items:center; justify-content:space-between; background:#eaf8ed; border:1px solid #b7e4c7; color:#1b4332;">
      <span>✓ <b>Success:</b> ESSCI Program data has been successfully saved and updated!</span>
      <a href="marvel_essci_mis.php" style="color:#1b4332; text-decoration:underline; font-weight:700;">View on Home Dashboard &rarr;</a>
    </div>
  <?php endif; ?>

  <form id="essciDataForm" method="post" action="marvel_essci_data.php">
    <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
    <input type="hidden" name="action" value="save">

    <!-- Section 1: Program Stakeholders -->
    <div class="data-form-section">
      <div class="section-label">Program Stakeholders</div>
      <div class="sub2">Core institutional partners for the skilling initiative</div>
      
      <div class="data-grid-3">
        <div class="form-card">
          <label for="sponsor">Sponsor *</label>
          <input type="text" id="sponsor" name="sponsor" value="<?= s($sponsor) ?>" required placeholder="e.g. Marvell Semiconductor">
          <div class="input-hint">Corporate sponsor funding the skilling initiative</div>
        </div>

        <div class="form-card">
          <label for="pmo">PMO / Skill Body *</label>
          <input type="text" id="pmo" name="pmo" value="<?= s($pmo) ?>" required placeholder="e.g. ESSCI">
          <div class="input-hint">Sector Skill Council / Program Management Office</div>
        </div>

        <div class="form-card">
          <label for="training_partner">Training Partner *</label>
          <input type="text" id="training_partner" name="training_partner" value="<?= s($training_partner) ?>" required placeholder="e.g. Emertxe">
          <div class="input-hint">Executing training delivery partner organization</div>
        </div>

        <div class="form-card">
          <label for="sites">Training Sites / Delivery Centers</label>
          <input type="text" id="sites" name="sites" value="<?= s($sites) ?>" placeholder="e.g. Bangalore · Hyderabad">
          <div class="input-hint">Locations displayed in the program meta banner</div>
        </div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Section 2: Program Mobilization Metrics (List View) -->
    <div class="data-form-section">
      <div class="section-label">Program Mobilization Metrics</div>
      <div class="sub2">Cumulative candidate funnel figures for overall program (Bangalore &amp; Hyderabad combined)</div>

      <div class="tablecard" style="margin-top:14px;">
        <table class="metric-list-table">
          <thead>
            <tr>
              <th style="width:35%;">Metric Name</th>
              <th style="width:45%;">Definition / Scope</th>
              <th style="width:20%; text-align:right;">Value</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><span class="metric-name">Total Candidates Reached</span></td>
              <td><span class="metric-desc">Total applicant pool and mobilization outreach across both centers</span></td>
              <td style="text-align:right;">
                <input type="number" class="list-input" id="candidates_reached" name="candidates_reached" value="<?= (int)$candidates_reached ?>" min="0" required>
              </td>
            </tr>
            <tr>
              <td><span class="metric-name">Eligible Candidates</span></td>
              <td><span class="metric-desc">Candidates meeting academic and prerequisite screening criteria</span></td>
              <td style="text-align:right;">
                <input type="number" class="list-input" id="eligible_candidates" name="eligible_candidates" value="<?= (int)$eligible_candidates ?>" min="0" required>
              </td>
            </tr>
            <tr>
              <td><span class="metric-name">Test Participants</span></td>
              <td><span class="metric-desc">Candidates who appeared for written and technical assessment tests</span></td>
              <td style="text-align:right;">
                <input type="number" class="list-input" id="test_participants" name="test_participants" value="<?= (int)$test_participants ?>" min="0" required>
              </td>
            </tr>
            <tr>
              <td><span class="metric-name">Interview Participants</span></td>
              <td><span class="metric-desc">Shortlisted candidates who completed personal and technical interviews</span></td>
              <td style="text-align:right;">
                <input type="number" class="list-input" id="interview_participants" name="interview_participants" value="<?= (int)$interview_participants ?>" min="0" required>
              </td>
            </tr>
            <tr>
              <td><span class="metric-name">Interview Selects (Final)</span></td>
              <td><span class="metric-desc">Final offer accepts and enrolled learners for sponsored cohorts</span></td>
              <td style="text-align:right;">
                <input type="number" class="list-input" id="interview_selects" name="interview_selects" value="<?= (int)$interview_selects ?>" min="0" required>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Section 3: Program Cohort Batches Mapping -->
    <?php
      $all_bm_batches = [];
      $currently_selected_ids = [];
      if ($DB->get_manager()->table_exists('local_bm_batch')) {
          $all_bm_batches = $DB->get_records('local_bm_batch', null, 'startdate ASC, id ASC');
      }
      if ($DB->get_manager()->table_exists('local_batchanalytics_prog_batch')) {
          $pb_recs = $DB->get_records('local_batchanalytics_prog_batch', ['programid' => 1]);
          foreach ($pb_recs as $pbr) {
              $currently_selected_ids[(int)$pbr->batchid] = true;
          }
      }
      // If table has no explicit records yet, default check batches 3, 4, 12
      if (empty($currently_selected_ids)) {
          $currently_selected_ids[3] = true;
          $currently_selected_ids[4] = true;
          $currently_selected_ids[12] = true;
      }
    ?>
    <div class="data-form-section">
      <div class="section-label">Program Cohort Batches</div>
      <div class="sub2">Select which active batches from Batch Management (local_bm_batch) are assigned to this sponsored program</div>

      <div class="tablecard" style="margin-top:14px;">
        <table class="metric-list-table">
          <thead>
            <tr>
              <th style="width:10%; text-align:center;">Include</th>
              <th style="width:20%;">Batch Name / Code</th>
              <th style="width:35%;">Course Name</th>
              <th style="width:15%;">Course Badge</th>
              <th style="width:20%;">Start Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($all_bm_batches as $bitem): ?>
              <?php
                $is_chk = !empty($currently_selected_ids[(int)$bitem->id]);
                $b_start_str = $bitem->startdate > 0 ? date('d M Y', $bitem->startdate) : 'N/A';
              ?>
              <tr>
                <td style="text-align:center;">
                  <input type="checkbox" name="selected_batches[]" value="<?= (int)$bitem->id ?>" <?= $is_chk ? 'checked' : '' ?> style="width:18px; height:18px; cursor:pointer;">
                </td>
                <td><strong style="color:#0f172a;"><?= s($bitem->name) ?></strong> (ID: <?= (int)$bitem->id ?>)</td>
                <td><?= s($bitem->coursename ?: '—') ?></td>
                <td><span class="pill" style="background:#f1f5f9; color:#475569;"><?= s(strtoupper(trim($bitem->coursename ?: 'ECEP'))) ?></span></td>
                <td><?= s($b_start_str) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Actions -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:24px;">
      <a href="marvel_essci_mis.php" class="btn btn-light" style="padding:11px 22px; text-decoration:none;">Cancel &amp; Return</a>
      <button type="submit" class="btn btn-primary" style="padding:12px 32px; font-size:14px; box-shadow:0 4px 14px rgba(27,110,194,.3);">Save Program Data</button>
    </div>
  </form>
</div></div>

<?php
echo $OUTPUT->footer();
