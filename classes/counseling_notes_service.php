<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Service for private staff counseling notes between Student Counsellors
 * and Program Managers. Strictly isolated from external sponsor logins.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class counseling_notes_service {

    const TABLE = 'local_batchanalytics_counseling';

    /**
     * Get notes for a student, strictly enforcing capability checks.
     *
     * @param int $userid
     * @param int $batchid
     * @param \context $context
     * @return array
     */
    public static function get_notes(int $userid, int $batchid, \context $context): array {
        global $DB;

        // Security gate: sponsor logins and unauthorized users cannot view counseling notes
        if (!has_capability('local/batchanalytics:viewcounselingnotes', $context) 
            && !is_siteadmin()) {
            return [];
        }

        if ($DB->get_manager()->table_exists(self::TABLE)) {
            $records = $DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated DESC');
            if (!empty($records)) {
                $notes = [];
                foreach ($records as $r) {
                    $notes[] = [
                        'id' => (int)$r->id,
                        'author' => $r->author,
                        'date' => userdate($r->timecreated, '%d %b %Y, %I:%M %p'),
                        'note' => $r->note,
                    ];
                }
                return $notes;
            }
        }

        // Prototype default counseling notes matching document
        return [
            [
                'id' => 1,
                'author' => 'Anitha S · Student Counsellor',
                'date' => '18 Sep 2026, 5:20 PM',
                'note' => 'Discussed placement goals — keen on embedded firmware roles. Confident, well-prepared.'
            ],
            [
                'id' => 2,
                'author' => 'Anitha S · Student Counsellor',
                'date' => '02 Sep 2026, 4:10 PM',
                'note' => 'Mid-module check-in. Doing well; mild exam anxiety flagged — shared prep resources.'
            ],
            [
                'id' => 3,
                'author' => 'Ravi Kumar · Program Manager',
                'date' => '14 Aug 2026, 3:30 PM',
                'note' => 'Batch review: strong candidate, consistent attendance and assignment quality.'
            ],
        ];
    }

    /**
     * Add a private counseling note.
     *
     * @param int $userid
     * @param int $batchid
     * @param int $authorid
     * @param string $author
     * @param string $note
     * @return int
     */
    public static function add_note(int $userid, int $batchid, int $authorid, string $author, string $note): int {
        global $DB;

        $record = (object)[
            'userid' => $userid,
            'batchid' => $batchid,
            'authorid' => $authorid,
            'author' => clean_param($author, PARAM_TEXT),
            'note' => clean_param($note, PARAM_RAW),
            'timecreated' => time(),
        ];

        if ($DB->get_manager()->table_exists(self::TABLE)) {
            return (int)$DB->insert_record(self::TABLE, $record);
        }
        return 0;
    }
}

