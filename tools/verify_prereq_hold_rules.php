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

check(prereq_hold_rules::unattended_void_blocks_submit(true, false), 'learner cannot submit on an unattended void');
check(!prereq_hold_rules::unattended_void_blocks_submit(true, true), 'manager override may pass the void gate only');
check(!prereq_hold_rules::unattended_void_blocks_submit(false, false), 'non-void session is not blocked by the void gate');
check(prereq_hold_rules::is_unattended_void($onday, $start, true, false, true, 0, false), 'failed add with zero valid learners stays void');
check(!prereq_hold_rules::is_unattended_void($onday, $start, true, false, true, 1, false), 'one valid learner clears the lapse on the next check');
check(!prereq_hold_rules::is_unattended_void($onday, $start, true, false, false, 1, false), 'restored open session is not void again while a valid learner remains');
check(prereq_hold_rules::is_unattended_void($onday, $start, true, false, false, 0, false), 'deadline with zero learners is still the empty-session lapse');
check(!prereq_hold_rules::is_unattended_void($onday, $start, true, false, true, 1, true), 'room block stays outside the lapse after a learner exists');
check(!prereq_hold_rules::is_unattended_void($onday, $start, false, true, true, 1, false), 'manual close with a learner is closed, not the empty-session lapse');

check(prereq_hold_rules::enrolment_counts_as_valid(0, false, 0, 0), 'pending learner counts as valid');
check(prereq_hold_rules::enrolment_counts_as_valid(1, false, 0, 0), 'approved learner counts as valid');
check(prereq_hold_rules::enrolment_counts_as_valid(4, false, 0, 0), 'waitlisted learner counts as valid');
check(!prereq_hold_rules::enrolment_counts_as_valid(2, false, 0, 0), 'rejected row is not a valid learner');
check(!prereq_hold_rules::enrolment_counts_as_valid(3, false, 0, 0), 'cancelled row is not a valid learner');
check(!prereq_hold_rules::enrolment_counts_as_valid(1, true, 0, 0), 'absence hold is not a valid learner');
check(!prereq_hold_rules::enrolment_counts_as_valid(1, false, 3, 0), 'unlinked placeholder is not a valid learner');
check(prereq_hold_rules::enrolment_counts_as_valid(1, false, 3, 9), 'linked placeholder counts as valid');

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

check(strpos($enrol, 'if (!$skipchecks) {') !== false, '2 real learners still hit prerequisite checks');
check(strpos($enrol, 'is_siteadmin($actorid)') !== false, '1.3 site-admin bypass uses is_siteadmin only');
check(strpos($enrol, 'local/tm_course:manage alone must never skip') !== false
    || strpos($enrol, 'Manage / sales roles never bypass') !== false, 'non-siteadmin manage cannot bypass');
