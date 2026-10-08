<?php
/**
 * PHPUnit: pin_session_surveys scheduled task (SPEC §59 Phase 3).
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/survey_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/task/pin_session_surveys.php');

/**
 * @covers \local_tm_course\task\pin_session_surveys
 */
class survey_pin_task_test extends \advanced_testcase {

    public function test_pin_eligible_skip_pinned_idempotent(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $surveyid = survey_manager::create_survey('Task survey', 2);
        survey_manager::save_structure($surveyid, [[
            'name' => '',
            'items' => [[
                'qtype' => survey_manager::TYPE_TEXT,
                'title' => 'Q',
                'required' => 0,
            ]],
        ]], 2);
        survey_manager::assign_course($surveyid, (int) $course->id);

        $start = time() - 120;
        $eligible = (int) $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $course->id,
            'name' => 'Eligible',
            'starttime' => $start,
            'endtime' => $start + 3600,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $future = (int) $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $course->id,
            'name' => 'Future',
            'starttime' => time() + 86400,
            'endtime' => time() + 90000,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $alreadypinned = (int) $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $course->id,
            'name' => 'Pinned',
            'starttime' => $start,
            'endtime' => $start + 3600,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $pinid = survey_manager::ensure_session_survey_pin($alreadypinned);
        $this->assertGreaterThan(0, $pinid);

        $task = new \local_tm_course\task\pin_session_surveys();
        $task->execute();

        $this->assertNotFalse($DB->get_record('local_tm_course_svpin', ['sessionid' => $eligible]));
        $this->assertFalse($DB->get_record('local_tm_course_svpin', ['sessionid' => $future]));
        $this->assertSame(1, $DB->count_records('local_tm_course_svpin', ['sessionid' => $alreadypinned]));
        $this->assertNotNull(survey_manager::get_token_for_session($eligible));

        // Idempotent second run.
        $before = $DB->count_records('local_tm_course_svpin');
        $task->execute();
        $this->assertSame($before, $DB->count_records('local_tm_course_svpin'));
    }
}
