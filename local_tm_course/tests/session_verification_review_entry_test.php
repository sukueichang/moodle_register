<?php
/**
 * Session enrolment verification: desk-board state and original-submitter resume.
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/session_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/enrolment_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/session_verification_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/verification_manager.php');

/**
 * @covers \local_tm_course\session_verification_manager
 * @covers \local_tm_course\enrolment_manager::approve
 */
class session_verification_review_entry_test extends \advanced_testcase {

    public function test_batch_states_and_shared_label_are_summarized_together(): void {
        $this->resetAfterTest(true);
        $built = $this->insert_onsite_session();
        $required = $this->add_question($built->courseid, 'onsite', 1);
        $second = $this->add_question($built->courseid, 'both', 1);
        $optional = $this->add_question($built->courseid, 'onsite', 0);
        $this->add_question($built->courseid, 'online', 1);

        $actor = $this->getDataGenerator()->create_user();
        $open = $this->add_submission($built->sessionid, session_verification_manager::SCOPE_BATCH, (int)$actor->id, 0);
        $pending = $this->add_submission($built->sessionid, session_verification_manager::SCOPE_BATCH, (int)$actor->id, 1);
        $failed = $this->add_submission($built->sessionid, session_verification_manager::SCOPE_BATCH, (int)$actor->id, 1);
        $passed = $this->add_submission($built->sessionid, session_verification_manager::SCOPE_SELF, (int)$actor->id, 1);

        $this->link_file($pending, $required, 71001, verification_manager::REVIEW_PENDING, true);
        $this->link_file($failed, $required, 71002, verification_manager::REVIEW_FAILED, true);
        $this->link_file($failed, $second, 71003, verification_manager::REVIEW_PENDING, true);
        $this->link_file($passed, $required, 71004, verification_manager::REVIEW_PASSED, true);
        $this->link_file($passed, $second, 71005, verification_manager::REVIEW_PASSED, true);
        $this->link_file($passed, $optional, 71006, verification_manager::REVIEW_FAILED, true);

        $summary = session_verification_manager::summarize_submission_states(
            [$open, $pending, $failed, $passed, 0],
            $built->session
        );

        $this->assertSame(session_verification_manager::STATE_NOT_SUBMITTED, $summary[$open]['state']);
        $this->assertTrue($summary[$open]['sharedbatch']);
        $this->assertSame(session_verification_manager::STATE_PENDING_REVIEW, $summary[$pending]['state']);
        $this->assertSame(session_verification_manager::STATE_REQUIRED_FAILED, $summary[$failed]['state']);
        $this->assertSame(session_verification_manager::STATE_REQUIRED_PASSED, $summary[$passed]['state']);
        $this->assertFalse($summary[$passed]['sharedbatch']);
        $this->assertArrayNotHasKey(0, $summary);
        $this->assertSame('session_vq_state_not_submitted', session_verification_manager::state_lang_key(
            session_verification_manager::STATE_NOT_SUBMITTED
        ));
    }

    public function test_missing_file_stays_pending_and_approval_gate_is_unchanged(): void {
        global $DB;
        $this->resetAfterTest(true);
        $built = $this->insert_onsite_session();
        $required = $this->add_question($built->courseid, 'onsite', 1);
        $learner = $this->getDataGenerator()->create_user(['institution' => 'Techman']);
        $admin = $this->getDataGenerator()->create_user();
        $sales = $this->getDataGenerator()->create_user();
        $enrolid = enrolment_manager::enrol($built->sessionid, (int)$learner->id, 'Techman');
        $subid = $this->add_submission($built->sessionid, session_verification_manager::SCOPE_BATCH, (int)$sales->id, 0);
        $DB->set_field('local_tm_course_enrolments', 'vq_submission_id', $subid, ['id' => $enrolid]);

        $before = (int)$DB->count_records('local_tm_course_sess_vq_sub');
        $this->expect_approve_error($enrolid, (int)$admin->id, 'error_session_vq_not_submitted');
        $this->assertSame($before, (int)$DB->count_records('local_tm_course_sess_vq_sub'));
        $this->assertSame($subid, (int)$DB->get_field('local_tm_course_enrolments', 'vq_submission_id', ['id' => $enrolid]));

        session_verification_manager::mark_submitted($subid);
        $this->link_file($subid, $required, 72001, verification_manager::REVIEW_PASSED, false);
        $summary = session_verification_manager::summarize_submission_states([$subid], $built->session);
        $this->assertSame(session_verification_manager::STATE_PENDING_REVIEW, $summary[$subid]['state']);
        $this->expect_approve_error($enrolid, (int)$admin->id, 'error_session_vq_required_not_passed');

        $DB->set_field('local_tm_course_sess_vq_file', 'review_status', verification_manager::REVIEW_FAILED, [
            'submissionid' => $subid,
            'questionid' => $required,
        ]);
        $summary = session_verification_manager::summarize_submission_states([$subid], $built->session);
        $this->assertSame(session_verification_manager::STATE_REQUIRED_FAILED, $summary[$subid]['state']);
        $this->expect_approve_error($enrolid, (int)$admin->id, 'error_session_vq_required_not_passed');

        $this->store_file(72001);
        $DB->set_field('local_tm_course_sess_vq_file', 'review_status', verification_manager::REVIEW_PASSED, [
            'submissionid' => $subid,
            'questionid' => $required,
        ]);
        $summary = session_verification_manager::summarize_submission_states([$subid], $built->session);
        $this->assertSame(session_verification_manager::STATE_REQUIRED_PASSED, $summary[$subid]['state']);
        enrolment_manager::approve($enrolid, 1, (int)$admin->id);
        $this->assertSame(session_manager::ENROL_APPROVED, (int)$DB->get_field('local_tm_course_enrolments', 'status', ['id' => $enrolid]));
        $this->assertSame($subid, (int)$DB->get_field('local_tm_course_enrolments', 'vq_submission_id', ['id' => $enrolid]));
    }

