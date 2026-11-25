# Technical Documentation: `batch.php` & `module.php` Architecture & Implementation

This document provides a comprehensive, component-by-component analysis of the two primary detail controllers in the **Batch Analytics (local_batchanalytics)** plugin:
1. **`batch.php`**: The Batch Detail View (Portfolio Overview, Schedule, SS Activities, Batch Student Performance, Review Notes, CRM Integration, and Sponsor Delivery Strips).
2. **`module.php`**: The Module Detail View (Module Header & Navigation, 8-Cell Schedule Grid, Gradebook KPI Cards, Mentor Activities / Lesson Plan Delivery, Class Evidence Photo/Video Tracking, Biometric Attendance Matrix, and Module Student Performance).

---

## 1. High-Level Architectural Context & Page Hierarchy

```
                          ┌────────────────────────┐
                          │   index.php (MIS/Ops)  │
                          └───────────┬────────────┘
                                      │
                         ┌────────────┴────────────┐
                         ▼                         ▼
             ┌───────────────────────┐ ┌───────────────────────┐
             │ Internal Batch Detail │ │  Sponsor Batch Detail │
             │      (batch.php)      │ │      (batch.php)      │
             └───────────┬───────────┘ └───────────┬───────────┘
                         │                         │
                         ├─────────────────────────┤
                         ▼                         ▼
             ┌───────────────────────┐ ┌───────────────────────┐
             │ Module Detail (Ops)   │ │ Module Detail (Sponsor)│
             │     (module.php)      │ │     (module.php)      │
             └───────────┬───────────┘ └───────────┬───────────┘
                         │                         │
                         └────────────┬────────────┘
                                      ▼
                         ┌─────────────────────────┐
                         │  Student Profile View   │
                         │      (student.php)      │
                         └─────────────────────────┘
```

The plugin serves two distinct operational audiences using unified controllers:
- **Internal Operations View**: Used by System Administrators, Program Managers (PM), Soft Skills Leads (SS Lead / SSE), and Course Mentors to monitor curriculum delivery, gradebook metrics, review notes, and operational checklists.
- **Sponsor MIS View**: Used by program sponsors (e.g., **ESSCI** and **Marvell Semiconductor**) to audit syllabus delivery compliance, biometric punch timings, class/lab evidence media, and student placement readiness.

---

## 2. Shared Security, Capabilities & Access Control

Both `batch.php` and `module.php` implement strict Moodle capability checks and user role assignment verification:

### Capabilities Defined & Enforced
| Capability | Scope / Purpose |
| :--- | :--- |
| `local/batchanalytics:view` | Base capability to access the dashboard, batch, and module pages. |
| `local/batchanalytics:manage` | Administrative management: edit all review notes, override mentor assignments, upload evidence, configure settings. |
| `local/batchanalytics:editreviewnotes` | Post new review notes and edit own notes on authorized batches. |
| `local/batchanalytics:viewreviewnotes` | Read batch review notes. |
| `local/batchanalytics:viewcrmdata` | Access the CRM Data tab (masked fields). |
| `local/batchanalytics:viewfullcrmdata` | Access all CRM fields including restricted contact information. |

### Role-Scoped Permissions & Write Guards
1. **Site Administrators & Managers (`can_manage_all`)**:
   - Possess unconditional read/write access across all batches and modules.
   - Can edit any review note in history, delete evidence, and manage lesson plans.
2. **Program Managers (PM)**:
   - Scoped strictly to batches assigned to them in `local_bm_classsection.pmmanager`.
   - Authorized to add review notes and edit their own latest review note on assigned batches.
3. **Soft Skills Leads (SS Lead / SSE)**:
   - Enterprise-level scope across all batches (`util::is_user_sslead()`).
   - Authorized to add review notes on any batch and edit their own latest review note.
4. **Mentors (Class Mentors & Lab Mentors)**:
   - Scoped strictly to the specific modules where their ID appears in `local_bm_classsection.moduledata` (`primarymentor`, `secondarymentor`, `labmentor1`, `labmentor2`, `labmentor3`).
   - In `module.php`, mentors are automatically redirected or restricted to their assigned modules.
