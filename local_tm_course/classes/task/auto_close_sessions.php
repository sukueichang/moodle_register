<?php
/**
 * Auto-close sessions one day before start date at 00:00.
 *
 * @package    local_tm_course
 */
namespace local_tm_course\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../session_manager.php');

class auto_close_sessions extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_auto_close_sessions', 'local_tm_course');
    }

    public function execute(): void {
        \local_tm_course\session_manager::auto_close_elapsed_sessions();
    }
}

