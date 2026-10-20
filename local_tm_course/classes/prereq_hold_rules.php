<?php
/**
 * Pure decisions for prerequisite absence holds and empty-session void display.
 *
 * Time checks use PHP's default timezone, which Moodle sets to the site timezone.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class prereq_hold_rules {

    /**
     * Midnight at the start of the session's calendar day.
     */
    public static function start_day_midnight(int $starttime): int {
        if ($starttime <= 0) {
            return 0;
        }
        $midnight = strtotime(date('Y-m-d 00:00:00', $starttime));
        return $midnight === false ? 0 : (int) $midnight;
    }

    /**
     * Whether a dependent enrolment should be flagged as paused.
     *
     * A manual cancellation is not represented here: callers never pass cancelled rows.
     * When the source evidence is still valid but the dependent does not meet the rules,
     * an existing hold is kept and a new hold is not created.
     */
    public static function hold_target(bool $sourceevidenceinvalid, bool $dependentmeetsrules, bool $currentlyheld): int {
        if ($dependentmeetsrules) {
            return 0;
        }
        if ($sourceevidenceinvalid) {
            return 1;
        }
        return $currentlyheld ? 1 : 0;
    }

    /**
     * Class-day void: deadline or manual close has shut registration, and nobody valid remains.
     *
     * Exempt sessions that are still open stay enrolable. Display is derived, not stored.
     */
    public static function is_unattended_void(
        int $now,
        int $starttime,
        bool $deadlinepassed,
        bool $exempt,
        bool $manuallyclosed,
        int $validcount,
        bool $roomclosed
    ): bool {
        if ($roomclosed || $starttime <= 0) {
            return false;
        }
        $midnight = self::start_day_midnight($starttime);
        if ($midnight <= 0 || $now < $midnight) {
            return false;
        }
        if ($exempt && !$manuallyclosed) {
            return false;
        }
        if (!$deadlinepassed && !$manuallyclosed) {
            return false;
        }
        return $validcount <= 0;
    }
}
