<?php
/**
 * Admin: Class Preparation page (M3 attendance + M-equipment check)
 * 上課準備事項：出缺勤／便當通知／設備檢查
 * URL: /local/tm_course/admin/class_prep.php?sessionid=N
 *
 * Formerly attendance.php (renamed; old URL still redirects here).
 *
 * @package    local_tm_course
 * @copyright  2024 Techman Robot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/attendance_manager.php');
require_once(__DIR__ . '/../classes/enrolment_manager.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');
require_once(__DIR__ . '/../classes/bento_notification_manager.php');
require_once(__DIR__ . '/../classes/notification_editor_helper.php');
require_once(__DIR__ . '/../classes/equipment_check_manager.php');

use local_tm_course\session_manager;
use local_tm_course\attendance_manager;
use local_tm_course\enrolment_manager;
use local_tm_course\permissions_manager;
use local_tm_course\bento_notification_manager;
use local_tm_course\notification_editor_helper;
use local_tm_course\equipment_check_manager;

require_login();
$ctx = context_system::instance();
if (!permissions_manager::user_can_attendance()) {
    throw new required_capability_exception($ctx, 'local/tm_course:attendance', 'nopermissions', '');
}

$PAGE->set_context($ctx);
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/tm_course/admin/class_prep.php'));
$PAGE->set_title(get_string('nav_class_prep', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');
$PAGE->requires->js('/local/tm_course/admin/attendance_diet.js', true);
$PAGE->requires->js('/local/tm_course/admin/equipment_check.js', true);

$sessionid = required_param('sessionid', PARAM_INT);
$action    = optional_param('action', '', PARAM_ALPHANUMEXT);
$enrolid   = optional_param('enrolid', 0, PARAM_INT);

$session = session_manager::get_session($sessionid);
$issetup = !empty($session->groupid);
$isonsite = ((string) ($session->delivery_mode ?? '') === session_manager::DELIVERY_ONSITE);

$back_url  = new moodle_url('/local/tm_course/admin/class_prep.php', ['sessionid' => $sessionid]);
$sessions_url = new moodle_url('/local/tm_course/admin/sessions.php');

function att_url(string $act, int $sid, int $eid = 0): string {
    $p = ['sessionid' => $sid, 'action' => $act, 'sesskey' => sesskey()];
    if ($eid) {
        $p['enrolid'] = $eid;
    }
    return (new moodle_url('/local/tm_course/admin/class_prep.php', $p))->out();
}

/**
 * Clean one desk's worth of raw POST fields (equip[itemid][status/remark]) into
 * a flat [itemid => ['status' => ..., 'remark' => ...]] array.
 * optional_param_array() can't handle this 2-level nesting, so we walk it manually
 * (same pattern used by settings/course_mapping.php for classroomids[]).
 */
function equipment_clean_raw_items(array $postequip): array {
    $rawitems = [];
    foreach ($postequip as $rawitemid => $fields) {
        $itemid = (int) $rawitemid;
        if ($itemid <= 0 || !is_array($fields)) {
            continue;
        }
        $rawitems[$itemid] = [
            'status' => clean_param((string) ($fields['status'] ?? ''), PARAM_ALPHANUMEXT),
            'remark' => clean_param((string) ($fields['remark'] ?? ''), PARAM_TEXT),
        ];
    }
    return $rawitems;
}

/**
 * Turn cleaned raw item fields into the $results array expected by
 * equipment_check_manager::save_desk_checks().
 */
