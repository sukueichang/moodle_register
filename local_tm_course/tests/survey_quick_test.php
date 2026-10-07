<?php
/**
 * PHPUnit: survey email quick-access / tokens (SPEC §59 Phase 3).
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/survey_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/session_manager.php');

/**
 * @covers \local_tm_course\survey_manager
 */
class survey_quick_test extends \advanced_testcase {

    /**
     * @return array{sessionid:int,versionid:int,structure:array,surveyid:int}
     */
    private function seed_open_session(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $start = time() - 60;
        $sessionid = (int) $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $course->id,
            'name' => 'Quick survey session',
            'starttime' => $start,
            'endtime' => $start + 7200,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $surveyid = survey_manager::create_survey('Quick', 2);
        $versionid = survey_manager::save_structure($surveyid, [[
            'name' => '',
            'items' => [[
                'qtype' => survey_manager::TYPE_SCALE,
                'title' => 'Rate',
                'required' => 1,
                'scalemin' => 'L',
                'scalemax' => 'H',
            ]],
        ]], 2);
        survey_manager::assign_course($surveyid, (int) $course->id);
        survey_manager::ensure_session_survey_pin($sessionid);
        return [
            'sessionid' => $sessionid,
            'versionid' => $versionid,
            'structure' => survey_manager::get_version_structure($versionid),
            'surveyid' => $surveyid,
            'course' => $course,
        ];
    }

    public function test_normalize_email(): void {
        $this->resetAfterTest(true);
        $this->assertSame('a@b.com', survey_manager::normalize_email('  A@B.COM '));
        $this->assertSame('', survey_manager::normalize_email('not-an-email'));
        $this->assertSame('', survey_manager::normalize_email(''));
    }

    public function test_token_valid_disabled_regenerate(): void {
        $this->resetAfterTest(true);
        $seed = $this->seed_open_session();
        $tok = survey_manager::get_token_for_session($seed['sessionid']);
        $this->assertNotNull($tok);
        $this->assertSame(1, (int) $tok->enabled);
        $this->assertNotNull(survey_manager::get_token_by_value((string) $tok->token));
        $this->assertTrue(survey_manager::is_quick_survey_accepting($seed['sessionid']));

        survey_manager::set_session_survey_token_enabled($seed['sessionid'], false);
        $this->assertFalse(survey_manager::is_quick_survey_accepting($seed['sessionid']));
        $old = (string) $tok->token;
        $this->assertNotNull(survey_manager::get_token_by_value($old));

        $new = survey_manager::regenerate_session_survey_token($seed['sessionid']);
        $this->assertNotSame($old, (string) $new->token);
        $this->assertSame(1, (int) $new->enabled);
        $this->assertNull(survey_manager::get_token_by_value($old));
        $this->assertNotNull(survey_manager::get_token_by_value((string) $new->token));
    }

