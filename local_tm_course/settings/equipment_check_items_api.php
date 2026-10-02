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
        $methods = equipment_check_manager::decode_resolution_methods($r->resolution_methods ?? '');
        $items[] = [
            'id' => (int) $r->id,
            'itemname' => (string) $r->itemname,
            'scope' => (string) $r->scope,
            'checktype' => (string) $r->checktype,
            'enabled' => (int) $r->enabled,
            'sortorder' => (int) $r->sortorder,
            'resolution_methods' => $methods,
            'resolution_methods_text' => implode("\n", $methods),
            'external_support' => (string) ($r->external_support ?? ''),
        ];
    }
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save') {
    $items = $payload['items'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $methods = equipment_check_manager::normalize_resolution_input($item['resolution_methods'] ?? ($item['resolution_methods_text'] ?? ''));
        if (equipment_check_manager::validate_resolution_methods($methods) !== null) {
            echo json_encode(['ok' => false, 'error' => 'invalid_resolution_methods']);
            exit;
        }
        $support = (string) ($item['external_support'] ?? '');
        if (equipment_check_manager::validate_external_support($support) !== null) {
            echo json_encode(['ok' => false, 'error' => 'invalid_external_support']);
            exit;
        }
        $normalized[] = [
            'itemname' => (string) ($item['itemname'] ?? ''),
            'scope' => (string) ($item['scope'] ?? 'both'),
            'checktype' => (string) ($item['checktype'] ?? 'status'),
            'enabled' => !empty($item['enabled']) ? 1 : 0,
            'sortorder' => (int) ($item['sortorder'] ?? 0),
            'resolution_methods' => $methods,
            'external_support' => $support,
        ];
    }
    equipment_check_manager::save_items_for_course($courseid, $normalized);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'invalidaction']);
exit;
