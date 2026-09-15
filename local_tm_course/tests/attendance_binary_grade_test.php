<?php
/**
 * PHPUnit: binary Attendance grade rule (any Present => full marks).
 *
 * @package    local_tm_course
 * @category   test
 */

namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/attendance_manager.php');

/**
 * @covers \local_tm_course\attendance_manager
 */
class attendance_binary_grade_test extends \advanced_testcase {

    public function test_status_row_is_present_stable_identifiers(): void {
        $cases = [
            ['Pr', 'Present', true],
            ['PR', 'Present', true],
            ['P', 'Present', true],
            ['Present', 'Present', true],
            ['pr', 'something', true], // acronym match is case-insensitive via strtoupper
            ['La', 'Late', false],
            ['L', 'Late', false],
            ['Ab', 'Absent', false],
            ['A', 'Absent', false],
            ['E', 'Excused', false],
            ['EX', 'Excused', false],
            ['', 'present', true],
            ['', 'Present', true],
            ['', '出席', true],
            ['', 'late', false],
            ['', 'absent', false],
            ['', 'excused', false],
            ['', '缺席', false],
            ['', '遲到', false],
            ['', '請假', false],
            ['X', 'random', false],
        ];
        foreach ($cases as [$acronym, $description, $expected]) {
            $row = (object)['acronym' => $acronym, 'description' => $description];
            $this->assertSame(
                $expected,
                attendance_manager::status_row_is_present($row),
                "acronym={$acronym} description={$description}"
            );
        }
    }

    public function test_binary_grade_rescan_any_present_is_full_marks(): void {
        $this->resetAfterTest(true);
        if (!attendance_manager::is_mod_attendance_installed()) {
            $this->markTestSkipped('mod_attendance not installed');
        }

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $att = $this->create_attendance_activity((int)$course->id);
        $statuses = $this->insert_tm_statuses((int)$att->id);
        $presentid = $statuses['Pr'];
        $absentid = $statuses['Ab'];
        $lateid = $statuses['La'];

        $s1 = $this->insert_attendance_session((int)$att->id, time() - DAYSECS, 'Slot 1');
        $s2 = $this->insert_attendance_session((int)$att->id, time(), 'Slot 2');

        // Only Present => full marks.
        $this->insert_log($s1, (int)$user->id, $presentid);
        $this->assertSame(100.0, attendance_manager::compute_binary_attendance_rawgrade((int)$att->id, (int)$user->id));

        // Absent then Present (second slot) => still 100.
        $this->insert_log($s2, (int)$user->id, $absentid);
        // Rescan: still has Present on s1.
        $this->assertTrue(attendance_manager::user_has_present_on_attendance_activity((int)$att->id, (int)$user->id));
        $this->assertSame(100.0, attendance_manager::compute_binary_attendance_rawgrade((int)$att->id, (int)$user->id));

        // Change the only Present to Absent => 0.
        $this->update_log_status($s1, (int)$user->id, $absentid);
        $this->assertFalse(attendance_manager::user_has_present_on_attendance_activity((int)$att->id, (int)$user->id));
        $this->assertSame(0.0, attendance_manager::compute_binary_attendance_rawgrade((int)$att->id, (int)$user->id));

        // Late / excused-like only => 0 (Late is not Present).
        $this->update_log_status($s1, (int)$user->id, $lateid);
        $this->update_log_status($s2, (int)$user->id, $lateid);
        $this->assertSame(0.0, attendance_manager::compute_binary_attendance_rawgrade((int)$att->id, (int)$user->id));

        // Two Presents, flip one to Absent => still 100.
        $this->update_log_status($s1, (int)$user->id, $presentid);
        $this->update_log_status($s2, (int)$user->id, $presentid);
        $this->assertSame(100.0, attendance_manager::compute_binary_attendance_rawgrade((int)$att->id, (int)$user->id));
        $this->update_log_status($s2, (int)$user->id, $absentid);
        $this->assertSame(100.0, attendance_manager::compute_binary_attendance_rawgrade((int)$att->id, (int)$user->id));
    }

