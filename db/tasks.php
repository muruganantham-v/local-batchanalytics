<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_batchanalytics\task\evaluate_activity_due_task',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '9',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '1-5',
    ],
];
