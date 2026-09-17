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

    /** Soft limits for resolution / support template fields (validation errors, never silent truncate). */
    public const MAX_RESOLUTION_STEPS = 50;
    public const MAX_RESOLUTION_STEP_CHARS = 500;
    public const MAX_EXTERNAL_SUPPORT_CHARS = 1000;

    // ----------------------------------------------------------------
    // Resolution methods / external support helpers
    // ----------------------------------------------------------------

    /**
     * Split Excel / textarea text into checklist labels.
     * Official template uses LF + numbered lines: "1. …\n2. …\n3. …".
     *
     * @return string[]
     */
    public static function parse_resolution_methods_text(string $raw): array {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Strip leading "1." / "1、" / "1．" / "1)" style numbering from the official template.
            $stripped = preg_replace('/^\d+[\.．、\)]\s*/u', '', $line);
            if ($stripped !== null) {
                $line = $stripped;
            }
            $stripped = preg_replace('/^[（(]\d+[）)]\s*/u', '', $line);
            if ($stripped !== null) {
                $line = $stripped;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Validate parsed resolution steps. Returns null when OK, else a short machine key:
     * too_many | step_long | step_empty_after_clean
     */
    public static function validate_resolution_methods(array $steps): ?string {
        if (count($steps) > self::MAX_RESOLUTION_STEPS) {
            return 'too_many';
        }
        foreach ($steps as $step) {
            $step = (string) $step;
            if ($step === '') {
                return 'step_empty_after_clean';
            }
            if (\core_text::strlen($step) > self::MAX_RESOLUTION_STEP_CHARS) {
                return 'step_long';
            }
            $cleaned = clean_param($step, PARAM_TEXT);
            if ($cleaned === '') {
                return 'step_empty_after_clean';
            }
            if (\core_text::strlen($cleaned) > self::MAX_RESOLUTION_STEP_CHARS) {
                return 'step_long';
            }
        }
        return null;
    }

    /**
     * @param string[] $steps
     * @return string JSON (empty array → '')
     */
    public static function encode_resolution_methods(array $steps): string {
        $clean = [];
        foreach ($steps as $step) {
            $s = trim(clean_param((string) $step, PARAM_TEXT));
            if ($s === '') {
                continue;
            }
            $clean[] = $s;
        }
        if (empty($clean)) {
            return '';
        }
        return json_encode(array_values($clean), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return string[]
     */
    public static function decode_resolution_methods(?string $json): array {
        $json = trim((string) $json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $step) {
            if (!is_string($step) && !is_numeric($step)) {
                continue;
            }
            $s = trim((string) $step);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * Normalize external support text. Returns '' when blank.
     * Does not truncate — caller must validate length first.
     */
    public static function normalize_external_support(string $raw): string {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        if ($raw === '') {
            return '';
        }
        return clean_param($raw, PARAM_TEXT);
    }

    /**
     * @return string|null null = OK; 'too_long' when over limit
     */
    public static function validate_external_support(string $raw): ?string {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        if ($raw === '') {
            return null;
        }
        if (\core_text::strlen($raw) > self::MAX_EXTERNAL_SUPPORT_CHARS) {
            return 'too_long';
        }
        $cleaned = clean_param($raw, PARAM_TEXT);
        if ($cleaned !== '' && \core_text::strlen($cleaned) > self::MAX_EXTERNAL_SUPPORT_CHARS) {
            return 'too_long';
        }
        return null;
    }

    /**
     * @param string[] $checked
     */
    public static function encode_resolution_checked(array $checked): string {
        return self::encode_resolution_methods($checked);
    }

    /**
     * @return string[]
     */
    public static function decode_resolution_checked(?string $json): array {
        return self::decode_resolution_methods($json);
    }

    /**
     * Filter submitted checked labels to those present in the item template (order preserved as submitted).
     *
     * @param string[] $submitted
     * @param string[] $allowed
     * @return string[]
     */
    public static function filter_resolution_checked(array $submitted, array $allowed): array {
        if (empty($submitted) || empty($allowed)) {
            return [];
        }
        $allowset = [];
        foreach ($allowed as $label) {
            $allowset[(string) $label] = true;
        }
        $out = [];
        $seen = [];
        foreach ($submitted as $label) {
            $label = trim((string) $label);
            if ($label === '' || !isset($allowset[$label]) || isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;
            $out[] = $label;
        }
        return $out;
    }

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
     * Batch-load checklist items for many courses (avoids N+1 on the maintenance overview).
     *
     * @param int[] $courseids
     * @return array<int,\stdClass[]> courseid => items ordered by sortorder, id
     */
    public static function get_items_by_courses(array $courseids): array {
        global $DB;
        $ids = [];
        foreach ($courseids as $cid) {
            $cid = (int) $cid;
            if ($cid > 0) {
                $ids[$cid] = $cid;
            }
        }
        $out = [];
        foreach ($ids as $cid) {
            $out[$cid] = [];
        }
        if (empty($ids)) {
            return $out;
        }
        list($insql, $params) = $DB->get_in_or_equal(array_values($ids), SQL_PARAMS_NAMED, 'cid');
        $rows = $DB->get_records_select(
            'local_tm_equip_check_item',
            "courseid $insql",
            $params,
            'courseid ASC, sortorder ASC, id ASC'
        );
        foreach ($rows as $row) {
            $cid = (int) $row->courseid;
            if (!isset($out[$cid])) {
                $out[$cid] = [];
            }
            $out[$cid][] = $row;
        }
        return $out;
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
            $rec->resolution_methods = self::encode_resolution_methods(
                self::normalize_resolution_input(
                    $item['resolution_methods'] ?? ($item['resolution_methods_text'] ?? [])
                )
            );
            $rec->external_support = self::normalize_external_support((string) ($item['external_support'] ?? ''));
            $rec->enabled = !empty($item['enabled']) ? 1 : (isset($item['enabled']) ? 0 : 1);
            $rec->sortorder = (int) ($item['sortorder'] ?? $sort);
            $rec->timecreated = $now;
            $rec->timemodified = $now;
            $DB->insert_record('local_tm_equip_check_item', $rec);
            $sort += 10;
        }
        $tx->allow_commit();
    }

    /**
     * Accept array of steps, JSON string, or newline-separated textarea text.
     *
     * @param mixed $raw
     * @return string[]
     */
    public static function normalize_resolution_input($raw): array {
        if (is_array($raw)) {
            $steps = [];
            foreach ($raw as $step) {
                if (is_string($step) || is_numeric($step)) {
                    $s = trim((string) $step);
                    if ($s !== '') {
                        $steps[] = $s;
                    }
                }
            }
            return $steps;
        }
        $text = (string) $raw;
        // JSON array from API?
        $trim = trim($text);
        if ($trim !== '' && ($trim[0] === '[')) {
            $decoded = self::decode_resolution_methods($trim);
            if (!empty($decoded) || $trim === '[]') {
                return $decoded;
            }
        }
        return self::parse_resolution_methods_text($text);
    }

    /**
     * Build the natural duplicate key used for import / dedupe checks.
     * Key = itemname (trimmed) + scope + checktype. enabled is intentionally excluded.
     */
    public static function make_duplicate_key(string $itemname, string $scope, string $checktype): string {
        return trim($itemname) . "\0" . $scope . "\0" . $checktype;
    }

    /**
     * @return array<string,true> set of duplicate keys already stored for the course
     */
    public static function get_duplicate_key_set(int $courseid): array {
        $set = [];
        foreach (self::get_items_by_course($courseid) as $row) {
            $key = self::make_duplicate_key((string) $row->itemname, (string) $row->scope, (string) $row->checktype);
            $set[$key] = true;
        }
        return $set;
    }

    /**
     * Next sortorder after the current max for this course (10-step increments, matching save_items_for_course).
     */
    public static function get_next_sortorder(int $courseid): int {
        global $DB;
        $max = $DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {local_tm_equip_check_item} WHERE courseid = ?',
            [$courseid]
        );
        if ($max === false || $max === null) {
            return 10;
        }
        return ((int) $max) + 10;
    }

    /**
     * Append a single checklist item without deleting existing rows (safe for import).
     * Caller must pass already-validated scope/checktype/enabled values.
     *
     * @return int new record id
     */
    public static function create_item(
        int $courseid,
        string $itemname,
        string $scope,
        string $checktype,
        int $enabled,
        ?int $sortorder = null,
        array $resolutionmethods = [],
        string $externalsupport = ''
    ): int {
        global $DB;
        $name = trim($itemname);
        if ($name === '' || $courseid <= 0) {
            throw new \invalid_parameter_exception('Invalid equipment check item');
        }
        if (!in_array($scope, [self::SCOPE_ONSITE, self::SCOPE_ONLINE, self::SCOPE_BOTH], true)) {
            throw new \invalid_parameter_exception('Invalid equipment check scope');
        }
        if (!in_array($checktype, [self::TYPE_STATUS, self::TYPE_TASK], true)) {
            throw new \invalid_parameter_exception('Invalid equipment check type');
        }
        $now = time();
        $rec = new \stdClass();
        $rec->courseid = $courseid;
        $rec->scope = $scope;
        $rec->checktype = $checktype;
        $rec->itemname = clean_param($name, PARAM_TEXT);
        $rec->resolution_methods = self::encode_resolution_methods($resolutionmethods);
        $rec->external_support = self::normalize_external_support($externalsupport);
        $rec->enabled = $enabled ? 1 : 0;
        $rec->sortorder = $sortorder !== null ? (int) $sortorder : self::get_next_sortorder($courseid);
        $rec->timecreated = $now;
        $rec->timemodified = $now;
        return (int) $DB->insert_record('local_tm_equip_check_item', $rec);
    }

    /**
     * Append multiple validated items in one transaction. Skips keys already in $skipto or DB.
     *
     * @param array<int,array{itemname:string,scope:string,checktype:string,enabled:int,resolution_methods?:string[],external_support?:string}> $items
     * @return array{inserted:int,skipped:int,ids:int[]}
     */
    public static function append_items(int $courseid, array $items): array {
        global $DB;
        $existing = self::get_duplicate_key_set($courseid);
        $sort = self::get_next_sortorder($courseid);
        $inserted = 0;
        $skipped = 0;
        $ids = [];
        $tx = $DB->start_delegated_transaction();
        foreach ($items as $item) {
            $name = trim((string) ($item['itemname'] ?? ''));
            $scope = (string) ($item['scope'] ?? '');
            $checktype = (string) ($item['checktype'] ?? '');
            $enabled = !empty($item['enabled']) ? 1 : 0;
            $methods = self::normalize_resolution_input($item['resolution_methods'] ?? []);
            $support = (string) ($item['external_support'] ?? '');
            $key = self::make_duplicate_key($name, $scope, $checktype);
            if ($name === '' || isset($existing[$key])) {
                $skipped++;
                continue;
            }
            $id = self::create_item($courseid, $name, $scope, $checktype, $enabled, $sort, $methods, $support);
            $existing[$key] = true;
            $ids[] = $id;
            $inserted++;
            $sort += 10;
        }
        $tx->allow_commit();
        return ['inserted' => $inserted, 'skipped' => $skipped, 'ids' => $ids];
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
                    'resolution_methods' => self::decode_resolution_methods($item->resolution_methods ?? ''),
                    'external_support' => (string) ($item->external_support ?? ''),
                    'checkstatus' => $checkstatus,
                    'remark' => $log ? (string) $log->remark : '',
                    'resolution_checked' => $log
                        ? self::decode_resolution_checked($log->resolution_checked ?? '')
                        : [],
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
     * @param array<int,array{checkstatus?:int,remark?:string,resolution_checked?:string[]}> $results keyed by itemid
     */
    public static function save_desk_checks(int $sessionid, int $desknumber, array $results, int $userid): void {
        global $DB;
        if ($sessionid <= 0 || $desknumber <= 0 || empty($results)) {
            return;
        }
        $itemids = array_map('intval', array_keys($results));
        $itemids = array_values(array_filter($itemids, static function (int $id): bool {
            return $id > 0;
        }));
        $templates = [];
        if (!empty($itemids)) {
            $templates = $DB->get_records_list('local_tm_equip_check_item', 'id', $itemids);
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

            $resolutionchecked = '';
            if ($checkstatus === self::STATUS_ABNORMAL) {
                $allowed = [];
                if (isset($templates[$itemid])) {
                    $allowed = self::decode_resolution_methods($templates[$itemid]->resolution_methods ?? '');
                }
                $submitted = is_array($data['resolution_checked'] ?? null)
                    ? $data['resolution_checked']
                    : [];
                $filtered = self::filter_resolution_checked($submitted, $allowed);
                $resolutionchecked = self::encode_resolution_checked($filtered);
            }

            $existing = $DB->get_record('local_tm_equip_check_log', [
                'sessionid' => $sessionid,
                'desknumber' => $desknumber,
                'itemid' => $itemid,
            ], '*', IGNORE_MISSING);

            if ($existing) {
                $existing->checkstatus = $checkstatus;
                $existing->remark = $remark;
                $existing->resolution_checked = $resolutionchecked;
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
                $rec->resolution_checked = $resolutionchecked;
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
                    $existing->resolution_checked = (string) ($srclog->resolution_checked ?? '');
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
                    $rec->resolution_checked = (string) ($srclog->resolution_checked ?? '');
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
