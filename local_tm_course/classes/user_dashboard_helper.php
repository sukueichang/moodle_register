<?php
/**
 * User-facing dashboard data for TM course sessions (used by block_tm_dashboard).
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/session_manager.php');

/**
 * Read-only queries for learner dashboard widgets.
 */
class user_dashboard_helper {

    /**
     * Approved enrolments whose session start is still in the future.
     *
     * @return \stdClass[] list rows: session fields + enrolmentid, desk_number
     */
    public static function get_upcoming_sessions(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }

        $now = time();
        $sql = "SELECT s.id AS sessionid, s.name, s.starttime, s.endtime, s.location, s.classroomid,
                       s.delivery_mode, s.teaching_language, s.meeting_link, s.status AS session_status,
                       e.id AS enrolmentid, e.desk_number,
                       e.diet_choice, e.diet_avoid_beef, e.diet_avoid_seafood,
                       e.diet_meat_other, e.diet_vegetarian_notes
                  FROM {local_tm_course_enrolments} e
                  JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
                 WHERE e.userid = :uid
                   AND e.status = :approved
                   AND s.starttime > :now
              ORDER BY s.starttime ASC";

        $rows = $DB->get_records_sql($sql, [
            'uid' => $userid,
            'approved' => session_manager::ENROL_APPROVED,
            'now' => $now,
        ]);

