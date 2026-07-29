<?php
/**
 * Dedicated class request interactive calendar (Phase 3 initial).
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/enabled_course_manager.php');
require_once(__DIR__ . '/../classes/reservation_application.php');

require_login();

global $DB, $USER;

$context = context_system::instance();
$issiteadmin = is_siteadmin();
$canbatch = \local_tm_course\permissions_manager::user_can_batch_enrol();
if (!$issiteadmin && !$canbatch) {
    throw new required_capability_exception($context, 'local/tm_course:batchenrol', 'nopermissions', '');
}

$id = required_param('id', PARAM_INT);
$reservation = $DB->get_record('local_tm_course_reservation', ['id' => $id], '*', MUST_EXIST);
if ((int)$reservation->requesterid !== (int)$USER->id && !$issiteadmin) {
    throw new required_capability_exception($context, 'local/tm_course:manage', 'nopermissions', '');
}
$isadminreviewmode = $issiteadmin && ((int)$reservation->requesterid !== (int)$USER->id || optional_param('review', 0, PARAM_INT) === 1);
if (!$isadminreviewmode && \local_tm_course\reservation_application::is_formally_submitted($reservation)) {
    redirect(
        new moodle_url('/local/tm_course/reservation/tracking.php'),
        get_string('reservation_error_already_submitted', 'local_tm_course'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$PAGE->set_context($context);
$pageparams = ['id' => $id];
if ($isadminreviewmode) {
    $pageparams['review'] = 1;
}
$PAGE->set_url(new moodle_url('/local/tm_course/reservation/calendar.php', $pageparams));
$PAGE->set_pagelayout('standard');
$pagetitle = $isadminreviewmode
    ? '課程日期選定（系統管理員審核模式）'
    : '課程日期選定';
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);
$PAGE->requires->css('/local/tm_course/styles.css');
$PAGE->requires->css(new moodle_url('https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/main.min.css'));
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/index.global.min.js'), true);
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/locales-all.global.min.js'), true);

$calendarapiurl = (new moodle_url('/local/tm_course/calendar_events.php', ['sesskey' => sesskey()]))->out(false);
$planapiurl = (new moodle_url('/local/tm_course/reservation/plan_events.php', ['id' => $id, 'sesskey' => sesskey()]))->out(false);
$fclocale = str_replace('_', '-', current_language());
$initialdate = date('Y-m-d', max(1, (int)$reservation->preferred_starttime));
$str = function(string $key, string $fallback): string {
    $sm = get_string_manager();
    if ($sm->string_exists($key, 'local_tm_course')) {
        return get_string($key, 'local_tm_course');
    }
    return $fallback;
};
$txtphase3hint = $str('reservation_calendar_phase3_hint', 'Interactive calendar initial build loaded: gray blocks are existing sessions, blue blocks are this request plan blocks.');
$txtlegendexisting = $str('reservation_calendar_legend_existing', 'Existing sessions');
$txtlegendplan = $str('reservation_calendar_legend_plan', 'Request plan');
$txtbadgeplan = $str('reservation_calendar_badge_plan', 'Planned');
$txtclassroom = $str('classroom', 'Classroom');
$txtdelivery = $str('reservation_calendar_delivery', 'Delivery');
$txtlanguage = $str('reservation_calendar_language', 'Language');
$txtdeliveryonsite = get_string('delivery_mode_onsite', 'local_tm_course');
$txtdeliveryonline = get_string('delivery_mode_online', 'local_tm_course');
$txtlangzh = get_string('teaching_language_zh_tw', 'local_tm_course');
$txtlangen = get_string('teaching_language_english', 'local_tm_course');
$txtplanloaded = $str('reservation_calendar_plan_loaded', 'Plan blocks loaded. You can drag the blue blocks to adjust start date/time.');
$txtdragdraft = $str('reservation_calendar_drag_draft', 'Plan block moved (draft only). The final submit action will be added in the next step.');
$txtweekendblocked = $str('reservation_calendar_weekend_blocked', 'Weekend scheduling is not allowed. Please place blocks on weekdays.');
$txtconflictblocked = $str('reservation_calendar_conflict_blocked', 'Classroom conflict detected. This timeslot is already occupied.');
$txtdaylimitblocked = $str('reservation_calendar_daylimit_blocked', 'Daily training limit reached for this classroom/day. Please choose another day.');
$txtautoadjusted = $str('reservation_calendar_autoadjusted', 'Auto-adjusted to next available slot on the same day.');
$txtpreferredtimelocked = $str(
    'reservation_calendar_preferred_time_locked',
    'Preferred start time cannot change. Days with classroom conflicts were skipped; later weekdays still use the same start time.'
);
$txtpreferredtimeconflict = $str(
    'reservation_calendar_preferred_time_conflict',
    'Preferred start time cannot change, and that classroom timeslot is already taken. Please choose another date.'
);
$txtreservationoverlap = $str('reservation_calendar_reservation_overlap', 'This time overlaps another course in the same application. Learners cannot attend two sessions at once.');
$txtonlinedaylimit = $str('reservation_calendar_error_online_day_end_limit', 'Online blocks exceed the configured daily end-time limit.');
$txtlibmissing = $str('calendar_lib_missing', 'FullCalendar library is not loaded. It may be blocked by browser/network CSP.');
$txteventsfailed = $str('calendar_events_load_failed', 'Failed to load calendar events');
$txtunknownerror = $str('calendar_unknown_error', 'Unknown error');
$txtsubmitplan = $isadminreviewmode
    ? $str('reservation_calendar_submit_plan_admin_review', 'Save review adjustments')
    : $str('reservation_calendar_submit_plan', 'Next: verification files (3/3)');
$txtsubmitting = $str('reservation_calendar_submitting', 'Submitting…');
$txtonboardingtitle = $str('reservation_calendar_onboarding_title', 'Calendar tips');
$txtonboardingbody = $str('reservation_calendar_onboarding_body', 'Drag the blue blocks to adjust date and time. The system avoids weekends, classroom conflicts, overlapping courses in this application, and daily training limits.');
$txtonboardingok = $str('reservation_calendar_onboarding_ok', 'Got it');
$txtreplanbtn = $str('reservation_calendar_replan', 'Re-run auto-plan');
$txtreplanconfirm = $str('reservation_calendar_replan_confirm', 'This discards your manual adjustments and lets the system auto-plan all courses again. Continue?');
$txtnavhint = $str('reservation_calendar_nav_hint', 'Tip: to place a block in a later week, use the ‹ › buttons at the top to move the calendar first, then drag.');
$saveerrormap = [
    'reservation_calendar_error_not_pending' => $str('reservation_calendar_error_not_pending', 'This request is no longer pending.'),
    'reservation_calendar_error_no_courses' => $str('reservation_calendar_error_no_courses', 'No courses are linked to this request.'),
    'reservation_calendar_error_block_count' => $str('reservation_calendar_error_block_count', 'The number of plan blocks does not match the selected courses.'),
    'reservation_calendar_error_course_mismatch' => $str('reservation_calendar_error_course_mismatch', 'Plan blocks must include each selected course exactly once.'),
    'reservation_calendar_error_invalid_block' => $str('reservation_calendar_error_invalid_block', 'One or more plan blocks have invalid times.'),
    'reservation_calendar_error_weekend' => $str('reservation_calendar_error_weekend', 'Weekend times are not allowed.'),
    'reservation_calendar_error_classroom' => $str('reservation_calendar_error_classroom', 'Invalid classroom for this course.'),
    'reservation_calendar_error_online_classroom' => $str('reservation_calendar_error_online_classroom', 'Online plan must use the configured online scheduling classroom.'),
    'reservation_calendar_error_online_classroom_unconfigured' => $str('reservation_calendar_error_online_classroom_unconfigured', 'This course has no online scheduling classroom configured.'),
    'reservation_calendar_error_online_day_end_limit' => $str('reservation_calendar_error_online_day_end_limit', 'Online blocks exceed the configured daily end-time limit.'),
    'reservation_calendar_error_overlap_internal' => $str('reservation_calendar_error_overlap_internal', 'Two or more blocks overlap in time.'),
    'reservation_calendar_error_overlap_room' => $str('reservation_calendar_error_overlap_room', 'A block overlaps an existing session in the same classroom.'),
    'reservation_calendar_error_priority_course_first' => $str('reservation_calendar_error_priority_course_first', "If selected, AI Cobot Beginner's Training must be scheduled as the first block."),
    'reservation_calendar_error_invalid_request' => $str('reservation_calendar_error_invalid_request', 'Invalid request.'),
    'invalidsesskey' => get_string('invalidsesskey', 'error'),
    'nopermissions' => get_string('nopermissions', 'error'),
];
$physicaldailylimit = \local_tm_course\session_manager::get_physical_daily_limit();
$onlinedayendhhmm = \local_tm_course\session_manager::get_online_day_end_hhmm();
$preferredstarthm = date('H:i', max(1, (int)$reservation->preferred_starttime));

$nextweekdaystart = function(int $ts, string $hhmmss = '09:30:00'): int {
    $base = strtotime(date('Y-m-d', max(1, $ts)) . ' ' . $hhmmss);
    while (true) {
        $w = (int)date('w', $base); // 0 Sun, 6 Sat.
        if ($w >= 1 && $w <= 5) {
            return $base;
        }
        $base = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $base)));
        $base = strtotime(date('Y-m-d', $base) . ' ' . $hhmmss);
    }
};

$fallbackevents = [];
$deliverymode = (string)$reservation->delivery_mode;
$preferredclassroomid = (int)($reservation->preferred_classroomid ?? 0);
$preferredhhmmss = ($deliverymode === \local_tm_course\session_manager::DELIVERY_ONSITE)
    ? '09:30:00'
    : date('H:i:s', max(1, (int)$reservation->preferred_starttime));
// Optional manual daily cap (hours) for online manual split. NULL/0 = no split.
$fallbackforceddailyhours = 0.0;
if ($deliverymode === \local_tm_course\session_manager::DELIVERY_ONLINE
    && property_exists($reservation, 'online_daily_hours_limit')
    && $reservation->online_daily_hours_limit !== null
    && (float)$reservation->online_daily_hours_limit > 0) {
    $fallbackforceddailyhours = (float)$reservation->online_daily_hours_limit;
}
$fallbackstart = $nextweekdaystart(max(1, (int)$reservation->preferred_starttime), $preferredhhmmss);
$fallbackcursor = $fallbackstart;
$fallbackclassroomlabels = [];
$fallbackclassrooms = $DB->get_records('local_tm_classroom', [], '', 'id, name, location');
foreach ($fallbackclassrooms as $room) {
    $label = trim((string)$room->name);
    $loc = trim((string)($room->location ?? ''));
    if ($loc !== '') {
        $label .= ' — ' . $loc;
    }
    $fallbackclassroomlabels[(int)$room->id] = $label;
}
$fallbackdefaultclassroomid = 0;
if (!empty($fallbackclassroomlabels)) {
    reset($fallbackclassroomlabels);
    $fallbackdefaultclassroomid = (int)key($fallbackclassroomlabels);
}
$fallbackcourseids = [];
if (!empty($reservation->courseids_json)) {
    $decoded = json_decode((string)$reservation->courseids_json, true);
    if (is_array($decoded)) {
        foreach ($decoded as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) {
                $fallbackcourseids[] = $cid;
            }
        }
    } else {
        $parts = explode(',', (string)$reservation->courseids_json);
        foreach ($parts as $part) {
            $cid = (int)trim($part);
            if ($cid > 0) {
                $fallbackcourseids[] = $cid;
            }
        }
    }
}
if (empty($fallbackcourseids) && (int)$reservation->courseid > 0) {
    $fallbackcourseids[] = (int)$reservation->courseid;
}
$fallbackcourseids = array_values(array_unique($fallbackcourseids));

$fallbackcoursenames = [];
if (!empty($fallbackcourseids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($fallbackcourseids, SQL_PARAMS_NAMED);
    $courserecs = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $insql", $inparams);
    $prioritycourseid = 0;
    $ispriorityname = function(string $name): bool {
        $n = strtolower(trim($name));
        $n = str_replace(["\xE2\x80\x99", "\xE2\x80\x98", "'", ' '], '', $n);
        return strpos($n, 'aicobotbeginner') !== false;
    };
    foreach ($courserecs as $cr) {
        $fallbackcoursenames[(int)$cr->id] = format_string((string)$cr->fullname);
        if ($ispriorityname((string)$cr->fullname)) {
            $prioritycourseid = (int)$cr->id;
        }
    }
    if ($prioritycourseid > 0) {
        $orderedcourseids = [$prioritycourseid];
        foreach ($fallbackcourseids as $cid) {
            if ((int)$cid !== $prioritycourseid) {
                $orderedcourseids[] = (int)$cid;
            }
        }
        $fallbackcourseids = $orderedcourseids;
    }
}
if (empty($fallbackcourseids)) {
    $fallbackcourseids[] = 0;
}

$onlineclassroomblocked = false;
$onlineclassroomblockmsg = '';
if ($deliverymode === \local_tm_course\session_manager::DELIVERY_ONLINE) {
    $checkcourseids = array_values(array_filter(array_map('intval', $fallbackcourseids), function($v) {
        return $v > 0;
    }));
    $missingonline = \local_tm_course\enabled_course_manager::get_missing_online_classroom_course_ids($checkcourseids);
    if (!empty($missingonline)) {
        $onlineclassroomblocked = true;
        $onlineclassroomblockmsg = \local_tm_course\enabled_course_manager::format_missing_online_classroom_error(
            $missingonline,
            $fallbackcoursenames
        );
    }
}

$fbintervalconflict = function (array $intervals, int $start, int $end): bool {
    foreach ($intervals as $itv) {
        $s = (int)($itv['start'] ?? 0);
        $e = (int)($itv['end'] ?? 0);
        if ($s > 0 && $e > 0 && $s < $end && $e > $start) {
            return true;
        }
    }
    return false;
};
$buildonlineavgblocks = function(int $courseid, int $cursor, string $hhmmss, string $dayendhhmmss, float $forceddailyhours = 0.0) use (&$nextweekdaystart): array {
    $teachinghours = \local_tm_course\enabled_course_manager::get_default_duration_hours((int)$courseid, \local_tm_course\session_manager::DELIVERY_ONLINE);
    $remainingsecs = (int)round($teachinghours * HOURSECS);
    $firststart = strtotime(date('Y-m-d', $cursor) . ' ' . $hhmmss);
    while (true) {
        $w = (int)date('w', $firststart);
        if ($w >= 1 && $w <= 5) { break; }
        $firststart = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $firststart)));
        $firststart = strtotime(date('Y-m-d', $firststart) . ' ' . $hhmmss);
    }
    $firstdayend = strtotime(date('Y-m-d', $firststart) . ' ' . $dayendhhmmss);
    $firstavail = max(1, $firstdayend - $firststart);
    if ($forceddailyhours > 0 && $teachinghours > 0) {
        // Manual split mode (mirrors local_tm_course_build_online_blocks_average in plan_events.php).
        $days = max(1, (int)ceil($teachinghours / max(0.01, $forceddailyhours)));
    } else {
        $days = max(1, (int)ceil($remainingsecs / $firstavail));
    }
    $blocks = [];
    $daystart = $firststart;
    for ($i = 0; $i < $days; $i++) {
        $daysremain = max(1, $days - $i);
        $targetsecs = (int)round($remainingsecs / $daysremain);
        $daylimit = strtotime(date('Y-m-d', $daystart) . ' ' . $dayendhhmmss);
        $avail = max(1, $daylimit - $daystart);
        $use = min($targetsecs, $avail);
        $end = $daystart + $use;
        $blocks[] = [
            'start' => (int)$daystart,
            'end' => (int)$end,
            'durationhours' => $use / HOURSECS,
            'teachinghours' => $teachinghours,
        ];
        $remainingsecs -= $use;
        if ($i < $days - 1) {
            $nextday = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $daystart)));
            $daystart = $nextweekdaystart((int)$nextday, $hhmmss);
        }
    }
    $last = end($blocks);
    $nextcursor = !empty($last) ? (int)$last['end'] : (int)$firststart;
    return ['blocks' => $blocks, 'nextcursor' => $nextcursor, 'teachinghours' => $teachinghours];
};

$fbclassroomocc = [];
foreach ($DB->get_records_sql(
    "SELECT id, classroomid, starttime, endtime
       FROM {local_tm_course_sessions}
      WHERE classroomid IS NOT NULL AND classroomid > 0"
) as $fbrow) {
    $rid = (int)$fbrow->classroomid;
    if (empty($fbclassroomocc[$rid])) {
        $fbclassroomocc[$rid] = [];
    }
    $fbclassroomocc[$rid][] = ['start' => (int)$fbrow->starttime, 'end' => (int)$fbrow->endtime];
}
$fbreservationintervals = [];
// Per-classroom chaining (mirror of plan_events.php): different rooms never chain onto
// each other's end, avoiding evening/overnight auto-placement across unrelated rooms.
$fbbasecursor = (int) $fallbackcursor;
$fbroomcursor = [];

if (!$onlineclassroomblocked) {
foreach ($fallbackcourseids as $idx => $cid) {
    $title = $fallbackcoursenames[$cid] ?? 'Draft block';
    $delivery = (string)$reservation->delivery_mode;
    $hours = ($cid > 0) ? \local_tm_course\enabled_course_manager::get_default_duration_hours((int)$cid, $delivery) : 2.0;
    $fallbackclassroomid = \local_tm_course\enabled_course_manager::resolve_plan_classroom(
        (int)$cid,
        $delivery,
        $preferredclassroomid,
        $delivery === \local_tm_course\session_manager::DELIVERY_ONSITE ? $fallbackdefaultclassroomid : 0
    );

    $start = 0;
    $end = 0;
    $durationhours = 0.0;
    $guard = 0;

    if ($delivery === \local_tm_course\session_manager::DELIVERY_ONSITE && $cid > 0) {
        $seed = ($fallbackclassroomid > 0 && isset($fbroomcursor[$fallbackclassroomid]))
            ? (int) $fbroomcursor[$fallbackclassroomid]
            : (int) $fbbasecursor;
        $segments = [];
        $planseg = ['segments' => [], 'nextcursor' => $seed];
        while ($guard < 500) {
            $roomocc = ($fallbackclassroomid > 0) ? ($fbclassroomocc[$fallbackclassroomid] ?? []) : [];
            try {
                $planseg = \local_tm_course\session_manager::plan_onsite_course_segments((int)$cid, $seed, $roomocc);
            } catch (\Throwable $e) {
                // Best-effort fallback only (the AJAX plan API is the primary source); skip on failure.
                $segments = [];
                break;
            }
            $segments = $planseg['segments'];
            $conflict = false;
            foreach ($segments as $sg) {
                $roomconflict = $fallbackclassroomid > 0
                    && $fbintervalconflict($fbclassroomocc[$fallbackclassroomid] ?? [], (int)$sg['start'], (int)$sg['end']);
                $resvconflict = $fbintervalconflict($fbreservationintervals, (int)$sg['start'], (int)$sg['end']);
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
            $seed = $nextweekdaystart((int)$nextday, '09:30:00');
            $guard++;
        }
        foreach ($segments as $segidx => $sg) {
            $start = (int)$sg['start'];
            $end = (int)$sg['end'];
            if ($fallbackclassroomid > 0) {
                if (empty($fbclassroomocc[$fallbackclassroomid])) {
                    $fbclassroomocc[$fallbackclassroomid] = [];
                }
                $fbclassroomocc[$fallbackclassroomid][] = ['start' => $start, 'end' => $end];
            }
            $fbreservationintervals[] = ['start' => $start, 'end' => $end];
            $fallbackevents[] = [
                'id' => 'res-' . $id . '-client-fallback-' . ($idx + 1) . '-seg-' . ($segidx + 1),
                'title' => $title . ' · #' . ($idx + 1),
                'start' => date('c', $start),
                'end' => date('c', $end),
                'allDay' => false,
                'extendedProps' => [
                    'eventType' => 'reservation_plan',
                    'courseId' => (int) $cid,
                    'planGroup' => 'res-' . $id . '-course-' . ($idx + 1),
                    'classroomId' => $fallbackclassroomid,
                    'classroomLabel' => $fallbackclassroomlabels[$fallbackclassroomid] ?? '',
                    'deliveryMode' => $delivery,
                    'teachingLanguage' => (string)$reservation->teaching_language,
                    'teachingHours' => (float)$sg['teachinghours'],
                    'durationHours' => ($end - $start) / HOURSECS,
                    'preferredStartHm' => '09:30',
                ],
            ];
        }
        if ($fallbackclassroomid > 0) {
            $fbroomcursor[$fallbackclassroomid] = (int)$planseg['nextcursor'];
        }
        continue;
    } else {
        $plan = $buildonlineavgblocks((int)$cid, (int)$fallbackcursor, $preferredhhmmss, $onlinedayendhhmm . ':00', $fallbackforceddailyhours);
        $durations = [];
        foreach ($plan['blocks'] as $ob) {
            $durations[] = (float)($ob['durationhours'] ?? 0);
        }
        $teachinghoursfb = (float)($plan['teachinghours'] ?? 0);
        $onlineblocks = [];
        $daycursor = $nextweekdaystart((int)$fallbackcursor, $preferredhhmmss);
        foreach ($durations as $durh) {
            if ($durh <= 0) {
                continue;
            }
            $usecsecs = (int) round($durh * HOURSECS);
            $placed = false;
            $placeguard = 0;
            while ($placeguard < 500) {
                $start = (int) $daycursor;
                $end = $start + $usecsecs;
                $dayendlimit = strtotime(date('Y-m-d', $start) . ' ' . $onlinedayendhhmm . ':00');
                $overdayend = ($end > $dayendlimit);
                $roomconflict = $fallbackclassroomid > 0
                    && $fbintervalconflict($fbclassroomocc[$fallbackclassroomid] ?? [], $start, $end);
                $resvconflict = $fbintervalconflict($fbreservationintervals, $start, $end);
                if (!$overdayend && !$roomconflict && !$resvconflict) {
                    $onlineblocks[] = [
                        'start' => $start,
                        'end' => $end,
                        'durationhours' => $usecsecs / HOURSECS,
                        'teachinghours' => $teachinghoursfb,
                    ];
                    $nextday = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $start)));
                    $daycursor = $nextweekdaystart((int)$nextday, $preferredhhmmss);
                    $placed = true;
                    break;
                }
                $nextday = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $start)));
                $daycursor = $nextweekdaystart((int)$nextday, $preferredhhmmss);
                $placeguard++;
            }
            if (!$placed) {
                $onlineblocks = [];
                break;
            }
        }
        foreach ($onlineblocks as $segidx => $ob) {
            $start = (int)$ob['start'];
            $end = (int)$ob['end'];
            $durationhours = (float)$ob['durationhours'];
            if ($fallbackclassroomid > 0) {
                if (empty($fbclassroomocc[$fallbackclassroomid])) {
                    $fbclassroomocc[$fallbackclassroomid] = [];
                }
                $fbclassroomocc[$fallbackclassroomid][] = ['start' => $start, 'end' => $end];
            }
            $fbreservationintervals[] = ['start' => $start, 'end' => $end];
            $titlewithseg = $title . ' · #' . ($idx + 1);
            $fallbackevents[] = [
                'id' => 'res-' . $id . '-client-fallback-' . ($idx + 1) . '-seg-' . ($segidx + 1),
                'title' => $titlewithseg,
                'start' => date('c', $start),
                'end' => date('c', $end),
                'allDay' => false,
                'extendedProps' => [
                    'eventType' => 'reservation_plan',
                    'courseId' => (int) $cid,
                    'planGroup' => 'res-' . $id . '-course-' . ($idx + 1),
                    'classroomId' => $fallbackclassroomid,
                    'classroomLabel' => $fallbackclassroomlabels[$fallbackclassroomid] ?? '',
                    'deliveryMode' => $delivery,
                    'teachingLanguage' => (string)$reservation->teaching_language,
                    'teachingHours' => (float)$ob['teachinghours'],
                    'durationHours' => $durationhours,
                    'preferredStartHm' => $preferredstarthm,
                ],
            ];
        }
        if (!empty($onlineblocks)) {
            $lastob = end($onlineblocks);
            $fallbackcursor = (int)$lastob['end'];
        }
        continue;
    }
}
}
$savedplandevents = [];
$savedgroupbycourse = [];
$planjsonstored = isset($reservation->calendar_plan_json) ? (string) $reservation->calendar_plan_json : '';
if ($planjsonstored !== '') {
    $decodedplan = json_decode($planjsonstored, true);
    if (is_array($decodedplan)) {
        $si = 0;
        foreach ($decodedplan as $row) {
            $si++;
            if (!is_array($row)) {
                continue;
            }
            $cid = (int) ($row['courseId'] ?? 0);
            $start = (int) ($row['start'] ?? 0);
            $end = (int) ($row['end'] ?? 0);
            if ($start <= 0 || $end <= $start) {
                continue;
            }
            $room = (int) ($row['classroomId'] ?? 0);
            $title = trim((string) ($row['title'] ?? ''));
            $title = preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', $title);
            if ($title === '') {
                $title = ($fallbackcoursenames[$cid] ?? '') !== ''
                    ? $fallbackcoursenames[$cid] . ' · #' . $si
                    : ('Block #' . $si);
            }
            $hours = $cid > 0 ? \local_tm_course\enabled_course_manager::get_default_duration_hours($cid, (string)$reservation->delivery_mode) : 2.0;
            $dur = ($end - $start) / HOURSECS;
            $savedplandevents[] = [
                'id' => 'res-' . $id . '-saved-' . $si,
                'title' => $title,
                'start' => date('c', $start),
                'end' => date('c', $end),
                'allDay' => false,
                'extendedProps' => [
                    'eventType' => 'reservation_plan',
                    'courseId' => $cid,
                    'planGroup' => '',
                    'classroomId' => $room,
                    'classroomLabel' => $fallbackclassroomlabels[$room] ?? '',
                    'deliveryMode' => (string) $reservation->delivery_mode,
                    'teachingLanguage' => (string)$reservation->teaching_language,
                    'teachingHours' => $hours,
                    'durationHours' => $dur,
                    'preferredStartHm' => $preferredstarthm,
                ],
            ];
            $groupkey = 'res-' . $id . '-saved-course-' . $cid;
            if (empty($savedgroupbycourse[$groupkey])) {
                $savedgroupbycourse[$groupkey] = $groupkey;
            }
            $savedplandevents[count($savedplandevents) - 1]['extendedProps']['planGroup'] = $savedgroupbycourse[$groupkey];
        }
    }
}

$showonboarding = empty((int) ($reservation->calendar_onboarding_seen ?? 0));
$showonboarding = $showonboarding && !$isadminreviewmode;
$saveplanurl = (new moodle_url('/local/tm_course/reservation/save_calendar_plan.php'))->out(false);
$onboardingmarkurl = (new moodle_url('/local/tm_course/reservation/mark_calendar_onboarding.php'))->out(false);
$redirectaftersave = $isadminreviewmode
    ? (new moodle_url('/local/tm_course/reservation/calendar.php', ['id' => $id, 'review' => 1, 'calendarsaved' => 1]))->out(false)
    : (new moodle_url('/local/tm_course/reservation/verification.php', ['id' => $id]))->out(false);
$backtoformurl = (new moodle_url('/local/tm_course/reservation/index.php', ['editrid' => $id]))->out(false);
$backtoreviewurl = (new moodle_url('/local/tm_course/admin/review_center.php'))->out(false);
$sesskeyforjs = sesskey();

echo $OUTPUT->header();
if (!$isadminreviewmode) {
    echo html_writer::div(get_string('reservation_calendar_draft_hint', 'local_tm_course'), 'tm-alert tm-alert-info mb-3');
}
echo html_writer::start_div('tm-page-header');
echo html_writer::span('', 'tm-logo-dot');
echo html_writer::tag('h2', $pagetitle);
echo html_writer::end_div();
if (!$isadminreviewmode) {
    echo html_writer::start_div('mb-2');
    echo html_writer::tag('span', '基本資料 (1/3)', ['class' => 'badge badge-secondary mr-1']);
    echo html_writer::tag('span', '月曆編排 (2/3)', ['class' => 'badge badge-info mr-1']);
    echo html_writer::tag('span', '檢核資料 (3/3)', ['class' => 'badge badge-secondary']);
    echo html_writer::end_div();
}

echo html_writer::start_div('tm-card');
echo html_writer::start_div('tm-card-body');
if ($isadminreviewmode) {
    echo html_writer::div(
        $str('reservation_calendar_admin_review_hint', 'You are editing this request as a system admin reviewer. Changes here update the applicant schedule draft for review/approval.'),
        'tm-alert tm-alert-warning'
    );
}
if (optional_param('calendarsaved', 0, PARAM_INT) === 1) {
    \core\notification::success($str('reservation_calendar_admin_review_saved', 'Review schedule has been updated.'));
}
echo html_writer::div($txtphase3hint, 'tm-alert tm-alert-info');
if ($onlineclassroomblocked && $onlineclassroomblockmsg !== '') {
    echo html_writer::div($onlineclassroomblockmsg, 'tm-alert tm-alert-error');
}
echo html_writer::start_div('tm-calendar-legend mb-2');
echo html_writer::tag('span', $txtlegendexisting, ['class' => 'badge badge-secondary mr-2']);
echo html_writer::tag('span', $txtlegendplan, ['class' => 'badge badge-info']);
echo html_writer::end_div();
echo html_writer::start_div('tm-cal-actions mt-3 mb-2');
if (!$isadminreviewmode) {
    echo html_writer::link($backtoformurl, $str('reservation_calendar_back_to_form', 'Back to form'), ['class' => 'btn btn-secondary mr-2']);
} else {
    echo html_writer::link($backtoreviewurl, $str('reservation_calendar_back_to_review', 'Back to review center'), ['class' => 'btn btn-secondary mr-2']);
}
echo html_writer::tag('button', $txtreplanbtn, ['type' => 'button', 'class' => 'btn btn-outline-secondary mr-2', 'id' => 'tm-cal-replan']);
echo html_writer::tag('button', $txtsubmitplan, ['type' => 'button', 'class' => 'btn btn-primary', 'id' => 'tm-cal-submit-plan']);
echo html_writer::end_div();
echo html_writer::div($txtnavhint, 'tm-session-muted small mb-2');
echo html_writer::div('', 'tm-alert tm-alert-info', ['id' => 'tm-calendar-plan-info', 'style' => 'display:none;']);
echo html_writer::div('', 'tm-alert tm-alert-error', ['id' => 'tm-calendar-error', 'style' => 'display:none;']);
echo html_writer::div('', '', ['id' => 'tm-month-calendar']);
echo html_writer::div('', '', ['id' => 'tm-calendar-tooltip', 'class' => 'tm-calendar-tooltip', 'hidden' => 'hidden']);
echo html_writer::start_div('tm-cancel-modal-backdrop', [
    'id' => 'tm-cal-onboarding',
    'style' => 'display:none;',
    'role' => 'dialog',
    'aria-modal' => 'true',
    'aria-labelledby' => 'tm-cal-onboarding-title',
]);
echo html_writer::start_div('tm-cancel-modal-panel tm-mode-modal-panel');
echo html_writer::tag('h3', $txtonboardingtitle, ['id' => 'tm-cal-onboarding-title', 'class' => 'mb-2']);
echo html_writer::div($txtonboardingbody, 'mb-3');
echo html_writer::tag('button', $txtonboardingok, ['type' => 'button', 'class' => 'btn btn-primary', 'id' => 'tm-cal-onboarding-ok']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::script("
(function() {
    var calendarRoot = document.getElementById('tm-month-calendar');
    var tooltip = document.getElementById('tm-calendar-tooltip');
    var errorBox = document.getElementById('tm-calendar-error');
    var planInfoBox = document.getElementById('tm-calendar-plan-info');
    var reservationId = " . (int) $id . ";
    var tmSesskey = " . json_encode($sesskeyforjs) . ";
    var savePlanUrl = " . json_encode($saveplanurl) . ";
    var markOnboardingUrl = " . json_encode($onboardingmarkurl) . ";
    var redirectAfterSave = " . json_encode($redirectaftersave) . ";
    var saveErrorMap = " . json_encode($saveerrormap) . ";
    var savedPlanEventsFromDb = " . json_encode($savedplandevents) . ";
    var hasSavedPlanFromDb = savedPlanEventsFromDb.length > 0;
    var showOnboarding = " . ($showonboarding ? 'true' : 'false') . ";
    var tmCalendarRef = null;
    var fallbackPlanEvents = " . json_encode($fallbackevents) . ";
    var onlineClassroomBlocked = " . ($onlineclassroomblocked ? 'true' : 'false') . ";
    var draftOverrides = {};
    var replanConfirmText = " . json_encode($txtreplanconfirm) . ";
    var physicalDailyLimitHours = " . json_encode((float)$physicaldailylimit) . ";
    var onlineDayEndHm = " . json_encode($onlinedayendhhmm) . ";
    var durationCalcBase = " . json_encode((new moodle_url('/local/tm_course/duration_calc.php', ['sesskey' => sesskey()]))->out(false)) . ";
    if (!calendarRoot) {
        return;
    }

    function buildPlanPayload(cal) {
        var out = [];
        var evs = cal.getEvents();
        for (var i = 0; i < evs.length; i++) {
            var ev = evs[i];
            var ext = ev.extendedProps || {};
            if (String(ext.eventType || '') !== 'reservation_plan') {
                continue;
            }
            if (!(ev.start instanceof Date) || !(ev.end instanceof Date)) {
                continue;
            }
            out.push({
                courseId: Number(ext.courseId || 0),
                classroomId: Number(ext.classroomId || 0),
                start: Math.floor(ev.start.getTime() / 1000),
                end: Math.floor(ev.end.getTime() / 1000),
                title: String(ev.title || '')
            });
        }
        return out;
    }

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function parseEventInstant(v) {
        if (v === null || v === undefined || v === '') {
            return null;
        }
        var d = new Date(v);
        return isNaN(d.getTime()) ? null : d;
    }

    function formatHm(d) {
        if (!(d instanceof Date)) {
            return '';
        }
        var hh = String(d.getHours()).padStart(2, '0');
        var mm = String(d.getMinutes()).padStart(2, '0');
        return hh + ':' + mm;
    }

    function positionTooltip(anchorEl) {
        if (!tooltip || !anchorEl) {
            return;
        }
        var rect = anchorEl.getBoundingClientRect();
        var tw = tooltip.offsetWidth || 280;
        var th = tooltip.offsetHeight || 120;
        var left = rect.left + (rect.width / 2) - (tw / 2);
        var top = rect.top - th - 8;
        var vw = window.innerWidth || document.documentElement.clientWidth || 1200;
        if (left + tw > vw - 8) {
            left = vw - tw - 8;
        }
        if (left < 8) {
            left = 8;
        }
        if (top < 8) {
            top = rect.bottom + 8;
        }
        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
    }

    function showTooltip(anchorEl, html) {
        if (!tooltip || tmTooltipSuppressed) {
            return;
        }
        tooltip.innerHTML = html;
        tooltip.hidden = false;
        if (tooltip.parentNode !== document.body) {
            document.body.appendChild(tooltip);
        }
        positionTooltip(anchorEl);
    }

    function hideTooltip() {
        if (tooltip) {
            tooltip.hidden = true;
        }
    }

    var tmTooltipSuppressed = false;

    function suppressTooltipForDrag() {
        tmTooltipSuppressed = true;
        hideTooltip();
    }

    function releaseTooltipSuppression() {
        tmTooltipSuppressed = false;
    }

    function showCalendarError(msg) {
        if (!errorBox) {
            return;
        }
        errorBox.textContent = msg || " . json_encode($txtunknownerror) . ";
        errorBox.style.display = 'block';
    }

    function clearCalendarError() {
        if (!errorBox) {
            return;
        }
        errorBox.style.display = 'none';
        errorBox.textContent = '';
    }

    function showPlanInfo(msg) {
        if (!planInfoBox) {
            return;
        }
        planInfoBox.textContent = msg;
        planInfoBox.style.display = 'block';
    }

    function dayStartAt0930(d) {
        return new Date(d.getFullYear(), d.getMonth(), d.getDate(), 9, 30, 0, 0);
    }

    function dayEndByLimit(dayStart) {
        var ms = Math.max(1, Number(physicalDailyLimitHours || 7)) * 3600 * 1000;
        return new Date(dayStart.getTime() + ms);
    }

    /** Physical plan: end of calendar day for conflict scans when chaining afternoon blocks. */
    function dayEndCalendar(dateObj) {
        return new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate(), 23, 59, 0, 0);
    }

    function fetchOnsitePlanTimes(courseId, chainStart) {
        var qs = '&courseid=' + encodeURIComponent(String(courseId))
            + '&delivery_mode=onsite'
            + '&start_date=' + encodeURIComponent(dateKey(chainStart))
            + '&start_hour=' + encodeURIComponent(String(chainStart.getHours()))
            + '&start_minute=' + encodeURIComponent((chainStart.getMinutes() >= 30) ? 30 : 0)
            + '&respect_start=1';
        return fetch(durationCalcBase + qs, {credentials: 'same-origin'}).then(function(r) {
            return r.json();
        });
    }

    function parseHm(hm) {
        var m = String(hm || '').match(/^(\d{2}):(\d{2})$/);
        if (!m) {
            return {h: 22, m: 30};
        }
        var h = Number(m[1]);
        var mm = Number(m[2]);
        if (!(h >= 0 && h <= 23)) { h = 22; }
        if (!(mm === 0 || mm === 30)) { mm = 30; }
        return {h: h, m: mm};
    }

    function onlineDayEndAt(dateObj) {
        var hm = parseHm(onlineDayEndHm);
        return new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate(), hm.h, hm.m, 0, 0);
    }

    function preferredStartAt(dateObj, preferredHm) {
        var hm = parseHm(preferredHm || '');
        return new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate(), hm.h, hm.m, 0, 0);
    }

    function mergeIntervals(intervals) {
        if (!intervals.length) {
            return [];
        }
        intervals.sort(function(a, b) { return a.start.getTime() - b.start.getTime(); });
        var out = [intervals[0]];
        for (var i = 1; i < intervals.length; i++) {
            var cur = intervals[i];
            var last = out[out.length - 1];
            if (cur.start.getTime() <= last.end.getTime()) {
                if (cur.end.getTime() > last.end.getTime()) {
                    last.end = cur.end;
                }
            } else {
                out.push(cur);
            }
        }
        return out;
    }

    function collectDayClassroomIntervals(calendar, classroomId, dayStart, dayEnd, movingEventId) {
        var rid = Number(classroomId || 0);
        if (!rid) {
            return [];
        }
        var events = calendar.getEvents ? calendar.getEvents() : [];
        var ranges = [];
        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            if (!ev) { continue; }
            if (String(ev.id) === String(movingEventId)) { continue; }
            var ext = ev.extendedProps || {};
            if (String(ext.eventType || '') === 'reservation_plan_group_display') { continue; }
            if (Number(ext.classroomId || 0) !== rid) { continue; }
            if (!(ev.start instanceof Date)) { continue; }
            var s = new Date(ev.start.getTime());
            var e = (ev.end instanceof Date) ? new Date(ev.end.getTime()) : new Date(s.getTime() + 30 * 60 * 1000);
            if (e <= dayStart || s >= dayEnd) { continue; }
            if (s < dayStart) { s = new Date(dayStart.getTime()); }
            if (e > dayEnd) { e = new Date(dayEnd.getTime()); }
            ranges.push({start: s, end: e});
        }
        return mergeIntervals(ranges);
    }

    /**
     * Busy intervals for floating placement: same-classroom conflicts + any other plan block
     * in this application (learners cannot overlap in time).
     */
    function collectDayBlockingIntervals(calendar, myClassroomId, dayStart, dayEnd, movingEventId) {
        var myRoom = Number(myClassroomId || 0);
        var events = calendar.getEvents ? calendar.getEvents() : [];
        var ranges = [];
        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            if (!ev) { continue; }
            if (String(ev.id) === String(movingEventId)) { continue; }
            if (!(ev.start instanceof Date)) { continue; }
            var ext = ev.extendedProps || {};
            var et = String(ext.eventType || '');
            var s = new Date(ev.start.getTime());
            var e = (ev.end instanceof Date) ? new Date(ev.end.getTime()) : new Date(s.getTime() + 30 * 60 * 1000);
            if (e <= dayStart || s >= dayEnd) { continue; }
            if (s < dayStart) { s = new Date(dayStart.getTime()); }
            if (e > dayEnd) { e = new Date(dayEnd.getTime()); }
            if (et === 'reservation_plan') {
                ranges.push({start: s, end: e});
            } else if (et === 'existing_session' || et === '' || ext.isRoomClosed) {
                if (myRoom > 0 && Number(ext.classroomId || 0) === myRoom) {
                    ranges.push({start: s, end: e});
                }
            }
        }
        return mergeIntervals(ranges);
    }

    function intervalSpansWeekend(startMs, endMs) {
        if (!(endMs > startMs)) {
            return false;
        }
        var d = new Date(startMs);
        d.setHours(0, 0, 0, 0);
        var last = new Date(endMs - 1);
        last.setHours(0, 0, 0, 0);
        while (d.getTime() <= last.getTime()) {
            var wd = d.getDay();
            if (wd === 0 || wd === 6) {
                return true;
            }
            d.setDate(d.getDate() + 1);
        }
        return false;
    }

    function collectDayBlockingIntervalsWithExcludes(calendar, myClassroomId, dayStart, dayEnd, excludeIds) {
        var myRoom = Number(myClassroomId || 0);
        var events = calendar.getEvents ? calendar.getEvents() : [];
        var ranges = [];
        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            if (!ev) { continue; }
            if (excludeIds && excludeIds[String(ev.id)]) { continue; }
            if (!(ev.start instanceof Date)) { continue; }
            var ext = ev.extendedProps || {};
            var et = String(ext.eventType || '');
            var s = new Date(ev.start.getTime());
            var e = (ev.end instanceof Date) ? new Date(ev.end.getTime()) : new Date(s.getTime() + 30 * 60 * 1000);
            if (e <= dayStart || s >= dayEnd) { continue; }
            if (s < dayStart) { s = new Date(dayStart.getTime()); }
            if (e > dayEnd) { e = new Date(dayEnd.getTime()); }
            if (et === 'reservation_plan') {
                ranges.push({start: s, end: e});
            } else if (et === 'existing_session' || et === '' || ext.isRoomClosed) {
                if (myRoom > 0 && Number(ext.classroomId || 0) === myRoom) {
                    ranges.push({start: s, end: e});
                }
            }
        }
        return mergeIntervals(ranges);
    }

    function hasReservationPlanOverlap(calendar, start, end, movingEventId) {
        if (!(start instanceof Date) || !(end instanceof Date)) {
            return false;
        }
        var events = calendar.getEvents ? calendar.getEvents() : [];
        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            if (!ev) { continue; }
            if (String(ev.id) === String(movingEventId)) { continue; }
            var ext = ev.extendedProps || {};
            if (String(ext.eventType || '') !== 'reservation_plan') { continue; }
            if (!(ev.start instanceof Date)) { continue; }
            var s = ev.start;
            var e = (ev.end instanceof Date) ? ev.end : new Date(s.getTime() + 30 * 60 * 1000);
            if (s < end && e > start) {
                return true;
            }
        }
        return false;
    }

    function sumIntervalMs(intervals) {
        var total = 0;
        for (var i = 0; i < intervals.length; i++) {
            total += Math.max(0, intervals[i].end.getTime() - intervals[i].start.getTime());
        }
        return total;
    }

    function findAvailableSlotSameDay(intervals, candidateStart, durationMs, dayStart, dayEnd) {
        var next = new Date(Math.max(candidateStart.getTime(), dayStart.getTime()));
        for (var i = 0; i < intervals.length; i++) {
            var itv = intervals[i];
            if (next.getTime() + durationMs <= itv.start.getTime()) {
                return new Date(next.getTime());
            }
            if (next.getTime() < itv.end.getTime()) {
                next = new Date(itv.end.getTime());
            }
        }
        if (next.getTime() + durationMs <= dayEnd.getTime()) {
            return next;
        }
        return null;
    }

    function findLastIntervalEnd(intervals, dayStart) {
        var last = new Date(dayStart.getTime());
        for (var i = 0; i < (intervals || []).length; i++) {
            var itv = intervals[i];
            if (!itv || !(itv.end instanceof Date)) { continue; }
            if (itv.end.getTime() > last.getTime()) {
                last = new Date(itv.end.getTime());
            }
        }
        return last;
    }

    function isWeekendDate(d) {
        if (!(d instanceof Date)) {
            return false;
        }
        var wd = d.getDay();
        return wd === 0 || wd === 6;
    }

    function hasClassroomConflict(calendar, classroomId, start, end, movingEventId) {
        var rid = Number(classroomId || 0);
        if (!rid || !(start instanceof Date) || !(end instanceof Date)) {
            return false;
        }
        var events = calendar.getEvents ? calendar.getEvents() : [];
        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            if (!ev) { continue; }
            if (String(ev.id) === String(movingEventId)) { continue; }
            var ext = ev.extendedProps || {};
            if (String(ext.eventType || '') === 'reservation_plan_group_display') { continue; }
            if (Number(ext.classroomId || 0) !== rid) { continue; }
            var s = ev.start;
            var e = ev.end;
            if (!(s instanceof Date)) { continue; }
            if (!(e instanceof Date)) {
                e = new Date(s.getTime() + 30 * 60 * 1000);
            }
            if (s < end && e > start) {
                return true;
            }
        }
        return false;
    }

    function getPlanGroupEvents(calendar, groupKey) {
        var out = [];
        if (!groupKey) { return out; }
        var events = calendar.getEvents ? calendar.getEvents() : [];
        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            if (!ev) { continue; }
            var ext = ev.extendedProps || {};
            if (String(ext.eventType || '') !== 'reservation_plan') { continue; }
            if (String(ext.planGroup || '') !== String(groupKey)) { continue; }
            if (!(ev.start instanceof Date) || !(ev.end instanceof Date)) { continue; }
            out.push(ev);
        }
        out.sort(function(a, b) {
            return a.start.getTime() - b.start.getTime();
        });
        return out;
    }

    function dateKey(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function nextDateKey(d) {
        var n = new Date(d.getFullYear(), d.getMonth(), d.getDate() + 1, 0, 0, 0, 0);
        return dateKey(n);
    }
    function isNextDay(a, b) {
        var aa = new Date(a.getFullYear(), a.getMonth(), a.getDate(), 0, 0, 0, 0);
        var bb = new Date(b.getFullYear(), b.getMonth(), b.getDate(), 0, 0, 0, 0);
        return (bb.getTime() - aa.getTime()) === (24 * 3600 * 1000);
    }
    function nextWeekdayStartFromDate(baseDate, hour, minute) {
        var d = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate(), hour, minute, 0, 0);
        while (isWeekendDate(d)) {
            d = new Date(d.getFullYear(), d.getMonth(), d.getDate() + 1, hour, minute, 0, 0);
        }
        return d;
    }

    function intervalOverlapsList(intervals, start, end) {
        for (var i = 0; i < (intervals || []).length; i++) {
            var itv = intervals[i];
            if (!itv || !(itv.start instanceof Date) || !(itv.end instanceof Date)) { continue; }
            if (itv.start.getTime() < end.getTime() && itv.end.getTime() > start.getTime()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Place online segments at a FIXED preferred clock time only.
     * On classroom / plan conflict or day-end overflow, skip to the next weekday
     * (same clock time) — never shift start time within a day.
     *
     * @return {{ok:boolean,proposals:Array,skipped:boolean}}
     */
    function placeOnlineSegmentsFixedStart(calendar, segmentSpecs, dropDate, preferredHm, excludeIds) {
        var hm = parseHm(preferredHm);
        var dayCursor = nextWeekdayStartFromDate(
            new Date(dropDate.getFullYear(), dropDate.getMonth(), dropDate.getDate(), 0, 0, 0, 0),
            hm.h,
            hm.m
        );
        var proposals = [];
        var skipped = false;
        for (var si = 0; si < segmentSpecs.length; si++) {
            var seg = segmentSpecs[si];
            var placed = false;
            var guard = 0;
            while (guard < 500) {
                if (isWeekendDate(dayCursor)) {
                    dayCursor = nextWeekdayStartFromDate(
                        new Date(dayCursor.getFullYear(), dayCursor.getMonth(), dayCursor.getDate() + 1, 0, 0, 0, 0),
                        hm.h,
                        hm.m
                    );
                    guard++;
                    continue;
                }
                var start = new Date(dayCursor.getTime());
                var end = new Date(start.getTime() + seg.durationMs);
                var dayLimit = onlineDayEndAt(start);
                if (end.getTime() > dayLimit.getTime()) {
                    skipped = true;
                    dayCursor = nextWeekdayStartFromDate(
                        new Date(start.getFullYear(), start.getMonth(), start.getDate() + 1, 0, 0, 0, 0),
                        hm.h,
                        hm.m
                    );
                    guard++;
                    continue;
                }
                var dayStart = new Date(start.getFullYear(), start.getMonth(), start.getDate(), 8, 0, 0, 0);
                var intervals = collectDayBlockingIntervalsWithExcludes(
                    calendar,
                    seg.classroomId,
                    dayStart,
                    dayLimit,
                    excludeIds
                );
                if (intervalOverlapsList(intervals, start, end)) {
                    skipped = true;
                    dayCursor = nextWeekdayStartFromDate(
                        new Date(start.getFullYear(), start.getMonth(), start.getDate() + 1, 0, 0, 0, 0),
                        hm.h,
                        hm.m
                    );
                    guard++;
                    continue;
                }
                proposals.push({ event: seg.event, start: start, end: end });
                dayCursor = nextWeekdayStartFromDate(
                    new Date(start.getFullYear(), start.getMonth(), start.getDate() + 1, 0, 0, 0, 0),
                    hm.h,
                    hm.m
                );
                placed = true;
                break;
            }
            if (!placed) {
                return { ok: false, proposals: [], skipped: skipped };
            }
        }
        return { ok: true, proposals: proposals, skipped: skipped };
    }

    /** Shift a date by n weekdays (n may be negative), skipping Sat/Sun. */
    function addWeekdays(baseDate, n) {
        var d = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate(), 0, 0, 0, 0);
        var step = n >= 0 ? 1 : -1;
        var remaining = Math.abs(n);
        while (remaining > 0) {
            d = new Date(d.getFullYear(), d.getMonth(), d.getDate() + step, 0, 0, 0, 0);
            if (!isWeekendDate(d)) {
                remaining--;
            }
        }
        return d;
    }

    /**
     * Re-plan an onsite multi-day course after any of its day-blocks is dragged.
     * The dragged day lands on the drop day; earlier/later days reflow onto the
     * preceding/following weekdays, each keeping its original duration.
     */
    function replanOnsiteGroup(calendar, info, groupEvents) {
        var draggedId = String(info.event.id);
        var oldDraggedStart = (info.oldEvent && info.oldEvent.start instanceof Date)
            ? info.oldEvent.start.getTime()
            : (info.event.start instanceof Date ? info.event.start.getTime() : 0);
        var ordered = groupEvents.slice().sort(function(a, b) {
            var as = (String(a.id) === draggedId) ? oldDraggedStart : a.start.getTime();
            var bs = (String(b.id) === draggedId) ? oldDraggedStart : b.start.getTime();
            return as - bs;
        });
        var draggedIndex = -1;
        for (var i = 0; i < ordered.length; i++) {
            if (String(ordered[i].id) === draggedId) { draggedIndex = i; break; }
        }
        var dropStart = info.event.start;
        if (draggedIndex < 0 || !(dropStart instanceof Date)) {
            info.revert();
            return;
        }
        var firstDay = addWeekdays(new Date(dropStart.getFullYear(), dropStart.getMonth(), dropStart.getDate(), 0, 0, 0, 0), -draggedIndex);
        var exclude = {};
        for (var e = 0; e < ordered.length; e++) {
            exclude[String(ordered[e].id)] = true;
        }
        var proposals = [];
        var dayCursor = nextWeekdayStartFromDate(firstDay, 9, 30);
        for (var s = 0; s < ordered.length; s++) {
            var seg = ordered[s];
            var durationMs = seg.end.getTime() - seg.start.getTime();
            var ds = new Date(dayCursor.getTime());
            if (isWeekendDate(ds)) {
                info.revert();
                showPlanInfo(" . json_encode($txtweekendblocked) . ");
                return;
            }
            var rid = Number((seg.extendedProps || {}).classroomId || 0);
            var dayStart = new Date(ds.getFullYear(), ds.getMonth(), ds.getDate(), 8, 0, 0, 0);
            var dayEnd = dayEndCalendar(ds);
            var blockIntervals = collectDayBlockingIntervalsWithExcludes(calendar, rid, dayStart, dayEnd, exclude);
            var slot = findAvailableSlotSameDay(blockIntervals, ds, durationMs, dayStart, dayEnd);
            if (!slot && blockIntervals.length > 0) {
                var appendStart = findLastIntervalEnd(blockIntervals, dayStart);
                var appendEnd = new Date(appendStart.getTime() + durationMs);
                if (appendEnd.getTime() <= dayEnd.getTime()) {
                    slot = appendStart;
                }
            }
            if (!slot) {
                info.revert();
                showPlanInfo(" . json_encode($txtconflictblocked) . ");
                return;
            }
            proposals.push({ event: seg, start: slot, end: new Date(slot.getTime() + durationMs) });
            dayCursor = nextWeekdayStartFromDate(new Date(ds.getFullYear(), ds.getMonth(), ds.getDate() + 1, 0, 0, 0, 0), 9, 30);
        }
        for (var p = 0; p < proposals.length; p++) {
            proposals[p].event.setDates(proposals[p].start, proposals[p].end, {allDay: false});
            draftOverrides[String(proposals[p].event.id)] = {
                start: proposals[p].start.toISOString(),
                end: proposals[p].end.toISOString()
            };
        }
        showPlanInfo(" . json_encode($txtautoadjusted) . ");
        rebuildGroupDisplayEvents(calendar);
    }

    var rebuildingGroupDisplay = false;
    function rebuildGroupDisplayEvents(calendar) {
        if (rebuildingGroupDisplay) { return; }
        rebuildingGroupDisplay = true;
        try {
            var events = calendar.getEvents ? calendar.getEvents() : [];
            var groups = {};
            for (var i = 0; i < events.length; i++) {
                var ev = events[i];
                if (!ev) { continue; }
                var ext = ev.extendedProps || {};
                var et = String(ext.eventType || '');
                if (et === 'reservation_plan_group_display') {
                    ev.remove();
                    continue;
                }
                if (et !== 'reservation_plan') { continue; }
                var gk = String(ext.planGroup || '');
                if (!gk) {
                    ev.setProp('display', 'auto');
                    continue;
                }
                if (!groups[gk]) { groups[gk] = []; }
                groups[gk].push(ev);
            }
            Object.keys(groups).forEach(function(gk) {
                var list = groups[gk] || [];
                list.sort(function(a, b) { return a.start.getTime() - b.start.getTime(); });
                if (list.length <= 1) {
                    if (list[0]) { list[0].setProp('display', 'auto'); }
                    return;
                }
                // Onsite multi-day courses stay as individual, linked day blocks so each day's
                // real time is visible; dragging any day re-plans the whole course (see eventDrop).
                var groupDelivery = String((list[0].extendedProps || {}).deliveryMode || '');
                if (groupDelivery === 'onsite') {
                    for (var oj = 0; oj < list.length; oj++) {
                        list[oj].setProp('display', 'auto');
                    }
                    return;
                }
                for (var j = 0; j < list.length; j++) {
                    list[j].setProp('display', 'none');
                }
                var runs = [];
                var current = [list[0]];
                for (var k = 1; k < list.length; k++) {
                    if (isNextDay(list[k - 1].start, list[k].start)) {
                        current.push(list[k]);
                    } else {
                        runs.push(current);
                        current = [list[k]];
                    }
                }
                runs.push(current);
                for (var r = 0; r < runs.length; r++) {
                    var run = runs[r];
                    var first = run[0];
                    var last = run[run.length - 1];
                    var ext = first.extendedProps || {};
                    var startDate = dateKey(first.start);
                    var endDate = nextDateKey(last.start);
                    var startHm = formatHm(first.start);
                    var endHm = formatHm(first.end instanceof Date ? first.end : first.start);
                    calendar.addEvent({
                        id: 'display-' + gk + '-' + (r + 1),
                        title: first.title || '',
                        start: startDate,
                        end: endDate,
                        allDay: true,
                        editable: true,
                        extendedProps: {
                            eventType: 'reservation_plan_group_display',
                            planGroup: gk,
                            classroomId: ext.classroomId || 0,
                            classroomLabel: ext.classroomLabel || '',
                            deliveryMode: ext.deliveryMode || '',
                            teachingLanguage: ext.teachingLanguage || '',
                            groupStartHm: startHm,
                            groupEndHm: endHm
                        }
                    });
                }
            });
        } finally {
            rebuildingGroupDisplay = false;
        }
    }

    function hasOverlapWithExcludes(calendar, start, end, classroomId, excludeIds) {
        var roomConflict = false;
        var resvConflict = false;
        var events = calendar.getEvents ? calendar.getEvents() : [];
        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            if (!ev) { continue; }
            if (excludeIds && excludeIds[String(ev.id)]) { continue; }
            if (!(ev.start instanceof Date)) { continue; }
            var ext = ev.extendedProps || {};
            if (String(ext.eventType || '') === 'reservation_plan_group_display') { continue; }
            var s = ev.start;
            var e = ev.end instanceof Date ? ev.end : new Date(s.getTime() + 30 * 60 * 1000);
            if (!(s < end && e > start)) { continue; }
            if (String(ext.eventType || '') === 'reservation_plan') {
                resvConflict = true;
            }
            if (Number(classroomId || 0) > 0 && Number(ext.classroomId || 0) === Number(classroomId || 0)) {
                roomConflict = true;
            }
            if (roomConflict || resvConflict) { break; }
        }
        return { roomConflict: roomConflict, resvConflict: resvConflict };
    }

    function mapItems(items, defaultType) {
        return (items || []).map(function(item) {
            var startDate = parseEventInstant(item.start);
            if (!startDate) {
                return null;
            }
            var endDate = parseEventInstant(item.end);
            var ext = item.extendedProps || {};
            if (!ext.eventType) {
                ext.eventType = defaultType;
            }
            return {
                id: String(item.id || ''),
                start: startDate,
                end: endDate,
                allDay: false,
                title: item.title || '',
                extendedProps: ext
            };
        }).filter(Boolean);
    }

    function initCalendar() {
        if (typeof FullCalendar === 'undefined') {
            return false;
        }
        if (calendarRoot.getAttribute('data-tm-fc-init') === '1') {
            return true;
        }
        calendarRoot.setAttribute('data-tm-fc-init', '1');

        var calendar = new FullCalendar.Calendar(calendarRoot, {
            initialView: 'dayGridMonth',
            initialDate: " . json_encode($initialdate) . ",
            locale: " . json_encode($fclocale) . ",
            height: 'auto',
            customButtons: {
                prevWeek: {
                    text: '<',
                    click: function() { calendar.incrementDate({days: -7}); }
                },
                nextWeek: {
                    text: '>',
                    click: function() { calendar.incrementDate({days: 7}); }
                }
            },
            headerToolbar: {
                left: 'prevWeek,nextWeek today',
                center: 'title',
                right: ''
            },
            fixedWeekCount: false,
            dayMaxEvents: true,
            eventDisplay: 'block',
            editable: true,
            eventDurationEditable: false,
            events: function(info, successCallback, failureCallback) {
                var from = Math.floor(info.start.getTime() / 1000);
                var to = Math.floor(info.end.getTime() / 1000);
                var existUrl = " . json_encode($calendarapiurl) . " + '&from=' + from + '&to=' + to;
                var planUrl = " . json_encode($planapiurl) . ";
                var planFetch = hasSavedPlanFromDb
                    ? Promise.resolve({ ok: true, events: savedPlanEventsFromDb })
                    : (onlineClassroomBlocked
                        ? Promise.resolve({ ok: false, error: 'reservation_calendar_error_online_classroom_unconfigured', events: [] })
                        : fetch(planUrl, { credentials: 'same-origin' }).then(function(res) {
                            if (!res.ok) { throw new Error('plan HTTP ' + res.status); }
                            return res.json();
                        }));
                Promise.all([
                    fetch(existUrl, { credentials: 'same-origin' }).then(function(res) {
                        if (!res.ok) { throw new Error('existing HTTP ' + res.status); }
                        return res.json();
                    }),
                    planFetch
                ]).then(function(results) {
                    var existing = mapItems(results[0] && results[0].events ? results[0].events : [], 'existing_session');
                    var planPayload = results[1] || {};
                    var planned = [];
                    if (planPayload.ok === false && planPayload.error) {
                        var planErrMsg = saveErrorMap[planPayload.error] || planPayload.error;
                        showCalendarError(planErrMsg);
                    } else {
                        clearCalendarError();
                        planned = mapItems(planPayload.events ? planPayload.events : [], 'reservation_plan');
                        if (planned.length > 0) {
                            showPlanInfo(" . json_encode($txtplanloaded) . ");
                        } else if (!onlineClassroomBlocked) {
                            planned = mapItems(fallbackPlanEvents, 'reservation_plan');
                            showPlanInfo(" . json_encode($txtplanloaded) . ");
                        }
                    }
                    // Keep dragged draft positions when user navigates week range.
                    for (var p = 0; p < planned.length; p++) {
                        var pid = String(planned[p].id || '');
                        if (pid && draftOverrides[pid]) {
                            planned[p].start = parseEventInstant(draftOverrides[pid].start) || planned[p].start;
                            planned[p].end = parseEventInstant(draftOverrides[pid].end) || planned[p].end;
                        }
                    }
                    clearCalendarError();
                    successCallback(existing.concat(planned));
                    setTimeout(function() {
                        if (tmCalendarRef) {
                            rebuildGroupDisplayEvents(tmCalendarRef);
                        }
                    }, 0);
                }).catch(function(err) {
                    showCalendarError(" . json_encode($txteventsfailed) . " + ': ' + (err && err.message ? err.message : " . json_encode($txtunknownerror) . "));
                    failureCallback(err);
                });
            },
            eventAllow: function(dropInfo, draggedEvent) {
                var ext = draggedEvent && draggedEvent.extendedProps ? draggedEvent.extendedProps : {};
                var et = String(ext.eventType || '');
                if (et !== 'reservation_plan' && et !== 'reservation_plan_group_display') {
                    return false;
                }
                var d = dropInfo && dropInfo.start ? dropInfo.start : null;
                if (!d || !(d instanceof Date)) {
                    return false;
                }
                if (isWeekendDate(d)) {
                    return false;
                }
                return true;
            },
            eventDragStart: function() {
                suppressTooltipForDrag();
            },
            eventDragStop: function() {
                releaseTooltipSuppression();
            },
            eventDrop: function(info) {
                releaseTooltipSuppression();
                var ext = info.event.extendedProps || {};
                var etype = String(ext.eventType || '');
                if (etype === 'reservation_plan_group_display') {
                    var groupKeyDisplay = String(ext.planGroup || '');
                    var groupEventsDisplay = getPlanGroupEvents(calendar, groupKeyDisplay);
                    if (!groupEventsDisplay.length) {
                        info.revert();
                        return;
                    }
                    var oldStartDisplay = info.oldEvent && info.oldEvent.start instanceof Date ? info.oldEvent.start : null;
                    var newStartDisplay = info.event.start instanceof Date ? info.event.start : null;
                    if (!oldStartDisplay || !newStartDisplay) {
                        info.revert();
                        return;
                    }
                    var excludeDisplay = {};
                    for (var dgi = 0; dgi < groupEventsDisplay.length; dgi++) {
                        excludeDisplay[String(groupEventsDisplay[dgi].id)] = true;
                    }
                    excludeDisplay[String(info.event.id)] = true;
                    var preferredHmDisplay = String((groupEventsDisplay[0].extendedProps || {}).preferredStartHm || '');
                    var specsDisplay = [];
                    for (var dpi = 0; dpi < groupEventsDisplay.length; dpi++) {
                        var dev = groupEventsDisplay[dpi];
                        specsDisplay.push({
                            event: dev,
                            durationMs: dev.end.getTime() - dev.start.getTime(),
                            classroomId: Number((dev.extendedProps || {}).classroomId || 0)
                        });
                    }
                    var placedDisplay = placeOnlineSegmentsFixedStart(
                        calendar,
                        specsDisplay,
                        newStartDisplay,
                        preferredHmDisplay,
                        excludeDisplay
                    );
                    if (!placedDisplay.ok) {
                        info.revert();
                        showPlanInfo(" . json_encode($txtpreferredtimeconflict) . ");
                        return;
                    }
                    for (var dai = 0; dai < placedDisplay.proposals.length; dai++) {
                        placedDisplay.proposals[dai].event.setDates(
                            placedDisplay.proposals[dai].start,
                            placedDisplay.proposals[dai].end,
                            {allDay: false}
                        );
                        draftOverrides[String(placedDisplay.proposals[dai].event.id)] = {
                            start: placedDisplay.proposals[dai].start.toISOString(),
                            end: placedDisplay.proposals[dai].end.toISOString()
                        };
                    }
                    rebuildGroupDisplayEvents(calendar);
                    showPlanInfo(placedDisplay.skipped
                        ? " . json_encode($txtpreferredtimelocked) . "
                        : " . json_encode($txtdragdraft) . ");
                    return;
                }
                if (etype !== 'reservation_plan') {
                    info.revert();
                    return;
                }
                var groupKey = String(ext.planGroup || '');
                var isOnlineGroup = String(ext.deliveryMode || '') === 'online' && groupKey !== '';
                if (isOnlineGroup) {
                    var oldStart = info.oldEvent && info.oldEvent.start instanceof Date ? info.oldEvent.start : null;
                    var newStart = info.event.start instanceof Date ? info.event.start : null;
                    if (!oldStart || !newStart) {
                        info.revert();
                        return;
                    }
                    var groupEvents = getPlanGroupEvents(calendar, groupKey);
                    var exclude = {};
                    for (var gi = 0; gi < groupEvents.length; gi++) {
                        exclude[String(groupEvents[gi].id)] = true;
                    }
                    var preferredHm = String((groupEvents[0].extendedProps || {}).preferredStartHm || '');
                    var specsGroup = [];
                    for (var gpi = 0; gpi < groupEvents.length; gpi++) {
                        var gev = groupEvents[gpi];
                        specsGroup.push({
                            event: gev,
                            durationMs: gev.end.getTime() - gev.start.getTime(),
                            classroomId: Number((gev.extendedProps || {}).classroomId || 0)
                        });
                    }
                    var placedGroup = placeOnlineSegmentsFixedStart(
                        calendar,
                        specsGroup,
                        newStart,
                        preferredHm,
                        exclude
                    );
                    if (!placedGroup.ok) {
                        info.revert();
                        showPlanInfo(" . json_encode($txtpreferredtimeconflict) . ");
                        return;
                    }
                    for (var api = 0; api < placedGroup.proposals.length; api++) {
                        placedGroup.proposals[api].event.setDates(
                            placedGroup.proposals[api].start,
                            placedGroup.proposals[api].end,
                            {allDay: false}
                        );
                        draftOverrides[String(placedGroup.proposals[api].event.id)] = {
                            start: placedGroup.proposals[api].start.toISOString(),
                            end: placedGroup.proposals[api].end.toISOString()
                        };
                    }
                    rebuildGroupDisplayEvents(calendar);
                    showPlanInfo(placedGroup.skipped
                        ? " . json_encode($txtpreferredtimelocked) . "
                        : " . json_encode($txtdragdraft) . ");
                    return;
                }
                // Onsite multi-day course: drag ANY day and the whole course re-plans onto
                // consecutive weekdays, preserving each day's duration (drag-one, all-follow).
                if (String(ext.deliveryMode || '') !== 'online' && groupKey !== '') {
                    var onsiteGroup = getPlanGroupEvents(calendar, groupKey);
                    if (onsiteGroup.length > 1) {
                        replanOnsiteGroup(calendar, info, onsiteGroup);
                        return;
                    }
                }
                var start = info.event.start;
                if (start instanceof Date) {
                    if (isWeekendDate(start)) {
                        info.revert();
                        showPlanInfo(" . json_encode($txtweekendblocked) . ");
                        return;
                    }
                }
                var rid = Number(ext.classroomId || 0);
                var currentStart = info.event.start instanceof Date ? new Date(info.event.start.getTime()) : null;
                var currentEnd = info.event.end instanceof Date ? new Date(info.event.end.getTime()) : null;
                if (!currentStart || !currentEnd || currentEnd <= currentStart) {
                    info.revert();
                    return;
                }
                var isOnlineSingle = String(ext.deliveryMode || '') === 'online';
                var preferredHmSingle = String(ext.preferredStartHm || '');

                if (!isOnlineSingle) {
                    var dayStartPhys = dayStartAt0930(currentStart);
                    var dayEndPhys = dayEndCalendar(currentStart);
                    var blockIntervalsPhys = collectDayBlockingIntervals(
                        calendar, rid, dayStartPhys, dayEndPhys, info.event.id
                    );
                    var chainStart = findLastIntervalEnd(blockIntervalsPhys, dayStartPhys);
                    if (chainStart.getTime() < dayStartPhys.getTime()) {
                        chainStart = dayStartPhys;
                    }
                    var cidPhys = Number(ext.courseId || 0);
                    if (!cidPhys) {
                        info.revert();
                        return;
                    }
                    fetchOnsitePlanTimes(cidPhys, chainStart).then(function(j) {
                        if (!j || !j.starttime || !j.endtime) {
                            info.revert();
                            showPlanInfo(" . json_encode($txtconflictblocked) . ");
                            return;
                        }
                        var slot = new Date(j.starttime * 1000);
                        var slotEnd = new Date(j.endtime * 1000);
                        if (intervalSpansWeekend(slot.getTime(), slotEnd.getTime())) {
                            info.revert();
                            showPlanInfo(" . json_encode($txtweekendblocked) . ");
                            return;
                        }
                        if (hasClassroomConflict(calendar, rid, slot, slotEnd, info.event.id)) {
                            info.revert();
                            showPlanInfo(" . json_encode($txtconflictblocked) . ");
                            return;
                        }
                        if (hasReservationPlanOverlap(calendar, slot, slotEnd, info.event.id)) {
                            info.revert();
                            showPlanInfo(" . json_encode($txtreservationoverlap) . ");
                            return;
                        }
                        var slotDayStart = dayStartAt0930(slot);
                        var slotDayEnd = dayEndCalendar(slot);
                        var sameRoomIntervals = collectDayClassroomIntervals(
                            calendar, rid, slotDayStart, slotDayEnd, info.event.id
                        );
                        var usedMsPhys = sumIntervalMs(sameRoomIntervals);
                        var slotUsedMs = Math.max(0, Math.min(slotEnd.getTime(), slotDayEnd.getTime()) - slot.getTime());
                        var dailyLimitMs = Math.max(1, Number(j.daily_limit || physicalDailyLimitHours || 7)) * 3600 * 1000;
                        if (usedMsPhys + slotUsedMs > dailyLimitMs) {
                            info.revert();
                            showPlanInfo(" . json_encode($txtdaylimitblocked) . ");
                            return;
                        }
                        info.event.setDates(slot, slotEnd, {allDay: false});
                        draftOverrides[String(info.event.id)] = {
                            start: slot.toISOString(),
                            end: slotEnd.toISOString()
                        };
                        showPlanInfo(" . json_encode($txtautoadjusted) . " + ' (' + formatHm(slot) + '-' + formatHm(slotEnd) + ')');
                        rebuildGroupDisplayEvents(calendar);
                    }).catch(function() {
                        info.revert();
                        showPlanInfo(" . json_encode($txtconflictblocked) . ");
                    });
                    return;
                }

                var durationMs = currentEnd.getTime() - currentStart.getTime();
                var excludeSingle = {};
                excludeSingle[String(info.event.id)] = true;
                var placedSingle = placeOnlineSegmentsFixedStart(
                    calendar,
                    [{
                        event: info.event,
                        durationMs: durationMs,
                        classroomId: rid
                    }],
                    currentStart,
                    preferredHmSingle,
                    excludeSingle
                );
                if (!placedSingle.ok || !placedSingle.proposals.length) {
                    info.revert();
                    showPlanInfo(" . json_encode($txtpreferredtimeconflict) . ");
                    return;
                }
                var slot = placedSingle.proposals[0].start;
                var slotEnd = placedSingle.proposals[0].end;
                info.event.setDates(slot, slotEnd, {allDay: false});
                draftOverrides[String(info.event.id)] = {
                    start: slot.toISOString(),
                    end: slotEnd.toISOString()
                };
                var hh1 = formatHm(slot);
                var hh2 = formatHm(slotEnd);
                showPlanInfo(placedSingle.skipped
                    ? " . json_encode($txtpreferredtimelocked) . "
                    : (" . json_encode($txtdragdraft) . " + ' (' + hh1 + '-' + hh2 + ')'));
                rebuildGroupDisplayEvents(calendar);
            },
            eventContent: function(arg) {
                var ext = arg.event.extendedProps || {};
                var type = String(ext.eventType || '');
                var dm = String(ext.deliveryMode || '');
                var tl = String(ext.teachingLanguage || '');
                var dmLabel = (dm === 'online')
                    ? " . json_encode($txtdeliveryonline) . "
                    : " . json_encode($txtdeliveryonsite) . ";
                var tlLabel = (tl === 'en')
                    ? " . json_encode($txtlangen) . "
                    : " . json_encode($txtlangzh) . ";
                var start = arg.event.start;
                var end = arg.event.end;
                var timeLabel = formatHm(start) + (end ? ('-' + formatHm(end)) : '');
                if (type === 'reservation_plan_group_display') {
                    var gsh = String(ext.groupStartHm || '');
                    var geh = String(ext.groupEndHm || '');
                    var gtime = (gsh && geh) ? (gsh + '-' + geh) : '';
                    return {
                        html: '<div class=\"tm-fc-card tm-resv-plan-card tm-resv-plan-group-display\">'
                            + '<div class=\"tm-fc-main-row\">'
                            + '<div class=\"tm-fc-main\"><span class=\"tm-fc-title\">' + esc(arg.event.title) + '</span></div>'
                            + '<span class=\"tm-fc-status-badge\">' + esc(" . json_encode($txtbadgeplan) . ") + '</span>'
                            + '</div>'
                            + '<div class=\"tm-fc-progress-caption\">' + esc(dmLabel + ' | ' + tlLabel) + '</div>'
                            + '<div class=\"tm-fc-progress-caption\">' + esc(gtime) + '</div>'
                            + '</div>'
                    };
                }
                if (type === 'reservation_plan') {
                    return {
                        html: '<div class=\"tm-fc-card tm-resv-plan-card\">'
                            + '<div class=\"tm-fc-main-row\">'
                            + '<div class=\"tm-fc-main\"><span class=\"tm-fc-title\">' + esc(arg.event.title) + '</span></div>'
                            + '<span class=\"tm-fc-status-badge\">' + esc(" . json_encode($txtbadgeplan) . ") + '</span>'
                            + '</div>'
                            + '<div class=\"tm-fc-progress-caption\">' + esc(dmLabel + ' | ' + tlLabel) + '</div>'
                            + '<div class=\"tm-fc-progress-caption\">' + esc(timeLabel) + '</div>'
                            + '</div>'
                    };
                }
                var ownDedicatedRow = (type === 'existing_session' && ext.ownDedicatedSession && ext.ownDedicatedBadge)
                    ? '<div class=\"tm-fc-own-dedicated-line\">' + esc(ext.ownDedicatedBadge) + '</div>'
                    : '';
                return {
                    html: '<div class=\"tm-fc-card tm-resv-existing-card' + (type === 'existing_session' && ext.ownDedicatedSession ? ' tm-fc-card-own-dedicated' : '') + '\">'
                        + '<div class=\"tm-fc-main\"><span class=\"tm-fc-title\">' + esc(arg.event.title) + '</span></div>'
                        + ownDedicatedRow
                        + '<div class=\"tm-fc-progress-caption\">' + esc(dmLabel + ' | ' + tlLabel) + '</div>'
                        + '<div class=\"tm-fc-progress-caption\">' + esc(timeLabel) + '</div>'
                        + '</div>'
                };
            },
            eventDidMount: function(info) {
                var ext = info.event.extendedProps || {};
                var type = String(ext.eventType || '');
                if (type === 'reservation_plan' || type === 'reservation_plan_group_display') {
                    info.el.addEventListener('mousedown', suppressTooltipForDrag);
                    info.el.addEventListener('touchstart', suppressTooltipForDrag, {passive: true});
                }
                if (type === 'reservation_plan') {
                    info.el.style.backgroundColor = '#005f7e';
                    info.el.style.borderColor = '#00455b';
                    var gk = String(ext.planGroup || '');
                    if (gk) {
                        var gEvents = getPlanGroupEvents(calendar, gk);
                        if (gEvents.length > 1) {
                            var idx = -1;
                            for (var gi = 0; gi < gEvents.length; gi++) {
                                if (String(gEvents[gi].id) === String(info.event.id)) {
                                    idx = gi;
                                    break;
                                }
                            }
                            info.el.classList.add('tm-plan-group-linked');
                            if (idx === 0) {
                                info.el.classList.add('tm-plan-group-start');
                            } else if (idx === gEvents.length - 1) {
                                info.el.classList.add('tm-plan-group-end');
                            } else {
                                info.el.classList.add('tm-plan-group-middle');
                            }
                        }
                    }
                } else if (type === 'existing_session' && ext.ownDedicatedSession) {
                    info.el.classList.add('tm-fc-event-own-dedicated');
                    info.el.style.backgroundColor = '#74b42a';
                    info.el.style.borderColor = '#5c8f21';
                } else {
                    info.el.style.backgroundColor = '#6f7780';
                    info.el.style.borderColor = '#59616a';
                }
            },
            eventMouseEnter: function(info) {
                if (tmTooltipSuppressed) {
                    return;
                }
                var ext = info.event.extendedProps || {};
                var type = String(ext.eventType || '');
                var title = info.event.title || '';
                var head = (type === 'reservation_plan')
                    ? " . json_encode($txtlegendplan) . "
                    : " . json_encode($txtlegendexisting) . ";
                var room = ext.classroomLabel ? ('<div class=\"tm-fc-tooltip-line\">' + esc(" . json_encode($txtclassroom) . ") + ': ' + esc(ext.classroomLabel) + '</div>') : '';
                var dm = String(ext.deliveryMode || '');
                var tl = String(ext.teachingLanguage || '');
                var dmLabel = (dm === 'online')
                    ? " . json_encode($txtdeliveryonline) . "
                    : " . json_encode($txtdeliveryonsite) . ";
                var tlLabel = (tl === 'en')
                    ? " . json_encode($txtlangen) . "
                    : " . json_encode($txtlangzh) . ";
                var modeLine = '<div class=\"tm-fc-tooltip-line\">' + esc(" . json_encode($txtdelivery) . ") + ': ' + esc(dmLabel) + '</div>';
                var langLine = '<div class=\"tm-fc-tooltip-line\">' + esc(" . json_encode($txtlanguage) . ") + ': ' + esc(tlLabel) + '</div>';
                var ownDedicatedTip = (type === 'existing_session' && ext.ownDedicatedSession && ext.ownDedicatedSessionLabel)
                    ? '<div class=\"tm-fc-tooltip-line tm-fc-tooltip-own-dedicated\">' + esc(ext.ownDedicatedSessionLabel) + '</div>'
                    : '';
                var html = ''
                    + '<div class=\"tm-fc-tooltip-head\">' + esc(head) + '</div>'
                    + '<div class=\"tm-fc-tooltip-title\">' + esc(title) + '</div>'
                    + ownDedicatedTip
                    + modeLine
                    + langLine
                    + room;
                showTooltip(info.el, html);
            },
            eventMouseLeave: function() {
                hideTooltip();
            }
        });
        calendar.render();
        tmCalendarRef = calendar;
        document.addEventListener('mouseup', releaseTooltipSuppression);
        document.addEventListener('touchend', releaseTooltipSuppression);
        var obBackdrop = document.getElementById('tm-cal-onboarding');
        var obOk = document.getElementById('tm-cal-onboarding-ok');
        if (showOnboarding && obBackdrop) {
            obBackdrop.style.display = 'flex';
        }
        if (obOk && obBackdrop) {
            obOk.addEventListener('click', function() {
                fetch(markOnboardingUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sesskey: tmSesskey, id: reservationId }),
                    credentials: 'same-origin'
                }).finally(function() {
                    obBackdrop.style.display = 'none';
                });
            });
        }
        var replanBtn = document.getElementById('tm-cal-replan');
        if (replanBtn) {
            replanBtn.addEventListener('click', function() {
                if (!tmCalendarRef) {
                    return;
                }
                if (!window.confirm(replanConfirmText)) {
                    return;
                }
                // Drop manual positions and any previously saved plan so the server auto-plan is used.
                draftOverrides = {};
                hasSavedPlanFromDb = false;
                savedPlanEventsFromDb = [];
                clearCalendarError();
                tmCalendarRef.refetchEvents();
            });
        }
        var submitBtn = document.getElementById('tm-cal-submit-plan');
        if (submitBtn) {
            submitBtn.addEventListener('click', function() {
                if (!tmCalendarRef) {
                    return;
                }
                clearCalendarError();
                var plan = buildPlanPayload(tmCalendarRef);
                submitBtn.disabled = true;
                var prevText = submitBtn.textContent;
                submitBtn.textContent = " . json_encode($txtsubmitting) . ";
                fetch(savePlanUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sesskey: tmSesskey, id: reservationId, plan: plan }),
                    credentials: 'same-origin'
                }).then(function(res) {
                    return res.json().then(function(data) {
                        return { httpOk: res.ok, data: data };
                    });
                }).then(function(r) {
                    if (r.httpOk && r.data && r.data.ok) {
                        window.location.href = redirectAfterSave;
                        return;
                    }
                    var code = (r.data && r.data.error) ? r.data.error : 'reservation_calendar_error_invalid_request';
                    var msg = saveErrorMap[code] || saveErrorMap['reservation_calendar_error_invalid_request'];
                    showCalendarError(msg);
                }).catch(function() {
                    showCalendarError(" . json_encode($txtunknownerror) . ");
                }).finally(function() {
                    submitBtn.disabled = false;
                    submitBtn.textContent = prevText;
                });
            });
        }
        return true;
    }

    function waitForFullCalendar() {
        var attempts = 0;
        function tick() {
            if (initCalendar()) {
                return;
            }
            attempts++;
            if (attempts > 300) {
                showCalendarError(" . json_encode($txtlibmissing) . ");
                return;
            }
            setTimeout(tick, 50);
        }
        window.addEventListener('load', function() { tick(); });
        setTimeout(tick, 0);
    }

    waitForFullCalendar();
})();
");

echo $OUTPUT->footer();
