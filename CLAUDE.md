# CLAUDE.md

Guidance for maintaining the `local_batchanalytics` Moodle local plugin.

## Plugin Overview

`local_batchanalytics` provides batch and course analytics, MAAC data management,
Module Tracker, ticket workflows, optional MAAC import, and Zoho CRM/Cliq
integrations. The current release is `2.0.4`, requires Moodle 4.4 or later, and
supports the PHP version required by that Moodle installation.

Install the plugin at `moodle/local/batchanalytics` and complete **Site
administration > Notifications** after installing or deploying an upgrade.

## Architecture

This is not a single-page plugin. Its primary pages and frontend scripts are:

- `index.php` and top-level `new_analytics.js`: Batch Analytics main dashboard
  displaying active and completed cohorts with filters, stat cards, and links to
  batch and module analytics.
- `batch.php` and `batch.js`: Cohort batch detail view (Schedule, Module Tracker,
  Student Performance, MAAC, and CRM Data tabs).
- `module.php` and `module.js`: Single-module analytics and student grading view.
- `maac.php` and `maac.js`: per-course MAAC values, feedback, and ticket actions.
- `activity_tracker.php` and `activity_tracker.js`: Module Tracker activity
  delivery status and completion dates.
- `tickets.php` and `tickets.js`: Ticket Dashboard and ticket workflow actions.
- `maac_import.php` and `maac_import.js`: optional MAAC XLSX/CSV import flow.
- `ticket_templates.php`, `cliq_templates.php`, and
  `cliq_message_history.php`: plugin administration pages for templates and
  notification history.

`amd/src/main.js` is only the Moodle AMD entry stub. The active page scripts are
the top-level JavaScript files listed above.

## Backend Services

- `classes/moodledata.php`: role-aware Moodle course, enrollment, grade, and
  analytics data access.
- `classes/maac_service.php`: MAAC aggregation/persistence, ticket workflow,
  and related authorization checks.
- `classes/activity_tracker_service.php`: Module Tracker classification,
  retrieval, and status persistence.
- `classes/course_summary_service.php`: per-course summary storage and legacy
  configuration migration.
- `classes/crmapi.php`: Zoho CRM token, lookup, and update client.
- `classes/cliq_service.php`: Zoho Cliq template rendering, delivery, and
  history recording.
- `classes/admin_setting_*.php`: custom Moodle administration setting controls.

## Persistent Storage

The plugin uses Moodle core tables and six custom plugin tables. Do not assume
that it is stateless or that no database migration is needed.

- `local_batchanalytics_maac`: per-course, per-student MAAC field values.
- `local_batchanalytics_ticket`: ticket records, ownership, status, priority,
  escalation, and resolution data.
- `local_batchanalytics_ticket_event`: ticket timeline events.
- `local_batchanalytics_cliq_history`: Cliq delivery attempts and responses.
- `local_batchanalytics_activity_tracker`: per-course module delivery status.
- `local_batchanalytics_course_summary`: per-course Batch Analytics summaries.

New installs use `db/install.xml`; changes to persisted schema require a new
incremental upgrade step in `db/upgrade.php` and a higher plugin version in
`version.php`. Course-summary records are removed by the plugin observer when a
course is deleted.

## Access and Request Rules

- The Batch Analytics, MAAC, Module Tracker, and Ticket Dashboard pages require
  `local/batchanalytics:view` at system context, except that site
  administrators retain full access.
- Course-level MAAC, Module Tracker, and ticket operations apply their specific
  course capabilities in addition to the system-level access gate.
- MAAC import requires `local/batchanalytics:manage` and the enabled import
  setting.
- Mutating JSON actions use POST and Moodle `sesskey` validation. Preserve these
  checks when adding actions; do not expose these endpoints as a public API.
- Use `access.md` for the detailed capability matrix, ticket routing rules, and
  role behavior.

## Development Notes

- Keep Zoho CRM and Cliq credentials in Moodle plugin settings, never in source
  code or documentation.
- Use existing UI classes and page-specific JavaScript rather than adding a
  build system or duplicating frontend helpers.
- After PHP changes, run `php -l` on every changed PHP file. After JavaScript
  changes, run `node --check` on every changed JavaScript file. Always run
  `git diff --check` before committing.
- Runtime behavior involving Moodle capabilities, database upgrades, CRM, and
  Cliq must be verified in a configured Moodle environment after deployment.
