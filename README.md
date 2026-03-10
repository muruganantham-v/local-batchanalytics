# local_batchanalytics

Moodle local plugin for batch analytics and CRM data integration.

## Table of Contents

- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Usage](#usage)
- [Configuration](#configuration)
- [Git Workflow](#git-workflow)
- [Tagging](#tagging)
- [Troubleshooting](#troubleshooting)

## Overview

`local_batchanalytics` provides:

- course search by keyword/batch code
- batch analytics aggregation (students/teachers/grade items)
- integration with Zoho CRM for student placement/status

## Prerequisites

- Moodle 4.x+ (compatible with your Moodle version)
- PHP 8.x
- Zoho CRM credentials and API permissions

## Installation

1. Copy this folder to `moodle/local/batchanalytics`.
2. Visit `Site administration > Notifications` in Moodle to install plugin.
3. Configure plugin settings (if added via `settings.php`).

## Usage

API endpoint actions are in `index.php`:

- `?action=searchcourses&keyword=<term>`
- `?action=getbatchcourses&batchcode=<code>`
- `?action=getcrmdata&username=<user>`
- `?action=getptfdata&username=<user>`
- `?action=getbatchfulldata&batchcode=<code>`

All return JSON responses.

## Configuration

### Zoho CRM (admin settings)

The plugin now exposes credential fields in the Moodle admin settings page:
`Site administration > Plugins > Local plugins > Batch Analytics`.

Fields:

- `Zoho CRM Client ID`
- `Zoho CRM Client Secret`
- `Zoho CRM Refresh Token`
- `Zoho Accounts URL` (default `https://accounts.zoho.com`)
- `Zoho API Base URL` (default `https://www.zohoapis.com`)

⚠️ Avoid committing secrets in source code. Use the admin settings instead of hardcoding credentials in `classes/crmapi.php`.

### Recommended secure config (example)

Use `get_config('local_batchanalytics', 'zoho_client_id')` etc.

## Permissions

This plugin uses Moodle capability `local/batchanalytics:view` for access control.

Grant permission to a role via:
- Site administration > Users > Permissions > Define roles > select role > "Allow" for Local > Batch Analytics: View

## Git Workflow

```bash
cd C:\Users\User\Desktop\batchanalytics
git init
git add .
git commit -m "chore: initial commit"
```

### Branch model

- `main` (or `master`) for released code
- `develop` for integration
- `feature/<name>` for features
- `hotfix/<name>` for patches

## Tagging

Create annotated release tags:

```bash
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
```

## Troubleshooting

- `git status` to check staged files
- `git log --oneline --decorate --max-count=10` to inspect history
- confirm `phpunit` path if adding tests
- confirm Moodle cache clearing after code deploy
