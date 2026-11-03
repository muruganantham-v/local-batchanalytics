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
        $customdata = $this->get_custom_data();
        if (empty($customdata) || empty($customdata->action)) {
            return;
        }

        $action = $customdata->action;
        $data   = (array)($customdata->data ?? []);

        switch ($action) {
            case 'completion':
                \local_batchanalytics\cliq_activity_notifier::send_completion_alert(
                    $data['type'] ?? 'mentor',
                    (int)($data['batchid'] ?? 0),
                    (int)($data['courseid'] ?? 0),
                    $data['act_key'] ?? '',
                    (int)($data['completed_by_userid'] ?? 0),
                    $data['completed_by_name'] ?? '',
                    (int)($data['completion_timestamp'] ?? 0),
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
                \local_batchanalytics\cliq_activity_notifier::handle_module_start_transition(
                    (int)($data['batchid'] ?? 0),
                    (string)($data['current_mod_idx'] ?? ''),
                    (array)($data['modules'] ?? []),
                    (int)($data['actualstart_ts'] ?? 0),
                    false // async = false
                );
                break;

            case 'module_assigned':
                \local_batchanalytics\cliq_activity_notifier::send_module_assigned_mentor_alert(
                    (int)($data['batchid'] ?? 0),
                    (string)($data['next_module_name'] ?? ''),
                    (array)($data['next_mod_data'] ?? []),
                    (int)($data['transition_timestamp'] ?? 0),
                    false // async = false
                );
                break;
        }
    }
}

