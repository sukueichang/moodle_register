<?php
/**
 * Marks first-visit calendar onboarding as seen (Phase 4).
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');

use local_tm_course\permissions_manager;

require_login();

global $DB, $USER;

$context = context_system::instance();
$issiteadmin = is_siteadmin();
$canbatch = permissions_manager::user_can_batch_enrol();
if (!$issiteadmin && !$canbatch) {
    throw new required_capability_exception($context, 'local/tm_course:batchenrol', 'nopermissions', '');
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    $data = [];
}

$sesskey = (string) ($data['sesskey'] ?? '');
if ($sesskey === '' || !confirm_sesskey($sesskey)) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'invalidsesskey']);
    exit;
}

$id = (int) ($data['id'] ?? 0);
if ($id <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'reservation_calendar_error_invalid_request']);
    exit;
}

$reservation = $DB->get_record('local_tm_course_reservation', ['id' => $id], 'id,requesterid', MUST_EXIST);
if ((int) $reservation->requesterid !== (int) $USER->id && !$issiteadmin) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'nopermissions']);
    exit;
}

$DB->set_field('local_tm_course_reservation', 'calendar_onboarding_seen', 1, ['id' => $id]);
$DB->set_field('local_tm_course_reservation', 'timemodified', time(), ['id' => $id]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true]);
exit;
