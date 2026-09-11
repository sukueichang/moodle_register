<?php
/**
 * Cron: sync open grading requests against the gradebook.
 *
 * @package    local_tm_course
 */
namespace local_tm_course\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../grading_request_manager.php');

class sync_grading_request_grades extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_sync_grading_request_grades', 'local_tm_course');
    }

    public function execute(): void {
        \local_tm_course\grading_request_manager::sync_open_requests();
    }
}
