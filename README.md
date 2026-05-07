# local_batchanalytics

Moodle local plugin (v1.2.0) for batch analytics — aggregates student, teacher, and grade data from Moodle courses and enriches it with Zoho CRM placement data.

## Requirements

- Moodle 4.0+
- PHP 8.x
- Zoho CRM account with API access

## Installation

1. Copy this folder to `moodle/local/batchanalytics`.
2. Visit **Site Administration > Notifications** to complete installation.
3. Configure Zoho CRM credentials under **Site Administration > Plugins > Local plugins > Batch Analytics**.

## Features

- Search courses by keyword or batch code
- Batch analytics dashboard — student count, teacher list, grade summary per course
- Zoho CRM integration — lazy-loads student placement/status data from the `Child_Admission` module
- Configurable CRM fields — choose which CRM fields to display via admin settings
- Configurable allowed course keywords — filter which courses appear in results
- Role-based access — admins see all courses, teachers see only their enrolled courses
- No custom DB tables — uses only standard Moodle tables

## Configuration

All settings are at **Site Administration > Plugins > Local plugins > Batch Analytics**.

| Setting | Description |
|---|---|
| Zoho CRM Client ID | OAuth2 client ID from Zoho API Console |
| Zoho CRM Client Secret | OAuth2 client secret |
| Zoho CRM Refresh Token | Long-lived refresh token for token renewal |
| Zoho Accounts URL | Default: `https://accounts.zoho.com` |
| Zoho API Base URL | Default: `https://www.zohoapis.com` |
| Allowed Course Keywords | Comma-separated list of course name keywords to include |
| CRM Fields Config | Select which Zoho CRM fields to display in the analytics view |

> Never hardcode credentials in source files. Always use the admin settings page.

## Permissions

The plugin defines the capability `local/batchanalytics:view`, granted by default to:
- Manager
- Editing Teacher
- Teacher

To adjust: **Site Administration > Users > Permissions > Define roles**.

## API Endpoints

All endpoints are served via `index.php?action=<action>` and return JSON.

| Action | Parameters | Description |
|---|---|---|
| `searchcourses` | `keyword=<term>` | Search courses by keyword |
| `getbatchcourses` | `batchcode=<code>` | Get courses for a batch code |
| `getbatchfulldata` | `batchcode=<code>` | Full batch analytics with grades and student list |
| `getptfdata` | `username=<user>` or `usernames=<json>` | CRM placement data (single or bulk) |
| `getcrmdata` | `username=<user>` | Simple placement status lookup |

## Architecture

Single-page app pattern — `index.php` serves both the HTML page and all JSON API endpoints. The frontend is vanilla ES6+ JavaScript (`simple.js`) with plain CSS (`styles.css`) — no build system, no Node.js dependencies.

```
index.php              # Main controller + API endpoints
simple.js              # Frontend: search UI, tabs, tables, CRM data
styles.css             # All styling
classes/
  moodledata.php       # Moodle DB queries (role-aware)
  crmapi.php           # Zoho CRM OAuth2 client
  crm_fields_helper.php        # CRM field config helpers
  admin_setting_crm_fields.php # Custom admin setting UI
  hook_callbacks.php   # Moodle hook integrations
db/
  access.php           # Capability definitions
  hooks.php            # Hook registrations
settings.php           # Admin settings page
```

## Data Flow

1. User enters a batch code → frontend calls `getbatchfulldata`
2. Response includes courses, grade summaries, and student list
3. Frontend renders tabs: Batch Overview, per-course grades
4. CRM/PTF data is lazy-loaded via `getptfdata` and cached in the browser session
