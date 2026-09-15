<?php
/**
 * AJAX endpoint: Excel import preview / commit for equipment check items.
 * Access mirrors equipment_check_items.php (permissions_manager::user_can_attendance()).
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');
require_once(__DIR__ . '/../classes/equipment_check_import_manager.php');

use local_tm_course\equipment_check_import_manager;
use local_tm_course\permissions_manager;

require_login();
$PAGE->set_context(context_system::instance());

header('Content-Type: application/json; charset=utf-8');

if (!permissions_manager::user_can_attendance()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => get_string('nopermissions', 'error')]);
    exit;
}

$action = required_param('action', PARAM_ALPHANUMEXT);
$courseid = required_param('courseid', PARAM_INT);
require_sesskey();

try {
    if ($action === 'preview') {
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new moodle_exception('equipment_check_import_error_upload', 'local_tm_course');
        }
        $result = equipment_check_import_manager::preview($courseid, $_FILES['file']);
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'commit') {
        $token = required_param('token', PARAM_ALPHANUMEXT);
        $result = equipment_check_import_manager::commit($courseid, $token);
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'invalidaction']);
} catch (moodle_exception $e) {
    http_response_code(400);
    $msg = $e->getMessage();
    if ($e->errorcode && get_string_manager()->string_exists($e->errorcode, 'local_tm_course')) {
        $msg = get_string($e->errorcode, 'local_tm_course', $e->a);
    }
    echo json_encode([
        'ok' => false,
        'error' => $msg,
        'errorcode' => $e->errorcode,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => get_string('equipment_check_import_error_db', 'local_tm_course'),
    ], JSON_UNESCAPED_UNICODE);
}
exit;
