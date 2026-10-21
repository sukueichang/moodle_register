<?php
/**
 * Offline checks for grading-overdue notice decisions.
 * Run: php tools/verify_grading_overdue_rules.php
 */
declare(strict_types=1);

if (!defined('MOODLE_INTERNAL')) {
    define('MOODLE_INTERNAL', true);
}

require_once dirname(__DIR__) . '/local_tm_course/classes/grading_overdue_rules.php';

use local_tm_course\grading_overdue_rules;

$fail = 0;
$day = 86400;
$assigned = 1_700_000_000;

function check(bool $cond, string $msg): void {
    global $fail;
    if ($cond) {
        echo "PASS  {$msg}\n";
    } else {
        echo "FAIL  {$msg}\n";
        $fail++;
    }
}

check(!grading_overdue_rules::should_notify($assigned + (3 * $day), $assigned, 3, false, false, 0), 'disabled');
check(!grading_overdue_rules::should_notify($assigned + (2 * $day), $assigned, 3, true, false, 0), 'before X days');
check(grading_overdue_rules::should_notify($assigned + (3 * $day), $assigned, 3, true, false, 0), 'on X days and unfinished');
check(!grading_overdue_rules::should_notify($assigned + (5 * $day), $assigned, 3, true, true, 0), 'finished');
check(!grading_overdue_rules::should_notify($assigned + (5 * $day), $assigned, 3, true, false, $assigned), 'already sent this cycle');
check(grading_overdue_rules::should_notify($assigned + (10 * $day), $assigned + (6 * $day), 3, true, false, $assigned), 'reassign starts a new cycle');
check(!grading_overdue_rules::should_notify($assigned + (10 * $day), 0, 3, true, false, 0), 'no reliable assign time');
check(!grading_overdue_rules::should_notify($assigned + (10 * $day), $assigned, 0, true, false, 0), 'days must be positive');

$src = file_get_contents(dirname(__DIR__) . '/local_tm_course/classes/grading_request_manager.php');
check(is_string($src) && strpos($src, 'assignedby > 0') !== false, 'manager only trusts assignment rows with an actor');
check(is_string($src) && strpos($src, 'overdue_sent_assign') !== false, 'manager records the cycle that was notified');

$enrol = file_get_contents(dirname(__DIR__) . '/local_tm_course/admin/enrolments.php');
check(is_string($enrol) && strpos($enrol, 'scope_approved') !== false, 'online enrolled count uses the same scope as the list');
check(is_string($enrol) && strpos($enrol, 'enrolments_filter_empty') !== false, 'empty filter explains itself');
check(is_string($enrol) && strpos($enrol, '$show_desk_board = !$session_is_online') !== false, 'online still has no desk board');

if ($fail > 0) {
    echo "FAILED {$fail}\n";
    exit(1);
}
echo "ALL PASS\n";
