# CLAUDE.md

Guidance for maintaining the `local_batchanalytics` Moodle local plugin.

## Plugin Overview

`local_batchanalytics` provides cohort batch and module analytics, curriculum progress tracking, student performance grading, batch review notes, and Zoho CRM integration. Current release is `2.1.0` (version `2026092700`), requiring Moodle 4.4 or later.

Install the plugin at `moodle/local/batchanalytics` and complete **Site administration > Notifications** after installing or deploying an upgrade.

## Architecture

The plugin focuses strictly on three core views with 1:1 matching frontend assets:

- `index.php` & `index.js`: Batch Analytics main dashboard displaying running and completed cohorts with filters, KPI stat cards, and direct links to batch and module analytics.
- `batch.php` & `batch.js`: Cohort batch detail view (Schedule, SS Activities, Student Performance, CRM Data, and Review Notes tabs).
- `module.php` & `module.js`: Single-module analytics, student performance grading, embedded Activity Tracker, and CRM Data view.

### Stylesheets

- `styles.css`: Standard Moodle plugin stylesheet automatically loaded across plugin pages.
- `dashboard.css`: Modern component, card, pill, and dashboard design system.

## Backend Services (`classes/`)

- `classes/moodledata.php`: Role-aware Moodle course, section, batch, enrollment, and grade data access.
- `classes/student_performance_service.php`: Gradebook categories aggregation, grade calculations, and student performance metrics.
- `classes/activity_tracker_service.php`: Module Tracker classification, activity discovery, and completion status persistence.
- `classes/batch_notes_service.php`: Batch review notes storage and retrieval.
- `classes/crmapi.php`: Zoho CRM OAuth token management and student placement data fetching.
- `classes/crm_fields_helper.php`: Helper for dynamic CRM field configuration.
- `classes/util.php`: Core utility helpers for dates, mentors, and batch scheduling.
- `classes/hook_callbacks.php`: Hook callback for Moodle primary navigation.
- `classes/admin_setting_*.php`: Custom Moodle administration setting controls.

## Persistent Storage

The plugin uses Moodle core tables (plus `mdl_local_bm_*` Batch Management tables if present) and two custom tables:

- `local_batchanalytics_activity_tracker`: Course activity delivery status by course module.
- `local_batchanalytics_batch_notes`: Review notes captured for batches.

## Documentation

Internal reference documentation is located in the `docs/` directory:
- `docs/access.md`: Permissions, roles, and capability model.
- `docs/issue_report.md`: Historical issue log.
- `docs/phase_1_documentation.md`: Phase 1 architecture documentation.
