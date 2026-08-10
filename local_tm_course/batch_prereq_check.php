<?php
/**
 * JSON: prerequisite status for batch debrief preview.
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/prerequisite_manager.php');
require_once(__DIR__ . '/classes/permissions_manager.php');

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

$sessionid = required_param('sessionid', PARAM_INT);
$emailsraw = required_param('emails', PARAM_RAW);
$emails = json_decode($emailsraw, true);
if (!is_array($emails)) {
    $emails = [];
}

$session = session_manager::get_session($sessionid);
$rules = prerequisite_manager::parse_session_rules($session);

header('Content-Type: application/json; charset=utf-8');

if ($rules === null) {
    echo json_encode(['has_prerequisites' => false, 'results' => []]);
    die;
}

$results = [];
foreach ($emails as $email) {
    $email = \core_text::strtolower(trim(clean_param((string)$email, PARAM_EMAIL)));
    if ($email === '') {
        continue;
    }
    $user = $DB->get_record('user', [
        'email' => $email,
        'deleted' => 0,
        'mnethostid' => $CFG->mnet_localhost_id,
    ], '*', IGNORE_MISSING);
    if (!$user) {
        $results[] = [
            'email' => $email,
            'userid' => 0,
            'met' => false,
            'missing' => [],
            'unknown_user' => true,
        ];
        continue;
    }
    $prereqctx = prerequisite_manager::context_for_session($session);
    $eval = prerequisite_manager::evaluate_user((int)$user->id, $rules, $prereqctx);
    $missingdisplay = prerequisite_manager::format_missing_reason_list($eval['missing']);
    $results[] = [
        'email' => $email,
        'userid' => (int)$user->id,
        'name' => fullname($user),
        'met' => !empty($eval['met']),
        'missing' => $missingdisplay !== '' ? [$missingdisplay] : [],
        'missing_display' => $missingdisplay,
        'reasons' => prerequisite_manager::flatten_missing_reasons($eval['missing']),
        'unknown_user' => false,
    ];
}

echo json_encode([
    'has_prerequisites' => true,
    'results' => $results,
]);
