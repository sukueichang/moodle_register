<?php
/**
 * Calendar events API for FullCalendar month view.
 * URL: /local/tm_course/calendar_events.php
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/permissions_manager.php');

use local_tm_course\session_manager;
use local_tm_course\permissions_manager;

require_login();
permissions_manager::require_view_access(true);

$PAGE->set_url(new moodle_url('/local/tm_course/calendar_events.php'));
$PAGE->set_context(context_system::instance());

$from = optional_param('from', 0, PARAM_INT);
$to = optional_param('to', 0, PARAM_INT);

// Calendar should show all created sessions in the selected date range.
// Status is still returned for UI rendering/interaction decisions.
$filters = [];
if ($from > 0) {
    $filters['from'] = $from;
}
if ($to > 0) {
    $filters['to'] = $to;
}

$sessions = session_manager::get_sessions($filters);
$canenrolcapability = has_capability('local/tm_course:enrol', context_system::instance());
$canselfenrolbyrole = permissions_manager::user_can_self_enrol_by_role();

$courseids = [];
foreach ($sessions as $s) {
    $cid = (int)$s->courseid;
    if ($cid > 0) {
        $courseids[$cid] = $cid;
    }
}

$coursenames = [];
if (!empty($courseids)) {
    list($insql, $inparams) = $DB->get_in_or_equal(array_values($courseids), SQL_PARAMS_NAMED);
    $records = $DB->get_records_select('course', "id $insql", $inparams, '', 'id, fullname');
    foreach ($records as $c) {
        $coursenames[(int)$c->id] = format_string($c->fullname);
    }
}

$myenrolsql = "SELECT sessionid, status
                 FROM {local_tm_course_enrolments}
                WHERE userid = :uid";
$myenrols = $DB->get_records_sql($myenrolsql, ['uid' => $USER->id]);
$enrolmap = [];
foreach ($myenrols as $me) {
    $enrolmap[(int)$me->sessionid] = (int)$me->status;
}

$reservationids = [];
foreach ($sessions as $s) {
    $rid = (int)($s->source_reservation_id ?? 0);
    if ($rid > 0) {
        $reservationids[$rid] = $rid;
    }
}
// Batch-load requesters: ownDedicatedSession when reservation.requesterid matches current user (any role, incl. admin-as-requester).
$reservationrequesters = [];
if (!empty($reservationids)) {
    list($insql, $inparams) = $DB->get_in_or_equal(array_values($reservationids), SQL_PARAMS_NAMED);
    $revrows = $DB->get_records_select('local_tm_course_reservation', "id $insql", $inparams, '', 'id, requesterid');
    foreach ($revrows as $rev) {
        $reservationrequesters[(int)$rev->id] = (int)$rev->requesterid;
    }
}
$userid = (int)$USER->id;

$events = [];
foreach ($sessions as $s) {
    if (session_manager::is_room_closed_session($s)) {
        $events[] = [
            'id' => (int)$s->id,
            'title' => format_string($s->name),
            'start' => date('c', (int)$s->starttime),
            'end' => date('c', (int)$s->endtime),
            'allDay' => false,
            'backgroundColor' => '#bdbdbd',
            'borderColor' => '#9e9e9e',
            'textColor' => '#424242',
            'extendedProps' => [
                'isRoomClosed' => true,
                'classroomId' => (int)($s->classroomid ?? 0),
                'location' => (string)($s->location ?: ''),
                'remainingPersons' => 0,
                'remainingDesks' => 0,
                'totalDesks' => 0,
                'totalCapacity' => 0,
                'approvedCount' => 0,
                'fillPercent' => 0,
                'isNearlyFull' => false,
                'isEnrolled' => false,
                'isEnrollable' => false,
                'isTimeAutoClosed' => false,
                'fullCourseName' => get_string('session_room_closed_display', 'local_tm_course'),
                'teachingLanguage' => '',
                'deliveryMode' => session_manager::DELIVERY_ONSITE,
                'sessionStatus' => session_manager::STATUS_CLOSED,
                'ownDedicatedSession' => false,
                'sourceReservationId' => null,
                'ownDedicatedSessionLabel' => '',
                'ownDedicatedBadge' => '',
            ],
        ];
        continue;
    }

    $autoclosetime = session_manager::session_auto_close_timestamp((int)$s->starttime);
    $timeautoclosed = (time() >= $autoclosetime) && empty((int)($s->auto_close_exempt ?? 0));
    $effectivestatus = (int)$s->status;
    if ($timeautoclosed && in_array((int)$s->status, [session_manager::STATUS_OPEN, session_manager::STATUS_FULL], true)) {
        // Align month-view enrol CTA with the configured auto-close rule even if DB status lags.
        $effectivestatus = session_manager::STATUS_CLOSED;
    }
    $status = $enrolmap[(int)$s->id] ?? null;
    $isenrolled = in_array($status, [
        session_manager::ENROL_PENDING,
        session_manager::ENROL_APPROVED,
        session_manager::ENROL_WAITLISTED,
    ], true);
    $isonline = ((string)($s->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE);
    // Online sessions have no enrolment capacity; never surface "full" / seat maths.
    if ($isonline && $effectivestatus === session_manager::STATUS_FULL) {
        $effectivestatus = session_manager::STATUS_OPEN;
    }
    $approvedcount = max(0, (int)$s->confirmed_count);
    if ($isonline) {
        $remainingpersons = 0;
        $remainingdesks = 0;
        $totaldesks = 0;
        $nearlyfull = false;
        $totalcapacity = 0;
        $fillpercent = 0;
    } else {
        $remainingpersons = max(0, (int)$s->remaining_persons);
        $remainingdesks = max(0, (int)$s->remaining_desks);
        $totaldesks = (int)$s->num_desks;
        $nearlyfull = $remainingpersons <= 2;
        $totalcapacity = max(1, (int)$s->total_capacity);
        $fillpercent = (int)min(100, round(($approvedcount / $totalcapacity) * 100));
    }
    $courseid = (int)$s->courseid;
    $fullcoursename = $coursenames[$courseid] ?? format_string($s->name);

    $srcresvid = (int)($s->source_reservation_id ?? 0);
    $resrequester = $srcresvid > 0 ? ($reservationrequesters[$srcresvid] ?? 0) : 0;
    $owndedicated = $srcresvid > 0 && $resrequester === $userid;
    $ownlabel = '';
    $ownbadge = '';
    if ($owndedicated) {
        $ownlabel = get_string('calendar_own_dedicated_session', 'local_tm_course', (object)['id' => $srcresvid]);
        $ownbadge = get_string('calendar_own_dedicated_badge', 'local_tm_course');
    }

    $events[] = [
        'id' => (int)$s->id,
        'title' => format_string($s->name), // short label on card.
        // ISO8601 for FullCalendar (avoids JS timezone/number edge cases).
        'start' => date('c', (int)$s->starttime),
        'end' => date('c', (int)$s->endtime),
        'allDay' => false,
        'extendedProps' => [
            'classroomId' => (int)($s->classroomid ?? 0),
            'location' => $isonline
                ? get_string('delivery_mode_online', 'local_tm_course')
                : (string)($s->location ?: 'TBD'),
            'remainingPersons' => $remainingpersons,
            'remainingDesks' => $remainingdesks,
            'totalDesks' => $totaldesks,
            'totalCapacity' => $totalcapacity,
            'approvedCount' => $approvedcount,
            'fillPercent' => $fillpercent,
            'isNearlyFull' => $nearlyfull,
            'isEnrolled' => $isenrolled,
            'isEnrollable' => (session_manager::can_submit_enrolment($s, false)
                && $canenrolcapability
                && $canselfenrolbyrole),
            'isTimeAutoClosed' => $timeautoclosed,
            'fullCourseName' => $fullcoursename,
            'teachingLanguage' => (string)($s->teaching_language ?? session_manager::LANG_ZH_TW),
            'deliveryMode' => (string)($s->delivery_mode ?? session_manager::DELIVERY_ONSITE),
            'sessionStatus' => $effectivestatus,
            'ownDedicatedSession' => $owndedicated,
            'sourceReservationId' => $srcresvid > 0 ? $srcresvid : null,
            'ownDedicatedSessionLabel' => $ownlabel,
            'ownDedicatedBadge' => $ownbadge,
        ],
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['events' => $events]);
exit;

