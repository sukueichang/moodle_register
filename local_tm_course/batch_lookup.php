<?php
/**
 * JSON: resolve email to full name for batch debrief (M4).
 * Optional sessionid returns prerequisite status for debrief (same auth as batch enrol).
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/permissions_manager.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/prerequisite_manager.php');

use local_tm_course\permissions_manager;
use local_tm_course\prerequisite_manager;
use local_tm_course\session_manager;

require_login();
require_sesskey();

global $DB, $USER, $CFG;

$ctx = context_system::instance();
$PAGE->set_context($ctx);
if (!is_siteadmin($USER)
    && !has_capability('local/tm_course:manage', $ctx)
    && !permissions_manager::user_can_batch_enrol()) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    die;
}

$email = required_param('email', PARAM_EMAIL);
$email = trim(strtolower($email));
$sessionid = optional_param('sessionid', 0, PARAM_INT);

$rules = null;
if ($sessionid > 0) {
    try {
        $session = session_manager::get_session($sessionid);
        $rules = prerequisite_manager::parse_session_rules($session);
    } catch (\moodle_exception $e) {
        $rules = null;
    }
}

$user = $DB->get_record('user', [
    'email' => $email,
    'deleted' => 0,
    'mnethostid' => $CFG->mnet_localhost_id,
], '*', IGNORE_MISSING);

header('Content-Type: application/json; charset=utf-8');

if (!$user) {
    $out = ['found' => false, 'name' => '', 'institution' => ''];
    if ($rules !== null) {
        $out['prereq_met'] = false;
        $out['prereq_missing'] = '';
        $out['prereq_reasons'] = [];
    }
    echo json_encode($out);
    die;
}

$out = [
    'found' => true,
    'name' => fullname($user),
    'institution' => (string) ($user->institution ?? ''),
];

if ($rules !== null) {
    $eval = prerequisite_manager::evaluate_user((int)$user->id, $rules);
    $out['prereq_met'] = !empty($eval['met']);
    $out['prereq_reasons'] = prerequisite_manager::flatten_missing_reasons($eval['missing']);
    $out['prereq_missing'] = prerequisite_manager::format_missing_reason_list($eval['missing']);
}

echo json_encode($out);