    public function test_mapped_and_unmatched_submit(): void {
        global $DB;
        $this->resetAfterTest(true);
        $seed = $this->seed_open_session();
        $itemid = (int) $seed['structure'][0]['items'][0]['id'];
        $answers = [$itemid => ['value' => 5]];

        $user = $this->getDataGenerator()->create_user(['email' => 'mapped.learner@example.com']);
        $enrolid = (int) $DB->insert_record('local_tm_course_enrolments', (object) [
            'sessionid' => $seed['sessionid'],
            'userid' => $user->id,
            'status' => session_manager::ENROL_APPROVED,
            'linked_userid' => 0,
            'placeholder_seq' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $rid = survey_manager::submit_response_by_email($seed['sessionid'], 'mapped.learner@example.com', $answers);
        $row = $DB->get_record('local_tm_course_svresp', ['id' => $rid], '*', MUST_EXIST);
        $this->assertSame(survey_manager::MAPPED, (int) $row->mapped);
        $this->assertSame($enrolid, (int) $row->enrolid);
        $this->assertSame('mapped.learner@example.com', $row->email);

        $rid2 = survey_manager::submit_response_by_email($seed['sessionid'], 'guest.unmatched@example.com', $answers);
        $row2 = $DB->get_record('local_tm_course_svresp', ['id' => $rid2], '*', MUST_EXIST);
        $this->assertSame(survey_manager::UNMAPPED, (int) $row2->mapped);
        $this->assertSame(0, (int) $row2->enrolid);
        $this->assertSame(0, (int) $row2->userid);
    }

    public function test_duplicate_email_and_legacy_enrol(): void {
        global $DB;
        $this->resetAfterTest(true);
        $seed = $this->seed_open_session();
        $itemid = (int) $seed['structure'][0]['items'][0]['id'];
        $answers = [$itemid => ['value' => 3]];

        survey_manager::submit_response_by_email($seed['sessionid'], 'dup@example.com', $answers);
        try {
            survey_manager::submit_response_by_email($seed['sessionid'], 'dup@example.com', $answers);
            $this->fail('Expected duplicate');
        } catch (\moodle_exception $e) {
            $this->assertSame('survey_error_already_submitted', $e->errorcode);
        }

        $user = $this->getDataGenerator()->create_user(['email' => 'legacy@example.com']);
        $enrolid = (int) $DB->insert_record('local_tm_course_enrolments', (object) [
            'sessionid' => $seed['sessionid'],
            'userid' => $user->id,
            'status' => session_manager::ENROL_APPROVED,
            'linked_userid' => 0,
            'placeholder_seq' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        // Legacy-style response keyed by enrolid only (then email path should detect).
        $DB->insert_record('local_tm_course_svresp', (object) [
            'enrolid' => $enrolid,
            'versionid' => $seed['versionid'],
            'sessionid' => $seed['sessionid'],
            'userid' => $user->id,
            'email' => 'other-legacy@example.com',
            'mapped' => 1,
            'timecreated' => time(),
        ]);
        try {
            survey_manager::submit_response_by_email($seed['sessionid'], 'legacy@example.com', $answers);
            $this->fail('Expected legacy enrol duplicate');
        } catch (\moodle_exception $e) {
            $this->assertSame('survey_error_already_submitted', $e->errorcode);
        }
    }

    public function test_same_email_different_session_ok(): void {
        global $DB;
        $this->resetAfterTest(true);
        $seed = $this->seed_open_session();
        $itemid = (int) $seed['structure'][0]['items'][0]['id'];
        $answers = [$itemid => ['value' => 2]];
        survey_manager::submit_response_by_email($seed['sessionid'], 'shared@example.com', $answers);

        $start = time() - 60;
        $session2 = (int) $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $seed['course']->id,
            'name' => 'Second',
            'starttime' => $start,
            'endtime' => $start + 7200,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        survey_manager::ensure_session_survey_pin($session2);
        $rid = survey_manager::submit_response_by_email($session2, 'shared@example.com', $answers);
        $this->assertGreaterThan(0, $rid);
    }

    public function test_submit_response_phase2_still_works(): void {
        global $DB;
        $this->resetAfterTest(true);
        $seed = $this->seed_open_session();
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_tm_course_enrolments', (object) [
            'sessionid' => $seed['sessionid'],
            'userid' => $user->id,
            'status' => session_manager::ENROL_APPROVED,
            'linked_userid' => 0,
            'placeholder_seq' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $itemid = (int) $seed['structure'][0]['items'][0]['id'];
        $rid = survey_manager::submit_response($seed['sessionid'], (int) $user->id, [
            $itemid => ['value' => 4],
        ]);
        $row = $DB->get_record('local_tm_course_svresp', ['id' => $rid], '*', MUST_EXIST);
        $this->assertNotEmpty($row->email);
        $this->assertSame(survey_manager::MAPPED, (int) $row->mapped);
    }
}
