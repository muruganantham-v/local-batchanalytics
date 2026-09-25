<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/** Plugin event observers. */
final class observer {
    /** Remove course-specific data that has no meaning after course deletion. */
    public static function course_deleted(\core\event\course_deleted $event): void {
        // Course summary table has been removed.
    }
}
