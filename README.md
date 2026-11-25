# Batch Analytics

`local_batchanalytics` is an enterprise-grade, high-performance Moodle local plugin purpose-built for training academies, corporate bootcamps, and higher education institutions. It delivers end-to-end cohort tracking, module schedule variance monitoring, real-time LMS Gradebook analytics, student performance banding, operational checklist workflows, biometric mentor attendance tracking, syllabus lesson plan delivery progression, weekly class/lab photo evidence galleries, asynchronous Zoho Cliq bot notifications, and Zoho CRM student placement synchronization.

**Current Release:** `2.3.00` | **Plugin Version:** `2026112304` | **Requires Moodle:** `4.4+` / `4.5+` (`2024100700`) | **PHP Support:** `8.1` – `8.4`

---

## Table of Contents

- [Key Capabilities](#key-capabilities)
- [System Architecture](#system-architecture)
- [Directory Structure](#directory-structure)
- [Frontend AMD Modules & Build Pipeline](#frontend-amd-modules--build-pipeline)
- [Core Workflows & Pages](#core-workflows--pages)
  - [1. Unified Portfolio Dashboard (index.php)](#1-unified-portfolio-dashboard-indexphp)
  - [2. Sponsor Program MIS & Mobilization Funnel](#2-sponsor-program-mis--mobilization-funnel)
  - [3. ESSCI Mobilization Data Entry (marvel_essci_data.php)](#3-essci-mobilization-data-entry-marvel_essci_dataphp)
  - [4. Cohort Analytics (batch.php)](#4-cohort-analytics-batchphp)
  - [5. Module Analytics & KPIs (module.php)](#5-module-analytics--kpis-modulephp)
  - [6. Dedicated Student Performance & Profile (student.php)](#6-dedicated-student-performance--profile-studentphp)
  - [7. Operational Activity Checklists & Biometric Attendance](#7-operational-activity-checklists--biometric-attendance)
  - [8. Notification & Escalation Engine (Zoho Cliq)](#8-notification--escalation-engine-zoho-cliq)
  - [9. Zoho CRM Placement Integration](#9-zoho-crm-placement-integration)
  - [10. Companion Dashboard Block](#10-companion-dashboard-block)
  - [11. Privacy & GDPR Compliance](#11-privacy--gdpr-compliance)
- [Database Schema (10 Core Tables)](#database-schema-10-core-tables)
- [Service Layer Deep-Dive](#service-layer-deep-dive)
- [Scheduled & Ad-hoc Background Tasks](#scheduled--ad-hoc-background-tasks)
- [Admin Settings & Configuration](#admin-settings--configuration)
- [Permissions & Capabilities](#permissions--capabilities)
- [Installation, Upgrading & Uninstallation](#installation-upgrading--uninstallation)
- [License](#license)

---

## Key Capabilities

* **Dual-Mode Portfolio Telemetry:** 
  * **Internal Operations Mode:** Tracks running vs. completed cohorts, mode distributions (Online, Offline, Hybrid), schedule variance (On-Schedule, Early, Minor Slip, Delayed), and active students.
  * **Sponsor / Program MIS Mode:** Purpose-built for external corporate sponsors (e.g. ESSCI & Marvell) with candidate mobilization funnels, program window selectors, cobranded partner badges, and cohort delivery progression.
* **Accurate Unique Learner Headcount:** Evaluates real Moodle core enrollments (`get_enrolled_users()`), excludes non-student roles (`manager`, `teacher`, `editingteacher`, `coursecreator`, mentors, SSEs) and site administrators, and deduplicates active learners across all running batches via unique User IDs.
* **Active Mentor Attribution & Circular Modal Drill-Down:** Mentors are dynamically attributed strictly from the *currently active module* of running cohorts. Clicking Class Mentors or Lab Mentors cards opens an instant live-search modal with unified circular close button styling.
* **Gradebook-First Analytics:** Directly queries Moodle's native `{grade_categories}`, `{grade_items}`, and `{grade_grades}` for 100% parity with official LMS calculations without cache desynchronization.
* **Standardized 9-Point MAAC Rating:** Normalized 9-point score computed via:
  $$\text{MAAC Rating} = \text{round}\left(\left(\frac{\text{grade}}{100}\right) \times 9, 2\right)$$
  Displayed as a fixed 9-point score with 2 decimal places (e.g. `7.45`).
* **Student Performance Banding:** Categorizes learners into Top Performers, Middle Performers, and Low Performers with deep-dive category performance modals, Spot Awards, and PT nominations.
* **Day-Wise Lesson Plan & Biometric Compliance:** Tracks syllabus delivery progression against planned dates and correlates session dates with the **ESSCI Biometric Attendance Report** for Class & Lab Mentors.
* **Evidence Gallery & Session Uploads:** Mentors upload class and lab photographs on a set cadence (e.g. twice a week). Sponsors view a high-resolution lightbox album preview as delivery evidence.
* **Dedicated Student Profile & PDF Transcripts:** Individual student page (`student.php`) featuring overall attendance percentage, module-by-module performance matrix, interactive radar competency chart, confidential counseling notes, CRM placement status, and 1-click PDF transcript export.
* **Asynchronous Non-Blocking Alerts:** Zoho Cliq alerts (upcoming T-3 deadlines, due today, overdue T+3, review notes, and stage transitions) are offloaded to background ad-hoc tasks, preventing user web request latency.
* **Zoho CRM Placement Telemetry:** Synchronizes corporate recruitment telemetry (placement status, hiring company, CTC) into student performance records with strict capability-based masking.
* **Standardized White Shell Layout & Breadcrumbs:** Uniform `.wrap > .shell` card canvas on neutral `#f8fafc` background across all views, with contextual breadcrumb trails (`Program MIS › ...` for sponsors, `Home › ...` for internal operations).

---

## System Architecture

```text
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                                 MOODLE CORE & CLIENT                                   │
└──────────────────────────────────────────┬─────────────────────────────────────────────┘
                                           │
                    ┌──────────────────────┴──────────────────────┐
                    ▼                                             ▼
        ┌───────────────────────┐                     ┌───────────────────────┐
        │   Web Controllers     │                     │  Frontend AMD Modules │
        │  ──────────────────── │                     │  ──────────────────── │
        │  • index.php          │                     │  • dashboard.min.js   │
        │  • batch.php          │ ◄─────────────────► │  • batch.min.js       │
        │  • module.php         │   AJAX Endpoints    │  • module.min.js      │
        │  • student.php        │                     │  • student.min.js     │
        │  • marvel_essci_data  │                     │  • cliq_templates.js  │
        │  • cliq_templates.php │                     │  • html2pdf / jspdf   │
        └───────────┬───────────┘                     └───────────────────────┘
                    │
                    ▼
        ┌─────────────────────────────────────────────────────────────────────┐
        │                         SERVICE LAYER (PSR-4)                       │
        │                        \local_batchanalytics\                       │
        ├──────────────────────────────────┬──────────────────────────────────┤
        │ • program_service                │ • student_page_service           │
        │ • essci_dashboard_helper         │ • student_pdf_service            │
        │ • student_performance_service    │ • student_profile_service        │
        │ • lesson_plan_service            │ • crm_fields_helper & crmapi     │
        │ • biometric_service              │ • cliq_activity_notifier         │
        │ • evidence_service               │ • batch_notes_service            │
        │ • mentor_activity_service        │ • counseling_notes_service       │
        │ • pet_service                    │ • task_service & util            │
        └──────────────────────────────────┬──────────────────────────────────┘
                                           │
                    ┌──────────────────────┴──────────────────────┐
                    ▼                                             ▼
        ┌───────────────────────┐                     ┌───────────────────────┐
        │  Background Tasks     │                     │  Database (XMLDB)     │
        │  ──────────────────── │                     │  ──────────────────── │
        │  • evaluate_activity_ │                     │  • 10 Core Tables     │
        │    due_task (Cron)    │ ◄─────────────────► │  • Moodle Gradebook   │
        │  • send_cliq_notifi-  │                     │  • Enrolments & Users │
        │    cation_task (Adhoc)│                     │  • local_bm_* batches │
        └───────────────────────┘                     └───────────────────────┘
```

---

## Directory Structure

```text
local/batchanalytics/
├── index.php             # Unified Portfolio Dashboard: Dual-mode (Internal vs. Sponsor MIS)
├── batch.php             # Cohort View: Timeline, SS activities, & collaborative review notes
├── module.php            # Module View: Gradebook KPIs, lesson plan progression, & evidence gallery
├── student.php           # Student Profile: Performance matrix, radar chart, notes, & PDF export
├── marvel_essci_mis.php  # Sponsor MIS router (redirects to index.php?viewmode=sponsor)
├── marvel_essci_data.php # ESSCI Mobilization Funnel Data Entry portal
├── cliq_templates.php    # Admin template editor for Zoho Cliq markdown notification formats
├── build.js              # Node.js Terser compiler for AMD JavaScript modules
├── package.json          # Node package definition for AMD build dependencies
├── dashboard.css         # Modern component, card, pill, modal, and shell design system
├── styles.css            # Base stylesheet (automatically loaded by Moodle)
├── student.css           # Student profile, radar chart, and printable PDF stylesheet
├── marvel_essci_mis.css  # ESSCI Mobilization Funnel & Sponsor MIS design system
├── lib.php               # Moodle navigation hooks
├── settings.php          # Admin settings tree & dedicated configuration pages
├── version.php           # Plugin version & dependency specification (2.3.00 / 2026112304)
├── amd/                  # AMD JavaScript architecture
│   ├── src/              # Unminified ES6+ source modules
│   │   ├── dashboard.js  # Portfolio dashboard multi-filter engine, mentor modal, & KPI manager
│   │   ├── batch.js      # Cohort timeline, review notes editor, and SS activity controller
│   │   ├── module.js     # Module KPI toggles, category modals, student banding, & activity checklists
│   │   ├── student.js    # Student tabs, counseling notes AJAX handler, & radar chart
│   │   ├── cliq_templates.js # Interactive Cliq notification template editor
│   │   ├── html2canvas.min.js# Bundled client canvas capture library
│   │   ├── html2pdf.bundle.min.js # Bundled client PDF generator
│   │   └── jspdf.umd.min.js  # Bundled jsPDF client library
│   └── build/            # Minified production bundles with source maps (compiled via build.js)
│       ├── dashboard.min.js
│       ├── batch.min.js
│       ├── module.min.js
│       ├── student.min.js
│       └── cliq_templates.min.js
├── classes/              # PSR-4 autoloaded service layer (\local_batchanalytics\)
│   ├── activity_tracker_service.php            # Module activity delivery tracking & VPL completion sync
│   ├── admin_setting_configcommaemails.php     # Custom multi-email admin setting
│   ├── admin_setting_crm_fields.php            # Custom admin setting for CRM field mapping
│   ├── admin_setting_mentor_activity_grouping.php # Activity grouping settings UI
│   ├── admin_setting_mentor_master_activities.php # Master checklist definition UI
│   ├── admin_setting_module_tracker_categories.php# Custom tracker categories UI
│   ├── batch_notes_service.php                 # Collaborative cohort review notes & author editing
│   ├── biometric_service.php                   # Biometric attendance punch verification for mentors
│   ├── cliq_activity_notifier.php              # Zoho Cliq bot engine & stage transition alerts
│   ├── counseling_notes_service.php            # Student counseling notes persistence & authorization
│   ├── crmapi.php                              # Zoho CRM OAuth2 REST client
│   ├── crm_fields_helper.php                   # CRM data normalization, caching, & field masking
│   ├── essci_dashboard_helper.php              # ESSCI Program MIS dashboard renderer & cohort resolution
│   ├── evidence_service.php                    # Class & lab weekly evidence photo upload management
│   ├── hook_callbacks.php                      # Moodle core hook bindings
│   ├── lesson_plan_service.php                 # Day-wise syllabus execution progression & coverage
│   ├── mentor_activity_service.php             # Mentor operational activities checklist logic
│   ├── moodledata.php                          # LMS database query abstraction utilities
│   ├── pdf_report_service.php                  # Server-side PDF export generation utilities
│   ├── pet_service.php                         # Placement Eligibility Test (PET) telemetry
│   ├── program_service.php                     # Sponsor program metadata, authorization, & funnel
│   ├── student_page_service.php                # Student profile data aggregation & course matrix
│   ├── student_pdf_service.php                 # Clean transcript PDF generator for student profiles
│   ├── student_performance_service.php         # Gradebook aggregation, MAAC rating, & metrics
│   ├── student_profile_service.php             # Student avatar, contact, and enrollment data
│   ├── task_service.php                        # Task aggregation for companion block widget
│   ├── util.php                                # Section normalization, dates, and profile helpers
│   ├── privacy/
│   │   └── provider.php                        # Complete GDPR / Privacy Subsystem implementation
│   └── task/
│       ├── evaluate_activity_due_task.php      # Scheduled task: evaluates deadlines & queues alerts
│       └── send_cliq_notification_task.php     # Ad-hoc task: asynchronous Zoho Cliq HTTP dispatcher
├── db/                   # Database schema, capabilities, & task registrations
│   ├── access.php        # Capability definitions
│   ├── caches.php        # MUC cache definitions
│   ├── hooks.php         # Core hook listeners
│   ├── install.xml       # XMLDB table schema (10 tables)
│   ├── tasks.php         # Scheduled background tasks registration
│   ├── uninstall.php     # Clean uninstall callback
│   └── upgrade.php       # Incremental database migrations
└── lang/en/              # Localized language strings
    └── local_batchanalytics.php
```

---

## Frontend AMD Modules & Build Pipeline

The plugin uses Moodle's native AMD (Asynchronous Module Definition) loader. All source code is authored in `amd/src/` and compiled to `amd/build/*.min.js` with source maps using [Terser](https://terser.org/).

### Compiling Assets

```bash
# Using Node.js directly
node build.js
```

The build script compiles five core AMD modules:
* `local_batchanalytics/dashboard` -> `amd/build/dashboard.min.js`
* `local_batchanalytics/batch` -> `amd/build/batch.min.js`
* `local_batchanalytics/module` -> `amd/build/module.min.js`
* `local_batchanalytics/student` -> `amd/build/student.min.js`
* `local_batchanalytics/cliq_templates` -> `amd/build/cliq_templates.min.js`

---

## Core Workflows & Pages

### 1. Unified Portfolio Dashboard (`index.php`)
The dashboard is unified into a single page supporting two seamless viewmodes:
* **Internal Operations Mode (`index.php`):**
  * Portfolio KPI strip: Running Batches, Online, Offline, Class Mentors, Lab Mentors, and Active Students.
  * Context-aware schedule variance metrics (Early, On Schedule, Minor Slip, Delayed).
  * Deduplicated Active Learner Headcount across all running cohorts.
  * Dynamic Active Mentors attribution with modal drill-down (`.ba-modal-btn-close-circle`).
  * Instant multi-tag filter pills, sticky table header, and Excel-compatible UTF-8 BOM CSV exports.
* **Sponsor / Program MIS Mode (`index.php?viewmode=sponsor`):**
  * Automatically activated for users with the Sponsor role or via the viewmode switcher.
  * Direct rendering via `\local_batchanalytics\essci_dashboard_helper::render()`.
  * Cobranded header badges (Emertxe, ESSCI, Marvell) with live sync indicator.
  * Multi-year program window dropdown selector.
  * Mobilization telemetry funnel cards.
  * Strict class section parity: cohorts are retrieved via `INNER JOIN {local_bm_classsection}`, guaranteeing only active cohorts with valid class sections appear.
  * Dynamic breadcrumbs rooted at **`Program MIS`**.

### 2. Sponsor Program MIS & Mobilization Funnel
* **Candidate Mobilization Funnel:** Tracks recruitment conversion across 5 standardized stages:
  1. Total Candidates Reached
  2. Eligible Candidates
  3. Test Participants
  4. Interview Participants
  5. Interview Selects (Final)
* **Dynamic Cohorts Table:** Shows Class Section, Course Name, Delivery Site (Bangalore, Hyderabad), Start Date, Current Active Module, Enrolled Count, Schedule Status badge, and 1-click View Batch navigation.

### 3. ESSCI Mobilization Data Entry (`marvel_essci_data.php`)
* Dedicated management portal for administrators, program managers, and student success leads to enter and update recruitment metrics per program window.
* Automatically resolves training sites dynamically from `local_bm_batch` location telemetry.
* Form fields for recruitment funnel numbers with immediate validation.
* Unified white card `.shell` layout on neutral `#f8fafc` canvas.

### 4. Cohort Analytics (`batch.php`)
* **Sequential Curriculum Timeline:** Visualizes modules in chronological order with planned start/end dates, actual completion timestamps, and schedule variance (delta days).
* **Delivery Site Detection:** Inspects `local_bm_batch` location column and falls back to `local_bm_classsection.classlocation`, rendering a clean blue location pill.
* **Hours & Coverage Telemetry:** Displays total planned vs. delivered hours, curriculum delivery coverage percentage, and biometric mentor attendance rates.
* **Student Success (SS) Touchpoints:** Tracks operational milestone touchpoints (e.g. attendance reconciliation, counseling sessions) assigned to the Student Success Executive (SSE).
* **Collaborative Review Notes Feed:** Log allowing Academic Mentors, Batch Managers, and SSEs to record dated notes with role-based badges. Note authors can edit their most recent note inline with full CSRF and authorization checks.

### 5. Module Analytics & KPIs (`module.php`)
* **Gradebook-First Category KPIs:** Summary cards displaying live metrics directly from LMS Gradebook:
  * **Grade Mode:** Average Final Grade across all items in that category.
  * **Completion % Mode:** Percentage of enrolled students who completed category activities.
* **Underlying Category Modals:** Clicking any KPI card opens a modal detailing category completion rate, category final grade, enrolled student breakdown table, and CSV download.
* **Day-Wise Lesson Plan Delivery:**
  * Day-wise syllabus delivery logging with planned date, actual date, status, and topic coverage.
  * Automatic verification of Class Mentor and Lab Mentor attendance from the ESSCI Biometric Attendance Report for that session date.
* **Class & Lab Photo Evidence Gallery:**
  * Mentors upload classroom and lab delivery photographs with drag-and-drop multiple file support.
  * High-resolution lightbox modal album preview for sponsor verification.
* **Student Performance Table & MAAC Rating:** Comprehensive roster displaying student avatar, ID, overall grade, attendance %, individual category grades, student band, and 9-point MAAC Rating.

### 6. Dedicated Student Performance & Profile (`student.php`)
* **Hero Profile Banner:** Student avatar, full name, email, batch code, course name, enrolled date, and CRM placement status badge.
* **Telemetry KPI Cards:** Overall attendance percentage, course completion percentage, average grade, and 9-point MAAC rating.
* **Course Performance Matrix:** Complete module-by-module matrix detailing individual module grades, attendance, and category breakdown.
* **Interactive Radar Competency Chart:** Visualizes student mastery across curriculum domains (C Programming, Data Structures, Linux Internals, Embedded Systems).
* **Confidential Counseling Notes:** Real-time counseling log for SSEs, mentors, and program managers to document student interventions.
* **1-Click PDF Transcript Export:** Generates clean, professional student evaluation transcripts using `student_pdf_service.php` / client-side jsPDF.

### 7. Operational Activity Checklists & Biometric Attendance
* Standardized operational checklists for mentors (e.g. Attendance Reconciliation, Grade Upload, Weekly Code Reviews).
* Auto-matches to courses based on curriculum keyword rules or manual selection.
* Checking an item auto-populates today's date and persists immediately via AJAX with optimistic UI updates.
* Section-isolated progress tracking via `sectionid`.
* Biometric punch verification via `biometric_service.php`.

### 8. Notification & Escalation Engine (Zoho Cliq)
* **Scheduled Evaluation Task (`evaluate_activity_due_task`):** Evaluates pending mentor and SS activities every weekday at 09:00:
  * **T-3 Advance Reminder:** 3 days before the scheduled deadline.
  * **Due Today Alert:** On the day of the deadline.
  * **T+3 Overdue Escalation:** Critical alert 3 days after an overdue deadline.
* **Stage Transition Alerts:** Triggered when a batch completes a module and transitions to the next.
* **Non-Blocking Asynchronous Queue:** Dispatched via Moodle's background ad-hoc task runner (`send_cliq_notification_task`), ensuring zero user web request stalls.
* **Per-Recipient Deduplication:** Tracked in `{local_batchanalytics_cliq_log}` to prevent duplicate alerts.
* **Template Customizer (`cliq_templates.php`):** Admin interface for customizing markdown notification message formats.

### 9. Zoho CRM Placement Integration
* Synchronizes corporate recruitment and placement data (placement status, hiring company, CTC) into student performance records.
* Fine-grained capability guarding: sensitive compensation details (`CTC`) and company names are stripped from responses unless the user holds `local/batchanalytics:viewfullcrmdata`.

### 10. Companion Dashboard Block
* Independent companion widget packaged as [`block_batchanalytics`](https://github.com/EmertxeInfoTech/Moodle-Block-Batchanalytics) (`blocks/batchanalytics`).
* Embeddable on user personal dashboards (`/my/`) to display upcoming and overdue tasks with 1-click completion.

### 11. Privacy & GDPR Compliance
* Implements Moodle's Privacy Subsystem (`classes/privacy/provider.php`) conforming to GDPR:
  * **Metadata Declaration:** Declares all 10 plugin tables containing personal data.
  * **Subject Access Requests (SAR):** Exports review notes, counseling entries, activity modifications, and Cliq notification history.
  * **Data Erasure:** Deletes personal review notes, logs, and anonymizes audit fields upon user deletion requests.

---

## Database Schema (10 Core Tables)

Defined in [`db/install.xml`](db/install.xml):

| Table Name | Description | Key Columns & Indexes |
| :--- | :--- | :--- |
| `local_batchanalytics_activity_tracker` | Module activity delivery status | `courseid`, `cmid`, `sectionid`, `completed`, `completiondate`, `modifiedby`, `timemodified`<br>Indexes: `[courseid, cmid, sectionid]` (Unique), `sectionid_ix` |
| `local_batchanalytics_batch_notes` | Collaborative cohort review notes | `batchid`, `userid`, `author`, `body`, `timecreated`<br>Indexes: `batchid_ix` |
| `local_batchanalytics_mentor_act` | Mentor operational checklist data (JSON) | `courseid`, `selectedgroup`, `activitiesdata`, `timemodified`<br>Indexes: `courseid_uix` (Unique) |
| `local_batchanalytics_cliq_log` | Notification dispatch & deduplication log | `batchid`, `courseid`, `activity_type`, `activity_key`, `stage`, `recipient_email`, `userid`, `timesent`, `date_sent`, `status`<br>Indexes: `dedup_ix`, `dedup_recipient_ix`, `recipient_email_ix`, `userid_ix` |
| `local_batchanalytics_program` | Sponsored programs metadata & funnel | `name`, `tagline`, `sponsor_name`, `pmo_name`, `partner_name`, `sites`, `program_window`, `mobilization_data`<br>Indexes: `name_ix` |
| `local_batchanalytics_lesson_plan` | Day-wise curriculum delivery progression | `batchid`, `sectionid`, `courseid`, `day_index`, `status`, `planned_date`, `actual_date`, `topic`, `timemodified`<br>Indexes: `batch_section_ix` |
| `local_batchanalytics_evidence` | Weekly class/lab delivery photo uploads | `batchid`, `sectionid`, `courseid`, `session_type`, `session_date`, `filename`, `filepath`, `caption`, `timecreated`<br>Indexes: `batch_session_ix` |
| `local_batchanalytics_bio_att` | Biometric punch attendance records | `userid`, `courseid`, `sectionid`, `session_date`, `punch_in`, `punch_out`, `status`<br>Indexes: `user_date_ix` |
| `local_batchanalytics_counseling` | Confidential student counseling notes | `userid`, `batchid`, `authorid`, `note`, `timecreated`<br>Indexes: `user_batch_ix` |
| `local_batchanalytics_pet` | Placement Eligibility Test (PET) results | `userid`, `batchid`, `test_name`, `score`, `max_score`, `percentage`, `status`, `attempt_date`<br>Indexes: `user_test_ix` |

---

## Service Layer Deep-Dive

All service classes are located in `classes/` under the `\local_batchanalytics\` namespace:

1. **`program_service.php`**: Core engine for sponsored programs. Controls viewmode switching (`sponsor` vs `internal`), authorization checks (`is_sponsor_user`, `is_sponsor_view`, `has_write_permission`, `can_write_operations`), and mobilization funnel telemetry.
2. **`essci_dashboard_helper.php`**: Coordinates the unified Marvell/ESSCI Program MIS dashboard. Performs strict `INNER JOIN {local_bm_classsection}` cohort resolution, multi-year window filtering, and cohort metrics calculation.
3. **`student_page_service.php`**: Aggregates comprehensive student telemetry for `student.php`, including enrollment dates, attendance rates, module performance matrices, counseling feeds, and CRM placement information.
4. **`student_pdf_service.php` & `pdf_report_service.php`**: Server-side and client-assisted transcript generators for formal student performance dossiers.
5. **`student_performance_service.php`**: Core Gradebook aggregation engine. Computes average category grades, completion percentages, 9-point MAAC ratings, and student performance banding.
6. **`lesson_plan_service.php`**: Manages day-wise syllabus progression logging and calculates total planned vs. delivered hours and curriculum coverage.
7. **`biometric_service.php`**: Queries mentor biometric attendance punches and matches attendance compliance against scheduled lesson plan session dates.
8. **`evidence_service.php`**: Handles multipart file uploads, storage, and retrieval for classroom and lab session evidence galleries.
9. **`counseling_notes_service.php`**: Manages confidential student counseling records with strict author and role checks.
10. **`crm_fields_helper.php` & `crmapi.php`**: Connects to Zoho CRM via OAuth2 REST endpoints, handles caching in MUC, and enforces field-level masking for sensitive compensation data (`CTC`).
11. **`cliq_activity_notifier.php`**: Composes markdown notifications for upcoming, due, overdue tasks, and stage transitions, dispatching them via background ad-hoc tasks.
12. **`task_service.php`**: Aggregates pending and overdue operational tasks across batches for the companion dashboard block widget.

---

## Scheduled & Ad-hoc Background Tasks

| Task Class | Type | Frequency | Description |
| :--- | :--- | :--- | :--- |
| `\local_batchanalytics\task\evaluate_activity_due_task` | Scheduled | Mon–Fri at 09:00 | Evaluates upcoming, due, and overdue checklist deadlines; queues Zoho Cliq alerts. |
| `\local_batchanalytics\task\send_cliq_notification_task` | Ad-hoc | On demand | Asynchronous background dispatcher for external Zoho Cliq HTTP calls, preventing web request stalls. |

---

## Admin Settings & Configuration

Navigate to **Site administration > Plugins > Local plugins > Batch Analytics**:

1. **Zoho Credential Config (`local_batchanalytics_zoho`):**
   * Client ID, Client Secret, Refresh Token, and API URLs for Zoho CRM and Zoho Cliq.
   * Zoho Cliq Bot Webhook URL, API Key, default escalation channel, and enablement toggle.
2. **CRM Field Configuration (`local_batchanalytics_crm`):**
   * CRM module API name (`Child_Admission`).
   * Field mappings for student placement status, company name, CTC, and candidate ID.
3. **Mentor Activity Config (`local_batchanalytics_mentor`):**
   * **Master Activities:** Define standardized mentor operational checklist items.
   * **Activity Grouping:** Configure course keyword auto-matching rules to bind checklists to specific curriculums.
4. **Zoho Cliq Template Editor (`local_batchanalytics_cliq_templates`):**
   * Graphical editor to customize markdown templates for T-3, Due Today, T+3, Review Note, and Stage Transition notifications.
5. **Module Tracker Configuration (`local_batchanalytics_tracker`):**
   * Define custom tracker category names and match them to Gradebook category alias patterns.
6. **Operational Roles & Task Assignments (`local_batchanalytics_roles`):**
   * Multi-select role mappings for Class Mentors, Lab Mentors, SS Executives, SS Leads, and Program Managers.
   * Dedicated email field (`PARAM_EMAIL`) for the central Student Success Team Lead.
7. **Sponsor Portal & Program MIS (`local_batchanalytics_sponsor`):**
   * Multi-select role mappings for external Sponsor representatives.
   * Delivery milestone targets and PET assessment pass benchmarks.

---

## Permissions & Capabilities

Detailed in [`docs/access.md`](docs/access.md):

| Capability | Context | Archetypes (Default) | Description |
| :--- | :--- | :--- | :--- |
| `local/batchanalytics:view` | System | Manager, Editing Teacher, Teacher | Primary gate to access Batch Analytics dashboards and reports. |
| `local/batchanalytics:viewallcourses` | System | Manager | View and search all cohorts across the institution regardless of enrollment. |
| `local/batchanalytics:viewassignedcourses` | System | Editing Teacher, Teacher | Restricts dashboard view to cohorts where the user is an assigned mentor. |
| `local/batchanalytics:viewenrolledcourses` | Course | Manager, Editing Teacher, Teacher | View a specific enrolled course through Batch Analytics. |
| `local/batchanalytics:viewreviewnotes` | System | Manager, Editing Teacher, Teacher | Permission to view cohort review notes. |
| `local/batchanalytics:editreviewnotes` | System | Manager, Editing Teacher, Teacher | Permission to post and edit cohort review notes. |
| `local/batchanalytics:viewcrmdata` | System | Manager, Editing Teacher, Teacher | Permission to view basic student CRM placement status. |
| `local/batchanalytics:viewfullcrmdata` | System | Manager | Permission to view sensitive placement details (Hiring Company, CTC). |
| `local/batchanalytics:manage` | System | Manager | Full administrative access, settings configuration, and manual overrides. |

---

## Installation, Upgrading & Uninstallation

### 1. Installation

1. **Clone Plugin Files:**
   ```bash
   git clone https://github.com/muruganantham-v/local-batchanalytics.git moodle/local/batchanalytics
   ```

2. **Install Companion Dashboard Block (Optional):**
   ```bash
   git clone https://github.com/EmertxeInfoTech/Moodle-Block-Batchanalytics.git moodle/blocks/batchanalytics
   ```

3. **Execute Database Upgrade:**
   ```bash
   php moodle/admin/cli/upgrade.php
   ```
   Or visit **Site administration > Notifications** in your browser.

4. **Compile AMD Assets (if developing):**
   ```bash
   cd moodle/local/batchanalytics
   npm install
   node build.js
   ```

5. **Purge Caches:**
   ```bash
   php moodle/admin/cli/purge_caches.php
   ```

### 2. Upgrading

1. Pull the latest code into `moodle/local/batchanalytics`.
2. Run database migrations:
   ```bash
   php moodle/admin/cli/upgrade.php
   ```
3. Rebuild AMD assets if any JavaScript source files changed:
   ```bash
   node build.js
   ```
4. Purge caches:
   ```bash
   php moodle/admin/cli/purge_caches.php
   ```

### 3. Uninstallation

When uninstalled via **Site administration > Plugins > Plugins overview**:
* Moodle core automatically drops all 10 plugin database tables declared in `install.xml`.
* Database configurations and sensitive API tokens in `mdl_config_plugins` are safely cleared.

---

## License

This plugin is licensed under the [GNU General Public License v3 or later](http://www.gnu.org/copyleft/gpl.html).  
Copyright &copy; 2026 Emertxe Information Technologies.
