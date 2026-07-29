<?php
/**
 * Front-end: Course calendar + session list + search
 * URL: /local/tm_course/index.php
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/enrolment_manager.php');
require_once(__DIR__ . '/classes/permissions_manager.php');
require_once(__DIR__ . '/classes/user_dashboard_helper.php');
require_once(__DIR__ . '/classes/prerequisite_manager.php');
use local_tm_course\session_manager;
use local_tm_course\enrolment_manager;
use local_tm_course\permissions_manager;
use local_tm_course\user_dashboard_helper;
use local_tm_course\prerequisite_manager;

require_login();
permissions_manager::require_view_access();

$PAGE->set_url(new moodle_url('/local/tm_course/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('pluginname', 'local_tm_course'));
$PAGE->set_pagelayout('standard');
$PAGE->requires->css('/local/tm_course/styles.css');
$PAGE->requires->js('/local/tm_course/local_time_display.js');
$PAGE->requires->css(new moodle_url('https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/main.min.css'));
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/index.global.min.js'), true);
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/locales-all.global.min.js'), true);

$is_admin = has_capability('local/tm_course:manage', context_system::instance());
$can_viewall = has_capability('local/tm_course:viewall', context_system::instance());
$can_batch_enrol = permissions_manager::user_can_batch_enrol();
$can_view_session_roster = $is_admin || $can_batch_enrol;
$can_attendance_access = permissions_manager::user_can_attendance();
$can_self_enrol_by_role = permissions_manager::user_can_self_enrol_by_role();

// ---- Handle enrolment submission ----
$enrol_action = optional_param('enrol_action', '', PARAM_ALPHANUMEXT);
$enrol_sessionid = optional_param('enrol_sessionid', 0, PARAM_INT);
$cancel_enrolid  = optional_param('cancel_enrolid', 0, PARAM_INT);
$cancel_reason_code = optional_param('cancel_reason_code', '', PARAM_ALPHANUMEXT);
$cancel_reason_other = optional_param('cancel_reason_other', '', PARAM_TEXT);
$enrol_error = null;

// Start enrolment after confirming user's diet preference step.
if ($enrol_action === 'enrol_start' && $enrol_sessionid && confirm_sesskey()) {
    global $SESSION;
    $startsession = session_manager::get_session($enrol_sessionid);
    try {
        prerequisite_manager::assert_learner_prerequisites($startsession, (int)$USER->id);
    } catch (\moodle_exception $e) {
        $SESSION->local_tm_course_prereq_modal = $e->getMessage();
        redirect(new moodle_url('/local/tm_course/index.php'));
    }
    $SESSION->local_tm_course_diet_pending = (object) [
        'sessionid'   => (int)$enrol_sessionid,
        'timecreated' => time(),
    ];
    redirect(new moodle_url('/local/tm_course/enrol_apply_step.php', ['sessionid' => $enrol_sessionid]));
}

if ($enrol_action === 'cancel' && $cancel_enrolid && confirm_sesskey()) {
    enrolment_manager::cancel($cancel_enrolid, $USER->id, $cancel_reason_code, $cancel_reason_other);
    redirect(new moodle_url('/local/tm_course/index.php'),
             'Enrolment cancelled.', null, \core\output\notification::NOTIFY_WARNING);
}

// ---- Search ----
$search_query = optional_param('q', '', PARAM_TEXT);
$search_results = [];
if ($search_query !== '') {
    $search_results = enrolment_manager::search(
        $search_query,
        $can_viewall,
        $USER->id
    );
}

// ---- Load sessions for listing ----
$sessions = session_manager::get_sessions();

// My enrolments (indexed by sessionid)
$my_enrolments_sql = "SELECT * FROM {local_tm_course_enrolments}
                       WHERE userid = :uid ORDER BY timecreated DESC";
$my_enrolments_raw = $DB->get_records_sql($my_enrolments_sql, ['uid' => $USER->id]);
$my_map = []; // sessionid => enrolment record
foreach ($my_enrolments_raw as $me) {
    $my_map[$me->sessionid] = $me;
}

// ---- Course-level mutual exclusion (active enrol blocks other sessions) ----
$active_courseids = [];
$activeStatuses = [
    session_manager::ENROL_PENDING,
    session_manager::ENROL_APPROVED,
    session_manager::ENROL_WAITLISTED,
];
list($statusinsql, $statusparams) = $DB->get_in_or_equal($activeStatuses, SQL_PARAMS_NAMED);
$sql = "SELECT DISTINCT s.courseid
          FROM {local_tm_course_enrolments} e
          JOIN {local_tm_course_sessions} s ON s.id = e.sessionid
         WHERE e.userid = :uid
           AND e.status $statusinsql";
$params = ['uid' => $USER->id] + $statusparams;
$blocked = $DB->get_fieldset_sql($sql, $params);
foreach ($blocked as $cid) {
    $active_courseids[(int)$cid] = true;
}

$status_labels = [
    session_manager::ENROL_PENDING    => ['pending',  get_string('enrol_pending', 'local_tm_course')],
    session_manager::ENROL_APPROVED   => ['approved', get_string('enrol_approved', 'local_tm_course')],
    session_manager::ENROL_REJECTED   => ['rejected', get_string('enrol_rejected', 'local_tm_course')],
    session_manager::ENROL_CANCELLED  => ['closed',   get_string('enrol_cancelled', 'local_tm_course')],
    session_manager::ENROL_WAITLISTED => ['full',     get_string('enrol_waitlisted', 'local_tm_course')],
];

$calendar_api_url = (new moodle_url('/local/tm_course/calendar_events.php', ['sesskey' => sesskey()]))->out(false);
$calendar_enrol_url_base = (new moodle_url('/local/tm_course/enrol_form.php'))->out(false);
$calendar_batch_url_base = (new moodle_url('/local/tm_course/batch_enrol.php'))->out(false);
$calendar_roster_url_base = (new moodle_url('/local/tm_course/admin/session_roster.php'))->out(false);
$calendar_attendance_url_base = (new moodle_url('/local/tm_course/admin/class_prep.php'))->out(false);
$calendar_admin_url = (new moodle_url('/local/tm_course/admin/sessions.php'))->out(false);
$calendar_month_view_label = get_string('calendar_month_view', 'local_tm_course');
$calendar_status_open = get_string('calendar_status_open', 'local_tm_course');
$calendar_status_full = get_string('session_status_full', 'local_tm_course');
$calendar_status_closed = get_string('session_status_closed', 'local_tm_course');
$calendar_popover_title = get_string('calendar_popover_title', 'local_tm_course');
$calendar_available_seats = get_string('calendar_available_seats', 'local_tm_course');
$calendar_view_details = get_string('calendar_view_details', 'local_tm_course');
$calendar_events_load_failed = get_string('calendar_events_load_failed', 'local_tm_course');
$calendar_lib_missing = get_string('calendar_lib_missing', 'local_tm_course');
$calendar_unknown_error = get_string('calendar_unknown_error', 'local_tm_course');
$fc_locale = str_replace('_', '-', current_language());
$calendar_action_enrol = get_string('calendar_action_enrol', 'local_tm_course');
$label_language = get_string('session_teaching_language', 'local_tm_course');
$label_delivery = get_string('session_delivery_mode', 'local_tm_course');
$label_lang_en = get_string('teaching_language_english', 'local_tm_course');
$label_lang_zh = get_string('teaching_language_zh_tw', 'local_tm_course');
$label_mode_onsite = get_string('delivery_mode_onsite', 'local_tm_course');
$label_mode_online = get_string('delivery_mode_online', 'local_tm_course');

// Template context for session cards (display only; same rules as former inline HTML).
global $USER;
$can_enrol_capability = has_capability('local/tm_course:enrol', context_system::instance());
$tm_sesskey = sesskey();
$cancel_enrolment_str = get_string('cancel_enrolment', 'local_tm_course');
$closed_str = get_string('session_status_closed', 'local_tm_course');
$full_str = get_string('session_status_full', 'local_tm_course');
$batch_str = get_string('nav_batch_enrol', 'local_tm_course');
$prereq_badge_str = get_string('prereq_required_badge', 'local_tm_course');
$hours_suffix_str = get_string('hours_suffix', 'local_tm_course');
$capacity_suffix_str = get_string('session_capacity_persons_suffix', 'local_tm_course');
$reject_reason_label_str = get_string('reject_reason_label', 'local_tm_course');
$sm = get_string_manager();
$self_enrol_contact_notice_str = $sm->string_exists('self_enrol_contact_notice', 'local_tm_course')
    ? get_string('self_enrol_contact_notice', 'local_tm_course')
    : '報名課程請洽詢對應原廠業務人員';
$fallback_join_labels = [
    'en' => 'Join online session',
    'zh_tw' => '加入視訊課程',
    'zh_cn' => '加入在线课程',
    'ja' => 'オンライン参加',
    'ko' => '온라인 세션 참여',
    'fr' => 'Rejoindre la session en ligne',
    'de' => 'Online-Sitzung beitreten',
];
$join_meeting_button_label = $sm->string_exists('session_join_meeting_button', 'local_tm_course')
    ? get_string('session_join_meeting_button', 'local_tm_course')
    : ($fallback_join_labels[(string)current_language()] ?? get_string('session_meeting_link', 'local_tm_course')); // Fallback to avoid fatal if language key missing.
$calendar_list_empty_month_str = $sm->string_exists('calendar_session_list_empty_for_month', 'local_tm_course')
    ? get_string('calendar_session_list_empty_for_month', 'local_tm_course')
    : '此月份目前沒有開課場次。';

$calendar_room_closed_display = get_string('session_room_closed_display', 'local_tm_course');
$calendar_room_closed_tooltip = get_string('calendar_room_closed_tooltip', 'local_tm_course');

$prereq_unmet_by_session = [];
$tm_index_sessions = [];
foreach ($sessions as $s) {
    if (session_manager::is_room_closed_session($s)) {
        continue;
    }
    $my_enrol = $my_map[$s->id] ?? null;
    [$badge_cls, $badge_lbl] = $status_labels[(int)($my_enrol->status ?? -1)]
        ?? ['open', get_string('session_status_open', 'local_tm_course')];
    $fill_class = $s->fill_percent >= 100 ? 'danger'
        : ($s->fill_percent >= 80 ? 'warning' : '');

    $sd = date('Y-m-d', (int) $s->starttime);
    $ed = date('Y-m-d', (int) $s->endtime);
    if ($sd === $ed) {
        $start_label = userdate($s->starttime, get_string('strftimetime', 'langconfig'));
        $end_label = userdate($s->endtime, get_string('strftimetime', 'langconfig'));
    } else {
        $start_label = userdate($s->starttime, get_string('strftimedatetimeshort'));
        $end_label = userdate($s->endtime, get_string('strftimedatetimeshort'));
    }

    $teaching_lang_label = ((string)($s->teaching_language ?? '') === session_manager::LANG_ENGLISH)
        ? $label_lang_en : $label_lang_zh;
    $delivery_text = ((string)($s->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE)
        ? $label_mode_online : $label_mode_onsite;

    $is_online_session = ((string)($s->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE);

    $desks = [];
    if (!$is_online_session) {
        for ($d = 1; $d <= (int) $s->num_desks; $d++) {
            $desks[] = [
                'num' => $d,
                'occupied' => in_array($d, $s->occupied_desk_numbers, true),
                'desk_title' => 'Desk ' . $d,
            ];
        }
    }

    $my_enrol_status = $my_enrol ? (int) $my_enrol->status : null;
    $my_enrol_is_cancelled = ($my_enrol_status === session_manager::ENROL_CANCELLED);
    $my_enrol_is_rejected = ($my_enrol_status === session_manager::ENROL_REJECTED);

    $show_already = $my_enrol && !$my_enrol_is_cancelled && !$my_enrol_is_rejected;
    $eligible_for_self_enrol = $can_enrol_capability && $can_self_enrol_by_role;
    $can_submit_enrol = session_manager::can_submit_enrolment($s, false);
    $show_enrol_form = !$show_already
        && $can_submit_enrol
        && ($my_enrol_is_cancelled || $my_enrol_is_rejected || !$my_enrol)
        && $eligible_for_self_enrol;
    $show_closed_btn = !$show_already && !$show_enrol_form
        && ((int) $s->status === session_manager::STATUS_CLOSED
            || session_manager::is_registration_deadline_passed($s));
    $show_contact_notice = !$show_already
        && !$show_enrol_form
        && !$show_closed_btn
        && $can_submit_enrol
        && $can_enrol_capability
        && !$can_self_enrol_by_role;
    $show_full_badge = !$show_already && !$show_enrol_form && !$show_closed_btn && !$show_contact_notice
        && !session_manager::is_online_session($s)
        && ((int) $s->status === session_manager::STATUS_FULL
            || session_manager::is_onsite_desks_full($s));

    $show_cancel = $show_already && $my_enrol
        && in_array((int) $my_enrol->status, [session_manager::ENROL_PENDING, session_manager::ENROL_APPROVED], true);
    $show_change_diet = $show_already && !$is_online_session && $my_enrol
        && (int)$my_enrol->status === session_manager::ENROL_APPROVED;

    $enrol_button_text = '';
    if ($show_enrol_form) {
        if ($my_enrol_is_rejected) {
            $enrol_button_text = get_string('enrol_reapply', 'local_tm_course');
        } else {
            $enrol_button_text = ((int) $s->approval_mode === session_manager::APPROVAL_MANUAL)
                ? get_string('enrol_apply', 'local_tm_course')
                : get_string('enrol_now', 'local_tm_course');
        }
    }

    $show_prereq = false;
    $prereq_courses = [];
    $prereq_unmet = false;
    $prereq_unmet_modal_message = '';
    if (prerequisite_manager::session_has_prerequisites($s)) {
        $prereqnames = prerequisite_manager::get_learner_required_course_names_for_session($s);
        if (!empty($prereqnames)) {
            $show_prereq = true;
            foreach ($prereqnames as $pname) {
                $prereq_courses[] = ['name' => $pname];
            }
        }
        if ($show_enrol_form) {
            $unmetmsg = prerequisite_manager::get_learner_unmet_message_for_session($s, (int)$USER->id);
            if ($unmetmsg !== null) {
                $prereq_unmet = true;
                $prereq_unmet_modal_message = $unmetmsg;
            }
        }
    }

    $show_batch_open = $can_batch_enrol && $can_submit_enrol;
    $show_batch_closed = $can_batch_enrol && !$show_batch_open;
    $batch_disabled_label = $closed_str;
    if ($show_batch_closed && !session_manager::is_online_session($s)
        && ((int) $s->status === session_manager::STATUS_FULL
            || session_manager::is_onsite_desks_full($s))) {
        $batch_disabled_label = $full_str;
    }

    $lunch_note = '';
    if ((string)($s->delivery_mode ?? '') === session_manager::DELIVERY_ONSITE) {
        $lunch_note = get_string('session_duration_note_includes_lunch', 'local_tm_course');
    }

    $online_location_label = '';
    if ($is_online_session) {
        $online_location_label = get_string('delivery_mode_online', 'local_tm_course');
    } else {
        $location_display = trim((string)($s->location ?? ''));
        if ($location_display === '') {
            $location_display = 'TBD';
        }
    }

    $desk_line = '';
    if ($show_already && !$is_online_session && !empty($my_enrol->desk_number)) {
        $desk_line = get_string('desk_assigned_to', 'local_tm_course', (int) $my_enrol->desk_number);
    }
    $diet_line = '';
    if ($show_already && $my_enrol) {
        $diet_line = enrolment_manager::format_diet_summary($my_enrol);
    }

    $show_meeting_link = user_dashboard_helper::can_show_meeting_link_for_enrolment($s, $my_enrol);
    $meeting_link_url = $show_meeting_link
        ? user_dashboard_helper::normalize_meeting_link_url((string) ($s->meeting_link ?? ''))
        : '';

    $tm_index_sessions[] = [
        'id' => $s->id,
        'start_ts' => (int)$s->starttime,
        'end_ts' => (int)$s->endtime,
        'name' => (string) $s->name,
        'date_header' => userdate($s->starttime, '%b %d, %Y'),
        'location' => $is_online_session ? '' : $location_display,
        'online_location_label' => $online_location_label,
        'duration_hours' => number_format(session_manager::learner_display_duration_hours($s), 1),
        'hours_suffix' => $hours_suffix_str,
        'lunch_note' => $lunch_note,
        'start_label' => $start_label,
        'end_label' => $end_label,
        'language_label' => $label_language,
        'teaching_language_label' => $teaching_lang_label,
        'delivery_mode_label' => $label_delivery,
        'is_online' => $is_online_session,
        'delivery_mode_text' => $delivery_text,
        'show_meeting_link' => $show_meeting_link,
        'meeting_link_url' => $meeting_link_url,
        'meeting_link_label' => $join_meeting_button_label,
        'fill_percent' => (int) min(100, max(0, (int) $s->fill_percent)),
        'fill_bar_class' => $fill_class,
        // Onsite only: match legacy UI (desk row even when num_desks is 0). Online: no desk UI.
        'has_desks' => !$is_online_session,
        'desks_heading' => get_string('session_desks_heading', 'local_tm_course'),
        'desks' => $desks,
        'show_roster_link' => $can_view_session_roster,
        'roster_url' => $can_view_session_roster
            ? (new moodle_url('/local/tm_course/admin/session_roster.php', ['sessionid' => (int) $s->id]))->out(false)
            : '',
        'roster_label' => get_string('session_roster_button', 'local_tm_course'),
        'show_prereq' => $show_prereq,
        'prereq_badge' => $prereq_badge_str,
        'prereq_courses' => $prereq_courses,
        'prereq_unmet' => $prereq_unmet,
        'prereq_unmet_modal_message' => $prereq_unmet_modal_message,
        'remaining_persons' => (int) $s->remaining_persons,
        'capacity_suffix' => $capacity_suffix_str,
        'remaining_positions_text' => get_string('session_remaining_positions', 'local_tm_course', (object) [
            'desks' => $s->remaining_desks,
            'total' => $s->num_desks,
            'persons' => $s->remaining_persons,
        ]),
        'badge_cls' => $badge_cls,
        'badge_lbl' => $badge_lbl,
        'show_already_enrolled' => $show_already,
        'show_desk_line' => $show_already && !$is_online_session && !empty($my_enrol->desk_number),
        'desk_line' => $desk_line,
        'show_diet_line' => $show_already && !$is_online_session && $diet_line !== '',
        'diet_line_label' => get_string('diet_survey_title', 'local_tm_course'),
        'diet_line' => $diet_line,
        'show_cancel' => $show_cancel,
        'cancel_enrolid' => $show_cancel ? (int) $my_enrol->id : 0,
        'cancel_label' => $cancel_enrolment_str,
        'show_change_diet' => $show_change_diet,
        'change_diet_url' => ($show_change_diet && $my_enrol)
            ? (new moodle_url('/local/tm_course/enrol_diet_edit.php', ['enrolid' => (int)$my_enrol->id]))->out(false)
            : '',
        'change_diet_label' => get_string('change_diet_habit', 'local_tm_course'),
        'sesskey' => $tm_sesskey,
        'show_enrol_form' => $show_enrol_form,
        'enrol_form_id' => 'enrol-form-' . $s->id,
        'show_rejected_block' => $show_enrol_form && $my_enrol_is_rejected,
        'show_reject_notes' => $show_enrol_form && $my_enrol_is_rejected && $my_enrol && !empty($my_enrol->notes),
        'reject_reason_label' => $reject_reason_label_str,
        'reject_notes' => ($my_enrol && !empty($my_enrol->notes)) ? (string) $my_enrol->notes : '',
        'enrol_button_text' => $enrol_button_text,
        'show_closed_btn' => $show_closed_btn,
        'closed_label' => $closed_str,
        'show_full_badge' => $show_full_badge,
        'full_label' => $full_str,
        'show_contact_notice' => $show_contact_notice,
        'contact_notice_text' => $self_enrol_contact_notice_str,
        'show_batch_open' => $show_batch_open,
        'batch_url' => (new moodle_url('/local/tm_course/batch_enrol.php', ['sessionid' => (int) $s->id]))->out(false),
        'batch_label' => $batch_str,
        'show_batch_closed' => $show_batch_closed,
        'batch_disabled_label' => $batch_disabled_label,
        'show_attendance_open' => $can_attendance_access,
        'attendance_url' => (new moodle_url('/local/tm_course/admin/class_prep.php', ['sessionid' => (int) $s->id]))->out(false),
        'attendance_label' => get_string('nav_class_prep', 'local_tm_course'),
    ];
    if ($prereq_unmet && $prereq_unmet_modal_message !== '') {
        $prereq_unmet_by_session[(int)$s->id] = $prereq_unmet_modal_message;
    }
}

global $SESSION;
$prereq_modal_on_load = '';
if (!empty($SESSION->local_tm_course_prereq_modal)) {
    $prereq_modal_on_load = (string)$SESSION->local_tm_course_prereq_modal;
    unset($SESSION->local_tm_course_prereq_modal);
}

echo $OUTPUT->header();
?>

<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h1><?php echo get_string('calendar_title', 'local_tm_course'); ?></h1>
    <?php if ($is_admin): ?>
    <a href="<?php echo (new moodle_url('/local/tm_course/admin/sessions.php'))->out(); ?>"
       class="btn btn-sm btn-tm-success ml-auto" style="margin-left:auto">
        ⚙ <?php echo get_string('manage_sessions', 'local_tm_course'); ?>
    </a>
    <?php endif; ?>
</div>

<?php if (!empty($enrol_error)): ?>
<div class="tm-alert tm-alert-error"><?php echo s($enrol_error); ?></div>
<?php endif; ?>

<!-- ===== Calendar Month View (M6) ===== -->
<div class="tm-card mt-3" id="tm-calendar-month-anchor">
<div class="tm-card-body tm-calendar-panel">
    <h3 class="tm-calendar-title">🗓 <?php echo get_string('calendar_title', 'local_tm_course'); ?> - <?php echo s($calendar_month_view_label); ?></h3>
    <div id="tm-calendar-error" class="tm-alert tm-alert-error" hidden></div>
    <div id="tm-month-calendar"></div>
    <div id="tm-calendar-tooltip" class="tm-calendar-tooltip" hidden></div>
</div>
</div>

<div id="tm-mode-modal" class="tm-cancel-modal-backdrop" hidden>
    <div class="tm-cancel-modal-panel tm-mode-modal-panel" role="dialog" aria-modal="true" aria-labelledby="tm-mode-title">
        <h4 id="tm-mode-title">選擇報名模式</h4>
        <p class="mb-2 text-muted">請選擇這筆場次的報名方式。</p>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($can_view_session_roster): ?>
            <a href="#" class="btn btn-secondary" id="tm-mode-roster-btn"><?php echo get_string('session_roster_button', 'local_tm_course'); ?></a>
            <?php endif; ?>
            <a href="#" class="btn tm-enrol-btn" id="tm-mode-self-btn">自主報名</a>
            <a href="#" class="btn tm-enrol-btn" id="tm-mode-batch-btn">批次報名</a>
            <?php if ($is_admin): ?>
            <a href="<?php echo s($calendar_admin_url); ?>" class="btn btn-secondary">進入管理後台</a>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" id="tm-mode-close-btn">取消</button>
        </div>
    </div>
</div>

<!-- ===== Session List ===== -->
<div class="tm-card mt-3">
<div class="tm-card-body">
<h3 style="color:var(--tm-blue); margin-bottom:.8rem">📅 <?php echo get_string('nav_sessions_available', 'local_tm_course'); ?></h3>

    <?php if (empty($sessions)): ?>
        <div class="tm-alert tm-alert-info"><?php echo get_string('no_sessions', 'local_tm_course'); ?></div>
    <?php else: ?>
        <?php echo $OUTPUT->render_from_template('local_tm_course/index', ['sessions' => $tm_index_sessions]); ?>
        <div id="tm-session-list-empty-month" class="tm-alert tm-alert-info mt-2" hidden>
            <?php echo s($calendar_list_empty_month_str); ?>
        </div>
    <?php endif; ?>
</div><!-- .tm-card-body -->
</div><!-- .tm-card -->

<div id="tm-prereq-modal" class="tm-cancel-modal-backdrop" hidden>
    <div class="tm-cancel-modal-panel" style="max-width:36rem" role="dialog" aria-modal="true" aria-labelledby="tm-prereq-modal-title">
        <div style="background:#005f7e;color:#fff;padding:.6rem .9rem;margin:-1rem -1rem .9rem;border-radius:.4rem .4rem 0 0;">
            <strong id="tm-prereq-modal-title"><?php echo get_string('prerequisite_learner_modal_title', 'local_tm_course'); ?></strong>
        </div>
        <p id="tm-prereq-modal-body" class="mb-3"></p>
        <div class="d-flex justify-content-end">
            <button type="button" class="btn btn-tm-primary" id="tm-prereq-modal-confirm"><?php echo get_string('ok'); ?></button>
        </div>
    </div>
</div>

<div id="tm-cancel-modal" class="tm-cancel-modal-backdrop" hidden>
    <div class="tm-cancel-modal-panel" role="dialog" aria-modal="true" aria-labelledby="tm-cancel-title">
        <h4 id="tm-cancel-title"><?php echo get_string('cancel_modal_title', 'local_tm_course'); ?></h4>
        <p class="mb-2"><?php echo get_string('cancel_modal_prompt', 'local_tm_course'); ?></p>
        <div class="mb-2">
            <select id="cancel_reason_code_ui" class="form-control">
                <option value=""><?php echo get_string('cancel_reason_select', 'local_tm_course'); ?></option>
                <option value="work"><?php echo get_string('cancel_reason_work', 'local_tm_course'); ?></option>
                <option value="other_session"><?php echo get_string('cancel_reason_other_session', 'local_tm_course'); ?></option>
                <option value="other"><?php echo get_string('cancel_reason_other', 'local_tm_course'); ?></option>
            </select>
            <textarea id="cancel_reason_other_ui" class="form-control mt-2" maxlength="1000" rows="3"
                      placeholder="<?php echo get_string('cancel_reason_other_placeholder', 'local_tm_course'); ?>"
                      style="display:none"></textarea>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-tm-danger" id="tm-cancel-confirm-btn"><?php echo get_string('cancel_modal_confirm', 'local_tm_course'); ?></button>
            <button type="button" class="btn btn-secondary" id="tm-cancel-close-btn"><?php echo get_string('cancel_modal_close', 'local_tm_course'); ?></button>
        </div>
    </div>
</div>

<form method="post" action="" id="tm-cancel-submit-form" style="display:none">
    <input type="hidden" name="enrol_action" value="cancel">
    <input type="hidden" name="cancel_enrolid" id="cancel_enrolid_hidden" value="0">
    <input type="hidden" name="cancel_reason_code" id="cancel_reason_code_hidden" value="">
    <input type="hidden" name="cancel_reason_other" id="cancel_reason_other_hidden" value="">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
</form>

<script>
(function() {
    var calendarRoot = document.getElementById('tm-month-calendar');
    var tooltip = document.getElementById('tm-calendar-tooltip');
    var calendarError = document.getElementById('tm-calendar-error');
    var modeModal = document.getElementById('tm-mode-modal');
    var modeSelfBtn = document.getElementById('tm-mode-self-btn');
    var modeBatchBtn = document.getElementById('tm-mode-batch-btn');
    var modeCloseBtn = document.getElementById('tm-mode-close-btn');
    var sessionListGrid = document.getElementById('tm-session-list-grid');
    var sessionListEmptyMonth = document.getElementById('tm-session-list-empty-month');
    var isSales = <?php echo json_encode((bool)$can_batch_enrol); ?>;
    var canViewRoster = <?php echo json_encode((bool)$can_view_session_roster); ?>;
    var rosterLabel = <?php echo json_encode(get_string('session_roster_button', 'local_tm_course')); ?>;
    var prereqUnmetBySession = <?php echo json_encode($prereq_unmet_by_session, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    if (!calendarRoot) {
        return;
    }

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function shortTitle(title) {
        var t = String(title || '');
        return t.length > 18 ? (t.substring(0, 18) + '...') : t;
    }

    function formatHm(date) {
        if (!date) {
            return '';
        }
        var h = date.getHours();
        var m = date.getMinutes();
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }

    var tooltipHideTimer = null;

    function clearTooltipHideTimer() {
        if (tooltipHideTimer) {
            clearTimeout(tooltipHideTimer);
            tooltipHideTimer = null;
        }
    }

    function positionTooltipByElement(anchorEl) {
        if (!tooltip || !anchorEl) {
            return;
        }
        var rect = anchorEl.getBoundingClientRect();
        var margin = 8;
        var vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
        var vh = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
        var tw = tooltip.offsetWidth || 280;
        var th = tooltip.offsetHeight || 160;
        var left = rect.left + (rect.width - tw) / 2;
        var top = rect.top - th - margin; // placement: top
        if (top < margin) {
            top = rect.bottom + margin; // auto fallback to bottom.
        }
        if (left < margin) {
            left = margin;
        }
        if (left + tw > vw - margin) {
            left = vw - tw - margin;
        }
        if (top + th > vh - margin) {
            top = vh - th - margin;
        }
        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
    }

    function showTooltip(anchorEl, html) {
        if (!tooltip) {
            return;
        }
        clearTooltipHideTimer();
        tooltip.innerHTML = html;
        tooltip.hidden = false;
        // Append to body to avoid clipping by calendar containers.
        if (tooltip.parentNode !== document.body) {
            document.body.appendChild(tooltip);
        }
        positionTooltipByElement(anchorEl);
    }

    function showCalendarError(msg) {
        if (!calendarError) {
            return;
        }
        calendarError.textContent = msg;
        calendarError.hidden = false;
    }

    function clearCalendarError() {
        if (calendarError) {
            calendarError.hidden = true;
            calendarError.textContent = '';
        }
    }

    function hideTooltip() {
        clearTooltipHideTimer();
        tooltip.hidden = true;
    }

    function scheduleHideTooltip() {
        clearTooltipHideTimer();
        tooltipHideTimer = setTimeout(function() {
            hideTooltip();
        }, 120);
    }

    function enrolUrl(sessionid) {
        var base = <?php echo json_encode($calendar_enrol_url_base); ?>;
        return base + '?sessionid=' + encodeURIComponent(String(sessionid));
    }

    function batchUrl(sessionid) {
        var base = <?php echo json_encode($calendar_batch_url_base); ?>;
        return base + '?sessionid=' + encodeURIComponent(String(sessionid));
    }
    function attendanceUrl(sessionid) {
        var base = <?php echo json_encode($calendar_attendance_url_base); ?>;
        return base + '?sessionid=' + encodeURIComponent(String(sessionid));
    }

    function rosterUrl(sessionid) {
        var base = <?php echo json_encode($calendar_roster_url_base); ?>;
        return base + '?sessionid=' + encodeURIComponent(String(sessionid));
    }

    function scrollToSessionCard(sessionid) {
        if (!sessionListGrid || !sessionid) {
            return;
        }
        var card = sessionListGrid.querySelector('.tm-session-card[data-sessionid="' + String(sessionid) + '"]');
        if (!card) {
            return;
        }
        card.style.display = '';
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        card.classList.add('tm-session-card-highlight');
        window.setTimeout(function() {
            card.classList.remove('tm-session-card-highlight');
        }, 2400);
    }

    function openPrereqBlockModal(message) {
        var prereqModal = document.getElementById('tm-prereq-modal');
        var prereqBody = document.getElementById('tm-prereq-modal-body');
        if (!prereqModal || !prereqBody) {
            window.alert(message || '');
            return;
        }
        prereqBody.textContent = message || '';
        prereqModal.hidden = false;
    }

    function trySelfEnrol(sessionid) {
        var sid = String(sessionid || '');
        if (prereqUnmetBySession && prereqUnmetBySession[sid]) {
            openPrereqBlockModal(prereqUnmetBySession[sid]);
            return;
        }
        window.location.href = enrolUrl(sessionid);
    }

    function openModeModal(sessionid) {
        if (!modeModal || !modeSelfBtn || !modeBatchBtn) {
            trySelfEnrol(sessionid);
            return;
        }
        modeSelfBtn.setAttribute('data-sessionid', String(sessionid || ''));
        modeSelfBtn.setAttribute('href', enrolUrl(sessionid));
        modeBatchBtn.setAttribute('href', batchUrl(sessionid));
        var rosterBtn = document.getElementById('tm-mode-roster-btn');
        if (rosterBtn) {
            rosterBtn.setAttribute('href', rosterUrl(sessionid));
        }
        modeModal.hidden = false;
    }

    function closeModeModal() {
        if (modeModal) {
            modeModal.hidden = true;
        }
    }

    if (modeCloseBtn) {
        modeCloseBtn.addEventListener('click', closeModeModal);
    }
    if (modeSelfBtn) {
        modeSelfBtn.addEventListener('click', function(e) {
            var sid = parseInt(modeSelfBtn.getAttribute('data-sessionid'), 10) || 0;
            if (!sid) {
                return;
            }
            if (prereqUnmetBySession && prereqUnmetBySession[String(sid)]) {
                e.preventDefault();
                closeModeModal();
                openPrereqBlockModal(prereqUnmetBySession[String(sid)]);
            }
        });
    }
    if (tooltip) {
        tooltip.addEventListener('mouseenter', function() {
            clearTooltipHideTimer();
        });
        tooltip.addEventListener('mouseleave', function() {
            scheduleHideTooltip();
        });
        tooltip.addEventListener('click', function(e) {
            var btn = e.target && e.target.closest ? e.target.closest('.tm-fc-tooltip-btn') : null;
            if (!btn) {
                return;
            }
            var sid = parseInt(btn.getAttribute('data-sid'), 10) || 0;
            var enrollable = (btn.getAttribute('data-enrollable') === '1');
            if (!sid) {
                // Non-enrol tooltip links (e.g. attendance direct link) use normal navigation.
                return;
            }
            e.preventDefault();
            if (!sid || !enrollable) {
                return;
            }
            if (isSales) {
                openModeModal(sid);
                return;
            }
            trySelfEnrol(sid);
        });
    }

    function parseEventInstant(v) {
        if (v === null || v === undefined || v === '') {
            return null;
        }
        if (typeof v === 'number' && isFinite(v)) {
            return new Date(v * (v < 1e12 ? 1000 : 1));
        }
        var d = new Date(v);
        return isNaN(d.getTime()) ? null : d;
    }

    function syncSessionListByMonth(date) {
        if (!sessionListGrid || !date) {
            return;
        }
        var targetYear = date.getFullYear();
        var targetMonth = date.getMonth();
        var cards = sessionListGrid.querySelectorAll('.tm-session-card[data-start-ts]');
        var visibleCount = 0;
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var ts = Number(card.getAttribute('data-start-ts') || 0);
            if (!ts) {
                card.style.display = '';
                visibleCount++;
                continue;
            }
            var start = new Date(ts * 1000);
            var visible = start.getFullYear() === targetYear && start.getMonth() === targetMonth;
            card.style.display = visible ? '' : 'none';
            if (visible) {
                visibleCount++;
            }
        }
        if (sessionListEmptyMonth) {
            sessionListEmptyMonth.hidden = visibleCount > 0;
        }
    }

    function initTmCalendar() {
        if (typeof FullCalendar === 'undefined') {
            return false;
        }
        if (calendarRoot.getAttribute('data-tm-fc-init') === '1') {
            return true;
        }
        calendarRoot.setAttribute('data-tm-fc-init', '1');

        var calendar = new FullCalendar.Calendar(calendarRoot, {
        initialView: 'dayGridMonth',
        locale: <?php echo json_encode($fc_locale); ?>,
        height: 'auto',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: ''
        },
        fixedWeekCount: false,
        dayMaxEvents: true,
        eventDisplay: 'block',
        eventClassNames: function(arg) {
            var ext = arg.event.extendedProps || {};
            var classes = ext.ownDedicatedSession ? ['tm-fc-event-own-dedicated'] : [];
            if (ext.isRoomClosed) {
                classes.push('tm-fc-event-room-closed');
            }
            return classes;
        },
        datesSet: function(info) {
            if (info && info.view && info.view.currentStart) {
                syncSessionListByMonth(info.view.currentStart);
            }
        },
        events: function(info, successCallback, failureCallback) {
            var from = Math.floor(info.start.getTime() / 1000);
            var to = Math.floor(info.end.getTime() / 1000);
            var url = <?php echo json_encode($calendar_api_url); ?> + '&from=' + from + '&to=' + to;
            fetch(url, { credentials: 'same-origin' })
                .then(function(res) {
                    if (!res.ok) {
                        throw new Error('Calendar API HTTP ' + res.status);
                    }
                    return res.json();
                })
                .then(function(data) {
                    var items = (data && data.events) ? data.events : [];
                    var mapped = items.map(function(item) {
                        var startDate = parseEventInstant(item.start);
                        if (!startDate) {
                            return null;
                        }
                        var endDate = parseEventInstant(item.end);
                        var timeLabel = (window.tmCourseLocalTime && window.tmCourseLocalTime.formatEventHmWithTz)
                            ? window.tmCourseLocalTime.formatEventHmWithTz(startDate)
                            : formatHm(startDate);
                        var shortname = shortTitle(item.title || '');
                        var extRaw = item.extendedProps || {};
                        var out = {
                            id: String(item.id),
                            start: startDate,
                            end: endDate,
                            allDay: false,
                            title: shortname + ' (' + timeLabel + ')',
                            extendedProps: extRaw
                        };
                        if (item.backgroundColor) {
                            out.backgroundColor = item.backgroundColor;
                            out.borderColor = item.borderColor || item.backgroundColor;
                            if (item.textColor) {
                                out.textColor = item.textColor;
                            }
                        }
                        return out;
                    }).filter(Boolean);
                    clearCalendarError();
                    successCallback(mapped);
                })
                .catch(function(err) {
                    showCalendarError(<?php echo json_encode($calendar_events_load_failed); ?> + ': ' + (err && err.message ? err.message : <?php echo json_encode($calendar_unknown_error); ?>));
                    failureCallback(err);
                });
        },
        eventContent: function(arg) {
            var ext = arg.event.extendedProps || {};
            if (ext.isRoomClosed) {
                return {
                    html: '<div class="tm-fc-card tm-fc-card-room-closed">' +
                        '<div class="tm-fc-main-row">' +
                        '<span class="tm-fc-title">' + esc(arg.event.title) + '</span>' +
                        '</div>' +
                        '<div class="tm-fc-room-closed-hint">' + esc(<?php echo json_encode($calendar_room_closed_display); ?>) + '</div>' +
                        '<div class="tm-fc-progress-caption">' + esc(String(ext.location || '').trim() || '—') + '</div>' +
                    '</div>'
                };
            }
            var remain = Number(ext.remainingPersons || 0);
            var nearly = !!ext.isNearlyFull;
            var isEnrolled = !!ext.isEnrolled;
            var fillPercent = Math.max(0, Math.min(100, Number(ext.fillPercent || 0)));
            var totalCapacity = Number(ext.totalCapacity || 0);
            var approvedCount = Number(ext.approvedCount || 0);
            var lang = String(ext.teachingLanguage || '');
            var mode = String(ext.deliveryMode || '');
            var isOnline = (mode === <?php echo json_encode(session_manager::DELIVERY_ONLINE); ?>);
            var langLabel = (lang === <?php echo json_encode(session_manager::LANG_ENGLISH); ?>)
                ? <?php echo json_encode($label_lang_en); ?>
                : <?php echo json_encode($label_lang_zh); ?>;
            var modeLabel = isOnline
                ? <?php echo json_encode($label_mode_online); ?>
                : <?php echo json_encode($label_mode_onsite); ?>;
            var remainClass = nearly ? 'tm-fc-remain-nearly' : 'tm-fc-remain-normal';
            var checkHtml = isEnrolled ? '<span class="tm-fc-check" aria-label="enrolled">✔</span>' : '';
            var isClosed = Number(ext.sessionStatus) === <?php echo (int) session_manager::STATUS_CLOSED; ?>;
            var statusBadge = '';
            if (isClosed) {
                statusBadge = '<span class="tm-fc-status-badge tm-fc-status-badge-closed">' + esc(<?php echo json_encode($calendar_status_closed); ?>) + '</span>';
            } else if (!isOnline && remain <= 0) {
                statusBadge = '<span class="tm-fc-status-badge tm-fc-status-badge-full">' + esc(<?php echo json_encode($calendar_status_full); ?>) + '</span>';
            } else if (isOnline || ext.isEnrollable) {
                // Online: open/closed only (no capacity-full). Onsite: open when enrolable.
                statusBadge = '<span class="tm-fc-status-badge">' + esc(<?php echo json_encode($calendar_status_open); ?>) + '</span>';
            }
            var ownDedicatedRow = (ext.ownDedicatedSession && ext.ownDedicatedBadge)
                ? '<div class="tm-fc-own-dedicated-row">' + esc(ext.ownDedicatedBadge) + '</div>'
                : '';
            var capacityHtml = '';
            if (!isOnline) {
                capacityHtml =
                    '<div class="tm-fc-remain ' + remainClass + '">' + remain + ' / ' + totalCapacity + '</div>' +
                    '<div class="tm-fc-progress-caption">' + esc(<?php echo json_encode($label_language); ?>) + ': ' + esc(langLabel) + ' · ' + esc(<?php echo json_encode($label_delivery); ?>) + ': ' + esc(modeLabel) + '</div>' +
                    '<div class="tm-fc-progress"><span style="width:' + fillPercent + '%"></span></div>' +
                    '<div class="tm-fc-progress-caption">' + approvedCount + '/' + totalCapacity + '</div>';
            } else {
                capacityHtml =
                    '<div class="tm-fc-progress-caption">' + esc(<?php echo json_encode($label_language); ?>) + ': ' + esc(langLabel) + ' · ' + esc(<?php echo json_encode($label_delivery); ?>) + ': ' + esc(modeLabel) + '</div>';
            }
            return {
                html: '<div class="tm-fc-card">' +
                    '<div class="tm-fc-main-row">' +
                        '<div class="tm-fc-main">' + checkHtml + '<span class="tm-fc-title">' + esc(arg.event.title) + '</span></div>' +
                        statusBadge +
                    '</div>' +
                    ownDedicatedRow +
                    capacityHtml +
                '</div>'
            };
        },
        eventDidMount: function(info) {
            var ext = info.event.extendedProps || {};
            var full = String(ext.fullCourseName || info.event.title || '').trim();
            var extra = String(ext.ownDedicatedSessionLabel || '').trim();
            var tip = extra ? (full ? full + ' — ' + extra : extra) : full;
            if (tip) {
                info.el.setAttribute('title', tip);
            }
            if (ext.ownDedicatedSession && tip) {
                info.el.setAttribute('aria-label', tip);
            }
        },
        eventMouseEnter: function(info) {
            var ext = info.event.extendedProps || {};
            if (ext.isRoomClosed) {
                var tloc = String(ext.location || '').trim();
                var tipHead = <?php echo json_encode($calendar_room_closed_tooltip); ?>;
                var fullName = ext.fullCourseName || info.event.title || '';
                var html = '<div class="tm-fc-tooltip-head">' + esc(tipHead) + '</div>'
                    + '<div class="tm-fc-tooltip-title">' + esc(fullName) + '</div>';
                if (tloc) {
                    html += '<div class="tm-fc-tooltip-line">' + esc(tloc) + '</div>';
                }
                showTooltip(info.el, html);
                return;
            }
            var remainPersons = Number(ext.remainingPersons || 0);
            var approvedCount = Number(ext.approvedCount || 0);
            var fullName = ext.fullCourseName || info.event.title || '';
            var tipMode = String(ext.deliveryMode || '');
            var tipIsOnline = (tipMode === <?php echo json_encode(session_manager::DELIVERY_ONLINE); ?>);
            var remainClass = ext.isNearlyFull ? 'tm-fc-tooltip-nearly' : 'tm-fc-tooltip-normal';
            var sid = parseInt(info.event.id, 10) || 0;
            var enrollable = ext.isEnrollable ? '1' : '0';
            var attendanceHtml = <?php echo json_encode((bool)$can_attendance_access); ?>
                ? ('<a class="tm-fc-tooltip-btn" href="' + attendanceUrl(sid) + '">' + esc(<?php echo json_encode(get_string('nav_class_prep', 'local_tm_course')); ?>) + '</a>')
                : '';
            var rosterHtml = canViewRoster
                ? ('<a class="tm-fc-tooltip-btn" href="' + rosterUrl(sid) + '">' + esc(rosterLabel) + '</a>')
                : '';
            var actionHtml = ext.isEnrollable
                ? ('<a class="tm-fc-tooltip-btn" href="#" data-sid="' + sid + '" data-enrollable="' + enrollable + '">' + esc(<?php echo json_encode($calendar_action_enrol); ?>) + '</a>')
                : '';
            var ownDedicatedTip = (ext.ownDedicatedSession && ext.ownDedicatedSessionLabel)
                ? '<div class="tm-fc-tooltip-line tm-fc-tooltip-own-dedicated">' + esc(ext.ownDedicatedSessionLabel) + '</div>'
                : '';
            var scheduleTip = '';
            if (info.event.start && window.tmCourseLocalTime) {
                var endForTip = info.event.end instanceof Date ? info.event.end : info.event.start;
                var startTs = Math.floor(info.event.start.getTime() / 1000);
                var endTs = Math.floor(endForTip.getTime() / 1000);
                if (startTs > 0 && endTs > startTs) {
                    scheduleTip = '<div class="tm-fc-tooltip-line">' + esc(<?php echo json_encode(get_string('label_start', 'local_tm_course')); ?>)
                        + ': ' + esc(window.tmCourseLocalTime.formatTimeRange(startTs, endTs)) + '</div>';
                }
            }
            var seatsTip = tipIsOnline
                ? ('<div class="tm-fc-tooltip-line">' + esc(<?php echo json_encode(get_string('session_online_enrolled_heading', 'local_tm_course')); ?>)
                    + ': ' + approvedCount + '</div>')
                : ('<div class="tm-fc-tooltip-line ' + remainClass + '">' + esc(<?php echo json_encode($calendar_available_seats); ?>)
                    + ': ' + remainPersons + '</div>');
            var html = ''
                + '<div class="tm-fc-tooltip-head">' + esc(<?php echo json_encode($calendar_popover_title); ?>) + '</div>'
                + '<div class="tm-fc-tooltip-title">' + esc(fullName) + '</div>'
                + scheduleTip
                + ownDedicatedTip
                + seatsTip
                + rosterHtml
                + actionHtml
                + attendanceHtml;
            showTooltip(info.el, html);
        },
        eventMouseLeave: function(info) {
            scheduleHideTooltip();
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            var sid = parseInt(info.event.id, 10) || 0;
            var ext = info.event.extendedProps || {};
            if (!sid) {
                return;
            }
            if (canViewRoster) {
                if (info.event.start) {
                    syncSessionListByMonth(info.event.start);
                }
                scrollToSessionCard(sid);
                return;
            }
            if (!ext.isEnrollable) {
                return;
            }
            if (isSales) {
                openModeModal(sid);
                return;
            }
            trySelfEnrol(sid);
        }
        });

        calendar.render();
        syncSessionListByMonth(calendar.getDate());
        return true;
    }

    function waitForFullCalendar() {
        var attempts = 0;
        function tick() {
            if (initTmCalendar()) {
                return;
            }
            attempts++;
            if (attempts > 300) {
                showCalendarError(<?php echo json_encode($calendar_lib_missing); ?>);
                return;
            }
            setTimeout(tick, 50);
        }
        window.addEventListener('load', function() {
            tick();
        });
        setTimeout(tick, 0);
    }

    waitForFullCalendar();
})();

(function() {
    var modal = document.getElementById('tm-cancel-modal');
    var openButtons = document.querySelectorAll('.js-open-cancel-modal');
    var closeBtn = document.getElementById('tm-cancel-close-btn');
    var confirmBtn = document.getElementById('tm-cancel-confirm-btn');
    var otherInput = document.getElementById('cancel_reason_other_ui');
    var hidEnrol = document.getElementById('cancel_enrolid_hidden');
    var hidCode = document.getElementById('cancel_reason_code_hidden');
    var hidOther = document.getElementById('cancel_reason_other_hidden');
    var submitForm = document.getElementById('tm-cancel-submit-form');
    var currentEnrolid = 0;

    function closeModal() {
        modal.hidden = true;
        currentEnrolid = 0;
    }

    var reasonSelect = document.getElementById('cancel_reason_code_ui');
    function selectedReason() {
        return reasonSelect ? reasonSelect.value : '';
    }
    if (reasonSelect) {
        reasonSelect.addEventListener('change', function() {
            otherInput.style.display = (selectedReason() === 'other') ? 'block' : 'none';
        });
    }

    openButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            currentEnrolid = parseInt(btn.getAttribute('data-enrolid'), 10) || 0;
            modal.hidden = false;
        });
    });

    closeBtn.addEventListener('click', closeModal);

    confirmBtn.addEventListener('click', function() {
        var code = selectedReason();
        if (!code) {
            alert(<?php echo json_encode(get_string('error_cancel_reason_required', 'local_tm_course')); ?>);
            return;
        }
        var other = '';
        if (code === 'other') {
            other = (otherInput.value || '').trim();
            if (!other) {
                alert(<?php echo json_encode(get_string('error_cancel_reason_other_required', 'local_tm_course')); ?>);
                return;
            }
        }
        if (!confirm(<?php echo json_encode(get_string('cancel_final_confirm', 'local_tm_course'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>)) {
            return;
        }
        hidEnrol.value = String(currentEnrolid);
        hidCode.value = code;
        hidOther.value = other;
        submitForm.submit();
    });
})();

(function() {
    var prereqModal = document.getElementById('tm-prereq-modal');
    var prereqBody = document.getElementById('tm-prereq-modal-body');
    var prereqConfirm = document.getElementById('tm-prereq-modal-confirm');
    if (!prereqModal || !prereqBody) {
        return;
    }

    function openPrereqModal(message) {
        prereqBody.textContent = message || '';
        prereqModal.hidden = false;
    }

    function closePrereqModal() {
        prereqModal.hidden = true;
        prereqBody.textContent = '';
    }

    if (prereqConfirm) {
        prereqConfirm.addEventListener('click', closePrereqModal);
    }
    prereqModal.addEventListener('click', function(e) {
        if (e.target === prereqModal) {
            closePrereqModal();
        }
    });

    document.querySelectorAll('.js-tm-enrol-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (form.getAttribute('data-prereq-unmet') !== '1') {
                return;
            }
            e.preventDefault();
            openPrereqModal(form.getAttribute('data-prereq-modal-message') || '');
        });
    });

    var onLoadMessage = <?php echo json_encode($prereq_modal_on_load, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    if (onLoadMessage) {
        openPrereqModal(onLoadMessage);
    }
})();
</script>

<?php echo $OUTPUT->footer(); ?>
