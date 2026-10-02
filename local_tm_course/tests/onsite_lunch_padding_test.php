<?php
/**
 * PHPUnit: onsite auto-mode lunch padding (Taipei 12:00–13:00 overlap).
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/session_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/enabled_course_manager.php');

/**
 * @covers \local_tm_course\session_manager::onsite_segment_lunch_hours
 * @covers \local_tm_course\session_manager::calculate_session_times
 * @covers \local_tm_course\session_manager::session_includes_lunch_note
 */
class onsite_lunch_padding_test extends \advanced_testcase {

    /**
     * Build a unix timestamp for Y-m-d H:i in Asia/Taipei.
     */
    private function taipei_ts(string $ymdhi): int {
        $tz = new \DateTimeZone('Asia/Taipei');
        return (new \DateTime($ymdhi, $tz))->getTimestamp();
    }

    public function test_afternoon_block_gets_no_lunch(): void {
        $start = $this->taipei_ts('2026-10-05 13:30:00');
        $this->assertSame(0.0, session_manager::onsite_segment_lunch_hours($start, 2.5));
        $this->assertSame(0.0, session_manager::onsite_segment_lunch_hours($start, 3.0));
    }

    public function test_teaching_overlapping_1200_1300_gets_lunch(): void {
        $start = $this->taipei_ts('2026-10-05 09:30:00');
        $this->assertSame(1.0, session_manager::onsite_segment_lunch_hours($start, 8.0));
        $this->assertSame(1.0, session_manager::onsite_segment_lunch_hours($start, 3.5));
        // 09:30 + 2.5h ends exactly 12:00 — no overlap with [12:00, 13:00).
        $this->assertSame(0.0, session_manager::onsite_segment_lunch_hours($start, 2.5));
        // 11:30 + 1h ends 12:30 — overlaps lunch window.
        $lateam = $this->taipei_ts('2026-10-05 11:30:00');
        $this->assertSame(1.0, session_manager::onsite_segment_lunch_hours($lateam, 1.0));
    }

    public function test_calculate_session_times_afternoon_omits_lunch(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('local_tm_enabled_courses', (object) [
            'courseid' => (int) $course->id,
            'default_duration_hours' => 2.5,
            'default_duration_hours_onsite' => 2.5,
            'default_duration_hours_online' => 2.5,
            'allow_onsite' => 1,
            'allow_online' => 1,
            'timecreated' => time(),
        ]);

        $start = $this->taipei_ts('2026-10-05 13:30:00');
        $calc = session_manager::calculate_session_times(
            (int) $course->id,
            session_manager::DELIVERY_ONSITE,
            $start,
            true
        );

        $this->assertSame($start, (int) $calc['starttime']);
        $this->assertSame($this->taipei_ts('2026-10-05 16:00:00'), (int) $calc['endtime']);
        $this->assertEqualsWithDelta(2.5, (float) $calc['total_hours'], 0.001);
    }

    public function test_calculate_session_times_morning_full_day_includes_lunch(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('local_tm_enabled_courses', (object) [
            'courseid' => (int) $course->id,
            'default_duration_hours' => 8.0,
            'default_duration_hours_onsite' => 8.0,
            'default_duration_hours_online' => 8.0,
            'allow_onsite' => 1,
            'allow_online' => 1,
            'timecreated' => time(),
        ]);

        $start = $this->taipei_ts('2026-10-05 09:30:00');
        $calc = session_manager::calculate_session_times(
            (int) $course->id,
            session_manager::DELIVERY_ONSITE,
            $start,
            true
        );

        $this->assertSame($start, (int) $calc['starttime']);
        // 8h teaching + 1h lunch → 18:30 Taipei.
        $this->assertSame($this->taipei_ts('2026-10-05 18:30:00'), (int) $calc['endtime']);
    }

    public function test_session_includes_lunch_note_only_when_wall_covers_lunch(): void {
        $withlunch = (object) [
            'delivery_mode' => session_manager::DELIVERY_ONSITE,
            'starttime' => $this->taipei_ts('2026-10-05 09:30:00'),
            'endtime' => $this->taipei_ts('2026-10-05 18:30:00'),
        ];
        $afternoon = (object) [
            'delivery_mode' => session_manager::DELIVERY_ONSITE,
            'starttime' => $this->taipei_ts('2026-10-05 13:30:00'),
            'endtime' => $this->taipei_ts('2026-10-05 16:00:00'),
        ];
        $online = (object) [
            'delivery_mode' => session_manager::DELIVERY_ONLINE,
            'starttime' => $this->taipei_ts('2026-10-05 09:30:00'),
            'endtime' => $this->taipei_ts('2026-10-05 18:30:00'),
        ];
        $this->assertTrue(session_manager::session_includes_lunch_note($withlunch));
        $this->assertFalse(session_manager::session_includes_lunch_note($afternoon));
        $this->assertFalse(session_manager::session_includes_lunch_note($online));
    }
}
