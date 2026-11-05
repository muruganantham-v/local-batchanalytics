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

namespace local_batchanalytics;

defined('MOODLE_INTERNAL') || die();

global $CFG;
if (!empty($CFG) && !empty($CFG->libdir)) {
    require_once($CFG->libdir . '/adminlib.php');
}

/**
 * Admin setting for comma-separated email addresses.
 *
 * Validates each individual email address using Moodle's validate_email()
 * and normalizes the stored value to a clean comma-separated list of valid emails.
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_configcommaemails extends \admin_setting_configtext {

    /**
     * Constructor.
     *
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param string $defaultsetting
     * @param int|null $size
     */
    public function __construct($name, $visiblename, $description, $defaultsetting = '', $size = null) {
        $paramtype = defined('PARAM_RAW_TRIMMED') ? PARAM_RAW_TRIMMED : 'raw_trimmed';
        parent::__construct($name, $visiblename, $description, $defaultsetting, $paramtype, $size);
    }

    /**
     * Validate comma-separated email addresses.
     *
     * @param string $data
     * @return true|string True if all provided emails are valid, or error message string.
     */
    public function validate($data) {
        $data = trim((string)$data);
        if ($data === '') {
            return true;
        }

        $parts = array_map('trim', explode(',', $data));
        foreach ($parts as $email) {
            if ($email === '') {
                continue;
            }
            if (!validate_email($email)) {
                return get_string('validateerror', 'admin');
            }
        }

        return true;
    }

    /**
     * Clean and save comma-separated email addresses.
     *
     * @param string $data
     * @return string Empty string on success, or error string.
     */
    public function write_setting($data) {
        $data = trim((string)$data);
        if ($data === '') {
            return ($this->config_write($this->name, '') ? '' : get_string('errorsetting', 'admin'));
        }

        $validated = $this->validate($data);
        if ($validated !== true) {
            return $validated;
        }

        $parts = array_map('trim', explode(',', $data));
        $valid_emails = [];
        $email_param = defined('PARAM_EMAIL') ? PARAM_EMAIL : 'email';
        foreach ($parts as $email) {
            if ($email !== '' && validate_email($email)) {
                $valid_emails[] = clean_param($email, $email_param);
            }
        }

        $cleaned_value = implode(',', $valid_emails);
        return ($this->config_write($this->name, $cleaned_value) ? '' : get_string('errorsetting', 'admin'));
    }
}
