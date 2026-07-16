<?php
/**
 * TCMS inbound API: create/update/delete Moodle classroom occupancy (room_closed).
 *
 * POST   /local/tm_course/api/room_closed.php          — upsert by tcmsSessionId
 * DELETE /local/tm_course/api/room_closed.php?tcmsSessionId=...
 *
 * Auth: Bearer same as TCMS sync token.
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/classroom_manager.php');
require_once(__DIR__ . '/../classes/tcms_inbound_auth.php');
require_once(__DIR__ . '/../classes/tcms_cors.php');

use local_tm_course\session_manager;
use local_tm_course\classroom_manager;
use local_tm_course\tcms_inbound_auth;
use local_tm_course\tcms_cors;

tcms_cors::apply_tcms_browser_cors();
tcms_cors::exit_if_preflight();

tcms_inbound_auth::require_auth();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'DELETE') {
    $tcmsid = optional_param('tcmsSessionId', '', PARAM_TEXT);
    if ($tcmsid === '') {
        $body = tcms_inbound_auth::read_json_body();
        $tcmsid = trim((string) ($body['tcmsSessionId'] ?? ''));
    }
    if ($tcmsid === '') {
        tcms_inbound_auth::json_response(['error' => 'tcmsSessionId required'], 400);
    }
    $deleted = session_manager::delete_room_closed_by_tcms_session_id($tcmsid);
    tcms_inbound_auth::json_response(['ok' => true, 'deleted' => $deleted, 'tcmsSessionId' => $tcmsid]);
}

if ($method !== 'POST' && $method !== 'PUT') {
    tcms_inbound_auth::json_response(['error' => 'method not allowed'], 405);
}

$body = tcms_inbound_auth::read_json_body();
$tcmsid = trim((string) ($body['tcmsSessionId'] ?? ''));
$classroomid = (int) ($body['classroomId'] ?? $body['moodleClassroomId'] ?? 0);
$name = trim((string) ($body['title'] ?? $body['name'] ?? ''));
$start = (int) ($body['starttime'] ?? 0);
$end = (int) ($body['endtime'] ?? 0);

// Accept ISO date+time from TCMS if unix not provided.
if ($start <= 0 || $end <= 0) {
    $startdate = trim((string) ($body['startDate'] ?? ''));
    $enddate = trim((string) ($body['endDate'] ?? $startdate));
    $starttime = trim((string) ($body['startTime'] ?? '09:00'));
    $endtime = trim((string) ($body['endTime'] ?? '17:00'));
    if ($startdate !== '') {
        $tz = \core_date::get_server_timezone_object();
        try {
            $sd = new \DateTime($startdate . ' ' . $starttime . ':00', $tz);
            $ed = new \DateTime($enddate . ' ' . $endtime . ':00', $tz);
            $start = $sd->getTimestamp();
            $end = $ed->getTimestamp();
        } catch (\Throwable $e) {
            tcms_inbound_auth::json_response(['error' => 'invalid date/time', 'detail' => $e->getMessage()], 400);
        }
    }
}

if ($tcmsid === '' || $classroomid <= 0 || $end <= $start) {
    tcms_inbound_auth::json_response([
        'error' => 'invalid body',
        'hint' => 'Need tcmsSessionId, classroomId, and valid start/end (unix or date+time)',
    ], 400);
}

try {
    classroom_manager::get($classroomid);
    $moodleid = session_manager::upsert_room_closed_from_tcms([
        'classroomid' => $classroomid,
        'starttime' => $start,
        'endtime' => $end,
        'name' => $name !== '' ? $name : get_string('session_room_closed_default_title', 'local_tm_course'),
        'tcms_session_id' => $tcmsid,
    ]);
    tcms_inbound_auth::json_response([
        'ok' => true,
        'moodleSessionId' => $moodleid,
        'tcmsSessionId' => $tcmsid,
        'classroomId' => $classroomid,
        'starttime' => $start,
        'endtime' => $end,
    ]);
} catch (\moodle_exception $e) {
    $code = ($e->errorcode === 'error_classroom_time_conflict') ? 409 : 400;
    tcms_inbound_auth::json_response([
        'error' => $e->errorcode,
        'message' => $e->getMessage(),
    ], $code);
} catch (\Throwable $e) {
    tcms_inbound_auth::json_response(['error' => 'server_error', 'message' => $e->getMessage()], 500);
}
