<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Service for storing and retrieving batch review notes.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class batch_notes_service {
    const TABLE = 'local_batchanalytics_batch_notes';

    /**
     * Ensure the database table exists.
     */
    public static function ensure_table_exists(): void {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(self::TABLE)) {
            $table = new \xmldb_table(self::TABLE);
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('batchid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('author', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('batchid_ix', XMLDB_INDEX_NOTUNIQUE, ['batchid']);

            $dbman->create_table($table);
        }
    }

    /**
     * Get all notes for a specific batch/classsection ID, newest first.
     *
     * @param int $batchid
     * @return array
     */
    public static function get_notes(int $batchid): array {
        global $DB;
        self::ensure_table_exists();

        if ($batchid <= 0) {
            return [];
        }

        $records = $DB->get_records(self::TABLE, ['batchid' => $batchid], 'timecreated DESC, id DESC');
        $notes = [];
        foreach ($records as $r) {
            $notes[] = [
                'id'          => (int)$r->id,
                'batchid'     => (int)$r->batchid,
                'userid'      => (int)$r->userid,
                'who'         => (string)$r->author,
                'date'        => userdate($r->timecreated, '%d %b %Y, %I:%M %p'),
                'timecreated' => (int)$r->timecreated,
                'body'        => (string)$r->body,
            ];
        }
        return $notes;
    }

    /**
     * Add a review note to a batch.
     *
     * @param int $batchid
     * @param int $userid
     * @param string $author
     * @param string $body
     * @return array
     */
    public static function add_note(int $batchid, int $userid, string $author, string $body): array {
        global $DB;
        self::ensure_table_exists();

        $body = trim($body);
        if ($body === '') {
            throw new \invalid_parameter_exception('Review note cannot be empty.');
        }
        if ($batchid <= 0) {
            throw new \invalid_parameter_exception('Invalid batch ID.');
        }

        $record = (object)[
            'batchid'     => $batchid,
            'userid'      => $userid,
            'author'      => $author,
            'body'        => $body,
            'timecreated' => time(),
        ];

        $id = $DB->insert_record(self::TABLE, $record);
        $record->id = $id;

        // Dispatch real-time Zoho Cliq alert for Batch Review completion to Program Manager
        try {
            if (class_exists('\\local_batchanalytics\\cliq_activity_notifier')) {
                cliq_activity_notifier::send_batch_review_note_alert($batchid, $userid, $author, $body);
            }
        } catch (\Throwable $e) {
            // Silently continue so note creation is never interrupted
        }

        return [
            'id'          => $id,
            'batchid'     => $batchid,
            'userid'      => $userid,
            'who'         => $author,
            'date'        => userdate($record->timecreated, '%d %b %Y, %I:%M %p'),
            'timecreated' => $record->timecreated,
            'body'        => $body,
        ];
    }

    /**
     * Calculate the 15-day recurring batch review due information.
     *
     * Cadence rule:
     * 1. If batch has started, initial review due date is 15 days after batch start date.
     * 2. Once a review note is added/updated, reset due date to 15 days from the latest note timestamp.
     * 3. Continues recurring every 15 days.
     *
     * @param int $batchid Class section ID
     * @param ?object $section Optional pre-loaded section object
     * @return ?array Array containing due info or null if batch not started / invalid
     */
    public static function get_batch_review_due_info(int $batchid, ?object $section = null): ?array {
        global $DB;
        self::ensure_table_exists();

        if ($batchid <= 0) {
            return null;
        }

        if ($section === null) {
            $dbman = $DB->get_manager();
            if (!$dbman->table_exists('local_bm_classsection')) {
                return null;
            }
            $section = $DB->get_record('local_bm_classsection', ['id' => $batchid]);
        }

        if (!$section) {
            return null;
        }

        // Determine batch start date
        $startdate_ts = 0;
        if (!empty($section->batchid)) {
            $dbman = $DB->get_manager();
            if ($dbman->table_exists('local_bm_batch')) {
                $batch = $DB->get_record('local_bm_batch', ['id' => $section->batchid]);
                if ($batch && !empty($batch->startdate)) {
                    $startdate_ts = (int)$batch->startdate;
                }
            }
        }
        if ($startdate_ts <= 0 && !empty($section->timecreated)) {
            $startdate_ts = (int)$section->timecreated;
        }

        if ($startdate_ts <= 0) {
            return null;
        }

        // Only evaluate if batch has started (start date is now or in the past)
        if ($startdate_ts > time()) {
            return null;
        }

        $cycle_days = 15;
        $cycle_seconds = $cycle_days * 86400;

        // Fetch latest review note
        $notes = $DB->get_records(self::TABLE, ['batchid' => $batchid], 'timecreated DESC, id DESC', 'id, userid, author, timecreated', 0, 1);
        if (!empty($notes)) {
            $latest = reset($notes);
            $has_notes = true;
            $last_review_ts = (int)$latest->timecreated;
            $last_review_author = (string)$latest->author;
            $due_ts = $last_review_ts + $cycle_seconds;
        } else {
            $has_notes = false;
            $last_review_ts = 0;
            $last_review_author = '';
            $due_ts = $startdate_ts + $cycle_seconds;
        }

        return [
            'batchid'            => $batchid,
            'startdate_ts'       => $startdate_ts,
            'has_notes'          => $has_notes,
            'last_review_ts'     => $last_review_ts,
            'last_review_author' => $last_review_author,
            'due_ts'             => $due_ts,
            'due_date_str'       => userdate($due_ts, '%d %b %Y'),
            'cycle_days'         => $cycle_days,
        ];
    }

    /**
     * Delete a review note by ID.
     *
     * @param int $noteid
     * @return bool
     */
    public static function delete_note(int $noteid): bool {
        global $DB;
        self::ensure_table_exists();
        return $DB->delete_records(self::TABLE, ['id' => $noteid]);
    }
}

