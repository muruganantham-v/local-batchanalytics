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
require_once(__DIR__ . '/classes/admin_setting_maac_columns.php');
require_once(__DIR__ . '/classes/admin_setting_maac_column_groups.php');
require_once(__DIR__ . '/classes/admin_setting_mentor_crm_field_groups.php');

global $DB;

$roleoptions = [0 => get_string('none')];
$roles = $DB->get_records('role', null, 'sortorder ASC, shortname ASC', 'id, shortname, name');
foreach ($roles as $role) {
    $label = trim($role->shortname . (!empty($role->name) ? ' - ' . $role->name : ''));
    $roleoptions[(int)$role->id] = $label !== '' ? $label : (string)$role->id;
}

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
        'local_batchanalytics/mentor_crm_data',
        get_string('mentor_crm_data', 'local_batchanalytics'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_batchanalytics/mentor_crm_module_api_name',
        get_string('mentor_crm_module_api_name', 'local_batchanalytics'),
        get_string('mentor_crm_module_api_name_desc', 'local_batchanalytics'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configtext(
        'local_batchanalytics/mentor_crm_batch_field_key',
        get_string('mentor_crm_batch_field_key', 'local_batchanalytics'),
        get_string('mentor_crm_batch_field_key_desc', 'local_batchanalytics'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new \local_batchanalytics\admin_setting_crm_fields(
        'local_batchanalytics/mentor_crm_fields_config',
        get_string('mentor_crm_fields_config', 'local_batchanalytics'),
        get_string('mentor_crm_fields_config_desc', 'local_batchanalytics'),
        []
    ));
    $settings->add(new \local_batchanalytics\admin_setting_mentor_crm_field_groups(
        'local_batchanalytics/mentor_crm_field_groups',
        get_string('mentor_crm_field_groups', 'local_batchanalytics'),
        get_string('mentor_crm_field_groups_desc', 'local_batchanalytics')
    ));

    $settings->add(new admin_setting_heading(
        'local_batchanalytics/maac_sheet_columns',
        get_string('maac_sheet_columns', 'local_batchanalytics'),
        ''
    ));
    $settings->add(new \local_batchanalytics\admin_setting_maac_columns(
        'local_batchanalytics/maac_custom_columns',
        get_string('maac_custom_columns', 'local_batchanalytics'),
        get_string('maac_custom_columns_desc', 'local_batchanalytics')
    ));
    $settings->add(new \local_batchanalytics\admin_setting_maac_column_groups(
        'local_batchanalytics/maac_column_groups',
        get_string('maac_column_groups', 'local_batchanalytics'),
        get_string('maac_column_groups_desc', 'local_batchanalytics')
    ));
    $settings->add(new admin_setting_configtext(
        'local_batchanalytics/trend_window',
        get_string('trend_window', 'local_batchanalytics'),
        get_string('trend_window_desc', 'local_batchanalytics'),
        '20',
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_batchanalytics/attendance_window',
        get_string('attendance_window', 'local_batchanalytics'),
        get_string('attendance_window_desc', 'local_batchanalytics'),
        '5',
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_batchanalytics/role_management',
        get_string('role_management', 'local_batchanalytics'),
        ''
    ));
    $settings->add(new admin_setting_configselect(
        'local_batchanalytics/ss_team_role',
        get_string('ss_team_role', 'local_batchanalytics'),
        get_string('ss_team_role_desc', 'local_batchanalytics'),
        0,
        $roleoptions
    ));
    $settings->add(new admin_setting_configselect(
        'local_batchanalytics/batch_manager_role',
        get_string('batch_manager_role', 'local_batchanalytics'),
        get_string('batch_manager_role_desc', 'local_batchanalytics'),
        0,
        $roleoptions
    ));

    $cliqtemplatelinks = html_writer::div(
        html_writer::link(
            new moodle_url('/local/batchanalytics/cliq_templates.php'),
            get_string('configure_cliq_templates', 'local_batchanalytics'),
            ['class' => 'btn btn-secondary']
        ) . ' ' . html_writer::link(
            new moodle_url('/local/batchanalytics/cliq_message_history.php'),
            get_string('view_cliq_message_history', 'local_batchanalytics'),
            ['class' => 'btn btn-secondary']
        ),
        'local-batchanalytics-cliq-template-actions'
    );
    $settings->add(new admin_setting_heading(
        'local_batchanalytics/cliq_configuration',
        get_string('cliq_configuration', 'local_batchanalytics'),
        $cliqtemplatelinks
    ));
    $settings->add(new admin_setting_configtext(
        'local_batchanalytics/cliq_bot_url',
        get_string('cliq_bot_url', 'local_batchanalytics'),
        get_string('cliq_bot_url_desc', 'local_batchanalytics'),
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_batchanalytics/cliq_bot_key',
        get_string('cliq_bot_key', 'local_batchanalytics'),
        get_string('cliq_bot_key_desc', 'local_batchanalytics'),
        ''
    ));

    $ADMIN->add('localplugins', $settings);
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_batchanalytics_cliq_templates',
        get_string('configure_cliq_templates', 'local_batchanalytics'),
        new moodle_url('/local/batchanalytics/cliq_templates.php'),
        'moodle/site:config',
        true
    ));
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_batchanalytics_cliq_message_history',
        get_string('view_cliq_message_history', 'local_batchanalytics'),
        new moodle_url('/local/batchanalytics/cliq_message_history.php'),
        'moodle/site:config',
        true
    ));
}
