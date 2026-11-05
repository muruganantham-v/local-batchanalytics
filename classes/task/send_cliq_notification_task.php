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

namespace local_batchanalytics\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Ad-hoc task to dispatch deferred Zoho Cliq notifications asynchronously.
 *
 * Prevents blocking user web requests (e.g. review notes, module start, activity completions)
 * by offloading external HTTP cURL calls to Moodle background ad-hoc task runner.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_cliq_notification_task extends \core\task\adhoc_task {

    /**
     * Get task name for administration displays.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_send_cliq_notification', 'local_batchanalytics');
    }

    /**
     * Execute the ad-hoc task.
     */
    public function execute() {
        $raw_data = $this->get_custom_data();
        if (empty($raw_data)) {
            return;
        }

        // Moodle core deserializes ad-hoc task customdata into stdClass objects recursively.
        // Convert to an associative array so all nested structures (e.g. modules, next_mod_data) are real arrays.
        $customdata = json_decode(json_encode($raw_data), true);
        if (empty($customdata) || empty($customdata['action'])) {
            return;
        }

        $action = (string)$customdata['action'];
        $data   = is_array($customdata['data'] ?? null) ? $customdata['data'] : [];

        switch ($action) {
            case 'completion':
                \local_batchanalytics\cliq_activity_notifier::send_completion_alert(
                    $data['type'] ?? 'mentor',
                    (int)($data['batchid'] ?? 0),
                    (int)($data['courseid'] ?? 0),
                    $data['act_key'] ?? '',
                    (int)($data['completed_by_userid'] ?? 0),
                    false // async = false to perform synchronous delivery in task runner
                );
                break;

            case 'batch_review_note':
                \local_batchanalytics\cliq_activity_notifier::send_batch_review_note_alert(
                    (int)($data['batchid'] ?? 0),
                    (int)($data['userid'] ?? 0),
                    $data['author'] ?? '',
                    $data['body'] ?? '',
                    false // async = false
                );
                break;

            case 'module_start_transition':
                $batchid = (int)($data['batchid'] ?? 0);
                $mod_key = (string)($data['current_mod_idx'] ?? '');
                $modules = is_array($data['modules'] ?? null) ? $data['modules'] : [];
                $actualstart_ts = (int)($data['actualstart_ts'] ?? 0);

                // If modules array is empty or missing, re-read fresh modules from the batch class section record.
                if (empty($modules) && $batchid > 0) {
                    global $DB;
                    $sec = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
                    if ($sec && !empty($sec->moduledata)) {
                        $modules = \local_batchanalytics\util::decode_module_data($sec->moduledata, true);
                    }
                }

                \local_batchanalytics\cliq_activity_notifier::handle_module_start_transition(
                    $batchid,
                    $mod_key,
                    $modules,
                    $actualstart_ts,
                    false // async = false
                );
                break;

            case 'module_assigned':
                $next_mod_data = is_array($data['next_mod_data'] ?? null) ? $data['next_mod_data'] : [];
                \local_batchanalytics\cliq_activity_notifier::send_module_assigned_mentor_alert(
                    (int)($data['batchid'] ?? 0),
                    (string)($data['next_module_name'] ?? ''),
                    $next_mod_data,
                    (int)($data['transition_timestamp'] ?? 0),
                    (int)($data['escalation_type'] ?? 0),
                    null,
                    (string)($data['escalation_reason'] ?? ''),
                    false // async = false
                );
                break;
        }
    }
}

