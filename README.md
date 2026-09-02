# local_batchanalytics

`local_batchanalytics` is a Moodle local plugin for monitoring course batches, student progress, MAAC data, and ticket workflows. It combines Moodle course and grade data with configurable Zoho CRM student and mentor data, and can send ticket notifications through Zoho Cliq.

Current release: `2.0.2`

## Requirements

- Moodle 4.4 or later
- PHP version supported by the installed Moodle version
- A Zoho CRM account and API credentials when CRM enrichment or MAAC-to-CRM sync is required
- A Zoho Cliq bot URL and key when Cliq ticket notifications are required

## Installation

1. Copy this directory to `moodle/local/batchanalytics`.
2. Sign in as a site administrator and visit **Site administration > Notifications** to run the installation or upgrade.
3. Configure the plugin at **Site administration > Plugins > Local plugins > Batch Analytics**.
4. Assign the required capabilities and, where applicable, configure the SS Team and Batch Manager roles.

## Plugin Areas

- **Batch Analytics**: browse batches and courses, inspect student, teacher, grade, attendance, and placement data, and maintain permitted course summaries.
- **MAAC Sheet**: view and edit per-student MAAC values, raise tickets, and optionally sync configured numeric MAAC columns to Zoho CRM.
- **Module Tracker**: record activity delivery status and completion dates for a course.
- **Ticket Dashboard**: view tickets, update priority and status, resolve tickets, and escalate eligible tickets to the configured Batch Manager flow.
- **Ticket Templates**: manage reusable ticket content from plugin administration.
- **Cliq Templates and History**: configure notification templates and inspect the recorded delivery history.
- **MAAC Import**: when enabled in settings, analyze, preview, and import MAAC sheet data.

## Configuration

The plugin settings provide these configuration groups:

- Zoho OAuth credentials and accounts/API base URLs
- Allowed course keywords used to filter eligible courses
- Student CRM module name and fields, including restricted fields for non-managers
- Mentor CRM module, batch field, fields, and field groups
- MAAC custom columns, groups, CRM-sync columns, feedback duration, trend window, and attendance window
- SS Team and Batch Manager course-role mappings
- Ticket duration and ticket-edit window
- Zoho Cliq bot URL, key, notification templates, and delivery history
- Optional MAAC sheet import

Keep Zoho and Cliq credentials in plugin settings. Do not store them in source code or documentation.

## Access

All pages require a signed-in user. The Batch Analytics home and Ticket Dashboard also require the system capability `local/batchanalytics:view`; course-level capabilities control MAAC, Module Tracker, and ticket actions. Site administrators retain full access.

See [access.md](access.md) for the capability reference, role matrix, ticket-flow permissions, and configuration dependencies.

## Storage

The plugin uses Moodle core tables and these plugin tables:

- `local_batchanalytics_maac`: per-course, per-student MAAC field values
- `local_batchanalytics_ticket`: ticket records, ownership, status, priority, escalation, and resolution data
- `local_batchanalytics_ticket_event`: ticket timeline events
- `local_batchanalytics_cliq_history`: Cliq delivery attempts and responses
- `local_batchanalytics_activity_tracker`: per-course module delivery status

## JSON Actions

The page endpoints below also render their normal Moodle pages when no `action` is supplied. They are intended for the plugin UI, require an authenticated session and the relevant capabilities, and are not a public API.

### Batch Analytics (`index.php`)

- `GET action=getallbatches`
- `GET action=searchcourses&keyword=...`
- `GET action=getbatchcourses&batchcode=...`
- `GET action=getbatchfulldata&batchcode=...`
- `GET action=getcrmdata&username=...`
- `POST action=savecoursesummary`
- `POST action=getptfdata`
- `POST action=getmentordetails`
- `POST action=syncmaaccrm`

### MAAC and Module Tracker

- `POST maac.php?action=summary&courseid=...`
- `POST maac.php?action=getdata&courseid=...`
- `POST maac.php?action=savedata&courseid=...`
- `POST maac.php?action=saveticket&courseid=...`
- `POST maac.php?action=editticket&courseid=...`
- `POST maac.php?action=viewticket&courseid=...`
- `POST activity_tracker.php?action=getdata&courseid=...`
- `POST activity_tracker.php?action=save&courseid=...`
- `POST maac_import.php?action=analyze`
- `POST maac_import.php?action=preview`
- `POST maac_import.php?action=import`

### Tickets (`tickets.php`)

- `POST action=gettickets`
- `POST action=viewticket`
- `POST action=updateticket`
- `POST action=escalateticket`

All POST actions require a valid Moodle `sesskey`. Request payload shape and response fields are internal implementation details and may change with the plugin UI.

## Architecture

- `index.php`: Batch Analytics page and batch/CRM JSON actions
- `maac.php`: MAAC page and MAAC data/ticket actions
- `activity_tracker.php`: Module Tracker page and activity-status actions
- `tickets.php`: Ticket Dashboard and ticket actions
- `maac_import.php`: MAAC import workflow
- `ticket_templates.php`, `cliq_templates.php`, and `cliq_message_history.php`: administration pages for templates and notification history
- `classes/moodledata.php`: role-aware Moodle course and analytics data access
- `classes/maac_service.php`: MAAC aggregation, persistence, ticket workflow, and related access checks
- `classes/activity_tracker_service.php`: activity tracking persistence and retrieval
- `classes/crmapi.php`: Zoho CRM token, lookup, and update client
- `classes/cliq_service.php`: Zoho Cliq notification delivery and history recording
- `classes/admin_setting_*.php`: custom plugin-setting controls and normalization helpers
- `db/install.xml` and `db/upgrade.php`: plugin schema and upgrade steps
- `db/tasks.php`: scheduled task definitions

## Validation

After deployment, complete the Moodle upgrade process, purge caches if required, and verify the configured role flows, CRM access, Cliq delivery, and MAAC import in the target Moodle environment.
