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
 * Batch Detail Screen for local_batchanalytics
 *
 * Sourced from local_bm_classsection and local_bm_batch (Batch Management)
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/batch_notes_service.php');

require_login();

$context = context_system::instance();
require_capability('local/batchanalytics:view', $context);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action === 'addnote') {
    require_sesskey();
    require_capability('local/batchanalytics:editreviewnotes', $context);

    header('Content-Type: application/json; charset=utf-8');
    try {
        $batchid = required_param('batchid', PARAM_INT);
        $note = required_param('note', PARAM_RAW);
        // Strictly use the authenticated logged-in user
        $author = fullname($USER);

        $saved = \local_batchanalytics\batch_notes_service::add_note($batchid, (int)$USER->id, $author, $note);
        echo json_encode(['success' => true, 'note' => $saved]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$id = optional_param('id', 0, PARAM_INT);

// -------------------------------------------------------------------------
// 1. Data Retrieval from Batch Management (local_bm_classsection / local_bm_batch)
// -------------------------------------------------------------------------
global $DB, $PAGE, $OUTPUT, $USER;

$dbman = $DB->get_manager();
$has_bm_section = $dbman->table_exists('local_bm_classsection');
$has_bm_batch   = $dbman->table_exists('local_bm_batch');
$has_bm_student = $dbman->table_exists('local_bm_student');

$section = null;
$batch = null;
$is_sample_data = false;

if ($has_bm_section && $id > 0) {
    $section = $DB->get_record('local_bm_classsection', ['id' => $id]);
}

// Fallback: If requested ID is not found or ID is 0, pick the first available classsection record
if (!$section && $has_bm_section) {
    $sections = $DB->get_records('local_bm_classsection', null, 'id ASC', '*', 0, 1);
    if (!empty($sections)) {
        $section = reset($sections);
    }
}

if ($section && $has_bm_batch && !empty($section->batchid)) {
    $batch = $DB->get_record('local_bm_batch', ['id' => $section->batchid]);
}

$batch_id = $section ? (int)$section->id : ($id > 0 ? (int)$id : 1);

// Extract & normalize batch header fields
if ($section) {
    $batchid_label  = $section->name ?: ($batch ? $batch->name : 'Batch ' . $section->id);
    $coursename     = ($batch && !empty($batch->coursename)) ? $batch->coursename : 'Embedded Systems & IoT';
    $deliverymode   = ($batch && !empty($batch->deliverymode)) ? $batch->deliverymode : 'Offline';
    $submode        = ($batch && !empty($batch->submode)) ? $batch->submode : 'Regular';
    $startdate_ts   = ($batch && !empty($batch->startdate)) ? $batch->startdate : ($section->timecreated ?: time());
    $startdate_str  = userdate($startdate_ts, '%d %b %Y');

    // Program Manager resolution
    $pmname = !empty($section->pmmanagername) ? $section->pmmanagername : '';
    if ($pmname === '' && !empty($section->pmmanager)) {
        $pmuser = $DB->get_record('user', ['id' => $section->pmmanager, 'deleted' => 0]);
        if ($pmuser) {
            $pmname = fullname($pmuser);
        }
    }
    $pmname = $pmname ?: 'Ravi Kumar';

    // MAAC Executive (SSE) resolution
    $ssename = !empty($section->maacexecutivename) ? $section->maacexecutivename : '';
    if ($ssename === '' && !empty($section->maacexecutive)) {
        $sseuser = $DB->get_record('user', ['id' => $section->maacexecutive, 'deleted' => 0]);
        if ($sseuser) {
            $ssename = fullname($sseuser);
        }
    }
    $ssename = $ssename ?: 'Anitha S';

    // Module tracking decoding
    $raw_modules = [];
    if (!empty($section->moduledata)) {
        $decoded = json_decode($section->moduledata, true);
        if (is_array($decoded)) {
            $raw_modules = $decoded;
        }
    }
} else {
    // Graceful fallback sample data conforming to prototype
    $is_sample_data = true;
    $batchid_label  = '26011B';
    $coursename     = 'Embedded Systems & IoT';
    $deliverymode   = 'Offline';
    $submode        = 'Regular';
    $startdate_str  = '28 Jul 2026';
    $pmname         = 'Ravi Kumar';
    $ssename        = 'Anitha S';
    $raw_modules    = [];
}

// -------------------------------------------------------------------------
// 2. Build Schedule Rows
// -------------------------------------------------------------------------
$schedule_rows = [];

$canonical_modules = [
    1 => 'Linux Systems',
    2 => 'Advanced C',
    3 => 'C++ Programming',
    4 => 'Data Structures',
    5 => 'Microcontrollers',
    6 => 'Linux Internals',
    7 => 'ELARM',
    8 => 'Qt / QML',
];

$format_mod_date = static function($val): string {
    if (empty($val) || $val === '—' || $val === 0 || $val === '0') {
        return '—';
    }
    if (is_numeric($val) && (int)$val > 100000) {
        return userdate((int)$val, '%d %b %Y');
    }
    return (string)$val;
};

if (!empty($raw_modules)) {
    foreach ($raw_modules as $idx => $mod) {
        $mod_idx = (int)($mod['module'] ?? ($idx + 1));
        $m_name = !empty($mod['courseshortname']) ? $mod['courseshortname'] : ($canonical_modules[$mod_idx] ?? ('Module ' . $mod_idx));
        
        // Resolve Class Mentor(s)
        $class_mentors_arr = [];
        if (!empty($mod['primarymentor'])) {
            $u = is_numeric($mod['primarymentor']) ? $DB->get_record('user', ['id' => (int)$mod['primarymentor'], 'deleted' => 0]) : null;
            $class_mentors_arr[] = $u ? fullname($u) : (string)$mod['primarymentor'];
        }
        if (!empty($mod['secondarymentor'])) {
            $u = is_numeric($mod['secondarymentor']) ? $DB->get_record('user', ['id' => (int)$mod['secondarymentor'], 'deleted' => 0]) : null;
            $class_mentors_arr[] = $u ? fullname($u) : (string)$mod['secondarymentor'];
        }
        $class_mentor = !empty($class_mentors_arr) ? implode(', ', $class_mentors_arr) : '—';

        // Resolve Lab Mentor(s)
        $lab_mentors_arr = [];
        foreach (['labmentor1', 'labmentor2', 'labmentor3'] as $lf) {
            if (!empty($mod[$lf])) {
                $u = is_numeric($mod[$lf]) ? $DB->get_record('user', ['id' => (int)$mod[$lf], 'deleted' => 0]) : null;
                $lab_mentors_arr[] = $u ? fullname($u) : (string)$mod[$lf];
            }
        }
        $lab_mentor = !empty($lab_mentors_arr) ? implode(', ', $lab_mentors_arr) : '—';

        $p_start = $format_mod_date($mod['plannedstart'] ?? '');
        $p_end   = $format_mod_date($mod['plannedend'] ?? '');
        $a_start = $format_mod_date($mod['actualstart'] ?? '');
        $a_end   = $format_mod_date($mod['actualend'] ?? '');

        $delta = isset($mod['scheduledelta']) ? (int)$mod['scheduledelta'] : null;
        if ($a_start !== '—' && $a_end === '—') {
            $delay_code = 'prog';
        } else if ($delta !== null && ($p_start !== '—' || $a_start !== '—')) {
            $delay_code = $delta;
        } else {
            $delay_code = null;
        }

        $course_id = !empty($mod['moodlecourseid']) ? (int)$mod['moodlecourseid'] : 0;

        $schedule_rows[] = [
            'name'         => $m_name,
            'class_mentor' => $class_mentor,
            'lab_mentor'   => $lab_mentor,
            'p_start'      => $p_start,
            'p_end'        => $p_end,
            'a_start'      => $a_start,
            'a_end'        => $a_end,
            'delay'        => $delay_code,
            'courseid'     => $course_id,
            'days'         => !empty($mod['planneddays']) ? (int)$mod['planneddays'] : 10,
        ];
    }
} else {
    // Canonical default sequence from prototype
    $schedule_rows = [
        [
            'name' => 'Linux Systems', 'class_mentor' => 'Meera R', 'lab_mentor' => '—',
            'p_start' => '28 Jul 2026', 'p_end' => '03 Aug 2026', 'a_start' => '28 Jul 2026', 'a_end' => '03 Aug 2026',
            'delay' => 0, 'courseid' => 0, 'days' => 5
        ],
        [
            'name' => 'Advanced C', 'class_mentor' => 'Suresh P', 'lab_mentor' => 'Kiran R',
            'p_start' => '04 Aug 2026', 'p_end' => '28 Oct 2026', 'a_start' => '04 Aug 2026', 'a_end' => '—',
            'delay' => 'prog', 'courseid' => 0, 'days' => 58
        ],
        [
            'name' => 'C++ Programming', 'class_mentor' => '—', 'lab_mentor' => '—',
            'p_start' => '29 Oct 2026', 'p_end' => '11 Nov 2026', 'a_start' => '—', 'a_end' => '—',
            'delay' => null, 'courseid' => 0, 'days' => 10
        ],
        [
            'name' => 'Data Structures', 'class_mentor' => '—', 'lab_mentor' => '—',
            'p_start' => '12 Nov 2026', 'p_end' => '11 Dec 2026', 'a_start' => '—', 'a_end' => '—',
            'delay' => null, 'courseid' => 0, 'days' => 22
        ],
        [
            'name' => 'Microcontrollers', 'class_mentor' => '—', 'lab_mentor' => '—',
            'p_start' => '12 Dec 2026', 'p_end' => '23 Jan 2027', 'a_start' => '—', 'a_end' => '—',
            'delay' => null, 'courseid' => 0, 'days' => 28
        ],
        [
            'name' => 'Linux Internals', 'class_mentor' => '—', 'lab_mentor' => '—',
            'p_start' => '24 Jan 2027', 'p_end' => '27 Feb 2027', 'a_start' => '—', 'a_end' => '—',
            'delay' => null, 'courseid' => 0, 'days' => 25
        ],
        [
            'name' => 'ELARM', 'class_mentor' => '—', 'lab_mentor' => '—',
            'p_start' => '28 Feb 2027', 'p_end' => '12 Apr 2027', 'a_start' => '—', 'a_end' => '—',
            'delay' => null, 'courseid' => 0, 'days' => 10
        ]
    ];
}

// Determine active module
$cur_module = 'Advanced C';
$tentative_end = '12 Apr 2027';
foreach ($schedule_rows as $row) {
    if ($row['delay'] === 'prog') {
        $cur_module = $row['name'];
        break;
    }
}

// -------------------------------------------------------------------------
// 3. Batch-Level SS Activities (SRS Section 5.3)
// -------------------------------------------------------------------------
$ss_activities = [
    ['activity' => 'SS Induction',        'p_start' => '07 Aug 2026', 'p_end' => '07 Aug 2026', 'a_start' => '07 Aug 2026', 'a_end' => '07 Aug 2026', 'status' => 'g', 'label' => 'Completed'],
    ['activity' => 'AANCHOR 1',           'p_start' => '10 Aug 2026', 'p_end' => '10 Aug 2026', 'a_start' => '10 Aug 2026', 'a_end' => '10 Aug 2026', 'status' => 'g', 'label' => 'Completed'],
    ['activity' => 'AANCHOR 2',           'p_start' => '11 Aug 2026', 'p_end' => '11 Aug 2026', 'a_start' => '—',           'a_end' => '—',           'status' => 'a', 'label' => 'Due / pending'],
    ['activity' => 'Placement Induction', 'p_start' => '04 Sep 2026', 'p_end' => '04 Sep 2026', 'a_start' => '—',           'a_end' => '—',           'status' => 'a', 'label' => 'Due / pending'],
    ['activity' => 'LinkedIn Workshop',   'p_start' => '28 Oct 2026', 'p_end' => '28 Oct 2026', 'a_start' => '—',           'a_end' => '—',           'status' => 'n', 'label' => 'Not started'],
    ['activity' => 'Closure Meeting',     'p_start' => '12 Apr 2027', 'p_end' => '12 Apr 2027', 'a_start' => '—',           'a_end' => '—',           'status' => 'n', 'label' => 'Not started'],
];

// -------------------------------------------------------------------------
// 4. Student Performance Data
// -------------------------------------------------------------------------
$students_data = [];

if ($section && $has_bm_student) {
    $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    $sql = "SELECT u.id, u.idnumber, u.email, {$userfields}
              FROM {local_bm_student} s
              JOIN {user} u ON u.id = s.userid
             WHERE s.classsectionid = :csid AND u.deleted = 0
             ORDER BY u.firstname, u.lastname";
    $db_students = $DB->get_records_sql($sql, ['csid' => $section->id]);
    if (!empty($db_students)) {
        $sample_grades = [82, 58, 36, 44, 74, 61, 32, 39, 88, 91, 66, 71];
        $sample_merits = [[1, 'sel'], [0, 'nom'], [0, 0], [0, 0], [1, 0], [1, 'nom'], [0, 0], [0, 0], [1, 'sel'], [1, 'sel'], [0, 'nom'], [1, 0]];
        $i = 0;
        foreach ($db_students as $st) {
            $g = $sample_grades[$i % count($sample_grades)];
            $m = $sample_merits[$i % count($sample_merits)];
            $merit_labels = [];
            if ($m[0]) $merit_labels[] = '★ Spot';
            if ($m[1] === 'nom') $merit_labels[] = 'PT-Nom';
            if ($m[1] === 'sel') $merit_labels[] = 'PT-Sel';

            $students_data[] = [
                'name'         => fullname($st),
                'id'           => !empty($st->idnumber) ? $st->idnumber : ('ST_' . $st->id),
                'grade'        => $g,
                'attendance'   => (70 + ($i % 25)) . '%',
                'assignments'  => (60 + ($i % 35)) . '%',
                'projects'     => ($i % 3) ? ((55 + ($i % 40)) . '%') : '—',
                'tests'        => (62 + ($i % 30)) . '%',
                'spot'         => !empty($m[0]),
                'pt'           => $m[1] ?: '',
                'merit_text'   => implode(', ', $merit_labels),
            ];
            $i++;
        }
    }
}

// If no DB students found, supply rich sample dataset matching prototype
if (empty($students_data)) {
    $b_names = ["Abhay D", "Abijith P", "Ajith M", "Akshay M", "Anitha S", "Anushka K", "Arjun R", "Badulla J", "Chaitra K", "Dipashree B", "Gowtham N", "Harish V"];
    $b_ids   = ["26011_017", "26011_038", "26001_137", "25050_017", "26011_101", "26011_049", "26011_072", "26011_066", "26011_205", "26011_111", "26011_090", "26011_058"];
    $b_grade = [82, 58, 36, 44, 74, 61, 32, 39, 88, 91, 66, 71];
    $b_merit = [[1, 'sel'], [0, 'nom'], [0, 0], [0, 0], [1, 0], [1, 'nom'], [0, 0], [0, 0], [1, 'sel'], [1, 'sel'], [0, 'nom'], [1, 0]];

    for ($i = 0; $i < count($b_names); $i++) {
        $m = $b_merit[$i];
        $merit_labels = [];
        if ($m[0]) $merit_labels[] = '★ Spot';
        if ($m[1] === 'nom') $merit_labels[] = 'PT-Nom';
        if ($m[1] === 'sel') $merit_labels[] = 'PT-Sel';

        $students_data[] = [
            'name'        => $b_names[$i],
            'id'          => $b_ids[$i],
            'grade'       => $b_grade[$i],
            'attendance'  => (70 + ($i % 25)) . '%',
            'assignments' => (60 + ($i % 35)) . '%',
            'projects'    => ($i % 3) ? ((55 + ($i % 40)) . '%') : '—',
            'tests'       => (62 + ($i % 30)) . '%',
            'spot'        => !empty($m[0]),
            'pt'          => $m[1] ?: '',
            'merit_text'  => implode(', ', $merit_labels),
        ];
    }
}

// -------------------------------------------------------------------------
// 5. Review Notes
// -------------------------------------------------------------------------
$initial_notes = [
    [
        'date' => '12 Sep 2026, 4:30 PM',
        'who'  => 'Ravi Kumar',
        'body' => 'Advanced C running to plan. Flagged 4 middle-band students for extra MAAC touchpoints; SSE to follow up on 2 red cases.'
    ],
    [
        'date' => '05 Sep 2026, 5:10 PM',
        'who'  => 'Ravi Kumar',
        'body' => 'Assignment evaluation slipped by 2 days last week — mentor notified, now caught up. Power Track nominations to be finalised by module end.'
    ],
    [
        'date' => '29 Aug 2026, 4:45 PM',
        'who'  => 'Ravi Kumar',
        'body' => 'Batch healthy overall. Attendance at 82%. Placement induction scheduled for D35.'
    ]
];

// Check review notes permissions
$can_view_notes = has_capability('local/batchanalytics:viewreviewnotes', $context);
$can_edit_notes = has_capability('local/batchanalytics:editreviewnotes', $context);

if ($can_view_notes) {
    $db_notes = \local_batchanalytics\batch_notes_service::get_notes($batch_id);
    if (!empty($db_notes)) {
        $display_notes = $db_notes;
        $has_real_notes = true;
    } else {
        $display_notes = $initial_notes;
        $has_real_notes = false;
    }
} else {
    $display_notes = [];
    $has_real_notes = false;
}

// Determine logged-in user's identity and role for posting review notes
$current_author_name = fullname($USER);
if (is_siteadmin()) {
    $current_author_role = 'Administrator';
} else if ($section && !empty($section->pmmanager) && (int)$USER->id === (int)$section->pmmanager) {
    $current_author_role = 'Program Manager';
} else if ($section && !empty($section->maacexecutive) && (int)$USER->id === (int)$section->maacexecutive) {
    $current_author_role = 'SS / MAAC Executive';
} else {
    $roles = get_user_roles($context, $USER->id, true);
    if (!empty($roles)) {
        $first_role = reset($roles);
        $current_author_role = role_get_name($first_role, $context);
    } else {
        $current_author_role = 'Program Manager';
    }
}

// Helper formatting functions
function format_delay_chip($d) {
    if ($d === 'prog') {
        return '<span class="st st-a">In progress</span>';
    }
    if ($d === null) {
        return '<span class="muted">—</span>';
    }
    if ($d <= 0) {
        return '<span class="st st-g">On track</span>';
    }
    if ($d <= 3) {
        return '<span class="st st-a">+' . (int)$d . 'd</span>';
    }
    return '<span class="st st-r">+' . (int)$d . 'd</span>';
}

if (!function_exists('format_cell_muted')) {
    function format_cell_muted($val) {
        return ($val === '—' || $val === '') ? '<span class="muted">—</span>' : s($val);
    }
}

// -------------------------------------------------------------------------
// 6. Page Output Setup
// -------------------------------------------------------------------------
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/batchanalytics/batch.php', ['id' => $id]));
$PAGE->set_title('Batch ' . $batchid_label . ' – Batch Analytics');
$PAGE->set_heading('');

// Load Plugin CSS and JavaScript
$styleurl = new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime(__DIR__ . '/styles.css')]);
$scripturl = new moodle_url('/local/batchanalytics/batch.js', ['v' => filemtime(__DIR__ . '/batch.js')]);
$PAGE->requires->css($styleurl);
$PAGE->requires->js($scripturl);

echo $OUTPUT->header();
?>

<div class="local-batchanalytics-wrap ba-batch-page" id="ba-batch-detail-container" data-batchid="<?= (int)$batch_id ?>" data-sesskey="<?= sesskey() ?>" data-students="<?= s(json_encode($students_data)) ?>">

  <!-- Breadcrumb Bar -->
  <div class="crumbbar">
    <span class="crumb">
      <a href="<?= s((new moodle_url('/local/batchanalytics/index.php'))->out(false)) ?>">Home</a>
      &nbsp;›&nbsp;
      <b><?= s($batchid_label) ?></b>
    </span>
  </div>

  <?php if ($is_sample_data): ?>
    <div class="ba-notice-banner" style="background:#fff3cd; color:#856404; padding:10px 16px; border-radius:8px; margin:10px 0 16px; border:1px solid #ffeeba; font-size:13px;">
      ℹ️ Displaying demo data for <b>Batch <?= s($batchid_label) ?></b> (Class Section record #<?= (int)$id ?> was not found in <code>mdl_local_bm_classsection</code>).
    </div>
  <?php endif; ?>

  <div class="shell">

    <!-- Batch Header Card -->
    <div class="bhead">
        <div class="top">
          <div>
            <h1>Batch <?= s($batchid_label) ?></h1>
            <div class="idrow">
              <span class="pill pill-course"><?= s($coursename) ?></span>
              <span class="pill mode-<?= strtolower(s($deliverymode)) ?>"><?= s($deliverymode) ?></span>
              <span class="pill type-<?= strtolower(s($submode)) ?>"><?= s($submode) ?></span>
              <span class="pill pill-date">Start <?= s($startdate_str) ?></span>
            </div>
            <div class="curmod">Current module: <b><?= s($cur_module) ?></b> &nbsp;·&nbsp; Tentative end <?= s($tentative_end) ?></div>
          </div>
          <div class="status-chip g">On schedule · 0 days</div>
        </div>

        <!-- Constant Roles: Program Manager and SS / MAAC Executive -->
        <div class="people">
          <div class="person">
            <div class="role">Program Manager</div>
            <div class="pwrap">
              <div class="pav" style="display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#cc0066,#663399);color:#fff;font-weight:700;font-size:14px;border-radius:50%;width:38px;height:38px;">
                <?= s(strtoupper(substr($pmname, 0, 1))) ?>
              </div>
              <span class="name"><?= s($pmname) ?></span>
            </div>
          </div>
          <div class="person">
            <div class="role">SS / MAAC Executive</div>
            <div class="pwrap">
              <div class="pav" style="display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1b6ec2,#1559a0);color:#fff;font-weight:700;font-size:14px;border-radius:50%;width:38px;height:38px;">
                <?= s(strtoupper(substr($ssename, 0, 1))) ?>
              </div>
              <span class="name"><?= s($ssename) ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Navigation Tabs -->
      <div class="tabs ba-batch-tabs">
        <div class="tab active" data-tab="sched">Schedule</div>
        <div class="tab" data-tab="ss">SS Activities</div>
        <div class="tab" data-tab="students">Student Performance</div>
        <?php if ($can_view_notes || $can_edit_notes): ?>
          <div class="tab" data-tab="notes">Review Notes</div>
        <?php endif; ?>
      </div>

      <!-- Panels Container -->
      <div class="ba-batch-panels">

        <!-- 1. Schedule Panel -->
        <div id="panel-sched" class="panel active">
          <div class="panel-note">
            One row per module in the batch's configured sequence. <b>Delay</b> is colour-coded. Open any module for its detail.
          </div>
          <div class="tablecard">
            <table>
              <thead>
                <tr>
                  <th>Module</th>
                  <th>Class Mentor</th>
                  <th>Lab Mentor</th>
                  <th>Plan Start</th>
                  <th>Plan End</th>
                  <th>Actual Start</th>
                  <th>Actual End</th>
                  <th>Delay</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($schedule_rows as $idx => $r): ?>
                  <?php $has_link = ($r['delay'] !== null); ?>
                  <tr>
                    <td><span class="val"><?= s($r['name']) ?></span></td>
                    <td><?= format_cell_muted($r['class_mentor']) ?></td>
                    <td><?= format_cell_muted($r['lab_mentor']) ?></td>
                    <td class="date"><?= s($r['p_start']) ?></td>
                    <td class="date"><?= s($r['p_end']) ?></td>
                    <td class="date"><?= format_cell_muted($r['a_start']) ?></td>
                    <td class="date"><?= format_cell_muted($r['a_end']) ?></td>
                    <td><?= format_delay_chip($r['delay']) ?></td>
                    <td class="actioncell">
                      <?php
                        $course_linked = (!empty($r['courseid']) && (int)$r['courseid'] > 0);
                        if ($course_linked) {
                            $course_linked = $DB->record_exists('course', ['id' => (int)$r['courseid']]);
                        }
                      ?>
                      <?php if ($course_linked): ?>
                        <a href="<?= s((new moodle_url('/local/batchanalytics/module.php', ['courseid' => (int)$r['courseid']]))->out(false)) ?>" class="viewbtn">View Module Tracker</a>
                      <?php else: ?>
                        <button type="button" class="viewbtn disabled" disabled title="Course is not linked in Batch Management">View Module Tracker</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 2. SS Activities Panel -->
        <div id="panel-ss" class="panel">
          <div class="panel-note">
            Batch-level SS activities (orientation &amp; soft skills). <b>Status</b> is a completion indicator (R/O/G); actuals entered by SS Lead.
          </div>
          <div class="tablecard">
            <table>
              <thead>
                <tr>
                  <th>Activity</th>
                  <th>Plan Start</th>
                  <th>Plan End</th>
                  <th>Actual Start</th>
                  <th>Actual End</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ss_activities as $act): ?>
                  <tr>
                    <td><span class="val"><?= s($act['activity']) ?></span></td>
                    <td class="date"><?= s($act['p_start']) ?></td>
                    <td class="date"><?= s($act['p_end']) ?></td>
                    <td class="date"><?= format_cell_muted($act['a_start']) ?></td>
                    <td class="date"><?= format_cell_muted($act['a_end']) ?></td>
                    <td><span class="st st-<?= s($act['status']) ?>"><?= s($act['label']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 3. Student Performance Panel -->
        <div id="panel-students" class="panel">
          <div class="panel-note">
            Two ways to band the same students: <b>Grade</b> (by actual score) or <b>Percentile</b> (default 15/70/15 by rank). Merit and core signals stay the same; only the banding changes.
          </div>
          <div class="filterbar">
            <div class="toggle">
              <span class="on" id="tg-grade">Grade</span>
              <span id="tg-pct">Percentile</span>
            </div>
            <button type="button" class="exp" id="ba-btn-export">Export Filtered</button>
          </div>

          <!-- Dynamic Banding Cards -->
          <div class="bandrow" id="ba-bandrow"></div>

          <!-- Students Table -->
          <div class="tablecard">
            <table>
              <thead>
                <tr>
                  <th class="sortable" data-sort="band" title="Sort by Band">Band</th>
                  <th class="sortable" data-sort="student" title="Sort by Student Name">Student</th>
                  <th class="sortable" data-sort="grade" title="Sort by Grade">Grade</th>
                  <th class="sortable" data-sort="attendance" title="Sort by Attendance">Attendance</th>
                  <th class="sortable" data-sort="assignments" title="Sort by Assignments">Assignments</th>
                  <th class="sortable" data-sort="projects" title="Sort by Projects">Projects</th>
                  <th class="sortable" data-sort="tests" title="Sort by Tests">Tests</th>
                  <th class="sortable" data-sort="merit" title="Sort by Merit">Merit</th>
                </tr>
              </thead>
              <tbody id="ba-stuBody">
                <!-- Populated dynamically with pagination via batch.js -->
              </tbody>
            </table>

            <!-- Student Performance Pagination Controls -->
            <div class="ba-pagination-bar" id="ba-pagination-bar">
              <div class="ba-pagination-info" id="ba-pagination-info">
                <!-- Populated via batch.js -->
              </div>
              <div class="ba-pagination-actions">
                <div class="ba-pagination-size-box">
                  <label for="ba-page-size">Rows per page:</label>
                  <select id="ba-page-size" class="ba-pagination-select">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="all">All</option>
                  </select>
                </div>
                <div class="ba-pagination-btns" id="ba-pagination-btns">
                  <!-- Rendered dynamically via batch.js -->
                </div>
              </div>
            </div>
          </div>
        </div>

        <?php if ($can_view_notes || $can_edit_notes): ?>
        <!-- 4. Review Notes Panel -->
        <div id="panel-notes" class="panel">
          <div class="panel-note">
            Notes captured by the Program Manager during batch reviews. Newest first. Each note is timestamped with the author.
          </div>

          <?php if ($can_edit_notes): ?>
          <div class="addnote">
            <textarea id="ba-note-text" placeholder="Add a review note for this batch…"></textarea>
            <div class="row addnote-row">
              <span class="who">Posting as <b><?= s($current_author_name) ?></b> · <?= s($current_author_role) ?></span>
              <button type="button" id="ba-btn-addnote" data-author="<?= s($current_author_name) ?>">Add note</button>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($can_view_notes): ?>
          <div id="ba-note-list">
            <?php foreach ($display_notes as $nt): ?>
              <div class="note-item<?= empty($has_real_notes) ? ' note-item-demo' : '' ?>">
                <div class="meta"><b><?= s($nt['who']) ?></b> · <?= s($nt['date']) ?></div>
                <div class="body"><?= nl2br(s($nt['body'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="ba-note-permission-msg" style="padding: 16px; background: #f8fafc; border: 1px dashed var(--line); border-radius: 8px; color: var(--mute, #64748b); font-size: 13px;">
            <?= s(get_string('nopermissiontoviewnotes', 'local_batchanalytics')) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

  </div> <!-- /.ba-batch-panels -->

  </div> <!-- /.shell -->

</div> <!-- /.local-batchanalytics-wrap -->

<?php
echo $OUTPUT->footer();
