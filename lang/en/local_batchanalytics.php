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
$string['batchanalytics:view'] = 'View batchanalytics';
$string['view'] = 'View batchanalytics';
$string['batchanalytics:myaddinstance'] = 'Add block to dashboard';
$string['myaddinstance'] = 'Add block to dashboard';
$string['batchanalytics:addinstance'] = 'Add Batch Analytics block';
$string['addinstance'] = 'Add Batch Analytics block';
$string['batchanalytics:viewallcourses'] = 'View all course data (all courses from the data of batch management)';
$string['viewallcourses'] = 'View all course data (all courses from the data of batch management)';
$string['batchanalytics:viewassignedcourses'] = 'View assigned course only (view only the course which is assigned to (mentors, PM or sse))';
$string['viewassignedcourses'] = 'View assigned course only (view only the course which is assigned to (mentors, PM or sse))';
$string['batchanalytics:viewenrolledcourses'] = 'View assigned course only (view only the course which is assigned to (mentors, PM or sse))';
$string['viewenrolledcourses'] = 'View assigned course only (view only the course which is assigned to (mentors, PM or sse))';
$string['batchanalytics:viewreviewnotes'] = 'View review notes';
$string['viewreviewnotes'] = 'View review notes';
$string['batchanalytics:editreviewnotes'] = 'Edit review notes';
$string['editreviewnotes'] = 'Edit review notes';
$string['batchanalytics:viewcrmdata'] = 'View crm data (non restricted fields)';
$string['viewcrmdata'] = 'View crm data (non restricted fields)';
$string['batchanalytics:viewfullcrmdata'] = 'View full crm data';
$string['viewfullcrmdata'] = 'View full crm data';
$string['batchanalytics:manage'] = 'Manage Batch Analytics (full CRM data access)';
$string['manage'] = 'Manage Batch Analytics (full CRM data access)';
$string['nopermissiontoviewnotes'] = 'You do not have permission to view review notes.';

// Zoho CRM configuration strings
$string['crmsettings'] = 'CRM Integration Settings';
$string['crmsettingsdesc'] = 'Configure CRM API credentials for fetching placement data';
$string['zoho_configuration'] = 'Zoho Configuration';
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

// CRM fields
$string['crm_fields_config'] = 'CRM Fields Configuration';
$string['crm_fields_config_desc'] = 'Define the CRM fields shown in the PTF/CRM data table. Drag rows to reorder columns. <strong>Numeric</strong>: sort &amp; align as a number. <strong>Restricted</strong>: hide this column from users with only the View capability (Manage users always see all columns).';

// Student CRM settings
$string['student_crm_data'] = 'Student CRM Data';
$string['student_crm_module_api_name'] = 'Student CRM module API name';
$string['student_crm_module_api_name_desc'] = 'Zoho CRM module API name used by the CRM Data tab.';

// Module Tracker
$string['module_tracker'] = 'Module Tracker';
$string['module_tracker_configuration'] = 'Module Tracker Categories';
$string['module_tracker_categories'] = 'Tracker categories';
$string['module_tracker_categories_desc'] = 'Add the Module Tracker tab name and the comma-separated Gradebook category names or phrases that belong in it.';
$string['invalidactivity'] = 'The selected activity is not available in this course.';
$string['invalidcompletiondate'] = 'Enter a valid completion date.';
$string['activity_tracker_upgrade_required'] = 'Module Tracker data is not available yet. Complete the Moodle plugin upgrade, then reload this page.';
$string['modal_close'] = 'Close';

// Mentor Activities
$string['mentor_activities_heading'] = 'Mentor Activities Configuration';
$string['mentor_activities_heading_desc'] = 'Configure the master list of mentor activities and their course grouping rules.';
$string['mentor_master_activities'] = 'Master Activity Pool';
$string['mentor_master_activities_desc'] = 'List all possible mentor activities (one per line).';
$string['mentor_activity_grouping'] = 'Course Grouping Rules';
$string['mentor_activity_grouping_desc'] = 'Define activities grouped by course pattern or subject (format: Group Name: Activity 1, Activity 2, ...). If no manual group is chosen in Course Settings, the course title is matched against these group names.';
$string['mentor_activity_grouping_hdr'] = 'Mentor Activities';
$string['mentor_activity_group_select'] = 'Mentor Activity Group';
$string['mentor_activity_group_select_desc'] = 'Select which group of mentor activities applies to this course, or choose None.';
$string['mentor_activity_none'] = 'None (No Mentor Activity)';
$string['mentor_activity_autodetect'] = 'None (No Mentor Activity)';
$string['mentor_activities_empty'] = 'For this module there is no mentor activity.';
$string['mentor_activities_saved'] = 'Mentor activity saved successfully.';
$string['mentor_activities_group_badge'] = 'Activity Group';

