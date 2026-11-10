<?php
/**
 * Forward-only enrolment action history.
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

/**
 * @covers \local_tm_course\enrolment_manager
 * @covers \local_tm_course\enrolment_status_detail
 */
class enrolment_log_test extends \advanced_testcase {

    protected function tearDown(): void {
        enrolment_manager::$phpunit_throw_before_log_commit = false;
        parent::tearDown();
    }

    public function test_each_transition_writes_one_log_for_that_enrolment(): void {
        $this->resetAfterTest(true);
        $learner = $this->learner('Self', 'Learner');
        $other = $this->learner('Other', 'Learner');
        $sales = $this->getDataGenerator()->create_user([
            'firstname' => 'Sales',
            'lastname' => 'User',
            'institution' => 'Techman',
        ]);
        $requester = $this->getDataGenerator()->create_user([
            'firstname' => 'Class',
            'lastname' => 'Requester',
            'institution' => 'Techman',
        ]);
        $admin = $this->getDataGenerator()->create_user([
            'firstname' => 'Review',
            'lastname' => 'Admin',
        ]);

        $manual = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $selfid = enrolment_manager::enrol($manual, (int) $learner->id, 'Techman');
        $self = $this->logs($selfid);
        $this->assertCount(1, $self);
        $this->assertSame($selfid, (int) $self[0]->enrolmentid);
        $this->assertSame('apply', $self[0]->action);
        $this->assertNull($self[0]->oldstatus);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $self[0]->newstatus);
        $this->assertSame((int) $learner->id, (int) $self[0]->actorid);
        $this->assertSame((int) $this->row($selfid)->status_time, (int) $self[0]->timecreated);

        $auto = $this->insert_session(session_manager::APPROVAL_AUTO);
        $autoid = enrolment_manager::enrol($auto, (int) $learner->id, 'Techman');
        $autolog = $this->logs($autoid);
        $this->assertCount(1, $autolog);
        $this->assertSame('approve', $autolog[0]->action);
        $this->assertNotNull($autolog[0]->actorid);
        $this->assertSame(0, (int) $autolog[0]->actorid);
        $this->assertSame((int) $this->row($autoid)->status_time, (int) $autolog[0]->timecreated);

        $batchsession = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $batch = enrolment_manager::batch_enrol_pending($batchsession, (int) $sales->id, [
            ['userid' => (int) $other->id],
        ]);
        $this->assertSame(1, (int) $batch['processed'], json_encode($batch['skipped']));
        $batchid = (int) $batch['enrolment_ids'][0];
        $batchlog = $this->logs($batchid);
        $this->assertCount(1, $batchlog);
        $this->assertSame('apply', $batchlog[0]->action);
        $this->assertSame((int) $sales->id, (int) $batchlog[0]->actorid);
        $this->assertNotSame((int) $other->id, (int) $batchlog[0]->actorid);

        $dedicated = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $dedicatedresult = enrolment_manager::batch_enrol_pending($dedicated, (int) $requester->id, [[
            'userid' => (int) $learner->id,
            'reservation_initial' => 1,
        ]]);
        $this->assertSame(1, (int) $dedicatedresult['processed'], json_encode($dedicatedresult['skipped']));
        $dedicatedid = (int) $dedicatedresult['enrolment_ids'][0];
        $dedicatedlog = $this->logs($dedicatedid);
        $this->assertSame('apply', $dedicatedlog[0]->action);
        $this->assertSame((int) $requester->id, (int) $dedicatedlog[0]->actorid);
        $this->assertNotSame((int) $learner->id, (int) $dedicatedlog[0]->actorid);

        $this->setAdminUser();
        global $USER;
        $siteadmin = (int) $USER->id;
        $immediatesession = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $immediate = enrolment_manager::batch_enrol_pending($immediatesession, $siteadmin, [
            ['userid' => (int) $other->id],
        ]);
        $this->assertSame(1, (int) $immediate['processed'], json_encode($immediate['skipped']));
        $immediateid = (int) $immediate['enrolment_ids'][0];
        $immediatelog = $this->logs($immediateid);
        $this->assertCount(1, $immediatelog);
        $this->assertSame('approve', $immediatelog[0]->action);
        $this->assertSame($siteadmin, (int) $immediatelog[0]->actorid);
        $this->assertNotSame(0, (int) $immediatelog[0]->actorid);

