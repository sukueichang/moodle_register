<?php
/**
 * TCMS inbound API: list Moodle classrooms for occupancy mapping.
 * GET /local/tm_course/api/classrooms.php
 * Auth: Bearer same as TCMS sync token.
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/classroom_manager.php');
require_once(__DIR__ . '/../classes/tcms_inbound_auth.php');
require_once(__DIR__ . '/../classes/tcms_cors.php');

use local_tm_course\classroom_manager;
use local_tm_course\tcms_inbound_auth;
use local_tm_course\tcms_cors;

tcms_cors::apply_tcms_browser_cors();
tcms_cors::exit_if_preflight();

tcms_inbound_auth::require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    tcms_inbound_auth::json_response(['error' => 'method not allowed'], 405);
}

$rows = classroom_manager::get_all();
$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id' => (int) $r->id,
        'name' => (string) ($r->name ?? ''),
        'location' => (string) ($r->location ?? ''),
        'tcmsLocation' => trim((string) ($r->tcms_location ?? '')),
        'tableCount' => classroom_manager::table_count($r),
    ];
}

tcms_inbound_auth::json_response(['ok' => true, 'classrooms' => $out]);
