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



if ($hassiteconfig) {
    // Backward compatibility: If accessed directly via admin/settings.php?section=local_batchanalytics,
    // redirect to the admin category landing page to avoid 'cannotfindadminpage' error.
    if (optional_param('section', '', PARAM_SAFEDIR) === 'local_batchanalytics' &&
        $PAGE->has_set_url() && str_contains($PAGE->url->get_path(), '/admin/settings.php')) {
        redirect(new moodle_url('/admin/category.php', ['category' => 'local_batchanalytics']));
    }

    // -------------------------------------------------------------------------
    // Main Settings Category: Batch Analytics (Landing Page with 4 links)
    // -------------------------------------------------------------------------
    $ADMIN->add('localplugins', new admin_category('local_batchanalytics', get_string('pluginname', 'local_batchanalytics')));

    // =========================================================================
    // 4 Clean Navigation Links Shown on the Category Landing Page
    // =========================================================================
    $ADMIN->add('local_batchanalytics', new admin_externalpage(
        'local_batchanalytics_zoho_link',
        get_string('settings_zoho_credential_config', 'local_batchanalytics'),
        new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics_zoho'])
    ));

    $ADMIN->add('local_batchanalytics', new admin_externalpage(
        'local_batchanalytics_crm_link',
        get_string('settings_crm_field_configuration', 'local_batchanalytics'),
        new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics_crm'])
    ));

    $ADMIN->add('local_batchanalytics', new admin_externalpage(
        'local_batchanalytics_mentor_link',
        get_string('settings_mentor_activity_config', 'local_batchanalytics'),
        new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics_mentor'])
    ));

    $ADMIN->add('local_batchanalytics', new admin_externalpage(
        'local_batchanalytics_cliq_templates',
        get_string('settings_zoho_cliq_template_config', 'local_batchanalytics'),
        new moodle_url('/local/batchanalytics/cliq_templates.php')
    ));

    $ADMIN->add('local_batchanalytics', new admin_externalpage(
        'local_batchanalytics_tracker_link',
        get_string('module_tracker_configuration', 'local_batchanalytics'),
        new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics_tracker'])
    ));

    $ADMIN->add('local_batchanalytics', new admin_externalpage(
        'local_batchanalytics_roles_link',
        get_string('role_configuration', 'local_batchanalytics'),
        new moodle_url('/admin/settings.php', ['section' => 'local_batchanalytics_roles'])
    ));

    // =========================================================================
    // Page 1: Zoho Credential Config (Dedicated Form Page)
    // Includes: Zoho Configuration, Zoho Cliq Notifications configuration
    // =========================================================================
    $page_zoho = new admin_settingpage(
        'local_batchanalytics_zoho',
        get_string('settings_zoho_credential_config', 'local_batchanalytics'),
        'moodle/site:config',
        true // Hidden from category overview
    );

    // Section 1.1: Zoho Configuration
    $page_zoho->add(new admin_setting_heading(
        'local_batchanalytics/zoho_configuration',
        get_string('zoho_configuration', 'local_batchanalytics'),
        ''
    ));
    $page_zoho->add(new admin_setting_configtext(
        'local_batchanalytics/zoho_client_id',
        get_string('zoho_client_id', 'local_batchanalytics'),
        get_string('zoho_client_id_desc', 'local_batchanalytics'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $page_zoho->add(new admin_setting_configpasswordunmask(
        'local_batchanalytics/zoho_client_secret',
        get_string('zoho_client_secret', 'local_batchanalytics'),
        get_string('zoho_client_secret_desc', 'local_batchanalytics'),
        ''
    ));
    $page_zoho->add(new admin_setting_configpasswordunmask(
        'local_batchanalytics/zoho_refresh_token',
        get_string('zoho_refresh_token', 'local_batchanalytics'),
        get_string('zoho_refresh_token_desc', 'local_batchanalytics'),
        ''
    ));
    $page_zoho->add(new admin_setting_configtext(
        'local_batchanalytics/zoho_accounts_url',
        get_string('zoho_accounts_url', 'local_batchanalytics'),
        get_string('zoho_accounts_url_desc', 'local_batchanalytics'),
        'https://accounts.zoho.com',
        PARAM_URL
    ));
    $page_zoho->add(new admin_setting_configtext(
        'local_batchanalytics/zoho_api_base_url',
        get_string('zoho_api_base_url', 'local_batchanalytics'),
        get_string('zoho_api_base_url_desc', 'local_batchanalytics'),
        'https://www.zohoapis.com',
        PARAM_URL
    ));

    // Section 1.2: Zoho Cliq Notifications Configuration
    $page_zoho->add(new admin_setting_heading(
        'local_batchanalytics/zoho_cliq_heading',
        get_string('zoho_cliq_heading', 'local_batchanalytics'),
        get_string('zoho_cliq_heading_desc', 'local_batchanalytics')
    ));
    $page_zoho->add(new admin_setting_configcheckbox(
        'local_batchanalytics/zoho_cliq_enabled',
        get_string('zoho_cliq_enabled', 'local_batchanalytics'),
        get_string('zoho_cliq_enabled_desc', 'local_batchanalytics'),
        '0'
    ));
    $page_zoho->add(new admin_setting_configtext(
        'local_batchanalytics/zoho_cliq_bot_url',
        get_string('zoho_cliq_bot_url', 'local_batchanalytics'),
        get_string('zoho_cliq_bot_url_desc', 'local_batchanalytics'),
        'https://cliq.zoho.com/api/v2/bots/batchinformer/message',
        PARAM_URL
    ));
    $page_zoho->add(new admin_setting_configpasswordunmask(
        'local_batchanalytics/zoho_cliq_zapikey',
        get_string('zoho_cliq_zapikey', 'local_batchanalytics'),
        get_string('zoho_cliq_zapikey_desc', 'local_batchanalytics'),
        ''
    ));
    $page_zoho->add(new admin_setting_configtext(
        'local_batchanalytics/zoho_cliq_default_channel',
        get_string('zoho_cliq_default_channel', 'local_batchanalytics'),
        get_string('zoho_cliq_default_channel_desc', 'local_batchanalytics'),
        '',
        PARAM_ALPHANUMEXT
    ));

    // Link banner to Zoho Cliq templates
    $manage_templates_url = new moodle_url('/local/batchanalytics/cliq_templates.php');
    $templates_banner_html = '<div class="alert alert-info d-flex align-items-center justify-content-between p-3" style="border-radius: 8px; margin-top: 10px;">'
        . '<div>'
        . '<strong style="font-size: 14px;"><i class="fa fa-envelope-open-text"></i> ' . get_string('zoho_cliq_manage_templates', 'local_batchanalytics') . '</strong><br>'
        . '<span class="text-muted small">' . get_string('zoho_cliq_manage_templates_desc', 'local_batchanalytics') . '</span>'
        . '</div>'
        . '<div class="ml-3">'
        . '<a href="' . $manage_templates_url->out() . '" class="btn btn-primary" style="white-space: nowrap; font-weight: 600;">'
        . '<i class="fa fa-external-link"></i> ' . get_string('zoho_cliq_open_templates_page', 'local_batchanalytics')
        . '</a>'
        . '</div>'
        . '</div>';
    $page_zoho->add(new admin_setting_heading(
        'local_batchanalytics/zoho_cliq_templates_link',
        '',
        $templates_banner_html
    ));

    $ADMIN->add('local_batchanalytics', $page_zoho);

    // =========================================================================
    // Page 2: CRM Field configuration (Dedicated Form Page)
    // Includes: Student CRM Data only
    // =========================================================================
    $page_crm = new admin_settingpage(
        'local_batchanalytics_crm',
        get_string('settings_crm_field_configuration', 'local_batchanalytics'),
        'moodle/site:config',
        true // Hidden from category overview
    );

    // Section 2.1: Student CRM Data
    $page_crm->add(new admin_setting_heading(
        'local_batchanalytics/student_crm_data',
        get_string('student_crm_data', 'local_batchanalytics'),
        ''
    ));
    $page_crm->add(new admin_setting_configtext(
        'local_batchanalytics/student_crm_module_api_name',
        get_string('student_crm_module_api_name', 'local_batchanalytics'),
        get_string('student_crm_module_api_name_desc', 'local_batchanalytics'),
        'Child_Admission',
        PARAM_RAW_TRIMMED
    ));
    $page_crm->add(new \local_batchanalytics\admin_setting_crm_fields(
        'local_batchanalytics/crm_fields_config',
        get_string('crm_fields_config', 'local_batchanalytics'),
        get_string('crm_fields_config_desc', 'local_batchanalytics')
    ));

    $ADMIN->add('local_batchanalytics', $page_crm);

    // =========================================================================
    // Page 3: Mentor Activity Config (Dedicated Form Page)
    // Includes: Mentor Activities Configuration only
    // =========================================================================
    $page_mentor = new admin_settingpage(
        'local_batchanalytics_mentor',
        get_string('settings_mentor_activity_config', 'local_batchanalytics'),
        'moodle/site:config',
        true // Hidden from category overview
    );

    // Section 3.1: Mentor Activities Configuration
    $page_mentor->add(new admin_setting_heading(
        'local_batchanalytics/mentor_activities_heading',
        get_string('mentor_activities_heading', 'local_batchanalytics'),
        get_string('mentor_activities_heading_desc', 'local_batchanalytics')
    ));
    $page_mentor->add(new \local_batchanalytics\admin_setting_mentor_master_activities(
        'local_batchanalytics/mentor_master_activities',
        get_string('mentor_master_activities', 'local_batchanalytics'),
        get_string('mentor_master_activities_desc', 'local_batchanalytics')
    ));
    $page_mentor->add(new \local_batchanalytics\admin_setting_mentor_activity_grouping(
        'local_batchanalytics/mentor_activity_grouping',
        get_string('mentor_activity_grouping', 'local_batchanalytics'),
        get_string('mentor_activity_grouping_desc', 'local_batchanalytics')
    ));

    $ADMIN->add('local_batchanalytics', $page_mentor);

    // =========================================================================
    // Page 5: Module Tracker Categories (Dedicated Form Page)
    // =========================================================================
    $page_tracker = new admin_settingpage(
        'local_batchanalytics_tracker',
        get_string('module_tracker_configuration', 'local_batchanalytics'),
        'moodle/site:config',
        true // Hidden from category overview so form fields do not dump on landing page
    );
    $page_tracker->add(new admin_setting_heading(
        'local_batchanalytics/module_tracker_configuration',
        get_string('module_tracker_configuration', 'local_batchanalytics'),
        ''
    ));
    $page_tracker->add(new \local_batchanalytics\admin_setting_module_tracker_categories(
        'local_batchanalytics/module_tracker_categories',
        get_string('module_tracker_categories', 'local_batchanalytics'),
        get_string('module_tracker_categories_desc', 'local_batchanalytics')
    ));
    $ADMIN->add('local_batchanalytics', $page_tracker);

    // =========================================================================
    // Page 6: Operational Roles & Task Assignments (Dedicated Form Page)
    // =========================================================================
    $page_roles = new admin_settingpage(
        'local_batchanalytics_roles',
        get_string('role_configuration', 'local_batchanalytics'),
        'moodle/site:config',
        true // Hidden from category overview
    );
    $page_roles->add(new admin_setting_heading(
        'local_batchanalytics/role_configuration',
        get_string('role_configuration', 'local_batchanalytics'),
        get_string('role_configuration_desc', 'local_batchanalytics')
    ));

    $role_choices = ['0' => get_string('none')];
    $all_roles = $DB->get_records('role', null, 'sortorder ASC', 'id, name, shortname');
    foreach ($all_roles as $r) {
        $rname = !empty($r->name) ? format_string($r->name) : $r->shortname;
        $role_choices[(string)$r->id] = $rname . ' (' . $r->shortname . ')';
    }

    $page_roles->add(new admin_setting_configselect(
        'local_batchanalytics/mentor_roles',
        get_string('mentor_roles', 'local_batchanalytics'),
        get_string('mentor_roles_desc', 'local_batchanalytics'),
        '0',
        $role_choices
    ));
    $page_roles->add(new admin_setting_configselect(
        'local_batchanalytics/ssexecutive_roles',
        get_string('ssexecutive_roles', 'local_batchanalytics'),
        get_string('ssexecutive_roles_desc', 'local_batchanalytics'),
        '0',
        $role_choices
    ));
    $page_roles->add(new admin_setting_configselect(
        'local_batchanalytics/sslead_roles',
        get_string('sslead_roles', 'local_batchanalytics'),
        get_string('sslead_roles_desc', 'local_batchanalytics'),
        '0',
        $role_choices
    ));
    $page_roles->add(new admin_setting_configtext(
        'local_batchanalytics/sslead_email',
        get_string('sslead_email', 'local_batchanalytics'),
        get_string('sslead_email_desc', 'local_batchanalytics'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $page_roles->add(new admin_setting_configselect(
        'local_batchanalytics/program_manager_roles',
        get_string('program_manager_roles', 'local_batchanalytics'),
        get_string('program_manager_roles_desc', 'local_batchanalytics'),
        '0',
        $role_choices
    ));
    $page_roles->add(new admin_setting_configselect(
        'local_batchanalytics/assistant_manager_roles',
        get_string('assistant_manager_roles', 'local_batchanalytics'),
        get_string('assistant_manager_roles_desc', 'local_batchanalytics'),
        '0',
        $role_choices
    ));
    $ADMIN->add('local_batchanalytics', $page_roles);
}
