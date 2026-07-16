<?php
/**
 * Dedicated class reservation draft vs formal submission lifecycle.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class reservation_application {

    /** Draft: in progress (steps 1–3), not visible in tracking or admin review. */
    public const SUBMITTED_NO = 0;
    /** Formal application submitted at end of step 3. */
    public const SUBMITTED_YES = 1;

    /**
     * SQL fragment (with leading AND) for lists that should only show formal applications.
     */
    public static function sql_submitted_only(): string {
        return ' AND COALESCE(r.application_submitted, 0) = ' . self::SUBMITTED_YES;
    }

    public static function is_formally_submitted(\stdClass $reservation): bool {
        return (int) ($reservation->application_submitted ?? 0) === self::SUBMITTED_YES;
    }

    public static function is_editable_draft(\stdClass $reservation): bool {
        return !self::is_formally_submitted($reservation) && (int) $reservation->status === 0;
    }

    /**
     * @param array<int,array<string,mixed>> $learnerrows From batch full-mode parse (userid, names, diet, …).
     */
    public static function save_draft_from_form(
        array $form,
        array $learnerrows,
        int $timestamp,
        int $requesterid,
        string $disclaimer,
        int $existingid = 0,
        string $batchsubmitternote = ''
    ): int {
        global $DB;

        $record = new \stdClass();
        $record->requesterid = $requesterid;
        $record->courseid = (int) $form['courseids'][0];
        $record->courseids_json = json_encode($form['courseids']);
        $record->delivery_mode = (string) $form['delivery_mode'];
        $record->teaching_language = (string) $form['teaching_language'];
        $record->preferred_classroomid = ((string) $form['delivery_mode'] === 'onsite') ? (int) $form['preferred_classroomid'] : null;
        $record->classroomid = ((string) $form['delivery_mode'] === 'onsite') ? (int) $form['preferred_classroomid'] : null;
        $record->preferred_meeting_link = '';
        $record->preferred_starttime = (int) $timestamp;
        $record->preferred_endtime = (int) $timestamp;
        if ((string) $form['delivery_mode'] === 'online'
            && (string) $form['online_daily_hours_limit'] !== ''
            && (float) $form['online_daily_hours_limit'] > 0) {
            $record->online_daily_hours_limit = (float) $form['online_daily_hours_limit'];
        } else {
            $record->online_daily_hours_limit = null;
        }
        $record->learner_source = empty($learnerrows) ? 'manual_empty' : 'batch_full';
        $record->cohortid = null;
        $record->excel_filename = '';
        $record->batch_submitter_note = enrolment_manager::normalise_batch_submitter_note($batchsubmitternote);
        $record->status = 0;
        $record->manager_note = '';
        $record->disclaimer_snapshot = $disclaimer;
        $record->application_submitted = self::SUBMITTED_NO;
        $record->timesubmitted = 0;
        $record->timemodified = time();

        if ($existingid > 0) {
            $existing = $DB->get_record('local_tm_course_reservation', ['id' => $existingid], '*', MUST_EXIST);
            if ((int) $existing->requesterid !== $requesterid) {
                throw new \moodle_exception('nopermissions', 'error');
            }
            if (!self::is_editable_draft($existing)) {
                throw new \moodle_exception('reservation_error_not_editable', 'local_tm_course');
            }
            $record->id = $existingid;
            $record->timecreated = (int) $existing->timecreated;
            $record->calendar_plan_json = $existing->calendar_plan_json;
            $record->calendar_plan_submitted = (int) ($existing->calendar_plan_submitted ?? 0);
            $record->calendar_onboarding_seen = (int) ($existing->calendar_onboarding_seen ?? 0);
            $record->archived = (int) ($existing->archived ?? 0);
            $DB->update_record('local_tm_course_reservation', $record);
            $DB->delete_records('local_tm_course_resv_learner', ['reservationid' => $existingid]);
            self::insert_learners($existingid, $learnerrows);
            return $existingid;
        }

        $record->timecreated = time();
        $reservationid = (int) $DB->insert_record('local_tm_course_reservation', $record);
        self::insert_learners($reservationid, $learnerrows);
        return $reservationid;
    }

    /**
     * @param array<int,array<string,mixed>> $learnerrows
     */
    private static function insert_learners(int $reservationid, array $learnerrows): void {
        global $DB;

        $seenemail = [];
        foreach ($learnerrows as $r) {
            $email = strtolower((string) ($r['email'] ?? ''));
            if ($email === '' || isset($seenemail[$email])) {
                continue;
            }
            $seenemail[$email] = true;
            $lr = new \stdClass();
            $lr->reservationid = $reservationid;
            $lr->userid = isset($r['userid']) ? (int) $r['userid'] : null;
            $lr->firstname = (string) ($r['firstname'] ?? '');
            $lr->lastname = (string) ($r['lastname'] ?? '');
            $lr->email = (string) ($r['email'] ?? '');
            $lr->institution = (string) ($r['institution'] ?? '');
            $lr->diet_choice = (string) ($r['diet_choice'] ?? '');
            $lr->diet_special_note = (string) ($r['diet_special_note'] ?? '');
            $lr->source_type = (string) ($r['source_type'] ?? 'batch_full');
            $lr->timecreated = time();
            $DB->insert_record('local_tm_course_resv_learner', $lr);
        }
    }

    /**
     * Mark application as formally submitted (step 3 confirm).
     *
     * @throws \moodle_exception
     */
    public static function finalize_submission(int $reservationid, int $requesterid, bool $issiteadmin = false): void {
        global $DB;

        $reservation = $DB->get_record('local_tm_course_reservation', ['id' => $reservationid], '*', MUST_EXIST);
        if ((int) $reservation->requesterid !== $requesterid && !$issiteadmin) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        if (self::is_formally_submitted($reservation)) {
            throw new \moodle_exception('reservation_error_already_submitted', 'local_tm_course');
        }
        if ((int) $reservation->status !== 0) {
            throw new \moodle_exception('reservation_calendar_error_not_pending', 'local_tm_course');
        }

        $plan = json_decode((string) ($reservation->calendar_plan_json ?? ''), true);
        if (!is_array($plan) || empty($plan)) {
            throw new \moodle_exception('reservation_error_plan_required', 'local_tm_course');
        }

        $result = reservation_plan_validator::validate_submitted_plan($reservation, $plan);
        if (empty($result['ok']) || empty($result['blocks'])) {
            $err = (string) ($result['error'] ?? 'reservation_calendar_error_invalid_block');
            throw new \moodle_exception($err, 'local_tm_course');
        }

        $now = time();
        $rec = new \stdClass();
        $rec->id = $reservationid;
        $rec->calendar_plan_json = json_encode($result['blocks']);
        $rec->calendar_plan_submitted = 1;
        $rec->application_submitted = self::SUBMITTED_YES;
        $rec->timesubmitted = $now;
        $rec->timemodified = $now;
        $DB->update_record('local_tm_course_reservation', $rec);

        notification_helper::notify_reservation_submitted($reservationid);
    }

    /**
     * Build structured summary for step-3 confirmation modal.
     *
     * @return array{sections:array<int,array{title:string,items:array<int,array{label:string,value:string}>}>}
     */
    public static function build_summary_payload(\stdClass $reservation): array {
        global $DB;

        $sm = get_string_manager();
        $str = function(string $key, string $fallback) use ($sm): string {
            if ($sm->string_exists($key, 'local_tm_course')) {
                return get_string($key, 'local_tm_course');
            }
            return $fallback;
        };

        $courseids = reservation_plan_validator::get_reservation_course_ids($reservation);
        $coursenames = [];
        $courserows = [];
        if (!empty($courseids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $courserows = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $insql", $inparams);
            foreach ($courseids as $cid) {
                if (!empty($courserows[$cid])) {
                    $coursenames[] = format_string((string) $courserows[$cid]->fullname);
                }
            }
        }

        $classroomlabel = '—';
        if ((string) $reservation->delivery_mode === session_manager::DELIVERY_ONSITE) {
            $rid = (int) ($reservation->preferred_classroomid ?? $reservation->classroomid ?? 0);
            if ($rid > 0) {
                $room = $DB->get_record('local_tm_classroom', ['id' => $rid], 'name,location', IGNORE_MISSING);
                if ($room) {
                    $classroomlabel = trim((string) $room->name);
                    $loc = trim((string) ($room->location ?? ''));
                    if ($loc !== '') {
                        $classroomlabel .= ' — ' . $loc;
                    }
                }
            }
        }

        $delivery = (string) $reservation->delivery_mode === 'online'
            ? get_string('delivery_mode_online', 'local_tm_course')
            : get_string('delivery_mode_onsite', 'local_tm_course');
        $lang = (string) $reservation->teaching_language === session_manager::LANG_ENGLISH
            ? get_string('teaching_language_english', 'local_tm_course')
            : get_string('teaching_language_zh_tw', 'local_tm_course');

        $step1items = [
            ['label' => $str('reservation_field_course_multi', 'Courses'), 'value' => implode('、', $coursenames) ?: '—'],
            ['label' => $str('reservation_field_delivery_mode', 'Delivery'), 'value' => $delivery],
            ['label' => get_string('session_teaching_language', 'local_tm_course'), 'value' => $lang],
        ];
        if ((string) $reservation->delivery_mode === session_manager::DELIVERY_ONSITE) {
            $step1items[] = ['label' => $str('reservation_field_preferred_classroom', 'Classroom'), 'value' => $classroomlabel];
        }
        $prefstart = (int) ($reservation->preferred_starttime ?? 0);
        if ($prefstart > 0) {
            $step1items[] = [
                'label' => $str('reservation_field_preferred_date', 'Preferred date'),
                'value' => userdate($prefstart, get_string('strftimedate', 'langconfig')),
            ];
            $step1items[] = [
                'label' => $str('reservation_field_preferred_time', 'Preferred time'),
                'value' => userdate($prefstart, get_string('strftimetime', 'langconfig')),
            ];
        }
        if ((string) $reservation->delivery_mode === 'online'
            && !empty($reservation->online_daily_hours_limit)
            && (float) $reservation->online_daily_hours_limit > 0) {
            $step1items[] = [
                'label' => $str('reservation_field_online_daily_hours_limit', 'Daily hours cap'),
                'value' => (string) (int) round((float) $reservation->online_daily_hours_limit) . ' h',
            ];
        }

        $learnerrows = $DB->get_records('local_tm_course_resv_learner', ['reservationid' => (int) $reservation->id], 'id ASC');
        $step1items[] = [
            'label' => $str('reservation_summary_learner_count', 'Learner rows'),
            'value' => (string) count($learnerrows),
        ];

        $plan = json_decode((string) ($reservation->calendar_plan_json ?? ''), true);
        if (!is_array($plan)) {
            $plan = [];
        }
        usort($plan, function($a, $b) {
            return ((int) ($a['start'] ?? 0)) <=> ((int) ($b['start'] ?? 0));
        });
        $classrooms = $DB->get_records('local_tm_classroom', [], '', 'id,name');
        $step2items = [];
        if (empty($plan)) {
            $step2items[] = ['label' => $str('reservation_summary_calendar', 'Schedule'), 'value' => '—'];
        } else {
            $idx = 0;
            foreach ($plan as $blk) {
                $idx++;
                $start = (int) ($blk['start'] ?? 0);
                $end = (int) ($blk['end'] ?? 0);
                $cid = (int) ($blk['courseId'] ?? 0);
                $title = trim((string) ($blk['title'] ?? ''));
                if ($title === '' && $cid > 0 && !empty($courserows[$cid])) {
                    $title = format_string((string) $courserows[$cid]->fullname);
                }
                $roomid = (int) ($blk['classroomId'] ?? 0);
                $roomname = $classrooms[$roomid]->name ?? ('#' . $roomid);
                $line = $title;
                if ($start > 0 && $end > 0) {
                    $line .= ' — ' . userdate($start, get_string('strftimedatetimeshort'))
                        . ' ~ ' . userdate($end, get_string('strftimetime', 'langconfig'));
                }
                $line .= ' (' . format_string((string) $roomname) . ')';
                $step2items[] = [
                    'label' => $str('reservation_summary_block', 'Block') . ' ' . $idx,
                    'value' => $line,
                ];
            }
        }

        $questions = verification_manager::get_questions_for_courses($courseids, (string) $reservation->delivery_mode);
        $links = verification_manager::get_reservation_file_links((int) $reservation->id);
        $progress = verification_manager::get_reservation_progress_summary((int) $reservation->id, $questions, $links);
        $step3items = [];
        if (empty($questions)) {
            $step3items[] = [
                'label' => $str('reservation_summary_verification', 'Pre-course files'),
                'value' => $str('reservation_summary_verification_none', 'No files required'),
            ];
        } else {
            $step3items[] = [
                'label' => $str('reservation_summary_verification', 'Pre-course files'),
                'value' => get_string('reservation_tracking_verification_complete', 'local_tm_course') . ' '
                    . '(' . (int) $progress['uploaded'] . '/' . (int) $progress['total'] . ')',
            ];
            foreach ($questions as $q) {
                $qid = (int) $q->id;
                $has = false;
                foreach ($links as $l) {
                    if ((int) $l->questionid === $qid) {
                        $itemid = (int) ($l->itemid ?? 0);
                        $has = ($itemid > 0 && verification_manager::stored_area_has_file(
                            \context_system::instance(),
                            $itemid
                        ));
                        break;
                    }
                }
                $step3items[] = [
                    'label' => format_string((string) $q->question_text),
                    'value' => $has
                        ? $str('reservation_summary_file_uploaded', 'Uploaded')
                        : $str('reservation_summary_file_missing', 'Not uploaded'),
                ];
            }
        }

        return [
            'sections' => [
                ['title' => $str('reservation_summary_section_step1', 'Step 1 — Basic information'), 'items' => $step1items],
                ['title' => $str('reservation_summary_section_step2', 'Step 2 — Calendar'), 'items' => $step2items],
                ['title' => $str('reservation_summary_section_step3', 'Step 3 — Pre-course verification'), 'items' => $step3items],
            ],
        ];
    }
}
