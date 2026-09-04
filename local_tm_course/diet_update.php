<?php
/**
 * AJAX: update enrolment diet (admin / batch submitter / learner).
 * URL: /local/tm_course/diet_update.php
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/enrolment_manager.php');
require_once(__DIR__ . '/classes/permissions_manager.php');

use local_tm_course\enrolment_manager;
use local_tm_course\permissions_manager;

require_login();
permissions_manager::require_view_access();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

$enrolid = required_param('enrolid', PARAM_INT);
$dietchoice = optional_param('diet_choice', '', PARAM_ALPHA);
$specialnote = optional_param('special_note', '', PARAM_TEXT);

try {
    global $USER;
    $result = enrolment_manager::update_diet_by_actor((int) $enrolid, (int) $USER->id, [
        'choice' => $dietchoice,
        'special_note' => $specialnote,
    ]);
    echo json_encode([
        'ok' => true,
        'enrolid' => $enrolid,
        'choice' => $result['choice'],
        'special_note' => $result['special_note'],
        'label' => $result['label'],
    ]);
} catch (Throwable $ex) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $ex->getMessage(),
    ]);
}
