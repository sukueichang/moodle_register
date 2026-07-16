<?php
/**
 * Reservation/Batch application tracking page for business users.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/enrolment_manager.php');
require_once(__DIR__ . '/../classes/verification_manager.php');
require_once(__DIR__ . '/../classes/reservation_application.php');

use local_tm_course\permissions_manager;
use local_tm_course\session_manager;
use local_tm_course\enrolment_manager;
use local_tm_course\verification_manager;

require_login();

$context = context_system::instance();
$issiteadmin = is_siteadmin();
$canbatch = permissions_manager::user_can_batch_enrol();
if (!$issiteadmin && !$canbatch) {
    throw new required_capability_exception($context, 'local/tm_course:batchenrol', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_url(new moodle_url('/local/tm_course/reservation/tracking.php'));
$PAGE->set_title(get_string('reservation_tracking_title', 'local_tm_course'));
$PAGE->set_heading(get_string('reservation_tracking_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');
$str = function(string $key, string $fallback): string {
    $sm = get_string_manager();
    if ($sm->string_exists($key, 'local_tm_course')) {
        return get_string($key, 'local_tm_course');
    }
    return $fallback;
};

$uid = (int)$USER->id;
$statusall = -1;
$statusarchived = -2;
$batchstatus = optional_param('batchstatus', $statusall, PARAM_INT);
$customstatus = optional_param('customstatus', $statusall, PARAM_INT);
$allowedstatuses = [
    $statusall,
    $statusarchived,
    session_manager::ENROL_PENDING,
    session_manager::ENROL_APPROVED,
    session_manager::ENROL_REJECTED,
];
if (!in_array($batchstatus, $allowedstatuses, true)) {
    $batchstatus = $statusall;
}
if (!in_array($customstatus, [$statusall, $statusarchived, 0, 1, 2], true)) {
    $customstatus = $statusall;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    if ($action === 'archive_batch' || $action === 'restore_batch') {
        $sid = required_param('sessionid', PARAM_INT);
        $DB->set_field('local_tm_course_enrolments', 'batch_archived', $action === 'archive_batch' ? 1 : 0, [
            'sessionid' => $sid,
            'batch_submittedby' => $uid,
        ]);
    } else if ($action === 'archive_custom' || $action === 'restore_custom') {
        $rid = required_param('reservationid', PARAM_INT);
        $DB->set_field('local_tm_course_reservation', 'archived', $action === 'archive_custom' ? 1 : 0, [
            'id' => $rid,
            'requesterid' => $uid,
        ]);
    }
    redirect(new moodle_url('/local/tm_course/reservation/tracking.php', [
        'batchstatus' => $batchstatus,
        'customstatus' => $customstatus,
    ]), get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}
$batchrows = $DB->get_records_sql(
    "SELECT s.id AS sessionid, s.name, s.starttime,
            SUM(CASE WHEN e.status = :pending THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN e.status = :approved THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN e.status = :rejected THEN 1 ELSE 0 END) AS rejected_count,
            MAX(COALESCE(e.batch_archived, 0)) AS archived
       FROM {local_tm_course_enrolments} e
       JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
      WHERE e.batch_submittedby = :uid
   GROUP BY s.id, s.name, s.starttime
   ORDER BY s.starttime DESC",
    [
        'uid' => $uid,
        'pending' => session_manager::ENROL_PENDING,
        'approved' => session_manager::ENROL_APPROVED,
        'rejected' => session_manager::ENROL_REJECTED,
    ]
);
$filteredbatchrows = [];
foreach ($batchrows as $row) {
    $isarchived = !empty($row->archived);
    if ($batchstatus === $statusall) {
        $filteredbatchrows[] = $row;
        continue;
    }
    if ($batchstatus === $statusarchived) {
        if ($isarchived) {
            $filteredbatchrows[] = $row;
        }
        continue;
    }
    if ($isarchived) {
        continue;
    }
    if ($batchstatus === session_manager::ENROL_PENDING && (int)$row->pending_count <= 0) {
        continue;
    }
    if ($batchstatus === session_manager::ENROL_APPROVED && (int)$row->approved_count <= 0) {
        continue;
    }
    if ($batchstatus === session_manager::ENROL_REJECTED && (int)$row->rejected_count <= 0) {
        continue;
    }
    $filteredbatchrows[] = $row;
}

$resvsql = "SELECT r.*
              FROM {local_tm_course_reservation} r
             WHERE r.requesterid = :uid"
    . \local_tm_course\reservation_application::sql_submitted_only();
$resvparams = ['uid' => $uid];
if ($customstatus !== $statusall) {
    if ($customstatus === $statusarchived) {
        $resvsql .= " AND COALESCE(r.archived, 0) = 1";
    } else {
        $resvsql .= " AND r.status = :status AND COALESCE(r.archived, 0) = 0";
        $resvparams['status'] = $customstatus;
    }
} else {
    $resvsql .= " AND 1=1";
}
$resvsql .= " ORDER BY COALESCE(NULLIF(r.timesubmitted, 0), r.timecreated) DESC, r.id DESC";
$resvrows = $DB->get_records_sql($resvsql, $resvparams);

$reservationstarttimes = [];
$verificationprogress = [];
$questioncache = [];
if (!empty($resvrows)) {
    $reservationids = array_map(static function($row) {
        return (int)$row->id;
    }, $resvrows);
    list($rinsql, $rinparams) = $DB->get_in_or_equal($reservationids, SQL_PARAMS_NAMED);
    $sessionstarts = $DB->get_records_sql(
        "SELECT source_reservation_id, MIN(starttime) AS firststart
           FROM {local_tm_course_sessions}
          WHERE source_reservation_id $rinsql
       GROUP BY source_reservation_id",
        $rinparams
    );
    foreach ($sessionstarts as $ss) {
        $reservationstarttimes[(int)$ss->source_reservation_id] = (int)$ss->firststart;
    }

    foreach ($resvrows as $row) {
        $rid = (int)$row->id;
        if (!isset($reservationstarttimes[$rid])) {
            $plan = json_decode((string)($row->calendar_plan_json ?? ''), true);
            if (is_array($plan)) {
                $first = 0;
                foreach ($plan as $blk) {
                    $start = (int)($blk['start'] ?? 0);
                    if ($start > 0 && ($first === 0 || $start < $first)) {
                        $first = $start;
                    }
                }
                if ($first > 0) {
                    $reservationstarttimes[$rid] = $first;
                }
            }
        }

        $courseids = [];
        if (!empty($row->courseids_json)) {
            $decoded = json_decode((string)$row->courseids_json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $cid) {
                    $cid = (int)$cid;
                    if ($cid > 0) {
                        $courseids[] = $cid;
                    }
                }
            }
        }
        if (empty($courseids) && (int)$row->courseid > 0) {
            $courseids[] = (int)$row->courseid;
        }
        $courseids = array_values(array_unique($courseids));
        sort($courseids);
        $cachekey = implode(',', $courseids) . '|' . (string)($row->delivery_mode ?? '');
        if (!isset($questioncache[$cachekey])) {
            $questioncache[$cachekey] = verification_manager::get_questions_for_courses($courseids, (string)($row->delivery_mode ?? ''));
        }
        $questions = $questioncache[$cachekey];
        $links = verification_manager::get_reservation_file_links($rid);
        $verificationprogress[$rid] = verification_manager::get_reservation_progress_summary($rid, $questions, $links);
    }
}

$missingprofiles = [];
if (!empty($filteredbatchrows)) {
    $like = $DB->sql_like('u.email', ':heldpattern', false);
    $missingrows = $DB->get_records_sql(
        "SELECT e.sessionid, COUNT(1) AS missing_count
           FROM {local_tm_course_enrolments} e
           JOIN {user} u ON u.id = e.userid
          WHERE e.batch_submittedby = :uid
            AND e.status = :approved
            AND e.placeholder_seq IS NOT NULL AND e.placeholder_seq > 0
            AND (e.linked_userid IS NULL OR e.linked_userid = 0)
            AND $like
       GROUP BY e.sessionid",
        [
            'uid' => $uid,
            'approved' => session_manager::ENROL_APPROVED,
            'heldpattern' => '%' . $DB->sql_like_escape(enrolment_manager::PLACEHOLDER_EMAIL_MARKER),
        ]
    );
    foreach ($missingrows as $mr) {
        $missingprofiles[(int)$mr->sessionid] = (int)$mr->missing_count;
    }
}
$statuslabels = [
    0 => get_string('enrol_pending', 'local_tm_course'),
    1 => get_string('enrol_approved', 'local_tm_course'),
    2 => get_string('enrol_rejected', 'local_tm_course'),
];

echo $OUTPUT->header();
echo html_writer::start_div('tm-page-header');
echo html_writer::span('', 'tm-logo-dot');
echo html_writer::tag('h2', $str('reservation_tracking_title', 'Application tracking'));
echo html_writer::end_div();

echo html_writer::start_div('tm-card');
echo html_writer::start_div('tm-card-body');
echo html_writer::tag('h3', $str('reservation_tracking_batch_title', 'Batch enrolment tracking'), ['class' => 'mb-3']);
echo '<form method="get" class="d-flex gap-2 align-items-center mb-3">';
echo '<input type="hidden" name="customstatus" value="' . (int)$customstatus . '">';
echo '<select name="batchstatus" class="form-control form-control-sm js-tracking-autosubmit" style="width:auto">'
    . '<option value="' . $statusall . '"' . ($batchstatus === $statusall ? ' selected' : '') . '>' . s(get_string('reservation_tracking_filter_all', 'local_tm_course')) . '</option>'
    . '<option value="' . $statusarchived . '"' . ($batchstatus === $statusarchived ? ' selected' : '') . '>' . s(get_string('reservation_tracking_filter_archived', 'local_tm_course')) . '</option>'
    . '<option value="' . session_manager::ENROL_PENDING . '"' . ($batchstatus === session_manager::ENROL_PENDING ? ' selected' : '') . '>' . s(get_string('enrol_pending', 'local_tm_course')) . '</option>'
    . '<option value="' . session_manager::ENROL_APPROVED . '"' . ($batchstatus === session_manager::ENROL_APPROVED ? ' selected' : '') . '>' . s(get_string('enrol_approved', 'local_tm_course')) . '</option>'
    . '<option value="' . session_manager::ENROL_REJECTED . '"' . ($batchstatus === session_manager::ENROL_REJECTED ? ' selected' : '') . '>' . s(get_string('enrol_rejected', 'local_tm_course')) . '</option>'
    . '</select>';
echo '</form>';
if (empty($filteredbatchrows)) {
    echo html_writer::div($str('reservation_tracking_batch_empty', 'No batch enrolment submitted by you yet.'), 'tm-alert tm-alert-info');
} else {
    echo '<table class="tm-table" data-sort-table="tracking-batch-list"><thead><tr>'
        . '<th>#</th><th>' . s(get_string('session_name', 'local_tm_course')) . '</th><th data-sortable="datetime">' . s(get_string('label_start', 'local_tm_course')) . '</th>'
        . '<th>' . s(get_string('enrol_pending', 'local_tm_course')) . '</th><th>' . s(get_string('enrol_approved', 'local_tm_course')) . '</th><th>' . s(get_string('enrol_rejected', 'local_tm_course')) . '</th>'
        . '<th>' . s(get_string('sessions_actions', 'local_tm_course')) . '</th>'
        . '</tr></thead><tbody>';
    foreach ($filteredbatchrows as $row) {
        $detailurl = new moodle_url('/local/tm_course/reservation/tracking_detail.php', [
            'type' => 'batch',
            'id' => (int)$row->sessionid,
        ]);
        $missingtag = '';
        if (!empty($missingprofiles[(int)$row->sessionid])) {
            $missingtag = ' <span class="tm-badge tm-badge-pending">'
                . s(get_string('reservation_tracking_missing_profile_tag', 'local_tm_course')) . '</span>';
        }
        $ops = '<a class="btn btn-sm btn-secondary" href="' . $detailurl->out(false) . '">' . s($str('reservation_tracking_view_detail', 'View detail')) . '</a>';
        if (!empty($row->archived)) {
            $ops .= '<form method="post" action="" style="display:inline-flex; margin-left:.35rem">'
                . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
                . '<input type="hidden" name="action" value="restore_batch">'
                . '<input type="hidden" name="sessionid" value="' . (int)$row->sessionid . '">'
                . '<input type="hidden" name="batchstatus" value="' . (int)$batchstatus . '">'
                . '<input type="hidden" name="customstatus" value="' . (int)$customstatus . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-secondary">' . s(get_string('reservation_tracking_restore', 'local_tm_course')) . '</button>'
                . '</form>';
        } else {
            $ops .= '<form method="post" action="" style="display:inline-flex; margin-left:.35rem">'
                . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
                . '<input type="hidden" name="action" value="archive_batch">'
                . '<input type="hidden" name="sessionid" value="' . (int)$row->sessionid . '">'
                . '<input type="hidden" name="batchstatus" value="' . (int)$batchstatus . '">'
                . '<input type="hidden" name="customstatus" value="' . (int)$customstatus . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-secondary">' . s(get_string('reservation_tracking_archive', 'local_tm_course')) . '</button>'
                . '</form>';
        }
        echo '<tr>'
            . '<td>' . (int)$row->sessionid . $missingtag . '</td>'
            . '<td>' . s((string)$row->name) . '</td>'
            . '<td data-sort-value="' . (int)$row->starttime . '">' . userdate((int)$row->starttime, get_string('strftimedatetimeshort')) . '</td>'
            . '<td>' . (int)$row->pending_count . '</td>'
            . '<td>' . (int)$row->approved_count . '</td>'
            . '<td>' . (int)$row->rejected_count . '</td>'
            . '<td>' . $ops . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';
}

echo html_writer::tag('hr', '', ['class' => 'my-4']);
echo html_writer::tag('h3', $str('reservation_tracking_custom_title', 'Dedicated class application tracking'), ['class' => 'mb-3']);
echo '<form method="get" class="d-flex gap-2 align-items-center mb-3">';
echo '<input type="hidden" name="batchstatus" value="' . (int)$batchstatus . '">';
echo '<select name="customstatus" class="form-control form-control-sm js-tracking-autosubmit" style="width:auto">'
    . '<option value="' . $statusall . '"' . ($customstatus === $statusall ? ' selected' : '') . '>' . s(get_string('reservation_tracking_filter_all', 'local_tm_course')) . '</option>'
    . '<option value="' . $statusarchived . '"' . ($customstatus === $statusarchived ? ' selected' : '') . '>' . s(get_string('reservation_tracking_filter_archived', 'local_tm_course')) . '</option>'
    . '<option value="0"' . ($customstatus === 0 ? ' selected' : '') . '>' . s(get_string('enrol_pending', 'local_tm_course')) . '</option>'
    . '<option value="1"' . ($customstatus === 1 ? ' selected' : '') . '>' . s(get_string('enrol_approved', 'local_tm_course')) . '</option>'
    . '<option value="2"' . ($customstatus === 2 ? ' selected' : '') . '>' . s(get_string('enrol_rejected', 'local_tm_course')) . '</option>'
    . '</select>';
echo '</form>';
if (empty($resvrows)) {
    echo html_writer::div($str('reservation_tracking_custom_empty', 'No custom request submitted by you yet.'), 'tm-alert tm-alert-info');
} else {
    echo '<table class="tm-table" data-sort-table="tracking-custom-list"><thead><tr>'
        . '<th>#</th><th data-sortable="datetime">' . s(get_string('label_applied_at', 'local_tm_course')) . '</th><th>' . s(get_string('label_status', 'local_tm_course')) . '</th><th>' . s(get_string('reservation_tracking_verification_progress', 'local_tm_course')) . '</th><th data-sortable="datetime">' . s($str('reservation_review_last_calendar_update', 'Last calendar update')) . '</th><th data-sortable="number">' . s(get_string('reservation_tracking_days_to_start', 'local_tm_course')) . '</th>'
        . '<th>' . s(get_string('sessions_actions', 'local_tm_course')) . '</th>'
        . '</tr></thead><tbody>';
    foreach ($resvrows as $row) {
        $rid = (int)$row->id;
        $progress = $verificationprogress[$rid] ?? ['total' => 0, 'uploaded' => 0, 'status' => 'na', 'complete' => true];
        $progresslabel = get_string('reservation_tracking_verification_na', 'local_tm_course');
        $progresscls = 'closed';
        if ($progress['status'] === 'not_started') {
            $progresslabel = get_string('reservation_tracking_verification_not_started', 'local_tm_course');
            $progresscls = 'closed';
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
        $starttime = (int)($reservationstarttimes[$rid] ?? 0);
        $daystostart = '—';
        $daystostartsort = -999999;
        $riskbadge = '';
        if ($starttime > 0) {
            $daystoleft = (int)floor(($starttime - time()) / DAYSECS);
            $daystostart = (string)$daystoleft;
            $daystostartsort = $daystoleft;
            if ((int)$progress['total'] > 0 && !$progress['complete'] && $daystoleft <= 7) {
                $riskbadge = ' <span class="tm-badge tm-badge-rejected">' . s(get_string('reservation_tracking_verification_risk', 'local_tm_course')) . '</span>';
            }
        }
        $lastcal = !empty($row->calendar_plan_json)
            ? userdate((int)$row->timemodified, get_string('strftimedatetimeshort'))
            : '—';
        $detailurl = new moodle_url('/local/tm_course/reservation/tracking_detail.php', [
            'type' => 'custom',
            'id' => (int)$row->id,
        ]);
        $isarchived = !empty($row->archived);
        $ops = '<a class="btn btn-sm btn-secondary" href="' . $detailurl->out(false) . '">' . s($str('reservation_tracking_view_detail', 'View detail')) . '</a>';
        if ($isarchived) {
            $ops .= '<form method="post" action="" style="display:inline-flex; margin-left:.35rem">'
                . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
                . '<input type="hidden" name="action" value="restore_custom">'
                . '<input type="hidden" name="reservationid" value="' . (int)$row->id . '">'
                . '<input type="hidden" name="batchstatus" value="' . (int)$batchstatus . '">'
                . '<input type="hidden" name="customstatus" value="' . (int)$customstatus . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-secondary">' . s(get_string('reservation_tracking_restore', 'local_tm_course')) . '</button>'
                . '</form>';
        } else {
            $ops .= '<form method="post" action="" style="display:inline-flex; margin-left:.35rem">'
                . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
                . '<input type="hidden" name="action" value="archive_custom">'
                . '<input type="hidden" name="reservationid" value="' . (int)$row->id . '">'
                . '<input type="hidden" name="batchstatus" value="' . (int)$batchstatus . '">'
                . '<input type="hidden" name="customstatus" value="' . (int)$customstatus . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-secondary">' . s(get_string('reservation_tracking_archive', 'local_tm_course')) . '</button>'
                . '</form>';
        }
        echo '<tr>'
            . '<td>' . (int)$row->id . '</td>'
            . '<td data-sort-value="' . (int)(!empty($row->timesubmitted) ? $row->timesubmitted : $row->timecreated) . '">'
            . userdate((int)(!empty($row->timesubmitted) ? $row->timesubmitted : $row->timecreated), get_string('strftimedatetimeshort')) . '</td>'
            . '<td>' . s($statuslabels[(int)$row->status] ?? '—') . '</td>'
            . '<td><span class="tm-badge tm-badge-' . s($progresscls) . '">' . s($progresslabel) . '</span>' . $riskbadge . '</td>'
            . '<td data-sort-value="' . (!empty($row->calendar_plan_json) ? (int)$row->timemodified : 0) . '">' . s($lastcal) . '</td>'
            . '<td data-sort-value="' . (int)$daystostartsort . '">' . s($daystostart) . '</td>'
            . '<td>' . $ops . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::script("(function(){function initSortableTable(tbl){if(!tbl)return;var thead=tbl.querySelector('thead');if(!thead)return;var ths=thead.querySelectorAll('th[data-sortable]');for(var i=0;i<ths.length;i++){(function(th){th.classList.add('tm-th-sortable');th.dataset.sortDir='desc';th.addEventListener('click',function(){var tr=th.parentNode;var col=-1;for(var j=0;j<tr.children.length;j++){if(tr.children[j]===th){col=j;break;}}if(col<0)return;var tbody=tbl.querySelector('tbody');if(!tbody)return;var rows=Array.prototype.slice.call(tbody.querySelectorAll('tr'));var dir=(th.dataset.sortDir||'desc')==='desc'?'desc':'asc';for(var k=0;k<ths.length;k++){if(ths[k]!==th){ths[k].dataset.sortDir='desc';ths[k].classList.remove('tm-th-sort-asc');ths[k].classList.remove('tm-th-sort-desc');}}rows.sort(function(a,b){var ca=a.children[col],cb=b.children[col];var va=ca?ca.getAttribute('data-sort-value'):'';var vb=cb?cb.getAttribute('data-sort-value'):'';if(va===null||va===undefined||va===''){va=ca?ca.textContent.trim():'';}if(vb===null||vb===undefined||vb===''){vb=cb?cb.textContent.trim():'';}var na=Number(va),nb=Number(vb);var cmp=0;if(!isNaN(na)&&!isNaN(nb)){cmp=na===nb?0:(na<nb?-1:1);}else{cmp=String(va).localeCompare(String(vb));}return dir==='desc'?-cmp:cmp;});for(var r=0;r<rows.length;r++){tbody.appendChild(rows[r]);}th.dataset.sortDir=(dir==='desc'?'asc':'desc');th.classList.remove('tm-th-sort-asc');th.classList.remove('tm-th-sort-desc');th.classList.add(dir==='desc'?'tm-th-sort-desc':'tm-th-sort-asc');});})(ths[i]);}}var tables=document.querySelectorAll('table[data-sort-table]');for(var t=0;t<tables.length;t++){initSortableTable(tables[t]);}var autos=document.querySelectorAll('.js-tracking-autosubmit');for(var a=0;a<autos.length;a++){autos[a].addEventListener('change',function(){if(this.form){this.form.submit();}});}})();");
echo $OUTPUT->footer();
