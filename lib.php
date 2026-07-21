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
    $navigation->add_node($node);
}

/**
 * Add MAAC sheet link to the current course navigation.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_batchanalytics_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context) {
    if (!isloggedin() || isguestuser() || empty($course->id) || (int)$course->id === SITEID) {
        return;
    }

    $systemcontext = context_system::instance();
    $canmanage = is_siteadmin() || has_capability('local/batchanalytics:manage', $systemcontext);
    $canviewmaac = has_capability('local/batchanalytics:viewmaac', $context)
        || has_capability('local/batchanalytics:editmaac', $context);
    if (!$canmanage && !$canviewmaac) {
        return;
    }

    if ($navigation->find('local_batchanalytics_maac_sheet', navigation_node::TYPE_CUSTOM)) {
        return;
    }

    $node = navigation_node::create(
        'MAAC sheet',
        new moodle_url('/local/batchanalytics/maac.php', ['courseid' => $course->id]),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_batchanalytics_maac_sheet',
        new pix_icon('i/report', '')
    );
    $node->showinflatnavigation = true;
    $node->linkattributes = ['target' => '_blank', 'rel' => 'noopener'];
    $navigation->add_node($node);
}
