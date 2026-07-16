<?php
/**
 * M5 cron task: remind admins about overdue pending enrolments.
 *
 * @package    local_tm_course
 */
namespace local_tm_course\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../notification_helper.php');

class remind_pending_enrolment extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_remind_pending_enrolment', 'local_tm_course');
    }

    public function execute(): void {
        // N3: read threshold from plugin settings and apply dynamic cutoff.
        $threshold = \local_tm_course\notification_helper::get_reminder_threshold_seconds();
        \local_tm_course\notification_helper::notify_pending_overdue_to_admins_by_threshold($threshold);
    }
}

