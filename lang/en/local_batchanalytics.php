<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English language strings for local_batchanalytics
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Batch Analytics';
$string['batchanalytics:view'] = 'View batch analytics';
$string['crmsettings'] = 'CRM Integration Settings';
$string['crmsettingsdesc'] = 'Configure CRM API credentials for fetching placement data';
$string['zoho_client_id'] = 'Zoho CRM Client ID';
$string['zoho_client_id_desc'] = 'OAuth client_id for Zoho CRM API';
$string['zoho_client_secret'] = 'Zoho CRM Client Secret';
$string['zoho_client_secret_desc'] = 'OAuth client_secret for Zoho CRM API';
$string['zoho_refresh_token'] = 'Zoho CRM Refresh Token';
$string['zoho_refresh_token_desc'] = 'OAuth refresh_token for Zoho CRM API';
$string['zoho_accounts_url'] = 'Zoho Accounts URL';
$string['zoho_accounts_url_desc'] = 'Zoho OAuth token endpoint base URL (e.g., https://accounts.zoho.com)';
$string['zoho_api_base_url'] = 'Zoho API Base URL';
$string['zoho_api_base_url_desc'] = 'Zoho CRM data API base URL (e.g., https://www.zohoapis.com)';
$string['crmconfigmissing'] = 'CRM configuration missing';
$string['crmconnectionfailed'] = 'CRM connection failed';
$string['crmtokenfailed'] = 'CRM token request failed';
$string['batchanalytics:manage'] = 'Manage batch analytics (full CRM access)';
$string['allowed_course_keywords'] = 'Allowed course keywords';
$string['allowed_course_keywords_desc'] = 'Comma-separated list of course name keywords. Only courses whose name contains at least one of these keywords will appear. Only batch groups containing matching courses will be shown in the dropdown. Leave empty to show all courses.';
$string['crm_fields_config'] = 'CRM Fields Configuration';
$string['crm_fields_config_desc'] = 'Define the CRM fields shown in the PTF/CRM data table. Drag rows to reorder columns. <strong>Numeric</strong>: sort &amp; align as a number. <strong>Restricted</strong>: hide this column from users with only the View capability (Manage users always see all columns).';