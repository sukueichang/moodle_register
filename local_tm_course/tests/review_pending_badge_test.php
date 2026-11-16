<?php
/**
 * Pending-count badge for the enrolment and dedicated-class review entry.
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/lib.php');
require_once($CFG->dirroot . '/local/tm_course/classes/session_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/reservation_application.php');

/**
 * @covers \local_tm_course\session_manager::pending_enrolment_application_count
 * @covers \local_tm_course\reservation_application::pending_review_application_count
 * @covers \local_tm_course\reservation_application::is_pending_on_review_list
 * @covers ::local_tm_course_review_pending_badge_count
 * @covers ::local_tm_course_review_entry_presentation
 */
class review_pending_badge_test extends \advanced_testcase {

    public function test_review_list_pending_rule(): void {
        $this->assertSame([2], reservation_application::pending_review_excluded_statuses());
        $this->assertFalse(reservation_application::is_pending_on_review_list(2, false, 0, 0));
        $this->assertTrue(reservation_application::is_pending_on_review_list(0, false, 3, 0));
        $this->assertFalse(reservation_application::is_pending_on_review_list(1, true, 0, 0));
        $this->assertFalse(reservation_application::is_pending_on_review_list(1, true, 2, 0));
        $this->assertTrue(reservation_application::is_pending_on_review_list(1, true, 2, 1));
        // Status 3 is not a cancelled state in this branch's review page.
        $this->assertTrue(reservation_application::is_pending_on_review_list(3, false, 0, 0));
    }

    public function test_only_pending_enrolments_including_batch_rows(): void {
        $this->resetAfterTest(true);
        $session = $this->insert_session('Batch and self', 1000, 0, 0);
        $other = $this->insert_session('Closed statuses', 2000, 0, 0);
        $removed = $this->insert_session('Removed but pending', 3000, 0, 1);
        $sales = $this->getDataGenerator()->create_user();
        $learners = [];
        for ($i = 0; $i < 6; $i++) {
            $learners[] = $this->getDataGenerator()->create_user();
        }
        $this->insert_enrol($session, (int) $learners[0]->id, session_manager::ENROL_PENDING, 0, (int) $sales->id);
        $this->insert_enrol($session, (int) $learners[1]->id, session_manager::ENROL_PENDING, 0, (int) $sales->id);
        $this->insert_enrol($other, (int) $learners[2]->id, session_manager::ENROL_APPROVED, 0, 0);
        $this->insert_enrol($other, (int) $learners[3]->id, session_manager::ENROL_REJECTED, 0, 0);
        $this->insert_enrol($other, (int) $learners[4]->id, session_manager::ENROL_CANCELLED, 0, 0);
        $this->insert_enrol($other, (int) $learners[5]->id, session_manager::ENROL_WAITLISTED, 0, 0);
        $removeduser = $this->getDataGenerator()->create_user();
        $this->insert_enrol($removed, (int) $removeduser->id, session_manager::ENROL_PENDING, 0, 0);

        $this->assertSame(0, reservation_application::pending_review_application_count());
        $this->assertSame(3, session_manager::pending_enrolment_application_count());
        $this->assertSame(3, $this->badge_for_admin());
    }

    public function test_only_submitted_dedicated_class_applications(): void {
        $this->resetAfterTest(true);
        $requester = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->insert_reservation((int) $requester->id, 0, 1, (int) $course->id);
        $this->insert_reservation((int) $requester->id, 0, 0, (int) $course->id);
        $this->insert_reservation((int) $requester->id, 2, 1, (int) $course->id);
        $this->insert_reservation(0, 0, 1, (int) $course->id);

        $this->assertSame(0, session_manager::pending_enrolment_application_count());
        $this->assertSame(1, reservation_application::pending_review_application_count());
        $this->assertSame(1, $this->badge_for_admin());
    }

    public function test_both_queues_add_and_do_not_count_the_same_row_twice(): void {
        $this->resetAfterTest(true);
        $requester = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $session = $this->insert_session('Existing course', 1000, 0, 0);
        $this->insert_enrol($session, (int) $learner->id, session_manager::ENROL_PENDING, 0, (int) $requester->id);
        $this->insert_reservation((int) $requester->id, 0, 1, (int) $course->id);

        $this->assertSame(1, session_manager::pending_enrolment_application_count());
        $this->assertSame(1, reservation_application::pending_review_application_count());
        $this->assertSame(2, $this->badge_for_admin());
    }

