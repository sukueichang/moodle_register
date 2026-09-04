<?php

/**

 * Route after "start enrolment": always open self-enrol confirmation (institution + diet).

 *

 * @package    local_tm_course

 */

require_once(__DIR__ . '/../../config.php');

require_once(__DIR__ . '/classes/session_manager.php');



use local_tm_course\session_manager;



require_login();

require_capability('local/tm_course:enrol', context_system::instance());



global $PAGE, $SESSION;



$sessionid = required_param('sessionid', PARAM_INT);

$PAGE->set_context(context_system::instance());

$PAGE->set_url(new moodle_url('/local/tm_course/enrol_apply_step.php', ['sessionid' => $sessionid]));



$session = session_manager::get_session($sessionid);



if (!session_manager::can_submit_enrolment($session, false)) {

    if (!session_manager::is_online_session($session)

        && ((int) $session->status === session_manager::STATUS_FULL

            || session_manager::is_onsite_persons_full($session))) {

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



$pending = $SESSION->local_tm_course_diet_pending ?? null;

if (!$pending || (int)($pending->sessionid ?? 0) !== (int)$sessionid) {

    redirect(new moodle_url('/local/tm_course/index.php'),

        get_string('error_enrol_flow_expired', 'local_tm_course'),

        null,

        \core\output\notification::NOTIFY_ERROR);

}



redirect(new moodle_url('/local/tm_course/enrol_diet.php', ['sessionid' => $sessionid]));

