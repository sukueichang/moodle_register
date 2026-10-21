<?php
/**
 * Static checks for 5.29.5 acceptance fixes (no Moodle bootstrap).
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
assert_true(
    !preg_match('/didprereqbypass[\s\S]{0,400}debugging\s*\(/', $em),
    'siteadmin prereq bypass does not call debugging()'
);
assert_true(strpos($em, 'learnerid') !== false && strpos($em, 'siteadmin_prereq_bypass_note') !== false,
    'bypass audit note includes learner/session fields');
assert_true(strpos($em, 'LEFT JOIN {course}') !== false, 'search SQL uses LEFT JOIN course');
assert_true(strpos($em, "SELECT e.id, e.*") !== false, 'search SQL selects e.id first');

$enrol = file_get_contents($root . '/admin/enrolments.php');
assert_true(strpos($enrol, 'value="unapprove"') === false, 'enrolments UI has no unapprove form');
assert_true(strpos($enrol, "action === 'unapprove'") === false, 'enrolments POST does not handle unapprove');
assert_true(strpos($enrol, 'value="cancel"') !== false, 'enrolments UI still has cancel');
assert_true(strpos($enrol, 'cancel_by_admin') !== false, 'enrolments still calls cancel_by_admin');

$search = file_get_contents($root . '/search.php');
assert_true(strpos($search, 'searchemails') !== false, 'search passes emails to userid enrolment lookup');
assert_true(strpos($search, 'search_completed_status') !== false, 'search has separate completed column');
assert_true(strpos($search, 'search_passed_status') !== false, 'search has separate passed column');
assert_true(
    preg_match('/byuserids[\s\S]+byfields/', $search) === 1
    || (strpos($search, '$byuserids') !== false && strpos($search, '$byfields') !== false),
    'search loads userid hits independently of field LIKE'
);

$ver = file_get_contents($root . '/version.php');
assert_true(strpos($ver, '5.29.5') !== false && strpos($ver, '2026102104') !== false, 'version is 5.29.5');

exit($fail > 0 ? 1 : 0);
