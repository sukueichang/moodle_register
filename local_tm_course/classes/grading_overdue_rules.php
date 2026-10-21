<?php
/**
 * Pure decisions for grading-overdue notices.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class grading_overdue_rules {
    /**
     * One notice per assignment cycle, only after X full days from a reliable assign time.
     */
    public static function should_notify(
        int $now,
        int $reliableassigned,
        int $days,
        bool $enabled,
        bool $finished,
        int $alreadysentfor
    ): bool {
        if (!$enabled || $finished || $reliableassigned <= 0 || $days < 1) {
            return false;
        }
        if ($alreadysentfor === $reliableassigned) {
            return false;
        }
        return $now >= ($reliableassigned + ($days * 86400));
    }
}
