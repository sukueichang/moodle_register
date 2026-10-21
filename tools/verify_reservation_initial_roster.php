<?php
/**
 * Offline checks: dedicated-class initial enrolments belong on the formal roster.
 * Run: php tools/verify_reservation_initial_roster.php
 */
declare(strict_types=1);

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

$root = dirname(__DIR__) . '/local_tm_course';
$enrolments = file_get_contents($root . '/admin/enrolments.php');
$review = file_get_contents($root . '/admin/review_center.php');
$manager = file_get_contents($root . '/classes/enrolment_manager.php');
$session = file_get_contents($root . '/classes/session_manager.php');
$en = file_get_contents($root . '/lang/en/local_tm_course.php');
$zhtw = file_get_contents($root . '/lang/zh_tw/local_tm_course.php');
$version = file_get_contents($root . '/version.php');

// Formal / existing-course review must not exclude reservation_initial_enrol=1.
check(strpos($enrolments, "COALESCE(e.reservation_initial_enrol, 0) = 0") === false
    || (strpos($enrolments, 'Existing-course review: full formal roster') !== false
        && substr_count($enrolments, "COALESCE(e.reservation_initial_enrol, 0) = 0") === 1),
    'enrolments.php: only from_resv path may filter initial=0 (otherscope hint)');
check(strpos($enrolments, 'Existing-course review: full formal roster') !== false,
    'enrolments.php documents full formal roster for non-from_resv');
check(preg_match('/if \(\$fromresv > 0\) \{\s*\$sql \.= \' AND COALESCE\(e\.reservation_initial_enrol, 0\) = 1\';/', $enrolments) === 1,
    'enrolments.php: from_resv still lists only initial learners');
check(strpos($enrolments, "AND COALESCE(e.reservation_initial_enrol, 0) = 0\n                      GROUP BY s.id") === false,
    'enrolments.php session list pending_count includes dedicated-class learners');

check(strpos($review, 'Existing-course review block: full formal roster') !== false,
    'review_center.php documents full formal roster counts');
check(strpos($review, "COALESCE(e.reservation_initial_enrol, 0) = 0") === false,
    'review_center.php existing-course block no longer excludes initial enrolments');
check(strpos($review, 'COALESCE(e.reservation_initial_enrol, 0) = 1') !== false,
    'review_center.php dedicated block still counts initial learners only');

check(strpos($manager, "enrol_source_dedicated") !== false
    && strpos($manager, 'reservation_initial_enrol') !== false,
    'source label can show dedicated-class tag');
check(strpos($en, "enrol_source_dedicated") !== false && strpos($zhtw, "enrol_source_dedicated") !== false,
    'en + zh_tw define enrol_source_dedicated');

// Stats / roster already ignore the flag — guard against regressions that reintroduce exclusion.
$confirmedfn = '';
if (preg_match('/public static function confirmed_count\(.*?public static function /s', $session, $m)) {
    $confirmedfn = $m[0];
}
check($confirmedfn !== '' && strpos($confirmedfn, 'reservation_initial_enrol') === false,
    'confirmed_count does not exclude reservation_initial_enrol');
check(strpos($manager, 'function get_session_roster_approved_rows') !== false
    && !preg_match('/get_session_roster_approved_rows.*?reservation_initial_enrol\s*=\s*0/s', $manager),
    'roster approved rows do not filter initial=0');

check(strpos($version, "5.29.7") !== false && strpos($version, '2026102106') !== false,
    'version bumped to 5.29.7 / 2026102106');

if ($fail > 0) {
    echo "\n{$fail} check(s) failed.\n";
    exit(1);
}
echo "\nAll reservation-initial roster checks passed.\n";
exit(0);
