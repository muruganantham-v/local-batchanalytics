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
 * Zoho Cliq Notification Service for Batch Analytics.
 * Handles dynamic notification templates, rendering, and API dispatches to Zoho Cliq.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cliq_notification_service {

    const CONFIG_KEY = 'cliq_notification_templates';

    /**
     * Default message templates list is empty.
     * All templates, workflows, and escalation metrics are created dynamically by administrators and managers.
     */
    const DEFAULT_TEMPLATES = [];

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
        '{pending_activities}'  => ['desc' => 'Pending activities names', 'sample' => 'Lab Report 1, Quiz 2'],
        '{pending_count}'       => ['desc' => 'Count of pending activities', 'sample' => '2'],
        '{owner}'               => ['desc' => 'Person responsible', 'sample' => 'Ravi Kumar'],
        '{owner_role}'          => ['desc' => 'Role of the owner', 'sample' => 'Class Mentor'],
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
     * Get all active user-configured templates.
     *
     * @return array
     */
    public static function get_templates(): array {
        $raw = get_config('local_batchanalytics', self::CONFIG_KEY);
        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $templates = [];
        foreach ($decoded as $item) {
            if (!empty($item['id'])) {
                $templates[] = $item;
            }
        }

        return $templates;
    }

    /**
     * Get a single template by ID.
     *
     * @param string $id
     * @return array|null
     */
    public static function get_template(string $id): ?array {
        $templates = self::get_templates();
        foreach ($templates as $t) {
            if (($t['id'] ?? '') === $id) {
                return $t;
            }
        }
        return null;
    }

    /**
     * Save dynamic templates to Moodle config.
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
                'id'              => clean_param($t['id'], PARAM_ALPHANUMEXT),
                'title'           => clean_param($t['title'] ?? '', PARAM_TEXT),
                'recipient'       => clean_param($t['recipient'] ?? 'CM', PARAM_ALPHANUMEXT),
                'recipient_title' => clean_param($t['recipient_title'] ?? 'Class Mentor', PARAM_TEXT),
                'trigger'         => clean_param($t['trigger'] ?? 'Activity due', PARAM_TEXT),
                'severity'        => clean_param($t['severity'] ?? 'info', PARAM_ALPHANUMEXT),
                'template'        => clean_param($t['template'] ?? ($t['body'] ?? ''), PARAM_RAW_TRIMMED),
                'threshold_days'  => isset($t['threshold_days']) ? (int)$t['threshold_days'] : 0,
                'escalation'      => clean_param($t['escalation'] ?? '—', PARAM_TEXT),
                'enabled'         => !empty($t['enabled']),
            ];
        }

        return set_config(self::CONFIG_KEY, json_encode($clean, JSON_UNESCAPED_UNICODE), 'local_batchanalytics');
    }

    /**
     * Add or update a single template dynamically.
     *
     * @param array $data
     * @return bool
     */
    public static function add_or_update_template(array $data): bool {
        $id = clean_param($data['id'] ?? '', PARAM_ALPHANUMEXT);
        if (empty($id)) {
            return false;
        }

        $templates = self::get_templates();
        $found = false;
        foreach ($templates as &$t) {
            if (($t['id'] ?? '') === $id) {
                $t = array_merge($t, $data);
                $found = true;
                break;
            }
        }
        unset($t);

        if (!$found) {
            $templates[] = $data;
        }

        return self::save_templates($templates);
    }

    /**
     * Delete a single template by ID.
     *
     * @param string $id
     * @return bool
     */
    public static function delete_template(string $id): bool {
        $templates = self::get_templates();
        $filtered = array_values(array_filter($templates, function($t) use ($id) {
            return ($t['id'] ?? '') !== $id;
        }));
        return self::save_templates($filtered);
    }

    /**
     * Clear all dynamic templates from Moodle config.
     *
     * @return bool
     */
    public static function clear_all_templates(): bool {
        return unset_config(self::CONFIG_KEY, 'local_batchanalytics');
    }

    /**
     * Reset templates back to empty specification.
     *
     * @return bool
     */
    public static function reset_templates(): bool {
        return self::clear_all_templates();
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
     * { "text": messageText, "userids": "email1,email2" }
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

        // Construct Zoho Cliq Bot Payload: { "text": messageText }
        $payload_data = [
            'text' => $message,
        ];

        // Format userids as string (Zoho Cliq bot endpoint expects string, NOT a JSON array)
        if (!empty($userids)) {
            if (is_string($userids)) {
                $parts = preg_split('/[\s,]+/', trim($userids), -1, PREG_SPLIT_NO_EMPTY);
            } else if (is_array($userids)) {
                $parts = array_values(array_filter(array_map('trim', $userids)));
            } else {
                $parts = [];
            }

            if (!empty($parts)) {
                $payload_data['userids'] = implode(',', $parts);
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
