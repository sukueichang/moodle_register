<?php
/**
 * Pre-course verification for session enrolments (self or batch), reusing course question definitions.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class session_verification_manager {

    public const SCOPE_SELF = 'self';
    public const SCOPE_BATCH = 'batch';

    /** Desk-board labels. Failed wins over still-pending required items. */
    public const STATE_NOT_SUBMITTED = 'not_submitted';
    public const STATE_PENDING_REVIEW = 'pending_review';
    public const STATE_REQUIRED_FAILED = 'required_failed';
    public const STATE_REQUIRED_PASSED = 'required_passed';

    /**
     * Whether the linked course has at least one verification question for this session's delivery mode.
     */
    public static function session_has_verification_questions(\stdClass $session): bool {
        $courseid = (int)($session->courseid ?? 0);
        if ($courseid <= 0) {
            return false;
        }
        $qs = verification_manager::get_questions_for_courses(
            [$courseid],
            (string)($session->delivery_mode ?? session_manager::DELIVERY_ONSITE)
        );
        return !empty($qs);
    }

    public static function get_or_create_self_submission(int $sessionid, int $userid): \stdClass {
        global $DB;
        $rec = $DB->get_record('local_tm_course_sess_vq_sub', [
            'sessionid' => $sessionid,
            'scope' => self::SCOPE_SELF,
            'actor_userid' => $userid,
        ], '*', IGNORE_MISSING);
        if ($rec) {
            return $rec;
        }
        $now = time();
        $r = new \stdClass();
        $r->sessionid = $sessionid;
        $r->scope = self::SCOPE_SELF;
        $r->actor_userid = $userid;
        $r->submitted = 0;
        $r->timecreated = $now;
        $r->timemodified = $now;
        $r->id = (int)$DB->insert_record('local_tm_course_sess_vq_sub', $r, true);
        return $r;
    }

    public static function create_batch_submission(int $sessionid, int $actoruserid): int {
        global $DB;
        $now = time();
        $r = new \stdClass();
        $r->sessionid = $sessionid;
        $r->scope = self::SCOPE_BATCH;
        $r->actor_userid = $actoruserid;
        $r->submitted = 0;
        $r->timecreated = $now;
        $r->timemodified = $now;
        return (int)$DB->insert_record('local_tm_course_sess_vq_sub', $r, true);
    }

    public static function get_submission(int $id): \stdClass {
        global $DB;
        return $DB->get_record('local_tm_course_sess_vq_sub', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * @return array<int,\stdClass>
     */
    public static function get_file_links(int $submissionid): array {
        global $DB;
        return $DB->get_records(
            'local_tm_course_sess_vq_file',
            ['submissionid' => $submissionid],
            '',
            'id,submissionid,questionid,itemid,review_status,review_note,reviewedby,timereviewed,timecreated,timemodified'
        );
    }

    public static function mark_submitted(int $submissionid): void {
        global $DB;
        $rec = new \stdClass();
        $rec->id = $submissionid;
        $rec->submitted = 1;
        $rec->timemodified = time();
        $DB->update_record('local_tm_course_sess_vq_sub', $rec);
    }

    /**
     * Save uploaded file from a standard file input (same storage as reservation verification).
     */
    public static function save_question_upload(int $submissionid, int $questionid, array $upload, \context $context): void {
        global $DB, $USER;
        $existing = $DB->get_record('local_tm_course_sess_vq_file', [
            'submissionid' => $submissionid,
            'questionid' => $questionid,
        ], '*', IGNORE_MISSING);
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
        $fs->delete_area_files($context->id, 'local_tm_course', verification_manager::FILEAREA, $itemid);
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'local_tm_course',
            'filearea' => verification_manager::FILEAREA,
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
            $existing->review_status = verification_manager::REVIEW_PENDING;
            $existing->review_note = '';
            $existing->reviewedby = null;
            $existing->timereviewed = 0;
            $existing->timemodified = $now;
            $DB->update_record('local_tm_course_sess_vq_file', $existing);
        } else {
            $rec = new \stdClass();
            $rec->submissionid = $submissionid;
            $rec->questionid = $questionid;
            $rec->itemid = $itemid;
            $rec->review_status = verification_manager::REVIEW_PENDING;
            $rec->review_note = '';
            $rec->reviewedby = null;
            $rec->timereviewed = 0;
            $rec->timecreated = $now;
            $rec->timemodified = $now;
            $DB->insert_record('local_tm_course_sess_vq_file', $rec);
        }
    }

    public static function review_uploaded_file(int $submissionid, int $questionid, int $status, int $reviewerid): void {
        global $DB;
        if (!in_array($status, [verification_manager::REVIEW_PASSED, verification_manager::REVIEW_FAILED], true)) {
            $status = verification_manager::REVIEW_PENDING;
        }
        $existing = $DB->get_record('local_tm_course_sess_vq_file', [
            'submissionid' => $submissionid,
            'questionid' => $questionid,
        ], '*', IGNORE_MISSING);
        if (!$existing) {
            return;
        }
        $existing->review_status = $status;
        $existing->reviewedby = $reviewerid;
        $existing->timereviewed = time();
        $existing->timemodified = time();
        $DB->update_record('local_tm_course_sess_vq_file', $existing);
    }

    /**
     * Block admin approval until applicant submitted verification and required items are marked passed.
     *
     * @throws \moodle_exception
     */
    public static function assert_enrol_verification_allows_approval(\stdClass $enrol, \stdClass $session): void {
        global $DB;
        if (!self::session_has_verification_questions($session)) {
            return;
        }
        $subid = (int)($enrol->vq_submission_id ?? 0);
        if ($subid <= 0) {
            return;
        }
        $sub = $DB->get_record('local_tm_course_sess_vq_sub', ['id' => $subid], '*', IGNORE_MISSING);
        if (!$sub) {
            return;
        }
        if ((int)$sub->submitted !== 1) {
            throw new \moodle_exception('error_session_vq_not_submitted', 'local_tm_course');
        }
        $questions = verification_manager::get_questions_for_courses(
            [(int)$session->courseid],
            (string)($session->delivery_mode ?? session_manager::DELIVERY_ONSITE)
        );
        $links = self::get_file_links($subid);
        $byq = [];
        foreach ($links as $l) {
            $byq[(int)$l->questionid] = $l;
        }
        $context = \context_system::instance();
        foreach ($questions as $q) {
            if ((int)($q->is_required ?? 0) !== 1) {
                continue;
            }
            $qid = (int)$q->id;
            $link = $byq[$qid] ?? null;
            if (!$link) {
                throw new \moodle_exception('error_session_vq_required_not_passed', 'local_tm_course');
            }
            if ((int)$link->review_status !== verification_manager::REVIEW_PASSED) {
                throw new \moodle_exception('error_session_vq_required_not_passed', 'local_tm_course');
            }
            $itemid = (int)$link->itemid;
            if ($itemid <= 0 || !verification_manager::stored_area_has_file($context, $itemid)) {
                throw new \moodle_exception('error_session_vq_required_not_passed', 'local_tm_course');
            }
        }
    }

    /**
     * Batch packages stay editable by the original submitter.
     * Site admins keep the existing exception. Approve or manage capability is not enough.
     */
    public static function user_may_edit_batch_submission(\stdClass $submission, int $userid, bool $issiteadmin): bool {
        if ((string)($submission->scope ?? '') !== self::SCOPE_BATCH) {
            return false;
        }
        $actor = (int)($submission->actor_userid ?? 0);
        if ($actor > 0 && $userid > 0 && $actor === $userid) {
            return true;
        }
        return $issiteadmin;
    }

    /**
     * Unsubmitted batch packages created by this user. Does not create a new submission.
     *
     * @return \stdClass[]
     */
    public static function list_actor_open_batch_submissions(int $sessionid, int $actoruserid): array {
        global $DB;
        if ($sessionid <= 0 || $actoruserid <= 0) {
            return [];
        }
        return $DB->get_records('local_tm_course_sess_vq_sub', [
            'sessionid' => $sessionid,
            'scope' => self::SCOPE_BATCH,
            'actor_userid' => $actoruserid,
            'submitted' => 0,
        ], 'id ASC');
    }

    public static function state_lang_key(string $state): string {
        switch ($state) {
            case self::STATE_NOT_SUBMITTED:
                return 'session_vq_state_not_submitted';
            case self::STATE_PENDING_REVIEW:
                return 'session_vq_state_pending_review';
            case self::STATE_REQUIRED_FAILED:
                return 'session_vq_state_required_failed';
            case self::STATE_REQUIRED_PASSED:
                return 'session_vq_state_required_passed';
            default:
                return '';
        }
    }

    /**
     * One query for submissions, one for file rows, one for stored files.
     *
     * @param int[] $submissionids
     * @return array<int,array{state:string,sharedbatch:bool}>
     */
    public static function summarize_submission_states(array $submissionids, \stdClass $session): array {
        global $DB;
        $ids = [];
        foreach ($submissionids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if (!$ids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $subs = $DB->get_records_sql(
            "SELECT id, scope, submitted
               FROM {local_tm_course_sess_vq_sub}
              WHERE id $insql",
            $params
        );
        $filerows = $DB->get_records_sql(
            "SELECT id, submissionid, questionid, itemid, review_status
               FROM {local_tm_course_sess_vq_file}
              WHERE submissionid $insql",
            $params
        );
        $questions = verification_manager::get_questions_for_courses(
            [(int)($session->courseid ?? 0)],
            (string)($session->delivery_mode ?? session_manager::DELIVERY_ONSITE)
        );
        $requiredids = [];
        foreach ($questions as $q) {
            if ((int)($q->is_required ?? 0) === 1 && (int)$q->id > 0) {
                $requiredids[] = (int)$q->id;
            }
        }
        $byquestion = [];
        $itemids = [];
        foreach ($filerows as $row) {
            $sid = (int)$row->submissionid;
            $qid = (int)$row->questionid;
            $byquestion[$sid][$qid] = $row;
            if ((int)$row->itemid > 0) {
                $itemids[] = (int)$row->itemid;
            }
        }
        $withfile = self::itemids_with_stored_files($itemids);
        $out = [];
        foreach ($ids as $id) {
            if (empty($subs[$id])) {
                continue;
            }
            $sub = $subs[$id];
            $shared = ((string)$sub->scope === self::SCOPE_BATCH);
            if ((int)$sub->submitted !== 1) {
                $out[$id] = [
                    'state' => self::STATE_NOT_SUBMITTED,
                    'sharedbatch' => $shared,
                ];
                continue;
            }
            $failed = false;
            $waiting = false;
            foreach ($requiredids as $qid) {
                $link = $byquestion[$id][$qid] ?? null;
                if (!$link) {
                    $waiting = true;
                    continue;
                }
                $status = (int)$link->review_status;
                if ($status === verification_manager::REVIEW_FAILED) {
                    $failed = true;
                    continue;
                }
                $itemid = (int)$link->itemid;
                if ($status !== verification_manager::REVIEW_PASSED || $itemid <= 0 || empty($withfile[$itemid])) {
                    $waiting = true;
                }
            }
            if ($failed) {
                $state = self::STATE_REQUIRED_FAILED;
            } else if ($waiting) {
                $state = self::STATE_PENDING_REVIEW;
            } else {
                $state = self::STATE_REQUIRED_PASSED;
            }
            $out[$id] = [
                'state' => $state,
                'sharedbatch' => $shared,
            ];
        }
        return $out;
    }

    /**
     * @param int[] $itemids
     * @return array<int,bool>
     */
    private static function itemids_with_stored_files(array $itemids): array {
        global $DB;
        $clean = [];
        foreach ($itemids as $itemid) {
            $itemid = (int)$itemid;
            if ($itemid > 0) {
                $clean[$itemid] = $itemid;
            }
        }
        $clean = array_values($clean);
        if (!$clean) {
            return [];
        }
        $context = \context_system::instance();
        list($insql, $params) = $DB->get_in_or_equal($clean, SQL_PARAMS_NAMED);
        $params['ctx'] = $context->id;
        $params['component'] = 'local_tm_course';
        $params['filearea'] = verification_manager::FILEAREA;
        $rows = $DB->get_records_sql(
            "SELECT itemid
               FROM {files}
              WHERE contextid = :ctx
                AND component = :component
                AND filearea = :filearea
                AND filename <> '.'
                AND itemid $insql
           GROUP BY itemid",
            $params
        );
        $found = [];
        foreach ($rows as $row) {
            $found[(int)$row->itemid] = true;
        }
        return $found;
    }
}
