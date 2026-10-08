<?php
/**
 * PHPUnit: survey_stats filters and aggregates (SPEC §59 Phase 4).
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/survey_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/survey_stats.php');
require_once($CFG->dirroot . '/local/tm_course/classes/survey_xlsx_writer.php');
require_once($CFG->dirroot . '/local/tm_course/classes/session_manager.php');

/**
 * @covers \local_tm_course\survey_stats
 */
class survey_stats_test extends \advanced_testcase {

    /**
     * @return array{sessionid:int,versionid:int,surveyid:int,itemid:int}
     */
    private function seed_with_responses(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $start = time() - 60;
        $sessionid = (int) $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $course->id,
            'name' => 'Stats session',
            'starttime' => $start,
            'endtime' => $start + 7200,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $surveyid = survey_manager::create_survey('Stats', 2);
        $versionid = survey_manager::save_structure($surveyid, [[
            'name' => '',
            'items' => [
                [
                    'qtype' => survey_manager::TYPE_SCALE,
                    'title' => 'Scale Q',
                    'required' => 1,
                    'scalemin' => 'L',
                    'scalemax' => 'H',
                ],
                [
                    'qtype' => survey_manager::TYPE_SINGLE,
                    'title' => 'Single Q',
                    'required' => 1,
                    'options' => [['label' => 'Yes'], ['label' => 'No']],
                ],
            ],
        ]], 2);
        survey_manager::assign_course($surveyid, (int) $course->id);
        survey_manager::ensure_session_survey_pin($sessionid);
        $structure = survey_manager::get_version_structure($versionid);
        $scaleid = (int) $structure[0]['items'][0]['id'];
        $singleid = (int) $structure[0]['items'][1]['id'];
        $yes = (int) $structure[0]['items'][1]['options'][0]['id'];

        survey_manager::submit_response_by_email($sessionid, 'a@example.com', [
            $scaleid => ['value' => 4],
            $singleid => ['option' => $yes],
        ]);
        survey_manager::submit_response_by_email($sessionid, 'b@example.com', [
            $scaleid => ['value' => 2],
            $singleid => ['option' => $yes],
        ]);

        return [
            'sessionid' => $sessionid,
            'versionid' => $versionid,
            'surveyid' => $surveyid,
            'scaleid' => $scaleid,
            'singleid' => $singleid,
        ];
    }

    public function test_filters_counts_and_scale_avg(): void {
        $this->resetAfterTest(true);
        $seed = $this->seed_with_responses();
        $filters = survey_stats::filters_from_params([
            'sessionid' => $seed['sessionid'],
            'versionid' => $seed['versionid'],
        ]);
        $this->assertSame(2, survey_stats::count_responses($filters));
        $summary = survey_stats::summary($filters);
        $this->assertSame(2, $summary['response_count']);
        $this->assertSame(0, $summary['mapped_count']);
        $this->assertSame(2, $summary['unmatched_count']);

        $q = survey_stats::question_stats($filters);
        $this->assertSame($seed['versionid'], $q['versionid']);
        $scale = null;
        foreach ($q['questions'] as $question) {
            if ($question['itemid'] === $seed['scaleid']) {
                $scale = $question;
            }
        }
        $this->assertNotNull($scale);
        $this->assertSame(3.0, $scale['average']);
        $this->assertSame(2, $scale['answered']);
    }

    public function test_export_filter_consistency(): void {
        $this->resetAfterTest(true);
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }
        $seed = $this->seed_with_responses();
        $filters = survey_stats::filters_from_params(['sessionid' => $seed['sessionid']]);
        list($responses, $statistics) = survey_stats::export_rows($filters);
        // Header + 2 data rows.
        $this->assertCount(3, $responses);
        $this->assertGreaterThan(3, count($statistics));
        $emails = [];
        foreach (array_slice($responses, 1) as $row) {
            $emails[] = $row[1];
        }
        sort($emails);
        $this->assertSame(['a@example.com', 'b@example.com'], $emails);

