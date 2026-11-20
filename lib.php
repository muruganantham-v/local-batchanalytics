<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library functions for local_batchanalytics
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add Batch Analytics link to Moodle's side navigation drawer.
 *
 * @param global_navigation $navigation
 */
function local_batchanalytics_extend_navigation(global_navigation $navigation) {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $context = context_system::instance();
    if (!is_siteadmin() && !has_capability('local/batchanalytics:view', $context)) {
        return;
    }

    if ($navigation->find('local_batchanalytics', navigation_node::TYPE_CUSTOM)) {
        return;
    }

    $node = navigation_node::create(
        get_string('pluginname', 'local_batchanalytics'),
        new moodle_url('/local/batchanalytics/index.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_batchanalytics',
        new pix_icon('i/report', '')
    );
    $node->showinflatnavigation = true;

    $misnode = navigation_node::create(
        'Program MIS (Marvell & ESSCI)',
        new moodle_url('/local/batchanalytics/marvel_essci_mis.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_batchanalytics_mis',
        new pix_icon('i/dashboard', '')
    );
    $misnode->showinflatnavigation = true;
    $node->add_node($misnode);

    $datanode = navigation_node::create(
        'ESSCI Data Page',
        new moodle_url('/local/batchanalytics/marvel_essci_data.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_batchanalytics_essci_data',
        new pix_icon('i/settings', '')
    );
    $datanode->showinflatnavigation = true;
    $node->add_node($datanode);

    $navigation->add_node($node);
}

/**
 * Serve files from the local_batchanalytics file areas.
 *
 * @param stdClass $course course settings object
 * @param stdClass $cm_or_context_module
 * @param context $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if the file not found, just send the file otherwise and do not return
 */
function local_batchanalytics_pluginfile($course, $cm_or_context_module, $context, $filearea, $args, $forcedownload, array $options = []) {
    if (!isloggedin() || isguestuser()) {
        return false;
    }

    if ($filearea !== 'class_evidence') {
        return false;
    }

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_batchanalytics', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}



