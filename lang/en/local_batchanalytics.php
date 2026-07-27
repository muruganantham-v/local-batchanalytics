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
$string['local/batchanalytics:view'] = 'View batch analytics';
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
$string['local/batchanalytics:manage'] = 'Manage batch analytics (full CRM access)';
$string['local/batchanalytics:editmaac'] = 'Edit MAAC data';
$string['local/batchanalytics:viewallcourses'] = 'View all courses in batch analytics';
$string['local/batchanalytics:viewenrolledcourses'] = 'View enrolled courses in batch analytics';
$string['local/batchanalytics:viewmaac'] = 'View MAAC data';
$string['local/batchanalytics:viewtickets'] = 'View ticket dashboard';
$string['local/batchanalytics:managetickets'] = 'Manage tickets';
$string['local/batchanalytics:manageescalatedtickets'] = 'Manage escalated tickets for PM';
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
$string['module_tracker'] = 'Module Tracker';
$string['invalidactivity'] = 'The selected activity is not available in this course.';
$string['invalidcompletiondate'] = 'Enter a valid completion date.';
$string['activity_tracker_upgrade_required'] = 'Module Tracker data is not available yet. Complete the Moodle plugin upgrade, then reload this page.';
$string['feedback_duration_days'] = 'Feedback duration (days)';
$string['feedback_duration_days_desc'] = 'Number of recent calendar days used to create attendance, assignment, test, and project feedback suggestions.';
$string['ss_team_role'] = 'SS team role';
$string['ss_team_role_desc'] = 'Select the course role used to identify SS team members for MAAC ticket routing.';
$string['batch_manager_role'] = 'Batch manager role';
$string['batch_manager_role_desc'] = 'Select the course role used to identify batch managers for MAAC ticket routing.';
$string['ticket_configuration'] = 'Ticket Configuration';
$string['auto_assign_ticket_to_pm_manager'] = 'Auto Assign ticket to PM manager';
$string['ticket_duration'] = 'Ticket duration';
$string['ticket_duration_desc'] = 'Assign Ticket to PM after this no of days';
$string['ticket_edit_window_hours'] = 'Ticket edit window (hours)';
$string['ticket_edit_window_hours_desc'] = 'The number of hours after a ticket is created during which the creator can edit the ticket title and reason. (Enter 0 to disable editing completely)';
$string['add_ticket_template'] = 'Add Ticket Template';
$string['manage_ticket_templates'] = 'Manage Ticket Templates';
$string['ticket_template'] = 'Ticket Template';
$string['ticket_template_title'] = 'Title';
$string['ticket_template_body'] = 'Message body';
$string['ticket_templates_desc'] = 'Create reusable ticket templates. Selecting a template while raising a ticket will fill the ticket title and reason fields.';
$string['ticket_templates_saved'] = 'Ticket templates saved successfully.';
$string['modal_close'] = 'Close';
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
$string['ticket_batch_manager_missing'] = 'No batch manager was found in this course for ticket escalation.';
$string['ticket_invalid_student'] = 'The selected student is not available in this course.';
$string['cliq_bot_url'] = 'Cliq bot URL';
$string['cliq_bot_url_desc'] = 'Webhook or endpoint URL used by the Cliq bot integration.';
$string['cliq_bot_key'] = 'Cliq bot Key';
$string['cliq_bot_key_desc'] = 'Secret key or token used to authenticate Cliq bot requests.';
$string['cliq_template_settings'] = 'Cliq Template Settings';
$string['configure_cliq_templates'] = 'Configure Cliq Templates';
$string['view_cliq_message_history'] = 'View Message History';
$string['cliq_templates_placeholder'] = 'Cliq template configuration will be added here.';
$string['cliq_message_history_placeholder'] = 'Cliq message history will be shown here.';
$string['cliq_back_to_settings'] = 'Back to settings';
$string['zoho_configuration'] = 'Zoho Configuration';
$string['crm_field_configuration'] = 'CRM Field Configuration';
$string['maac_sheet_columns'] = 'MAAC Sheet Columns';
$string['role_management'] = 'Role Management';
$string['cliq_configuration'] = 'Cliq Configuration';
$string['cliq_available_placeholders'] = 'Available placeholders';
$string['cliq_available_placeholders_desc'] = 'Use these placeholders inside the title or message body. They will be replaced when the Cliq message is sent.';
$string['cliq_ticket_raise_template'] = 'Cliq Ticket Raising message template';
$string['cliq_ticket_update_template'] = 'Cliq Ticket Update message template';
$string['cliq_ticket_resolved_template'] = 'Cliq Ticket Resolved message template';
$string['cliq_ticket_auto_pm_template'] = 'Assign ticket to PM manager';
$string['cliq_template_title'] = 'Title';
$string['cliq_template_message_body'] = 'Message body';
$string['cliq_templates_saved'] = 'Cliq templates saved successfully.';
$string['cliq_message_history_empty'] = 'No Cliq messages have been recorded yet.';
$string['cliq_history_table_missing'] = 'Cliq message history table is not available yet. Complete the plugin upgrade first.';
$string['cliq_history_type'] = 'Type';
$string['cliq_history_recipient'] = 'Recipient';
$string['cliq_history_subject'] = 'Subject';
$string['cliq_history_status'] = 'Status';
$string['cliq_history_error'] = 'Response / Error';

$string['task_auto_assign_tickets_to_pm'] = 'Auto assign overdue tickets to PM manager';

$string['student_crm_data'] = 'Student CRM Data';
$string['mentor_crm_data'] = 'Mentor Details CRM Data';
$string['student_crm_module_api_name'] = 'Student CRM module API name';
$string['student_crm_module_api_name_desc'] = 'Zoho CRM module API name used by the CRM Data tab.';
$string['mentor_crm_module_api_name'] = 'Mentor CRM module API name';
$string['mentor_crm_module_api_name_desc'] = 'Zoho CRM module API name used by the Mentor Details tab.';
$string['mentor_crm_batch_field_key'] = 'Mentor CRM batch field API name';
$string['mentor_crm_batch_field_key_desc'] = 'CRM field API name used to find the mentor record by Moodle batch group name, for example 25001.';
$string['mentor_crm_fields_config'] = 'Mentor CRM fields configuration';
$string['mentor_crm_fields_config_desc'] = 'Define the CRM fields fetched and shown in the Mentor Details tab. Use the Zoho CRM field API name and a display label.';
$string['mentor_crm_field_groups'] = 'Mentor CRM field groups';
$string['mentor_crm_field_groups_desc'] = 'Create groups and select the Mentor CRM fields that belong to each group. The Mentor Details tab displays each group as a separate section.';
$string['mentor_details'] = 'Mentor Details';
