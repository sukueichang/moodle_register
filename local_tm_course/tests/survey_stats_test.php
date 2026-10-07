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
}
