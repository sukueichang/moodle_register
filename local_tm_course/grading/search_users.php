<?php
/**
 * AJAX: search submitted learners for a visible assign/quiz.
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

$cmid = required_param('cmid', PARAM_INT);
$q = optional_param('q', '', PARAM_RAW_TRIMMED);

try {
    $users = grading_request_manager::search_submitted_users($cmid, $q);
} catch (moodle_exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'users' => []]);
    die();
}

echo json_encode(['users' => $users]);