// Role & Operational Task Configuration
$string['role_configuration'] = 'Operational Roles & Task Assignments';
$string['role_configuration_desc'] = 'Select which Moodle roles function as Mentors, SS Executives, Program Managers, and Assistant Managers. Assigned users will automatically see their module mentor activities and batch soft skill tasks in their Dashboard To-Do block.';
$string['mentor_roles'] = 'Mentor Roles';
$string['mentor_roles_desc'] = 'Users with these roles (or assigned as mentors in batch module data) will see module mentor activities due.';
$string['ssexecutive_roles'] = 'SS / MAAC Executive Roles';
$string['ssexecutive_roles_desc'] = 'Users with these roles (or assigned as maacexecutive in class sections) will see batch soft skill activities.';
$string['sslead_roles'] = 'SS Lead Roles';
$string['sslead_roles_desc'] = 'Users with these roles will be treated as the Soft Skills (SS) Lead and receive SS activity escalations and completion alerts.';
$string['sslead_email'] = 'SS Lead Direct Email (Optional)';
$string['sslead_email_desc'] = 'Optional direct email address of the SS Lead to receive SS activity escalations and completion alerts (comma-separated if multiple).';
$string['program_manager_roles'] = 'Program Manager Roles';
$string['program_manager_roles_desc'] = 'Users with these roles (or assigned as pmmanager in class sections) will see batch milestones and soft skill activities.';
$string['assistant_manager_roles'] = 'Assistant Manager Roles';
$string['assistant_manager_roles_desc'] = 'Users with these roles will see batch setup and soft skill planning activities.';
$string['mytodolist'] = 'My To-Do';
$string['forthcoming'] = 'Forthcoming';
$string['markcomplete'] = 'Mark Complete';
$string['completed'] = 'Completed';
$string['invalidmodule'] = 'The selected module was not found.';
$string['invalidbatch'] = 'The selected batch or section was not found.';
$string['invalidparams'] = 'Invalid parameters supplied.';
$string['invalidaction'] = 'Invalid action specified.';
$string['modulestartsaved'] = 'Module actual start date recorded.';
$string['moduleendsaved'] = 'Module completion recorded.';
$string['mentorassigned'] = 'Module mentor assignment updated.';
$string['role_mentors'] = 'Mentors';
$string['role_pm'] = 'Program Manager';
$string['role_sse'] = 'SS Executive';
$string['role_sslead'] = 'SS Lead';
$string['role_sspm'] = 'SS / PM';
$string['role_am'] = 'Assistant Manager';
$string['role_admin'] = 'Admin';

// Zoho Cliq Notifications
$string['zoho_cliq_heading'] = 'Zoho Cliq Notifications';
$string['zoho_cliq_heading_desc'] = 'Automated notifications for Mentor activities (module-based) and SS activities (batch-based) sent at T-3 days, Due date, T+3 days overdue, and T+5 days escalation, plus instant completion alerts.';
$string['zoho_cliq_enabled'] = 'Enable Zoho Cliq Notifications';
$string['zoho_cliq_enabled_desc'] = 'Enable automated activity due reminders, overdue escalations, and completion alerts to Zoho Cliq.';
$string['zoho_cliq_bot_url'] = 'Zoho Cliq Bot Message URL';
$string['zoho_cliq_bot_url_desc'] = 'Endpoint URL for the Zoho Cliq bot message API (e.g. https://cliq.zoho.com/api/v2/bots/batchinformer/message).';
$string['zoho_cliq_zapikey'] = 'Bot ZAPI Key (Token)';
$string['zoho_cliq_zapikey_desc'] = 'The zapikey token authorizing the bot message API.';
$string['zoho_cliq_default_channel'] = 'Default Fallback Channel (Optional)';
$string['zoho_cliq_default_channel_desc'] = 'Optional Zoho Cliq channel unique name to receive alerts if direct user messaging is unavailable.';
$string['task_evaluate_activity_due'] = 'Evaluate Mentor and SS activity due dates for Zoho Cliq notifications';

