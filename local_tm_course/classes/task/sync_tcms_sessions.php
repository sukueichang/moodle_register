<?php
/**
 * Reconcile TM sessions with TCMS (when sync is enabled).
 *
 * @package    local_tm_course
 */
namespace local_tm_course\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../tcms_sync_manager.php');

class sync_tcms_sessions extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_sync_tcms_sessions', 'local_tm_course');
    }

    public function execute(): void {
        if (!\local_tm_course\tcms_sync_manager::is_enabled()) {
            return;
        }

        $interval = (int) get_config('local_tm_course', 'tcms_sync_reconcile_interval');
        if ($interval <= 0) {
            $interval = DAYSECS;
        }
        $lastrun = (int) get_config('local_tm_course', 'tcms_sync_last_reconcile');
        $now = time();
        if ($lastrun > 0 && ($now - $lastrun) < $interval) {
            return;
        }

        \local_tm_course\tcms_sync_manager::reconcile_all();
    }
}
