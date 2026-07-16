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
}