5. **Sponsors (`is_sponsor_user` / `viewmode=sponsor`)**:
   - Strictly read-only (`program_service::can_write_operations() === false`).
   - All write endpoints (add note, edit note, upload evidence, add lesson plan, toggle mentor activities) reject sponsor write requests with HTTP 403.

---

## 3. Deep Dive: `batch.php` Implementation

### 3.1. Parameter & Action Routing
`batch.php` accepts the following query and form parameters:
- `id` / `sectionid`: The primary key of the batch section in `local_bm_classsection`.
- `batchid`: Optional reference to the parent batch entity in `local_bm_batch`.
- `mode`: Delivery mode override (`Offline`, `Online`, `Blended`).
- `viewmode`: Display mode toggle (`sponsor` or `internal`).
- `action`: Triggers dedicated backend actions:
  - `action=export_batch_pdf`: Generates and streams a comprehensive batch PDF report via `\local_batchanalytics\pdf_report_service::export_batch_report()`.
  - `action=addnote`: AJAX endpoint for adding review notes.
  - `action=editnote`: AJAX endpoint for editing the latest review note.
  - `action=get_note_history`: AJAX endpoint returning the versioned edit history of a review note.

### 3.2. Data Retrieval Pipeline
1. **Class Section & Batch Resolution**:
   - Queries `local_bm_classsection` by `$id`. If not found, falls back to `$param_batchid`.
   - Resolves the linked `local_bm_batch` record for global batch settings (coursename, delivery mode, start date).
   - Scopes non-manager users via `\local_batchanalytics\util::get_section_user_assignment()`. If the user is neither assigned nor has manager capabilities, they are redirected to `index.php` with an error notice.
2. **Module Data Decoding & Schedule Calculation**:
   - Decodes the JSON stored in `local_bm_classsection.moduledata`.
   - Filters out non-applicable modules (e.g., Qt/QML is automatically excluded for Online batches per specification).
   - For mentors, filters modules to display only those assigned to them.
   - For each module row, calculates:
     - Class Mentors & Lab Mentors (with name, user object, avatar, and deduplication).
     - Planned Start & Planned End dates.
     - Actual Start & Actual End dates.
     - Schedule Delay ($\Delta$ in days) = `Actual Days - Planned Days` or `(Actual End - Planned End) / 86400`.
     - Status Chip: `In progress`, `On schedule`, `Minor delay` (+1 to +2 days for short modules, +1 to +5 days for standard modules), or `Delayed`.
3. **Overall Batch Health & Delay Aggregation**:
   - Sums delays across completed and in-progress modules.
   - Status banding:
     - $\Delta < 0$: `Early · -X days`
     - $\Delta \le 11$: `On schedule · +X days`
     - $12 \le \Delta \le 23$: `Minor slip · +X days`
     - $\Delta \ge 24$: `Delayed · +X days`
4. **Student Resolution & Gradebook Aggregation**:
   - Extracts all linked Moodle course IDs from `moduledata`.
   - Calls `\local_batchanalytics\util::get_courses_student_uids($performance_courseids)` to retrieve actively enrolled student IDs scoped strictly to student archetypes, eliminating teachers and staff.
   - Fetches Spot Awards from `nominations_sa_items` / `spotaward_nomination_items` where `status = 'closed'`.
   - Passes the student list and linked course IDs to `\local_batchanalytics\student_performance_service::build()`.
   - Computes weighted overall grades, completion percentages, attendance rates, and category scores across all linked courses.
5. **Sponsor Delivery KPI Strips**:
   - **Hours & Coverage Strip**: Calls `lesson_plan_service::get_hours_and_coverage($batch_id)` to calculate target hours, total hours delivered, theory class hours, lab hours, and modules completed.
   - **Placement Eligibility Test (PET) Strip**: Calls `pet_service::get_batch_pet_summary($batch_id)` to compute qualified student count, percentage, 1st/2nd/3rd attempt clearance, and not-appeared count.