    public function test_resume_list_is_only_the_original_open_batch(): void {
        global $DB;
        $this->resetAfterTest(true);
        $built = $this->insert_onsite_session();
        $actor = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $open = $this->add_submission($built->sessionid, session_verification_manager::SCOPE_BATCH, (int)$actor->id, 0);
        $this->add_submission($built->sessionid, session_verification_manager::SCOPE_BATCH, (int)$other->id, 0);
        $done = $this->add_submission($built->sessionid, session_verification_manager::SCOPE_BATCH, (int)$actor->id, 1);
        $this->add_submission($built->sessionid, session_verification_manager::SCOPE_SELF, (int)$actor->id, 0);

        $before = (int)$DB->count_records('local_tm_course_sess_vq_sub');
        $mine = session_verification_manager::list_actor_open_batch_submissions($built->sessionid, (int)$actor->id);
        $theirs = session_verification_manager::list_actor_open_batch_submissions($built->sessionid, (int)$other->id);
        $nobody = session_verification_manager::list_actor_open_batch_submissions($built->sessionid, 0);
        $this->assertSame($before, (int)$DB->count_records('local_tm_course_sess_vq_sub'));
        $this->assertCount(1, $mine);
        $this->assertArrayHasKey($open, $mine);
        $this->assertCount(1, $theirs);
        $this->assertArrayNotHasKey($open, $theirs);
        $this->assertSame([], $nobody);

        session_verification_manager::mark_submitted($open);
        $after = session_verification_manager::list_actor_open_batch_submissions($built->sessionid, (int)$actor->id);
        $this->assertSame([], $after);
        $this->assertSame(1, (int)$DB->get_field('local_tm_course_sess_vq_sub', 'submitted', ['id' => $done]));
    }

    public function test_edit_permission_stays_with_the_original_actor(): void {
        $actorid = 15;
        $otherid = 16;
        $batch = (object)[
            'scope' => session_verification_manager::SCOPE_BATCH,
            'actor_userid' => $actorid,
        ];
        $this->assertTrue(session_verification_manager::user_may_edit_batch_submission($batch, $actorid, false));
        $this->assertFalse(session_verification_manager::user_may_edit_batch_submission($batch, $otherid, false));
        $this->assertTrue(session_verification_manager::user_may_edit_batch_submission($batch, $otherid, true));

        $unknown = (object)[
            'scope' => session_verification_manager::SCOPE_BATCH,
            'actor_userid' => 0,
        ];
        $this->assertFalse(session_verification_manager::user_may_edit_batch_submission($unknown, $actorid, false));
        $this->assertTrue(session_verification_manager::user_may_edit_batch_submission($unknown, $actorid, true));

        $self = (object)[
            'scope' => session_verification_manager::SCOPE_SELF,
            'actor_userid' => $actorid,
        ];
        $this->assertFalse(session_verification_manager::user_may_edit_batch_submission($self, $actorid, false));
    }

