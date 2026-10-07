<?php
/**
 * Offline checks for survey Excel export wiring (no Moodle bootstrap).
 * Run: php tools/verify_survey_excel_export.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = $root . DIRECTORY_SEPARATOR . 'local_tm_course';
$fail = 0;

function assert_true(bool $cond, string $msg): void {
    global $fail;
    if ($cond) {
        echo "PASS  {$msg}\n";
    } else {
        echo "FAIL  {$msg}\n";
        $fail++;
    }
}

$export = file_get_contents($plugin . '/admin/survey_export.php');
$stats = file_get_contents($plugin . '/classes/survey_stats.php');

assert_true(str_contains($export, 'excellib.class.php'), 'export requires excellib.class.php');
assert_true(str_contains($export, 'MoodleExcelWorkbook'), 'export uses MoodleExcelWorkbook');
assert_true(str_contains($export, 'fill_moodle_excel_workbook'), 'export fills workbook via survey_stats');
assert_true(!str_contains($export, 'send_file('), 'export does not call send_file');
assert_true(!str_contains($export, 'survey_xlsx_writer'), 'export does not use custom zip writer');
assert_true(str_contains($export, 'write_close'), 'export closes session before download');
assert_true(str_contains($stats, 'function fill_moodle_excel_workbook'), 'stats has fill_moodle_excel_workbook');
assert_true(str_contains($stats, "add_worksheet('Responses')"), 'workbook sheet Responses');
assert_true(str_contains($stats, "add_worksheet('Statistics')"), 'workbook sheet Statistics');

$ver = file_get_contents($plugin . '/version.php');
assert_true(str_contains($ver, '5.28.1'), 'release 5.28.1');
assert_true(str_contains($ver, '2026100701'), 'version 2026100701');

echo $fail === 0 ? "\nAll offline checks passed.\n" : "\n{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
