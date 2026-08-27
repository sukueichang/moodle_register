<?php
/**
 * Push TM course sessions to TCMS (Phase 1: Moodle -> TCMS A-1).
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/enabled_course_manager.php');
require_once(__DIR__ . '/classroom_manager.php');
require_once(__DIR__ . '/enrolment_manager.php');
require_once(__DIR__ . '/session_manager.php');
require_once(__DIR__ . '/tcms_endpoint.php');

class tcms_sync_manager {

    const SYNC_OK = 'ok';
    const SYNC_PENDING = 'pending';
    const SYNC_ERROR = 'error';
    const SYNC_SKIPPED = 'skipped';

    /** @return bool */
    public static function is_enabled(): bool {
        return (bool) get_config('local_tm_course', 'tcms_sync_enabled');
    }

    /**
     * Whether this session row should sync to TCMS (standard course sessions only).
     */
    public static function should_sync_session(\stdClass $session): bool {
        if (!self::is_enabled()) {
            return false;
        }
        if (session_manager::is_room_closed_session($session)) {
            return false;
        }
        $kind = (string) ($session->session_kind ?? session_manager::SESSION_KIND_STANDARD);
        if ($kind !== session_manager::SESSION_KIND_STANDARD) {
            return false;
        }
        $courseid = (int) ($session->courseid ?? 0);
        if ($courseid <= 0) {
            return false;
        }
        $enabled = enabled_course_manager::get_enabled_ids();
        if (!in_array($courseid, $enabled, true)) {
            return false;
        }
        return self::session_meets_sync_from_date($session);
    }

    /**
     * Earliest session start (unix) allowed for Moodle→TCMS sync.
     * Empty setting = no date filter.
     *
     * @return int 0 = no filter
     */
    public static function sync_from_timestamp(): int {
        $raw = trim((string) get_config('local_tm_course', 'tcms_sync_from_date'));
        if ($raw === '') {
            return 0;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return 0;
        }
        $tz = \core_date::get_server_timezone();
        try {
            $dt = new \DateTime($raw . ' 00:00:00', new \DateTimeZone($tz));
        } catch (\Exception $e) {
            return 0;
        }
        return (int) $dt->getTimestamp();
    }

    /**
     * Session starttime must be on/after configured sync-from date (server timezone day start).
     */
    public static function session_meets_sync_from_date(\stdClass $session): bool {
        $from = self::sync_from_timestamp();
        if ($from <= 0) {
            return true;
        }
        $start = (int) ($session->starttime ?? 0);
        return $start >= $from;
    }

    /**
     * Push create/update to TCMS after session changes.
     */
    public static function push_session(int $sessionid): void {
        global $DB;

        $session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid], '*', IGNORE_MISSING);
        if (!$session) {
            return;
        }
        if (!self::should_sync_session($session)) {
            $skipreason = '';
            if (self::is_enabled() && !self::session_meets_sync_from_date($session)) {
                $from = trim((string) get_config('local_tm_course', 'tcms_sync_from_date'));
                $skipreason = $from !== '' ? ('Before sync-from date ' . $from) : 'Before sync-from date';
            }
            self::mark_sync_state($sessionid, self::SYNC_SKIPPED, $skipreason);
            return;
        }

        $payload = self::build_payload($session);
        if ($payload === null) {
            self::mark_sync_state($sessionid, self::SYNC_ERROR, 'Invalid session payload');
            return;
        }

        $base = self::api_base_url();
        $token = self::api_token();
        if ($base === '' || $token === '') {
            self::mark_sync_state($sessionid, self::SYNC_ERROR, 'TCMS API URL or token not configured');
            return;
        }

        $url = tcms_endpoint::sessions_collection_url($base);
        $sendpayload = $payload;
        unset($sendpayload['_hash']);
        $response = self::http_request('POST', $url, $token, $sendpayload);
        if (!$response['ok']) {
            self::mark_sync_state($sessionid, self::SYNC_ERROR, $response['error']);
            return;
        }

        $data = $response['data'];
        $tcmsid = isset($data['id']) ? (string) $data['id'] : '';
        self::mark_sync_state($sessionid, self::SYNC_OK, '', $tcmsid, $payload['_hash']);
    }

    /**
     * Delete TCMS mirror before Moodle session is removed.
     *
     * @param bool $force When true, delete even if outbound sync is currently disabled (used by purge-on-disable).
     */
    public static function delete_remote_for_session(\stdClass $session, bool $force = false): void {
        if (!$force && !self::is_enabled()) {
            return;
        }
        // Room blocks owned by TCMS must not trigger Moodle→TCMS delete.
        if (session_manager::is_room_closed_session($session)) {
            return;
        }
        if (!$force) {
            $hastcms = trim((string) ($session->tcms_session_id ?? '')) !== '';
            if (!$hastcms && !self::should_sync_session($session)) {
                return;
            }
        }

        $base = self::api_base_url();
        $token = self::api_token();
        if ($base === '' || $token === '') {
            return;
        }

        $moodleid = (int) $session->id;
        $url = tcms_endpoint::session_item_url($base, $moodleid);
        self::http_request('DELETE', $url, $token, null);
    }

    /**
     * When outbound sync is turned off: remove all previously pushed mirrors from TCMS
     * (manual Sync button + reconcile), then clear local sync markers.
     *
     * @return array{deleted:int,cleared:int,error:int}
     */
    public static function purge_all_pushed_mirrors(): array {
        global $DB;

        $stats = ['deleted' => 0, 'cleared' => 0, 'error' => 0];
        $base = self::api_base_url();
        $token = self::api_token();
        if ($base === '' || $token === '') {
            return $stats;
        }

        // Any row that was successfully pushed (or still has a TCMS id).
        $sessions = $DB->get_records_select(
            'local_tm_course_sessions',
            "(tcms_session_id IS NOT NULL AND tcms_session_id <> '') OR tcms_sync_status = :ok",
            ['ok' => self::SYNC_OK]
        );

        foreach ($sessions as $session) {
            if (session_manager::is_room_closed_session($session)) {
                continue;
            }
            $url = tcms_endpoint::session_item_url($base, (int) $session->id);
            $response = self::http_request('DELETE', $url, $token, null);
            // 404 / not found still counts as gone on TCMS.
            if ($response['ok'] || self::is_not_found_error($response)) {
                $stats['deleted']++;
                self::clear_sync_state((int) $session->id);
                $stats['cleared']++;
            } else {
                $stats['error']++;
                self::mark_sync_state(
                    (int) $session->id,
                    self::SYNC_ERROR,
                    'Purge on disable failed: ' . (string) ($response['error'] ?? 'unknown')
                );
            }
        }

        return $stats;
    }

    /**
     * @param array{ok?:bool,error?:string} $response
     */
    private static function is_not_found_error(array $response): bool {
        $err = strtolower((string) ($response['error'] ?? ''));
        return strpos($err, '404') !== false || strpos($err, 'not found') !== false;
    }

    /**
     * Clear local Moodle→TCMS sync fields after mirror removed.
     */
    public static function clear_sync_state(int $sessionid): void {
        global $DB;

        $update = (object) [
            'id' => $sessionid,
            'tcms_session_id' => null,
            'tcms_sync_status' => null,
            'tcms_sync_error' => null,
            'tcms_sync_hash' => null,
            'tcms_last_synced' => null,
            'timemodified' => time(),
        ];
        $DB->update_record('local_tm_course_sessions', $update);
    }

    /**
     * Reconcile all eligible sessions (scheduled task).
     *
     * @return array{ok:int,error:int,skipped:int}
     */
    public static function reconcile_all(): array {
        global $DB;

        $stats = ['ok' => 0, 'error' => 0, 'skipped' => 0];
        if (!self::is_enabled()) {
            return $stats;
        }

        $enabled = enabled_course_manager::get_enabled_ids();
        if (empty($enabled)) {
            return $stats;
        }

        list($insql, $params) = $DB->get_in_or_equal($enabled, SQL_PARAMS_NAMED, 'c');
        $params['kind'] = session_manager::SESSION_KIND_STANDARD;
        $fromsql = '';
        $fromts = self::sync_from_timestamp();
        if ($fromts > 0) {
            $fromsql = ' AND starttime >= :syncfrom';
            $params['syncfrom'] = $fromts;
        }
        $sessions = $DB->get_records_select(
            'local_tm_course_sessions',
            "session_kind = :kind AND courseid $insql" . $fromsql,
            $params
        );

        foreach ($sessions as $session) {
            if (!self::should_sync_session($session)) {
                $stats['skipped']++;
                continue;
            }
            self::push_session((int) $session->id);
            $after = (string) $DB->get_field('local_tm_course_sessions', 'tcms_sync_status', ['id' => $session->id]);
            if ($after === self::SYNC_OK) {
                $stats['ok']++;
            } else if ($after === self::SYNC_ERROR) {
                $stats['error']++;
            } else {
                $stats['skipped']++;
            }
        }

        set_config('tcms_sync_last_reconcile', time(), 'local_tm_course');
        return $stats;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function build_payload(\stdClass $session): ?array {
        global $DB;

        $start = (int) $session->starttime;
        $end = (int) $session->endtime;
        if ($start <= 0 || $end <= 0) {
            return null;
        }

        $tz = \core_date::get_server_timezone();
        $startdt = new \DateTime('@' . $start);
        $startdt->setTimezone(new \DateTimeZone($tz));
        $enddt = new \DateTime('@' . $end);
        $enddt->setTimezone(new \DateTimeZone($tz));

        $courseid = (int) $session->courseid;
        $course = $DB->get_record('course', ['id' => $courseid], 'id,fullname', IGNORE_MISSING);
        $title = trim((string) $session->name);
        if ($title === '' && $course) {
            $title = format_string($course->fullname, true) . ' ' . $startdt->format('Y/m/d');
        }

        $classroomid = (int) ($session->classroomid ?? 0);
        $classroomname = '';
        if ($classroomid > 0) {
            $room = $DB->get_record('local_tm_classroom', ['id' => $classroomid], 'id,name', IGNORE_MISSING);
            if ($room) {
                $classroomname = (string) $room->name;
            }
        }

        $location = self::resolve_location($session, $classroomid, $classroomname);
        $coursetypes = self::resolve_course_types($courseid, $course ? $course->fullname : '');

        $status = self::map_status_to_tcms((int) $session->status);
        $enrolstats = self::collect_enrolment_stats((int) $session->id);

        $core = [
            'source' => 'moodle',
            'moodleSessionId' => (int) $session->id,
            'moodleCourseId' => $courseid,
            'title' => $title,
            'startDate' => $startdt->format('Y-m-d'),
            'endDate' => $enddt->format('Y-m-d'),
            'startTime' => $startdt->format('H:i'),
            'endTime' => $enddt->format('H:i'),
            'courseTypes' => $coursetypes,
            'location' => $location,
            'moodleClassroomId' => $classroomid > 0 ? $classroomid : null,
            'moodleClassroomName' => $classroomname,
            'deliveryMode' => (string) ($session->delivery_mode ?? session_manager::DELIVERY_ONSITE),
            'status' => $status,
            'kpiArea' => 'A-1',
            'countForKpi' => true,
            'customerNames' => $enrolstats['customerNames'],
            'customerCount' => $enrolstats['customerCount'],
            'studentCount' => $enrolstats['studentCount'],
            'studentsReached' => $enrolstats['studentCount'],
        ];

        $core['_hash'] = sha1(json_encode($core));

        return $core;
    }

    /**
     * Approved roster → distinct companies + student headcount for TCMS A-1.
     *
     * @return array{customerNames:string,customerCount:int,studentCount:int}
     */
    public static function collect_enrolment_stats(int $sessionid): array {
        $names = [];
        try {
            $rows = enrolment_manager::get_session_roster_approved_rows($sessionid);
            foreach ($rows as $row) {
                $cells = enrolment_manager::format_attendance_roster_cells($row);
                $inst = trim((string) ($cells['institution'] ?? ''));
                if ($inst === '' || $inst === '—') {
                    continue;
                }
                $names[$inst] = true;
            }
        } catch (\Throwable $e) {
            $names = [];
        }
        $list = array_keys($names);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);
        $studentcount = 0;
        try {
            $studentcount = session_manager::confirmed_count($sessionid);
        } catch (\Throwable $e) {
            $studentcount = 0;
        }
        return [
            'customerNames' => implode('、', $list),
            'customerCount' => count($list),
            'studentCount' => (int) $studentcount,
        ];
    }

    public static function map_status_to_tcms(int $moodlestatus): string {
        if ($moodlestatus === session_manager::STATUS_CLOSED) {
            return 'Done';
        }
        return 'To Do';
    }

    /**
     * @return string[]
     */
    public static function resolve_course_types(int $courseid, string $coursefullname = ''): array {
        // Preferred: per-course dropdown selection saved in course mapping.
        $type = enabled_course_manager::get_tcms_course_type($courseid);
        if ($type !== '') {
            return [$type];
        }
        // Fallback: legacy JSON map.
        $map = self::parse_json_map((string) get_config('local_tm_course', 'tcms_course_type_map'));
        $key = (string) $courseid;
        if (isset($map[$key]) && $map[$key] !== '') {
            $type = trim((string) $map[$key]);
            return $type !== '' ? [$type] : ['Customized'];
        }
        return ['Customized'];
    }

    public static function resolve_location(\stdClass $session, int $classroomid, string $classroomname): string {
        if ((string) ($session->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE) {
            return 'Webcam';
        }
        // Preferred: per-classroom dropdown selection saved in classroom edit.
        if ($classroomid > 0) {
            $tcmsloc = classroom_manager::get_tcms_location($classroomid);
            if ($tcmsloc !== '') {
                return $tcmsloc;
            }
        }
        // Fallback: legacy JSON map.
        $map = self::parse_json_map((string) get_config('local_tm_course', 'tcms_classroom_location_map'));
        if ($classroomid > 0 && isset($map[(string) $classroomid]) && $map[(string) $classroomid] !== '') {
            return (string) $map[(string) $classroomid];
        }
        $loc = trim((string) ($session->location ?? ''));
        if ($loc !== '') {
            return $loc;
        }
        return $classroomname !== '' ? 'TM HQ' : '';
    }

    /**
     * @return array<string,string>
     */
    private static function parse_json_map(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = (string) $v;
        }
        return $out;
    }

    public static function api_base_url(): string {
        $url = trim((string) get_config('local_tm_course', 'tcms_api_base_url'));
        return tcms_endpoint::normalize_base_url($url);
    }

    public static function api_token(): string {
        // Must match VM env TCMS_MOODLE_SYNC_TOKEN — never hard-code.
        return trim((string) get_config('local_tm_course', 'tcms_sync_token'));
    }

    /**
     * Available TCMS dropdown options (courseTypes + locations).
     * Pulled from TCMS GET /api/sessions/schema when reachable, cached ~1h.
     * Schema may require TCMS login on VM; failure must not block session sync —
     * use cache or built-in fallback for dropdowns only.
     *
     * @return array{courseTypes:string[],locations:string[]}
     */
    public static function get_schema_options(): array {
        $cached = (string) get_config('local_tm_course', 'tcms_schema_cache');
        $cachetime = (int) get_config('local_tm_course', 'tcms_schema_cache_time');
        if ($cached !== '' && (time() - $cachetime) < 3600) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && !empty($decoded['courseTypes'])) {
                return self::normalize_schema_options($decoded);
            }
        }

        $remote = self::fetch_remote_schema();
        if ($remote !== null) {
            set_config('tcms_schema_cache', json_encode($remote), 'local_tm_course');
            set_config('tcms_schema_cache_time', time(), 'local_tm_course');
            return $remote;
        }

        if ($cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && !empty($decoded['courseTypes'])) {
                return self::normalize_schema_options($decoded);
            }
        }
        return self::fallback_schema_options();
    }

    /**
     * @return array{courseTypes:string[],locations:string[]}|null
     */
    private static function fetch_remote_schema(): ?array {
        global $CFG;
        $base = self::api_base_url();
        if ($base === '') {
            return null;
        }
        try {
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl();
            $options = [
                'CURLOPT_TIMEOUT' => 6,
                'CURLOPT_CONNECTTIMEOUT' => 4,
                'CURLOPT_RETURNTRANSFER' => 1,
                'CURLOPT_HEADER' => 0,
            ];
            $raw = $curl->get($base . '/api/sessions/schema', [], $options);
            $info = $curl->get_info();
            $code = (int) ($info['http_code'] ?? 0);
            if ($code < 200 || $code >= 300 || !is_string($raw) || $raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return null;
            }
            $normalized = self::normalize_schema_options($decoded);
            if (empty($normalized['courseTypes']) && empty($normalized['locations'])) {
                return null;
            }
            return $normalized;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array{courseTypes:string[],locations:string[]}
     */
    private static function normalize_schema_options(array $decoded): array {
        $clean = function ($arr): array {
            if (!is_array($arr)) {
                return [];
            }
            $out = [];
            foreach ($arr as $v) {
                $s = trim((string) $v);
                if ($s !== '' && !in_array($s, $out, true)) {
                    $out[] = $s;
                }
            }
            return $out;
        };
        $fallback = self::fallback_schema_options();
        $types = $clean($decoded['courseTypes'] ?? []);
        $locs = $clean($decoded['locations'] ?? []);
        return [
            'courseTypes' => !empty($types) ? $types : $fallback['courseTypes'],
            'locations' => !empty($locs) ? $locs : $fallback['locations'],
        ];
    }

    /**
     * @return array{courseTypes:string[],locations:string[]}
     */
    private static function fallback_schema_options(): array {
        return [
            'courseTypes' => [
                "Beginner's", 'TM AI+', 'Maintenance HW3.2', 'Maintenance HW5.0', 'Maintenance TM25S',
                '新人營', 'Lecturer', '3D Vision', 'Palletizing', 'TMcraft', 'Communication Protocol',
                'External Vision', 'Screwdriving', 'TM Image Manager', 'Customized',
            ],
            'locations' => ['TM HQ', 'Tainan-HQ', 'External', 'Webcam', '南港高工', '台中高工', '南台科大'],
        ];
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array{ok:bool,data?:array,error?:string}
     */
    private static function http_request(string $method, string $url, string $token, ?array $payload): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader([
            tcms_endpoint::authorization_header($token),
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        $options = [
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_RETURNTRANSFER' => 1,
            'CURLOPT_HEADER' => 0,
        ];

        $body = $payload !== null ? json_encode($payload) : '';
        if ($method === 'POST') {
            $raw = $curl->post($url, $body, $options);
        } else if ($method === 'DELETE') {
            $raw = $curl->delete($url, [], $options);
        } else {
            return ['ok' => false, 'error' => 'Unsupported HTTP method'];
        }

        $info = $curl->get_info();
        $code = (int) ($info['http_code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            $err = is_string($raw) && $raw !== '' ? $raw : ('HTTP ' . $code);
            if (!empty($curl->error)) {
                $err = (string) $curl->error . ' (' . $err . ')';
            }
            return ['ok' => false, 'error' => $err];
        }

        $data = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        return ['ok' => true, 'data' => $data];
    }

    private static function mark_sync_state(
        int $sessionid,
        string $status,
        string $errormsg = '',
        string $tcmsid = '',
        string $hash = ''
    ): void {
        global $DB;

        $update = (object) [
            'id' => $sessionid,
            'tcms_sync_status' => $status,
            'tcms_sync_error' => $errormsg !== '' ? $errormsg : null,
            'timemodified' => time(),
        ];
        if ($tcmsid !== '') {
            $update->tcms_session_id = $tcmsid;
        }
        if ($hash !== '') {
            $update->tcms_sync_hash = $hash;
        }
        if ($status === self::SYNC_OK) {
            $update->tcms_last_synced = time();
        }
        $DB->update_record('local_tm_course_sessions', $update);
    }

    public static function format_sync_badge(\stdClass $session): string {
        $status = (string) ($session->tcms_sync_status ?? '');
        if ($status === '') {
            return '';
        }
        return $status;
    }
}
