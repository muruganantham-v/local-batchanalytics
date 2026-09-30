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
 * Upgrade script for block_batchanalytics.
 *
 * @package    block_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute upgrade steps.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_block_batchanalytics_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Migrate any tables from local_batchanalytics if they exist.
    $table_renames = [
        'local_batchanalytics_activity_tracker' => 'block_batchanalytics_activity_tracker',
        'local_batchanalytics_batch_notes' => 'block_batchanalytics_batch_notes',
        'local_batchanalytics_mentor_act' => 'block_batchanalytics_mentor_act',
    ];

    foreach ($table_renames as $old_name => $new_name) {
        $old_table = new xmldb_table($old_name);
        $new_table = new xmldb_table($new_name);
        if ($dbman->table_exists($old_table) && !$dbman->table_exists($new_table)) {
            $dbman->rename_table($old_table, $new_name);
        }
    }

    if ($oldversion < 2026092806) {
        // Consolidate any legacy local/batchanalytics capabilities into block/batchanalytics.
        $legacy_caps = [
            'local/batchanalytics:view' => 'block/batchanalytics:view',
            'local/batchanalytics:manage' => 'block/batchanalytics:manage',
            'local/batchanalytics:viewallcourses' => 'block/batchanalytics:viewallcourses',
            'local/batchanalytics:viewenrolledcourses' => 'block/batchanalytics:viewenrolledcourses',
            'local/batchanalytics:viewreviewnotes' => 'block/batchanalytics:viewreviewnotes',
            'local/batchanalytics:editreviewnotes' => 'block/batchanalytics:editreviewnotes',
        ];

        foreach ($legacy_caps as $oldcap => $newcap) {
            $old_rcs = $DB->get_records('role_capabilities', ['capability' => $oldcap]);
            foreach ($old_rcs as $rc) {
                if (!$DB->record_exists('role_capabilities', ['roleid' => $rc->roleid, 'capability' => $newcap, 'contextid' => $rc->contextid])) {
                    $new_rc = clone($rc);
                    unset($new_rc->id);
                    $new_rc->capability = $newcap;
                    $DB->insert_record('role_capabilities', $new_rc);
                }
            }
            $DB->delete_records('role_capabilities', ['capability' => $oldcap]);
            $DB->delete_records('capabilities', ['name' => $oldcap]);
        }

        // Clean up any remaining local_batchanalytics component entries in capabilities table.
        $DB->delete_records('capabilities', ['component' => 'local_batchanalytics']);

        // Purge capability cache so Define Roles shows single unified group.
        accesslib_clear_all_caches(true);

        upgrade_block_savepoint(true, 2026092806, 'batchanalytics');
    }

    if ($oldversion < 2026092814) {
        global $CFG;
        require_once($CFG->dirroot . '/blocks/batchanalytics/classes/activity_tracker_service.php');
        require_once($CFG->dirroot . '/blocks/batchanalytics/classes/mentor_activity_service.php');

        set_config('module_tracker_categories', json_encode(\block_batchanalytics\activity_tracker_service::get_default_tracker_categories()), 'block_batchanalytics');
        set_config('mentor_master_activities', json_encode(\block_batchanalytics\mentor_activity_service::DEFAULT_MASTER_ACTIVITIES), 'block_batchanalytics');
        set_config('mentor_activity_grouping', json_encode(\block_batchanalytics\mentor_activity_service::DEFAULT_GROUPING_RULES), 'block_batchanalytics');

        unset_config('module_tracker_categories', 'local_batchanalytics');
        unset_config('mentor_master_activities', 'local_batchanalytics');
        unset_config('mentor_activity_grouping', 'local_batchanalytics');

        \block_batchanalytics\mentor_activity_service::sync_all_courses_from_class_sections();

        upgrade_block_savepoint(true, 2026092814, 'batchanalytics');
    }

    if ($oldversion < 2026092815) {
        upgrade_block_savepoint(true, 2026092815, 'batchanalytics');
    }

    return true;
}
