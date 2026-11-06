<?php
/**
 * Current enrolment status actor, time, and detail popup.
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/session_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/enrolment_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/enrolment_status_detail.php');
require_once($CFG->dirroot . '/local/tm_course/classes/attendance_manager.php');

/**
 * @covers \local_tm_course\enrolment_manager
 * @covers \local_tm_course\enrolment_status_detail
 */
class enrolment_status_detail_test extends \advanced_testcase {

    public function test_self_enrol_records_the_learner_and_reenrol_replaces_the_episode(): void {
        global $DB;
        $this->resetAfterTest(true);
        $learner = $this->learner();
        $manual = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $before = time();
        $enrolid = enrolment_manager::enrol($manual, (int) $learner->id, 'Techman');
        $row = $this->row($enrolid);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $row->status);
        $this->assertSame((int) $learner->id, (int) $row->status_actorid);
        $this->assertGreaterThanOrEqual($before, (int) $row->status_time);
        $created = (int) $row->timecreated;

        enrolment_manager::cancel($enrolid, (int) $learner->id, 'work', '');
        $DB->set_field('local_tm_course_enrolments', 'status_time', 10, ['id' => $enrolid]);
        $again = time();
        enrolment_manager::enrol($manual, (int) $learner->id, 'Techman');
        $row = $this->row($enrolid);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $row->status);
        $this->assertSame((int) $learner->id, (int) $row->status_actorid);
        $this->assertGreaterThanOrEqual($again, (int) $row->status_time);
        $this->assertNotSame(10, (int) $row->status_time);
        $this->assertSame($created, (int) $row->timecreated);
        $this->assertSame('', (string) $row->cancel_reason_code);
        $this->assertTrue($row->reject_reason === null || $row->reject_reason === '');
    }

    public function test_auto_approval_is_system_and_site_admin_batch_is_the_admin(): void {
        $this->resetAfterTest(true);
        $learner = $this->learner();
        $auto = $this->insert_session(session_manager::APPROVAL_AUTO);
        $enrolid = enrolment_manager::enrol($auto, (int) $learner->id, 'Techman');
        $row = $this->row($enrolid);
        $this->assertSame(session_manager::ENROL_APPROVED, (int) $row->status);
        $this->assertNotNull($row->status_actorid);
        $this->assertSame(0, (int) $row->status_actorid);

        $this->setAdminUser();
        global $USER;
        $adminid = (int) $USER->id;
        $batchsession = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $result = enrolment_manager::batch_enrol_pending($batchsession, $adminid, [
            ['userid' => (int) $learner->id],
        ]);
        $this->assertSame(1, (int) $result['processed'], json_encode($result['skipped']));
        $row = $this->row((int) $result['enrolment_ids'][0]);
        $this->assertSame(session_manager::ENROL_APPROVED, (int) $row->status);
        $this->assertSame($adminid, (int) $row->status_actorid);
        $this->assertNotSame(0, (int) $row->status_actorid);
    }

    public function test_batch_and_dedicated_class_record_the_submitter_not_the_learner(): void {
        $this->resetAfterTest(true);
        $learner = $this->learner();
        $sales = $this->getDataGenerator()->create_user(['institution' => 'Techman']);
        $requester = $this->getDataGenerator()->create_user(['institution' => 'Techman']);
        $session = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $dedicated = $this->insert_session(session_manager::APPROVAL_MANUAL);

        $salesresult = enrolment_manager::batch_enrol_pending($session, (int) $sales->id, [
            ['userid' => (int) $learner->id],
        ]);
        $this->assertSame(1, (int) $salesresult['processed'], json_encode($salesresult['skipped']));
        $row = $this->row((int) $salesresult['enrolment_ids'][0]);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $row->status);
        $this->assertSame((int) $sales->id, (int) $row->status_actorid);
        $this->assertNotSame((int) $learner->id, (int) $row->status_actorid);

        $other = $this->learner();
        $dedicatedresult = enrolment_manager::batch_enrol_pending($dedicated, (int) $requester->id, [[
            'userid' => (int) $other->id,
            'reservation_initial' => 1,
        ]]);
        $this->assertSame(1, (int) $dedicatedresult['processed'], json_encode($dedicatedresult['skipped']));
        $row = $this->row((int) $dedicatedresult['enrolment_ids'][0]);
        $this->assertSame(1, (int) $row->reservation_initial_enrol);
        $this->assertSame((int) $requester->id, (int) $row->status_actorid);
        $this->assertSame((int) $other->id, (int) $row->userid);
    }

    public function test_approve_reject_and_unapprove_record_the_actor_without_reusing_notes(): void {
        global $DB;
        $this->resetAfterTest(true);
        $learner = $this->learner();
        $session = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $admin = $this->getDataGenerator()->create_user();
        $enrolid = $this->insert_enrol($session, (int) $learner->id);
        $DB->set_field('local_tm_course_enrolments', 'notes', '原備註', ['id' => $enrolid]);

        enrolment_manager::approve($enrolid, 1, (int) $admin->id);
        $row = $this->row($enrolid);
        $this->assertSame(session_manager::ENROL_APPROVED, (int) $row->status);
        $this->assertSame((int) $admin->id, (int) $row->status_actorid);
        $this->assertGreaterThan(0, (int) $row->status_time);
        $this->assertSame('原備註', (string) $row->notes);

        $reverter = $this->getDataGenerator()->create_user();
        enrolment_manager::unapprove($enrolid, (int) $reverter->id);
        $row = $this->row($enrolid);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $row->status);
        $this->assertSame((int) $reverter->id, (int) $row->status_actorid);

        enrolment_manager::reject($enrolid, '座位不足', (int) $admin->id);
        $row = $this->row($enrolid);
        $this->assertSame(session_manager::ENROL_REJECTED, (int) $row->status);
        $this->assertSame((int) $admin->id, (int) $row->status_actorid);
        $this->assertSame('座位不足', (string) $row->reject_reason);
        $this->assertSame('座位不足', (string) $row->notes);

        $DB->set_field('local_tm_course_enrolments', 'status_time', 10, ['id' => $enrolid]);
        enrolment_manager::reject($enrolid, '第二次原因', (int) $reverter->id);
        $row = $this->row($enrolid);
        $this->assertSame(10, (int) $row->status_time);
        $this->assertSame((int) $admin->id, (int) $row->status_actorid);
        $this->assertSame('座位不足', (string) $row->reject_reason);
        $this->assertSame('第二次原因', (string) $row->notes);

        $again = time();
        enrolment_manager::enrol($session, (int) $learner->id, 'Techman');
        $row = $this->row($enrolid);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $row->status);
        $this->assertSame((int) $learner->id, (int) $row->status_actorid);
        $this->assertGreaterThanOrEqual($again, (int) $row->status_time);
        $this->assertTrue($row->reject_reason === null || $row->reject_reason === '');
        $this->assertSame('第二次原因', (string) $row->notes);
        $detail = enrolment_status_detail::present($row, '');
        $this->assertSame('', $detail['reasonlabel']);
    }

    public function test_repeat_approve_and_non_status_edits_keep_the_original_episode(): void {
        global $DB;
        $this->resetAfterTest(true);
        $learner = $this->learner();
        $admin = $this->getDataGenerator()->create_user();
        $session = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $enrolid = $this->insert_enrol($session, (int) $learner->id);
        enrolment_manager::approve($enrolid, 1, (int) $admin->id);
        $DB->set_field('local_tm_course_enrolments', 'status_actorid', $admin->id, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'status_time', 77, ['id' => $enrolid]);

        $other = $this->getDataGenerator()->create_user();
        enrolment_manager::approve($enrolid, 1, (int) $other->id);
        enrolment_manager::change_desk_assignment($enrolid, 1);
        enrolment_manager::update_diet_by_actor($enrolid, (int) $learner->id, ['choice' => 'A']);
        attendance_manager::mark_attended($enrolid, attendance_manager::ATTEND_ABSENT);

        $row = $this->row($enrolid);
        $this->assertSame(77, (int) $row->status_time);
        $this->assertSame((int) $admin->id, (int) $row->status_actorid);
        $this->assertSame('A', (string) $row->diet_choice);
        $this->assertSame(attendance_manager::ATTEND_ABSENT, (int) $row->attended);
    }

    public function test_all_cancel_paths_and_repeat_learner_cancel(): void {
        global $DB;
        $this->resetAfterTest(true);
        $learner = $this->learner();
        $sales = $this->getDataGenerator()->create_user(['institution' => 'Techman']);
        $session = $this->insert_session(session_manager::APPROVAL_MANUAL);

        $adminenrol = $this->insert_enrol($session, (int) $learner->id);
        $this->setAdminUser();
        global $USER;
        $admin = $USER;
        enrolment_manager::cancel_by_admin($adminenrol, (int) $admin->id, '臨時');
        $row = $this->row($adminenrol);
        $this->assertSame(session_manager::ENROL_CANCELLED, (int) $row->status);
        $this->assertSame((int) $admin->id, (int) $row->status_actorid);
        $this->assertSame('admin', (string) $row->cancel_reason_code);
        $this->assertSame('臨時', (string) $row->cancel_reason_text);
        $DB->set_field('local_tm_course_enrolments', 'status_time', 10, ['id' => $adminenrol]);
        try {
            enrolment_manager::cancel_by_admin($adminenrol, (int) $admin->id, '再取消');
            $this->fail('A second admin cancel should still be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_batch_cancel_terminal', $e->errorcode);
        }
        $row = $this->row($adminenrol);
        $this->assertSame(10, (int) $row->status_time);
        $this->assertSame('臨時', (string) $row->cancel_reason_text);

        $self = $this->insert_enrol($session, (int) $this->learner()->id);
        $selfuser = (int) $this->row($self)->userid;
        enrolment_manager::cancel($self, $selfuser, 'other', '改期');
        $DB->set_field('local_tm_course_enrolments', 'status_time', 11, ['id' => $self]);
        enrolment_manager::cancel($self, $selfuser, 'work', '');
        $row = $this->row($self);
        $this->assertSame($selfuser, (int) $row->status_actorid);
        $this->assertSame(11, (int) $row->status_time);
        $this->assertSame('other', (string) $row->cancel_reason_code);
        $this->assertSame('改期', (string) $row->cancel_reason_text);

        $batchlearner = $this->learner();
        $batchsession = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $batch = enrolment_manager::batch_enrol_pending($batchsession, (int) $sales->id, [
            ['userid' => (int) $batchlearner->id],
        ]);
        $this->assertSame(1, (int) $batch['processed'], json_encode($batch['skipped']));
        $batchid = (int) $batch['enrolment_ids'][0];
        enrolment_manager::cancel_by_batch_submitter($batchid, (int) $sales->id);
        $row = $this->row($batchid);
        $this->assertSame((int) $sales->id, (int) $row->status_actorid);
        $this->assertSame('batch_submitter', (string) $row->cancel_reason_code);

        $dependent = $this->insert_enrol($session, (int) $this->learner()->id);
        $method = new \ReflectionMethod(enrolment_manager::class, 'cancel_due_to_prereq_cascade');
        $method->setAccessible(true);
        $method->invoke(null, $dependent, $adminenrol, '先修場次A');
        $row = $this->row($dependent);
        $this->assertSame(session_manager::ENROL_CANCELLED, (int) $row->status);
        $this->assertNotNull($row->status_actorid);
        $this->assertSame(0, (int) $row->status_actorid);
        $this->assertSame('prereq_cascade', (string) $row->cancel_reason_code);
        $this->assertSame('先修場次A', (string) $row->cancel_reason_text);
        $DB->set_field('local_tm_course_enrolments', 'status_time', 12, ['id' => $dependent]);
        $method->invoke(null, $dependent, $adminenrol, '不該覆寫');
        $row = $this->row($dependent);
        $this->assertSame(12, (int) $row->status_time);
        $this->assertSame('先修場次A', (string) $row->cancel_reason_text);
    }

    public function test_actor_without_capability_cannot_admin_cancel(): void {
        $this->resetAfterTest(true);
        $learner = $this->learner();
        $session = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $enrolid = $this->insert_enrol($session, (int) $learner->id);
        try {
            enrolment_manager::cancel_by_admin($enrolid, (int) $learner->id, '');
            $this->fail('Learner must not gain admin cancel');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $row = $this->row($enrolid);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $row->status);
        $this->assertTrue($row->status_actorid === null || $row->status_actorid === '');
    }

    public function test_old_null_episode_displays_not_recorded_and_keeps_cancel_code(): void {
        $notrecorded = get_string('statusdetail_not_recorded', 'local_tm_course');
        $legacy = (object) [
            'status' => session_manager::ENROL_CANCELLED,
            'status_actorid' => null,
            'status_time' => null,
            'timecreated' => 999,
            'timemodified' => 999,
            'notes' => '不是取消原因',
            'reject_reason' => null,
            'cancel_reason_code' => 'work',
            'cancel_reason_text' => '夜班',
        ];
        $detail = enrolment_status_detail::present($legacy, '不該用的名字');
        $this->assertSame($notrecorded, $detail['actor']);
        $this->assertSame($notrecorded, $detail['time']);
        $this->assertStringContainsString(get_string('cancel_detail_work', 'local_tm_course'), $detail['reason']);
        $this->assertStringContainsString('夜班', $detail['reason']);
        $this->assertStringNotContainsString('不是取消原因', $detail['reason']);

        $rejected = (object) [
            'status' => session_manager::ENROL_REJECTED,
            'status_actorid' => null,
            'status_time' => null,
            'notes' => '舊備註',
            'reject_reason' => null,
            'cancel_reason_code' => '',
            'cancel_reason_text' => '',
        ];
        $detail = enrolment_status_detail::present($rejected, '');
        $this->assertSame($notrecorded, $detail['actor']);
        $this->assertSame($notrecorded, $detail['time']);
        $this->assertSame($notrecorded, $detail['reason']);
        $this->assertStringNotContainsString('舊備註', $detail['reason']);

        $unknown = enrolment_status_detail::cancel_reason_text('mystery', '');
        $this->assertSame($notrecorded, $unknown);
        $prereq = enrolment_status_detail::cancel_reason_text('prereq_cascade', '先修場次A');
        $this->assertStringContainsString(get_string('cancel_detail_prereq', 'local_tm_course'), $prereq);
        $this->assertStringContainsString('先修場次A', $prereq);
        $admin = enrolment_status_detail::cancel_reason_text('admin', '臨時');
        $this->assertStringContainsString(get_string('cancel_detail_admin', 'local_tm_course'), $admin);
        $this->assertStringContainsString('臨時', $admin);
    }

    public function test_popup_escapes_text_and_page_assets_stay_in_scope(): void {
        $escaped = enrolment_status_detail::escape('<img src=x onerror=alert(1)>');
        $this->assertStringContainsString('&lt;img', $escaped);
        $this->assertStringNotContainsString('<img', $escaped);

        $root = dirname(__DIR__);
        $js = file_get_contents($root . '/admin/enrolment_status_detail.js');
        $css = file_get_contents($root . '/styles.css');
        $page = file_get_contents($root . '/admin/enrolments.php');
        $upgrade = file_get_contents($root . '/db/upgrade.php');
        $zh = file_get_contents($root . '/lang/zh_tw/local_tm_course.php');

        $this->assertStringNotContainsString('innerHTML', $js);
        $this->assertStringContainsString('textContent', $js);
        $this->assertStringContainsString('Escape', $js);
        $this->assertStringContainsString('100dvh', $css);
        $this->assertStringContainsString('95vw', $css);
        $this->assertStringContainsString('tm-status-detail-trigger', $page);
        $this->assertStringContainsString('type="button"', $page);
        $this->assertStringContainsString('enrolment_status_detail::escape', $page);
        $this->assertStringContainsString('2026110601', $upgrade);
        $this->assertStringNotContainsString('SET status_actorid', $upgrade);
        foreach ([
            '管理員取消',
            '工作因素',
            '改報其他場次',
            '其他原因',
            '批次提交者取消',
            '先修條件失效，自動取消',
            '系統自動核准',
            '系統自動取消',
            '未記錄',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $zh);
        }
    }

    private function learner(): \stdClass {
        return $this->getDataGenerator()->create_user(['institution' => 'Techman']);
    }

    private function insert_session(int $approvalmode): int {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $start = time() + 7 * DAYSECS;
        return (int) $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $course->id,
            'classroomid' => 0,
            'name' => 'Status detail ' . $start . ' ' . $approvalmode,
            'teaching_language' => 'zh_tw',
            'delivery_mode' => 'onsite',
            'starttime' => $start,
            'endtime' => $start + 3600,
            'duration_hours' => 1,
            'num_desks' => 4,
            'persons_per_desk' => 2,
            'approval_mode' => $approvalmode,
            'status' => session_manager::STATUS_OPEN,
            'auto_close_exempt' => 1,
            'session_kind' => session_manager::SESSION_KIND_STANDARD,
            'removed' => 0,
            'timecreated' => 1,
            'timemodified' => 1,
            'createdby' => 2,
        ]);
    }

    private function insert_enrol(int $sessionid, int $userid): int {
        global $DB;
        return (int) $DB->insert_record('local_tm_course_enrolments', (object) [
            'sessionid' => $sessionid,
            'userid' => $userid,
            'status' => session_manager::ENROL_PENDING,
            'attended' => 0,
            'prereq_absence_hold' => 0,
            'diet_choice' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'sync_health' => 0,
            'batch_archived' => 0,
            'reservation_initial_enrol' => 0,
        ]);
    }

    private function row(int $enrolid): \stdClass {
        global $DB;
        return $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST);
    }
}
