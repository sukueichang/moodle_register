<?php
/**
 * Verification question/file management for reservation flow.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class verification_manager {
    /** @var string */
    const FILEAREA = 'resvcheck';
    const REVIEW_PENDING = 0;
    const REVIEW_PASSED = 1;
    const REVIEW_FAILED = 2;

    /**
     * @return int[]
     */
    public static function get_reservation_course_ids(\stdClass $reservation): array {
        return reservation_plan_validator::get_reservation_course_ids($reservation);
    }

    /**
     * @param int[] $courseids
     * @return array<int,\stdClass>
     */
    public static function get_questions_for_courses(array $courseids, string $deliverymode = ''): array {
        global $DB;
        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids), function($v) {
            return $v > 0;
        })));
        if (empty($courseids)) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $where = "courseid $insql";
        if ($deliverymode === session_manager::DELIVERY_ONLINE) {
            $where .= " AND (apply_mode = :m1 OR apply_mode = :m2)";
            $params['m1'] = 'online';
            $params['m2'] = 'both';
        } else if ($deliverymode === session_manager::DELIVERY_ONSITE) {
            $where .= " AND (apply_mode = :m1 OR apply_mode = :m2)";
            $params['m1'] = 'onsite';
            $params['m2'] = 'both';
        }
        return $DB->get_records_sql(
            "SELECT *
               FROM {local_tm_course_vq_q}
              WHERE $where
           ORDER BY courseid ASC, sortorder ASC, id ASC",
            $params
        );
    }

    /**
     * @return array<int,\stdClass>
     */
    public static function get_questions_by_course(int $courseid): array {
        global $DB;
        return $DB->get_records('local_tm_course_vq_q', ['courseid' => $courseid], 'sortorder ASC, id ASC');
    }

    /**
     * @param array<int,array<string,mixed>> $questions
     */
    public static function save_questions_for_course(int $courseid, array $questions): void {
        global $DB;
        $tx = $DB->start_delegated_transaction();
        $DB->delete_records('local_tm_course_vq_q', ['courseid' => $courseid]);
        $now = time();
        $sort = 10;
        foreach ($questions as $q) {
            $text = trim((string)($q['question_text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $mode = strtolower(trim((string)($q['apply_mode'] ?? 'both')));
            if (!in_array($mode, ['onsite', 'online', 'both'], true)) {
                $mode = 'both';
            }
            $rec = new \stdClass();
            $rec->courseid = $courseid;
            $rec->apply_mode = $mode;
            $rec->question_text = $text;
            $rec->is_required = !empty($q['is_required']) ? 1 : 0;
            $rec->sortorder = (int)($q['sortorder'] ?? $sort);
            $rec->timecreated = $now;
            $rec->timemodified = $now;
            $DB->insert_record('local_tm_course_vq_q', $rec);
            $sort += 10;
        }
        $tx->allow_commit();
    }

    /**
     * @return array<int,\stdClass>
     */
    public static function get_reservation_file_links(int $reservationid): array {
        global $DB;
        return $DB->get_records(
            'local_tm_course_vq_file',
            ['reservationid' => $reservationid],
            '',
            'id,reservationid,questionid,itemid,review_status,review_note,reviewedby,timereviewed,timecreated,timemodified'
        );
    }

    public static function save_question_draft(int $reservationid, int $questionid, int $draftitemid, \context $context): void {
        global $DB;
        $existing = $DB->get_record('local_tm_course_vq_file', ['reservationid' => $reservationid, 'questionid' => $questionid], '*', IGNORE_MISSING);
        if ($existing) {
            $itemid = (int)$existing->itemid;
        } else {
            $itemid = 0;
        }
        if ($itemid <= 0) {
            $itemid = (int)time() + random_int(1000, 9999);
        }
        file_save_draft_area_files($draftitemid, $context->id, 'local_tm_course', self::FILEAREA, $itemid, [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => '*',
        ]);
        $hasfile = self::stored_area_has_file($context, $itemid);
        if ($hasfile) {
            $now = time();
            if ($existing) {
                $existing->itemid = $itemid;
                $existing->timemodified = $now;
                $DB->update_record('local_tm_course_vq_file', $existing);
            } else {
                $rec = new \stdClass();
                $rec->reservationid = $reservationid;
                $rec->questionid = $questionid;
                $rec->itemid = $itemid;
                $rec->timecreated = $now;
                $rec->timemodified = $now;
                $DB->insert_record('local_tm_course_vq_file', $rec);
            }
        } else if ($existing) {
            $DB->delete_records('local_tm_course_vq_file', ['id' => (int)$existing->id]);
        }
    }

    /**
     * Save uploaded file from a standard <input type="file"> field.
     */
    public static function save_question_upload(int $reservationid, int $questionid, array $upload, \context $context): void {
        global $DB, $USER;
        $existing = $DB->get_record('local_tm_course_vq_file', ['reservationid' => $reservationid, 'questionid' => $questionid], '*', IGNORE_MISSING);
        $itemid = $existing ? (int)$existing->itemid : 0;
        if ($itemid <= 0) {
            $itemid = (int)time() + random_int(1000, 9999);
        }
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK || empty($upload['tmp_name'])) {
            return;
        }
        $tmpname = (string)$upload['tmp_name'];
        if (!is_uploaded_file($tmpname)) {
            return;
        }
        $filename = clean_param((string)($upload['name'] ?? ''), PARAM_FILE);
        if ($filename === '') {
            $filename = 'upload.bin';
        }
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_tm_course', self::FILEAREA, $itemid);
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'local_tm_course',
            'filearea' => self::FILEAREA,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => (int)$USER->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $fs->create_file_from_pathname($filerecord, $tmpname);
        $now = time();
        if ($existing) {
            $existing->itemid = $itemid;
            $existing->review_status = self::REVIEW_PENDING;
            $existing->review_note = '';
            $existing->reviewedby = null;
            $existing->timereviewed = 0;
            $existing->timemodified = $now;
            $DB->update_record('local_tm_course_vq_file', $existing);
        } else {
            $rec = new \stdClass();
            $rec->reservationid = $reservationid;
            $rec->questionid = $questionid;
            $rec->itemid = $itemid;
            $rec->review_status = self::REVIEW_PENDING;
            $rec->review_note = '';
            $rec->reviewedby = null;
            $rec->timereviewed = 0;
            $rec->timecreated = $now;
            $rec->timemodified = $now;
            $DB->insert_record('local_tm_course_vq_file', $rec);
        }
    }

    public static function review_uploaded_file(int $reservationid, int $questionid, int $status, int $reviewerid): void {
        global $DB;
        if (!in_array($status, [self::REVIEW_PASSED, self::REVIEW_FAILED], true)) {
            $status = self::REVIEW_PENDING;
        }
        $existing = $DB->get_record('local_tm_course_vq_file', ['reservationid' => $reservationid, 'questionid' => $questionid], '*', IGNORE_MISSING);
        if (!$existing) {
            return;
        }
        $existing->review_status = $status;
        $existing->reviewedby = $reviewerid;
        $existing->timereviewed = time();
        $existing->timemodified = time();
        $DB->update_record('local_tm_course_vq_file', $existing);
    }

    public static function validate_required_uploaded(int $reservationid, array $questions, \context $context): bool {
        $links = self::get_reservation_file_links($reservationid);
        $byquestion = [];
        foreach ($links as $link) {
            $byquestion[(int)$link->questionid] = (int)$link->itemid;
        }
        foreach ($questions as $q) {
            if ((int)($q->is_required ?? 0) !== 1) {
                continue;
            }
            $qid = (int)$q->id;
            $itemid = (int)($byquestion[$qid] ?? 0);
            if ($itemid <= 0 || !self::stored_area_has_file($context, $itemid)) {
                return false;
            }
        }
        return true;
    }

    public static function stored_area_has_file(\context $context, int $itemid): bool {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_tm_course', self::FILEAREA, $itemid, 'itemid, filepath, filename', false);
        return !empty($files);
    }

    /**
     * Build verification progress summary for one reservation.
     *
     * @param int $reservationid
     * @param array<int,\stdClass> $questions
     * @param array<int,\stdClass>|null $filelinks
     * @return array{total:int,uploaded:int,status:string,complete:bool}
     */
    public static function get_reservation_progress_summary(int $reservationid, array $questions, ?array $filelinks = null): array {
        $context = \context_system::instance();
        $questionids = [];
        foreach ($questions as $q) {
            $qid = (int)($q->id ?? 0);
            if ($qid > 0) {
                $questionids[$qid] = true;
            }
        }

        $total = count($questionids);
        if ($total <= 0) {
            return [
                'total' => 0,
                'uploaded' => 0,
                'status' => 'na',
                'complete' => true,
            ];
        }

        if ($filelinks === null) {
            $filelinks = self::get_reservation_file_links($reservationid);
        }
        $uploaded = 0;
        foreach ($filelinks as $link) {
            $qid = (int)($link->questionid ?? 0);
            if (!isset($questionids[$qid])) {
                continue;
            }
            $itemid = (int)($link->itemid ?? 0);
            if ($itemid > 0 && self::stored_area_has_file($context, $itemid)) {
                $uploaded++;
            }
        }

        $status = 'not_started';
        if ($uploaded >= $total) {
            $status = 'complete';
        } else if ($uploaded > 0) {
            $status = 'in_progress';
        }

        return [
            'total' => $total,
            'uploaded' => $uploaded,
            'status' => $status,
            'complete' => ($uploaded >= $total),
        ];
    }
}

