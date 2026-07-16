<?php
/**
 * Scheduled task: send tomorrow onsite pre-class notification digest.
 *
 * @package    local_tm_course
 */
namespace local_tm_course\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../pre_class_notification_manager.php');

class send_pre_class_notification extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_send_pre_class_notification', 'local_tm_course');
    }

    public function execute(): void {
        \local_tm_course\pre_class_notification_manager::send_if_due();
    }
}