        // Narrow filter must not include the other email.
        $one = survey_stats::filters_from_params([
            'sessionid' => $seed['sessionid'],
            'email' => 'a@example.com',
        ]);
        list($onlya, ) = survey_stats::export_rows($one);
        $this->assertCount(2, $onlya);
        $this->assertSame('a@example.com', $onlya[1][1]);
    }

    public function test_export_script_uses_moodle_excellib(): void {
        $src = file_get_contents(dirname(__DIR__) . '/admin/survey_export.php');
        $this->assertStringContainsString("excellib.class.php", $src);
        $this->assertStringContainsString('MoodleExcelWorkbook', $src);
        $this->assertStringContainsString('fill_moodle_excel_workbook', $src);
        $this->assertStringNotContainsString('send_file(', $src);
        $this->assertStringNotContainsString('survey_xlsx_writer', $src);
    }

    public function test_fill_moodle_excel_workbook_writes_two_sheets(): void {
        global $CFG;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/excellib.class.php');
        $seed = $this->seed_with_responses();
        $filters = survey_stats::filters_from_params(['sessionid' => $seed['sessionid']]);

        $path = make_request_directory() . '/survey_export_test.xlsx';
        $workbook = new \MoodleExcelWorkbook($path);
        survey_stats::fill_moodle_excel_workbook($workbook, $filters);
        $workbook->close();

        $this->assertFileExists($path);
        $this->assertGreaterThan(100, filesize($path));
        // XLSX is a zip package.
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
        $wb = $zip->getFromName('xl/workbook.xml');
        $this->assertStringContainsString('Responses', $wb);
        $this->assertStringContainsString('Statistics', $wb);
        $zip->close();
    }

    public function test_session_live_snapshot_is_scoped_and_has_no_identity_fields(): void {
        global $DB;
        $this->resetAfterTest(true);
        $seed = $this->seed_with_responses();
        $other = $this->getDataGenerator()->create_course();
        $start = time() - 30;
        $othersession = (int) $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $other->id,
            'name' => 'Other',
            'starttime' => $start,
            'endtime' => $start + 3600,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $snap = survey_stats::session_live_snapshot($seed['sessionid']);
        $this->assertSame(2, $snap['response_count']);
        $this->assertSame(0, $snap['mapped_count']);
        $this->assertSame(2, $snap['unmatched_count']);
        $encoded = json_encode($snap);
        $this->assertStringNotContainsString('a@example.com', $encoded);
        $this->assertStringNotContainsString('email', $encoded);
        $this->assertStringNotContainsString('userid', $encoded);

        $empty = survey_stats::session_live_snapshot($othersession);
        $this->assertSame(0, $empty['response_count']);
    }

    public function test_results_page_requires_survey_and_drops_raw_filters(): void {
        $results = file_get_contents(__DIR__ . '/../admin/survey_results.php');
        $export = file_get_contents(__DIR__ . '/../admin/survey_export.php');
        $this->assertStringContainsString('survey_stats_pick_survey', $results);
        $this->assertStringContainsString('survey_stats_export_need_survey', $export);
        $this->assertStringContainsString("if (\$surveyid <= 0)", $results);
        $this->assertStringContainsString("if (\$surveyid <= 0)", $export);
        $this->assertStringNotContainsString("name' => 'email'", $results);
        $this->assertStringNotContainsString("name' => 'versionid'", $results);
        $this->assertStringNotContainsString('survey_stats_sessionid', $results);
        $this->assertStringContainsString('survey_stats_session_filter', $results);
        $this->assertStringContainsString("'email' => ''", $export);
        $this->assertStringContainsString("'versionid' => 0", $export);
    }

    public function test_question_stats_use_the_same_filter_dataset_as_summary(): void {
        global $DB;
        $this->resetAfterTest(true);
        $seed = $this->seed_with_responses();
        $structure = survey_manager::get_version_structure($seed['versionid']);
        $scalekey = (string) $structure[0]['items'][0]['stablekey'];
        $single = $structure[0]['items'][1];

        // Current version moves forward with no new replies. Stats must still see the old replies.
        survey_manager::save_structure($seed['surveyid'], [[
            'name' => '',
            'items' => [
                [
                    'qtype' => survey_manager::TYPE_SCALE,
                    'title' => 'Scale Q',
                    'stablekey' => $scalekey,
                    'required' => 1,
                    'scalemin' => 'L',
                    'scalemax' => 'H',
                ],
                [
                    'qtype' => survey_manager::TYPE_SINGLE,
                    'title' => 'Single Q',
                    'stablekey' => $single['stablekey'],
                    'required' => 1,
                    'options' => [
                        ['label' => 'Yes', 'stablekey' => $single['options'][0]['stablekey']],
                        ['label' => 'No', 'stablekey' => $single['options'][1]['stablekey']],
                    ],
                ],
            ],
        ]], 2);

        $surveyonly = survey_stats::filters_from_params(['surveyid' => $seed['surveyid']]);
        $summary = survey_stats::summary($surveyonly);
        $stats = survey_stats::question_stats($surveyonly);
        $this->assertSame(2, $summary['response_count']);
        $scale = $this->question_by_title($stats['questions'], 'Scale Q');
        $this->assertSame(2, $scale['answered']);
        $this->assertSame(3.0, $scale['average']);
        list($rows, $sheet) = survey_stats::export_rows($surveyonly);
        $this->assertCount(3, $rows);
        $this->assertStringContainsString('answered=2', json_encode($sheet));

        $session = $DB->get_record('local_tm_course_sessions', ['id' => $seed['sessionid']], '*', MUST_EXIST);
        $bycourse = survey_stats::filters_from_params([
            'surveyid' => $seed['surveyid'],
            'courseid' => (int) $session->courseid,
        ]);
        $this->assertSame(2, survey_stats::summary($bycourse)['response_count']);
        $this->assertSame(2, $this->question_by_title(survey_stats::question_stats($bycourse)['questions'], 'Scale Q')['answered']);

        $none = survey_stats::filters_from_params([
            'surveyid' => $seed['surveyid'],
            'courseid' => 999999,
        ]);
        $this->assertSame(0, survey_stats::summary($none)['response_count']);
        $this->assertSame([], survey_stats::question_stats($none)['questions']);
        list($emptyrows, ) = survey_stats::export_rows($none);
        $this->assertCount(1, $emptyrows);

        $DB->set_field('local_tm_course_svresp', 'timecreated', 1000, ['email' => 'b@example.com']);
        $dated = survey_stats::filters_from_params([
            'surveyid' => $seed['surveyid'],
            'datefrom' => 2000,
        ]);
        $this->assertSame(1, survey_stats::summary($dated)['response_count']);
        $this->assertSame(1, $this->question_by_title(survey_stats::question_stats($dated)['questions'], 'Scale Q')['answered']);
        $this->assertSame(4.0, $this->question_by_title(survey_stats::question_stats($dated)['questions'], 'Scale Q')['average']);

        $DB->set_field('local_tm_course_svresp', 'mapped', 1, ['email' => 'a@example.com']);
        $mapped = survey_stats::filters_from_params([
            'surveyid' => $seed['surveyid'],
            'mapped' => 1,
        ]);
        $this->assertSame(1, survey_stats::summary($mapped)['response_count']);
        $this->assertSame(1, $this->question_by_title(survey_stats::question_stats($mapped)['questions'], 'Scale Q')['answered']);

        $onesession = survey_stats::filters_from_params([
            'surveyid' => $seed['surveyid'],
            'sessionid' => $seed['sessionid'],
        ]);
        $this->assertSame(2, survey_stats::summary($onesession)['response_count']);
        $this->assertSame(2, $this->question_by_title(survey_stats::question_stats($onesession)['questions'], 'Scale Q')['answered']);
    }

    /**
     * @param array $questions
     * @return array
     */
    private function question_by_title(array $questions, string $title): array {
        foreach ($questions as $question) {
            if ((string) $question['title'] === $title) {
                return $question;
            }
        }
        $this->fail('Missing question ' . $title);
        return [];
    }
}
