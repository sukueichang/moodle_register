<?php
/**
 * Daily check: one overdue notice per open grading assignment cycle.
 *
 * @package    local_tm_course
 */
namespace local_tm_course\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../grading_request_manager.php');

class notify_grading_overdue extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_notify_grading_overdue', 'local_tm_course');
    }

    public function execute(): void {
        \local_tm_course\grading_request_manager::dispatch_overdue_notices(time());
    }
}
