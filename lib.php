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
 * Course form definition callback (legacy fallback).
 *
 * @param moodleform $mform
 * @param stdClass $course
 */
function local_batchanalytics_courseform_definition($mform, $course) {
    if ($mform->elementExists('local_batchanalytics_mentor_hdr')) {
        return;
    }

    $courseid = !empty($course->id) ? (int)$course->id : 0;
    $mform->addElement('header', 'local_batchanalytics_mentor_hdr', get_string('mentor_activity_grouping_hdr', 'local_batchanalytics'));
    $mform->setExpanded('local_batchanalytics_mentor_hdr', false);

    $options = \local_batchanalytics\mentor_activity_service::get_available_group_options();
    $mform->addElement(
        'select',
        'local_batchanalytics_mentor_group',
        get_string('mentor_activity_group_select', 'local_batchanalytics'),
        $options
    );
    $mform->setType('local_batchanalytics_mentor_group', PARAM_TEXT);
    $mform->addHelpButton('local_batchanalytics_mentor_group', 'mentor_activity_group_select', 'local_batchanalytics');

    if ($courseid > 0) {
        $selected = \local_batchanalytics\mentor_activity_service::get_course_selected_group($courseid);
        $mform->setDefault('local_batchanalytics_mentor_group', $selected);
    }
}

/**
 * Course form submitted data callback (legacy fallback).
 *
 * @param stdClass $course
 * @param stdClass $data
 */
function local_batchanalytics_courseform_submited_data($course, $data) {
    $courseid = !empty($course->id) ? (int)$course->id : (!empty($data->id) ? (int)$data->id : 0);
    if ($courseid > 0 && isset($data->local_batchanalytics_mentor_group)) {
        \local_batchanalytics\mentor_activity_service::save_course_selected_group(
            $courseid,
            trim((string)$data->local_batchanalytics_mentor_group)
        );
    }
}


