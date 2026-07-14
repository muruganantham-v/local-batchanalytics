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
$string['batchanalytics:editmaac'] = 'Edit MAAC data';
$string['batchanalytics:viewallcourses'] = 'View all courses in batch analytics';
$string['batchanalytics:viewenrolledcourses'] = 'View enrolled courses in batch analytics';
$string['batchanalytics:viewmaac'] = 'View MAAC data';
$string['batchanalytics:viewtickets'] = 'View ticket dashboard';
$string['batchanalytics:managetickets'] = 'Manage tickets';
$string['allowed_course_keywords'] = 'Allowed course keywords';
$string['allowed_course_keywords_desc'] = 'Comma-separated list of course name keywords. Only courses whose name contains at least one of these keywords will appear. Only batch groups containing matching courses will be shown in the dropdown. Leave empty to show all courses.';
$string['crm_fields_config'] = 'CRM Fields Configuration';
$string['crm_fields_config_desc'] = 'Define the CRM fields shown in the PTF/CRM data table. Drag rows to reorder columns. <strong>Numeric</strong>: sort &amp; align as a number. <strong>Restricted</strong>: hide this column from users with only the View capability (Manage users always see all columns).';
$string['maac_custom_columns'] = 'MAAC custom columns';
$string['maac_custom_columns_desc'] = 'Define the editable custom columns shown on the MAAC page. Each row needs a column name and a data type.';
$string['maac_column_groups'] = 'MAAC column groups';
$string['maac_column_groups_desc'] = 'Define grouped column sections for the MAAC page. Add a group name and select the custom columns that belong to that group.';
$string['maac_page_title'] = 'MAAC Details';
$string['maac_sheet'] = 'MAAC sheet';
$string['ss_team_role'] = 'SS team role';
$string['ss_team_role_desc'] = 'Select the course role used to identify SS team members for MAAC ticket routing.';
$string['batch_manager_role'] = 'Batch manager role';
$string['batch_manager_role_desc'] = 'Select the course role used to identify batch managers for MAAC ticket routing.';
$string['trend_window'] = 'Trend window';
$string['trend_window_desc'] = 'Number of recent graded activities to use when calculating grade trends.';
$string['attendance_window'] = 'Attendance window';
$string['attendance_window_desc'] = 'Number of recent attendance records to use when calculating attendance trends.';
$string['ticket_title'] = 'Ticket Title';
$string['ticket_reason'] = 'Reason for Raising Ticket';
$string['ticket_saved'] = 'Ticket raised successfully';
$string['ticket_dashboard'] = 'Ticket Dashboard';
$string['ticket_dashboard_title'] = 'Tickets';
$string['ticket_my_new'] = 'My New Tickets';
$string['ticket_my_resolved'] = 'My Resolved Tickets';
$string['ticket_view'] = 'View';
$string['ticket_resolve'] = 'Resolve';
$string['ticket_resolved'] = 'Resolved';
$string['ticket_open'] = 'Open';
$string['ticket_raised_by'] = 'Raised By';
$string['ticket_raised_to'] = 'Raised To';
$string['ticket_feedback'] = 'Resolution Feedback';
$string['ticket_resolve_success'] = 'Ticket resolved successfully';
$string['ticket_update_success'] = 'Ticket updated successfully';
$string['ticket_feedback_required'] = 'Resolution feedback is required.';
$string['ticket_invalid'] = 'The selected ticket is not available.';
$string['ticket_resolve_not_allowed'] = 'You are not allowed to resolve this ticket.';
$string['ticket_already_resolved'] = 'This ticket has already been resolved.';
$string['ticket_role_not_configured'] = 'Ticket roles are not configured.';
$string['ticket_assignees_missing'] = 'No matching role users were found in this course for ticket assignment.';
$string['ticket_invalid_student'] = 'The selected student is not available in this course.';
