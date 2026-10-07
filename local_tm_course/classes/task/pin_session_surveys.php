<?php
/**
 * Scheduled task: pin surveys for sessions that have started but have no pin yet.
 *
 * @package    local_tm_course
 */
namespace local_tm_course\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../survey_manager.php');

class pin_session_surveys extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_pin_session_surveys', 'local_tm_course');
    }

    public function execute(): void {
        global $DB;

        $now = time();
        // Sessions past starttime, course has active survey assignment, no pin yet.
        $sql = "SELECT s.id
                  FROM {local_tm_course_sessions} s
                  JOIN {local_tm_course_svcrs} c ON c.courseid = s.courseid
                  JOIN {local_tm_course_svdef} d ON d.id = c.surveyid AND d.enabled = 1
             LEFT JOIN {local_tm_course_svpin} p ON p.sessionid = s.id
                 WHERE s.starttime <= :now
                   AND p.id IS NULL
              ORDER BY s.starttime ASC, s.id ASC";
        $rows = $DB->get_records_sql($sql, ['now' => $now], 0, 200);

        $attempted = 0;
        $pinned = 0;
        foreach ($rows as $row) {
            $attempted++;
            $pinid = \local_tm_course\survey_manager::ensure_session_survey_pin((int) $row->id);
            if ($pinid > 0) {
                $pinned++;
            }
        }
        mtrace('pin_session_surveys: attempted=' . $attempted . ' pinned=' . $pinned);
    }
}
