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

require_once(__DIR__ . '/cliq_notification_service.php');
require_once(__DIR__ . '/cliq_recipient_resolver.php');
require_once(__DIR__ . '/mentor_activity_service.php');
require_once(__DIR__ . '/task_service.php');

/**
 * Dynamic Workflow and Condition Trigger Engine for Zoho Cliq Notifications.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cliq_workflow_engine {

    /** @var string Table for workflow rules */
    const RULES_TABLE = 'local_batchanalytics_cliq_rules';

    /** @var string Table for sent notification log & deduplication */
    const LOG_TABLE = 'local_batchanalytics_cliq_log';

    /**
     * Default rule definitions for all 42 templates.
     * @var array<string, array>
     */
    const DEFAULT_WORKFLOW_RULES = [
        // Project Manager (PM)
        'PM-01' => ['trigger_type' => 'event',    'condition_metric' => 'batch_created',       'condition_value' => 0, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],
        'PM-02' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_overdue',        'condition_value' => 2, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],
        'PM-03' => ['trigger_type' => 'schedule', 'condition_metric' => 'attendance_missing',  'condition_value' => 1, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],
        'PM-04' => ['trigger_type' => 'schedule', 'condition_metric' => 'session_unmarked',    'condition_value' => 0, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],
        'PM-05' => ['trigger_type' => 'schedule', 'condition_metric' => 'assessment_overdue',  'condition_value' => 2, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],
        'PM-06' => ['trigger_type' => 'event',    'condition_metric' => 'nomination_raised',   'condition_value' => 0, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],
        'PM-07' => ['trigger_type' => 'schedule', 'condition_metric' => 'nomination_pending',  'condition_value' => 2, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],
        'PM-08' => ['trigger_type' => 'schedule', 'condition_metric' => 'batch_closure',       'condition_value' => 0, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],
        'PM-09' => ['trigger_type' => 'event',    'condition_metric' => 'module_completed',    'condition_value' => 0, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],
        'PM-10' => ['trigger_type' => 'schedule', 'condition_metric' => 'midpoint_due',        'condition_value' => 0, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],
        'PM-11' => ['trigger_type' => 'schedule', 'condition_metric' => 'checkpoint_due',      'condition_value' => 1, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],
        'PM-12' => ['trigger_type' => 'schedule', 'condition_metric' => 'closure_meeting_due', 'condition_value' => 1, 'recipient_type' => 'PM',  'escalate_to' => null, 'escalate_days' => 0],

        // Senior Support Executive (SSE)
        'SSE-01' => ['trigger_type' => 'event',    'condition_metric' => 'batch_created',      'condition_value' => 0, 'recipient_type' => 'SSE', 'escalate_to' => null, 'escalate_days' => 0],
        'SSE-02' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_before_due',   'condition_value' => 3, 'recipient_type' => 'SSE', 'escalate_to' => null, 'escalate_days' => 0],
        'SSE-03' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_before_due',   'condition_value' => 1, 'recipient_type' => 'SSE', 'escalate_to' => null, 'escalate_days' => 0],
        'SSE-04' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_overdue',      'condition_value' => 1, 'recipient_type' => 'SSE', 'escalate_to' => 'PM', 'escalate_days' => 2],
        'SSE-05' => ['trigger_type' => 'schedule', 'condition_metric' => 'milestone_alert',   'condition_value' => 0, 'recipient_type' => 'SSE', 'escalate_to' => null, 'escalate_days' => 0],
        'SSE-06' => ['trigger_type' => 'schedule', 'condition_metric' => 'attendance_low',    'condition_value' => 0, 'recipient_type' => 'SSE', 'escalate_to' => 'PM', 'escalate_days' => 2],
        'SSE-07' => ['trigger_type' => 'schedule', 'condition_metric' => 'batch_closure',      'condition_value' => 0, 'recipient_type' => 'SSE', 'escalate_to' => null, 'escalate_days' => 0],

        // Class Mentor (CM)
        'CM-01' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_before_due',   'condition_value' => 1, 'recipient_type' => 'CM',  'escalate_to' => null, 'escalate_days' => 0],
        'CM-02' => ['trigger_type' => 'schedule', 'condition_metric' => 'on_due_date',       'condition_value' => 0, 'recipient_type' => 'CM',  'escalate_to' => null, 'escalate_days' => 0],
        'CM-03' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_overdue',      'condition_value' => 1, 'recipient_type' => 'CM',  'escalate_to' => 'PM', 'escalate_days' => 2],
        'CM-04' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_before_due',   'condition_value' => 2, 'recipient_type' => 'CM',  'escalate_to' => null, 'escalate_days' => 0],
        'CM-05' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_before_due',   'condition_value' => 1, 'recipient_type' => 'CM',  'escalate_to' => null, 'escalate_days' => 0],
        'CM-06' => ['trigger_type' => 'schedule', 'condition_metric' => 'session_starting',  'condition_value' => 0, 'recipient_type' => 'CM',  'escalate_to' => null, 'escalate_days' => 0],
        'CM-07' => ['trigger_type' => 'schedule', 'condition_metric' => 'attendance_missing', 'condition_value' => 0, 'recipient_type' => 'CM',  'escalate_to' => null, 'escalate_days' => 0],
        'CM-08' => ['trigger_type' => 'schedule', 'condition_metric' => 'attendance_missing', 'condition_value' => 1, 'recipient_type' => 'CM',  'escalate_to' => 'PM', 'escalate_days' => 1],
        'CM-09' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_overdue',      'condition_value' => 2, 'recipient_type' => 'CM',  'escalate_to' => 'PM', 'escalate_days' => 3],
        'CM-10' => ['trigger_type' => 'event',    'condition_metric' => 'nomination_decision','condition_value' => 0, 'recipient_type' => 'CM',  'escalate_to' => null, 'escalate_days' => 0],
        'CM-11' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_before_due',   'condition_value' => 2, 'recipient_type' => 'CM',  'escalate_to' => null, 'escalate_days' => 0],
        'CM-12' => ['trigger_type' => 'schedule', 'condition_metric' => 'on_due_date',       'condition_value' => 0, 'recipient_type' => 'CM',  'escalate_to' => null, 'escalate_days' => 0],
        'CM-13' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_overdue',      'condition_value' => 1, 'recipient_type' => 'CM',  'escalate_to' => 'PM', 'escalate_days' => 2],

        // Lab Mentor (LM)
        'LM-01' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_before_due',   'condition_value' => 1, 'recipient_type' => 'LM',  'escalate_to' => null, 'escalate_days' => 0],
        'LM-02' => ['trigger_type' => 'schedule', 'condition_metric' => 'on_due_date',       'condition_value' => 0, 'recipient_type' => 'LM',  'escalate_to' => null, 'escalate_days' => 0],
        'LM-03' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_overdue',      'condition_value' => 1, 'recipient_type' => 'LM',  'escalate_to' => 'PM', 'escalate_days' => 2],
        'LM-04' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_before_due',   'condition_value' => 2, 'recipient_type' => 'LM',  'escalate_to' => null, 'escalate_days' => 0],
        'LM-05' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_before_due',   'condition_value' => 1, 'recipient_type' => 'LM',  'escalate_to' => null, 'escalate_days' => 0],
        'LM-06' => ['trigger_type' => 'schedule', 'condition_metric' => 'session_starting',  'condition_value' => 0, 'recipient_type' => 'LM',  'escalate_to' => null, 'escalate_days' => 0],
        'LM-07' => ['trigger_type' => 'schedule', 'condition_metric' => 'attendance_missing', 'condition_value' => 0, 'recipient_type' => 'LM',  'escalate_to' => null, 'escalate_days' => 0],
        'LM-08' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_overdue',      'condition_value' => 2, 'recipient_type' => 'LM',  'escalate_to' => 'PM', 'escalate_days' => 3],
        'LM-09' => ['trigger_type' => 'event',    'condition_metric' => 'nomination_decision','condition_value' => 0, 'recipient_type' => 'LM',  'escalate_to' => null, 'escalate_days' => 0],
        'LM-10' => ['trigger_type' => 'schedule', 'condition_metric' => 'days_overdue',      'condition_value' => 1, 'recipient_type' => 'LM',  'escalate_to' => 'PM', 'escalate_days' => 2],
    ];

    /**
     * Check if currently within quiet hours (default: outside 09:00 AM - 07:00 PM).
     *
     * @param int $start_hour
     * @param int $end_hour
     * @return bool True if currently quiet hours (do NOT send messages)
     */
    public static function is_quiet_hours(int $start_hour = 9, int $end_hour = 19): bool {
        $curr_hour = (int)date('G');
        return ($curr_hour < $start_hour || $curr_hour >= $end_hour);
    }

    /**
     * Fetch all workflow rules (merged with defaults).
     *
     * @return array<string, array> Keyed by template_id
     */
    public static function get_rules(): array {
        global $DB;

        $rules = self::DEFAULT_WORKFLOW_RULES;

        // Ensure each rule has enabled flag
        foreach ($rules as $tid => &$r) {
            $r['template_id'] = $tid;
            $r['enabled'] = 1;
            $r['quiet_hours_enabled'] = 1;
        }
        unset($r);

        $dbman = $DB->get_manager();
        if ($dbman->table_exists(self::RULES_TABLE)) {
            $records = $DB->get_records(self::RULES_TABLE);
            foreach ($records as $rec) {
                $tid = $rec->template_id;
                $rules[$tid] = [
                    'template_id'         => $tid,
                    'enabled'             => (int)$rec->enabled,
                    'trigger_type'        => $rec->trigger_type,
                    'condition_metric'    => $rec->condition_metric,
                    'condition_value'     => (int)$rec->condition_value,
                    'recipient_type'      => $rec->recipient_type,
                    'escalate_to'         => $rec->escalate_to ?: null,
                    'escalate_days'       => (int)$rec->escalate_days,
                    'quiet_hours_enabled' => (int)$rec->quiet_hours_enabled,
                ];
            }
        }

        return $rules;
    }

    /**
     * Save/update a single workflow rule.
     *
     * @param array $rule
     * @return bool
     */
    public static function save_rule(array $rule): bool {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(self::RULES_TABLE)) {
            return false;
        }

        $tid = trim($rule['template_id'] ?? '');
        if (empty($tid)) {
            return false;
        }

        $existing = $DB->get_record(self::RULES_TABLE, ['template_id' => $tid]);
        $record = (object)[
            'template_id'         => $tid,
            'enabled'             => isset($rule['enabled']) ? (int)$rule['enabled'] : 1,
            'trigger_type'        => $rule['trigger_type'] ?? 'schedule',
            'condition_metric'    => $rule['condition_metric'] ?? 'days_before_due',
            'condition_value'     => isset($rule['condition_value']) ? (int)$rule['condition_value'] : 0,
            'recipient_type'      => $rule['recipient_type'] ?? 'CM',
            'escalate_to'         => !empty($rule['escalate_to']) ? trim($rule['escalate_to']) : null,
            'escalate_days'       => isset($rule['escalate_days']) ? (int)$rule['escalate_days'] : 2,
            'quiet_hours_enabled' => isset($rule['quiet_hours_enabled']) ? (int)$rule['quiet_hours_enabled'] : 1,
            'timemodified'        => time(),
        ];

        if ($existing) {
            $record->id = $existing->id;
            return $DB->update_record(self::RULES_TABLE, $record);
        } else {
            return (bool)$DB->insert_record(self::RULES_TABLE, $record);
        }
    }

    /**
     * Save multiple workflow rules.
     *
     * @param array $rules
     * @return bool
     */
    public static function save_all_rules(array $rules): bool {
        $success = true;
        foreach ($rules as $r) {
            if (is_array($r) && !empty($r['template_id'])) {
                if (!self::save_rule($r)) {
                    $success = false;
                }
            }
        }
        return $success;
    }

    /**
     * Check if a notification for this template, batch, and activity was already sent today.
     *
     * @param string $template_id
     * @param string $batchid
     * @param string $activity_key
     * @return bool
     */
    public static function has_already_sent_today(string $template_id, string $batchid, string $activity_key): bool {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(self::LOG_TABLE)) {
            return false;
        }

        $today = date('Y-m-d');
        return $DB->record_exists(self::LOG_TABLE, [
            'template_id'  => $template_id,
            'batchid'      => (string)$batchid,
            'activity_key' => (string)$activity_key,
            'date_sent'    => $today,
        ]);
    }

    /**
     * Log a dispatched notification to the audit log table.
     *
     * @param string $template_id
     * @param int $courseid
     * @param string $batchid
     * @param string $activity_key
     * @param string $recipient_email
     * @param int $http_code
     * @param string $response_payload
     * @return int Inserted ID
     */
    public static function log_notification(
        string $template_id,
        int $courseid,
        string $batchid,
        string $activity_key,
        string $recipient_email,
        int $http_code,
        string $response_payload
    ): int {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(self::LOG_TABLE)) {
            return 0;
        }

        $rec = (object)[
            'template_id'      => $template_id,
            'courseid'         => $courseid,
            'batchid'          => $batchid,
            'activity_key'     => $activity_key,
            'recipient_email'  => $recipient_email,
            'timesent'         => time(),
            'http_code'        => $http_code,
            'response_payload' => substr($response_payload, 0, 1000),
            'date_sent'        => date('Y-m-d'),
        ];

        return (int)$DB->insert_record(self::LOG_TABLE, $rec);
    }

    /**
     * Evaluate active workflow rules against all current batches and mentor activities.
     * Can run in real execution mode (scheduled task) or dry-run simulation mode (admin preview).
     *
     * @param bool $dry_run If true, does not send HTTP requests and does not write log
     * @param bool $ignore_quiet_hours If true, evaluates regardless of time (useful for manual dry-run)
     * @return array Summary of processed, matched, sent, and skipped notifications
     */
    public static function evaluate_scheduled_workflows(bool $dry_run = false, bool $ignore_quiet_hours = false): array {
        global $DB;

        $results = [
            'total_batches_checked' => 0,
            'matches_found'         => 0,
            'sent_count'            => 0,
            'skipped_count'         => 0,
            'notifications'         => [],
            'in_quiet_hours'        => false,
        ];

        // 1. Check if Cliq Notifications are globally enabled
        if (!get_config('local_batchanalytics', 'zoho_cliq_enabled') && !$dry_run) {
            $results['message'] = 'Zoho Cliq notifications are globally disabled in plugin settings.';
            return $results;
        }

        // 2. Check Quiet Hours
        if (!$ignore_quiet_hours && self::is_quiet_hours()) {
            $results['in_quiet_hours'] = true;
            if (!$dry_run) {
                $results['message'] = 'Currently within quiet hours (09:00 PM - 09:00 AM). Notifications paused until morning.';
                return $results;
            }
        }

        // 3. Load active rules and templates
        $rules = self::get_rules();
        $templates_map = [];
        foreach (cliq_notification_service::get_templates() as $t) {
            $templates_map[$t['id']] = $t;
        }

        // 4. Fetch batches and sections
        $dbman = $DB->get_manager();
        $sections = [];
        if ($dbman->table_exists('local_bm_classsection')) {
            $sections = $DB->get_records('local_bm_classsection', null, 'id ASC');
        }

        $results['total_batches_checked'] = count($sections);
        $today_midnight = strtotime('today midnight');

        foreach ($sections as $sec) {
            $batch_code = $sec->batchname ?? ($sec->name ?? ($sec->code ?? ('Batch-' . $sec->id)));
            $modules = util::decode_module_data($sec->moduledata ?? '', true);

            foreach ($modules as $mod) {
                $courseid = (int)($mod['moodlecourseid'] ?? 0);
                if ($courseid <= 0) {
                    continue;
                }

                // Fetch mentor activities for this course
                $activities_payload = mentor_activity_service::get_mentor_activities($courseid);
                $groups = $activities_payload['groups'] ?? [];

                foreach ($groups as $grp) {
                    $acts = $grp['activities'] ?? [];
                    foreach ($acts as $act) {
                        $is_completed = !empty($act['completed']);
                        $due_ts = (int)($act['due_date'] ?? 0);
                        if ($due_ts <= 0) {
                            continue;
                        }

                        $due_midnight = strtotime('today midnight', $due_ts);
                        $days_diff = (int)round(($due_midnight - $today_midnight) / 86400);
                        // $days_diff > 0 => due in future (e.g. 1 = tomorrow)
                        // $days_diff == 0 => due today
                        // $days_diff < 0 => overdue (e.g. -1 = 1 day overdue)

                        $overdue_days = ($days_diff < 0) ? abs($days_diff) : 0;

                        // Check each active scheduled rule against this activity
                        foreach ($rules as $tid => $rule) {
                            if (empty($rule['enabled']) || ($rule['trigger_type'] ?? '') !== 'schedule') {
                                continue;
                            }

                            $matched = false;
                            $metric = $rule['condition_metric'];
                            $cval = (int)$rule['condition_value'];

                            // Evaluate metric conditions:
                            // A. Pre-due reminder (e.g. 1 day before due)
                            if ($metric === 'days_before_due') {
                                if (!$is_completed && $days_diff === $cval) {
                                    $matched = true;
                                }
                            }
                            // B. On due date (due today)
                            else if ($metric === 'on_due_date') {
                                if (!$is_completed && $days_diff === 0) {
                                    $matched = true;
                                }
                            }
                            // C. Overdue reminder (overdue by N days)
                            else if ($metric === 'days_overdue') {
                                if (!$is_completed && $overdue_days >= $cval && $overdue_days > 0) {
                                    $matched = true;
                                }
                            }

                            if (!$matched) {
                                continue;
                            }

                            $results['matches_found']++;

                            $activity_key = $act['key'] ?? mentor_activity_service::slugify_key($act['name'] ?? 'act');

                            // Deduplication check: Did we send this notification today?
                            $already_sent = self::has_already_sent_today($tid, $batch_code, $activity_key);
                            if ($already_sent && !$dry_run) {
                                $results['skipped_count']++;
                                continue;
                            }

                            // Resolve recipient email(s)
                            $escalate_to = ($overdue_days >= (int)$rule['escalate_days'] && !empty($rule['escalate_to'])) ? $rule['escalate_to'] : null;
                            $recipients = cliq_recipient_resolver::resolve_recipient_emails(
                                $rule['recipient_type'],
                                $sec,
                                $courseid,
                                $mod,
                                $escalate_to
                            );

                            if (empty($recipients)) {
                                $results['skipped_count']++;
                                continue;
                            }

                            // Render message template
                            $template_text = $templates_map[$tid]['template'] ?? '';
                            $placeholders = cliq_recipient_resolver::build_placeholder_data($sec, $courseid, $mod, $act);
                            $rendered_msg = cliq_notification_service::render_template($template_text, $placeholders);

                            $item = [
                                'template_id'    => $tid,
                                'trigger'        => $templates_map[$tid]['trigger'] ?? $metric,
                                'batch_code'     => $batch_code,
                                'module'         => $mod['modulename'] ?? '',
                                'activity'       => $act['name'] ?? '',
                                'due_date'       => date('d M Y', $due_ts),
                                'days_diff'      => $days_diff,
                                'overdue_days'   => $overdue_days,
                                'recipients'     => $recipients,
                                'escalated'      => !empty($escalate_to),
                                'rendered_text'  => $rendered_msg,
                                'status'         => $dry_run ? 'simulated' : 'pending',
                            ];

                            // If not dry-run, actually fire to Zoho Cliq Bot API!
                            if (!$dry_run) {
                                $send_res = cliq_notification_service::send_cliq_message($rendered_msg, $recipients);
                                $item['http_code'] = $send_res['http_code'];
                                $item['api_success'] = $send_res['success'];
                                $item['status'] = $send_res['success'] ? 'sent' : 'failed';

                                foreach ($recipients as $email) {
                                    self::log_notification(
                                        $tid,
                                        $courseid,
                                        $batch_code,
                                        $activity_key,
                                        $email,
                                        $send_res['http_code'],
                                        $send_res['response']
                                    );
                                }
                                $results['sent_count']++;
                            }

                            $results['notifications'][] = $item;
                        }
                    }
                }
            }
        }

        return $results;
    }
}
