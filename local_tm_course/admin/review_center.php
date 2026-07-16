<?php
/**
 * Admin: Combined review center (enrolments + custom reservations).
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/enrolment_manager.php');
require_once(__DIR__ . '/../classes/verification_manager.php');
require_once(__DIR__ . '/../classes/notification_helper.php');
require_once(__DIR__ . '/../classes/classroom_manager.php');
require_once(__DIR__ . '/../classes/enabled_course_manager.php');
require_once(__DIR__ . '/../classes/batch_enrol_helper.php');

use local_tm_course\session_manager;
use local_tm_course\enrolment_manager;
use local_tm_course\classroom_manager;
use local_tm_course\enabled_course_manager;

require_login();
require_capability('local/tm_course:approve', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/tm_course/admin/review_center.php'));
$PAGE->set_title(get_string('nav_enrolments', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');
$str = function(string $key, string $fallback, $a = null): string {
    $sm = get_string_manager();
    if ($sm->string_exists($key, 'local_tm_course')) {
        return get_string($key, 'local_tm_course', $a);
    }
    if ($a === null) {
        return $fallback;
    }
    if (is_scalar($a)) {
        return str_replace('{$a}', (string)$a, $fallback);
    }
    if (is_object($a)) {
        foreach (get_object_vars($a) as $k => $v) {
            $fallback = str_replace('{$a->' . $k . '}', (string)$v, $fallback);
        }
    }
    return $fallback;
};

$action = optional_param('action', '', PARAM_RAW_TRIMMED);
$resvid = optional_param('resvid', 0, PARAM_INT);
$resvstatus = optional_param('resvstatus', 0, PARAM_INT);
$enrolstatus = optional_param('enrolstatus', 0, PARAM_INT);
$resvnote = optional_param('resv_note', '', PARAM_TEXT);
$resvmeetinglink = optional_param('resv_meeting_link', '', PARAM_RAW_TRIMMED);

/**
 * Create formal sessions from reservation calendar blocks.
 *
 * @return int[]
 */
