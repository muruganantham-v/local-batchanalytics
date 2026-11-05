<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\navigation\primary_extend::class,
        'callback' => [\local_batchanalytics\hook_callbacks::class, 'extend_primary_navigation'],
        'priority' => 500,
    ],
    [
        'hook' => \core_course\hook\after_form_definition::class,
        'callback' => [\local_batchanalytics\hook_callbacks::class, 'course_edit_form_definition'],
        'priority' => 500,
    ],
    [
        'hook' => \core_course\hook\after_form_submission::class,
        'callback' => [\local_batchanalytics\hook_callbacks::class, 'course_edit_form_submission'],
        'priority' => 500,
    ],
];