// Mentor Activity Message Templates
$string['zoho_cliq_mentor_heading'] = 'Mentor Activity Notification Templates (Module-Based)';
$string['zoho_cliq_mentor_heading_desc'] = 'Customize the Zoho Cliq messages sent for module-level mentor activities. Placeholders available: {batch_name}, {module_name}, {task_name}, {due_date}, {mentor_name}, {pm_name}, {overdue_days}, {completed_by}, {completion_date}, {link}.';
$string['cliq_tpl_mentor_t_minus_3'] = 'Mentor Activity: 3 Days Before Due Template';
$string['cliq_tpl_mentor_t_minus_3_desc'] = 'Message sent to the assigned mentor 3 days before the module activity due date.';
$string['cliq_tpl_mentor_due_today'] = 'Mentor Activity: Due Today Template';
$string['cliq_tpl_mentor_due_today_desc'] = 'Message sent to the assigned mentor on the due date.';
$string['cliq_tpl_mentor_t_plus_3'] = 'Mentor Activity: 3 Days Overdue Template';
$string['cliq_tpl_mentor_t_plus_3_desc'] = 'Warning message sent to the assigned mentor when the activity is 3 days overdue.';
$string['cliq_tpl_mentor_t_plus_5'] = 'Mentor Activity: 5th Day PM Escalation Template';
$string['cliq_tpl_mentor_t_plus_5_desc'] = 'Escalation message sent to the Program Manager when the mentor activity is 5+ days overdue.';
$string['cliq_tpl_mentor_completed'] = 'Mentor Activity: Completion Confirmation Template';
$string['cliq_tpl_mentor_completed_desc'] = 'Instant confirmation message sent to the PM and Mentor when the activity is marked complete.';

// SS Activity Message Templates
$string['zoho_cliq_ss_heading'] = 'SS Activity Notification Templates (Batch-Based)';
$string['zoho_cliq_ss_heading_desc'] = 'Customize the Zoho Cliq messages sent for batch-level SS / Soft Skills milestones. Placeholders available: {batch_name}, {activity_name}, {due_date}, {sse_name}, {ss_lead_name}, {overdue_days}, {completed_by}, {completion_date}, {link}.';
$string['cliq_tpl_ss_t_minus_3'] = 'SS Activity: 3 Days Before Due Template';
$string['cliq_tpl_ss_t_minus_3_desc'] = 'Message sent to the SSE / MAAC Executive 3 days before the activity due date.';
$string['cliq_tpl_ss_due_today'] = 'SS Activity: Due Today Template';
$string['cliq_tpl_ss_due_today_desc'] = 'Message sent to the SSE / MAAC Executive on the due date.';
$string['cliq_tpl_ss_t_plus_3'] = 'SS Activity: 3 Days Overdue Template';
$string['cliq_tpl_ss_t_plus_3_desc'] = 'Warning message sent to the SSE / MAAC Executive when the activity is 3 days overdue.';
$string['cliq_tpl_ss_t_plus_5'] = 'SS Activity: 5th Day SS Lead Escalation Template';
$string['cliq_tpl_ss_t_plus_5_desc'] = 'Escalation message sent to the SS Lead and SS Executive when the SS activity is 5+ days overdue.';
$string['cliq_tpl_ss_completed'] = 'SS Activity: Completion Confirmation Template';
$string['cliq_tpl_ss_completed_desc'] = 'Instant confirmation message sent to the SS Executive and SS Lead when the SS activity is marked complete.';

// Stage & Module Transition Message Templates
$string['zoho_cliq_transition_heading'] = 'Stage & Module Transition Message Templates';
$string['zoho_cliq_transition_heading_desc'] = 'Notifications and emails dispatched when a batch transitions to the next module/stage (based on actual start date). Placeholders available: {batch_name}, {previous_module}, {next_module}, {transition_date}, {pm_name}, {sse_name}, {next_mentor_name}, {next_class_mentor}, {next_lab_mentor}, {overall_attendance}, {overall_maac_rating}, {student_count}, {prev_module_completion}, {batch_status}, {link}.';
$string['cliq_tpl_stage_transition'] = 'Stage Transition Template';
$string['cliq_tpl_stage_transition_desc'] = 'Notification and email sent to Program Manager, SS Executive, Class Mentor, and Lab Mentor when a batch moves to the next module/stage.';
$string['cliq_tpl_module_assigned_mentor'] = 'Module Assigned: Class/Lab Mentor Template';
$string['cliq_tpl_module_assigned_mentor_desc'] = 'Notification and email sent to assigned Class and Lab Mentors when a module is assigned or begins for a batch.';

// Dedicated Templates Page Strings
$string['zoho_cliq_templates'] = 'Zoho Cliq Notification Templates';
$string['zoho_cliq_templates_nav'] = 'Zoho Cliq Notification Templates';
$string['zoho_cliq_manage_templates'] = 'Customize Zoho Cliq Notification Templates';
$string['zoho_cliq_manage_templates_desc'] = 'Open the dedicated collapsible editor to customize Subjects, Message Bodies, and Dynamic Placeholders for Mentor & SS activities.';
$string['zoho_cliq_open_templates_page'] = 'Open Templates Editor';
$string['templates_saved_success'] = 'Zoho Cliq notification templates have been updated successfully.';
