<?php
define('CLI_SCRIPT', true);
require('/var/www/html/moodle/config.php');
global $DB;
echo "=== local_bm_classsection columns ===\n";
if ($DB->get_manager()->table_exists('local_bm_classsection')) {
    print_r(array_keys($DB->get_columns('local_bm_classsection')));
    echo "\nSample classsection record:\n";
    $first = $DB->get_records('local_bm_classsection', null, 'id ASC', '*', 0, 1);
    print_r($first);
} else {
    echo "Table local_bm_classsection does not exist.\n";
}

echo "\n=== local_bm_batch columns ===\n";
if ($DB->get_manager()->table_exists('local_bm_batch')) {
    print_r(array_keys($DB->get_columns('local_bm_batch')));
    echo "\nSample batch record:\n";
    $first_b = $DB->get_records('local_bm_batch', null, 'id ASC', '*', 0, 1);
    print_r($first_b);
} else {
    echo "Table local_bm_batch does not exist.\n";
}
