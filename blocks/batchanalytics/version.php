<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026100801;
$plugin->requires  = 2024042200;
$plugin->component = 'block_batchanalytics';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '2.2.14';
$plugin->dependencies = [
    'local_batchanalytics' => 2026092801,
];