function local_tm_course_review_center_create_sessions(\stdClass $reservation, string $batchsubmitternote = ''): array {
    global $DB, $USER;
    $plan = json_decode((string)($reservation->calendar_plan_json ?? ''), true);
    if (!is_array($plan) || empty($plan)) {
        throw new moodle_exception('reservation_review_error_empty_plan', 'local_tm_course');
    }
    $delivery = (string)($reservation->delivery_mode ?? session_manager::DELIVERY_ONSITE);
    $preferredclassroomid = (int)($reservation->preferred_classroomid ?? $reservation->classroomid ?? 0);
    $classroommap = enabled_course_manager::get_classroom_map();
    $fallbackroomid = 0;
    $fallbackrooms = classroom_manager::get_all();
    if (!empty($fallbackrooms)) {
        reset($fallbackrooms);
        $fallbackroomid = (int)key($fallbackrooms);
    }
    $courseids = [];
    foreach ($plan as $b) {
        $cid = (int)($b['courseId'] ?? 0);
        if ($cid > 0) {
            $courseids[] = $cid;
        }
    }
    $coursenames = [];
    if (!empty($courseids)) {
        list($insql, $inparams) = $DB->get_in_or_equal(array_values(array_unique($courseids)), SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $insql", $inparams);
        foreach ($rows as $r) {
            $coursenames[(int)$r->id] = format_string((string)$r->fullname);
        }
    }
    usort($plan, function($a, $b) {
        return ((int)($a['start'] ?? 0)) <=> ((int)($b['start'] ?? 0));
    });
    $created = [];
    foreach ($plan as $idx => $b) {
        $cid = (int)($b['courseId'] ?? 0);
        $start = (int)($b['start'] ?? 0);
        $end = (int)($b['end'] ?? 0);
        if ($cid <= 0 || $start <= 0 || $end <= $start) {
            throw new moodle_exception('reservation_review_error_invalid_block', 'local_tm_course');
        }
        $roomid = (int)($b['classroomId'] ?? $b['classroomid'] ?? 0);
        if ($delivery === session_manager::DELIVERY_ONLINE) {
            if ($roomid <= 0) {
                $roomid = enabled_course_manager::get_online_classroom_id($cid);
            }
        } else if ($roomid <= 0) {
            $roomid = enabled_course_manager::resolve_plan_classroom(
                $cid,
                $delivery,
                $preferredclassroomid,
                $fallbackroomid
            );
        }
        if ($delivery === session_manager::DELIVERY_ONSITE && $roomid <= 0) {
            throw new \moodle_exception('reservation_calendar_error_classroom', 'local_tm_course');
        }
        $location = '';
        if ($roomid > 0) {
            $room = classroom_manager::get($roomid);
            $location = classroom_manager::session_location_label($room);
        }
        $name = $coursenames[$cid] ?? ('Course #' . $cid);
        $sessionpayload = [
            'courseid' => $cid,
            'classroomid' => $roomid,
            'allow_same_day_classroom' => 1,
            'name' => $name,
            'description' => '',
            'location' => $location,
            'teaching_language' => (string)$reservation->teaching_language,
            'delivery_mode' => $delivery,
            'meeting_link' => (string)($reservation->preferred_meeting_link ?? ''),
            'starttime' => $start,
            'endtime' => $end,
            'duration_hours' => round(enabled_course_manager::get_default_duration_hours($cid, $delivery), 2),
            'approval_mode' => session_manager::APPROVAL_MANUAL,
            'status' => session_manager::STATUS_OPEN,
            'source_reservation_id' => (int)$reservation->id,
        ];
        if ($roomid <= 0) {
            [$legacydesks, $legacyppd] = session_manager::default_capacity_for_classroom('');
            $sessionpayload['num_desks'] = $legacydesks;
            $sessionpayload['persons_per_desk'] = $legacyppd;
        }
        $sid = session_manager::create_session($sessionpayload);
        $created[] = (int)$sid;
    }
    // Keep a unique first column (id) for get_records() to avoid duplicate-key debug output
    // when one reservation contains multiple rows for the same userid.
    $entries = \local_tm_course\batch_enrol_helper::pending_entries_from_reservation_learners((int)$reservation->id);
    $batchnote = enrolment_manager::normalise_batch_submitter_note(
        $batchsubmitternote !== ''
            ? $batchsubmitternote
            : (string)($reservation->batch_submitter_note ?? '')
    );
    if (!empty($entries)) {
        $requesterid = (int)($reservation->requesterid ?? 0);
        $actorid = $requesterid > 1 ? $requesterid : (int)$USER->id;
        foreach ($created as $sid) {
            enrolment_manager::batch_enrol_pending($sid, $actorid, $entries, true, $batchnote);
        }
    }
    return $created;
}

if ($action && confirm_sesskey() && $resvid > 0) {
    $allowedactions = ['resv_note', 'resv_meeting_link', 'resv_reject', 'resv_approve'];
    if (!in_array($action, $allowedactions, true)) {
        redirect(new moodle_url('/local/tm_course/admin/review_center.php', ['resvstatus' => $resvstatus, 'enrolstatus' => $enrolstatus]),
            $str('reservation_review_action_invalid', 'Invalid review action.'), null, \core\output\notification::NOTIFY_ERROR);
    }
    try {
        $reservation = $DB->get_record('local_tm_course_reservation', ['id' => $resvid], '*', MUST_EXIST);
        $isonline = ((string) $reservation->delivery_mode === session_manager::DELIVERY_ONLINE);
        if ($action === 'resv_note') {
            $reservation->manager_note = trim((string)$resvnote);
            $reservation->timemodified = time();
            $DB->update_record('local_tm_course_reservation', $reservation);
            $msg = $str('reservation_review_note_saved', 'Review note saved.');
        } else if ($action === 'resv_meeting_link') {
            if (!$isonline) {
                throw new moodle_exception('reservation_review_meeting_link_online_only', 'local_tm_course');
            }
            $reservation->preferred_meeting_link = clean_param(trim((string)$resvmeetinglink), PARAM_RAW_TRIMMED);
            $reservation->timemodified = time();
            $DB->update_record('local_tm_course_reservation', $reservation);
            $msg = $str('reservation_review_meeting_link_saved', 'Video meeting link saved.');
        } else if ($action === 'resv_reject') {
            $reservation->status = 2;
            $reservation->timemodified = time();
            $DB->update_record('local_tm_course_reservation', $reservation);
            \local_tm_course\notification_helper::notify_reservation_result(
                (int)$reservation->requesterid,
                false,
                (int)$reservation->id,
                (string)($reservation->manager_note ?? '')
            );
            $msg = $str('reservation_review_rejected_notice', 'Dedicated class application rejected.');
        } else if ($action === 'resv_approve') {
            if ((int)$reservation->calendar_plan_submitted !== 1) {
                $decodedplan = json_decode((string)($reservation->calendar_plan_json ?? ''), true);
                $hassavedplan = is_array($decodedplan) && !empty($decodedplan);
                if (!$hassavedplan) {
                    throw new moodle_exception('reservation_review_error_plan_not_submitted', 'local_tm_course');
                }
                // Backward-compatible healing: if plan exists but submit flag was not
                // persisted (legacy save path), mark it submitted before approval.
                $reservation->calendar_plan_submitted = 1;
            }
            $progress = \local_tm_course\verification_manager::get_reservation_progress_summary(
                (int)$reservation->id,
                \local_tm_course\verification_manager::get_questions_for_courses(
                    \local_tm_course\reservation_plan_validator::get_reservation_course_ids($reservation),
                    (string)($reservation->delivery_mode ?? '')
                ),
                \local_tm_course\verification_manager::get_reservation_file_links((int)$reservation->id)
            );
            if (!empty($progress['total']) && empty($progress['complete'])) {
                throw new moodle_exception('reservation_review_verification_incomplete', 'local_tm_course');
            }
            if ($isonline && trim((string)($reservation->preferred_meeting_link ?? '')) === '') {
                throw new moodle_exception('reservation_review_meeting_link_required', 'local_tm_course');
            }
            $tx = $DB->start_delegated_transaction();
            $created = local_tm_course_review_center_create_sessions($reservation, '');
            $reservation->status = 1;
            $reservation->timemodified = time();
            $DB->update_record('local_tm_course_reservation', $reservation);
            $tx->allow_commit();
            \local_tm_course\notification_helper::notify_reservation_result(
                (int)$reservation->requesterid,
                true,
                (int)$reservation->id,
                (string)($reservation->manager_note ?? '')
            );
            $msg = $str('reservation_review_approved_notice', 'Dedicated class application approved. {$a} formal sessions were created.', count($created));
        }
    } catch (\moodle_exception $ex) {
        $errmsg = $ex->getMessage();
        if (!empty($ex->errorcode) && !empty($ex->module)) {
            try {
                $errmsg = get_string($ex->errorcode, $ex->module, $ex->a);
            } catch (\Throwable $ignored) {
                // keep getMessage
            }
        }
        redirect(new moodle_url('/local/tm_course/admin/review_center.php', ['resvstatus' => $resvstatus, 'enrolstatus' => $enrolstatus]),
            $errmsg, null, \core\output\notification::NOTIFY_ERROR);
    } catch (\Throwable $ex) {
        redirect(new moodle_url('/local/tm_course/admin/review_center.php', ['resvstatus' => $resvstatus, 'enrolstatus' => $enrolstatus]),
            $ex->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect(new moodle_url('/local/tm_course/admin/review_center.php', ['resvstatus' => $resvstatus, 'enrolstatus' => $enrolstatus]),
        $msg ?? '', null, \core\output\notification::NOTIFY_SUCCESS);
}

$allsessions = $DB->get_records_sql(
    "SELECT s.id, s.name, s.starttime,
            SUM(CASE WHEN e.status = :pending AND COALESCE(e.reservation_initial_enrol, 0) = 0
                THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN e.status = :approved AND COALESCE(e.reservation_initial_enrol, 0) = 0
                THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN e.status = :rejected AND COALESCE(e.reservation_initial_enrol, 0) = 0
                THEN 1 ELSE 0 END) AS rejected_count
       FROM {local_tm_course_sessions} s
  LEFT JOIN {local_tm_course_enrolments} e ON e.sessionid = s.id
   GROUP BY s.id, s.name, s.starttime
   ORDER BY s.starttime DESC",
    [
        'pending' => session_manager::ENROL_PENDING,
        'approved' => session_manager::ENROL_APPROVED,
        'rejected' => session_manager::ENROL_REJECTED,
    ]
);
$filteredallsessions = [];
foreach ($allsessions as $s) {
    $pc = (int)$s->pending_count;
    $ac = (int)$s->approved_count;
    $rc = (int)$s->rejected_count;
    if ($enrolstatus === 1 && $ac <= 0) { continue; }
    if ($enrolstatus === 2 && $rc <= 0) { continue; }
    if ($enrolstatus === 0 && $pc <= 0) { continue; }
    $filteredallsessions[] = $s;
}

$resvwhere = '';
$resvparams = [];
if (!in_array($resvstatus, [0, 1, 2], true)) {
    $resvstatus = 0;
}
if ($resvstatus === 2) {
    $resvwhere = 'WHERE r.status = :rstatus';
    $resvparams['rstatus'] = 2;
}
require_once(__DIR__ . '/../classes/reservation_application.php');
$submittedsql = \local_tm_course\reservation_application::sql_submitted_only();
if ($resvwhere !== '') {
    $resvwhere .= $submittedsql;
} else {
    $resvwhere = 'WHERE 1=1' . $submittedsql;
}
$reservations = $DB->get_records_sql(
    "SELECT r.*, u.firstname, u.lastname, u.email
       FROM {local_tm_course_reservation} r
       JOIN {user} u ON u.id = r.requesterid
       $resvwhere
      ORDER BY COALESCE(NULLIF(r.timesubmitted, 0), r.timecreated) DESC",
    $resvparams
);
$reservationids = array_map(function($r) { return (int)$r->id; }, $reservations);
$learnercounts = [];
$verificationprogress = [];
$reservationstarttimes = [];
$reservationsessions = [];
if (!empty($reservationids)) {
    list($sinsql, $sinparams) = $DB->get_in_or_equal($reservationids, SQL_PARAMS_NAMED);
    $sessionstarts = $DB->get_records_sql(
        "SELECT source_reservation_id, MIN(starttime) AS firststart
           FROM {local_tm_course_sessions}
          WHERE source_reservation_id $sinsql
       GROUP BY source_reservation_id",
        $sinparams
    );
    foreach ($sessionstarts as $ss) {
        $reservationstarttimes[(int)$ss->source_reservation_id] = (int)$ss->firststart;
    }
    $sessparams = $sinparams + [
        'pending' => session_manager::ENROL_PENDING,
        'approved' => session_manager::ENROL_APPROVED,
    ];
    $sessrows = $DB->get_records_sql(
        "SELECT s.id, s.source_reservation_id, s.name, s.starttime,
                SUM(CASE WHEN e.status = :pending AND COALESCE(e.reservation_initial_enrol, 0) = 1 THEN 1 ELSE 0 END) AS initial_pending_count,
                SUM(CASE WHEN e.status = :approved AND COALESCE(e.reservation_initial_enrol, 0) = 1 THEN 1 ELSE 0 END) AS initial_approved_count,
                SUM(CASE WHEN COALESCE(e.reservation_initial_enrol, 0) = 1 THEN 1 ELSE 0 END) AS initial_total_count
           FROM {local_tm_course_sessions} s
      LEFT JOIN {local_tm_course_enrolments} e ON e.sessionid = s.id
          WHERE s.source_reservation_id $sinsql
       GROUP BY s.id, s.source_reservation_id, s.name, s.starttime
       ORDER BY s.starttime ASC",
        $sessparams
    );
    foreach ($sessrows as $sessrow) {
        $rid = (int)$sessrow->source_reservation_id;
        if (!isset($reservationsessions[$rid])) {
            $reservationsessions[$rid] = [];
        }
        $reservationsessions[$rid][] = $sessrow;
    }
    list($linsql, $linparams) = $DB->get_in_or_equal($reservationids, SQL_PARAMS_NAMED);
    $lrows = $DB->get_records_sql(
        "SELECT reservationid, COUNT(1) AS cnt
           FROM {local_tm_course_resv_learner}
          WHERE reservationid $linsql
       GROUP BY reservationid",
        $linparams
    );
    foreach ($lrows as $row) {
        $learnercounts[(int)$row->reservationid] = (int)$row->cnt;
    }
}
if ($resvstatus !== 2) {
    $reservations = array_values(array_filter($reservations, function($r) use ($reservationsessions, $learnercounts, $resvstatus) {
        if ((int)$r->status === 2) {
            return false;
        }
        $sess = $reservationsessions[(int)$r->id] ?? [];
        $hascreated = !empty($sess);
        $initialpending = 0;
        foreach ($sess as $s) {
            $initialpending += (int)($s->initial_pending_count ?? 0);
        }
        $hasinitiallearners = (int)($learnercounts[(int)$r->id] ?? 0) > 0;
        $isapproveddone = $hascreated && (!$hasinitiallearners || $initialpending <= 0);
        if ($resvstatus === 1) {
            return $isapproveddone;
        }
        return !$isapproveddone;
    }));
}
$coursecache = [];
$questioncache = [];
foreach ($reservations as $r) {
    $ids = [];
    if (!empty($r->courseids_json)) {
        $decoded = json_decode((string)$r->courseids_json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $cid) {
                $cid = (int)$cid;
                if ($cid > 0) { $ids[] = $cid; }
            }
        }
    }
    if (empty($ids) && (int)$r->courseid > 0) {
        $ids[] = (int)$r->courseid;
    }
    foreach (array_unique($ids) as $cid) {
        $coursecache[$cid] = '';
    }
    sort($ids);
    $cachekey = implode(',', array_unique($ids)) . '|' . (string)($r->delivery_mode ?? '');
    if (!isset($questioncache[$cachekey])) {
        $questioncache[$cachekey] = \local_tm_course\verification_manager::get_questions_for_courses($ids, (string)($r->delivery_mode ?? ''));
    }
    $links = \local_tm_course\verification_manager::get_reservation_file_links((int)$r->id);
    $verificationprogress[(int)$r->id] = \local_tm_course\verification_manager::get_reservation_progress_summary(
        (int)$r->id,
        $questioncache[$cachekey],
        $links
    );
    if (!isset($reservationstarttimes[(int)$r->id])) {
        $rangedata = json_decode((string)($r->calendar_plan_json ?? ''), true);
        if (is_array($rangedata)) {
            $first = 0;
            foreach ($rangedata as $blk) {
                $bs = (int)($blk['start'] ?? 0);
                if ($bs > 0 && ($first === 0 || $bs < $first)) {
                    $first = $bs;
                }
            }
            if ($first > 0) {
                $reservationstarttimes[(int)$r->id] = $first;
            }
        }
    }
}
if (!empty($coursecache)) {
    list($cinsql, $cinparams) = $DB->get_in_or_equal(array_keys($coursecache), SQL_PARAMS_NAMED);
    $rows = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $cinsql", $cinparams);
    foreach ($rows as $row) {
        $coursecache[(int)$row->id] = format_string((string)$row->fullname);
    }
}

$resvstatuslabels = [
    0 => ['pending', get_string('enrol_pending', 'local_tm_course')],
    1 => ['approved', get_string('enrol_approved', 'local_tm_course')],
    2 => ['rejected', get_string('enrol_rejected', 'local_tm_course')],
];

echo $OUTPUT->header();
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo s(get_string('nav_enrolments', 'local_tm_course')); ?></h2>
</div>
<div class="tm-card">
<div class="tm-card-body">
    <h3 class="mb-3"><?php echo s($str('review_block_enrolment_title', 'Existing course enrolment review')); ?></h3>
    <form method="get" class="d-flex gap-2 align-items-center mb-3">
        <input type="hidden" name="resvstatus" value="<?php echo (int)$resvstatus; ?>">
        <select name="enrolstatus" class="form-control form-control-sm" style="width:auto">
            <option value="0"<?php echo ($enrolstatus === 0 ? ' selected' : ''); ?>><?php echo get_string('enrol_pending', 'local_tm_course'); ?></option>
            <option value="1"<?php echo ($enrolstatus === 1 ? ' selected' : ''); ?>><?php echo get_string('enrol_approved', 'local_tm_course'); ?></option>
            <option value="2"<?php echo ($enrolstatus === 2 ? ' selected' : ''); ?>><?php echo get_string('enrol_rejected', 'local_tm_course'); ?></option>
        </select>
        <button type="submit" class="btn btn-sm btn-tm-primary"><?php echo get_string('filter'); ?></button>
    </form>
    <table class="tm-table" data-sort-table="admin-enrol-list">
        <thead><tr><th>#</th><th><?php echo get_string('session_name', 'local_tm_course'); ?></th><th data-sortable="datetime"><?php echo get_string('label_start', 'local_tm_course'); ?></th><th><?php echo get_string('enrol_pending', 'local_tm_course'); ?></th><th><?php echo get_string('enrol_approved', 'local_tm_course'); ?></th><th><?php echo get_string('enrol_rejected', 'local_tm_course'); ?></th><th><?php echo get_string('sessions_actions', 'local_tm_course'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($filteredallsessions as $s): ?>
            <tr>
                <td><?php echo (int)$s->id; ?></td>
                <td><?php echo s($s->name); ?></td>
                <td data-sort-value="<?php echo (int)$s->starttime; ?>"><?php echo userdate((int)$s->starttime, get_string('strftimedatetimeshort')); ?></td>
                <td><?php echo (int)$s->pending_count; ?></td>
                <td><?php echo (int)$s->approved_count; ?></td>
                <td><?php echo (int)$s->rejected_count; ?></td>
                <td><a class="btn btn-sm btn-tm-primary" href="<?php echo (new moodle_url('/local/tm_course/admin/enrolments.php', ['sessionid' => (int)$s->id]))->out(); ?>"><?php echo get_string('view'); ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <hr class="my-4">
    <h3 class="mb-3"><?php echo s($str('review_block_reservation_title', 'Dedicated class review')); ?></h3>
    <form method="get" class="d-flex gap-2 align-items-center mb-3">
        <input type="hidden" name="enrolstatus" value="<?php echo (int)$enrolstatus; ?>">
        <select name="resvstatus" class="form-control form-control-sm" style="width:auto">
            <option value="0"<?php echo ($resvstatus === 0 ? ' selected' : ''); ?>><?php echo get_string('enrol_pending', 'local_tm_course'); ?></option>
            <option value="1"<?php echo ($resvstatus === 1 ? ' selected' : ''); ?>><?php echo get_string('enrol_approved', 'local_tm_course'); ?></option>
            <option value="2"<?php echo ($resvstatus === 2 ? ' selected' : ''); ?>><?php echo get_string('enrol_rejected', 'local_tm_course'); ?></option>
        </select>
        <button type="submit" class="btn btn-sm btn-tm-primary"><?php echo get_string('filter'); ?></button>
    </form>
    <?php if (empty($reservations)): ?>
        <div class="tm-alert tm-alert-info"><?php echo s($str('reservation_review_none_found', 'No dedicated class applications found.')); ?></div>
    <?php else: ?>
        <table class="tm-table tm-resv-review-table" data-sort-table="admin-reservation-list">
            <thead><tr><th>#</th><th><?php echo get_string('label_learner', 'local_tm_course'); ?></th><th><?php echo get_string('session_delivery_mode', 'local_tm_course'); ?></th><th data-sortable="datetime"><?php echo get_string('label_applied_at', 'local_tm_course'); ?></th><th><?php echo s($str('reservation_review_courses', 'Courses')); ?></th><th><?php echo s($str('reservation_review_time_range', 'Planned time range')); ?></th><th><?php echo s($str('reservation_review_learner_count', 'Learner count')); ?></th><th><?php echo s(get_string('reservation_tracking_verification_progress', 'local_tm_course')); ?></th><th><?php echo get_string('label_status', 'local_tm_course'); ?></th><th><?php echo get_string('sessions_actions', 'local_tm_course'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($reservations as $r):
                $ressesslist = $reservationsessions[(int)$r->id] ?? [];
                $sessioninitialpendingtotal = 0;
                $sessioninitialapprovedtotal = 0;
                foreach ($ressesslist as $ress) {
                    $sessioninitialpendingtotal += (int)($ress->initial_pending_count ?? 0);
                    $sessioninitialapprovedtotal += (int)($ress->initial_approved_count ?? 0);
                }
                $hascreatedsessions = !empty($ressesslist);
                $hasinitiallearners = (int)($learnercounts[(int)$r->id] ?? 0) > 0;
                $learnerreviewdone = $hascreatedsessions && (!$hasinitiallearners || $sessioninitialpendingtotal <= 0);
                $showresvenrolreview = ((int)$r->status !== 2);
                $virtualstatus = ((int)$r->status === 2) ? 2 : ($learnerreviewdone ? 1 : 0);
                [$cls, $lbl] = $resvstatuslabels[$virtualstatus] ?? ['closed', 'Unknown'];
                $rcourseids = [];
                if (!empty($r->courseids_json)) {
                    $decoded = json_decode((string)$r->courseids_json, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $cid) {
                            $cid = (int)$cid;
                            if ($cid > 0) { $rcourseids[] = $cid; }
                        }
                    }
                }
                if (empty($rcourseids) && (int)$r->courseid > 0) { $rcourseids[] = (int)$r->courseid; }
                $rcourselabels = [];
                foreach (array_unique($rcourseids) as $cid) {
                    $rcourselabels[] = $coursecache[$cid] ?? ('#' . $cid);
                }
                $rangedata = json_decode((string)($r->calendar_plan_json ?? ''), true);
                $rangestart = 0;
                $rangeend = 0;
                if (is_array($rangedata)) {
                    foreach ($rangedata as $blk) {
                        $bs = (int)($blk['start'] ?? 0);
                        $be = (int)($blk['end'] ?? 0);
                        if ($bs > 0 && ($rangestart === 0 || $bs < $rangestart)) { $rangestart = $bs; }
                        if ($be > 0 && $be > $rangeend) { $rangeend = $be; }
                    }
                }
                $rangetext = ($rangestart > 0 && $rangeend > 0)
                    ? (userdate($rangestart, get_string('strftimedatetimeshort')) . ' ~ ' . userdate($rangeend, get_string('strftimedatetimeshort')))
                    : '—';
                $progress = $verificationprogress[(int)$r->id] ?? ['total' => 0, 'uploaded' => 0, 'status' => 'na', 'complete' => true];
                $progresslabel = get_string('reservation_tracking_verification_na', 'local_tm_course');
                $progresscls = 'closed';
                if ($progress['status'] === 'not_started') {
                    $progresslabel = get_string('reservation_tracking_verification_not_started', 'local_tm_course');
                } else if ($progress['status'] === 'in_progress') {
                    $progresslabel = get_string('reservation_tracking_verification_in_progress', 'local_tm_course');
                    $progresscls = 'pending';
                } else if ($progress['status'] === 'complete') {
                    $progresslabel = get_string('reservation_tracking_verification_complete', 'local_tm_course');
                    $progresscls = 'approved';
                }
                if ((int)$progress['total'] > 0) {
                    $progresslabel .= ' (' . (int)$progress['uploaded'] . '/' . (int)$progress['total'] . ')';
                }
                $starttime = (int)($reservationstarttimes[(int)$r->id] ?? 0);
                $riskbadge = '';
                $risknote = '';
                if ($starttime > 0) {
                    $daysleft = (int)floor(($starttime - time()) / DAYSECS);
                    if ((int)$progress['total'] > 0 && !$progress['complete'] && $daysleft <= 7) {
                        $riskbadge = ' <span class="tm-badge tm-badge-rejected">' . s(get_string('reservation_tracking_verification_risk', 'local_tm_course')) . '</span>';
                        $risknote = '<div class="small text-danger mt-1">' . s(get_string('reservation_tracking_verification_risk_notice', 'local_tm_course')) . '</div>';
                    }
                }
            ?>
                <tr>
                    <td><?php echo (int)$r->id; ?></td>
                    <td><?php echo s(trim((string)$r->firstname . ' ' . (string)$r->lastname)); ?></td>
                    <td><?php
                        $dm = (string) ($r->delivery_mode ?? '');
                        echo $dm === session_manager::DELIVERY_ONLINE
                            ? s(get_string('delivery_mode_online', 'local_tm_course'))
                            : s(get_string('delivery_mode_onsite', 'local_tm_course'));
                    ?></td>
                    <td data-sort-value="<?php echo (int)$r->timecreated; ?>"><?php echo userdate((int)$r->timecreated, get_string('strftimedatetimeshort')); ?></td>
                    <td><?php echo s(implode(' / ', $rcourselabels)); ?></td>
                    <td><?php echo s($rangetext); ?></td>
                    <td><?php echo (int)($learnercounts[(int)$r->id] ?? 0); ?></td>
                    <td><span class="tm-badge tm-badge-<?php echo s($progresscls); ?>"><?php echo s($progresslabel); ?></span><?php echo $riskbadge; ?><?php echo $risknote; ?></td>
                    <td class="tm-resv-col-status"><span class="tm-badge tm-badge-<?php echo s($cls); ?>"><?php echo s($lbl); ?></span></td>
                    <td class="tm-resv-col-actions">
                        <?php
                        $stepdateok = ((int)($r->calendar_plan_submitted ?? 0) === 1);
                        if (!$stepdateok) {
                            $decodedplan = json_decode((string)($r->calendar_plan_json ?? ''), true);
                            $stepdateok = is_array($decodedplan) && !empty($decodedplan);
                        }
                        $stepverifyok = ((int)($progress['total'] ?? 0) <= 0) || !empty($progress['complete']);
                        $isonline = ((string)($r->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE);
                        $stepmeetingok = !$isonline || trim((string)($r->preferred_meeting_link ?? '')) !== '';
                        $stepapproveok = $hascreatedsessions;
                        $stepallapproveprereq = $stepdateok && $stepverifyok && $stepmeetingok;
                        $check = '<span class=\"text-success ml-1\">&#10003;</span>';
                        ?>
                        <div class="tm-resv-actions-main">
                            <span class="tm-resv-action-item">
                                <a href="<?php echo (new moodle_url('/local/tm_course/reservation/calendar.php', ['id' => (int)$r->id, 'review' => 1]))->out(); ?>" class="btn btn-sm btn-secondary"><?php echo s($str('reservation_review_edit_calendar', 'Edit schedule calendar')); ?></a>
                                <?php if ($stepdateok) { echo $check; } ?>
                            </span>

                            <?php if ((int)($r->calendar_plan_submitted ?? 0) === 1): ?>
                                <span class="tm-resv-action-item">
                                    <a href="<?php echo (new moodle_url('/local/tm_course/admin/reservation_verification_review.php', ['id' => (int)$r->id]))->out(); ?>" class="btn btn-sm btn-secondary" target="_blank" rel="noopener"><?php echo s(get_string('reservation_review_verification_open', 'local_tm_course')); ?></a>
                                    <?php if ($stepverifyok) { echo $check; } ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($isonline): ?>
                                <span class="tm-resv-action-item">
                                    <button type="button" class="btn btn-sm btn-secondary js-resv-modal-trigger"
                                            data-modal-kind="meeting"
                                            data-resv-id="<?php echo (int)$r->id; ?>"
                                            data-value="<?php echo s((string)($r->preferred_meeting_link ?? '')); ?>"
                                            data-title="<?php echo s(get_string('reservation_review_meeting_link_open', 'local_tm_course')); ?>">
                                        <?php echo s(get_string('reservation_review_meeting_link_open', 'local_tm_course')); ?>
                                    </button>
                                    <?php if ($stepmeetingok) { echo $check; } ?>
                                </span>
                            <?php endif; ?>

                            <span class="tm-resv-action-item">
                                <button type="button" class="btn btn-sm btn-secondary js-resv-modal-trigger"
                                        data-modal-kind="note"
                                        data-resv-id="<?php echo (int)$r->id; ?>"
                                        data-value="<?php echo s((string)($r->manager_note ?? '')); ?>"
                                        data-title="<?php echo s(get_string('reservation_review_note_open', 'local_tm_course')); ?>">
                                    <?php echo s(get_string('reservation_review_note_open', 'local_tm_course')); ?>
                                </button>
                                <?php if (trim((string)$r->manager_note) !== '') { echo $check; } ?>
                            </span>

                            <?php if ((int)$r->status !== 2): ?>
                                <span class="tm-resv-actions-approval-pair">
                                <form method="post" action="" style="display:inline">
                                    <input type="hidden" name="resvid" value="<?php echo (int)$r->id; ?>">
                                    <input type="hidden" name="resvstatus" value="<?php echo (int)$resvstatus; ?>">
                                    <input type="hidden" name="enrolstatus" value="<?php echo (int)$enrolstatus; ?>">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <button type="submit" name="action" value="resv_approve" class="btn btn-sm btn-tm-success" <?php echo $stepallapproveprereq ? '' : 'disabled'; ?>>
                                        <?php echo s($str('reservation_review_approve', 'Approve custom request')); ?>
                                    </button>
                                </form>
                                <?php if ($stepapproveok) { echo $check; } ?>
                                <form method="post" action="" style="display:inline">
                                    <input type="hidden" name="resvid" value="<?php echo (int)$r->id; ?>">
                                    <input type="hidden" name="resvstatus" value="<?php echo (int)$resvstatus; ?>">
                                    <input type="hidden" name="enrolstatus" value="<?php echo (int)$enrolstatus; ?>">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <button type="submit" name="action" value="resv_reject" class="btn btn-sm btn-tm-danger"
                                        onclick="return confirm(<?php echo json_encode(get_string('reservation_review_reject_confirm', 'local_tm_course')); ?>)">
                                        <?php echo s($str('reservation_review_reject', 'Reject custom request')); ?>
                                    </button>
                                </form>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!$stepallapproveprereq && (int)$r->status !== 2): ?>
                            <div class="small text-muted mt-1"><?php echo s(get_string('reservation_review_approve_prereq_hint', 'local_tm_course')); ?></div>
                        <?php endif; ?>

                        <?php if ($showresvenrolreview): ?>
                            <div class="mt-2 w-100">
                                <div class="small font-weight-bold mb-1"><?php echo s(get_string('reservation_review_enrol_sessions_title', 'local_tm_course')); ?><?php if ($learnerreviewdone) { echo $check; } ?></div>
                                <?php if ($hascreatedsessions && !empty($ressesslist)): ?>
                                <ul class="list-unstyled mb-0 small">
                                <?php foreach ($ressesslist as $ressess):
                                    $initialpendingn = (int)($ressess->initial_pending_count ?? 0);
                                    $initialapprovedn = (int)($ressess->initial_approved_count ?? 0);
                                    $enrollink = new moodle_url('/local/tm_course/admin/enrolments.php', [
                                        'sessionid' => (int)$ressess->id,
                                        'from_resv' => (int)$r->id,
                                        'resvstatus' => (int)$resvstatus,
                                        'enrolstatus' => (int)$enrolstatus,
                                    ]);
                                ?>
                                    <li class="mb-1 tm-resv-enrol-session-row">
                                        <a class="btn btn-sm btn-tm-primary tm-resv-enrol-session-btn" href="<?php echo $enrollink->out(); ?>"><?php echo s(get_string('reservation_review_enrol_session_button', 'local_tm_course')); ?></a>
                                        <span class="tm-resv-enrol-session-label"><?php echo s((string)$ressess->name); ?>
                                            <span class="text-muted">(<?php echo userdate((int)$ressess->starttime, get_string('strftimedatetimeshort')); ?>)</span>
                                        </span>
                                        <?php if ($initialpendingn > 0): ?>
                                            <span class="tm-badge tm-badge-pending"><?php echo get_string('enrol_pending', 'local_tm_course'); ?> <?php echo $initialpendingn; ?></span>
                                        <?php endif; ?>
                                        <?php if ($initialapprovedn > 0): ?>
                                            <span class="tm-badge tm-badge-approved"><?php echo get_string('enrol_approved', 'local_tm_course'); ?> <?php echo $initialapprovedn; ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                                <?php else:
                                    $planblocks = is_array($rangedata) ? $rangedata : [];
                                    usort($planblocks, function($a, $b) {
                                        return ((int)($a['start'] ?? 0)) <=> ((int)($b['start'] ?? 0));
                                    });
                                ?>
                                    <?php if (!empty($planblocks)): ?>
                                    <ul class="list-unstyled mb-0 small">
                                    <?php foreach ($planblocks as $blk):
                                        $blkcid = (int)($blk['courseId'] ?? 0);
                                        $blkstart = (int)($blk['start'] ?? 0);
                                        $blklabel = $coursecache[$blkcid] ?? ('#' . $blkcid);
                                    ?>
                                        <li class="mb-1 tm-resv-enrol-session-row">
                                            <button type="button" class="btn btn-sm btn-secondary tm-resv-enrol-session-btn" disabled><?php echo s(get_string('reservation_review_enrol_session_button', 'local_tm_course')); ?></button>
                                            <span class="tm-resv-enrol-session-label"><?php echo s($blklabel); ?>
                                                <?php if ($blkstart > 0): ?>
                                                <span class="text-muted">(<?php echo userdate($blkstart, get_string('strftimedatetimeshort')); ?>)</span>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                    </ul>
                                    <?php else: ?>
                                    <div class="d-flex flex-wrap align-items-center" style="gap:.35rem">
                                        <button type="button" class="btn btn-sm btn-secondary" disabled><?php echo s(get_string('reservation_review_enrol_session_button', 'local_tm_course')); ?></button>
                                    </div>
                                    <?php endif; ?>
                                    <div class="small text-muted mt-1"><?php echo s(get_string('reservation_review_enrol_prereq_hint', 'local_tm_course')); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</div>
<div id="tm-resv-edit-modal" class="tm-cancel-modal-backdrop" hidden>
    <div class="tm-cancel-modal-panel" style="max-width:42rem" role="dialog" aria-modal="true">
        <h4 id="tm-resv-edit-modal-title"></h4>
        <form method="post" action="" id="tm-resv-edit-modal-form">
            <input type="hidden" name="resvid" id="tm-resv-edit-resvid" value="0">
            <input type="hidden" name="resvstatus" value="<?php echo (int)$resvstatus; ?>">
            <input type="hidden" name="enrolstatus" value="<?php echo (int)$enrolstatus; ?>">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" id="tm-resv-edit-action" value="">
            <div class="form-group mb-2">
                <textarea class="form-control" rows="3" id="tm-resv-edit-text" name="resv_note"></textarea>
                <input type="text" class="form-control" id="tm-resv-edit-link" name="resv_meeting_link" style="display:none" placeholder="<?php echo s($str('reservation_review_meeting_link_placeholder', 'https://…')); ?>">
            </div>
            <div class="d-flex" style="gap:.35rem">
                <button type="submit" class="btn btn-sm btn-tm-primary"><?php echo s(get_string('savechanges')); ?></button>
                <button type="button" class="btn btn-sm btn-secondary" id="tm-resv-edit-modal-close"><?php echo s(get_string('cancel')); ?></button>
            </div>
        </form>
    </div>
</div>
<?php echo html_writer::script("(function(){var modal=document.getElementById('tm-resv-edit-modal');if(!modal){return;}var title=document.getElementById('tm-resv-edit-modal-title');var rid=document.getElementById('tm-resv-edit-resvid');var action=document.getElementById('tm-resv-edit-action');var txt=document.getElementById('tm-resv-edit-text');var link=document.getElementById('tm-resv-edit-link');var close=document.getElementById('tm-resv-edit-modal-close');var triggers=document.querySelectorAll('.js-resv-modal-trigger');for(var i=0;i<triggers.length;i++){triggers[i].addEventListener('click',function(){var kind=String(this.getAttribute('data-modal-kind')||'note');rid.value=String(this.getAttribute('data-resv-id')||'0');title.textContent=String(this.getAttribute('data-title')||'');txt.value='';link.value='';if(kind==='meeting'){action.value='resv_meeting_link';txt.style.display='none';link.style.display='block';link.value=String(this.getAttribute('data-value')||'');}else{action.value='resv_note';txt.style.display='block';link.style.display='none';txt.value=String(this.getAttribute('data-value')||'');}modal.hidden=false;modal.removeAttribute('hidden');});}if(close){close.addEventListener('click',function(){modal.hidden=true;modal.setAttribute('hidden','hidden');});}})();"); ?>
<?php echo html_writer::script("(function(){function initSortableTable(tbl){if(!tbl)return;var thead=tbl.querySelector('thead');if(!thead)return;var ths=thead.querySelectorAll('th[data-sortable]');for(var i=0;i<ths.length;i++){(function(th){th.classList.add('tm-th-sortable');th.dataset.sortDir='desc';th.addEventListener('click',function(){var tr=th.parentNode;var col=-1;for(var j=0;j<tr.children.length;j++){if(tr.children[j]===th){col=j;break;}}if(col<0)return;var tbody=tbl.querySelector('tbody');if(!tbody)return;var rows=Array.prototype.slice.call(tbody.querySelectorAll('tr'));var dir=(th.dataset.sortDir||'desc')==='desc'?'desc':'asc';for(var k=0;k<ths.length;k++){if(ths[k]!==th){ths[k].dataset.sortDir='desc';ths[k].classList.remove('tm-th-sort-asc');ths[k].classList.remove('tm-th-sort-desc');}}rows.sort(function(a,b){var ca=a.children[col],cb=b.children[col];var va=ca?ca.getAttribute('data-sort-value'):'';var vb=cb?cb.getAttribute('data-sort-value'):'';if(va===null||va===undefined||va===''){va=ca?ca.textContent.trim():'';}if(vb===null||vb===undefined||vb===''){vb=cb?cb.textContent.trim():'';}var na=Number(va),nb=Number(vb);var cmp=0;if(!isNaN(na)&&!isNaN(nb)){cmp=na===nb?0:(na<nb?-1:1);}else{cmp=String(va).localeCompare(String(vb));}return dir==='desc'?-cmp:cmp;});for(var r=0;r<rows.length;r++){tbody.appendChild(rows[r]);}th.dataset.sortDir=(dir==='desc'?'asc':'desc');th.classList.remove('tm-th-sort-asc');th.classList.remove('tm-th-sort-desc');th.classList.add(dir==='desc'?'tm-th-sort-desc':'tm-th-sort-asc');});})(ths[i]);}}var tables=document.querySelectorAll('table[data-sort-table]');for(var t=0;t<tables.length;t++){initSortableTable(tables[t]);}})();"); ?>
<?php echo $OUTPUT->footer(); ?>
