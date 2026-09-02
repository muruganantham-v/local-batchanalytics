<?php
namespace local_batchanalytics\task;
defined('MOODLE_INTERNAL') || die();
class prune_cliq_history extends \core\task\scheduled_task {
    public function get_name(): string { return get_string('task_prune_cliq_history', 'local_batchanalytics'); }
    public function execute(): void {
        global $DB;
        $days = (int)get_config('local_batchanalytics', 'cliq_history_retention_days');
        if ($days <= 0 || !$DB->get_manager()->table_exists(new \xmldb_table('local_batchanalytics_cliq_history'))) { return; }
        $cutoff = time() - ($days * DAYSECS);
        $deleted = 0;
        for ($batch = 0; $batch < 10; $batch++) {
            $records = $DB->get_records_select('local_batchanalytics_cliq_history', 'timecreated < :cutoff', ['cutoff' => $cutoff], 'timecreated ASC, id ASC', 'id', 0, 500);
            if (empty($records)) { break; }
            $DB->delete_records_list('local_batchanalytics_cliq_history', 'id', array_keys($records));
            $deleted += count($records);
        }
        mtrace('Pruned Cliq message history records: ' . $deleted);
    }
}
