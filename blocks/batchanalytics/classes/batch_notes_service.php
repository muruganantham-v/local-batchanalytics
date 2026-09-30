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
