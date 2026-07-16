<?php
/**
 * Daily audit for approved enrolment sync completeness.
 *
 * @package    local_tm_course
 */
namespace local_tm_course\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../enrolment_manager.php');
require_once(__DIR__ . '/../session_manager.php');

class audit_approved_enrolment_sync extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_audit_approved_enrolment_sync', 'local_tm_course');
    }

    public function execute(): void {
        global $DB;

        $interval = (int) get_config('local_tm_course', 'sync_audit_interval_seconds');
        if ($interval <= 0) {
            $interval = HOURSECS;
        }
        $lastrun = (int) get_config('local_tm_course', 'sync_audit_last_run');
        $now = time();
        if ($lastrun > 0 && ($now - $lastrun) < $interval) {
            return;
        }

        $sql = "SELECT e.id
                  FROM {local_tm_course_enrolments} e
                  JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
                 WHERE e.status = :approved
                   AND s.starttime > :now";
        $params = [
            'approved' => \local_tm_course\session_manager::ENROL_APPROVED,
            'now' => $now,
        ];
        $enrolids = $DB->get_fieldset_sql($sql, $params);

        foreach ($enrolids as $enrolid) {
            \local_tm_course\enrolment_manager::audit_enrolment_sync_health((int) $enrolid, true);
        }
        set_config('sync_audit_last_run', $now, 'local_tm_course');
    }
}