### 3.3. UI Components & Layout Structure
```html
<div class="local-batchanalytics-wrap ba-batch-page">
  <div class="crumbbar"> ... </div>
  <div class="wrap">
    <div class="shell">
      <!-- 1. Batch Header Card -->
      <div class="bhead"> ... </div>

      <!-- 2. Sponsor Strips (If Sponsor/ESSCI) -->
      <div class="ba-summary-strip"> Hours & Coverage </div>
      <div class="ba-summary-strip"> Placement Eligibility Test </div>

      <!-- 3. Navigation Tabs -->
      <div class="tabs ba-batch-tabs"> ... </div>

      <!-- 4. Tab Panels -->
      <div class="ba-batch-panels">
        <div id="panel-sched" class="panel active"> ... </div>
        <div id="panel-ss" class="panel"> ... </div>
        <div id="panel-students" class="panel"> ... </div>
        <div id="panel-notes" class="panel"> ... </div>
        <div id="panel-crm" class="panel"> ... </div>
      </div>
    </div>
  </div>
</div>
```

### 3.4. Tabbed Panels in Detail
1. **Schedule Panel (`#panel-sched`)**:
   - Displays a comprehensive table of all curriculum modules in chronological sequence.
   - Columns: Module Name, Class Mentor, Lab Mentor, Planned Start, Planned End, Actual Start, Actual End, Delay Status Chip, and Action link.
   - Clicking a module title or the "View Module" button navigates directly to `module.php` with contextual parameters (`courseid`, `batchid`, `module`, `mode`).
2. **Soft Skills Activities Panel (`#panel-ss`)**:
   - Decodes `section->softskillsdata` using `\local_batchanalytics\util::decode_softskills_activities()`.
   - Lists all scheduled grooming, communication, and placement preparation sessions with planned dates, actual completion dates, and status pills (`Completed`, `Scheduled`, `Delayed`).
3. **Student Performance Panel (`#panel-students`)**:
   - Multi-metric table powered by `batch.js`.
   - **Banding Summary Cards**: Categorizes students into `High Performers` (≥80%), `Medium Performers` (60–79%), and `Low Performers / At Risk` (<60%). Clicking any card filters the table instantly.
   - **Grade vs Completion % Toggle**: Switches table display between academic grade scores and activity completion percentages.
   - **Sorting & Pagination**: Full client-side sort on any column header and dynamic pagination (10, 25, 50, all rows).
   - **Export**: Generates a CSV file of the currently filtered and sorted student records.
4. **Review Notes Panel (`#panel-notes`)**:
   - Internal review and counseling audit trail.
   - **Posting Form**: Shows the logged-in user's name and role (`Administrator`, `Program Manager`, `SS Lead`, or custom role).
   - **Note Card Feed**: Shows author avatar, author name, timestamp, and a `Latest` badge on the most recent entry.
   - **Inline Edit & History**:
     - Regular authorized users can edit their own note only if it is the latest note in the batch.
     - Administrators can edit any note.
     - Edited notes display an `Edited` icon button that opens an AJAX modal displaying the full version diff history.
5. **CRM Data Panel (`#panel-crm`)**:
   - Split-screen interface with a persistent filter sidebar on the left and a responsive data table on the right.
   - **Filter Controls**: Search by Student Name/ID, Placement Status (`Placed` / `Not Placed`), PET Status, MAAC Rating Range (0.0–10.0), Advanced C Mock Score Range (0–100), Year of Passing (multi-checkbox), and Home State (multi-checkbox).
   - **Data Protection**: Fields marked `restricted` in `classes/crm_fields_helper.php` are masked unless the user holds `local/batchanalytics:viewfullcrmdata`.

---

## 4. Deep Dive: `module.php` Implementation

### 4.1. Parameter & Action Routing
`module.php` accepts:
- `courseid`: Moodle course ID for the selected module.
- `batchid` / `sectionid` / `id`: Linked batch section ID.
- `module`: 1-based index of the module within the batch curriculum.
- `mode`: Delivery mode (`Offline`, `Online`, `Blended`).
- `att_date`: Selected date for filtering the Daily Biometric Register.
- `action`: Backend AJAX and export endpoints:
  - `action=saveactivity`: Saves status for Moodle course-module activities.
  - `action=save_mentor_activity`: Saves mentor checklist activity completion with pending submission validation.
  - `action=add_lesson_plan_day`: Adds a day-wise lesson plan entry.
  - `action=delete_lesson_plan_day`: Deletes a lesson plan day.
  - `action=upload_evidence`: Handles multi-file upload for class/lab photos and videos.
  - `action=delete_evidence`: Deletes individual media files or entire session albums.
  - `action=export_attendance_pdf`: Generates and downloads the biometric attendance matrix PDF report.

