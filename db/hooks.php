<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\navigation\primary_extend::class,
        'callback' => [\local_batchanalytics\hook_callbacks::class, 'extend_primary_navigation'],
        'priority' => 500,
    ],
];
