<?php
/**
 * Reservation planning events API (Phase 3 initial).
 * URL: /local/tm_course/reservation/plan_events.php?id=RID
 * @package    local_tm_course
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');
require_once(__DIR__ . '/../classes/enabled_course_manager.php');
require_once(__DIR__ . '/../classes/session_manager.php');

use local_tm_course\enabled_course_manager;
use local_tm_course\permissions_manager;
use local_tm_course\session_manager;

require_login();

$context = context_system::instance();
// AJAX_SCRIPT calls still need an explicit page context, otherwise downstream helpers
// such as format_string() will throw "$PAGE->context was not set".
$PAGE->set_context($context);
$issiteadmin = is_siteadmin();
$canbatch = permissions_manager::user_can_batch_enrol();
if (!$issiteadmin && !$canbatch) {
    throw new required_capability_exception($context, 'local/tm_course:batchenrol', 'nopermissions', '');
}

$id = required_param('id', PARAM_INT);
$reservation = $DB->get_record('local_tm_course_reservation', ['id' => $id], '*', MUST_EXIST);

if ((int)$reservation->requesterid !== (int)$USER->id && !$issiteadmin) {
    throw new required_capability_exception($context, 'local/tm_course:manage', 'nopermissions', '');
}

/**
 * Move timestamp to next weekday while preserving time.
 */
function local_tm_course_next_weekday_ts(int $ts): int {
    $cursor = $ts;
    while (true) {
        $w = (int)date('w', $cursor); // 0=Sun, 6=Sat
        if ($w >= 1 && $w <= 5) {
            return $cursor;
        }
        $cursor = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $cursor)));
    }
}

/**
 * @param array<int,array{start:int,end:int}> $intervals
 */
function local_tm_course_has_time_conflict(array $intervals, int $start, int $end): bool {
    foreach ($intervals as $itv) {
        $s = (int)($itv['start'] ?? 0);
        $e = (int)($itv['end'] ?? 0);
        if ($s <= 0 || $e <= 0) {
            continue;
        }
        if ($s < $end && $e > $start) {
            return true;
        }
    }
    return false;
}

/**
 * Build onsite block: chain after same-room occupancy on cursor day, then course duration rules.
 *
 * @param array<int,array{start:int,end:int}> $roomintervals
 * @return array{start:int,end:int,teachinghours:float,durationhours:float,nextcursor:int}
 */
function local_tm_course_build_onsite_block(int $courseid, int $cursor, array $roomintervals = []): array {
    $built = session_manager::build_reservation_onsite_block($courseid, $cursor, $roomintervals);
    $start = (int) $built['starttime'];
    $end = (int) $built['endtime'];
    $teachinghours = (float) $built['teaching_hours'];
    return [
        'start' => $start,
        'end' => $end,
        'teachinghours' => $teachinghours,
        'durationhours' => ($end - $start) / HOURSECS,
        'nextcursor' => (int) $built['nextcursor'],
    ];
}

/**
 * Build online blocks with average split so each day ends by configured limit.
 *
 * When $forceddailyhours > 0, the function ignores the natural day-end based
 * day count and instead forces ceil(total / forceddailyhours) segments, each
 * with total/days hours. The day-end limit is still enforced as a hard cap
 * (blocks will be clipped — the caller should validate that nothing was lost).
 *
 * @param int   $courseid          Moodle course id (must be enabled course).
 * @param int   $cursor            Earliest acceptable timestamp (will roll forward to next weekday).
 * @param string $hhmmss           Daily start time, HH:MM:SS.
 * @param string $dayendhhmmss     Latest allowed end time per day, HH:MM:SS.
 * @param float $forceddailyhours  Optional manual cap (hours/day). 0 = automatic.
 * @return array{blocks:array<int,array{start:int,end:int,durationhours:float}>,nextcursor:int,teachinghours:float}
 */
