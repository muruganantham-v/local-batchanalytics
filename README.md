# local_batchanalytics

Moodle local plugin for batch analytics. It aggregates course, student, teacher, and grade data from Moodle and enriches parts of the workflow with Zoho CRM placement data plus MAAC ticket tracking.

## Requirements

- Moodle 4.0+
- PHP 8.x
- Zoho CRM account with API access

## Installation

1. Copy this folder to `moodle/local/batchanalytics`.
2. Visit **Site administration > Notifications** to complete installation.
3. Configure the plugin under **Site administration > Plugins > Local plugins > Batch Analytics**.

## Features

- Batch and course analytics dashboard with per-course grade rollups
- Zoho CRM integration for placement/PTF data
- Configurable CRM fields, MAAC columns, and MAAC column groups
- Ticket workflow with dashboard, timeline events, and notifications
- Role-aware access:
  - Managers can edit MAAC data and view/manage all ticket workflows
  - Teachers can view accessible courses and raise tickets from the MAAC flow
  - SS Team and Batch Manager roles can update tickets for their configured course scope

## Storage

The plugin uses Moodle core tables and these plugin tables:

- `local_batchanalytics_maac`
- `local_batchanalytics_ticket`
- `local_batchanalytics_ticket_event`

## Configuration

Available settings include:

- Zoho CRM OAuth credentials and endpoint URLs
- Allowed course keywords
- CRM field configuration
- MAAC custom columns and column groups
- SS Team and Batch Manager role mappings
- Trend and attendance windows

## Endpoints

Read endpoints are served over GET:

- `index.php?action=getallbatches`
- `index.php?action=getbatchcourses&batchcode=...`
- `index.php?action=getbatchfulldata&batchcode=...`
- `index.php?action=getptfdata&usernames=...`
- `maac.php?action=summary&courseid=...`
- `maac.php?action=getdata&courseid=...`
- `tickets.php?action=gettickets`

Write endpoints require POST plus `sesskey`:

- `maac.php?action=savedata`
- `maac.php?action=saveticket`
- `tickets.php?action=resolveticket`
- `tickets.php?action=updateticket`

## Architecture

- `index.php`: batch analytics page plus read-side JSON endpoints
- `maac.php`: MAAC page and MAAC write/read endpoints
- `tickets.php`: ticket dashboard and ticket update endpoints
- `classes/moodledata.php`: role-aware Moodle data access
- `classes/maac_service.php`: MAAC aggregation, persistence, and ticket business logic
- `classes/crmapi.php`: Zoho CRM token and student lookup client
- `db/install.xml` / `db/upgrade.php`: plugin schema and upgrades

## Notes

- Keep CRM credentials in plugin settings, not in source.
- PHP CLI validation and Moodle runtime verification should be run in a PHP-enabled environment after deployment.


updated feedback suggestion
