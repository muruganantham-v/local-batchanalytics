<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_batchanalytics\privacy;

use advanced_testcase;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Unit tests for Privacy API provider in local_batchanalytics.
 *
 * @package    local_batchanalytics
 * @category   test
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_batchanalytics
 * @coversDefaultClass \local_batchanalytics\privacy\provider
 */
class privacy_provider_test extends advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * Test that metadata collection declares all database tables and items.
     */
    public function test_get_metadata(): void {
        $collection = new collection('local_batchanalytics');
        $result = provider::get_metadata($collection);

        $this->assertInstanceOf(collection::class, $result);

        $items = $result->get_collection();
        $this->assertCount(3, $items);

        $table_names = array_map(function($item) {
            return $item->get_name();
        }, $items);

        $this->assertContains('local_batchanalytics_batch_notes', $table_names);
        $this->assertContains('local_batchanalytics_activity_tracker', $table_names);
        $this->assertContains('local_batchanalytics_cliq_log', $table_names);
    }

    /**
     * Test export_user_data for a user with recorded batch review notes.
     */
    public function test_export_and_delete_user_data(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();

        // Insert a mock note
        $noteid = $DB->insert_record('local_batchanalytics_batch_notes', (object)[
            'batchid' => 999,
            'userid' => $user->id,
            'author' => 'Test User',
            'body' => 'Batch progressing smoothly.',
            'timecreated' => time(),
        ]);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertContains((int)$systemcontext->id, $contextlist->get_contextids());

        $approvedlist = new approved_contextlist($user, 'local_batchanalytics', [$systemcontext->id]);
        provider::export_user_data($approvedlist);

        $writer = writer::with_context($systemcontext);
        $this->assertTrue($writer->has_any_data());

        // Test delete_data_for_user
        provider::delete_data_for_user($approvedlist);
        $this->assertFalse($DB->record_exists('local_batchanalytics_batch_notes', ['id' => $noteid]));
    }
}
