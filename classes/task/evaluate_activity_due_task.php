<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_batchanalytics\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../cliq_activity_notifier.php');

/**
 * Scheduled task to evaluate Mentor activities and SS activities due dates and dispatch Zoho Cliq notifications.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evaluate_activity_due_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_evaluate_activity_due', 'local_batchanalytics');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        if (!\local_batchanalytics\cliq_activity_notifier::is_enabled()) {
            mtrace("Zoho Cliq notifications are globally disabled. Skipping task.");
            return;
        }

        mtrace("Starting evaluation of Mentor activities and SS activities due dates...");
        $res = \local_batchanalytics\cliq_activity_notifier::evaluate_due_activities(false);

        mtrace("Evaluation completed:");
        mtrace("- Sections checked: " . ($res['checked_sections'] ?? 0));
        mtrace("- Activities matched: " . ($res['matched'] ?? 0));
        mtrace("- Notifications sent: " . ($res['sent'] ?? 0));
        mtrace("- Skipped (already sent today): " . ($res['skipped_dedup'] ?? 0));

        if (!empty($res['errors'])) {
            mtrace("- Errors encountered: " . count($res['errors']));
            foreach ($res['errors'] as $err) {
                mtrace("  ! " . $err);
            }
        }
    }
}
