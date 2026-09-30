<?php
namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for navigation integration.
 */
class hook_callbacks {
    /**
     * Add Batch Analytics into primary navigation (Moodle 4.5+ hook).
     *
     * @param \core\hook\navigation\primary_extend $hook
     * @return void
     */
    public static function extend_primary_navigation(\core\hook\navigation\primary_extend $hook): void {
        if (!isloggedin() || isguestuser()) {
            return;
        }

        $context = \context_system::instance();
        if (!is_siteadmin() && !has_capability('local/batchanalytics:view', $context)) {
            return;
        }

        $primary = $hook->get_primaryview();
        if ($primary->find('local_batchanalytics_primary', \navigation_node::TYPE_CUSTOM)) {
            return;
        }

        $primary->add(
            get_string('pluginname', 'local_batchanalytics'),
            new \moodle_url('/local/batchanalytics/index.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_batchanalytics_primary',
            new \pix_icon('i/report', '')
        );
    }

    /**
     * Extend course edit form with a Mentor Activity section and group selector.
     *
     * @param \core_course\hook\after_form_definition $hook
     * @return void
     */
    public static function course_edit_form_definition(\core_course\hook\after_form_definition $hook): void {
        $mform = $hook->mform;
        $course = $hook->formwrapper->get_course();
        $courseid = !empty($course->id) ? (int)$course->id : 0;

        $mform->addElement('header', 'local_batchanalytics_mentor_hdr', get_string('mentor_activity_grouping_hdr', 'local_batchanalytics'));
        $mform->setExpanded('local_batchanalytics_mentor_hdr', false);

        $options = mentor_activity_service::get_available_group_options();
        $mform->addElement(
            'select',
            'local_batchanalytics_mentor_group',
            get_string('mentor_activity_group_select', 'local_batchanalytics'),
            $options
        );
        $mform->setType('local_batchanalytics_mentor_group', PARAM_TEXT);
        $mform->addHelpButton('local_batchanalytics_mentor_group', 'mentor_activity_group_select', 'local_batchanalytics');

        if ($courseid > 0) {
            $selected = mentor_activity_service::get_course_selected_group($courseid);
            $mform->setDefault('local_batchanalytics_mentor_group', $selected);
        }
    }

    /**
     * Handle saving of the course edit form for Mentor Activity group selection.
     *
     * @param \core_course\hook\after_form_submission $hook
     * @return void
     */
    public static function course_edit_form_submission(\core_course\hook\after_form_submission $hook): void {
        $data = $hook->get_data();
        $courseid = !empty($data->id) ? (int)$data->id : 0;
        if ($courseid > 0 && isset($data->local_batchanalytics_mentor_group)) {
            mentor_activity_service::save_course_selected_group(
                $courseid,
                trim((string)$data->local_batchanalytics_mentor_group)
            );
        }
    }
}

