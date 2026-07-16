<?php
/**
 * Admin: Enrolment management — approve / reject / list
 * URL: /local/tm_course/admin/enrolments.php[?sessionid=N]
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/enrolment_manager.php');
require_once(__DIR__ . '/../classes/session_verification_manager.php');

use local_tm_course\session_manager;
use local_tm_course\enrolment_manager;
use local_tm_course\session_verification_manager;

require_login();
require_capability('local/tm_course:approve', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/tm_course/admin/enrolments.php'));
$PAGE->set_title(get_string('nav_enrolments', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

$sessionid = optional_param('sessionid', 0, PARAM_INT);
$fromresv = optional_param('from_resv', 0, PARAM_INT);
$fromresvstatus = optional_param('resvstatus', 0, PARAM_INT);
$fromenrolstatus = optional_param('enrolstatus', 0, PARAM_INT);
$action    = optional_param('action', '', PARAM_ALPHA);
$enrolid   = optional_param('enrolid', 0, PARAM_INT);
$reason    = optional_param('reason', '', PARAM_TEXT);
$deskno    = optional_param('desk_number', 0, PARAM_INT);

// ---- Handle approve / reject ----
if ($action && $enrolid && confirm_sesskey()) {
    try {
        if ($action === 'approve') {
            enrolment_manager::approve($enrolid, $deskno);
            $msg = get_string('enrol_approved_notice', 'local_tm_course');
        } elseif ($action === 'unapprove') {
            enrolment_manager::unapprove($enrolid);
            $msg = get_string('enrol_unapproved_notice', 'local_tm_course');
        } elseif ($action === 'reject') {
            enrolment_manager::reject($enrolid, $reason);
            $msg = get_string('enrol_rejected_notice', 'local_tm_course');
        }
    } catch (\moodle_exception $ex) {
        redirect(new moodle_url('/local/tm_course/admin/enrolments.php', enrolments_redirect_params($sessionid, $fromresv, $fromresvstatus, $fromenrolstatus)),
            $ex->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect(new moodle_url('/local/tm_course/admin/enrolments.php', enrolments_redirect_params($sessionid, $fromresv, $fromresvstatus, $fromenrolstatus)),
             $msg ?? '', null, \core\output\notification::NOTIFY_SUCCESS);
}

/**
 * @return array<string,int>
 */
function enrolments_redirect_params(int $sessionid, int $fromresv, int $resvstatus, int $enrolstatus): array {
    $params = ['sessionid' => $sessionid];
    if ($fromresv > 0) {
        $params['from_resv'] = $fromresv;
        $params['resvstatus'] = $resvstatus;
        $params['enrolstatus'] = $enrolstatus;
    }
    return $params;
}

// ---- Load data ----
$status_filter_raw = optional_param('filter_status', '', PARAM_RAW_TRIMMED);
$status_filter = ($status_filter_raw === '' ? '' : (int) $status_filter_raw);
$filterable_statuses = [
    session_manager::ENROL_PENDING,
    session_manager::ENROL_APPROVED,
    session_manager::ENROL_REJECTED,
];

