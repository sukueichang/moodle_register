<?php
/**
 * Enrolment Manager — M2 logic (enrol, approve, prereq, institution)
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

// Load attendance_manager for group sync (M3)
require_once(__DIR__ . '/attendance_manager.php');
require_once(__DIR__ . '/notification_helper.php');
require_once(__DIR__ . '/session_verification_manager.php');
require_once(__DIR__ . '/prerequisite_manager.php');

class enrolment_manager {

    /** Moodle user email marker for internal seat-holder accounts (no course enrol until follow-up). */
    public const PLACEHOLDER_EMAIL_MARKER = '@local.tm.placeholder';

    /**
     * Whether this email belongs to an internal placeholder/seat-holder account.
     */
    public static function is_placeholder_holder_email(string $email): bool {
        $email = \core_text::strtolower(trim($email));
        return ($email !== '' && strpos($email, self::PLACEHOLDER_EMAIL_MARKER) !== false);
    }

    /**
     * Whether the Moodle user is an internal seat-holder account.
     */
    public static function is_placeholder_holder_userid(int $userid): bool {
        global $DB;
        if ($userid <= 0) {
            return false;
        }
        $email = $DB->get_field('user', 'email', ['id' => $userid, 'deleted' => 0], IGNORE_MISSING);
        return !empty($email) && self::is_placeholder_holder_email((string)$email);
    }

    /**
     * Create a Moodle user used only to anchor a pending seat until a real learner email is supplied.
     */
    public static function create_placeholder_holder_user(int $sessionid, int $seq, string $company): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $company = clean_param(trim($company), PARAM_TEXT);
        if ($company === '') {
            throw new \moodle_exception('error_institution_required', 'local_tm_course');
        }

        $uniq = random_string(8);
        $email = 'tm.ph.' . $sessionid . '.' . $seq . '.' . $uniq . self::PLACEHOLDER_EMAIL_MARKER;

        $firstname = get_string('batch_placeholder_firstname', 'local_tm_course');
        $lastname = (string) $seq;

        $usernamebase = 'tm_ph_' . $sessionid . '_' . $seq;
        $username = $usernamebase;
        $suffix = 1;
        while ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
            $suffix++;
            $username = substr($usernamebase, 0, max(1, 30 - strlen((string)$suffix))) . '_' . $suffix;
        }

        $user = new \stdClass();
        $user->auth = 'manual';
        $user->confirmed = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->username = $username;
        $user->email = $email;
        $user->firstname = $firstname;
        $user->lastname = $lastname;
        $user->institution = $company;
        // Manual accounts need a stored hash; learners never log in as holders. Avoid user_password()
        // (not loaded in all bootstrap paths / some forks) — use PHP CSPRNG then Moodle's hash helper.
        $plainsecret = bin2hex(random_bytes(16));
        $user->password = \hash_internal_user_password($plainsecret);
        $user->country = 'TW';
        $user->lang = $CFG->lang;
        $user->timezone = '99';
        $user->maildisplay = 0;
        $user->autosubscribe = 0;
        $user->trackforums = 0;
        $user->timecreated = time();
        $user->timemodified = time();

        $newid = (int) \user_create_user($user, false, false);
        if ($newid < 2) {
            throw new \moodle_exception('error_batch_user_invalid', 'local_tm_course');
        }
        return $newid;
    }

    /**
     * Plain initial password for auto-created manual accounts (policy-friendly when core API exists).
     */
    private static function generate_batch_initial_plain_password(): string {
        if (function_exists('generate_password')) {
            return generate_password(20, true);
        }
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@$%_-';
        $pw = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < 16; $i++) {
            $pw .= $chars[random_int(0, $max)];
        }
        return $pw;
    }

    /**
     * Resolve existing Moodle user by email or create a real learner account for batch flow.
     *
     * @return array{userid:int,created:bool,linked:bool,email:string,initial_password:string}
     */
    public static function provision_or_link_batch_user(
        int $sessionid,
        string $email,
        string $firstname,
        string $lastname,
        string $institution,
        int $submitterid = 0
    ): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $email = \core_text::strtolower(trim($email));
        $firstname = clean_param(trim($firstname), PARAM_TEXT);
        $lastname = clean_param(trim($lastname), PARAM_TEXT);
        $institution = clean_param(trim($institution), PARAM_TEXT);

        if ($email === '' || !validate_email($email)) {
            throw new \moodle_exception('error_batch_email_invalid', 'local_tm_course');
        }
        if ($firstname === '' || $lastname === '') {
            throw new \moodle_exception('error_batch_name_required', 'local_tm_course');
        }
        if ($institution === '') {
            throw new \moodle_exception('error_institution_required', 'local_tm_course');
        }

        $existing = $DB->get_record('user', [
            'email' => $email,
            'deleted' => 0,
            'mnethostid' => $CFG->mnet_localhost_id,
        ], 'id,institution', IGNORE_MISSING);
        if ($existing) {
            // Keep existing profile intact, but backfill institution when blank.
            if (trim((string)$existing->institution) === '') {
                $DB->set_field('user', 'institution', $institution, ['id' => (int)$existing->id]);
            }
            return [
                'userid' => (int)$existing->id,
                'created' => false,
                'linked' => true,
                'email' => $email,
                'initial_password' => '',
            ];
        }

        $username = self::generate_batch_username($email);
        $plainsecret = self::generate_batch_initial_plain_password();
        $now = time();
        $newuser = new \stdClass();
        $newuser->auth = 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = $CFG->mnet_localhost_id;
        $newuser->username = $username;
        $newuser->password = \hash_internal_user_password($plainsecret);
        $newuser->firstname = $firstname;
        $newuser->lastname = $lastname;
        $newuser->email = $email;
        $newuser->institution = $institution;
        $newuser->country = 'TW';
        $newuser->lang = $CFG->lang;
        $newuser->timezone = '99';
        $newuser->maildisplay = 0;
        $newuser->autosubscribe = 0;
        $newuser->trackforums = 0;
        $newuser->timecreated = $now;
        $newuser->timemodified = $now;
        $newuserid = (int)\user_create_user($newuser, false, false);
        if ($newuserid < 2) {
            throw new \moodle_exception('error_batch_user_invalid', 'local_tm_course');
        }
        // Require learner to reset password on first login when schema supports it.
        // Some older/custom Moodle schemas may not have this column.
        $usertable = new \xmldb_table('user');
        $forcepwfield = new \xmldb_field('forcepasswordchange');
        if ($DB->get_manager()->field_exists($usertable, $forcepwfield)) {
            $DB->set_field('user', 'forcepasswordchange', 1, ['id' => $newuserid]);
        }

        if ($submitterid > 0) {
            notification_helper::notify_batch_account_created($newuserid, $submitterid, $sessionid, $plainsecret);
        }

        return [
            'userid' => $newuserid,
            'created' => true,
            'linked' => false,
            'email' => $email,
            'initial_password' => $plainsecret,
        ];
    }

    /**
     * Generate a unique username for auto-created batch learners.
     */
    private static function generate_batch_username(string $email): string {
        global $DB, $CFG;

        $localpart = trim((string)preg_replace('/[^a-z0-9._-]+/i', '', strstr($email, '@', true)));
        if ($localpart === '') {
            $localpart = 'tm_learner';
        }
        $base = \core_text::strtolower(substr($localpart, 0, 20));
        $username = $base;
        $suffix = 0;
        while ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
            $suffix++;
            $username = substr($base, 0, max(1, 28 - strlen((string)$suffix))) . '_' . $suffix;
        }
        return $username;
    }

    /**
     * Persist institution on the Moodle user profile (self-enrol confirmation step).
     *
     * @throws \moodle_exception when institution is blank after trim
     */
    public static function sync_user_institution(int $userid, string $institution): string {
        global $DB, $USER;

        $institution = clean_param(trim($institution), PARAM_TEXT);
        if ($institution === '') {
            throw new \moodle_exception('error_institution_required', 'local_tm_course');
        }

        $DB->set_field('user', 'institution', $institution, ['id' => $userid]);
        if ((int) $userid === (int) ($USER->id ?? 0)) {
            $USER->institution = $institution;
        }

        return $institution;
    }

    /**
     * Attempt to enrol a user in a session.
     * Runs all validation checks first.
     *
     * @param  int    $sessionid
     * @param  int    $userid
     * @param  string $institution   Institution from the enrolment confirmation form
     * @param  array  $diet          Diet survey data (optional)
     * @return int    Enrolment record id
     */
    public static function enrol(int $sessionid, int $userid, string $institution = '', array $diet = []): int {
        global $DB, $USER;

        $session = session_manager::get_session($sessionid);

        if (!session_manager::can_submit_enrolment($session, false)) {
            if (!session_manager::is_online_session($session)
                && ((int) $session->status === session_manager::STATUS_FULL
                    || session_manager::is_onsite_persons_full($session))) {
                throw new \moodle_exception('error_session_full', 'local_tm_course');
            }
            if (session_manager::is_registration_deadline_passed($session)) {
                throw new \moodle_exception('error_session_registration_deadline', 'local_tm_course');
            }
            throw new \moodle_exception('error_session_full', 'local_tm_course');
        }

        // 2. If enrol record already exists for (sessionid, userid),
        //    never INSERT again (uq_session_user). Instead UPDATE diet fields.
        $existing = $DB->get_record('local_tm_course_enrolments', [
            'sessionid' => $sessionid,
            'userid'    => $userid,
        ], '*', IGNORE_MISSING);

        $choice = strtoupper(trim((string)($diet['choice'] ?? '')));
        // Column is NOT NULL on upgraded DBs (see upgrade 2026040218); use '' not NULL when unset.
        $diet_choice = in_array($choice, ['A', 'B'], true) ? $choice : '';
        $diet_avoid_beef = 0;
        $diet_avoid_seafood = 0;
        $specialnote = clean_param((string)($diet['special_note'] ?? ''), PARAM_TEXT);
        if ($specialnote === '') {
            // Backward-compatible read for old payloads.
            $specialnote = clean_param((string)($diet['meat_other'] ?? ($diet['vegetarian_notes'] ?? '')), PARAM_TEXT);
        }
        $diet_meat_other = $specialnote;
        $diet_vegetarian_notes = '';

        // If already active (pending/approved/waitlisted/etc), only update diet and return.
        // This prevents "Duplicate entry" when users double-submit the diet step.
        if ($existing && !in_array((int)$existing->status, [
            session_manager::ENROL_CANCELLED,
            session_manager::ENROL_REJECTED,
        ], true)) {
            $existing->diet_choice = $diet_choice;
            $existing->diet_avoid_beef = $diet_avoid_beef;
            $existing->diet_avoid_seafood = $diet_avoid_seafood;
            $existing->diet_meat_other = $diet_meat_other;
            $existing->diet_vegetarian_notes = $diet_vegetarian_notes;
            $existing->timemodified = time();
            $DB->update_record('local_tm_course_enrolments', $existing);

            session_manager::recalculate_status($sessionid);
            return (int)$existing->id;
        }

        // 3. Prerequisite check
        self::assert_user_meets_prerequisites($session, $userid);

        // 4. Institution must be filled and synced to the user profile.
        $institution = self::sync_user_institution($userid, $institution);
        $userobj = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $userobj->institution = $institution;

        // 5. Determine initial status
        $status = ((int)$session->approval_mode === session_manager::APPROVAL_MANUAL)
                   ? session_manager::ENROL_PENDING
                   : session_manager::ENROL_APPROVED;

        // 5.5 Course-level mutual exclusion:
        // If user has an active enrolment in any other *upcoming/ongoing* session of the
        // same course, block the enrol attempt. Past sessions (already ended) and
        // rejected/cancelled rows do not block retaking.
        $activeStatuses = [
            session_manager::ENROL_PENDING,
            session_manager::ENROL_APPROVED,
            session_manager::ENROL_WAITLISTED,
        ];
        list($statusinsql, $statusparams) = $DB->get_in_or_equal($activeStatuses, SQL_PARAMS_NAMED);
        $courseconflictsql = "SELECT 1
                                FROM {local_tm_course_enrolments} e
                                JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
                               WHERE e.userid = :uid
                                 AND s.courseid = :cid
                                 AND e.sessionid <> :sid
                                 AND s.endtime > :now
                                 AND e.status $statusinsql";
        $courseparams = [
            'uid' => $userid,
            'cid' => (int)$session->courseid,
            'sid' => (int)$sessionid,
            'now' => time(),
        ] + $statusparams;
        if ($DB->record_exists_sql($courseconflictsql, $courseparams)) {
            $conflictsql = "SELECT s.starttime
                              FROM {local_tm_course_enrolments} e
                              JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
                             WHERE e.userid = :uid
                               AND s.courseid = :cid
                               AND e.sessionid <> :sid
                               AND s.endtime > :now
                               AND e.status $statusinsql
                             ORDER BY s.starttime ASC";
            $conflictrow = $DB->get_record_sql($conflictsql, $courseparams, IGNORE_MISSING);
            $datehint = $conflictrow ? userdate((int)$conflictrow->starttime, get_string('strftimedatetimeshort')) : '';
            throw new \moodle_exception('error_course_enrolment_conflict_with_date', 'local_tm_course', '', $datehint);
        }

        // 5.6 Time-slot mutual exclusion with already approved sessions:
        // a user cannot enrol if the target session overlaps any of their approved sessions.
        $timeconflict = self::find_approved_time_conflict(
            $userid,
            (int)$session->starttime,
            (int)$session->endtime,
            (int)$sessionid
        );
        if ($timeconflict) {
            $hint = format_string((string)$timeconflict->name) . ' (' .
                userdate((int)$timeconflict->starttime, get_string('strftimedatetimeshort')) . ' - ' .
                userdate((int)$timeconflict->endtime, get_string('strftimedatetimeshort')) . ')';
            throw new \moodle_exception('error_enrolment_time_conflict_with_approved', 'local_tm_course', '', $hint);
        }

        // 6. Write record
        if ($existing) {
            // Re-activate a cancelled/rejected enrolment by updating the same row.
            $existing->status = $status;
            $existing->institution = $userobj->institution;
            if (empty($existing->batch_submittedby)) {
                // Keep source attribution from now on for self-service enrolments.
                $existing->batch_submittedby = (int) $userid;
            }
            $existing->diet_choice = $diet_choice;
            $existing->diet_avoid_beef = $diet_avoid_beef;
            $existing->diet_avoid_seafood = $diet_avoid_seafood;
            $existing->diet_meat_other = $diet_meat_other;
            $existing->diet_vegetarian_notes = $diet_vegetarian_notes;
            $existing->timemodified = time();

            $DB->update_record('local_tm_course_enrolments', $existing);
            $id = (int)$existing->id;
        } else {
            $record = new \stdClass();
            $record->sessionid    = $sessionid;
            $record->userid       = $userid;
            $record->status       = $status;
            $record->institution  = $userobj->institution;
            $record->batch_submittedby = (int) $userid;
            $record->timecreated  = time();
            $record->timemodified = time();

            // Diet survey (optional)
            $record->diet_choice = $diet_choice;
            $record->diet_avoid_beef = $diet_avoid_beef;
            $record->diet_avoid_seafood = $diet_avoid_seafood;
            $record->diet_meat_other = $diet_meat_other;
            $record->diet_vegetarian_notes = $diet_vegetarian_notes;

            try {
                $id = $DB->insert_record('local_tm_course_enrolments', $record);
            } catch (\dml_write_exception $e) {
                // Handle race condition: record created between our SELECT and INSERT.
                $existing2 = $DB->get_record('local_tm_course_enrolments', [
                    'sessionid' => $sessionid,
                    'userid' => $userid,
                ], '*', IGNORE_MISSING);
                if ($existing2) {
                    $existing2->status = $status;
                    $existing2->institution = $userobj->institution;
                    if (empty($existing2->batch_submittedby)) {
                        $existing2->batch_submittedby = (int) $userid;
                    }
                    $existing2->diet_choice = $diet_choice;
                    $existing2->diet_avoid_beef = $diet_avoid_beef;
                    $existing2->diet_avoid_seafood = $diet_avoid_seafood;
                    $existing2->diet_meat_other = $diet_meat_other;
                    $existing2->diet_vegetarian_notes = $diet_vegetarian_notes;
                    $existing2->timemodified = time();
                    $DB->update_record('local_tm_course_enrolments', $existing2);
                    $id = (int)$existing2->id;
                } else {
                    throw new \moodle_exception('error_enrol_diet_duplicate', 'local_tm_course');
                }
            }
        }

        // 7. Recalculate session capacity
        session_manager::recalculate_status($sessionid);

        // 8. If auto-approved: enrol in Moodle course + ensure/add to session group (M3)
        if ($status === session_manager::ENROL_APPROVED) {
            self::sync_moodle_enrolment($userid, (int)$session->courseid, 'enrol');
            attendance_manager::setup_session((int)$sessionid);
            attendance_manager::add_to_group($sessionid, $userid);
            self::audit_enrolment_sync_health((int)$id, false);
        }

        if ($status === session_manager::ENROL_PENDING) {
            notification_helper::notify_new_enrolment_submitted((int)$id);
        } else if ($status === session_manager::ENROL_APPROVED) {
            // Auto-approved flow: notify success immediately (includes meeting link for online sessions).
            notification_helper::notify_approval_result((int)$id, true, '');
        }
        return $id;
    }

    /**
     * Approve a pending enrolment.
     */
    public static function approve(int $enrolid, ?int $deskno = null): void {
        global $DB;
        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST);

        $session = session_manager::get_session($enrol->sessionid);
        session_verification_manager::assert_enrol_verification_allows_approval($enrol, $session);
        $isonline = ((string)($session->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE);
        if ($isonline) {
            $DB->set_field('local_tm_course_enrolments', 'desk_number', null, ['id' => $enrolid]);
        } else {
            // Desk assignment required when approving (dropdown 1..num_desks).
            $deskno = (int) ($deskno ?? 0);
            if ($deskno < 1 || $deskno > (int) $session->num_desks) {
                throw new \moodle_exception('error_desk_required_for_approval', 'local_tm_course');
            }

            $DB->set_field('local_tm_course_enrolments', 'desk_number', $deskno, ['id' => $enrolid]);
        }

        $syncuserid = self::moodle_sync_subject_userid($enrol);
        $learneruser = $DB->get_record('user', ['id' => $syncuserid, 'deleted' => 0], 'id,email', IGNORE_MISSING);
        $skiplms = $learneruser && self::is_placeholder_holder_email((string)$learneruser->email);

        if (!$skiplms) {
            $timeconflict = self::find_approved_time_conflict(
                $syncuserid,
                (int)$session->starttime,
                (int)$session->endtime,
                (int)$enrol->sessionid
            );
            if ($timeconflict) {
                $hint = format_string((string)$timeconflict->name) . ' (' .
                    userdate((int)$timeconflict->starttime, get_string('strftimedatetimeshort')) . ' - ' .
                    userdate((int)$timeconflict->endtime, get_string('strftimedatetimeshort')) . ')';
                throw new \moodle_exception('error_enrolment_time_conflict_with_approved', 'local_tm_course', '', $hint);
            }
        }

        $DB->set_field('local_tm_course_enrolments', 'status', session_manager::ENROL_APPROVED, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'timemodified', time(), ['id' => $enrolid]);

        session_manager::recalculate_status($enrol->sessionid);

        if (!$skiplms) {
            self::sync_moodle_enrolment($syncuserid, $session->courseid, 'enrol');
            attendance_manager::setup_session((int)$enrol->sessionid);
            attendance_manager::add_to_group((int)$enrol->sessionid, $syncuserid);
            self::audit_enrolment_sync_health((int)$enrolid, false);
            if ((int)($enrol->placeholder_seq ?? 0) > 0) {
                $lemail = \core_text::strtolower(trim((string)($learneruser->email ?? '')));
                $DB->set_field('local_tm_course_enrolments', 'linked_userid', $syncuserid, ['id' => $enrolid]);
                $DB->set_field('local_tm_course_enrolments', 'linked_email', $lemail !== '' ? $lemail : null, ['id' => $enrolid]);
            }
        } else {
            self::clear_sync_health_state((int)$enrolid);
        }

        notification_helper::notify_approval_result((int)$enrolid, true, '');
    }

    /**
     * Reject an enrolment.
     */
    public static function reject(int $enrolid, string $reason = ''): void {
        global $DB;
        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST);
        $wasapproved = ((int)$enrol->status === session_manager::ENROL_APPROVED);
        $syncuserid = self::moodle_sync_subject_userid($enrol);
        $DB->set_field('local_tm_course_enrolments', 'status',
                       session_manager::ENROL_REJECTED, ['id' => $enrolid]);
        if ($reason) {
            $DB->set_field('local_tm_course_enrolments', 'notes', $reason, ['id' => $enrolid]);
        }
        $DB->set_field('local_tm_course_enrolments', 'timemodified', time(), ['id' => $enrolid]);
        session_manager::recalculate_status($enrol->sessionid);
        // If this enrolment was previously approved, keep Moodle group sync consistent.
        if ($wasapproved) {
            attendance_manager::remove_from_group((int)$enrol->sessionid, $syncuserid);
        }
        self::clear_sync_health_state((int)$enrolid);
        notification_helper::notify_approval_result((int)$enrolid, false, (string)$reason);
        self::cascade_cancel_after_prereq_lost((int)$enrolid);
    }

    /**
     * Revert an approved enrolment back to pending review.
     */
    public static function unapprove(int $enrolid): void {
        global $DB;
        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST);
        if ((int) $enrol->status !== session_manager::ENROL_APPROVED) {
            return;
        }
        $session = session_manager::get_session((int) $enrol->sessionid);
        $syncuserid = self::moodle_sync_subject_userid($enrol);
        $DB->set_field('local_tm_course_enrolments', 'status', session_manager::ENROL_PENDING, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'desk_number', null, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'timemodified', time(), ['id' => $enrolid]);
        session_manager::recalculate_status((int) $enrol->sessionid);
        self::sync_moodle_enrolment($syncuserid, (int) $session->courseid, 'unenrol');
        attendance_manager::remove_from_group((int)$enrol->sessionid, $syncuserid);
        self::clear_sync_health_state((int)$enrolid);
        self::cascade_cancel_after_prereq_lost((int)$enrolid);
    }

    /**
     * Cancel own enrolment (student action) with mandatory reason.
     */
    public static function cancel(int $enrolid, int $userid, string $reasoncode, string $reasontext = ''): void {
        global $DB;
        $enrol = $DB->get_record('local_tm_course_enrolments',
            ['id' => $enrolid, 'userid' => $userid], '*', MUST_EXIST);
        $reasoncode = clean_param($reasoncode, PARAM_ALPHANUMEXT);
        $allowed = ['work', 'other_session', 'other'];
        if (!in_array($reasoncode, $allowed, true)) {
            throw new \moodle_exception('error_cancel_reason_required', 'local_tm_course');
        }
        $reasontext = clean_param(trim($reasontext), PARAM_TEXT);
        if ($reasoncode === 'other' && $reasontext === '') {
            throw new \moodle_exception('error_cancel_reason_other_required', 'local_tm_course');
        }
        $DB->set_field('local_tm_course_enrolments', 'status',
                       session_manager::ENROL_CANCELLED, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'cancel_reason_code', $reasoncode, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'cancel_reason_text', $reasontext, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'timemodified', time(), ['id' => $enrolid]);
        session_manager::recalculate_status($enrol->sessionid);
        try {
            attendance_manager::remove_from_group((int)$enrol->sessionid, (int)$enrol->userid);
            self::clear_sync_health_state((int)$enrolid);
            notification_helper::notify_enrolment_cancelled((int)$enrolid);
        } finally {
            self::cascade_cancel_after_prereq_lost((int)$enrolid);
        }
    }

    /**
     * Cancel an enrolment row submitted via batch enrolment by the same business user (batch_submittedby).
     * Releases LMS manual enrolment / attendance group when previously approved.
     *
     * @throws \moodle_exception When not owned by submitter or enrolment is already terminal.
     */
    public static function cancel_by_batch_submitter(int $enrolid, int $submitterid): void {
        global $DB;
        if ($submitterid <= 0) {
            throw new \moodle_exception('error_batch_cancel_not_owner', 'local_tm_course');
        }
        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST);
        if ((int) ($enrol->batch_submittedby ?? 0) !== $submitterid) {
            throw new \moodle_exception('error_batch_cancel_not_owner', 'local_tm_course');
        }
        $status = (int) $enrol->status;
        $terminal = [
            session_manager::ENROL_CANCELLED,
            session_manager::ENROL_REJECTED,
        ];
        if (in_array($status, $terminal, true)) {
            throw new \moodle_exception('error_batch_cancel_terminal', 'local_tm_course');
        }
        $wasapproved = ($status === session_manager::ENROL_APPROVED);
        $session = session_manager::get_session((int) $enrol->sessionid);
        $syncuserid = self::moodle_sync_subject_userid($enrol);

        $DB->set_field('local_tm_course_enrolments', 'status',
            session_manager::ENROL_CANCELLED, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'cancel_reason_code', 'batch_submitter', ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'cancel_reason_text', '', ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'desk_number', null, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'timemodified', time(), ['id' => $enrolid]);
        session_manager::recalculate_status((int) $enrol->sessionid);

        try {
            if ($wasapproved) {
                try {
                    self::sync_moodle_enrolment($syncuserid, (int) $session->courseid, 'unenrol');
                } catch (\Throwable $e) {
                    debugging('TM Course unenrol on batch cancel failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
                try {
                    attendance_manager::remove_from_group((int) $enrol->sessionid, $syncuserid);
                } catch (\Throwable $e) {
                    debugging('TM Course remove_from_group on batch cancel failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            self::clear_sync_health_state((int) $enrolid);
            try {
                notification_helper::notify_enrolment_cancelled((int) $enrolid);
            } catch (\Throwable $e) {
                debugging('TM Course cancel notify failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        } finally {
            // Always cascade even if LMS sync / email fails.
            self::cascade_cancel_after_prereq_lost((int) $enrolid);
        }
    }

    /**
     * Update diet preference for an existing enrolment owned by the user.
     */
    public static function update_diet(int $enrolid, int $userid, array $diet): void {
        global $DB;
        $enrol = $DB->get_record('local_tm_course_enrolments',
            ['id' => $enrolid, 'userid' => $userid], '*', MUST_EXIST);

        $choice = strtoupper(trim((string)($diet['choice'] ?? '')));
        if (!in_array($choice, ['A', 'B'], true)) {
            throw new \moodle_exception('error_diet_choice_required', 'local_tm_course');
        }

        $dietchoice = $choice;
        $dietbeef = 0;
        $dietsea = 0;
        $specialnote = clean_param((string)($diet['special_note'] ?? ''), PARAM_TEXT);
        if ($specialnote === '') {
            $specialnote = clean_param((string)($diet['meat_other'] ?? ($diet['vegetarian_notes'] ?? '')), PARAM_TEXT);
        }
        $dietmeat = $specialnote;
        $dietveg = '';

        $enrol->diet_choice = $dietchoice;
        $enrol->diet_avoid_beef = $dietbeef;
        $enrol->diet_avoid_seafood = $dietsea;
        $enrol->diet_meat_other = $dietmeat;
        $enrol->diet_vegetarian_notes = $dietveg;
        $enrol->timemodified = time();
        $DB->update_record('local_tm_course_enrolments', $enrol);
    }

    /**
     * Update diet preference from the attendance page (staff with attendance permission).
     *
     * @return array{choice:string,special_note:string,label:string}
     */
    public static function update_diet_for_attendance(int $sessionid, int $enrolid, array $diet): array {
        global $DB;

        $enrol = $DB->get_record('local_tm_course_enrolments', [
            'id' => $enrolid,
            'sessionid' => $sessionid,
            'status' => session_manager::ENROL_APPROVED,
        ], '*', MUST_EXIST);

        $choice = strtoupper(trim((string) ($diet['choice'] ?? '')));
        if (!in_array($choice, ['A', 'B'], true)) {
            throw new \moodle_exception('error_diet_choice_required', 'local_tm_course');
        }

        $specialnote = clean_param((string) ($diet['special_note'] ?? ''), PARAM_TEXT);
        if ($specialnote === '') {
            $specialnote = clean_param((string) ($diet['meat_other'] ?? ($diet['vegetarian_notes'] ?? '')), PARAM_TEXT);
        }

        $enrol->diet_choice = $choice;
        $enrol->diet_avoid_beef = 0;
        $enrol->diet_avoid_seafood = 0;
        $enrol->diet_meat_other = $specialnote;
        $enrol->diet_vegetarian_notes = '';
        $enrol->timemodified = time();
        $DB->update_record('local_tm_course_enrolments', $enrol);

        return [
            'choice' => $choice,
            'special_note' => $specialnote,
            'label' => self::format_diet_summary($enrol),
        ];
    }

    /**
     * Check if user has completed a Moodle course (legacy helper).
     */
    public static function has_completed_course(int $userid, int $courseid): bool {
        $rules = prerequisite_manager::normalize_rules([
            'operator' => prerequisite_manager::OPERATOR_AND,
            'rules' => [
                ['courseid' => $courseid, 'verify_type' => prerequisite_manager::VERIFY_COURSE],
            ],
        ]);
        return prerequisite_manager::user_meets_rules($userid, $rules);
    }

    /**
     * @throws \moodle_exception when session prerequisites are not met
     */
    public static function assert_user_meets_prerequisites(\stdClass $session, int $userid): void {
        prerequisite_manager::assert_learner_prerequisites($session, $userid);
    }

    /**
     * @return array{met:bool,missing:array<int,array{label:string,courseid:int}>}
     */
    public static function evaluate_session_prerequisites(\stdClass $session, int $userid): array {
        $ctx = prerequisite_manager::context_for_session($session);
        return prerequisite_manager::evaluate_user(
            $userid,
            prerequisite_manager::resolve_session_rules($session),
            $ctx
        );
    }

    /**
     * Guard against recursive cascade when cancelling a chain of dependent enrolments.
     * @var array<int,bool>
     */
    private static $prereq_cascade_guard = [];

    /**
     * After an enrolment is cancelled or rejected, cancel dependents that relied on it
     * as an approved course-complete prerequisite and no longer meet the rules.
     */
    public static function cascade_cancel_after_prereq_lost(int $sourceenrolid): void {
        if ($sourceenrolid <= 0 || !empty(self::$prereq_cascade_guard[$sourceenrolid])) {
            return;
        }
        self::$prereq_cascade_guard[$sourceenrolid] = true;
        try {
            global $DB;
            $source = $DB->get_record('local_tm_course_enrolments', ['id' => $sourceenrolid], '*', IGNORE_MISSING);
            if (!$source) {
                return;
            }
            $userid = (int)$source->userid;
            if ($userid < 2) {
                return;
            }
            try {
                $sourcesession = session_manager::get_session((int)$source->sessionid);
            } catch (\Throwable $e) {
                return;
            }
            $prereqcourseid = (int)($sourcesession->courseid ?? 0);
            if ($prereqcourseid <= 0) {
                return;
            }

            $activestatuses = [
                session_manager::ENROL_PENDING,
                session_manager::ENROL_APPROVED,
                session_manager::ENROL_WAITLISTED,
            ];
            list($statusinsql, $statusparams) = $DB->get_in_or_equal($activestatuses, SQL_PARAMS_NAMED);
            $sql = "SELECT e.*
                      FROM {local_tm_course_enrolments} e
                     WHERE e.userid = :uid
                       AND e.id <> :sid
                       AND e.status $statusinsql";
            $params = ['uid' => $userid, 'sid' => $sourceenrolid] + $statusparams;
            $deps = $DB->get_records_sql($sql, $params);
            if (empty($deps)) {
                return;
            }

            $prereqname = format_string((string)($sourcesession->name ?? ''));
            foreach ($deps as $dep) {
                $depid = (int)$dep->id;
                try {
                    $depsession = session_manager::get_session((int)$dep->sessionid);
                } catch (\Throwable $e) {
                    continue;
                }
                $rules = prerequisite_manager::resolve_session_rules($depsession);
                if (!prerequisite_manager::rules_reference_course_complete($rules, $prereqcourseid)) {
                    continue;
                }

                // Build a clean context: no co-bundle waiver; exclude the lost enrolment.
                $ctx = [
                    'target_starttime' => (int)($depsession->starttime ?? 0),
                    'coselected_courseids' => [],
                    'exclude_enrolment_ids' => [$sourceenrolid],
                ];

                // If the learner still satisfies the course-complete path for the lost
                // prereq course (Moodle completion or another approved earlier session),
                // keep the dependent enrolment.
                $coursepathok = false;
                try {
                    global $CFG;
                    require_once($CFG->dirroot . '/lib/datalib.php');
                    $prereqcourse = get_course($prereqcourseid, false);
                    if ($prereqcourse) {
                        $coursepathok = prerequisite_manager::user_meets_course_complete_rule(
                            $userid,
                            $prereqcourseid,
                            $prereqcourse,
                            $ctx
                        );
                    }
                } catch (\Throwable $e) {
                    $coursepathok = false;
                }
                if ($coursepathok) {
                    continue;
                }

                // Course-complete path for this prereq is broken. Keep the dependent only if
                // the full rule set is still met without relying on the lost enrolment
                // (e.g. OR grades path already satisfied).
                try {
                    $eval = prerequisite_manager::evaluate_user($userid, $rules, $ctx);
                    if (!empty($eval['met'])) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    // Evaluation failed — still cascade-cancel to avoid orphan dependent seats.
                    debugging('TM Course prereq cascade evaluate failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }

                try {
                    self::cancel_due_to_prereq_cascade($depid, $sourceenrolid, $prereqname);
                } catch (\Throwable $e) {
                    debugging('TM Course prereq cascade cancel failed for enrol #' . $depid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        } finally {
            unset(self::$prereq_cascade_guard[$sourceenrolid]);
        }
    }

    /**
     * Cancel a dependent enrolment because its prerequisite enrolment was lost.
     */
    private static function cancel_due_to_prereq_cascade(
        int $enrolid,
        int $sourceenrolid,
        string $prereqsessionname
    ): void {
        global $DB;
        if (!empty(self::$prereq_cascade_guard[$enrolid])) {
            return;
        }
        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', IGNORE_MISSING);
        if (!$enrol) {
            return;
        }
        $status = (int)$enrol->status;
        if (in_array($status, [session_manager::ENROL_CANCELLED, session_manager::ENROL_REJECTED], true)) {
            return;
        }
        $wasapproved = ($status === session_manager::ENROL_APPROVED);
        try {
            $session = session_manager::get_session((int)$enrol->sessionid);
        } catch (\Throwable $e) {
            return;
        }
        $syncuserid = self::moodle_sync_subject_userid($enrol);

        $DB->set_field('local_tm_course_enrolments', 'status',
            session_manager::ENROL_CANCELLED, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'cancel_reason_code', 'prereq_cascade', ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'cancel_reason_text', $prereqsessionname, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'desk_number', null, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'timemodified', time(), ['id' => $enrolid]);
        session_manager::recalculate_status((int)$enrol->sessionid);

        if ($wasapproved) {
            try {
                self::sync_moodle_enrolment($syncuserid, (int)$session->courseid, 'unenrol');
            } catch (\Throwable $e) {
                // Notification/unenrol failure must not block cascade.
            }
            try {
                attendance_manager::remove_from_group((int)$enrol->sessionid, $syncuserid);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        self::clear_sync_health_state($enrolid);

        try {
            notification_helper::notify_prereq_cascade_cancelled($enrolid, $sourceenrolid, $prereqsessionname);
        } catch (\Throwable $e) {
            // ignore
        }

        // Further dependents of this cancelled row.
        self::cascade_cancel_after_prereq_lost($enrolid);
    }

    /**
     * Translate a batch skip reason code to a user-facing label.
     */
    public static function format_batch_skip_reason(string $errorcode): string {
        $errorcode = trim($errorcode);
        if ($errorcode === '') {
            return get_string('batch_skipped_reason_unknown', 'local_tm_course');
        }
        $sm = get_string_manager();
        if ($sm->string_exists($errorcode, 'local_tm_course')) {
            return get_string($errorcode, 'local_tm_course');
        }
        return get_string('batch_skipped_reason_unknown', 'local_tm_course');
    }

    /**
     * Build a human-readable summary of skipped batch rows (deduped by reason).
     *
     * @param array<int,array{userid?:int,reason?:string}> $skipped
     */
    public static function format_batch_skipped_reasons_text(array $skipped): string {
        if (empty($skipped)) {
            return '';
        }
        $counts = [];
        foreach ($skipped as $row) {
            $label = self::format_batch_skip_reason((string)($row['reason'] ?? ''));
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $label => $count) {
            $parts[] = $count > 1 ? ($label . ' (×' . $count . ')') : $label;
        }
        return get_string('batch_skipped_reasons', 'local_tm_course', implode('；', $parts));
    }

    /**
     * Audit whether an approved enrolment has completed required Moodle sync steps.
     * Steps checked: account active, enrolled in course, added to group, attendance slot ready.
     *
     * @param int  $enrolid
     * @param bool $fromcron whether this check is from scheduled task
     */
    public static function audit_enrolment_sync_health(int $enrolid, bool $fromcron = false): void {
        global $DB;

        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', IGNORE_MISSING);
        if (!$enrol) {
            return;
        }

        if ((int) $enrol->status !== session_manager::ENROL_APPROVED) {
            self::clear_sync_health_state($enrolid);
            return;
        }

        // Approved seat-holder rows stay outside Moodle course/group until a learner email is linked.
        if (self::is_placeholder_holder_userid((int)$enrol->userid) && (int)($enrol->linked_userid ?? 0) <= 0) {
            self::clear_sync_health_state($enrolid);
            return;
        }

        $syncuserid = self::moodle_sync_subject_userid($enrol);

        $steps = [];
        $errors = [];
        $health = 1;

        try {
            $session = $DB->get_record('local_tm_course_sessions', ['id' => $enrol->sessionid], '*', MUST_EXIST);
            $user = $DB->get_record('user', ['id' => $syncuserid], 'id,deleted', IGNORE_MISSING);

            $userok = !empty($user) && empty($user->deleted);
            $steps[] = ['key' => 'account', 'ok' => $userok];
            if (!$userok) {
                $errors[] = get_string('sync_error_account_missing', 'local_tm_course');
            }

            $courseenrolled = false;
            if (!empty($session->courseid) && $userok) {
                $coursecontext = \context_course::instance((int) $session->courseid);
                $courseenrolled = is_enrolled($coursecontext, $syncuserid, '', true);
            }
            $steps[] = ['key' => 'course', 'ok' => $courseenrolled];
            if (!$courseenrolled) {
                $errors[] = get_string('sync_error_course_enrolment_missing', 'local_tm_course');
            }

            $groupok = false;
            if (!empty($session->groupid) && $courseenrolled) {
                global $CFG;
                require_once($CFG->dirroot . '/group/lib.php');
                $groupok = groups_is_member((int) $session->groupid, $syncuserid);
            }
            $steps[] = ['key' => 'group', 'ok' => $groupok];
            if (!$groupok) {
                $errors[] = get_string('sync_error_group_missing', 'local_tm_course');
            }

            $attendanceok = true;
            if (attendance_manager::is_mod_attendance_installed()) {
                $attendanceok = !empty($session->attendance_cmid) && !empty($session->attendance_sessionid);
            }
            $steps[] = ['key' => 'attendance', 'ok' => $attendanceok];
            if (!$attendanceok) {
                $errors[] = get_string('sync_error_attendance_missing', 'local_tm_course');
            }

            if (!empty($errors)) {
                $health = 3;
            }
            $summary = empty($errors) ? get_string('sync_ok', 'local_tm_course') : implode(' ', $errors);
            self::save_sync_health_state($enrolid, $health, $steps, $summary, $fromcron);
        } catch (\Throwable $e) {
            $steps[] = ['key' => 'system', 'ok' => false];
            $summary = get_string('sync_error_internal', 'local_tm_course');
            self::save_sync_health_state($enrolid, 3, $steps, $summary, $fromcron);
            debugging('TM Course sync audit failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Build UI metadata for sync health pill in admin enrolment list.
     *
     * @param \stdClass $enrol
     * @return array [class, label, icon, tooltip]
     */
    public static function get_sync_health_meta(\stdClass $enrol): array {
        $health = isset($enrol->sync_health) ? (int) $enrol->sync_health : 0;
        $summary = trim((string) ($enrol->sync_error ?? ''));
        $lastcheck = !empty($enrol->sync_lastcheck)
            ? userdate((int) $enrol->sync_lastcheck, get_string('strftimedatetimeshort'))
            : get_string('sync_not_checked', 'local_tm_course');

        if ($health === 1) {
            $label = get_string('sync_ok', 'local_tm_course');
            $icon = '✓';
            $class = 'ok';
        } else if ($health === 3) {
            $label = get_string('sync_issue', 'local_tm_course');
            $icon = '!';
            $class = 'error';
        } else {
            $label = get_string('sync_not_checked', 'local_tm_course');
            $icon = '•';
            $class = 'unknown';
        }

        $tooltip = $label . ' | ' . get_string('sync_last_checked', 'local_tm_course') . ': ' . $lastcheck;
        if ($summary !== '') {
            $tooltip .= ' | ' . $summary;
        }

        return [
            'class' => $class,
            'label' => $label,
            'icon' => $icon,
            'tooltip' => $tooltip,
        ];
    }

    /**
     * Persist sync state details on enrolment row.
     */
    private static function save_sync_health_state(
        int $enrolid,
        int $health,
        array $steps,
        string $summary,
        bool $fromcron
    ): void {
        global $DB;
        $update = new \stdClass();
        $update->id = $enrolid;
        $update->sync_health = $health;
        $update->sync_lastcheck = time();
        $update->sync_statusjson = json_encode([
            'fromcron' => $fromcron ? 1 : 0,
            'steps' => $steps,
        ], JSON_UNESCAPED_UNICODE);
        $update->sync_error = $summary;
        $DB->update_record('local_tm_course_enrolments', $update);
    }

    /**
     * Moodle user id used for course enrol / session group sync for this enrolment row.
     * Placeholder seats keep the holder {@see userid}; real learner linkage uses {@see linked_userid}.
     */
    private static function moodle_sync_subject_userid(\stdClass $enrol): int {
        $lid = (int)($enrol->linked_userid ?? 0);
        if ($lid > 0) {
            return $lid;
        }
        return (int)$enrol->userid;
    }

    /**
     * Clear sync state when enrolment is no longer approved.
     */
    private static function clear_sync_health_state(int $enrolid): void {
        global $DB;
        $update = new \stdClass();
        $update->id = $enrolid;
        $update->sync_health = 0;
        $update->sync_lastcheck = null;
        $update->sync_statusjson = null;
        $update->sync_error = null;
        $DB->update_record('local_tm_course_enrolments', $update);
    }

    /**
     * Role id to pass to manual enrol — same source as the course enrol UI default.
     * Uses this course's manual enrol instance {@see enrol}.roleid, then site enrol_manual default.
     * Does not inspect the user's profile "type" or any fixed shortname such as student.
     *
     * @param \stdClass $instance enabled manual enrol row from {enrol}
     * @return int valid role id
     * @throws \moodle_exception if no role can be determined
     */
    private static function resolve_manual_enrol_role_id(\stdClass $instance): int {
        global $DB;

        $rid = (int) ($instance->roleid ?? 0);
        if ($rid > 0 && $DB->record_exists('role', ['id' => $rid])) {
            return $rid;
        }

        $fallback = (int) get_config('enrol_manual', 'roleid');
        if ($fallback > 0 && $DB->record_exists('role', ['id' => $fallback])) {
            return $fallback;
        }

        throw new \moodle_exception('error_moodle_manual_role_not_configured', 'local_tm_course');
    }

    /**
     * Enrol or unenrol user in Moodle course via manual enrolment.
     *
     * @throws \moodle_exception When manual enrol is disabled, no role can be resolved,
     *                          no enrol instance can be created, or post-enrol verification fails.
     */
    private static function sync_moodle_enrolment(int $userid, int $courseid, string $action): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/enrol/manual/lib.php');

        if (!enrol_is_enabled('manual')) {
            throw new \moodle_exception('error_moodle_manual_enrol_disabled', 'local_tm_course');
        }

        $enrol_plugin = enrol_get_plugin('manual');
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return;
        }

        if ($action === 'unenrol') {
            $manualwithue = $DB->get_record_sql(
                "SELECT e.*
                   FROM {enrol} e
                   JOIN {user_enrolments} ue ON ue.enrolid = e.id
                  WHERE e.courseid = :courseid AND e.enrol = :manual AND ue.userid = :userid",
                ['courseid' => $courseid, 'manual' => 'manual', 'userid' => $userid],
                IGNORE_MISSING
            );
            if ($manualwithue) {
                $enrol_plugin->unenrol_user($manualwithue, $userid);
            }
            return;
        }

        if ($action !== 'enrol') {
            return;
        }

        $instances = $DB->get_records('enrol', [
            'courseid' => $courseid,
            'enrol' => 'manual',
            'status' => ENROL_INSTANCE_ENABLED,
        ], 'sortorder ASC, id ASC');
        $instance = reset($instances);
        if (!$instance) {
            $instanceid = $enrol_plugin->add_default_instance($course);
            if (!$instanceid) {
                throw new \moodle_exception('error_moodle_manual_instance_missing', 'local_tm_course');
            }
            $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
        }

        $roleid = self::resolve_manual_enrol_role_id($instance);

        $timenow = time();
        $enrol_plugin->enrol_user($instance, $userid, $roleid, $timenow, 0, ENROL_USER_ACTIVE);

        $context = \context_course::instance($courseid);
        if (!is_enrolled($context, $userid, '', true)) {
            throw new \moodle_exception('error_moodle_enrol_verify_failed', 'local_tm_course');
        }
    }

    /**
     * Universal search: find enrolments by name / email / institution.
     * Admins see all; others see only their own.
     */
    public static function search(string $query, bool $admin_view = false, int $current_userid = 0): array {
        global $DB;

        $query = trim($query);
        if ($query === '') return [];

        $like = $DB->sql_like_escape($query);

        $sql = "SELECT e.*, u.firstname, u.lastname, u.email, u.institution AS user_institution,
                       sb.firstname AS submitter_firstname, sb.lastname AS submitter_lastname,
                       s.name AS session_name, s.starttime, s.courseid,
                       s.delivery_mode AS session_delivery_mode
                  FROM {local_tm_course_enrolments} e
                  JOIN {user} u ON u.id = e.userid
             LEFT JOIN {user} sb ON sb.id = e.batch_submittedby
                  JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
                 WHERE (
                       " . $DB->sql_like('u.firstname', ':q1', false) . "
                    OR " . $DB->sql_like('u.lastname',  ':q2', false) . "
                    OR " . $DB->sql_like('u.email',     ':q3', false) . "
                    OR " . $DB->sql_like('u.institution',':q4', false) . "
                    OR " . $DB->sql_like("CONCAT(u.firstname,' ',u.lastname)", ':q5', false) . "
                 )";

        $params = [
            'q1' => "%$like%",
            'q2' => "%$like%",
            'q3' => "%$like%",
            'q4' => "%$like%",
            'q5' => "%$like%",
        ];

        if (!$admin_view && $current_userid) {
            $sql .= ' AND e.userid = :uid';
            $params['uid'] = $current_userid;
        }

        $sql .= ' ORDER BY s.starttime DESC';
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Search local Moodle user accounts (not TM enrolments). Used by staff to verify external users registered.
     * Each non-empty filter uses substring match (LIKE %value%); conditions are combined with AND.
     *
     * @param array $filters keys: firstname, lastname, institution, email (trimmed text)
     * @param int $limit max rows (capped at 100)
     * @return \stdClass[] user rows (includes all name fields required by fullname())
     */
    public static function search_moodle_users(array $filters, int $limit = 100): array {
        global $DB, $CFG;

        $limit = min(100, max(1, $limit));

        $firstname = clean_param(trim((string) ($filters['firstname'] ?? '')), PARAM_TEXT);
        $lastname = clean_param(trim((string) ($filters['lastname'] ?? '')), PARAM_TEXT);
        $institution = clean_param(trim((string) ($filters['institution'] ?? '')), PARAM_TEXT);
        $email = clean_param(trim((string) ($filters['email'] ?? '')), PARAM_TEXT);

        $hasany = ($firstname !== '' || $lastname !== '' || $institution !== '' || $email !== '');
        if (!$hasany) {
            return [];
        }

        $minchars = 2;
        foreach ([$firstname, $lastname, $institution, $email] as $val) {
            if ($val !== '' && \core_text::strlen($val) < $minchars) {
                throw new \moodle_exception('search_user_field_too_short', 'local_tm_course');
            }
        }

        $conditions = [
            'deleted = 0',
            'id > 1',
            'mnethostid = :mhid',
        ];
        $params = ['mhid' => $CFG->mnet_localhost_id];
        $idx = 0;

        foreach ([
            'firstname' => $firstname,
            'lastname' => $lastname,
            'institution' => $institution,
            'email' => $email,
        ] as $col => $val) {
            if ($val === '') {
                continue;
            }
            $idx++;
            $param = 'lk' . $idx;
            $params[$param] = '%' . $DB->sql_like_escape($val) . '%';
            $conditions[] = $DB->sql_like($col, ':' . $param, false);
        }

        $namefields = get_all_user_name_fields(true);
        $sql = "SELECT id, username, $namefields, email, institution, suspended, lastaccess, timecreated
                  FROM {user}
                 WHERE " . implode(' AND ', $conditions) . '
              ORDER BY lastname ASC, firstname ASC, id ASC';

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Search enrolment records by Moodle user profile fields.
     * Each non-empty filter uses substring match (LIKE %value%); conditions are combined with AND.
     *
     * @param array $filters keys: firstname, lastname, institution, email (trimmed text)
     * @param bool $admin_view allow viewing all records
     * @param int|null $current_userid current user when not admin
     * @param int $limit max rows (capped at 100)
     * @return \stdClass[]
     */
    public static function search_enrolments_by_user_filters(
        array $filters,
        bool $admin_view = false,
        ?int $current_userid = null,
        int $limit = 100
    ): array {
        global $DB;

        $limit = min(100, max(1, $limit));

        $firstname = clean_param(trim((string)($filters['firstname'] ?? '')), PARAM_TEXT);
        $lastname = clean_param(trim((string)($filters['lastname'] ?? '')), PARAM_TEXT);
        $institution = clean_param(trim((string)($filters['institution'] ?? '')), PARAM_TEXT);
        $email = clean_param(trim((string)($filters['email'] ?? '')), PARAM_TEXT);

        $hasany = ($firstname !== '' || $lastname !== '' || $institution !== '' || $email !== '');
        if (!$hasany) {
            return [];
        }

        $minchars = 2;
        foreach ([$firstname, $lastname, $institution, $email] as $val) {
            if ($val !== '' && \core_text::strlen($val) < $minchars) {
                throw new \moodle_exception('search_user_field_too_short', 'local_tm_course');
            }
        }

        $conditions = [];
        $params = [];
        $idx = 0;
        foreach ([
            'u.firstname' => $firstname,
            'u.lastname' => $lastname,
            'u.institution' => $institution,
            'u.email' => $email,
        ] as $col => $val) {
            if ($val === '') {
                continue;
            }
            $idx++;
            $param = 'lk' . $idx;
            $params[$param] = '%' . $DB->sql_like_escape($val) . '%';
            $conditions[] = $DB->sql_like($col, ':' . $param, false);
        }

        if (!$admin_view && $current_userid) {
            $conditions[] = 'e.userid = :uid';
            $params['uid'] = $current_userid;
        }

        $sql = "SELECT e.*, u.firstname, u.lastname, u.email, u.institution AS user_institution,
                       sb.firstname AS submitter_firstname, sb.lastname AS submitter_lastname,
                       s.name AS session_name, s.starttime, s.courseid,
                       s.delivery_mode AS session_delivery_mode
                  FROM {local_tm_course_enrolments} e
                  JOIN {user} u ON u.id = e.userid
             LEFT JOIN {user} sb ON sb.id = e.batch_submittedby
                  JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
                 WHERE " . implode(' AND ', $conditions) . "
              ORDER BY s.starttime DESC";

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Get all enrolment records of one user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_user_records(int $userid): array {
        global $DB;

        $sql = "SELECT e.*, u.firstname, u.lastname, u.email, u.institution AS user_institution,
                       sb.firstname AS submitter_firstname, sb.lastname AS submitter_lastname,
                       s.name AS session_name, s.starttime, s.courseid,
                       s.delivery_mode AS session_delivery_mode
                  FROM {local_tm_course_enrolments} e
                  JOIN {user} u ON u.id = e.userid
             LEFT JOIN {user} sb ON sb.id = e.batch_submittedby
                  JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
                 WHERE e.userid = :userid
              ORDER BY s.starttime DESC, e.timecreated DESC";
        return $DB->get_records_sql($sql, ['userid' => $userid]);
    }

    /**
     * Normalise optional batch submitter remark (shared across all rows in one batch_enrol_pending call).
     */
    public static function normalise_batch_submitter_note(string $raw): string {
        $t = clean_param(trim($raw), PARAM_TEXT);
        if (strlen($t) > 2000) {
            return substr($t, 0, 2000);
        }
        return $t;
    }

    /**
     * M4: Batch enrol learners as pending (always pending regardless of session approval_mode).
     *
     * @param int   $sessionid
     * @param int   $actorid   User submitting the batch (stored as batch_submittedby)
     * @param array $entries   List of [ 'userid' => int, 'institution' => string optional, 'diet' => array ]
     * @param bool  $allowclosed When true, full / closed / deadline are allowed (admin manage paths).
     * @param string $batchsubmitternote Optional remark stored on each created/updated enrolment row.
     * @param bool   $bypassprerequisite Site administrators may bypass prerequisite checks.
     * @return array{ processed:int, capped:bool, requested:int, skipped:array<int,array{userid:int,reason:string}>, enrolment_ids:int[], prereq_bypassed:array<int,array{userid:int,email:string,name:string,missing:array}> }
     */
    public static function batch_enrol_pending(
        int $sessionid,
        int $actorid,
        array $entries,
        bool $allowclosed = false,
        string $batchsubmitternote = '',
        bool $bypassprerequisite = false
    ): array {
        global $DB;

        $session = session_manager::get_session($sessionid);
        if (!session_manager::can_submit_enrolment($session, $allowclosed)) {
            if (!session_manager::is_online_session($session)
                && ((int) $session->status === session_manager::STATUS_FULL
                    || session_manager::is_onsite_persons_full($session))) {
                throw new \moodle_exception('error_session_full', 'local_tm_course');
            }
            if (session_manager::is_registration_deadline_passed($session)) {
                throw new \moodle_exception('error_session_registration_deadline', 'local_tm_course');
            }
            throw new \moodle_exception('error_session_full', 'local_tm_course');
        }

        $requested = count($entries);
        $capped = false;

        $bnote = self::normalise_batch_submitter_note($batchsubmitternote);

        $processed = 0;
        $skipped = [];
        $enrolmentids = [];
        $prereqbypassed = [];

        foreach ($entries as $row) {
            $userid = (int) ($row['userid'] ?? 0);
            if ($userid < 2) {
                $skipped[] = ['userid' => $userid, 'reason' => 'error_batch_user_invalid'];
                continue;
            }
            $row['batch_submitter_note'] = $bnote;
            try {
                $eid = self::enrol_one_pending_batch($sessionid, $session, $userid, $actorid, $row, false, $bypassprerequisite);
                $enrolmentids[] = $eid;
                $processed++;
                if ($bypassprerequisite && empty($row['is_placeholder_holder']) && (int)($row['placeholder_seq'] ?? 0) <= 0
                    && !self::is_placeholder_holder_userid($userid)) {
                    $eval = self::evaluate_session_prerequisites($session, $userid);
                    if (empty($eval['met'])) {
                        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
                        $missingdisplay = prerequisite_manager::format_missing_reason_list($eval['missing']);
                        $prereqbypassed[] = [
                            'userid' => $userid,
                            'email' => $user ? (string)$user->email : '',
                            'name' => $user ? fullname($user) : '',
                            'missing' => $missingdisplay !== '' ? [$missingdisplay] : [],
                            'missing_display' => $missingdisplay,
                        ];
                    }
                }
            } catch (\moodle_exception $ex) {
                $skipped[] = ['userid' => $userid, 'reason' => $ex->errorcode];
            }
        }

        session_manager::recalculate_status($sessionid);

        return [
            'processed' => $processed,
            'capped'    => $capped,
            'requested' => $requested,
            'skipped'   => $skipped,
            'enrolment_ids' => $enrolmentids,
            'prereq_bypassed' => $prereqbypassed,
        ];
    }

    /**
     * Count batch rows still missing a linked Moodle learner account (pending / approved / waitlisted).
     */
    public static function count_missing_batch_profile(int $sessionid, int $submitterid): int {
        global $DB;
        $like = $DB->sql_like('u.email', ':heldpattern', false);
        $statuses = [
            session_manager::ENROL_PENDING,
            session_manager::ENROL_APPROVED,
            session_manager::ENROL_WAITLISTED,
        ];
        list($insql, $inparams) = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED, 'st');
        $sql = "SELECT COUNT(1)
                  FROM {local_tm_course_enrolments} e
                  JOIN {user} u ON u.id = e.userid
                 WHERE e.sessionid = :sid
                   AND e.batch_submittedby = :uid
                   AND e.status $insql
                   AND (e.linked_userid IS NULL OR e.linked_userid = 0)
                   AND (
                        $like
                        OR (e.placeholder_seq IS NOT NULL AND e.placeholder_seq > 0)
                        OR (e.linked_email IS NOT NULL AND e.linked_email <> :emptyem)
                   )";
        $params = [
            'sid' => $sessionid,
            'uid' => $submitterid,
            'emptyem' => '',
        ] + $inparams;
        $params['heldpattern'] = '%' . $DB->sql_like_escape(self::PLACEHOLDER_EMAIL_MARKER);
        return (int)$DB->count_records_sql($sql, $params);
    }

    /**
     * Business follow-up: update placeholder email and sync Moodle linkage/group membership.
     *
     * @return array{linked:bool,found:bool,cleared:bool,created:bool,needprofile:bool}
     */
    public static function update_batch_placeholder_email(
        int $enrolid,
        int $submitterid,
        string $email,
        string $firstname = '',
        string $lastname = '',
        string $institution = ''
    ): array {
        global $DB, $CFG;

        $enrol = $DB->get_record('local_tm_course_enrolments', [
            'id' => $enrolid,
            'batch_submittedby' => $submitterid,
        ], '*', MUST_EXIST);
        $session = session_manager::get_session((int)$enrol->sessionid);

        $email = \core_text::strtolower(trim($email));
        $oldlinkeduserid = (int)($enrol->linked_userid ?? 0);

        $unlinkold = static function(int $userid) use ($session): void {
            if ($userid <= 0) {
                return;
            }
            self::sync_moodle_enrolment($userid, (int)$session->courseid, 'unenrol');
            attendance_manager::remove_from_group((int)$session->id, $userid);
        };

        // Allow clearing email; keep seat row but remove linked Moodle user.
        if ($email === '') {
            if ($oldlinkeduserid > 0) {
                $unlinkold($oldlinkeduserid);
            }
            $update = new \stdClass();
            $update->id = (int)$enrol->id;
            $update->linked_userid = null;
            $update->linked_email = null;
            $update->timemodified = time();
            $DB->update_record('local_tm_course_enrolments', $update);
            return ['linked' => false, 'found' => false, 'cleared' => true];
        }

        $activeStatuses = [
            session_manager::ENROL_PENDING,
            session_manager::ENROL_APPROVED,
            session_manager::ENROL_WAITLISTED,
        ];
        list($statusinsql, $statusparams) = $DB->get_in_or_equal($activeStatuses, SQL_PARAMS_NAMED);
        $dupeemailsql = "SELECT 1
                           FROM {local_tm_course_enrolments} e
                      LEFT JOIN {user} lu ON lu.id = e.linked_userid
                      LEFT JOIN {user} hu ON hu.id = e.userid
                          WHERE e.sessionid = :sid
                            AND e.id <> :eid
                            AND e.status $statusinsql
                            AND (
                                e.linked_email = :em1
                                OR lu.email = :em2
                                OR hu.email = :em3
                            )";
        $dupeemailparams = [
            'sid' => (int)$session->id,
            'eid' => (int)$enrol->id,
            'em1' => $email,
            'em2' => $email,
            'em3' => $email,
        ] + $statusparams;
        if ($DB->record_exists_sql($dupeemailsql, $dupeemailparams)) {
            throw new \moodle_exception('error_batch_email_already_used', 'local_tm_course');
        }

        $target = $DB->get_record('user', [
            'email' => $email,
            'deleted' => 0,
            'mnethostid' => $CFG->mnet_localhost_id,
        ], 'id', IGNORE_MISSING);
        $created = false;
        if ($target) {
            $newuserid = (int)$target->id;
        } else {
            $fallbackname = clean_param(trim((string)($enrol->placeholder_name ?? '')), PARAM_TEXT);
            $name = clean_param(trim($firstname), PARAM_TEXT);
            if ($name === '' && $fallbackname !== '') {
                $name = $fallbackname;
            }
            $lname = clean_param(trim($lastname), PARAM_TEXT);
            $inst = clean_param(trim($institution), PARAM_TEXT);
            if ($inst === '') {
                $inst = clean_param(trim((string)($enrol->seat_company ?? '')), PARAM_TEXT);
            }
            if ($name === '' || $lname === '' || $inst === '') {
                if ($oldlinkeduserid > 0) {
                    $unlinkold($oldlinkeduserid);
                }
                $update = new \stdClass();
                $update->id = (int)$enrol->id;
                $update->linked_userid = null;
                $update->linked_email = $email;
                $update->timemodified = time();
                $DB->update_record('local_tm_course_enrolments', $update);
                return ['linked' => false, 'found' => false, 'cleared' => false, 'created' => false, 'needprofile' => true];
            }
            $provisioned = self::provision_or_link_batch_user((int)$session->id, $email, $name, $lname, $inst, $submitterid);
            $newuserid = (int)$provisioned['userid'];
            $created = !empty($provisioned['created']);
        }

        $dupsql = "SELECT 1 FROM {local_tm_course_enrolments}
                    WHERE sessionid = :sid AND userid = :uid AND id <> :eid AND status $statusinsql";
        $dupparams = [
            'sid' => (int)$session->id,
            'uid' => $newuserid,
            'eid' => (int)$enrol->id,
        ] + $statusparams;
        if ($DB->record_exists_sql($dupsql, $dupparams)) {
            throw new \moodle_exception('error_batch_already_active', 'local_tm_course');
        }

        if ($oldlinkeduserid > 0 && $oldlinkeduserid !== $newuserid) {
            $unlinkold($oldlinkeduserid);
        }

        // Only approved rows should be synced into Moodle course/group. Re-run sync even when
        // linked user is unchanged so "save again" can heal prior partial sync failures.
        $shouldsync = ((int)$enrol->status === session_manager::ENROL_APPROVED);
        if ($shouldsync) {
            self::sync_moodle_enrolment($newuserid, (int)$session->courseid, 'enrol');
            attendance_manager::setup_session((int)$session->id);
            attendance_manager::add_to_group((int)$session->id, $newuserid);
        }

        $update = new \stdClass();
        $update->id = (int)$enrol->id;
        $update->linked_userid = $newuserid;
        $update->linked_email = $email;
        $update->timemodified = time();
        $DB->update_record('local_tm_course_enrolments', $update);

        if ($shouldsync) {
            $attended = (int)($enrol->attended ?? attendance_manager::ATTEND_UNSET);
            if (in_array($attended, [attendance_manager::ATTEND_PRESENT, attendance_manager::ATTEND_ABSENT], true)) {
                // Rebind previously marked attendance to the newly linked learner account.
                attendance_manager::mark_attended((int)$enrol->id, $attended);
            }
            self::audit_enrolment_sync_health((int)$enrol->id, false);
        }

        return ['linked' => true, 'found' => true, 'cleared' => false, 'created' => $created, 'needprofile' => false];
    }

    /**
     * Business follow-up: display name + diet on batch seat rows before a real learner is linked (any active status).
     *
     * @param array $raw optional_param keys: placeholder_name, placeholder_institution, diet_choice, avoid_beef, avoid_seafood, meat_other, vegetarian_notes
     */
    public static function update_batch_placeholder_details(int $enrolid, int $submitterid, array $raw): void {
        global $DB;

        $enrol = $DB->get_record('local_tm_course_enrolments', [
            'id' => $enrolid,
            'batch_submittedby' => $submitterid,
        ], '*', MUST_EXIST);

        $active = [
            session_manager::ENROL_PENDING,
            session_manager::ENROL_APPROVED,
            session_manager::ENROL_WAITLISTED,
        ];
        if (!in_array((int)$enrol->status, $active, true)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $name = isset($raw['placeholder_name']) ? clean_param(trim((string) $raw['placeholder_name']), PARAM_TEXT) : '';
        if (\core_text::strlen($name) > 255) {
            $name = \core_text::substr($name, 0, 255);
        }
        $institution = isset($raw['placeholder_institution']) ? clean_param(trim((string)$raw['placeholder_institution']), PARAM_TEXT) : '';
        if (\core_text::strlen($institution) > 255) {
            $institution = \core_text::substr($institution, 0, 255);
        }
        if ($institution === '') {
            $institution = clean_param(trim((string)($enrol->seat_company ?? '')), PARAM_TEXT);
        }

        $choice = strtoupper(trim((string) ($raw['diet_choice'] ?? '')));
        if (!in_array($choice, ['A', 'B'], true)) {
            throw new \moodle_exception('error_diet_choice_required', 'local_tm_course');
        }

        $beef = 0;
        $sea = 0;
        $specialnote = clean_param(trim((string)($raw['special_note'] ?? '')), PARAM_TEXT);
        if ($specialnote === '') {
            $specialnote = clean_param(trim((string)($raw['meat_other'] ?? ($raw['vegetarian_notes'] ?? ''))), PARAM_TEXT);
        }
        $meatother = $specialnote;
        $veg = '';

        $update = new \stdClass();
        $update->id = (int) $enrol->id;
        $update->placeholder_name = ($name === '') ? null : $name;
        $update->seat_company = $institution !== '' ? $institution : null;
        $update->institution = $institution !== '' ? $institution : null;
        $update->diet_choice = $choice;
        $update->diet_avoid_beef = $beef;
        $update->diet_avoid_seafood = $sea;
        $update->diet_meat_other = $meatother;
        $update->diet_vegetarian_notes = $veg;
        $update->timemodified = time();
        $DB->update_record('local_tm_course_enrolments', $update);
    }

    /**
     * Admin attendance roster labels for name / email / institution columns (handles seat placeholders).
     *
     * @param \stdClass $row Flat row from attendance_manager::get_session_attendance()
     * @return array{displayname:string,email:string,institution:string}
     */
    public static function format_attendance_roster_cells(\stdClass $row): array {
        $placeholderseq = (int) ($row->placeholder_seq ?? 0);
        if ($placeholderseq <= 0) {
            $displayname = trim((string)($row->firstname ?? '') . ' ' . (string)($row->lastname ?? ''));
            if ($displayname === '') {
                $displayname = '—';
            }
            return [
                'displayname' => $displayname,
                'email' => (string) ($row->email ?? ''),
                'institution' => (trim((string) ($row->institution ?? '')) !== '')
                    ? (string) $row->institution
                    : (string) ($row->profile_institution ?? ''),
            ];
        }

        $company = trim((string) ($row->seat_company ?? ''));
        $instout = $company !== ''
            ? $company
            : ((trim((string) ($row->institution ?? '')) !== '')
                ? (string) $row->institution
                : (string) ($row->profile_institution ?? ''));

        $lu_fn = trim((string) ($row->lu_firstname ?? ''));
        $lu_ln = trim((string) ($row->lu_lastname ?? ''));
        if (!empty($row->linked_userid) && ($lu_fn !== '' || $lu_ln !== '')) {
            $em = trim((string) ($row->linked_email ?? ''));
            if ($em === '') {
                $em = trim((string) ($row->lu_email ?? ''));
            }

            return [
                'displayname' => trim($lu_fn . ' ' . $lu_ln),
                'email' => $em !== '' ? $em : '—',
                'institution' => $instout !== '' ? $instout : '—',
            ];
        }

        $pname = trim((string) ($row->placeholder_name ?? ''));
        if ($pname !== '') {
            return [
                'displayname' => $pname,
                'email' => trim((string) ($row->linked_email ?? '')) !== ''
                    ? trim((string) $row->linked_email)
                    : '—',
                'institution' => $instout !== '' ? $instout : '—',
            ];
        }

        return [
            'displayname' => get_string('seat_holder_generic_name', 'local_tm_course', (object) ['seq' => $placeholderseq]),
            'email' => trim((string) ($row->linked_email ?? '')) !== ''
                ? trim((string) $row->linked_email)
                : '—',
            'institution' => $instout !== '' ? $instout : '—',
        ];
    }

    /**
     * Enrolment source label: self vs batch (business submitter).
     *
     * Self-enrol stores batch_submittedby = learner userid; batch stores the business actor id.
     */
    public static function format_enrol_source_label(\stdClass $row): string {
        $learnerid = (int) ($row->userid ?? 0);
        $submitterid = (int) ($row->batch_submittedby ?? 0);
        if ($submitterid <= 0 || $submitterid === $learnerid) {
            return get_string('enrol_source_self', 'local_tm_course');
        }
        $name = trim((string) ($row->submitter_firstname ?? '') . ' ' . (string) ($row->submitter_lastname ?? ''));
        if ($name === '') {
            return get_string('enrol_source_batch', 'local_tm_course', (object) ['name' => '—']);
        }
        return get_string('enrol_source_batch', 'local_tm_course', (object) ['name' => $name]);
    }

    /**
     * Approved enrolments for session roster view (desk layout / online list).
     *
     * @return \stdClass[]
     */
    public static function get_session_roster_approved_rows(int $sessionid): array {
        global $DB;

        $sql = "SELECT e.id, e.userid, e.status, e.desk_number, e.institution,
                       e.attended, e.diet_choice, e.diet_meat_other,
                       e.batch_submittedby, e.batch_submitter_note,
                       e.seat_company, e.placeholder_seq, e.linked_userid, e.linked_email, e.placeholder_name,
                       u.firstname, u.lastname, u.email, u.institution AS profile_institution,
                       lu.firstname AS lu_firstname, lu.lastname AS lu_lastname, lu.email AS lu_email,
                       sb.firstname AS submitter_firstname, sb.lastname AS submitter_lastname,
                       sb.institution AS submitter_institution, sb.phone1 AS submitter_phone
                  FROM {local_tm_course_enrolments} e
                  JOIN {user} u ON u.id = e.userid
             LEFT JOIN {user} lu ON lu.id = e.linked_userid AND e.linked_userid > 0
             LEFT JOIN {user} sb ON sb.id = e.batch_submittedby
                 WHERE e.sessionid = :sid
                   AND e.status = :st
              ORDER BY COALESCE(e.desk_number, 9999) ASC, u.lastname ASC, u.firstname ASC";

        return array_values($DB->get_records_sql($sql, [
            'sid' => $sessionid,
            'st'  => session_manager::ENROL_APPROVED,
        ]));
    }

    /**
     * Build read-only roster payload for session_roster.php.
     *
     * @return array{
     *   is_online:bool,
     *   session:\stdClass,
     *   total:int,
     *   desks?:array,
     *   unassigned?:array,
     *   persons_per_desk?:int,
     *   num_desks?:int,
     *   online_groups?:array
     * }
     */
    public static function build_session_roster_view(int $sessionid): array {
        $session = session_manager::get_session($sessionid);
        $isonline = ((string) ($session->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE);
        $rows = self::get_session_roster_approved_rows($sessionid);

        $learners = [];
        foreach ($rows as $row) {
            $cells = self::format_attendance_roster_cells($row);
            $inst = trim((string) ($cells['institution'] ?? ''));
            if ($inst === '—') {
                $inst = '';
            }
            $learners[] = [
                'enrolid' => (int) $row->id,
                'desk_number' => (int) ($row->desk_number ?? 0),
                'displayname' => (string) ($cells['displayname'] ?? '—'),
                'institution' => $inst,
                'source_label' => self::format_enrol_source_label($row),
            ];
        }

        $total = count($learners);
        if ($isonline) {
            $groups = [];
            $unknownlabel = get_string('session_roster_institution_unknown', 'local_tm_course');
            foreach ($learners as $learner) {
                $key = $learner['institution'] !== '' ? $learner['institution'] : $unknownlabel;
                if (empty($groups[$key])) {
                    $groups[$key] = [];
                }
                $groups[$key][] = $learner;
            }
            ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

            return [
                'is_online' => true,
                'session' => $session,
                'total' => $total,
                'online_groups' => $groups,
            ];
        }

        $numdesks = max(0, (int) ($session->num_desks ?? 0));
        $ppd = max(1, (int) ($session->persons_per_desk ?? 1));
        $desks = [];
        for ($d = 1; $d <= $numdesks; $d++) {
            $desks[$d] = [
                'desk_number' => $d,
                'capacity' => $ppd,
                'learners' => [],
            ];
        }
        $unassigned = [];
        foreach ($learners as $learner) {
            $dn = (int) $learner['desk_number'];
            if ($dn >= 1 && $dn <= $numdesks) {
                $desks[$dn]['learners'][] = $learner;
            } else {
                $unassigned[] = $learner;
            }
        }

        return [
            'is_online' => false,
            'session' => $session,
            'total' => $total,
            'num_desks' => $numdesks,
            'persons_per_desk' => $ppd,
            'desks' => array_values($desks),
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Attendance page payload: roster layout + attended/diet per learner + summary stats.
     *
     * @return array{
     *   is_online:bool,
     *   is_onsite:bool,
     *   session:\stdClass,
     *   total:int,
     *   desks?:array,
     *   unassigned?:array,
     *   persons_per_desk?:int,
     *   num_desks?:int,
     *   online_groups?:array,
     *   stats:array{
     *     enrolled:int,present:int,absent:int,unset:int,
     *     diet_meat:int,diet_vegetarian:int,diet_unknown:int
     *   }
     * }
     */
    public static function build_session_attendance_view(int $sessionid): array {
        $roster = self::build_session_roster_view($sessionid);
        $rowsbyid = [];
        foreach (self::get_session_roster_approved_rows($sessionid) as $row) {
            $rowsbyid[(int) $row->id] = $row;
        }

        $enrich = static function (array $learner) use ($rowsbyid): array {
            $row = $rowsbyid[$learner['enrolid']] ?? null;
            $attended = $row ? (int) ($row->attended ?? 0) : 0;
            $diet = $row ? (string) ($row->diet_choice ?? '') : '';
            $specialnote = $row ? (string) ($row->diet_meat_other ?? '') : '';
            $learner['attended'] = $attended;
            $learner['diet_choice'] = $diet;
            $learner['diet_special_note'] = $specialnote;
            $learner['diet_label'] = $row ? self::format_diet_summary($row) : '—';
            return $learner;
        };

        $alllearners = [];
        if (!empty($roster['is_online'])) {
            foreach ($roster['online_groups'] as $instlabel => $group) {
                $enriched = [];
                foreach ($group as $learner) {
                    $enriched[] = $enrich($learner);
                    $alllearners[] = end($enriched);
                }
                $roster['online_groups'][$instlabel] = $enriched;
            }
        } else {
            foreach ($roster['desks'] as $idx => $desk) {
                $enriched = [];
                foreach ($desk['learners'] as $learner) {
                    $enriched[] = $enrich($learner);
                    $alllearners[] = end($enriched);
                }
                $roster['desks'][$idx]['learners'] = $enriched;
            }
            $unassigned = [];
            foreach ($roster['unassigned'] as $learner) {
                $unassigned[] = $enrich($learner);
                $alllearners[] = end($unassigned);
            }
            $roster['unassigned'] = $unassigned;
        }

        $roster['is_onsite'] = empty($roster['is_online']);
        $roster['stats'] = self::compute_attendance_page_stats($alllearners);
        return $roster;
    }

    /**
     * @param array<int,array{attended:int,diet_choice:string}> $learners
     * @return array{
     *   enrolled:int,present:int,absent:int,unset:int,
     *   diet_meat:int,diet_vegetarian:int,diet_unknown:int,
     *   diet_enrolled_meat:int,diet_enrolled_vegetarian:int,diet_enrolled_unknown:int
     * }
     */
    public static function compute_attendance_page_stats(array $learners): array {
        $present = $absent = $unset = 0;
        $meat = $veg = $unknown = 0;
        $enrolledmeat = $enrolledveg = $enrolledunknown = 0;
        foreach ($learners as $learner) {
            $att = (int) ($learner['attended'] ?? 0);
            if ($att === attendance_manager::ATTEND_PRESENT) {
                $present++;
                $dc = (string) ($learner['diet_choice'] ?? '');
                if ($dc === 'A') {
                    $meat++;
                } else if ($dc === 'B') {
                    $veg++;
                } else {
                    $unknown++;
                }
            } else if ($att === attendance_manager::ATTEND_ABSENT) {
                $absent++;
            } else {
                $unset++;
            }

            $enrolleddc = (string) ($learner['diet_choice'] ?? '');
            if ($enrolleddc === 'A') {
                $enrolledmeat++;
            } else if ($enrolleddc === 'B') {
                $enrolledveg++;
            } else {
                $enrolledunknown++;
            }
        }
        return [
            'enrolled' => count($learners),
            'present' => $present,
            'absent' => $absent,
            'unset' => $unset,
            'diet_meat' => $meat,
            'diet_vegetarian' => $veg,
            'diet_unknown' => $unknown,
            'diet_enrolled_meat' => $enrolledmeat,
            'diet_enrolled_vegetarian' => $enrolledveg,
            'diet_enrolled_unknown' => $enrolledunknown,
        ];
    }

    /**
     * @param \stdClass $session from session_manager::get_session
     * @param array     $row     [userid, institution?, diet?]
     */
    /**
     * @param bool $recalc If false, caller runs recalculate once after batch (default true for single ops).
     * @return int Enrolment row id
     */
    private static function enrol_one_pending_batch(
        int $sessionid,
        \stdClass $session,
        int $userid,
        int $actorid,
        array $row,
        bool $recalc = true,
        bool $bypassprerequisite = false
    ): int {
        global $DB;

        $userobj = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$userobj) {
            throw new \moodle_exception('error_batch_user_invalid', 'local_tm_course');
        }

        $placeholderseq = (int)($row['placeholder_seq'] ?? 0);
        // Seat/batch-placeholder rows must bypass learner-only gates (prerequisite, same-course
        // conflict, approved time overlap). Rely on seq / internal holder email as well as the
        // explicit flag so older callers or partial payloads cannot strip skip behaviour.
        $skipchecks = !empty($row['is_placeholder_holder'])
            || $placeholderseq > 0
            || self::is_placeholder_holder_userid($userid);
        $seatcompanyraw = isset($row['seat_company']) ? trim((string)$row['seat_company']) : '';
        $seatcompanydb = ($seatcompanyraw !== '') ? clean_param($seatcompanyraw, PARAM_TEXT) : null;

        $linkedpendingdb = null;
        if (!empty($row['linked_email_pending'])) {
            $lp = \core_text::strtolower(trim((string)$row['linked_email_pending']));
            $lpclean = clean_param($lp, PARAM_EMAIL);
            if ($lpclean !== '') {
                $linkedpendingdb = $lpclean;
            }
        }

        $instoverride = isset($row['institution']) ? clean_param(trim((string) $row['institution']), PARAM_TEXT) : '';
        $userinst = trim((string) ($userobj->institution ?? ''));
        if ($userinst === '') {
            $fallback = ($instoverride !== '') ? $instoverride : (($seatcompanydb !== null && $seatcompanydb !== '') ? $seatcompanydb : '');
            if ($fallback === '') {
                throw new \moodle_exception('error_institution_required', 'local_tm_course');
            }
            $DB->set_field('user', 'institution', $fallback, ['id' => $userid]);
            $userobj->institution = $fallback;
        }

        if (!$skipchecks && !$bypassprerequisite) {
            self::assert_user_meets_prerequisites($session, $userid);
        }

        $activeStatuses = [
            session_manager::ENROL_PENDING,
            session_manager::ENROL_APPROVED,
            session_manager::ENROL_WAITLISTED,
        ];
        list($statusinsql, $statusparams) = $DB->get_in_or_equal($activeStatuses, SQL_PARAMS_NAMED);
        if (!$skipchecks) {
            $courseconflictsql = "SELECT 1
                                    FROM {local_tm_course_enrolments} e
                                    JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
                                   WHERE e.userid = :uid
                                     AND s.courseid = :cid
                                     AND e.sessionid <> :sid
                                     AND s.endtime > :now
                                     AND e.status $statusinsql";
            $courseparams = [
                'uid' => $userid,
                'cid' => (int) $session->courseid,
                'sid' => $sessionid,
                'now' => time(),
            ] + $statusparams;
            if ($DB->record_exists_sql($courseconflictsql, $courseparams)) {
                throw new \moodle_exception('error_course_enrolment_conflict', 'local_tm_course');
            }

            $timeconflict = self::find_approved_time_conflict(
                $userid,
                (int)$session->starttime,
                (int)$session->endtime,
                $sessionid
            );
            if ($timeconflict) {
                $hint = format_string((string)$timeconflict->name) . ' (' .
                    userdate((int)$timeconflict->starttime, get_string('strftimedatetimeshort')) . ' - ' .
                    userdate((int)$timeconflict->endtime, get_string('strftimedatetimeshort')) . ')';
                throw new \moodle_exception('error_enrolment_time_conflict_with_approved', 'local_tm_course', '', $hint);
            }
        }

        $diet = is_array($row['diet'] ?? null) ? $row['diet'] : [];
        $choice = strtoupper(trim((string) ($diet['choice'] ?? '')));
        // Match enrol(): diet_choice is NOT NULL in DB after upgrade 2026040218.
        $dietchoice = in_array($choice, ['A', 'B'], true) ? $choice : '';
        $dietbeef = 0;
        $dietsea = 0;
        $specialnote = clean_param((string)($diet['special_note'] ?? ''), PARAM_TEXT);
        if ($specialnote === '') {
            $specialnote = clean_param((string)($diet['meat_other'] ?? ($diet['vegetarian_notes'] ?? '')), PARAM_TEXT);
        }
        $dietmeatother = $specialnote;
        $dietveg = '';

        $batchnote = isset($row['batch_submitter_note']) ? (string) $row['batch_submitter_note'] : '';
        $batchnote = self::normalise_batch_submitter_note($batchnote);
        $batchnotedb = ($batchnote === '') ? null : $batchnote;
        $reservationinitial = !empty($row['reservation_initial']) ? 1 : 0;

        $existing = $DB->get_record('local_tm_course_enrolments', [
            'sessionid' => $sessionid,
            'userid'    => $userid,
        ], '*', IGNORE_MISSING);

        $status = session_manager::ENROL_PENDING;

        if ($existing) {
            $st = (int) $existing->status;
            if (in_array($st, [session_manager::ENROL_PENDING, session_manager::ENROL_APPROVED, session_manager::ENROL_WAITLISTED], true)) {
                throw new \moodle_exception('error_batch_already_active', 'local_tm_course');
            }
            $existing->status = $status;
            $existing->institution = $userobj->institution;
            $existing->desk_number = null;
            $existing->diet_choice = $dietchoice;
            $existing->diet_avoid_beef = $dietbeef;
            $existing->diet_avoid_seafood = $dietsea;
            $existing->diet_meat_other = $dietmeatother;
            $existing->diet_vegetarian_notes = $dietveg;
            $existing->batch_submittedby = $actorid;
            $existing->batch_submitter_note = $batchnotedb;
            $existing->reservation_initial_enrol = $reservationinitial;
            $existing->placeholder_seq = $placeholderseq > 0 ? $placeholderseq : null;
            $existing->seat_company = $seatcompanydb;
            $existing->linked_email = $linkedpendingdb;
            $existing->timemodified = time();
            $DB->update_record('local_tm_course_enrolments', $existing);
            $enrolid = (int)$existing->id;
        } else {
            $record = new \stdClass();
            $record->sessionid = $sessionid;
            $record->userid = $userid;
            $record->status = $status;
            $record->institution = $userobj->institution;
            $record->diet_choice = $dietchoice;
            $record->diet_avoid_beef = $dietbeef;
            $record->diet_avoid_seafood = $dietsea;
            $record->diet_meat_other = $dietmeatother;
            $record->diet_vegetarian_notes = $dietveg;
            $record->batch_submittedby = $actorid;
            $record->batch_submitter_note = $batchnotedb;
            $record->reservation_initial_enrol = $reservationinitial;
            $record->placeholder_seq = $placeholderseq > 0 ? $placeholderseq : null;
            $record->seat_company = $seatcompanydb;
            $record->linked_email = $linkedpendingdb;
            $record->timecreated = time();
            $record->timemodified = time();
            $enrolid = (int)$DB->insert_record('local_tm_course_enrolments', $record, true);
        }

        if ($recalc) {
            session_manager::recalculate_status($sessionid);
        }
        return $enrolid;
    }

    /**
     * Human-readable diet line for debrief tables (uses lang strings).
     */
    public static function format_diet_summary(\stdClass $e): string {
        $dc = trim((string) ($e->diet_choice ?? ''));
        if ($dc === '') {
            return '—';
        }
        if ($dc === 'A') {
            $parts = [get_string('diet_choice_meat', 'local_tm_course')];
            if (!empty($e->diet_meat_other)) {
                $parts[] = format_string($e->diet_meat_other);
            }
            return implode(' / ', $parts);
        }
        if ($dc === 'B') {
            $parts = [get_string('diet_choice_vegetarian', 'local_tm_course')];
            if (!empty($e->diet_meat_other)) {
                $parts[] = format_string($e->diet_meat_other);
            }
            return implode(' / ', $parts);
        }
        return '—';
    }

    /**
     * Find an approved enrolment of the user that overlaps the given timeslot.
     *
     * Overlap condition:
     * existing.start < target.end AND existing.end > target.start
     */
    private static function find_approved_time_conflict(
        int $userid,
        int $targetstart,
        int $targetend,
        int $excludesessionid = 0
    ): ?\stdClass {
        global $DB;
        if ($targetend <= $targetstart) {
            return null;
        }
        $sql = "SELECT s.id, s.name, s.starttime, s.endtime
                  FROM {local_tm_course_enrolments} e
                  JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
                 WHERE e.userid = :uid
                   AND e.status = :approved
                   AND s.id <> :sid
                   AND s.starttime < :targetend
                   AND s.endtime > :targetstart
              ORDER BY s.starttime ASC";
        $params = [
            'uid' => $userid,
            'approved' => session_manager::ENROL_APPROVED,
            'sid' => $excludesessionid,
            'targetend' => $targetend,
            'targetstart' => $targetstart,
        ];
        $row = $DB->get_record_sql($sql, $params, IGNORE_MISSING);
        return $row ?: null;
    }
}
