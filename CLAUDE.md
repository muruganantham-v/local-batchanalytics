# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Overview

`local_batchanalytics` is a Moodle local plugin that aggregates student, teacher, and grade data from Moodle courses and enriches it with Zoho CRM placement data. Requires Moodle 4.x+ and PHP 8.x.

Install by copying to `moodle/local/batchanalytics` and visiting Site Administration > Notifications.

## Architecture

**Single-page app pattern**: `index.php` serves both the HTML page and JSON API endpoints (via `?action=` parameter). The frontend is vanilla ES6+ JavaScript (`simple.js`) with plain CSS (`styles.css`) — no build system, no Node.js dependencies.

### Backend

- **`index.php`** — Main controller (~470 lines). All API endpoints and HTML rendering. Endpoints return JSON:
  - `searchcourses` — keyword search
  - `getbatchcourses` — find courses by batch code
  - `getcrmdata` / `getptfdata` — CRM placement data (single or batch)
  - `getbatchfulldata` — primary endpoint returning full batch analytics with grades and CRM data
- **`classes/moodledata.php`** — Moodle DB queries. Role-based filtering: admins see all courses, teachers see only enrolled. Uses recordsets to avoid N+1.
- **`classes/crmapi.php`** — Zoho CRM OAuth2 integration. Searches `Child_Admission` module by `Admission_Number`. Batches requests in chunks of 10. Session-cached access tokens with 1-hour expiry.
- **`settings.php`** — Admin settings for Zoho CRM credentials (client ID, secret, refresh token, URLs). Configured at Site Admin > Plugins > Local Plugins > Batch Analytics.
- **`db/access.php`** — Defines `local/batchanalytics:view` capability (granted to manager, editingteacher, teacher at SYSTEM context).

### Frontend

- **`simple.js`** (~1900 lines) — Entire frontend: search UI, tab navigation (Batch Overview, CRM/PTF data, per-course grades), sortable tables, skeleton loading, toast notifications. Key state: `BATCH_DATA`, `CRM_CACHE`, `PTF_CACHE`.
- **`amd/src/main.js`** — Minimal AMD stub, not actively used.
- **`styles.css`** — All styling including skeleton loading animations, tab system, sortable tables, sticky headers.

### Data Flow

User searches batch code → `getbatchfulldata` returns courses, grades, student list → frontend renders tabs → CRM data lazy-loaded via `getptfdata` and cached in `PTF_CACHE`.

### Key Implementation Details

- **Batch code parsing**: Extracted from course name format "Batch 22: Python" → "22"
- **Grade calculation**: Distinguishes percentage (submitted work quality) from completion rate (submitted / total). MAAC Ratings category divides by 10 for 0-10 scale.
- **No custom DB tables** — uses only standard Moodle tables (course, user, grade_items, grade_grades, etc.)

## API Endpoints

All via `index.php?action=<action>`:
- `searchcourses&keyword=<term>` — search courses
- `getbatchcourses&batchcode=<code>` — courses by batch
- `getbatchfulldata&batchcode=<code>` — full batch data (main endpoint)
- `getptfdata&username=<user>` or `&usernames=<json>` — CRM data
- `getcrmdata&username=<user>` — simple placement status

## Zoho CRM Configuration

Credentials are stored in Moodle admin settings, never hardcoded. Access via `get_config('local_batchanalytics', 'zoho_client_id')` etc.
