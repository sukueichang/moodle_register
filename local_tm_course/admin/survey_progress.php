<?php
/**
 * JSON progress for survey projection board.
 *
 * URL: /local/tm_course/admin/survey_progress.php?sessionid=N
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/survey_manager.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');

use local_tm_course\permissions_manager;
use local_tm_course\survey_manager;

require_login();
$ctx = context_system::instance();
if (!permissions_manager::user_can_attendance()) {
    throw new required_capability_exception($ctx, 'local/tm_course:attendance', 'nopermissions', '');
}

$sessionid = required_param('sessionid', PARAM_INT);
$count = survey_manager::count_session_responses($sessionid);
$expected = survey_manager::expected_headcount($sessionid);
$rate = $expected > 0 ? round($count / $expected * 100, 1) : null;

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'count' => $count,
    'expected' => $expected,
    'rate' => $rate,
], JSON_UNESCAPED_UNICODE);
die;
