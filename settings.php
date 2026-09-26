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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/classes/admin_setting_crm_fields.php');
require_once(__DIR__ . '/classes/admin_setting_module_tracker_categories.php');

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_batchanalytics', get_string('pluginname', 'local_batchanalytics'));

    $settings->add(new admin_setting_heading(
        'local_batchanalytics/zoho_configuration',
        get_string('zoho_configuration', 'local_batchanalytics'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_batchanalytics/zoho_client_id',
        get_string('zoho_client_id', 'local_batchanalytics'),
        get_string('zoho_client_id_desc', 'local_batchanalytics'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_batchanalytics/zoho_client_secret',
        get_string('zoho_client_secret', 'local_batchanalytics'),
        get_string('zoho_client_secret_desc', 'local_batchanalytics'),
        ''
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_batchanalytics/zoho_refresh_token',
        get_string('zoho_refresh_token', 'local_batchanalytics'),
        get_string('zoho_refresh_token_desc', 'local_batchanalytics'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_batchanalytics/zoho_accounts_url',
        get_string('zoho_accounts_url', 'local_batchanalytics'),
        get_string('zoho_accounts_url_desc', 'local_batchanalytics'),
        'https://accounts.zoho.com',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configtext(
        'local_batchanalytics/zoho_api_base_url',
        get_string('zoho_api_base_url', 'local_batchanalytics'),
        get_string('zoho_api_base_url_desc', 'local_batchanalytics'),
        'https://www.zohoapis.com',
        PARAM_URL
    ));

    $settings->add(new admin_setting_heading(
        'local_batchanalytics/crm_field_configuration',
        get_string('crm_field_configuration', 'local_batchanalytics'),
        ''
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_batchanalytics/allowed_course_keywords',
        get_string('allowed_course_keywords', 'local_batchanalytics'),
        get_string('allowed_course_keywords_desc', 'local_batchanalytics'),
        'Advanced C,C++ Programming,Data Structures,Linux Internals,Linux Systems,Microcontroller',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_heading(
        'local_batchanalytics/student_crm_data',
        get_string('student_crm_data', 'local_batchanalytics'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_batchanalytics/student_crm_module_api_name',
        get_string('student_crm_module_api_name', 'local_batchanalytics'),
        get_string('student_crm_module_api_name_desc', 'local_batchanalytics'),
        'Child_Admission',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new \local_batchanalytics\admin_setting_crm_fields(
        'local_batchanalytics/crm_fields_config',
        get_string('crm_fields_config', 'local_batchanalytics'),
        get_string('crm_fields_config_desc', 'local_batchanalytics')
    ));
    $settings->add(new admin_setting_heading(
        'local_batchanalytics/module_tracker_configuration',
        get_string('module_tracker_configuration', 'local_batchanalytics'),
        ''
    ));
    $settings->add(new \local_batchanalytics\admin_setting_module_tracker_categories(
        'local_batchanalytics/module_tracker_categories',
        get_string('module_tracker_categories', 'local_batchanalytics'),
        get_string('module_tracker_categories_desc', 'local_batchanalytics')
    ));

    $ADMIN->add('localplugins', $settings);
}
