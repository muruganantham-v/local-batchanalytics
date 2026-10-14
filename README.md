# Batch Analytics

`local_batchanalytics` is an advanced, high-performance Moodle local plugin designed for training academies, corporate bootcamps, and educational institutions. It provides end-to-end cohort tracking, module schedule variance monitoring, real-time LMS Gradebook analytics, student performance banding, operational checklist workflows, automated Zoho Cliq bot alerting, and Zoho CRM student placement synchronization.

**Current Release:** `2.2.25` | **Plugin Version:** `2026101402` | **Moodle Compatibility:** `4.4+` (`2024042200`)

---

## Table of Contents

- [Key Capabilities](#key-capabilities)
- [System Architecture](#system-architecture)
- [Core Workflows & Features](#core-workflows--features)
  - [1. Portfolio Dashboard (index.php)](#1-portfolio-dashboard-indexphp)
  - [2. Cohort Analytics (batch.php)](#2-cohort-analytics-batchphp)
  - [3. Module Analytics & KPIs (module.php)](#3-module-analytics--kpis-modulephp)
  - [4. Operational Activity Checklists](#4-operational-activity-checklists)
  - [5. Automated Notification Engine (Zoho Cliq)](#5-automated-notification-engine-zoho-cliq)
  - [6. Companion Dashboard Block](#6-companion-dashboard-block)
- [Database Schema](#database-schema)
- [Installation & Upgrading](#installation--upgrading)
- [Admin Settings & Configuration](#admin-settings--configuration)
- [Permissions & Capabilities](#permissions--capabilities)

---

## Key Capabilities

* **Portfolio at a Glance:** Live telemetry on running vs. completed cohorts, active learners, and schedule health (On-Schedule, Early, Minor Slip, Delayed).
* **Active Mentor Attribution:** Mentors are dynamically extracted and counted strictly from the *currently active module* of running batches.
* **Gradebook-First Analytics:** Directly queries Moodle's native `{grade_categories}`, `{grade_items}`, and `{grade_grades}` for 100% parity with LMS calculations.
* **Module KPIs:** Category performance cards displaying average **Final Grade** in Grade mode and **Completion %** in percentile mode.
* **Standardized MAAC Rating:** Normalized 9-point score calculated via `((grade / 100) * 9)` formatted to 2 decimal places.
* **Student Performance Banding:** Automated classification of learners into Top Performers, Middle Performers, and Low Performers with deep-dive modal analysis.
* **Operational Workflows:** Checklist tracking for Mentor and Student Success (SS) operational activities with auto-filled dates and real-time AJAX persistence.
* **Automated Escalation Bot:** Scheduled background tasks dispatching Zoho Cliq messages for upcoming (T-3), due today, and overdue (T+3) mentor activities, plus module transition briefings.
* **Zoho CRM Integration:** Synchronizes corporate recruitment telemetry (placement status, hiring company, CTC) into student performance records.

---

## System Architecture

```text
local/batchanalytics/
├── index.php             # Portfolio Dashboard: Stats, batch schedule table, mentor modal, & CSV export
├── index.js              # Client-side multi-filter engine, mentor modal controller, & state management
├── batch.php             # Cohort View: Module timeline schedule, SS activities, & review notes
├── batch.js              # Batch view tabs, student performance renderer, & note submission
├── module.php            # Module View: Module KPIs, Category Data Modals, Checklists, & Performance
├── module.js             # Client-side KPI toggle, table rendering, student banding, & activity actions
├── cliq_templates.php    # Admin template editor for Zoho Cliq message formats
├── dashboard.css         # Modern, high-density responsive styles & UI components
├── styles.css            # Base stylesheet (automatically loaded by Moodle)
├── lib.php               # Moodle navigation extension
├── settings.php          # Comprehensive admin settings & operational role mapping
├── version.php           # Plugin version specification
├── classes/              # PSR-4 autoloaded service layer
│   ├── activity_tracker_service.php            # Module activity delivery tracking
│   ├── admin_setting_crm_fields.php            # Admin custom setting for CRM field mapping
│   ├── admin_setting_mentor_activity_grouping.php # Activity grouping settings UI
│   ├── admin_setting_mentor_master_activities.php # Master checklist definition UI
│   ├── admin_setting_module_tracker_categories.php# Custom tracker categories UI
│   ├── batch_notes_service.php                 # Collaborative cohort review notes
│   ├── cliq_activity_notifier.php              # Zoho Cliq bot engine & stage transition alerts
│   ├── crm_fields_helper.php                   # CRM data normalization and caching
│   ├── crmapi.php                              # Zoho CRM OAuth2 REST client
│   ├── hook_callbacks.php                      # Moodle core hook bindings
│   ├── mentor_activity_service.php             # Mentor operational activities checklist logic
│   ├── moodledata.php                          # LMS database query utilities
│   ├── student_performance_service.php         # Gradebook aggregation, MAAC rating, & metrics
│   ├── task/
│   │   └── evaluate_activity_due_task.php      # Scheduled task for automated alerts
│   ├── task_service.php                        # Task aggregation for companion block widget
│   └── util.php                                # Common profile, date, and formatting utilities
├── blocks/                                     # Companion Moodle Block Plugin
│   └── batchanalytics/                         # User dashboard (/my/) To-Do widget
│       ├── block_batchanalytics.php
│       ├── block_task_dashboard.css
│       ├── block_task_dashboard.js
│       ├── version.php
│       ├── db/access.php
│       └── lang/en/block_batchanalytics.php
├── db/                                         # Database schema & Moodle specifications
│   ├── access.php                              # Capability definitions
│   ├── caches.php                              # MUC definitions
│   ├── hooks.php                               # Hook listeners
│   ├── install.xml                             # XMLDB database table schema
│   ├── tasks.php                               # Scheduled tasks registration
│   └── upgrade.php                             # Database migration scripts
├── docs/                                       # Technical references & specifications
│   ├── access.md                               # Capability access matrix
│   ├── issue_report.md                         # Release change logs and bugfix register
│   └── phase_1_documentation.md                # Functional specifications & design docs
└── lang/en/                                    # Localized language strings
    ├── block_batchanalytics.php
    └── local_batchanalytics.php
```

---

## Core Workflows & Features

### 1. Portfolio Dashboard (`index.php`)
- **Portfolio at a Glance:** Top stat cards display running vs. completed batches, total active learners, and schedule health indicators (Delayed, Minor Slip, On-Schedule, Early).
- **Active Mentors Modal:** Clicking the Class Mentors or Lab Mentors card opens a modal showing mentors assigned to *current active modules* of running cohorts. Batch chips format as `{batchId} - {currentModule}` with instant live search across mentor name, batch code, and module name.
- **Batch Schedule & Students:** Filterable table featuring sticky headers, multi-tag filter chips with instant removal, chronological sorting, and Excel-compatible UTF-8 BOM CSV exports.

### 2. Cohort Analytics (`batch.php`)
- **Curriculum Schedule:** Displays all sequential modules in the cohort curriculum, planned start/end dates, actual completion timestamps, and schedule variance (delta days).
- **Student Success Activities:** Tracks milestone touchpoints (e.g., student counseling, attendance reviews, onboarding) assigned to the Student Success Executive (SSE).
- **Review Notes Feed:** Interactive log allowing Batch Managers, Academic Mentors, and SSEs to record dated notes with role-based badges.

### 3. Module Analytics & KPIs (`module.php`)
- **Module KPIs:** Gradebook category summary cards auto-pulled from LMS.
  - **Grade Mode:** Displays the **Final Grade** of each category (e.g., Template Programs, Classworks, Assignments, Quizzes, Tests).
  - **Completion % Mode:** Displays the percentage of items completed in that category.
- **Underlying Category Modals:** Clicking any KPI card opens a detailed modal showing enrolled student count, category completion rate, category final grade, full student breakdown table, and CSV download.
- **Student Performance Table:** Displays student avatar, ID, overall module grade, attendance percentage, individual category grades, student band badge, and merit tags (Spot Awards, PT nominations).
- **MAAC Rating:** Calculated strictly from student grade using:
  $$\text{MAAC Rating} = \text{round}\left(\left(\frac{\text{grade}}{100}\right) \times 9, 2\right)$$
  Displayed as a fixed 9-point score with 2 decimal places (e.g., `7.02`) that does not toggle to completion mode.

### 4. Operational Activity Checklists
- Defined operational tasks for mentors (e.g., Attendance Reconciliation, LMS Grade Upload, Weekly Code Reviews).
- Checklists dynamically auto-match to the course based on curriculum configuration or manual selection.
- Marking an item completed auto-fills today's date and persists immediately via AJAX with optimistic UI updates.

### 5. Automated Notification Engine (Zoho Cliq)
- Backed by Moodle scheduled task `\local_batchanalytics\task\evaluate_activity_due_task`.
- **Activity Reminders:** Automatically dispatches Zoho Cliq notifications to assigned Class Mentors, Lab Mentors, and SSEs:
  - **T-3 Alert:** Advance notification 3 days prior to due date.
  - **Due Today:** Reminder on the day of the deadline.
  - **T+3 Overdue:** Critical warning sent 3 days after an overdue deadline.
- **Stage Transition Alerts:** Notifies stakeholders when a cohort transitions from one module to the next with attendance, completion, and mentor assignment telemetry.
- **Deduplication:** Tracked in `{local_batchanalytics_cliq_log}` to ensure notifications are never dispatched twice for the same stage on the same day.

### 6. Companion Dashboard Block
- Located in `blocks/batchanalytics`.
- Embeddable on the Moodle Dashboard (`/my/`) or course pages.
- Lists pending and overdue checklist items for the logged-in user with status pills and 1-click completion.

---

## Database Schema

The plugin maintains 4 core persistent tables defined in [`db/install.xml`](db/install.xml):

| Table Name | Description | Key Columns |
| :--- | :--- | :--- |
| `local_batchanalytics_activity_tracker` | Delivery status for LMS course modules | `courseid`, `cmid`, `completed`, `completiondate`, `modifiedby`, `timemodified` |
| `local_batchanalytics_batch_notes` | Review notes captured for cohorts | `batchid`, `userid`, `author`, `body`, `timecreated` |
| `local_batchanalytics_mentor_act` | Operational checklist state per course | `courseid`, `selectedgroup`, `activitiesdata` (JSON), `timemodified` |
| `local_batchanalytics_cliq_log` | Zoho Cliq notification deduplication log | `batchid`, `courseid`, `activity_type`, `activity_key`, `stage`, `recipient_email`, `timesent`, `date_sent`, `status` |

---

## Installation & Upgrading

1. **Deploy Plugin Files:**
   - Clone or copy this directory into `moodle/local/batchanalytics`.
   - *Note:* The plugin includes a built-in auto-deployer. Upon running Moodle install/upgrade, it automatically deploys the companion block (`blocks/batchanalytics`) into `moodle/blocks/batchanalytics` seamlessly.
2. **Execute Database Upgrades:**
   - Run the Moodle CLI upgrade tool:
     ```bash
     php moodle/admin/cli/upgrade.php
     ```
   - Alternatively, visit **Site administration > Notifications** in your browser.
3. **Purge Caches:**
   ```bash
   php moodle/admin/cli/purge_caches.php
   ```

---

## Admin Settings & Configuration

Navigate to **Site administration > Plugins > Local plugins > Batch Analytics**:

1. **Zoho CRM Settings:**
   - Configure Client ID, Client Secret, Refresh Token, and API URLs for student placement information.
2. **Allowed Course Keywords:**
   - Filter cohort courses eligible for Batch Analytics.
3. **Module Tracker & Gradebook Mapping:**
   - Define custom tracker category names and match them to Gradebook category patterns.
4. **Mentor Master Activities & Groupings:**
   - Define standardized mentor operational activity checklists and configure auto-matching rules by course keyword.
5. **Zoho Cliq Bot Webhooks & Notification Templates:**
   - Configure incoming webhook tokens, bot endpoints, and customize markdown notification templates.

---

## Permissions & Capabilities

Detailed in [`docs/access.md`](docs/access.md):

| Capability | Context | Description |
| :--- | :--- | :--- |
| `local/batchanalytics:view` | System / Course | Primary gate to access Batch Analytics dashboards and reports. |
| `local/batchanalytics:manage` | System | Full administrative access, settings configuration, and CRM details. |
| `local/batchanalytics:viewallcourses` | System | Permits viewing all cohorts regardless of individual course enrollments. |
| `local/batchanalytics:viewreviewnotes` | System / Course | Permission to view cohort review notes. |
| `local/batchanalytics:editreviewnotes` | System / Course | Permission to post and edit cohort review notes. |

---

## License

This plugin is licensed under the [GNU General Public License v3 or later](http://www.gnu.org/copyleft/gpl.html).  
Copyright &copy; 2026 Emertxe Information Technologies.
