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
require_once(__DIR__ . '/classes/admin_setting_mentor_master_activities.php');
require_once(__DIR__ . '/classes/admin_setting_mentor_activity_grouping.php');
require_once(__DIR__ . '/classes/mentor_activity_service.php');

if ($hassiteconfig) {
    if (empty($settings)) {
        $settings = new admin_settingpage('blocksettingbatchanalytics', get_string('pluginname', 'block_batchanalytics'));
    }

    $settings->add(new admin_setting_heading(
        'block_batchanalytics/zoho_configuration',
        get_string('zoho_configuration', 'block_batchanalytics'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'block_batchanalytics/zoho_client_id',
        get_string('zoho_client_id', 'block_batchanalytics'),
        get_string('zoho_client_id_desc', 'block_batchanalytics'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'block_batchanalytics/zoho_client_secret',
        get_string('zoho_client_secret', 'block_batchanalytics'),
        get_string('zoho_client_secret_desc', 'block_batchanalytics'),
        ''
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'block_batchanalytics/zoho_refresh_token',
        get_string('zoho_refresh_token', 'block_batchanalytics'),
        get_string('zoho_refresh_token_desc', 'block_batchanalytics'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'block_batchanalytics/zoho_accounts_url',
        get_string('zoho_accounts_url', 'block_batchanalytics'),
        get_string('zoho_accounts_url_desc', 'block_batchanalytics'),
        'https://accounts.zoho.com',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configtext(
        'block_batchanalytics/zoho_api_base_url',
        get_string('zoho_api_base_url', 'block_batchanalytics'),
        get_string('zoho_api_base_url_desc', 'block_batchanalytics'),
        'https://www.zohoapis.com',
        PARAM_URL
    ));

    $settings->add(new admin_setting_heading(
        'block_batchanalytics/student_crm_data',
        get_string('student_crm_data', 'block_batchanalytics'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'block_batchanalytics/student_crm_module_api_name',
        get_string('student_crm_module_api_name', 'block_batchanalytics'),
        get_string('student_crm_module_api_name_desc', 'block_batchanalytics'),
        'Child_Admission',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new \block_batchanalytics\admin_setting_crm_fields(
        'block_batchanalytics/crm_fields_config',
        get_string('crm_fields_config', 'block_batchanalytics'),
        get_string('crm_fields_config_desc', 'block_batchanalytics')
    ));
    $settings->add(new admin_setting_heading(
        'block_batchanalytics/module_tracker_configuration',
        get_string('module_tracker_configuration', 'block_batchanalytics'),
        ''
    ));
    $settings->add(new \block_batchanalytics\admin_setting_module_tracker_categories(
        'block_batchanalytics/module_tracker_categories',
        get_string('module_tracker_categories', 'block_batchanalytics'),
        get_string('module_tracker_categories_desc', 'block_batchanalytics')
    ));

    $settings->add(new admin_setting_heading(
        'block_batchanalytics/mentor_activities_heading',
        get_string('mentor_activities_heading', 'block_batchanalytics'),
        get_string('mentor_activities_heading_desc', 'block_batchanalytics')
    ));

    $settings->add(new \block_batchanalytics\admin_setting_mentor_master_activities(
        'block_batchanalytics/mentor_master_activities',
        get_string('mentor_master_activities', 'block_batchanalytics'),
        get_string('mentor_master_activities_desc', 'block_batchanalytics')
    ));

    $settings->add(new \block_batchanalytics\admin_setting_mentor_activity_grouping(
        'block_batchanalytics/mentor_activity_grouping',
        get_string('mentor_activity_grouping', 'block_batchanalytics'),
        get_string('mentor_activity_grouping_desc', 'block_batchanalytics')
    ));

    // Role selection dropdown settings for operational task assignments
    $settings->add(new admin_setting_heading(
        'block_batchanalytics/role_configuration',
        get_string('role_configuration', 'block_batchanalytics'),
        get_string('role_configuration_desc', 'block_batchanalytics')
    ));

    $role_choices = ['0' => get_string('none')];
    $all_roles = $DB->get_records('role', null, 'sortorder ASC', 'id, name, shortname');
    foreach ($all_roles as $r) {
        $rname = !empty($r->name) ? format_string($r->name) : $r->shortname;
        $role_choices[(string)$r->id] = $rname . ' (' . $r->shortname . ')';
    }

    $settings->add(new admin_setting_configselect(
        'block_batchanalytics/mentor_roles',
        get_string('mentor_roles', 'block_batchanalytics'),
        get_string('mentor_roles_desc', 'block_batchanalytics'),
        '0',
        $role_choices
    ));

    $settings->add(new admin_setting_configselect(
        'block_batchanalytics/ssexecutive_roles',
        get_string('ssexecutive_roles', 'block_batchanalytics'),
        get_string('ssexecutive_roles_desc', 'block_batchanalytics'),
        '0',
        $role_choices
    ));

    $settings->add(new admin_setting_configselect(
        'block_batchanalytics/program_manager_roles',
        get_string('program_manager_roles', 'block_batchanalytics'),
        get_string('program_manager_roles_desc', 'block_batchanalytics'),
        '0',
        $role_choices
    ));

    $settings->add(new admin_setting_configselect(
        'block_batchanalytics/assistant_manager_roles',
        get_string('assistant_manager_roles', 'block_batchanalytics'),
        get_string('assistant_manager_roles_desc', 'block_batchanalytics'),
        '0',
        $role_choices
    ));

    if (!empty($ADMIN) && empty($ADMIN->locate('blocksettingbatchanalytics'))) {
        $ADMIN->add('blocksettings', $settings);
    }
}
