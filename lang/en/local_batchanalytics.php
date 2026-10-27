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
$string['batchanalytics:viewallcourses'] = 'View all courses';
$string['viewallcourses'] = 'View all courses';
$string['batchanalytics:viewassignedcourses'] = 'View assigned course only';
$string['viewassignedcourses'] = 'View assigned course only';
$string['batchanalytics:viewenrolledcourses'] = 'View assigned course only (view only the course which is assigned to (mentors, PM or sse))';
$string['viewenrolledcourses'] = 'View assigned course only (view only the course which is assigned to (mentors, PM or sse))';
$string['batchanalytics:viewreviewnotes'] = 'View review notes';
$string['viewreviewnotes'] = 'View review notes';
$string['batchanalytics:editreviewnotes'] = 'Edit review notes';
$string['editreviewnotes'] = 'Edit review notes';
$string['reviewnotes'] = 'Review Notes';
$string['btn_edit_note'] = 'Edit';
$string['btn_save_note'] = 'Save';
$string['btn_cancel_note'] = 'Cancel';
$string['edit_review_note'] = 'Edit review note';
$string['batchanalytics:viewcrmdata'] = 'View CRM Data (non restricted fields)';
$string['viewcrmdata'] = 'View CRM Data (non restricted fields)';
$string['batchanalytics:viewfullcrmdata'] = 'View CRM Data (restricted fields)';
$string['viewfullcrmdata'] = 'View CRM Data (restricted fields)';
$string['batchanalytics:manage'] = 'Manage Batch Analytics (full CRM data access)';
$string['manage'] = 'Manage Batch Analytics (full CRM data access)';
$string['nopermissiontoviewnotes'] = 'You do not have permission to view review notes.';
$string['nopermissiontoviewbatch'] = 'You do not have permission to view this batch because you are not assigned to it.';

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
$string['mentor_activity_grouping'] = 'Module Schedule Grouping';
$string['mentor_activity_grouping_desc'] = 'Define activities grouped by course pattern or subject (format: Group Name: Activity 1, Activity 2, ...). If no manual group is chosen in Course Settings, the course title is matched against these group names.';
$string['mentor_activity_grouping_hdr'] = 'Module Schedule Grouping';
$string['mentor_activity_group_select'] = 'Mentor Activity Group';
$string['mentor_activity_group_select_desc'] = 'Select which group of mentor activities applies to this course, or choose None.';
$string['mentor_activity_group_select_help'] = 'Select which group of mentor activities applies to this course, or choose None. If not set, the course title is automatically matched against the configured grouping rules.';
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
$string['cliq_tpl_ss_completed_desc'] = 'Instant confirmation message sent to the SS Executive, SS Lead, and Current Module Mentors when the SS activity is marked complete.';

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

// Admin Settings Navigation
$string['settings_zoho_credential_config'] = 'Zoho Credential Config';
$string['settings_crm_field_configuration'] = 'CRM Field configuration';
$string['settings_mentor_activity_config'] = 'Mentor Activity Config';
$string['settings_zoho_cliq_template_config'] = 'Zoho Cliq Template config';

// Privacy API metadata strings
$string['privacy:metadata:batch_notes'] = 'Stores batch review notes written by users.';
$string['privacy:metadata:batch_notes:batchid'] = 'The ID of the batch or class section the note belongs to.';
$string['privacy:metadata:batch_notes:userid'] = 'The ID of the user who authored the review note.';
$string['privacy:metadata:batch_notes:author'] = 'The name of the author when the review note was posted.';
$string['privacy:metadata:batch_notes:body'] = 'The content of the review note.';
$string['privacy:metadata:batch_notes:timecreated'] = 'The timestamp when the note was recorded.';

$string['privacy:metadata:activity_tracker'] = 'Stores manual activity delivery status stamps completed by mentors or teachers.';
$string['privacy:metadata:activity_tracker:courseid'] = 'The course ID containing the activity.';
$string['privacy:metadata:activity_tracker:cmid'] = 'The course module ID of the tracked activity.';
$string['privacy:metadata:activity_tracker:completed'] = 'Whether the activity was marked complete.';
$string['privacy:metadata:activity_tracker:modifiedby'] = 'The user ID who marked the activity as completed.';
$string['privacy:metadata:activity_tracker:timemodified'] = 'The timestamp when the status was last updated.';

