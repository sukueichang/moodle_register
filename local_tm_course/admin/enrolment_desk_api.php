<?php
/**
 * Admin AJAX: move enrolment between desks (pending or approved).
 * URL: /local/tm_course/admin/enrolment_desk_api.php
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/enrolment_manager.php');

use local_tm_course\enrolment_manager;

require_login();
require_capability('local/tm_course:approve', context_system::instance());
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

$action = required_param('action', PARAM_ALPHA);
$enrolid = required_param('enrolid', PARAM_INT);
$deskno = optional_param('desk_number', 0, PARAM_INT);

try {
    if ($action !== 'move') {
        throw new moodle_exception('invalidparameter', 'error');
    }
    enrolment_manager::change_desk_assignment($enrolid, $deskno);
    echo json_encode([
        'ok' => true,
        'enrolid' => $enrolid,
        'desk_number' => $deskno,
    ]);
} catch (Throwable $ex) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $ex->getMessage(),
    ]);
}
