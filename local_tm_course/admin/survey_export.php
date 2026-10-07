<?php
/**
 * Excel export for survey results (SPEC §59 Phase 4).
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/survey_stats.php');
require_once(__DIR__ . '/../classes/survey_xlsx_writer.php');

use local_tm_course\survey_stats;
use local_tm_course\survey_xlsx_writer;

require_login();
require_capability('local/tm_course:manage', context_system::instance());
require_sesskey();

$datefromraw = optional_param('datefrom', '', PARAM_RAW_TRIMMED);
$datetoraw = optional_param('dateto', '', PARAM_RAW_TRIMMED);
$datefrom = 0;
$dateto = 0;
if ($datefromraw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datefromraw)) {
    $datefrom = (int) make_timestamp((int) substr($datefromraw, 0, 4), (int) substr($datefromraw, 5, 2), (int) substr($datefromraw, 8, 2), 0, 0, 0);
} else if (ctype_digit($datefromraw)) {
    $datefrom = (int) $datefromraw;
}
if ($datetoraw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datetoraw)) {
    $dateto = (int) make_timestamp((int) substr($datetoraw, 0, 4), (int) substr($datetoraw, 5, 2), (int) substr($datetoraw, 8, 2), 23, 59, 59);
} else if (ctype_digit($datetoraw)) {
    $dateto = (int) $datetoraw;
}

$params = [
    'surveyid' => optional_param('surveyid', 0, PARAM_INT),
    'versionid' => optional_param('versionid', 0, PARAM_INT),
    'courseid' => optional_param('courseid', 0, PARAM_INT),
    'sessionid' => optional_param('sessionid', 0, PARAM_INT),
    'datefrom' => $datefrom,
    'dateto' => $dateto,
    'email' => optional_param('email', '', PARAM_RAW_TRIMMED),
    'mapped' => optional_param('mapped', -1, PARAM_INT),
];
$filters = survey_stats::filters_from_params($params);
list($responses, $statistics) = survey_stats::export_rows($filters);

$tmpdir = make_temp_directory('local_tm_course_survey');
$path = $tmpdir . '/survey_export_' . time() . '_' . random_int(1000, 9999) . '.xlsx';
survey_xlsx_writer::write($path, [
    ['name' => 'Responses', 'rows' => $responses],
    ['name' => 'Statistics', 'rows' => $statistics],
]);

$filename = 'survey_export_' . date('Ymd_His') . '.xlsx';
send_file($path, $filename, 0, 0, false, true, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', true);
