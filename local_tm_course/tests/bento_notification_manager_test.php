<?php
/**
 * PHPUnit: bento lunch-request send history.
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/bento_notification_manager.php');

/**
 * @covers \local_tm_course\bento_notification_manager
 */
class bento_notification_manager_test extends \advanced_testcase {

    public function test_successful_send_history_accumulates_per_session(): void {
        global $DB;
        $this->resetAfterTest(true);

        $user1 = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $user2 = $this->getDataGenerator()->create_user(['firstname' => 'Grace', 'lastname' => 'Hopper']);
        $sessionid = 4242;

        $id1 = bento_notification_manager::record_successful_send($sessionid, (int) $user1->id, 2);
        $this->assertGreaterThan(0, $id1);
        // Ensure distinct timestamps for ordering assertions.
        $DB->set_field('local_tm_course_bento_log', 'timecreated', 1000, ['id' => $id1]);

        $id2 = bento_notification_manager::record_successful_send($sessionid, (int) $user2->id, 3);
        $this->assertGreaterThan(0, $id2);
        $DB->set_field('local_tm_course_bento_log', 'timecreated', 2000, ['id' => $id2]);

        // Other session must not appear.
        bento_notification_manager::record_successful_send(9999, (int) $user1->id, 1);

        $history = bento_notification_manager::get_send_history($sessionid);
        $this->assertCount(2, $history);
        $this->assertSame(2000, (int) $history[0]->timecreated);
        $this->assertSame((int) $user2->id, (int) $history[0]->userid);
        $this->assertSame(3, (int) $history[0]->recipientcount);
        $this->assertStringContainsString('Grace', $history[0]->sendername);
        $this->assertSame(1000, (int) $history[1]->timecreated);
        $this->assertSame((int) $user1->id, (int) $history[1]->userid);
        $this->assertSame(2, $DB->count_records('local_tm_course_bento_log', ['sessionid' => $sessionid]));
    }
}
