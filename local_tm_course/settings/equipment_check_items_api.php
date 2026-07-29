<?php
/**
 * AJAX endpoint for equipment check item template settings ("設備檢查清單維護").
 * Access mirrors the class prep / attendance page (permissions_manager::user_can_attendance()).
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/equipment_check_manager.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');

use local_tm_course\equipment_check_manager;
use local_tm_course\permissions_manager;

require_login();
if (!permissions_manager::user_can_attendance()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'nopermissions']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '[]', true);
if (!is_array($payload)) {
    $payload = [];
}
$sesskey = (string) ($payload['sesskey'] ?? '');
if ($sesskey === '' || !confirm_sesskey($sesskey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalidsesskey']);
    exit;
}

$action = clean_param((string) ($payload['action'] ?? ''), PARAM_ALPHANUMEXT);
$courseid = (int) ($payload['courseid'] ?? 0);
if ($courseid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'invalidcourseid']);
    exit;
}

if ($action === 'list') {
    $rows = equipment_check_manager::get_items_by_course($courseid);
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int) $r->id,
            'itemname' => (string) $r->itemname,
            'scope' => (string) $r->scope,
            'checktype' => (string) $r->checktype,
            'enabled' => (int) $r->enabled,
            'sortorder' => (int) $r->sortorder,
        ];
    }
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

if ($action === 'save') {
    $items = $payload['items'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }
    equipment_check_manager::save_items_for_course($courseid, $items);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'invalidaction']);
exit;
