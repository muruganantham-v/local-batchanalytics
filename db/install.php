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

/**
 * Post-installation code for local_batchanalytics.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Post install function to set up default settings.
 */
function xmldb_local_batchanalytics_install() {
    global $CFG;

    require_once(__DIR__ . '/../classes/activity_tracker_service.php');
    require_once(__DIR__ . '/../classes/mentor_activity_service.php');

    set_config('module_tracker_categories', json_encode(\local_batchanalytics\activity_tracker_service::get_default_tracker_categories()), 'local_batchanalytics');
    set_config('mentor_master_activities', json_encode(\local_batchanalytics\mentor_activity_service::DEFAULT_MASTER_ACTIVITIES), 'local_batchanalytics');
    set_config('mentor_activity_grouping', json_encode(\local_batchanalytics\mentor_activity_service::DEFAULT_GROUPING_RULES), 'local_batchanalytics');
}