        return session_manager::apply_resolved_locations(array_values($rows));
    }

    /**
     * Pending enrolment requests for the user (awaiting review).
     *
     * @return \stdClass[] list rows: enrolment + session summary
     */
    public static function get_pending_requests(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }

        $sql = "SELECT e.id AS enrolmentid, e.timecreated, e.sessionid,
                       s.name AS sessionname, s.starttime, s.endtime, s.location, s.classroomid,
                       s.delivery_mode, s.status AS session_status
                  FROM {local_tm_course_enrolments} e
                  JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
                 WHERE e.userid = :uid
                   AND e.status = :pending
              ORDER BY e.timecreated DESC";

        $rows = $DB->get_records_sql($sql, [
            'uid' => $userid,
            'pending' => session_manager::ENROL_PENDING,
        ]);

        return session_manager::apply_resolved_locations(array_values($rows));
    }

    /**
     * Recent dedicated class reservation applications for a requester.
     * Sorted by latest update (timemodified/timecreated) DESC.
     *
     * @param int $userid requester id
     * @param int $limit max rows
     * @return \stdClass[]
     */
    public static function get_recent_reservation_requests(int $userid, int $limit = 5): array {
        global $DB;

        if ($userid <= 0 || $limit <= 0) {
            return [];
        }

        require_once(__DIR__ . '/reservation_application.php');
        $sql = "SELECT r.id, r.status, r.manager_note, r.courseids_json, r.delivery_mode, r.teaching_language,
                       r.timecreated, r.timemodified, r.timesubmitted,
                       CASE WHEN r.timesubmitted > 0 THEN r.timesubmitted
                            WHEN r.timemodified > 0 THEN r.timemodified
                            ELSE r.timecreated END AS sorttime
                  FROM {local_tm_course_reservation} r
                 WHERE r.requesterid = :uid"
            . \local_tm_course\reservation_application::sql_submitted_only() . "
              ORDER BY sorttime DESC, r.id DESC";
        $rows = $DB->get_records_sql($sql, ['uid' => $userid], 0, $limit);
        return array_values($rows);
    }

    /**
     * Recent batch enrolment submissions made by a user.
     * Group by session and order by latest status/update time.
     *
     * @param int $userid submitter id
     * @param int $limit max rows
     * @return \stdClass[]
     */
    public static function get_recent_batch_submissions(int $userid, int $limit = 5): array {
        global $DB;

        if ($userid <= 0 || $limit <= 0) {
            return [];
        }

        $sql = "SELECT e.sessionid,
                       MAX(CASE WHEN e.timemodified > 0 THEN e.timemodified ELSE e.timecreated END) AS sorttime,
                       COUNT(1) AS totalrows,
                       SUM(CASE WHEN e.status = :pending THEN 1 ELSE 0 END) AS pendingcount,
                       SUM(CASE WHEN e.status = :approved THEN 1 ELSE 0 END) AS approvedcount,
                       SUM(CASE WHEN e.status = :rejected THEN 1 ELSE 0 END) AS rejectedcount,
                       s.name AS sessionname, s.starttime, s.endtime, s.delivery_mode, s.teaching_language
                  FROM {local_tm_course_enrolments} e
                  JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
                 WHERE e.batch_submittedby = :uid
              GROUP BY e.sessionid, s.name, s.starttime, s.endtime, s.delivery_mode, s.teaching_language
              ORDER BY sorttime DESC, e.sessionid DESC";
        $rows = $DB->get_records_sql($sql, [
            'uid' => $userid,
            'pending' => session_manager::ENROL_PENDING,
            'approved' => session_manager::ENROL_APPROVED,
            'rejected' => session_manager::ENROL_REJECTED,
        ], 0, $limit);
        return array_values($rows);
    }

    /**
     * Normalize a meeting URL for use in href (add https:// when scheme omitted).
     */
    public static function normalize_meeting_link_url(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        return $raw;
    }

    /**
     * System roles that may always open online meeting links (hardcoded; no UI setting).
     * Matches Site administration role shortnames: 系統管理員 / 課程管理員.
     * Batch-enrol (Sales) is also allowed via permissions_manager::user_can_batch_enrol().
     */
    private const MEETING_LINK_PRIVILEGED_SHORTNAMES = ['manager', 'coursecreator'];

    /**
     * True if the user may always open online meeting links when a URL exists:
     * manager / coursecreator, or batch-enrol (capability or permission rule).
     */
    public static function user_can_always_view_meeting_link(?\stdClass $user = null): bool {
        global $USER;

        $user = $user ?? $USER;
        $userid = (int) ($user->id ?? 0);
        if ($userid <= 0 || isguestuser($user)) {
            return false;
        }

        static $cache = [];
        if (array_key_exists($userid, $cache)) {
            return $cache[$userid];
        }

        if (permissions_manager::user_can_batch_enrol($user)) {
            return $cache[$userid] = true;
        }

        $assignments = get_user_roles(\context_system::instance(), $userid, true);
        foreach ($assignments as $assignment) {
            $shortname = (string) ($assignment->shortname ?? '');
            if (in_array($shortname, self::MEETING_LINK_PRIVILEGED_SHORTNAMES, true)) {
                return $cache[$userid] = true;
            }
        }

        return $cache[$userid] = false;
    }

    /**
     * Whether the current user may open the session's online meeting link.
     * Allowed for approved enrolments, or privileged viewers (manager / coursecreator / batch-enrol)
     * when the session is online and a meeting link exists.
     */
    public static function can_show_meeting_link_for_enrolment(\stdClass $session, ?\stdClass $enrolment): bool {
        if (!session_manager::is_online_session($session)) {
            return false;
        }
        if (trim((string) ($session->meeting_link ?? '')) === '') {
            return false;
        }
        if (self::user_can_always_view_meeting_link()) {
            return true;
        }
        return $enrolment && (int) $enrolment->status === session_manager::ENROL_APPROVED;
    }

    /**
     * HTML button linking to the session meeting URL (empty string when unavailable).
     *
     * @param string $meetinglink raw meeting_link from session row
     * @param string $extraclass extra CSS classes appended to the button
     */
    public static function render_join_meeting_button(string $meetinglink, string $extraclass = ''): string {
        $url = self::normalize_meeting_link_url($meetinglink);
        if ($url === '') {
            return '';
        }
        $sm = get_string_manager();
        $fallback_join_labels = [
            'en' => 'Join online session',
            'zh_tw' => '加入視訊課程',
            'zh_cn' => '加入在线课程',
            'ja' => 'オンライン参加',
            'ko' => '온라인 세션 참여',
            'fr' => 'Rejoindre la session en ligne',
            'de' => 'Online-Sitzung beitreten',
        ];
        $lang = (string)current_language();
        $label = $sm->string_exists('session_join_meeting_button', 'local_tm_course')
            ? get_string('session_join_meeting_button', 'local_tm_course')
            : ($fallback_join_labels[$lang] ?? get_string('session_meeting_link', 'local_tm_course')); // Avoid fatal if language key missing.
        $class = 'btn btn-sm btn-tm-success tm-meeting-link-btn';
        if ($extraclass !== '') {
            $class .= ' ' . $extraclass;
        }
        return \html_writer::link(
            $url,
            $label,
            [
                'class' => $class,
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
            ]
        );
    }
}
