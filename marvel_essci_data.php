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

// If an authorized user (admin, manager, program manager) accesses this operational page while
// previewing sponsor mode, automatically reset their session back to internal mode.
if (!empty($SESSION->ba_viewmode) && $SESSION->ba_viewmode === 'sponsor') {
    if (\local_batchanalytics\program_service::has_write_permission($context)) {
        $SESSION->ba_viewmode = 'internal';
    }
}

// Strictly restrict access to authorized write roles: programmanager, ssteam, manager, teacher/editingteacher, admin
if (!\local_batchanalytics\program_service::has_write_permission($context)) {
    throw new \moodle_exception('nopermissions', 'error', '', 'Access denied: write operations are restricted to Program Managers, SS Team, Managers, and Mentors.');
}
$PAGE->set_url(new moodle_url('/local/batchanalytics/marvel_essci_data.php'));
$PAGE->set_title('ESSCI Data Page — Program MIS');
$PAGE->set_heading('');

$layout = optional_param('layout', 'standard', PARAM_ALPHA);
if ($layout === 'embedded' || $layout === 'bare') {
    $PAGE->set_pagelayout('embedded');
} else {
    $PAGE->set_pagelayout($layout);
}

$mis_css_v = file_exists(__DIR__ . '/marvel_essci_mis.css') ? filemtime(__DIR__ . '/marvel_essci_mis.css') : '3.5.0';
$PAGE->requires->css(new moodle_url('/local/batchanalytics/marvel_essci_mis.css', ['v' => (string)$mis_css_v]));
$dash_v = file_exists(__DIR__ . '/dashboard.css') ? filemtime(__DIR__ . '/dashboard.css') : '3.5.0';
$PAGE->requires->css(new moodle_url('/local/batchanalytics/dashboard.css', ['v' => (string)$dash_v]));
$style_v = file_exists(__DIR__ . '/styles.css') ? filemtime(__DIR__ . '/styles.css') : '3.5.0';
$PAGE->requires->css(new moodle_url('/local/batchanalytics/styles.css', ['v' => (string)$style_v]));

$saved = optional_param('saved', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// Handle form submission
if ($action === 'save' && data_submitted() && confirm_sesskey()) {
    $sponsor          = required_param('sponsor', PARAM_TEXT);
    $pmo              = required_param('pmo', PARAM_TEXT);
    $training_partner = required_param('training_partner', PARAM_TEXT);
    $candidates_reached     = optional_param('candidates_reached', 0, PARAM_INT);
    $eligible_candidates    = optional_param('eligible_candidates', 0, PARAM_INT);
    $test_participants      = optional_param('test_participants', 0, PARAM_INT);
    $interview_participants = optional_param('interview_participants', 0, PARAM_INT);
    $interview_selects      = optional_param('interview_selects', 0, PARAM_INT);

    set_config('mis_sponsor', trim($sponsor), 'local_batchanalytics');
    set_config('mis_pmo', trim($pmo), 'local_batchanalytics');
    set_config('mis_training_partner', trim($training_partner), 'local_batchanalytics');

    set_config('mis_candidates_reached', $candidates_reached, 'local_batchanalytics');
    set_config('mis_eligible_candidates', $eligible_candidates, 'local_batchanalytics');
    set_config('mis_test_participants', $test_participants, 'local_batchanalytics');
    set_config('mis_interview_participants', $interview_participants, 'local_batchanalytics');
    set_config('mis_interview_selects', $interview_selects, 'local_batchanalytics');

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
.ba-essci-data-page .shell {
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
.form-card {
  background: #ffffff !important;
  border: 1px solid #e2e8f0 !important;
}
.metric-list-table th {
  background: #ffffff !important;
  border-bottom: 2px solid #e2e8f0 !important;
}
</style>

<div class="local-batchanalytics-wrap ba-essci-data-page" id="ba-data-page-container">
  <div class="wrap">
    <div class="crumbbar">
      <span class="crumb" id="crumb">
        <a href="<?= s((new moodle_url('/local/batchanalytics/index.php', ['viewmode' => 'sponsor']))->out(false)) ?>">Program MIS</a>
        <span class="crumb-sep">›</span>
        <b>ESSCI Data Page</b>
      </span>
    </div>
    <div class="shell">
  <div class="phdr" style="margin-bottom:20px; align-items:flex-start;">
    <div class="ptitle">
      <h1 class="pink">ESSCI Program Data Entry</h1>
      <div class="slogan">Enter and update program stakeholders and candidate mobilization metrics. Saved changes are dynamically displayed on the Home dashboard.</div>
    </div>
    <div style="display:flex; gap:10px;">
      <a href="<?= s((new moodle_url('/local/batchanalytics/index.php', ['viewmode' => 'sponsor']))->out(false)) ?>" class="viewbtn" style="text-decoration:none; padding:9px 18px; font-size:13px;">View Dashboard</a>
    </div>
  </div>

  <?php if ($saved): ?>
    <div class="alert alert-success" style="border-radius:10px; margin-bottom:24px; padding:14px 18px; font-weight:600; display:flex; align-items:center; justify-content:space-between; background:#eaf8ed; border:1px solid #b7e4c7; color:#1b4332;">
      <span>✓ <b>Success:</b> ESSCI Program data has been successfully saved and updated!</span>
      <a href="<?= s((new moodle_url('/local/batchanalytics/index.php', ['viewmode' => 'sponsor']))->out(false)) ?>" style="color:#1b4332; text-decoration:underline; font-weight:700;">View on Home Dashboard &rarr;</a>
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

    <!-- Actions -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:24px;">
      <a href="<?= s((new moodle_url('/local/batchanalytics/index.php', ['viewmode' => 'sponsor']))->out(false)) ?>" class="btn btn-light" style="padding:11px 22px; text-decoration:none;">Cancel &amp; Return</a>
      <button type="submit" class="btn btn-primary" style="padding:12px 32px; font-size:14px; box-shadow:0 4px 14px rgba(27,110,194,.3);">Save Program Data</button>
    </div>
  </form>
  </div> <!-- /.shell -->
  </div> <!-- /.wrap -->
</div> <!-- /.local-batchanalytics-wrap -->

<?php
echo $OUTPUT->footer();
