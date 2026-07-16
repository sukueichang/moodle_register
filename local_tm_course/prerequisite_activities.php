<?php
/**
 * JSON: completion-tracked activities for a TM-enabled course (prerequisite picker).
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/prerequisite_manager.php');

use local_tm_course\prerequisite_manager;

require_login();
require_sesskey();

$context = context_system::instance();
if (!has_capability('local/tm_course:manage', $context) && !is_siteadmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    die;
}

$courseid = required_param('courseid', PARAM_INT);
$activities = prerequisite_manager::get_completion_activities($courseid);
$gradeable = prerequisite_manager::get_gradeable_activities($courseid);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['activities' => array_values($activities), 'gradeable' => array_values($gradeable)]);