function local_tm_course_build_online_blocks_average(
    int $courseid,
    int $cursor,
    string $hhmmss,
    string $dayendhhmmss,
    float $forceddailyhours = 0.0
): array {
    $teachinghours = enabled_course_manager::get_default_duration_hours($courseid, session_manager::DELIVERY_ONLINE);
    $totalremainingsecs = (int)round($teachinghours * HOURSECS);
    $firststart = local_tm_course_next_weekday_ts(strtotime(date('Y-m-d', $cursor) . ' ' . $hhmmss));
    $firstdayend = strtotime(date('Y-m-d', $firststart) . ' ' . $dayendhhmmss);
    $firstdayavail = max(1, $firstdayend - $firststart);
    if ($forceddailyhours > 0 && $teachinghours > 0) {
        // Manual split mode: business-supplied daily cap (hours).
        // days = ceil(total / cap); each segment = total / days (even split).
        $days = max(1, (int)ceil($teachinghours / max(0.01, $forceddailyhours)));
    } else {
        $days = max(1, (int)ceil($totalremainingsecs / $firstdayavail));
    }
    $blocks = [];
    $daystart = $firststart;

    for ($i = 0; $i < $days; $i++) {
        $daysremaining = max(1, $days - $i);
        $targetsecs = (int)round($totalremainingsecs / $daysremaining);
        $dayendlimit = strtotime(date('Y-m-d', $daystart) . ' ' . $dayendhhmmss);
        $dayavail = max(1, $dayendlimit - $daystart);
        $use = min($targetsecs, $dayavail);
        $end = $daystart + $use;
        $blocks[] = [
            'start' => (int)$daystart,
            'end' => (int)$end,
            'durationhours' => $use / HOURSECS,
        ];
        $totalremainingsecs -= $use;
        if ($i < $days - 1) {
            $nextday = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $daystart)));
            $daystart = local_tm_course_next_weekday_ts(strtotime(date('Y-m-d', $nextday) . ' ' . $hhmmss));
        }
    }

    $lastblock = end($blocks);
    $nextcursor = !empty($lastblock) ? (int)$lastblock['end'] : (int)$firststart;
    return [
        'blocks' => $blocks,
        'nextcursor' => $nextcursor,
        'teachinghours' => $teachinghours,
    ];
}

$courseids = [];
if (!empty($reservation->courseids_json)) {
    $decoded = json_decode((string)$reservation->courseids_json, true);
    if (is_array($decoded)) {
        foreach ($decoded as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) {
                $courseids[] = $cid;
            }
        }
    } else {
        // Backward-compatible parsing for legacy CSV content.
        $csvparts = explode(',', (string)$reservation->courseids_json);
        foreach ($csvparts as $part) {
            $cid = (int)trim($part);
            if ($cid > 0) {
                $courseids[] = $cid;
            }
        }
    }
}
if (empty($courseids) && (int)$reservation->courseid > 0) {
    $courseids[] = (int)$reservation->courseid;
}

$courseids = array_values(array_unique(array_map('intval', $courseids)));
$courseids = array_values(array_filter($courseids, function($v) {
    return $v > 0;
}));

$delivery = (string)$reservation->delivery_mode;
if ($delivery === session_manager::DELIVERY_ONLINE && !empty($courseids)) {
    $missingonline = enabled_course_manager::get_missing_online_classroom_course_ids($courseids);
    if (!empty($missingonline)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'reservation_calendar_error_online_classroom_unconfigured',
            'events' => [],
        ]);
        exit;
    }
}

if (!empty($courseids)) {
    list($priorityinsql, $priorityparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
    $priorityrows = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $priorityinsql", $priorityparams);
    $prioritycourseid = 0;
    $ispriorityname = function(string $name): bool {
        $n = strtolower(trim($name));
        $n = str_replace(["\xE2\x80\x99", "\xE2\x80\x98", "'", ' '], '', $n);
        return strpos($n, 'aicobotbeginner') !== false;
    };
    foreach ($priorityrows as $prow) {
        if ($ispriorityname((string)$prow->fullname)) {
            $prioritycourseid = (int)$prow->id;
            break;
        }
    }
    if ($prioritycourseid > 0) {
        $orderedcourseids = [$prioritycourseid];
        foreach ($courseids as $cid) {
            if ((int)$cid !== $prioritycourseid) {
                $orderedcourseids[] = (int)$cid;
            }
        }
        $courseids = $orderedcourseids;
    }
}

if (empty($courseids)) {
    // Always provide at least one editable draft block so users can continue phase-3 flow.
    $start = (int)$reservation->preferred_starttime;
    if ($start <= 0) {
        $start = time();
    }
    $end = (int)($start + (2 * HOURSECS));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['events' => [[
        'id' => 'res-' . $id . '-draft',
        'title' => 'Draft block',
        'start' => date('c', $start),
        'end' => date('c', $end),
        'allDay' => false,
        'extendedProps' => [
            'eventType' => 'reservation_plan',
            'classroomId' => 0,
            'classroomLabel' => '',
            'deliveryMode' => (string)$reservation->delivery_mode,
            'teachingHours' => 2,
            'durationHours' => 2,
            'preferredStartHm' => $preferredstarthm,
        ],
    ]]]);
    exit;
}

