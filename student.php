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
 * Student Details Screen for local_batchanalytics v3.0.
 *
 * Integrates Zoho CRM Student Profile, LMS Course Performance, Spot Awards Merit, and Private Counseling Notes.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

global $DB, $PAGE, $OUTPUT, $USER, $CFG;

require_login();

$context = \context_system::instance();
$userid  = (int)$USER->id;

// Permission check: Assistant Manager, Mentor, SS Executive, SS Lead, Program Manager, Admins
if (!\local_batchanalytics\student_page_service::can_access($userid)) {
    redirect(
        new \moodle_url('/'),
        get_string('nopermissions', 'error', 'You do not have permission to view Student Details.'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// -----------------------------------------------------------------------------
// AJAX Action: Add Counseling Note
// -----------------------------------------------------------------------------
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

if ($action === 'add_counseling_note') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();
        $studentid = required_param('id', PARAM_INT);
        $note_body = required_param('note', PARAM_RAW);

        $saved_note = \local_batchanalytics\student_page_service::add_counseling_note($studentid, $userid, $note_body);
        echo json_encode(['success' => true, 'note' => $saved_note]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'update_counseling_note') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_sesskey();
        $studentid = required_param('id', PARAM_INT);
        $note_id   = required_param('note_id', PARAM_RAW);
        $note_body = required_param('note', PARAM_RAW);

        $updated_note = \local_batchanalytics\student_page_service::update_counseling_note($studentid, $userid, $note_id, $note_body);
        echo json_encode(['success' => true, 'note' => $updated_note]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// -----------------------------------------------------------------------------
// Page Parameters & Data Retrieval
// -----------------------------------------------------------------------------
$studentid = optional_param('id', 0, PARAM_INT);
if ($studentid <= 0) {
    $studentid = optional_param('userid', 0, PARAM_INT);
}
if ($studentid <= 0) {
    $studentid = optional_param('studentid', 0, PARAM_INT);
}
$batchid   = optional_param('batchid', 0, PARAM_INT);
$courseid  = optional_param('courseid', 0, PARAM_INT);
$module_idx = optional_param('module', 0, PARAM_INT);

// Fallback to first available enrolled student in section if none requested
if ($studentid <= 0 && $batchid > 0 && $DB->get_manager()->table_exists('local_bm_classsection')) {
    $sec_obj = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
    if ($sec_obj && !empty($sec_obj->moduledata)) {
        $m_list = \local_batchanalytics\util::decode_module_data($sec_obj->moduledata, true);
        foreach ($m_list as $mslot) {
            $mcid = (int)($mslot['moodlecourseid'] ?? $mslot['courseid'] ?? 0);
            if ($mcid > 0) {
                $ccontext = \context_course::instance($mcid, IGNORE_MISSING);
                if ($ccontext) {
                    $enrolled = get_enrolled_users($ccontext, '', 0, 'u.id', 'u.id ASC', 0, 1);
                    if (!empty($enrolled)) {
                        $first_s = reset($enrolled);
                        $studentid = (int)$first_s->id;
                        break;
                    }
                }
            }
        }
    }
}

if ($studentid <= 0) {
    throw new \moodle_exception('invaliduser', 'error', '', 'No valid student ID specified.');
}

$data = \local_batchanalytics\student_page_service::get_student_details($studentid, $batchid, $userid);

// Resolve module name if navigated with courseid
$module_name = '';
if ($courseid > 0) {
    if (!empty($data['batch_id']) && $DB->get_manager()->table_exists('local_bm_classsection')) {
        $sec_obj = $DB->get_record('local_bm_classsection', ['id' => $data['batch_id']]);
        if ($sec_obj && !empty($sec_obj->moduledata)) {
            $m_list = \local_batchanalytics\util::decode_module_data($sec_obj->moduledata, true);
            foreach ($m_list as $s_idx => $m_slot) {
                $scid = (int)($m_slot['moodlecourseid'] ?? $m_slot['courseid'] ?? 0);
                if ($scid === $courseid || ($module_idx > 0 && $s_idx == $module_idx)) {
                    $module_name = $m_slot['name'] ?? '';
                    if ($module_idx <= 0) {
                        $module_idx = $s_idx;
                    }
                    break;
                }
            }
        }
    }
    if (empty($module_name)) {
        $c_rec = $DB->get_record('course', ['id' => $courseid]);
        if ($c_rec) {
            $module_name = $c_rec->fullname;
        }
    }
}

// Handle PDF Download Request
if ($action === 'download_pdf') {
    \local_batchanalytics\student_pdf_service::generate_student_pdf($studentid, $batchid, $userid);
    exit;
}

$PAGE->set_context($context);
$url_params = ['id' => $studentid];
if ($batchid > 0) {
    $url_params['batchid'] = $batchid;
}
if ($courseid > 0) {
    $url_params['courseid'] = $courseid;
}
if ($module_idx > 0) {
    $url_params['module'] = $module_idx;
}
$PAGE->set_url(new \moodle_url('/local/batchanalytics/student.php', $url_params));
$PAGE->set_title($data['fullname'] . ' – Student Details');
$PAGE->set_heading('');

// Load Stylesheets: base dashboard styles + dedicated student styles with cache-busting
$dash_v = file_exists(__DIR__ . '/dashboard.css') ? filemtime(__DIR__ . '/dashboard.css') : time();
$stud_v = file_exists(__DIR__ . '/student.css') ? filemtime(__DIR__ . '/student.css') : time();
$PAGE->requires->css(new \moodle_url('/local/batchanalytics/dashboard.css', ['v' => $dash_v]));
$PAGE->requires->css(new \moodle_url('/local/batchanalytics/student.css', ['v' => $stud_v]));

// Load AMD JS Module without arguments to adhere to Moodle 1024 char limit
$PAGE->requires->js_call_amd('local_batchanalytics/student', 'init');

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
.drawers.drag-container {
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
}
.shell,
.ba-student-page .shell {
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
</style>

<div class="ba-student-page" id="ba-student-container"
     data-studentid="<?= (int)$studentid ?>"
     data-batchid="<?= (int)$batchid ?>"
     data-courseid="<?= (int)$courseid ?>"
     data-module="<?= (int)$module_idx ?>"
     data-sesskey="<?= sesskey() ?>"
     data-can-manage-notes="<?= !empty($data['can_manage_notes']) ? '1' : '0' ?>"
     data-crm-data="<?= s(json_encode($data['crm_student_data'] ?? [])) ?>">

    <script>
        window.StudentCRMData = <?= json_encode($data['crm_student_data'] ?? new stdClass()) ?>;
    </script>

    <!-- Breadcrumbs -->
    <?php
    $is_sponsor_view = \local_batchanalytics\program_service::is_sponsor_view($context, (int)$USER->id);
    $is_mis_mode = $is_sponsor_view;
    if (!$is_mis_mode && !empty($data['batch_id'])) {
        $b_check = $DB->get_record('local_bm_batch', ['id' => (int)$data['batch_id']], 'id, coursename');
        if ($b_check && !empty($b_check->coursename) && (stripos($b_check->coursename, 'essci') !== false || stripos($b_check->coursename, 'marvell') !== false)) {
            $is_mis_mode = true;
        }
    }
    $breadcrumb_url = (new \moodle_url('/local/batchanalytics/index.php', $is_mis_mode ? ['viewmode' => 'sponsor'] : []))->out(false);
    $breadcrumb_label = $is_mis_mode ? 'Program MIS' : 'Home';

    $batch_params = array_filter(['id' => $data['batch_id'] ?? 0]);
    if ($is_mis_mode) {
        $batch_params['viewmode'] = 'sponsor';
    }
    $batch_url = (new \moodle_url('/local/batchanalytics/batch.php', $batch_params))->out(false);

    $module_url = '';
    if (!empty($courseid) && !empty($module_name)) {
        $mod_params = [
            'id' => $courseid,
            'courseid' => $courseid,
            'batchid' => $data['batch_id'],
            'sectionid' => $data['batch_id'],
            'module' => $module_idx,
        ];
        if ($is_mis_mode) {
            $mod_params['viewmode'] = 'sponsor';
        }
        $module_url = (new \moodle_url('/local/batchanalytics/module.php', $mod_params))->out(false);
    }
    ?>
    <div class="wrap">
      <!-- Breadcrumbs -->
      <div class="crumbbar">
          <span class="crumb">
              <a href="<?= s($breadcrumb_url) ?>"><?= s($breadcrumb_label) ?></a>
              <span class="crumb-sep">›</span>
              <?php if (!empty($data['batch_id'])): ?>
                  <a href="<?= s($batch_url) ?>"><?= s($data['batch_code']) ?></a>
                  <span class="crumb-sep">›</span>
              <?php endif; ?>
              <?php if (!empty($module_url)): ?>
                  <a href="<?= s($module_url) ?>"><?= s($module_name) ?></a>
                  <span class="crumb-sep">›</span>
              <?php endif; ?>
              <b><?= s($data['fullname']) ?></b>
          </span>
      </div>

      <div class="shell">

    <!-- Student Profile Hero Header Card -->
    <div class="ba-profile-hero">
        <div class="ba-profile-left">
            <!-- Initials Avatar -->
            <div class="ba-avatar-circle">
                <?= s($data['initials']) ?>
            </div>

            <!-- Profile Info & Badges -->
            <div class="ba-profile-info">
                <h1 class="name"><?= s($data['fullname']) ?></h1>
                <div class="sub">
                    Admission ID <?= s($data['admission_id']) ?> &nbsp;·&nbsp;
                    Batch <?= s($data['batch_code']) ?> &nbsp;·&nbsp;
                    <?= s($data['course_name']) ?> &nbsp;·&nbsp;
                    <?= s($data['delivery_mode']) ?>
                </div>

                <div class="ba-badges-row">
                    <?php if (!empty($data['hero_badges'])): ?>
                        <?php foreach ($data['hero_badges'] as $badge): ?>
                            <span class="ba-pill <?= s($badge['class']) ?>"><?= s($badge['label']) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Side KPI & Download Button -->
        <div class="ba-profile-right">
            <div class="ba-hero-rating"><?= s($data['maac_rating']) ?></div>
            <div class="ba-hero-rating-lbl">MAAC rating</div>
            <div class="ba-hero-subkpi"><?= s($data['overall_attendance']) ?> attendance &nbsp;·&nbsp; <?= s($data['total_projects']) ?> projects</div>
            <?php
            $dl_params = ['id' => $data['student_id'], 'action' => 'download_pdf'];
            if (!empty($data['batch_id'])) {
                $dl_params['batchid'] = $data['batch_id'];
            }
            if (!empty($courseid)) {
                $dl_params['courseid'] = $courseid;
            }
            if (!empty($module_idx)) {
                $dl_params['module'] = $module_idx;
            }
            ?>
            <a href="<?= s((new \moodle_url('/local/batchanalytics/student.php', $dl_params))->out(false)) ?>"
               class="ba-btn-download"
               id="ba-btn-download-profile">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Download Profile
            </a>
        </div>
    </div>

    <!-- Tab Bar -->
    <div class="ba-tabs-bar">
        <button type="button" class="ba-tab-btn active" data-tab="basic-info">Basic Info</button>
        <button type="button" class="ba-tab-btn" data-tab="academic">Academic</button>
        <button type="button" class="ba-tab-btn" data-tab="placement-critical">Placement-Critical</button>
        <button type="button" class="ba-tab-btn" data-tab="course-performance">Course Performance</button>
        <button type="button" class="ba-tab-btn" data-tab="counseling-notes">Counseling Notes</button>
    </div>

    <!-- ==================== TAB 1: BASIC INFO (CRM PULL) ==================== -->
    <div class="ba-tab-panel active" id="panel-basic-info">
        <div class="ba-card">
            <div class="ba-card-header">
                <h2 class="title">Basic Information</h2>
            </div>

            <table class="ba-info-table">
                <tbody>
                    <tr>
                        <td>
                            <div class="field-label">FIRST NAME</div>
                            <div class="field-value"><?= s($data['basic_info']['first_name']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">LAST NAME</div>
                            <div class="field-value"><?= s($data['basic_info']['last_name']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">EMAIL ID</div>
                            <div class="field-value"><?= s($data['basic_info']['email']) ?></div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <div class="field-label">MOBILE NUMBER</div>
                            <div class="field-value"><?= s($data['basic_info']['mobile']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">WHATSAPP NUMBER</div>
                            <div class="field-value"><?= s($data['basic_info']['whatsapp']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">HOME STATE</div>
                            <div class="field-value"><?= s($data['basic_info']['home_state']) ?></div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <div class="field-label">PERMANENT ADDRESS</div>
                            <div class="field-value"><?= s($data['basic_info']['permanent_address']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">PIN CODE</div>
                            <div class="field-value"><?= s($data['basic_info']['pin_code']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">PARENTS NAME</div>
                            <div class="field-value"><?= s($data['basic_info']['parents_name']) ?></div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <div class="field-label">PARENTS PHONE</div>
                            <div class="field-value"><?= s($data['basic_info']['parents_phone']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">INSTAGRAM</div>
                            <div class="field-value"><?= s($data['basic_info']['instagram']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">LINKEDIN</div>
                            <div class="field-value"><?= s($data['basic_info']['linkedin']) ?></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==================== TAB 2: ACADEMIC (CRM PULL) ==================== -->
    <div class="ba-tab-panel" id="panel-academic">
        <!-- School & Pre-University -->
        <div class="ba-card">
            <div class="ba-card-header">
                <h2 class="title">School &amp; Pre-University</h2>
            </div>
            <table class="ba-info-table">
                <tbody>
                    <tr>
                        <td>
                            <div class="field-label">CLASS X</div>
                            <div class="field-value"><?= s($data['academic_info']['class_x']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">CLASS XII</div>
                            <div class="field-value"><?= s($data['academic_info']['class_xii']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">DIPLOMA</div>
                            <div class="field-value"><?= s($data['academic_info']['diploma']) ?></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- B.E / B.Tech -->
        <div class="ba-card">
            <div class="ba-card-header">
                <h2 class="title">B.E / B.Tech</h2>
            </div>
            <table class="ba-info-table">
                <tbody>
                    <tr>
                        <td>
                            <div class="field-label">SCORE</div>
                            <div class="field-value"><?= s($data['academic_info']['btech_score']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">BRANCH</div>
                            <div class="field-value"><?= s($data['academic_info']['btech_branch']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">YEAR OF PASSING</div>
                            <div class="field-value"><?= s($data['academic_info']['btech_yop']) ?></div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <div class="field-label">COLLEGE</div>
                            <div class="field-value"><?= s($data['academic_info']['btech_college']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">LOCATION</div>
                            <div class="field-value"><?= s($data['academic_info']['btech_location']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">STATE</div>
                            <div class="field-value"><?= s($data['academic_info']['btech_state']) ?></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- M.E / M.Tech -->
        <div class="ba-card">
            <div class="ba-card-header">
                <h2 class="title">M.E / M.Tech</h2>
            </div>
            <table class="ba-info-table">
                <tbody>
                    <tr>
                        <td>
                            <div class="field-label">SCORE</div>
                            <div class="field-value"><?= s($data['academic_info']['mtech_score']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">BRANCH</div>
                            <div class="field-value"><?= s($data['academic_info']['mtech_branch']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">YEAR</div>
                            <div class="field-value"><?= s($data['academic_info']['mtech_year']) ?></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==================== TAB 3: PLACEMENT-CRITICAL (LMS PULL) ==================== -->
    <div class="ba-tab-panel" id="panel-placement-critical">
        <!-- Placement Eligibility Test (PET) -->
        <div class="ba-card">
            <div class="ba-card-header">
                <h2 class="title">Placement Eligibility Test (PET)</h2>
            </div>
            <table class="ba-info-table">
                <tbody>
                    <tr>
                        <td>
                            <div class="field-label">PET STATUS</div>
                            <div class="field-value" style="color: <?= s($data['placement_critical']['pet_status_color'] ?? '#15803d') ?>; font-weight: 700;"><?= s($data['placement_critical']['pet_status']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">PET SCORE</div>
                            <div class="field-value"><?= s($data['placement_critical']['pet_score']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">ATTEMPT</div>
                            <div class="field-value"><?= s($data['placement_critical']['pet_attempt']) ?></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Merit — Spot Award & Power Track -->
        <div class="ba-card">
            <div class="ba-card-header">
                <h2 class="title">Merit — Spot Award &amp; Power Track</h2>
            </div>
            <div class="ba-card-body">
                <div class="ba-badges-row" style="margin-bottom: 12px;">
                    <?php if (!empty($data['placement_critical']['spot_awards'])): ?>
                        <?php foreach ($data['placement_critical']['spot_awards'] as $aw): ?>
                            <?php if (stripos($aw, 'power track') !== false || stripos($aw, 'powertrack') !== false): ?>
                                <span class="ba-pill ba-pill-purple"><?= s($aw) ?></span>
                            <?php else: ?>
                                <span class="ba-pill ba-pill-amber"><?= s($aw) ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span style="font-size: 14px; color: #64748b; font-weight: 600;">-</span>
                    <?php endif; ?>
                </div>
                <div style="font-size: 13px; color: #64748b;">
                    Merit signals accumulate across workflows and feed placement drive shortlisting.
                </div>
            </div>
        </div>

        <!-- Mock Interview Scores -->
        <div class="ba-card">
            <div class="ba-card-header">
                <h2 class="title">Mock Interview Scores</h2>
            </div>
            <table class="ba-info-table ba-info-table-4col">
                <tbody>
                    <tr>
                        <?php foreach ($data['placement_critical']['mock_scores'] as $mock): ?>
                            <td>
                                <div class="field-label"><?= s($mock['name']) ?></div>
                                <div class="field-value ba-mock-score">
                                    <?php if (!empty($mock['score_num']) && $mock['score_num'] !== '—' && $mock['score_num'] !== '-'): ?>
                                        <strong><?= s($mock['score_num']) ?></strong>/10
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==================== TAB 4: COURSE PERFORMANCE (LMS PULL) ==================== -->
    <div class="ba-tab-panel" id="panel-course-performance">
        <!-- Level 1 — Overall -->
        <div class="ba-card">
            <div class="ba-card-header">
                <h2 class="title">Level 1 — Overall</h2>
            </div>
            <table class="ba-info-table">
                <tbody>
                    <tr>
                        <td>
                            <div class="field-label">MAAC RATING</div>
                            <div class="field-value"><?= s($data['course_performance']['overall']['maac_rating']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">ATTENDANCE</div>
                            <div class="field-value"><?= s($data['course_performance']['overall']['attendance']) ?></div>
                        </td>
                        <td>
                            <div class="field-label">PROJECTS</div>
                            <div class="field-value"><?= s($data['course_performance']['overall']['projects']) ?></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Level 2 — Module-wise -->
        <div class="ba-card">
            <div class="ba-card-header">
                <h2 class="title">Level 2 — Module-wise</h2>
            </div>
            <?php if (empty($data['course_performance']['modules'])): ?>
                <div style="padding: 24px; text-align: center; color: #64748b; font-size: 14px; font-weight: 600;">
                    -
                </div>
            <?php else: ?>
                <div class="ba-table-wrap">
                    <table class="ba-matrix-table">
                        <thead>
                            <tr>
                                <th style="min-width: 180px;">METRIC</th>
                                <?php foreach ($data['course_performance']['modules'] as $mod): ?>
                                    <?php if (is_array($mod)): ?>
                                        <th class="ba-mod-th">
                                            <div class="ba-mod-code"><?= s($mod['code']) ?></div>
                                            <?php if (!empty($mod['section'])): ?>
                                                <div class="ba-mod-section">(<?= s(trim($mod['section'], '() ')) ?>)</div>
                                            <?php endif; ?>
                                        </th>
                                    <?php else: ?>
                                        <th><?= s($mod) ?></th>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['course_performance']['matrix'] as $metric_label => $row_vals): ?>
                                <tr>
                                    <td><?= s($metric_label) ?></td>
                                    <?php foreach ($row_vals as $v): ?>
                                        <td><?= s($v) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== TAB 5: COUNSELING NOTES (PRIVATE) ==================== -->
    <div class="ba-tab-panel" id="panel-counseling-notes">
        <style>
            /* Critical scoped styling for Counseling Notes (bypasses stale CSS caching) */
            #panel-counseling-notes .ba-notes-warning {
                background: #fffbeb !important;
                border: 1px solid #fef3c7 !important;
                border-radius: 8px !important;
                padding: 12px 16px !important;
                color: #92400e !important;
                font-size: 13px !important;
                font-weight: 500 !important;
                margin-bottom: 20px !important;
                display: flex !important;
                align-items: center !important;
                gap: 10px !important;
            }
            #panel-counseling-notes .ba-notes-box-header {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                margin-bottom: 12px !important;
            }
            #panel-counseling-notes .ba-notes-box-title {
                font-size: 15px !important;
                font-weight: 700 !important;
                color: #1e293b !important;
            }
            #panel-counseling-notes .ba-notes-crm-badge {
                font-size: 11px !important;
                font-weight: 600 !important;
                color: #0369a1 !important;
                background: #e0f2fe !important;
                border: 1px solid #bae6fd !important;
                padding: 3px 10px !important;
                border-radius: 12px !important;
                display: inline-flex !important;
                align-items: center !important;
                gap: 5px !important;
            }
            #panel-counseling-notes .ba-notes-feed-header {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                margin-top: 24px !important;
                margin-bottom: 16px !important;
            }
            #panel-counseling-notes .ba-notes-feed-header h3 {
                margin: 0 !important;
                font-size: 16px !important;
                font-weight: 700 !important;
                color: #1e293b !important;
            }
            #panel-counseling-notes .ba-notes-count-badge {
                font-size: 12px !important;
                font-weight: 600 !important;
                color: #64748b !important;
                background: #f1f5f9 !important;
                border: 1px solid #e2e8f0 !important;
                padding: 3px 12px !important;
                border-radius: 12px !important;
            }
            #panel-counseling-notes .ba-empty-notes {
                text-align: center !important;
                padding: 36px 24px !important;
                color: #64748b !important;
                background: #ffffff !important;
                border: 1px dashed #cbd5e1 !important;
                border-radius: 10px !important;
                margin-top: 10px !important;
            }
            #panel-counseling-notes .ba-empty-notes-icon {
                margin-bottom: 10px !important;
            }
            #panel-counseling-notes .ba-empty-notes-title {
                font-size: 15px !important;
                font-weight: 700 !important;
                color: #1e293b !important;
                margin-bottom: 4px !important;
            }
            #panel-counseling-notes .ba-empty-notes-text {
                font-size: 13px !important;
                color: #64748b !important;
                max-width: 480px !important;
                margin: 0 auto !important;
            }
        </style>


        <!-- Add Note Box (For Authorized Roles) -->
        <?php if (!empty($data['can_manage_notes'])): ?>
            <div class="ba-card ba-notes-box">
                <div class="ba-notes-box-header">
                    <span class="ba-notes-box-title">Add Counseling Note</span>
                    <span class="ba-notes-crm-badge">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Synced to Zoho CRM
                    </span>
                </div>
                <textarea class="ba-notes-textarea" id="ba-notes-input" placeholder="Type counseling notes, student observations, or intervention details here..."></textarea>
                <div class="ba-notes-footer">
                    <div class="ba-notes-author-tag">
                        Posting as <strong><?= s($data['viewer_name']) ?></strong> · <?= s($data['viewer_role']) ?>
                    </div>
                    <button type="button" class="ba-btn-add-note" id="ba-btn-add-note">
                        <span class="ba-btn-text">Add Note</span>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Past Notes Feed (Cards) -->
        <div class="ba-notes-feed-header">
            <h3>Counseling History</h3>
            <span class="ba-notes-count-badge"><?= count($data['counseling_notes']) ?> Notes</span>
        </div>

        <div id="ba-counseling-notes-feed">
            <?php if (empty($data['counseling_notes'])): ?>
                <div class="ba-card ba-empty-notes" id="ba-empty-notes">
                    <div class="ba-empty-notes-icon">
                        <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <div class="ba-empty-notes-title">No Counseling Notes Recorded</div>
                    <div class="ba-empty-notes-text">No counseling notes recorded for this student yet. Use the form above to add a counseling note.</div>
                </div>
            <?php else: ?>
                <?php foreach ($data['counseling_notes'] as $note): ?>
                    <div class="ba-card ba-note-card" id="note-card-<?= s($note['id']) ?>" data-note-id="<?= s($note['id']) ?>">
                        <div class="ba-note-card-header">
                            <div class="ba-note-meta-left">
                                <span class="ba-note-title"><?= s($note['title'] ?? 'Counseling Notes - LMS') ?></span>
                                <span class="ba-note-dot">·</span>
                                <span class="ba-note-author">By <strong><?= s($note['author']) ?></strong></span>
                                <span class="ba-note-dot">·</span>
                                <span class="ba-note-date"><?= s($note['date_str']) ?></span>
                            </div>
                            <div class="ba-note-meta-right">
                                <?php if (!empty($note['is_editable']) && !empty($data['can_manage_notes'])): ?>
                                    <span class="ba-note-badge-editable" title="Notes can be modified within 24 hours of creation">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                        <?= (int)($note['hours_left'] ?? 24) ?>h left to edit
                                    </span>
                                    <button type="button" class="ba-btn-edit-note" data-note-id="<?= s($note['id']) ?>">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        Edit
                                    </button>
                                <?php else: ?>
                                    <span class="ba-note-badge-readonly">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        Read-only
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Read view -->
                        <div class="ba-note-body-view" id="note-view-<?= s($note['id']) ?>">
                            <?= nl2br(s($note['body'])) ?>
                        </div>

                        <!-- Inline Edit form (hidden initially) -->
                        <?php if (!empty($note['is_editable']) && !empty($data['can_manage_notes'])): ?>
                            <div class="ba-note-body-edit" id="note-edit-box-<?= s($note['id']) ?>" style="display: none;">
                                <textarea class="ba-note-edit-textarea" id="note-edit-textarea-<?= s($note['id']) ?>"><?= s($note['body']) ?></textarea>
                                <div class="ba-note-edit-actions">
                                    <button type="button" class="ba-btn-cancel-edit" data-note-id="<?= s($note['id']) ?>">Cancel</button>
                                    <button type="button" class="ba-btn-save-note" data-note-id="<?= s($note['id']) ?>">Save Changes</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <!-- ==================== HIDDEN TEMPLATE FOR CLIENT-SIDE HIGH-FIDELITY PDF EXPORT ==================== -->
    <div id="ba-pdf-export-content" style="display:none;">
        <div class="ba-pdf-render-root" style="width:100%;box-sizing:border-box;padding:0 11px 0 1px;background:#fff;">
            <!-- ============================== PAGE 1 ============================== -->
            <div class="page">
                <header class="header">
                    <div class="header-left">
                        <div class="avatar"><?= s($data['initials']) ?></div>
                        <div>
                            <h1 class="name"><?= s($data['fullname']) ?></h1>
                            <p class="meta">Admission ID <?= s($data['admission_id']) ?> · Batch <?= s($data['batch_code']) ?> · <?= s($data['course_name']) ?> · <?= s($data['delivery_mode']) ?></p>
                            <div class="header-badges">
                                <?php if (!empty($data['hero_badges'])): ?>
                                    <?php foreach ($data['hero_badges'] as $b): ?>
                                        <?php
                                        $bcls = 'badge';
                                        if ($b['type'] === 'pet') {
                                            $bcls .= ' pet ' . (!empty($b['pdf_class']) ? $b['pdf_class'] : '');
                                        } else if ($b['type'] === 'power_track') {
                                            $bcls .= ' pt';
                                        }
                                        ?>
                                        <span class="<?= $bcls ?>"><?= s($b['label']) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="badge pet">PET: <?= s($data['placement_critical']['pet_status'] ?? '-') ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="header-right">
                        <div class="rating"><?= s($data['maac_rating']) ?></div>
                        <small>MAAC rating</small>
                        <small><?= s($data['overall_attendance']) ?> attendance · <?= s($data['total_projects']) ?> projects</small>
                    </div>
                </header>

                <section class="section">
                    <h2 class="section-title">Basic Info</h2>
                    <div class="card">
                        <h3 class="card-title">Basic Information</h3>
                        <div class="grid cols-3">
                            <div class="cell"><span class="label">First Name</span><span class="value"><?= s($data['basic_info']['first_name']) ?></span></div>
                            <div class="cell"><span class="label">Last Name</span><span class="value"><?= s($data['basic_info']['last_name']) ?></span></div>
                            <div class="cell"><span class="label">Email ID</span><span class="value"><?= s($data['basic_info']['email']) ?></span></div>

                            <div class="cell"><span class="label">Mobile Number</span><span class="value"><?= s($data['basic_info']['mobile']) ?></span></div>
                            <div class="cell"><span class="label">WhatsApp Number</span><span class="value"><?= s($data['basic_info']['whatsapp']) ?></span></div>
                            <div class="cell"><span class="label">Home State</span><span class="value"><?= s($data['basic_info']['home_state']) ?></span></div>

                            <div class="cell"><span class="label">Permanent Address</span><span class="value"><?= s($data['basic_info']['permanent_address']) ?></span></div>
                            <div class="cell"><span class="label">Pin Code</span><span class="value"><?= s($data['basic_info']['pin_code']) ?></span></div>
                            <div class="cell"><span class="label">Parents Name</span><span class="value"><?= s($data['basic_info']['parents_name']) ?></span></div>

                            <div class="cell"><span class="label">Parents Phone</span><span class="value"><?= s($data['basic_info']['parents_phone']) ?></span></div>
                            <div class="cell"><span class="label">Instagram</span><span class="value"><?= s($data['basic_info']['instagram']) ?></span></div>
                            <div class="cell"><span class="label">LinkedIn</span><span class="value"><?= s($data['basic_info']['linkedin']) ?></span></div>
                        </div>
                    </div>
                </section>

                <section class="section">
                    <h2 class="section-title">Academic</h2>

                    <div class="card">
                        <h3 class="card-title">School &amp; Pre-University</h3>
                        <div class="grid cols-3">
                            <div class="cell"><span class="label">Class X</span><span class="value"><?= s($data['academic_info']['class_x']) ?></span></div>
                            <div class="cell"><span class="label">Class XII</span><span class="value"><?= s($data['academic_info']['class_xii']) ?></span></div>
                            <div class="cell"><span class="label">Diploma</span><span class="value"><?= s($data['academic_info']['diploma']) ?></span></div>
                        </div>
                    </div>

                    <div class="card">
                        <h3 class="card-title">B.E / B.Tech</h3>
                        <div class="grid cols-3">
                            <div class="cell"><span class="label">Score</span><span class="value"><?= s($data['academic_info']['btech_score']) ?></span></div>
                            <div class="cell"><span class="label">Branch</span><span class="value"><?= s($data['academic_info']['btech_branch']) ?></span></div>
                            <div class="cell"><span class="label">Year of Passing</span><span class="value"><?= s($data['academic_info']['btech_yop']) ?></span></div>

                            <div class="cell"><span class="label">College</span><span class="value"><?= s($data['academic_info']['btech_college']) ?></span></div>
                            <div class="cell"><span class="label">Location</span><span class="value"><?= s($data['academic_info']['btech_location']) ?></span></div>
                            <div class="cell"><span class="label">State</span><span class="value"><?= s($data['academic_info']['btech_state']) ?></span></div>
                        </div>
                    </div>

                    <div class="card">
                        <h3 class="card-title">M.E / M.Tech</h3>
                        <div class="grid cols-3">
                            <div class="cell"><span class="label">Score</span><span class="value"><?= s($data['academic_info']['mtech_score']) ?></span></div>
                            <div class="cell"><span class="label">Branch</span><span class="value"><?= s($data['academic_info']['mtech_branch']) ?></span></div>
                            <div class="cell"><span class="label">Year</span><span class="value"><?= s($data['academic_info']['mtech_year']) ?></span></div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Page Break: Clean, empty and closed -->
            <div class="html2pdf__page-break"></div>

            <!-- ============================== PAGE 2 ============================== -->
            <div class="page">
                <section class="section">
                    <h2 class="section-title">Placement-Critical</h2>

                    <div class="card">
                        <h3 class="card-title">Placement Eligibility Test (PET)</h3>
                        <div class="grid cols-3">
                            <div class="cell"><span class="label">PET Status</span><span class="pill-status <?= s($data['placement_critical']['pet_badge_class'] ?? 'pill-green') ?>"><?= s($data['placement_critical']['pet_status']) ?></span></div>
                            <div class="cell"><span class="label">PET Score</span><span class="value"><?= s($data['placement_critical']['pet_score']) ?></span></div>
                            <div class="cell"><span class="label">Attempt</span><span class="value"><?= s($data['placement_critical']['pet_attempt']) ?></span></div>
                        </div>
                    </div>

                    <div class="card">
                        <h3 class="card-title">Merit — Spot Award &amp; Power Track</h3>
                        <div class="merit-body">
                            <div class="merit-tags">
                                <?php if (!empty($data['placement_critical']['spot_awards'])): ?>
                                    <?php foreach ($data['placement_critical']['spot_awards'] as $saw): ?>
                                        <?php if (stripos($saw, 'power track') !== false || stripos($saw, 'powertrack') !== false): ?>
                                            <span class="badge pt"><?= s($saw) ?></span>
                                        <?php else: ?>
                                            <span class="badge">★ <?= s(ltrim($saw, '★ ')) ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="font-size:13px;color:var(--muted);font-weight:600;">-</span>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>

                    <div class="card">
                        <h3 class="card-title">Mock Interview Scores</h3>
                        <div class="grid <?= !empty($data['placement_critical']['mock_scores']) && count($data['placement_critical']['mock_scores']) === 4 ? 'cols-4' : 'cols-3' ?>">
                            <?php if (!empty($data['placement_critical']['mock_scores'])): ?>
                                <?php foreach ($data['placement_critical']['mock_scores'] as $mock): ?>
                                    <div class="cell">
                                        <span class="label"><?= s($mock['name']) ?></span>
                                        <span class="value mock-score">
                                            <?php if (!empty($mock['score_num']) && $mock['score_num'] !== '-' && $mock['score_num'] !== '—'): ?>
                                                <strong style="font-weight:800;"><?= s($mock['score_num']) ?></strong>/10
                                            <?php else: ?>
                                                <?= s($mock['score']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="cell"><span class="label">Mock Scores</span><span class="value">-</span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="section">
                    <h2 class="section-title">Course Performance</h2>

                    <div class="card">
                        <h3 class="card-title">Level 1 — Overall</h3>
                        <div class="grid cols-3">
                            <div class="cell"><span class="label">MAAC Rating</span><span class="value"><?= s($data['course_performance']['overall']['maac_rating']) ?></span></div>
                            <div class="cell"><span class="label">Attendance</span><span class="value"><?= s($data['course_performance']['overall']['attendance']) ?></span></div>
                            <div class="cell"><span class="label">Projects</span><span class="value"><?= s($data['course_performance']['overall']['projects']) ?></span></div>
                        </div>
                    </div>

                    <div class="card">
                        <h3 class="card-title">Level 2 — Module-wise</h3>
                        <?php if (!empty($data['course_performance']['modules'])): ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Metric</th>
                                        <?php foreach ($data['course_performance']['modules'] as $mod): ?>
                                            <?php
                                            $mcode = is_array($mod) ? ($mod['code'] ?? '') : (string)$mod;
                                            $msec = is_array($mod) && !empty($mod['section']) ? ' (' . trim($mod['section'], '() ') . ')' : '';
                                            ?>
                                            <th><?= s($mcode . $msec) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['course_performance']['matrix'] as $metric_label => $row_vals): ?>
                                        <tr>
                                            <td><?= s($metric_label) ?></td>
                                            <?php foreach ($row_vals as $v): ?>
                                                <td><?= s($v) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div style="padding: 16px; text-align: center; color: var(--muted); font-size: 13px; font-weight: 600;">-</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </div>

    </div> <!-- .shell -->
    </div> <!-- .wrap -->

</div>

<?php
echo $OUTPUT->footer();
