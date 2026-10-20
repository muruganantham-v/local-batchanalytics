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

    if ($oldversion < 2026092801) {
        $table = new xmldb_table('local_batchanalytics_mentor_act');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('selectedgroup', XMLDB_TYPE_CHAR, '100', null, null, null, '');
        $table->add_field('activitiesdata', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('courseid_uix', XMLDB_INDEX_UNIQUE, ['courseid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092801, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026092901) {
        require_once(__DIR__ . '/../classes/activity_tracker_service.php');
        require_once(__DIR__ . '/../classes/mentor_activity_service.php');

        if (get_config('local_batchanalytics', 'module_tracker_categories') === false) {
            set_config('module_tracker_categories', json_encode(\local_batchanalytics\activity_tracker_service::get_default_tracker_categories()), 'local_batchanalytics');
        }
        if (get_config('local_batchanalytics', 'mentor_master_activities') === false) {
            set_config('mentor_master_activities', json_encode(\local_batchanalytics\mentor_activity_service::DEFAULT_MASTER_ACTIVITIES), 'local_batchanalytics');
        }
        if (get_config('local_batchanalytics', 'mentor_activity_grouping') === false) {
            set_config('mentor_activity_grouping', json_encode(\local_batchanalytics\mentor_activity_service::DEFAULT_GROUPING_RULES), 'local_batchanalytics');
        }

        upgrade_plugin_savepoint(true, 2026092901, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026093001) {
        require_once(__DIR__ . '/../classes/mentor_activity_service.php');

        set_config('mentor_master_activities', json_encode(\local_batchanalytics\mentor_activity_service::DEFAULT_MASTER_ACTIVITIES), 'local_batchanalytics');
        set_config('mentor_activity_grouping', json_encode(\local_batchanalytics\mentor_activity_service::DEFAULT_GROUPING_RULES), 'local_batchanalytics');

        upgrade_plugin_savepoint(true, 2026093001, 'local', 'batchanalytics');
    }


    if ($oldversion < 2026100101) {
        require_once(__DIR__ . '/../classes/mentor_activity_service.php');

        set_config('mentor_master_activities', json_encode(\local_batchanalytics\mentor_activity_service::DEFAULT_MASTER_ACTIVITIES), 'local_batchanalytics');
        set_config('mentor_activity_grouping', json_encode(\local_batchanalytics\mentor_activity_service::DEFAULT_GROUPING_RULES), 'local_batchanalytics');

        upgrade_plugin_savepoint(true, 2026100101, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026100104) {
        $table = new xmldb_table('local_batchanalytics_cliq_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('batchid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('activity_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
        $table->add_field('activity_key', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
        $table->add_field('stage', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, '');
        $table->add_field('recipient_email', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('timesent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('date_sent', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, '');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'sent');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('dedup_ix', XMLDB_INDEX_NOTUNIQUE, ['batchid', 'activity_key', 'stage', 'date_sent']);
        $table->add_index('timesent_ix', XMLDB_INDEX_NOTUNIQUE, ['timesent']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026100104, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026101001) {
        $table = new xmldb_table('local_batchanalytics_cliq_log');
        $index = new xmldb_index('dedup_recipient_ix', XMLDB_INDEX_NOTUNIQUE, ['batchid', 'activity_key', 'stage', 'recipient_email', 'date_sent']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026101001, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026101402) {
        upgrade_plugin_savepoint(true, 2026101402, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026101403) {
        require_once(__DIR__ . '/../classes/mentor_activity_service.php');

        set_config('mentor_master_activities', json_encode(\local_batchanalytics\mentor_activity_service::DEFAULT_MASTER_ACTIVITIES), 'local_batchanalytics');
        set_config('mentor_activity_grouping', json_encode(\local_batchanalytics\mentor_activity_service::DEFAULT_GROUPING_RULES), 'local_batchanalytics');

        upgrade_plugin_savepoint(true, 2026101403, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026101404) {
        require_once(__DIR__ . '/../classes/activity_tracker_service.php');

        set_config('module_tracker_categories', json_encode(\local_batchanalytics\activity_tracker_service::get_default_tracker_categories()), 'local_batchanalytics');

        upgrade_plugin_savepoint(true, 2026101404, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026101901) {
        // Authorization enhancements: unified section permission guard applied to batch actions and review notes.
        upgrade_plugin_savepoint(true, 2026101901, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026101902) {
        // Issue #42: Data migration to resolve existing legacy name strings in local_bm_classsection to user IDs.
        if ($dbman->table_exists('local_bm_classsection')) {
            $sections = $DB->get_records('local_bm_classsection', null, '', 'id, pmmanager, pmmanagername, maacexecutive, maacexecutivename, moduledata');

            // Helper to resolve a string/name/email/username to a unique user id.
            $resolve_unique_userid = function($val) use ($DB) {
                if ($val === null || $val === '' || $val === '0' || $val === '—') {
                    return null;
                }
                $str = trim((string)$val);
                if (ctype_digit($str) && (int)$str > 0) {
                    return (int)$str;
                }

                // Try exact match on username.
                $users_by_uname = $DB->get_records_select('user', 'deleted = 0 AND LOWER(username) = :uname', ['uname' => strtolower($str)]);
                if (count($users_by_uname) === 1) {
                    return (int)reset($users_by_uname)->id;
                }

                // Try exact match on email.
                $users_by_email = $DB->get_records_select('user', 'deleted = 0 AND LOWER(email) = :email', ['email' => strtolower($str)]);
                if (count($users_by_email) === 1) {
                    return (int)reset($users_by_email)->id;
                }

                // Try exact match on full name: firstname || ' ' || lastname.
                $fullname_sql = $DB->sql_concat('LOWER(firstname)', "' '", 'LOWER(lastname)');
                $users_by_name = $DB->get_records_select('user', "deleted = 0 AND $fullname_sql = :fullname", ['fullname' => strtolower($str)]);
                if (count($users_by_name) === 1) {
                    return (int)reset($users_by_name)->id;
                }

                return null;
            };

            foreach ($sections as $sec) {
                $updated = false;
                $update_record = new \stdClass();
                $update_record->id = $sec->id;

                // 1. Resolve pmmanager.
                if (empty($sec->pmmanager) || !ctype_digit(trim((string)$sec->pmmanager)) || (int)$sec->pmmanager <= 0) {
                    $cand = !empty($sec->pmmanager) ? $sec->pmmanager : ($sec->pmmanagername ?? '');
                    $matched_uid = $resolve_unique_userid($cand);
                    if ($matched_uid) {
                        $update_record->pmmanager = (string)$matched_uid;
                        $updated = true;
                    } else if (!empty($cand)) {
                        error_log("[local_batchanalytics] Issue #42 upgrade: Unable to resolve PM '{$cand}' on section {$sec->id} to a unique user ID.");
                    }
                }

                // 2. Resolve maacexecutive.
                if (empty($sec->maacexecutive) || !ctype_digit(trim((string)$sec->maacexecutive)) || (int)$sec->maacexecutive <= 0) {
                    $cand = !empty($sec->maacexecutive) ? $sec->maacexecutive : ($sec->maacexecutivename ?? '');
                    $matched_uid = $resolve_unique_userid($cand);
                    if ($matched_uid) {
                        $update_record->maacexecutive = (string)$matched_uid;
                        $updated = true;
                    } else if (!empty($cand)) {
                        error_log("[local_batchanalytics] Issue #42 upgrade: Unable to resolve SSE '{$cand}' on section {$sec->id} to a unique user ID.");
                    }
                }

                // 3. Resolve mentors inside moduledata JSON.
                if (!empty($sec->moduledata)) {
                    $modules = json_decode($sec->moduledata, true);
                    if (is_array($modules)) {
                        $mod_updated = false;
                        $mentor_keys = ['primarymentor', 'secondarymentor', 'labmentor1', 'labmentor2', 'labmentor3'];
                        foreach ($modules as &$mod) {
                            if (!is_array($mod)) {
                                continue;
                            }
                            foreach ($mentor_keys as $mkey) {
                                if (!empty($mod[$mkey]) && !is_numeric($mod[$mkey])) {
                                    $mval = $mod[$mkey];
                                    $matched_uid = $resolve_unique_userid($mval);
                                    if ($matched_uid) {
                                        $mod[$mkey] = (string)$matched_uid;
                                        $mod_updated = true;
                                    } else {
                                        error_log("[local_batchanalytics] Issue #42 upgrade: Unable to resolve mentor '{$mval}' on section {$sec->id} module to a unique user ID.");
                                    }
                                }
                            }
                        }
                        unset($mod);
                        if ($mod_updated) {
                            $update_record->moduledata = json_encode($modules);
                            $updated = true;
                        }
                    }
                }

                if ($updated) {
                    $DB->update_record('local_bm_classsection', $update_record);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026101902, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026101903) {
        // Module schedule date validation, idempotency, and transactional Cliq dispatch (Issues #46 & #47).
        upgrade_plugin_savepoint(true, 2026101903, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026101904) {
        // Prevent batch leakage to restricted users with no assignments in getnewbatchdata (Issue #40).
        upgrade_plugin_savepoint(true, 2026101904, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026101905) {
        // Prevent Placement_Company leakage via placed_company in getptfdata (Issue #44).
        upgrade_plugin_savepoint(true, 2026101905, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026101906) {
        // Repair shared mentor activities converted to section-specific records (Issue #48).
        $table_name = 'local_batchanalytics_mentor_act';
        if ($DB->get_manager()->table_exists($table_name)) {
            $records = $DB->get_records($table_name);
            foreach ($records as $rec) {
                if (empty($rec->activitiesdata)) {
                    continue;
                }
                $list = json_decode($rec->activitiesdata, true);
                if (!is_array($list) || empty($list)) {
                    continue;
                }

                $has_template = [];
                $section_items_by_key = [];

                foreach ($list as $item) {
                    $key = !empty($item['key']) ? mb_strtolower(trim($item['key'])) : (!empty($item['name']) ? mb_strtolower(trim($item['name'])) : '');
                    if ($key === '') {
                        continue;
                    }
                    $sec = isset($item['sectionid']) ? (int)$item['sectionid'] : 0;
                    if ($sec === 0) {
                        $has_template[$key] = true;
                    } else {
                        if (!isset($section_items_by_key[$key])) {
                            $section_items_by_key[$key] = $item;
                        }
                    }
                }

                $updated = false;
                foreach ($section_items_by_key as $key => $sample_item) {
                    if (empty($has_template[$key])) {
                        $template_entry = [
                            'key'            => !empty($sample_item['key']) ? $sample_item['key'] : $key,
                            'name'           => !empty($sample_item['name']) ? $sample_item['name'] : $key,
                            'completed'      => 0,
                            'completiondate' => '',
                            'modifiedby'     => 0,
                            'is_auto'        => 0,
                            'modifiedbyname' => '',
                            'timemodified'   => time(),
                            'sectionid'      => 0,
                        ];
                        if (isset($sample_item['activitytype'])) {
                            $template_entry['activitytype'] = $sample_item['activitytype'];
                        }
                        if (isset($sample_item['group'])) {
                            $template_entry['group'] = $sample_item['group'];
                        }
                        $list[] = $template_entry;
                        $has_template[$key] = true;
                        $updated = true;
                    }
                }

                if ($updated) {
                    $rec->activitiesdata = json_encode(array_values($list), JSON_UNESCAPED_UNICODE);
                    $rec->timemodified = time();
                    $DB->update_record($table_name, $rec);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026101906, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026101907) {
        // Decouple companion block: migrate block_batchanalytics settings and mentor activity data to local_batchanalytics (Issue #45).
        $dbman = $DB->get_manager();

        // 1. Migrate settings from block_batchanalytics if not already set in local_batchanalytics.
        $block_configs = $DB->get_records('config_plugins', ['plugin' => 'block_batchanalytics']);
        foreach ($block_configs as $cfg) {
            $existing = get_config('local_batchanalytics', $cfg->name);
            if ($existing === false || $existing === null || trim((string)$existing) === '') {
                set_config($cfg->name, $cfg->value, 'local_batchanalytics');
            }
        }

        // 2. Migrate mentor activities from block_batchanalytics_mentor_act if present.
        if ($dbman->table_exists('block_batchanalytics_mentor_act') && $dbman->table_exists('local_batchanalytics_mentor_act')) {
            $old_records = $DB->get_records('block_batchanalytics_mentor_act');
            foreach ($old_records as $rec) {
                if (empty($rec->courseid)) {
                    continue;
                }
                $exists = $DB->record_exists('local_batchanalytics_mentor_act', [
                    'courseid' => (int)$rec->courseid,
                ]);
                if (!$exists) {
                    $insert_rec = (object)[
                        'courseid'       => (int)$rec->courseid,
                        'selectedgroup'  => isset($rec->selectedgroup) ? (string)$rec->selectedgroup : '',
                        'activitiesdata' => isset($rec->activitiesdata) ? (string)$rec->activitiesdata : '[]',
                        'timemodified'   => !empty($rec->timemodified) ? (int)$rec->timemodified : time(),
                    ];
                    $DB->insert_record('local_batchanalytics_mentor_act', $insert_rec);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026101907, 'local', 'batchanalytics');
    }

    if ($oldversion < 2026102001) {
        $table = new xmldb_table('local_batchanalytics_cliq_log');
        $index = new xmldb_index('recipient_email_ix', XMLDB_INDEX_NOTUNIQUE, ['recipient_email']);

        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026102001, 'local', 'batchanalytics');
    }

    return true;
}
