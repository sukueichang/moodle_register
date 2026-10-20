<?php
/**
 * Offline checks for prerequisite-absence hold and empty-session void decisions.
 * Run: php tools/verify_prereq_hold_rules.php
 */
declare(strict_types=1);

if (!defined('MOODLE_INTERNAL')) {
    define('MOODLE_INTERNAL', true);
}

require_once dirname(__DIR__) . '/local_tm_course/classes/prereq_hold_rules.php';

use local_tm_course\prereq_hold_rules;

$fail = 0;

function check(bool $cond, string $msg): void {
    global $fail;
    if ($cond) {
        echo "PASS  {$msg}\n";
    } else {
        echo "FAIL  {$msg}\n";
        $fail++;
    }
}

date_default_timezone_set('Asia/Taipei');

$start = strtotime('2026-04-02 09:30:00');
$midnight = prereq_hold_rules::start_day_midnight($start);
check($midnight === strtotime('2026-04-02 00:00:00'), 'class day 00:00 uses site timezone');
check(prereq_hold_rules::start_day_midnight(strtotime('2026-04-02 00:00:00')) === $midnight, 'midnight start stays on that calendar day');

$daybefore = strtotime('2026-04-01 23:59:00');
check(!prereq_hold_rules::is_unattended_void($daybefore, $start, true, false, true, 0, false), 'before class day is not void');

$onday = strtotime('2026-04-02 00:00:00');
check(prereq_hold_rules::is_unattended_void($onday, $start, true, false, true, 0, false), '9 class day 00:00 with zero valid learners is void');
check(!prereq_hold_rules::is_unattended_void($onday, $start, true, false, true, 1, false), '10 valid learner keeps the session closed, not void');
check(!prereq_hold_rules::is_unattended_void($onday, $start, true, false, true, 0, true), 'room block is not a void course');
check(!prereq_hold_rules::is_unattended_void($onday, $start, false, true, false, 0, false), 'auto-close exempt and still open is not void');
check(prereq_hold_rules::is_unattended_void($onday, $start, false, true, true, 0, false), 'manually closed empty session is void on class day');

check(prereq_hold_rules::hold_target(true, false, false) === 1, '5 absent source without other qualification sets hold');
check(prereq_hold_rules::hold_target(false, true, true) === 0, '6 present again and qualified clears hold');
check(prereq_hold_rules::hold_target(true, false, true) === 1, '7 repeated absent does not ask for a new row');
check(prereq_hold_rules::hold_target(false, true, false) === 0, 'qualified learner is not held');
check(prereq_hold_rules::hold_target(false, false, true) === 1, 'present that still fails rules keeps an existing hold');
check(prereq_hold_rules::hold_target(true, true, true) === 0, 'other valid prerequisite clears hold');

$root = dirname(__DIR__) . '/local_tm_course';
$enrol = file_get_contents($root . '/classes/enrolment_manager.php');
$prereq = file_get_contents($root . '/classes/prerequisite_manager.php');
$attend = file_get_contents($root . '/classes/attendance_manager.php');
$session = file_get_contents($root . '/classes/session_manager.php');
$zhtw = file_get_contents($root . '/lang/zh_tw/local_tm_course.php');

check(strpos($enrol, 'if (!$skipchecks) {') !== false, '2 real learners are not bypassed');
check(strpos($enrol, '$bypassprerequisite = false') !== false, 'site-admin bypass flag is cleared');
check(strpos($enrol, 'error_batch_seat_prerequisite') !== false, 'placeholder seats cannot skip a prerequisite session');
check(strpos($prereq, 'user_has_issued_course_certificate') !== false, '3 issued certificate satisfies course completion');
check(strpos($prereq, 'user_is_reservation_learner') !== false, 'co-bundle waiver is limited to reservation learners');
check(strpos($prereq, 'COALESCE(e.attended, 0) <> :absent') !== false, 'absent enrolment is not a pre-hold');
check(strpos($attend, 'sync_prereq_holds_after_attendance') !== false, 'attendance mark rechecks dependents');
check(strpos($enrol, "set_field('local_tm_course_enrolments', 'prereq_absence_hold'") !== false, 'hold updates the flag only');
check(strpos($enrol, 'ENROL_CANCELLED') !== false && strpos($enrol, 'e.status $statusinsql') !== false, '8 cancelled rows are outside the hold query');
check(strpos($session, 'is_unattended_void($session)') !== false, '11 void blocks can_submit_enrolment');
check(strpos($session, 'COALESCE(prereq_absence_hold, 0) = 0') !== false, '12 valid headcount excludes holds');
check(strpos($zhtw, '先修課程未完成，已取消本場次上課資格') !== false, 'hold reason string');
check(strpos($zhtw, '本課程無人報名，已失效') !== false, 'void label string');

if ($fail > 0) {
    echo "FAILED {$fail}\n";
    exit(1);
}
echo "ALL PASS\n";
exit(0);
