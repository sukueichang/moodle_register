<?php
/**
 * AJAX: suggest start time to chain after the last session in a classroom on a date.
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/session_manager.php');

use local_tm_course\session_manager;

require_login();
require_capability('local/tm_course:manage', context_system::instance());
require_sesskey();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/tm_course/chain_start_suggest.php'));

$classroomid = required_param('classroomid', PARAM_INT);
$startdate = required_param('start_date', PARAM_TEXT);
$ignoresessionid = optional_param('ignore_session_id', 0, PARAM_INT);

if ($classroomid <= 0) {
    throw new moodle_exception('error_classroom_required', 'local_tm_course');
}

$startts = session_manager::suggest_chain_start_time($classroomid, $startdate, $ignoresessionid ?: null);
if ($startts <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'starttime' => $startts,
    'start' => [
        'date' => date('Y-m-d', $startts),
        'hour' => (int) date('G', $startts),
        'minute' => ((int) date('i', $startts) >= 30 ? 30 : 0),
    ],
]);
exit;