### 4.2. Moodle Core Enrolment & Role Query Compliance
In `module.php`, student enrolment is resolved strictly via student roles:
```php
$studentroles = $DB->get_records_select('role', "shortname = 'student' OR archetype = 'student'", null, '', 'id');
$studentroleids = !empty($studentroles) ? array_map('intval', array_keys($studentroles)) : [];

if (!empty($studentroleids)) {
    $role_users = get_role_users(
        $studentroleids,
        $coursecontext,
        false,
        'ra.id AS raid, u.id, u.idnumber, u.username, u.firstname, u.lastname, u.email'
    );
    $valid_map = \local_batchanalytics\util::get_courses_student_uids([$courseid])[$courseid] ?? [];
    if (!empty($role_users)) {
        foreach ($role_users as $userrec) {
            $uid = (int)$userrec->id;
            if (isset($valid_map[$uid]) && !isset($enrolledstudents[$uid])) {
                $enrolledstudents[$uid] = $userrec;
            }
        }
    }
}
```
> [!IMPORTANT]
> When calling Moodle core's `get_role_users()` with an array of role IDs, `$fields` must begin with `ra.id` (e.g. `'ra.id AS raid'`). This satisfies Moodle's `strpos($fields, 'ra.id') === 0` requirement, preventing developer debugging warnings while deduplicating users and validating active enrolment against `$valid_map`.

### 4.3. Module Header, Navigation & 8-Cell Schedule Grid
The module header provides continuous context and rapid curriculum navigation:
- **Title & Direct Course Link**: Clicking the module title opens the native Moodle course view in a new tab.
- **Previous / Next Navigation Buttons**: Allows switching between modules. For mentors, previous/next links are dynamically restricted to their assigned modules only.
- **Delivery Cadence Notice**: In sponsor view, displays `Delivery Cadence: 3 hrs class + 3 hrs lab per day (6 hrs/day)`.
- **8-Cell Schedule Grid**:
  1. *Class Mentor(s)*: Names, avatars, and role tags (Primary/Secondary).
  2. *Lab Mentor(s)*: Names, avatars, and lab station tags (Lab 1, Lab 2, Lab 3).
  3. *Planned Start Date*
  4. *Planned End Date*
  5. *Actual Start Date*
  6. *Actual End Date* (indicates "In progress" if ongoing)
  7. *Schedule Delay Chip*: Visual status badge indicating delay delta.
  8. *Attendance Rate*: Aggregated course attendance percentage badge (Green $\ge 80\%$, Amber $\ge 60\%$, Red $<60\%$).

### 4.4. Gradebook Category KPIs & Analytics Modal
1. **Dynamic Category KPI Cards**:
   - Inspects the gradebook categories for the course (Attendance, Assignments, Quizzes, Module Tests, Programming Exercises, Mini Projects).
   - Computes:
     - Average Raw Grade ($\%$)
     - Median Grade ($\%$)
     - Average Completion Rate ($\%$)
     - Average Final Weighted Grade ($\%$) = $\frac{\text{Grade} \times \text{Completed Items}}{\text{Total Items}}$
   - Calculates student percentile rank:
     $$\text{Percentile} = \text{round}\left(\frac{\text{Total Students} - \text{Rank}}{\text{Total Students} - 1} \times 100\right)$$
2. **Category Analytics Modal (`category_modal.js` / `module.js`)**:
   - Clicking any KPI card opens an interactive modal displaying:
     - Summary stat pills (Average Grade, Median Grade, Completion Rate).
     - Full student roster with individual scores, completed item counts, and percentile ranks.
     - Search and sorting capabilities within the modal.

### 4.5. Tabbed Panels in `module.php`

