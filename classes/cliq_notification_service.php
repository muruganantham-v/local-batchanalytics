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

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Service for managing Zoho Cliq (Kajal Bot) message templates, placeholders, and notifications.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cliq_notification_service {

    const CONFIG_KEY = 'cliq_notification_templates';

    /**
     * Default message templates catalogued from Kajal Bot Specification.
     */
    const DEFAULT_TEMPLATES = [
        // =====================================================================
        // 1. Project Manager (PM) Templates (PM-01 to PM-12)
        // =====================================================================
        [
            'id' => 'PM-01',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Batch created',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'info',
            'title' => 'New batch created',
            'template' => "ℹ️ **New batch created**\nBatch: {batch_id} ({mode})\nCourse: {course_name}\nStart date: {start_date} · Planned end: {planned_end}\nYou are assigned as **PM**.\nView batch: {lms_link}",
        ],
        [
            'id' => 'PM-02',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Escalation',
            'status' => 'Existing',
            'escalation' => 'PM is the escalation target',
            'threshold_days' => 2,
            'severity' => 'escalation',
            'title' => 'Activity Escalation',
            'template' => "🚨 **ESCALATION**\nActivity: {activity}\nBatch / Module: {batch_id} / {module}\nOwner: {owner} ({owner_role})\nOverdue by: {delay_days} day(s)\nDue date was: {due_date}\nThe owner has been reminded but has not completed this. Please follow up.\n{lms_link}",
        ],
        [
            'id' => 'PM-03',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Schedule slip',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 1,
            'severity' => 'warning',
            'title' => 'Schedule slip',
            'template' => "⚠️ **Schedule slip**\n{batch_id} / {module} is delayed by {delay_days} day(s) vs plan.\nPlanned end: {planned_end} · Revised end: {revised_end}\nDownstream modules affected: {affected_modules}\nReview recommended.\n{lms_link}",
        ],
        [
            'id' => 'PM-04',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Activity completed',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'completed',
            'title' => 'Activity completed',
            'template' => "✅ **Activity completed**\n{activity} for {batch_id} / {module} marked done by {owner} on {date}.\n{lms_link}",
        ],
        [
            'id' => 'PM-05',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Stage transition',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'info',
            'title' => 'Stage transition',
            'template' => "ℹ️ **Stage transition**\n{batch_id} moved from {module} to {next_module}.\nNew mentor: {next_mentor}\nSS activities have been re-anchored to the new module dates.\n{lms_link}",
        ],
        [
            'id' => 'PM-06',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Nomination raised',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'milestone',
            'title' => 'Nomination raised',
            'template' => "🎉 **Nomination raised**\n{owner} nominated {count} student(s) for {award_type} in {batch_id}.\nNominees: {nominee_names}\nApproval pending. Please review and approve / reject.\n{lms_link}",
        ],
        [
            'id' => 'PM-07',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Nomination pending too long',
            'status' => 'NEW',
            'escalation' => '→ Reminder to PM',
            'threshold_days' => 2,
            'severity' => 'reminder',
            'title' => 'Nomination awaiting approval',
            'template' => "🔔 **Nomination awaiting your approval**\n{award_type} nomination in {batch_id} (raised by {owner} on {date}) is pending for {pending_days} day(s).\nPlease approve or reject.\n{lms_link}",
        ],
        [
            'id' => 'PM-08',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Batch closure',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'milestone',
            'title' => 'Batch completed',
            'template' => "🎉 **Batch completed**\n{batch_id} completed on {end_date}.\nPending closure items:\n• Closure meeting\n• Final review\nPlease schedule and complete both by {closure_due_date}.\n{lms_link}",
        ],
        [
            'id' => 'PM-09',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Module completed',
            'status' => 'NEW',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'completed',
            'title' => 'Module completed',
            'template' => "✅ **Module completed**\n{module} for {batch_id} ended on {actual_end} (planned {planned_end}).\nVariance: {delay_days} day(s).\nPending activities in this module: {pending_count}.\n{lms_link}",
        ],
        [
            'id' => 'PM-10',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Mid-batch review due',
            'status' => 'NEW',
            'escalation' => '→ Escalate if not held',
            'threshold_days' => 0,
            'severity' => 'reminder',
            'title' => 'Mid-batch review due',
            'template' => "🔔 **Mid-batch review due**\n{batch_id} has reached its mid-point ({midpoint_date}).\nPlease schedule the mid-batch review with {reviewers}.\n{lms_link}",
        ],
        [
            'id' => 'PM-11',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Weekly batch digest',
            'status' => 'NEW',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'info',
            'title' => 'Weekly digest',
            'template' => "ℹ️ **Weekly digest – {batch_id}**\nWeek of {week_start}\nCurrent module: {module}\nActivities done: {done_count} · Due this week: {due_count} · Overdue: {overdue_count}\nSchedule status: {schedule_status}\n{lms_link}",
        ],
        [
            'id' => 'PM-12',
            'recipient' => 'PM',
            'recipient_title' => 'Project Manager',
            'trigger' => 'Repeated overdue by same owner',
            'status' => 'NEW',
            'escalation' => '—',
            'threshold_days' => 2,
            'severity' => 'escalation',
            'title' => 'Repeated delays',
            'template' => "🚨 **Repeated delays**\n{owner} has {overdue_count} overdue activities in {batch_id} (oldest: {oldest_activity}, {delay_days}d).\nPlease discuss with the owner.\n{lms_link}",
        ],

        // =====================================================================
        // 2. Senior Student Executive (SSE) Templates (SSE-01 to SSE-07)
        // =====================================================================
        [
            'id' => 'SSE-01',
            'recipient' => 'SSE',
            'recipient_title' => 'Senior Student Executive',
            'trigger' => 'Batch created',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'info',
            'title' => 'New batch assigned',
            'template' => "ℹ️ **New batch assigned**\nBatch: {batch_id} ({mode})\nCourse: {course_name}\nStart date: {start_date}\nYou are assigned as **SSE**.\nView batch: {lms_link}",
        ],
        [
            'id' => 'SSE-02',
            'recipient' => 'SSE',
            'recipient_title' => 'Senior Student Executive',
            'trigger' => 'Stage transition',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'info',
            'title' => 'Stage transition',
            'template' => "ℹ️ **Stage transition**\n{batch_id} moved from {module} to {next_module}.\nSS activities have been re-anchored. Please review your upcoming SS activities and due dates.\n{lms_link}",
        ],
        [
            'id' => 'SSE-03',
            'recipient' => 'SSE',
            'recipient_title' => 'Senior Student Executive',
            'trigger' => 'SS activity due',
            'status' => 'NEW',
            'escalation' => '→ PM at threshold',
            'threshold_days' => 1,
            'severity' => 'reminder',
            'title' => 'SS activity reminder',
            'template' => "🔔 **Reminder:** {activity} for {batch_id} / {module} is due {due_date}.\nPlease complete it in the LMS.\n{lms_link}",
        ],
        [
            'id' => 'SSE-04',
            'recipient' => 'SSE',
            'recipient_title' => 'Senior Student Executive',
            'trigger' => 'SS activity overdue',
            'status' => 'NEW',
            'escalation' => '→ PM after {n} days',
            'threshold_days' => 2,
            'severity' => 'overdue',
            'title' => 'SS activity overdue',
            'template' => "⏰ **Overdue:** {activity} for {batch_id} / {module} is overdue by {delay_days} day(s).\nComplete it now, otherwise it will be escalated to the PM.\n{lms_link}",
        ],
        [
            'id' => 'SSE-05',
            'recipient' => 'SSE',
            'recipient_title' => 'Senior Student Executive',
            'trigger' => 'Activity completed',
            'status' => 'NEW',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'completed',
            'title' => 'Activity completed',
            'template' => "✅ {activity} for {batch_id} / {module} marked done by {owner} on {date}. Thank you.\n{lms_link}",
        ],
        [
            'id' => 'SSE-06',
            'recipient' => 'SSE',
            'recipient_title' => 'Senior Student Executive',
            'trigger' => 'Schedule slip',
            'status' => 'NEW',
            'escalation' => '—',
            'threshold_days' => 1,
            'severity' => 'warning',
            'title' => 'Schedule slip impact',
            'template' => "⚠️ {batch_id} / {module} is delayed by {delay_days} day(s) vs plan. Your SS activities may be re-anchored.\n{lms_link}",
        ],
        [
            'id' => 'SSE-07',
            'recipient' => 'SSE',
            'recipient_title' => 'Senior Student Executive',
            'trigger' => 'Batch closure',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'milestone',
            'title' => 'Batch completed',
            'template' => "🎉 **Batch completed**\n{batch_id} completed on {end_date}.\nClosure meeting and final review are due by {closure_due_date}.\nPlease complete your closure inputs.\n{lms_link}",
        ],

        // =====================================================================
        // 3. Class Mentor Templates (CM-01 to CM-13)
        // =====================================================================
        [
            'id' => 'CM-01',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Module started',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'info',
            'title' => 'Module started',
            'template' => "ℹ️ **Module started**\n{module} started for {batch_id} on {actual_start}.\nPlanned end: {planned_end}.\nActivities due in this module: {activity_count}.\n{lms_link}",
        ],
        [
            'id' => 'CM-02',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Activity due',
            'status' => 'Existing',
            'escalation' => '→ PM at threshold',
            'threshold_days' => 1,
            'severity' => 'reminder',
            'title' => 'Activity reminder',
            'template' => "🔔 **Reminder:** {activity} for {batch_id} / {module} is due {due_date}.\nPlease complete it in the LMS.\n{lms_link}",
        ],
        [
            'id' => 'CM-03',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Activity overdue',
            'status' => 'Existing',
            'escalation' => '→ PM after {n} days',
            'threshold_days' => 2,
            'severity' => 'overdue',
            'title' => 'Activity overdue',
            'template' => "⏰ **Overdue:** {activity} for {batch_id} / {module} is overdue ({delay_days}d).\nComplete it now, otherwise it will be escalated to the PM.\n{lms_link}",
        ],
        [
            'id' => 'CM-04',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Activity completed',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'completed',
            'title' => 'Activity completed',
            'template' => "✅ {activity} for {batch_id} / {module} marked done by you on {date}. Thank you.\n{lms_link}",
        ],
        [
            'id' => 'CM-05',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Stage transition (next mentor)',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'info',
            'title' => 'You are up next',
            'template' => "ℹ️ **You are up next**\n{batch_id} moved from {module} to {next_module}.\nYou are the mentor for {next_module}, starting {next_start_date}.\nPlease review the module plan and activities.\n{lms_link}",
        ],
        [
            'id' => 'CM-06',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Module ending soon',
            'status' => 'NEW',
            'escalation' => '—',
            'threshold_days' => 2,
            'severity' => 'reminder',
            'title' => 'Module ending soon',
            'template' => "🔔 {module} for {batch_id} ends on {planned_end} ({days_left} day(s) left).\nPending activities: {pending_activities}.\nPlease complete them before the module closes.\n{lms_link}",
        ],
        [
            'id' => 'CM-07',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Attendance not marked',
            'status' => 'NEW',
            'escalation' => '→ PM after {n} days',
            'threshold_days' => 1,
            'severity' => 'reminder',
            'title' => 'Attendance not marked',
            'template' => "🔔 Attendance for {batch_id} / {module} on {session_date} has not been marked.\nPlease update it in the LMS today.\n{lms_link}",
        ],
        [
            'id' => 'CM-08',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Assessment scheduled',
            'status' => 'NEW',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'reminder',
            'title' => 'Assessment scheduled',
            'template' => "🔔 **Assessment scheduled**\n{assessment_name} for {batch_id} / {module} is scheduled on {assessment_date}.\nPlease make sure questions / papers are ready.\n{lms_link}",
        ],
        [
            'id' => 'CM-09',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Assessment evaluation pending',
            'status' => 'NEW',
            'escalation' => '→ PM at threshold',
            'threshold_days' => 2,
            'severity' => 'overdue',
            'title' => 'Evaluation pending',
            'template' => "⏰ Evaluation of {assessment_name} for {batch_id} / {module} is pending ({delay_days}d). Please upload marks / results.\n{lms_link}",
        ],
        [
            'id' => 'CM-10',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Nomination submitted (confirmation)',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'completed',
            'title' => 'Nomination submitted',
            'template' => "✅ Your nomination of {count} student(s) for {award_type} in {batch_id} has been sent to the PM for approval.\n{lms_link}",
        ],
        [
            'id' => 'CM-11',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Nomination approved',
            'status' => 'NEW',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'milestone',
            'title' => 'Nomination approved',
            'template' => "🎉 Your {award_type} nomination in {batch_id} has been **approved** by {approver}.\nApproved: {approved_names}.\n{lms_link}",
        ],
        [
            'id' => 'CM-12',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Nomination rejected',
            'status' => 'NEW',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'info',
            'title' => 'Nomination not approved',
            'template' => "ℹ️ Your {award_type} nomination in {batch_id} was **not approved** by {approver}.\nRemarks: {remarks}.\nYou may re-submit with changes.\n{lms_link}",
        ],
        [
            'id' => 'CM-13',
            'recipient' => 'CM',
            'recipient_title' => 'Class Mentor',
            'trigger' => 'Batch closure',
            'status' => 'NEW',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'milestone',
            'title' => 'Batch closure thanks',
            'template' => "🎉 {batch_id} was completed on {end_date}. Thank you for your contribution.\nPlease complete any pending feedback / closure inputs by {closure_due_date}.\n{lms_link}",
        ],

        // =====================================================================
        // 4. Lab Mentor Templates (LM-01 to LM-10)
        // =====================================================================
        [
            'id' => 'LM-01',
            'recipient' => 'LM',
            'recipient_title' => 'Lab Mentor',
            'trigger' => 'Module started',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'info',
            'title' => 'Module started (Lab)',
            'template' => "ℹ️ **Module started**\n{module} started for {batch_id} on {actual_start}.\nPlanned end: {planned_end}.\nLab activities in this module: {lab_activity_count}.\n{lms_link}",
        ],
        [
            'id' => 'LM-02',
            'recipient' => 'LM',
            'recipient_title' => 'Lab Mentor',
            'trigger' => 'Activity due',
            'status' => 'Existing',
            'escalation' => '→ PM at threshold',
            'threshold_days' => 1,
            'severity' => 'reminder',
            'title' => 'Lab activity reminder',
            'template' => "🔔 **Reminder:** {activity} for {batch_id} / {module} is due {due_date}.\nPlease complete it in the LMS.\n{lms_link}",
        ],
        [
            'id' => 'LM-03',
            'recipient' => 'LM',
            'recipient_title' => 'Lab Mentor',
            'trigger' => 'Activity overdue',
            'status' => 'Existing',
            'escalation' => '→ PM after {n} days',
            'threshold_days' => 2,
            'severity' => 'overdue',
            'title' => 'Lab activity overdue',
            'template' => "⏰ **Overdue:** {activity} for {batch_id} / {module} is overdue ({delay_days}d).\nComplete it now, otherwise it will be escalated to the PM.\n{lms_link}",
        ],
        [
            'id' => 'LM-04',
            'recipient' => 'LM',
            'recipient_title' => 'Lab Mentor',
            'trigger' => 'Activity completed',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'completed',
            'title' => 'Activity completed',
            'template' => "✅ {activity} for {batch_id} / {module} marked done by you on {date}. Thank you.\n{lms_link}",
        ],
        [
            'id' => 'LM-05',
            'recipient' => 'LM',
            'recipient_title' => 'Lab Mentor',
            'trigger' => 'Stage transition (next mentor)',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'info',
            'title' => 'You are up next (Lab)',
            'template' => "ℹ️ **You are up next**\n{batch_id} moved from {module} to {next_module}.\nYou are the lab mentor for {next_module}, starting {next_start_date}.\nPlease verify lab readiness.\n{lms_link}",
        ],
        [
            'id' => 'LM-06',
            'recipient' => 'LM',
            'recipient_title' => 'Lab Mentor',
            'trigger' => 'Lab readiness check due',
            'status' => 'NEW',
            'escalation' => '→ PM at threshold',
            'threshold_days' => 2,
            'severity' => 'reminder',
            'title' => 'Lab readiness check due',
            'template' => "🔔 Lab readiness for {batch_id} / {module} must be confirmed by {due_date} (systems, software, access, datasets).\nPlease update the checklist in the LMS.\n{lms_link}",
        ],
        [
            'id' => 'LM-07',
            'recipient' => 'LM',
            'recipient_title' => 'Lab Mentor',
            'trigger' => 'Lab session attendance not marked',
            'status' => 'NEW',
            'escalation' => '→ PM after {n} days',
            'threshold_days' => 1,
            'severity' => 'reminder',
            'title' => 'Lab attendance not marked',
            'template' => "🔔 Lab attendance for {batch_id} / {module} on {session_date} has not been marked.\nPlease update it today.\n{lms_link}",
        ],
        [
            'id' => 'LM-08',
            'recipient' => 'LM',
            'recipient_title' => 'Lab Mentor',
            'trigger' => 'Practical / lab assessment evaluation pending',
            'status' => 'NEW',
            'escalation' => '→ PM at threshold',
            'threshold_days' => 2,
            'severity' => 'overdue',
            'title' => 'Lab evaluation pending',
            'template' => "⏰ Evaluation of {assessment_name} (lab) for {batch_id} / {module} is pending ({delay_days}d).\nPlease upload marks / results.\n{lms_link}",
        ],
        [
            'id' => 'LM-09',
            'recipient' => 'LM',
            'recipient_title' => 'Lab Mentor',
            'trigger' => 'Nomination submitted (confirmation)',
            'status' => 'Existing',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'completed',
            'title' => 'Nomination submitted',
            'template' => "✅ Your nomination of {count} student(s) for {award_type} in {batch_id} has been sent to the PM for approval.\n{lms_link}",
        ],
        [
            'id' => 'LM-10',
            'recipient' => 'LM',
            'recipient_title' => 'Lab Mentor',
            'trigger' => 'Nomination approved / rejected',
            'status' => 'NEW',
            'escalation' => '—',
            'threshold_days' => 0,
            'severity' => 'milestone',
            'title' => 'Nomination result',
            'template' => "🎉 Your {award_type} nomination in {batch_id} status has been updated by {approver}.\n{remarks}\n{lms_link}",
        ],
    ];

    /**
     * Dictionary of all available placeholders and sample data for previewing.
     */
    const PLACEHOLDER_DICTIONARY = [
        '{batch_id}'            => ['desc' => 'Batch identifier', 'sample' => '25002A'],
        '{mode}'                => ['desc' => 'Delivery mode', 'sample' => 'Offline'],
        '{course_name}'         => ['desc' => 'Course name', 'sample' => 'Advance C Programming'],
        '{start_date}'          => ['desc' => 'Batch start date', 'sample' => '05 Oct 2026'],
        '{end_date}'            => ['desc' => 'Actual batch end date', 'sample' => '20 Dec 2026'],
        '{planned_end}'         => ['desc' => 'Planned end date of batch / module', 'sample' => '15 Nov 2026'],
        '{revised_end}'         => ['desc' => 'Revised end date after a slip', 'sample' => '18 Nov 2026'],
        '{role}'                => ['desc' => 'Role assigned to recipient', 'sample' => 'PM / SSE'],
        '{module}'              => ['desc' => 'Module name', 'sample' => 'Advance C Programming'],
        '{next_module}'         => ['desc' => 'Next module name', 'sample' => 'C++ Programming'],
        '{actual_start}'        => ['desc' => 'Actual module start date', 'sample' => '12 Oct 2026'],
        '{actual_end}'          => ['desc' => 'Actual module end date', 'sample' => '24 Oct 2026'],
        '{activity}'            => ['desc' => 'Activity name', 'sample' => 'Weekly assessment report'],
        '{activity_count}'      => ['desc' => 'Number of activities in module', 'sample' => '6'],
        '{lab_activity_count}'  => ['desc' => 'Number of lab activities in module', 'sample' => '4'],
        '{pending_activities}'  => ['desc' => 'Pending activities names', 'sample' => 'Lab Test 1, Report Review'],
        '{pending_count}'       => ['desc' => 'Pending activities count', 'sample' => '2'],
        '{owner}'               => ['desc' => 'Person responsible', 'sample' => 'Ravi Kumar'],
        '{owner_role}'          => ['desc' => 'Role of the responsible person', 'sample' => 'Class Mentor'],
        '{next_mentor}'         => ['desc' => 'Mentor of the next module', 'sample' => 'Anita S.'],
        '{next_start_date}'     => ['desc' => 'Next module start date', 'sample' => '26 Oct 2026'],
        '{due_date}'            => ['desc' => 'Due date of activity', 'sample' => '10 Oct 2026'],
        '{delay_days}'          => ['desc' => 'Days overdue / delayed', 'sample' => '3'],
        '{pending_days}'        => ['desc' => 'Days pending approval', 'sample' => '2'],
        '{days_left}'           => ['desc' => 'Days remaining', 'sample' => '2'],
        '{n}'                   => ['desc' => 'Escalation threshold in days', 'sample' => '2'],
        '{date}'                => ['desc' => 'Date of the event', 'sample' => '10 Oct 2026'],
        '{session_date}'        => ['desc' => 'Date of session', 'sample' => '09 Oct 2026'],
        '{count}'               => ['desc' => 'Number of students nominated', 'sample' => '3'],
        '{award_type}'          => ['desc' => 'Award category', 'sample' => 'Spot Award'],
        '{nominee_names}'       => ['desc' => 'Nominated student names', 'sample' => 'Rahul S., Priya K., Amit P.'],
        '{approved_names}'      => ['desc' => 'Approved student names', 'sample' => 'Rahul S., Priya K.'],
        '{approver}'            => ['desc' => 'Person who approved / rejected', 'sample' => 'Balwant Sir'],
        '{remarks}'             => ['desc' => 'Approver comments / reasons', 'sample' => 'Good performance in practical assessments.'],
        '{assessment_name}'     => ['desc' => 'Assessment name', 'sample' => 'Module 2 Test'],
        '{assessment_date}'     => ['desc' => 'Assessment date', 'sample' => '12 Oct 2026'],
        '{affected_modules}'    => ['desc' => 'Modules shifted due to slip', 'sample' => 'Module 3, Module 4'],
        '{midpoint_date}'       => ['desc' => 'Batch mid-point date', 'sample' => '15 Nov 2026'],
        '{reviewers}'           => ['desc' => 'Review participants', 'sample' => 'Balwant Sir, SSE'],
        '{closure_due_date}'    => ['desc' => 'Deadline for closure items', 'sample' => '27 Dec 2026'],
        '{week_start}'          => ['desc' => 'Start of week for digest', 'sample' => '05 Oct 2026'],
        '{done_count}'          => ['desc' => 'Done activity count', 'sample' => '8'],
        '{due_count}'           => ['desc' => 'Due activity count', 'sample' => '3'],
        '{overdue_count}'       => ['desc' => 'Overdue activity count', 'sample' => '1'],
        '{schedule_status}'     => ['desc' => 'Schedule status', 'sample' => 'On track'],
        '{oldest_activity}'     => ['desc' => 'Oldest overdue activity name', 'sample' => 'Lab Report'],
        '{lms_link}'            => ['desc' => 'Deep link to LMS batch / module page', 'sample' => 'https://lms.emertxe.com/local/batchanalytics/module.php?batchid=37&module=2'],
    ];

    /**
     * Get all active templates, merged with stored configurations.
     *
     * @return array
     */
    public static function get_templates(): array {
        $raw = get_config('local_batchanalytics', self::CONFIG_KEY);
        $customized = [];
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (!empty($item['id'])) {
                        $customized[$item['id']] = $item;
                    }
                }
            }
        }

        $templates = self::DEFAULT_TEMPLATES;
        foreach ($templates as &$tmpl) {
            $tid = $tmpl['id'];
            if (isset($customized[$tid])) {
                $c = $customized[$tid];
                if (isset($c['template'])) {
                    $tmpl['template'] = $c['template'];
                }
                if (isset($c['title'])) {
                    $tmpl['title'] = $c['title'];
                }
                if (isset($c['threshold_days'])) {
                    $tmpl['threshold_days'] = (int)$c['threshold_days'];
                }
                if (isset($c['severity'])) {
                    $tmpl['severity'] = $c['severity'];
                }
                if (isset($c['enabled'])) {
                    $tmpl['enabled'] = (bool)$c['enabled'];
                }
                $tmpl['is_customized'] = true;
            } else {
                $tmpl['is_customized'] = false;
                $tmpl['enabled'] = true;
            }
        }
        unset($tmpl);

        return $templates;
    }

    /**
     * Save customized templates to Moodle config.
     *
     * @param array $templates
     * @return bool
     */
    public static function save_templates(array $templates): bool {
        $clean = [];
        foreach ($templates as $t) {
            if (empty($t['id'])) {
                continue;
            }
            $clean[] = [
                'id'             => clean_param($t['id'], PARAM_ALPHANUMEXT),
                'title'          => clean_param($t['title'] ?? '', PARAM_TEXT),
                'template'       => clean_param($t['template'] ?? '', PARAM_RAW_TRIMMED),
                'threshold_days' => isset($t['threshold_days']) ? (int)$t['threshold_days'] : 0,
                'severity'       => clean_param($t['severity'] ?? 'info', PARAM_ALPHANUMEXT),
                'enabled'        => !empty($t['enabled']),
            ];
        }

        return set_config(self::CONFIG_KEY, json_encode($clean, JSON_UNESCAPED_UNICODE), 'local_batchanalytics');
    }

    /**
     * Reset templates back to default specification.
     *
     * @return bool
     */
    public static function reset_templates(): bool {
        return unset_config(self::CONFIG_KEY, 'local_batchanalytics');
    }

    /**
     * Render a template with replacement data.
     *
     * @param string $template_text
     * @param array $data
     * @return string
     */
    public static function render_template(string $template_text, array $data = []): string {
        $sample_map = [];
        foreach (self::PLACEHOLDER_DICTIONARY as $ph => $meta) {
            $sample_map[$ph] = $meta['sample'];
        }

        // Merge actual data on top of sample data
        foreach ($data as $k => $v) {
            $ph = strpos($k, '{') === 0 ? $k : ('{' . $k . '}');
            $sample_map[$ph] = (string)$v;
        }

        return strtr($template_text, $sample_map);
    }

    /**
     * Check if Zoho Cliq Bot API is configured.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        $zapikey = trim((string)get_config('local_batchanalytics', 'zoho_cliq_zapikey'));
        $webhook = trim((string)get_config('local_batchanalytics', 'zoho_cliq_webhook_url'));
        return (!empty($zapikey) || !empty($webhook));
    }

    /**
     * Build the Zoho Cliq Bot API message URL with zapikey.
     * Example: https://cliq.zoho.com/api/v2/bots/batchinformer/message?zapikey=1000.xxxxxxx.xxxxx
     *
     * @param string|null $custom_zapikey
     * @param string|null $custom_bot_name
     * @return string
     */
    public static function get_bot_api_url(?string $custom_zapikey = null, ?string $custom_bot_name = null): string {
        $zapikey = !empty($custom_zapikey) ? trim($custom_zapikey) : trim((string)get_config('local_batchanalytics', 'zoho_cliq_zapikey'));
        $bot_name = !empty($custom_bot_name) ? trim($custom_bot_name) : trim((string)get_config('local_batchanalytics', 'zoho_cliq_bot_name'));
        if (empty($bot_name)) {
            $bot_name = 'batchinformer';
        }

        $endpoint = trim((string)get_config('local_batchanalytics', 'zoho_cliq_bot_endpoint'));
        if (empty($endpoint)) {
            $endpoint = 'https://cliq.zoho.com/api/v2/bots/';
        }

        $base = rtrim($endpoint, '/');
        $url = $base . '/' . rawurlencode($bot_name) . '/message';

        if (!empty($zapikey)) {
            $url .= '?zapikey=' . urlencode($zapikey);
        }

        return $url;
    }

    /**
     * Send a notification message payload to Zoho Cliq Bot API.
     * Zoho Cliq Bot endpoint format:
     * https://cliq.zoho.com/api/v2/bots/{bot_name}/message?zapikey={CLIQ_ZAPI_KEY}
     * Payload:
     * { "text": messageText, "userids": emails }
     *
     * @param string $message The message body
     * @param array|string|null $userids Email string or array of emails / Cliq user IDs
     * @param string|null $target_url Custom URL override (if null, uses configured Bot API URL or webhook)
     * @return array{success: bool, response: string, http_code: int, url_used: string}
     */
    public static function send_cliq_message(string $message, $userids = [], ?string $target_url = null): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $zapikey = trim((string)get_config('local_batchanalytics', 'zoho_cliq_zapikey'));
        $webhook_url = trim((string)get_config('local_batchanalytics', 'zoho_cliq_webhook_url'));

        if (empty($target_url)) {
            if (!empty($zapikey)) {
                $target_url = self::get_bot_api_url();
            } else if (!empty($webhook_url)) {
                $target_url = $webhook_url;
            }
        }

        if (empty($target_url)) {
            return [
                'success' => false,
                'response' => 'Zoho Cliq API Key (zapikey) or Webhook URL is not configured.',
                'http_code' => 0,
                'url_used' => ''
            ];
        }

        // Construct Zoho Cliq Bot Payload: { "text": messageText, "userids": emails }
        $payload_data = [
            'text' => $message,
        ];

        // Format userids as list of trimmed email strings
        if (!empty($userids)) {
            if (is_string($userids)) {
                $emails = preg_split('/[\s,]+/', trim($userids), -1, PREG_SPLIT_NO_EMPTY);
            } else if (is_array($userids)) {
                $emails = array_values(array_filter(array_map('trim', $userids)));
            } else {
                $emails = [];
            }

            if (!empty($emails)) {
                $payload_data['userids'] = $emails;
            }
        }

        $payload_json = json_encode($payload_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json'
        ]);

        $resp = $curl->post($target_url, $payload_json);
        $info = $curl->get_info();
        $code = (int)($info['http_code'] ?? 0);

        // Mask zapikey for safe logging and display
        $safe_url = preg_replace('/(zapikey=)([^&]+)/i', '$1****', $target_url);

        return [
            'success' => ($code >= 200 && $code < 300),
            'response' => (string)$resp,
            'http_code' => $code,
            'url_used' => $safe_url,
        ];
    }
}