if ($sessionid) {
    $session = session_manager::get_session($sessionid);
    $session_is_online = ((string)($session->delivery_mode ?? '') === session_manager::DELIVERY_ONLINE);
    $session_has_vq = session_verification_manager::session_has_verification_questions($session);
    $sql = "SELECT e.*, u.firstname, u.lastname, u.email, u.institution AS profile_institution,
                   sb.firstname AS submitter_firstname, sb.lastname AS submitter_lastname
              FROM {local_tm_course_enrolments} e
              JOIN {user} u ON u.id = e.userid
         LEFT JOIN {user} sb ON sb.id = e.batch_submittedby
             WHERE e.sessionid = :sid";
    $params = ['sid' => $sessionid];
    if ($status_filter !== '' && in_array((int) $status_filter, $filterable_statuses, true)) {
        $sql .= ' AND e.status = :st';
        $params['st'] = (int)$status_filter;
    }
    if ($fromresv > 0) {
        $sql .= ' AND COALESCE(e.reservation_initial_enrol, 0) = 1';
    } else {
        $sql .= ' AND COALESCE(e.reservation_initial_enrol, 0) = 0';
    }
    $sql .= ' ORDER BY e.timecreated ASC';
    $enrolments = $DB->get_records_sql($sql, $params);
} else {
    $session_is_online = false;
    $session_has_vq = false;
    // Show all sessions with pending count
    $sessions_sql = "SELECT s.*, COUNT(e.id) AS pending_count
                       FROM {local_tm_course_sessions} s
                  LEFT JOIN {local_tm_course_enrolments} e
                         ON e.sessionid = s.id
                        AND e.status = 0
                        AND COALESCE(e.reservation_initial_enrol, 0) = 0
                      GROUP BY s.id
                      ORDER BY s.starttime DESC";
    $all_sessions = $DB->get_records_sql($sessions_sql);
}

$status_labels = [
    session_manager::ENROL_PENDING    => ['pending',  get_string('enrol_pending', 'local_tm_course')],
    session_manager::ENROL_APPROVED   => ['approved', get_string('enrol_approved', 'local_tm_course')],
    session_manager::ENROL_REJECTED   => ['rejected', get_string('enrol_rejected', 'local_tm_course')],
    session_manager::ENROL_CANCELLED  => ['closed',   get_string('enrol_cancelled', 'local_tm_course')],
    session_manager::ENROL_WAITLISTED => ['full',     get_string('enrol_waitlisted', 'local_tm_course')],
];

echo $OUTPUT->header();
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo get_string('nav_enrolments', 'local_tm_course'); ?>
        <?php if ($sessionid && isset($session)): ?>
            — <span style="font-weight:400"><?php echo s($session->name); ?></span>
            <?php if ($fromresv > 0): ?>
                <span class="text-muted small">(<?php echo get_string('reservation_review_enrol_heading_suffix', 'local_tm_course', (object)['id' => $fromresv]); ?>)</span>
            <?php endif; ?>
        <?php endif; ?>
    </h2>
</div>
<div class="tm-card">
<div class="tm-card-body">

<?php if (!$sessionid): ?>
<!-- ---- Session selection list ---- -->
<table class="tm-table">
<thead><tr>
    <th>#</th><th><?php echo get_string('session_name', 'local_tm_course'); ?></th><th><?php echo get_string('label_start', 'local_tm_course'); ?></th><th><?php echo get_string('session_total_capacity', 'local_tm_course'); ?></th><th><?php echo get_string('enrol_pending', 'local_tm_course'); ?></th><th><?php echo get_string('sessions_actions', 'local_tm_course'); ?></th>
</tr></thead>
<tbody>
<?php foreach ($all_sessions as $s):
    $se = session_manager::get_session($s->id); ?>
<tr>
    <td><?php echo $s->id; ?></td>
    <td><?php echo s($s->name); ?></td>
    <td><?php echo userdate($s->starttime, get_string('strftimedatetimeshort')); ?></td>
    <td><?php echo $se->confirmed_count; ?>/<?php echo $se->total_capacity; ?></td>
    <td>
        <?php if ($s->pending_count > 0): ?>
            <span class="tm-badge tm-badge-pending">
                <?php echo $s->pending_count; ?> <?php echo get_string('enrol_pending', 'local_tm_course'); ?>
            </span>
        <?php else: ?>
            <span class="text-muted">—</span>
        <?php endif; ?>
    </td>
    <td>
        <a href="<?php echo (new moodle_url('/local/tm_course/admin/enrolments.php', ['sessionid' => $s->id]))->out(); ?>"
           class="btn btn-sm btn-tm-primary"><?php echo get_string('view'); ?></a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php else: ?>
