<?php
/**
 * AJAX endpoint for course-mapping default prerequisite rules.
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/enabled_course_manager.php');
require_once(__DIR__ . '/../classes/prerequisite_manager.php');

use local_tm_course\enabled_course_manager;
use local_tm_course\prerequisite_manager;

require_login();
require_capability('local/tm_course:manage', context_system::instance());

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '[]', true);
if (!is_array($payload)) {
    $payload = [];
}
$sesskey = (string)($payload['sesskey'] ?? '');
if ($sesskey === '' || !confirm_sesskey($sesskey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalidsesskey']);
    exit;
}

$action = clean_param((string)($payload['action'] ?? ''), PARAM_ALPHANUMEXT);
$courseid = (int)($payload['courseid'] ?? 0);
if ($courseid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'invalidcourseid']);
    exit;
}

if ($action === 'list') {
    $rules = enabled_course_manager::get_default_prerequisite_rules($courseid);
    echo json_encode([
        'ok' => true,
        'rules' => $rules ?? ['operator' => prerequisite_manager::OPERATOR_AND, 'rules' => []],
        'course_menu' => enabled_course_manager::get_course_menu(),
    ]);
    exit;
}

if ($action === 'save') {
    $rulesraw = $payload['rules'] ?? null;
    $rules = null;
    if (is_array($rulesraw)) {
        $rules = prerequisite_manager::normalize_rules($rulesraw);
    }
    try {
        enabled_course_manager::save_default_prerequisite_rules($courseid, $rules);
        echo json_encode(['ok' => true]);
    } catch (\moodle_exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'invalidaction']);
