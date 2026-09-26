# Batch Analytics

`local_batchanalytics` is a high-performance Moodle local plugin for batch and module analytics, curriculum progress tracking, student performance grading, batch review notes, and Zoho CRM integration.

Current release: `2.1.0` | Plugin version: `2026092700`

## Contents

- [Requirements](#requirements)
- [Installation and Upgrade](#installation-and-upgrade)
- [File & Architecture Structure](#file--architecture-structure)
- [Configuration](#configuration)
- [Data Storage](#data-storage)
- [Access and Permissions](#access-and-permissions)

## Requirements

### Required

- Moodle `4.4` or later (`$plugin->requires = 2024042200`).
- PHP version compatible with the installed Moodle version.
- User roles and capabilities configured for Batch Analytics.

### Optional Integrations

- **Zoho CRM**: required for fetching student placement information and CTC.

## Installation and Upgrade

1. Copy the plugin directory to `moodle/local/batchanalytics`.
2. Sign in as a site administrator.
3. Open **Site administration > Notifications** to run the plugin installation or upgrade.
4. Purge caches if Moodle does not immediately load new navigation, strings, JavaScript, or styles.
5. Open **Site administration > Plugins > Local plugins > Batch Analytics** to configure settings.

## File & Architecture Structure

The plugin is structured symmetrically around three core views:

```text
local/batchanalytics/
├── index.php             # Main Batch Analytics dashboard
├── index.js              # Client-side filtering, stats, & table rendering
├── batch.php             # Cohort batch detail view (Schedule, SS, Performance, CRM, Notes)
├── batch.js              # Batch view tabs, student performance, & note submission
├── module.php            # Module detail view (Overview, Performance, Activity Tracker, CRM)
├── module.js             # Module view tabs & embedded activity status saving
├── styles.css            # Base stylesheet (automatically loaded by Moodle)
├── dashboard.css         # Modern UI components, stat cards, badges, and layout
├── lib.php               # Moodle navigation extension
├── settings.php          # Admin settings configuration
├── version.php           # Plugin version specification
├── classes/              # PSR-4 autoloaded services
│   ├── activity_tracker_service.php
│   ├── admin_setting_crm_fields.php
│   ├── admin_setting_module_tracker_categories.php
│   ├── batch_notes_service.php
│   ├── crmapi.php
│   ├── crm_fields_helper.php
│   ├── hook_callbacks.php
│   ├── moodledata.php
│   ├── student_performance_service.php
│   └── util.php
├── db/                   # Database schemas, capabilities, & upgrade scripts
│   ├── access.php
│   ├── caches.php
│   ├── hooks.php
│   ├── install.xml
│   └── upgrade.php
├── docs/                 # Reference documentation
│   ├── access.md
│   ├── issue_report.md
│   └── phase_1_documentation.md
└── lang/en/              # Language strings
    └── local_batchanalytics.php
```

## Configuration

Open **Site administration > Plugins > Local plugins > Batch Analytics**:

1. **Zoho CRM Configuration**: Enter Client ID, Client Secret, Refresh Token, and API URLs.
2. **Allowed Course Keywords**: Comma-separated list to filter batches/courses.
3. **Student CRM Data**: Configure CRM module name and field mappings.
4. **Module Tracker Configuration**: Define custom tracker category names and matching Gradebook categories.

## Data Storage

The plugin uses two persistent tables:

- `local_batchanalytics_activity_tracker`: Delivery status and completion timestamp per course activity module.
- `local_batchanalytics_batch_notes`: Batch review notes timestamped by author and role.

## Access and Permissions

Refer to [`docs/access.md`](docs/access.md) for detailed capability mapping:
- `local/batchanalytics:view`: Primary gate for viewing dashboard and analytics.
- `local/batchanalytics:manage`: Administrative manager access with full CRM visibility.
- `local/batchanalytics:viewallcourses`: Permission to view all batches and modules across the site.
- `local/batchanalytics:viewreviewnotes`: Permission to view batch review notes.
- `local/batchanalytics:editreviewnotes`: Permission to post batch review notes.
