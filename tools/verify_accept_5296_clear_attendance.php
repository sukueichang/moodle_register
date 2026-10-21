<?php
/**
 * Static checks for 5.29.6 clear-attendance-on-cancel (no Moodle bootstrap).
 */
$root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'local_tm_course';
$fail = 0;

function assert_true(bool $cond, string $msg): void {
    global $fail;
    if ($cond) {
        echo "PASS: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $fail++;
    }
}

$am = file_get_contents($root . '/classes/attendance_manager.php');
assert_true(strpos($am, 'function clear_attendance_after_enrolment_cancel') !== false,
    'clear_attendance_after_enrolment_cancel exists');
assert_true(strpos($am, 'clear_mod_attendance_log_for_enrol') !== false,
    'mod_attendance log clear helper exists');
assert_true(strpos($am, 'revoke_completion_after_flipped_absence') !== false,
    'completion revoke still available for present→clear');

$em = file_get_contents($root . '/classes/enrolment_manager.php');
assert_true(substr_count($em, 'clear_attendance_after_enrolment_cancel') >= 3,
    'cancel / admin / batch (and ideally cascade) call clear');
assert_true(preg_match('/function cancel\([\s\S]*?clear_attendance_after_enrolment_cancel/', $em) === 1,
    'student cancel clears attendance');
assert_true(preg_match('/function cancel_by_admin\([\s\S]*?clear_attendance_after_enrolment_cancel/', $em) === 1,
    'admin cancel clears attendance');
assert_true(preg_match('/function cancel_by_batch_submitter\([\s\S]*?clear_attendance_after_enrolment_cancel/', $em) === 1,
    'batch cancel clears attendance');
assert_true(strpos($em, 'attended = attendance_manager::ATTEND_UNSET') !== false
    || strpos($em, '->attended = attendance_manager::ATTEND_UNSET') !== false,
    're-enrol reuses row with attended reset to UNSET');

// Hold sync must not be invoked from clear helper (spec: hold/restore keeps marks).
$clearpos = strpos($am, 'function clear_attendance_after_enrolment_cancel');
$nextfn = strpos($am, "\n    public static function ", $clearpos + 10);
$clearbody = substr($am, $clearpos, $nextfn - $clearpos);
assert_true(strpos($clearbody, 'sync_prereq_holds_after_attendance') === false,
    'clear-on-cancel does not call prereq hold sync');

$ver = file_get_contents($root . '/version.php');
assert_true(strpos($ver, '5.29.6') !== false && strpos($ver, '2026102105') !== false, 'version 5.29.6');

exit($fail > 0 ? 1 : 0);