    public function test_pages_keep_the_existing_review_and_resume_entries(): void {
        $root = dirname(__DIR__);
        $enrolments = file_get_contents($root . '/admin/enrolments.php');
        $batch = file_get_contents($root . '/batch_enrol.php');
        $form = file_get_contents($root . '/enrol_session_verification.php');
        $this->assertNotFalse($enrolments);
        $this->assertNotFalse($batch);
        $this->assertNotFalse($form);
        $this->assertSame(2, substr_count($enrolments, 'echo enrolments_render_vq_entry('));
        $this->assertSame(1, substr_count($enrolments, 'summarize_submission_states('));
        $this->assertStringContainsString('session_verification_review.php', $enrolments);
        $this->assertStringContainsString("get_string('session_vq_review_link_col'", $enrolments);
        $detail = file_get_contents($root . '/reservation/tracking_detail.php');
        $this->assertNotFalse($detail);
        $this->assertStringNotContainsString('list_actor_open_batch_submissions(', $batch);
        $this->assertStringNotContainsString('session_vq_resume_intro', $batch);
        $this->assertStringContainsString('enrol_session_verification.php', $batch);
        $this->assertSame(1, substr_count($batch, 'create_batch_submission('));
        $this->assertStringContainsString('user_may_edit_batch_submission(', $detail);
        $this->assertStringContainsString('session_vq_tracking_open', $detail);
        $this->assertStringContainsString('enrol_session_verification.php', $detail);
        $this->assertStringNotContainsString('$vqcell', $detail);
        $this->assertStringNotContainsString('batch_tracking_followup_where_hint', $detail);
        $this->assertStringContainsString('cancel_by_requester(', $detail);
        $this->assertStringContainsString('user_may_edit_batch_submission(', $form);
        $this->assertStringNotContainsString("has_capability('local/tm_course:approve'", $form);
    }

    private function expect_approve_error(int $enrolid, int $adminid, string $errorcode): void {
        try {
            enrolment_manager::approve($enrolid, 1, $adminid);
            $this->fail('Approval should stay blocked');
        } catch (\moodle_exception $ex) {
            $this->assertSame($errorcode, $ex->errorcode);
        }
    }

    private function insert_onsite_session(): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $start = time() + 7 * DAYSECS;
        $session = (object)[
            'courseid' => $course->id,
            'classroomid' => 0,
            'name' => 'VQ entry ' . $start,
            'teaching_language' => 'zh_tw',
            'delivery_mode' => session_manager::DELIVERY_ONSITE,
            'starttime' => $start,
            'endtime' => $start + 3600,
            'duration_hours' => 1,
            'num_desks' => 4,
            'persons_per_desk' => 2,
            'approval_mode' => session_manager::APPROVAL_MANUAL,
            'status' => session_manager::STATUS_OPEN,
            'auto_close_exempt' => 1,
            'session_kind' => session_manager::SESSION_KIND_STANDARD,
            'removed' => 0,
            'timecreated' => 1,
            'timemodified' => 1,
            'createdby' => 2,
        ];
        $session->id = (int)$DB->insert_record('local_tm_course_sessions', $session);
        return (object)[
            'sessionid' => (int)$session->id,
            'courseid' => (int)$course->id,
            'session' => $session,
        ];
    }

    private function add_question(int $courseid, string $mode, int $required): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('local_tm_course_vq_q', (object)[
            'courseid' => $courseid,
            'apply_mode' => $mode,
            'question_text' => 'Check ' . $mode . ' ' . $required . ' ' . $now,
            'is_required' => $required,
            'sortorder' => 10,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    private function add_submission(int $sessionid, string $scope, int $actorid, int $submitted): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('local_tm_course_sess_vq_sub', (object)[
            'sessionid' => $sessionid,
            'scope' => $scope,
            'actor_userid' => $actorid,
            'submitted' => $submitted,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    private function link_file(int $submissionid, int $questionid, int $itemid, int $status, bool $storefile): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_tm_course_sess_vq_file', (object)[
            'submissionid' => $submissionid,
            'questionid' => $questionid,
            'itemid' => $itemid,
            'review_status' => $status,
            'review_note' => '',
            'timereviewed' => $status === verification_manager::REVIEW_PENDING ? 0 : $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        if ($storefile && $itemid > 0) {
            $this->store_file($itemid);
        }
    }

    private function store_file(int $itemid): void {
        $fs = get_file_storage();
        $context = \context_system::instance();
        $existing = $fs->get_area_files(
            $context->id,
            'local_tm_course',
            verification_manager::FILEAREA,
            $itemid,
            'itemid',
            false
        );
        if ($existing) {
            return;
        }
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'local_tm_course',
            'filearea' => verification_manager::FILEAREA,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => 'proof-' . $itemid . '.pdf',
        ], 'proof');
    }
}
