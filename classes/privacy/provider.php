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

namespace local_batchanalytics\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy Subsystem implementation for local_batchanalytics.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Return the metadata about user data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_batchanalytics_batch_notes',
            [
                'batchid'     => 'privacy:metadata:batch_notes:batchid',
                'userid'      => 'privacy:metadata:batch_notes:userid',
                'author'      => 'privacy:metadata:batch_notes:author',
                'body'        => 'privacy:metadata:batch_notes:body',
                'timecreated' => 'privacy:metadata:batch_notes:timecreated',
            ],
            'privacy:metadata:batch_notes'
        );

        $collection->add_database_table(
            'local_batchanalytics_activity_tracker',
            [
                'courseid'     => 'privacy:metadata:activity_tracker:courseid',
                'cmid'         => 'privacy:metadata:activity_tracker:cmid',
                'completed'    => 'privacy:metadata:activity_tracker:completed',
                'modifiedby'   => 'privacy:metadata:activity_tracker:modifiedby',
                'timemodified' => 'privacy:metadata:activity_tracker:timemodified',
            ],
            'privacy:metadata:activity_tracker'
        );

        $collection->add_database_table(
            'local_batchanalytics_cliq_log',
            [
                'batchid'         => 'privacy:metadata:cliq_log:batchid',
                'courseid'        => 'privacy:metadata:cliq_log:courseid',
                'activity_type'   => 'privacy:metadata:cliq_log:activity_type',
                'activity_key'    => 'privacy:metadata:cliq_log:activity_key',
                'stage'           => 'privacy:metadata:cliq_log:stage',
                'recipient_email' => 'privacy:metadata:cliq_log:recipient_email',
                'timesent'        => 'privacy:metadata:cliq_log:timesent',
                'status'          => 'privacy:metadata:cliq_log:status',
            ],
            'privacy:metadata:cliq_log'
        );

        return $collection;
    }

    /**
     * Get the list of contexts where user data exists for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Course contexts where user modified activity tracking records.
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course} co ON co.id = c.instanceid AND c.contextlevel = :courselevel
                  JOIN {local_batchanalytics_activity_tracker} at ON at.courseid = co.id
                 WHERE at.modifiedby = :userid";
        $contextlist->add_from_sql($sql, ['courselevel' => CONTEXT_COURSE, 'userid' => $userid]);

        // Review notes are attached to batches and managed at the system context level.
        $notes_sql = "SELECT c.id
                        FROM {context} c
                        JOIN {local_batchanalytics_batch_notes} bn ON c.contextlevel = :syslevel
                       WHERE bn.userid = :userid";
        $contextlist->add_from_sql($notes_sql, ['syslevel' => CONTEXT_SYSTEM, 'userid' => $userid]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a specific context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context instanceof \context_course) {
            $sql = "SELECT modifiedby AS userid
                      FROM {local_batchanalytics_activity_tracker}
                     WHERE courseid = :courseid AND modifiedby > 0";
            $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
        } else if ($context instanceof \context_system) {
            $sql = "SELECT userid
                      FROM {local_batchanalytics_batch_notes}
                     WHERE userid > 0";
            $userlist->add_from_sql('userid', $sql, []);
        }
    }

    /**
     * Export all user data for the specified user in the provided contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        $userid = $user->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                // Export batch review notes written by user.
                $notes = $DB->get_records('local_batchanalytics_batch_notes', ['userid' => $userid]);
                if (!empty($notes)) {
                    $export_notes = [];
                    foreach ($notes as $n) {
                        $export_notes[] = [
                            'batchid'     => $n->batchid,
                            'body'        => $n->body,
                            'timecreated' => transform::datetime($n->timecreated),
                        ];
                    }
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_batchanalytics'), get_string('reviewnotes', 'local_batchanalytics')],
                        (object)['notes' => $export_notes]
                    );
                }
            } else if ($context instanceof \context_course) {
                // Export activity tracking completion stamps by this user.
                $tracker = $DB->get_records('local_batchanalytics_activity_tracker', [
                    'courseid'   => $context->instanceid,
                    'modifiedby' => $userid,
                ]);
                if (!empty($tracker)) {
                    $export_tracker = [];
                    foreach ($tracker as $t) {
                        $export_tracker[] = [
                            'cmid'           => $t->cmid,
                            'completed'      => transform::yesno($t->completed),
                            'completiondate' => $t->completiondate,
                            'timemodified'   => transform::datetime($t->timemodified),
                        ];
                    }
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_batchanalytics'), 'ActivityTracker'],
                        (object)['activities' => $export_tracker]
                    );
                }
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context instanceof \context_course) {
            $DB->delete_records('local_batchanalytics_activity_tracker', ['courseid' => $context->instanceid]);
        } else if ($context instanceof \context_system) {
            $DB->delete_records('local_batchanalytics_batch_notes');
        }
    }

    /**
     * Delete all user data for the specified user in the provided contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $DB->delete_records('local_batchanalytics_batch_notes', ['userid' => $userid]);
            } else if ($context instanceof \context_course) {
                $DB->set_field(
                    'local_batchanalytics_activity_tracker',
                    'modifiedby',
                    0,
                    ['courseid' => $context->instanceid, 'modifiedby' => $userid]
                );
            }
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        if ($context instanceof \context_system) {
            $DB->delete_records_select('local_batchanalytics_batch_notes', "userid $insql", $inparams);
        } else if ($context instanceof \context_course) {
            $inparams['courseid'] = $context->instanceid;
            $DB->execute(
                "UPDATE {local_batchanalytics_activity_tracker}
                    SET modifiedby = 0
                  WHERE courseid = :courseid AND modifiedby $insql",
                $inparams
            );
        }
    }
}
