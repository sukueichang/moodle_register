<?php
/**
 * Proxy certificate download for TM record pages.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/certificate_helper.php');
require_once(__DIR__ . '/classes/permissions_manager.php');

use local_tm_course\certificate_helper;
use local_tm_course\permissions_manager;

$courseid = required_param('courseid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);

require_login();

$sysctx = context_system::instance();
$cansearchrecords = has_capability('local/tm_course:manage', $sysctx) || permissions_manager::user_can_batch_enrol($USER);
if (!$cansearchrecords) {
    throw new required_capability_exception($sysctx, 'local/tm_course:manage', 'nopermissions', '');
}

$slot = certificate_helper::find_receivable_course_certificate($courseid, $userid, $cmid);
if (!$slot) {
    redirect(new moodle_url('/local/tm_course/search.php'), get_string('search_no_results', 'local_tm_course'), null, \core\output\notification::NOTIFY_WARNING);
}

if (!certificate_helper::ensure_certificate_issued_for_user(
    (int)$slot->customcertid,
    $courseid,
    (int)$slot->cmid,
    $userid
)) {
    redirect(new moodle_url('/local/tm_course/search.php'), get_string('search_no_results', 'local_tm_course'), null, \core\output\notification::NOTIFY_WARNING);
}

$cm = get_coursemodule_from_id('customcert', (int)$slot->cmid, 0, false, MUST_EXIST);
$customcert = $DB->get_record('customcert', ['id' => $cm->instance], '*', MUST_EXIST);
$template = $DB->get_record('customcert_templates', ['id' => $customcert->templateid], '*', MUST_EXIST);

\core\session\manager::write_close();
$tpl = new \mod_customcert\template($template);
$tpl->generate_pdf(false, $userid);
exit;
