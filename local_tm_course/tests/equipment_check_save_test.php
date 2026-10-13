<?php
/**
 * PHPUnit: equipment check last-modified is updated only when saved content changes.
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/equipment_check_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/session_manager.php');

/**
 * @covers \local_tm_course\equipment_check_manager
 */
class equipment_check_save_test extends \advanced_testcase {

    public function test_resolution_checked_equivalent_ignores_json_formatting(): void {
        $ordered = json_encode(['步驟一', '步驟二'], JSON_UNESCAPED_UNICODE);
        $reversed = json_encode(['步驟二', '步驟一']);
        $duplicated = json_encode(['步驟一', '步驟一', '步驟二'], JSON_UNESCAPED_UNICODE);

        $this->assertTrue(equipment_check_manager::resolution_checked_equivalent($ordered, $reversed));
        $this->assertTrue(equipment_check_manager::resolution_checked_equivalent($ordered, $duplicated));
        $this->assertTrue(equipment_check_manager::resolution_checked_equivalent('', '[]'));
        $this->assertTrue(equipment_check_manager::resolution_checked_equivalent(null, ''));
        $this->assertFalse(equipment_check_manager::resolution_checked_equivalent($ordered, json_encode(['步驟一'])));
    }

    public function test_resave_without_changes_keeps_original_checker(): void {
        $this->resetAfterTest(true);
        $userA = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $userB = $this->getDataGenerator()->create_user(['firstname' => 'Grace', 'lastname' => 'Hopper']);
        $course = $this->getDataGenerator()->create_course();
        $sessionid = $this->insert_session((int) $course->id, 2);
        $itemchanged = equipment_check_manager::create_item(
            (int) $course->id, 'Camera', 'both', 'status', 1, 1, ['步驟一', '步驟二']
        );
        $itemstable = equipment_check_manager::create_item(
            (int) $course->id, 'Mic', 'both', 'task', 1, 2
        );

        $payload = [
            $itemchanged => [
                'checkstatus' => equipment_check_manager::STATUS_ABNORMAL,
                'remark' => 'flicker',
                'resolution_checked' => ['步驟二', '步驟一'],
            ],
            $itemstable => [
                'checkstatus' => equipment_check_manager::STATUS_NORMAL,
                'remark' => '',
                'resolution_checked' => [],
            ],
        ];
        equipment_check_manager::save_desk_checks($sessionid, 1, $payload, (int) $userA->id);
        $this->freeze_log_times($sessionid, 1, 1700000000);

        equipment_check_manager::save_desk_checks($sessionid, 1, $payload, (int) $userB->id);

        $changed = $this->fetch_log($sessionid, 1, $itemchanged);
        $stable = $this->fetch_log($sessionid, 1, $itemstable);
        $this->assertSame((int) $userA->id, (int) $changed->checkedby);
        $this->assertSame(1700000000, (int) $changed->timemodified);
        $this->assertSame(1700000000, (int) $changed->timecreated);
        $this->assertSame((int) $userA->id, (int) $stable->checkedby);
        $this->assertSame(1700000000, (int) $stable->timemodified);

        $payload[$itemchanged]['remark'] = 'replaced cable';
        equipment_check_manager::save_desk_checks($sessionid, 1, $payload, (int) $userB->id);

        $changed = $this->fetch_log($sessionid, 1, $itemchanged);
        $stable = $this->fetch_log($sessionid, 1, $itemstable);
        $this->assertSame((int) $userB->id, (int) $changed->checkedby);
        $this->assertGreaterThan(1700000000, (int) $changed->timemodified);
        $this->assertSame(1700000000, (int) $changed->timecreated);
        $this->assertSame('replaced cable', (string) $changed->remark);
        $this->assertSame((int) $userA->id, (int) $stable->checkedby);
        $this->assertSame(1700000000, (int) $stable->timemodified);
    }