#### A. Internal Operations Mode
1. **Mentor Activities Checklist Panel (`#panel-mentor`)**:
   - Reads operational activities from `mentor_activity_service`.
   - Planned due dates are calculated offset from the module's planned start date ($+\text{duedays}$).
   - Status badges: `Upcoming`, `Due Today`, `Minor Delay`, `Delayed`, or `Completed`.
   - Checking an activity validates pending student submissions before saving completion to prevent premature sign-offs.
2. **Soft Skills Activities Panel (`#panel-ssact`)**:
   - Filters batch soft skills activities to display only those whose planned dates fall within this module's planned start and end date window.
3. **Student Performance Panel (`#panel-mstudents`)**:
   - Module-level performance gradebook with Banding Cards, Grade vs Completion toggle, column sorting, pagination, and CSV export.

#### B. Sponsor / ESSCI Mode
1. **Lesson Plan Panel (`#panel-lessonplan`)**:
   - Day-by-day syllabus delivery progression.
   - Columns: Day Number, Planned Date, Actual Date, Topic / Syllabus Coverage, Status (`Done` / `Pending`), Class Mentor Biometric Attendance, Lab Mentor Biometric Attendance, and Action.
   - **Biometric Integration**: Class and Lab mentor attendance pills (`Present`, `Absent`, `Pending`) are resolved live from `\local_batchanalytics\biometric_service::get_mentor_biometric_status()`.
   - **Add Session Modal**: Authorized staff can log new days with day number, planned/actual dates, and topic coverage.
2. **Class & Lab Evidence Panel (`#panel-evidence`)**:
   - **Weekly Compliance Tracker**: Horizontal metric cards showing weekly photo delivery compliance against targets (e.g. `Week 1: 4 / 4 ✔`, `Week 2: 2 / 4 due`).
   - **Photo & Video Gallery**: Session album cards showing media preview, session type (`Class` / `Lab`), session date, caption, and multi-file badge count (e.g. `📷 5 🎥 1`).
   - **Drag & Drop Upload Modal**: Supports multiple file selection, file size calculation, and drag-and-drop file uploads stored under Moodle file storage area `class_evidence`.
   - **Interactive Fullscreen Lightbox**:
     - High-definition image showcase and HTML5 video player with autoplay.
     - Next/Previous controls with keyboard arrow navigation (`←`, `→`, `Esc`).
     - Bottom thumbnail filmstrip for rapid multi-photo browsing.
     - Download and delete action buttons.
3. **Student Attendance Matrix Panel (`#panel-stdattendance`)**:
   - **S1–S18 Session Matrix**: Comprehensive matrix table listing all students against delivered course sessions.
   - **View Mode Switcher**:
     - *Compact View (Default)*: Shows color-coded compact badges (`P` in green, `A` in red, `L` in yellow, `—` in gray).
     - *Timings View*: Expands each cell to display biometric Check-In time ($\downarrow$ 08:58), Check-Out time ($\uparrow$ 17:02), and Total Duration (`8h 4m`).
   - **Summary Metrics**: Average Attendance rate ($86\%$) and Attendance Shortfall alert count ($<75\%$).
   - **Daily Biometric Punch Register**:
     - Internal staff view providing terminal-level punch logs for any selected session date.
     - Displays Student Name, Admission PIN, Check-In, Check-Out, Total Hours, and Day Status (`Full Day`, `Half Day`, `Late In`).
   - **Export Matrix (PDF)**: Downloads a formatted PDF attendance sheet via `action=export_attendance_pdf`.
4. **Student Performance Panel (`#panel-mstudents`)**:
   - Displays student performance scoped strictly to this module's gradebook.

---

## 5. JavaScript AMD Architecture (`batch.js` & `module.js`)

Both pages load dedicated AMD JavaScript modules compiled via `build.js`:

### `amd/src/batch.js`
- **State Initialization**: Reads JSON configurations embedded in `data-*` attributes (`data-students`, `data-performance-columns`, `data-crm-fields`, `data-is-sponsor`).
- **Tab Switching**: Preserves and toggles active panels without page reload.
- **Client-Side Filter & Sort Engine**:
  - Dynamically builds banding counts (`High`, `Medium`, `Low`).
  - Supports sorting across any column (numeric grades, completion rates, dates, text names, merit stars).
  - Handles client-side pagination with rows-per-page selection (`10`, `25`, `50`, `all`).
