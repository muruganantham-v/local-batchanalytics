<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/** Store course summaries independently from plugin settings. */
final class course_summary_service {
    /** Return summaries indexed by course ID. */
    public static function get_for_courses(array $courseids): array {
        global $DB;

        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids))));
        if (empty($courseids)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'summarycourse');
        $records = $DB->get_records_select(
            'local_batchanalytics_course_summary',
            'courseid ' . $insql,
            $params,
            '',
            'id, courseid, summary'
        );

        $summaries = [];
        foreach ($records as $record) {
            $summaries[(int)$record->courseid] = (string)$record->summary;
        }

        return $summaries;
    }

    /** Save a summary or remove the record when the summary is empty. */
    public static function save(int $courseid, string $summary): void {
        global $DB;

        $summary = trim($summary);
        $existing = $DB->get_record(
            'local_batchanalytics_course_summary',
            ['courseid' => $courseid],
            'id',
            IGNORE_MISSING
        );

        if ($summary === '') {
            if ($existing) {
                $DB->delete_records('local_batchanalytics_course_summary', ['id' => $existing->id]);
            }
            return;
        }

        $record = (object)[
            'courseid' => $courseid,
            'summary' => $summary,
            'timemodified' => time(),
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_batchanalytics_course_summary', $record);
        } else {
            $DB->insert_record('local_batchanalytics_course_summary', $record);
        }
    }

    /** Remove a deleted course's summary. */
    public static function delete_for_course(int $courseid): void {
        global $DB;
        $DB->delete_records('local_batchanalytics_course_summary', ['courseid' => $courseid]);
    }
}
