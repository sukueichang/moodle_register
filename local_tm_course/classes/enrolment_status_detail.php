<?php
/**
 * Current-status detail for one enrolment row.
 *
 * The current-status view shows only the latest episode. History comes from
 * local_tm_course_enrol_logs and is never inferred from the current columns,
 * timecreated, timemodified, notes, or Moodle logs.
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

    /**
     * Newest-first history for the enrolments already loaded on this page.
     * One log query and one user query. Missing enrolments get an empty list.
     *
     * @param int[] $enrolids
     * @return array<int, array<int, array{action:string,actor:string,time:string,reason:string}>>
     */
    public static function histories_for_enrolments(array $enrolids): array {
        global $DB;
        $enrolids = array_values(array_unique(array_filter(array_map('intval', $enrolids))));
        $out = [];
        foreach ($enrolids as $enrolid) {
            $out[$enrolid] = [];
        }
        if (!$enrolids) {
            return $out;
        }
        list($insql, $params) = $DB->get_in_or_equal($enrolids, SQL_PARAMS_NAMED, 'eid');
        $logs = $DB->get_records_sql(
            "SELECT *
               FROM {local_tm_course_enrol_logs}
              WHERE enrolmentid $insql
           ORDER BY timecreated DESC, id DESC",
            $params
        );
        $actorids = [];
        foreach ($logs as $log) {
            if ($log->actorid === null || $log->actorid === '') {
                continue;
            }
            $actorid = (int) $log->actorid;
            if ($actorid > 0) {
                $actorids[$actorid] = $actorid;
            }
        }
        $names = [];
        if ($actorids) {
            $users = $DB->get_records_list('user', 'id', array_values($actorids), '', 'id, firstname, lastname, deleted');
            foreach ($users as $user) {
                if (!empty($user->deleted)) {
                    continue;
                }
                $names[(int) $user->id] = trim($user->firstname . ' ' . $user->lastname);
            }
        }
        foreach ($logs as $log) {
            $enrolid = (int) $log->enrolmentid;
            if (!array_key_exists($enrolid, $out)) {
                continue;
            }
            $out[$enrolid][] = self::present_log($log, $names);
        }
        return $out;
    }

    /**
     * @param array<int,string> $names
     * @return array{action:string,actor:string,time:string,reason:string}
     */
    private static function present_log(\stdClass $log, array $names): array {
        $actioncode = (string) ($log->action ?? '');
        $reason = '';
        $code = trim((string) ($log->reason_code ?? ''));
        $text = (string) ($log->reason_text ?? '');
        if ($code !== '') {
            $reason = self::cancel_reason_text($code, $text);
        } else {
            $text = trim($text);
            if ($text !== '') {
                $reason = $text;
            }
        }
        return [
            'action' => self::action_label($actioncode),
            'actor' => self::log_actor_label($log->actorid ?? null, $actioncode, $names),
            'time' => self::time_text($log->timecreated ?? null),
            'reason' => $reason,
        ];
    }

    private static function action_label(string $action): string {
        $keys = [
            'apply' => 'enrol_log_apply',
            'approve' => 'enrol_log_approve',
            'reject' => 'enrol_log_reject',
            'cancel' => 'enrol_log_cancel',
            'reenrol' => 'enrol_log_reenrol',
            'unapprove' => 'enrol_log_unapprove',
        ];
        if (!isset($keys[$action])) {
            return get_string('statusdetail_not_recorded', 'local_tm_course');
        }
        return get_string($keys[$action], 'local_tm_course');
    }

    /**
     * @param mixed $actorid
     * @param array<int,string> $names
     */
    private static function log_actor_label($actorid, string $action, array $names): string {
        if ($actorid === null || $actorid === '') {
            return get_string('statusdetail_not_recorded', 'local_tm_course');
        }
        $actorid = (int) $actorid;
        if ($actorid === 0) {
            if ($action === 'approve') {
                return get_string('statusdetail_system_approved', 'local_tm_course');
            }
            if ($action === 'cancel') {
                return get_string('statusdetail_system_cancelled', 'local_tm_course');
            }
            return get_string('statusdetail_system', 'local_tm_course');
        }
        $name = trim($names[$actorid] ?? '');
        if ($name === '') {
            return get_string('statusdetail_not_recorded', 'local_tm_course');
        }
        return $name;
    }
}
