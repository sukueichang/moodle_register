<?php
/**
 * M5 notification helper (message providers + reminder logic).
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class notification_helper {
    public const TARGET_LEARNER = 'learner';
    public const TARGET_BATCH_SUBMITTER = 'batch_submitter';
    public const TARGET_REQUESTER = 'requester';
    public const TARGET_APPROVER = 'approver';

    /**
     * Notify learner and (optional) batch submitter about approval result.
     */
    public static function notify_approval_result(int $enrolid, bool $approved, string $reason = ''): void {
        global $DB;

        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', IGNORE_MISSING);
        if (!$enrol) {
            return;
        }
        $learner = $DB->get_record('user', ['id' => (int)$enrol->userid, 'deleted' => 0], '*', IGNORE_MISSING);
        $session = $DB->get_record('local_tm_course_sessions', ['id' => (int)$enrol->sessionid], '*', IGNORE_MISSING);
        if (!$learner || !$session) {
            return;
        }

        $statuskey = $approved ? 'notify_status_approved' : 'notify_status_rejected';
        $tokens = [
            'session' => (string)$session->name,
            'status' => get_string($statuskey, 'local_tm_course'),
            'reason' => $reason !== '' ? $reason : get_string('none'),
            'learner' => fullname($learner),
            'applied_at' => userdate((int)($enrol->timecreated ?? time()), get_string('strftimedatetimeshort')),
            'meeting_link' => ((string)($session->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE)
                ? trim((string)($session->meeting_link ?? ''))
                : '',
            'reservationid' => '',
            'count' => '',
            'threshold' => '',
        ];

        $settings = self::get_event_target_settings('approval_result');

        $recipientids = [];
        $holderfake = enrolment_manager::is_placeholder_holder_email((string)($learner->email ?? ''));
        if (in_array(self::TARGET_LEARNER, $settings['targets'], true) && !$holderfake) {
            $recipientids[] = (int)$learner->id;
        }

        $submitterid = (int)($enrol->batch_submittedby ?? 0);
        if (in_array(self::TARGET_BATCH_SUBMITTER, $settings['targets'], true)
            && $submitterid > 0 && $submitterid !== (int)$learner->id) {
            $submitter = $DB->get_record('user', ['id' => $submitterid, 'deleted' => 0], '*', IGNORE_MISSING);
            if ($submitter) {
                $recipientids[] = (int)$submitter->id;
            }
        }
        $recipientids = array_merge($recipientids, self::collect_role_user_ids($settings['roleids']));
        foreach (self::normalise_user_ids($recipientids) as $userid) {
            self::send_event_message($userid, 'approval_result_submitter', 'approval_result', $tokens);
        }
    }

    public static function notify_new_enrolment_submitted(int $enrolid): void {
        global $DB;
        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', IGNORE_MISSING);
        if (!$enrol) {
            return;
        }
        $learner = $DB->get_record('user', ['id' => (int)$enrol->userid, 'deleted' => 0], '*', IGNORE_MISSING);
        $session = $DB->get_record('local_tm_course_sessions', ['id' => (int)$enrol->sessionid], '*', IGNORE_MISSING);
        if (!$learner || !$session) {
            return;
        }
        $settings = self::get_event_target_settings('new_enrolment');
        $tokens = [
            'session' => (string)$session->name,
            'status' => get_string('enrol_pending', 'local_tm_course'),
            'reason' => '',
            'learner' => fullname($learner),
            'applied_at' => userdate((int)($enrol->timecreated ?? time()), get_string('strftimedatetimeshort')),
            'meeting_link' => ((string)($session->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE)
                ? trim((string)($session->meeting_link ?? ''))
                : '',
            'reservationid' => '',
            'count' => '',
            'threshold' => '',
        ];
        $recipientids = [];
        if (in_array(self::TARGET_APPROVER, $settings['targets'], true)) {
            $recipientids = array_merge($recipientids, self::collect_approver_ids());
        }
        $recipientids = array_merge($recipientids, self::collect_role_user_ids($settings['roleids']));
        foreach (self::normalise_user_ids($recipientids) as $userid) {
            self::send_event_message($userid, 'pending_reminder_admin', 'new_enrolment', $tokens);
        }
    }

    public static function notify_enrolment_cancelled(int $enrolid): void {
        global $DB;
        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', IGNORE_MISSING);
        if (!$enrol) {
            return;
        }
        $learner = $DB->get_record('user', ['id' => (int)$enrol->userid, 'deleted' => 0], '*', IGNORE_MISSING);
        $session = $DB->get_record('local_tm_course_sessions', ['id' => (int)$enrol->sessionid], '*', IGNORE_MISSING);
        if (!$learner || !$session) {
            return;
        }
        $settings = self::get_event_target_settings('cancelled');
        $cancelreason = self::format_cancel_reason($enrol);
        $tokens = [
            'session' => (string)$session->name,
            'status' => get_string('enrol_cancelled', 'local_tm_course'),
            'reason' => $cancelreason,
            'learner' => fullname($learner),
            'applied_at' => userdate((int)($enrol->timecreated ?? time()), get_string('strftimedatetimeshort')),
            'meeting_link' => ((string)($session->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE)
                ? trim((string)($session->meeting_link ?? ''))
                : '',
            'reservationid' => '',
            'count' => '',
            'threshold' => '',
        ];
        $recipientids = [];
        if (in_array(self::TARGET_LEARNER, $settings['targets'], true)) {
            $recipientids[] = (int)$learner->id;
        }
        if (in_array(self::TARGET_APPROVER, $settings['targets'], true)) {
            $recipientids = array_merge($recipientids, self::collect_approver_ids());
        }
        $recipientids = array_merge($recipientids, self::collect_role_user_ids($settings['roleids']));
        foreach (self::normalise_user_ids($recipientids) as $userid) {
            self::send_event_message($userid, 'approval_result_learner', 'cancelled', $tokens);
        }
    }

    /**
     * Always notify learner + batch submitter (and reservation requester when known)
     * when an enrolment is auto-cancelled because a prerequisite enrolment was lost.
     */
    public static function notify_prereq_cascade_cancelled(
        int $enrolid,
        int $sourceenrolid,
        string $prereqsessionname
    ): void {
        global $DB;
        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', IGNORE_MISSING);
        if (!$enrol) {
            return;
        }
        $learner = $DB->get_record('user', ['id' => (int)$enrol->userid, 'deleted' => 0], '*', IGNORE_MISSING);
        $session = $DB->get_record('local_tm_course_sessions', ['id' => (int)$enrol->sessionid], '*', IGNORE_MISSING);
        if (!$learner || !$session) {
            return;
        }

        $prereqsessionname = trim($prereqsessionname);
        if ($prereqsessionname === '') {
            $prereqsessionname = get_string('none');
        }
        $a = (object)[
            'session' => (string)$session->name,
            'prereq_session' => $prereqsessionname,
            'learner' => fullname($learner),
        ];
        $subject = get_string('notify_prereq_cascade_subject', 'local_tm_course', $a);
        $bodylearner = get_string('notify_prereq_cascade_body_learner', 'local_tm_course', $a);
        $bodysubmitter = get_string('notify_prereq_cascade_body_submitter', 'local_tm_course', $a);

        $recipientids = [(int)$learner->id];
        $submitterid = (int)($enrol->batch_submittedby ?? 0);
        if ($submitterid > 1) {
            $recipientids[] = $submitterid;
        }

        $rid = (int)($session->source_reservation_id ?? 0);
        if ($rid > 0) {
            $requesterid = (int)$DB->get_field('local_tm_course_reservation', 'requesterid', ['id' => $rid]);
            if ($requesterid > 1) {
                $recipientids[] = $requesterid;
            }
        }

        foreach (self::normalise_user_ids($recipientids) as $userid) {
            $body = ($userid === (int)$learner->id) ? $bodylearner : $bodysubmitter;
            self::send_message($userid, 'approval_result_learner', $subject, $body);
        }
    }

    /**
     * Notify when a dedicated class application is formally submitted (step 3/3).
     */
    public static function notify_reservation_submitted(int $reservationid): void {
        global $DB;

        $reservation = $DB->get_record('local_tm_course_reservation', ['id' => $reservationid], '*', IGNORE_MISSING);
        if (!$reservation || (int) ($reservation->application_submitted ?? 0) !== 1) {
            return;
        }

        $requesterid = (int) ($reservation->requesterid ?? 0);
        $requester = $requesterid > 0
            ? $DB->get_record('user', ['id' => $requesterid, 'deleted' => 0], '*', IGNORE_MISSING)
            : null;
        if (!$requester) {
            return;
        }

        $courseids = reservation_plan_validator::get_reservation_course_ids($reservation);
        $coursenames = [];
        if (!empty($courseids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $courserows = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $insql", $inparams);
            foreach ($courseids as $cid) {
                if (!empty($courserows[$cid])) {
                    $coursenames[] = format_string((string) $courserows[$cid]->fullname);
                }
            }
        }

        $learnercount = (int) $DB->count_records('local_tm_course_resv_learner', ['reservationid' => $reservationid]);
        $plan = json_decode((string) ($reservation->calendar_plan_json ?? ''), true);
        $blockcount = is_array($plan) ? count($plan) : 0;

        $delivery = (string) ($reservation->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE
            ? get_string('delivery_mode_online', 'local_tm_course')
            : get_string('delivery_mode_onsite', 'local_tm_course');

        $note = trim((string) ($reservation->batch_submitter_note ?? ''));
        $submittedat = (int) ($reservation->timesubmitted ?? 0);
        if ($submittedat <= 0) {
            $submittedat = time();
        }

        $settings = self::get_event_target_settings('reservation_submitted');
        $tokens = [
            'session' => '',
            'status' => get_string('enrol_pending', 'local_tm_course'),
            'reason' => '',
            'learner' => '',
            'submitter' => fullname($requester),
            'requester' => fullname($requester),
            'courses' => implode('、', $coursenames) ?: get_string('none'),
            'delivery_mode' => $delivery,
            'count' => (string) $learnercount,
            'block_count' => (string) $blockcount,
            'batch_note' => $note !== '' ? $note : get_string('none'),
            'applied_at' => userdate($submittedat, get_string('strftimedatetimeshort')),
            'meeting_link' => '',
            'reservationid' => (string) $reservationid,
            'threshold' => '',
        ];

        $recipientids = [];
        if (in_array(self::TARGET_APPROVER, $settings['targets'], true)) {
            $recipientids = array_merge($recipientids, self::collect_approver_ids());
        }
        if (in_array(self::TARGET_REQUESTER, $settings['targets'], true) && $requesterid > 0) {
            $recipientids[] = $requesterid;
        }
        $recipientids = array_merge($recipientids, self::collect_role_user_ids($settings['roleids']));
        foreach (self::normalise_user_ids($recipientids) as $userid) {
            self::send_event_message($userid, 'reservation_submitted', 'reservation_submitted', $tokens);
        }
    }

    public static function notify_reservation_result(int $requesterid, bool $approved, int $reservationid, string $note = ''): void {
        $settings = self::get_event_target_settings('reservation_result');
        $statuskey = $approved ? 'notify_status_approved' : 'notify_status_rejected';
        $tokens = [
            'session' => '',
            'status' => get_string($statuskey, 'local_tm_course'),
            'reason' => $note,
            'learner' => '',
            'applied_at' => '',
            'meeting_link' => '',
            'reservationid' => (string)$reservationid,
            'count' => '',
            'threshold' => '',
        ];
        if (in_array(self::TARGET_REQUESTER, $settings['targets'], true)) {
            $recipientids = [$requesterid];
        } else {
            $recipientids = [];
        }
        $recipientids = array_merge($recipientids, self::collect_role_user_ids($settings['roleids']));
        foreach (self::normalise_user_ids($recipientids) as $userid) {
            self::send_event_message($userid, 'approval_result_submitter', 'reservation_result', $tokens);
        }
    }

    /**
     * Daily reminder: count pending enrolments older than configured threshold and notify approvers.
     */
    public static function notify_pending_overdue_to_admins(): void {
        $threshold = self::get_reminder_threshold_seconds();
        self::notify_pending_overdue_to_admins_by_threshold($threshold);
    }

    /**
     * Notify approvers for pending records older than provided threshold.
     */
    /**
     * Notify when a user successfully submits a batch enrolment (pending rows created).
     */
    public static function notify_batch_enrol_completed(
        int $sessionid,
        int $submitterid,
        int $processedcount,
        string $batchsubmitternote = ''
    ): void {
        global $DB;

        if ($processedcount <= 0) {
            return;
        }

        $session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid], '*', IGNORE_MISSING);
        $submitter = $DB->get_record('user', ['id' => $submitterid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$session || !$submitter) {
            return;
        }

        $settings = self::get_event_target_settings('batch_enrol_completed');
        $note = trim($batchsubmitternote);
        $tokens = [
            'session' => (string)$session->name,
            'learner' => '',
            'status' => get_string('enrol_pending', 'local_tm_course'),
            'reason' => '',
            'submitter' => fullname($submitter),
            'count' => (string)$processedcount,
            'batch_note' => $note !== '' ? $note : get_string('none'),
            'applied_at' => userdate(time(), get_string('strftimedatetimeshort')),
            'meeting_link' => ((string)($session->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE)
                ? trim((string)($session->meeting_link ?? ''))
                : '',
            'reservationid' => '',
            'threshold' => '',
        ];

        $recipientids = [];
        if (in_array(self::TARGET_APPROVER, $settings['targets'], true)) {
            $recipientids = array_merge($recipientids, self::collect_approver_ids());
        }
        if (in_array(self::TARGET_BATCH_SUBMITTER, $settings['targets'], true) && $submitterid > 0) {
            $recipientids[] = $submitterid;
        }
        $recipientids = array_merge($recipientids, self::collect_role_user_ids($settings['roleids']));
        foreach (self::normalise_user_ids($recipientids) as $userid) {
            self::send_event_message($userid, 'batch_enrol_completed', 'batch_enrol_completed', $tokens);
        }
    }

    /**
     * Notify newly auto-created learner account from batch flow (initial password for first sign-in).
     *
     * @param string $initialpassword Plain password (only for newly created accounts); included in templates as {{initial_password}}.
     */
    public static function notify_batch_account_created(int $userid, int $submitterid, int $sessionid = 0, string $initialpassword = ''): void {
        global $CFG, $DB;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$user) {
            return;
        }
        $submitter = $DB->get_record('user', ['id' => $submitterid, 'deleted' => 0], '*', IGNORE_MISSING);
        $sessionname = '';
        if ($sessionid > 0) {
            $session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid], 'id,name', IGNORE_MISSING);
            if ($session) {
                $sessionname = (string)$session->name;
            }
        }

        $settings = self::get_event_target_settings('batch_account_created');
        $tokens = [
            'session' => $sessionname,
            'learner' => fullname($user),
            'status' => '',
            'reason' => '',
            'submitter' => $submitter ? fullname($submitter) : '',
            'count' => '',
            'batch_note' => '',
            'applied_at' => userdate(time(), get_string('strftimedatetimeshort')),
            'meeting_link' => '',
            'reservationid' => '',
            'threshold' => '',
            'login_url' => (new \moodle_url('/login/index.php'))->out(false),
            'reset_url' => (new \moodle_url('/login/forgot_password.php'))->out(false),
            'username' => (string)($user->username ?? ''),
            'learner_email' => (string)($user->email ?? ''),
            'initial_password' => $initialpassword,
        ];

        $recipientids = [];
        if (in_array(self::TARGET_LEARNER, $settings['targets'], true)) {
            $recipientids[] = (int)$user->id;
        }
        if (in_array(self::TARGET_BATCH_SUBMITTER, $settings['targets'], true)
            && $submitterid > 0 && $submitterid !== (int)$user->id) {
            $recipientids[] = $submitterid;
        }
        $recipientids = array_merge($recipientids, self::collect_role_user_ids($settings['roleids']));
        foreach (self::normalise_user_ids($recipientids) as $rid) {
            self::send_batch_account_created_bilingual_message((int)$rid, $tokens);
        }
    }

    /**
     * Send batch-account-created mail using both EN and zh_TW templates (English first, then Chinese).
     * Subject: English ｜ Traditional Chinese (fullwidth separator).
     */
    private static function send_batch_account_created_bilingual_message(int $useridto, array $tokens): void {
        $tplen = self::get_event_template('batch_account_created', 'en');
        $tplzh = self::get_event_template('batch_account_created', 'zh_tw');

        $suben = trim(self::render_template($tplen['subject'], $tokens));
        $subzh = trim(self::render_template($tplzh['subject'], $tokens));
        if ($suben !== '' && $subzh !== '') {
            $subject = $suben . ' ｜ ' . $subzh;
        } else {
            $subject = $suben !== '' ? $suben : $subzh;
        }

        $bodyen = trim(self::render_template($tplen['body'], $tokens));
        $bodyzh = trim(self::render_template($tplzh['body'], $tokens));
        if ($bodyen !== '' && $bodyzh !== '') {
            $body = $bodyen . "\n\n" . $bodyzh;
        } else {
            $body = $bodyen !== '' ? $bodyen : $bodyzh;
        }

        self::send_message($useridto, 'batch_account_created', $subject, $body);
    }

    public static function notify_pending_overdue_to_admins_by_threshold(int $threshold): void {
        global $DB;

        $cutoff = time() - max(1, $threshold);
        $count = (int)$DB->count_records_select(
            'local_tm_course_enrolments',
            'status = :st AND timecreated < :cutoff',
            ['st' => session_manager::ENROL_PENDING, 'cutoff' => $cutoff]
        );
        if ($count <= 0) {
            return;
        }

        $ctx = \context_system::instance();
        $admins = get_users_by_capability($ctx, 'local/tm_course:approve', 'u.id, u.firstname, u.lastname, u.email');
        if (empty($admins)) {
            return;
        }

        $subject = get_string('notify_pending_subject', 'local_tm_course', (object)['count' => $count]);
        $body = get_string('notify_pending_body', 'local_tm_course', (object)[
            'count' => $count,
            'threshold' => self::format_threshold($threshold),
        ]);
        $settings = self::get_event_target_settings('pending_overdue');
        $tokens = [
            'session' => '',
            'status' => '',
            'reason' => '',
            'learner' => '',
            'applied_at' => '',
            'meeting_link' => '',
            'reservationid' => '',
            'count' => (string)$count,
            'threshold' => self::format_threshold($threshold),
        ];
        $recipientids = [];
        if (in_array(self::TARGET_APPROVER, $settings['targets'], true)) {
            foreach ($admins as $admin) {
                $recipientids[] = (int)$admin->id;
            }
        }
        $recipientids = array_merge($recipientids, self::collect_role_user_ids($settings['roleids']));
        foreach (self::normalise_user_ids($recipientids) as $userid) {
            self::send_event_message($userid, 'pending_reminder_admin', 'pending_overdue', $tokens);
        }
    }

    private static function send_event_message(int $useridto, string $provider, string $eventkey, array $tokens): void {
        global $DB;
        $user = $DB->get_record('user', ['id' => $useridto, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$user) {
            return;
        }
        $templang = self::resolve_template_lang_for_user($user);
        $template = self::get_event_template($eventkey, $templang);
        $subject = self::render_template($template['subject'], $tokens);
        $body = self::render_template($template['body'], $tokens);
        self::send_message($useridto, $provider, $subject, $body);
    }

    /**
     * Get configured threshold (seconds). Defaults to 24h.
     */
    public static function get_reminder_threshold_seconds(): int {
        $raw = get_config('local_tm_course', 'reminder_threshold');
        $val = (int)$raw;
        if ($val <= 0) {
            return 24 * HOURSECS;
        }
        return $val;
    }

    private static function format_threshold(int $seconds): string {
        if ($seconds % HOURSECS === 0) {
            $h = (int)($seconds / HOURSECS);
            return get_string('reminder_hours_option', 'local_tm_course', $h);
        }
        $m = (int)($seconds / MINSECS);
        return get_string('reminder_minutes_option', 'local_tm_course', $m);
    }

    private static function send_message(int $useridto, string $provider, string $subject, string $fullmessage): void {
        global $DB;
        $userto = $DB->get_record('user', ['id' => $useridto, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$userto) {
            return;
        }
        $usertoinapp = clone $userto;
        // Keep in-site bell notification via message_send, but prevent Moodle's
        // email message processor from interrupting current admin actions.
        $usertoinapp->emailstop = 1;

        try {
            $eventdata = new \core\message\message();
            $eventdata->component = 'local_tm_course';
            $eventdata->name = $provider;
            $eventdata->userfrom = \core_user::get_noreply_user();
            $eventdata->userto = $usertoinapp;
            $eventdata->subject = $subject;
            $eventdata->fullmessage = $fullmessage;
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml = '';
            $eventdata->smallmessage = $subject;
            $eventdata->notification = 1;
            message_send($eventdata);
        } catch (\Throwable $t) {
            debugging('TM Course notification failed: ' . $t->getMessage(), DEBUG_DEVELOPER);
        }
        // Email channel is sent explicitly and independently.
        try {
            email_to_user($userto, \core_user::get_noreply_user(), $subject, $fullmessage);
        } catch (\Throwable $t) {
            debugging('TM Course email notification failed: ' . $t->getMessage(), DEBUG_DEVELOPER);
        }
    }

    private static function collect_role_user_ids(array $roleids): array {
        if (empty($roleids)) {
            return [];
        }
        $ctx = \context_system::instance();
        $ids = [];
        foreach ($roleids as $roleid) {
            $users = get_role_users((int)$roleid, $ctx, false, 'u.id');
            foreach ($users as $u) {
                $ids[] = (int)$u->id;
            }
        }
        return $ids;
    }

    private static function collect_approver_ids(): array {
        $approvers = get_users_by_capability(\context_system::instance(), 'local/tm_course:approve', 'u.id');
        $ids = [];
        foreach ($approvers as $approver) {
            $ids[] = (int)$approver->id;
        }
        return $ids;
    }

    private static function normalise_user_ids(array $ids): array {
        $ids = array_values(array_filter(array_map('intval', $ids), static function(int $id): bool {
            return $id > 0;
        }));
        return array_values(array_unique($ids));
    }

    private static function format_cancel_reason(\stdClass $enrol): string {
        $code = trim((string)($enrol->cancel_reason_code ?? ''));
        $text = trim((string)($enrol->cancel_reason_text ?? ''));
        if ($code === 'work') {
            return get_string('cancel_reason_work', 'local_tm_course');
        }
        if ($code === 'other_session') {
            return get_string('cancel_reason_other_session', 'local_tm_course');
        }
        if ($code === 'other') {
            return $text !== '' ? $text : get_string('cancel_reason_other', 'local_tm_course');
        }
        if ($code === 'batch_submitter') {
            return get_string('cancel_reason_batch_submitter', 'local_tm_course');
        }
        if ($code === 'prereq_cascade') {
            $name = $text !== '' ? $text : get_string('none');
            return get_string('cancel_reason_prereq_cascade', 'local_tm_course', $name);
        }
        if ($text !== '') {
            return $text;
        }
        return get_string('none');
    }

    public static function get_notification_events_config(): array {
        return [
            'new_enrolment' => [
                'label' => get_string('notify_event_new_enrolment', 'local_tm_course'),
                'tokens' => ['{{session}}', '{{learner}}', '{{status}}', '{{applied_at}}'],
                'defaultsubject_zh_tw' => '【TM 課程】新報名：{{session}}',
                'defaultbody_zh_tw' => '學員 {{learner}} 已提交 {{session}} 的報名申請（狀態：{{status}}，申請時間：{{applied_at}}）。',
                'defaultsubject_en' => '[TM Course] New enrolment: {{session}}',
                'defaultbody_en' => 'Learner {{learner}} submitted enrolment for {{session}} (status: {{status}}, applied at: {{applied_at}}).',
            ],
            'approval_result' => [
                'label' => get_string('notify_event_approval_result', 'local_tm_course'),
                'tokens' => ['{{session}}', '{{learner}}', '{{status}}', '{{reason}}', '{{meeting_link}}'],
                'defaultsubject_zh_tw' => '【TM 課程】{{session}} — {{status}}',
                'defaultbody_zh_tw' => "學員：{{learner}}\n場次：{{session}}\n審核結果：{{status}}\n原因：{{reason}}\n視訊連結：{{meeting_link}}",
                'defaultsubject_en' => '[TM Course] {{session}} — {{status}}',
                'defaultbody_en' => "Learner: {{learner}}\nSession: {{session}}\nResult: {{status}}\nReason: {{reason}}\nMeeting link: {{meeting_link}}",
            ],
            'cancelled' => [
                'label' => get_string('notify_event_cancelled', 'local_tm_course'),
                'tokens' => ['{{session}}', '{{learner}}', '{{status}}', '{{reason}}'],
                'defaultsubject_zh_tw' => '【TM 課程】{{session}} — {{status}}',
                'defaultbody_zh_tw' => "學員：{{learner}}\n場次：{{session}}\n狀態：{{status}}\n取消原因：{{reason}}",
                'defaultsubject_en' => '[TM Course] {{session}} — {{status}}',
                'defaultbody_en' => "Learner: {{learner}}\nSession: {{session}}\nStatus: {{status}}\nCancellation reason: {{reason}}",
            ],
            'pending_overdue' => [
                'label' => get_string('notify_event_pending_overdue', 'local_tm_course'),
                'tokens' => ['{{count}}', '{{threshold}}'],
                'defaultsubject_zh_tw' => '【TM 課程】待審核逾期 {{count}} 筆',
                'defaultbody_zh_tw' => '目前有 {{count}} 筆待審核報名超過 {{threshold}} 尚未處理，請儘速審核。',
                'defaultsubject_en' => '[TM Course] {{count}} pending enrolment(s) overdue',
                'defaultbody_en' => 'There are {{count}} pending enrolment(s) older than {{threshold}}. Please review them.',
            ],
            'reservation_result' => [
                'label' => get_string('notify_event_reservation_result', 'local_tm_course'),
                'tokens' => ['{{reservationid}}', '{{status}}', '{{reason}}'],
                'defaultsubject_zh_tw' => '【TM 課程】專屬開班申請 #{{reservationid}} — {{status}}',
                'defaultbody_zh_tw' => "專屬開班申請編號：#{{reservationid}}\n審核結果：{{status}}\n備註：{{reason}}",
                'defaultsubject_en' => '[TM Course] Dedicated class application #{{reservationid}} — {{status}}',
                'defaultbody_en' => "Dedicated class application #{{reservationid}}\nResult: {{status}}\nNote: {{reason}}",
            ],
            'reservation_submitted' => [
                'label' => get_string('notify_event_reservation_submitted', 'local_tm_course'),
                'tokens' => [
                    '{{reservationid}}',
                    '{{requester}}',
                    '{{courses}}',
                    '{{delivery_mode}}',
                    '{{count}}',
                    '{{block_count}}',
                    '{{batch_note}}',
                    '{{applied_at}}',
                ],
                'defaultsubject_zh_tw' => '【TM 課程】專屬開班申請已送出：#{{reservationid}}（{{courses}}）',
                'defaultbody_zh_tw' => "申請編號：#{{reservationid}}\n申請者：{{requester}}\n課程：{{courses}}\n授課方式：{{delivery_mode}}\n學員名單筆數：{{count}}\n排程區塊數：{{block_count}}\n申請備註：{{batch_note}}\n送出時間：{{applied_at}}",
                'defaultsubject_en' => '[TM Course] Dedicated class application submitted: #{{reservationid}} ({{courses}})',
                'defaultbody_en' => "Application #: {{reservationid}}\nRequester: {{requester}}\nCourses: {{courses}}\nDelivery: {{delivery_mode}}\nLearner rows: {{count}}\nSchedule blocks: {{block_count}}\nApplication note: {{batch_note}}\nSubmitted at: {{applied_at}}",
            ],
            'batch_enrol_completed' => [
                'label' => get_string('notify_event_batch_enrol_completed', 'local_tm_course'),
                'tokens' => [
                    '{{session}}',
                    '{{submitter}}',
                    '{{count}}',
                    '{{batch_note}}',
                    '{{applied_at}}',
                    '{{meeting_link}}',
                ],
                'defaultsubject_zh_tw' => '【TM 課程】批次報名已送出：{{session}}（{{count}} 筆）',
                'defaultbody_zh_tw' => "批次提交者：{{submitter}}\n場次：{{session}}\n已送出待審核筆數：{{count}}\n批次備註：{{batch_note}}\n送出時間：{{applied_at}}\n視訊連結：{{meeting_link}}",
                'defaultsubject_en' => '[TM Course] Batch enrolment submitted: {{session}} ({{count}})',
                'defaultbody_en' => "Submitted by: {{submitter}}\nSession: {{session}}\nPending enrolments: {{count}}\nBatch note: {{batch_note}}\nSubmitted at: {{applied_at}}\nMeeting link: {{meeting_link}}",
            ],
            'batch_account_created' => [
                'label' => get_string('notify_event_batch_account_created', 'local_tm_course'),
                'tokens' => [
                    '{{learner}}',
                    '{{learner_email}}',
                    '{{username}}',
                    '{{initial_password}}',
                    '{{session}}',
                    '{{submitter}}',
                    '{{login_url}}',
                    '{{reset_url}}',
                ],
                'defaultsubject_zh_tw' => '【TM 課程】歡迎使用 Moodle 學習帳號',
                'defaultbody_zh_tw' => "您好 {{learner}}：\n歡迎註冊 Moodle 學習帳號。\n登入信箱：{{learner_email}}\n登入帳號：{{username}}\n初始密碼：{{initial_password}}\n登入網址：{{login_url}}\n來源場次：{{session}}\n提交業務：{{submitter}}\n請首次登入後立即變更密碼（系統可能會要求變更）。\n若無法登入，可使用忘記密碼：{{reset_url}}",
                'defaultsubject_en' => '[TM Course] Your Moodle learning account is ready',
                'defaultbody_en' => "Hello {{learner}},\nYour Moodle learning account has been created.\nEmail on file: {{learner_email}}\nUsername: {{username}}\nInitial password: {{initial_password}}\nSign-in: {{login_url}}\nSession: {{session}}\nSubmitted by: {{submitter}}\nPlease change your password after first sign-in (you may be prompted to do so).\nIf you cannot sign in, use Forgot password: {{reset_url}}",
            ],
        ];
    }

    public static function get_recipient_target_options(): array {
        return [
            self::TARGET_LEARNER => get_string('notify_target_learner', 'local_tm_course'),
            self::TARGET_BATCH_SUBMITTER => get_string('notify_target_batch_submitter', 'local_tm_course'),
            self::TARGET_REQUESTER => get_string('notify_target_requester', 'local_tm_course'),
            self::TARGET_APPROVER => get_string('notify_target_approver', 'local_tm_course'),
        ];
    }

    public static function get_event_target_settings(string $eventkey): array {
        $meta = self::get_notification_events_config()[$eventkey] ?? null;
        if (!$meta) {
            return ['targets' => [], 'roleids' => []];
        }
        $targetsraw = (string)get_config('local_tm_course', 'notifyrecips_' . $eventkey . '_targets');
        $rolesraw = (string)get_config('local_tm_course', 'notifyrecips_' . $eventkey . '_roleids');
        $targets = $targetsraw === '' ? self::get_default_targets($eventkey) : preg_split('/\s*,\s*/', $targetsraw, -1, PREG_SPLIT_NO_EMPTY);
        $roleids = [];
        if ($rolesraw !== '') {
            $roleids = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $rolesraw, -1, PREG_SPLIT_NO_EMPTY))));
        }
        return [
            'targets' => $targets,
            'roleids' => $roleids,
        ];
    }

    public static function get_event_template(string $eventkey, string $templang): array {
        $meta = self::get_notification_events_config()[$eventkey] ?? null;
        if (!$meta) {
            return ['subject' => '', 'body' => ''];
        }
        $subject = (string)get_config('local_tm_course', 'notifytpl_' . $eventkey . '_subject_' . $templang);
        $body = (string)get_config('local_tm_course', 'notifytpl_' . $eventkey . '_body_' . $templang);
        // Backward compatible fallback.
        if ($subject === '') {
            $subject = (string)get_config('local_tm_course', 'notifytpl_' . $eventkey . '_subject');
        }
        if ($body === '') {
            $body = (string)get_config('local_tm_course', 'notifytpl_' . $eventkey . '_body');
        }
        if ($subject === '') {
            $subject = (string)($meta['defaultsubject_' . $templang] ?? $meta['defaultsubject_en'] ?? '');
        }
        if ($body === '') {
            $body = (string)($meta['defaultbody_' . $templang] ?? $meta['defaultbody_en'] ?? '');
        }
        return ['subject' => $subject, 'body' => $body];
    }

    public static function save_event_settings(
        string $eventkey,
        string $subjectzhtw,
        string $bodyzhtw,
        string $subjecten,
        string $bodyen,
        array $targets,
        array $roleids
    ): void {
        set_config('notifytpl_' . $eventkey . '_subject_zh_tw', trim($subjectzhtw), 'local_tm_course');
        set_config('notifytpl_' . $eventkey . '_body_zh_tw', trim($bodyzhtw), 'local_tm_course');
        set_config('notifytpl_' . $eventkey . '_subject_en', trim($subjecten), 'local_tm_course');
        set_config('notifytpl_' . $eventkey . '_body_en', trim($bodyen), 'local_tm_course');
        $targets = array_values(array_intersect($targets, array_keys(self::get_recipient_target_options())));
        set_config('notifyrecips_' . $eventkey . '_targets', implode(',', $targets), 'local_tm_course');
        $roleids = array_values(array_filter(array_map('intval', $roleids), static function(int $v): bool {
            return $v > 0;
        }));
        set_config('notifyrecips_' . $eventkey . '_roleids', implode(',', $roleids), 'local_tm_course');
    }

    private static function resolve_template_lang_for_user(\stdClass $user): string {
        $lang = strtolower(trim((string)($user->lang ?? '')));
        if ($lang === 'zh_tw' || $lang === 'zh_cn') {
            return 'zh_tw';
        }
        return 'en';
    }

    private static function get_default_targets(string $eventkey): array {
        if ($eventkey === 'new_enrolment' || $eventkey === 'pending_overdue') {
            return [self::TARGET_APPROVER];
        }
        if ($eventkey === 'approval_result') {
            return [self::TARGET_LEARNER, self::TARGET_BATCH_SUBMITTER];
        }
        if ($eventkey === 'reservation_result') {
            return [self::TARGET_REQUESTER];
        }
        if ($eventkey === 'reservation_submitted') {
            return [self::TARGET_APPROVER, self::TARGET_REQUESTER];
        }
        if ($eventkey === 'cancelled') {
            return [self::TARGET_LEARNER, self::TARGET_APPROVER];
        }
        if ($eventkey === 'batch_enrol_completed') {
            return [self::TARGET_APPROVER, self::TARGET_BATCH_SUBMITTER];
        }
        if ($eventkey === 'batch_account_created') {
            return [self::TARGET_LEARNER, self::TARGET_BATCH_SUBMITTER];
        }
        return [];
    }

    private static function render_template(string $template, array $tokens): string {
        $out = self::prune_empty_token_lines($template, $tokens);
        foreach ($tokens as $key => $val) {
            $out = str_replace('{{' . $key . '}}', (string)$val, $out);
        }
        return $out;
    }

    private static function prune_empty_token_lines(string $template, array $tokens): string {
        $lines = preg_split("/\r\n|\n|\r/", $template);
        if (!is_array($lines)) {
            return $template;
        }
        $kept = [];
        foreach ($lines as $line) {
            $drop = false;
            foreach ($tokens as $key => $val) {
                if (strpos($line, '{{' . $key . '}}') !== false && trim((string)$val) === '') {
                    $drop = true;
                    break;
                }
            }
            if (!$drop) {
                $kept[] = $line;
            }
        }
        return implode("\n", $kept);
    }
}