- **Review Notes Handlers**:
  - AJAX submission for adding notes (`action=addnote`).
  - Inline editing with cancellation and optimistic UI updates (`action=editnote`).
  - Fetches and displays version history modal (`action=get_note_history`).
- **CRM Integration Engine (`baBatchCrm`)**:
  - Handles multi-criteria filtering across 7 filter parameters.
  - Generates CSV exports on the fly from filtered data.

### `amd/src/module.js`
- **Tab Persistence**: Reads `window.location.hash` or `?tab=` to restore active tab on page refresh (e.g., staying on `#panel-evidence` after an upload).
- **Mentor Checklist Actions**:
  - Dispatches AJAX calls to `save_mentor_activity`.
  - Recomputes overdue status badges (`Due Today`, `Upcoming`, `Delayed`) dynamically without reloading the page.
- **Category Modal Controller**:
  - Listens to clicks on `.kpi[data-category-modal="1"]`.
  - Populates and displays the student percentile analytics modal.
- **Evidence Lightbox Controller**:
  - Manages session album parsing, media rendering, keyboard listener events, and filmstrip navigation.
- **Attendance Matrix Toggle**:
  - Swaps DOM display styles between `.ba-att-compact-block` and `.ba-att-timing-block` with cell width adjustments.

---

## 6. CSS & Design System Specifications

Both pages conform to the prototype design system defined in [dashboard.css](file:///c:/Users/User/Desktop/Pasupathi/D&A%20Automation/Local%20Plugins/batchanalytics/moodle-local-Batchanalytics-V2.0/dashboard.css):

1. **Universal Canvas Background**:
   - `#f8fafc !important` enforced across body and Moodle wrappers (`#page`, `#page.drawers`, `.main-inner`).
   - Removes default Moodle card borders and background tints.
2. **Container Shell (`.wrap > .shell`)**:
   - `background: #ffffff !important;`
   - `border-radius: 14px !important;`
   - `box-shadow: 0 8px 30px rgba(30, 50, 70, 0.08) !important;`
   - `border: 1px solid #e3e6ea !important;`
   - `padding: 26px 28px 32px !important;`
   - Unifies header cards, summary strips, navigation tabs, and data panels inside a cohesive white card surface.
3. **Dynamic Cache Busting**:
   - Stylesheets are registered using `filemtime(__DIR__ . '/dashboard.css')` and `filemtime(__DIR__ . '/styles.css')` to guarantee immediate asset delivery without stale browser cache issues.

---

## 7. Comparative Feature Matrix: `batch.php` vs `module.php`

| Architectural Dimension | `batch.php` | `module.php` |
| :--- | :--- | :--- |
| **Scope** | Whole batch across all curriculum modules | Single curriculum module course |
| **Primary Identifier** | `local_bm_classsection.id` | `course.id` + `local_bm_classsection.id` |
| **Header Metrics** | Batch Code, Course, Delivery Mode, Start/Tentative End, PM, SSE | Module Title, Module X of Y, Planned Days, Cadence, Mentors |
| **Delay Calculation** | Sum of module delays vs total planned duration | Single module planned vs actual days ($\Delta$) |
| **Sponsor Strips** | Hours & Coverage Strip + PET Clearance Strip | Delivery KPIs Grid + Weekly Compliance Tracker |
| **Curriculum View** | Full Module Schedule table | 8-Cell Schedule Grid + Prev/Next switcher |
| **Activities Tab** | Batch-wide Soft Skills activities list | Mentor Checklist (Ops) / Lesson Plan & Evidence (Sponsor) |
| **Attendance Matrix** | Summary rate per student | S1–S18 matrix with Check-In / Check-Out timestamps |
| **Review / Audit** | Review Notes with versioned history | Daily Biometric Punch Register by session date |
| **CRM Integration** | Full CRM lead data tab with filter sidebar | N/A (Scoped to Batch Level) |
| **PDF Reports** | Batch Overview Report (`export_batch_pdf`) | Biometric Attendance Matrix Report (`export_attendance_pdf`) |
