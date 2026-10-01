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

require_once(__DIR__ . '/../cliq_workflow_engine.php');

/**
 * Scheduled task to evaluate and dispatch automated Zoho Cliq batch notifications.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_cliq_notifications extends \core\task\scheduled_task {

    /**
     * Get task human-readable name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_send_cliq_notifications', 'local_batchanalytics');
    }

    /**
     * Execute the task: evaluate rules and dispatch Cliq messages.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG;

        mtrace("Starting Zoho Cliq notification evaluation...");

        $results = \local_batchanalytics\cliq_workflow_engine::evaluate_scheduled_workflows(false, false);

        if (!empty($results['in_quiet_hours'])) {
            mtrace("Evaluation paused: currently in quiet hours (09:00 PM - 09:00 AM).");
            return;
        }

        mtrace(sprintf(
            "Checked %d batches. Matches found: %d, Dispatched: %d, Skipped (already sent today): %d",
            $results['total_batches_checked'],
            $results['matches_found'],
            $results['sent_count'],
            $results['skipped_count']
        ));

        mtrace("Zoho Cliq notification evaluation completed.");
    }
}
