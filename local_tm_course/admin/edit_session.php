<?php
/**
 * Admin: Create / Edit / Batch-create a session
 * URL: /local/tm_course/admin/edit_session.php[?id=N][?batch=1]
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/classroom_manager.php');
require_once(__DIR__ . '/../classes/enabled_course_manager.php');
require_once(__DIR__ . '/../classes/prerequisite_manager.php');

use local_tm_course\session_manager;
use local_tm_course\classroom_manager;
use local_tm_course\enabled_course_manager;
use local_tm_course\prerequisite_manager;

/**
 * @param string $date Y-m-d
 */
function local_tm_course_combine_schedule_half_hour(string $date, int $hour, int $minute): int {
    $minute = ((int) $minute === 30) ? 30 : 0;
    $hour = max(0, min(23, (int) $hour));
    $date = trim($date);
    if ($date === '') {
        return 0;
    }
    $ts = strtotime($date . ' ' . sprintf('%02d:%02d:00', $hour, $minute));
    return $ts === false ? 0 : $ts;
}

function local_tm_course_split_schedule_half_hour(int $ts): array {
    $i = (int) date('i', $ts);
    $m = ($i >= 30) ? 30 : 0;
    return [
        'date' => date('Y-m-d', $ts),
        'hour' => (int) date('G', $ts),
        'minute' => $m,
    ];
}

function local_tm_course_round_unix_half_hour(int $ts): int {
    $d = date('Y-m-d', $ts);
    $h = (int) date('G', $ts);
    $i = (int) date('i', $ts);
    $tot = $h * 60 + $i;
    $r = (int) (round($tot / 30) * 30);
    if ($r >= 1440) {
        $mid = strtotime($d . ' 00:00:00');
        $d = date('Y-m-d', strtotime('+1 day', $mid));
        $r = 0;
    }
    $nh = intdiv($r, 60);
    $nm = $r % 60;
    return local_tm_course_combine_schedule_half_hour($d, $nh, $nm);
}

require_login();
require_capability('local/tm_course:manage', context_system::instance());

global $DB, $OUTPUT, $PAGE, $CFG;

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/local/tm_course/styles.css');

$id    = optional_param('id',    0, PARAM_INT);
$batch = optional_param('batch', 0, PARAM_BOOL);

$session = null;
if ($id) {
    $session = session_manager::get_session($id);
    if ($session && session_manager::is_room_closed_session($session)) {
        redirect(new moodle_url('/local/tm_course/admin/room_closed.php', ['id' => $id]));
    }
}

$title = $batch ? get_string('batch_create', 'local_tm_course')
       : ($id   ? get_string('edit_session', 'local_tm_course')
                : get_string('add_session',  'local_tm_course'));

$PAGE->set_url(new moodle_url('/local/tm_course/admin/edit_session.php',
                              $id ? ['id' => $id] : ($batch ? ['batch' => 1] : [])));
$PAGE->set_title($title);

$classroomrows = classroom_manager::get_all();
$courses       = enabled_course_manager::get_course_menu();
if ($id && $session && !empty($session->courseid) && !isset($courses[$session->courseid])) {
    $crow = $DB->get_record('course', ['id' => $session->courseid], 'id, fullname', IGNORE_MISSING);
    if ($crow) {
        $courses[$crow->id] = $crow->fullname . ' (' . get_string('course_mapping_inactive_tag', 'local_tm_course') . ')';
    }
}
$prereqcourses = enabled_course_manager::get_course_menu();