function equipment_build_results(array $rawitems, array $applicableitems): array {
    $results = [];
    foreach ($applicableitems as $item) {
        $itemid = (int) $item->id;
        $entry = $rawitems[$itemid] ?? [];
        $statusraw = (string) ($entry['status'] ?? '');
        $checkstatus = equipment_check_manager::STATUS_UNSET;
        if ((string) $item->checktype === equipment_check_manager::TYPE_TASK) {
            $checkstatus = ($statusraw === 'done') ? equipment_check_manager::STATUS_NORMAL : equipment_check_manager::STATUS_UNSET;
        } else if ($statusraw === 'normal') {
            $checkstatus = equipment_check_manager::STATUS_NORMAL;
        } else if ($statusraw === 'abnormal') {
            $checkstatus = equipment_check_manager::STATUS_ABNORMAL;
        }
        $results[$itemid] = [
            'checkstatus' => $checkstatus,
            'remark' => (string) ($entry['remark'] ?? ''),
        ];
    }
    return $results;
}

// ---- Handle actions ----
if ($action && confirm_sesskey()) {
    if ($action === 'bento_preview') {
        $isajax = optional_param('ajax', 0, PARAM_INT) === 1;
        if (!$isonsite) {
            if ($isajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'not_onsite'], JSON_UNESCAPED_UNICODE);
                die;
            }
            redirect($back_url);
        }
        $overrides = [
            'extra_emails' => optional_param('bento_recipients', '', PARAM_RAW_TRIMMED),
            'subject_zh_tw' => optional_param('bento_subject', '', PARAM_TEXT),
            'body_zh_tw' => optional_param('bento_body', '', PARAM_RAW),
        ];
        if ($overrides['body_zh_tw'] === '') {
            $settings = bento_notification_manager::get_settings();
            $overrides['subject_zh_tw'] = $overrides['subject_zh_tw'] !== ''
                ? $overrides['subject_zh_tw']
                : ($settings['subject_zh_tw'] !== '' ? $settings['subject_zh_tw'] : bento_notification_manager::get_default_subject());
            $overrides['body_zh_tw'] = $settings['body_zh_tw'] !== ''
                ? $settings['body_zh_tw']
                : bento_notification_manager::get_default_body();
        }
        $preview = bento_notification_manager::compose_for_session($sessionid, $overrides);
        if ($isajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'html' => $preview['html'],
                'subject' => $preview['subject'],
                'present_count' => (int) $preview['present_count'],
            ], JSON_UNESCAPED_UNICODE);
            die;
        }
        redirect($back_url);
    }

    if ($action === 'update_diet') {
        $enrolid = required_param('enrolid', PARAM_INT);
        $dietchoice = optional_param('diet_choice', '', PARAM_ALPHA);
        $specialnote = optional_param('diet_special_note', '', PARAM_TEXT);
        $isajax = optional_param('ajax', 0, PARAM_INT) === 1;
        try {
            $result = enrolment_manager::update_diet_for_attendance($sessionid, $enrolid, [
                'choice' => $dietchoice,
                'special_note' => $specialnote,
            ]);
            if ($isajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'enrolid' => $enrolid,
                    'choice' => $result['choice'],
                    'special_note' => $result['special_note'],
                    'label' => $result['label'],
                ], JSON_UNESCAPED_UNICODE);
                die;
            }
            redirect($back_url, get_string('attendance_diet_updated', 'local_tm_course'),
                null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (\Throwable $e) {
            if ($isajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE);
                die;
            }
            redirect($back_url, $e->getMessage(),
                null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    if ($action === 'setup') {
        try {
            attendance_manager::setup_session($sessionid);
            redirect($back_url, get_string('attendance_setup_done', 'local_tm_course'),
                null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (\Exception $e) {
            redirect($back_url, get_string('attendance_setup_error', 'local_tm_course') . ': ' . $e->getMessage(),
                null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    if ($action === 'present') {
        if (!$enrolid) {
            redirect($back_url, get_string('attendance_mark_invalid_enrol', 'local_tm_course'),
                null, \core\output\notification::NOTIFY_ERROR);
        }
        attendance_manager::mark_attended($enrolid, attendance_manager::ATTEND_PRESENT);
        redirect($back_url, get_string('attendance_marked_present', 'local_tm_course'),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'absent') {
        if (!$enrolid) {
            redirect($back_url, get_string('attendance_mark_invalid_enrol', 'local_tm_course'),
                null, \core\output\notification::NOTIFY_ERROR);
        }
        attendance_manager::mark_attended($enrolid, attendance_manager::ATTEND_ABSENT);
        redirect($back_url, get_string('attendance_marked_absent', 'local_tm_course'),
            null, \core\output\notification::NOTIFY_WARNING);
    }

    if ($action === 'markall') {
        $count = attendance_manager::mark_all_present($sessionid);
        redirect($back_url,
            get_string('attendance_marked_all', 'local_tm_course', $count),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'send_bento' && $isonsite) {
        $viewcheck = enrolment_manager::build_session_attendance_view($sessionid);
        if ((int) ($viewcheck['stats']['present'] ?? 0) < 1) {
            redirect($back_url, get_string('bento_send_need_present', 'local_tm_course'),
                null, \core\output\notification::NOTIFY_WARNING);
        }
        $overrides = [
            'extra_emails' => optional_param('bento_recipients', '', PARAM_RAW_TRIMMED),
            'cc_emails' => optional_param('bento_cc', '', PARAM_RAW_TRIMMED),
            'subject_zh_tw' => optional_param('bento_subject', '', PARAM_TEXT),
            'body_zh_tw' => notification_editor_helper::read_submitted_body('bento_body'),
        ];
        $result = bento_notification_manager::send_for_session($sessionid, $overrides, (int) $USER->id);
        if ($result['sent'] > 0) {
            $msg = get_string('bento_send_success', 'local_tm_course', (object) [
                'sent' => $result['sent'],
                'list' => implode(', ', $result['recipients']),
            ]);
            redirect($back_url, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($result['recipients'] === [] && $result['failed'] === 0) {
            redirect($back_url, get_string('bento_send_no_recipients', 'local_tm_course'),
                null, \core\output\notification::NOTIFY_WARNING);
        }
        redirect($back_url, get_string('bento_send_failed', 'local_tm_course'),
            null, \core\output\notification::NOTIFY_ERROR);
    }

    if ($action === 'equipment_save' || $action === 'equipment_sync') {
        $desknumber = required_param('desknumber', PARAM_INT);
        $maxdesk = equipment_check_manager::get_desk_count($session);
        if ($desknumber < 1 || $desknumber > $maxdesk) {
            redirect($back_url, get_string('error_equipment_check_invalid_desk', 'local_tm_course'),
                null, \core\output\notification::NOTIFY_ERROR);
        }

        $rawitems = [];
        if (!empty($_POST['equip']) && is_array($_POST['equip'])) {
            $rawitems = equipment_clean_raw_items($_POST['equip']);
        }
        $applicableitems = equipment_check_manager::get_applicable_items($session);
        $results = equipment_build_results($rawitems, $applicableitems);

        equipment_check_manager::save_desk_checks($sessionid, $desknumber, $results, (int) $USER->id);

        if ($action === 'equipment_sync') {
            $synced = equipment_check_manager::sync_desk_to_all($sessionid, $desknumber, (int) $USER->id);
            redirect($back_url, get_string('equipment_check_sync_success', 'local_tm_course', (object) [
                'source' => $desknumber,
                'count' => $synced,
            ]), null, \core\output\notification::NOTIFY_SUCCESS);
        }

        redirect($back_url, get_string('equipment_check_save_success', 'local_tm_course', $desknumber),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'equipment_save_all') {
        $maxdesk = equipment_check_manager::get_desk_count($session);
        $applicableitems = equipment_check_manager::get_applicable_items($session);
        $saveddesks = 0;

        // Note: $_POST['equip_all'] is a 3-level nested array (equip_all[desknumber][itemid][field]);
        // optional_param_array() cannot handle multidimensional arrays, so walk it manually
        // (same pattern used by settings/course_mapping.php for classroomids[]).
        if (!empty($_POST['equip_all']) && is_array($_POST['equip_all'])) {
            foreach ($_POST['equip_all'] as $rawdesk => $deskfields) {
                $desknumber = (int) $rawdesk;
                if ($desknumber < 1 || $desknumber > $maxdesk || !is_array($deskfields)) {
                    continue;
                }
                $rawitems = equipment_clean_raw_items($deskfields);
                $results = equipment_build_results($rawitems, $applicableitems);
                equipment_check_manager::save_desk_checks($sessionid, $desknumber, $results, (int) $USER->id);
                $saveddesks++;
            }
        }

        if ($saveddesks < 1) {
            redirect($back_url, get_string('equipment_check_save_all_none', 'local_tm_course'),
                null, \core\output\notification::NOTIFY_WARNING);
        }

        redirect($back_url, get_string('equipment_check_save_all_success', 'local_tm_course', $saveddesks),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$view = enrolment_manager::build_session_attendance_view($sessionid);
$stats = $view['stats'];
$has_attendance_plugin = attendance_manager::is_mod_attendance_installed();
$attendance_take_url = '';
if ($has_attendance_plugin && !empty($session->attendance_cmid) && !empty($session->attendance_sessionid)) {
    $attendance_take_url = (new moodle_url('/mod/attendance/take.php', [
        'id' => (int) $session->attendance_cmid,
        'sessionid' => (int) $session->attendance_sessionid,
        'grouptype' => 0,
    ]))->out(false);
}

$bentosettings = bento_notification_manager::get_settings();
$bentosubject = $bentosettings['subject_zh_tw'] !== ''
    ? $bentosettings['subject_zh_tw']
    : bento_notification_manager::get_default_subject();
$bentobody = $bentosettings['body_zh_tw'] !== ''
    ? $bentosettings['body_zh_tw']
    : bento_notification_manager::get_default_body();
$bentopreview = bento_notification_manager::compose_for_session($sessionid, [
    'extra_emails' => $bentosettings['extra_emails'],
    'subject_zh_tw' => $bentosubject,
    'body_zh_tw' => $bentobody,
]);

$equipmentview = equipment_check_manager::get_check_view($sessionid);
$equipmentmanageurl = (new moodle_url('/local/tm_course/settings/equipment_check_items.php', [
    'courseid' => (int) $session->courseid,
]))->out();

echo $OUTPUT->header();
?>

<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo get_string('nav_class_prep', 'local_tm_course'); ?>
        <span style="font-weight:400; font-size:.85em"> — <?php echo s($session->name); ?></span>
    </h2>
</div>

<div class="mb-3">
    <a href="<?php echo $sessions_url->out(); ?>" class="btn btn-sm btn-secondary">
        ← <?php echo get_string('nav_sessions', 'local_tm_course'); ?>
    </a>
</div>

<div class="tm-card">
<div class="tm-card-body">

    <div class="row mb-3 p-2" style="background:#f4f6f8; border-radius:6px">
        <div class="col-md-3">
            <strong><?php echo get_string('session_startdate', 'local_tm_course'); ?>:</strong><br>
            <?php echo userdate($session->starttime, get_string('strftimedatetimeshort')); ?>
        </div>
        <div class="col-md-3">
            <strong><?php echo get_string('session_location', 'local_tm_course'); ?>:</strong><br>
            <?php echo s($session->location ?: '—'); ?>
        </div>
        <div class="col-md-3">
            <strong><?php echo get_string('session_total_capacity', 'local_tm_course'); ?>:</strong><br>
            <?php echo $session->confirmed_count; ?>/<?php echo $session->total_capacity; ?> persons
        </div>
        <div class="col-md-3">
            <strong><?php echo get_string('nav_attendance', 'local_tm_course'); ?> Status:</strong><br>
            <?php if ($issetup): ?>
                <span class="tm-badge tm-badge-approved">✓ <?php echo get_string('attendance_setup_ready', 'local_tm_course'); ?></span>
            <?php else: ?>
                <span class="tm-badge tm-badge-pending"><?php echo get_string('attendance_not_setup', 'local_tm_course'); ?></span>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<!-- ============================================================
     Section 1: 出缺勤 (attendance) — blue theme
     ============================================================ -->
<div class="tm-card tm-prep-section tm-prep-section-attendance">
<div class="tm-card-body">
    <h3 class="tm-prep-section-title"><span class="tm-prep-section-icon" aria-hidden="true">📋</span> <?php echo get_string('nav_attendance', 'local_tm_course'); ?></h3>

    <div class="d-flex gap-2 mb-3 flex-wrap">
        <a href="<?php echo att_url('setup', $sessionid); ?>"
           class="btn btn-tm-primary"
           onclick="return confirm('<?php echo ($issetup
               ? get_string('attendance_re_setup_confirm', 'local_tm_course')
               : get_string('attendance_setup_confirm', 'local_tm_course')); ?>')">
            ⚙ <?php echo $issetup
                ? get_string('attendance_re_setup', 'local_tm_course')
                : get_string('attendance_setup', 'local_tm_course'); ?>
        </a>

        <?php if ($stats['enrolled'] > 0): ?>
        <a href="<?php echo att_url('markall', $sessionid); ?>"
           class="btn btn-tm-success"
           onclick="return confirm('<?php echo get_string('attendance_markall_confirm', 'local_tm_course'); ?>')">
            ✓✓ <?php echo get_string('attendance_mark_all_present', 'local_tm_course'); ?>
        </a>
        <?php endif; ?>

        <?php if ($attendance_take_url !== ''): ?>
        <a href="<?php echo s($attendance_take_url); ?>" class="btn btn-secondary" target="_blank" rel="noopener">
            ↗ <?php echo get_string('attendance_open_moodle_take', 'local_tm_course'); ?>
        </a>
        <?php endif; ?>
    </div>

    <?php if ($stats['enrolled'] > 0): ?>
    <div class="row text-center mb-3">
        <div class="col-md-3">
            <div style="font-size:2em; font-weight:700; color:var(--tm-blue)"><?php echo (int) $stats['enrolled']; ?></div>
            <small class="text-muted"><?php echo get_string('attendance_total_enrolled', 'local_tm_course'); ?></small>
        </div>
        <div class="col-md-3">
            <div style="font-size:2em; font-weight:700; color:var(--tm-green)"><?php echo (int) $stats['present']; ?></div>
            <small class="text-muted"><?php echo get_string('attendance_present', 'local_tm_course'); ?></small>
        </div>
        <div class="col-md-3">
            <div style="font-size:2em; font-weight:700; color:#dc3545"><?php echo (int) $stats['absent']; ?></div>
            <small class="text-muted"><?php echo get_string('attendance_absent', 'local_tm_course'); ?></small>
        </div>
        <div class="col-md-3">
            <div style="font-size:2em; font-weight:700; color:#6c757d"><?php echo (int) $stats['unset']; ?></div>
            <small class="text-muted"><?php echo get_string('attendance_not_recorded', 'local_tm_course'); ?></small>
        </div>
    </div>

    <?php if ($isonsite): ?>
    <div class="tm-attendance-diet-summary mb-3" id="tm-attendance-diet-summary">
        <div class="tm-attendance-diet-summary-title text-muted small mb-2">
            <?php echo get_string('attendance_diet_summary_title', 'local_tm_course'); ?>
        </div>
        <div class="row text-center">
            <div class="col-md-4">
                <div class="tm-attendance-diet-count" style="font-size:1.5em; font-weight:700; color:var(--tm-blue)"
                     id="tm-diet-enrolled-meat" data-diet-stat="meat"><?php echo (int) $stats['diet_enrolled_meat']; ?></div>
                <small class="text-muted"><?php echo get_string('attendance_diet_meat_count', 'local_tm_course'); ?></small>
            </div>
            <div class="col-md-4">
                <div class="tm-attendance-diet-count" style="font-size:1.5em; font-weight:700; color:var(--tm-green)"
                     id="tm-diet-enrolled-vegetarian" data-diet-stat="vegetarian"><?php echo (int) $stats['diet_enrolled_vegetarian']; ?></div>
                <small class="text-muted"><?php echo get_string('attendance_diet_vegetarian_count', 'local_tm_course'); ?></small>
            </div>
            <div class="col-md-4">
                <div class="tm-attendance-diet-count" style="font-size:1.5em; font-weight:700; color:#6c757d"
                     id="tm-diet-enrolled-unknown" data-diet-stat="unknown"><?php echo (int) $stats['diet_enrolled_unknown']; ?></div>
                <small class="text-muted"><?php echo get_string('attendance_diet_no_choice_label', 'local_tm_course'); ?></small>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isonsite && $stats['present'] > 0): ?>
    <div class="tm-card mb-3">
        <div class="tm-card-body">
            <h5 class="mb-2"><?php echo get_string('attendance_diet_present_summary_title', 'local_tm_course'); ?></h5>
            <div class="row text-center">
                <div class="col-md-4">
                    <div style="font-size:1.8em; font-weight:700; color:var(--tm-blue)"><?php echo (int) $stats['diet_meat']; ?></div>
                    <small class="text-muted"><?php echo get_string('attendance_diet_meat_count', 'local_tm_course'); ?></small>
                </div>
                <div class="col-md-4">
                    <div style="font-size:1.8em; font-weight:700; color:var(--tm-green)"><?php echo (int) $stats['diet_vegetarian']; ?></div>
                    <small class="text-muted"><?php echo get_string('attendance_diet_vegetarian_count', 'local_tm_course'); ?></small>
                </div>
                <div class="col-md-4">
                    <div style="font-size:1.8em; font-weight:700; color:#6c757d"><?php echo (int) $stats['diet_unknown']; ?></div>
                    <small class="text-muted"><?php echo get_string('attendance_diet_no_choice_label', 'local_tm_course'); ?></small>
                </div>
            </div>
            <?php if ($stats['diet_unknown'] > 0): ?>
            <div class="mt-2 text-muted small">
                <?php echo get_string('attendance_diet_no_choice_count', 'local_tm_course', (object)['n' => $stats['diet_unknown']]); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <p class="text-muted mb-3">
        <?php echo get_string('session_roster_approved_only', 'local_tm_course'); ?>
        &mdash;
        <?php echo get_string('session_roster_total', 'local_tm_course', (object) ['n' => (int) $view['total']]); ?>
    </p>

    <?php
    $attendanceformaction = $back_url->out(false);
    $attendance_can_edit_diet = true;
    require(__DIR__ . '/attendance_roster_partial.php');
    ?>

    <?php if ($stats['present'] > 0): ?>
    <div class="tm-alert" style="background:#e8f5e9; border-left:4px solid var(--tm-green); margin-top:1rem">
        <?php echo get_string('attendance_completion_note', 'local_tm_course'); ?>
    </div>
    <?php endif; ?>

</div>
</div>

<!-- ============================================================
     Section 2: 便當通知 (bento notification) — blue theme, existing popup
     ============================================================ -->
<?php if ($isonsite): ?>
<div class="tm-card tm-prep-section tm-prep-section-bento">
<div class="tm-card-body">
    <h3 class="tm-prep-section-title"><span class="tm-prep-section-icon" aria-hidden="true">🍱</span> <?php echo get_string('bento_send_button', 'local_tm_course'); ?></h3>
    <p class="text-muted small mb-3"><?php echo get_string('bento_modal_intro', 'local_tm_course'); ?></p>
    <button type="button"
            class="btn btn-tm-primary"
            id="tm-bento-open-btn"
            <?php echo ($stats['present'] < 1) ? 'disabled title="' . s(get_string('bento_send_need_present', 'local_tm_course')) . '"' : ''; ?>>
        <?php echo get_string('bento_send_button', 'local_tm_course'); ?>
    </button>
</div>
</div>
<?php endif; ?>

<!-- ============================================================
     Section 3: 設備檢查 (equipment check) — orange theme
     ============================================================ -->
<div class="tm-card tm-prep-section tm-prep-section-equipment">
<div class="tm-card-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <h3 class="tm-prep-section-title mb-0"><span class="tm-prep-section-icon" aria-hidden="true">🔧</span> <?php echo get_string('equipment_check_section_title', 'local_tm_course'); ?></h3>
        <a href="<?php echo s($equipmentmanageurl); ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
            <?php echo get_string('equipment_check_manage_open_button', 'local_tm_course'); ?>
        </a>
    </div>
    <p class="text-muted small mb-3"><?php echo get_string('equipment_check_section_desc', 'local_tm_course'); ?></p>

    <?php
    $equipment_form_action = $back_url->out(false);
    require(__DIR__ . '/equipment_check_partial.php');
    ?>
</div>
</div>

<?php if ($isonsite): ?>
<div id="tm-bento-modal" class="tm-cancel-modal-backdrop tm-bento-modal-backdrop" hidden>
    <div class="tm-cancel-modal-panel tm-bento-modal-panel" role="dialog" aria-modal="true" aria-labelledby="tm-bento-modal-title">
        <div class="tm-bento-modal-header">
            <h4 class="mb-2" id="tm-bento-modal-title"><?php echo get_string('bento_modal_title', 'local_tm_course'); ?></h4>
            <p class="text-muted small mb-0"><?php echo get_string('bento_modal_intro', 'local_tm_course'); ?></p>
        </div>
        <form method="post" action="<?php echo $back_url->out(); ?>" id="tm-bento-form" class="tm-bento-modal-form">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="send_bento">
            <div class="tm-bento-modal-body">
                <div class="form-group">
                    <label for="bento_recipients"><strong><?php echo get_string('bento_recipients_label', 'local_tm_course'); ?></strong></label>
                    <textarea name="bento_recipients" id="bento_recipients" class="form-control" rows="2"><?php echo s($bentosettings['extra_emails']); ?></textarea>
                    <small class="text-muted"><?php echo get_string('preclass_notify_extra_emails_desc', 'local_tm_course'); ?></small>
                </div>
                <div class="form-group">
                    <label for="bento_cc"><strong><?php echo get_string('bento_cc_recipients_label', 'local_tm_course'); ?></strong></label>
                    <textarea name="bento_cc" id="bento_cc" class="form-control" rows="2"><?php echo s($bentosettings['cc_emails']); ?></textarea>
                    <small class="text-muted"><?php echo get_string('bento_cc_recipients_desc', 'local_tm_course'); ?></small>
                </div>
                <div class="form-group">
                    <label for="bento_subject"><strong><?php echo get_string('bento_subject_label', 'local_tm_course'); ?></strong></label>
                    <input type="text" name="bento_subject" id="bento_subject" class="form-control" maxlength="255"
                           value="<?php echo s($bentopreview['subject']); ?>">
                </div>
                <div class="form-group mb-2">
                    <label for="bento_body"><strong><?php echo get_string('bento_body_label', 'local_tm_course'); ?></strong></label>
                    <?php
                    $bodyforeditor = $bentobody;
                    if (strpos($bodyforeditor, '{{bento_summary_table}}') === false) {
                        $bodyforeditor = trim($bodyforeditor) . "\n\n{{bento_summary_table}}";
                    }
                    notification_editor_helper::print_body_editor('bento_body', $bodyforeditor, 5);
                    ?>
                    <small class="text-muted d-block mt-1"><?php echo s(implode(', ', bento_notification_manager::get_template_tokens())); ?></small>
                    <small class="text-muted d-block"><?php echo get_string('bento_notify_body_editor_hint', 'local_tm_course'); ?></small>
                </div>
                <details class="tm-bento-preview-fold">
                    <summary><strong><?php echo get_string('bento_preview_heading', 'local_tm_course'); ?></strong></summary>
                    <div class="tm-preclass-preview-box tm-bento-preview-box mt-1" id="tm-bento-preview-html"><?php echo $bentopreview['html']; ?></div>
                </details>
            </div>
            <div class="tm-bento-modal-footer d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-tm-primary" id="tm-bento-submit"
                    onclick="return confirm(<?php echo json_encode(get_string('bento_send_confirm', 'local_tm_course')); ?>);">
                    <?php echo get_string('bento_send_submit', 'local_tm_course'); ?>
                </button>
                <button type="button" class="btn btn-secondary" id="tm-bento-close"><?php echo get_string('cancel'); ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(function() {
    var openBtn = document.getElementById('tm-bento-open-btn');
    var modal = document.getElementById('tm-bento-modal');
    var closeBtn = document.getElementById('tm-bento-close');
    if (!openBtn || !modal) {
        return;
    }
    function openModal() {
        modal.hidden = false;
        modal.removeAttribute('hidden');
        document.body.classList.add('tm-bento-modal-open');
        var body = modal.querySelector('.tm-bento-modal-body');
        if (body) {
            body.scrollTop = 0;
        }
        refreshBentoPreview();
    }
    function refreshBentoPreview() {
        var previewEl = document.getElementById('tm-bento-preview-html');
        if (!previewEl) {
            return;
        }
        var cfg = window.tmAttendanceDietConfig || {};
        var body = new URLSearchParams();
        body.set('sesskey', cfg.sesskey || '');
        body.set('sessionid', String(cfg.sessionid || ''));
        body.set('action', 'bento_preview');
        body.set('ajax', '1');
        var subjectInput = document.getElementById('bento_subject');
        if (subjectInput) {
            body.set('bento_subject', subjectInput.value);
        }
        fetch(cfg.postUrl || window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString(),
            credentials: 'same-origin'
        })
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                if (data && data.ok && data.html) {
                    previewEl.innerHTML = data.html;
                }
            })
            .catch(function() {
                // Keep server-rendered preview on failure.
            });
    }
    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('hidden', 'hidden');
        document.body.classList.remove('tm-bento-modal-open');
    }
    function syncBentoEditors() {
        if (typeof window.tinymce !== 'undefined') {
            window.tinymce.editors.forEach(function(ed) {
                if (ed && ed.id === 'bento_body') {
                    ed.save();
                }
            });
        }
    }

    openBtn.addEventListener('click', openModal);
    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });
    var bentoForm = document.getElementById('tm-bento-form');
    if (bentoForm) {
        bentoForm.addEventListener('submit', syncBentoEditors);
    }
})();
</script>
<?php endif; ?>

<script>
window.tmAttendanceDietConfig = <?php echo json_encode([
    'sessionid' => $sessionid,
    'sesskey' => sesskey(),
    'postUrl' => $back_url->out(false),
    'canEdit' => true,
    'strings' => [
        'meat' => get_string('diet_choice_meat', 'local_tm_course'),
        'vegetarian' => get_string('diet_choice_vegetarian', 'local_tm_course'),
        'specialNote' => get_string('diet_special_note', 'local_tm_course'),
        'save' => get_string('savechanges'),
        'cancel' => get_string('cancel'),
        'clickEdit' => get_string('attendance_diet_click_edit', 'local_tm_course'),
        'noChoice' => get_string('attendance_diet_no_choice_label', 'local_tm_course'),
        'saveError' => get_string('error', 'moodle'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>

<?php
echo $OUTPUT->footer();
