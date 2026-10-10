<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/util.php');
require_once(__DIR__ . '/mentor_activity_service.php');
require_once(__DIR__ . '/batch_notes_service.php');

/**
 * Service to evaluate and dispatch Zoho Cliq notifications for
 * Mentor activities (Module-based) and SS activities (Batch-based).
 *
 * Cadence:
 * - Before 3 days (T-3) -> Mentor / SSE
 * - On day (Due date) -> Mentor / SSE
 * - After 3 days overdue (T+3) -> Mentor / SSE
 * - After 5th day overdue (T+5) -> Program Manager (PM) Escalation
 * - On Completion -> PM + Mentor / SSE
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cliq_activity_notifier {

    const LOG_TABLE = 'local_batchanalytics_cliq_log';

    /**
     * Check if Zoho Cliq notifications are globally enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool)get_config('local_batchanalytics', 'zoho_cliq_enabled');
    }

    /**
     * Get the configured Bot API URL and ZAPI Key.
     *
     * @return array{url: string, zapikey: string, channel: string}
     */
    public static function get_config_details(): array {
        $url     = trim((string)get_config('local_batchanalytics', 'zoho_cliq_bot_url'));
        $zapikey = trim((string)get_config('local_batchanalytics', 'zoho_cliq_zapikey'));
        $channel = trim((string)get_config('local_batchanalytics', 'zoho_cliq_default_channel'));

        if ($url === '' || str_ends_with($url, '/me')) {
            $url = 'https://cliq.zoho.com/api/v2/bots/batchinformer/message';
        }

        if (empty($zapikey) || $zapikey === 'dummy_test_token') {
            $zapikey = trim((string)get_config('local_spotaward', 'zohocliq_api_key'));
            if (empty($zapikey)) {
                $zapikey = trim((string)get_config('assignfeedback_aievaluator', 'cliqbotapikey'));
            }
        }

        return [
            'url'     => $url,
            'zapikey' => $zapikey,
            'channel' => $channel,
        ];
    }

    /**
     * Get the master definitions of all notification templates, stages, defaults, and placeholders.
     *
     * @return array
     */
    public static function get_template_definitions(): array {
        return [
            'mentor' => [
                'title'       => 'Mentor Activity Message Templates',
                'scope'       => 'module',
                'description' => 'Notifications dispatched for course module mentor activities (evaluated against planned start date + working due days).',
                'stages'      => [
                    't_minus_3' => [
                        'key'             => 'mentor_t_minus_3',
                        'title'           => '3 Days Before Due Reminder',
                        'timing_badge'    => 'T-3 Days',
                        'badge_color'     => '#0d6efd',
                        'recipient'       => 'Assigned Mentors (Primary & Lab)',
                        'recipient_badge' => 'Mentors',
                        'card_theme'      => 'modern-inline',
                        'default_subject' => '⏳ Upcoming Mentor Activity Reminder: {module_name} - {task_name}',
                        'default_body'    => "Hello {mentor_name},\n\nThis is a reminder that the following module mentor activity is due in 3 days:\n• Batch: {batch_name}\n• Module: {module_name}\n• Pending Activity: {task_name}\n• Due Date: {due_date}\n• Mentor: {mentor_name}\n\n🔗 View Module: {link}",
                        'placeholders'    => ['{batch_name}', '{module_name}', '{task_name}', '{due_date}', '{mentor_name}', '{link}'],
                    ],
                    'due_today' => [
                        'key'             => 'mentor_due_today',
                        'title'           => 'Due Today Alert',
                        'timing_badge'    => 'Due Today (T-0)',
                        'badge_color'     => '#fd7e14',
                        'recipient'       => 'Assigned Mentors (Primary & Lab)',
                        'recipient_badge' => 'Mentors',
                        'card_theme'      => 'amber',
                        'default_subject' => '🚨 Mentor Activity Due Today: {module_name} - {task_name}',
                        'default_body'    => "Hello {mentor_name},\n\nThe following module mentor activity is due today:\n• Batch: {batch_name}\n• Module: {module_name}\n• Pending Activity: {task_name}\n• Due Date: {due_date} (Today)\n• Mentor: {mentor_name}\n\nPlease complete the evaluations and mark the activity complete in LMS:\n🔗 View Module: {link}",
                        'placeholders'    => ['{batch_name}', '{module_name}', '{task_name}', '{due_date}', '{mentor_name}', '{link}'],
                    ],
                    't_plus_3' => [
                        'key'             => 'mentor_t_plus_3',
                        'title'           => '3 Days Overdue Warning',
                        'timing_badge'    => '3 Days Overdue (T+3)',
                        'badge_color'     => '#dc3545',
                        'recipient'       => 'Assigned Mentors (Primary & Lab)',
                        'recipient_badge' => 'Mentors',
                        'card_theme'      => 'red',
                        'default_subject' => '⚠️ Overdue Warning: {module_name} - {task_name} (3 Days Overdue)',
                        'default_body'    => "Attention {mentor_name},\n\nThe following module mentor activity is 3 days overdue:\n• Batch: {batch_name}\n• Module: {module_name}\n• Pending Activity: {task_name}\n• Original Due Date: {due_date}\n• Status: 3 days overdue\n\nPlease evaluate pending submissions and mark complete immediately.\n🔗 View Module: {link}",
                        'placeholders'    => ['{batch_name}', '{module_name}', '{task_name}', '{due_date}', '{mentor_name}', '{link}'],
                    ],
                    't_plus_5' => [
                        'key'             => 'mentor_t_plus_5',
                        'title'           => '5th Day PM Escalation',
                        'timing_badge'    => '5+ Days Overdue (T+5)',
                        'badge_color'     => '#6f42c1',
                        'recipient'       => 'Program Manager (PM)',
                        'recipient_badge' => 'PM Only',
                        'card_theme'      => 'red',
                        'default_subject' => '🛑 ESCALATION: {module_name} - {task_name} is {overdue_days} Days Overdue',
                        'default_body'    => "Attention {pm_name} (Program Manager),\n\nThe following module mentor activity has not been completed and is {overdue_days} days overdue:\n• Batch: {batch_name}\n• Module: {module_name}\n• Pending Activity: {task_name}\n• Assigned Mentor: {mentor_name}\n• Original Due Date: {due_date}\n• Status: {overdue_days} days overdue (Escalation)\n\nPlease follow up with the assigned mentor.\n🔗 View Module: {link}",
                        'placeholders'    => ['{batch_name}', '{module_name}', '{task_name}', '{due_date}', '{mentor_name}', '{pm_name}', '{overdue_days}', '{link}'],
                    ],
                    'completed' => [
                        'key'             => 'mentor_completed',
                        'title'           => 'Instant Completion Confirmation',
                        'timing_badge'    => 'Completed',
                        'badge_color'     => '#198754',
                        'recipient'       => 'Program Manager (PM) + Assigned Mentors',
                        'recipient_badge' => 'PM + Mentors',
                        'card_theme'      => 'green',
                        'default_subject' => '✅ Mentor Activity Completed: {module_name} - {task_name}',
                        'default_body'    => "Hello Team,\n\nThe following module mentor activity has been successfully marked as completed:\n• Batch: {batch_name}\n• Module: {module_name}\n• Completed Activity: {task_name}\n• Completed By: {completed_by}\n• Completion Date: {completion_date}\n\n🔗 View Module: {link}",
                        'placeholders'    => ['{batch_name}', '{module_name}', '{task_name}', '{completed_by}', '{completion_date}', '{link}'],
                    ],
                ],
            ],
            'ss' => [
                'title'       => 'Soft Skills (SS) Activity Message Templates',
                'scope'       => 'batch',
                'description' => 'Notifications dispatched for batch-level soft skills milestones (evaluated against planned dates).',
                'stages'      => [
                    't_minus_3' => [
                        'key'             => 'ss_t_minus_3',
                        'title'           => '3 Days Before Due Reminder',
                        'timing_badge'    => 'T-3 Days',
                        'badge_color'     => '#0d6efd',
                        'recipient'       => 'SS Executive + SS Lead + Current Module Mentors',
                        'recipient_badge' => 'SSE + SS Lead + Mentors',
                        'card_theme'      => 'modern-inline',
                        'default_subject' => '⏳ Upcoming SS Activity Reminder: {activity_name}',
                        'default_body'    => "Hello Team,\n\nThis is a reminder that the following batch SS activity is due in 3 days:\n• Batch: {batch_name}\n• Current Module: {current_module}\n• Pending Activity: {activity_name}\n• Due Date: {due_date}\n• Assigned SS Executive: {sse_name}\n• Current Module Mentors: {mentor_name}\n\n🔗 View Batch: {link}",
                        'placeholders'    => ['{batch_name}', '{current_module}', '{activity_name}', '{due_date}', '{sse_name}', '{ss_lead_name}', '{mentor_name}', '{link}'],
                    ],
                    'due_today' => [
                        'key'             => 'ss_due_today',
                        'title'           => 'Due Today Alert',
                        'timing_badge'    => 'Due Today (T-0)',
                        'badge_color'     => '#fd7e14',
                        'recipient'       => 'SS Executive + SS Lead + Current Module Mentors',
                        'recipient_badge' => 'SSE + SS Lead + Mentors',
                        'card_theme'      => 'amber',
                        'default_subject' => '🚨 SS Activity Due Today: {activity_name}',
                        'default_body'    => "Hello Team,\n\nThe following batch SS activity is due today:\n• Batch: {batch_name}\n• Current Module: {current_module}\n• Pending Activity: {activity_name}\n• Due Date: {due_date} (Today)\n• Assigned SS Executive: {sse_name}\n• Current Module Mentors: {mentor_name}\n\nPlease record completion in the LMS:\n🔗 View Batch: {link}",
                        'placeholders'    => ['{batch_name}', '{current_module}', '{activity_name}', '{due_date}', '{sse_name}', '{ss_lead_name}', '{mentor_name}', '{link}'],
                    ],
                    't_plus_3' => [
                        'key'             => 'ss_t_plus_3',
                        'title'           => '3 Days Overdue Warning',
                        'timing_badge'    => '3 Days Overdue (T+3)',
                        'badge_color'     => '#dc3545',
                        'recipient'       => 'SS Executive + SS Lead',
                        'recipient_badge' => 'SSE + SS Lead',
                        'card_theme'      => 'red',
                        'default_subject' => '⚠️ Overdue Warning: Pending SS Activity ({activity_name}) 3 Days Overdue',
                        'default_body'    => "Attention {sse_name} & SS Lead,\n\nThe following batch SS activity is 3 days overdue:\n• Batch: {batch_name}\n• Current Module: {current_module}\n• Pending Activity: {activity_name}\n• Original Due Date: {due_date}\n• Assigned SS Executive: {sse_name}\n• Status: 3 days overdue\n\nKindly complete this deliverable immediately to avoid management escalation.\n🔗 View Batch: {link}",
                        'placeholders'    => ['{batch_name}', '{current_module}', '{activity_name}', '{due_date}', '{sse_name}', '{ss_lead_name}', '{link}'],
                    ],
                    't_plus_5' => [
                        'key'             => 'ss_t_plus_5',
                        'title'           => '5th Day SS Lead Escalation',
                        'timing_badge'    => '5+ Days Overdue (T+5)',
                        'badge_color'     => '#dc2626',
                        'recipient'       => 'SS Lead Only (PM, SSE & Mentors Excluded)',
                        'recipient_badge' => 'SS Lead Only',
                        'card_theme'      => 'red',
                        'default_subject' => '🛑 ESCALATION: Pending SS Activity {activity_name} is {overdue_days} Days Overdue',
                        'default_body'    => "Attention {ss_lead_name},\n\nThe following batch SS activity has not been completed and is {overdue_days} days overdue:\n• Batch: {batch_name}\n• Current Module: {current_module}\n• Pending Activity: {activity_name}\n• Assigned Executive: {sse_name}\n• Original Due Date: {due_date}\n• Status: {overdue_days} days overdue\n\nPlease coordinate to ensure this milestone is completed and recorded in the LMS:\n🔗 View Batch: {link}",
                        'placeholders'    => ['{batch_name}', '{current_module}', '{activity_name}', '{due_date}', '{sse_name}', '{ss_lead_name}', '{overdue_days}', '{link}'],
                    ],
                    'completed' => [
                        'key'             => 'ss_completed',
                        'title'           => 'Instant Completion Confirmation',
                        'timing_badge'    => 'Completed',
                        'badge_color'     => '#198754',
                        'recipient'       => 'SS Executive + SS Lead + Current Module Mentors',
                        'recipient_badge' => 'SSE + SS Lead + Mentors',
                        'card_theme'      => 'green',
                        'default_subject' => '✅ SS Activity Completed: {activity_name}',
                        'default_body'    => "Hello Team,\n\nThe following batch SS activity has been successfully marked as completed:\n• Batch: {batch_name}\n• Current Module: {current_module}\n• Completed Activity: {activity_name}\n• Assigned Executive: {sse_name}\n• Current Module Mentors: {mentor_name}\n• Completed By: {completed_by}\n• Completion Date: {completion_date}\n\n🔗 View Batch: {link}",
                        'placeholders'    => ['{batch_name}', '{current_module}', '{activity_name}', '{sse_name}', '{ss_lead_name}', '{mentor_name}', '{completed_by}', '{completion_date}', '{link}'],
                    ],
                ],
            ],
            'batch_review' => [
                'title'       => 'Program Manager (PM) Batch Review Message Templates',
                'scope'       => 'batch',
                'description' => 'Notifications dispatched for recurring 15-day Program Manager batch reviews (evaluated against batch start date or latest review note).',
                'stages'      => [
                    't_minus_3' => [
                        'key'             => 'batch_review_t_minus_3',
                        'title'           => '3 Days Before Due Reminder',
                        'timing_badge'    => 'T-3 Days',
                        'badge_color'     => '#0d6efd',
                        'recipient'       => 'Program Manager (PM)',
                        'recipient_badge' => 'PM',
                        'card_theme'      => 'modern-inline',
                        'default_subject' => '⏳ Upcoming Batch Review Reminder: {batch_name}',
                        'default_body'    => "Hello {pm_name},\n\nThis is a reminder that the 15-day recurring batch review for {batch_name} is due in 3 days:\n• Batch: {batch_name}\n• Review Due Date: {due_date}\n• Responsible PM: {pm_name}\n• Previous Review: {last_review_info}\n\nPlease conduct the batch review and log notes in the LMS:\n🔗 Open Batch Review Notes: {link}",
                        'placeholders'    => ['{batch_name}', '{pm_name}', '{due_date}', '{last_review_info}', '{link}'],
                    ],
                    'due_today' => [
                        'key'             => 'batch_review_due_today',
                        'title'           => 'Due Today Alert',
                        'timing_badge'    => 'Due Today (T-0)',
                        'badge_color'     => '#fd7e14',
                        'recipient'       => 'Program Manager (PM)',
                        'recipient_badge' => 'PM',
                        'card_theme'      => 'amber',
                        'default_subject' => '🚨 Batch Review Due Today: {batch_name}',
                        'default_body'    => "Hello {pm_name},\n\nThe 15-day batch review for {batch_name} is due today:\n• Batch: {batch_name}\n• Due Date: {due_date} (Today)\n• Responsible PM: {pm_name}\n• Previous Review: {last_review_info}\n\nPlease update the batch review notes:\n🔗 Open Batch Review Notes: {link}",
                        'placeholders'    => ['{batch_name}', '{pm_name}', '{due_date}', '{last_review_info}', '{link}'],
                    ],
                    'overdue' => [
                        'key'             => 'batch_review_overdue',
                        'title'           => 'Overdue Warning',
                        'timing_badge'    => 'Overdue (T+3 / T+5)',
                        'badge_color'     => '#dc3545',
                        'recipient'       => 'Program Manager (PM)',
                        'recipient_badge' => 'PM',
                        'card_theme'      => 'red',
                        'default_subject' => '⚠️ Overdue Warning: Batch Review for {batch_name} is {overdue_days} Days Overdue',
                        'default_body'    => "Attention {pm_name},\n\nThe 15-day recurring batch review for {batch_name} is {overdue_days} days overdue:\n• Batch: {batch_name}\n• Original Due Date: {due_date}\n• Responsible PM: {pm_name}\n• Status: {overdue_days} days overdue\n\nKindly complete the review and record notes in the LMS:\n🔗 Open Batch Review Notes: {link}",
                        'placeholders'    => ['{batch_name}', '{pm_name}', '{due_date}', '{overdue_days}', '{link}'],
                    ],
                    'completed' => [
                        'key'             => 'batch_review_completed',
                        'title'           => 'Instant Review Note Added Confirmation',
                        'timing_badge'    => 'Note Added',
                        'badge_color'     => '#198754',
                        'recipient'       => 'SS Executive + SS Lead + Current Module Mentors + PM',
                        'recipient_badge' => 'SSE + SSL + Mentors + PM',
                        'card_theme'      => 'green',
                        'default_subject' => '✅ Batch Review Note Added: {batch_name}',
                        'default_body'    => "Hello Team,\n\nA batch review note has been recorded for {batch_name}:\n• Batch: {batch_name}\n• Current Module: {current_module}\n• Logged By: {author}\n• Date: {review_date}\n• Note: {note_preview}\n• Next Review Due: {next_due_date}\n\n🔗 View Batch Review Notes: {link}",
                        'placeholders'    => ['{batch_name}', '{current_module}', '{pm_name}', '{sse_name}', '{ss_lead_name}', '{mentor_name}', '{author}', '{review_date}', '{note_preview}', '{next_due_date}', '{link}'],
                    ],
                ],
            ],
            'transition' => [
                'title'       => 'Stage & Module Transition Message Templates',
                'scope'       => 'batch',
                'description' => 'Notifications and emails dispatched when a batch transitions to the next module/stage (based on actual start date). Recipients: Program Manager, SS Executive, Next Class Mentor, and Next Lab Mentor.',
                'stages'      => [
                    'stage_transition' => [
                        'key'             => 'stage_transition',
                        'title'           => 'Batch Stage Transition Alert',
                        'timing_badge'    => 'Stage Transition',
                        'badge_color'     => '#0284c7',
                        'recipient'       => 'Program Manager + SSE + Next Class & Lab Mentors',
                        'recipient_badge' => 'PM + SSE + Next Mentors',
                        'card_theme'      => 'modern-inline',
                        'default_subject' => 'ℹ️ Batch Stage Transition: {batch_name} moved from {previous_module} to {next_module}',
                        'default_body'    => "Dear {pm_name} / {sse_name} / {next_mentor_name},\n\nThis is to inform you that batch {batch_name} has moved from {previous_module} to {next_module}.\n\nThe applicable Student Success activities have been re-anchored to the new stage.\n\nAction Required:\nReview the activities relevant to the new stage and proceed with the assigned responsibilities.\n\n🔗 View Batch / Module: {link}",
                        'placeholders'    => [
                            '{batch_name}',
                            '{previous_module}',
                            '{next_module}',
                            '{transition_date}',
                            '{pm_name}',
                            '{sse_name}',
                            '{next_mentor_name}',
                            '{next_class_mentor}',
                            '{next_lab_mentor}',
                            '{overall_attendance}',
                            '{overall_maac_rating}',
                            '{student_count}',
                            '{prev_module_completion}',
                            '{batch_status}',
                            '{link}',
                        ],
                    ],
                    'module_assigned_mentor' => [
                        'key'             => 'module_assigned_mentor',
                        'title'           => 'Module Assigned - Class/Lab Mentor',
                        'timing_badge'    => 'Module Assigned',
                        'badge_color'     => '#6f42c1',
                        'recipient'       => 'Class/Lab Mentor',
                        'recipient_badge' => 'Mentors',
                        'card_theme'      => 'modern-inline',
                        'default_subject' => 'Module Assigned - {module_name} | {batch_id}',
                        'default_body'    => "Dear {mentor_name},\n\nThis is to inform you that the {module_name} module for batch {batch_id} has been assigned to you.\n\nYou are assigned as the {mentor_role} for this module. Please review the module plan and conduct the scheduled classes/labs within the planned timeline.\n\nAction Required:\nReview the module plan and proceed with the scheduled classes/labs as per the planned timeline.\n\n🔗 View Module Plan: {link}",
                        'placeholders'    => [
                            '{mentor_name}',
                            '{mentor_role}',
                            '{batch_id}',
                            '{batch_name}',
                            '{module_name}',
                            '{actual_start_date}',
                            '{planned_end_date}',
                            '{link}',
                            '{url}',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get a configured message subject or fall back to default subject text.
     *
     * @param string $key Template identifier (e.g. 'mentor_t_minus_3' or 'cliq_tpl_mentor_t_minus_3')
     * @param string $default_subject
     * @return string
     */
    public static function get_template_subject(string $key, string $default_subject): string {
        $clean_key = str_starts_with($key, 'cliq_tpl_') ? substr($key, 9) : $key;
        $clean_key = preg_replace('/_(subject|body)$/', '', $clean_key);

        $val = get_config('local_batchanalytics', 'cliq_tpl_' . $clean_key . '_subject');
        if ($val !== false && trim((string)$val) !== '') {
            return (string)$val;
        }
        return $default_subject;
    }

    /**
     * Get a configured message body or fall back to default body text.
     * Checks 'cliq_tpl_{key}_body' first, then 'cliq_tpl_{key}' (legacy), then $default_body.
     *
     * @param string $key Template identifier (e.g. 'mentor_t_minus_3' or 'cliq_tpl_mentor_t_minus_3')
     * @param string $default_body
     * @return string
     */
    public static function get_template_body(string $key, string $default_body): string {
        $clean_key = str_starts_with($key, 'cliq_tpl_') ? substr($key, 9) : $key;
        $clean_key = preg_replace('/_(subject|body)$/', '', $clean_key);

        // 1. Check cliq_tpl_{key}_body
        $val = get_config('local_batchanalytics', 'cliq_tpl_' . $clean_key . '_body');
        if ($val !== false && trim((string)$val) !== '') {
            return (string)$val;
        }

        // 2. Fallback to legacy cliq_tpl_{key}
        $legacy = get_config('local_batchanalytics', 'cliq_tpl_' . $clean_key);
        if ($legacy !== false && trim((string)$legacy) !== '') {
            return (string)$legacy;
        }

        return $default_body;
    }

    /**
     * Get a configured message template or fall back to default text (backward compatibility).
     *
     * @param string $config_key
     * @param string $default_text
     * @return string
     */
    public static function get_template_text(string $config_key, string $default_text): string {
        return self::get_template_body($config_key, $default_text);
    }

    /**
     * Render a message template by substituting placeholder tokens.
     *
     * @param string $template
     * @param array $replacements
     * @return string
     */
    public static function render_template(string $template, array $replacements): string {
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Resolve a user value (user ID, username, email, or fullname) to a Moodle user record.
     *
     * @param mixed $val
     * @return \stdClass|null
     */
    public static function resolve_user($val): ?\stdClass {
        global $DB;

        if ($val === null || $val === false || trim((string)$val) === '') {
            return null;
        }

        $s = trim((string)$val);

        if (is_numeric($s) && (int)$s > 0) {
            $u = $DB->get_record('user', ['id' => (int)$s, 'deleted' => 0]);
            if ($u) {
                return $u;
            }
        }

        if (strpos($s, '@') !== false) {
            $u = $DB->get_record('user', ['email' => $s, 'deleted' => 0]);
            if ($u) {
                return $u;
            }
        }

        $u = $DB->get_record('user', ['username' => $s, 'deleted' => 0]);
        if ($u) {
            return $u;
        }

        // Match by fullname
        $sql = "SELECT * FROM {user} WHERE deleted = 0 AND " . $DB->sql_concat('firstname', "' '", 'lastname') . " = :fn";
        $u = $DB->get_record_sql($sql, ['fn' => $s]);
        if ($u) {
            return $u;
        }

        return null;
    }

    /**
     * Resolve the SS Lead user(s) or email(s) from plugin configuration.
     * Checks:
     * 1. Direct configured email(s) in 'sslead_email' (comma-separated).
     * 2. Users assigned the role configured in 'sslead_roles'.
     *
     * @return array{emails: string[], names: string[]}
     */
    public static function resolve_ss_leads(): array {
        global $DB;

        $emails = [];
        $names  = [];

        // 1. Direct configured email(s)
        $cfg_email = trim((string)get_config('local_batchanalytics', 'sslead_email'));
        if ($cfg_email !== '') {
            $parts = array_map('trim', explode(',', $cfg_email));
            foreach ($parts as $p) {
                if (filter_var($p, FILTER_VALIDATE_EMAIL)) {
                    $u = $DB->get_record('user', ['email' => $p, 'deleted' => 0]);
                    $emails[] = $p;
                    $names[]  = $u ? fullname($u) : $p;
                }
            }
        }

        // 2. Role configuration
        $role_id = (int)get_config('local_batchanalytics', 'sslead_roles');
        if ($role_id > 0) {
            $sql = "SELECT DISTINCT u.*
                      FROM {user} u
                      JOIN {role_assignments} ra ON ra.userid = u.id
                     WHERE ra.roleid = :roleid AND u.deleted = 0 AND u.suspended = 0";
            $users = $DB->get_records_sql($sql, ['roleid' => $role_id]);
            foreach ($users as $u) {
                if (!empty($u->email)) {
                    $emails[] = $u->email;
                    $names[]  = fullname($u);
                }
            }
        }

        $emails = array_values(array_unique(array_filter($emails)));
        $names  = array_values(array_unique(array_filter($names)));

        return [
            'emails' => $emails,
            'names'  => $names,
        ];
    }

    /**
     * Resolve the current/active module and its assigned mentor(s) for a batch section.
     *
     * Active module determination:
     * 1. Module currently in progress (actualstart > 0 and empty actualend).
     * 2. If none in progress, first module where actualend is empty.
     * 3. Fallback to the first module in the schedule.
     *
     * @param object $sec Section record from local_bm_classsection
     * @return array{emails: string[], names: string[], module_name: string}
     */
    public static function resolve_current_module_mentors(object $sec): array {
        $emails = [];
        $names  = [];
        $module_name = 'Current Module';

        if (empty($sec->moduledata)) {
            return [
                'emails'      => [],
                'names'       => [],
                'module_name' => $module_name,
            ];
        }

        $modules = util::decode_module_data($sec->moduledata, true);
        if (empty($modules)) {
            return [
                'emails'      => [],
                'names'       => [],
                'module_name' => $module_name,
            ];
        }

        $cur_mod = null;

        // 1. Check for module currently in progress
        foreach ($modules as $m) {
            $a_start = (int)($m['actualstart'] ?? 0);
            $a_end   = (int)($m['actualend'] ?? 0);
            if ($a_start > 0 && $a_end <= 0) {
                $cur_mod = $m;
                break;
            }
        }

        // 2. If none in progress, first module where actual end is not completed
        if (!$cur_mod) {
            foreach ($modules as $m) {
                $a_end = (int)($m['actualend'] ?? 0);
                if ($a_end <= 0) {
                    $cur_mod = $m;
                    break;
                }
            }
        }

        // 3. Fallback to first module
        if (!$cur_mod) {
            $cur_mod = reset($modules);
        }

        if ($cur_mod) {
            $module_name = trim((string)($cur_mod['name'] ?? $cur_mod['courseshortname'] ?? 'Current Module'));

            $mentor_cands = [
                $cur_mod['primarymentor'] ?? '',
                $cur_mod['secondarymentor'] ?? '',
                $cur_mod['labmentor1'] ?? '',
                $cur_mod['labmentor2'] ?? '',
                $cur_mod['labmentor3'] ?? ''
            ];

            foreach ($mentor_cands as $cand) {
                $u = self::resolve_user($cand);
                if ($u && !empty($u->email)) {
                    $emails[] = $u->email;
                    $names[]  = fullname($u);
                }
            }
        }

        $emails = array_values(array_unique(array_filter($emails)));
        $names  = array_values(array_unique(array_filter($names)));

        return [
            'emails'      => $emails,
            'names'       => $names,
            'module_name' => $module_name,
        ];
    }

    /**
     * Check if a notification has already been sent today for this batch and activity stage.
     *
     * @param int $batchid
     * @param string $act_key
     * @param string $stage
     * @param string $date_sent
     * @return bool
     */
    public static function is_already_sent(int $batchid, string $act_key, string $stage, string $date_sent = '', string $recipient_email = ''): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists(self::LOG_TABLE)) {
            return false;
        }

        $conditions = [
            'batchid'      => $batchid,
            'activity_key' => $act_key,
            'stage'        => $stage,
        ];
        if (!empty($date_sent)) {
            $conditions['date_sent'] = $date_sent;
        }
        if (!empty($recipient_email)) {
            $conditions['recipient_email'] = $recipient_email;
        }

        return $DB->record_exists(self::LOG_TABLE, $conditions);
    }

    /**
     * Record a sent notification in the log.
     *
     * @param int $batchid
     * @param int $courseid
     * @param string $act_type
     * @param string $act_key
     * @param string $stage
     * @param string $recipient_email
     * @param string $status
     * @return int
     */
    public static function log_notification(
        int $batchid,
        int $courseid,
        string $act_type,
        string $act_key,
        string $stage,
        string $recipient_email,
        string $status = 'sent'
    ): int {
        global $DB;

        if (!$DB->get_manager()->table_exists(self::LOG_TABLE)) {
            return 0;
        }

        $emails = explode(',', $recipient_email);
        $last_id = 0;
        $now = time();
        $today = date('Y-m-d');

        foreach ($emails as $em) {
            $em = trim($em);
            if ($em === '') {
                continue;
            }
            $rec = (object)[
                'batchid'         => $batchid,
                'courseid'        => $courseid,
                'activity_type'   => $act_type,
                'activity_key'    => $act_key,
                'stage'           => $stage,
                'recipient_email' => $em,
                'timesent'        => $now,
                'date_sent'       => $today,
                'status'          => $status,
            ];
            $last_id = (int)$DB->insert_record(self::LOG_TABLE, $rec);
        }

        return $last_id;
    }

    /**
     * Dispatch a message via Zoho Cliq Bot API.
     *
     * @param array $recipient_emails List of recipient emails
     * @param string $text Message content
     * @param array $card_options Optional card layout parameters (title, theme)
     * @param bool $dry_run If true, simulate without HTTP call
     * @return array{success: bool, http_code: int, response: string}
     */
    public static function send_cliq_message(
        array $recipient_emails,
        string $text,
        array $card_options = [],
        bool $dry_run = false
    ): array {
        $cfg = self::get_config_details();

        if (empty($cfg['zapikey']) && !$dry_run) {
            return ['success' => false, 'http_code' => 0, 'response' => 'No Zoho Cliq ZAPI Key configured.'];
        }

        // Build URL
        $url = $cfg['url'];
        if (!empty($cfg['zapikey'])) {
            $separator = (strpos($url, '?') !== false) ? '&' : '?';
            $url .= $separator . 'zapikey=' . urlencode($cfg['zapikey']);
        }

        // Build Payload
        $payload = ['text' => $text];

        if (!empty($card_options['title'])) {
            $valid_themes = ['modern-inline', 'prompt'];
            $theme = $card_options['theme'] ?? 'modern-inline';
            if (!in_array($theme, $valid_themes, true)) {
                $theme = 'modern-inline';
            }
            $payload['card'] = [
                'title' => $card_options['title'],
                'theme' => $theme,
            ];
        }

        if (!empty($card_options['slides'])) {
            $payload['slides'] = $card_options['slides'];
        }

        $clean_emails = array_values(array_filter(array_unique($recipient_emails)));

        if ($dry_run) {
            $payload['userids'] = !empty($clean_emails) ? implode(',', $clean_emails) : ($cfg['channel'] ?? '');
            return [
                'success'   => true,
                'http_code' => 200,
                'response'  => 'Simulated (dry-run): ' . json_encode($payload),
            ];
        }

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 5,
            'CURLOPT_CONNECTTIMEOUT' => 3,
        ]);
        $curl->setHeader([
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ]);

        if (!empty($clean_emails)) {
            $overall_success = true;
            $first_error_code = 0;
            $last_http_code = 200;
            $responses = [];

            foreach ($clean_emails as $single_email) {
                $user_payload = $payload;
                $user_payload['userids'] = $single_email;
                $raw_response = $curl->post($url, json_encode($user_payload));
                $http_code = (int)$curl->get_info()['http_code'];
                $is_ok = ($http_code >= 200 && $http_code < 300);
                if (!$is_ok) {
                    $overall_success = false;
                    if ($first_error_code === 0) {
                        $first_error_code = $http_code;
                    }
                }
                $last_http_code = $http_code;
                $responses[$single_email] = $raw_response;
            }

            return [
                'success'   => $overall_success,
                'http_code' => $overall_success ? $last_http_code : ($first_error_code ?: $last_http_code),
                'response'  => json_encode($responses),
            ];
        } else if (!empty($cfg['channel'])) {
            $payload['channel_unique_name'] = $cfg['channel'];
            $raw_response = $curl->post($url, json_encode($payload));
            $http_code = (int)$curl->get_info()['http_code'];
            $success = ($http_code >= 200 && $http_code < 300);

            return [
                'success'   => $success,
                'http_code' => $http_code,
                'response'  => $raw_response,
            ];
        }

        return ['success' => false, 'http_code' => 0, 'response' => 'No recipients or channel specified.'];
    }

    /**
     * Evaluate all batches for Mentor activities and SS activities due dates.
     * Evaluates:
     * - T-3 days (Before 3 days) -> Mentor / SSE
     * - T-0 days (Due today) -> Mentor / SSE
     * - T+3 days (Overdue 3 days) -> Mentor / SSE
     * - T+5 days (Overdue 5 days) -> Program Manager (PM) Escalation
     *
     * @param bool $dry_run
     * @return array Summary of evaluation
     */
    public static function evaluate_due_activities(bool $dry_run = false): array {
        global $DB, $CFG;

        $results = [
            'checked_sections' => 0,
            'matched'          => 0,
            'sent'             => 0,
            'skipped_dedup'    => 0,
            'errors'           => [],
            'details'          => [],
        ];

        if (!self::is_enabled() && !$dry_run) {
            $results['message'] = 'Zoho Cliq notifications are disabled.';
            return $results;
        }

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_bm_classsection')) {
            $results['message'] = 'local_bm_classsection table not found.';
            return $results;
        }

        $today_midnight = strtotime('today midnight');
        $date_sent = date('Y-m-d');
        $defs = self::get_template_definitions();
        $sections = $DB->get_records('local_bm_classsection', null, 'id ASC');

        foreach ($sections as $sec) {
            $results['checked_sections']++;
            $batch_name = util::clean_section_name($sec->name ?: ('Batch ' . $sec->id));

            // 1. Resolve Stakeholders
            $pm_user  = self::resolve_user($sec->pmmanager) ?: self::resolve_user($sec->pmmanagername);
            $sse_user = self::resolve_user($sec->maacexecutive) ?: self::resolve_user($sec->maacexecutivename);

            $pm_email  = $pm_user ? $pm_user->email : '';
            $pm_name   = $pm_user ? fullname($pm_user) : ($sec->pmmanagername ?: 'Program Manager');
            $sse_email = $sse_user ? $sse_user->email : '';
            $sse_name  = $sse_user ? fullname($sse_user) : ($sec->maacexecutivename ?: 'SS Executive');

            $batch_url = $CFG->wwwroot . '/local/batchanalytics/batch.php?id=' . $sec->id;

            // -----------------------------------------------------------------
            // A. SS ACTIVITIES (Batch-Level)
            // -----------------------------------------------------------------
            if (!empty($sec->softskillsdata)) {
                $ss_list = util::decode_softskills_activities($sec->softskillsdata);

                // Resolve SS Lead(s)
                $ss_lead_info   = self::resolve_ss_leads();
                $ss_lead_emails = $ss_lead_info['emails'];
                $ss_lead_name   = !empty($ss_lead_info['names']) ? implode(', ', $ss_lead_info['names']) : 'SS Lead';

                // Resolve Current Module Mentor(s)
                $mod_mentor_info   = self::resolve_current_module_mentors($sec);
                $cur_mentor_emails = $mod_mentor_info['emails'];
                $cur_mentor_name   = !empty($mod_mentor_info['names']) ? implode(', ', $mod_mentor_info['names']) : '—';
                $cur_module_name   = $mod_mentor_info['module_name'];

                foreach ($ss_list as $ss) {
                    $p_ts = (int)($ss['planned'] ?? 0);
                    $a_ts = (int)($ss['actual'] ?? 0);

                    // Skip if already completed or no planned date
                    if ($a_ts > 0 || $p_ts <= 0) {
                        continue;
                    }

                    $act_key  = $ss['key'];
                    $act_name = $ss['activity'];
                    $due_date_str = date('d M Y', $p_ts);

                    // Calculate difference in whole days from today
                    // diff > 0: in future; diff == 0: today; diff < 0: overdue
                    $diff_days = (int)round(($p_ts - $today_midnight) / 86400);

                    $stage = null;
                    $recipient_emails = [];
                    $card_theme = 'modern-inline';
                    $subject = '';
                    $body = '';

                    $placeholders = [
                        '{batch_name}'     => $batch_name,
                        '{activity_name}'  => $act_name,
                        '{due_date}'       => $due_date_str,
                        '{sse_name}'       => $sse_name,
                        '{ss_lead_name}'   => $ss_lead_name,
                        '{mentor_name}'    => $cur_mentor_name,
                        '{current_module}' => $cur_module_name,
                        '{pm_name}'        => $pm_name,
                        '{overdue_days}'   => abs($diff_days),
                        '{link}'           => $batch_url,
                        '{url}'            => $batch_url,
                    ];

                    if ($diff_days >= 1 && $diff_days <= 3) {
                        // 1. Before 3 days -> SSE + SS Lead + Current Module Mentors
                        $stage = 't_minus_3';
                        $recipient_emails = array_values(array_filter(array_unique(array_merge(
                            [$sse_email],
                            $ss_lead_emails,
                            $cur_mentor_emails
                        ))));
                        $card_theme = 'modern-inline';
                        $stg_def = $defs['ss']['stages']['t_minus_3'];
                        $subject = self::render_template(self::get_template_subject('ss_t_minus_3', $stg_def['default_subject']), $placeholders);
                        $body = self::render_template(self::get_template_body('ss_t_minus_3', $stg_def['default_body']), $placeholders);
                    } else if ($diff_days === 0 || ($diff_days < 0 && $diff_days > -3)) {
                        // 2. On Due Date -> SSE + SS Lead + Current Module Mentors
                        $stage = 'due_today';
                        $recipient_emails = array_values(array_filter(array_unique(array_merge(
                            [$sse_email],
                            $ss_lead_emails,
                            $cur_mentor_emails
                        ))));
                        $card_theme = 'amber';
                        $stg_def = $defs['ss']['stages']['due_today'];
                        $subject = self::render_template(self::get_template_subject('ss_due_today', $stg_def['default_subject']), $placeholders);
                        $body = self::render_template(self::get_template_body('ss_due_today', $stg_def['default_body']), $placeholders);
                    } else if ($diff_days <= -3 && $diff_days > -5) {
                        // 3. After 3 days overdue -> SSE + SS Lead
                        $stage = 't_plus_3';
                        $recipient_emails = array_values(array_filter(array_unique(array_merge(
                            [$sse_email],
                            $ss_lead_emails
                        ))));
                        $card_theme = 'red';
                        $stg_def = $defs['ss']['stages']['t_plus_3'];
                        $subject = self::render_template(self::get_template_subject('ss_t_plus_3', $stg_def['default_subject']), $placeholders);
                        $body = self::render_template(self::get_template_body('ss_t_plus_3', $stg_def['default_body']), $placeholders);
                    } else if ($diff_days <= -5) {
                        // 4. After 5th day overdue -> SS Lead Escalation (PM, SSE & Mentors Excluded)
                        $stage = 't_plus_5_escalation';
                        $recipient_emails = array_values(array_filter(array_unique($ss_lead_emails)));
                        $card_theme = 'red';
                        $stg_def = $defs['ss']['stages']['t_plus_5'];
                        $subject = self::render_template(self::get_template_subject('ss_t_plus_5', $stg_def['default_subject']), $placeholders);
                        $body = self::render_template(self::get_template_body('ss_t_plus_5', $stg_def['default_body']), $placeholders);
                    }

                    if ($stage !== null) {
                        $results['matched']++;

                        $dedup_check_date = ($stage === 't_plus_5_escalation') ? $date_sent : '';
                        if (self::is_already_sent($sec->id, $act_key, $stage, $dedup_check_date)) {
                            $results['skipped_dedup']++;
                            continue;
                        }

                        $dispatch = self::send_cliq_message($recipient_emails, $body, ['title' => $subject, 'theme' => $card_theme], $dry_run);

                        if ($dispatch['success']) {
                            $results['sent']++;
                            if (!$dry_run) {
                                self::log_notification(
                                    $sec->id,
                                    0,
                                    'ss',
                                    $act_key,
                                    $stage,
                                    implode(',', $recipient_emails),
                                    'sent'
                                );
                            }
                        } else {
                            $results['errors'][] = "Failed SS [{$batch_name}/{$act_name}]: " . $dispatch['response'];
                        }

                        $results['details'][] = [
                            'type'       => 'ss',
                            'batch'      => $batch_name,
                            'activity'   => $act_name,
                            'stage'      => $stage,
                            'recipients' => $recipient_emails,
                        ];
                    }
                }
            }

            // -----------------------------------------------------------------
            // B. MENTOR ACTIVITIES (Module-Level)
            // -----------------------------------------------------------------
            if (!empty($sec->moduledata)) {
                $modules = util::decode_module_data($sec->moduledata, true);

                foreach ($modules as $mod_idx => $sm) {
                    $courseid = (int)($sm['moodlecourseid'] ?? 0);
                    $mod_name = trim((string)($sm['name'] ?? ('Module ' . ($mod_idx + 1))));
                    $mod_p_start = (int)($sm['plannedstart'] ?? 0);

                    if ($courseid <= 0 || $mod_p_start <= 0) {
                        continue;
                    }

                    // Resolve Assigned Mentors for this module
                    $mentor_cands = [
                        $sm['primarymentor'] ?? '',
                        $sm['secondarymentor'] ?? '',
                        $sm['labmentor1'] ?? '',
                        $sm['labmentor2'] ?? '',
                        $sm['labmentor3'] ?? ''
                    ];

                    $mentor_emails = [];
                    $mentor_names  = [];
                    foreach ($mentor_cands as $mcand) {
                        $u = self::resolve_user($mcand);
                        if ($u && !empty($u->email)) {
                            $mentor_emails[] = $u->email;
                            $mentor_names[]  = fullname($u);
                        }
                    }
                    $mentor_emails = array_values(array_unique($mentor_emails));
                    $mentor_names_str = !empty($mentor_names) ? implode(', ', array_unique($mentor_names)) : 'Assigned Mentor';

                    // Fetch course mentor activities
                    try {
                        $mdata = mentor_activity_service::get_course_mentor_activities($courseid, $mod_name, $mod_p_start, (int)$sec->id, false);
                        $activities = $mdata['activities'] ?? [];

                        $module_url = $CFG->wwwroot . '/local/batchanalytics/module.php?courseid=' . $courseid
                                    . '&sectionid=' . $sec->id . '&batchid=' . $sec->id . '&module=' . (int)($sm['module'] ?? 1);

                        foreach ($activities as $act) {
                            if (!empty($act['completed'])) {
                                continue;
                            }

                            $p_ts = (int)($act['planned_ts'] ?? 0);
                            if ($p_ts <= 0) {
                                continue;
                            }

                            $act_key = $act['key'];
                            $act_name = $act['name'];
                            $due_date_str = date('d M Y', $p_ts);

                            $diff_days = (int)round(($p_ts - $today_midnight) / 86400);

                            $stage = null;
                            $recipient_emails = [];
                            $card_theme = 'modern-inline';
                            $subject = '';
                            $body = '';

                            $placeholders = [
                                '{batch_name}'    => $batch_name,
                                '{module_name}'   => $mod_name,
                                '{task_name}'     => $act_name,
                                '{activity_name}' => $act_name,
                                '{due_date}'      => $due_date_str,
                                '{mentor_name}'   => $mentor_names_str,
                                '{pm_name}'       => $pm_name,
                                '{overdue_days}'  => abs($diff_days),
                                '{link}'          => $module_url,
                                '{url}'           => $module_url,
                            ];

                            if ($diff_days >= 1 && $diff_days <= 3) {
                                // 1. Before 3 days -> Mentor
                                $stage = 't_minus_3';
                                $recipient_emails = $mentor_emails;
                                $card_theme = 'modern-inline';
                                $stg_def = $defs['mentor']['stages']['t_minus_3'];
                                $subject = self::render_template(self::get_template_subject('mentor_t_minus_3', $stg_def['default_subject']), $placeholders);
                                $body = self::render_template(self::get_template_body('mentor_t_minus_3', $stg_def['default_body']), $placeholders);
                            } else if ($diff_days === 0 || ($diff_days < 0 && $diff_days > -3)) {
                                // 2. On Due Date -> Mentor
                                $stage = 'due_today';
                                $recipient_emails = $mentor_emails;
                                $card_theme = 'amber';
                                $stg_def = $defs['mentor']['stages']['due_today'];
                                $subject = self::render_template(self::get_template_subject('mentor_due_today', $stg_def['default_subject']), $placeholders);
                                $body = self::render_template(self::get_template_body('mentor_due_today', $stg_def['default_body']), $placeholders);
                            } else if ($diff_days <= -3 && $diff_days > -5) {
                                // 3. After 3 days overdue -> Mentor
                                $stage = 't_plus_3';
                                $recipient_emails = $mentor_emails;
                                $card_theme = 'red';
                                $stg_def = $defs['mentor']['stages']['t_plus_3'];
                                $subject = self::render_template(self::get_template_subject('mentor_t_plus_3', $stg_def['default_subject']), $placeholders);
                                $body = self::render_template(self::get_template_body('mentor_t_plus_3', $stg_def['default_body']), $placeholders);
                            } else if ($diff_days <= -5) {
                                // 4. After 5th day overdue -> Program Manager Escalation (PM Only)
                                $stage = 't_plus_5_escalation';
                                $recipient_emails = array_filter([$pm_email]);
                                $card_theme = 'red';
                                $stg_def = $defs['mentor']['stages']['t_plus_5'];
                                $subject = self::render_template(self::get_template_subject('mentor_t_plus_5', $stg_def['default_subject']), $placeholders);
                                $body = self::render_template(self::get_template_body('mentor_t_plus_5', $stg_def['default_body']), $placeholders);
                            }

                            if ($stage !== null) {
                                $results['matched']++;

                                $act_unique_key = 'mentor_' . $courseid . '_' . $act_key;
                                $dedup_check_date = ($stage === 't_plus_5_escalation') ? $date_sent : '';

                                if (self::is_already_sent($sec->id, $act_unique_key, $stage, $dedup_check_date)) {
                                    $results['skipped_dedup']++;
                                    continue;
                                }

                                $dispatch = self::send_cliq_message($recipient_emails, $body, ['title' => $subject, 'theme' => $card_theme], $dry_run);

                                if ($dispatch['success']) {
                                    $results['sent']++;
                                    if (!$dry_run) {
                                        self::log_notification(
                                            $sec->id,
                                            $courseid,
                                            'mentor',
                                            $act_unique_key,
                                            $stage,
                                            implode(',', $recipient_emails),
                                            'sent'
                                        );
                                    }
                                } else {
                                    $results['errors'][] = "Failed Mentor [{$batch_name}/{$mod_name}/{$act_name}]: " . $dispatch['response'];
                                }

                                $results['details'][] = [
                                    'type'       => 'mentor',
                                    'batch'      => $batch_name,
                                    'module'     => $mod_name,
                                    'activity'   => $act_name,
                                    'stage'      => $stage,
                                    'recipients' => $recipient_emails,
                                ];
                            }
                        }
                    } catch (\Throwable $e) {
                        // Gracefully log error without halting loop
                        $results['errors'][] = "Error processing module {$mod_name}: " . $e->getMessage();
                    }

                    // Check for Stage Transition: if module actual start date was recorded recently (within last 48 hours)
                    $mod_a_start = (int)($sm['actualstart'] ?? 0);
                    if ($mod_a_start > 0 && $mod_a_start >= ($today_midnight - 172800)) {
                        $dedup_key = 'stage_trans_' . clean_param($mod_name, PARAM_ALPHANUMEXT);
                        if (!self::is_already_sent($sec->id, $dedup_key, 'stage_transition')) {
                            try {
                                $t_res = self::handle_module_start_transition($sec->id, (string)$mod_idx, $modules, $mod_a_start);
                                if (!empty($t_res['success'])) {
                                    $results['sent']++;
                                    $results['details'][] = [
                                        'type'       => 'stage_transition',
                                        'batch'      => $batch_name,
                                        'module'     => $mod_name,
                                        'stage'      => 'stage_transition',
                                        'recipients' => $t_res['recipients'] ?? [],
                                    ];
                                }
                            } catch (\Throwable $te) {
                                $results['errors'][] = "Stage transition alert error for {$batch_name}/{$mod_name}: " . $te->getMessage();
                            }
                        }
                    }
                }
            }

            // -----------------------------------------------------------------
            // C. BATCH REVIEW (Recurring 15-day Cadence for Program Manager)
            // -----------------------------------------------------------------
            try {
                $rev_info = \local_batchanalytics\batch_notes_service::get_batch_review_due_info((int)$sec->id, $sec);
                if ($rev_info) {
                    $rev_due_ts = (int)$rev_info['due_ts'];
                    $diff_days = (int)round(($rev_due_ts - $today_midnight) / 86400);

                    $notes_url = $batch_url . '&tab=notes#panel-notes';
                    $last_rev_str = $rev_info['has_notes']
                        ? (date('d M Y', $rev_info['last_review_ts']) . ($rev_info['last_review_author'] ? ' by ' . $rev_info['last_review_author'] : ''))
                        : ('Initial review (batch started ' . date('d M Y', $rev_info['startdate_ts']) . ')');

                    $placeholders = [
                        '{batch_name}'        => $batch_name,
                        '{pm_name}'           => $pm_name,
                        '{due_date}'          => date('d M Y', $rev_due_ts),
                        '{last_review_info}'  => $last_rev_str,
                        '{overdue_days}'      => abs($diff_days),
                        '{link}'              => $notes_url,
                        '{url}'               => $notes_url,
                    ];

                    $stage = null;
                    $recipient_emails = !empty($pm_email) ? [$pm_email] : [];
                    $card_theme = 'modern-inline';

                    if ($diff_days === 3) {
                        // T-3 Days
                        $stage = 'batch_review_t_minus_3';
                        $card_theme = 'modern-inline';
                        $stg_def = $defs['batch_review']['stages']['t_minus_3'];
                        $subject = self::render_template(self::get_template_subject('batch_review_t_minus_3', $stg_def['default_subject']), $placeholders);
                        $body = self::render_template(self::get_template_body('batch_review_t_minus_3', $stg_def['default_body']), $placeholders);
                    } else if ($diff_days === 0) {
                        // T-0 Due Today
                        $stage = 'batch_review_due_today';
                        $card_theme = 'amber';
                        $stg_def = $defs['batch_review']['stages']['due_today'];
                        $subject = self::render_template(self::get_template_subject('batch_review_due_today', $stg_def['default_subject']), $placeholders);
                        $body = self::render_template(self::get_template_body('batch_review_due_today', $stg_def['default_body']), $placeholders);
                    } else if ($diff_days === -3 || $diff_days <= -5) {
                        // T+3 or T+5 Overdue
                        $stage = ($diff_days === -3) ? 'batch_review_overdue_3' : 'batch_review_overdue_5';
                        $card_theme = 'red';
                        $stg_def = $defs['batch_review']['stages']['overdue'];
                        $subject = self::render_template(self::get_template_subject('batch_review_overdue', $stg_def['default_subject']), $placeholders);
                        $body = self::render_template(self::get_template_body('batch_review_overdue', $stg_def['default_body']), $placeholders);
                    }

                    if ($stage !== null && !empty($recipient_emails)) {
                        $results['matched']++;
                        $act_unique_key = 'batch_review_' . $sec->id;

                        if (self::is_already_sent($sec->id, $act_unique_key, $stage, $date_sent)) {
                            $results['skipped_dedup']++;
                        } else {
                            $dispatch = self::send_cliq_message($recipient_emails, $body, ['title' => $subject, 'theme' => $card_theme], $dry_run);
                            if ($dispatch['success']) {
                                $results['sent']++;
                                if (!$dry_run) {
                                    self::log_notification(
                                        $sec->id,
                                        0,
                                        'batch_review',
                                        $act_unique_key,
                                        $stage,
                                        implode(',', $recipient_emails),
                                        'sent'
                                    );
                                }
                            } else {
                                $results['errors'][] = "Failed Batch Review [{$batch_name}]: " . $dispatch['response'];
                            }

                            $results['details'][] = [
                                'type'       => 'batch_review',
                                'batch'      => $batch_name,
                                'activity'   => 'Batch Review Note',
                                'stage'      => $stage,
                                'recipients' => $recipient_emails,
                            ];
                        }
                    }
                }
            } catch (\Throwable $re) {
                $results['errors'][] = "Batch review evaluation error for {$batch_name}: " . $re->getMessage();
            }
        }

        return $results;
    }

    /**
     * Send real-time instant confirmation alert when an activity is marked completed.
     * Notifies PM + Mentor / SSE.
     *
     * @param string $action_type 'mentor' or 'ss'
     * @param int $batchid
     * @param int $courseid
     * @param string $act_name_or_key
     * @param int $completed_by_userid
     * @return bool
     */
    public static function send_completion_alert(
        string $action_type,
        int $batchid,
        int $courseid,
        string $act_name_or_key,
        int $completed_by_userid
    ): bool {
        global $DB, $CFG;

        if (!self::is_enabled()) {
            return false;
        }

        if ($batchid <= 0 && $courseid > 0) {
            $matching_sections = $DB->get_records_sql(
                "SELECT id FROM {local_bm_classsection} WHERE moduledata LIKE :pattern LIMIT 1",
                ['pattern' => '%' . $courseid . '%']
            );
            if (!empty($matching_sections)) {
                $sec_found = reset($matching_sections);
                $batchid = (int)$sec_found->id;
            }
        }

        $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
        $batch_name = util::clean_section_name($sec ? ($sec->name ?: ('Batch ' . $sec->id)) : ('Batch ' . $batchid));

        $pm_user  = $sec ? (self::resolve_user($sec->pmmanager) ?: self::resolve_user($sec->pmmanagername)) : null;
        $sse_user = $sec ? (self::resolve_user($sec->maacexecutive) ?: self::resolve_user($sec->maacexecutivename)) : null;
        $by_user  = self::resolve_user($completed_by_userid);

        $pm_email  = $pm_user ? $pm_user->email : '';
        $sse_email = $sse_user ? $sse_user->email : '';
        $sse_name  = $sse_user ? fullname($sse_user) : ($sec && !empty($sec->maacexecutivename) ? $sec->maacexecutivename : 'SS Executive');
        $by_name   = $by_user ? fullname($by_user) : 'System (Auto)';
        $by_email  = ($by_user && !empty($by_user->email)) ? $by_user->email : '';
        $comp_date = date('d M Y');

        $defs = self::get_template_definitions();

        if ($action_type === 'ss') {
            $act_name = ucwords(str_replace(['_', '-'], ' ', $act_name_or_key));
            $batch_url = $CFG->wwwroot . '/local/batchanalytics/batch.php?id=' . $batchid;

            // Resolve SS Lead(s)
            $ss_lead_info   = self::resolve_ss_leads();
            $ss_lead_emails = $ss_lead_info['emails'];
            $ss_lead_name   = !empty($ss_lead_info['names']) ? implode(', ', $ss_lead_info['names']) : 'SS Lead';

            // Resolve Current Module Mentor(s)
            $mentor_info       = $sec ? self::resolve_current_module_mentors($sec) : ['emails' => [], 'names' => [], 'module_name' => ''];
            $cur_mentor_emails = $mentor_info['emails'];
            $cur_mentor_name   = !empty($mentor_info['names']) ? implode(', ', $mentor_info['names']) : 'Mentors';
            $cur_module_name   = $mentor_info['module_name'] ?: 'Module';

            // SSE, SS Lead, and Current Module Mentors receive completion notification
            $recipients = array_values(array_filter(array_unique(array_merge([$sse_email, $by_email], $ss_lead_emails, $cur_mentor_emails))));

            $stg_def = $defs['ss']['stages']['completed'];
            $placeholders = [
                '{batch_name}'      => $batch_name,
                '{current_module}'  => $cur_module_name,
                '{activity_name}'   => $act_name,
                '{sse_name}'        => $sse_name,
                '{ss_lead_name}'    => $ss_lead_name,
                '{mentor_name}'     => $cur_mentor_name,
                '{completed_by}'    => $by_name,
                '{completion_date}' => $comp_date,
                '{link}'            => $batch_url,
                '{url}'             => $batch_url,
            ];

            $subject = self::render_template(self::get_template_subject('ss_completed', $stg_def['default_subject']), $placeholders);
            $body = self::render_template(self::get_template_body('ss_completed', $stg_def['default_body']), $placeholders);

            $dispatch = self::send_cliq_message($recipients, $body, ['title' => $subject, 'theme' => 'green']);
            $status = !empty($dispatch['success']) ? 'sent' : 'failed';
            self::log_notification($batchid, 0, 'ss', $act_name_or_key, 'completed', implode(',', $recipients), $status);
            return $dispatch['success'];
        }

        if ($action_type === 'mentor') {
            $module_name = 'Module';
            $mentor_emails = [];

            if ($sec && !empty($sec->moduledata)) {
                $modules = util::decode_module_data($sec->moduledata, true);
                foreach ($modules as $sm) {
                    if ((int)($sm['moodlecourseid'] ?? 0) === $courseid) {
                        $module_name = trim((string)($sm['name'] ?? 'Module'));
                        $cands = [
                            $sm['primarymentor'] ?? '',
                            $sm['secondarymentor'] ?? '',
                            $sm['labmentor1'] ?? '',
                            $sm['labmentor2'] ?? '',
                            $sm['labmentor3'] ?? ''
                        ];
                        foreach ($cands as $c) {
                            $u = self::resolve_user($c);
                            if ($u && !empty($u->email)) {
                                $mentor_emails[] = $u->email;
                            }
                        }
                        break;
                    }
                }
            }

            $module_url = $CFG->wwwroot . '/local/batchanalytics/module.php?courseid=' . $courseid . '&batchid=' . $batchid;
            // Both the mentor and PM receive the notification when activity is completed
            $recipients = array_values(array_filter(array_unique(array_merge([$pm_email, $by_email], $mentor_emails))));

            $stg_def = $defs['mentor']['stages']['completed'];
            $placeholders = [
                '{batch_name}'      => $batch_name,
                '{module_name}'     => $module_name,
                '{task_name}'       => $act_name_or_key,
                '{activity_name}'   => $act_name_or_key,
                '{completed_by}'    => $by_name,
                '{completion_date}' => $comp_date,
                '{link}'            => $module_url,
                '{url}'             => $module_url,
            ];

            $subject = self::render_template(self::get_template_subject('mentor_completed', $stg_def['default_subject']), $placeholders);
            $body = self::render_template(self::get_template_body('mentor_completed', $stg_def['default_body']), $placeholders);

            $dispatch = self::send_cliq_message($recipients, $body, ['title' => $subject, 'theme' => 'green']);
            $status = !empty($dispatch['success']) ? 'sent' : 'failed';
            self::log_notification($batchid, $courseid, 'mentor', $act_name_or_key, 'completed', implode(',', $recipients), $status);
            return $dispatch['success'];
        }

        if ($action_type === 'batch_review') {
            return self::send_batch_review_note_alert($batchid, $completed_by_userid, $by_name, 'Batch review note recorded.');
        }

        return false;
    }

    /**
     * Compute batch performance telemetry (overall attendance, MAAC rating, students, completion).
     *
     * @param int $batchid
     * @param int $prev_courseid
     * @return array{overall_attendance: string, overall_maac_rating: string, student_count: string, prev_module_completion: string, batch_status: string}
     */
    public static function get_batch_performance_summary(int $batchid, int $prev_courseid = 0): array {
        global $DB;

        $summary = [
            'overall_attendance'      => '—',
            'overall_maac_rating'     => '—',
            'student_count'           => '—',
            'prev_module_completion'  => '—',
            'batch_status'            => 'Active (On Track)',
        ];

        if ($batchid <= 0) {
            return $summary;
        }

        $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
        if (!$sec) {
            return $summary;
        }

        // 1. Resolve Enrolled Students
        $student_role_id = $DB->get_field('role', 'id', ['shortname' => 'student']) ?: 5;
        $courseids = [];
        if (!empty($sec->moduledata)) {
            $modules = util::decode_module_data($sec->moduledata, true);
            foreach ($modules as $m) {
                $cid = (int)($m['moodlecourseid'] ?? 0);
                if ($cid > 0) {
                    $courseids[] = $cid;
                }
            }
        }
        $courseids = array_values(array_unique(array_filter($courseids)));

        if ($prev_courseid <= 0 && !empty($courseids)) {
            $prev_courseid = reset($courseids);
        }

        $userids = [];
        if (!empty($courseids)) {
            list($cin, $cparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
            $cparams['roleid'] = $student_role_id;
            $students_sql = "
                SELECT DISTINCT u.id
                FROM {user} u
                JOIN {role_assignments} ra ON ra.userid = u.id
                JOIN {context} ctx ON ctx.id = ra.contextid
                WHERE ctx.instanceid $cin
                  AND ctx.contextlevel = 50
                  AND ra.roleid = :roleid
                  AND u.deleted = 0
            ";
            $userids = array_keys($DB->get_records_sql($students_sql, $cparams));
        }

        $student_count = count($userids);
        $summary['student_count'] = $student_count > 0 ? (string)$student_count : '—';

        // 2. Attendance percentage (for previous course or across courses)
        $att_courseid = $prev_courseid > 0 ? $prev_courseid : (!empty($courseids) ? reset($courseids) : 0);
        if ($att_courseid > 0) {
            $att_pct = util::get_course_attendance_percentage($att_courseid, $userids);
            if ($att_pct !== null) {
                $summary['overall_attendance'] = $att_pct . '%';
            }
        }

        // 3. MAAC Rating
        if ($att_courseid > 0) {
            $dbman = $DB->get_manager();
            $maac_val = null;
            if ($dbman->table_exists('local_batchanalytics_maac')) {
                $sql = "SELECT AVG(CAST(value AS DECIMAL(5,2))) as avg_val 
                          FROM {local_batchanalytics_maac} 
                         WHERE courseid = :cid AND value IS NOT NULL AND value != ''";
                $rec = $DB->get_record_sql($sql, ['cid' => $att_courseid]);
                if ($rec && $rec->avg_val !== null && (float)$rec->avg_val > 0) {
                    $maac_val = round((float)$rec->avg_val, 1);
                }
            }
            if ($maac_val === null) {
                // Check course final grade scaled out of 10
                $sql = "SELECT AVG(gg.finalgrade / gi.grademax * 10) as avg_rating
                          FROM {grade_items} gi
                          JOIN {grade_grades} gg ON gg.itemid = gi.id
                         WHERE gi.courseid = :cid AND gi.itemtype = 'course' AND gi.grademax > 0 AND gg.finalgrade IS NOT NULL";
                $rec = $DB->get_record_sql($sql, ['cid' => $att_courseid]);
                if ($rec && $rec->avg_rating !== null && (float)$rec->avg_rating > 0) {
                    $maac_val = round((float)$rec->avg_rating, 1);
                }
            }
            if ($maac_val !== null) {
                $summary['overall_maac_rating'] = $maac_val . ' / 10';
            }
        }

        // 4. Previous Module Completion
        if ($att_courseid > 0) {
            $sql = "SELECT COUNT(DISTINCT userid) as completed_count
                      FROM {course_completions}
                     WHERE course = :cid AND timecompleted IS NOT NULL AND timecompleted > 0";
            $rec = $DB->get_record_sql($sql, ['cid' => $att_courseid]);
            if ($rec && $student_count > 0 && (int)$rec->completed_count > 0) {
                $comp_pct = round(((int)$rec->completed_count / $student_count) * 100, 1);
                $summary['prev_module_completion'] = $comp_pct . '% (' . (int)$rec->completed_count . '/' . $student_count . ')';
            } else {
                $summary['prev_module_completion'] = 'Completed';
            }
        }

        return $summary;
    }

    /**
     * Build standard HTML email format matching Emertxe Digitization & Automation specifications.
     *
     * @param array $placeholders
     * @return string HTML email content
     */
    public static function build_stage_transition_html_email(array $placeholders): string {
        $batch = htmlspecialchars($placeholders['{batch_name}'] ?? '');
        $prev_mod = htmlspecialchars($placeholders['{previous_module}'] ?? '');
        $next_mod = htmlspecialchars($placeholders['{next_module}'] ?? '');
        $trans_date = htmlspecialchars($placeholders['{transition_date}'] ?? '');
        $cm = htmlspecialchars($placeholders['{next_class_mentor}'] ?? '—');
        $lm = htmlspecialchars($placeholders['{next_lab_mentor}'] ?? '—');
        $att = htmlspecialchars($placeholders['{overall_attendance}'] ?? '—');
        $maac = htmlspecialchars($placeholders['{overall_maac_rating}'] ?? '—');
        $students = htmlspecialchars($placeholders['{student_count}'] ?? '—');
        $comp = htmlspecialchars($placeholders['{prev_module_completion}'] ?? '—');
        $status = htmlspecialchars($placeholders['{batch_status}'] ?? 'Active (On Track)');
        $link = htmlspecialchars($placeholders['{link}'] ?? '#');
        $salutation = htmlspecialchars($placeholders['{pm_name}'] ?? 'Program Manager') . ' / '
                    . htmlspecialchars($placeholders['{sse_name}'] ?? 'SSE') . ' / '
                    . htmlspecialchars($placeholders['{next_mentor_name}'] ?? 'Next Mentor');

        return '
        <div style="font-family: Arial, Helvetica, sans-serif; color: #1e293b; max-width: 650px; margin: 0 auto; padding: 20px; line-height: 1.6;">
            <p style="font-size: 15px; margin-bottom: 12px;">Dear ' . $salutation . ',</p>
            <p style="font-size: 14px; margin-bottom: 16px;">This is to inform you that batch <strong>' . $batch . '</strong> has moved from <strong>' . $prev_mod . '</strong> to <strong>' . $next_mod . '</strong>.</p>
            <div style="text-align: center; margin: 16px 0; color: #64748b; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                Emertxe &ndash; For Digitization &amp; Automation Team
            </div>
            <p style="font-size: 14px; margin-bottom: 20px;">The applicable Student Success activities have been re-anchored to the new stage.</p>
            
            <h4 style="color: #0f172a; margin: 20px 0 8px 0; font-size: 15px;">Notification Details:</h4>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 13.5px;">
                <thead>
                    <tr style="background-color: #0073aa; color: #ffffff; text-align: left;">
                        <th style="padding: 10px 14px; border: 1px solid #0073aa; width: 35%;">Field</th>
                        <th style="padding: 10px 14px; border: 1px solid #0073aa;">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background-color: #ffffff;">
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">Batch ID</td>
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; color: #0f172a;">' . $batch . '</td>
                    </tr>
                    <tr style="background-color: #f8fafc;">
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">Previous Module</td>
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; color: #0f172a;">' . $prev_mod . '</td>
                    </tr>
                    <tr style="background-color: #ffffff;">
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">Next Module</td>
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #0284c7;">' . $next_mod . '</td>
                    </tr>
                    <tr style="background-color: #f8fafc;">
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">Transition Date</td>
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; color: #0f172a;">' . $trans_date . '</td>
                    </tr>
                    <tr style="background-color: #ffffff;">
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">Class Mentor</td>
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; color: #0f172a;">' . $cm . '</td>
                    </tr>
                    <tr style="background-color: #f8fafc;">
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">Lab Mentor</td>
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; color: #0f172a;">' . $lm . '</td>
                    </tr>
                </tbody>
            </table>

            <h4 style="color: #0f172a; margin: 20px 0 8px 0; font-size: 15px;">📊 Batch Performance Summary:</h4>
            <div style="background-color: #f1f5f9; border-left: 4px solid #0284c7; border-radius: 4px; padding: 14px 18px; margin-bottom: 24px; font-size: 13.5px;">
                <p style="margin: 4px 0;"><strong>Overall Attendance:</strong> ' . $att . '</p>
                <p style="margin: 4px 0;"><strong>MAAC Rating:</strong> ' . $maac . '</p>
                <p style="margin: 4px 0;"><strong>Total Students:</strong> ' . $students . '</p>
                <p style="margin: 4px 0;"><strong>Previous Module Completion:</strong> ' . $comp . '</p>
                <p style="margin: 4px 0;"><strong>Batch Status:</strong> ' . $status . '</p>
            </div>

            <h4 style="color: #0f172a; margin: 20px 0 8px 0; font-size: 15px;">Action Required:</h4>
            <p style="font-size: 14px; margin-bottom: 12px;">Review the activities relevant to the new stage and proceed with the assigned responsibilities.</p>
            <p style="margin-bottom: 24px;"><a href="' . $link . '" style="display: inline-block; background-color: #0284c7; color: #ffffff; text-decoration: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; font-size: 13px;">🔗 View Batch in LMS</a></p>

            <p style="font-size: 14px; margin-top: 24px; color: #334155;">
                Regards,<br>
                <strong>Emertxe Information Technologies</strong>
            </p>
        </div>';
    }

    /**
     * Build rich HTML email for Module Assigned notification to Class/Lab Mentor.
     *
     * @param array $placeholders
     * @return string HTML email content
     */
    public static function build_module_assigned_html_email(array $placeholders): string {
        $mentor_name = htmlspecialchars($placeholders['{mentor_name}'] ?? 'Mentor');
        $mentor_role = htmlspecialchars($placeholders['{mentor_role}'] ?? 'Class/Lab Mentor');
        $batch_id    = htmlspecialchars($placeholders['{batch_id}'] ?? ($placeholders['{batch_name}'] ?? '—'));
        $module_name = htmlspecialchars($placeholders['{module_name}'] ?? '—');
        $start_date  = htmlspecialchars($placeholders['{actual_start_date}'] ?? '—');
        $end_date    = htmlspecialchars($placeholders['{planned_end_date}'] ?? '—');
        $link        = htmlspecialchars($placeholders['{link}'] ?? ($placeholders['{url}'] ?? '#'));

        return '
        <div style="font-family: Arial, Helvetica, sans-serif; color: #1e293b; max-width: 650px; margin: 0 auto; padding: 20px; line-height: 1.6;">
            <p style="font-size: 15px; margin-bottom: 12px;">Dear ' . $mentor_name . ',</p>
            <p style="font-size: 14px; margin-bottom: 16px;">This is to inform you that the <strong>' . $module_name . '</strong> module for batch <strong>' . $batch_id . '</strong> has been assigned to you.</p>
            <p style="font-size: 14px; margin-bottom: 20px;">You are assigned as the <strong>' . $mentor_role . '</strong> for this module. Please review the module plan and conduct the scheduled classes/labs within the planned timeline.</p>
            
            <div style="text-align: center; margin: 16px 0; color: #64748b; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                Emertxe &ndash; For Digitization &amp; Automation Team
            </div>

            <h4 style="color: #0f172a; margin: 20px 0 8px 0; font-size: 15px;">Notification Details:</h4>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 13.5px;">
                <thead>
                    <tr style="background-color: #0073aa; color: #ffffff; text-align: left;">
                        <th style="padding: 10px 14px; border: 1px solid #0073aa; width: 35%;">Field</th>
                        <th style="padding: 10px 14px; border: 1px solid #0073aa;">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background-color: #ffffff;">
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">Batch ID</td>
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; color: #0f172a;">' . $batch_id . '</td>
                    </tr>
                    <tr style="background-color: #f8fafc;">
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">Module</td>
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #0284c7;">' . $module_name . '</td>
                    </tr>
                    <tr style="background-color: #ffffff;">
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">Actual Start Date</td>
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; color: #0f172a;">' . $start_date . '</td>
                    </tr>
                    <tr style="background-color: #f8fafc;">
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">Planned End Date</td>
                        <td style="padding: 9px 14px; border: 1px solid #cbd5e1; color: #0f172a;">' . $end_date . '</td>
                    </tr>
                </tbody>
            </table>

            <h4 style="color: #0f172a; margin: 20px 0 8px 0; font-size: 15px;">Action Required:</h4>
            <p style="font-size: 14px; margin-bottom: 16px;">Review the module plan and proceed with the scheduled classes/labs as per the planned timeline.</p>
            <p style="margin-bottom: 24px;"><a href="' . $link . '" style="display: inline-block; background-color: #0284c7; color: #ffffff; text-decoration: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; font-size: 13px;">🔗 Open Module in LMS</a></p>

            <p style="font-size: 14px; margin-top: 24px; color: #334155;">
                Regards,<br>
                <strong>Emertxe Information Technologies</strong>
            </p>
        </div>';
    }

    /**
     * Send stage transition notification to PM, SSE, Next Class Mentor, and Next Lab Mentor.
     * Dispatches both Zoho Cliq message and HTML email.
     *
     * @param int $batchid
     * @param string $prev_module_name
     * @param string $next_module_name
     * @param int $transition_timestamp
     * @param int $next_courseid
     * @param int $prev_courseid
     * @param array $next_mod_data
     * @return array
     */
    public static function send_stage_transition_alert(
        int $batchid,
        string $prev_module_name,
        string $next_module_name,
        int $transition_timestamp = 0,
        int $next_courseid = 0,
        int $prev_courseid = 0,
        array $next_mod_data = []
    ): array {
        global $DB, $CFG;

        $results = [
            'success'     => false,
            'recipients'  => [],
            'emails_sent' => 0,
            'errors'      => [],
        ];

        $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
        if (!$sec) {
            $results['errors'][] = 'Class section not found for ID: ' . $batchid;
            return $results;
        }

        $batch_name = util::clean_section_name($sec->name ?: ('Batch ' . $sec->id));
        $batch_url  = $CFG->wwwroot . '/local/batchanalytics/batch.php?id=' . $sec->id;
        $trans_ts   = $transition_timestamp > 0 ? $transition_timestamp : time();
        $trans_date = date('d M Y', $trans_ts);

        // 1. Resolve Stakeholders
        $pm_user  = self::resolve_user($sec->pmmanager) ?: self::resolve_user($sec->pmmanagername);
        $sse_user = self::resolve_user($sec->maacexecutive) ?: self::resolve_user($sec->maacexecutivename);

        $class_mentor_user = null;
        $lab_mentor_user   = null;

        if (!empty($next_mod_data)) {
            $class_mentor_cand = $next_mod_data['primarymentor'] ?? $next_mod_data['secondarymentor'] ?? '';
            $lab_mentor_cand   = $next_mod_data['labmentor1'] ?? $next_mod_data['labmentor2'] ?? '';
            $class_mentor_user = self::resolve_user($class_mentor_cand);
            $lab_mentor_user   = self::resolve_user($lab_mentor_cand);
        } else if (!empty($sec->moduledata)) {
            $modules = util::decode_module_data($sec->moduledata, true);
            foreach ($modules as $m) {
                if (strcasecmp(trim($m['name']), trim($next_module_name)) === 0 || ($next_courseid > 0 && (int)($m['moodlecourseid'] ?? 0) === $next_courseid)) {
                    $class_mentor_user = self::resolve_user($m['primarymentor'] ?? '');
                    $lab_mentor_user   = self::resolve_user($m['labmentor1'] ?? '');
                    break;
                }
            }
        }

        $pm_name   = $pm_user ? fullname($pm_user) : ($sec->pmmanagername ?: 'Program Manager');
        $sse_name  = $sse_user ? fullname($sse_user) : ($sec->maacexecutivename ?: 'SS Executive');
        $cm_name   = $class_mentor_user ? fullname($class_mentor_user) : ($next_mod_data['primarymentor'] ?? 'Assigned Class Mentor');
        $lm_name   = $lab_mentor_user ? fullname($lab_mentor_user) : ($next_mod_data['labmentor1'] ?? 'Assigned Lab Mentor');

        $next_mentor_names = trim($cm_name . ' (Class) / ' . $lm_name . ' (Lab)');

        // Gather recipient users and emails
        $recipient_users  = [];
        $recipient_emails = [];

        if ($pm_user && !empty($pm_user->email)) {
            $recipient_users[]  = $pm_user;
            $recipient_emails[] = $pm_user->email;
        }
        if ($sse_user && !empty($sse_user->email)) {
            $recipient_users[]  = $sse_user;
            $recipient_emails[] = $sse_user->email;
        }
        if ($class_mentor_user && !empty($class_mentor_user->email)) {
            $recipient_users[]  = $class_mentor_user;
            $recipient_emails[] = $class_mentor_user->email;
        }
        if ($lab_mentor_user && !empty($lab_mentor_user->email)) {
            $recipient_users[]  = $lab_mentor_user;
            $recipient_emails[] = $lab_mentor_user->email;
        }

        $recipient_emails = array_values(array_filter(array_unique($recipient_emails)));
        $results['recipients'] = $recipient_emails;

        // 2. Compute Batch Performance Summary
        $perf = self::get_batch_performance_summary($batchid, $prev_courseid);

        // 3. Prepare Placeholders
        $placeholders = [
            '{batch_name}'              => $batch_name,
            '{previous_module}'         => $prev_module_name,
            '{next_module}'             => $next_module_name,
            '{transition_date}'         => $trans_date,
            '{pm_name}'                 => $pm_name,
            '{sse_name}'                => $sse_name,
            '{next_mentor_name}'        => $next_mentor_names,
            '{next_class_mentor}'       => $cm_name,
            '{next_lab_mentor}'         => $lm_name,
            '{overall_attendance}'      => $perf['overall_attendance'],
            '{overall_maac_rating}'     => $perf['overall_maac_rating'],
            '{student_count}'           => $perf['student_count'],
            '{prev_module_completion}'  => $perf['prev_module_completion'],
            '{batch_status}'            => $perf['batch_status'],
            '{link}'                    => $batch_url,
            '{url}'                     => $batch_url,
        ];

        $defs = self::get_template_definitions();
        $stg_def = $defs['transition']['stages']['stage_transition'] ?? null;
        $default_sub = $stg_def['default_subject'] ?? 'ℹ️ Batch Stage Transition: {batch_name} moved to {next_module}';
        $default_bod = $stg_def['default_body'] ?? '';

        $subject = self::render_template(self::get_template_subject('stage_transition', $default_sub), $placeholders);
        $body    = self::render_template(self::get_template_body('stage_transition', $default_bod), $placeholders);

        $dedup_key = 'stage_trans_' . clean_param($next_module_name, PARAM_ALPHANUMEXT);
        if (self::is_already_sent($batchid, $dedup_key, 'stage_transition')) {
            $results['skipped_dedup'] = true;
            $results['success'] = true;
            return $results;
        }

        // 4. Send via Zoho Cliq Bot API (if enabled)
        $cliq_res = ['success' => false];
        if (self::is_enabled()) {
            $slides = [
                [
                    'type' => 'table',
                    'title' => '📋 Notification Details',
                    'data' => [
                        'headers' => ['Field', 'Value'],
                        'rows' => [
                            ['Field' => 'Batch ID', 'Value' => (string)$batch_name],
                            ['Field' => 'Previous Module', 'Value' => (string)$prev_module_name],
                            ['Field' => 'Next Module', 'Value' => (string)$next_module_name],
                            ['Field' => 'Transition Date', 'Value' => (string)$trans_date],
                            ['Field' => 'Class Mentor', 'Value' => (string)$cm_name],
                            ['Field' => 'Lab Mentor', 'Value' => (string)$lm_name],
                        ],
                    ],
                ],
                [
                    'type' => 'table',
                    'title' => '📊 Batch Performance Summary',
                    'data' => [
                        'headers' => ['Metric', 'Performance'],
                        'rows' => [
                            ['Metric' => 'Overall Attendance', 'Performance' => (string)$perf['overall_attendance']],
                            ['Metric' => 'MAAC Rating', 'Performance' => (string)$perf['overall_maac_rating']],
                            ['Metric' => 'Total Students', 'Performance' => (string)$perf['student_count']],
                            ['Metric' => 'Previous Module Completion', 'Performance' => (string)$perf['prev_module_completion']],
                            ['Metric' => 'Batch Status', 'Performance' => (string)$perf['batch_status']],
                        ],
                    ],
                ],
            ];

            $cliq_res = self::send_cliq_message($recipient_emails, $body, [
                'title'  => $subject,
                'theme'  => 'modern-inline',
                'slides' => $slides,
            ]);
        }

        // 5. Send via Moodle Email (HTML format) to each recipient
        $email_count = 0;
        $html_body = self::build_stage_transition_html_email($placeholders);
        $noreply_user = \core_user::get_noreply_user();

        foreach ($recipient_users as $target_u) {
            try {
                if (email_to_user($target_u, $noreply_user, $subject, $body, $html_body)) {
                    $email_count++;
                }
            } catch (\Throwable $ex) {
                $results['errors'][] = 'Email error for ' . $target_u->email . ': ' . $ex->getMessage();
            }
        }

        $results['success'] = ($cliq_res['success'] ?? false) || ($email_count > 0);
        $results['emails_sent'] = $email_count;

        if ($results['success']) {
            self::log_notification($batchid, $next_courseid, 'transition', $dedup_key, 'stage_transition', implode(',', $recipient_emails));
        }

        return $results;
    }

    /**
     * Dispatch Module Assigned notification to assigned Class and Lab Mentors.
     *
     * @param int $batchid
     * @param string $mod_name
     * @param array $mod_data Module data array containing dates and mentor IDs
     * @param int $actualstart_ts Optional actual start timestamp
     * @param int $plannedend_ts Optional planned end timestamp
     * @param \stdClass|null $target_mentor_user Optional single user to send to
     * @param string $target_role Optional role override
     * @return array Results summary
     */
    public static function send_module_assigned_mentor_alert(
        int $batchid,
        string $mod_name,
        array $mod_data,
        int $actualstart_ts = 0,
        int $plannedend_ts = 0,
        ?\stdClass $target_mentor_user = null,
        string $target_role = ''
    ): array {
        global $DB, $CFG;

        $results = [
            'success'     => false,
            'recipients'  => [],
            'emails_sent' => 0,
            'errors'      => [],
        ];

        $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
        if (!$sec) {
            $results['errors'][] = 'Class section not found for ID: ' . $batchid;
            return $results;
        }

        $batch_name = util::clean_section_name($sec->name ?: ('Batch ' . $sec->id));
        $courseid   = (int)($mod_data['moodlecourseid'] ?? 0);
        $mod_idx    = (int)($mod_data['module'] ?? 1);

        $module_url = $courseid > 0
            ? ($CFG->wwwroot . '/local/batchanalytics/module.php?courseid=' . $courseid . '&sectionid=' . $sec->id . '&batchid=' . $sec->id . '&module=' . $mod_idx)
            : ($CFG->wwwroot . '/local/batchanalytics/batch.php?id=' . $sec->id);

        $a_start = $actualstart_ts > 0 ? $actualstart_ts : (int)($mod_data['actualstart'] ?? ($mod_data['plannedstart'] ?? time()));
        $p_end   = $plannedend_ts > 0 ? $plannedend_ts : (int)($mod_data['plannedend'] ?? 0);

        $actual_start_str = $a_start > 0 ? date('d M Y', $a_start) : 'Not Started';
        $planned_end_str  = $p_end > 0 ? date('d M Y', $p_end) : 'Pending Schedule';

        // Resolve recipients
        $mentors_to_notify = [];

        if ($target_mentor_user) {
            $mentors_to_notify[] = [
                'user' => $target_mentor_user,
                'role' => !empty($target_role) ? $target_role : 'Class/Lab Mentor',
            ];
        } else {
            $class_cands = [
                $mod_data['primarymentor'] ?? '',
                $mod_data['secondarymentor'] ?? '',
            ];
            $lab_cands = [
                $mod_data['labmentor1'] ?? '',
                $mod_data['labmentor2'] ?? '',
                $mod_data['labmentor3'] ?? '',
            ];

            $class_users = [];
            foreach ($class_cands as $c) {
                $u = self::resolve_user($c);
                if ($u && !empty($u->email)) {
                    $class_users[$u->id] = $u;
                }
            }

            $lab_users = [];
            foreach ($lab_cands as $c) {
                $u = self::resolve_user($c);
                if ($u && !empty($u->email)) {
                    $lab_users[$u->id] = $u;
                }
            }

            $all_user_ids = array_unique(array_merge(array_keys($class_users), array_keys($lab_users)));
            foreach ($all_user_ids as $uid) {
                $in_class = isset($class_users[$uid]);
                $in_lab   = isset($lab_users[$uid]);
                $u = $class_users[$uid] ?? $lab_users[$uid];

                if ($in_class && $in_lab) {
                    $role = 'Class/Lab Mentor';
                } else if ($in_class) {
                    $role = 'Class Mentor';
                } else {
                    $role = 'Lab Mentor';
                }

                $mentors_to_notify[] = [
                    'user' => $u,
                    'role' => $role,
                ];
            }
        }

        if (empty($mentors_to_notify)) {
            $results['errors'][] = 'No mentors found for module ' . $mod_name;
            return $results;
        }

        $defs = self::get_template_definitions();
        $stg_def = $defs['transition']['stages']['module_assigned_mentor'] ?? null;
        $default_sub = $stg_def['default_subject'] ?? 'Module Assigned - {module_name} | {batch_id}';
        $default_bod = $stg_def['default_body'] ?? '';

        $noreply_user = \core_user::get_noreply_user();
        $any_success = false;

        foreach ($mentors_to_notify as $item) {
            $mentor_u = $item['user'];
            $role     = $item['role'];

            $dedup_key = 'mod_assign_' . clean_param($mod_name, PARAM_ALPHANUMEXT) . '_u' . $mentor_u->id;
            if (self::is_already_sent($batchid, $dedup_key, 'module_assigned')) {
                continue;
            }

            $placeholders = [
                '{mentor_name}'       => fullname($mentor_u),
                '{mentor_role}'       => $role,
                '{batch_id}'          => $batch_name,
                '{batch_name}'        => $batch_name,
                '{module_name}'       => $mod_name,
                '{actual_start_date}' => $actual_start_str,
                '{planned_end_date}'  => $planned_end_str,
                '{link}'              => $module_url,
                '{url}'               => $module_url,
            ];

            $subject = self::render_template(self::get_template_subject('module_assigned_mentor', $default_sub), $placeholders);
            $body    = self::render_template(self::get_template_body('module_assigned_mentor', $default_bod), $placeholders);

            $cliq_ok = false;
            if (self::is_enabled()) {
                $slides = [
                    [
                        'type' => 'table',
                        'title' => '📋 Notification Details',
                        'data' => [
                            'headers' => ['Field', 'Value'],
                            'rows' => [
                                ['Field' => 'Batch ID', 'Value' => (string)$batch_name],
                                ['Field' => 'Module', 'Value' => (string)$mod_name],
                                ['Field' => 'Actual Start Date', 'Value' => (string)$actual_start_str],
                                ['Field' => 'Planned End Date', 'Value' => (string)$planned_end_str],
                            ],
                        ],
                    ],
                ];

                $cres = self::send_cliq_message([$mentor_u->email], $body, [
                    'title'  => $subject,
                    'theme'  => 'modern-inline',
                    'slides' => $slides,
                ]);
                $cliq_ok = !empty($cres['success']);
            }

            $email_ok = false;
            $html_body = self::build_module_assigned_html_email($placeholders);
            try {
                if (email_to_user($mentor_u, $noreply_user, $subject, $body, $html_body)) {
                    $email_ok = true;
                    $results['emails_sent']++;
                }
            } catch (\Throwable $ex) {
                $results['errors'][] = 'Email error for ' . $mentor_u->email . ': ' . $ex->getMessage();
            }

            if ($cliq_ok || $email_ok) {
                $any_success = true;
                $results['recipients'][] = $mentor_u->email;
                self::log_notification(
                    $batchid,
                    $courseid,
                    'mentor',
                    $dedup_key,
                    'module_assigned',
                    $mentor_u->email,
                    'sent'
                );
            }
        }

        $results['success'] = $any_success;
        return $results;
    }

    /**
     * Trigger stage transition and module assigned alerts when a module actual start date is recorded.
     *
     * @param int $batchid
     * @param string $mod_key E.g. 'module2' or 'module_2'
     * @param array $modules Raw moduledata array
     * @param int $actualstart_ts Timestamp of actual start date
     * @return array Result of dispatch
     */
    public static function handle_module_start_transition(int $batchid, string $mod_key, array $modules, int $actualstart_ts): array {
        if ($batchid <= 0 || empty($modules)) {
            return ['success' => false, 'error' => 'Invalid parameters'];
        }

        $keys = array_keys($modules);
        $pos  = array_search($mod_key, $keys, true);

        if ($pos === false || !isset($modules[$mod_key])) {
            return ['success' => false, 'error' => 'Module key not found'];
        }

        $next_mod = $modules[$mod_key];
        $next_name = trim((string)($next_mod['name'] ?? $next_mod['courseshortname'] ?? 'Next Module'));
        $next_cid  = (int)($next_mod['moodlecourseid'] ?? 0);

        $prev_name = 'Orientation / Batch Launch';
        $prev_cid  = 0;

        if ($pos > 0) {
            $prev_key  = $keys[$pos - 1];
            $prev_mod  = $modules[$prev_key];
            $prev_name = trim((string)($prev_mod['name'] ?? $prev_mod['courseshortname'] ?? 'Previous Module'));
            $prev_cid  = (int)($prev_mod['moodlecourseid'] ?? 0);
        }

        // 1. Dispatch Template 1: Stage Transition alert (PM + SSE + Next Mentors)
        $trans_res = self::send_stage_transition_alert($batchid, $prev_name, $next_name, $actualstart_ts, $next_cid, $prev_cid, $next_mod);

        // 2. Dispatch Template 2: Module Assigned alert (Class & Lab Mentors)
        $assign_res = self::send_module_assigned_mentor_alert($batchid, $next_name, $next_mod, $actualstart_ts);

        return [
            'stage_transition' => $trans_res,
            'module_assigned'  => $assign_res,
            'success'          => ($trans_res['success'] ?? false) || ($assign_res['success'] ?? false),
        ];
    }

    /**
     * Send real-time instant confirmation alert when a batch review note is added.
     * Notifies the Program Manager.
     *
     * @param int $batchid Class section ID
     * @param int $userid
     * @param string $author
     * @param string $note_text
     * @return bool
     */
    public static function send_batch_review_note_alert(
        int $batchid,
        int $userid,
        string $author,
        string $note_text
    ): bool {
        global $DB, $CFG;

        if (!self::is_enabled()) {
            return false;
        }

        $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
        if (!$sec) {
            return false;
        }

        $batch_name = util::clean_section_name($sec->name ?: ('Batch ' . $sec->id));

        // 1. Program Manager
        $pm_user  = self::resolve_user($sec->pmmanager) ?: self::resolve_user($sec->pmmanagername);
        $pm_email = $pm_user ? $pm_user->email : '';
        $pm_name  = $pm_user ? fullname($pm_user) : ($sec->pmmanagername ?: 'Program Manager');

        // 2. SS Executive
        $sse_user  = self::resolve_user($sec->maacexecutive) ?: self::resolve_user($sec->maacexecutivename);
        $sse_email = $sse_user ? $sse_user->email : '';
        $sse_name  = $sse_user ? fullname($sse_user) : ($sec->maacexecutivename ?: 'SS Executive');

        // 3. SS Lead
        $ss_lead_info   = self::resolve_ss_leads();
        $ss_lead_emails = $ss_lead_info['emails'];
        $ss_lead_name   = !empty($ss_lead_info['names']) ? implode(', ', $ss_lead_info['names']) : 'SS Lead';

        // 4. Current Module Mentors
        $mentor_info       = self::resolve_current_module_mentors($sec);
        $cur_mentor_emails = $mentor_info['emails'];
        $cur_mentor_name   = !empty($mentor_info['names']) ? implode(', ', $mentor_info['names']) : 'Mentors';
        $cur_module_name   = $mentor_info['module_name'] ?: 'Current Module';

        // 5. Author / User who logged the note
        $by_user  = self::resolve_user($userid);
        $by_email = ($by_user && !empty($by_user->email)) ? $by_user->email : '';

        // Recipients: SS Executive, SS Lead, Current Module Mentors (+ PM & Author if available)
        $recipients = array_values(array_filter(array_unique(array_merge(
            [$sse_email],
            $ss_lead_emails,
            $cur_mentor_emails,
            [$pm_email, $by_email]
        ))));

        if (empty($recipients)) {
            return false;
        }

        $notes_url = $CFG->wwwroot . '/local/batchanalytics/batch.php?id=' . $sec->id . '&tab=notes#panel-notes';

        // Calculate next due date (15 days from now)
        $next_due_ts = time() + (15 * 86400);
        $next_due_date_str = date('d M Y', $next_due_ts);

        $preview = (mb_strlen($note_text) > 160) ? (mb_substr($note_text, 0, 157) . '...') : $note_text;

        $placeholders = [
            '{batch_name}'     => $batch_name,
            '{current_module}' => $cur_module_name,
            '{pm_name}'        => $pm_name,
            '{sse_name}'       => $sse_name,
            '{ss_lead_name}'   => $ss_lead_name,
            '{mentor_name}'    => $cur_mentor_name,
            '{author}'         => $author,
            '{review_date}'    => date('d M Y, h:i A'),
            '{note_preview}'   => $preview,
            '{next_due_date}'  => $next_due_date_str,
            '{link}'           => $notes_url,
            '{url}'            => $notes_url,
        ];

        $defs = self::get_template_definitions();
        $stg_def = $defs['batch_review']['stages']['completed'];

        $subject = self::render_template(self::get_template_subject('batch_review_completed', $stg_def['default_subject']), $placeholders);
        $body    = self::render_template(self::get_template_body('batch_review_completed', $stg_def['default_body']), $placeholders);

        $dispatch = self::send_cliq_message($recipients, $body, ['title' => $subject, 'theme' => 'green']);

        if ($dispatch['success']) {
            self::log_notification(
                $sec->id,
                0,
                'batch_review',
                'batch_review_' . $sec->id,
                'completed',
                implode(',', $recipients),
                'sent'
            );
            return true;
        }
        return false;
    }
}

