<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/util.php');
require_once(__DIR__ . '/mentor_activity_service.php');

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

        if ($url === '') {
            $url = 'https://cliq.zoho.com/api/v2/bots/batchinformer/message';
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
                        'default_subject' => '⏳ Upcoming Mentor Activity Reminder: {task_name}',
                        'default_body'    => "Hello {mentor_name},\n\nThis is a reminder that the following module mentor activity is due in 3 days:\n• Batch: {batch_name}\n• Module: {module_name}\n• Task: {task_name}\n• Due Date: {due_date}\n• Mentor: {mentor_name}\n\n🔗 View Module: {link}",
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
                        'default_subject' => '🚨 Mentor Activity Due Today: {task_name}',
                        'default_body'    => "Hello {mentor_name},\n\nThe following module mentor activity is due today:\n• Batch: {batch_name}\n• Module: {module_name}\n• Task: {task_name}\n• Due Date: {due_date} (Today)\n• Mentor: {mentor_name}\n\nPlease complete the evaluations and mark the activity complete in LMS:\n🔗 View Module: {link}",
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
                        'default_subject' => '⚠️ Overdue Warning: Mentor Activity ({task_name}) 3 Days Overdue',
                        'default_body'    => "Attention {mentor_name},\n\nThe following module mentor activity is 3 days overdue:\n• Batch: {batch_name}\n• Module: {module_name}\n• Task: {task_name}\n• Original Due Date: {due_date}\n• Overdue: 3 days\n\nPlease evaluate pending submissions and mark complete immediately.\n🔗 View Module: {link}",
                        'placeholders'    => ['{batch_name}', '{module_name}', '{task_name}', '{due_date}', '{mentor_name}', '{link}'],
                    ],
                    't_plus_5' => [
                        'key'             => 'mentor_t_plus_5',
                        'title'           => '5th Day PM Escalation',
                        'timing_badge'    => '5+ Days Overdue (T+5)',
                        'badge_color'     => '#6f42c1',
                        'recipient'       => 'Program Manager (PM) + Assigned Mentors',
                        'recipient_badge' => 'PM Escalation',
                        'card_theme'      => 'red',
                        'default_subject' => '🛑 ESCALATION: Mentor Activity {task_name} is {overdue_days} Days Overdue',
                        'default_body'    => "Attention {pm_name} (Program Manager),\n\nThe following module mentor activity has not been completed and is {overdue_days} days overdue:\n• Batch: {batch_name}\n• Module: {module_name}\n• Task: {task_name}\n• Assigned Mentor: {mentor_name}\n• Original Due Date: {due_date}\n• Delay: {overdue_days} days overdue\n\nPlease follow up with the assigned mentor.\n🔗 View Module: {link}",
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
                        'default_subject' => '✅ Mentor Activity Completed: {task_name}',
                        'default_body'    => "Hello Team,\n\nThe following module mentor activity has been successfully marked as completed:\n• Batch: {batch_name}\n• Module: {module_name}\n• Task: {task_name}\n• Completed By: {completed_by}\n• Completion Date: {completion_date}\n\n🔗 View Module: {link}",
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
                        'recipient'       => 'Student Success Executive (SSE / MAAC Executive)',
                        'recipient_badge' => 'SSE',
                        'card_theme'      => 'modern-inline',
                        'default_subject' => '⏳ Upcoming SS Activity Reminder: {activity_name}',
                        'default_body'    => "Hello {sse_name},\n\nThis is a reminder that the following batch SS activity is due in 3 days:\n• Batch: {batch_name}\n• Activity: {activity_name}\n• Due Date: {due_date}\n• Responsible: {sse_name} (SS / MAAC Executive)\n\n🔗 View Batch: {link}",
                        'placeholders'    => ['{batch_name}', '{activity_name}', '{due_date}', '{sse_name}', '{link}'],
                    ],
                    'due_today' => [
                        'key'             => 'ss_due_today',
                        'title'           => 'Due Today Alert',
                        'timing_badge'    => 'Due Today (T-0)',
                        'badge_color'     => '#fd7e14',
                        'recipient'       => 'Student Success Executive (SSE / MAAC Executive)',
                        'recipient_badge' => 'SSE',
                        'card_theme'      => 'amber',
                        'default_subject' => '🚨 SS Activity Due Today: {activity_name}',
                        'default_body'    => "Hello {sse_name},\n\nThe following batch SS activity is due today:\n• Batch: {batch_name}\n• Activity: {activity_name}\n• Due Date: {due_date} (Today)\n• Responsible: {sse_name} (SS / MAAC Executive)\n\nPlease record completion in the LMS:\n🔗 View Batch: {link}",
                        'placeholders'    => ['{batch_name}', '{activity_name}', '{due_date}', '{sse_name}', '{link}'],
                    ],
                    't_plus_3' => [
                        'key'             => 'ss_t_plus_3',
                        'title'           => '3 Days Overdue Warning',
                        'timing_badge'    => '3 Days Overdue (T+3)',
                        'badge_color'     => '#dc3545',
                        'recipient'       => 'Student Success Executive (SSE / MAAC Executive)',
                        'recipient_badge' => 'SSE',
                        'card_theme'      => 'red',
                        'default_subject' => '⚠️ Overdue Warning: SS Activity ({activity_name}) 3 Days Overdue',
                        'default_body'    => "Attention {sse_name},\n\nThe following batch SS activity is 3 days overdue:\n• Batch: {batch_name}\n• Activity: {activity_name}\n• Original Due Date: {due_date}\n• Overdue: 3 days\n\nKindly complete this deliverable immediately to avoid management escalation.\n🔗 View Batch: {link}",
                        'placeholders'    => ['{batch_name}', '{activity_name}', '{due_date}', '{sse_name}', '{link}'],
                    ],
                    't_plus_5' => [
                        'key'             => 'ss_t_plus_5',
                        'title'           => '5th Day PM Escalation',
                        'timing_badge'    => '5+ Days Overdue (T+5)',
                        'badge_color'     => '#6f42c1',
                        'recipient'       => 'Program Manager (PM) + SSE',
                        'recipient_badge' => 'PM Escalation',
                        'card_theme'      => 'red',
                        'default_subject' => '🛑 ESCALATION: Batch SS Activity {activity_name} is {overdue_days} Days Overdue',
                        'default_body'    => "Attention {pm_name} (Program Manager),\n\nThe following SS activity has not been completed and is {overdue_days} days overdue:\n• Batch: {batch_name}\n• Activity: {activity_name}\n• Assigned Executive: {sse_name}\n• Original Due Date: {due_date}\n• Delay: {overdue_days} days overdue\n\nPlease intervene and review this milestone.\n🔗 View Batch: {link}",
                        'placeholders'    => ['{batch_name}', '{activity_name}', '{due_date}', '{sse_name}', '{pm_name}', '{overdue_days}', '{link}'],
                    ],
                    'completed' => [
                        'key'             => 'ss_completed',
                        'title'           => 'Instant Completion Confirmation',
                        'timing_badge'    => 'Completed',
                        'badge_color'     => '#198754',
                        'recipient'       => 'Program Manager (PM) + SSE',
                        'recipient_badge' => 'PM + SSE',
                        'card_theme'      => 'green',
                        'default_subject' => '✅ SS Activity Completed: {activity_name}',
                        'default_body'    => "Hello Team,\n\nThe following batch SS activity has been successfully marked as completed:\n• Batch: {batch_name}\n• Activity: {activity_name}\n• Completed By: {completed_by}\n• Completion Date: {completion_date}\n\n🔗 View Batch: {link}",
                        'placeholders'    => ['{batch_name}', '{activity_name}', '{completed_by}', '{completion_date}', '{link}'],
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
     * Check if a notification has already been sent today for this batch and activity stage.
     *
     * @param int $batchid
     * @param string $act_key
     * @param string $stage
     * @param string $date_sent
     * @return bool
     */
    public static function is_already_sent(int $batchid, string $act_key, string $stage, string $date_sent): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists(self::LOG_TABLE)) {
            return false;
        }

        return $DB->record_exists(self::LOG_TABLE, [
            'batchid'      => $batchid,
            'activity_key' => $act_key,
            'stage'        => $stage,
            'date_sent'    => $date_sent,
        ]);
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

        $rec = (object)[
            'batchid'         => $batchid,
            'courseid'        => $courseid,
            'activity_type'   => $act_type,
            'activity_key'    => $act_key,
            'stage'           => $stage,
            'recipient_email' => $recipient_email,
            'timesent'        => time(),
            'date_sent'       => date('Y-m-d'),
            'status'          => $status,
        ];

        return (int)$DB->insert_record(self::LOG_TABLE, $rec);
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
            $payload['card'] = [
                'title' => $card_options['title'],
                'theme' => $card_options['theme'] ?? 'modern-inline',
            ];
        }

        // Target userids as comma-separated string (Zoho Cliq specification)
        $clean_emails = array_values(array_filter(array_unique($recipient_emails)));
        if (!empty($clean_emails)) {
            $payload['userids'] = implode(',', $clean_emails);
        } else if (!empty($cfg['channel'])) {
            $payload['channel_unique_name'] = $cfg['channel'];
        }

        if ($dry_run) {
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

        $raw_response = $curl->post($url, json_encode($payload));
        $http_code = (int)$curl->get_info()['http_code'];
        $success = ($http_code >= 200 && $http_code < 300);

        return [
            'success'   => $success,
            'http_code' => $http_code,
            'response'  => $raw_response,
        ];
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
        $sections = $DB->get_records('local_bm_classsection', null, 'id ASC');

        foreach ($sections as $sec) {
            $results['checked_sections']++;
            $batch_name = $sec->name ?: ('Batch ' . $sec->id);

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
                        '{batch_name}'    => $batch_name,
                        '{activity_name}' => $act_name,
                        '{due_date}'      => $due_date_str,
                        '{sse_name}'      => $sse_name,
                        '{pm_name}'       => $pm_name,
                        '{overdue_days}'  => abs($diff_days),
                        '{link}'          => $batch_url,
                        '{url}'           => $batch_url,
                    ];

                    $defs = self::get_template_definitions();

                    if ($diff_days === 3) {
                        // 1. Before 3 days -> SSE
                        $stage = 't_minus_3';
                        $recipient_emails = [$sse_email];
                        $card_theme = 'modern-inline';
                        $stg_def = $defs['ss']['stages']['t_minus_3'];
                        $subject = self::render_template(self::get_template_subject('ss_t_minus_3', $stg_def['default_subject']), $placeholders);
                        $body = self::render_template(self::get_template_body('ss_t_minus_3', $stg_def['default_body']), $placeholders);
                    } else if ($diff_days === 0) {
                        // 2. On Due Date -> SSE
                        $stage = 'due_today';
                        $recipient_emails = [$sse_email];
                        $card_theme = 'amber';
                        $stg_def = $defs['ss']['stages']['due_today'];
                        $subject = self::render_template(self::get_template_subject('ss_due_today', $stg_def['default_subject']), $placeholders);
                        $body = self::render_template(self::get_template_body('ss_due_today', $stg_def['default_body']), $placeholders);
                    } else if ($diff_days === -3) {
                        // 3. After 3 days overdue -> SSE
                        $stage = 't_plus_3';
                        $recipient_emails = [$sse_email];
                        $card_theme = 'red';
                        $stg_def = $defs['ss']['stages']['t_plus_3'];
                        $subject = self::render_template(self::get_template_subject('ss_t_plus_3', $stg_def['default_subject']), $placeholders);
                        $body = self::render_template(self::get_template_body('ss_t_plus_3', $stg_def['default_body']), $placeholders);
                    } else if ($diff_days <= -5) {
                        // 4. After 5th day overdue -> Program Manager Escalation
                        $stage = 't_plus_5_escalation';
                        $recipient_emails = array_filter([$pm_email, $sse_email]);
                        $card_theme = 'red';
                        $stg_def = $defs['ss']['stages']['t_plus_5'];
                        $subject = self::render_template(self::get_template_subject('ss_t_plus_5', $stg_def['default_subject']), $placeholders);
                        $body = self::render_template(self::get_template_body('ss_t_plus_5', $stg_def['default_body']), $placeholders);
                    }

                    if ($stage !== null) {
                        $results['matched']++;

                        if (self::is_already_sent($sec->id, $act_key, $stage, $date_sent)) {
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
                        $mdata = mentor_activity_service::get_course_mentor_activities($courseid, $mod_name, $mod_p_start, (int)$sec->id);
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

                            if ($diff_days === 3) {
                                // 1. Before 3 days -> Mentor
                                $stage = 't_minus_3';
                                $recipient_emails = $mentor_emails;
                                $card_theme = 'modern-inline';
                                $stg_def = $defs['mentor']['stages']['t_minus_3'];
                                $subject = self::render_template(self::get_template_subject('mentor_t_minus_3', $stg_def['default_subject']), $placeholders);
                                $body = self::render_template(self::get_template_body('mentor_t_minus_3', $stg_def['default_body']), $placeholders);
                            } else if ($diff_days === 0) {
                                // 2. On Due Date -> Mentor
                                $stage = 'due_today';
                                $recipient_emails = $mentor_emails;
                                $card_theme = 'amber';
                                $stg_def = $defs['mentor']['stages']['due_today'];
                                $subject = self::render_template(self::get_template_subject('mentor_due_today', $stg_def['default_subject']), $placeholders);
                                $body = self::render_template(self::get_template_body('mentor_due_today', $stg_def['default_body']), $placeholders);
                            } else if ($diff_days === -3) {
                                // 3. After 3 days overdue -> Mentor
                                $stage = 't_plus_3';
                                $recipient_emails = $mentor_emails;
                                $card_theme = 'red';
                                $stg_def = $defs['mentor']['stages']['t_plus_3'];
                                $subject = self::render_template(self::get_template_subject('mentor_t_plus_3', $stg_def['default_subject']), $placeholders);
                                $body = self::render_template(self::get_template_body('mentor_t_plus_3', $stg_def['default_body']), $placeholders);
                            } else if ($diff_days <= -5) {
                                // 4. After 5th day overdue -> Program Manager Escalation
                                $stage = 't_plus_5_escalation';
                                $recipient_emails = array_filter(array_merge([$pm_email], $mentor_emails));
                                $card_theme = 'red';
                                $stg_def = $defs['mentor']['stages']['t_plus_5'];
                                $subject = self::render_template(self::get_template_subject('mentor_t_plus_5', $stg_def['default_subject']), $placeholders);
                                $body = self::render_template(self::get_template_body('mentor_t_plus_5', $stg_def['default_body']), $placeholders);
                            }

                            if ($stage !== null) {
                                $results['matched']++;

                                $act_unique_key = 'mentor_' . $courseid . '_' . $act_key;

                                if (self::is_already_sent($sec->id, $act_unique_key, $stage, $date_sent)) {
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
                }
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
        $batch_name = $sec ? ($sec->name ?: ('Batch ' . $sec->id)) : ('Batch ' . $batchid);

        $pm_user  = $sec ? (self::resolve_user($sec->pmmanager) ?: self::resolve_user($sec->pmmanagername)) : null;
        $sse_user = $sec ? (self::resolve_user($sec->maacexecutive) ?: self::resolve_user($sec->maacexecutivename)) : null;
        $by_user  = self::resolve_user($completed_by_userid);

        $pm_email  = $pm_user ? $pm_user->email : '';
        $sse_email = $sse_user ? $sse_user->email : '';
        $by_name   = $by_user ? fullname($by_user) : 'User';
        $comp_date = date('d M Y');

        $defs = self::get_template_definitions();

        if ($action_type === 'ss') {
            $act_name = ucwords(str_replace(['_', '-'], ' ', $act_name_or_key));
            $batch_url = $CFG->wwwroot . '/local/batchanalytics/batch.php?id=' . $batchid;
            $recipients = array_filter(array_unique([$pm_email, $sse_email]));

            $stg_def = $defs['ss']['stages']['completed'];
            $placeholders = [
                '{batch_name}'      => $batch_name,
                '{activity_name}'   => $act_name,
                '{completed_by}'    => $by_name,
                '{completion_date}' => $comp_date,
                '{link}'            => $batch_url,
                '{url}'             => $batch_url,
            ];

            $subject = self::render_template(self::get_template_subject('ss_completed', $stg_def['default_subject']), $placeholders);
            $body = self::render_template(self::get_template_body('ss_completed', $stg_def['default_body']), $placeholders);

            $dispatch = self::send_cliq_message($recipients, $body, ['title' => $subject, 'theme' => 'green']);
            self::log_notification($batchid, 0, 'ss', $act_name_or_key, 'completed', implode(',', $recipients));
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
                        $cands = [$sm['primarymentor'] ?? '', $sm['secondarymentor'] ?? '', $sm['labmentor1'] ?? ''];
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
            $recipients = array_filter(array_unique(array_merge([$pm_email], $mentor_emails)));

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
            self::log_notification($batchid, $courseid, 'mentor', $act_name_or_key, 'completed', implode(',', $recipients));
            return $dispatch['success'];
        }

        return false;
    }
}
