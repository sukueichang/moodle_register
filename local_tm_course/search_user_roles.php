<?php
/**
 * JSON API: load/save system roles for a user (site administrators only).
 *
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/permissions_manager.php');

use local_tm_course\permissions_manager;

require_login();
require_sesskey();

global $DB;

header('Content-Type: application/json; charset=utf-8');

if (!is_siteadmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    die;
}

$action = required_param('action', PARAM_ALPHA);
$userid = required_param('userid', PARAM_INT);

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
if (!$user || isguestuser($user) || $userid < 2) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invaliduser']);
    die;
}

if ($action === 'load') {
    $assigned = permissions_manager::get_user_system_roleids($userid);
    $assignedmap = array_fill_keys($assigned, true);
    $roles = [];
    foreach (permissions_manager::get_system_roles_options() as $role) {
        $roles[] = [
            'id' => $role['id'],
            'shortname' => $role['shortname'],
            'name' => $role['name'],
            'assigned' => isset($assignedmap[$role['id']]),
        ];
    }
    $display = permissions_manager::get_users_system_role_shortnames([$userid]);
    echo json_encode([
        'ok' => true,
        'userid' => $userid,
        'fullname' => fullname($user),
        'username' => (string)$user->username,
        'displayroles' => $display[$userid] ?? '',
        'readonlyroles' => permissions_manager::get_user_readonly_system_roles($userid),
        'roles' => $roles,
    ]);
    die;
}

if ($action === 'save') {
    $roleids = optional_param_array('roleids', [], PARAM_INT);
    try {
        permissions_manager::set_user_system_roles($userid, $roleids);
        $display = permissions_manager::get_users_system_role_shortnames([$userid]);
        echo json_encode([
            'ok' => true,
            'displayroles' => $display[$userid] ?? '',
        ]);
    } catch (\moodle_exception $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
        ]);
    }
    die;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalidaction']);