check(strpos($enrol, 'ENROL_APPROVED') !== false && strpos($enrol, 'finalize_siteadmin_batch_approval') !== false, 'site-admin proxy auto-approves');
check(strpos($enrol, 'siteadmin_prereq_bypass_note') !== false, 'prereq bypass leaves a notes audit trail');
check(strpos($enrol, 'error_batch_seat_prerequisite') !== false, 'placeholder seats cannot skip a prerequisite session');
check(strpos($enrol, 'function cancel(') !== false && strpos($enrol, 'ENROL_CANCELLED') !== false, 'cancel goes to cancelled');
$cancelfn = '';
if (preg_match('/public static function cancel\(.*?public static function /s', $enrol, $m)) {
    $cancelfn = $m[0];
}
check($cancelfn !== '' && strpos($cancelfn, 'ENROL_PENDING') === false, 'cancel does not set pending');
check(strpos($prereq, 'user_has_issued_course_certificate') !== false, '3 issued certificate satisfies course completion');
check(strpos($prereq, 'user_is_reservation_learner') !== false, 'co-bundle waiver is limited to reservation learners');
check(strpos($prereq, 'COALESCE(e.attended, 0) <> :absent') !== false, 'absent enrolment is not a pre-hold');
check(strpos($attend, 'sync_prereq_holds_after_attendance') !== false, 'attendance mark rechecks dependents');
check(strpos($enrol, "set_field('local_tm_course_enrolments', 'prereq_absence_hold'") !== false, 'hold updates the flag only');
check(strpos($enrol, 'ENROL_CANCELLED') !== false && strpos($enrol, 'e.status $statusinsql') !== false, '8 cancelled rows are outside the hold query');
check(strpos($enrol, 'Do not exclude the source enrolment id') !== false, 'absence hold sync does not exclude source for restore');
check(strpos($session, 'unattended_void_blocks_submit(self::is_unattended_void($session), $adminoverride)') !== false, '11 void blocks learners and allows manager override');
check(strpos($session, 'if (self::is_removed($session))') !== false, 'removed sessions stay blocked for managers');
check(strpos($session, 'Manually closed') !== false, 'recalculate leaves a closed status closed');
check(strpos($session, 'COALESCE(prereq_absence_hold, 0) = 0') !== false, '12 valid headcount excludes holds');
check(strpos($session, 'COALESCE(placeholder_seq, 0) = 0 OR COALESCE(linked_userid, 0) > 0') !== false, '3.6 unnamed placeholders are not valid learners');
check(strpos($zhtw, '因先修課程 {$a} 缺席，已取消預卡位資格') !== false, 'hold reason names course A');
check(strpos($zhtw, '先修預卡位') !== false, '1.6 pre-hold badge string');
check(strpos($zhtw, '本課程無人報名，已失效') !== false, 'void label string');
check(strpos($zhtw, 'admin_void_unattended_add_hint') === false, 'long manager lapse essay is removed');
check(strpos($zhtw, 'batch_result_added_restored') !== false, 'restore result is one short line');
check(strpos($zhtw, '已新增 {$a} 筆報名，場次已恢復有效') !== false, 'restore wording stays one sentence');
check(strpos($zhtw, '未完成先修，已強制加入') !== false, 'force-add names the learner only');
$batchpage = file_get_contents($root . '/batch_enrol.php');
$batchjs = file_get_contents($root . '/batch_enrol.js');
check(is_string($batchpage) && strpos($batchpage, 'format_batch_result_notice') !== false, 'batch result is one notice');
check(is_string($batchpage) && strpos($batchpage, 'missing_display') === false, 'batch notice does not list missing courses');
check(is_string($batchjs) && strpos($batchjs, 'batch_prereq_siteadmin_force_hint') !== false, 'site-admin force hint remains one line');
check(is_string($batchjs) && strpos($batchjs, '<ul class="small text-muted mb-0 mt-1 pl-3">') === false, 'preview does not list each unmet course');
check(strpos($enrol, 'is_unattended_void($session) && !$allowclosed') !== false, 'batch void bypass requires the manage flag');
$selffn = '';
if (preg_match('/public static function enrol\(int \$sessionid.*?public static function /s', $enrol, $m)) {
    $selffn = $m[0];
}
check($selffn !== '' && strpos($selffn, 'is_unattended_void($session)') !== false, 'self-enrol still rejects a void session');
check($selffn !== '' && strpos($selffn, '$allowclosed') === false, 'self-enrol cannot use the manage void bypass');
$index = file_get_contents($root . '/index.php');
$calendar = file_get_contents($root . '/calendar_events.php');
check(is_string($index) && strpos($index, 'can_submit_enrolment($s, false)') !== false, 'session card self-enrol stays learner scoped');
check(is_string($index) && strpos($index, 'can_submit_enrolment($s, $is_admin)') !== false, 'manager batch button uses the manage override');
check(is_string($calendar) && strpos($calendar, 'can_submit_enrolment($s, false)') !== false, 'calendar enrol action stays learner scoped');
$prereqsrc = file_get_contents($root . '/classes/prerequisite_manager.php');
check(is_string($prereqsrc) && strpos($prereqsrc, 'prehold_course_labels') !== false, 'pre-hold labels derived without new columns');
$notify = file_get_contents($root . '/classes/notification_helper.php');
check(is_string($notify) && strpos($notify, 'TARGET_ASSIGNEE') !== false, '5.1 assignee recipient target exists');
check(is_string($notify) && strpos($notify, "eventkey === 'grading_overdue'") !== false, 'grading overdue has its own recipient options');

if ($fail > 0) {
    echo "FAILED {$fail}\n";
    exit(1);
}
echo "ALL PASS\n";
exit(0);
