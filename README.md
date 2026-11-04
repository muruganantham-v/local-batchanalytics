# Batch Analytics

`local_batchanalytics` is an enterprise-grade, high-performance Moodle local plugin purpose-built for training academies, corporate bootcamps, and higher education institutions. It delivers end-to-end cohort tracking, module schedule variance monitoring, real-time LMS Gradebook analytics, student performance banding, operational checklist workflows, asynchronous Zoho Cliq bot notifications, and Zoho CRM student placement synchronization.

**Current Release:** `2.2.38` | **Plugin Version:** `2026110303` | **Requires Moodle:** `4.4+` / `4.5+` (`2024100700`) | **PHP Support:** `8.1` – `8.4`

---

## Table of Contents

- [Key Capabilities](#key-capabilities)
- [System Architecture](#system-architecture)
- [Frontend AMD Modules & Build Pipeline](#frontend-amd-modules--build-pipeline)
- [Core Workflows & Pages](#core-workflows--pages)
  - [1. Portfolio Dashboard (index.php)](#1-portfolio-dashboard-indexphp)
  - [2. Cohort Analytics (batch.php)](#2-cohort-analytics-batchphp)
  - [3. Module Analytics & KPIs (module.php)](#3-module-analytics--kpis-modulephp)
  - [4. Operational Activity Checklists](#4-operational-activity-checklists)
  - [5. Notification & Escalation Engine (Zoho Cliq)](#5-notification--escalation-engine-zoho-cliq)
  - [6. Collaborative Review Notes](#6-collaborative-review-notes)
  - [7. Zoho CRM Integration](#7-zoho-crm-integration)
  - [8. Companion Dashboard Block](#8-companion-dashboard-block)
  - [9. Privacy & GDPR Compliance](#9-privacy--gdpr-compliance)
- [Database Schema](#database-schema)
- [Scheduled & Ad-hoc Background Tasks](#scheduled--ad-hoc-background-tasks)
- [Admin Settings & Configuration](#admin-settings--configuration)
- [Permissions & Capabilities](#permissions--capabilities)
- [Installation, Upgrading & Uninstallation](#installation-upgrading--uninstallation)
- [License](#license)

---

## Key Capabilities

* **Portfolio Telemetry & Schedule Health:** Live tracking of running vs. completed cohorts, mode distributions (Online, Offline, Hybrid), and curriculum schedule health (On-Schedule, Early, Minor Slip, Delayed).
* **Accurate Unique Learner Headcount:** Evaluates real Moodle core enrollments (`get_enrolled_users()`), excludes non-student roles (`manager`, `teacher`, `editingteacher`, `coursecreator`, mentors) and site administrators, and deduplicates active learners across all running batches via unique User IDs.
* **Active Mentor Attribution:** Mentors are dynamically attributed and counted strictly from the *currently active module* of active cohorts, accompanied by an instant live-search modal drill-down.
* **Gradebook-First Analytics:** Directly queries Moodle's native `{grade_categories}`, `{grade_items}`, and `{grade_grades}` for 100% parity with official LMS calculations without cache desynchronization.
* **Module KPI Toggles:** Category summary cards support instant switching between average **Final Grade** and item **Completion %** modes.
* **Standardized 9-Point MAAC Rating:** Normalized 9-point score computed via:
  $$\text{MAAC Rating} = \text{round}\left(\left(\frac{\text{grade}}{100}\right) \times 9, 2\right)$$
  Displayed as a fixed 9-point score with 2 decimal places (e.g. `7.45`).
* **Student Performance Banding:** Categorizes learners into Top Performers, Middle Performers, and Low Performers with deep-dive category performance modals, Spot Awards, and PT nominations.
* **Operational Checklists:** Standardized checklists for Mentors and Student Success (SS) executives with keyword-based auto-matching, real-time AJAX persistence, and optimistic UI updates.
* **Asynchronous Non-Blocking Alerts:** Zoho Cliq alerts (upcoming T-3 deadlines, due today, overdue T+3, review notes, and stage transitions) are offloaded to background ad-hoc tasks, preventing user web request latency.
* **Zoho CRM Placement Telemetry:** Synchronizes corporate recruitment telemetry (placement status, hiring company, CTC) into student performance records with strict capability-based masking.
* **GDPR Privacy API Compliance:** Full implementation of Moodle Privacy Subsystem (`provider.php`) supporting Subject Access Request (SAR) data export and complete user data erasure.

---

## System Architecture

```text
local/batchanalytics/
├── index.php             # Portfolio Dashboard: Stats, batch schedule table, mentor modal, & CSV export
├── batch.php             # Cohort View: Module timeline schedule, SS touchpoints, & review notes feed
├── module.php            # Module View: Module KPIs, category drill-down modals, checklists, & performance
├── cliq_templates.php    # Admin template customizer for Zoho Cliq markdown notification formats
├── build.js              # Node.js Terser build script for compiling AMD JavaScript modules
├── package.json          # Node package definition for AMD build dependencies
├── dashboard.css         # Modern, high-density responsive styles & UI components
├── styles.css            # Base stylesheet (automatically loaded by Moodle)
├── lib.php               # Moodle navigation hooks
├── settings.php          # Admin settings category & dedicated configuration pages
├── version.php           # Plugin version & dependency specification
├── amd/                  # Asynchronous Module Definition (AMD) JavaScript architecture
│   ├── src/              # Unminified ES6+ source modules
│   │   ├── dashboard.js  # Portfolio dashboard multi-filter engine, mentor modal, & KPI manager
│   │   ├── batch.js      # Cohort timeline, review notes editor, and SS activity controller
│   │   ├── module.js     # Module KPI toggles, category modals, student banding, & activity checklists
│   │   └── cliq_templates.js # Interactive Cliq notification template editor
│   └── build/            # Minified production bundles with source maps (compiled via build.js)
│       ├── dashboard.min.js
│       ├── dashboard.min.js.map
│       ├── batch.min.js
│       ├── batch.min.js.map
│       ├── module.min.js
│       ├── module.min.js.map
│       ├── cliq_templates.min.js
│       └── cliq_templates.min.js.map
├── classes/              # PSR-4 autoloaded service layer (\local_batchanalytics\)
│   ├── activity_tracker_service.php            # Module activity delivery tracking & VPL completion sync
│   ├── admin_setting_crm_fields.php            # Custom admin setting for CRM field mapping
│   ├── admin_setting_mentor_activity_grouping.php # Activity grouping settings UI
│   ├── admin_setting_mentor_master_activities.php # Master checklist definition UI
│   ├── admin_setting_module_tracker_categories.php# Custom tracker categories UI
│   ├── batch_notes_service.php                 # Collaborative cohort review notes & author editing
│   ├── cliq_activity_notifier.php              # Zoho Cliq bot engine & stage transition alerts
│   ├── crmapi.php                              # Zoho CRM OAuth2 REST client
│   ├── crm_fields_helper.php                   # CRM data normalization, caching, & field masking
│   ├── hook_callbacks.php                      # Moodle core hook bindings
│   ├── mentor_activity_service.php             # Mentor operational activities checklist logic
│   ├── moodledata.php                          # LMS database query abstraction utilities
│   ├── student_performance_service.php         # Gradebook aggregation, MAAC rating, & metrics
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
│   ├── install.xml       # XMLDB table schema
│   ├── tasks.php         # Scheduled background tasks registration
│   ├── uninstall.php     # Clean uninstall callback
│   └── upgrade.php       # Incremental database migrations
├── docs/                 # Documentation, specifications, & audits
│   ├── access.md         # Capability access matrix
│   ├── issue_report.md   # Architectural changelog & issue register
│   └── phase_1_documentation.md # Functional specifications & design documentation
└── lang/en/              # Localized language strings
    └── local_batchanalytics.php
```

---

## Frontend AMD Modules & Build Pipeline

The plugin uses Moodle's native AMD (Asynchronous Module Definition) loader for client-side functionality. All client code is authored in `amd/src/` and compiled to `amd/build/*.min.js` with source maps using [Terser](https://terser.org/).

### Compiling Assets

To rebuild the minified AMD bundles after making changes:

```bash
# Using npm
npm run build

# Or directly using Node.js
node build.js
```

The build script compiles and compresses all four core modules:
* `local_batchanalytics/dashboard` -> `amd/build/dashboard.min.js`
* `local_batchanalytics/batch` -> `amd/build/batch.min.js`
* `local_batchanalytics/module` -> `amd/build/module.min.js`
* `local_batchanalytics/cliq_templates` -> `amd/build/cliq_templates.min.js`

---

## Core Workflows & Pages

### 1. Portfolio Dashboard (`index.php`)
* **Portfolio at a Glance:** Live metrics on Running Batches, Online/Offline/Hybrid counts, Active Class Mentors, Active Lab Mentors, Schedule Health (Early, On Schedule, Minor Slip, Delayed), and Active Students.
* **Accurate Unique Student Counting:** 
  * Queries active course enrollments through Moodle core `get_enrolled_users()`.
  * Filters out non-student roles (`manager`, `teacher`, `editingteacher`, `coursecreator`, `program_manager`, `mentor_resource_access`, `ss_team_member`, `ss_team_lead`) and site administrators.
  * The **Students (current)** KPI card calculates a deduplicated `Set` of active student User IDs across all running batches. Students enrolled in multiple running cohorts are counted once, showing the true learner headcount.
  * Individual rows in the schedule table accurately display the enrollment size of that specific cohort.
* **Active Mentors Drill-Down:** Clicking Class Mentors or Lab Mentors cards opens a modal listing mentors assigned strictly to the *current active module* of running batches, with instant multi-field live search.
* **Batch Schedule & Multi-Factor Filtering:** Responsive table featuring sticky headers, multi-tag filter pills with 1-click removal, chronological sorting (oldest date first), and Excel-compatible UTF-8 BOM CSV exports.

### 2. Cohort Analytics (`batch.php`)
* **Curriculum Timeline:** Chronological progression of sequential modules with planned start/end dates, actual completion timestamps, and schedule variance (delta days).
* **Student Success (SS) Touchpoints:** Tracks operational milestone touchpoints (e.g. attendance reviews, counseling sessions, milestone assessments) assigned to the Student Success Executive (SSE).
* **Collaborative Review Notes Feed:** Log allowing Academic Mentors, Batch Managers, and SSEs to record dated notes with role-based badges. Note authors can edit their most recent note inline with full CSRF and authorization checks.

### 3. Module Analytics & KPIs (`module.php`)
* **Gradebook-First Category KPIs:** Summary cards displaying live metrics directly from LMS Gradebook:
  * **Grade Mode:** Average **Final Grade** across all items in that category.
  * **Completion % Mode:** Percentage of enrolled students who completed the category activities.
* **Underlying Category Modals:** Clicking any KPI card opens a modal detailing category completion rate, category final grade, enrolled student breakdown table, and CSV download.
* **Student Performance Table:** Comprehensive roster displaying avatar, ID, overall module grade, attendance percentage, individual category grades, student performance band, and merit tags (Spot Awards, PT nominations).
* **Standardized 9-Point MAAC Rating:** Normalized 9-point score calculated via:
  $$\text{MAAC Rating} = \text{round}\left(\left(\frac{\text{grade}}{100}\right) \times 9, 2\right)$$
  Displayed as a fixed 9-point score with 2 decimal places (e.g. `7.45`).

### 4. Operational Activity Checklists
* Standardized operational checklists for mentors (e.g. Attendance Reconciliation, Grade Upload, Weekly Code Reviews).
* Auto-matches to courses based on curriculum keyword rules or manual selection.
* Checking an item auto-populates today's date and persists immediately via AJAX with optimistic UI updates.
* Activities are section-isolated via `sectionid`, ensuring multiple cohorts sharing a course maintain independent progress tracking.

### 5. Notification & Escalation Engine (Zoho Cliq)
* **Scheduled Evaluation Task (`evaluate_activity_due_task`):** Evaluates pending mentor and SS activities every weekday at 09:00:
  * **T-3 Advance Reminder:** Notification sent 3 days before the scheduled deadline.
  * **Due Today Alert:** Notification sent on the day of the deadline.
  * **T+3 Overdue Escalation:** Critical alert sent 3 days after an overdue deadline.
* **Stage Transition Alerts:** Triggered when a batch completes a module and transitions to the next, reporting module completion percentages, attendance rates, and upcoming mentor assignments.
* **Non-Blocking Asynchronous Queue:** Completion alerts, review note notifications, and module start notices are dispatched via Moodle's background ad-hoc task runner (`send_cliq_notification_task`), ensuring zero user web request stalls.
* **Per-Recipient Deduplication:** Tracked in `{local_batchanalytics_cliq_log}` with indexes on `[batchid, activity_key, stage, recipient_email, date_sent]` to ensure notifications are never duplicated per recipient per day.
* **Template Editor (`cliq_templates.php`):** Admin interface for customizing markdown notification message formats with localized AMD controls.

### 6. Collaborative Review Notes
* Dedicated review notes log per cohort.
* Author inline editing with optimistic updates and full audit logging.
* Secured against IDOR and CSRF: users can only edit notes they authored, protected by `sesskey` and capability checks.

### 7. Zoho CRM Integration
* Synchronizes corporate recruitment and placement data (placement status, hiring company, CTC) into student performance records.
* Fine-grained capability guarding: sensitive compensation details (`CTC`) and company names are stripped from responses unless the user holds `local/batchanalytics:viewfullcrmdata`.
* Real-time soft skills sync triggers communicated to upstream `local_batchmanagement`.

### 8. Companion Dashboard Block
* Independent companion widget packaged as [`block_batchanalytics`](https://github.com/EmertxeInfoTech/Moodle-Block-Batchanalytics) (`blocks/batchanalytics`).
* Embeddable on user personal dashboards (`/my/`) to display upcoming and overdue tasks with 1-click completion.

### 9. Privacy & GDPR Compliance
* Implements Moodle's Privacy Subsystem (`classes/privacy/provider.php`) conforming to GDPR:
  * **Metadata Declaration:** Declares all tables containing personal data.
  * **Subject Access Requests (SAR):** Exports review notes, activity modifications, and Cliq notification history.
  * **Data Erasure:** Deletes personal review notes, logs, and anonymizes audit fields upon user deletion requests.

---

## Database Schema

The plugin maintains 4 core persistent tables defined in [`db/install.xml`](db/install.xml):

| Table Name | Description | Key Columns & Indexes |
| :--- | :--- | :--- |
| `local_batchanalytics_activity_tracker` | Module activity delivery status | `courseid`, `cmid`, `sectionid`, `completed`, `completiondate`, `modifiedby`, `timemodified`<br>Indexes: `[courseid, cmid, sectionid]` (Unique), `sectionid_ix` |
| `local_batchanalytics_batch_notes` | Collaborative cohort review notes | `batchid`, `userid`, `author`, `body`, `timecreated`<br>Indexes: `batchid_ix` |
| `local_batchanalytics_mentor_act` | Mentor operational checklist data (JSON) | `courseid`, `selectedgroup`, `activitiesdata`, `timemodified`<br>Indexes: `courseid_uix` (Unique) |
| `local_batchanalytics_cliq_log` | Notification dispatch & deduplication log | `batchid`, `courseid`, `activity_type`, `activity_key`, `stage`, `recipient_email`, `userid`, `timesent`, `date_sent`, `status`<br>Indexes: `dedup_ix`, `dedup_recipient_ix`, `recipient_email_ix`, `userid_ix` |

---

## Scheduled & Ad-hoc Background Tasks

| Task Class | Type | Frequency | Description |
| :--- | :--- | :--- | :--- |
| `\local_batchanalytics\task\evaluate_activity_due_task` | Scheduled | Mon–Fri at 09:00 | Evaluates upcoming, due, and overdue checklist deadlines; dispatches Zoho Cliq notifications. |
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
   Clone this repository into your Moodle installation at `moodle/local/batchanalytics`:
   ```bash
   git clone https://github.com/EmertxeInfoTech/moodle-local-batchanalytics.git moodle/local/batchanalytics
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
   npm run build
   ```

5. **Purge Caches:**
   ```bash
   php moodle/admin/cli/purge_caches.php
   ```

### 2. Upgrading

When upgrading the plugin to a newer release:
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
* Moodle core drops all plugin database tables declared in `install.xml`.
* Database configurations and sensitive API tokens in `mdl_config_plugins` are safely cleared.

---

## License

This plugin is licensed under the [GNU General Public License v3 or later](http://www.gnu.org/copyleft/gpl.html).  
Copyright &copy; 2026 Emertxe Information Technologies.
