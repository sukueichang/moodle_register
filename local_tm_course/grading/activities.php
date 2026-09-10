<?php
/**
 * AJAX: visible assign/quiz list for an enabled course.
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/grading_request_manager.php');

require_login();
$PAGE->set_context(context_system::instance());

use local_tm_course\grading_request_manager;

grading_request_manager::require_can_apply();
require_sesskey();

$courseid = required_param('courseid', PARAM_INT);
$activities = grading_request_manager::list_activities($courseid);
echo json_encode(['activities' => $activities]);