    public function test_sync_binary_grade_updates_existing_attendance_grade_item(): void {
        $this->resetAfterTest(true);
        if (!attendance_manager::is_mod_attendance_installed()) {
            $this->markTestSkipped('mod_attendance not installed');
        }

        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $att = $this->create_attendance_activity((int)$course->id);
        $statuses = $this->insert_tm_statuses((int)$att->id);
        $slot = $this->insert_attendance_session((int)$att->id, time(), 'Grade sync slot');
        $this->insert_log($slot, (int)$user->id, $statuses['Pr']);

        // Ensure the standard mod/attendance grade item exists (same identity native uses).
        if (function_exists('attendance_grade_item_update')) {
            require_once($CFG->dirroot . '/mod/attendance/lib.php');
            attendance_grade_item_update($att);
        }

        $beforecount = $DB->count_records('grade_items', [
            'courseid' => (int)$course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'attendance',
            'iteminstance' => (int)$att->id,
            'itemnumber' => 0,
        ]);

        attendance_manager::sync_binary_attendance_grade_for_user(
            (int)$att->id,
            (int)$course->id,
            (int)$user->id
        );

        $aftercount = $DB->count_records('grade_items', [
            'courseid' => (int)$course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'attendance',
            'iteminstance' => (int)$att->id,
            'itemnumber' => 0,
        ]);
        $this->assertSame(1, (int)$aftercount, 'Must use a single existing attendance grade item');
        $this->assertLessThanOrEqual(1, (int)$beforecount);

        $grades = grade_get_grades(
            (int)$course->id,
            'mod',
            'attendance',
            (int)$att->id,
            (int)$user->id
        );
        $this->assertNotEmpty($grades->items);
        $item = reset($grades->items);
        $this->assertArrayHasKey((int)$user->id, $item->grades);
        $this->assertEquals(100.0, (float)$item->grades[(int)$user->id]->grade);
    }

    /**
     * @return \stdClass attendance row
     */
    private function create_attendance_activity(int $courseid): \stdClass {
        global $DB;
        $att = new \stdClass();
        $att->course = $courseid;
        $att->name = 'TM Attendance';
        $att->intro = '';
        $att->introformat = FORMAT_HTML;
        $att->grade = 100;
        $att->timemodified = time();
        $id = (int)$DB->insert_record('attendance', $att);
        return $DB->get_record('attendance', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * @return array{Pr:int,La:int,Ab:int}
     */
    private function insert_tm_statuses(int $attendanceid): array {
        global $DB;
        $map = [];
        foreach (
            [
                ['Pr', 'Present', 2, 0],
                ['La', 'Late', 1, 0],
                ['Ab', 'Absent', 0, 1],
            ] as [$acronym, $description, $grade, $setunmarked]
        ) {
            $rec = new \stdClass();
            $rec->attendanceid = $attendanceid;
            $rec->acronym = $acronym;
            $rec->description = $description;
            $rec->grade = $grade;
            $rec->studentavailability = null;
            $rec->setunmarked = $setunmarked;
            $rec->visible = 1;
            $rec->deleted = 0;
            $map[$acronym] = (int)$DB->insert_record('attendance_statuses', $rec);
        }
        return $map;
    }

    private function insert_attendance_session(int $attendanceid, int $sessdate, string $desc): int {
        global $DB;
        $slot = new \stdClass();
        $slot->attendanceid = $attendanceid;
        $slot->groupid = 0;
        $slot->sessdate = $sessdate;
        $slot->duration = HOURSECS;
        $slot->lasttaken = 0;
        $slot->lasttakenby = 0;
        $slot->timemodified = time();
        $slot->description = $desc;
        $slot->descriptionformat = FORMAT_HTML;
        $slot->studentscanmark = 0;
        return (int)$DB->insert_record('attendance_sessions', $slot);
    }

    private function insert_log(int $sessionid, int $userid, int $statusid): void {
        global $DB;
        $log = new \stdClass();
        $log->sessionid = $sessionid;
        $log->studentid = $userid;
        $log->statusid = $statusid;
        $log->statusset = (string)$statusid;
        $log->timetaken = time();
        $log->takenby = $userid;
        $log->remarks = '';
        $log->timemodified = time();
        $DB->insert_record('attendance_log', $log);
    }

    private function update_log_status(int $sessionid, int $userid, int $statusid): void {
        global $DB;
        $existing = $DB->get_record('attendance_log', [
            'sessionid' => $sessionid,
            'studentid' => $userid,
        ], '*', MUST_EXIST);
        $existing->statusid = $statusid;
        $existing->timemodified = time();
        $DB->update_record('attendance_log', $existing);
    }
}
