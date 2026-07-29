<?php
/**
 * Equipment Check Manager — "上課準備事項" 設備檢查
 *
 * Manages per-course equipment checklist templates and per-session,
 * per-desk check results. Desk logic mirrors attendance/enrolment desk
 * handling: onsite sessions record one result set per physical desk
 * (1..num_desks); online sessions are always treated as a single desk
 * (the instructor's own camera/mic/etc set).
 *
 * @package    local_tm_course
 * @copyright  2026 Techman Robot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/session_manager.php');

class equipment_check_manager {

    // Applicability scope, mirrors verification_manager::apply_mode convention.
    public const SCOPE_ONSITE = 'onsite';
    public const SCOPE_ONLINE = 'online';
    public const SCOPE_BOTH   = 'both';

    // Item type.
    public const TYPE_STATUS = 'status';
    public const TYPE_TASK   = 'task';

    // local_tm_equip_check_log.checkstatus values.
    public const STATUS_UNSET    = 0; // Not checked / task not done.
    public const STATUS_NORMAL   = 1; // Normal (status type) / done (task type).
    public const STATUS_ABNORMAL = 2; // Abnormal (status type only).

    /** Desk number used for online sessions (single "instructor's own set"). */
    public const ONLINE_DESK_NUMBER = 1;

    // ----------------------------------------------------------------
    // Item template CRUD (maintenance page)
    // ----------------------------------------------------------------

    /**
     * @return \stdClass[] all items configured for a course (enabled and disabled), ordered.
     */
    public static function get_items_by_course(int $courseid): array {
        global $DB;
        return $DB->get_records('local_tm_equip_check_item', ['courseid' => $courseid], 'sortorder ASC, id ASC');
    }

    /**
     * Replace the full checklist template for one course (delete + reinsert),
     * matching the pattern used by verification_manager::save_questions_for_course().
     *
     * @param array<int,array<string,mixed>> $items
     */
    public static function save_items_for_course(int $courseid, array $items): void {
        global $DB;
        $tx = $DB->start_delegated_transaction();
        $DB->delete_records('local_tm_equip_check_item', ['courseid' => $courseid]);
        $now = time();
        $sort = 10;
        foreach ($items as $item) {
            $name = trim((string) ($item['itemname'] ?? ''));
            if ($name === '') {
                continue;
            }
            $scope = strtolower(trim((string) ($item['scope'] ?? self::SCOPE_BOTH)));
            if (!in_array($scope, [self::SCOPE_ONSITE, self::SCOPE_ONLINE, self::SCOPE_BOTH], true)) {
                $scope = self::SCOPE_BOTH;
            }
            $checktype = strtolower(trim((string) ($item['checktype'] ?? self::TYPE_STATUS)));
            if (!in_array($checktype, [self::TYPE_STATUS, self::TYPE_TASK], true)) {
                $checktype = self::TYPE_STATUS;
            }
            $rec = new \stdClass();
            $rec->courseid = $courseid;
            $rec->scope = $scope;
            $rec->checktype = $checktype;
            $rec->itemname = clean_param($name, PARAM_TEXT);
            $rec->enabled = !empty($item['enabled']) ? 1 : (isset($item['enabled']) ? 0 : 1);
            $rec->sortorder = (int) ($item['sortorder'] ?? $sort);
            $rec->timecreated = $now;
            $rec->timemodified = $now;
            $DB->insert_record('local_tm_equip_check_item', $rec);
            $sort += 10;
        }
        $tx->allow_commit();
    }

    // ----------------------------------------------------------------
    // Applicability: which items show for a given session
    // ----------------------------------------------------------------

    /**
     * True when the session's delivery mode is online.
     */
    public static function is_online_session(\stdClass $session): bool {
        return ((string) ($session->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE);
    }

    /**
     * Number of desks to record equipment checks for.
     * Onsite: session's configured desk count (min 1). Online: always 1 (instructor's own set).
     */
    public static function get_desk_count(\stdClass $session): int {
        if (self::is_online_session($session)) {
            return self::ONLINE_DESK_NUMBER;
        }
        return max(1, (int) ($session->num_desks ?? 0));
    }

    /**
     * Checklist items applicable to this session: matched by linked course + onsite/online scope.
     *
     * @return \stdClass[] enabled items ordered by sortorder
     */
    public static function get_applicable_items(\stdClass $session): array {
        global $DB;
        $courseid = (int) ($session->courseid ?? 0);
        if ($courseid <= 0) {
            return [];
        }
        $isonline = self::is_online_session($session);
        $params = ['courseid' => $courseid, 'both' => self::SCOPE_BOTH];
        $modesql = $isonline ? self::SCOPE_ONLINE : self::SCOPE_ONSITE;
        $params['mode'] = $modesql;
        return $DB->get_records_select(
            'local_tm_equip_check_item',
            'courseid = :courseid AND enabled = 1 AND (scope = :mode OR scope = :both)',
            $params,
            'sortorder ASC, id ASC'
        );
    }

    // ----------------------------------------------------------------
    // Per-session, per-desk results
    // ----------------------------------------------------------------

    /**
     * Build the full class-prep equipment check view for a session: desks 1..N,
     * each carrying the applicable items with their current saved result (or unset default).
     *
     * @return array{
     *   is_online:bool,
     *   items:\stdClass[],
     *   desks:array<int,array{desk_number:int,items:array<int,array{itemid:int,checktype:string,itemname:string,scope:string,checkstatus:int,remark:string,checkedby:int,checkedby_name:string,timemodified:int}>,completed:int,total:int}>
     * }
     */
    public static function get_check_view(int $sessionid): array {
        global $DB;

        $session = session_manager::get_session($sessionid);
        $items = self::get_applicable_items($session);
        $deskcount = self::get_desk_count($session);
        $isonline = self::is_online_session($session);

        $itemids = array_map(static function (\stdClass $item): int {
            return (int) $item->id;
        }, $items);

        $logsbydeskitem = [];
        $checkers = [];
        if (!empty($itemids)) {
            list($insql, $params) = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'itemid');
            $params['sessionid'] = $sessionid;
            $rows = $DB->get_records_select(
                'local_tm_equip_check_log',
                "sessionid = :sessionid AND itemid $insql",
                $params
            );
            foreach ($rows as $row) {
                $logsbydeskitem[(int) $row->desknumber][(int) $row->itemid] = $row;
                if (!empty($row->checkedby)) {
                    $checkers[(int) $row->checkedby] = true;
                }
            }
        }
        $usernames = [];
        if (!empty($checkers)) {
            $userrecords = $DB->get_records_list('user', 'id', array_keys($checkers), '', 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename');
            foreach ($userrecords as $uid => $u) {
                $usernames[$uid] = fullname($u);
            }
        }

        $desks = [];
        for ($d = 1; $d <= $deskcount; $d++) {
            $deskitems = [];
            $completed = 0;
            foreach ($items as $item) {
                $itemid = (int) $item->id;
                $log = $logsbydeskitem[$d][$itemid] ?? null;
                $checkstatus = $log ? (int) $log->checkstatus : self::STATUS_UNSET;
                if ($checkstatus !== self::STATUS_UNSET) {
                    $completed++;
                }
                $deskitems[] = [
                    'itemid' => $itemid,
                    'checktype' => (string) $item->checktype,
                    'itemname' => (string) $item->itemname,
                    'scope' => (string) $item->scope,
                    'checkstatus' => $checkstatus,
                    'remark' => $log ? (string) $log->remark : '',
                    'checkedby' => $log ? (int) ($log->checkedby ?? 0) : 0,
                    'checkedby_name' => ($log && !empty($log->checkedby)) ? ($usernames[(int) $log->checkedby] ?? '') : '',
                    'timemodified' => $log ? (int) $log->timemodified : 0,
                ];
            }
            $desks[] = [
                'desk_number' => $d,
                'items' => $deskitems,
                'completed' => $completed,
                'total' => count($items),
            ];
        }

        return [
            'is_online' => $isonline,
            'items' => $items,
            'desks' => $desks,
        ];
    }

    /**
     * Save one desk's checklist results (upsert per item).
     *
     * @param array<int,array{checkstatus?:int,remark?:string}> $results keyed by itemid
     */
    public static function save_desk_checks(int $sessionid, int $desknumber, array $results, int $userid): void {
        global $DB;
        if ($sessionid <= 0 || $desknumber <= 0 || empty($results)) {
            return;
        }
        $now = time();
        $tx = $DB->start_delegated_transaction();
        foreach ($results as $itemid => $data) {
            $itemid = (int) $itemid;
            if ($itemid <= 0) {
                continue;
            }
            $checkstatus = (int) ($data['checkstatus'] ?? self::STATUS_UNSET);
            if (!in_array($checkstatus, [self::STATUS_UNSET, self::STATUS_NORMAL, self::STATUS_ABNORMAL], true)) {
                $checkstatus = self::STATUS_UNSET;
            }
            $remark = clean_param((string) ($data['remark'] ?? ''), PARAM_TEXT);

            $existing = $DB->get_record('local_tm_equip_check_log', [
                'sessionid' => $sessionid,
                'desknumber' => $desknumber,
                'itemid' => $itemid,
            ], '*', IGNORE_MISSING);

            if ($existing) {
                $existing->checkstatus = $checkstatus;
                $existing->remark = $remark;
                $existing->checkedby = $userid;
                $existing->timemodified = $now;
                $DB->update_record('local_tm_equip_check_log', $existing);
            } else {
                $rec = new \stdClass();
                $rec->sessionid = $sessionid;
                $rec->desknumber = $desknumber;
                $rec->itemid = $itemid;
                $rec->checkstatus = $checkstatus;
                $rec->remark = $remark;
                $rec->checkedby = $userid;
                $rec->timecreated = $now;
                $rec->timemodified = $now;
                $DB->insert_record('local_tm_equip_check_log', $rec);
            }
        }
        $tx->allow_commit();
    }

    /**
     * One-click sync: copy one desk's saved checklist results to every other desk of the session.
     * Overwrites existing results on the other desks. Returns the number of desks synced to.
     */
    public static function sync_desk_to_all(int $sessionid, int $sourcedesk, int $userid): int {
        global $DB;
        $session = session_manager::get_session($sessionid);
        $deskcount = self::get_desk_count($session);
        if ($deskcount <= 1) {
            return 0;
        }

        $sourcelogs = $DB->get_records('local_tm_equip_check_log', [
            'sessionid' => $sessionid,
            'desknumber' => $sourcedesk,
        ]);

        $now = time();
        $synced = 0;
        $tx = $DB->start_delegated_transaction();
        for ($d = 1; $d <= $deskcount; $d++) {
            if ($d === $sourcedesk) {
                continue;
            }
            foreach ($sourcelogs as $srclog) {
                $itemid = (int) $srclog->itemid;
                $existing = $DB->get_record('local_tm_equip_check_log', [
                    'sessionid' => $sessionid,
                    'desknumber' => $d,
                    'itemid' => $itemid,
                ], '*', IGNORE_MISSING);
                if ($existing) {
                    $existing->checkstatus = (int) $srclog->checkstatus;
                    $existing->remark = (string) $srclog->remark;
                    $existing->checkedby = $userid;
                    $existing->timemodified = $now;
                    $DB->update_record('local_tm_equip_check_log', $existing);
                } else {
                    $rec = new \stdClass();
                    $rec->sessionid = $sessionid;
                    $rec->desknumber = $d;
                    $rec->itemid = $itemid;
                    $rec->checkstatus = (int) $srclog->checkstatus;
                    $rec->remark = (string) $srclog->remark;
                    $rec->checkedby = $userid;
                    $rec->timecreated = $now;
                    $rec->timemodified = $now;
                    $DB->insert_record('local_tm_equip_check_log', $rec);
                }
            }
            $synced++;
        }
        $tx->allow_commit();
        return $synced;
    }
}
