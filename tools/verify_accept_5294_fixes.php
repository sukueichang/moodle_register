<?php
/**
 * Static checks for 5.29.4 acceptance fixes (no Moodle bootstrap).
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

$em = file_get_contents($root . '/classes/enrolment_manager.php');
assert_true(strpos($em, 'DEBUG_NORMAL') === false || !preg_match('/debugging\([^)]*DEBUG_NORMAL/', $em),
    'enrolment_manager has no debugging(..., DEBUG_NORMAL)');
assert_true(strpos($em, 'function cancel_by_admin') !== false, 'cancel_by_admin exists');
assert_true(strpos($em, 'function search_enrolments_by_userids') !== false, 'search_enrolments_by_userids exists');
assert_true(strpos($em, 'function merge_enrolment_search_results') !== false, 'merge_enrolment_search_results exists');

$pm = file_get_contents($root . '/classes/prerequisite_manager.php');
assert_true(strpos($pm, "['context' => \$ctx]") !== false || strpos($pm, '"context"') !== false,
    'get_course_display_name passes context to format_string');

$roster = file_get_contents($root . '/admin/session_roster.php');
$pospage = strpos($roster, '$PAGE->set_context');
$posbuild = strpos($roster, 'build_session_roster_view');
assert_true($pospage !== false && $posbuild !== false && $pospage < $posbuild,
    'session_roster sets PAGE context before build_session_roster_view');

$enrol = file_get_contents($root . '/admin/enrolments.php');
assert_true(strpos($enrol, "action === 'cancel'") !== false, 'enrolments.php handles cancel action');
assert_true(strpos($enrol, 'cancel_by_admin') !== false, 'enrolments.php calls cancel_by_admin');
assert_true(substr_count($enrol, "value=\"cancel\"") >= 3, 'enrolments.php has multiple cancel forms');

$search = file_get_contents($root . '/search.php');
assert_true(strpos($search, 'search_enrolments_by_userids') !== false, 'search.php loads enrolments by user ids');
assert_true(strpos($search, 'search_enrol_status') !== false, 'search.php has separate enrol status column');
assert_true(strpos($search, 'search_attendance_status') !== false, 'search.php has attendance column');

$js = file_get_contents($root . '/batch_enrol.js');
assert_true(strpos($js, 'batch_prereq_siteadmin_force_hint') !== false, 'batch_enrol.js shows siteadmin force hint');
assert_true(strpos($js, 'cfg.isSiteAdmin') !== false, 'batch_enrol.js gates hint on isSiteAdmin');

$ver = file_get_contents($root . '/version.php');
assert_true(strpos($ver, "5.29.4") !== false && strpos($ver, '2026102103') !== false, 'version is 5.29.4 / 2026102103');

$zh = file_get_contents($root . '/lang/zh_tw/local_tm_course.php');
assert_true(strpos($zh, 'batch_prereq_siteadmin_force_hint') !== false, 'zh_tw has siteadmin force hint string');
assert_true(strpos($zh, 'enrol_cancelled_notice') !== false, 'zh_tw has enrol_cancelled_notice');

exit($fail > 0 ? 1 : 0);
