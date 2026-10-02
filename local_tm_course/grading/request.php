<?php
/**
 * Grading request detail: assign, start grading, reject/cancel.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/grading_request_manager.php');

use local_tm_course\grading_request_manager;

require_login();
global $DB, $USER, $OUTPUT, $PAGE;
$PAGE->set_context(context_system::instance());

$id = required_param('id', PARAM_INT);
$req = grading_request_manager::get_request($id);
if (!$req) {
    throw new moodle_exception('grading_error_notfound', 'local_tm_course');
}
if (!grading_request_manager::can_view($req)) {
    throw new required_capability_exception(
        context_system::instance(),
        'local/tm_course:manage',
        'nopermissions',
        ''
    );
}

grading_request_manager::sync_request($id);
$req = grading_request_manager::get_request($id);

$PAGE->set_url(new moodle_url('/local/tm_course/grading/request.php', ['id' => $id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('grading_request_title', 'local_tm_course', $id));
$PAGE->set_heading(get_string('grading_request_title', 'local_tm_course', $id));
$PAGE->requires->css('/local/tm_course/styles.css');

$candispatch = grading_request_manager::user_can_dispatch_request($req);
$isowner = ((int)$req->requesterid === (int)$USER->id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = required_param('action', PARAM_ALPHANUMEXT);
    try {
        if ($action === 'assign' && $candispatch) {
            $assignee = required_param('assigneeid', PARAM_INT);
            grading_request_manager::assign_to($id, $assignee, (int)$USER->id);
            redirect($PAGE->url, get_string('grading_assigned_ok', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'reject' && $candispatch) {
            $reason = optional_param('rejectreason', '', PARAM_RAW_TRIMMED);
            grading_request_manager::reject($id, $reason);
            redirect($PAGE->url, get_string('grading_rejected_ok', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'cancel') {
            grading_request_manager::cancel($id, (int)$USER->id);
            redirect($PAGE->url, get_string('grading_cancelled_ok', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'restore' && $candispatch) {
            grading_request_manager::restore($id);
            redirect($PAGE->url, get_string('grading_restored_ok', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
        $req = grading_request_manager::get_request($id);
        $candispatch = grading_request_manager::user_can_dispatch_request($req);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = required_param('action', PARAM_ALPHANUMEXT);
    try {
        if ($action === 'assign' && $candispatch) {
            $assignee = required_param('assigneeid', PARAM_INT);
            grading_request_manager::assign_to($id, $assignee, (int)$USER->id);
            redirect($PAGE->url, get_string('grading_assigned_ok', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'reject' && $candispatch) {
            $reason = optional_param('rejectreason', '', PARAM_RAW_TRIMMED);
            grading_request_manager::reject($id, $reason);
            redirect($PAGE->url, get_string('grading_rejected_ok', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'cancel') {
            grading_request_manager::cancel($id, (int)$USER->id);
            redirect($PAGE->url, get_string('grading_cancelled_ok', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'restore' && $candispatch) {
            grading_request_manager::restore($id);
            redirect($PAGE->url, get_string('grading_restored_ok', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
        $req = grading_request_manager::get_request($id);
    }
}

$activity = grading_request_manager::get_activity((int)$req->cmid);
$counts = grading_request_manager::progress_counts($req);
$items = grading_request_manager::get_items($id);
$open = in_array((int)$req->status, grading_request_manager::OPEN_STATUSES, true);
$canstart = grading_request_manager::can_start_grade($req);
$activitygone = ((int)$req->activitygone === 1) || !$activity || !$activity['exists'];

echo $OUTPUT->header();
echo html_writer::start_div('tm-page-header');
echo html_writer::span('', 'tm-logo-dot');
echo html_writer::tag('h2', get_string('grading_request_title', 'local_tm_course', $id));
echo html_writer::end_div();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/tm_course/grading/queue.php'), get_string('grading_back_queue', 'local_tm_course')),
    'mb-3'
);

echo html_writer::start_div('tm-card tm-card-body mb-3');
if ($activitygone) {
    $actcell = s(get_string('grading_activity_missing', 'local_tm_course'));
} else {
    $actname = $activity['name'] ?? '';
    $acturl = new moodle_url('/mod/' . $req->modname . '/view.php', ['id' => (int)$req->cmid]);
    $actcell = html_writer::link($acturl, s($actname));
}
echo html_writer::div(html_writer::tag('strong', get_string('grading_label_course', 'local_tm_course') . ': ')
    . s(format_string($DB->get_field('course', 'fullname', ['id' => (int)$req->courseid]) ?: '')));
echo html_writer::div(html_writer::tag('strong', get_string('grading_label_activity', 'local_tm_course') . ': ')
    . $actcell . ' (' . s((string)$req->modname) . ')');
echo html_writer::div(html_writer::tag('strong', get_string('status') . ': ')
    . s(grading_request_manager::status_label((int)$req->status))
    . ' (' . $counts['done'] . '/' . $counts['total'] . ')');
$assignhist = grading_request_manager::assignment_history($id);
if ((int)$req->assigneeid > 0) {
    $current = $assignhist ? end($assignhist) : null;
    $assigneename = $current
        ? grading_request_manager::assignment_display_name($current)
        : grading_request_manager::assignment_display_name((object) ['assigneeid' => (int)$req->assigneeid]);
    $assignedat = (int)($req->timeassigned ?? 0);
    if ($assignedat <= 0 && $current) {
        $assignedat = (int)$current->timecreated;
    }
    echo html_writer::div(html_writer::tag('strong', get_string('grading_label_assignee', 'local_tm_course') . ': ')
        . s($assigneename));
    if ($assignedat > 0) {
        echo html_writer::div(html_writer::tag('strong', get_string('grading_label_assigned_at', 'local_tm_course') . ': ')
            . s(userdate($assignedat, get_string('strftimedatetimeshort'))));
    }
}
if (count($assignhist) > 1) {
    echo html_writer::div(html_writer::tag('strong', get_string('grading_assign_history', 'local_tm_course')), 'mt-2');
    echo html_writer::start_tag('ul', ['class' => 'tm-grading-assign-history']);
    $lasti = count($assignhist) - 1;
    foreach ($assignhist as $i => $row) {
        $label = ($i === $lasti)
            ? get_string('grading_assign_current', 'local_tm_course')
            : get_string('grading_assign_previous', 'local_tm_course');
        $when = (int)$row->timecreated > 0
            ? userdate((int)$row->timecreated, get_string('strftimedatetimeshort'))
            : '—';
        echo html_writer::tag('li',
            html_writer::span($label . '：', 'tm-grading-assign-role')
            . s(grading_request_manager::assignment_display_name($row))
            . ' — '
            . s($when)
        );
    }
    echo html_writer::end_tag('ul');
}
if (trim((string)($req->note ?? '')) !== '') {
    echo html_writer::div(html_writer::tag('strong', get_string('grading_label_note', 'local_tm_course') . ': ')
        . s((string)$req->note));
}
if (trim((string)($req->rejectreason ?? '')) !== '') {
    echo html_writer::div(html_writer::tag('strong', get_string('grading_reject_reason', 'local_tm_course') . ': ')
        . s((string)$req->rejectreason));
}
echo html_writer::end_div();

$table = new html_table();
$table->attributes['class'] = 'generaltable tm-grading-table';
$table->head = [
    get_string('user'),
    get_string('email'),
    get_string('grading_col_itemstatus', 'local_tm_course'),
    get_string('grading_col_grade', 'local_tm_course'),
    '',
];
foreach ($items as $item) {
    $gradecell = '—';
    $actioncell = '';
    $statuslabel = grading_request_manager::item_status_label((int)$item->itemstatus);
    if ((int)$item->itemstatus === grading_request_manager::ITEM_MISSING || $activitygone) {
        $statuslabel = get_string('grading_item_missing', 'local_tm_course');
    } else if ($activity && $activity['exists']) {
        $g = grading_request_manager::submission_grade((int)$req->cmid, (int)$item->userid);
        if (!empty($g['has'])) {
            $gradecell = s($g['str']);
            if (!empty($g['time'])) {
                $gradecell .= html_writer::div(userdate((int)$g['time'], get_string('strftimedatetimeshort')), 'text-muted');
            }
        }
        // Per-learner Moodle link: show whether pending or already graded, even after the ticket closes.
        if ($canstart && (int)$item->itemstatus !== grading_request_manager::ITEM_MISSING) {
            $url = grading_request_manager::grade_url($req, $item);
            if ($url) {
                $linklabel = !empty($g['has'])
                    ? get_string('grading_open_work', 'local_tm_course')
                    : get_string('grading_start', 'local_tm_course');
                $actioncell = html_writer::link($url, $linklabel, [
                    'class' => 'btn btn-sm tm-dashboard-btn tm-dashboard-btn-active',
                    'target' => '_blank',
                    'rel' => 'noopener',
                ]);
            }
        }
    }
    $email = trim((string)($item->snapemail ?? ''));
    $table->data[] = [
        s(grading_request_manager::display_item_name($item)),
        s($email !== '' ? $email : '—'),
        s($statuslabel),
        $gradecell,
        $actioncell,
    ];
}
echo html_writer::table($table);

if ($candispatch && $open && !$activitygone) {
    $graders = grading_request_manager::list_graders((int)$req->courseid, (string)$req->modname);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'tm-card tm-card-body mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'assign']);
    echo html_writer::tag('h4', get_string('grading_assign_heading', 'local_tm_course'), ['class' => 'tm-dashboard-section-title']);
    echo html_writer::start_tag('select', ['name' => 'assigneeid', 'class' => 'form-control mb-2', 'required' => 'required']);
    echo html_writer::tag('option', get_string('choosedots'), ['value' => '']);
    foreach ($graders as $g) {
        $attrs = ['value' => (int)$g->id];
        if ((int)$req->assigneeid === (int)$g->id) {
            $attrs['selected'] = 'selected';
        }
        echo html_writer::tag('option', fullname($g) . ' (' . $g->email . ')', $attrs);
    }
    echo html_writer::end_tag('select');
    echo html_writer::tag('button', get_string('grading_assign_submit', 'local_tm_course'), [
        'type' => 'submit',
        'class' => 'btn tm-dashboard-btn',
    ]);
    echo html_writer::end_tag('form');

    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'tm-card tm-card-body mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'reject']);
    echo html_writer::tag('h4', get_string('grading_reject_heading', 'local_tm_course'), ['class' => 'tm-dashboard-section-title']);
    echo html_writer::tag('textarea', '', [
        'name' => 'rejectreason',
        'class' => 'form-control mb-2',
        'rows' => 2,
        'required' => 'required',
    ]);
    echo html_writer::tag('button', get_string('grading_reject_submit', 'local_tm_course'), [
        'type' => 'submit',
        'class' => 'btn tm-dashboard-btn tm-dashboard-btn-danger',
    ]);
    echo html_writer::end_tag('form');
}

$cancancel = $open && ($candispatch || ($isowner && (int)$req->assigneeid === 0));
if ($cancancel) {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'cancel']);
    echo html_writer::tag('button', get_string('grading_cancel_submit', 'local_tm_course'), [
        'type' => 'submit',
        'class' => 'btn tm-dashboard-btn',
    ]);
    echo html_writer::end_tag('form');
}

$canrestore = $candispatch && in_array((int)$req->status, [
    grading_request_manager::STATUS_REJECTED,
    grading_request_manager::STATUS_CANCELLED,
], true);
if ($canrestore) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'class' => 'tm-card tm-card-body mb-3',
        'onsubmit' => 'return confirm(' . json_encode(get_string('grading_restore_confirm', 'local_tm_course')) . ');',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'restore']);
    echo html_writer::tag('h4', get_string('grading_restore_heading', 'local_tm_course'), ['class' => 'tm-dashboard-section-title']);
    echo html_writer::div(get_string('grading_restore_help', 'local_tm_course'), 'mb-2');
    echo html_writer::tag('button', get_string('grading_restore_submit', 'local_tm_course'), [
        'type' => 'submit',
        'class' => 'btn tm-dashboard-btn tm-dashboard-btn-active',
    ]);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
