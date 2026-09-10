<?php
/**
 * Sales grading-request dispatch for Moodle assign/quiz.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/enabled_course_manager.php');
require_once(__DIR__ . '/permissions_manager.php');
require_once(__DIR__ . '/notification_helper.php');

class grading_request_manager {
    public const STATUS_PENDING = 0;
    public const STATUS_ASSIGNED = 1;
    public const STATUS_IN_PROGRESS = 2;
    public const STATUS_COMPLETED = 3;
    public const STATUS_REJECTED = 4;
    public const STATUS_CANCELLED = 5;

    public const ITEM_PENDING = 0;
    public const ITEM_GRADED = 1;
    public const ITEM_MISSING = 2;

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
    ];

    public static function user_is_admin(?\stdClass $user = null): bool {
        global $USER;
        $user = $user ?? $USER;
        if (empty($user->id) || isguestuser($user)) {
            return false;
        }
        return is_siteadmin($user) || has_capability('local/tm_course:manage', \context_system::instance(), $user);
    }

    public static function user_can_apply(?\stdClass $user = null): bool {
        return self::user_is_admin($user) || permissions_manager::user_can_batch_enrol($user);
    }

    /**
     * Name columns required by fullname() (phonetic / middle / alternate, etc.).
     */
    private static function user_name_fields_sql(string $tablealias = 'u'): string {
        $fields = get_all_user_name_fields(true, $tablealias);
        return $fields !== '' ? $fields : $tablealias . '.firstname, ' . $tablealias . '.lastname';
    }

    public static function require_can_apply(): void {
        if (!self::user_can_apply()) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/tm_course:batchenrol',
                'nopermissions',
                ''
            );
        }
    }

    public static function badge_count(?\stdClass $user = null): int {
        global $DB, $USER;
        $user = $user ?? $USER;
        if (empty($user->id)) {
            return 0;
        }
        if (!$DB->get_manager()->table_exists('local_tm_course_grreq')) {
            return 0;
        }
        list($insql, $params) = $DB->get_in_or_equal(self::OPEN_STATUSES, SQL_PARAMS_NAMED);
        if (self::user_is_admin($user)) {
            $params['assignee'] = 0;
            return (int)$DB->count_records_select(
                'local_tm_course_grreq',
                "status $insql AND assigneeid = :assignee",
                $params
            );
        }
        $params['uid'] = (int)$user->id;
        return (int)$DB->count_records_select(
            'local_tm_course_grreq',
            "status $insql AND assigneeid = :uid",
            $params
        );
    }

    public static function user_can_see_queue(?\stdClass $user = null): bool {
        global $USER;
        $user = $user ?? $USER;
        if (self::user_is_admin($user)) {
            return true;
        }
        return self::badge_count($user) > 0 || self::has_any_assigned((int)$user->id);
    }

    public static function has_any_assigned(int $userid): bool {
        global $DB;
        if ($userid <= 0) {
            return false;
        }
        if (!$DB->get_manager()->table_exists('local_tm_course_grreq')) {
            return false;
        }
        return $DB->record_exists('local_tm_course_grreq', ['assigneeid' => $userid]);
    }

    /**
     * @return \stdClass[] id, fullname
     */
    public static function enabled_courses(): array {
        global $DB;
        $ids = enabled_course_manager::get_enabled_ids();
        if (empty($ids)) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        return array_values($DB->get_records_select(
            'course',
            "id $insql AND visible = 1",
            $params,
            'fullname ASC',
            'id, fullname, shortname'
        ));
    }

    /**
     * Visible assign/quiz cms in an enabled course (ignore availability restrictions).
     *
     * @return array{cmid:int,modname:string,name:string,instanceid:int}[]
     */
    public static function list_activities(int $courseid): array {
        global $DB;
        if ($courseid <= 0 || !enabled_course_manager::is_enabled($courseid)) {
            return [];
        }
        $sql = "SELECT cm.id AS cmid, m.name AS modname, cm.instance AS instanceid,
                       COALESCE(a.name, q.name) AS activityname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
             LEFT JOIN {assign} a ON m.name = 'assign' AND a.id = cm.instance
             LEFT JOIN {quiz} q ON m.name = 'quiz' AND q.id = cm.instance
                 WHERE cm.course = :courseid
                   AND m.name IN ('assign', 'quiz')
                   AND cm.visible = 1
                   AND COALESCE(cm.deletioninprogress, 0) = 0
              ORDER BY activityname ASC, cm.id ASC";
        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid]);
        $out = [];
        foreach ($rows as $row) {
            $name = trim((string)($row->activityname ?? ''));
            $out[] = [
                'cmid' => (int)$row->cmid,
                'modname' => (string)$row->modname,
                'instanceid' => (int)$row->instanceid,
                'name' => $name !== '' ? $name : (string)$row->modname,
            ];
        }
        return $out;
    }

    /**
     * @return array{exists:bool,courseid:int,modname:string,instanceid:int,name:string,visible:bool}|null
     */
    public static function get_activity(int $cmid): ?array {
        global $DB;
        if ($cmid <= 0) {
            return null;
        }
        $sql = "SELECT cm.id AS cmid, cm.course AS courseid, cm.visible, m.name AS modname,
                       cm.instance AS instanceid, COALESCE(a.name, q.name) AS activityname,
                       COALESCE(cm.deletioninprogress, 0) AS deletioninprogress
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
             LEFT JOIN {assign} a ON m.name = 'assign' AND a.id = cm.instance
             LEFT JOIN {quiz} q ON m.name = 'quiz' AND q.id = cm.instance
                 WHERE cm.id = :cmid";
        $row = $DB->get_record_sql($sql, ['cmid' => $cmid]);
        if (!$row || !in_array((string)$row->modname, ['assign', 'quiz'], true)) {
            return null;
        }
        $name = trim((string)($row->activityname ?? ''));
        return [
            'exists' => ((int)$row->deletioninprogress === 0),
            'courseid' => (int)$row->courseid,
            'modname' => (string)$row->modname,
            'instanceid' => (int)$row->instanceid,
            'name' => $name !== '' ? $name : (string)$row->modname,
            'visible' => ((int)$row->visible === 1),
        ];
    }

    public static function user_has_submitted(int $cmid, int $userid): bool {
        return self::latest_submission($cmid, $userid) !== null;
    }

    /**
     * @return array{type:string,id:int}|null
     */
    public static function latest_submission(int $cmid, int $userid): ?array {
        global $DB;
        $activity = self::get_activity($cmid);
        if (!$activity || !$activity['exists'] || $userid <= 0) {
            return null;
        }
        if ($activity['modname'] === 'assign') {
            $rec = $DB->get_record_sql(
                "SELECT s.id
                   FROM {assign_submission} s
                  WHERE s.assignment = :aid
                    AND s.userid = :uid
                    AND s.status = :st
                    AND s.latest = 1",
                ['aid' => $activity['instanceid'], 'uid' => $userid, 'st' => 'submitted']
            );
            return $rec ? ['type' => 'assign', 'id' => (int)$rec->id] : null;
        }
        $recs = $DB->get_records_sql(
            "SELECT qa.id
               FROM {quiz_attempts} qa
              WHERE qa.quiz = :qid
                AND qa.userid = :uid
                AND qa.state = :st
           ORDER BY qa.attempt DESC, qa.id DESC",
            ['qid' => $activity['instanceid'], 'uid' => $userid, 'st' => 'finished'],
            0,
            1
        );
        $rec = $recs ? reset($recs) : false;
        return $rec ? ['type' => 'quiz', 'id' => (int)$rec->id] : null;
    }

    /**
     * @return array{has:bool,str:string,time:int}
     */
    public static function gradebook_grade(int $courseid, string $modname, int $instanceid, int $userid): array {
        global $CFG;
        $empty = ['has' => false, 'str' => '', 'time' => 0];
        if ($courseid <= 0 || $instanceid <= 0 || $userid <= 0) {
            return $empty;
        }
        require_once($CFG->libdir . '/gradelib.php');
        $grades = \grade_get_grades($courseid, 'mod', $modname, $instanceid, $userid);
        if (empty($grades->items)) {
            return $empty;
        }
        $item = reset($grades->items);
        if (empty($item->grades[$userid])) {
            return $empty;
        }
        $g = $item->grades[$userid];
        if ($g->grade === null || $g->grade === '') {
            return $empty;
        }
        $str = trim((string)($g->str_long_grade ?? ''));
        if ($str === '') {
            $str = trim((string)($g->str_grade ?? ''));
        }
        return [
            'has' => true,
            'str' => $str,
            'time' => (int)($g->dategraded ?? 0),
        ];
    }

    public static function open_duplicate_requestid(int $cmid, int $userid, int $excludeid = 0): int {
        global $DB;
        if ($cmid <= 0 || $userid <= 0) {
            return 0;
        }
        list($insql, $params) = $DB->get_in_or_equal(self::OPEN_STATUSES, SQL_PARAMS_NAMED);
        $params['cmid'] = $cmid;
        $params['uid'] = $userid;
        $exclude = '';
        if ($excludeid > 0) {
            $exclude = ' AND r.id <> :exid';
            $params['exid'] = $excludeid;
        }
        $id = $DB->get_field_sql(
            "SELECT r.id
               FROM {local_tm_course_grreq} r
               JOIN {local_tm_course_gritem} i ON i.requestid = r.id
              WHERE r.cmid = :cmid
                AND i.userid = :uid
                AND r.status $insql
                $exclude",
            $params,
            IGNORE_MULTIPLE
        );
        return (int)$id;
    }

    /**
     * @return array{id:int,fullname:string,email:string,blocked:int,blockrequestid:int}[]
     */
    public static function search_submitted_users(int $cmid, string $query): array {
        global $DB;
        $q = trim($query);
        if (\core_text::strlen($q) < 2) {
            throw new \moodle_exception('search_user_field_too_short', 'local_tm_course');
        }
        $activity = self::get_activity($cmid);
        if (!$activity || !$activity['exists'] || !$activity['visible']) {
            return [];
        }
        if (!enabled_course_manager::is_enabled($activity['courseid'])) {
            return [];
        }
        $like = $DB->sql_like('u.firstname', ':q1', false, false)
            . ' OR ' . $DB->sql_like('u.lastname', ':q2', false, false)
            . ' OR ' . $DB->sql_like('u.email', ':q3', false, false)
            . ' OR ' . $DB->sql_like($DB->sql_fullname('u.firstname', 'u.lastname'), ':q4', false, false);
        $esc = '%' . $DB->sql_like_escape($q) . '%';
        $params = [
            'q1' => $esc,
            'q2' => $esc,
            'q3' => $esc,
            'q4' => $esc,
        ];
        $namefields = self::user_name_fields_sql('u');
        if ($activity['modname'] === 'assign') {
            $sql = "SELECT DISTINCT u.id, u.email, $namefields
                      FROM {assign_submission} s
                      JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                     WHERE s.assignment = :aid
                       AND s.status = :st
                       AND s.latest = 1
                       AND ($like)
                  ORDER BY u.lastname, u.firstname";
            $params['aid'] = $activity['instanceid'];
            $params['st'] = 'submitted';
        } else {
            $sql = "SELECT DISTINCT u.id, u.email, $namefields
                      FROM {quiz_attempts} qa
                      JOIN {user} u ON u.id = qa.userid AND u.deleted = 0
                     WHERE qa.quiz = :qid
                       AND qa.state = :st
                       AND ($like)
                  ORDER BY u.lastname, u.firstname";
            $params['qid'] = $activity['instanceid'];
            $params['st'] = 'finished';
        }
        $users = $DB->get_records_sql($sql, $params, 0, 50);
        $out = [];
        foreach ($users as $u) {
            $dup = self::open_duplicate_requestid($cmid, (int)$u->id);
            $out[] = [
                'id' => (int)$u->id,
                'fullname' => fullname($u),
                'email' => (string)$u->email,
                'blocked' => $dup > 0 ? 1 : 0,
                'blockrequestid' => $dup,
            ];
        }
        return $out;
    }

    /**
     * @param int[] $userids
     */
    public static function create_request(int $cmid, array $userids, string $note, int $requesterid): int {
        global $DB;
        $activity = self::get_activity($cmid);
        if (!$activity || !$activity['exists'] || !$activity['visible']) {
            throw new \moodle_exception('grading_error_activity_hidden', 'local_tm_course');
        }
        if (!enabled_course_manager::is_enabled($activity['courseid'])) {
            throw new \moodle_exception('grading_error_course_not_enabled', 'local_tm_course');
        }
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids), static function(int $id): bool {
            return $id > 0;
        })));
        if (empty($userids)) {
            throw new \moodle_exception('grading_error_no_users', 'local_tm_course');
        }
        $accepted = [];
        foreach ($userids as $uid) {
            if (!self::user_has_submitted($cmid, $uid)) {
                continue;
            }
            $dup = self::open_duplicate_requestid($cmid, $uid);
            if ($dup > 0) {
                throw new \moodle_exception('grading_error_duplicate', 'local_tm_course', '', $dup);
            }
            $accepted[] = $uid;
        }
        if (empty($accepted)) {
            throw new \moodle_exception('grading_error_none_submitted', 'local_tm_course');
        }

        $now = time();
        $req = (object) [
            'courseid' => $activity['courseid'],
            'cmid' => $cmid,
            'modname' => $activity['modname'],
            'requesterid' => $requesterid,
            'assigneeid' => 0,
            'status' => self::STATUS_PENDING,
            'note' => $note !== '' ? $note : null,
            'rejectreason' => null,
            'activitygone' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timecompleted' => 0,
        ];
        $requestid = (int)$DB->insert_record('local_tm_course_grreq', $req);
        foreach ($accepted as $uid) {
            $u = $DB->get_record('user', ['id' => $uid], 'id, firstname, lastname, email', IGNORE_MISSING);
            $item = (object) [
                'requestid' => $requestid,
                'userid' => $uid,
                'itemstatus' => self::ITEM_PENDING,
                'snapfirst' => $u ? (string)$u->firstname : null,
                'snaplast' => $u ? (string)$u->lastname : null,
                'snapemail' => $u ? (string)$u->email : null,
                'timemodified' => $now,
            ];
            $DB->insert_record('local_tm_course_gritem', $item);
        }
        try {
            notification_helper::notify_grading_submitted($requestid);
        } catch (\Throwable $e) {
            debugging('TM grading notify submitted failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $requestid;
    }

    public static function get_request(int $id): ?\stdClass {
        global $DB;
        if ($id <= 0) {
            return null;
        }
        $rec = $DB->get_record('local_tm_course_grreq', ['id' => $id], '*', IGNORE_MISSING);
        return $rec ?: null;
    }

    public static function can_view(\stdClass $req, ?\stdClass $user = null): bool {
        global $USER;
        $user = $user ?? $USER;
        if (self::user_is_admin($user)) {
            return true;
        }
        if ((int)$req->requesterid === (int)$user->id && self::user_can_apply($user)) {
            return true;
        }
        return (int)$req->assigneeid === (int)$user->id;
    }

    public static function can_start_grade(\stdClass $req, ?\stdClass $user = null): bool {
        global $USER;
        $user = $user ?? $USER;
        if (!self::can_view($req, $user)) {
            return false;
        }
        if (in_array((int)$req->status, [self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
            return false;
        }
        if (self::user_is_admin($user)) {
            return true;
        }
        return (int)$req->assigneeid === (int)$user->id;
    }

    /**
     * @return \stdClass[]
     */
    public static function get_items(int $requestid): array {
        global $DB;
        return array_values($DB->get_records('local_tm_course_gritem', ['requestid' => $requestid], 'id ASC'));
    }

    public static function display_item_name(\stdClass $item): string {
        $first = trim((string)($item->snapfirst ?? ''));
        $last = trim((string)($item->snaplast ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name !== '') {
            return $name;
        }
        return get_string('grading_unknown_user', 'local_tm_course');
    }

    public static function grade_url(\stdClass $req, \stdClass $item): ?\moodle_url {
        $activity = self::get_activity((int)$req->cmid);
        if (!$activity || !$activity['exists']) {
            return null;
        }
        if ((int)$item->itemstatus === self::ITEM_MISSING) {
            return null;
        }
        if ($activity['modname'] === 'assign') {
            return new \moodle_url('/mod/assign/view.php', [
                'id' => (int)$req->cmid,
                'action' => 'grader',
                'userid' => (int)$item->userid,
            ]);
        }
        $sub = self::latest_submission((int)$req->cmid, (int)$item->userid);
        if (!$sub || $sub['type'] !== 'quiz') {
            return null;
        }
        return new \moodle_url('/mod/quiz/review.php', ['attempt' => $sub['id']]);
    }

    /**
     * Refresh item statuses from Moodle. Returns true if the request became completed.
     */
    public static function sync_request(int $requestid): bool {
        global $DB;
        $req = self::get_request($requestid);
        if (!$req) {
            return false;
        }
        if (in_array((int)$req->status, [self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_COMPLETED], true)
            && (int)$req->activitygone === 1) {
            return (int)$req->status === self::STATUS_COMPLETED;
        }
        if (in_array((int)$req->status, [self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        $now = time();
        $activity = self::get_activity((int)$req->cmid);
        if (!$activity || !$activity['exists']) {
            $DB->set_field('local_tm_course_grreq', 'activitygone', 1, ['id' => $requestid]);
            $DB->set_field('local_tm_course_gritem', 'itemstatus', self::ITEM_MISSING, ['requestid' => $requestid]);
            $DB->set_field('local_tm_course_grreq', 'status', self::STATUS_COMPLETED, ['id' => $requestid]);
            $DB->set_field('local_tm_course_grreq', 'timecompleted', $now, ['id' => $requestid]);
            $DB->set_field('local_tm_course_grreq', 'timemodified', $now, ['id' => $requestid]);
            try {
                notification_helper::notify_grading_completed($requestid);
            } catch (\Throwable $e) {
                debugging('TM grading notify completed failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            return true;
        }

        $items = self::get_items($requestid);
        $graded = 0;
        $missing = 0;
        $pending = 0;
        foreach ($items as $item) {
            $user = $DB->get_record('user', ['id' => (int)$item->userid, 'deleted' => 0], 'id', IGNORE_MISSING);
            $sub = $user ? self::latest_submission((int)$req->cmid, (int)$item->userid) : null;
            $grade = $user
                ? self::gradebook_grade((int)$req->courseid, (string)$req->modname, (int)$activity['instanceid'], (int)$item->userid)
                : ['has' => false];
            $newstatus = self::ITEM_PENDING;
            if (!$user || !$sub) {
                $newstatus = self::ITEM_MISSING;
                $missing++;
            } else if (!empty($grade['has'])) {
                $newstatus = self::ITEM_GRADED;
                $graded++;
            } else {
                $pending++;
            }
            if ((int)$item->itemstatus !== $newstatus) {
                $DB->set_field('local_tm_course_gritem', 'itemstatus', $newstatus, ['id' => (int)$item->id]);
                $DB->set_field('local_tm_course_gritem', 'timemodified', $now, ['id' => (int)$item->id]);
            }
        }

        $wascomplete = ((int)$req->status === self::STATUS_COMPLETED);
        $newreqstatus = (int)$req->status;
        if ($pending === 0) {
            $newreqstatus = self::STATUS_COMPLETED;
        } else if ($graded > 0 || $missing > 0) {
            $newreqstatus = ((int)$req->assigneeid > 0) ? self::STATUS_IN_PROGRESS : self::STATUS_IN_PROGRESS;
            if ((int)$req->assigneeid <= 0 && $graded === 0) {
                $newreqstatus = self::STATUS_PENDING;
            } else if ((int)$req->assigneeid > 0 && $graded === 0 && $missing === 0) {
                $newreqstatus = self::STATUS_ASSIGNED;
            }
        } else if ((int)$req->assigneeid > 0) {
            $newreqstatus = self::STATUS_ASSIGNED;
        } else {
            $newreqstatus = self::STATUS_PENDING;
        }

        $update = [
            'status' => $newreqstatus,
            'timemodified' => $now,
            'activitygone' => 0,
        ];
        if ($newreqstatus === self::STATUS_COMPLETED && !$wascomplete) {
            $update['timecompleted'] = $now;
        }
        foreach ($update as $field => $val) {
            $DB->set_field('local_tm_course_grreq', $field, $val, ['id' => $requestid]);
        }
        if ($newreqstatus === self::STATUS_COMPLETED && !$wascomplete) {
            try {
                notification_helper::notify_grading_completed($requestid);
            } catch (\Throwable $e) {
                debugging('TM grading notify completed failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            return true;
        }
        return false;
    }

    public static function sync_open_requests(): int {
        global $DB;
        list($insql, $params) = $DB->get_in_or_equal(self::OPEN_STATUSES, SQL_PARAMS_NAMED);
        $ids = $DB->get_fieldset_select('local_tm_course_grreq', 'id', "status $insql", $params);
        $n = 0;
        foreach ($ids as $id) {
            self::sync_request((int)$id);
            $n++;
        }
        return $n;
    }

    /**
     * @return \stdClass[] course graders
     */
    public static function list_graders(int $courseid, string $modname): array {
        if ($courseid <= 0) {
            return [];
        }
        $ctx = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$ctx) {
            return [];
        }
        $cap = $modname === 'quiz' ? 'mod/quiz:grade' : 'mod/assign:grade';
        $fields = 'u.id, u.email, ' . self::user_name_fields_sql('u');
        $bycap = get_users_by_capability($ctx, $cap, $fields, 'u.lastname ASC, u.firstname ASC');
        $byedit = get_users_by_capability($ctx, 'moodle/grade:edit', $fields, 'u.lastname ASC, u.firstname ASC');
        $merged = [];
        foreach ([$bycap, $byedit] as $set) {
            foreach ($set as $u) {
                $merged[(int)$u->id] = $u;
            }
        }
        uasort($merged, static function(\stdClass $a, \stdClass $b): int {
            return strcasecmp(fullname($a), fullname($b));
        });
        return array_values($merged);
    }

    public static function assign_to(int $requestid, int $assigneeid, int $actorid): void {
        global $DB;
        $req = self::get_request($requestid);
        if (!$req) {
            throw new \moodle_exception('grading_error_notfound', 'local_tm_course');
        }
        if (!self::user_is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        if (!in_array((int)$req->status, self::OPEN_STATUSES, true)) {
            throw new \moodle_exception('grading_error_closed', 'local_tm_course');
        }
        $ok = false;
        foreach (self::list_graders((int)$req->courseid, (string)$req->modname) as $g) {
            if ((int)$g->id === $assigneeid) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new \moodle_exception('grading_error_not_grader', 'local_tm_course');
        }
        $now = time();
        $DB->set_field('local_tm_course_grreq', 'assigneeid', $assigneeid, ['id' => $requestid]);
        $status = ((int)$req->status === self::STATUS_IN_PROGRESS) ? self::STATUS_IN_PROGRESS : self::STATUS_ASSIGNED;
        $DB->set_field('local_tm_course_grreq', 'status', $status, ['id' => $requestid]);
        $DB->set_field('local_tm_course_grreq', 'timemodified', $now, ['id' => $requestid]);
        unset($actorid);
        try {
            notification_helper::notify_grading_assigned($requestid);
        } catch (\Throwable $e) {
            debugging('TM grading notify assigned failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    public static function reject(int $requestid, string $reason): void {
        global $DB;
        $req = self::get_request($requestid);
        if (!$req) {
            throw new \moodle_exception('grading_error_notfound', 'local_tm_course');
        }
        if (!self::user_is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        if (!in_array((int)$req->status, self::OPEN_STATUSES, true)) {
            throw new \moodle_exception('grading_error_closed', 'local_tm_course');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \moodle_exception('grading_error_reason_required', 'local_tm_course');
        }
        $now = time();
        $DB->set_field('local_tm_course_grreq', 'status', self::STATUS_REJECTED, ['id' => $requestid]);
        $DB->set_field('local_tm_course_grreq', 'rejectreason', $reason, ['id' => $requestid]);
        $DB->set_field('local_tm_course_grreq', 'timemodified', $now, ['id' => $requestid]);
        try {
            notification_helper::notify_grading_closed($requestid, 'rejected');
        } catch (\Throwable $e) {
            debugging('TM grading notify closed failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    public static function cancel(int $requestid, int $userid): void {
        global $DB;
        $req = self::get_request($requestid);
        if (!$req) {
            throw new \moodle_exception('grading_error_notfound', 'local_tm_course');
        }
        $isowner = ((int)$req->requesterid === $userid) && self::user_can_apply();
        if (!self::user_is_admin() && !$isowner) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        if (!in_array((int)$req->status, self::OPEN_STATUSES, true)) {
            throw new \moodle_exception('grading_error_closed', 'local_tm_course');
        }
        if ($isowner && !self::user_is_admin()) {
            if ((int)$req->assigneeid > 0) {
                throw new \moodle_exception('grading_error_cannot_cancel', 'local_tm_course');
            }
            $hasgrade = false;
            $activity = self::get_activity((int)$req->cmid);
            foreach (self::get_items($requestid) as $item) {
                if ((int)$item->itemstatus === self::ITEM_GRADED) {
                    $hasgrade = true;
                    break;
                }
                if ($activity && $activity['exists']) {
                    $g = self::gradebook_grade((int)$req->courseid, (string)$req->modname, (int)$activity['instanceid'], (int)$item->userid);
                    if (!empty($g['has'])) {
                        $hasgrade = true;
                        break;
                    }
                }
            }
            if ($hasgrade) {
                throw new \moodle_exception('grading_error_cannot_cancel', 'local_tm_course');
            }
        }
        $now = time();
        $DB->set_field('local_tm_course_grreq', 'status', self::STATUS_CANCELLED, ['id' => $requestid]);
        $DB->set_field('local_tm_course_grreq', 'timemodified', $now, ['id' => $requestid]);
        try {
            notification_helper::notify_grading_closed($requestid, 'cancelled');
        } catch (\Throwable $e) {
            debugging('TM grading notify closed failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Admin reopen of a rejected or cancelled request.
     */
    public static function restore(int $requestid): void {
        global $DB;
        $req = self::get_request($requestid);
        if (!$req) {
            throw new \moodle_exception('grading_error_notfound', 'local_tm_course');
        }
        if (!self::user_is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        if (!in_array((int)$req->status, [self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
            throw new \moodle_exception('grading_error_cannot_restore', 'local_tm_course');
        }
        foreach (self::get_items($requestid) as $item) {
            $dup = self::open_duplicate_requestid((int)$req->cmid, (int)$item->userid, $requestid);
            if ($dup > 0) {
                throw new \moodle_exception('grading_error_restore_duplicate', 'local_tm_course', '', $dup);
            }
        }
        $now = time();
        $status = ((int)$req->assigneeid > 0) ? self::STATUS_ASSIGNED : self::STATUS_PENDING;
        $DB->set_field('local_tm_course_grreq', 'status', $status, ['id' => $requestid]);
        $DB->set_field('local_tm_course_grreq', 'rejectreason', null, ['id' => $requestid]);
        $DB->set_field('local_tm_course_grreq', 'timemodified', $now, ['id' => $requestid]);
        self::sync_request($requestid);
    }

    /**
     * @return \stdClass[]
     */
    public static function list_requests(string $view, int $userid, int $limit = 100): array {
        global $DB;
        $where = '1=1';
        $params = [];
        if ($view === 'pending') {
            list($insql, $params) = $DB->get_in_or_equal(self::OPEN_STATUSES, SQL_PARAMS_NAMED);
            $where = "r.status $insql AND r.assigneeid = 0";
        } else if ($view === 'assigned') {
            list($insql, $params) = $DB->get_in_or_equal(self::OPEN_STATUSES, SQL_PARAMS_NAMED);
            $params['uid'] = $userid;
            $where = "r.status $insql AND r.assigneeid = :uid";
        } else if ($view === 'mine') {
            $params['uid'] = $userid;
            $where = 'r.requesterid = :uid';
        } else if ($view === 'all') {
            $where = '1=1';
        } else {
            $params['uid'] = $userid;
            $where = 'r.requesterid = :uid';
        }
        $sql = "SELECT r.*, c.fullname AS coursename
                  FROM {local_tm_course_grreq} r
             LEFT JOIN {course} c ON c.id = r.courseid
                 WHERE $where
              ORDER BY r.timemodified DESC, r.id DESC";
        return array_values($DB->get_records_sql($sql, $params, 0, $limit));
    }

    public static function progress_counts(\stdClass $req): array {
        $items = self::get_items((int)$req->id);
        $total = count($items);
        $done = 0;
        foreach ($items as $item) {
            if ((int)$item->itemstatus === self::ITEM_GRADED || (int)$item->itemstatus === self::ITEM_MISSING) {
                $done++;
            }
        }
        return ['done' => $done, 'total' => $total];
    }

    public static function status_label(int $status): string {
        switch ($status) {
            case self::STATUS_ASSIGNED:
                return get_string('grading_status_assigned', 'local_tm_course');
            case self::STATUS_IN_PROGRESS:
                return get_string('grading_status_inprogress', 'local_tm_course');
            case self::STATUS_COMPLETED:
                return get_string('grading_status_completed', 'local_tm_course');
            case self::STATUS_REJECTED:
                return get_string('grading_status_rejected', 'local_tm_course');
            case self::STATUS_CANCELLED:
                return get_string('grading_status_cancelled', 'local_tm_course');
            default:
                return get_string('grading_status_pending', 'local_tm_course');
        }
    }

    public static function item_status_label(int $status): string {
        if ($status === self::ITEM_GRADED) {
            return get_string('grading_item_graded', 'local_tm_course');
        }
        if ($status === self::ITEM_MISSING) {
            return get_string('grading_item_missing', 'local_tm_course');
        }
        return get_string('grading_item_pending', 'local_tm_course');
    }
}