$course_duration_map = enabled_course_manager::get_duration_map();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $schedulemode = optional_param('schedule_time_mode', 1, PARAM_INT);
    $deliverymodepost = optional_param('delivery_mode', session_manager::DELIVERY_ONSITE, PARAM_ALPHANUMEXT);
    $courseidpost = required_param('courseid', PARAM_INT);

    // Disabled form controls are omitted from POST. Online + auto mode leaves schedule_time_mode
    // and end_* disabled, so infer auto when end_date was not submitted.
    if ($schedulemode !== 0 && optional_param('end_date', '', PARAM_TEXT) === '') {
        $schedulemode = 0;
    }

    $start_ts = local_tm_course_combine_schedule_half_hour(
        required_param('start_date', PARAM_TEXT),
        required_param('start_hour', PARAM_INT),
        required_param('start_minute', PARAM_INT)
    );

    if ($schedulemode === 0) {
        $respectstart = ($deliverymodepost === session_manager::DELIVERY_ONSITE);
        $calc = session_manager::calculate_session_times(
            (int)$courseidpost,
            (string)$deliverymodepost,
            (int)$start_ts,
            $respectstart
        );
        $start_ts = (int)$calc['starttime'];
        $end_ts = (int)$calc['endtime'];
    } else {
        $end_ts = local_tm_course_combine_schedule_half_hour(
            required_param('end_date', PARAM_TEXT),
            required_param('end_hour', PARAM_INT),
            required_param('end_minute', PARAM_INT)
        );
    }

    $data = [
        'classroomid'           => optional_param('classroomid', 0, PARAM_INT),
        'courseid'              => (int) $courseidpost,
        'name'                  => required_param('name', PARAM_TEXT),
        'description'           => optional_param('description', '', PARAM_CLEANHTML),
        'location'              => optional_param('location', '', PARAM_TEXT),
        // Physical Auto spans multiple calendar days with gaps; duration = teaching hours, not timestamp delta.
        'schedule_auto_physical' => ($schedulemode === 0 && $deliverymodepost === session_manager::DELIVERY_ONSITE),
        'starttime'             => $start_ts,
        'endtime'               => $end_ts,
        'num_desks'             => optional_param('num_desks', 0, PARAM_INT),
        'persons_per_desk'      => optional_param('persons_per_desk', 0, PARAM_INT),
        'teaching_language'     => optional_param('teaching_language', session_manager::LANG_ZH_TW, PARAM_ALPHANUMEXT),
        'delivery_mode'         => optional_param('delivery_mode', session_manager::DELIVERY_ONSITE, PARAM_ALPHANUMEXT),
        'meeting_link'          => optional_param('meeting_link', '', PARAM_RAW_TRIMMED),
        'approval_mode'         => optional_param('approval_mode', session_manager::APPROVAL_MANUAL, PARAM_INT),
        'status'                => optional_param('status', session_manager::STATUS_OPEN, PARAM_INT),
        'auto_close_exempt'     => optional_param('auto_close_exempt', 0, PARAM_BOOL),
        // Admin may schedule multiple non-overlapping sessions in the same classroom on one day.
        'allow_same_day_classroom' => ($deliverymodepost === session_manager::DELIVERY_ONSITE),
    ];

    $repeat_type  = optional_param('repeat_type',  0, PARAM_INT);
    $repeat_count = max(1, min(52, optional_param('repeat_count', 1, PARAM_INT)));

    $prereqcmidsbyrow = [];
    if (!empty($_POST['prerequisite_rule_cmids']) && is_array($_POST['prerequisite_rule_cmids'])) {
        foreach ($_POST['prerequisite_rule_cmids'] as $rowidx => $cmids) {
            $rowidx = (int)$rowidx;
            if (!is_array($cmids)) {
                continue;
            }
            $prereqcmidsbyrow[$rowidx] = array_map('intval', $cmids);
        }
    }
    $prereqgradejsonbyrow = [];
    $gradepost = optional_param_array('prerequisite_rule_grade_json', [], PARAM_RAW);
    if (is_array($gradepost)) {
        foreach ($gradepost as $rowidx => $rawjson) {
            $decoded = json_decode((string)$rawjson, true);
            if (is_array($decoded)) {
                $prereqgradejsonbyrow[(int)$rowidx] = $decoded;
            }
        }
    }
    $prereqverifypost = optional_param_array('prerequisite_rule_verify_type', [], PARAM_ALPHANUMEXT);
    $prereqcoursespost = optional_param_array('prerequisite_rule_courseid', [], PARAM_INT);
    $prereqgradeopspost = optional_param_array('prerequisite_rule_grade_operator', [], PARAM_ALPHA);

    try {
        foreach ($prereqcoursespost as $pidx => $pcid) {
            if ((int)$pcid <= 0) {
                continue;
            }
            $pverify = \core_text::strtolower(trim((string)($prereqverifypost[$pidx] ?? '')));
            if ($pverify === prerequisite_manager::VERIFY_ACTIVITIES) {
                $rowcmids = $prereqcmidsbyrow[$pidx] ?? [];
                if (empty($rowcmids)) {
                    throw new \moodle_exception('error_prerequisite_activity_invalid', 'local_tm_course');
                }
            }
            if ($pverify === prerequisite_manager::VERIFY_GRADES) {
                $rawconds = $prereqgradejsonbyrow[$pidx] ?? [];
                $normalized = prerequisite_manager::normalize_rules([
                    'operator' => prerequisite_manager::OPERATOR_AND,
                    'rules' => [[
                        'courseid' => (int)$pcid,
                        'verify_type' => prerequisite_manager::VERIFY_GRADES,
                        'grade_operator' => $prereqgradeopspost[$pidx] ?? prerequisite_manager::ACTIVITY_ALL,
                        'grade_conditions' => $rawconds,
                    ]],
                ]);
                if ($normalized === null || empty($normalized['rules'])) {
                    throw new \moodle_exception('error_prerequisite_activity_invalid', 'local_tm_course');
                }
            }
        }
        $data['prerequisite_rules'] = prerequisite_manager::build_from_post(
            optional_param('prerequisite_operator', prerequisite_manager::OPERATOR_AND, PARAM_ALPHA),
            $prereqcoursespost,
            $prereqverifypost,
            optional_param_array('prerequisite_rule_activity_operator', [], PARAM_ALPHA),
            $prereqcmidsbyrow,
            $prereqgradejsonbyrow,
            $prereqgradeopspost
        );
        session_manager::normalize_session_data($data);
        session_manager::validate_session_data($data, !$id);

        if (empty($courses) && !$id) {
            throw new \moodle_exception('error_no_enabled_courses', 'local_tm_course');
        }
        if (!$id && empty($data['courseid'])) {
            throw new \moodle_exception('error_course_not_found', 'local_tm_course');
        }
        $cid = (int) $data['courseid'];
        if ($cid > 0 && !enabled_course_manager::is_enabled($cid)) {
            $same = $id && $session && (int) $session->courseid === $cid;
            if (!$same) {
                throw new \moodle_exception('error_course_not_enabled', 'local_tm_course');
            }
        }

        if ($batch && $repeat_count > 1) {
            $new_ids = session_manager::batch_create($data, $repeat_type, $repeat_count);
            redirect(new moodle_url('/local/tm_course/admin/sessions.php'),
                     get_string('session_created', 'local_tm_course') . " ($repeat_count sessions)",
                     null, \core\output\notification::NOTIFY_SUCCESS);
        } elseif ($id) {
            session_manager::update_session($id, $data);
            redirect(new moodle_url('/local/tm_course/admin/sessions.php'),
                     get_string('session_updated', 'local_tm_course'),
                     null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            session_manager::create_session($data);
            redirect(new moodle_url('/local/tm_course/admin/sessions.php'),
                     get_string('session_created', 'local_tm_course'),
                     null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (\moodle_exception $e) {
        $errors[] = $e->getMessage();
    }
}

$legacy = $session && empty((int) ($session->classroomid ?? 0));

$nowrounded = local_tm_course_round_unix_half_hour(time());
if ($session) {
    $startsplit = local_tm_course_split_schedule_half_hour(local_tm_course_round_unix_half_hour($session->starttime));
    $endsplit = local_tm_course_split_schedule_half_hour(local_tm_course_round_unix_half_hour($session->endtime));
} else {
    $startsplit = local_tm_course_split_schedule_half_hour($nowrounded);
    $endsplit = local_tm_course_split_schedule_half_hour($nowrounded + 8 * 3600);
}

$v = (object) [
    'classroomid'           => (int) ($session->classroomid ?? 0),
    'courseid'              => $session->courseid              ?? 0,
    'name'                  => $session->name                  ?? '',
    'description'           => $session->description           ?? '',
    'location'              => $session->location              ?? '',
    'start_date'            => $startsplit['date'],
    'start_hour'            => $startsplit['hour'],
    'start_minute'          => $startsplit['minute'],
    'end_date'              => $endsplit['date'],
    'end_hour'              => $endsplit['hour'],
    'end_minute'            => $endsplit['minute'],
    // Physical sessions use auto duration in JS; match that so first paint matches forced auto mode.
    'schedule_time_mode'    => (($session !== null ? $session->delivery_mode : session_manager::DELIVERY_ONSITE) === session_manager::DELIVERY_ONLINE) ? 1 : 0,
    'num_desks'             => $session->num_desks             ?? 6,
    'persons_per_desk'      => $session->persons_per_desk      ?? session_manager::PERSONS_CLASSROOM,
    'teaching_language'     => $session->teaching_language     ?? session_manager::LANG_ZH_TW,
    'delivery_mode'         => $session->delivery_mode         ?? session_manager::DELIVERY_ONSITE,
    'meeting_link'          => $session->meeting_link          ?? '',
    'approval_mode'         => $session !== null ? (int) $session->approval_mode : session_manager::APPROVAL_MANUAL,
    'status'                => $session !== null ? (int) $session->status : session_manager::STATUS_OPEN,
    'auto_close_exempt'     => $session !== null ? (int) ($session->auto_close_exempt ?? 0) : 0,
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $postedrules = prerequisite_manager::build_from_post(
        optional_param('prerequisite_operator', prerequisite_manager::OPERATOR_AND, PARAM_ALPHA),
        optional_param_array('prerequisite_rule_courseid', [], PARAM_INT),
        optional_param_array('prerequisite_rule_verify_type', [], PARAM_ALPHANUMEXT),
        optional_param_array('prerequisite_rule_activity_operator', [], PARAM_ALPHA),
        $prereqcmidsbyrow ?? []
    );
    $prereqrulestate = $postedrules ?? [
        'operator' => optional_param('prerequisite_operator', prerequisite_manager::OPERATOR_AND, PARAM_ALPHA),
        'rules' => [],
    ];
} else if (!$id) {
    $defaultcid = (int)($v->courseid ?? 0);
    $coursedefaults = ($defaultcid > 0)
        ? enabled_course_manager::get_default_prerequisite_rules($defaultcid)
        : null;
    $prereqrulestate = $coursedefaults ?? [
        'operator' => prerequisite_manager::OPERATOR_AND,
        'rules' => [],
    ];
} else {
    $prereqrulestate = prerequisite_manager::resolve_session_rules($session) ?? [
        'operator' => prerequisite_manager::OPERATOR_AND,
        'rules' => [],
    ];
}
$sessioninheritsprereqdefaults = ($id > 0 && $session)
    && prerequisite_manager::session_inherits_course_prerequisite_defaults($session);
$courseprereqdefaultmap = enabled_course_manager::get_default_prerequisite_rules_map();
$prereqverifylabels = [
    prerequisite_manager::VERIFY_COURSE => get_string('session_prerequisite_verify_course', 'local_tm_course'),
    prerequisite_manager::VERIFY_ACTIVITIES => get_string('session_prerequisite_verify_activities', 'local_tm_course'),
    prerequisite_manager::VERIFY_GRADES => get_string('session_prerequisite_verify_grades', 'local_tm_course'),
];

echo $OUTPUT->header();
?>

<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo s($title); ?></h2>
</div>

<div class="tm-card">
<div class="tm-card-body">

<?php foreach ($errors as $err): ?>
    <div class="tm-alert tm-alert-error"><?php echo s($err); ?></div>
<?php endforeach; ?>

<?php if (empty($classroomrows)): ?>
    <div class="tm-alert tm-alert-error mb-3">
        <?php echo get_string('classroom_none_defined', 'local_tm_course'); ?>
        <a href="<?php echo (new moodle_url('/local/tm_course/classroom/edit.php'))->out(); ?>" class="alert-link">
            <?php echo get_string('classroom_add', 'local_tm_course'); ?></a>
    </div>
<?php endif; ?>

<?php if (empty($courses)): ?>
    <div class="tm-alert tm-alert-info mb-3">
        <?php echo get_string('course_mapping_empty_hint', 'local_tm_course'); ?>
        <a href="<?php echo (new moodle_url('/local/tm_course/settings/course_mapping.php'))->out(); ?>" class="alert-link">
            <?php echo get_string('nav_course_mapping', 'local_tm_course'); ?></a>
    </div>
<?php endif; ?>

<form method="post" action="" novalidate id="tm-session-form">
<?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
<?php if ($id): ?><input type="hidden" name="id" value="<?php echo $id; ?>"><?php endif; ?>

<div class="tm-form-section mb-3">
    <div class="section-title">📋 <?php echo get_string('session_section_basic', 'local_tm_course'); ?></div>

    <div class="row">
        <div class="col-md-8 mb-2">
            <label class="font-weight-bold"><?php echo get_string('session_name', 'local_tm_course'); ?> *</label>
            <input type="text" name="name" class="form-control" required maxlength="255"
                   value="<?php echo s($v->name); ?>">
        </div>
        <div class="col-md-4 mb-2">
            <label class="font-weight-bold"><?php echo get_string('session_course', 'local_tm_course'); ?> *</label>
            <select name="courseid" class="form-control" required <?php echo (empty($courses) && !$id) ? 'disabled' : ''; ?>>
                <option value="0"><?php echo get_string('choosedots'); ?></option>
                <?php foreach ($courses as $cid => $cname):
                    $defdur = $course_duration_map[(int) $cid] ?? 8.0; ?>
                    <option value="<?php echo $cid; ?>"
                            data-default-hours="<?php echo s(format_float($defdur, 2, true)); ?>"
                        <?php echo ((int) $v->courseid === (int) $cid) ? 'selected' : ''; ?>>
                        <?php echo s($cname); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 mb-2">
            <label class="font-weight-bold"><?php echo get_string('session_teaching_language', 'local_tm_course'); ?></label>
            <select name="teaching_language" class="form-control">
                <option value="<?php echo s(session_manager::LANG_ENGLISH); ?>" <?php echo $v->teaching_language === session_manager::LANG_ENGLISH ? 'selected' : ''; ?>>
                    <?php echo get_string('teaching_language_english', 'local_tm_course'); ?>
                </option>
                <option value="<?php echo s(session_manager::LANG_ZH_TW); ?>" <?php echo $v->teaching_language === session_manager::LANG_ZH_TW ? 'selected' : ''; ?>>
                    <?php echo get_string('teaching_language_zh_tw', 'local_tm_course'); ?>
                </option>
            </select>
        </div>
        <div class="col-md-4 mb-2">
            <label class="font-weight-bold"><?php echo get_string('session_delivery_mode', 'local_tm_course'); ?></label>
            <select name="delivery_mode" id="delivery_mode" class="form-control">
                <option value="<?php echo s(session_manager::DELIVERY_ONSITE); ?>" <?php echo $v->delivery_mode === session_manager::DELIVERY_ONSITE ? 'selected' : ''; ?>>
                    <?php echo get_string('delivery_mode_onsite', 'local_tm_course'); ?>
                </option>
                <option value="<?php echo s(session_manager::DELIVERY_ONLINE); ?>" <?php echo $v->delivery_mode === session_manager::DELIVERY_ONLINE ? 'selected' : ''; ?>>
                    <?php echo get_string('delivery_mode_online', 'local_tm_course'); ?>
                </option>
            </select>
        </div>
    </div>

    <div class="mb-2">
        <label><?php echo get_string('session_description', 'local_tm_course'); ?></label>
        <textarea name="description" class="form-control" rows="3"><?php echo s($v->description); ?></textarea>
    </div>

    <div class="mb-2">
        <label class="font-weight-bold"><?php echo get_string('session_classroom', 'local_tm_course'); ?> *</label>
        <select name="classroomid" id="classroomid" class="form-control">
            <?php if ($legacy): ?>
            <option value="0" <?php echo $v->classroomid === 0 ? 'selected' : ''; ?>>
                <?php echo get_string('classroom_legacy_option', 'local_tm_course'); ?>
            </option>
            <?php endif; ?>
            <?php foreach ($classroomrows as $room): ?>
                <option value="<?php echo (int) $room->id; ?>"
                        data-tables="<?php echo classroom_manager::table_count($room); ?>"
                        data-label="<?php echo s(classroom_manager::session_location_label($room)); ?>"
                    <?php echo ((int) $v->classroomid === (int) $room->id) ? 'selected' : ''; ?>>
                    <?php echo s($room->name); ?>
                    <?php if (!empty(trim($room->location ?? ''))): ?>
                        — <?php echo s($room->location); ?>
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="tm-form-hint"><?php echo get_string('session_classroom_help', 'local_tm_course'); ?></div>
    </div>
    <div class="mb-2" id="meeting-link-wrap">
        <label class="font-weight-bold"><?php echo get_string('session_meeting_link', 'local_tm_course'); ?></label>
        <input type="url" name="meeting_link" id="meeting_link" class="form-control" maxlength="1000"
               value="<?php echo s($v->meeting_link); ?>" placeholder="https://...">
    </div>
</div>

<div class="tm-form-section mb-3">
    <div class="section-title">🕐 <?php echo get_string('session_section_schedule', 'local_tm_course'); ?></div>
    <div class="mb-2">
        <label class="font-weight-bold"><?php echo get_string('session_schedule_mode', 'local_tm_course'); ?></label>
        <select name="schedule_time_mode" id="schedule_time_mode" class="form-control" style="max-width:28rem">
            <option value="1" <?php echo (int) $v->schedule_time_mode === 1 ? 'selected' : ''; ?>>
                <?php echo get_string('session_schedule_manual', 'local_tm_course'); ?>
            </option>
            <option value="0" <?php echo (int) $v->schedule_time_mode === 0 ? 'selected' : ''; ?>>
                <?php echo get_string('session_schedule_auto', 'local_tm_course'); ?>
            </option>
        </select>
        <div class="tm-form-hint"><?php echo get_string('session_schedule_mode_help', 'local_tm_course'); ?></div>
    </div>
    <div class="row">
        <div class="col-lg-4 col-md-6 mb-2">
            <label class="font-weight-bold"><?php echo get_string('session_startdate', 'local_tm_course'); ?> *</label>
            <input type="date" name="start_date" id="start_date" class="form-control mb-1" required
                   value="<?php echo s($v->start_date); ?>">
            <div class="d-flex flex-wrap align-items-center gap-1">
                <select name="start_hour" id="start_hour" class="form-control" style="width:auto;min-width:5rem" required>
                    <?php for ($h = 0; $h < 24; $h++): ?>
                    <option value="<?php echo $h; ?>" <?php echo (int) $v->start_hour === $h ? 'selected' : ''; ?>><?php echo sprintf('%02d', $h); ?></option>
                    <?php endfor; ?>
                </select>
                <span>:</span>
                <select name="start_minute" id="start_minute" class="form-control" style="width:auto;min-width:4.5rem" required>
                    <option value="0" <?php echo (int) $v->start_minute === 0 ? 'selected' : ''; ?>>00</option>
                    <option value="30" <?php echo (int) $v->start_minute === 30 ? 'selected' : ''; ?>>30</option>
                </select>
            </div>
            <div class="mt-1" id="tm-chain-start-wrap" style="display:none">
                <button type="button" class="btn btn-sm btn-outline-primary" id="tm-chain-start-btn">
                    <?php echo get_string('session_chain_after_previous', 'local_tm_course'); ?>
                </button>
                <div class="tm-form-hint"><?php echo get_string('session_chain_after_previous_help', 'local_tm_course'); ?></div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-2" id="tm-end-fields">
            <label class="font-weight-bold"><?php echo get_string('session_enddate', 'local_tm_course'); ?> *</label>
            <input type="date" name="end_date" id="end_date" class="form-control mb-1" required
                   value="<?php echo s($v->end_date); ?>">
            <div class="d-flex flex-wrap align-items-center gap-1">
                <select name="end_hour" id="end_hour" class="form-control" style="width:auto;min-width:5rem" required>
                    <?php for ($h = 0; $h < 24; $h++): ?>
                    <option value="<?php echo $h; ?>" <?php echo (int) $v->end_hour === $h ? 'selected' : ''; ?>><?php echo sprintf('%02d', $h); ?></option>
                    <?php endfor; ?>
                </select>
                <span>:</span>
                <select name="end_minute" id="end_minute" class="form-control" style="width:auto;min-width:4.5rem" required>
                    <option value="0" <?php echo (int) $v->end_minute === 0 ? 'selected' : ''; ?>>00</option>
                    <option value="30" <?php echo (int) $v->end_minute === 30 ? 'selected' : ''; ?>>30</option>
                </select>
            </div>
            <div class="tm-form-hint"><?php echo get_string('session_manual_end_hint', 'local_tm_course'); ?></div>
        </div>
        <div class="col-lg-4 col-md-12 mb-2">
            <label class="font-weight-bold"><?php echo get_string('session_duration_computed', 'local_tm_course'); ?></label>
            <div id="tm-duration-preview" class="p-2 border rounded bg-light font-weight-bold" style="font-size:1.1rem;color:var(--tm-blue)">
                —
            </div>
            <div class="tm-form-hint"><?php echo get_string('session_duration_computed_help', 'local_tm_course'); ?></div>
        </div>
    </div>
</div>

<div class="tm-form-section mb-3">
    <div class="section-title">🪑 <?php echo get_string('session_section_capacity', 'local_tm_course'); ?></div>
    <div class="tm-alert tm-alert-info mb-2"><?php echo get_string('desks_info', 'local_tm_course'); ?></div>
    <div id="capacity-edit-wrap" class="row mt-2">
        <div class="col-md-3 mb-2">
            <label class="font-weight-bold"><?php echo get_string('session_desks', 'local_tm_course'); ?></label>
            <input type="number" id="num_desks" name="num_desks" class="form-control" min="1" max="99"
                   value="<?php echo (int) $v->num_desks; ?>">
        </div>
        <div class="col-md-3 mb-2">
            <label class="font-weight-bold"><?php echo get_string('session_persons_per_desk', 'local_tm_course'); ?></label>
            <input type="number" id="ppd" name="persons_per_desk" class="form-control" min="1" max="3"
                   value="<?php echo (int) $v->persons_per_desk; ?>">
        </div>
        <div class="col-md-6 mb-2">
            <label><?php echo get_string('session_total_capacity', 'local_tm_course'); ?></label>
            <div id="capacity-preview" class="font-weight-bold" style="font-size:1.3rem; color:var(--tm-blue)">
                <?php echo (int) $v->num_desks * (int) $v->persons_per_desk; ?>
                <?php echo get_string('session_capacity_persons_suffix', 'local_tm_course'); ?>
            </div>
            <div class="tm-form-hint"><?php echo get_string('session_capacity_formula', 'local_tm_course'); ?></div>
        </div>
    </div>
    <div id="capacity-disabled-hint" class="tm-alert tm-alert-info mt-2" style="display:none">
        <?php echo get_string('session_capacity_disabled_online', 'local_tm_course'); ?>
    </div>
    <div class="tm-form-hint mt-1">
        <?php echo get_string('session_auto_formula_note', 'local_tm_course'); ?>
    </div>
</div>

<div class="tm-form-section mb-3">
    <div class="section-title">📝 <?php echo get_string('session_section_policy', 'local_tm_course'); ?></div>
    <div class="row">
        <div class="col-md-6 mb-2">
            <label class="font-weight-bold"><?php echo get_string('session_approval_mode', 'local_tm_course'); ?></label>
            <select name="approval_mode" class="form-control">
                <option value="1" <?php echo (int) $v->approval_mode === 1 ? 'selected' : ''; ?>>
                    <?php echo get_string('session_approval_manual', 'local_tm_course'); ?>
                </option>
                <option value="0" <?php echo (int) $v->approval_mode === 0 ? 'selected' : ''; ?>>
                    <?php echo get_string('session_approval_auto', 'local_tm_course'); ?>
                </option>
            </select>
        </div>
        <div class="col-md-6 mb-2">
            <label class="font-weight-bold"><?php echo get_string('session_status', 'local_tm_course'); ?></label>
            <select name="status" class="form-control">
                <option value="<?php echo (int) session_manager::STATUS_OPEN; ?>" <?php echo (int) $v->status === (int) session_manager::STATUS_OPEN ? 'selected' : ''; ?>>
                    <?php echo get_string('session_status_open', 'local_tm_course'); ?>
                </option>
                <option value="<?php echo (int) session_manager::STATUS_CLOSED; ?>" <?php echo (int) $v->status === (int) session_manager::STATUS_CLOSED ? 'selected' : ''; ?>>
                    <?php echo get_string('session_status_closed', 'local_tm_course'); ?>
                </option>
            </select>
        </div>
        <div class="col-md-12 mb-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="auto_close_exempt" id="auto_close_exempt" value="1"
                    <?php echo !empty($v->auto_close_exempt) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="auto_close_exempt">
                    <?php echo get_string('session_auto_close_exempt', 'local_tm_course'); ?>
                </label>
            </div>
            <div class="tm-form-hint"><?php echo get_string('session_auto_close_exempt_desc', 'local_tm_course'); ?></div>
        </div>
    </div>
</div>

<div class="tm-form-section tm-form-section-prerequisite mb-3">
    <div class="section-title">📋 <?php echo get_string('session_prerequisite', 'local_tm_course'); ?></div>
    <div class="tm-form-hint mb-2"><?php echo get_string('session_prerequisite_rules_hint', 'local_tm_course'); ?></div>
            <?php if (!$id): ?>
            <div class="tm-form-hint mb-2 text-muted" id="tm-prereq-from-mapping-hint">
                <?php echo get_string('session_prerequisite_from_mapping_hint', 'local_tm_course'); ?>
            </div>
            <?php elseif (!empty($sessioninheritsprereqdefaults)): ?>
            <div class="tm-alert tm-alert-info mb-2 py-2 small">
                <?php echo get_string('session_prerequisite_inherit_mapping_hint', 'local_tm_course'); ?>
            </div>
            <?php endif; ?>
            <div class="form-inline mb-2">
                <label class="mr-2"><?php echo get_string('session_prerequisite_operator', 'local_tm_course'); ?></label>
                <select name="prerequisite_operator" id="tm-prereq-operator" class="form-control form-control-sm">
                    <option value="and" <?php echo ($prereqrulestate['operator'] === prerequisite_manager::OPERATOR_AND) ? 'selected' : ''; ?>>
                        <?php echo get_string('session_prerequisite_operator_and', 'local_tm_course'); ?>
                    </option>
                    <option value="or" <?php echo ($prereqrulestate['operator'] === prerequisite_manager::OPERATOR_OR) ? 'selected' : ''; ?>>
                        <?php echo get_string('session_prerequisite_operator_or', 'local_tm_course'); ?>
                    </option>
                </select>
            </div>
            <p id="tm-prereq-empty-state" class="tm-prereq-empty-state text-muted small mb-2"<?php echo !empty($prereqrulestate['rules'] ?? []) ? ' hidden' : ''; ?>>
                <?php echo get_string('session_prerequisite_none', 'local_tm_course'); ?>
            </p>
            <div id="tm-prereq-rules-list" class="tm-prereq-rules-list">
                <details id="tm-prereq-rule-prototype" class="tm-prereq-rule-prototype tm-prereq-rule-panel" hidden>
                    <summary class="tm-prereq-rule-summary">
                        <span class="tm-prereq-rule-summary-label">—</span>
                        <button type="button" class="btn btn-sm btn-outline-danger tm-prereq-remove" disabled aria-label="<?php echo get_string('delete', 'core'); ?>">&times;</button>
                    </summary>
                    <div class="tm-prereq-rule-body">
                        <div class="row">
                            <div class="col-md-4 mb-2">
                                <label class="small text-muted d-block"><?php echo get_string('session_prerequisite_course_col', 'local_tm_course'); ?></label>
                                <select class="form-control form-control-sm tm-prereq-course" disabled>
                                    <option value="0">—</option>
                                    <?php foreach ($prereqcourses as $cid => $cname): ?>
                                        <option value="<?php echo (int)$cid; ?>"><?php echo s($cname); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-2">
                                <label class="small text-muted d-block"><?php echo get_string('session_prerequisite_verify_col', 'local_tm_course'); ?></label>
                                <select class="form-control form-control-sm tm-prereq-verify" disabled>
                                    <option value="course"><?php echo get_string('session_prerequisite_verify_course', 'local_tm_course'); ?></option>
                                    <option value="activities"><?php echo get_string('session_prerequisite_verify_activities', 'local_tm_course'); ?></option>
                                    <option value="grades"><?php echo get_string('session_prerequisite_verify_grades', 'local_tm_course'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-2 tm-prereq-activities-cell">
                                <label class="small text-muted d-block"><?php echo get_string('session_prerequisite_activities_col', 'local_tm_course'); ?></label>
                                <select class="form-control form-control-sm tm-prereq-activity-op mb-1" disabled hidden>
                                    <option value="all"><?php echo get_string('session_prerequisite_activity_all', 'local_tm_course'); ?></option>
                                    <option value="any"><?php echo get_string('session_prerequisite_activity_any', 'local_tm_course'); ?></option>
                                </select>
                                <select class="form-control form-control-sm tm-prereq-cmids" multiple size="4" disabled hidden></select>
                                <div class="tm-prereq-grades-wrap" hidden>
                                    <select class="form-control form-control-sm tm-prereq-grade-op mb-1" disabled>
                                        <option value="all"><?php echo get_string('session_prerequisite_grade_all', 'local_tm_course'); ?></option>
                                        <option value="any"><?php echo get_string('session_prerequisite_grade_any', 'local_tm_course'); ?></option>
                                    </select>
                                    <div class="tm-prereq-grade-list"></div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary tm-prereq-grade-add mt-1" disabled>
                                        <?php echo get_string('session_prerequisite_add_grade_condition', 'local_tm_course'); ?>
                                    </button>
                                    <input type="hidden" class="tm-prereq-grade-json" name="prerequisite_rule_grade_json[0]" value="[]" disabled>
                                </div>
                                <span class="text-muted small tm-prereq-activities-hint"><?php echo get_string('session_prerequisite_activities_hint', 'local_tm_course'); ?></span>
                                <span class="text-muted small tm-prereq-grades-hint" hidden><?php echo get_string('session_prerequisite_grades_hint', 'local_tm_course'); ?></span>
                            </div>
                        </div>
                    </div>
                </details>
                    <?php
                    $prereqrules = $prereqrulestate['rules'] ?? [];
                    foreach ($prereqrules as $ridx => $rule):
                        $rcourse = (int)($rule['courseid'] ?? 0);
                        $rverify = (string)($rule['verify_type'] ?? prerequisite_manager::VERIFY_COURSE);
                        $ractop = (string)($rule['activity_operator'] ?? prerequisite_manager::ACTIVITY_ALL);
                        $rgradeop = (string)($rule['grade_operator'] ?? prerequisite_manager::ACTIVITY_ALL);
                        $rcmids = array_map('intval', (array)($rule['cmids'] ?? []));
                        $rgradeconds = (array)($rule['grade_conditions'] ?? []);
                        $isactivities = ($rverify === prerequisite_manager::VERIFY_ACTIVITIES);
                        $isgrades = ($rverify === prerequisite_manager::VERIFY_GRADES);
                        $summarycourse = ($rcourse > 0 && isset($prereqcourses[$rcourse])) ? $prereqcourses[$rcourse] : '—';
                        $summaryverify = $prereqverifylabels[$rverify] ?? $prereqverifylabels[prerequisite_manager::VERIFY_COURSE];
                        $summarylabel = $summarycourse . ' · ' . $summaryverify;
                    ?>
                    <details class="tm-prereq-rule-panel tm-prereq-rule-row" data-row-index="<?php echo (int)$ridx; ?>">
                        <summary class="tm-prereq-rule-summary">
                            <span class="tm-prereq-rule-summary-label"><?php echo s($summarylabel); ?></span>
                            <button type="button" class="btn btn-sm btn-outline-danger tm-prereq-remove" aria-label="<?php echo get_string('delete', 'core'); ?>">&times;</button>
                        </summary>
                        <div class="tm-prereq-rule-body">
                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <label class="small text-muted d-block"><?php echo get_string('session_prerequisite_course_col', 'local_tm_course'); ?></label>
                                    <select name="prerequisite_rule_courseid[]" class="form-control form-control-sm tm-prereq-course">
                                        <option value="0">—</option>
                                        <?php foreach ($prereqcourses as $cid => $cname): ?>
                                            <option value="<?php echo (int)$cid; ?>" <?php echo ($rcourse === (int)$cid) ? 'selected' : ''; ?>>
                                                <?php echo s($cname); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="small text-muted d-block"><?php echo get_string('session_prerequisite_verify_col', 'local_tm_course'); ?></label>
                                    <select name="prerequisite_rule_verify_type[]" class="form-control form-control-sm tm-prereq-verify">
                                        <option value="course" <?php echo (!$isactivities && !$isgrades) ? 'selected' : ''; ?>>
                                            <?php echo get_string('session_prerequisite_verify_course', 'local_tm_course'); ?>
                                        </option>
                                        <option value="activities" <?php echo $isactivities ? 'selected' : ''; ?>>
                                            <?php echo get_string('session_prerequisite_verify_activities', 'local_tm_course'); ?>
                                        </option>
                                        <option value="grades" <?php echo $isgrades ? 'selected' : ''; ?>>
                                            <?php echo get_string('session_prerequisite_verify_grades', 'local_tm_course'); ?>
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-2 tm-prereq-activities-cell">
                                    <label class="small text-muted d-block"><?php echo get_string('session_prerequisite_activities_col', 'local_tm_course'); ?></label>
                                    <select name="prerequisite_rule_activity_operator[]" class="form-control form-control-sm tm-prereq-activity-op mb-1" <?php echo $isactivities ? '' : 'hidden'; ?>>
                                        <option value="all" <?php echo ($ractop !== prerequisite_manager::ACTIVITY_ANY) ? 'selected' : ''; ?>>
                                            <?php echo get_string('session_prerequisite_activity_all', 'local_tm_course'); ?>
                                        </option>
                                        <option value="any" <?php echo ($ractop === prerequisite_manager::ACTIVITY_ANY) ? 'selected' : ''; ?>>
                                            <?php echo get_string('session_prerequisite_activity_any', 'local_tm_course'); ?>
                                        </option>
                                    </select>
                                    <select name="prerequisite_rule_cmids[<?php echo (int)$ridx; ?>][]" class="form-control form-control-sm tm-prereq-cmids" multiple size="4" <?php echo $isactivities ? '' : 'hidden'; ?>
                                        data-selected="<?php echo s(json_encode(array_values($rcmids))); ?>"></select>
                                    <div class="tm-prereq-grades-wrap" <?php echo $isgrades ? '' : 'hidden'; ?>>
                                        <select name="prerequisite_rule_grade_operator[]" class="form-control form-control-sm tm-prereq-grade-op mb-1" <?php echo $isgrades ? '' : 'disabled'; ?>>
                                            <option value="all" <?php echo ($rgradeop !== prerequisite_manager::ACTIVITY_ANY) ? 'selected' : ''; ?>>
                                                <?php echo get_string('session_prerequisite_grade_all', 'local_tm_course'); ?>
                                            </option>
                                            <option value="any" <?php echo ($rgradeop === prerequisite_manager::ACTIVITY_ANY) ? 'selected' : ''; ?>>
                                                <?php echo get_string('session_prerequisite_grade_any', 'local_tm_course'); ?>
                                            </option>
                                        </select>
                                        <div class="tm-prereq-grade-list" data-conditions="<?php echo s(json_encode(array_values($rgradeconds))); ?>"></div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary tm-prereq-grade-add mt-1" <?php echo $isgrades ? '' : 'disabled'; ?>>
                                            <?php echo get_string('session_prerequisite_add_grade_condition', 'local_tm_course'); ?>
                                        </button>
                                        <input type="hidden" class="tm-prereq-grade-json" name="prerequisite_rule_grade_json[<?php echo (int)$ridx; ?>]"
                                            value="<?php echo s(json_encode(array_values($rgradeconds))); ?>">
                                    </div>
                                    <span class="text-muted small tm-prereq-activities-hint" <?php echo ($isactivities || $isgrades) ? 'hidden' : ''; ?>>
                                        <?php echo get_string('session_prerequisite_activities_hint', 'local_tm_course'); ?>
                                    </span>
                                    <span class="text-muted small tm-prereq-grades-hint" <?php echo $isgrades ? '' : 'hidden'; ?>>
                                        <?php echo get_string('session_prerequisite_grades_hint', 'local_tm_course'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </details>
                    <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-secondary mt-2" id="tm-prereq-add-rule">
                <?php echo get_string('session_prerequisite_add_rule', 'local_tm_course'); ?>
            </button>
</div>

<?php if ($id): ?>
<div class="tm-form-section mb-3" style="border-left-color:var(--tm-green)">
    <div class="section-title" style="color:var(--tm-green)">👥 <?php echo get_string('admin_batch_enrol_section', 'local_tm_course'); ?></div>
    <div class="tm-form-hint mb-2"><?php echo get_string('admin_batch_enrol_section_desc', 'local_tm_course'); ?></div>
    <a class="btn btn-sm btn-tm-primary"
       href="<?php echo (new moodle_url('/local/tm_course/batch_enrol.php', ['sessionid' => (int)$id]))->out(); ?>">
        <?php echo get_string('admin_batch_enrol_open', 'local_tm_course'); ?>
    </a>
</div>
<?php endif; ?>

<?php if ($batch): ?>
<div class="tm-form-section mb-3" style="border-left-color:var(--tm-green)">
    <div class="section-title" style="color:var(--tm-green)">⟳ <?php echo get_string('batch_section_title', 'local_tm_course'); ?></div>
    <div class="row">
        <div class="col-md-4 mb-2">
            <label><?php echo get_string('batch_repeat_type', 'local_tm_course'); ?></label>
            <select name="repeat_type" class="form-control">
                <option value="0"><?php echo get_string('batch_repeat_none', 'local_tm_course'); ?></option>
                <option value="1"><?php echo get_string('batch_repeat_weekly', 'local_tm_course'); ?></option>
                <option value="2"><?php echo get_string('batch_repeat_monthly', 'local_tm_course'); ?></option>
            </select>
        </div>
        <div class="col-md-4 mb-2">
            <label><?php echo get_string('batch_repeat_count', 'local_tm_course'); ?></label>
            <input type="number" name="repeat_count" class="form-control" min="1" max="52" value="1">
        </div>
    </div>
</div>
<?php endif; ?>

<div class="d-flex gap-2 mt-3">
    <button type="submit" class="btn btn-tm-success px-4" <?php echo (empty($classroomrows) && !$legacy) ? 'disabled' : ''; ?>>
        <?php echo get_string('save_changes', 'local_tm_course'); ?>
    </button>
    <a href="<?php echo (new moodle_url('/local/tm_course/admin/sessions.php'))->out(); ?>" class="btn btn-secondary">
        <?php echo get_string('cancel', 'local_tm_course'); ?>
    </a>
</div>

</form>
</div>
</div>

<script>
(function() {
    var hoursSuffix = <?php echo json_encode(get_string('hours_suffix', 'local_tm_course')); ?>;
    var sel = document.getElementById('classroomid');
    var desksInput = document.getElementById('num_desks');
    var ppdInput = document.getElementById('ppd');
    var preview = document.getElementById('capacity-preview');
    var deliverySel = document.getElementById('delivery_mode');
    var meetingWrap = document.getElementById('meeting-link-wrap');
    var meetingInput = document.getElementById('meeting_link');
    var capWrap = document.getElementById('capacity-edit-wrap');
    var capDisabledHint = document.getElementById('capacity-disabled-hint');

    function defaultCapacityByClassroomName(name) {
        var n = (name || '').toLowerCase();
        if (n.indexOf('維修') >= 0 || n.indexOf('repair') >= 0 || n.indexOf('maintenance') >= 0) {
            return {desks: 2, ppd: 3};
        }
        return {desks: 6, ppd: 3};
    }

    var modeSel = document.getElementById('schedule_time_mode');
    var startDate = document.getElementById('start_date');
    var startHour = document.getElementById('start_hour');
    var startMin = document.getElementById('start_minute');
    var endDate = document.getElementById('end_date');
    var endHour = document.getElementById('end_hour');
    var endMin = document.getElementById('end_minute');
    var durPreview = document.getElementById('tm-duration-preview');
    var courseSel = document.querySelector('[name=courseid]');
    var durationCalcBase = <?php echo json_encode((new moodle_url('/local/tm_course/duration_calc.php', ['sesskey' => sesskey()]))->out(false)); ?>;
    var chainStartBase = <?php echo json_encode((new moodle_url('/local/tm_course/chain_start_suggest.php', ['sesskey' => sesskey()]))->out(false)); ?>;
    var sessionEditId = <?php echo (int) $id; ?>;
    var txtChainNone = <?php echo json_encode(get_string('session_chain_after_previous_none', 'local_tm_course')); ?>;
    var autoTeachingHours = null;
    var autoCalcSeq = 0;
    var autoFetchTimer = null;

    function combineLocal(dateStr, h, m) {
        var p = (dateStr || '').split('-');
        if (p.length !== 3) {
            return NaN;
        }
        var t = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10),
            parseInt(h, 10), parseInt(m, 10), 0, 0);
        return t.getTime() / 1000;
    }

    function getCurrentCourseId() {
        var opt = courseSel && courseSel.options[courseSel.selectedIndex];
        return opt ? (parseInt(opt.value, 10) || 0) : 0;
    }

    function updateDurationPreview() {
        if (!durPreview || !modeSel) {
            return;
        }
        var mode = parseInt(modeSel.value, 10);
        if (mode === 0) {
            if (autoTeachingHours !== null && !isNaN(autoTeachingHours)) {
                durPreview.textContent = Number(autoTeachingHours).toFixed(2) + ' ' + hoursSuffix;
            } else {
                durPreview.textContent = '—';
            }
            return;
        }
        var st = combineLocal(startDate.value, startHour.value, startMin.value);
        var en = combineLocal(endDate.value, endHour.value, endMin.value);
        if (!isNaN(st) && !isNaN(en) && en > st) {
            durPreview.textContent = ((en - st) / 3600).toFixed(2) + ' ' + hoursSuffix;
        } else {
            durPreview.textContent = '—';
        }
    }

    function applyAutoFromServer() {
        if (parseInt(modeSel.value, 10) !== 0) {
            return;
        }
        var cid = getCurrentCourseId();
        if (!cid || !startDate.value) {
            autoTeachingHours = null;
            updateDurationPreview();
            return;
        }
        autoTeachingHours = null;
        updateDurationPreview();
        var seq = ++autoCalcSeq;
        var qs = '&courseid=' + encodeURIComponent(String(cid))
            + '&delivery_mode=' + encodeURIComponent(deliverySel ? deliverySel.value : '')
            + '&start_date=' + encodeURIComponent(startDate.value)
            + '&start_hour=' + encodeURIComponent(startHour.value)
            + '&start_minute=' + encodeURIComponent(startMin.value)
            + '&respect_start=1';
        fetch(durationCalcBase + qs, {credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(j) {
                if (seq !== autoCalcSeq) {
                    return;
                }
                if (!j || !j.start || !j.end) {
                    autoTeachingHours = null;
                    updateDurationPreview();
                    return;
                }
                startDate.value = j.start.date || startDate.value;
                startHour.value = String(j.start.hour);
                startMin.value = String(j.start.minute || 0);
                endDate.value = j.end.date || endDate.value;
                endHour.value = String(j.end.hour);
                endMin.value = String(j.end.minute || 0);
                if (typeof j.total_hours === 'number') {
                    autoTeachingHours = j.total_hours;
                }
                updateDurationPreview();
            })
            .catch(function() {
                if (seq === autoCalcSeq) {
                    autoTeachingHours = null;
                }
                updateDurationPreview();
            });
    }

    function scheduleAutoFromServer() {
        if (parseInt(modeSel.value, 10) !== 0) {
            return;
        }
        if (autoFetchTimer) {
            clearTimeout(autoFetchTimer);
        }
        autoFetchTimer = setTimeout(function() {
            autoFetchTimer = null;
            applyAutoFromServer();
        }, 80);
    }

    function applyScheduleModeUi() {
        var auto = parseInt(modeSel.value, 10) === 0;
        var endBlock = document.getElementById('tm-end-fields');
        if (!endBlock) {
            return;
        }
        endBlock.querySelectorAll('input,select').forEach(function(el) {
            if (auto) {
                el.removeAttribute('required');
                el.disabled = true;
            } else {
                el.disabled = false;
                el.setAttribute('required', 'required');
            }
        });
        endBlock.classList.toggle('text-muted', auto);
        updateDurationPreview();
    }

    if (modeSel) {
        modeSel.addEventListener('change', function() {
            applyScheduleModeUi();
            var chainWrap = document.getElementById('tm-chain-start-wrap');
            var online = deliverySel && deliverySel.value === <?php echo json_encode(session_manager::DELIVERY_ONLINE); ?>;
            if (chainWrap) {
                chainWrap.style.display = (!online && parseInt(modeSel.value, 10) === 0) ? 'block' : 'none';
            }
            if (parseInt(modeSel.value, 10) === 0) {
                applyAutoFromServer();
            }
        });
        applyScheduleModeUi();
    }
    function bindStartField(el) {
        if (!el) {
            return;
        }
        el.addEventListener('change', function() {
            applyAutoFromServer();
            updateDurationPreview();
        });
        el.addEventListener('input', function() {
            scheduleAutoFromServer();
            updateDurationPreview();
        });
    }
    bindStartField(startDate);
    bindStartField(startHour);
    bindStartField(startMin);
    [endDate, endHour, endMin].forEach(function(el) {
        if (el) {
            el.addEventListener('change', updateDurationPreview);
            el.addEventListener('input', updateDurationPreview);
        }
    });
    if (courseSel) {
        courseSel.addEventListener('change', function() {
            applyAutoFromServer();
            updateDurationPreview();
        });
    }

    function updateCapacityPreview() {
        var d = Math.max(1, parseInt(desksInput.value || '1', 10));
        var p = Math.max(1, parseInt(ppdInput.value || '1', 10));
        preview.textContent = (d * p) + ' ' + <?php echo json_encode(get_string('session_capacity_persons_suffix', 'local_tm_course')); ?>;
    }

    function applyClassroomDefaults() {
        if (!sel || !sel.options[sel.selectedIndex]) return;
        var opt = sel.options[sel.selectedIndex];
        var cid = parseInt(opt.value, 10);
        if (!cid) {
            return;
        }
        var defaults = defaultCapacityByClassroomName(opt.textContent || '');
        if (!desksInput.value || parseInt(desksInput.value, 10) <= 0) {
            desksInput.value = String(defaults.desks);
        }
        if (!ppdInput.value || parseInt(ppdInput.value, 10) <= 0) {
            ppdInput.value = String(defaults.ppd);
        }
        updateCapacityPreview();
    }

    function applyDeliveryModeUi() {
        var online = deliverySel && deliverySel.value === <?php echo json_encode(session_manager::DELIVERY_ONLINE); ?>;
        if (modeSel) {
            modeSel.disabled = online;
        }
        if (meetingWrap) {
            meetingWrap.style.display = online ? 'block' : 'none';
        }
        if (meetingInput) {
            if (online) {
                meetingInput.setAttribute('required', 'required');
            } else {
                meetingInput.removeAttribute('required');
            }
        }
        if (capWrap) {
            capWrap.style.opacity = online ? '.55' : '1';
            capWrap.querySelectorAll('input').forEach(function(el) {
                if (online) {
                    el.setAttribute('readonly', 'readonly');
                } else {
                    el.removeAttribute('readonly');
                }
            });
        }
        if (capDisabledHint) {
            capDisabledHint.style.display = online ? 'block' : 'none';
        }
        var chainWrap = document.getElementById('tm-chain-start-wrap');
        if (chainWrap) {
            chainWrap.style.display = (!online && parseInt(modeSel.value, 10) === 0) ? 'block' : 'none';
        }
        applyScheduleModeUi();
        if (!online && parseInt(modeSel.value, 10) === 0) {
            applyAutoFromServer();
        } else {
            updateDurationPreview();
        }
    }

    if (sel) {
        sel.addEventListener('change', applyClassroomDefaults);
        applyClassroomDefaults();
    }
    [desksInput, ppdInput].forEach(function(el) {
        if (el) {
            el.addEventListener('input', updateCapacityPreview);
            el.addEventListener('change', updateCapacityPreview);
        }
    });
    function applyChainStartFromServer() {
        var classroomVal = sel ? parseInt(sel.value, 10) : 0;
        if (!classroomVal || !startDate || !startDate.value) {
            alert(txtChainNone);
            return;
        }
        var qs = '&classroomid=' + encodeURIComponent(String(classroomVal))
            + '&start_date=' + encodeURIComponent(startDate.value)
            + '&ignore_session_id=' + encodeURIComponent(String(sessionEditId));
        fetch(chainStartBase + qs, {credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(j) {
                if (!j || !j.start) {
                    alert(txtChainNone);
                    return;
                }
                if (j.start.date) {
                    startDate.value = j.start.date;
                }
                startHour.value = String(j.start.hour);
                startMin.value = String(j.start.minute || 0);
                if (parseInt(modeSel.value, 10) === 0) {
                    applyAutoFromServer();
                } else {
                    updateDurationPreview();
                }
            })
            .catch(function() {
                alert(txtChainNone);
            });
    }

    var chainBtn = document.getElementById('tm-chain-start-btn');
    if (chainBtn) {
        chainBtn.addEventListener('click', applyChainStartFromServer);
    }

    if (deliverySel) {
        deliverySel.addEventListener('change', applyDeliveryModeUi);
        applyDeliveryModeUi();
    } else if (modeSel && parseInt(modeSel.value, 10) === 0) {
        applyAutoFromServer();
    } else {
        updateDurationPreview();
    }

    function enableScheduleFieldsForPost() {
        if (modeSel) {
            modeSel.disabled = false;
        }
        var endBlock = document.getElementById('tm-end-fields');
        if (endBlock) {
            endBlock.querySelectorAll('input,select').forEach(function(el) {
                el.disabled = false;
            });
        }
    }

    document.getElementById('tm-session-form').addEventListener('submit', function(e) {
        if (typeof window.tmPrereqSyncBeforeSubmit === 'function') {
            window.tmPrereqSyncBeforeSubmit();
        }
        var errs = [];
        var classroomVal = sel ? parseInt(sel.value, 10) : 0;
        var isOnline = deliverySel && deliverySel.value === <?php echo json_encode(session_manager::DELIVERY_ONLINE); ?>;
        <?php if (!$legacy): ?>
        if (!isOnline && !classroomVal) {
            errs.push(<?php echo json_encode(get_string('error_classroom_required', 'local_tm_course')); ?>);
        }
        <?php endif; ?>
        if (isOnline && meetingInput && !(meetingInput.value || '').trim()) {
            errs.push(<?php echo json_encode(get_string('error_meeting_link_required', 'local_tm_course')); ?>);
        }
        var st = combineLocal(startDate.value, startHour.value, startMin.value);
        var en = combineLocal(endDate.value, endHour.value, endMin.value);
        if (isNaN(st) || isNaN(en) || en <= st) {
            errs.push(<?php echo json_encode(get_string('error_end_after_start', 'local_tm_course')); ?>);
        }
        if (errs.length) {
            e.preventDefault();
            alert(errs.join('\n'));
            return;
        }
        enableScheduleFieldsForPost();
    });

    (function initPrereqRules() {
        var rulesList = document.getElementById('tm-prereq-rules-list');
        var proto = document.getElementById('tm-prereq-rule-prototype');
        var addBtn = document.getElementById('tm-prereq-add-rule');
        var activitiesBase = <?php echo json_encode((new moodle_url('/local/tm_course/prerequisite_activities.php', ['sesskey' => sesskey()]))->out(false)); ?>;
        if (!rulesList || !proto || !addBtn) {
            return;
        }

        function syncPrereqEmptyState() {
            var emptyEl = document.getElementById('tm-prereq-empty-state');
            var count = rulesList.querySelectorAll('.tm-prereq-rule-row').length;
            if (emptyEl) {
                emptyEl.hidden = count > 0;
            }
        }

        function nextRowIndex() {
            var max = -1;
            rulesList.querySelectorAll('.tm-prereq-rule-row').forEach(function (panel) {
                var idx = parseInt(panel.getAttribute('data-row-index') || '-1', 10);
                if (!isNaN(idx) && idx > max) {
                    max = idx;
                }
            });
            return max + 1;
        }

        function updateRuleSummary(row) {
            var label = row.querySelector('.tm-prereq-rule-summary-label');
            var courseSel = row.querySelector('.tm-prereq-course');
            var verify = row.querySelector('.tm-prereq-verify');
            if (!label) {
                return;
            }
            var courseText = '—';
            if (courseSel && courseSel.options.length && courseSel.selectedIndex >= 0) {
                courseText = (courseSel.options[courseSel.selectedIndex].textContent || '').trim() || '—';
            }
            var verifyText = '—';
            if (verify && verify.options.length && verify.selectedIndex >= 0) {
                verifyText = (verify.options[verify.selectedIndex].textContent || '').trim() || '—';
            }
            label.textContent = courseText + ' · ' + verifyText;
        }

        var prereqStr = <?php echo json_encode([
            'choose' => get_string('choosedots'),
            'gradeMinLabel' => get_string('session_prerequisite_grade_min_label', 'local_tm_course'),
            'gradeMaxLabel' => get_string('session_prerequisite_grade_max_label', 'local_tm_course'),
            'addGradeCondition' => get_string('session_prerequisite_add_grade_condition', 'local_tm_course'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

        function gradeConditionRowHtml(cond) {
            cond = cond || {};
            var useMin = cond.min !== null && cond.min !== undefined && cond.min !== '';
            var useMax = cond.max !== null && cond.max !== undefined && cond.max !== '';
            if (!useMin && !useMax) {
                useMin = true;
            }
            var minVal = useMin ? String(cond.min != null ? cond.min : 100) : '';
            var maxVal = useMax ? String(cond.max) : '';
            return '<div class="tm-prereq-grade-cond border rounded p-1 mb-1">'
                + '<div class="d-flex flex-wrap align-items-center gap-1">'
                + '<select class="form-control form-control-sm js-grade-cmid" style="min-width:9rem" data-cmid="' + String(cond.cmid || 0) + '"><option value="0">' + prereqStr.choose + '</option></select>'
                + '<label class="mb-0 small"><input type="checkbox" class="js-grade-use-min"' + (useMin ? ' checked' : '') + '> ' + prereqStr.gradeMinLabel + '</label>'
                + '<input type="number" class="form-control form-control-sm js-grade-min" style="width:4.5rem" min="0" max="100" step="0.01" value="' + minVal + '"' + (useMin ? '' : ' disabled') + '>'
                + '<span class="small">%</span>'
                + '<label class="mb-0 small"><input type="checkbox" class="js-grade-use-max"' + (useMax ? ' checked' : '') + '> ' + prereqStr.gradeMaxLabel + '</label>'
                + '<input type="number" class="form-control form-control-sm js-grade-max" style="width:4.5rem" min="0" max="100" step="0.01" value="' + maxVal + '"' + (useMax ? '' : ' disabled') + '>'
                + '<span class="small">%</span>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary js-grade-rm">×</button>'
                + '</div></div>';
        }

        function collectGradeConditionsFromRow(row) {
            var out = [];
            row.querySelectorAll('.tm-prereq-grade-cond').forEach(function (condRow) {
                var cmid = parseInt((condRow.querySelector('.js-grade-cmid') || {}).value, 10) || 0;
                if (cmid <= 0) {
                    return;
                }
                var useMin = condRow.querySelector('.js-grade-use-min');
                var useMax = condRow.querySelector('.js-grade-use-max');
                var minIn = condRow.querySelector('.js-grade-min');
                var maxIn = condRow.querySelector('.js-grade-max');
                var item = {cmid: cmid, min: null, max: null};
                if (useMin && useMin.checked && minIn && minIn.value !== '') {
                    item.min = parseFloat(minIn.value);
                }
                if (useMax && useMax.checked && maxIn && maxIn.value !== '') {
                    item.max = parseFloat(maxIn.value);
                }
                if (item.min === null && item.max === null) {
                    return;
                }
                out.push(item);
            });
            return out;
        }

        function syncGradeJsonField(row) {
            var hidden = row.querySelector('.tm-prereq-grade-json');
            if (hidden) {
                hidden.value = JSON.stringify(collectGradeConditionsFromRow(row));
            }
        }

        function populateGradeCmidSelect(condRow, row) {
            var courseSel = row.querySelector('.tm-prereq-course');
            var cmSel = condRow.querySelector('.js-grade-cmid');
            if (!courseSel || !cmSel) {
                return;
            }
            var courseid = parseInt(courseSel.value, 10) || 0;
            var selected = parseInt(cmSel.getAttribute('data-cmid') || '0', 10) || 0;
            cmSel.innerHTML = '<option value="0">' + prereqStr.choose + '</option>';
            if (!courseid) {
                return;
            }
            fetch(activitiesBase + '&courseid=' + encodeURIComponent(String(courseid)), {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    (j.gradeable || []).forEach(function (a) {
                        var opt = document.createElement('option');
                        opt.value = String(a.cmid);
                        opt.textContent = a.label;
                        if (selected === parseInt(a.cmid, 10)) {
                            opt.selected = true;
                        }
                        cmSel.appendChild(opt);
                    });
                    cmSel.removeAttribute('data-cmid');
                })
                .catch(function () {});
        }

        function bindGradeConditionRow(condRow, row) {
            var useMin = condRow.querySelector('.js-grade-use-min');
            var useMax = condRow.querySelector('.js-grade-use-max');
            var minIn = condRow.querySelector('.js-grade-min');
            var maxIn = condRow.querySelector('.js-grade-max');
            var rmBtn = condRow.querySelector('.js-grade-rm');
            function syncInputs() {
                if (minIn) {
                    minIn.disabled = !(useMin && useMin.checked);
                }
                if (maxIn) {
                    maxIn.disabled = !(useMax && useMax.checked);
                }
                syncGradeJsonField(row);
            }
            if (useMin) {
                useMin.addEventListener('change', syncInputs);
            }
            if (useMax) {
                useMax.addEventListener('change', syncInputs);
            }
            if (minIn) {
                minIn.addEventListener('input', syncInputs);
            }
            if (maxIn) {
                maxIn.addEventListener('input', syncInputs);
            }
            var cmSel = condRow.querySelector('.js-grade-cmid');
            if (cmSel) {
                cmSel.addEventListener('change', syncInputs);
            }
            if (rmBtn) {
                rmBtn.addEventListener('click', function () {
                    var list = row.querySelector('.tm-prereq-grade-list');
                    if (list && list.querySelectorAll('.tm-prereq-grade-cond').length <= 1) {
                        return;
                    }
                    condRow.remove();
                    syncGradeJsonField(row);
                });
            }
            syncInputs();
            populateGradeCmidSelect(condRow, row);
        }

        function initGradeList(row) {
            var list = row.querySelector('.tm-prereq-grade-list');
            if (!list) {
                return;
            }
            var conditions = [];
            try {
                conditions = JSON.parse(list.getAttribute('data-conditions') || '[]');
            } catch (e) {
                conditions = [];
            }
            if (!conditions.length) {
                conditions = [{cmid: 0, min: 100, max: null}];
            }
            list.innerHTML = '';
            conditions.forEach(function (cond) {
                list.insertAdjacentHTML('beforeend', gradeConditionRowHtml(cond));
                bindGradeConditionRow(list.lastElementChild, row);
            });
            list.removeAttribute('data-conditions');
            syncGradeJsonField(row);
        }

        function reindexRowFields() {
            rulesList.querySelectorAll('.tm-prereq-rule-row').forEach(function (panel, i) {
                panel.setAttribute('data-row-index', String(i));
                var cmSel = panel.querySelector('.tm-prereq-cmids');
                if (cmSel) {
                    cmSel.name = 'prerequisite_rule_cmids[' + i + '][]';
                }
                var gradeJson = panel.querySelector('.tm-prereq-grade-json');
                if (gradeJson) {
                    gradeJson.name = 'prerequisite_rule_grade_json[' + i + ']';
                }
            });
        }

        function toggleRuleUi(row) {
            var verify = row.querySelector('.tm-prereq-verify');
            var actOp = row.querySelector('.tm-prereq-activity-op');
            var cmSel = row.querySelector('.tm-prereq-cmids');
            var gradeWrap = row.querySelector('.tm-prereq-grades-wrap');
            var gradeOp = row.querySelector('.tm-prereq-grade-op');
            var gradeAdd = row.querySelector('.tm-prereq-grade-add');
            var actHint = row.querySelector('.tm-prereq-activities-hint');
            var gradeHint = row.querySelector('.tm-prereq-grades-hint');
            var mode = verify ? verify.value : 'course';
            var isAct = mode === 'activities';
            var isGrade = mode === 'grades';
            if (actOp) {
                actOp.hidden = !isAct;
            }
            if (cmSel) {
                cmSel.hidden = !isAct;
            }
            if (gradeWrap) {
                gradeWrap.hidden = !isGrade;
            }
            if (gradeOp) {
                gradeOp.disabled = !isGrade;
            }
            if (gradeAdd) {
                gradeAdd.disabled = !isGrade;
            }
            if (actHint) {
                actHint.hidden = isAct || isGrade;
            }
            if (gradeHint) {
                gradeHint.hidden = !isGrade;
            }
            if (isAct) {
                loadActivitiesForRow(row);
            }
            if (isGrade) {
                var list = row.querySelector('.tm-prereq-grade-list');
                if (list && !list.querySelector('.tm-prereq-grade-cond')) {
                    initGradeList(row);
                } else if (list) {
                    list.querySelectorAll('.tm-prereq-grade-cond').forEach(function (condRow) {
                        populateGradeCmidSelect(condRow, row);
                    });
                }
            }
            updateRuleSummary(row);
        }

        function loadActivitiesForRow(row) {
            var courseSel = row.querySelector('.tm-prereq-course');
            var cmSel = row.querySelector('.tm-prereq-cmids');
            if (!courseSel || !cmSel) {
                return;
            }
            var courseid = parseInt(courseSel.value, 10) || 0;
            var selected = [];
            try {
                selected = JSON.parse(cmSel.getAttribute('data-selected') || '[]');
            } catch (e) {
                selected = [];
            }
            Array.prototype.slice.call(cmSel.options).forEach(function (opt) {
                if (opt.selected) {
                    selected.push(parseInt(opt.value, 10));
                }
            });
            selected = selected.filter(function (v, i, a) {
                return v > 0 && a.indexOf(v) === i;
            });
            cmSel.innerHTML = '';
            if (!courseid) {
                return;
            }
            var url = activitiesBase + '&courseid=' + encodeURIComponent(String(courseid));
            fetch(url, {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    var acts = (j && j.activities) ? j.activities : [];
                    acts.forEach(function (a) {
                        var opt = document.createElement('option');
                        opt.value = String(a.cmid);
                        opt.textContent = a.label;
                        if (selected.indexOf(parseInt(a.cmid, 10)) !== -1) {
                            opt.selected = true;
                        }
                        cmSel.appendChild(opt);
                    });
                    cmSel.removeAttribute('data-selected');
                })
                .catch(function () {});
        }

        function wireRow(row) {
            var courseSel = row.querySelector('.tm-prereq-course');
            var verify = row.querySelector('.tm-prereq-verify');
            var removeBtn = row.querySelector('.tm-prereq-remove');
            var gradeAdd = row.querySelector('.tm-prereq-grade-add');
            if (courseSel) {
                courseSel.addEventListener('change', function () {
                    toggleRuleUi(row);
                });
            }
            if (verify) {
                verify.addEventListener('change', function () {
                    toggleRuleUi(row);
                });
            }
            if (gradeAdd) {
                gradeAdd.addEventListener('click', function () {
                    var list = row.querySelector('.tm-prereq-grade-list');
                    if (!list) {
                        return;
                    }
                    list.insertAdjacentHTML('beforeend', gradeConditionRowHtml({min: 100}));
                    bindGradeConditionRow(list.lastElementChild, row);
                });
            }
            if (removeBtn) {
                removeBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    row.remove();
                    reindexRowFields();
                    syncPrereqEmptyState();
                });
            }
            toggleRuleUi(row);
            updateRuleSummary(row);
        }

        function addRuleRow(rule) {
            var row = proto.cloneNode(true);
            row.id = '';
            row.classList.remove('tm-prereq-rule-prototype');
            row.classList.add('tm-prereq-rule-row');
            row.hidden = false;
            row.removeAttribute('hidden');
            row.removeAttribute('open');
            row.setAttribute('data-row-index', String(nextRowIndex()));
            row.querySelectorAll('select, button').forEach(function (el) {
                el.disabled = false;
            });
            var courseSel = row.querySelector('.tm-prereq-course');
            if (courseSel) {
                courseSel.name = 'prerequisite_rule_courseid[]';
            }
            var verify = row.querySelector('.tm-prereq-verify');
            if (verify) {
                verify.name = 'prerequisite_rule_verify_type[]';
            }
            var actOp = row.querySelector('.tm-prereq-activity-op');
            if (actOp) {
                actOp.name = 'prerequisite_rule_activity_operator[]';
            }
            var cmSel = row.querySelector('.tm-prereq-cmids');
            if (cmSel) {
                cmSel.name = 'prerequisite_rule_cmids[' + row.getAttribute('data-row-index') + '][]';
            }
            var gradeWrap = row.querySelector('.tm-prereq-grades-wrap');
            if (gradeWrap) {
                var gradeOp = gradeWrap.querySelector('.tm-prereq-grade-op');
                if (gradeOp) {
                    gradeOp.name = 'prerequisite_rule_grade_operator[]';
                }
                var gradeJson = gradeWrap.querySelector('.tm-prereq-grade-json');
                if (gradeJson) {
                    gradeJson.name = 'prerequisite_rule_grade_json[' + row.getAttribute('data-row-index') + ']';
                    gradeJson.disabled = false;
                }
                var gradeAdd = gradeWrap.querySelector('.tm-prereq-grade-add');
                if (gradeAdd) {
                    gradeAdd.disabled = false;
                }
            }
            if (rule) {
                if (courseSel && rule.courseid) {
                    courseSel.value = String(rule.courseid);
                }
                if (verify) {
                    if (rule.verify_type === 'activities') {
                        verify.value = 'activities';
                    } else if (rule.verify_type === 'grades') {
                        verify.value = 'grades';
                    } else {
                        verify.value = 'course';
                    }
                }
                if (actOp) {
                    actOp.value = rule.activity_operator === 'any' ? 'any' : 'all';
                }
                if (cmSel && rule.cmids && rule.cmids.length) {
                    cmSel.setAttribute('data-selected', JSON.stringify(rule.cmids));
                }
                if (gradeWrap) {
                    var gradeOp = gradeWrap.querySelector('.tm-prereq-grade-op');
                    if (gradeOp) {
                        gradeOp.value = rule.grade_operator === 'any' ? 'any' : 'all';
                    }
                    var list = gradeWrap.querySelector('.tm-prereq-grade-list');
                    if (list) {
                        list.setAttribute('data-conditions', JSON.stringify(rule.grade_conditions || []));
                    }
                }
            }
            rulesList.appendChild(row);
            wireRow(row);
            reindexRowFields();
            syncPrereqEmptyState();
            if (!rule) {
                row.setAttribute('open', '');
            }
            return row;
        }

        function applyPrereqDefaults(defaults) {
            var opSel = document.getElementById('tm-prereq-operator');
            rulesList.querySelectorAll('.tm-prereq-rule-row').forEach(function (panel) {
                panel.remove();
            });
            if (opSel) {
                opSel.value = (defaults && defaults.operator) ? defaults.operator : 'and';
            }
            var rules = (defaults && defaults.rules) ? defaults.rules : [];
            if (!rules.length) {
                syncPrereqEmptyState();
                return;
            }
            rules.forEach(function (rule) {
                addRuleRow(rule);
            });
            syncPrereqEmptyState();
        }

        addBtn.addEventListener('click', function () {
            addRuleRow(null);
        });

        rulesList.querySelectorAll('.tm-prereq-rule-row').forEach(wireRow);
        reindexRowFields();
        syncPrereqEmptyState();

        window.tmPrereqSyncBeforeSubmit = function () {
            rulesList.querySelectorAll('.tm-prereq-rule-row').forEach(syncGradeJsonField);
        };

        <?php if (!$id): ?>
        var sessionCourseSel = document.querySelector('[name=courseid]');
        var coursePrereqDefaults = <?php echo json_encode($courseprereqdefaultmap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
        if (sessionCourseSel) {
            sessionCourseSel.addEventListener('change', function () {
                var cid = parseInt(sessionCourseSel.value, 10) || 0;
                var defaults = coursePrereqDefaults[cid] || coursePrereqDefaults[String(cid)] || null;
                applyPrereqDefaults(defaults);
            });
        }
        <?php endif; ?>
    })();
})();
</script>

<?php echo $OUTPUT->footer(); ?>