list($cinsql, $cinparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
$courserecords = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $cinsql", $cinparams);
$coursenames = [];
foreach ($courserecords as $row) {
    $coursenames[(int)$row->id] = format_string((string)$row->fullname);
}

$classrooms = $DB->get_records('local_tm_classroom', [], '', 'id, name, location');
$classroomlabels = [];
foreach ($classrooms as $room) {
    $label = trim((string)$room->name);
    $loc = trim((string)($room->location ?? ''));
    if ($loc !== '') {
        $label .= ' — ' . $loc;
    }
    $classroomlabels[(int)$room->id] = $label;
}
$fallbackclassroomid = 0;
if (!empty($classroomlabels)) {
    reset($classroomlabels);
    $fallbackclassroomid = (int)key($classroomlabels);
}

$preferredclassroomid = (int)($reservation->preferred_classroomid ?? 0);
$cursor = (int)$reservation->preferred_starttime;
$preferredhhmmss = date('H:i:s', max(1, (int)$reservation->preferred_starttime));
$preferredstarthm = date('H:i', max(1, (int)$reservation->preferred_starttime));
$onlinedayendhhmmss = session_manager::get_online_day_end_hhmm() . ':00';
// Optional manual daily cap (hours) supplied on the application form. Online only.
$forceddailyhours = 0.0;
if ($delivery === session_manager::DELIVERY_ONLINE
    && property_exists($reservation, 'online_daily_hours_limit')
    && $reservation->online_daily_hours_limit !== null
    && (float)$reservation->online_daily_hours_limit > 0) {
    $forceddailyhours = (float)$reservation->online_daily_hours_limit;
}
$events = [];

// Same reservation: learners attend all blocks — no time overlap between any two plan blocks.
$reservationintervals = [];

// Onsite chaining is per-classroom: courses in the SAME room chain after that room's last
// end; courses in a DIFFERENT room restart from the preferred date (never chained onto an
// unrelated room's evening end). Reservation-overlap still keeps the learner sequential.
$basecursor = (int) $cursor;
$roomcursor = [];

// Build classroom occupancy map from existing sessions.
$classroomoccupancy = [];
$existing = $DB->get_records_sql(
    "SELECT id, classroomid, starttime, endtime
       FROM {local_tm_course_sessions}
      WHERE classroomid IS NOT NULL
        AND classroomid > 0"
);
foreach ($existing as $row) {
    $rid = (int)$row->classroomid;
    if (empty($classroomoccupancy[$rid])) {
        $classroomoccupancy[$rid] = [];
    }
    $classroomoccupancy[$rid][] = [
        'start' => (int)$row->starttime,
        'end' => (int)$row->endtime,
    ];
}

foreach ($courseids as $idx => $cid) {
    $classname = $coursenames[$cid] ?? ('Course #' . $cid);
    $classroomid = enabled_course_manager::resolve_plan_classroom(
        (int)$cid,
        $delivery,
        $preferredclassroomid,
        $delivery === session_manager::DELIVERY_ONSITE ? $fallbackclassroomid : 0
    );
    if ($delivery === session_manager::DELIVERY_ONLINE && $classroomid <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'reservation_calendar_error_online_classroom_unconfigured',
            'events' => [],
        ]);
        exit;
    }

    if ($delivery === session_manager::DELIVERY_ONSITE) {
        // Seed from this room's own chain point; different room => back to preferred date.
        $seed = ($classroomid > 0 && isset($roomcursor[$classroomid]))
            ? (int) $roomcursor[$classroomid]
            : (int) $basecursor;
        $guard = 0;
        $segments = [];
        while ($guard < 500) {
            $roomocc = ($classroomid > 0) ? ($classroomoccupancy[$classroomid] ?? []) : [];
            $planseg = session_manager::plan_onsite_course_segments($cid, $seed, $roomocc);
            $segments = $planseg['segments'];
            $conflict = false;
            foreach ($segments as $sg) {
                $roomconflict = $classroomid > 0
                    && local_tm_course_has_time_conflict($classroomoccupancy[$classroomid] ?? [], (int)$sg['start'], (int)$sg['end']);
                $resvconflict = local_tm_course_has_time_conflict($reservationintervals, (int)$sg['start'], (int)$sg['end']);
                if ($roomconflict || $resvconflict) {
                    $conflict = true;
                    break;
                }
            }
            if (!$conflict) {
                break;
            }
            $firststart = !empty($segments[0]['start']) ? (int)$segments[0]['start'] : (int)$seed;
            $nextday = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $firststart)));
            $seed = session_manager::next_weekday_timestamp(
                (int) strtotime(date('Y-m-d', $nextday) . ' 09:30:00'),
                true
            );
            $guard++;
        }
        foreach ($segments as $segidx => $sg) {
            $start = (int)$sg['start'];
            $end = (int)$sg['end'];
            if ($classroomid > 0) {
                if (empty($classroomoccupancy[$classroomid])) {
                    $classroomoccupancy[$classroomid] = [];
                }
                $classroomoccupancy[$classroomid][] = ['start' => $start, 'end' => $end];
            }
            $reservationintervals[] = ['start' => $start, 'end' => $end];
            $segtitle = $classname . ' · #' . ($idx + 1);
            $events[] = [
                'id' => 'res-' . $id . '-' . ($idx + 1) . '-seg-' . ($segidx + 1),
                'title' => $segtitle,
                'start' => date('c', $start),
                'end' => date('c', $end),
                'allDay' => false,
                'extendedProps' => [
                    'eventType' => 'reservation_plan',
                    'courseId' => $cid,
                    'planGroup' => 'res-' . $id . '-course-' . ($idx + 1),
                    'classroomId' => $classroomid,
                    'classroomLabel' => $classroomlabels[$classroomid] ?? '',
                    'deliveryMode' => $delivery,
                    'teachingLanguage' => (string)$reservation->teaching_language,
                    'teachingHours' => (float)$sg['teachinghours'],
                    'durationHours' => ($end - $start) / HOURSECS,
                    'preferredStartHm' => '09:30',
                ],
            ];
        }
        if ($classroomid > 0) {
            $roomcursor[$classroomid] = (int)$planseg['nextcursor'];
        }
        // Onsite courses no longer advance the shared cursor onto another room's end.
        continue;
    } else {
        $guard = 0;
        $planblocks = [];
        while ($guard < 500) {
            $onlineplan = local_tm_course_build_online_blocks_average(
                $cid,
                (int)$cursor,
                $preferredhhmmss,
                $onlinedayendhhmmss,
                $forceddailyhours
            );
            $planblocks = $onlineplan['blocks'];
            $hasconflict = false;
            foreach ($planblocks as $pb) {
                $start = (int)$pb['start'];
                $end = (int)$pb['end'];
                $roomconflict = $classroomid > 0
                    && local_tm_course_has_time_conflict($classroomoccupancy[$classroomid] ?? [], $start, $end);
                $resvconflict = local_tm_course_has_time_conflict($reservationintervals, $start, $end);
                if ($roomconflict || $resvconflict) {
                    $hasconflict = true;
                    break;
                }
            }
            if (!$hasconflict) {
                break;
            }
            $firststart = !empty($planblocks[0]['start']) ? (int)$planblocks[0]['start'] : (int)$cursor;
            $nextday = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $firststart)));
            $cursor = session_manager::next_weekday_timestamp(
                (int) strtotime(date('Y-m-d', $nextday) . ' ' . $preferredhhmmss),
                false
            );
            $guard++;
        }
        $segcount = max(1, count($planblocks));
        foreach ($planblocks as $segidx => $pb) {
            $start = (int)$pb['start'];
            $end = (int)$pb['end'];
            $durationhours = (float)$pb['durationhours'];
            if ($classroomid > 0) {
                if (empty($classroomoccupancy[$classroomid])) {
                    $classroomoccupancy[$classroomid] = [];
                }
                $classroomoccupancy[$classroomid][] = ['start' => $start, 'end' => $end];
            }
            $reservationintervals[] = ['start' => $start, 'end' => $end];
            $title = $classname . ' · #' . ($idx + 1);
            $events[] = [
                'id' => 'res-' . $id . '-' . ($idx + 1) . '-seg-' . ($segidx + 1),
                'title' => $title,
                'start' => date('c', $start),
                'end' => date('c', $end),
                'allDay' => false,
                'extendedProps' => [
                    'eventType' => 'reservation_plan',
                    'courseId' => $cid,
                    'planGroup' => 'res-' . $id . '-course-' . ($idx + 1),
                    'classroomId' => $classroomid,
                    'classroomLabel' => $classroomlabels[$classroomid] ?? '',
                    'deliveryMode' => $delivery,
                    'teachingLanguage' => (string)$reservation->teaching_language,
                    'teachingHours' => $onlineplan['teachinghours'],
                    'durationHours' => $durationhours,
                    'preferredStartHm' => $preferredstarthm,
                ],
            ];
        }
        $nextcursor = (int)$onlineplan['nextcursor'];
        $cursor = $nextcursor;
        continue;
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'events' => $events]);
exit;

