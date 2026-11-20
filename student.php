<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Dedicated Student 360° Profile Screen for local_batchanalytics
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

global $DB, $PAGE, $OUTPUT, $USER;

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/student.php'));

if (!has_capability('local/batchanalytics:view', $context)) {
    require_capability('local/batchanalytics:view', $context);
}

$userid = required_param('id', PARAM_INT);
$batchid = optional_param('batchid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// PDF Export Action
if ($action === 'export_pdf') {
    \local_batchanalytics\pdf_report_service::export_student_profile($userid, $batchid);
    exit;
}

// Add Counseling Note Action (internal staff only)
if ($action === 'add_counseling_note') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');

    if (!has_capability('local/batchanalytics:viewcounselingnotes', $context) && !is_siteadmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    $note = required_param('note', PARAM_RAW);
    $author = fullname($USER) . ' · ' . role_get_name(reset(get_user_roles($context, $USER->id, true)) ?: (object)['name' => 'Staff'], $context);
    $id = \local_batchanalytics\counseling_notes_service::add_note($userid, $batchid, (int)$USER->id, $author, $note);

    echo json_encode([
        'success' => true,
        'note' => [
            'id' => $id,
            'author' => $author,
            'date' => userdate(time(), '%d %b %Y, %I:%M %p'),
            'note' => s($note),
        ]
    ]);
    exit;
}

$is_sponsor = \local_batchanalytics\program_service::is_sponsor_view($context, (int)$USER->id);
$profile = \local_batchanalytics\student_profile_service::get_student_profile($userid, $batchid, $context, $is_sponsor);
$h = $profile['header'];

$PAGE->set_title($h['name'] . ' – Student Profile');
$PAGE->set_heading('');

$styleurl = new moodle_url('/local/batchanalytics/styles.css');
$PAGE->requires->css($styleurl);
$dashboardstyleurl = new moodle_url('/local/batchanalytics/dashboard.css');
$PAGE->requires->css($dashboardstyleurl);

echo $OUTPUT->header();

$batch_url = (new moodle_url('/local/batchanalytics/batch.php', ['id' => $batchid]))->out(false);
$home_url = (new moodle_url('/local/batchanalytics/index.php'))->out(false);
$export_pdf_url = (new moodle_url('/local/batchanalytics/student.php', [
    'id' => $userid,
    'batchid' => $batchid,
    'action' => 'export_pdf',
]))->out(false);

?>
<div class="local-batchanalytics-wrap ba-student-page" id="ba-student-profile-wrap"
     data-userid="<?= (int)$userid ?>"
     data-batchid="<?= (int)$batchid ?>"
     data-sesskey="<?= sesskey() ?>"
     data-is-sponsor="<?= $is_sponsor ? '1' : '0' ?>">

  <!-- Breadcrumbs -->
  <div class="crumbbar">
    <span class="crumb">
      <a href="<?= s($home_url) ?>"><?= $is_sponsor ? 'Program MIS' : 'Home' ?></a>
      <span class="crumb-sep">›</span>
      <a href="<?= s($batch_url) ?>"><?= s($h['batch_code']) ?></a>
      <span class="crumb-sep">›</span>
      <b><?= s($h['name']) ?></b>
    </span>
  </div>

  <div class="shell">

    <!-- 1. Student Header Card -->
    <div class="ba-student-header-card">
      <div class="ba-student-header-left">
        <div class="ba-student-avatar-lg">
          <span class="ba-avatar-lg-initials"><?= s($h['initials']) ?></span>
        </div>
        <div class="ba-student-main-meta">
          <h1 class="ba-student-name"><?= s($h['name']) ?></h1>
          <div class="ba-student-subrow">
            <span class="ba-sid-tag">Admission ID <?= s($h['admission_id']) ?></span>
            <span class="ba-meta-dot">·</span>
            <span class="ba-batch-meta-text"><?= s($h['batch_code']) ?> · <?= s($h['course']) ?> · <?= s($h['site']) ?> · <?= s($h['mode']) ?></span>
          </div>
          <div class="ba-student-badges-row">
            <span class="ba-badge-pet"><?= s($h['pet_badge']) ?></span>
            <?php if ($h['spot_award_count'] > 0): ?>
              <span class="ba-badge-spot">★ Spot Award x<?= (int)$h['spot_award_count'] ?></span>
            <?php endif; ?>
            <span class="ba-badge-rating-neutral">MAAC: <?= s($h['maac_rating']) ?>/10</span>
            <span class="ba-badge-rating-neutral">Coach: <?= s($h['coach_rating']) ?>/5</span>
          </div>
        </div>
      </div>
      <div class="ba-student-header-right">
        <div class="ba-student-kpi-summary">
          <div class="ba-kpi-att-num"><?= s($h['attendance_pct']) ?>%</div>
          <div class="ba-kpi-att-lbl">Attendance</div>
          <div class="ba-kpi-att-sub"><?= (int)$h['projects_count'] ?> projects · <?= (int)$h['assignments_count'] ?> assignments</div>
        </div>
        <a href="<?= s($export_pdf_url) ?>" class="ba-btn-download-profile">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          Download Profile
        </a>
      </div>
    </div>

    <!-- 2. Navigation Tabs -->
    <div class="tabs ba-student-tabs">
      <button type="button" class="tab active" data-tab="basic">Basic Information</button>
      <button type="button" class="tab" data-tab="academic">Academic Background</button>
      <button type="button" class="tab" data-tab="perf">Course Performance</button>
      <?php if (!$is_sponsor): ?>
        <button type="button" class="tab" data-tab="counseling">Counseling Notes</button>
      <?php endif; ?>
      <button type="button" class="tab" data-tab="placement">Placement</button>
    </div>

    <!-- 3. Panels Container -->
    <div class="ba-student-panels">

      <!-- Panel 1: Basic Info -->
      <div id="panel-basic" class="panel active">
        <div class="ba-card-grid-hdr">
          <span>Personal & Demographic Information</span>
          <span class="ba-badge-crm-pull">CRM PULL</span>
        </div>
        <div class="ba-info-grid">
          <div class="ba-info-cell">
            <label>FIRST NAME</label>
            <div class="val"><?= s($profile['basic_info']['first_name']) ?></div>
          </div>
          <div class="ba-info-cell">
            <label>LAST NAME</label>
            <div class="val"><?= s($profile['basic_info']['last_name']) ?></div>
          </div>
          <div class="ba-info-cell">
            <label>EMAIL ID</label>
            <div class="val"><?= s($profile['basic_info']['email']) ?></div>
          </div>
          <div class="ba-info-cell">
            <label>MOBILE NUMBER</label>
            <div class="val"><?= s($profile['basic_info']['mobile']) ?></div>
          </div>
          <div class="ba-info-cell">
            <label>WHATSAPP NUMBER</label>
            <div class="val"><?= s($profile['basic_info']['whatsapp']) ?></div>
          </div>
          <div class="ba-info-cell">
            <label>HOME STATE</label>
            <div class="val"><?= s($profile['basic_info']['home_state']) ?></div>
          </div>
          <?php if (!$is_sponsor): ?>
            <div class="ba-info-cell">
              <label>PERMANENT ADDRESS</label>
              <div class="val"><?= s($profile['basic_info']['permanent_address'] ?? '—') ?></div>
            </div>
            <div class="ba-info-cell">
              <label>PIN CODE</label>
              <div class="val"><?= s($profile['basic_info']['pincode'] ?? '—') ?></div>
            </div>
            <div class="ba-info-cell">
              <label>PARENTS NAME</label>
              <div class="val"><?= s($profile['basic_info']['parents_name'] ?? '—') ?></div>
            </div>
            <div class="ba-info-cell">
              <label>PARENTS PHONE</label>
              <div class="val"><?= s($profile['basic_info']['parents_phone'] ?? '—') ?></div>
            </div>
          <?php endif; ?>
          <div class="ba-info-cell">
            <label>INSTAGRAM</label>
            <div class="val"><?= s($profile['basic_info']['instagram']) ?></div>
          </div>
          <div class="ba-info-cell">
            <label>LINKEDIN</label>
            <div class="val"><?= s($profile['basic_info']['linkedin']) ?></div>
          </div>
        </div>

        <?php if (!$is_sponsor && !empty($profile['basic_info']['has_income_certificate'])): ?>
          <div class="ba-ews-card">
            <div>
              <div class="ba-ews-title">INCOME CERTIFICATE · EWS PROOF</div>
              <div class="ba-ews-sub"><?= s($profile['basic_info']['income_certificate_status']) ?></div>
              <div class="ba-ews-hint">Uploaded from the back end · sponsors view read-only (economically-weaker-section eligibility)</div>
            </div>
            <button type="button" class="ba-btn-view-doc" onclick="alert('Income Certificate Document Viewer');">
              View Income Certificate · PDF &rsaquo;
            </button>
          </div>
        <?php endif; ?>
      </div>

      <!-- Panel 2: Academic Background -->
      <div id="panel-academic" class="panel">
        <div class="ba-academic-block">
          <div class="ba-card-grid-hdr">
            <span>School & Pre-University</span>
            <span class="ba-badge-crm-pull">CRM PULL</span>
          </div>
          <div class="ba-info-grid ba-info-grid-3">
            <div class="ba-info-cell">
              <label>CLASS X</label>
              <div class="val"><?= s($profile['academic']['school']['class_x_pct']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>CLASS XII</label>
              <div class="val"><?= s($profile['academic']['school']['class_xii_pct']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>DIPLOMA</label>
              <div class="val"><?= s($profile['academic']['school']['diploma']) ?></div>
            </div>
          </div>
        </div>

        <div class="ba-academic-block" style="margin-top: 20px;">
          <div class="ba-card-grid-hdr">
            <span>Undergraduate (B.Tech / B.E.)</span>
            <span class="ba-badge-crm-pull">CRM PULL</span>
          </div>
          <div class="ba-info-grid ba-info-grid-3">
            <div class="ba-info-cell">
              <label>SCORE</label>
              <div class="val"><?= s($profile['academic']['undergraduate']['score']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>BRANCH</label>
              <div class="val"><?= s($profile['academic']['undergraduate']['branch']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>YEAR OF PASSING</label>
              <div class="val"><?= s($profile['academic']['undergraduate']['year_of_passing']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>COLLEGE</label>
              <div class="val"><?= s($profile['academic']['undergraduate']['college']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>LOCATION</label>
              <div class="val"><?= s($profile['academic']['undergraduate']['location']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>STATE</label>
              <div class="val"><?= s($profile['academic']['undergraduate']['state']) ?></div>
            </div>
          </div>
        </div>

        <div class="ba-academic-block" style="margin-top: 20px;">
          <div class="ba-card-grid-hdr">
            <span>Postgraduate (M.Tech / M.E.)</span>
            <span class="ba-badge-crm-pull">CRM PULL</span>
          </div>
          <div class="ba-info-grid ba-info-grid-3">
            <div class="ba-info-cell">
              <label>SCORE</label>
              <div class="val"><?= s($profile['academic']['postgraduate']['score']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>BRANCH</label>
              <div class="val"><?= s($profile['academic']['postgraduate']['branch']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>YEAR</label>
              <div class="val"><?= s($profile['academic']['postgraduate']['year']) ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Panel 3: Course Performance -->
      <div id="panel-perf" class="panel">
        <div class="ba-academic-block">
          <div class="ba-card-grid-hdr">
            <span>Level 1 — Overall Track Record</span>
            <span class="ba-badge-crm-pull">LMS PULL</span>
          </div>
          <div class="ba-info-grid ba-info-grid-3">
            <div class="ba-info-cell">
              <label>ATTENDANCE</label>
              <div class="val ba-val-highlight"><?= s($profile['course_performance']['overall']['attendance_pct']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>ASSIGNMENTS COMPLETED</label>
              <div class="val"><?= s($profile['course_performance']['overall']['assignments_count']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>PROJECTS COMPLETED</label>
              <div class="val"><?= s($profile['course_performance']['overall']['projects_count']) ?></div>
            </div>
          </div>
        </div>

        <div class="ba-academic-block" style="margin-top: 24px;">
          <div class="ba-card-grid-hdr">
            <span>Level 2 — Module-wise Performance</span>
            <span class="ba-badge-crm-pull">LMS PULL</span>
          </div>
          <div class="ba-table-scroll-wrap">
            <table class="ba-matrix-table">
              <thead>
                <tr>
                  <th style="min-width: 170px; text-align: left;">METRIC</th>
                  <?php foreach ($profile['course_performance']['modules_grid']['columns'] as $col): ?>
                    <th><?= s($col) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($profile['course_performance']['modules_grid']['metrics'] as $m): ?>
                  <tr>
                    <td class="metric-name"><?= s($m['metric']) ?></td>
                    <?php foreach ($m['values'] as $v): ?>
                      <td><?= s($v) ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php if (!$is_sponsor): ?>
      <!-- Panel 4: Counseling Notes (Private to internal staff) -->
      <div id="panel-counseling" class="panel">
        <div class="ba-private-banner">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
          <span>Private — counseling notes are visible to the <b>Student Counsellor</b> & <b>Program Manager</b> only. Not shown to the student or to sponsors.</span>
        </div>

        <div class="ba-counseling-box">
          <textarea id="ba-counseling-text" placeholder="Add a counseling note..." rows="3"></textarea>
          <div class="ba-counseling-actions">
            <span class="ba-posting-as">Posting as <b><?= s(fullname($USER)) ?></b> · Student Counsellor</span>
            <button type="button" class="ba-btn-add-note" id="ba-btn-save-counseling">Add note</button>
          </div>
        </div>

        <div class="ba-notes-list" id="ba-counseling-list">
          <?php foreach ($profile['counseling_notes'] as $cn): ?>
            <div class="ba-note-card">
              <div class="ba-note-meta"><?= s($cn['author']) ?> &nbsp;·&nbsp; <?= s($cn['date']) ?></div>
              <div class="ba-note-body"><?= s($cn['note']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Panel 5: Placement -->
      <div id="panel-placement" class="panel">
        <div class="ba-placement-summary-cards">
          <div class="ba-stat-box">
            <div class="ba-stat-num"><?= (int)$profile['placement']['opportunities_given'] ?></div>
            <div class="ba-stat-lbl">Opportunities given</div>
          </div>
          <div class="ba-stat-box">
            <div class="ba-stat-num"><?= (int)$profile['placement']['interviews_selected'] ?></div>
            <div class="ba-stat-lbl">Interviews — Selected</div>
          </div>
          <div class="ba-stat-box">
            <div class="ba-stat-num text-emerald-green">
              <span class="ba-dot dot-green"></span> <?= s($profile['placement']['status']) ?>
            </div>
            <div class="ba-stat-lbl">Placement status</div>
          </div>
          <div class="ba-stat-box">
            <div class="ba-stat-num"><?= s($profile['placement']['offered_salary']) ?></div>
            <div class="ba-stat-lbl">Offered salary</div>
          </div>
        </div>

        <div class="ba-academic-block" style="margin-top: 24px;">
          <div class="ba-card-grid-hdr">
            <span>Placement Summary</span>
            <span class="ba-badge-crm-pull">BACK-END UPDATE</span>
          </div>
          <div class="ba-info-grid ba-info-grid-3">
            <div class="ba-info-cell">
              <label>TOTAL OPPORTUNITIES GIVEN</label>
              <div class="val"><?= (int)$profile['placement']['summary']['total_opportunities'] ?></div>
            </div>
            <div class="ba-info-cell">
              <label>PLACEMENT STATUS</label>
              <div class="val text-emerald-green"><?= s($profile['placement']['summary']['status']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>PLACED COMPANY</label>
              <div class="val"><b><?= s($profile['placement']['summary']['placed_company']) ?></b></div>
            </div>
            <div class="ba-info-cell">
              <label>ROLE</label>
              <div class="val"><?= s($profile['placement']['summary']['role']) ?></div>
            </div>
            <div class="ba-info-cell">
              <label>SALARY (CTC)</label>
              <div class="val"><b><?= s($profile['placement']['summary']['salary_ctc']) ?></b></div>
            </div>
            <div class="ba-info-cell">
              <label>DATE OF OFFER</label>
              <div class="val"><?= s($profile['placement']['summary']['date_of_offer']) ?></div>
            </div>
          </div>
        </div>

        <div class="ba-academic-block" style="margin-top: 24px;">
          <div class="ba-card-grid-hdr">
            <span>Opportunities — Selection Detail</span>
            <span class="ba-badge-crm-pull">BACK-END UPDATE</span>
          </div>
          <div class="ba-table-scroll-wrap">
            <table class="ba-matrix-table" style="text-align: left;">
              <thead>
                <tr>
                  <th style="min-width: 220px;">COMPANY</th>
                  <th style="min-width: 220px;">ROLE</th>
                  <th style="min-width: 140px;">STATUS</th>
                  <th style="min-width: 140px;">SALARY (CTC)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($profile['placement']['opportunities_detail'] as $op): ?>
                  <tr>
                    <td><b><?= s($op['company']) ?></b></td>
                    <td><?= s($op['role']) ?></td>
                    <td>
                      <span class="ba-placement-status-pill pill-<?= s($op['status_class']) ?>">
                        <?= s($op['status']) ?>
                      </span>
                    </td>
                    <td><?= s($op['salary_ctc']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var tabs = document.querySelectorAll('.ba-student-tabs .tab');
    var panels = document.querySelectorAll('.ba-student-panels .panel');

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            var target = this.getAttribute('data-tab');
            tabs.forEach(function(t) { t.classList.remove('active'); });
            panels.forEach(function(p) { p.classList.remove('active'); });

            this.classList.add('active');
            var activePanel = document.getElementById('panel-' + target);
            if (activePanel) activePanel.classList.add('active');
        });
    });

    var saveBtn = document.getElementById('ba-btn-save-counseling');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            var textEl = document.getElementById('ba-counseling-text');
            var note = textEl ? textEl.value.trim() : '';
            if (!note) {
                alert('Please enter a note.');
                return;
            }

            var wrap = document.getElementById('ba-student-profile-wrap');
            var uid = wrap ? wrap.getAttribute('data-userid') : 0;
            var bid = wrap ? wrap.getAttribute('data-batchid') : 0;
            var sesskey = wrap ? wrap.getAttribute('data-sesskey') : '';

            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            var formData = new FormData();
            formData.append('action', 'add_counseling_note');
            formData.append('id', uid);
            formData.append('batchid', bid);
            formData.append('sesskey', sesskey);
            formData.append('note', note);

            fetch('student.php', { method: 'POST', body: formData })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Add note';
                    if (data.success && data.note) {
                        textEl.value = '';
                        var list = document.getElementById('ba-counseling-list');
                        if (list) {
                            var card = document.createElement('div');
                            card.className = 'ba-note-card';
                            card.innerHTML = '<div class="ba-note-meta">' + data.note.author + ' &nbsp;·&nbsp; ' + data.note.date + '</div>' +
                                             '<div class="ba-note-body">' + data.note.note + '</div>';
                            list.insertBefore(card, list.firstChild);
                        }
                    } else {
                        alert(data.message || 'Failed to save note');
                    }
                })
                .catch(function(err) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Add note';
                    alert('Error saving note.');
                });
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();

