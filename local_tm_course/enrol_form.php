<?php
/**
 * Calendar click enrol entry.
 * URL: /local/tm_course/enrol_form.php?sessionid=N
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/prerequisite_manager.php');

use local_tm_course\prerequisite_manager;
use local_tm_course\session_manager;

require_login();
global $USER, $SESSION;

$sessionid = required_param('sessionid', PARAM_INT);
$session = session_manager::get_session($sessionid);

if (!session_manager::can_submit_enrolment($session, false)) {
    if (!session_manager::is_online_session($session)
        && ((int) $session->status === session_manager::STATUS_FULL
            || session_manager::is_onsite_desks_full($session))) {
        redirect(new moodle_url('/local/tm_course/index.php'),
            get_string('session_status_full', 'local_tm_course'),
            null,
            \core\output\notification::NOTIFY_WARNING);
    }
    if (session_manager::is_registration_deadline_passed($session)) {
        redirect(new moodle_url('/local/tm_course/index.php'),
            get_string('error_session_registration_deadline', 'local_tm_course'),
            null,
            \core\output\notification::NOTIFY_WARNING);
    }
    redirect(new moodle_url('/local/tm_course/index.php'),
        get_string('session_status_closed', 'local_tm_course'),
        null,
        \core\output\notification::NOTIFY_WARNING);
}

if (!has_capability('local/tm_course:enrol', context_system::instance())) {
    throw new required_capability_exception(
        context_system::instance(),
        'local/tm_course:enrol',
        'nopermissions',
        ''
    );
}

try {
    prerequisite_manager::assert_learner_prerequisites($session, (int)$USER->id);
} catch (\moodle_exception $e) {
    $SESSION->local_tm_course_prereq_modal = $e->getMessage();
    redirect(new moodle_url('/local/tm_course/index.php'));
}

// Keep the same single self-enrol path as index.php: prepare pending draft, then open confirmation form.
$SESSION->local_tm_course_diet_pending = (object) [
    'sessionid' => (int)$sessionid,
    'timecreated' => time(),
];

redirect(new moodle_url('/local/tm_course/enrol_apply_step.php', ['sessionid' => $sessionid]));

