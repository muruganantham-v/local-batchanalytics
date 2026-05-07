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
}
