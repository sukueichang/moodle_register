<?php
/**
 * Admin: Session list + quick actions
 * URL: /local/tm_course/admin/sessions.php
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/prerequisite_manager.php');
require_once(__DIR__ . '/../classes/tcms_sync_manager.php');

use local_tm_course\session_manager;
use local_tm_course\prerequisite_manager;
use local_tm_course\tcms_sync_manager;

require_login();
require_capability('local/tm_course:manage', context_system::instance());
$issiteadmin = is_siteadmin();

global $DB, $OUTPUT, $PAGE;
$PAGE->set_url(new moodle_url('/local/tm_course/admin/sessions.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('nav_sessions', 'local_tm_course'));
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/local/tm_course/styles.css');

$monthviewurl = (new moodle_url('/local/tm_course/index.php'))->out(false) . '#tm-calendar-month-anchor';

// ---- Handle delete action ----
$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
if ($delete && $confirm) {
    require_sesskey();
    session_manager::delete_session($delete);
    redirect(new moodle_url('/local/tm_course/admin/sessions.php'),
             get_string('session_deleted', 'local_tm_course'), null,
             \core\output\notification::NOTIFY_SUCCESS);
}

// ---- Force push one session to TCMS ----
$tcmsresync = optional_param('tcms_resync', 0, PARAM_INT);
if ($tcmsresync > 0) {
    require_sesskey();
    $redirecturl = new moodle_url('/local/tm_course/admin/sessions.php');
    if (!tcms_sync_manager::is_enabled()) {
        redirect($redirecturl,
            get_string('tcms_sync_resync_disabled', 'local_tm_course'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    tcms_sync_manager::push_session($tcmsresync);
    $after = $DB->get_record('local_tm_course_sessions', ['id' => $tcmsresync], 'tcms_sync_status,tcms_sync_error', IGNORE_MISSING);
    $st = $after ? (string) ($after->tcms_sync_status ?? '') : '';
    $err = $after ? trim((string) ($after->tcms_sync_error ?? '')) : '';
    if ($st === tcms_sync_manager::SYNC_OK) {
        redirect($redirecturl,
            get_string('tcms_sync_resync_ok', 'local_tm_course'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($st === tcms_sync_manager::SYNC_SKIPPED) {
        redirect($redirecturl,
            get_string('tcms_sync_resync_skipped', 'local_tm_course'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    $msg = get_string('tcms_sync_resync_error', 'local_tm_course');
    if ($err !== '') {
        $msg .= ' ' . $err;
    }
    redirect($redirecturl, $msg, null, \core\output\notification::NOTIFY_ERROR);
}

// ---- Immediate reconcile: push all eligible sessions (respects sync-from date) ----
$tcmsreconcilenow = optional_param('tcms_reconcile_now', 0, PARAM_BOOL);
if ($tcmsreconcilenow) {
    require_sesskey();
    $redirecturl = new moodle_url('/local/tm_course/admin/sessions.php');
    if (!tcms_sync_manager::is_enabled()) {
        redirect($redirecturl,
            get_string('tcms_sync_resync_disabled', 'local_tm_course'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    \core_php_time_limit::raise(300);
    raise_memory_limit(MEMORY_EXTRA);
    $stats = tcms_sync_manager::reconcile_all();
    $from = trim((string) get_config('local_tm_course', 'tcms_sync_from_date'));
    $a = (object) [
        'ok' => (int) ($stats['ok'] ?? 0),
        'error' => (int) ($stats['error'] ?? 0),
        'skipped' => (int) ($stats['skipped'] ?? 0),
        'from' => $from !== '' ? $from : get_string('tcms_sync_reconcile_now_from_all', 'local_tm_course'),
    ];
    $notify = ((int) $a->error > 0)
        ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_SUCCESS;
    redirect($redirecturl, get_string('tcms_sync_reconcile_now_done', 'local_tm_course', $a), null, $notify);
}
$bulkaction = optional_param('bulk_action', '', PARAM_ALPHA);
$selectedsessionids = optional_param_array('sessionids', [], PARAM_INT);
if ($bulkaction === 'bulk_delete') {
    require_sesskey();
    $selectedsessionids = array_values(array_unique(array_filter(array_map('intval', $selectedsessionids), function($v) {
        return $v > 0;
    })));
    if (empty($selectedsessionids)) {
        redirect(new moodle_url('/local/tm_course/admin/sessions.php'),
            get_string('sessions_bulk_delete_empty', 'local_tm_course'), null, \core\output\notification::NOTIFY_WARNING);
    }
    $deleted = 0;
    foreach ($selectedsessionids as $sid) {
        session_manager::delete_session((int)$sid);
        $deleted++;
    }
    redirect(new moodle_url('/local/tm_course/admin/sessions.php'),
        get_string('sessions_bulk_delete_done', 'local_tm_course', $deleted), null, \core\output\notification::NOTIFY_SUCCESS);
}

// ---- Filters ----
$filter_status = optional_param('filter_status', '', PARAM_INT);
$filter_course = optional_param('filter_course', 0, PARAM_INT);

$filters = [];
if ($filter_status !== '') $filters['status'] = $filter_status;
if ($filter_course)        $filters['courseid'] = $filter_course;

$sessions = session_manager::get_sessions($filters);

// All Moodle courses for filter dropdown
$courses = $DB->get_records_menu('course', ['visible' => 1], 'fullname ASC', 'id, fullname');

echo $OUTPUT->header();
require_once(__DIR__ . '/../lib.php');
$pluginver = local_tm_course_plugin_version_info();
?>

<div class="tm-page-header d-flex flex-wrap align-items-baseline justify-content-between gap-2">
    <div class="d-flex align-items-center gap-2">
        <span class="tm-logo-dot"></span>
        <h2 class="mb-0"><?php echo get_string('nav_sessions', 'local_tm_course'); ?></h2>
    </div>
    <?php if ($issiteadmin): ?>
    <span class="text-muted small" title="<?php echo s(get_string('setting_plugin_version', 'local_tm_course')); ?>">
        <?php echo s(get_string('plugin_version_badge', 'local_tm_course', $pluginver['label'])); ?>
    </span>
    <?php endif; ?>
</div>

<div class="tm-card">
<div class="tm-card-body">

<!-- Toolbar: Add button + filter form -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <a href="<?php echo (new moodle_url('/local/tm_course/admin/edit_session.php'))->out(); ?>"
       class="btn btn-tm-success">
        + <?php echo get_string('add_session', 'local_tm_course'); ?>
    </a>
    <a href="<?php echo (new moodle_url('/local/tm_course/admin/edit_session.php', ['batch' => 1]))->out(); ?>"
       class="btn btn-tm-primary">
        <?php echo get_string('batch_create', 'local_tm_course'); ?>
    </a>
    <a href="<?php echo s($monthviewurl); ?>"
       class="btn btn-secondary">
        <?php echo get_string('sessions_open_month_view', 'local_tm_course'); ?>
    </a>
    <a href="<?php echo (new moodle_url('/local/tm_course/admin/room_closed.php'))->out(); ?>"
       class="btn btn-secondary">
        <?php echo get_string('sessions_room_closed_button', 'local_tm_course'); ?>
    </a>
    <?php
    $syncon = tcms_sync_manager::is_enabled();
    $syncfrom = trim((string) get_config('local_tm_course', 'tcms_sync_from_date'));
    $reconcileurl = new moodle_url('/local/tm_course/admin/sessions.php', [
        'tcms_reconcile_now' => 1,
        'sesskey' => sesskey(),
    ]);
    $reconciletitle = $syncon
        ? get_string('tcms_sync_reconcile_now_hint', 'local_tm_course',
            $syncfrom !== '' ? $syncfrom : get_string('tcms_sync_reconcile_now_from_all', 'local_tm_course'))
        : get_string('tcms_sync_resync_disabled', 'local_tm_course');
    ?>
    <a href="<?php echo $reconcileurl->out(false); ?>"
       class="btn btn-tm-primary<?php echo $syncon ? '' : ' disabled'; ?>"
       title="<?php echo s($reconciletitle); ?>"
       <?php echo $syncon ? '' : 'aria-disabled="true" tabindex="-1" onclick="return false;"'; ?>>
        <?php echo get_string('tcms_sync_reconcile_now_button', 'local_tm_course'); ?>
    </a>

    <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
        <select name="filter_status" class="form-control form-control-sm" style="width:auto">
            <option value=""><?php echo get_string('session_status', 'local_tm_course'); ?>: <?php echo get_string('all', 'local_tm_course'); ?></option>
            <option value="1" <?php selected($filter_status, 1); ?>><?php echo get_string('session_status_open',   'local_tm_course'); ?></option>
            <option value="2" <?php selected($filter_status, 2); ?>><?php echo get_string('session_status_full',   'local_tm_course'); ?></option>
            <option value="0" <?php selected($filter_status, 0); ?>><?php echo get_string('session_status_closed', 'local_tm_course'); ?></option>
        </select>
        <select name="filter_course" class="form-control form-control-sm" style="width:auto">
            <option value="0"><?php echo get_string('course_filter_all', 'local_tm_course'); ?></option>
            <?php foreach ($courses as $cid => $cname): ?>
                <option value="<?php echo $cid; ?>" <?php selected($filter_course, $cid); ?>>
                    <?php echo s($cname); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-tm-primary"><?php echo get_string('filter', 'local_tm_course'); ?></button>
        <a href="<?php echo (new moodle_url('/local/tm_course/admin/sessions.php'))->out(); ?>"
           class="btn btn-sm btn-secondary"><?php echo get_string('reset', 'local_tm_course'); ?></a>
    </form>
</div>

<?php if (empty($sessions)): ?>
    <div class="tm-alert tm-alert-info"><?php echo get_string('no_sessions', 'local_tm_course'); ?></div>
<?php else: ?>
<?php if ($issiteadmin): ?>
<form method="post" id="tm-sessions-bulk-form">
<?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
<?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulk_action', 'value' => 'bulk_delete']); ?>
<div class="d-flex gap-2 mb-2">
    <button type="button" class="btn btn-sm btn-tm-primary" id="tm-sessions-select-all"><?php echo get_string('sessions_select_all', 'local_tm_course'); ?></button>
    <button type="submit" class="btn btn-sm btn-tm-danger" id="tm-sessions-bulk-delete"><?php echo get_string('sessions_bulk_delete', 'local_tm_course'); ?></button>
</div>
<div style="overflow-x:auto">
<table class="tm-table">
<thead>
<tr>
    <th style="width:2rem"></th>
    <th><?php echo get_string('session_name', 'local_tm_course'); ?></th>
    <th><?php echo get_string('session_startdate', 'local_tm_course'); ?></th>
    <th><?php echo get_string('session_location', 'local_tm_course'); ?></th>
    <th><?php echo get_string('session_remaining_desks', 'local_tm_course'); ?></th>
    <th><?php echo get_string('session_status', 'local_tm_course'); ?></th>
    <th><?php echo get_string('session_approval_mode', 'local_tm_course'); ?></th>
    <th><?php echo get_string('tcms_sync_col', 'local_tm_course'); ?></th>
    <th><?php echo get_string('sessions_actions', 'local_tm_course'); ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($sessions as $s):
    $fill_class = $s->fill_percent >= 100 ? 'danger' : ($s->fill_percent >= 80 ? 'warning' : '');
    $status_labels = [
        session_manager::STATUS_OPEN   => ['open',   get_string('session_status_open',   'local_tm_course')],
        session_manager::STATUS_FULL   => ['full',   get_string('session_status_full',   'local_tm_course')],
        session_manager::STATUS_CLOSED => ['closed', get_string('session_status_closed', 'local_tm_course')],
    ];
    $unknown_label = get_string('session_status_unknown', 'local_tm_course');
    [$badge_class, $badge_label] = $status_labels[(int)$s->status] ?? ['closed', $unknown_label];
    $is_online = ((string)($s->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE);
    $is_room_closed = session_manager::is_room_closed_session($s);
    if ($is_room_closed) {
        $badge_class = 'closed';
        $badge_label = get_string('session_room_closed_list_badge', 'local_tm_course');
    }
?>
<tr>
    <td><input type="checkbox" class="tm-session-bulk-item" name="sessionids[]" value="<?php echo (int)$s->id; ?>"></td>
    <td data-label="<?php echo get_string('label_name', 'local_tm_course'); ?>">
        <strong><?php echo s($s->name); ?></strong>
        <?php if (prerequisite_manager::session_has_prerequisites($s)): ?>
            <br><small class="text-muted"><?php echo get_string('prereq_required_note', 'local_tm_course'); ?></small>
        <?php endif; ?>
    </td>
    <td data-label="<?php echo get_string('label_start', 'local_tm_course'); ?>"><?php echo userdate($s->starttime, get_string('strftimedatetimeshort')); ?></td>
    <td data-label="<?php echo get_string('label_location', 'local_tm_course'); ?>"><?php echo s($s->location); ?></td>
    <td data-label="<?php echo get_string('label_desks', 'local_tm_course'); ?>">
        <?php if ($is_room_closed): ?>
            <span class="text-muted">&mdash;</span>
        <?php elseif (!$is_online): ?>
        <!-- Desk visual grid (max 6) -->
        <div class="tm-desk-grid">
            <?php for ($d = 1; $d <= $s->num_desks; $d++):
                $occupied = in_array($d, $s->occupied_desk_numbers, true) ? 'occupied' : 'available';
            ?>
            <div class="tm-desk-slot <?php echo $occupied; ?>" title="<?php echo get_string('label_desk', 'local_tm_course'); ?> <?php echo $d; ?>">
                <?php echo $d; ?>
            </div>
            <?php endfor; ?>
        </div>
        <!-- Capacity bar -->
        <div class="tm-capacity-bar" style="width:120px">
            <div class="tm-capacity-bar-fill <?php echo $fill_class; ?>"
                 style="width:<?php echo $s->fill_percent; ?>%"></div>
        </div>
        <small class="text-muted">
            <?php
            echo get_string('session_remaining_positions', 'local_tm_course', (object)[
                'desks' => $s->remaining_desks,
                'total' => $s->num_desks,
                'persons' => $s->remaining_persons
            ]);
            ?>
        </small>
        <?php else: ?>
        <span class="text-muted"><?php echo get_string('session_online_admin_no_capacity_ui', 'local_tm_course'); ?></span>
        <?php endif; ?>
    </td>
    <td data-label="<?php echo get_string('label_status', 'local_tm_course'); ?>">
        <span class="tm-badge tm-badge-<?php echo $badge_class; ?>"><?php echo $badge_label; ?></span>
    </td>
    <td data-label="<?php echo get_string('label_approval', 'local_tm_course'); ?>">
        <?php if ($is_room_closed): ?>
        <span class="text-muted">&mdash;</span>
        <?php else: ?>
        <?php
        if ((int)$s->approval_mode === session_manager::APPROVAL_MANUAL) {
            echo '<span class="tm-badge tm-badge-pending">' . get_string('session_approval_manual_badge', 'local_tm_course') . '</span>';
        } else {
            echo '<span class="tm-badge tm-badge-approved">' . get_string('session_approval_auto_badge', 'local_tm_course') . '</span>';
        }
        ?>
        <?php endif; ?>
    </td>
    <td data-label="<?php echo get_string('tcms_sync_col', 'local_tm_course'); ?>">
        <?php
        if ($is_room_closed) {
            echo '<span class="text-muted">&mdash;</span>';
        } else if (!tcms_sync_manager::is_enabled()) {
            echo '<span class="text-muted" title="' . s(get_string('tcms_sync_off_hint', 'local_tm_course')) . '">'
                . get_string('tcms_sync_off', 'local_tm_course') . '</span>';
        } else {
            $syncst = (string) ($s->tcms_sync_status ?? '');
            $synclabels = [
                tcms_sync_manager::SYNC_OK => get_string('tcms_sync_ok', 'local_tm_course'),
                tcms_sync_manager::SYNC_ERROR => get_string('tcms_sync_error', 'local_tm_course'),
                tcms_sync_manager::SYNC_SKIPPED => get_string('tcms_sync_skipped', 'local_tm_course'),
                tcms_sync_manager::SYNC_PENDING => get_string('tcms_sync_pending', 'local_tm_course'),
            ];
            $label = $synclabels[$syncst] ?? ($syncst !== '' ? s($syncst) : get_string('tcms_sync_never', 'local_tm_course'));
            $title = trim((string) ($s->tcms_sync_error ?? ''));
            if ($title === '' && $syncst === '') {
                $title = get_string('tcms_sync_never_hint', 'local_tm_course');
            }
            if ($title !== '') {
                echo '<span title="' . s($title) . '">' . $label . '</span>';
            } else {
                echo $label;
            }
            $resyncurl = new moodle_url('/local/tm_course/admin/sessions.php', [
                'tcms_resync' => (int) $s->id,
                'sesskey' => sesskey(),
            ]);
            echo ' <a class="btn btn-sm btn-link p-0 ml-1" href="' . $resyncurl->out(false) . '">'
                . get_string('tcms_sync_resync_button', 'local_tm_course') . '</a>';
        }
        ?>
    </td>
    <td data-label="<?php echo get_string('sessions_actions', 'local_tm_course'); ?>">
        <?php
        $editurl = $is_room_closed
            ? (new moodle_url('/local/tm_course/admin/room_closed.php', ['id' => $s->id]))->out()
            : (new moodle_url('/local/tm_course/admin/edit_session.php', ['id' => $s->id]))->out();
        ?>
        <a href="<?php echo s($editurl); ?>"
           class="btn btn-sm btn-tm-primary"><?php echo get_string('sessions_edit_button', 'local_tm_course'); ?></a>
        <?php if (!$is_room_closed): ?>
        <a href="<?php echo (new moodle_url('/local/tm_course/admin/enrolments.php', ['sessionid' => $s->id]))->out(); ?>"
           class="btn btn-sm btn-secondary"><?php echo get_string('sessions_enrolments_button', 'local_tm_course'); ?></a>
        <a href="<?php echo (new moodle_url('/local/tm_course/admin/session_roster.php', ['sessionid' => $s->id]))->out(); ?>"
           class="btn btn-sm btn-secondary"><?php echo get_string('session_roster_button', 'local_tm_course'); ?></a>
        <a href="<?php echo (new moodle_url('/local/tm_course/admin/class_prep.php', ['sessionid' => $s->id]))->out(); ?>"
           class="btn btn-sm" style="background:var(--tm-blue);color:#fff">
            <?php echo get_string('nav_class_prep', 'local_tm_course'); ?>
        </a>
        <?php endif; ?>
        <a href="#"
           class="btn btn-sm btn-tm-danger tm-session-delete-one"
           role="button"
           data-sid="<?php echo (int)$s->id; ?>"
           data-sk="<?php echo s(sesskey()); ?>">
           <?php echo get_string('sessions_delete_button', 'local_tm_course'); ?></a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</form>
<script>
(function() {
    var btnSelectAll = document.getElementById('tm-sessions-select-all');
    var btnDelete = document.getElementById('tm-sessions-bulk-delete');
    var form = document.getElementById('tm-sessions-bulk-form');
    var delMsg = <?php echo json_encode(get_string('confirm_delete', 'local_tm_course'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    var delBase = <?php echo json_encode((new moodle_url('/local/tm_course/admin/sessions.php'))->out(false), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    document.querySelectorAll('.tm-session-delete-one').forEach(function (el) {
        el.addEventListener('click', function (ev) {
            ev.preventDefault();
            if (!confirm(delMsg)) {
                return;
            }
            var id = el.getAttribute('data-sid');
            var sk = el.getAttribute('data-sk');
            window.location.href = delBase + '?delete=' + encodeURIComponent(id) + '&confirm=1&sesskey=' + encodeURIComponent(sk);
        });
    });
    if (!btnSelectAll || !btnDelete || !form) { return; }
    btnSelectAll.addEventListener('click', function() {
        var boxes = form.querySelectorAll('.tm-session-bulk-item');
        var hasUnchecked = false;
        for (var i = 0; i < boxes.length; i++) {
            if (!boxes[i].checked) { hasUnchecked = true; break; }
        }
        for (var j = 0; j < boxes.length; j++) {
            boxes[j].checked = hasUnchecked;
        }
    });
    form.addEventListener('submit', function(e) {
        var boxes = form.querySelectorAll('.tm-session-bulk-item:checked');
        if (!boxes.length) {
            e.preventDefault();
            alert(<?php echo json_encode(get_string('sessions_bulk_delete_empty', 'local_tm_course')); ?>);
            return false;
        }
        if (!confirm(<?php echo json_encode(get_string('sessions_bulk_delete_confirm', 'local_tm_course')); ?>)) {
            e.preventDefault();
            return false;
        }
        return true;
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>

</div><!-- .tm-card-body -->
</div><!-- .tm-card -->

<?php
echo $OUTPUT->footer();

// Helper: echo 'selected' attr
function selected($val, $target): void {
    if ((string)$val === (string)$target) echo ' selected';
}