    public function test_sync_skips_targets_whose_content_already_matches(): void {
        global $DB;
        $this->resetAfterTest(true);
        $userA = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $userB = $this->getDataGenerator()->create_user(['firstname' => 'Grace', 'lastname' => 'Hopper']);
        $course = $this->getDataGenerator()->create_course();
        $sessionid = $this->insert_session((int) $course->id, 2);
        $sameitem = equipment_check_manager::create_item((int) $course->id, 'Same', 'both', 'task', 1, 1);
        $diffitem = equipment_check_manager::create_item((int) $course->id, 'Diff', 'both', 'task', 1, 2);

        $now = 1700000000;
        $DB->insert_record('local_tm_equip_check_log', (object) [
            'sessionid' => $sessionid,
            'desknumber' => 1,
            'itemid' => $sameitem,
            'checkstatus' => equipment_check_manager::STATUS_NORMAL,
            'remark' => '',
            'resolution_checked' => '',
            'checkedby' => $userA->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_tm_equip_check_log', (object) [
            'sessionid' => $sessionid,
            'desknumber' => 1,
            'itemid' => $diffitem,
            'checkstatus' => equipment_check_manager::STATUS_NORMAL,
            'remark' => '',
            'resolution_checked' => '',
            'checkedby' => $userA->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        // Same labels, different JSON encoding — must not count as a change.
        $DB->insert_record('local_tm_equip_check_log', (object) [
            'sessionid' => $sessionid,
            'desknumber' => 2,
            'itemid' => $sameitem,
            'checkstatus' => equipment_check_manager::STATUS_NORMAL,
            'remark' => '',
            'resolution_checked' => '[]',
            'checkedby' => $userA->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_tm_equip_check_log', (object) [
            'sessionid' => $sessionid,
            'desknumber' => 2,
            'itemid' => $diffitem,
            'checkstatus' => equipment_check_manager::STATUS_UNSET,
            'remark' => '',
            'resolution_checked' => '',
            'checkedby' => $userA->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $synced = equipment_check_manager::sync_desk_to_all($sessionid, 1, (int) $userB->id);
        $this->assertSame(1, $synced);

        $same = $this->fetch_log($sessionid, 2, $sameitem);
        $diff = $this->fetch_log($sessionid, 2, $diffitem);
        $this->assertSame((int) $userA->id, (int) $same->checkedby);
        $this->assertSame($now, (int) $same->timemodified);
        $this->assertSame((int) $userB->id, (int) $diff->checkedby);
        $this->assertSame(equipment_check_manager::STATUS_NORMAL, (int) $diff->checkstatus);
        $this->assertGreaterThan($now, (int) $diff->timemodified);
        $this->assertSame($now, (int) $diff->timecreated);
    }

    public function test_check_view_is_shared_across_users(): void {
        $this->resetAfterTest(true);
        $userA = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $userB = $this->getDataGenerator()->create_user(['firstname' => 'Grace', 'lastname' => 'Hopper']);
        $course = $this->getDataGenerator()->create_course();
        $sessionid = $this->insert_session((int) $course->id, 1);
        $itemid = equipment_check_manager::create_item((int) $course->id, 'Camera', 'both', 'task', 1, 1);

        equipment_check_manager::save_desk_checks($sessionid, 1, [
            $itemid => [
                'checkstatus' => equipment_check_manager::STATUS_NORMAL,
                'remark' => '',
                'resolution_checked' => [],
            ],
        ], (int) $userA->id);

        $this->setUser($userB);
        $view = equipment_check_manager::get_check_view($sessionid);
        $item = $view['desks'][0]['items'][0];
        $this->assertSame($itemid, (int) $item['itemid']);
        $this->assertSame(equipment_check_manager::STATUS_NORMAL, (int) $item['checkstatus']);
        $this->assertSame((int) $userA->id, (int) $item['checkedby']);
        $this->assertStringContainsString('Ada', (string) $item['checkedby_name']);

        $presented = equipment_check_manager::present_saved_logs([
            (object) $this->fetch_log($sessionid, 1, $itemid),
        ]);
        $this->assertNotSame('', $presented[0]['label']);
        $this->assertSame(1, preg_match('/\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}/', $presented[0]['label']));

        $previous = force_current_language('zh_tw');
        $zh = equipment_check_manager::format_last_modified_label('Ada Lovelace', 1700000000);
        force_current_language('en');
        $en = equipment_check_manager::format_last_modified_label('Ada Lovelace', 1700000000);
        force_current_language($previous);
        $this->assertStringStartsWith('最後修改：Ada Lovelace · ', $zh);
        $this->assertStringStartsWith('Last modified: Ada Lovelace · ', $en);
    }

    private function insert_session(int $courseid, int $desks): int {
        global $DB;
        $start = time();
        return (int) $DB->insert_record('local_tm_course_sessions', (object) [
            'courseid' => $courseid,
            'classroomid' => 0,
            'name' => 'Equip session',
            'location' => 'HQ',
            'teaching_language' => session_manager::LANG_ZH_TW,
            'delivery_mode' => session_manager::DELIVERY_ONSITE,
            'starttime' => $start,
            'endtime' => $start + 7200,
            'duration_hours' => 2,
            'num_desks' => $desks,
            'persons_per_desk' => 3,
            'approval_mode' => session_manager::APPROVAL_MANUAL,
            'status' => session_manager::STATUS_OPEN,
            'auto_close_exempt' => 0,
            'timecreated' => $start,
            'timemodified' => $start,
            'createdby' => 0,
            'session_kind' => session_manager::SESSION_KIND_STANDARD,
        ]);
    }

    private function freeze_log_times(int $sessionid, int $desknumber, int $timestamp): void {
        global $DB;
        $DB->set_field('local_tm_equip_check_log', 'timecreated', $timestamp, [
            'sessionid' => $sessionid,
            'desknumber' => $desknumber,
        ]);
        $DB->set_field('local_tm_equip_check_log', 'timemodified', $timestamp, [
            'sessionid' => $sessionid,
            'desknumber' => $desknumber,
        ]);
    }

    private function fetch_log(int $sessionid, int $desknumber, int $itemid): \stdClass {
        global $DB;
        return $DB->get_record('local_tm_equip_check_log', [
            'sessionid' => $sessionid,
            'desknumber' => $desknumber,
            'itemid' => $itemid,
        ], '*', MUST_EXIST);
    }
}