$string['privacy:metadata:cliq_log'] = 'Stores dispatch logs for Zoho Cliq notifications and reminders.';
$string['privacy:metadata:cliq_log:batchid'] = 'The batch ID associated with the notification.';
$string['privacy:metadata:cliq_log:courseid'] = 'The course ID associated with the notification.';
$string['privacy:metadata:cliq_log:activity_type'] = 'The type of activity (mentor, ss, or transition).';
$string['privacy:metadata:cliq_log:activity_key'] = 'The unique activity identifier.';
$string['privacy:metadata:cliq_log:stage'] = 'The notification trigger stage.';
$string['privacy:metadata:cliq_log:recipient_email'] = 'The email address of the notification recipient.';
$string['privacy:metadata:cliq_log:timesent'] = 'The timestamp when the alert was dispatched.';
$string['privacy:metadata:cliq_log:status'] = 'The dispatch delivery status.';

// Dashboard UI Strings
$string['search_placeholder'] = 'Search section or batch code (e.g., 26011A, 25002A)...';
$string['reset'] = 'Reset';
$string['filters'] = 'FILTERS';
$string['filter_year_all'] = 'Year — All';
$string['filter_batch_all'] = 'Section / Batch — All';
$string['filter_course_all'] = 'Course — All';
$string['filter_mode_all'] = 'Mode — All';
$string['my_running_batches'] = 'My Running Batches';
$string['my_completed_batches'] = 'My Completed Batches';
$string['current_running_batches'] = 'Current Running Batches';
$string['completed_batches'] = 'Completed Batches';
$string['export_csv'] = 'Export';
$string['showing_zero_info'] = 'Showing 0 of 0';
$string['col_batch_section'] = 'BATCH / SECTION';
$string['col_course'] = 'COURSE';
$string['col_mode'] = 'MODE';
$string['col_type'] = 'TYPE';
$string['col_start'] = 'START';
$string['col_current_module'] = 'CURRENT MODULE';
$string['col_status'] = 'STATUS';
$string['loading_batch_analytics'] = 'Loading batch analytics...';
$string['modal_mentors_title'] = 'Mentors';
$string['modal_mentors_sub'] = 'Assigned mentors and active batches';
$string['modal_search_placeholder'] = 'Search mentor name or batch...';
$string['close'] = 'Close';