    public function test_approved_class_with_pending_initial_learners_counts_both_lists(): void {
        $this->resetAfterTest(true);
        $requester = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $second = $this->getDataGenerator()->create_user();
        $reservationid = $this->insert_reservation((int) $requester->id, 1, 1, (int) $course->id);
        $this->insert_learner($reservationid, (int) $learner->id);
        $this->insert_learner($reservationid, (int) $second->id);
        $session = $this->insert_session('Created from class', 4000, $reservationid, 0);
        $othersession = $this->insert_session('Second block', 5000, $reservationid, 0);
        $first = $this->insert_enrol($session, (int) $learner->id, session_manager::ENROL_PENDING, 1, 0);
        $this->insert_enrol($session, (int) $second->id, session_manager::ENROL_PENDING, 1, 0);
        $this->insert_enrol($othersession, (int) $learner->id, session_manager::ENROL_PENDING, 1, 0);
        $this->insert_enrol($othersession, (int) $second->id, session_manager::ENROL_PENDING, 1, 0);

        // Four enrolment rows, one class application. The class is not counted once per learner.
        $this->assertSame(4, session_manager::pending_enrolment_application_count());
        $this->assertSame(1, reservation_application::pending_review_application_count());
        $this->assertSame(5, $this->badge_for_admin());

        global $DB;
        $DB->set_field('local_tm_course_enrolments', 'status', session_manager::ENROL_APPROVED, ['id' => $first]);
        $this->assertSame(3, session_manager::pending_enrolment_application_count());
        $this->assertSame(1, reservation_application::pending_review_application_count());
        $DB->set_field('local_tm_course_enrolments', 'status', session_manager::ENROL_APPROVED, [
            'reservation_initial_enrol' => 1,
        ]);
        $this->assertSame(0, session_manager::pending_enrolment_application_count());
        $this->assertSame(0, reservation_application::pending_review_application_count());
        $shown = $this->presentation_for_admin('報名與專屬開班審核');
        $this->assertSame('報名與專屬開班審核', $shown['label']);
        $this->assertFalse($shown['active']);
    }

    public function test_finished_cancelled_and_rejected_items_are_omitted(): void {
        $this->resetAfterTest(true);
        $requester = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $extra = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $session = $this->insert_session('Processed enrolments', 5000, 0, 0);
        $this->insert_enrol($session, (int) $learner->id, session_manager::ENROL_APPROVED, 0, 0);
        $this->insert_enrol($session, (int) $extra->id, session_manager::ENROL_CANCELLED, 0, 0);
        $rejected = $this->insert_reservation((int) $requester->id, 2, 1, (int) $course->id);
        $this->insert_learner($rejected, (int) $learner->id);
        $settled = $this->insert_reservation((int) $requester->id, 1, 1, (int) $course->id);
        $this->insert_learner($settled, (int) $learner->id);
        $settledsession = $this->insert_session('Initial learner approved', 6000, $settled, 0);
        $this->insert_enrol($settledsession, (int) $learner->id, session_manager::ENROL_APPROVED, 1, 0);
        $emptyclass = $this->insert_reservation((int) $requester->id, 1, 1, (int) $course->id);
        $this->insert_session('Approved with no learners', 7000, $emptyclass, 0);

        $this->assertSame(0, session_manager::pending_enrolment_application_count());
        $this->assertSame(0, reservation_application::pending_review_application_count());
        $shown = $this->presentation_for_admin('報名與專屬開班審核');
        $this->assertSame(0, $shown['count']);
        $this->assertSame('報名與專屬開班審核', $shown['label']);
        $this->assertFalse($shown['active']);
    }

    public function test_unclassified_status_three_stays_on_the_pending_list(): void {
        $this->resetAfterTest(true);
        $requester = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->insert_reservation((int) $requester->id, 3, 1, (int) $course->id);

        $this->assertSame(1, reservation_application::pending_review_application_count());
    }

