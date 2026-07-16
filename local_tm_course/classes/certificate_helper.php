<?php
/**
 * Certificate helper for customcert integration.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class certificate_helper {
    /**
     * List all issued certificates for a user across Moodle courses.
     *
     * @param int $userid
     * @return array<int,\stdClass>
     */
    public static function get_user_certificates(int $userid): array {
        return self::get_certificates_by_user_ids([$userid], 0);
    }

    /**
     * List issued certificates for selected users.
     *
     * @param array<int,int> $userids
     * @param int $limit
     * @return array<int,\stdClass>
     */
    public static function get_certificates_by_user_ids(array $userids, int $limit = 100): array {
        global $DB;

        if (!self::is_customcert_installed() || empty($userids)) {
            return [];
        }

        $userids = array_values(array_unique(array_map('intval', $userids)));
        $fetchlimit = $limit > 0 ? max(1, $limit) : 0;

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $sql = "SELECT ci.id AS issueid,
                       ci.code,
                       ci.userid,
                       ci.timecreated,
                       cc.id AS customcertid,
                       c.id AS courseid,
                       c.fullname AS coursename,
                       cm.id AS cmid
                  FROM {customcert_issues} ci
                  JOIN {customcert} cc ON cc.id = ci.customcertid
                  JOIN {course} c ON c.id = cc.course
                  JOIN {course_modules} cm ON cm.instance = cc.id AND cm.course = cc.course
                  JOIN {modules} m ON m.id = cm.module
                 WHERE m.name = :modname
                   AND cm.deletioninprogress = 0
                   AND ci.userid $insql
              ORDER BY ci.timecreated DESC, ci.id DESC";
        $params = $inparams + ['modname' => 'customcert'];
        $issued = self::dedupe_certificate_rows(self::fetch_sql_rows($sql, $params));

        $issuedkeys = [];
        foreach ($issued as $row) {
            $issuedkeys[self::certificate_row_key($row)] = true;
        }

        $receivable = [];
        foreach (self::get_enrolled_customcert_candidates($userids) as $candidate) {
            $key = self::certificate_row_key($candidate);
            if (isset($issuedkeys[$key])) {
                continue;
            }
            if (!self::user_can_receive_certificate(
                (int)$candidate->customcertid,
                (int)$candidate->courseid,
                (int)$candidate->cmid,
                (int)$candidate->userid
            )) {
                continue;
            }
            $receivable[] = (object)[
                'issueid' => 0,
                'code' => '',
                'userid' => (int)$candidate->userid,
                'timecreated' => 0,
                'customcertid' => (int)$candidate->customcertid,
                'courseid' => (int)$candidate->courseid,
                'coursename' => (string)$candidate->coursename,
                'cmid' => (int)$candidate->cmid,
            ];
        }

        $merged = array_merge($issued, $receivable);
        usort($merged, static function(\stdClass $a, \stdClass $b): int {
            $atime = (int)($a->timecreated ?? 0);
            $btime = (int)($b->timecreated ?? 0);
            if ($atime !== $btime) {
                return $btime <=> $atime;
            }
            return ((int)($b->issueid ?? 0)) <=> ((int)($a->issueid ?? 0));
        });

        if ($fetchlimit > 0) {
            return array_slice($merged, 0, $fetchlimit);
        }

        return $merged;
    }

    /**
     * Resolve certificate issue + CM for course/user.
     *
     * @param int $courseid
     * @param int $userid
     * @return \stdClass|null {issueid, customcertid, cmid}
     */
    public static function find_course_certificate_issue(int $courseid, int $userid): ?\stdClass {
        $slot = self::find_receivable_course_certificate($courseid, $userid);
        if (!$slot || empty($slot->issueid)) {
            return null;
        }
        return $slot;
    }

    /**
     * Resolve a receivable certificate slot for a course/user (issued or eligible).
     *
     * @param int $courseid
     * @param int $userid
     * @param int $cmid Optional course-module id when multiple certificates exist in one course.
     * @return \stdClass|null {issueid, customcertid, cmid}
     */
    public static function find_receivable_course_certificate(int $courseid, int $userid, int $cmid = 0): ?\stdClass {
        global $DB;

        if (!self::is_customcert_installed()) {
            return null;
        }

        $params = [
            'modname' => 'customcert',
            'courseid' => $courseid,
        ];
        $cmfilter = '';
        if ($cmid > 0) {
            $cmfilter = 'AND cm.id = :cmid';
            $params['cmid'] = $cmid;
        }

        $sql = "SELECT cc.id AS customcertid,
                       cm.id AS cmid,
                       ci.id AS issueid
                  FROM {customcert} cc
                  JOIN {course_modules} cm ON cm.instance = cc.id AND cm.course = cc.course
                  JOIN {modules} m ON m.id = cm.module
             LEFT JOIN {customcert_issues} ci
                    ON ci.customcertid = cc.id
                   AND ci.userid = :userid
                 WHERE m.name = :modname
                   AND cc.course = :courseid
                   AND cm.deletioninprogress = 0
                       $cmfilter
              ORDER BY (CASE WHEN ci.id IS NULL THEN 0 ELSE 1 END) DESC,
                       ci.timecreated DESC,
                       ci.id DESC,
                       cc.id DESC,
                       cm.id DESC";
        $params['userid'] = $userid;

        $rows = self::fetch_sql_rows($sql, $params);
        foreach ($rows as $row) {
            if (!self::user_can_receive_certificate(
                (int)$row->customcertid,
                $courseid,
                (int)$row->cmid,
                $userid
            )) {
                continue;
            }
            return (object)[
                'issueid' => !empty($row->issueid) ? (int)$row->issueid : 0,
                'customcertid' => (int)$row->customcertid,
                'cmid' => (int)$row->cmid,
            ];
        }

        return null;
    }

    /**
     * Issue a certificate when the user is eligible but has not opened View certificate yet.
     *
     * @param int $customcertid
     * @param int $courseid
     * @param int $cmid
     * @param int $userid
     * @return int|null Issue id
     */
    public static function ensure_certificate_issued_for_user(
        int $customcertid,
        int $courseid,
        int $cmid,
        int $userid
    ): ?int {
        global $DB;

        if (!self::user_can_receive_certificate($customcertid, $courseid, $cmid, $userid)) {
            return null;
        }

        $issueid = $DB->get_field('customcert_issues', 'id', [
            'userid' => $userid,
            'customcertid' => $customcertid,
        ], IGNORE_MULTIPLE);
        if (!empty($issueid)) {
            return (int)$issueid;
        }

        return (int)\mod_customcert\certificate::issue_certificate($customcertid, $userid);
    }

    /**
     * Whether a user can use the customcert "View certificate" action.
     *
     * Mirrors mod_customcert scheduled email / view.php eligibility checks.
     *
     * @param int $customcertid
     * @param int $courseid
     * @param int $cmid
     * @param int $userid
     * @return bool
     */
    public static function user_can_receive_certificate(int $customcertid, int $courseid, int $cmid, int $userid): bool {
        global $DB;

        $coursecontext = \context_course::instance($courseid);
        if (!is_enrolled($coursecontext, $userid)) {
            return false;
        }

        $modinfo = get_fast_modinfo($courseid, $userid);
        if (empty($modinfo->instances['customcert'][$customcertid])) {
            return false;
        }

        $cminfo = $modinfo->instances['customcert'][$customcertid];
        if (!$cminfo->uservisible) {
            return false;
        }

        $context = \context_module::instance($cmid);
        if (has_capability('mod/customcert:manage', $context, $userid)) {
            return false;
        }
        if (!has_capability('mod/customcert:receiveissue', $context, $userid)) {
            return false;
        }

        $customcert = $DB->get_record('customcert', ['id' => $customcertid], 'id, requiredtime');
        if (!$customcert) {
            return false;
        }
        if (!empty($customcert->requiredtime)
            && \mod_customcert\certificate::get_course_time($courseid, $userid) < ((int)$customcert->requiredtime * 60)) {
            return false;
        }

        return true;
    }

    /**
     * Build certificate download URL for a viewer.
     *
     * @param int $courseid
     * @param int $targetuserid
     * @param \stdClass $viewer
     * @param int $cmid Optional course-module id.
     * @return \moodle_url|null
     */
    public static function get_download_url_for_viewer(
        int $courseid,
        int $targetuserid,
        \stdClass $viewer,
        int $cmid = 0
    ): ?\moodle_url {
        $slot = self::find_receivable_course_certificate($courseid, $targetuserid, $cmid);
        if (!$slot) {
            return null;
        }

        if ((int)$viewer->id === $targetuserid) {
            return new \moodle_url('/mod/customcert/view.php', [
                'id' => (int)$slot->cmid,
                'downloadown' => 1,
            ]);
        }

        if (!empty($slot->issueid)) {
            $ctx = \context_module::instance((int)$slot->cmid);
            if (has_capability('mod/customcert:viewreport', $ctx, $viewer)
                || has_capability('mod/customcert:manage', $ctx, $viewer)) {
                return new \moodle_url('/mod/customcert/view.php', [
                    'id' => (int)$slot->cmid,
                    'downloadissue' => $targetuserid,
                ]);
            }
        }

        $params = [
            'courseid' => $courseid,
            'userid' => $targetuserid,
            'cmid' => (int)$slot->cmid,
        ];
        return new \moodle_url('/local/tm_course/certificate_download.php', $params);
    }

    /**
     * @param array<int,int> $userids
     * @return array<int,\stdClass>
     */
    private static function get_enrolled_customcert_candidates(array $userids): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $sql = "SELECT ue.userid,
                       cc.id AS customcertid,
                       c.id AS courseid,
                       c.fullname AS coursename,
                       cm.id AS cmid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.status = 0
                  JOIN {customcert} cc ON cc.course = e.courseid
                  JOIN {course} c ON c.id = cc.course
                  JOIN {course_modules} cm ON cm.instance = cc.id AND cm.course = cc.course
                  JOIN {modules} m ON m.id = cm.module
                 WHERE m.name = :modname
                   AND ue.status = 0
                   AND cm.deletioninprogress = 0
                   AND ue.userid $insql
              ORDER BY cc.id DESC, cm.id DESC";
        $params = $inparams + ['modname' => 'customcert'];

        return self::dedupe_certificate_rows(self::fetch_sql_rows($sql, $params));
    }

    /**
     * Fetch SQL rows without get_records_sql first-column uniqueness constraint.
     *
     * @param string $sql
     * @param array $params
     * @return array<int,\stdClass>
     */
    private static function fetch_sql_rows(string $sql, array $params): array {
        global $DB;

        $rows = [];
        $recordset = $DB->get_recordset_sql($sql, $params);
        foreach ($recordset as $row) {
            $rows[] = $row;
        }
        $recordset->close();

        return $rows;
    }

    /**
     * Keep one row per user/course/customcert combination.
     *
     * @param array<int,\stdClass> $rows
     * @return array<int,\stdClass>
     */
    private static function dedupe_certificate_rows(array $rows): array {
        $deduped = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = self::certificate_row_key($row);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    /**
     * @param \stdClass $row
     * @return string
     */
    private static function certificate_row_key(\stdClass $row): string {
        return (int)$row->userid . ':' . (int)$row->courseid . ':' . (int)$row->customcertid;
    }

    /**
     * Whether mod_customcert tables are available.
     *
     * @return bool
     */
    public static function is_customcert_installed(): bool {
        global $DB;
        $manager = $DB->get_manager();
        return $manager->table_exists('customcert')
            && $manager->table_exists('customcert_issues');
    }
}
