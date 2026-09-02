<?php
if (!empty($_REQUEST['action'])) {
    ob_start();
}
require_once(__DIR__ . '/../../config.php');

require_login();
$systemcontext = context_system::instance();
require_capability('local/batchanalytics:manage', $systemcontext);
if (!(int)get_config('local_batchanalytics', 'import_maac_sheet')) {
    throw new moodle_exception('nopermissions', 'error', '', 'MAAC import is disabled');
}

function local_batchanalytics_import_courses(string $raw): array {
    global $DB;
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
    if (empty($ids)) {
        return [];
    }
    return array_values($DB->get_records_list('course', 'id', $ids, 'fullname ASC', 'id, fullname, shortname'));
}

function local_batchanalytics_read_import_file(string $path, string $extension): array {
    if ($extension === 'csv') {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new moodle_exception('cannotreadfile');
        }
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return ['CSV' => $rows];
    }
    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        throw new moodle_exception('generalexceptionmessage', 'error', '', 'XLSX reader is not available on this Moodle server.');
    }
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheets = [];
    foreach ($book->getWorksheetIterator() as $sheet) {
        $sheets[$sheet->getTitle()] = $sheet->toArray('', true, true, false);
    }
    return $sheets;
}

function local_batchanalytics_sheet_data(array $rows): array {
    $headers = array_map(static fn($value): string => trim((string)$value), array_shift($rows) ?? []);
    $headers = array_values($headers);
    $data = [];
    foreach ($rows as $row) {
        $record = [];
        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $record[$header] = trim((string)($row[$index] ?? ''));
            }
        }
        if (!empty(array_filter($record, static fn($value): bool => $value !== ''))) {
            $data[] = $record;
        }
    }
    return ['headers' => $headers, 'rows' => $data];
}

