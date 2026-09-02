<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\\core\\event\\course_deleted',
        'callback' => '\\local_batchanalytics\\observer::course_deleted',
    ],
];
