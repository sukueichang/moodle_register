<?php
/**
 * Excel export for survey results via Moodle excellib (SPEC §59 Phase 4).
 *
 * URL: /local/tm_course/admin/survey_export.php?...&sesskey=
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/excellib.class.php');
require_once(__DIR__ . '/../classes/survey_stats.php');

use local_tm_course\survey_stats;

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

// No page chrome — excellib streams the XLSX to the browser.
\core\session\manager::write_close();
raise_memory_limit(MEMORY_EXTRA);

$downloadname = clean_filename('survey_export_' . date('Ymd_His'));
$workbook = new MoodleExcelWorkbook('-');
$workbook->send($downloadname);
survey_stats::fill_moodle_excel_workbook($workbook, $filters);
$workbook->close();
exit;
