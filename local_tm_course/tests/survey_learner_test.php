<?php
/**
 * PHPUnit: course survey stage 2 learner fill-in (SPEC §59).
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/survey_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/enrolment_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/session_manager.php');

/**
 * @covers \local_tm_course\survey_manager
 */
class survey_learner_test extends \advanced_testcase {

    /**
     * @return array{course:\stdClass,sessionid:int,surveyid:int,versionid:int,structure:array}
     */
    private function seed_open_survey_session(int $startoffset = -3600): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $start = time() + $startoffset;
        $sessionid = (int) $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $course->id,
            'name' => 'Survey session',
            'starttime' => $start,
            'endtime' => $start + 7200,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $surveyid = survey_manager::create_survey('Learner survey', 2);
        $versionid = survey_manager::save_structure($surveyid, [[
            'name' => 'Block',
            'items' => [
                [
                    'qtype' => survey_manager::TYPE_SINGLE,
                    'title' => 'Single',
                    'required' => 1,
                    'allowother' => 1,
                    'options' => [
                        ['label' => 'A'],
                        ['label' => 'B'],
                    ],
                ],
                [
                    'qtype' => survey_manager::TYPE_MULTI,
                    'title' => 'Multi',
                    'required' => 1,
                    'options' => [
                        ['label' => 'X'],
                        ['label' => 'Y'],
                    ],
                ],
                [
                    'qtype' => survey_manager::TYPE_SCALE,
                    'title' => 'Scale',
                    'required' => 1,
                    'scalemin' => 'Low',
                    'scalemax' => 'High',
                ],
                [
                    'qtype' => survey_manager::TYPE_TEXT,
                    'title' => 'Text',
                    'required' => 0,
                ],
            ],
        ]], 2);
        survey_manager::assign_course($surveyid, (int) $course->id);
        $structure = survey_manager::get_version_structure($versionid);
        return [
            'course' => $course,
            'sessionid' => $sessionid,
            'surveyid' => $surveyid,
            'versionid' => $versionid,
            'structure' => $structure,
            'start' => $start,
        ];
    }

    private function insert_enrol(int $sessionid, int $userid, int $status, int $linkeduserid = 0): int {
        global $DB;
        return (int) $DB->insert_record('local_tm_course_enrolments', (object) [
            'sessionid' => $sessionid,
            'userid' => $userid,
            'status' => $status,
            'linked_userid' => $linkeduserid,
            'placeholder_seq' => $linkeduserid > 0 ? 1 : 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * @param array $structure
     * @return array<int,array<string,mixed>>
     */
    private function valid_answers(array $structure): array {
        $items = $structure[0]['items'];
        $single = $items[0];
        $multi = $items[1];
        $scale = $items[2];
        $text = $items[3];
        return [
            (int) $single['id'] => ['option' => (int) $single['options'][0]['id']],
            (int) $multi['id'] => ['options' => [(int) $multi['options'][0]['id'], (int) $multi['options'][1]['id']]],
            (int) $scale['id'] => ['value' => 4],
            (int) $text['id'] => ['value' => 'Hello'],
        ];
    }

    public function test_cannot_fill_before_starttime(): void {
        $this->resetAfterTest(true);
        $seed = $this->seed_open_survey_session(3600);
        $user = $this->getDataGenerator()->create_user();
        $this->insert_enrol($seed['sessionid'], (int) $user->id, session_manager::ENROL_APPROVED);
        $this->assertFalse(survey_manager::is_session_survey_open($seed['sessionid']));
        $this->assertFalse(survey_manager::can_user_submit($seed['sessionid'], (int) $user->id));
        $this->expectException(\moodle_exception::class);
        survey_manager::submit_response($seed['sessionid'], (int) $user->id, $this->valid_answers($seed['structure']));
    }

    public function test_can_fill_after_starttime_without_attendance(): void {
        global $DB;
        $this->resetAfterTest(true);
        $seed = $this->seed_open_survey_session(-60);
        $user = $this->getDataGenerator()->create_user();
        $enrolid = $this->insert_enrol($seed['sessionid'], (int) $user->id, session_manager::ENROL_APPROVED);
        // No attendance row required.
        $this->assertTrue(survey_manager::is_session_survey_open($seed['sessionid']));
        $this->assertTrue(survey_manager::can_user_submit($seed['sessionid'], (int) $user->id));
        $responseid = survey_manager::submit_response(
            $seed['sessionid'],
            (int) $user->id,
            $this->valid_answers($seed['structure'])
        );
        $this->assertGreaterThan(0, $responseid);
        $this->assertSame(1, $DB->count_records('local_tm_course_svresp', ['enrolid' => $enrolid]));
        // Fill must not depend on attendance (no attendance write required).
        $this->assertTrue(survey_manager::can_user_view_response($seed['sessionid'], (int) $user->id));
    }

    public function test_non_approved_cannot_submit(): void {
        $this->resetAfterTest(true);
        $seed = $this->seed_open_survey_session(-60);
        $user = $this->getDataGenerator()->create_user();
        $this->insert_enrol($seed['sessionid'], (int) $user->id, session_manager::ENROL_PENDING);
        $this->assertFalse(survey_manager::can_user_submit($seed['sessionid'], (int) $user->id));
        $this->expectException(\moodle_exception::class);
        survey_manager::submit_response($seed['sessionid'], (int) $user->id, $this->valid_answers($seed['structure']));
    }

    public function test_linked_userid_identifies_learner(): void {
        global $DB;
        $this->resetAfterTest(true);
        $seed = $this->seed_open_survey_session(-60);
        $learner = $this->getDataGenerator()->create_user();
        $holder = $this->getDataGenerator()->create_user([
            'email' => 'tm.ph.' . $seed['sessionid'] . '.1.x' . enrolment_manager::PLACEHOLDER_EMAIL_MARKER,
        ]);
        $enrolid = $this->insert_enrol(
            $seed['sessionid'],
            (int) $holder->id,
            session_manager::ENROL_APPROVED,
            (int) $learner->id
        );
        $this->assertSame((int) $learner->id, survey_manager::learner_userid_for_enrol(
            $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST)
        ));
        $this->assertTrue(survey_manager::can_user_submit($seed['sessionid'], (int) $learner->id));
        $this->assertFalse(survey_manager::can_user_submit($seed['sessionid'], (int) $holder->id));
        $records = enrolment_manager::get_user_records((int) $learner->id);
        $this->assertArrayHasKey($enrolid, $records);
    }

    public function test_first_open_pins_version_and_later_starttime_edit_keeps_pin(): void {
        global $DB;
        $this->resetAfterTest(true);
        $seed = $this->seed_open_survey_session(-60);
        $pinid = survey_manager::ensure_session_survey_pin($seed['sessionid']);
        $this->assertGreaterThan(0, $pinid);
        $pin = $DB->get_record('local_tm_course_svpin', ['id' => $pinid], '*', MUST_EXIST);
        $this->assertSame($seed['versionid'], (int) $pin->versionid);
        $this->assertSame($seed['start'], (int) $pin->opens_at);

        $newstart = time() + 86400;
        survey_manager::lock_pin_before_starttime_edit($seed['sessionid'], $seed['start'], $newstart, 2);
        $DB->set_field('local_tm_course_sessions', 'starttime', $newstart, ['id' => $seed['sessionid']]);
        $pin2 = $DB->get_record('local_tm_course_svpin', ['id' => $pinid], '*', MUST_EXIST);
        $this->assertSame($seed['start'], (int) $pin2->opens_at);
        $this->assertSame($seed['versionid'], (int) $pin2->versionid);
        $this->assertTrue(survey_manager::is_session_survey_open($seed['sessionid']));
    }

    public function test_duplicate_submit_rejected_and_retry_safe(): void {
        global $DB;
        $this->resetAfterTest(true);
        $seed = $this->seed_open_survey_session(-60);
        $user = $this->getDataGenerator()->create_user();
        $enrolid = $this->insert_enrol($seed['sessionid'], (int) $user->id, session_manager::ENROL_APPROVED);
        $answers = $this->valid_answers($seed['structure']);
        $first = survey_manager::submit_response($seed['sessionid'], (int) $user->id, $answers);
        $this->assertGreaterThan(0, $first);
        try {
            survey_manager::submit_response($seed['sessionid'], (int) $user->id, $answers);
            $this->fail('Expected already-submitted exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('survey_error_already_submitted', $e->errorcode);
        }
        $this->assertSame(1, $DB->count_records('local_tm_course_svresp', ['enrolid' => $enrolid]));
    }

    public function test_after_submit_view_only_and_cancel_keeps_response(): void {
        global $DB;
        $this->resetAfterTest(true);
        $seed = $this->seed_open_survey_session(-60);
        $user = $this->getDataGenerator()->create_user();
        $enrolid = $this->insert_enrol($seed['sessionid'], (int) $user->id, session_manager::ENROL_APPROVED);
        survey_manager::submit_response($seed['sessionid'], (int) $user->id, $this->valid_answers($seed['structure']));
        $this->assertFalse(survey_manager::can_user_submit($seed['sessionid'], (int) $user->id));
        $this->assertTrue(survey_manager::can_user_view_response($seed['sessionid'], (int) $user->id));
        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST);
        $this->assertSame(survey_manager::STATE_VIEW, survey_manager::my_records_survey_state($enrol, (int) $user->id));

        $DB->set_field('local_tm_course_enrolments', 'status', session_manager::ENROL_CANCELLED, ['id' => $enrolid]);
        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST);
        $this->assertSame(survey_manager::STATE_VIEW, survey_manager::my_records_survey_state($enrol, (int) $user->id));
        $this->assertTrue(survey_manager::can_user_view_response($seed['sessionid'], (int) $user->id));
        $this->assertFalse(survey_manager::can_user_submit($seed['sessionid'], (int) $user->id));
        $this->assertSame(1, $DB->count_records('local_tm_course_svresp', ['enrolid' => $enrolid]));
    }

    public function test_required_and_type_validation(): void {
        $this->resetAfterTest(true);
        $seed = $this->seed_open_survey_session(-60);
        $user = $this->getDataGenerator()->create_user();
        $this->insert_enrol($seed['sessionid'], (int) $user->id, session_manager::ENROL_APPROVED);
        $items = $seed['structure'][0]['items'];
        try {
            survey_manager::submit_response($seed['sessionid'], (int) $user->id, []);
            $this->fail('Expected required exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('survey_error_required', $e->errorcode);
        }
        $bad = $this->valid_answers($seed['structure']);
        $bad[(int) $items[2]['id']] = ['value' => 9];
        try {
            survey_manager::submit_response($seed['sessionid'], (int) $user->id, $bad);
            $this->fail('Expected bad answer exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('survey_error_bad_answer', $e->errorcode);
        }
        $ok = $this->valid_answers($seed['structure']);
        $responseid = survey_manager::submit_response($seed['sessionid'], (int) $user->id, $ok);
        $answers = survey_manager::get_response_answers($responseid);
        $this->assertSame(4, $answers[(int) $items[2]['id']]['valueint']);
        $this->assertSame('Hello', $answers[(int) $items[3]['id']]['valuetext']);
        $this->assertCount(2, $answers[(int) $items[1]['id']]['optionids']);
    }
}
