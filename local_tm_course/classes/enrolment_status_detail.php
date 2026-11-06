<?php
/**
 * Current-status detail for one enrolment row.
 *
 * Shows only the latest status episode. It does not read timecreated,
 * timemodified, notes, or Moodle logs.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class enrolment_status_detail {

    /**
     * Escape text for an HTML attribute or text node.
     */
    public static function escape(string $text): string {
        return s($text);
    }

    /**
     * View model for the status popup.
     *
     * @param \stdClass $enrol Row with status, status_actorid, status_time, reject_reason, cancel fields.
     * @param string $actorname Display name when status_actorid is a real user. Ignored for null and 0.
     * @return array{statuslabel:string,actorlabel:string,actor:string,timelabel:string,time:string,reasonlabel:string,reason:string}
     */
    public static function present(\stdClass $enrol, string $actorname = ''): array {
        $status = (int) ($enrol->status ?? -1);
        $actorid = self::actor_id($enrol);
        $reasonlabel = '';
        $reason = '';
        if ($status === session_manager::ENROL_REJECTED) {
            $reasonlabel = get_string('statusdetail_reject_reason', 'local_tm_course');
            $reason = self::reject_reason_text($enrol->reject_reason ?? null);
        } else if ($status === session_manager::ENROL_CANCELLED) {
            $reasonlabel = get_string('statusdetail_cancel_reason', 'local_tm_course');
            $reason = self::cancel_reason_text(
                (string) ($enrol->cancel_reason_code ?? ''),
                (string) ($enrol->cancel_reason_text ?? '')
            );
        }

        return [
            'statuslabel' => self::status_label($status),
            'actorlabel' => self::actor_field_label($status),
            'actor' => self::actor_text($status, $actorid, $actorname),
            'timelabel' => self::time_field_label($status),
            'time' => self::time_text($enrol->status_time ?? null),
            'reasonlabel' => $reasonlabel,
            'reason' => $reason,
        ];
    }

    /**
     * Popup label for a stored cancellation code. Appends free text when present.
     */
    public static function cancel_reason_text(string $code, string $text): string {
        $code = trim($code);
        $text = trim($text);
        $labels = [
            'admin' => 'cancel_detail_admin',
            'work' => 'cancel_detail_work',
            'other_session' => 'cancel_detail_other_session',
            'other' => 'cancel_detail_other',
            'batch_submitter' => 'cancel_detail_batch_submitter',
            'prereq_cascade' => 'cancel_detail_prereq',
        ];
        if ($code === '' || !isset($labels[$code])) {
            $label = get_string('statusdetail_not_recorded', 'local_tm_course');
        } else {
            $label = get_string($labels[$code], 'local_tm_course');
        }
        if ($text === '') {
            return $label;
        }
        return $label . '：' . $text;
    }

    /**
     * @param mixed $actorid
     */
    private static function actor_id(\stdClass $enrol): ?int {
        if (!property_exists($enrol, 'status_actorid') || $enrol->status_actorid === null || $enrol->status_actorid === '') {
            return null;
        }
        return (int) $enrol->status_actorid;
    }

    private static function status_label(int $status): string {
        $map = [
            session_manager::ENROL_PENDING => 'enrol_pending',
            session_manager::ENROL_APPROVED => 'enrol_approved',
            session_manager::ENROL_REJECTED => 'enrol_rejected',
            session_manager::ENROL_CANCELLED => 'enrol_cancelled',
        ];
        if (!isset($map[$status])) {
            return get_string('statusdetail_not_recorded', 'local_tm_course');
        }
        return get_string($map[$status], 'local_tm_course');
    }

    private static function actor_field_label(int $status): string {
        $map = [
            session_manager::ENROL_PENDING => 'statusdetail_submitter',
            session_manager::ENROL_APPROVED => 'statusdetail_approver',
            session_manager::ENROL_REJECTED => 'statusdetail_rejector',
            session_manager::ENROL_CANCELLED => 'statusdetail_canceller',
        ];
        $key = $map[$status] ?? 'statusdetail_submitter';
        return get_string($key, 'local_tm_course');
    }

    private static function time_field_label(int $status): string {
        $map = [
            session_manager::ENROL_PENDING => 'statusdetail_applied_at',
            session_manager::ENROL_APPROVED => 'statusdetail_approved_at',
            session_manager::ENROL_REJECTED => 'statusdetail_rejected_at',
            session_manager::ENROL_CANCELLED => 'statusdetail_cancelled_at',
        ];
        $key = $map[$status] ?? 'statusdetail_applied_at';
        return get_string($key, 'local_tm_course');
    }

    private static function actor_text(int $status, ?int $actorid, string $actorname): string {
        if ($actorid === null) {
            return get_string('statusdetail_not_recorded', 'local_tm_course');
        }
        if ($actorid === 0) {
            if ($status === session_manager::ENROL_APPROVED) {
                return get_string('statusdetail_system_approved', 'local_tm_course');
            }
            if ($status === session_manager::ENROL_CANCELLED) {
                return get_string('statusdetail_system_cancelled', 'local_tm_course');
            }
            return get_string('statusdetail_not_recorded', 'local_tm_course');
        }
        $actorname = trim($actorname);
        if ($actorname === '') {
            return get_string('statusdetail_not_recorded', 'local_tm_course');
        }
        return $actorname;
    }

    /**
     * @param mixed $time
     */
    private static function time_text($time): string {
        if ($time === null || $time === '' || (int) $time <= 0) {
            return get_string('statusdetail_not_recorded', 'local_tm_course');
        }
        return userdate((int) $time, get_string('strftimedatetimeshort'));
    }

    /**
     * @param mixed $reason
     */
    private static function reject_reason_text($reason): string {
        $reason = trim((string) ($reason ?? ''));
        if ($reason === '') {
            return get_string('statusdetail_not_recorded', 'local_tm_course');
        }
        return $reason;
    }
}