// Batch & Module Detail UI Strings
$string['col_module'] = 'Module';
$string['col_class_mentor'] = 'Class Mentor';
$string['col_lab_mentor'] = 'Lab Mentor';
$string['col_plan_start'] = 'Plan Start';
$string['col_plan_end'] = 'Plan End';
$string['col_actual_start'] = 'Actual Start';
$string['col_actual_end'] = 'Actual End';
$string['col_delay'] = 'Delay';
$string['no_modules_configured'] = 'No modules configured for this batch.';
$string['view_module'] = 'View Module';
$string['tab_schedule'] = 'Schedule';
$string['tab_ss_activities'] = 'SS Activities';
$string['tab_student_performance'] = 'Student Performance';
$string['tab_review_notes'] = 'Review Notes';
$string['tab_crm_data'] = 'CRM Data';
$string['sched_panel_note'] = 'One row per module in the batch\'s configured sequence. <b>Delay</b> is colour-coded. Open any module for its detail.';
$string['ss_panel_note'] = 'Batch-level soft skill activities from Batch Management. Displays planned date, actual completion date, and current status.';
$string['col_activity'] = 'Activity';
$string['col_planned_date'] = 'Planned Date';
$string['col_actual_date'] = 'Actual Date';
$string['no_ss_activities'] = 'No SS activities are configured for this batch.';
$string['perf_panel_note'] = 'Two ways to band the same students: <b>Grade</b> (by actual score) or <b>Completion %</b> (default 15/70/15 by rank). Merit and core signals stay the same; only the banding changes.';
$string['btn_export_filtered'] = 'Export Filtered';
$string['col_band'] = 'Band';
$string['col_student'] = 'Student';
$string['col_grade'] = 'Grade';
$string['col_attendance'] = 'Attendance';
$string['col_merit'] = 'Merit';
$string['rows_per_page'] = 'Rows per page:';
$string['crm_panel_note'] = 'CRM data for all students in this batch, fetched live from Zoho CRM. Use the filters to narrow by placement status, PET eligibility, scores, and more.';
$string['filters_reset_all'] = 'Reset All';
$string['search_name_id'] = 'Search Name / ID';
$string['placement_status'] = 'Placement Status';
$string['pet_status'] = 'PET Status';
$string['maac_rating'] = 'MAAC Rating';
$string['adv_c_mock_score'] = 'Adv C Mock Score';
$string['year_of_passing'] = 'Year of Passing';
$string['home_state'] = 'Home State';
$string['loading_crm_data'] = 'Loading CRM data...';
$string['loading_students'] = 'Loading students...';
$string['retry_crm_data'] = 'Retry CRM Data';
$string['click_crm_tab_to_load'] = 'Click the CRM Data tab to load student CRM data.';
$string['notes_panel_note'] = 'Notes captured by the Program Manager during batch reviews. Newest first. Each note is timestamped with the author.';
$string['add_review_note_placeholder'] = 'Add a review note for this batch…';
$string['btn_add_note'] = 'Add note';
$string['btn_edit_note'] = 'Edit';
$string['btn_save_note'] = 'Save';
$string['btn_cancel_note'] = 'Cancel';
$string['latest_note'] = 'Latest Note';
$string['saving_note'] = 'Saving…';
$string['role_program_manager'] = 'Program Manager';
$string['role_ss_executive'] = 'SS / MAAC Executive';
$string['schedule'] = 'Schedule';
$string['planned_start'] = 'Planned Start';
$string['planned_end'] = 'Planned End';
$string['actual_start'] = 'Actual Start';
$string['actual_end'] = 'Actual End';
$string['delay'] = 'Delay';
$string['attendance'] = 'Attendance';
$string['module_kpis'] = 'Module KPIs';
$string['module_kpis_sub'] = '· auto-pulled from LMS · common to mentors &amp; SS team';
$string['grade'] = 'Grade';
$string['completion_pct'] = 'Completion %';
$string['no_data_available_module'] = 'No data available for this module.';
$string['in_progress'] = 'In progress';

// Privacy Subsystem strings.
$string['cliqnotifications'] = 'Cliq Notifications';
$string['activitytracker'] = 'Activity Tracker';
$string['privacy:metadata:batch_notes'] = 'Stores batch review notes written by users';
$string['privacy:metadata:batch_notes:batchid'] = 'The ID of the batch being reviewed';
$string['privacy:metadata:batch_notes:userid'] = 'The ID of the author who created the review note';
$string['privacy:metadata:batch_notes:author'] = 'The name of the author';
$string['privacy:metadata:batch_notes:body'] = 'The content of the review note';
$string['privacy:metadata:batch_notes:timecreated'] = 'The timestamp when the note was created';
$string['privacy:metadata:activity_tracker'] = 'Stores module activity completion tracking data';
$string['privacy:metadata:activity_tracker:courseid'] = 'The ID of the course';
$string['privacy:metadata:activity_tracker:cmid'] = 'The ID of the course module';
$string['privacy:metadata:activity_tracker:completed'] = 'Whether the activity was completed';
$string['privacy:metadata:activity_tracker:modifiedby'] = 'The ID of the user who modified the completion status';
$string['privacy:metadata:activity_tracker:timemodified'] = 'The timestamp when the completion status was modified';
$string['privacy:metadata:cliq_log'] = 'Stores records of Zoho Cliq notification dispatches';
$string['privacy:metadata:cliq_log:batchid'] = 'The ID of the batch';
$string['privacy:metadata:cliq_log:courseid'] = 'The ID of the course';
$string['privacy:metadata:cliq_log:activity_type'] = 'The type of activity';
$string['privacy:metadata:cliq_log:activity_key'] = 'The key identifying the activity';
$string['privacy:metadata:cliq_log:stage'] = 'The stage of the notification';
$string['privacy:metadata:cliq_log:recipient_email'] = 'The email address of the notification recipient';
$string['privacy:metadata:cliq_log:timesent'] = 'The timestamp when the notification was dispatched';
$string['privacy:metadata:cliq_log:status'] = 'The status of the notification dispatch';
