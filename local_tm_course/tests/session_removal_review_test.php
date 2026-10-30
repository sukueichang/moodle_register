<?php
/**
 * Review-list application dates, and enrolment retention after session removal.
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/session_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/enrolment_manager.php');

/**
 * @covers \local_tm_course\session_manager::review_row_applied_at
 * @covers \local_tm_course\session_manager::get_enrolment_review_rows
 * @covers \local_tm_course\session_manager::delete_session
 * @covers \local_tm_course\enrolment_manager::approve
 * @covers \local_tm_course\enrolment_manager::reject
 * @covers \local_tm_course\enrolment_manager::cancel_by_admin
 */
class session_removal_review_test extends \advanced_testcase {

    public function test_review_date_uses_submission_time_for_the_filtered_status(): void {
        $row = (object) [
            'starttime' => 9000,
            'pending_applied_at' => 100,
            'approved_applied_at' => 300,
            'rejected_applied_at' => 50,
        ];
        $this->assertSame(100, session_manager::review_row_applied_at(session_manager::ENROL_PENDING, $row));
        $this->assertSame(300, session_manager::review_row_applied_at(session_manager::ENROL_APPROVED, $row));
        $this->assertSame(50, session_manager::review_row_applied_at(session_manager::ENROL_REJECTED, $row));
        $this->assertNotSame(9000, session_manager::review_row_applied_at(session_manager::ENROL_PENDING, $row));
    }

    public function test_aggregated_row_keeps_every_applicant_and_sorts_by_newest_submission(): void {
        $this->resetAfterTest(true);
        $early = $this->insert_session('Early start, later applications', 5000);
        $late = $this->insert_session('Later start, older application', 9000);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $this->insert_enrol($early, (int) $user1->id, session_manager::ENROL_PENDING, 100, 8000);
        $this->insert_enrol($early, (int) $user2->id, session_manager::ENROL_PENDING, 400, 100);
        $this->insert_enrol($late, (int) $user3->id, session_manager::ENROL_PENDING, 200, 9000);

        $rows = session_manager::get_enrolment_review_rows(session_manager::ENROL_PENDING);
        $this->assertCount(2, $rows);
        $this->assertSame($early, (int) $rows[0]->id);
        $this->assertSame(400, (int) $rows[0]->applied_at);
        $this->assertSame(2, (int) $rows[0]->pending_count);
        $this->assertNotSame(5000, (int) $rows[0]->applied_at);
        $this->assertSame($late, (int) $rows[1]->id);
        $this->assertSame(200, (int) $rows[1]->applied_at);
        $this->assertSame('Early start, later applications', session_manager::session_history_name($rows[0]));
    }

    public function test_delete_keeps_enrolments_name_and_status_and_blocks_writes(): void {
        global $DB;
        $this->resetAfterTest(true);
        $sessionid = $this->insert_session('Kept historical name', 1111);
        $user = $this->getDataGenerator()->create_user();
        $enrolid = $this->insert_enrol($sessionid, (int) $user->id, session_manager::ENROL_PENDING, 222, 333);
        $this->setAdminUser();
        global $USER;

        session_manager::delete_session($sessionid);

        $session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST);
        $this->assertSame(1, (int) $session->removed);
        $this->assertSame('Kept historical name', (string) $session->name);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $enrol->status);
        $this->assertSame(222, (int) $enrol->timecreated);
        $this->assertSame(1, $DB->count_records('local_tm_course_enrolments', ['sessionid' => $sessionid]));

        $rows = session_manager::get_enrolment_review_rows(session_manager::ENROL_PENDING);
        $this->assertCount(1, $rows);
        $this->assertSame($sessionid, (int) $rows[0]->id);
        $this->assertSame(1, (int) $rows[0]->removed);
        $this->assertSame(222, (int) $rows[0]->applied_at);
        $this->assertSame('Kept historical name', session_manager::session_history_name($rows[0]));

        $listed = session_manager::get_sessions();
        $this->assertArrayNotHasKey($sessionid, $listed);

        $this->assert_write_refused(function() use ($enrolid) {
            enrolment_manager::approve($enrolid, 1);
        });
        $this->assert_write_refused(function() use ($enrolid) {
            enrolment_manager::reject($enrolid, 'no');
        });
        $this->assert_write_refused(function() use ($enrolid, $USER) {
            enrolment_manager::cancel_by_admin($enrolid, (int) $USER->id, 'no');
        });
        $this->assert_write_refused(function() use ($sessionid) {
            session_manager::update_session($sessionid, [
                'courseid' => 1,
                'name' => 'renamed',
                'starttime' => 10,
                'endtime' => 20,
                'duration_hours' => 1,
                'num_desks' => 1,
                'persons_per_desk' => 1,
            ]);
        });

        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST);
        $session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $enrol->status);
        $this->assertNotSame(session_manager::ENROL_REJECTED, (int) $enrol->status);
        $this->assertSame('Kept historical name', (string) $session->name);
    }

    public function test_delete_without_enrolments_removes_the_session_row(): void {
        global $DB;
        $this->resetAfterTest(true);
        $sessionid = $this->insert_session('Empty session', 10);
        session_manager::delete_session($sessionid);
        $this->assertFalse($DB->record_exists('local_tm_course_sessions', ['id' => $sessionid]));
    }

    public function test_missing_session_is_not_writable(): void {
        $this->resetAfterTest(true);
        $this->assert_write_refused(function() {
            session_manager::assert_enrolment_writable(0);
        });
        $this->assert_write_refused(function() {
            session_manager::assert_enrolment_writable(999999);
        });
    }

    private function insert_session(string $name, int $starttime): int {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        return (int) $DB->insert_record('local_tm_course_sessions', (object) [
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
            'removed' => 0,
            'timecreated' => 1,
            'timemodified' => 1,
            'createdby' => 2,
        ]);
    }

    private function insert_enrol(int $sessionid, int $userid, int $status, int $timecreated, int $timemodified): int {
        global $DB;
        return (int) $DB->insert_record('local_tm_course_enrolments', (object) [
            'sessionid' => $sessionid,
            'userid' => $userid,
            'status' => $status,
            'attended' => 0,
            'prereq_absence_hold' => 0,
            'diet_choice' => '',
            'timecreated' => $timecreated,
            'timemodified' => $timemodified,
            'sync_health' => 0,
            'batch_archived' => 0,
            'reservation_initial_enrol' => 0,
        ]);
    }

    private function assert_write_refused(callable $fn): void {
        try {
            $fn();
            $this->fail('Write against a removed session should be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_session_removed_readonly', $e->errorcode);
        }
    }
}