        $reviewsession = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $reviewid = $this->insert_enrol($reviewsession, (int) $learner->id);
        $this->assertSame([], $this->logs($reviewid));
        enrolment_manager::approve($reviewid, 1, (int) $admin->id);
        enrolment_manager::change_desk_assignment($reviewid, 2);
        enrolment_manager::approve($reviewid, 2, (int) $admin->id);
        $reviewlogs = $this->logs($reviewid);
        $this->assertCount(1, $reviewlogs);
        $this->assertSame('approve', $reviewlogs[0]->action);
        $this->assertSame((int) $admin->id, (int) $reviewlogs[0]->actorid);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $reviewlogs[0]->oldstatus);
        $this->assertSame((int) $this->row($reviewid)->status_time, (int) $reviewlogs[0]->timecreated);

        enrolment_manager::unapprove($reviewid, (int) $admin->id);
        $reviewlogs = $this->logs($reviewid);
        $this->assertCount(2, $reviewlogs);
        $this->assertSame('unapprove', $reviewlogs[1]->action);
        $this->assertSame((int) $admin->id, (int) $reviewlogs[1]->actorid);
        $this->assertSame(session_manager::ENROL_APPROVED, (int) $reviewlogs[1]->oldstatus);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $reviewlogs[1]->newstatus);

        enrolment_manager::reject($reviewid, '名額已滿', (int) $admin->id);
        enrolment_manager::reject($reviewid, '第二次', (int) $admin->id);
        $reviewlogs = $this->logs($reviewid);
        $this->assertCount(3, $reviewlogs);
        $this->assertSame('reject', $reviewlogs[2]->action);
        $this->assertSame('名額已滿', (string) $reviewlogs[2]->reason_text);
        $this->assertSame('第二次', (string) $this->row($reviewid)->notes);

        $shown = enrolment_status_detail::histories_for_enrolments([$selfid, $batchid]);
        $this->assertSame(get_string('enrol_log_apply', 'local_tm_course'), $shown[$selfid][0]['action']);
        $this->assertStringContainsString('Self', $shown[$selfid][0]['actor']);
        $this->assertStringContainsString('Sales', $shown[$batchid][0]['actor']);
        $this->assertStringNotContainsString('Sales', $shown[$selfid][0]['actor']);
        $this->assertStringNotContainsString('Self', $shown[$batchid][0]['actor']);

        $autoshow = enrolment_status_detail::histories_for_enrolments([$autoid]);
        $this->assertSame(
            get_string('statusdetail_system_approved', 'local_tm_course'),
            $autoshow[$autoid][0]['actor']
        );
    }

    public function test_reenrol_keeps_earlier_rows_and_appends_one(): void {
        $this->resetAfterTest(true);
        $learner = $this->learner('Again', 'Learner');
        $admin = $this->getDataGenerator()->create_user();
        $session = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $enrolid = enrolment_manager::enrol($session, (int) $learner->id, 'Techman');

        enrolment_manager::cancel($enrolid, (int) $learner->id, 'work', '');
        enrolment_manager::cancel($enrolid, (int) $learner->id, 'other', '不該再寫');
        enrolment_manager::enrol($session, (int) $learner->id, 'Techman');
        $logs = $this->logs($enrolid);
        $this->assertCount(3, $logs);
        $this->assertSame('apply', $logs[0]->action);
        $this->assertSame('cancel', $logs[1]->action);
        $this->assertSame((int) $learner->id, (int) $logs[1]->actorid);
        $this->assertSame('work', (string) $logs[1]->reason_code);
        $this->assertSame('reenrol', $logs[2]->action);
        $this->assertSame(session_manager::ENROL_CANCELLED, (int) $logs[2]->oldstatus);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $logs[2]->newstatus);
        $this->assertSame((int) $this->row($enrolid)->status_time, (int) $logs[2]->timecreated);

        $rejected = enrolment_manager::enrol(
            $this->insert_session(session_manager::APPROVAL_MANUAL),
            (int) $learner->id,
            'Techman'
        );
        enrolment_manager::reject($rejected, '不符資格', (int) $admin->id);
        enrolment_manager::enrol(
            (int) $this->row($rejected)->sessionid,
            (int) $learner->id,
            'Techman'
        );
        $rejectedlogs = $this->logs($rejected);
        $this->assertCount(3, $rejectedlogs);
        $this->assertSame('reject', $rejectedlogs[1]->action);
        $this->assertSame('reenrol', $rejectedlogs[2]->action);
        $this->assertSame(session_manager::ENROL_REJECTED, (int) $rejectedlogs[2]->oldstatus);
    }

    public function test_cancel_paths_record_the_real_actor_and_ignore_repeats(): void {
        $this->resetAfterTest(true);
        $learner = $this->learner('Cancel', 'Learner');
        $sales = $this->getDataGenerator()->create_user(['institution' => 'Techman']);
        $session = $this->insert_session(session_manager::APPROVAL_MANUAL);

        $this->setAdminUser();
        global $USER;
        $adminid = (int) $USER->id;
        $adminenrol = enrolment_manager::enrol($session, (int) $learner->id, 'Techman');
        enrolment_manager::cancel_by_admin($adminenrol, $adminid, '臨時');
        try {
            enrolment_manager::cancel_by_admin($adminenrol, $adminid, '再取消');
            $this->fail('A second admin cancel should still be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_batch_cancel_terminal', $e->errorcode);
        }
        $adminlogs = $this->logs($adminenrol);
        $this->assertCount(2, $adminlogs);
        $this->assertSame('cancel', $adminlogs[1]->action);
        $this->assertSame($adminid, (int) $adminlogs[1]->actorid);
        $this->assertSame('admin', (string) $adminlogs[1]->reason_code);
        $this->assertSame('臨時', (string) $adminlogs[1]->reason_text);

        $self = enrolment_manager::enrol($session, (int) $this->learner('Own', 'Cancel')->id, 'Techman');
        $selfuser = (int) $this->row($self)->userid;
        enrolment_manager::cancel($self, $selfuser, 'other_session', '');
        $selflogs = $this->logs($self);
        $this->assertSame('cancel', $selflogs[1]->action);
        $this->assertSame($selfuser, (int) $selflogs[1]->actorid);
        $this->assertSame('other_session', (string) $selflogs[1]->reason_code);

        $batchsession = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $batch = enrolment_manager::batch_enrol_pending($batchsession, (int) $sales->id, [
            ['userid' => (int) $learner->id],
        ]);
        $batchid = (int) $batch['enrolment_ids'][0];
        enrolment_manager::cancel_by_batch_submitter($batchid, (int) $sales->id);
        $batchlogs = $this->logs($batchid);
        $this->assertSame('cancel', $batchlogs[1]->action);
        $this->assertSame((int) $sales->id, (int) $batchlogs[1]->actorid);
        $this->assertSame('batch_submitter', (string) $batchlogs[1]->reason_code);

        $dependent = $this->insert_enrol($session, (int) $this->learner('Cascade', 'Learner')->id);
        $method = new \ReflectionMethod(enrolment_manager::class, 'cancel_due_to_prereq_cascade');
        $method->setAccessible(true);
        $method->invoke(null, $dependent, $adminenrol, '先修場次A');
        $method->invoke(null, $dependent, $adminenrol, '不該覆寫');
        $cascadelogs = $this->logs($dependent);
        $this->assertCount(1, $cascadelogs);
        $this->assertSame('cancel', $cascadelogs[0]->action);
        $this->assertNotNull($cascadelogs[0]->actorid);
        $this->assertSame(0, (int) $cascadelogs[0]->actorid);
        $this->assertSame('prereq_cascade', (string) $cascadelogs[0]->reason_code);
        $this->assertSame('先修場次A', (string) $cascadelogs[0]->reason_text);
        $shown = enrolment_status_detail::histories_for_enrolments([$dependent, $adminenrol]);
        $this->assertSame(
            get_string('statusdetail_system_cancelled', 'local_tm_course'),
            $shown[$dependent][0]['actor']
        );
        $this->assertStringContainsString('先修場次A', $shown[$dependent][0]['reason']);
        $this->assertStringNotContainsString('先修場次A', $shown[$adminenrol][0]['reason']);
    }

    public function test_failed_write_does_not_leave_status_and_log_apart(): void {
        global $DB;
        $this->resetAfterTest(true);
        $learner = $this->learner('Rollback', 'Learner');
        $session = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $enrolid = $this->insert_enrol($session, (int) $learner->id);
        $admin = $this->getDataGenerator()->create_user();
        enrolment_manager::$phpunit_throw_before_log_commit = true;
        try {
            enrolment_manager::approve($enrolid, 1, (int) $admin->id);
            $this->fail('The forced failure must abort the status transaction');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('forced failure', $e->getMessage());
        } finally {
            enrolment_manager::$phpunit_throw_before_log_commit = false;
        }
        $still = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid]);
        if ($still) {
            $this->assertSame(session_manager::ENROL_PENDING, (int) $still->status);
        }
        $this->assertSame([], $DB->get_records('local_tm_course_enrol_logs', ['enrolmentid' => $enrolid]));
    }

    public function test_old_rows_have_no_invented_history(): void {
        $this->resetAfterTest(true);
        $learner = $this->learner('Legacy', 'Learner');
        $session = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $enrolid = $this->insert_enrol($session, (int) $learner->id);
        $this->assertSame([], $this->logs($enrolid));
        $legacy = $this->row($enrolid);
        $legacy->status_actorid = null;
        $legacy->status_time = null;
        $detail = enrolment_status_detail::present($legacy, '不該變成歷程');
        $this->assertSame(get_string('statusdetail_not_recorded', 'local_tm_course'), $detail['actor']);
        $this->assertSame(get_string('statusdetail_not_recorded', 'local_tm_course'), $detail['time']);
        $history = enrolment_status_detail::histories_for_enrolments([$enrolid]);
        $this->assertSame([], $history[$enrolid]);
    }

    public function test_repeat_enrol_of_an_active_row_only_updates_diet(): void {
        $this->resetAfterTest(true);
        $learner = $this->learner('Diet', 'Only');
        $session = $this->insert_session(session_manager::APPROVAL_MANUAL);
        $enrolid = enrolment_manager::enrol($session, (int) $learner->id, 'Techman');
        $before = (int) $this->row($enrolid)->status_time;
        $again = enrolment_manager::enrol($session, (int) $learner->id, 'Techman', [
            'choice' => 'A',
            'special_note' => '不辣',
        ]);
        $row = $this->row($enrolid);
        $this->assertSame($enrolid, $again);
        $this->assertSame(session_manager::ENROL_PENDING, (int) $row->status);
        $this->assertSame($before, (int) $row->status_time);
        $this->assertSame('A', (string) $row->diet_choice);
        $logs = $this->logs($enrolid);
        $this->assertCount(1, $logs);
        $this->assertSame('apply', $logs[0]->action);
    }

    public function test_history_markup_stays_escaped_and_scoped(): void {
        $root = dirname(__DIR__);
        $js = file_get_contents($root . '/admin/enrolment_status_detail.js');
        $css = file_get_contents($root . '/styles.css');
        $page = file_get_contents($root . '/admin/enrolments.php');
        $upgrade = file_get_contents($root . '/db/upgrade.php');
        $zh = file_get_contents($root . '/lang/zh_tw/local_tm_course.php');
        $manager = file_get_contents($root . '/classes/enrolment_manager.php');

        $this->assertStringNotContainsString('innerHTML', $js);
        $this->assertStringContainsString('textContent', $js);
        $this->assertStringContainsString('createElement', $js);
        $this->assertStringContainsString('Escape', $js);
        $this->assertStringContainsString('data-history', $js);
        $this->assertStringContainsString('100dvh', $css);
        $this->assertStringContainsString('95vw', $css);
        $this->assertStringContainsString('data-history', $page);
        $this->assertStringContainsString('local/tm_course:approve', $page);
        $this->assertStringContainsString('enrolment_status_detail::escape', $page);
        $this->assertStringNotContainsString('sync_moodle_enrolment', $this->function_body($manager, 'transaction_write'));
        $this->assertStringNotContainsString('notify_', $this->function_body($manager, 'transaction_write'));

        $start = strpos($upgrade, 'if ($oldversion < 2026111000)');
        $end = strpos($upgrade, 'return true;', $start);
        $block = substr($upgrade, $start, $end - $start);
        $this->assertStringContainsString('create_table', $block);
        $this->assertStringContainsString('local_tm_course_enrol_logs', $block);
        $this->assertStringNotContainsString('UPDATE', $block);
        $this->assertStringNotContainsString('SET status_actorid', $block);

        foreach (['操作歷程', '尚無操作歷程紀錄', '申請', '核准', '拒絕', '取消', '重新報名', '退回待審核', '系統'] as $phrase) {
            $this->assertStringContainsString($phrase, $zh);
        }
    }

    private function learner(string $firstname, string $lastname): \stdClass {
        return $this->getDataGenerator()->create_user([
            'firstname' => $firstname,
            'lastname' => $lastname,
            'institution' => 'Techman',
        ]);
    }

    private function insert_session(int $approvalmode): int {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $start = time() + 7 * DAYSECS;
        return (int) $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $course->id,
            'classroomid' => 0,
            'name' => 'Action log ' . $start . ' ' . $approvalmode,
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

    /**
     * @return \stdClass[]
     */
    private function logs(int $enrolid): array {
        global $DB;
        return array_values($DB->get_records(
            'local_tm_course_enrol_logs',
            ['enrolmentid' => $enrolid],
            'timecreated ASC, id ASC'
        ));
    }

    private function function_body(string $source, string $name): string {
        $start = strpos($source, 'function ' . $name);
        $this->assertNotFalse($start);
        $next = strpos($source, "\n    private static function", $start + 10);
        $this->assertNotFalse($next);
        return substr($source, $start, $next - $start);
    }
}
