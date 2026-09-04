<?php
/**
 * JSON: resolve email to full name for batch debrief (M4).
 * Optional sessionid or courseids returns prerequisite status for debrief.
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/permissions_manager.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/prerequisite_manager.php');
require_once(__DIR__ . '/classes/enabled_course_manager.php');

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
$courseids = optional_param_array('courseids', [], PARAM_INT);
if (empty($courseids)) {
    $courseidsraw = optional_param('courseids', '', PARAM_TEXT);
    if ($courseidsraw !== '') {
        foreach (preg_split('/[,\s]+/', $courseidsraw) as $part) {
            $cid = (int)$part;
            if ($cid > 0) {
                $courseids[] = $cid;
            }
        }
    }
}
$courseids = array_values(array_unique(array_filter(array_map('intval', $courseids), static function($v) {
    return $v > 0;
})));

$rules = null;
$usecoursedefaults = false;
$session = null;
if ($sessionid > 0) {
    try {
        $session = session_manager::get_session($sessionid);
        $rules = prerequisite_manager::parse_session_rules($session);
    } catch (\moodle_exception $e) {
        $rules = null;
        $session = null;
    }
} else if (!empty($courseids)) {
    $usecoursedefaults = true;
}

$user = $DB->get_record('user', [
    'email' => $email,
    'deleted' => 0,
    'mnethostid' => $CFG->mnet_localhost_id,
], '*', IGNORE_MISSING);

header('Content-Type: application/json; charset=utf-8');

$wantprereq = ($rules !== null) || $usecoursedefaults;

if (!$user) {
    $out = ['found' => false, 'name' => '', 'institution' => '', 'account_missing' => true];
    if ($wantprereq) {
        $reason = get_string('reservation_batch_prereq_account_missing', 'local_tm_course');
        $out['prereq_met'] = false;
        $out['prereq_missing'] = $reason;
        $out['prereq_reasons'] = [$reason];
    }
    echo json_encode($out);
    die;
}

$out = [
    'found' => true,
    'name' => fullname($user),
    'institution' => (string) ($user->institution ?? ''),
    'account_missing' => false,
];

if ($rules !== null && $session) {
    $ctx = prerequisite_manager::context_for_session($session);
    $eval = prerequisite_manager::evaluate_user((int)$user->id, $rules, $ctx);
    $out['prereq_met'] = !empty($eval['met']);
    $out['prereq_reasons'] = prerequisite_manager::flatten_missing_reasons($eval['missing']);
    $out['prereq_missing'] = prerequisite_manager::format_missing_reason_list($eval['missing']);
    $out['prereq_operator'] = (string)($rules['operator'] ?? '');
    $out['prereq_rule_types'] = array_values(array_map(static function($r) {
        return (string)($r['verify_type'] ?? '');
    }, (array)($rules['rules'] ?? [])));
    $out['prereq_target_starttime'] = (int)($ctx['target_starttime'] ?? 0);
} else if ($usecoursedefaults) {
    $eval = prerequisite_manager::evaluate_user_against_course_defaults((int)$user->id, $courseids);
    $out['prereq_met'] = !empty($eval['met']);
    $out['prereq_reasons'] = prerequisite_manager::flatten_missing_reasons($eval['missing']);
    $out['prereq_missing'] = prerequisite_manager::format_missing_reason_list($eval['missing']);
    $out['has_prerequisites'] = !empty($eval['has_prerequisites']);
}

echo json_encode($out);