<!-- ---- Enrolment list for specific session ---- -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <?php if ($fromresv > 0): ?>
    <a href="<?php echo (new moodle_url('/local/tm_course/admin/review_center.php', [
        'resvstatus' => $fromresvstatus,
        'enrolstatus' => $fromenrolstatus,
    ]))->out(); ?>"
       class="btn btn-sm btn-secondary">← <?php echo get_string('enrolments_back_to_reservation_review', 'local_tm_course'); ?></a>
    <?php else: ?>
    <a href="<?php echo (new moodle_url('/local/tm_course/admin/enrolments.php'))->out(); ?>"
       class="btn btn-sm btn-secondary">← <?php echo get_string('all_sessions', 'local_tm_course'); ?></a>
    <?php endif; ?>
    <!-- Status filter -->
    <form method="get" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="sessionid" value="<?php echo $sessionid; ?>">
        <?php if ($fromresv > 0): ?>
        <input type="hidden" name="from_resv" value="<?php echo (int)$fromresv; ?>">
        <input type="hidden" name="resvstatus" value="<?php echo (int)$fromresvstatus; ?>">
        <input type="hidden" name="enrolstatus" value="<?php echo (int)$fromenrolstatus; ?>">
        <?php endif; ?>
        <select name="filter_status" class="form-control form-control-sm" style="width:auto">
            <option value=""><?php echo get_string('all_statuses', 'local_tm_course'); ?></option>
            <?php foreach ($filterable_statuses as $sv):
                [$cls, $lbl] = $status_labels[$sv]; ?>
            <option value="<?php echo $sv; ?>" <?php echo ($status_filter == $sv) ? 'selected' : ''; ?>>
                <?php echo $lbl; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-tm-primary"><?php echo get_string('filter'); ?></button>
    </form>
</div>

<?php if ($fromresv > 0): ?>
    <div class="tm-alert tm-alert-info mb-3"><?php echo get_string('reservation_review_enrol_initial_only_hint', 'local_tm_course'); ?></div>
<?php endif; ?>

<!-- Session summary card -->
<?php $se = session_manager::get_session($sessionid); ?>
<div class="row mb-3">
    <div class="col-md-3"><strong><?php echo get_string('label_start', 'local_tm_course'); ?>:</strong><br><?php echo userdate($se->starttime, get_string('strftimedatetimeshort')); ?></div>
    <div class="col-md-3"><strong><?php echo get_string('label_location', 'local_tm_course'); ?>:</strong><br><?php echo s($se->location); ?></div>
    <div class="col-md-3"><strong><?php echo $session_is_online
        ? get_string('session_online_enrolled_heading', 'local_tm_course')
        : get_string('session_total_capacity', 'local_tm_course'); ?>:</strong><br><?php
    if ($session_is_online) {
        echo get_string('session_online_enrolled_only', 'local_tm_course', (object)['n' => (int) $se->confirmed_count]);
    } else {
        echo $se->confirmed_count . '/' . $se->total_capacity . ' ' . get_string('session_capacity_persons_suffix', 'local_tm_course');
    }
    ?></div>
    <div class="col-md-3"><strong><?php echo $session_is_online
        ? get_string('session_online_quota_column', 'local_tm_course')
        : get_string('session_remaining_desks', 'local_tm_course'); ?>:</strong><br><?php
    if ($session_is_online) {
        echo get_string('session_online_admin_no_capacity_ui', 'local_tm_course');
    } else {
        echo (int)$se->remaining_desks . '/' . (int)$se->num_desks;
    }
    ?></div>
</div>
<p class="mb-3">
    <a href="<?php echo (new moodle_url('/local/tm_course/admin/session_roster.php', ['sessionid' => $sessionid]))->out(); ?>"
       class="btn btn-sm btn-secondary"><?php echo get_string('session_roster_button', 'local_tm_course'); ?></a>
</p>

<?php if (empty($enrolments)): ?>
    <div class="tm-alert tm-alert-info"><?php echo get_string('enrolments_none_found', 'local_tm_course'); ?></div>
