<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Redirect shim for block_batchanalytics to the canonical local_batchanalytics dashboard.
 *
 * @package    block_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Resolve Moodle config.php whether deployed at /blocks/batchanalytics or inside /local/batchanalytics/blocks/batchanalytics
if (file_exists(__DIR__ . '/../../config.php')) {
    require_once(__DIR__ . '/../../config.php');
} else if (file_exists(__DIR__ . '/../../../../config.php')) {
    require_once(__DIR__ . '/../../../../config.php');
} else {
    require_once(dirname(dirname(dirname(dirname(__DIR__)))) . '/config.php');
}

$localindex = $CFG->dirroot . '/local/batchanalytics/index.php';

// If POST request or AJAX, include canonical local dashboard directly to preserve payload
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    if (file_exists($localindex)) {
        require_once($localindex);
        exit;
    }
}

// Otherwise redirect GET requests cleanly to the canonical local plugin URL
$params = !empty($_GET) ? $_GET : [];
redirect(new moodle_url('/local/batchanalytics/index.php', $params));