$courses = local_batchanalytics_import_courses(optional_param('courses', '', PARAM_RAW_TRIMMED));
$action = optional_param('action', '', PARAM_ALPHA);
if ($action !== '') {
    require_sesskey();
    $sendjson = static function(array $data, int $status = 200): void {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data);
        die();
    };
    try {
        global $SESSION, $DB;
        if ($action === 'analyze') {
            if (empty($_FILES['sheet']['tmp_name']) || (int)($_FILES['sheet']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new moodle_exception('required', 'error', '', 'Excel or CSV file');
            }
            if ((int)($_FILES['sheet']['size'] ?? 0) > 10 * 1024 * 1024) {
                throw new moodle_exception('generalexceptionmessage', 'error', '', 'The import file must be 10 MB or smaller.');
            }
            $name = (string)($_FILES['sheet']['name'] ?? '');
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, ['xlsx', 'csv'], true)) {
                throw new moodle_exception('generalexceptionmessage', 'error', '', 'Only XLSX and CSV files are supported.');
            }
            $sheets = local_batchanalytics_read_import_file($_FILES['sheet']['tmp_name'], $extension);
            $parsed = [];
            foreach ($sheets as $name => $rows) {
                $parsed[$name] = local_batchanalytics_sheet_data($rows);
            }
            $SESSION->local_batchanalytics_maac_import = ['sheets' => $parsed, 'time' => time()];
            $sendjson(['status' => 'ok', 'sheets' => array_map(static fn($sheet) => ['headers' => $sheet['headers'], 'rows' => count($sheet['rows'])], $parsed)]);
        }
        if (!in_array($action, ['preview', 'import'], true)) {
            throw new moodle_exception('invalidrequest');
        }
        $payload = json_decode(optional_param('payload', '', PARAM_RAW), true);
        $source = $SESSION->local_batchanalytics_maac_import['sheets'] ?? [];
        if (!is_array($payload) || empty($source)) {
            throw new moodle_exception('generalexceptionmessage', 'error', '', 'Analyze a file before importing.');
        }
        $columns = array_values(array_filter(\local_batchanalytics\maac_columns_helper::get_columns(), static fn($column) => empty($column['system']) && ($column['type'] ?? '') !== 'formula'));
        $columnmap = array_column($columns, null, 'key');
        $plans = [];
        $skipped = [];
        foreach ($courses as $course) {
            $map = $payload['courses'][(string)$course->id] ?? null;
            $sheetname = (string)($map['sheet'] ?? '');
            $usernameheader = (string)($map['username'] ?? '');
            if (!$map || ($sheetname === '' && $usernameheader === '')) {
                $skipped[] = ['courseid' => (int)$course->id];
                continue;
            }
            if (!isset($source[$sheetname]) || $usernameheader === '') {
                throw new moodle_exception('generalexceptionmessage', 'error', '', 'Select both a worksheet and Username column for ' . format_string($course->fullname) . '.');
            }
            $mappedfieldkeys = array_keys(array_filter($map['fields'] ?? [], static function($header, $fieldkey) use ($columnmap): bool {
                return isset($columnmap[$fieldkey]) && trim((string)$header) !== '';
            }, ARRAY_FILTER_USE_BOTH));
            if (empty($mappedfieldkeys)) {
                throw new moodle_exception('generalexceptionmessage', 'error', '', 'Select at least one MAAC field for every course.');
            }
            $rows = $source[$sheetname]['rows'];
            $context = context_course::instance((int)$course->id);
            $users = get_enrolled_users($context, '', 0, 'u.id, u.username');
            $usersbyusername = [];
            foreach ($users as $user) {
                $usersbyusername[core_text::strtolower(trim($user->username))] = (int)$user->id;
            }
            $importrows = [];
            $unmatched = [];
            $missingusernames = 0;
            $seenusernames = [];
            foreach ($rows as $row) {
                $username = core_text::strtolower(trim((string)($row[$usernameheader] ?? '')));
                if ($username === '') {
                    $missingusernames++;
                    continue;
                }
                if (!isset($usersbyusername[$username])) {
                    $unmatched[] = $username;
                    continue;
                }
                if (isset($seenusernames[$username])) {
                    throw new moodle_exception('generalexceptionmessage', 'error', '', 'Duplicate username in ' . format_string($course->fullname) . ': ' . $username);
                }
                $seenusernames[$username] = true;
                $values = [];
                foreach (($map['fields'] ?? []) as $fieldkey => $header) {
                    if (!isset($columnmap[$fieldkey]) || $header === '' || !array_key_exists($header, $row)) {
                        continue;
                    }
                    $value = $row[$header];
                    $type = $columnmap[$fieldkey]['type'];
                    if ($type === 'number') {
                        $values[$fieldkey] = is_numeric($value) ? $value : '';
                    } else if ($type === 'boolean') {
                        $values[$fieldkey] = core_text::strtolower(trim($value)) === 'yes' ? 1 : 0;
                    } else if ($type === 'multi_feedback') {
                        $entries = [];
                        foreach (preg_split('/\\R/u', $value) as $line) {
                            $text = preg_replace('/^\\s*w\\d+\\s*-\\s*/i', '', trim($line));
                            if ($text !== '') {
                                $entries[] = ['date' => date('Y-m-d'), 'text' => $text, 'added_at' => time()];
                            }
                        }
                        $values[$fieldkey] = $entries;
                    } else {
                        $values[$fieldkey] = $value;
                    }
                }
                $importrows[] = ['userid' => $usersbyusername[$username], 'values' => $values];
            }
            $matcheduserids = array_values(array_unique(array_column($importrows, 'userid')));
            $existingvalues = 0;
            if (!empty($matcheduserids)) {
                list($userinsql, $userparams) = $DB->get_in_or_equal($matcheduserids, SQL_PARAMS_NAMED, 'importuser');
                list($fieldinsql, $fieldparams) = $DB->get_in_or_equal($mappedfieldkeys, SQL_PARAMS_NAMED, 'importfield');
                $existingvalues = $DB->count_records_select(
                    'local_batchanalytics_maac',
                    "courseid = :importcourseid AND userid $userinsql AND fieldkey $fieldinsql",
                    ['importcourseid' => (int)$course->id] + $userparams + $fieldparams
                );
            }
            $plans[] = [
                'courseid' => (int)$course->id,
                'rows' => $importrows,
                'existingvalues' => $existingvalues,
                'unmatched' => count($unmatched),
                'missingusernames' => $missingusernames,
                'usernameheader' => $usernameheader,
            ];
        }
        if (empty($plans)) {
            throw new moodle_exception('generalexceptionmessage', 'error', '', 'Map at least one course before importing.');
        }
        if ($action === 'preview') {
            $sendjson(['status' => 'ok', 'courses' => array_map(static fn($plan) => [
                'courseid' => $plan['courseid'],
                'rows' => count($plan['rows']),
                'existingvalues' => $plan['existingvalues'],
                'unmatched' => $plan['unmatched'],
                'missingusernames' => $plan['missingusernames'],
                'usernameheader' => $plan['usernameheader'],
            ], $plans), 'skipped' => $skipped]);
        }
        $transaction = $DB->start_delegated_transaction();
        $service = new \local_batchanalytics\maac_service();
        foreach ($plans as $plan) {
            $service->save_course_data($plan['courseid'], $USER->id, $plan['rows']);
        }
        $transaction->allow_commit();
        \local_batchanalytics\util::purge_batch_response_cache();
        $sendjson(['status' => 'ok', 'courses' => array_map(static fn($plan) => [
            'courseid' => $plan['courseid'],
            'rows' => count($plan['rows']),
            'existingvalues' => $plan['existingvalues'],
            'unmatched' => $plan['unmatched'],
            'missingusernames' => $plan['missingusernames'],
            'usernameheader' => $plan['usernameheader'],
        ], $plans), 'skipped' => $skipped]);
    } catch (Throwable $e) {
        $sendjson(['error' => $e->getMessage()], 400);
    }
}

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/batchanalytics/maac_import.php', ['courses' => implode(',', array_column($courses, 'id'))]));
$PAGE->set_title('Import MAAC');
$PAGE->set_heading('Import MAAC');
$PAGE->requires->css(new moodle_url('/local/batchanalytics/styles.css', ['v' => filemtime(__DIR__ . '/styles.css')]));
$PAGE->requires->js(new moodle_url('/local/batchanalytics/maac_import.js', ['v' => filemtime(__DIR__ . '/maac_import.js')]));
echo $OUTPUT->header();
echo '<div id="ba-maac-import-app" class="local-batchanalytics-wrap" data-sesskey="' . sesskey() . '" data-courses="' . s(json_encode(array_map(static fn($course) => ['id' => $course->id, 'name' => $course->fullname], $courses))) . '" data-columns="' . s(json_encode(array_values(array_filter(\local_batchanalytics\maac_columns_helper::get_columns(), static fn($column) => empty($column['system']) && ($column['type'] ?? '') !== 'formula')))) . '"></div>';
echo $OUTPUT->footer();
