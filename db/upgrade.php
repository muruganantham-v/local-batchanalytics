<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for local_batchanalytics.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_batchanalytics_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026050700) {
        $table = new xmldb_table('local_batchanalytics_maac');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('fieldkey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('value', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('course_user_field_uix', XMLDB_INDEX_UNIQUE, ['courseid', 'userid', 'fieldkey']);
        $table->add_index('courseid_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('userid_ix', XMLDB_INDEX_NOTUNIQUE, ['userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026050700, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026050800) {
        $table = new xmldb_table('local_batchanalytics_ticket');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('studentuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('studentgroupid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('ssteamroleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('ssteamuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('batchmanagerroleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('batchmanageruserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('tickettitle', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('ticketreason', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'open');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('courseid_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('studentuserid_ix', XMLDB_INDEX_NOTUNIQUE, ['studentuserid']);
        $table->add_index('groupid_ix', XMLDB_INDEX_NOTUNIQUE, ['studentgroupid']);
        $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026050800, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026050900) {
        $table = new xmldb_table('local_batchanalytics_ticket');

        $field = new xmldb_field('resolutionfeedback', XMLDB_TYPE_TEXT, null, null, null, null, null, 'createdby');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('resolvedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'resolutionfeedback');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('timeresolved', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'resolvedby');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026050900, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026051200) {
        $table = new xmldb_table('local_batchanalytics_ticket');

        $field = new xmldb_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'low', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026051200, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026051300) {
        $table = new xmldb_table('local_batchanalytics_ticket_event');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('eventtype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('fromstatus', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('tostatus', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('frompriority', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('topriority', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('feedback', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('ticketid_ix', XMLDB_INDEX_NOTUNIQUE, ['ticketid']);
        $table->add_index('userid_ix', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('eventtype_ix', XMLDB_INDEX_NOTUNIQUE, ['eventtype']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026051300, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026071600) {
        $table = new xmldb_table('local_batchanalytics_cliq_history');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('recipientuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('recipientemail', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('messagetype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('subject', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('messagebody', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('responsebody', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('ticketid_ix', XMLDB_INDEX_NOTUNIQUE, ['ticketid']);
        $table->add_index('courseid_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('recipientuserid_ix', XMLDB_INDEX_NOTUNIQUE, ['recipientuserid']);
        $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('timecreated_ix', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026071600, 'local', 'batchanalytics');
    }
    
    if ($oldversion < 2026071601) {
        $table = new xmldb_table('local_batchanalytics_ticket');

        $field = new xmldb_field('escalatedtopm', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'batchmanageruserid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026071601, 'local', 'batchanalytics');
    }
    if ($oldversion < 2026072300) {
        $table = new xmldb_table('local_batchanalytics_activity_tracker');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completiondate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
        $table->add_field('modifiedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('course_cmid_uix', XMLDB_INDEX_UNIQUE, ['courseid', 'cmid']);
        $table->add_index('courseid_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        $table->add_index('cmid_ix', XMLDB_INDEX_NOTUNIQUE, ['cmid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072300, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026073300) {
        foreach (['ticket_raised', 'ticket_update'] as $provider) {
            $enabledkey = 'message_provider_local_batchanalytics_' . $provider . '_enabled';
            if (get_config('message', $enabledkey) === false) {
                set_config($enabledkey, 'popup,email', 'message');
            }
        }

        upgrade_plugin_savepoint(true, 2026073300, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026080200) {
        $table = new xmldb_table('local_batchanalytics_course_summary');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('summary', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('courseid_uix', XMLDB_INDEX_UNIQUE, ['courseid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $legacysummaries = $DB->get_records_select(
            'config_plugins',
            'plugin = :plugin AND ' . $DB->sql_like('name', ':nameprefix', false),
            ['plugin' => 'local_batchanalytics', 'nameprefix' => 'course_summary_%']
        );
        foreach ($legacysummaries as $legacy) {
            if (preg_match('/^course_summary_(\d+)$/', $legacy->name, $matches)) {
                $courseid = (int)$matches[1];
                $summary = trim((string)$legacy->value);
                if ($courseid > 1 && $summary !== '' && $DB->record_exists('course', ['id' => $courseid])) {
                    $DB->insert_record('local_batchanalytics_course_summary', (object)[
                        'courseid' => $courseid,
                        'summary' => $summary,
                        'timemodified' => time(),
                    ]);
                }
            }
            unset_config($legacy->name, 'local_batchanalytics');
        }

        upgrade_plugin_savepoint(true, 2026080200, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026080300) {
        $table = new xmldb_table('local_batchanalytics_ticket');

        $field = new xmldb_field('ssteamfeedback', XMLDB_TYPE_TEXT, null, null, null, null, null, 'resolutionfeedback');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('programmanagerfeedback', XMLDB_TYPE_TEXT, null, null, null, null, null, 'ssteamfeedback');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026080300, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026080400) {
        $table = new xmldb_table('local_batchanalytics_batch_notes');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('batchid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('author', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('batchid_ix', XMLDB_INDEX_NOTUNIQUE, ['batchid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080400, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026080500) {
        require_once($CFG->libdir . '/accesslib.php');
        update_capabilities('local_batchanalytics');
        upgrade_plugin_savepoint(true, 2026080500, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026091700) {
        upgrade_plugin_savepoint(true, 2026091700, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026092700) {
        $tables_to_drop = [
            'local_batchanalytics_ticket_event',
            'local_batchanalytics_cliq_history',
            'local_batchanalytics_ticket',
            'local_batchanalytics_maac',
            'local_batchanalytics_course_summary',
        ];

        foreach ($tables_to_drop as $tablename) {
            $table = new xmldb_table($tablename);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }

        require_once($CFG->libdir . '/accesslib.php');
        update_capabilities('local_batchanalytics');

        upgrade_plugin_savepoint(true, 2026092700, 'local', 'batchanalytics');
    }

    return true;
}
