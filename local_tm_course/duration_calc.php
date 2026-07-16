<?php
/**
 * AJAX endpoint for M7 auto duration calculation.
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/session_manager.php');

use local_tm_course\session_manager;

require_login();
require_sesskey();

$PAGE->set_url(new moodle_url('/local/tm_course/duration_calc.php'));
$PAGE->set_context(context_system::instance());

$courseid = required_param('courseid', PARAM_INT);
$deliverymode = optional_param('delivery_mode', session_manager::DELIVERY_ONSITE, PARAM_ALPHANUMEXT);
$startdate = required_param('start_date', PARAM_TEXT); // Y-m-d
$starthour = optional_param('start_hour', 9, PARAM_INT);
$startminute = optional_param('start_minute', 30, PARAM_INT);

$startts = strtotime($startdate . ' ' . sprintf('%02d:%02d:00', max(0, min(23, $starthour)), ((int)$startminute === 30 ? 30 : 0)));
if ($startts === false) {
    throw new moodle_exception('invalidparameter', 'error');
}

$respectstart = optional_param('respect_start', 0, PARAM_BOOL);
if ($deliverymode !== session_manager::DELIVERY_ONSITE) {
    $respectstart = false;
}

$calc = session_manager::calculate_session_times($courseid, $deliverymode, (int)$startts, (bool)$respectstart);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'starttime' => (int)$calc['starttime'],
    'endtime' => (int)$calc['endtime'],
    'total_hours' => (float)$calc['total_hours'],
    'daily_limit' => (float)$calc['daily_limit'],
    'start' => [
        'date' => date('Y-m-d', (int)$calc['starttime']),
        'hour' => (int)date('G', (int)$calc['starttime']),
        'minute' => ((int)date('i', (int)$calc['starttime']) >= 30 ? 30 : 0),
    ],
    'end' => [
        'date' => date('Y-m-d', (int)$calc['endtime']),
        'hour' => (int)date('G', (int)$calc['endtime']),
        'minute' => ((int)date('i', (int)$calc['endtime']) >= 30 ? 30 : 0),
    ],
]);
exit;

