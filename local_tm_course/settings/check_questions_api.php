<?php
/**
 * AJAX endpoint for verification question settings.
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/verification_manager.php');

require_login();
require_capability('local/tm_course:manage', context_system::instance());

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
    $rows = \local_tm_course\verification_manager::get_questions_by_course($courseid);
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int)$r->id,
            'question_text' => (string)$r->question_text,
            'apply_mode' => (string)$r->apply_mode,
            'is_required' => (int)$r->is_required,
            'sortorder' => (int)$r->sortorder,
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
    \local_tm_course\verification_manager::save_questions_for_course($courseid, $items);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'invalidaction']);
exit;

