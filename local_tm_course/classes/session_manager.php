<?php
/**
 * Session Manager — Core business logic for M1 & M2
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/classroom_manager.php');
require_once(__DIR__ . '/attendance_manager.php');
require_once(__DIR__ . '/enabled_course_manager.php');
require_once(__DIR__ . '/prerequisite_manager.php');
require_once(__DIR__ . '/tcms_sync_manager.php');

class session_manager {

    // ----------------------------------------------------------------
    // Constants
    // ----------------------------------------------------------------
    /** Legacy sessions (no classroom): max desks. */
    const MAX_DESKS_LEGACY   = 6;
    const MIN_DESKS          = 1;
    const MIN_PERSONS        = 1;
    const MAX_PERSONS        = 3;
    /** When a classroom is selected, persons per desk is fixed (capacity = tables × 3). */
    const PERSONS_CLASSROOM  = 3;

    const STATUS_CLOSED      = 0;
    const STATUS_OPEN        = 1;
    const STATUS_FULL        = 2;

    const APPROVAL_AUTO      = 0;
    const APPROVAL_MANUAL    = 1;

    const DELIVERY_ONSITE    = 'onsite';
    const DELIVERY_ONLINE    = 'online';

    const LANG_ENGLISH       = 'en';
    const LANG_ZH_TW         = 'zh_tw';

    const ENROL_PENDING      = 0;
    const ENROL_APPROVED     = 1;
    const ENROL_REJECTED     = 2;
    const ENROL_CANCELLED    = 3;
    const ENROL_WAITLISTED   = 4;

    /** Regular course session (default in DB). */
    const SESSION_KIND_STANDARD = 'standard';
    /** Classroom time block only — no course, no enrolment; blocks reservation calendar. */
    const SESSION_KIND_ROOM_CLOSED = 'room_closed';

    /**
     * True when this row is a “教室未開放” block (occupies classroom only).
     */
    public static function is_room_closed_session(\stdClass $session): bool {
        return isset($session->session_kind) && (string)$session->session_kind === self::SESSION_KIND_ROOM_CLOSED;
    }

    // ----------------------------------------------------------------
    // Session CRUD
    // ----------------------------------------------------------------

    /**
     * Create a new session. Returns new record id.
     */
    public static function create_session(array $data): int {
        global $DB, $USER;

        self::normalize_session_data($data);
        self::validate_session_data($data, true);
        self::validate_classroom_time_conflict($data, null);

        $record = new \stdClass();
        $record->courseid           = (int)$data['courseid'];
        $record->classroomid        = (int)($data['classroomid'] ?? 0);
        $record->name               = clean_param($data['name'], PARAM_TEXT);
        $record->description        = clean_param($data['description'] ?? '', PARAM_CLEANHTML);
        $record->location           = clean_param($data['location'] ?? '', PARAM_TEXT);
        $record->teaching_language  = clean_param($data['teaching_language'] ?? self::LANG_ZH_TW, PARAM_ALPHANUMEXT);
        $record->delivery_mode      = clean_param($data['delivery_mode'] ?? self::DELIVERY_ONSITE, PARAM_ALPHANUMEXT);
        $record->meeting_link       = clean_param($data['meeting_link'] ?? '', PARAM_RAW_TRIMMED);
        $record->starttime          = (int)$data['starttime'];
        $record->endtime            = (int)$data['endtime'];
        $record->duration_hours     = round((float)$data['duration_hours'], 2);
        $record->num_desks          = (int)$data['num_desks'];
        $record->persons_per_desk   = (int)$data['persons_per_desk'];
        $record->approval_mode      = (int)($data['approval_mode'] ?? self::APPROVAL_MANUAL);
        self::apply_prerequisite_fields($record, $data);
        $record->status             = isset($data['status']) ? (int)$data['status'] : self::STATUS_OPEN;
        $record->auto_close_exempt  = !empty($data['auto_close_exempt']) ? 1 : 0;
        $record->timecreated        = time();
        $record->timemodified       = time();
        $record->createdby          = $USER->id;
        $srid = (int)($data['source_reservation_id'] ?? 0);
        if ($srid > 0) {
            $record->source_reservation_id = $srid;
        }

        $sk = clean_param((string)($data['session_kind'] ?? self::SESSION_KIND_STANDARD), PARAM_ALPHANUMEXT);
        $record->session_kind = ($sk === self::SESSION_KIND_ROOM_CLOSED) ? self::SESSION_KIND_ROOM_CLOSED : self::SESSION_KIND_STANDARD;

        $id = $DB->insert_record('local_tm_course_sessions', $record);
        // Auto-create Moodle group/attendance slot for this session.
        try {
            attendance_manager::setup_session($id);
        } catch (\Throwable $t) {
            debugging('TM Course setup_session failed on create: ' . $t->getMessage(), DEBUG_DEVELOPER);
        }

        try {
            tcms_sync_manager::push_session($id);
        } catch (\Throwable $t) {
            debugging('TCMS sync failed on create: ' . $t->getMessage(), DEBUG_DEVELOPER);
        }

        return $id;
    }

    /**
     * Update an existing session.
     */
    public static function update_session(int $id, array $data): void {
        global $DB;

        self::normalize_session_data($data);
        self::validate_session_data($data, false);
        self::validate_classroom_time_conflict($data, $id);

        $record = $DB->get_record('local_tm_course_sessions', ['id' => $id], '*', MUST_EXIST);
        $record->courseid           = (int)$data['courseid'];
        $record->classroomid        = (int)($data['classroomid'] ?? 0);
        $record->name               = clean_param($data['name'], PARAM_TEXT);
        $record->description        = clean_param($data['description'] ?? '', PARAM_CLEANHTML);
        $record->location           = clean_param($data['location'] ?? '', PARAM_TEXT);
        $record->teaching_language  = clean_param($data['teaching_language'] ?? self::LANG_ZH_TW, PARAM_ALPHANUMEXT);
        $record->delivery_mode      = clean_param($data['delivery_mode'] ?? self::DELIVERY_ONSITE, PARAM_ALPHANUMEXT);
        $record->meeting_link       = clean_param($data['meeting_link'] ?? '', PARAM_RAW_TRIMMED);
        $record->starttime          = (int)$data['starttime'];
        $record->endtime            = (int)$data['endtime'];
        $record->duration_hours     = round((float)$data['duration_hours'], 2);
        $record->num_desks          = (int)$data['num_desks'];
        $record->persons_per_desk   = (int)$data['persons_per_desk'];
        $record->approval_mode      = (int)($data['approval_mode'] ?? self::APPROVAL_MANUAL);
        self::apply_prerequisite_fields($record, $data);
        if (isset($data['status'])) {
            $record->status = (int)$data['status'];
        }
        $pastdeadline = self::session_auto_close_timestamp((int)$record->starttime) <= time();
        if ((int)$record->status === self::STATUS_CLOSED) {
            $record->auto_close_exempt = 0;
        } else if (array_key_exists('auto_close_exempt', $data)) {
            $exempt = !empty($data['auto_close_exempt']);
            if ($pastdeadline && !$exempt && (int)$record->status === self::STATUS_OPEN) {
                throw new \moodle_exception('error_session_open_past_deadline_requires_exempt', 'local_tm_course');
            }
            $record->auto_close_exempt = $exempt ? 1 : 0;
        }
        $record->timemodified       = time();

        $DB->update_record('local_tm_course_sessions', $record);
        // Keep Moodle group/attendance session in sync after edits.
        try {
            attendance_manager::setup_session($id);
        } catch (\Throwable $t) {
            debugging('TM Course setup_session failed on update: ' . $t->getMessage(), DEBUG_DEVELOPER);
        }

        // Recalculate status after desk changes
        self::recalculate_status($id);

        try {
            tcms_sync_manager::push_session($id);
        } catch (\Throwable $t) {
            debugging('TCMS sync failed on update: ' . $t->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Persist prerequisite_rules JSON and legacy prerequisite_courseid column.
     *
     * @param \stdClass $record session row being saved
     * @param array     $data   normalized session payload
     */
    private static function apply_prerequisite_fields(\stdClass $record, array $data): void {
        $rules = null;
        if (array_key_exists('prerequisite_rules', $data)) {
            if (is_array($data['prerequisite_rules'])) {
                $rules = prerequisite_manager::normalize_rules($data['prerequisite_rules']);
            } else {
                $json = trim((string)$data['prerequisite_rules']);
                if ($json !== '') {
                    $decoded = json_decode($json, true);
                    $rules = is_array($decoded) ? prerequisite_manager::normalize_rules($decoded) : null;
                }
            }
            prerequisite_manager::validate_rules($rules);
            $record->prerequisite_rules = prerequisite_manager::encode_for_storage($rules);
            $record->prerequisite_courseid = prerequisite_manager::legacy_courseid_from_rules($rules);
            return;
        }
        if (array_key_exists('prerequisite_courseid', $data)) {
            $legacy = !empty($data['prerequisite_courseid']) ? (int)$data['prerequisite_courseid'] : 0;
            if ($legacy > 0) {
                $rules = prerequisite_manager::normalize_rules([
                    'operator' => prerequisite_manager::OPERATOR_AND,
                    'rules' => [
                        ['courseid' => $legacy, 'verify_type' => prerequisite_manager::VERIFY_COURSE],
                    ],
                ]);
                prerequisite_manager::validate_rules($rules);
                $record->prerequisite_rules = prerequisite_manager::encode_for_storage($rules);
                $record->prerequisite_courseid = $legacy;
            } else {
                $record->prerequisite_rules = null;
                $record->prerequisite_courseid = null;
            }
        }
    }

    /**
     * Delete a session and all its enrolments.
     */
    public static function delete_session(int $id): void {
        global $DB;
        $session = $DB->get_record('local_tm_course_sessions', ['id' => $id], '*', IGNORE_MISSING);
        if ($session) {
            try {
                tcms_sync_manager::delete_remote_for_session($session);
            } catch (\Throwable $t) {
                debugging('TCMS sync failed on delete: ' . $t->getMessage(), DEBUG_DEVELOPER);
            }
            try {
                attendance_manager::cleanup_session_resources($session);
            } catch (\Throwable $t) {
                debugging('TM Course cleanup_session_resources failed: ' . $t->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $DB->delete_records('local_tm_course_enrolments', ['sessionid' => $id]);
        $DB->delete_records('local_tm_course_sessions',   ['id'        => $id]);
    }

    /**
     * Create a classroom-only occupancy block (教室未開放). No course, groups, attendance, or enrolment.
     *
     * @param array{classroomid:int,starttime:int,endtime:int,name?:string} $data
     */
    public static function create_room_closed_block(array $data): int {
        global $DB, $USER;

        $classroomid = (int)($data['classroomid'] ?? 0);
        $start = (int)($data['starttime'] ?? 0);
        $end = (int)($data['endtime'] ?? 0);
        if ($classroomid <= 0 || $end <= $start) {
            throw new \moodle_exception('error_room_closed_invalid', 'local_tm_course');
        }

        $vd = [
            'classroomid' => $classroomid,
            'starttime' => $start,
            'endtime' => $end,
            'allow_same_day_classroom' => true,
            'session_kind' => self::SESSION_KIND_ROOM_CLOSED,
        ];
        self::validate_classroom_time_conflict($vd, null);

        $room = classroom_manager::get($classroomid);
        $location = classroom_manager::session_location_label($room);
        $name = trim(clean_param($data['name'] ?? '', PARAM_TEXT));
        if ($name === '') {
            $name = get_string('session_room_closed_default_title', 'local_tm_course');
        }

        $now = time();
        $record = new \stdClass();
        $record->courseid = 0;
        $record->classroomid = $classroomid;
        $record->name = $name;
        $record->description = '';
        $record->location = $location;
        $record->teaching_language = self::LANG_ZH_TW;
        $record->delivery_mode = self::DELIVERY_ONSITE;
        $record->meeting_link = '';
        $record->starttime = $start;
        $record->endtime = $end;
        $record->duration_hours = round(($end - $start) / 3600, 2);
        $record->num_desks = 0;
        $record->persons_per_desk = 1;
        $record->approval_mode = self::APPROVAL_AUTO;
        $record->prerequisite_courseid = null;
        $record->status = self::STATUS_CLOSED;
        $record->auto_close_exempt = 0;
        $record->session_kind = self::SESSION_KIND_ROOM_CLOSED;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->createdby = (!empty($USER->id)) ? (int)$USER->id : 0;
        $tcmssessionid = trim((string)($data['tcms_session_id'] ?? ''));
        if ($tcmssessionid !== '') {
            $record->tcms_session_id = clean_param($tcmssessionid, PARAM_TEXT);
            $record->tcms_sync_status = 'from_tcms';
            $record->tcms_last_synced = $now;
        }

        return (int)$DB->insert_record('local_tm_course_sessions', $record);
    }

    /**
     * Update a room-closed block (time / classroom / optional title only).
     *
     * @param array{classroomid:int,starttime:int,endtime:int,name?:string} $data
     */
    public static function update_room_closed_block(int $id, array $data): void {
        global $DB;

        $session = $DB->get_record('local_tm_course_sessions', ['id' => $id], '*', MUST_EXIST);
        if (!self::is_room_closed_session($session)) {
            throw new \moodle_exception('error_room_closed_not_this_kind', 'local_tm_course');
        }

        $classroomid = (int)($data['classroomid'] ?? 0);
        $start = (int)($data['starttime'] ?? 0);
        $end = (int)($data['endtime'] ?? 0);
        if ($classroomid <= 0 || $end <= $start) {
            throw new \moodle_exception('error_room_closed_invalid', 'local_tm_course');
        }

        $vd = [
            'classroomid' => $classroomid,
            'starttime' => $start,
            'endtime' => $end,
            'allow_same_day_classroom' => true,
            'session_kind' => self::SESSION_KIND_ROOM_CLOSED,
        ];
        self::validate_classroom_time_conflict($vd, $id);

        $room = classroom_manager::get($classroomid);
        $location = classroom_manager::session_location_label($room);
        $name = trim(clean_param($data['name'] ?? '', PARAM_TEXT));
        if ($name === '') {
            $name = get_string('session_room_closed_default_title', 'local_tm_course');
        }

        $session->classroomid = $classroomid;
        $session->name = $name;
        $session->location = $location;
        $session->starttime = $start;
        $session->endtime = $end;
        $session->duration_hours = round(($end - $start) / 3600, 2);
        $session->timemodified = time();
        $tcmssessionid = trim((string)($data['tcms_session_id'] ?? ''));
        if ($tcmssessionid !== '') {
            $session->tcms_session_id = clean_param($tcmssessionid, PARAM_TEXT);
            $session->tcms_sync_status = 'from_tcms';
            $session->tcms_last_synced = time();
        }

        $DB->update_record('local_tm_course_sessions', $session);
    }

    /**
     * Find room_closed block linked to a TCMS session id (Phase 2 inbound).
     */
    public static function find_room_closed_by_tcms_session_id(string $tcmssessionid): ?\stdClass {
        global $DB;
        $tcmssessionid = trim($tcmssessionid);
        if ($tcmssessionid === '') {
            return null;
        }
        $rec = $DB->get_record('local_tm_course_sessions', [
            'session_kind' => self::SESSION_KIND_ROOM_CLOSED,
            'tcms_session_id' => $tcmssessionid,
        ], '*', IGNORE_MISSING);
        return $rec ?: null;
    }

    /**
     * Upsert room occupancy from TCMS. Returns Moodle session id.
     *
     * @param array{classroomid:int,starttime:int,endtime:int,name?:string,tcms_session_id:string} $data
     */
    public static function upsert_room_closed_from_tcms(array $data): int {
        $tcmssessionid = trim((string)($data['tcms_session_id'] ?? ''));
        if ($tcmssessionid === '') {
            throw new \moodle_exception('error_room_closed_invalid', 'local_tm_course');
        }
        $existing = self::find_room_closed_by_tcms_session_id($tcmssessionid);
        if ($existing) {
            self::update_room_closed_block((int)$existing->id, $data);
            return (int)$existing->id;
        }
        return self::create_room_closed_block($data);
    }

    /**
     * Delete room_closed linked to a TCMS session (no-op if none).
     */
    public static function delete_room_closed_by_tcms_session_id(string $tcmssessionid): bool {
        $existing = self::find_room_closed_by_tcms_session_id($tcmssessionid);
        if (!$existing) {
            return false;
        }
        self::delete_session((int)$existing->id);
        return true;
    }

    /**
     * Get a single session with computed fields.
     */
    public static function get_session(int $id): \stdClass {
        global $DB;
        $session = $DB->get_record('local_tm_course_sessions', ['id' => $id], '*', MUST_EXIST);
        self::preload_classroom_location_cache([$session]);
        return self::enrich_session($session);
    }

    /**
     * Get all sessions (with optional filters).
     */
    public static function get_sessions(array $filters = []): array {
        global $DB;
        // Keep session status synchronized with the auto-close rule.
        self::auto_close_elapsed_sessions();

        $conditions = [];
        $params     = [];

        if (!empty($filters['courseid'])) {
            $conditions[] = 'courseid = :courseid';
            $params['courseid'] = (int)$filters['courseid'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $conditions[] = 'status = :status';
            $params['status'] = (int)$filters['status'];
        }
        if (!empty($filters['from'])) {
            $conditions[] = 'starttime >= :from';
            $params['from'] = (int)$filters['from'];
        }
        if (!empty($filters['to'])) {
            $conditions[] = 'starttime <= :to';
            $params['to'] = (int)$filters['to'];
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql   = "SELECT * FROM {local_tm_course_sessions} $where ORDER BY starttime ASC";
        $rows  = $DB->get_records_sql($sql, $params);

        self::preload_classroom_location_cache($rows);
        return array_map([self::class, 'enrich_session'], $rows);
    }

    /**
     * Auto-close sessions at 00:00 of the calendar day that is N days before the session start date,
     * where N is the site setting {@see get_session_auto_close_days_before()} (default 1).
     *
     * Sessions with auto_close_exempt=1 are never changed by this routine.
     *
     * @return int number of sessions changed to closed
     */
    public static function auto_close_elapsed_sessions(): int {
        global $DB;
        $candidates = $DB->get_records_select(
            'local_tm_course_sessions',
            'status IN (:openstatus, :fullstatus) AND auto_close_exempt = 0',
            ['openstatus' => self::STATUS_OPEN, 'fullstatus' => self::STATUS_FULL],
            '',
            'id, status, starttime'
        );
        if (empty($candidates)) {
            return 0;
        }

        $now = time();
        $changed = 0;
        foreach ($candidates as $s) {
            $autoclosetime = self::session_auto_close_timestamp((int)$s->starttime);
            if ($autoclosetime <= $now) {
                $DB->set_field('local_tm_course_sessions', 'status', self::STATUS_CLOSED, ['id' => (int)$s->id]);
                $DB->set_field('local_tm_course_sessions', 'timemodified', $now, ['id' => (int)$s->id]);
                try {
                    tcms_sync_manager::push_session((int) $s->id);
                } catch (\Throwable $t) {
                    debugging('TCMS sync failed on auto-close: ' . $t->getMessage(), DEBUG_DEVELOPER);
                }
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * Site setting: close registration at 00:00 on the calendar day that is this many days before the session start day.
     * Default 1 preserves legacy behaviour (close starting midnight the day before class).
     *
     * @return int clamped 0–60
     */
    public static function get_session_auto_close_days_before(): int {
        $raw = get_config('local_tm_course', 'session_auto_close_days_before');
        $d = is_numeric($raw) ? (int)$raw : 1;
        return max(0, min(60, $d));
    }

    /**
     * Unix timestamp when the registration auto-close rule takes effect for a session start time.
     */
    public static function session_auto_close_timestamp(int $starttime): int {
        $startday = strtotime(date('Y-m-d 00:00:00', $starttime));
        return $startday - (self::get_session_auto_close_days_before() * DAYSECS);
    }

    /**
     * True when the configured registration deadline has passed and this session is not exempt.
     */
    public static function is_registration_deadline_passed(\stdClass $session): bool {
        if (!empty($session->auto_close_exempt)) {
            return false;
        }
        return time() >= self::session_auto_close_timestamp((int)$session->starttime);
    }

    /**
     * @param \stdClass $session session row (enriched optional)
     */
    public static function is_online_session(\stdClass $session): bool {
        return ((string) ($session->delivery_mode ?? '') === self::DELIVERY_ONLINE);
    }

    /**
     * Learner-facing teaching hours: course preset for delivery mode (not start–end span).
     */
    public static function learner_display_duration_hours(\stdClass $session): float {
        $courseid = (int) ($session->courseid ?? 0);
        if ($courseid > 0) {
            return enabled_course_manager::get_default_duration_hours(
                $courseid,
                (string) ($session->delivery_mode ?? self::DELIVERY_ONSITE)
            );
        }
        return max(0.0, (float) ($session->duration_hours ?? 0));
    }

    /**
     * Onsite only: every desk slot has at least one approved learner with desk_number.
     */
    public static function is_onsite_desks_full(\stdClass $session): bool {
        if (self::is_online_session($session)) {
            return false;
        }
        $numdesks = (int) ($session->num_desks ?? 0);
        if ($numdesks <= 0) {
            return false;
        }
        return self::remaining_desks($session) <= 0;
    }

    /**
     * Whether self-enrol or business batch may submit new enrolments.
     * Onsite: blocked when STATUS_FULL (all desks occupied). Online: never blocked by full.
     * Closed / registration deadline: blocked unless $adminoverride (manage batch on closed sessions).
     *
     * @param \stdClass $session
     * @param bool $adminoverride allow closed/deadline for admin batch only
     */
    public static function can_submit_enrolment(\stdClass $session, bool $adminoverride = false): bool {
        if (!self::is_online_session($session)
            && ((int) $session->status === self::STATUS_FULL || self::is_onsite_desks_full($session))) {
            return false;
        }
        if (self::is_registration_deadline_passed($session)) {
            return $adminoverride;
        }
        if ((int) $session->status === self::STATUS_CLOSED) {
            return $adminoverride;
        }
        return true;
    }

    // ----------------------------------------------------------------
    // Batch creation
    // ----------------------------------------------------------------

    /**
     * Create multiple sessions at once (repeat weekly or monthly).
     * Returns array of created session ids.
     */
    public static function batch_create(array $base_data, int $repeat_type, int $repeat_count): array {
        global $DB;
        $transaction = $DB->start_delegated_transaction();

        $ids = [];
        $start = (int)$base_data['starttime'];
        $end   = (int)$base_data['endtime'];

        for ($i = 0; $i < $repeat_count; $i++) {
            $data = $base_data;
            $data['starttime'] = $start;
            $data['endtime']   = $end;
            $ids[] = self::create_session($data);

            if ($repeat_type === 1) {      // weekly
                $start = strtotime('+1 week', $start);
                $end   = strtotime('+1 week', $end);
            } elseif ($repeat_type === 2) { // monthly
                $start = strtotime('+1 month', $start);
                $end   = strtotime('+1 month', $end);
            }
        }

        $transaction->allow_commit();
        return $ids;
    }

    // ----------------------------------------------------------------
    // Capacity helpers
    // ----------------------------------------------------------------

    /**
     * Total capacity = desks × persons_per_desk
     */
    public static function total_capacity(\stdClass $session): int {
        if (self::is_room_closed_session($session)) {
            return 0;
        }
        return (int)$session->num_desks * (int)$session->persons_per_desk;
    }

    /**
     * Count confirmed (approved) enrolments.
     */
    public static function confirmed_count(int $sessionid): int {
        global $DB;
        return (int)$DB->count_records('local_tm_course_enrolments', [
            'sessionid' => $sessionid,
            'status'    => self::ENROL_APPROVED,
        ]);
    }

    /**
     * Remaining capacity (in persons, not desks).
     * Returns negative if over-enrolled (shouldn't happen but guard anyway).
     */
    public static function remaining_persons(\stdClass $session): int {
        return self::total_capacity($session) - self::confirmed_count($session->id);
    }

    /**
     * Occupied desks by approved enrolments with explicit desk assignment.
     *
     * @return int[]
     */
    public static function occupied_desk_numbers(int $sessionid): array {
        global $DB;
        $sql = "SELECT DISTINCT desk_number
                  FROM {local_tm_course_enrolments}
                 WHERE sessionid = :sid
                   AND status = :approved
                   AND desk_number IS NOT NULL
                   AND desk_number > 0
              ORDER BY desk_number ASC";
        $rows = $DB->get_fieldset_sql($sql, [
            'sid' => $sessionid,
            'approved' => self::ENROL_APPROVED,
        ]);
        return array_map('intval', $rows);
    }

    /**
     * Remaining desks by assigned desk slots.
     */
    public static function remaining_desks(\stdClass $session): int {
        $occupied = count(self::occupied_desk_numbers((int) $session->id));
        return max(0, (int) $session->num_desks - $occupied);
    }

    /**
     * Recalculate and persist status (open/full).
     */
    public static function recalculate_status(int $sessionid): void {
        global $DB;
        $session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid], '*', MUST_EXIST);

        if (self::is_room_closed_session($session)) {
            return;
        }

        if ((int)$session->status === self::STATUS_CLOSED) {
            return; // Manually closed — don't touch
        }

        // Online: never auto-mark full. Onsite: full when every desk has an approved assignee.
        if (self::is_online_session($session)) {
            $new_status = self::STATUS_OPEN;
        } else if ((int) $session->num_desks <= 0) {
            $new_status = self::STATUS_OPEN;
        } else {
            $new_status = (self::remaining_desks($session) <= 0) ? self::STATUS_FULL : self::STATUS_OPEN;
        }

        if ($new_status !== (int)$session->status) {
            $DB->set_field('local_tm_course_sessions', 'status', $new_status, ['id' => $sessionid]);
            $DB->set_field('local_tm_course_sessions', 'timemodified', time(), ['id' => $sessionid]);
        }
    }

    // ----------------------------------------------------------------
    // Validation
    // ----------------------------------------------------------------

    /**
     * Prevent classroom time conflicts when creating/updating sessions.
     *
     * Overlap rule:
     *   existing.starttime < new.endtime AND existing.endtime > new.starttime
     * This allows exact touching edges (end == start).
     *
     * @throws \moodle_exception
     */
    private static function validate_classroom_time_conflict(array $data, ?int $ignoreid = null): void {
        global $DB;

        $classroomid = (int)($data['classroomid'] ?? 0);
        if ($classroomid <= 0) {
            return; // Legacy sessions do not have classroom venue id.
        }

        $start = (int)($data['starttime'] ?? 0);
        $end   = (int)($data['endtime'] ?? 0);
        if ($end <= $start) {
            return;
        }

        $conditions = [
            'classroomid = :classroomid',
            'starttime < :endtime',
            'endtime > :starttime',
        ];
        $params = [
            'classroomid' => $classroomid,
            'starttime'   => $start,
            'endtime'     => $end,
        ];

        if (!empty($ignoreid)) {
            $conditions[] = 'id <> :ignoreid';
            $params['ignoreid'] = (int)$ignoreid;
        }

        $sql = 'SELECT id
                  FROM {local_tm_course_sessions}
                 WHERE ' . implode(' AND ', $conditions);

        if ($DB->record_exists_sql($sql, $params)) {
            throw new \moodle_exception('error_classroom_time_conflict', 'local_tm_course');
        }

        $newkind = clean_param((string)($data['session_kind'] ?? self::SESSION_KIND_STANDARD), PARAM_ALPHANUMEXT);
        if ($newkind === self::SESSION_KIND_ROOM_CLOSED) {
            return;
        }

        // Additional guard: same classroom + same date should not have multiple sessions.
        // Reservation review flow may intentionally create multiple non-overlapping
        // blocks in one day for the same classroom, so allow that path to opt out.
        $allowsamedayclassroom = !empty($data['allow_same_day_classroom']);
        if ($allowsamedayclassroom) {
            return;
        }

        $daystart = strtotime(date('Y-m-d 00:00:00', $start));
        $dayend = strtotime(date('Y-m-d 23:59:59', $start));

        $sameDayConditions = [
            'classroomid = :classroomid2',
            'starttime >= :daystart',
            'starttime <= :dayend',
            "(COALESCE(session_kind, 'standard') = 'standard')",
        ];
        $sameDayParams = [
            'classroomid2' => $classroomid,
            'daystart' => $daystart,
            'dayend' => $dayend,
        ];
        if (!empty($ignoreid)) {
            $sameDayConditions[] = 'id <> :ignoreid2';
            $sameDayParams['ignoreid2'] = (int)$ignoreid;
        }
        $samedaysql = 'SELECT id
                         FROM {local_tm_course_sessions}
                        WHERE ' . implode(' AND ', $sameDayConditions);
        if ($DB->record_exists_sql($samedaysql, $sameDayParams)) {
            throw new \moodle_exception('error_classroom_same_day_conflict', 'local_tm_course');
        }
    }

    /**
     * Apply classroom table_count and fixed 3 persons/desk; set display location.
     *
     * @param array $data (by ref)
     */
    public static function normalize_session_data(array &$data): void {
        $delivery = clean_param((string)($data['delivery_mode'] ?? self::DELIVERY_ONSITE), PARAM_ALPHANUMEXT);
        $cid = (int) ($data['classroomid'] ?? 0);
        if ($cid > 0) {
            $room = classroom_manager::get($cid);
            if (trim((string)($data['location'] ?? '')) === '') {
                $data['location'] = classroom_manager::session_location_label($room);
            }
            // Onsite: capacity = classroom table_count × 3. Online: classroomid is for
            // calendar occupancy only; enrolment is not capped by desks (see can_submit_enrolment).
            if ($delivery === self::DELIVERY_ONSITE
                && (empty($data['num_desks']) || empty($data['persons_per_desk']))) {
                [$d, $p] = self::capacity_for_classroom_record($room);
                $data['num_desks'] = (int)$d;
                $data['persons_per_desk'] = (int)$p;
            }
        }
        if ($delivery === self::DELIVERY_ONLINE
            && ((int)($data['num_desks'] ?? 0) < self::MIN_DESKS
                || (int)($data['persons_per_desk'] ?? 0) < self::MIN_PERSONS)) {
            // Satisfy DB/validate only; not used to block online enrolment.
            $data['num_desks'] = self::MIN_DESKS;
            $data['persons_per_desk'] = self::MIN_PERSONS;
        }

        $st = (int) ($data['starttime'] ?? 0);
        $et = (int) ($data['endtime'] ?? 0);
        $autophysical = !empty($data['schedule_auto_physical']);
        if ($autophysical && $delivery === self::DELIVERY_ONSITE) {
            $courseid = (int) ($data['courseid'] ?? 0);
            if ($courseid > 0) {
                $data['duration_hours'] = round(enabled_course_manager::get_default_duration_hours($courseid), 2);
            }
        } else if ($et > $st) {
            $data['duration_hours'] = round(($et - $st) / 3600, 2);
        }
        unset($data['schedule_auto_physical']);
    }

    /**
     * Validate session data. Throws \moodle_exception on failure.
     *
     * @param array $data
     * @param bool  $isnew If true, a classroom is required (new sessions).
     */
    public static function validate_session_data(array $data, bool $isnew = false): void {
        $delivery = clean_param((string)($data['delivery_mode'] ?? self::DELIVERY_ONSITE), PARAM_ALPHANUMEXT);
        $lang = clean_param((string)($data['teaching_language'] ?? self::LANG_ZH_TW), PARAM_ALPHANUMEXT);
        if (!in_array($delivery, [self::DELIVERY_ONSITE, self::DELIVERY_ONLINE], true)) {
            throw new \moodle_exception('error_delivery_mode_invalid', 'local_tm_course');
        }
        if (!in_array($lang, [self::LANG_ENGLISH, self::LANG_ZH_TW], true)) {
            throw new \moodle_exception('error_teaching_language_invalid', 'local_tm_course');
        }
        if ($delivery === self::DELIVERY_ONLINE) {
            $link = trim((string)($data['meeting_link'] ?? ''));
            if ($link === '') {
                throw new \moodle_exception('error_meeting_link_required', 'local_tm_course');
            }
        }
        $classroomid = (int) ($data['classroomid'] ?? 0);

        if ($isnew && $delivery === self::DELIVERY_ONSITE && $classroomid <= 0) {
            throw new \moodle_exception('error_classroom_required', 'local_tm_course');
        }

        $desks = (int) ($data['num_desks'] ?? 0);
        $ppd = (int) ($data['persons_per_desk'] ?? 0);

        if ($delivery === self::DELIVERY_ONSITE) {
            if ($desks < self::MIN_DESKS) {
                throw new \moodle_exception('error_desks_positive', 'local_tm_course');
            }
            if ($desks > classroom_manager::MAX_TABLES) {
                throw new \moodle_exception('error_classroom_tables_range', 'local_tm_course');
            }
            if ($ppd < self::MIN_PERSONS || $ppd > self::MAX_PERSONS) {
                throw new \moodle_exception('error_persons_range', 'local_tm_course');
            }
        } else {
            if ($desks < self::MIN_DESKS) {
                throw new \moodle_exception('error_desks_positive', 'local_tm_course');
            }
            if ($ppd < self::MIN_PERSONS || $ppd > self::MAX_PERSONS) {
                throw new \moodle_exception('error_persons_range', 'local_tm_course');
            }
        }

        if ((int) $data['endtime'] <= (int) $data['starttime']) {
            throw new \moodle_exception('error_end_after_start', 'local_tm_course');
        }

        if ((float) ($data['duration_hours'] ?? 0) <= 0) {
            throw new \moodle_exception('error_hours_positive', 'local_tm_course');
        }

        if (empty(trim($data['name'] ?? ''))) {
            throw new \moodle_exception('errornamemissing', 'error');
        }
    }

    // ----------------------------------------------------------------
    // Session location (live from classroom management when linked)
    // ----------------------------------------------------------------

    /** @var array<int,\stdClass|null> */
    private static $classroomlocationcache = [];

    /**
     * Display location: linked classroom name/address when classroomid is set,
     * otherwise the stored session.location (legacy / manual).
     */
    public static function resolve_session_location(\stdClass $session): string {
        $classroomid = (int) ($session->classroomid ?? 0);
        if ($classroomid > 0) {
            $room = self::get_classroom_for_location($classroomid);
            if ($room !== null) {
                return classroom_manager::session_location_label($room);
            }
        }
        return trim((string) ($session->location ?? ''));
    }

    /**
     * Batch-load classrooms for a list of sessions (avoids N+1 in lists).
     *
     * @param \stdClass[] $sessions
     */
    public static function preload_classroom_location_cache(array $sessions): void {
        global $DB;

        $ids = [];
        foreach ($sessions as $session) {
            $classroomid = (int) ($session->classroomid ?? 0);
            if ($classroomid > 0 && !array_key_exists($classroomid, self::$classroomlocationcache)) {
                $ids[$classroomid] = $classroomid;
            }
        }
        if ($ids === []) {
            return;
        }

        $rooms = $DB->get_records_list('local_tm_classroom', 'id', array_values($ids), '', 'id, name, location');
        foreach ($rooms as $room) {
            self::$classroomlocationcache[(int) $room->id] = $room;
        }
        foreach ($ids as $classroomid) {
            if (!array_key_exists($classroomid, self::$classroomlocationcache)) {
                self::$classroomlocationcache[$classroomid] = null;
            }
        }
    }

    /**
     * @param \stdClass[] $sessions
     * @return \stdClass[]
     */
    public static function apply_resolved_locations(array $sessions): array {
        if ($sessions === []) {
            return $sessions;
        }
        self::preload_classroom_location_cache($sessions);
        foreach ($sessions as $session) {
            $session->location = self::resolve_session_location($session);
        }
        return $sessions;
    }

    private static function get_classroom_for_location(int $classroomid): ?\stdClass {
        if ($classroomid <= 0) {
            return null;
        }
        if (array_key_exists($classroomid, self::$classroomlocationcache)) {
            $cached = self::$classroomlocationcache[$classroomid];
            return $cached instanceof \stdClass ? $cached : null;
        }
        global $DB;
        $room = $DB->get_record('local_tm_classroom', ['id' => $classroomid], 'id, name, location', IGNORE_MISSING);
        self::$classroomlocationcache[$classroomid] = $room ?: null;
        return $room ?: null;
    }

    // ----------------------------------------------------------------
    // Enrich session with computed fields
    // ----------------------------------------------------------------

    private static function enrich_session(\stdClass $session): \stdClass {
        $session->location = self::resolve_session_location($session);
        $session->total_capacity   = self::total_capacity($session);
        $session->confirmed_count  = self::confirmed_count($session->id);
        $session->remaining_persons = self::remaining_persons($session);
        $session->occupied_desk_numbers = self::occupied_desk_numbers((int) $session->id);
        $session->occupied_desks_count = count($session->occupied_desk_numbers);
        $session->remaining_desks  = max(0, (int) $session->num_desks - (int) $session->occupied_desks_count);
        $session->fill_percent     = $session->total_capacity > 0
            ? min(100, round($session->confirmed_count / $session->total_capacity * 100))
            : 0;
        return $session;
    }

    /**
     * Capacity from classroom settings (table_count × fixed persons per desk).
     *
     * @return array{0:int,1:int} [desks, persons_per_desk]
     */
    public static function capacity_for_classroom_record(\stdClass $room): array {
        return [classroom_manager::table_count($room), self::PERSONS_CLASSROOM];
    }

    /**
     * Legacy name-based defaults when no classroom is bound.
     *
     * @return array{0:int,1:int} [desks, persons_per_desk]
     */
    public static function default_capacity_for_classroom(string $classroomname): array {
        $name = \core_text::strtolower(trim($classroomname));
        if ($name === '') {
            return [6, 3];
        }
        if (\core_text::strpos($name, '維修') !== false) {
            return [2, 3];
        }
        if (\core_text::strpos($name, 'repair') !== false || \core_text::strpos($name, 'maintenance') !== false) {
            return [2, 3];
        }
        if (\core_text::strpos($name, '手臂') !== false || \core_text::strpos($name, 'arm') !== false) {
            return [6, 3];
        }
        return [6, 3];
    }

    /**
     * Configurable max training hours per day for physical auto mode.
     */
    public static function get_physical_daily_limit(): float {
        $raw = get_config('local_tm_course', 'physical_daily_limit');
        $v = is_numeric($raw) ? (float)$raw : 7.0;
        return max(1.0, min(24.0, $v));
    }

    /**
     * Configurable latest end-time for online reservation scheduling (HH:MM).
     */
    public static function get_online_day_end_hhmm(): string {
        $raw = trim((string)get_config('local_tm_course', 'online_day_end_time'));
        if (!preg_match('/^\d{2}:\d{2}$/', $raw)) {
            return '22:30';
        }
        $h = (int)substr($raw, 0, 2);
        $m = (int)substr($raw, 3, 2);
        if ($h < 0 || $h > 23 || !in_array($m, [0, 30], true)) {
            return '22:30';
        }
        return sprintf('%02d:%02d', $h, $m);
    }

    /**
     * @param int $ts Unix timestamp
     */
    public static function is_weekend_timestamp(int $ts): bool {
        $w = (int) date('w', $ts);
        return $w === 0 || $w === 6;
    }

    /**
     * Next weekday at or after $ts (optionally snapped to 09:30 that day).
     * When not snapping to 09:30, the original clock time is preserved across weekend skips.
     */
    public static function next_weekday_timestamp(int $ts, bool $at0930 = true): int {
        $cursor = (int) $ts;
        $hm = date('H:i:s', $cursor);
        if ($at0930) {
            $hm = '09:30:00';
            $cursor = (int) strtotime(date('Y-m-d', $cursor) . ' ' . $hm);
        }
        $guard = 0;
        while (self::is_weekend_timestamp($cursor) && $guard < 14) {
            $nextday = (int) strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $cursor)));
            $cursor = (int) strtotime(date('Y-m-d', $nextday) . ' ' . $hm);
            $guard++;
        }
        return $cursor;
    }

    /**
     * Move off weekends but keep the selected clock time (for admin edit / chain scheduling).
     */
    public static function ensure_weekday_preserve_time(int $ts): int {
        $cursor = (int) $ts;
        $hour = (int) date('G', $cursor);
        $minute = ((int) date('i', $cursor) >= 30) ? 30 : 0;
        $guard = 0;
        while (self::is_weekend_timestamp($cursor) && $guard < 14) {
            $cursor = (int) strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $cursor)));
            $cursor = (int) strtotime(date('Y-m-d', $cursor) . sprintf(' %02d:%02d:00', $hour, $minute));
            $guard++;
        }
        return $cursor;
    }

    /**
     * True when any calendar day touched by [start, end) is Saturday or Sunday.
     */
    public static function interval_spans_weekend(int $start, int $end): bool {
        if ($end <= $start) {
            return false;
        }
        $cursor = (int) strtotime(date('Y-m-d 00:00:00', $start));
        $lastday = (int) strtotime(date('Y-m-d 00:00:00', $end - 1));
        while ($cursor <= $lastday) {
            if (self::is_weekend_timestamp($cursor)) {
                return true;
            }
            $cursor = (int) strtotime('+1 day', $cursor);
        }
        return false;
    }

    /**
     * Clock-hours of room occupancy on a calendar day (sessions, room-closed blocks, plan blocks).
     * Used against physical daily limit — room-closed time counts like a course block.
     */
    public static function get_physical_day_used_hours(array $intervals, string $dateymd): float {
        $daystart = strtotime($dateymd . ' 00:00:00');
        if ($daystart === false) {
            return 0.0;
        }
        $dayend = strtotime($dateymd . ' 23:59:59');
        $used = 0.0;
        foreach ($intervals as $itv) {
            $s = (int) ($itv['start'] ?? 0);
            $e = (int) ($itv['end'] ?? 0);
            if ($e <= $s) {
                continue;
            }
            $clipstart = max((int) $daystart, $s);
            $clipend = min((int) $dayend, $e);
            if ($clipend > $clipstart) {
                $used += ($clipend - $clipstart) / HOURSECS;
            }
        }
        return $used;
    }

    /**
     * Round a Unix timestamp to the nearest 30-minute boundary (same day, or next day if past midnight).
     */
    public static function round_unix_half_hour(int $ts): int {
        $d = date('Y-m-d', $ts);
        $h = (int) date('G', $ts);
        $i = (int) date('i', $ts);
        $tot = $h * 60 + $i;
        $r = (int) (round($tot / 30) * 30);
        if ($r >= 1440) {
            $mid = strtotime($d . ' 00:00:00');
            $d = date('Y-m-d', strtotime('+1 day', $mid));
            $r = 0;
        }
        return (int) strtotime($d . ' ' . sprintf('%02d:%02d:00', intdiv($r, 60), $r % 60));
    }

    /**
     * Latest end time for any session occupying a classroom on the given calendar day.
     * Used for “chain after previous session” scheduling in admin edit.
     *
     * @return int|null Unix end time, or null when no sessions that day.
     */
    public static function get_classroom_last_end_on_date(int $classroomid, string $dateymd, ?int $ignoreid = null): ?int {
        global $DB;

        if ($classroomid <= 0 || trim($dateymd) === '') {
            return null;
        }

        $daystart = strtotime($dateymd . ' 00:00:00');
        if ($daystart === false) {
            return null;
        }
        $dayend = strtotime($dateymd . ' 23:59:59');

        $sql = "SELECT MAX(endtime) AS lastend
                  FROM {local_tm_course_sessions}
                 WHERE classroomid = :classroomid
                   AND starttime >= :daystart
                   AND starttime <= :dayend";
        $params = [
            'classroomid' => $classroomid,
            'daystart' => (int) $daystart,
            'dayend' => (int) $dayend,
        ];
        if (!empty($ignoreid)) {
            $sql .= ' AND id <> :ignoreid';
            $params['ignoreid'] = (int) $ignoreid;
        }

        $row = $DB->get_record_sql($sql, $params, IGNORE_MISSING);
        if (!$row || empty($row->lastend)) {
            return null;
        }
        return (int) $row->lastend;
    }

    /**
     * Latest end among intervals whose start falls on the given calendar day (Y-m-d).
     *
     * @param array<int,array{start:int,end:int}> $intervals
     */
    public static function get_last_interval_end_on_date(array $intervals, string $dateymd): ?int {
        $daystart = strtotime($dateymd . ' 00:00:00');
        if ($daystart === false) {
            return null;
        }
        $dayend = strtotime($dateymd . ' 23:59:59');
        $max = null;
        foreach ($intervals as $itv) {
            $s = (int) ($itv['start'] ?? 0);
            $e = (int) ($itv['end'] ?? 0);
            if ($s >= $daystart && $s <= $dayend && $e > $s) {
                if ($max === null || $e > $max) {
                    $max = $e;
                }
            }
        }
        return $max;
    }

    /**
     * When a same-room day already has a single block longer than this (clock hours),
     * auto-plan skips chaining and starts the next course on the following weekday.
     */
    public const ONSITE_SKIP_CHAIN_AFTER_LONG_BLOCK_HOURS = 4.0;

    /**
     * True when any single interval clipped to $dateymd exceeds $minhours (clock hours).
     *
     * @param array<int,array{start:int,end:int}> $intervals
     */
    public static function room_day_has_long_block(array $intervals, string $dateymd, float $minhours = 4.0): bool {
        $daystart = strtotime($dateymd . ' 00:00:00');
        if ($daystart === false) {
            return false;
        }
        $dayend = strtotime($dateymd . ' 23:59:59');
        foreach ($intervals as $itv) {
            $s = (int) ($itv['start'] ?? 0);
            $e = (int) ($itv['end'] ?? 0);
            if ($e <= $s) {
                continue;
            }
            $clipstart = max((int) $daystart, $s);
            $clipend = min((int) $dayend, $e);
            if ($clipend > $clipstart && (($clipend - $clipstart) / HOURSECS) > $minhours) {
                return true;
            }
        }
        return false;
    }

    /**
     * Start time for a reservation plan block: chain after last occupancy on the cursor day, else 09:30.
     *
     * @param array<int,array{start:int,end:int}> $roomintervals Existing sessions + prior plan blocks in same room.
     */
    public static function suggest_reservation_block_start(int $cursor, array $roomintervals = []): int {
        $cursor = (int) $cursor;
        if ($cursor <= 0) {
            return 0;
        }
        if (self::is_weekend_timestamp($cursor)) {
            $cursor = self::next_weekday_timestamp($cursor, true);
        }
        $dateymd = date('Y-m-d', $cursor);
        $dailylimit = self::get_physical_daily_limit();
        $usedhours = self::get_physical_day_used_hours($roomintervals, $dateymd);

        // Same room already has a long (>4h) block that day — do not squeeze a scrap at the end.
        if (self::room_day_has_long_block($roomintervals, $dateymd, self::ONSITE_SKIP_CHAIN_AFTER_LONG_BLOCK_HOURS)) {
            return self::next_weekday_timestamp(strtotime('+1 day', strtotime($dateymd . ' 00:00:00')), true);
        }

        $daystart0930 = (int) strtotime($dateymd . ' 09:30:00');
        $lastend = self::get_last_interval_end_on_date($roomintervals, $dateymd);
        if ($lastend !== null && $lastend > 0 && $usedhours < $dailylimit) {
            $candidate = self::round_unix_half_hour($lastend);
            if (!self::is_weekend_timestamp($candidate)) {
                return $candidate;
            }
        }

        // Day already at training-hour cap: move to the next weekday 09:30.
        if ($usedhours >= $dailylimit) {
            return self::next_weekday_timestamp(strtotime('+1 day', strtotime($dateymd . ' 00:00:00')), true);
        }

        // Open day with no prior block on this date: keep applicant cursor (preferred date/time), not tomorrow.
        $candidate = self::round_unix_half_hour(max($cursor, $daystart0930));
        return self::ensure_weekday_preserve_time($candidate);
    }

    /**
     * Teaching hours an onsite auto-mode block consumes on its first calendar day.
     * Multi-day courses are split by physical_daily_limit; lunch is clock padding only.
     */
    public static function get_onsite_first_day_teaching_hours(float $totalteaching, ?float $dailylimit = null): float {
        $limit = $dailylimit !== null ? (float)$dailylimit : self::get_physical_daily_limit();
        $limit = max(0.01, $limit);
        return min(max(0.0, $totalteaching), $limit);
    }

    /**
     * Build onsite reservation plan interval (chain start + course duration rules).
     *
     * @param array<int,array{start:int,end:int}> $roomintervals
     * @return array{starttime:int,endtime:int,teaching_hours:float,nextcursor:int}
     */
    public static function build_reservation_onsite_block(int $courseid, int $cursor, array $roomintervals = []): array {
        $seedcursor = (int)$cursor;
        $attempts = 0;
        $dailylimit = self::get_physical_daily_limit();
        while ($attempts < 60) {
            $start = self::suggest_reservation_block_start($seedcursor, $roomintervals);
            $calc = self::calculate_session_times($courseid, self::DELIVERY_ONSITE, $start, true);
            $starttime = (int)$calc['starttime'];
            $endtime = (int)$calc['endtime'];
            $teachinghours = (float) ($calc['teaching_hours'] ?? $calc['total_hours']);
            $dateymd = date('Y-m-d', $starttime);
            // Compare training hours (not overnight clock span of multi-day blocks, and not lunch).
            $usedhours = min(self::get_physical_day_used_hours($roomintervals, $dateymd), $dailylimit);
            $blockdayteaching = self::get_onsite_first_day_teaching_hours($teachinghours, $dailylimit);
            if (($usedhours + $blockdayteaching) <= ($dailylimit + 0.0001)) {
                return [
                    'starttime' => $starttime,
                    'endtime' => $endtime,
                    'teaching_hours' => $teachinghours,
                    'nextcursor' => $endtime,
                ];
            }
            $nextday = strtotime('+1 day', strtotime($dateymd . ' 00:00:00'));
            $seedcursor = self::next_weekday_timestamp((int)$nextday, true);
            $attempts++;
        }

        throw new \moodle_exception('error_reservation_onsite_no_slot', 'local_tm_course');
    }

    /**
     * Lunch padding (hours) for an onsite day segment: 1h when the teaching interval
     * crosses the midday lunch window, otherwise 0 (short / afternoon-only blocks).
     */
    public static function onsite_segment_lunch_hours(int $segstart, float $teachinghours): float {
        if ($teachinghours <= 0.0) {
            return 0.0;
        }
        $lunchmark = (int) strtotime(date('Y-m-d', $segstart) . ' 12:30:00');
        $teachingend = (int) round($segstart + ($teachinghours * HOURSECS));
        return ($segstart <= $lunchmark && $teachingend > $lunchmark) ? 1.0 : 0.0;
    }

    /**
     * Split an onsite course into per-day segments, each within the physical daily limit.
     *
     * The first day may chain after existing same-room occupancy on the seed day (respecting
     * remaining daily capacity); subsequent days start at 09:30. Weekends are skipped. Lunch
     * (1h) is added to any day whose teaching interval crosses midday.
     *
     * @param array<int,array{start:int,end:int}> $roomintervals Existing occupancy for first-day chaining/capacity.
     * @return array{segments:array<int,array{start:int,end:int,teachinghours:float}>,nextcursor:int}
     */
    public static function plan_onsite_course_segments(int $courseid, int $cursor, array $roomintervals = []): array {
        $dailylimit = self::get_physical_daily_limit();
        $total = max(0.0, (float) enabled_course_manager::get_default_duration_hours($courseid, self::DELIVERY_ONSITE));
        if ($total <= 0.0) {
            throw new \moodle_exception('error_hours_positive', 'local_tm_course');
        }

        $seed = (int) $cursor;
        $attempts = 0;
        while ($attempts < 120) {
            $segments = [];
            $remaining = $total;
            $firstday = true;
            $start = self::suggest_reservation_block_start($seed, $roomintervals);
            $daycursor = (int) $start;
            $safety = 0;
            while ($remaining > 0.0001 && $safety < 60) {
                $safety++;
                if (self::is_weekend_timestamp($daycursor)) {
                    $daycursor = self::next_weekday_timestamp($daycursor, !$firstday);
                }
                $dateymd = date('Y-m-d', $daycursor);
                $used = $firstday
                    ? min(self::get_physical_day_used_hours($roomintervals, $dateymd), $dailylimit)
                    : 0.0;
                $cap = $dailylimit - $used;
                // First day of a NEW course: if this room already has a long (>4h) block, skip scraps.
                if ($firstday && self::room_day_has_long_block(
                    $roomintervals,
                    $dateymd,
                    self::ONSITE_SKIP_CHAIN_AFTER_LONG_BLOCK_HOURS
                )) {
                    $daycursor = self::next_weekday_timestamp(
                        strtotime('+1 day', strtotime($dateymd . ' 00:00:00')),
                        true
                    );
                    $firstday = false;
                    continue;
                }
                if ($cap < 0.5) {
                    // Day essentially full — move to next weekday 09:30.
                    $daycursor = self::next_weekday_timestamp(
                        strtotime('+1 day', strtotime($dateymd . ' 00:00:00')),
                        true
                    );
                    $firstday = false;
                    continue;
                }
                if ($firstday) {
                    $segstart = self::round_unix_half_hour($daycursor);
                    $day0930 = (int) strtotime($dateymd . ' 09:30:00');
                    if ($segstart < $day0930) {
                        $segstart = $day0930;
                    }
                } else {
                    $segstart = (int) strtotime($dateymd . ' 09:30:00');
                }
                $dayteaching = min($remaining, $cap);
                $lunch = self::onsite_segment_lunch_hours($segstart, $dayteaching);
                $segend = (int) round($segstart + (($dayteaching + $lunch) * HOURSECS));
                $segments[] = [
                    'start' => (int) $segstart,
                    'end' => (int) $segend,
                    'teachinghours' => (float) $dayteaching,
                ];
                $remaining -= $dayteaching;
                $firstday = false;
                if ($remaining > 0.0001) {
                    $daycursor = self::next_weekday_timestamp(
                        strtotime('+1 day', strtotime($dateymd . ' 00:00:00')),
                        true
                    );
                }
            }

            if ($remaining <= 0.0001 && !empty($segments)) {
                $last = end($segments);
                return ['segments' => $segments, 'nextcursor' => (int) $last['end']];
            }

            $seed = self::next_weekday_timestamp(
                strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', (int) $start))),
                true
            );
            $attempts++;
        }

        throw new \moodle_exception('error_reservation_onsite_no_slot', 'local_tm_course');
    }

    /**
     * Suggested start when chaining after the last session in a classroom on a date.
     *
     * @return int Unix start (last session end, or 09:30 when none).
     */
    public static function suggest_chain_start_time(int $classroomid, string $dateymd, ?int $ignoreid = null): int {
        global $DB;

        $daystart = strtotime($dateymd . ' 00:00:00');
        if ($daystart === false) {
            return 0;
        }
        $dayend = strtotime($dateymd . ' 23:59:59');
        $intervals = [];
        if ($classroomid > 0) {
            $sql = "SELECT starttime, endtime
                      FROM {local_tm_course_sessions}
                     WHERE classroomid = :classroomid
                       AND starttime >= :daystart
                       AND starttime <= :dayend";
            $params = [
                'classroomid' => $classroomid,
                'daystart' => (int) $daystart,
                'dayend' => (int) $dayend,
            ];
            if (!empty($ignoreid)) {
                $sql .= ' AND id <> :ignoreid';
                $params['ignoreid'] = (int) $ignoreid;
            }
            $rows = $DB->get_records_sql($sql, $params);
            foreach ($rows as $row) {
                $intervals[] = [
                    'start' => (int) $row->starttime,
                    'end' => (int) $row->endtime,
                ];
            }
        }

        $dailylimit = self::get_physical_daily_limit();
        $usedhours = self::get_physical_day_used_hours($intervals, $dateymd);
        $lastend = self::get_last_interval_end_on_date($intervals, $dateymd);
        if ($lastend !== null && $lastend > 0 && $usedhours < $dailylimit) {
            return self::round_unix_half_hour($lastend);
        }
        if ($usedhours >= $dailylimit) {
            return self::next_weekday_timestamp(strtotime('+1 day', (int) $daystart), true);
        }
        return (int) strtotime($dateymd . ' 09:30:00');
    }

    /**
     * Calculate auto-mode session start/end timestamps.
     *
     * @param bool $respectstarttime When true (admin physical edit), keep the selected start time
     *                               instead of resetting to 09:30. Continuation days still begin at 09:30.
     * @return array{starttime:int,endtime:int,total_hours:float,daily_limit:float,teaching_hours:float}
     */
    public static function calculate_session_times(
        int $courseid,
        string $type,
        int $startts,
        bool $respectstarttime = false
    ): array {
        $type = clean_param($type, PARAM_ALPHANUMEXT);
        $totalhours = enabled_course_manager::get_default_duration_hours($courseid, $type);
        $start = (int)$startts;
        $dailylimit = self::get_physical_daily_limit();
        $lunchhours = 1.0;

        if ($type === self::DELIVERY_ONSITE) {
            $attempts = 0;
            while ($attempts < 60) {
                $attempts++;
                if ($respectstarttime) {
                    $start = self::round_unix_half_hour($start);
                    $start = self::ensure_weekday_preserve_time($start);
                } else {
                    $startdate = date('Y-m-d', $start);
                    $start = (int) strtotime($startdate . ' 09:30:00');
                    $start = self::next_weekday_timestamp($start, true);
                }

                $remaining = (float) $totalhours;
                $cursor = $start;
                while ($remaining > $dailylimit) {
                    $remaining -= $dailylimit;
                    $nextday = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $cursor)));
                    $cursor = self::next_weekday_timestamp((int) $nextday, true);
                }
                $end = (int) round($cursor + (($remaining + $lunchhours) * HOURSECS));

                if (!self::interval_spans_weekend($start, $end)) {
                    return [
                        'starttime' => $start,
                        'endtime' => $end,
                        'total_hours' => $totalhours,
                        'teaching_hours' => $totalhours,
                        'daily_limit' => $dailylimit,
                    ];
                }

                // Entire block moves to the next weekday (no teaching on weekends).
                $start = self::next_weekday_timestamp(
                    strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $start))),
                    true
                );
            }

            throw new \moodle_exception('error_reservation_onsite_no_slot', 'local_tm_course');
        }

        // Virtual course: no per-day limit.
        $end = (int)round($start + ($totalhours * HOURSECS));
        return [
            'starttime' => $start,
            'endtime' => $end,
            'total_hours' => $totalhours,
            'teaching_hours' => $totalhours,
            'daily_limit' => $dailylimit,
        ];
    }
}