<?php else: ?>
<table class="tm-table">
<thead><tr>
    <th>#</th>
    <th><?php echo get_string('label_learner', 'local_tm_course'); ?></th>
    <th><?php echo get_string('label_email', 'local_tm_course'); ?></th>
    <th><?php echo get_string('institution', 'local_tm_course'); ?></th>
    <th><?php echo get_string('label_applied_at', 'local_tm_course'); ?></th>
    <?php if (!$session_is_online): ?>
    <th><?php echo get_string('assigned_desk', 'local_tm_course'); ?></th>
    <?php endif; ?>
    <th><?php echo get_string('diet_survey_title', 'local_tm_course'); ?></th>
    <th><?php echo get_string('batch_submitted_by', 'local_tm_course'); ?></th>
    <th><?php echo get_string('batch_submitter_note_label', 'local_tm_course'); ?></th>
    <th><?php echo get_string('label_status', 'local_tm_course'); ?></th>
    <th><?php echo get_string('sync_status', 'local_tm_course'); ?></th>
    <?php if (!empty($session_has_vq)): ?>
    <th><?php echo get_string('session_vq_review_link_col', 'local_tm_course'); ?></th>
    <?php endif; ?>
    <th><?php echo get_string('sessions_actions', 'local_tm_course'); ?></th>
</tr></thead>
<tbody>
<?php foreach ($enrolments as $e):
    [$badge_cls, $badge_lbl] = $status_labels[$e->status] ?? ['closed', 'Unknown'];
    $syncmeta = enrolment_manager::get_sync_health_meta($e);
    $approveurlparams = [
        'sessionid' => $sessionid, 'action' => 'approve',
        'enrolid' => $e->id, 'sesskey' => sesskey(),
    ] + ($fromresv > 0 ? [
        'from_resv' => $fromresv,
        'resvstatus' => $fromresvstatus,
        'enrolstatus' => $fromenrolstatus,
    ] : []);
    $approve_url = (new moodle_url('/local/tm_course/admin/enrolments.php', $approveurlparams))->out();
    $diet_summary = enrolment_manager::format_diet_summary($e);
    $submitter = (!empty($e->submitter_firstname) || !empty($e->submitter_lastname))
        ? trim($e->submitter_firstname . ' ' . $e->submitter_lastname) : '—';
    $holderemail = (string) ($e->email ?? '');
    $linkedpending = trim((string) ($e->linked_email ?? ''));
    if (enrolment_manager::is_placeholder_holder_email($holderemail)) {
        $emailhtml = $linkedpending !== ''
            ? s($linkedpending)
            : '<span class="text-muted">' . s(get_string('admin_placeholder_email_display', 'local_tm_course')) . '</span>';
    } else {
        $emailhtml = s($holderemail);
    }
