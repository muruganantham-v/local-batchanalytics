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
 * Service to dynamically resolve recipient email addresses and metadata for Zoho Cliq notifications.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cliq_recipient_resolver {

    /**
     * Cache for resolved user emails by identifier.
     * @var array<string, string>
     */
    private static $user_email_cache = [];

    /**
     * Resolve email address from user identifier (ID, username, email, or fullname).
     *
     * @param mixed $identifier
     * @return string|null
     */
    public static function lookup_user_email($identifier): ?string {
        global $DB;

        if ($identifier === null || $identifier === false || trim((string)$identifier) === '') {
            return null;
        }

        $key = trim((string)$identifier);
        if (isset(self::$user_email_cache[$key])) {
            return self::$user_email_cache[$key];
        }

        // Direct email check
        if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
            self::$user_email_cache[$key] = $key;
            return $key;
        }

        // Numeric ID check
        if (is_numeric($key)) {
            $user = $DB->get_record('user', ['id' => (int)$key, 'deleted' => 0], 'id, email');
            if ($user && !empty($user->email)) {
                self::$user_email_cache[$key] = trim($user->email);
                return trim($user->email);
            }
        }

        // Username check
        $user = $DB->get_record('user', ['username' => $key, 'deleted' => 0], 'id, email');
        if ($user && !empty($user->email)) {
            self::$user_email_cache[$key] = trim($user->email);
            return trim($user->email);
        }

        // Fullname search (firstname + lastname)
        $sql = "SELECT id, email, " . $DB->sql_fullname() . " AS fname 
                FROM {user} 
                WHERE deleted = 0 AND " . $DB->sql_like($DB->sql_fullname(), ':name', false, false);
        $matches = $DB->get_records_sql($sql, ['name' => '%' . $key . '%'], 0, 1);
        if (!empty($matches)) {
            $matched = reset($matches);
            if (!empty($matched->email)) {
                self::$user_email_cache[$key] = trim($matched->email);
                return trim($matched->email);
            }
        }

        return null;
    }

    /**
     * Get email addresses of users assigned to a configured role.
     *
     * @param string $config_name e.g. 'mentor_roles', 'ssexecutive_roles', 'program_manager_roles'
     * @param int|null $courseid Course context or null for system
     * @return array<string>
     */
    public static function get_emails_by_configured_role(string $config_name, ?int $courseid = null): array {
        global $DB;

        $roleid = (int)get_config('local_batchanalytics', $config_name);
        if ($roleid <= 0) {
            return [];
        }

        $contextids = [];
        if ($courseid && $courseid > 0) {
            $course_context = \context_course::instance($courseid, IGNORE_MISSING);
            if ($course_context) {
                $contextids[] = $course_context->id;
            }
        }
        $sys_context = \context_system::instance();
        $contextids[] = $sys_context->id;

        [$ctx_sql, $ctx_params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        $params = array_merge($ctx_params, ['roleid' => $roleid]);

        $sql = "SELECT DISTINCT u.id, u.email
                FROM {role_assignments} ra
                JOIN {user} u ON u.id = ra.userid
                WHERE ra.roleid = :roleid AND ra.contextid $ctx_sql AND u.deleted = 0 AND u.suspended = 0";

        $records = $DB->get_records_sql($sql, $params);
        $emails = [];
        foreach ($records as $r) {
            if (!empty($r->email) && filter_var($r->email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = strtolower(trim($r->email));
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Resolve recipient emails dynamically based on role type and batch/section context.
     *
     * @param string $recipient_type 'PM', 'SSE', 'CM', 'LM', 'AM', 'escalation'
     * @param object|array|null $section_or_batch
     * @param int|null $courseid
     * @param array|null $module
     * @param string|null $escalate_to 'PM', 'AM' if escalation condition is met
     * @return array<string>
     */
    public static function resolve_recipient_emails(
        string $recipient_type,
        $section_or_batch = null,
        ?int $courseid = null,
        ?array $module = null,
        ?string $escalate_to = null
    ): array {
        $emails = [];
        $sec = is_array($section_or_batch) ? (object)$section_or_batch : $section_or_batch;

        $type = strtoupper(trim($recipient_type));

        // 1. Program Manager (PM)
        if ($type === 'PM') {
            if ($sec && (!empty($sec->pmmanager) || !empty($sec->pmmanagername))) {
                $em = self::lookup_user_email($sec->pmmanager ?: $sec->pmmanagername);
                if ($em) $emails[] = $em;
            }
            if (empty($emails)) {
                $emails = self::get_emails_by_configured_role('program_manager_roles', $courseid);
            }
        }

        // 2. Senior Support Executive (SSE)
        else if ($type === 'SSE') {
            if ($sec && (!empty($sec->maacexecutive) || !empty($sec->maacexecutivename))) {
                $em = self::lookup_user_email($sec->maacexecutive ?: $sec->maacexecutivename);
                if ($em) $emails[] = $em;
            }
            if (empty($emails)) {
                $emails = self::get_emails_by_configured_role('ssexecutive_roles', $courseid);
            }
        }

        // 3. Class Mentor (CM)
        else if ($type === 'CM') {
            if (!empty($module)) {
                $candidates = [$module['primarymentor'] ?? '', $module['secondarymentor'] ?? ''];
                foreach ($candidates as $cand) {
                    $em = self::lookup_user_email($cand);
                    if ($em) $emails[] = $em;
                }
            }
            if (empty($emails) && $courseid) {
                $emails = self::get_emails_by_configured_role('mentor_roles', $courseid);
            }
        }

        // 4. Lab Mentor (LM)
        else if ($type === 'LM') {
            if (!empty($module)) {
                $candidates = [
                    $module['labmentor1'] ?? '',
                    $module['labmentor2'] ?? '',
                    $module['labmentor3'] ?? ''
                ];
                foreach ($candidates as $cand) {
                    $em = self::lookup_user_email($cand);
                    if ($em) $emails[] = $em;
                }
            }
            if (empty($emails) && $courseid) {
                $emails = self::get_emails_by_configured_role('mentor_roles', $courseid);
            }
        }

        // 5. Assistant Manager (AM)
        else if ($type === 'AM') {
            $emails = self::get_emails_by_configured_role('assistant_manager_roles', $courseid);
        }

        // Handle Escalation addition
        if (!empty($escalate_to)) {
            $esc_type = strtoupper(trim($escalate_to));
            if ($esc_type !== $type) {
                $esc_emails = self::resolve_recipient_emails($esc_type, $section_or_batch, $courseid, $module);
                $emails = array_merge($emails, $esc_emails);
            }
        }

        // Fallback: If no email found, get site admin email so alerts are never silently dropped
        if (empty($emails)) {
            $admin = get_admin();
            if ($admin && !empty($admin->email)) {
                $emails[] = trim($admin->email);
            }
        }

        return array_values(array_unique(array_filter($emails)));
    }

    /**
     * Build realistic placeholder dictionary data for a specific batch and activity.
     *
     * @param object|array|null $section_or_batch
     * @param int|null $courseid
     * @param array|null $module
     * @param array|null $activity
     * @return array<string, string>
     */
    public static function build_placeholder_data(
        $section_or_batch = null,
        ?int $courseid = null,
        ?array $module = null,
        ?array $activity = null
    ): array {
        global $CFG;

        $sec = is_array($section_or_batch) ? (object)$section_or_batch : $section_or_batch;

        $batch_code = $sec->batchname ?? ($sec->name ?? ($sec->code ?? 'Current-Batch'));
        $module_name = $module['modulename'] ?? ($module['name'] ?? 'Core Module');
        $activity_name = $activity['name'] ?? ($activity['title'] ?? 'Module Delivery');

        $due_ts = (int)($activity['due_date'] ?? ($activity['planned_end'] ?? time()));
        $due_date_str = date('d M Y', $due_ts ?: time());

        $cm_name = $module['primarymentor'] ?? 'Class Mentor';
        $lm_name = $module['labmentor1'] ?? 'Lab Mentor';
        $sse_name = $sec->maacexecutivename ?? ($sec->maacexecutive ?? 'Senior Support Executive');
        $pm_name = $sec->pmmanagername ?? ($sec->pmmanager ?? 'Project Manager');

        $lms_url = $CFG->wwwroot . '/local/batchanalytics/index.php';
        if ($courseid && $courseid > 0) {
            $lms_url .= '?courseid=' . (int)$courseid;
        }

        $now = time();
        $delay_days = ($due_ts > 0 && $now > $due_ts) ? (int)floor(($now - $due_ts) / 86400) : 0;

        return [
            '{batch_id}'        => (string)$batch_code,
            '{batch_name}'      => (string)$batch_code,
            '{module}'          => (string)$module_name,
            '{activity}'        => (string)$activity_name,
            '{due_date}'        => (string)$due_date_str,
            '{owner}'           => (string)$cm_name,
            '{class_mentor}'    => (string)$cm_name,
            '{lab_mentor}'      => (string)$lm_name,
            '{sse}'             => (string)$sse_name,
            '{pm}'              => (string)$pm_name,
            '{delay_days}'      => (string)max(1, $delay_days),
            '{pending_days}'    => (string)max(1, $delay_days),
            '{lms_link}'        => (string)$lms_url,
            '{today_date}'      => date('d M Y'),
            '{student_count}'   => '40',
            '{attendance_pct}'  => '88%',
        ];
    }
}
