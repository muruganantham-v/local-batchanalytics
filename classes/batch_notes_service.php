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
     * Retained for backward-compatibility.
     * Schema management is handled exclusively via db/install.xml and db/upgrade.php per Moodle standards (ARCH-02).
     */
    public static function ensure_table_exists(): void {
        // No-op: Table local_batchanalytics_batch_notes is managed via db/install.xml.
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
        if (empty($records)) {
            return [];
        }

        // Pre-fetch modifier names to avoid N+1 queries.
        $modifier_ids = [];
        foreach ($records as $r) {
            if (!empty($r->modifiedby) && (int)$r->modifiedby > 0) {
                $modifier_ids[] = (int)$r->modifiedby;
            }
        }
        $modifier_map = [];
        if (!empty($modifier_ids)) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_unique($modifier_ids), SQL_PARAMS_NAMED);
            $users = $DB->get_records_select('user', "id $insql AND deleted = 0", $inparams, '', 'id, firstname, lastname');
            foreach ($users as $u) {
                $modifier_map[$u->id] = fullname($u);
            }
        }

        $notes = [];
        foreach ($records as $r) {
            $is_edited = !empty($r->timemodified) && (int)$r->timemodified > 0;
            $modby = (int)($r->modifiedby ?? 0);
            $mod_name = $modby > 0 ? ($modifier_map[$modby] ?? 'Reviewer') : '';

            $notes[] = [
                'id'               => (int)$r->id,
                'batchid'          => (int)$r->batchid,
                'userid'           => (int)$r->userid,
                'who'              => (string)$r->author,
                'date'             => userdate($r->timecreated, '%d %b %Y, %I:%M %p'),
                'timecreated'      => (int)$r->timecreated,
                'is_edited'        => $is_edited,
                'timemodified'     => $is_edited ? (int)$r->timemodified : 0,
                'timemodified_str' => $is_edited ? userdate($r->timemodified, '%d %b %Y, %I:%M %p') : '',
                'modifiedby'       => $modby,
                'modifier_name'    => $mod_name,
                'body'             => (string)$r->body,
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
     * Check whether a given note ID is the latest note for a batch.
     *
     * @param int $batchid
     * @param int $noteid
     * @return bool
     */
    public static function is_latest_note(int $batchid, int $noteid): bool {
        global $DB;
        self::ensure_table_exists();

        if ($batchid <= 0 || $noteid <= 0) {
            return false;
        }

        $top = $DB->get_records(self::TABLE, ['batchid' => $batchid], 'timecreated DESC, id DESC', 'id', 0, 1);
        if (empty($top)) {
            return false;
        }
        $latest = reset($top);
        return ((int)$latest->id === $noteid);
    }

    /**
     * Update an existing review note by ID and capture full revision history.
     *
     * @param int $noteid
     * @param string $body
     * @param int $modifiedby User ID performing the edit
     * @param string $modifier_name Full name of the user performing the edit
     * @return array
     */
    public static function update_note(int $noteid, string $body, int $modifiedby = 0, string $modifier_name = ''): array {
        global $DB;
        self::ensure_table_exists();

        $body = trim($body);
        if ($body === '') {
            throw new \invalid_parameter_exception('Review note cannot be empty.');
        }
        if ($noteid <= 0) {
            throw new \invalid_parameter_exception('Invalid note ID.');
        }

        $record = $DB->get_record(self::TABLE, ['id' => $noteid]);
        if (!$record) {
            throw new \moodle_exception('Note not found.');
        }

        // Parse existing history or initialize with the original baseline as Version 1
        $history_list = [];
        if (!empty($record->history)) {
            $decoded = json_decode($record->history, true);
            if (is_array($decoded)) {
                $history_list = $decoded;
            }
        }

        if (empty($history_list)) {
            $history_list[] = [
                'version'     => 1,
                'userid'      => (int)$record->userid,
                'author'      => (string)$record->author,
                'body'        => (string)$record->body,
                'timecreated' => (int)$record->timecreated,
                'date'        => userdate($record->timecreated, '%d %b %Y, %I:%M %p'),
            ];
        }

        $now = time();
        $mod_name = trim($modifier_name);
        if ($mod_name === '' && $modifiedby > 0) {
            $u = $DB->get_record('user', ['id' => $modifiedby, 'deleted' => 0]);
            if ($u) {
                $mod_name = fullname($u);
            }
        }
        if ($mod_name === '') {
            $mod_name = (string)$record->author;
        }

        $next_version = count($history_list) + 1;
        $history_list[] = [
            'version'     => $next_version,
            'userid'      => $modifiedby > 0 ? $modifiedby : (int)$record->userid,
            'author'      => $mod_name,
            'body'        => $body,
            'timecreated' => $now,
            'date'        => userdate($now, '%d %b %Y, %I:%M %p'),
        ];

        $record->body         = $body;
        $record->timemodified = $now;
        $record->modifiedby   = $modifiedby > 0 ? $modifiedby : (int)$record->userid;
        $record->history      = json_encode($history_list);

        $DB->update_record(self::TABLE, $record);

        return [
            'id'               => (int)$record->id,
            'batchid'          => (int)$record->batchid,
            'userid'           => (int)$record->userid,
            'who'              => (string)$record->author,
            'date'             => userdate($record->timecreated, '%d %b %Y, %I:%M %p'),
            'timecreated'      => (int)$record->timecreated,
            'is_edited'        => true,
            'timemodified'     => $now,
            'timemodified_str' => userdate($now, '%d %b %Y, %I:%M %p'),
            'modifiedby'       => (int)$record->modifiedby,
            'modifier_name'    => $mod_name,
            'body'             => $body,
        ];
    }

    /**
     * Retrieve the revision history snapshots for a review note.
     *
     * @param int $noteid
     * @return array Array of version objects (version, author, date, body, etc.)
     */
    public static function get_note_history(int $noteid): array {
        global $DB;
        self::ensure_table_exists();

        if ($noteid <= 0) {
            return [];
        }

        $record = $DB->get_record(self::TABLE, ['id' => $noteid]);
        if (!$record) {
            return [];
        }

        $history_list = [];
        if (!empty($record->history)) {
            $decoded = json_decode($record->history, true);
            if (is_array($decoded)) {
                $history_list = $decoded;
            }
        }

        if (empty($history_list)) {
            $history_list[] = [
                'version'     => 1,
                'userid'      => (int)$record->userid,
                'author'      => (string)$record->author,
                'body'        => (string)$record->body,
                'timecreated' => (int)$record->timecreated,
                'date'        => userdate($record->timecreated, '%d %b %Y, %I:%M %p'),
            ];
        }

        return $history_list;
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