    public function test_users_without_approve_do_not_receive_the_count(): void {
        $this->resetAfterTest(true);
        $session = $this->insert_session('Hidden from sales', 8000, 0, 0);
        $learner = $this->getDataGenerator()->create_user();
        $this->insert_enrol($session, (int) $learner->id, session_manager::ENROL_PENDING, 0, 0);
        $sales = $this->getDataGenerator()->create_user();
        $this->setUser($sales);
        $hidden = local_tm_course_review_entry_presentation('報名與專屬開班審核');
        $this->assertSame(0, $hidden['count']);
        $this->assertSame('報名與專屬開班審核', $hidden['label']);
        $this->assertFalse($hidden['active']);
        $this->assertSame(1, session_manager::pending_enrolment_application_count());

        $context = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/tm_course:manage', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $sales->id, $context->id);
        $this->setUser($sales);
        $this->assertSame(0, local_tm_course_review_pending_badge_count());

        $approver = $this->getDataGenerator()->create_user();
        $approverrole = $this->getDataGenerator()->create_role();
        assign_capability('local/tm_course:approve', CAP_ALLOW, $approverrole, $context->id, true);
        role_assign($approverrole, $approver->id, $context->id);
        $this->setUser($approver);
        $shown = local_tm_course_review_entry_presentation('報名與專屬開班審核');
        $this->assertSame(1, $shown['count']);
        $this->assertSame('報名與專屬開班審核 (1)', $shown['label']);
        $this->assertTrue($shown['active']);
    }

    private function badge_for_admin(): int {
        $this->setAdminUser();
        return local_tm_course_review_pending_badge_count();
    }

    private function presentation_for_admin(string $label): array {
        $this->setAdminUser();
        return local_tm_course_review_entry_presentation($label);
    }

    private function insert_session(string $name, int $starttime, int $reservationid, int $removed): int {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $record = (object) [
            'courseid' => $course->id,
            'classroomid' => 0,
            'name' => $name,
            'teaching_language' => 'zh_tw',
            'delivery_mode' => 'onsite',
            'starttime' => $starttime,
            'endtime' => $starttime + 3600,
            'duration_hours' => 1,
            'num_desks' => 1,
            'persons_per_desk' => 1,
            'approval_mode' => session_manager::APPROVAL_MANUAL,
            'status' => session_manager::STATUS_OPEN,
            'auto_close_exempt' => 1,
            'session_kind' => session_manager::SESSION_KIND_STANDARD,
            'removed' => $removed,
            'timecreated' => 1,
            'timemodified' => 1,
            'createdby' => 2,
        ];
        if ($reservationid > 0) {
            $record->source_reservation_id = $reservationid;
        }
        return (int) $DB->insert_record('local_tm_course_sessions', $record);
    }

    private function insert_enrol(
        int $sessionid,
        int $userid,
        int $status,
        int $initial,
        int $batchsubmittedby
    ): int {
        global $DB;
        $record = (object) [
            'sessionid' => $sessionid,
            'userid' => $userid,
            'status' => $status,
            'attended' => 0,
            'prereq_absence_hold' => 0,
            'diet_choice' => '',
            'timecreated' => 100,
            'timemodified' => 100,
            'sync_health' => 0,
            'batch_archived' => 0,
            'reservation_initial_enrol' => $initial,
        ];
        if ($batchsubmittedby > 0) {
            $record->batch_submittedby = $batchsubmittedby;
        }
        return (int) $DB->insert_record('local_tm_course_enrolments', $record);
    }

    private function insert_reservation(int $requesterid, int $status, int $submitted, int $courseid): int {
        global $DB;
        $now = 1000;
        return (int) $DB->insert_record('local_tm_course_reservation', (object) [
            'requesterid' => $requesterid,
            'courseid' => $courseid,
            'courseids_json' => json_encode([$courseid]),
            'delivery_mode' => 'onsite',
            'teaching_language' => 'zh_tw',
            'learner_source' => 'manual',
            'status' => $status,
            'preferred_starttime' => $now,
            'preferred_endtime' => $now + 3600,
            'timecreated' => $now,
            'timemodified' => $now,
            'calendar_onboarding_seen' => 0,
            'calendar_plan_submitted' => $submitted,
            'archived' => 0,
            'application_submitted' => $submitted,
            'timesubmitted' => $submitted ? $now : 0,
        ]);
    }

    private function insert_learner(int $reservationid, int $userid): void {
        global $DB;
        $DB->insert_record('local_tm_course_resv_learner', (object) [
            'reservationid' => $reservationid,
            'userid' => $userid,
            'source_type' => 'manual',
            'timecreated' => 1000,
        ]);
    }
}
