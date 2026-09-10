<?php
/**
 * Grading request queue.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/grading_request_manager.php');

use local_tm_course\grading_request_manager;

require_login();
$PAGE->set_context(context_system::instance());

$isadmin = grading_request_manager::user_is_admin();
$canapply = grading_request_manager::user_can_apply();
$canqueue = grading_request_manager::user_can_see_queue();
if (!$isadmin && !$canapply && !$canqueue) {
    throw new required_capability_exception(
        context_system::instance(),
        'local/tm_course:batchenrol',
        'nopermissions',
        ''
    );
}

$view = optional_param('view', '', PARAM_ALPHANUMEXT);
if ($view === '') {
    if ($isadmin) {
        $view = 'pending';
    } else if ($canqueue && !$canapply) {
        $view = 'assigned';
    } else {
        $view = 'mine';
    }
}

$PAGE->set_url(new moodle_url('/local/tm_course/grading/queue.php', ['view' => $view]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('grading_queue_title', 'local_tm_course'));
$PAGE->set_heading(get_string('grading_queue_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

$uid = (int)$USER->id;
$allowed = [];
if ($canapply) {
    $allowed[] = 'mine';
}
if ($isadmin) {
    $allowed[] = 'pending';
    $allowed[] = 'all';
}
if ($isadmin || $canqueue) {
    $allowed[] = 'assigned';
}
if (!in_array($view, $allowed, true)) {
    $view = $allowed[0] ?? 'mine';
}

$rows = grading_request_manager::list_requests($view, $uid);
foreach ($rows as $row) {
    grading_request_manager::sync_request((int)$row->id);
}
$rows = grading_request_manager::list_requests($view, $uid);

echo $OUTPUT->header();
echo html_writer::start_div('tm-page-header');
echo html_writer::span('', 'tm-logo-dot');
echo html_writer::tag('h2', get_string('grading_queue_title', 'local_tm_course'));
echo html_writer::end_div();

echo html_writer::start_div('tm-grading-tabs mb-3');
$tabs = [
    'mine' => get_string('grading_queue_mine', 'local_tm_course'),
    'pending' => get_string('grading_queue_pending', 'local_tm_course'),
    'assigned' => get_string('grading_queue_assigned', 'local_tm_course'),
    'all' => get_string('grading_queue_all', 'local_tm_course'),
];
foreach ($tabs as $key => $label) {
    if (!in_array($key, $allowed, true)) {
        continue;
    }
    $cls = 'btn tm-dashboard-btn' . ($view === $key ? ' tm-dashboard-btn-active' : '');
    echo html_writer::link(new moodle_url('/local/tm_course/grading/queue.php', ['view' => $key]), $label, ['class' => $cls]);
}
if ($canapply) {
    echo html_writer::link(
        new moodle_url('/local/tm_course/grading/apply.php'),
        get_string('grading_apply_title', 'local_tm_course'),
        ['class' => 'btn tm-dashboard-btn']
    );
}
echo html_writer::end_div();

if (empty($rows)) {
    echo html_writer::div(get_string('grading_queue_empty', 'local_tm_course'), 'tm-dashboard-empty');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable tm-grading-table';
    $table->head = [
        get_string('grading_col_id', 'local_tm_course'),
        get_string('grading_label_course', 'local_tm_course'),
        get_string('grading_label_activity', 'local_tm_course'),
        get_string('grading_col_progress', 'local_tm_course'),
        get_string('status'),
        get_string('grading_col_updated', 'local_tm_course'),
        '',
    ];
    foreach ($rows as $row) {
        $fresh = grading_request_manager::get_request((int)$row->id) ?: $row;
        $counts = grading_request_manager::progress_counts($fresh);
        $activity = grading_request_manager::get_activity((int)$fresh->cmid);
        $actname = $activity['name'] ?? get_string('grading_activity_missing', 'local_tm_course');
        if ((int)$fresh->activitygone === 1 || !$activity || !$activity['exists']) {
            $actname = get_string('grading_activity_missing', 'local_tm_course');
        }
        $detail = new moodle_url('/local/tm_course/grading/request.php', ['id' => (int)$fresh->id]);
        $table->data[] = [
            '#' . (int)$fresh->id,
            format_string((string)($row->coursename ?? '')),
            s($actname),
            $counts['done'] . '/' . $counts['total'],
            grading_request_manager::status_label((int)$fresh->status),
            userdate((int)$fresh->timemodified, get_string('strftimedatetimeshort')),
            html_writer::link($detail, get_string('grading_open', 'local_tm_course')),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