?>
<tr>
    <td><?php echo $e->id; ?></td>
    <td><?php echo s($e->firstname . ' ' . $e->lastname); ?></td>
    <td><?php echo $emailhtml; ?></td>
    <td><?php echo s($e->institution ?: $e->profile_institution); ?></td>
    <td><?php echo userdate($e->timecreated, get_string('strftimedatetimeshort')); ?></td>
    <?php if (!$session_is_online): ?>
    <td><?php echo !empty($e->desk_number) ? (int)$e->desk_number : '—'; ?></td>
    <?php endif; ?>
    <td><?php echo $diet_summary; ?></td>
    <td><?php echo s($submitter); ?></td>
    <td><?php
        $bnote = isset($e->batch_submitter_note) ? trim((string)$e->batch_submitter_note) : '';
        echo $bnote !== '' ? nl2br(s($bnote)) : '—';
    ?></td>
    <td><span class="tm-badge tm-badge-<?php echo $badge_cls; ?>"><?php echo $badge_lbl; ?></span></td>
    <td>
        <span class="tm-sync-pill tm-sync-pill-<?php echo s($syncmeta['class']); ?>"
              title="<?php echo s($syncmeta['tooltip']); ?>">
            <span class="tm-sync-icon" aria-hidden="true"><?php echo s($syncmeta['icon']); ?></span>
            <?php echo s($syncmeta['label']); ?>
        </span>
    </td>
    <?php if (!empty($session_has_vq)): ?>
    <td><?php
        $vqid = (int)($e->vq_submission_id ?? 0);
        if ($vqid > 0) {
            $urlvq = new moodle_url('/local/tm_course/admin/session_verification_review.php', ['submissionid' => $vqid]);
            echo html_writer::link($urlvq, get_string('session_vq_review_link_col', 'local_tm_course'), ['class' => 'btn btn-sm btn-outline-primary']);
        } else {
            echo html_writer::span('—', 'text-muted');
        }
    ?></td>
    <?php endif; ?>
    <td>
        <?php if ((int)$e->status === session_manager::ENROL_PENDING): ?>
            <form method="post" action="" style="display:inline-flex;align-items:center;gap:.35rem">
                <input type="hidden" name="sessionid" value="<?php echo (int)$sessionid; ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="enrolid" value="<?php echo (int)$e->id; ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <?php if ($session_is_online): ?>
                <input type="hidden" name="desk_number" value="0">
                <?php else: ?>
                <select name="desk_number" class="form-control form-control-sm" required style="width:auto;max-width:10rem"
                        aria-label="<?php echo get_string('approve_desk_select', 'local_tm_course'); ?>">
                    <option value=""><?php echo get_string('choosedots'); ?></option>
                    <?php for ($dn = 1; $dn <= (int) $se->num_desks; $dn++): ?>
                    <option value="<?php echo $dn; ?>"><?php echo get_string('label_desk', 'local_tm_course'); ?> <?php echo $dn; ?></option>
                    <?php endfor; ?>
                </select>
                <?php endif; ?>
                <button type="submit" class="btn btn-sm btn-tm-success"
                    onclick="return confirm(<?php echo json_encode(get_string('confirm_approve_enrolment', 'local_tm_course')); ?>)"><?php echo get_string('approve_enrolment', 'local_tm_course'); ?></button>
            </form>
            <form method="post" action="" style="display:inline-flex;align-items:center;gap:.35rem">
                <input type="hidden" name="sessionid" value="<?php echo (int)$sessionid; ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="enrolid" value="<?php echo (int)$e->id; ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="text" name="reason" class="form-control form-control-sm"
                       style="width:auto;max-width:14rem"
                       placeholder="<?php echo s(get_string('reject_reason_prompt_optional', 'local_tm_course')); ?>">
                <button type="submit" class="btn btn-sm btn-tm-danger"
                    onclick="return confirm(<?php echo json_encode(get_string('confirm_reject_enrolment', 'local_tm_course')); ?>)"><?php echo get_string('reject_enrolment', 'local_tm_course'); ?></button>
            </form>
        <?php elseif ((int)$e->status === session_manager::ENROL_APPROVED): ?>
            <form method="post" action="" style="display:inline-flex;align-items:center;gap:.35rem">
                <input type="hidden" name="sessionid" value="<?php echo (int)$sessionid; ?>">
                <input type="hidden" name="action" value="unapprove">
                <input type="hidden" name="enrolid" value="<?php echo (int)$e->id; ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <button type="submit" class="btn btn-sm btn-secondary"
                        onclick="return confirm(<?php echo json_encode(get_string('confirm_unapprove_enrolment', 'local_tm_course')); ?>)"><?php echo get_string('unapprove_enrolment', 'local_tm_course'); ?></button>
            </form>
        <?php elseif ($e->notes): ?>
            <span class="text-muted" title="<?php echo s($e->notes); ?>">ℹ <?php echo get_string('reject_reason_label', 'local_tm_course'); ?>: <?php echo s($e->notes); ?></span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php endif; ?>

</div><!-- .tm-card-body -->
</div><!-- .tm-card -->

<?php echo $OUTPUT->footer(); ?>
