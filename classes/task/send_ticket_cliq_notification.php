<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_batchanalytics\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Delivers a ticket Cliq notification outside the ticket write request.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_ticket_cliq_notification extends \core\task\adhoc_task {

    /**
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $ticketdata = (array)($data->ticket ?? []);
        $recipients = (array)($data->recipients ?? []);
        $type = (string)($data->type ?? 'update');
        if (empty($ticketdata) || empty($recipients)) {
            return;
        }

        $extra = [];
        $updatedby = (int)($data->updatedby ?? 0);
        if ($updatedby > 0) {
            $user = \core_user::get_user($updatedby, 'id, firstname, lastname, username, email', IGNORE_MISSING);
            if ($user) {
                $extra['updatedby'] = $user;
            }
        }

        try {
            $service = new \local_batchanalytics\cliq_service();
            $service->send_ticket_message($type, (object)$ticketdata, $recipients, $extra);
        } catch (\Throwable $e) {
            debugging('Unable to deliver queued Batch Analytics Cliq notification: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
