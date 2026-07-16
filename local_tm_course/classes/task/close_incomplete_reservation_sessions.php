<?php
/**
 * Auto-close reservation-derived sessions when verification files remain incomplete within deadline.
 *
 * @package    local_tm_course
 */
namespace local_tm_course\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../session_manager.php');
require_once(__DIR__ . '/../verification_manager.php');

class close_incomplete_reservation_sessions extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_close_incomplete_reservation_sessions', 'local_tm_course');
    }

    public function execute() {
        global $DB;

        $now = time();
        $days = (int)get_config('local_tm_course', 'reservation_verification_deadline_days');
        if ($days <= 0) {
            $days = 7;
        }
        $deadline = $now + ($days * DAYSECS);

        $rows = $DB->get_records_sql(
            "SELECT s.id AS sessionid, s.source_reservation_id AS reservationid
               FROM {local_tm_course_sessions} s
              WHERE s.source_reservation_id IS NOT NULL
                AND s.source_reservation_id > 0
                AND s.status IN (:openstatus, :fullstatus)
                AND s.starttime > :nowts
                AND s.starttime <= :deadline
           ORDER BY s.starttime ASC",
            [
                'openstatus' => \local_tm_course\session_manager::STATUS_OPEN,
                'fullstatus' => \local_tm_course\session_manager::STATUS_FULL,
                'nowts' => $now,
                'deadline' => $deadline,
            ]
        );
        if (empty($rows)) {
            return;
        }

        $reservationids = [];
        foreach ($rows as $row) {
            $reservationids[(int)$row->reservationid] = true;
        }
        $reservations = $DB->get_records_list('local_tm_course_reservation', 'id', array_keys($reservationids));

        $closeids = [];
        foreach ($rows as $row) {
            $rid = (int)$row->reservationid;
            $reservation = $reservations[$rid] ?? null;
            if (!$reservation) {
                continue;
            }
            $courseids = \local_tm_course\verification_manager::get_reservation_course_ids($reservation);
            $questions = \local_tm_course\verification_manager::get_questions_for_courses($courseids, (string)($reservation->delivery_mode ?? ''));
            $links = \local_tm_course\verification_manager::get_reservation_file_links($rid);
            $progress = \local_tm_course\verification_manager::get_reservation_progress_summary($rid, $questions, $links);
            if ((int)$progress['total'] > 0 && !$progress['complete']) {
                $closeids[] = (int)$row->sessionid;
            }
        }
        if (empty($closeids)) {
            return;
        }

        foreach (array_unique($closeids) as $sid) {
            $DB->set_field('local_tm_course_sessions', 'status', \local_tm_course\session_manager::STATUS_CLOSED, ['id' => $sid]);
            $DB->set_field('local_tm_course_sessions', 'timemodified', $now, ['id' => $sid]);
        }
    }
}

