<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_batchanalytics;

use advanced_testcase;

/**
 * Unit tests for task_service and authorization guards.
 *
 * @package    local_batchanalytics
 * @category   test
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_batchanalytics
 */
class task_service_test extends advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * Test that require_section_permission allows an assigned Program Manager.
     */
    public function test_require_section_permission_allows_assigned_pm(): void {
        $user1 = $this->getDataGenerator()->create_user();

        $section = (object)[
            'id' => 101,
            'pmmanager' => (string)$user1->id,
            'maacexecutive' => '0',
            'moduledata' => json_encode([]),
        ];

        $passed = false;
        try {
            task_service::require_section_permission((int)$user1->id, $section, 'am_sched');
            $passed = true;
        } catch (\moodle_exception $e) {
            $passed = false;
        }

        $this->assertTrue($passed, 'Assigned Program Manager should have permission to modify module schedule.');
    }

    /**
     * Test that require_section_permission blocks an unassigned user.
     */
    public function test_require_section_permission_blocks_unassigned_user(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $section = (object)[
            'id' => 101,
            'pmmanager' => (string)$user1->id,
            'maacexecutive' => '0',
            'moduledata' => json_encode([]),
        ];

        $this->expectException(\moodle_exception::class);
        task_service::require_section_permission((int)$user2->id, $section, 'am_sched');
    }

    /**
     * Test that direct soft skills completion by non-SS Lead is blocked.
     */
    public function test_require_section_permission_blocks_direct_ss_without_lead(): void {
        $user1 = $this->getDataGenerator()->create_user();

        $section = (object)[
            'id' => 102,
            'pmmanager' => (string)$user1->id,
            'maacexecutive' => '0',
            'moduledata' => json_encode([]),
        ];

        $this->expectException(\moodle_exception::class);
        task_service::require_section_permission((int)$user1->id, $section, 'ss');
    }

    /**
     * Test CRM field filtering strips restricted and derived fields (Placement_Company, CTC).
     */
    public function test_crm_field_filtering_strips_derived_company(): void {
        $data = [
            'Name' => 'Test Learner',
            'Placement_Company' => 'Acme Corp',
            'placed_company' => 'Acme Corp',
            'CTC' => '8.5 LPA',
            'Coach_Rating' => '4.5',
            '_debug_api_url' => 'https://crm.zoho.com/api',
        ];

        $restricted_keys = ['Placement_Company', 'CTC'];
        $filtered = crm_fields_helper::filter_crm_fields($data, $restricted_keys);

        $this->assertArrayNotHasKey('Placement_Company', $filtered);
        $this->assertArrayNotHasKey('placed_company', $filtered);
        $this->assertArrayNotHasKey('CTC', $filtered);
        $this->assertArrayNotHasKey('_debug_api_url', $filtered);
        $this->assertArrayHasKey('Coach_Rating', $filtered);
        $this->assertEquals('4.5', $filtered['Coach_Rating']);
        $this->assertEquals('Test Learner', $filtered['Name']);
    }

    /**
     * Test that CRM field filtering preserves allowed fields when restricted list is empty.
     */
    public function test_crm_field_filtering_preserves_allowed_fields(): void {
        $data = [
            'Name' => 'Test Learner',
            'Coach_Rating' => '4.8',
        ];

        $filtered = crm_fields_helper::filter_crm_fields($data, []);
        $this->assertEquals('Test Learner', $filtered['Name']);
        $this->assertEquals('4.8', $filtered['Coach_Rating']);
    }
}
