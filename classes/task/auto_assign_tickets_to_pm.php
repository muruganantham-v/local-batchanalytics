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
 * Auto assign overdue MAAC tickets to the configured PM/batch manager.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auto_assign_tickets_to_pm extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_auto_assign_tickets_to_pm', 'local_batchanalytics');
    }

    /**
     * @return void
     */
    public function execute(): void {
        $service = new \local_batchanalytics\maac_service();
        $result = $service->auto_assign_overdue_tickets_to_pm();
        mtrace('Auto assigned overdue MAAC tickets to PM: ' . (int)($result['processed'] ?? 0));
    }
}