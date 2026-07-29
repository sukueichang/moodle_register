<?php
/**
 * Attendance Manager — M3 logic
 * Handles Moodle Group creation, mod_attendance sync,
 * attendance marking, and course completion trigger.
 *
 * @package    local_tm_course
 * @copyright  2024 Techman Robot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class attendance_manager {

    // Attendance values stored in local_tm_course_enrolments.attended
    const ATTEND_UNSET   = 0;   // Not yet recorded
    const ATTEND_PRESENT = 1;   // Present / attended
    const ATTEND_ABSENT  = 2;   // Absent / not attended
    const COURSE_ATTENDANCE_NAME = '課程出缺席';

    /**
     * course_modules.idnumber for the mod_attendance activity TM creates and reuses (one per course).
     * Do not reuse other attendance activities in the course unless they carry this idnumber (or legacy adopt below).
     */
    public const TM_ATTENDANCE_CM_IDNUMBER = 'local_tm_course_attendance';

    // ----------------------------------------------------------------
    // Availability check
    // ----------------------------------------------------------------

    /**
     * Check whether mod_attendance plugin tables exist on this server.
     * Used to conditionally enable deep attendance sync.
     */
    public static function is_mod_attendance_installed(): bool {
        global $DB;
        static $checked = null;
        if ($checked === null) {
            $checked = $DB->get_manager()->table_exists('attendance');
        }
        return $checked;
    }

    // ----------------------------------------------------------------
    // Group & Attendance setup
    // ----------------------------------------------------------------

    /**
     * Set up the Moodle Group (and optionally mod_attendance) for a session.
     * Idempotent — safe to call multiple times; will not duplicate resources.
     *
     * @param  int  $sessionid   local_tm_course_sessions.id
     * @throws \moodle_exception If the session or its course cannot be found.
     */
    public static function setup_session(int $sessionid): void {
        global $DB;

        $session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid], '*', MUST_EXIST);

        // Classroom-only blocks: no Moodle group / attendance (matches session_manager::SESSION_KIND_ROOM_CLOSED).
        if (isset($session->session_kind) && (string)$session->session_kind === 'room_closed') {
            return;
        }

        $course = $DB->get_record('course', ['id' => $session->courseid]);

        if (!$course) {
            throw new \moodle_exception('error_course_not_found', 'local_tm_course');
        }

        // 1. Ensure Moodle Group exists
        $groupid = self::ensure_group($session, $course);

        // 2. If mod_attendance is installed, create the activity + session slot
        $attendance_cmid       = (int)($session->attendance_cmid ?? 0);
        $attendance_sessionid  = (int)($session->attendance_sessionid ?? 0);

        if (self::is_mod_attendance_installed()) {
            try {
                $att = self::ensure_attendance_activity($session, $course);
                $attendance_cmid      = $att['cmid'];
                $attendance_sessionid = $att['sessionid'];
            } catch (\Throwable $e) {
                // mod_attendance integration failed — group is still created.
                // Attendance will be tracked in local table only.
                debugging('TM Course: mod_attendance setup failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                error_log('TM Course: mod_attendance setup failed: ' . $e->getMessage());
            }
        }

        // 3. Persist back to session row
        $update = new \stdClass();
        $update->id                   = $sessionid;
        $update->groupid              = $groupid;
        $update->attendance_cmid      = $attendance_cmid;
        $update->attendance_sessionid = $attendance_sessionid;
        $update->timemodified         = time();
        $DB->update_record('local_tm_course_sessions', $update);

        // 4. Add all currently-approved students to the group
        $approved = $DB->get_records('local_tm_course_enrolments', [
            'sessionid' => $sessionid,
            'status'    => session_manager::ENROL_APPROVED,
        ]);
        foreach ($approved as $enrol) {
            self::add_to_group_by_ids($groupid, (int)$enrol->userid);
        }
    }

    /**
     * Remove Moodle resources linked to a TM session:
     * - group
     * - attendance session slot
     *
     * Safe to call repeatedly.
     */
    public static function cleanup_session_resources(\stdClass $session): void {
        global $DB, $CFG;

        $groupid = (int)($session->groupid ?? 0);
        if ($groupid > 0 && $DB->record_exists('groups', ['id' => $groupid])) {
            require_once($CFG->dirroot . '/group/lib.php');
            \groups_delete_group($groupid);
        }

        $attsessionid = (int)($session->attendance_sessionid ?? 0);
        if ($attsessionid > 0 && $DB->record_exists('attendance_sessions', ['id' => $attsessionid])) {
            // Remove linked attendance logs first.
            $DB->delete_records('attendance_log', ['sessionid' => $attsessionid]);
            $DB->delete_records('attendance_sessions', ['id' => $attsessionid]);
        }
    }

    /**
     * Find or create the Moodle Group for this session.
     * Group name pattern: "[TM] <session_name> (<date>)"
     *
     * @return int  The group id.
     */
    private static function ensure_group(\stdClass $session, \stdClass $course): int {
        global $DB, $CFG;

        // If already linked, verify the group still exists
        if (!empty($session->groupid)) {
            if ($DB->record_exists('groups', ['id' => $session->groupid, 'courseid' => $course->id])) {
                return (int)$session->groupid;
            }
        }

        // Build a unique-enough group name
        $date_str  = date('Y-m-d', (int)$session->starttime);
        $groupname = '[TM] ' . $session->name . ' (' . $date_str . ')';

        // Look for an existing group with same name in this course
        $existing = $DB->get_record('groups', [
            'courseid' => $course->id,
            'name'     => $groupname,
        ]);
        if ($existing) {
            return (int)$existing->id;
        }

        // Create new group — use $CFG->dirroot to avoid namespace path issues
        require_once($CFG->dirroot . '/group/lib.php');

        $group              = new \stdClass();
        $group->courseid    = $course->id;
        $group->name        = $groupname;
        $group->description = 'Auto-created by TM Course Management for session: ' . $session->name;
        $group->timecreated = time();
        $group->timemodified= time();

        return (int)\groups_create_group($group);
    }

    /**
     * The single TM-managed mod_attendance row for a course, if any.
     *
     * Resolution: course module with {@see TM_ATTENDANCE_CM_IDNUMBER}. If none, legacy adopt when the course has
     * exactly one attendance cm with empty idnumber whose instance title equals {@see COURSE_ATTENDANCE_NAME}
     * (older plugin builds); otherwise returns null and {@see ensure_attendance_activity()} will create one.
     *
     * @return \stdClass|null  Row from {attendance}
     */
    private static function find_tm_managed_attendance_instance(int $courseid): ?\stdClass {
        global $DB, $CFG;

        if ($courseid <= 0 || !self::is_mod_attendance_installed()) {
            return null;
        }

        $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'attendance']);
        if (!$moduleid) {
            return null;
        }

        $rows = $DB->get_records_sql(
            "SELECT a.*
               FROM {attendance} a
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
              WHERE a.course = :cid
                AND cm.module = :mid
                AND cm.deletioninprogress = 0
                AND cm.idnumber = :idn
           ORDER BY cm.id ASC",
            [
                'cid' => $courseid,
                'mid' => $moduleid,
                'idn' => self::TM_ATTENDANCE_CM_IDNUMBER,
            ],
            0,
            1
        );
        $row = reset($rows);
        if ($row) {
            return $row;
        }

        // Legacy: single unnamed cm + default title — tag idnumber and reuse.
        $legacycms = $DB->get_records_sql(
            "SELECT cm.id AS cmid, cm.instance
               FROM {course_modules} cm
               JOIN {attendance} a ON a.id = cm.instance AND a.course = cm.course
              WHERE cm.course = :cid
                AND cm.module = :mid
                AND cm.deletioninprogress = 0
                AND (cm.idnumber = :empty OR cm.idnumber IS NULL)
                AND a.name = :aname
           ORDER BY cm.id ASC",
            [
                'cid' => $courseid,
                'mid' => $moduleid,
                'empty' => '',
                'aname' => self::COURSE_ATTENDANCE_NAME,
            ]
        );
        if (count($legacycms) !== 1) {
            return null;
        }
        $leg = reset($legacycms);
        $DB->set_field('course_modules', 'idnumber', self::TM_ATTENDANCE_CM_IDNUMBER, ['id' => (int)$leg->cmid]);
        require_once($CFG->dirroot . '/lib/moodlelib.php');
        \rebuild_course_cache($courseid);

        return $DB->get_record('attendance', ['id' => (int)$leg->instance], '*', IGNORE_MISSING);
    }

    /**
     * Ensure mod_attendance activity and session slot exist for this session.
     * Returns ['cmid' => int, 'sessionid' => int].
     */
    private static function ensure_attendance_activity(\stdClass $session, \stdClass $course): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/attendance/lib.php');

        $courseid = (int)$course->id;

        // --- Find or create the TM-owned attendance module instance (never bind to teacher-made activities). ---
        $attendance_instance = self::find_tm_managed_attendance_instance($courseid);

        // Get attendance module ID from modules table
        $module_id = (int)$DB->get_field('modules', 'id', ['name' => 'attendance']);
        if (!$module_id) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '',
                'mod_attendance not found in modules table');
        }

        $cmid = 0;

        // Create a new attendance activity via direct DB inserts (avoids add_moduleinfo dependency)
        if (!$attendance_instance) {
            // 1. Insert into {attendance}
            $att                    = new \stdClass();
            $att->course            = $courseid;
            $att->name              = self::COURSE_ATTENDANCE_NAME;
            $att->intro             = '';
            $att->introformat       = FORMAT_HTML;
            $att->grade             = 100;
            $att->timemodified      = time();
            $att->sessiondetailspos      = 'left';
            $att->showextrauserdetails   = 1;
            $att->showsessiondetails     = 1;
            $att->studentscanmark        = 0;
            $att->autoassignstatus       = 0;
            $att->subnet                 = '';
            $att->studentpassword        = '';
            $att->strictparticipation    = 0;
            $att->completionattendance   = 0;
            $attendanceid = $DB->insert_record('attendance', $att);

            // Section 0 row in {course_sections}; course_modules.section must be that row's id, not 0.
            $sectionrec = $DB->get_record('course_sections',
                ['course' => $courseid, 'section' => 0], '*', MUST_EXIST);

            // 2. Insert into {course_modules}
            $cm                      = new \stdClass();
            $cm->course              = $courseid;
            $cm->module              = $module_id;
            $cm->instance            = $attendanceid;
            $cm->section             = (int)$sectionrec->id;
            $cm->idnumber            = self::TM_ATTENDANCE_CM_IDNUMBER;
            $cm->added               = time();
            $cm->score               = 0;
            $cm->indent              = 0;
            $cm->visible             = 1;
            $cm->visibleoncoursepage = 1;
            $cm->visibleold          = 1;
            // Default to separate groups when auto-creating attendance activity.
            $cm->groupmode           = 1;
            $cm->groupingid          = 0;
            $cm->completion          = 0;
            $cm->completionview      = 0;
            $cm->completionexpected  = 0;
            $cm->showdescription     = 0;
            $cm->deletioninprogress  = 0;
            $cmid = (int)$DB->insert_record('course_modules', $cm);

            // 3. Append cmid to section 0 sequence
            $seq = trim($sectionrec->sequence, ',');
            $seq = $seq === '' ? "$cmid" : "$seq,$cmid";
            $DB->set_field('course_sections', 'sequence', $seq, ['id' => $sectionrec->id]);

            // 4. Rebuild course cache
            \rebuild_course_cache($courseid);

            $attendance_instance = $DB->get_record('attendance',
                ['id' => $attendanceid], '*', MUST_EXIST);
        } else {
            $cmid = (int)($DB->get_field('course_modules', 'id', [
                'course'   => $courseid,
                'module'   => $module_id,
                'instance' => $attendance_instance->id,
                'idnumber' => self::TM_ATTENDANCE_CM_IDNUMBER,
                'deletioninprogress' => 0,
            ]) ?: 0);
        }

        // --- Find or create the attendance SESSION SLOT for this date ---
        $slot_date  = (int)$session->starttime;
        $slot_end   = (int)$session->endtime;
        $slot_desc  = $session->name;

        // Look for an existing slot: TM-linked id, or same start time + same name (idempotent setup_session).
        $existing_slot = null;
        if (!empty($session->attendance_sessionid)) {
            $existing_slot = $DB->get_record('attendance_sessions', [
                'id'           => $session->attendance_sessionid,
                'attendanceid' => $attendance_instance->id,
            ]);
        }
        if (!$existing_slot) {
            // Match same start instant + same description only (idempotent re-run of setup_session).
            // Do not reuse "any slot with same sessdate" — that hid new TM sessions behind an old slot
            // and made it look like no new period was created.
            $existing_slot = $DB->get_record_select('attendance_sessions',
                'attendanceid = :aid AND sessdate = :sd AND description = :desc',
                ['aid' => $attendance_instance->id, 'sd' => $slot_date, 'desc' => $slot_desc],
                '*',
                IGNORE_MISSING
            );
        }

        // Make sure active statuses exist, then align existing sets to TM preset when safe.
        self::ensure_attendance_statuses((int)$attendance_instance->id);
        self::align_attendance_status_set_for_tm((int)$attendance_instance->id);

        if ($existing_slot) {
            $att_sessionid = (int)$existing_slot->id;
        } else {
            // Create the slot
            $slot                  = new \stdClass();
            $slot->attendanceid    = $attendance_instance->id;
            $slot->groupid         = 0; // all groups
            $slot->sessdate        = $slot_date;
            $slot->duration        = max(0, $slot_end - $slot_date);
            $slot->lasttaken       = 0;
            $slot->lasttakenby     = 0;
            $slot->timemodified    = time();
            $slot->description     = $slot_desc;
            $slot->descriptionformat = FORMAT_HTML;
            $slot->studentscanmark = 0;
            $slot->autoassignstatus= 0;
            $slot->subnet          = '';
            $slot->studentpassword = '';
            $slot->includeqrcode   = 0;
            $att_sessionid = (int)$DB->insert_record('attendance_sessions', $slot);

        }

        return ['cmid' => (int)$cmid, 'sessionid' => $att_sessionid];
    }

    /**
     * Find mod_attendance activity instance + course module for the linked course.
     * Uses only the TM-managed activity ({@see TM_ATTENDANCE_CM_IDNUMBER} / {@see find_tm_managed_attendance_instance}).
     *
     * @return array{instance:int, cmid:int}|null
     */
    private static function resolve_attendance_activity_for_course(int $courseid): ?array {
        global $DB;

        $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'attendance']);
        if (!$moduleid) {
            return null;
        }

        $reuse = self::find_tm_managed_attendance_instance($courseid);
        if (!$reuse) {
            return null;
        }

        $instanceid = (int)$reuse->id;
        $cmid = (int)($DB->get_field('course_modules', 'id', [
            'course' => $courseid,
            'module' => $moduleid,
            'instance' => $instanceid,
            'idnumber' => self::TM_ATTENDANCE_CM_IDNUMBER,
            'deletioninprogress' => 0,
        ]) ?: 0);

        return ['instance' => $instanceid, 'cmid' => $cmid];
    }

    /**
     * Default mod_attendance status rows used by TM (acronym / English description / grade / setunmarked).
     * Insert order: Pr, La, Ab — matches typical take.php column layout.
     */
    private const TM_ATTENDANCE_STATUS_PRESET = [
        ['acronym' => 'Pr', 'description' => 'Present', 'grade' => 2, 'setunmarked' => 0],
        ['acronym' => 'La', 'description' => 'Late',    'grade' => 1, 'setunmarked' => 0],
        ['acronym' => 'Ab', 'description' => 'Absent',  'grade' => 0, 'setunmarked' => 1],
    ];

    /**
     * Insert {@see TM_ATTENDANCE_STATUS_PRESET} rows for one attendance activity.
     */
    private static function insert_default_tm_attendance_status_rows(int $attendanceid): void {
        global $DB;

        $cols = $DB->get_columns('attendance_statuses');
        $sortorder = 0;
        foreach (self::TM_ATTENDANCE_STATUS_PRESET as $s) {
            $rec               = new \stdClass();
            $rec->attendanceid = $attendanceid;
            $rec->acronym      = $s['acronym'];
            $rec->description  = $s['description'];
            $rec->grade        = $s['grade'];
            $rec->visible      = 1;
            $rec->deleted      = 0;
            $rec->setunmarked  = $s['setunmarked'];
            if (array_key_exists('sortorder', $cols)) {
                $rec->sortorder = $sortorder;
            }
            $sortorder++;

            try {
                $DB->insert_record('attendance_statuses', $rec);
            } catch (\Throwable $t) {
                // Some attendance builds limit acronym length to 1.
                $rec->acronym = strtoupper(substr((string)$s['acronym'], 0, 1));
                $DB->insert_record('attendance_statuses', $rec);
            }
        }
    }

    /**
     * True if this attendance activity already has any taken marks in attendance_log.
     */
    private static function attendance_activity_has_logs(int $attendanceid): bool {
        global $DB;

        if ($attendanceid <= 0) {
            return false;
        }
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {attendance_log} l
               JOIN {attendance_sessions} s ON s.id = l.sessionid
              WHERE s.attendanceid = ?
           LIMIT 1",
            [$attendanceid]
        );
    }

    /**
     * True when there are exactly three active statuses with acronyms Pr, La, Ab (case-insensitive).
     */
    private static function attendance_statuses_match_tm_preset(int $attendanceid): bool {
        global $DB;

        $rows = $DB->get_records('attendance_statuses', [
            'attendanceid' => $attendanceid,
            'deleted' => 0,
        ], 'id ASC');
        if (count($rows) !== 3) {
            return false;
        }
        $acr = [];
        foreach ($rows as $r) {
            $acr[] = \core_text::strtolower(trim((string)($r->acronym ?? '')));
        }
        sort($acr);
        return $acr === ['ab', 'la', 'pr'];
    }

    /**
     * Force mod_attendance status set to TM preset (Pr / La / Ab) when safe.
     *
     * If the activity already has {@see attendance_log} rows, we do **not** replace statuses (statusids on
     * logs would keep old semantics). In that case site admins should align manually in Moodle or clear logs
     * on a test course; a debugging() notice is emitted once per align attempt.
     */
    private static function align_attendance_status_set_for_tm(int $attendanceid): void {
        global $DB;

        static $logskipwarned = [];

        if ($attendanceid <= 0) {
            return;
        }
        if (self::attendance_statuses_match_tm_preset($attendanceid)) {
            return;
        }
        if (self::attendance_activity_has_logs($attendanceid)) {
            if (empty($logskipwarned[$attendanceid])) {
                $logskipwarned[$attendanceid] = true;
                debugging(
                    'local_tm_course: attendance id ' . $attendanceid .
                    ' has existing attendance_log rows; skipped automatic status-set alignment to Pr/La/Ab.',
                    DEBUG_DEVELOPER
                );
            }
            return;
        }

        $DB->execute(
            'UPDATE {attendance_statuses} SET deleted = 1 WHERE attendanceid = ? AND deleted = 0',
            [$attendanceid]
        );
        self::insert_default_tm_attendance_status_rows($attendanceid);
    }

    /**
     * Create default mod_attendance status rows when this activity has none yet.
     *
     * Runs only if {@see attendance_statuses} has no rows with deleted=0 for this attendanceid.
     * For existing activities with a non-matching set, see {@see align_attendance_status_set_for_tm()}.
     *
     * Default status set (acronym / English description / grade / setunmarked):
     * | Acronym | Description | Grade | setunmarked | TM 自動點名對應      |
     * |---------|-------------|-------|---------------|----------------------|
     * | Pr      | Present     | 2     | 0             | 出席 (ATTEND_PRESENT)|
     * | La      | Late        | 1     | 0             | （TM 未使用）        |
     * | Ab      | Absent      | 0     | 1             | 缺席 (ATTEND_ABSENT) |
     *
     * If the DB rejects 2-letter acronyms, insert falls back to single uppercase letter (P, L, A);
     * {@see resolve_status_id_for_attendance()} still resolves P / PR / Pr / A / Ab 等.
     */
    private static function ensure_attendance_statuses(int $attendanceid): void {
        global $DB;

        if ($DB->record_exists('attendance_statuses', [
            'attendanceid' => $attendanceid,
            'deleted' => 0,
        ])) {
            return; // Already has active statuses.
        }

        self::insert_default_tm_attendance_status_rows($attendanceid);
    }

    // ----------------------------------------------------------------
    // Group membership
    // ----------------------------------------------------------------

    /**
     * Add a user to the session's Moodle group.
     * Safe to call if group isn't set up yet (skips gracefully).
     *
     * @param int $sessionid
     * @param int $userid
     */
    public static function add_to_group(int $sessionid, int $userid): void {
        global $DB;

        $groupid = (int)$DB->get_field('local_tm_course_sessions', 'groupid', ['id' => $sessionid]);
        if (!$groupid) {
            return; // Group not set up yet — enrolment manager will call setup later
        }

        self::add_to_group_by_ids($groupid, $userid);
    }

    /**
     * Remove a user from the session's Moodle group.
     * Safe to call if group is not set up yet.
     *
     * @param int $sessionid
     * @param int $userid
     */
    public static function remove_from_group(int $sessionid, int $userid): void {
        global $DB;

        $groupid = (int)$DB->get_field('local_tm_course_sessions', 'groupid', ['id' => $sessionid]);
        if (!$groupid) {
            return;
        }

        self::remove_from_group_by_ids($groupid, $userid);
    }

    /**
     * Low-level helper: add a user to a Moodle group.
     */
    private static function add_to_group_by_ids(int $groupid, int $userid): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        if (!\groups_is_member($groupid, $userid)) {
            \groups_add_member($groupid, $userid);
        }
    }

    /**
     * Low-level helper: remove a user from a Moodle group.
     */
    private static function remove_from_group_by_ids(int $groupid, int $userid): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        if (\groups_is_member($groupid, $userid)) {
            \groups_remove_member($groupid, $userid);
        }
    }

    // ----------------------------------------------------------------
    // Attendance marking
    // ----------------------------------------------------------------

    /**
     * Mark a student as present or absent.
     * Updates local table and optionally syncs to mod_attendance.
     * If marked present, triggers Moodle course completion.
     *
     * @param int  $enrolid   local_tm_course_enrolments.id
     * @param int  $attended  ATTEND_PRESENT or ATTEND_ABSENT
     */
    public static function mark_attended(int $enrolid, int $attended): void {
        global $DB;

        $enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST);

        // Update our own table
        $DB->set_field('local_tm_course_enrolments', 'attended',     $attended, ['id' => $enrolid]);
        $DB->set_field('local_tm_course_enrolments', 'timemodified', time(),    ['id' => $enrolid]);

        // Sync to mod_attendance if installed
        if (self::is_mod_attendance_installed()) {
            // Ensure linkage (activity/slot/statuses) exists before writing attendance_log.
            try {
                self::setup_session((int)$enrol->sessionid);
                // Backfill any previously marked rows (present/absent) into the current attendance slot.
                // This covers scenarios where the course attendance activity was deleted and recreated.
                self::backfill_marked_attendance_for_session((int)$enrol->sessionid);
            } catch (\Throwable $t) {
                error_log('TM Course setup_session before attendance sync failed: ' . $t->getMessage());
            }
            self::sync_to_mod_attendance($enrol, $attended);
        }

        // Trigger course completion when student is marked present
        if ($attended === self::ATTEND_PRESENT) {
            $session = $DB->get_record('local_tm_course_sessions',
                                       ['id' => $enrol->sessionid], '*', MUST_EXIST);
            if (!empty($session->courseid)) {
                self::sync_completion((int)$enrol->userid, (int)$session->courseid);
            }
        }
    }

    /**
     * Moodle user id stored in attendance_log (linked real user for batch placeholders).
     */
    private static function attendance_log_userid(\stdClass $enrol): int {
        $linked = (int)($enrol->linked_userid ?? 0);
        if ($linked > 0) {
            return $linked;
        }
        return (int)$enrol->userid;
    }

    /**
     * Find mod_attendance session slot in the TM-managed activity for this course
     * (sessdate + slot description = TM session name).
     *
     * @return array{sessionid:int, attendanceid:int, cmid:int}|null cmid may be 0 if course_modules row missing
     */
    private static function resolve_attendance_slot_across_course(\stdClass $session, int $courseid): ?array {
        global $DB;

        $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'attendance']);
        if (!$moduleid) {
            return null;
        }

        $sd = (int)$session->starttime;
        $desc = (string)$session->name;

        $sql = "SELECT s.id AS sessionid, s.attendanceid AS attendanceid, cm.id AS cmid
                  FROM {attendance_sessions} s
                  JOIN {attendance} a ON a.id = s.attendanceid AND a.course = :cid
                  JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = :modid
                        AND cm.course = a.course AND cm.deletioninprogress = 0
                        AND cm.idnumber = :idn
                 WHERE s.sessdate = :sd AND s.description = :desc";

        $rows = $DB->get_records_sql($sql, [
            'cid' => $courseid,
            'modid' => $moduleid,
            'idn' => self::TM_ATTENDANCE_CM_IDNUMBER,
            'sd' => $sd,
            'desc' => $desc,
        ]);
        if (empty($rows)) {
            return null;
        }
        if (count($rows) === 1) {
            $r = reset($rows);
            return [
                'sessionid' => (int)$r->sessionid,
                'attendanceid' => (int)$r->attendanceid,
                'cmid' => (int)($r->cmid ?? 0),
            ];
        }
        // Multiple activities share the same slot label+time: prefer the activity already linked on the TM session.
        $wantcm = (int)($session->attendance_cmid ?? 0);
        $wantinst = 0;
        if ($wantcm > 0) {
            $wantinst = (int)($DB->get_field('course_modules', 'instance', ['id' => $wantcm]) ?: 0);
        }
        foreach ($rows as $r) {
            if ($wantinst > 0 && (int)$r->attendanceid === $wantinst) {
                return [
                    'sessionid' => (int)$r->sessionid,
                    'attendanceid' => (int)$r->attendanceid,
                    'cmid' => (int)($r->cmid ?? 0),
                ];
            }
        }
        $r = reset($rows);
        return [
            'sessionid' => (int)$r->sessionid,
            'attendanceid' => (int)$r->attendanceid,
            'cmid' => (int)($r->cmid ?? 0),
        ];
    }

    /**
     * mod_attendance take page only loads attendance_log when attendance_sessions.lasttaken > 0
     * ({@see \mod_attendance\output\take_data::__construct}). Mirror core save_log() behaviour.
     */
    private static function mark_mod_attendance_session_taken(int $attsessionid): void {
        global $DB, $USER;

        if ($attsessionid <= 0) {
            return;
        }
        $now = time();
        $by = (!empty($USER->id)) ? (int)$USER->id : 0;
        $DB->set_field('attendance_sessions', 'lasttaken', $now, ['id' => $attsessionid]);
        $DB->set_field('attendance_sessions', 'lasttakenby', $by, ['id' => $attsessionid]);
        $DB->set_field('attendance_sessions', 'timemodified', $now, ['id' => $attsessionid]);
    }

    /**
     * Sync an attendance mark to mod_attendance tables.
     */
    private static function sync_to_mod_attendance(\stdClass $enrol, int $attended): void {
        global $DB, $USER;

        $session = $DB->get_record('local_tm_course_sessions',
                                   ['id' => $enrol->sessionid]);
        if (!$session) {
            return;
        }

        $courseid = (int)$session->courseid;
        if ($courseid <= 0) {
            return;
        }

        $att_instance = 0;
        $att_sessionid = 0;
        $resolvedcmid = 0;

        $slotmatch = self::resolve_attendance_slot_across_course($session, $courseid);
        if ($slotmatch) {
            $att_sessionid = (int)$slotmatch['sessionid'];
            $att_instance = (int)$slotmatch['attendanceid'];
            $resolvedcmid = (int)$slotmatch['cmid'];
            if ($att_sessionid > 0 && $att_sessionid !== (int)($session->attendance_sessionid ?? 0)) {
                $DB->set_field('local_tm_course_sessions', 'attendance_sessionid', $att_sessionid, ['id' => (int)$session->id]);
            }
        }

        if ($att_instance <= 0) {
            $resolved = self::resolve_attendance_activity_for_course($courseid);
            if (!$resolved || (int)$resolved['instance'] <= 0) {
                return;
            }
            $att_instance = (int)$resolved['instance'];
            $resolvedcmid = (int)($resolved['cmid'] ?? 0);
        }

        if ($resolvedcmid > 0 && (int)($session->attendance_cmid ?? 0) !== $resolvedcmid) {
            $DB->set_field('local_tm_course_sessions', 'attendance_cmid', $resolvedcmid, ['id' => (int)$session->id]);
            $session->attendance_cmid = $resolvedcmid;
        }

        if ($att_sessionid <= 0 || !$DB->record_exists('attendance_sessions', ['id' => $att_sessionid])) {
            $att_sessionid = (int)($session->attendance_sessionid ?? 0);
        }
        if ($att_sessionid <= 0 || !$DB->record_exists('attendance_sessions', ['id' => $att_sessionid])) {
            $slot = $DB->get_record_select('attendance_sessions',
                'attendanceid = :aid AND sessdate = :sd AND description = :desc',
                [
                    'aid' => (int)$att_instance,
                    'sd' => (int)$session->starttime,
                    'desc' => (string)$session->name,
                ],
                '*',
                IGNORE_MISSING
            );
            if (!$slot) {
                $slot = $DB->get_record_select('attendance_sessions',
                    'attendanceid = :aid AND sessdate = :sd',
                    ['aid' => (int)$att_instance, 'sd' => (int)$session->starttime],
                    '*',
                    IGNORE_MISSING
                );
            }
            if ($slot) {
                $att_sessionid = (int)$slot->id;
                $DB->set_field('local_tm_course_sessions', 'attendance_sessionid', $att_sessionid, ['id' => (int)$session->id]);
            }
        }
        // Final fallback: recreate/resolve slot via ensure_attendance_activity.
        if ($att_sessionid <= 0) {
            $course = $DB->get_record('course', ['id' => (int)$session->courseid], '*', IGNORE_MISSING);
            if ($course) {
                try {
                    $ensured = self::ensure_attendance_activity($session, $course);
                    $att_sessionid = (int)($ensured['sessionid'] ?? 0);
                    if ($att_sessionid > 0) {
                        $DB->set_field('local_tm_course_sessions', 'attendance_sessionid', $att_sessionid, ['id' => (int)$session->id]);
                    }
                } catch (\Throwable $t) {
                    error_log('TM Course ensure_attendance_activity in sync failed: ' . $t->getMessage());
                }
            }
        }
        if ($att_sessionid <= 0) {
            return;
        }

        self::ensure_attendance_statuses((int)$att_instance);
        self::align_attendance_status_set_for_tm((int)$att_instance);

        $statusid = self::resolve_status_id_for_attendance((int)$att_instance, $attended);
        if (!$statusid) return;
        $active = $DB->get_records('attendance_statuses', ['attendanceid' => (int)$att_instance, 'deleted' => 0], 'id ASC', 'id');
        $statusset = implode(',', array_map(static function($r) { return (string)$r->id; }, $active));

        $studentid = self::attendance_log_userid($enrol);
        $takenby = !empty($USER->id) ? (int)$USER->id : 0;
        $now = time();

        // Check if log entry exists
        $existing = $DB->get_record('attendance_log', [
            'sessionid' => $att_sessionid,
            'studentid' => $studentid,
        ]);

        if ($existing) {
            $existing->statusid     = $statusid;
            $existing->statusset    = $statusset;
            $existing->timetaken    = $now;
            $existing->takenby      = $takenby;
            $existing->timemodified = $now;
            $DB->update_record('attendance_log', $existing);
        } else {
            $log               = new \stdClass();
            $log->sessionid    = $att_sessionid;
            $log->studentid    = $studentid;
            $log->statusid     = $statusid;
            $log->statusset    = $statusset;
            $log->remarks      = '';
            $log->timetaken    = $now;
            $log->takenby      = $takenby;
            $log->timemodified = $now;
            $DB->insert_record('attendance_log', $log);
        }

        // Required so Moodle take.php loads sessionlog and shows Pr/La/Ab selection.
        self::mark_mod_attendance_session_taken($att_sessionid);
    }

    /**
     * Resolve attendance_statuses.id for TM present/absent.
     * Primary mapping (per product spec): Present → acronym PR, Absent → acronym Ab.
     * Also matches description text and common legacy acronyms.
     */
    private static function resolve_status_id_for_attendance(int $attendanceid, int $attended): int {
        global $DB;

        if ($attendanceid <= 0) {
            return 0;
        }

        $rows = $DB->get_records('attendance_statuses', [
            'attendanceid' => $attendanceid,
            'deleted' => 0,
        ]);
        if (empty($rows)) {
            return 0;
        }

        $preferredacronyms = ($attended === self::ATTEND_PRESENT)
            ? ['PR', 'Pr', 'P', 'Present']
            : ['Ab', 'Absent', 'A'];

        foreach ($preferredacronyms as $acronym) {
            foreach ($rows as $row) {
                if (strcasecmp(trim((string)($row->acronym ?? '')), $acronym) === 0) {
                    return (int)$row->id;
                }
            }
        }

        foreach ($rows as $row) {
            $descraw = trim((string)($row->description ?? ''));
            $desc = \core_text::strtolower($descraw);
            if ($attended === self::ATTEND_PRESENT) {
                if ($descraw === '出席' || $desc === 'present') {
                    return (int)$row->id;
                }
            } else if ($descraw === '缺席' || $desc === 'absent') {
                return (int)$row->id;
            }
        }

        $wantdesc = ($attended === self::ATTEND_PRESENT) ? 'present' : 'absent';
        foreach ($rows as $row) {
            $desc = \core_text::strtolower(trim((string)($row->description ?? '')));
            if ($desc !== '' && $desc === $wantdesc) {
                return (int)$row->id;
            }
        }
        foreach ($rows as $row) {
            $desc = \core_text::strtolower(trim((string)($row->description ?? '')));
            if ($desc !== '' && \core_text::strpos($desc, $wantdesc) !== false) {
                // Avoid matching "present" inside unrelated strings when looking for absent, etc.
                if ($attended === self::ATTEND_PRESENT && \core_text::strpos($desc, 'absent') !== false) {
                    continue;
                }
                return (int)$row->id;
            }
        }

        // Customised status fallback:
        // - Present: choose highest positive grade (prefer not unmarked-default absent).
        // - Absent: prefer setunmarked=1; otherwise choose the lowest grade.
        if ($attended === self::ATTEND_PRESENT) {
            $best = null;
            foreach ($rows as $row) {
                if ((float)$row->grade <= 0) {
                    continue;
                }
                $d = \core_text::strtolower((string)($row->description ?? ''));
                if (\core_text::strpos($d, 'absent') !== false) {
                    continue;
                }
                if ($best === null || (float)$row->grade > (float)$best->grade) {
                    $best = $row;
                }
            }
            return $best ? (int)$best->id : 0;
        }

        foreach ($rows as $row) {
            if (!empty($row->setunmarked)) {
                return (int)$row->id;
            }
        }

        $best = null;
        foreach ($rows as $row) {
            if ($best === null || (float)$row->grade < (float)$best->grade) {
                $best = $row;
            }
        }
        return $best ? (int)$best->id : 0;
    }

    /**
     * Re-sync already marked attendance rows for a session to current mod_attendance slot.
     * Used when attendance activity/slot might have been recreated after earlier marks.
     */
    private static function backfill_marked_attendance_for_session(int $sessionid): void {
        global $DB;

        if ($sessionid <= 0) {
            return;
        }

        $rows = $DB->get_records_select('local_tm_course_enrolments',
            'sessionid = :sid AND status = :approved AND attended IN (:present, :absent)',
            [
                'sid' => $sessionid,
                'approved' => session_manager::ENROL_APPROVED,
                'present' => self::ATTEND_PRESENT,
                'absent' => self::ATTEND_ABSENT,
            ],
            '',
            'id, sessionid, userid, linked_userid, attended'
        );
        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            self::sync_to_mod_attendance($row, (int)$row->attended);
        }
    }

    /**
     * Mark all approved (and not-yet-marked) students of a session as present.
     * Convenience method for "bulk mark all present".
     *
     * @param  int   $sessionid
     * @return int   Number of students marked
     */
    public static function mark_all_present(int $sessionid): int {
        global $DB;

        $approved = $DB->get_records('local_tm_course_enrolments', [
            'sessionid' => $sessionid,
            'status'    => session_manager::ENROL_APPROVED,
        ]);

        $count = 0;
        foreach ($approved as $enrol) {
            if ((int)$enrol->attended !== self::ATTEND_PRESENT) {
                self::mark_attended((int)$enrol->id, self::ATTEND_PRESENT);
                $count++;
            }
        }
        return $count;
    }

    // ----------------------------------------------------------------
    // Course completion
    // ----------------------------------------------------------------

    /**
     * Mark a Moodle course as complete for a user.
     * Uses Moodle's core completion API (3.x compatible).
     *
     * @param int $userid
     * @param int $courseid
     */
    private static function sync_completion(int $userid, int $courseid): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/lib/completionlib.php');

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) return;

        $completion = new \completion_info($course);

        // Only proceed if completion is enabled for this course
        if (!$completion->is_enabled()) return;

        // Mark the course as complete for this user
        $ccompletion = new \completion_completion([
            'userid' => $userid,
            'course' => $courseid,
        ]);

        if (!$ccompletion->is_complete()) {
            // Some sites with email processor issues emit debugging output during completion notifications.
            // Capture and discard that output so attendance actions can still redirect cleanly.
            ob_start();
            try {
                $ccompletion->mark_complete(time());
            } catch (\Throwable $t) {
                error_log('TM Course completion sync failed: ' . $t->getMessage());
            } finally {
                ob_end_clean();
            }
        }
    }

    // ----------------------------------------------------------------
    // Query helpers
    // ----------------------------------------------------------------

    /**
     * Get all approved enrolments for a session with attendance status.
     * Used by admin/class_prep.php.
     *
     * @param  int    $sessionid
     * @return array  Array of stdClass rows
     */
    public static function get_session_attendance(int $sessionid): array {
        global $DB;

        $sql = "SELECT e.id, e.userid, e.status, e.attended, e.institution,
                       e.diet_choice, e.diet_avoid_beef, e.diet_avoid_seafood,
                       e.diet_meat_other, e.diet_vegetarian_notes,
                       e.seat_company, e.placeholder_seq, e.linked_userid, e.linked_email, e.placeholder_name,
                       u.firstname, u.lastname, u.email, u.institution AS profile_institution,
                       u.middlename, u.firstnamephonetic, u.lastnamephonetic, u.alternatename,
                       lu.firstname AS lu_firstname, lu.lastname AS lu_lastname, lu.email AS lu_email
                  FROM {local_tm_course_enrolments} e
                  JOIN {user} u ON u.id = e.userid
             LEFT JOIN {user} lu ON lu.id = e.linked_userid AND e.linked_userid > 0
                 WHERE e.sessionid = :sid
                   AND e.status    = :st
                 ORDER BY u.lastname ASC, u.firstname ASC";

        return $DB->get_records_sql($sql, [
            'sid' => $sessionid,
            'st'  => session_manager::ENROL_APPROVED,
        ]);
    }
}
