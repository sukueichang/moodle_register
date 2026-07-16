<?php
/**
 * Standalone universal search page (also linked from admin menu)
 * URL: /local/tm_course/search.php
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/enrolment_manager.php');
require_once(__DIR__ . '/classes/permissions_manager.php');
require_once(__DIR__ . '/classes/certificate_helper.php');
use local_tm_course\enrolment_manager;
use local_tm_course\certificate_helper;
use local_tm_course\permissions_manager;
use local_tm_course\session_manager;

require_login();

$uf = optional_param('uf', '', PARAM_TEXT);
$ul = optional_param('ul', '', PARAM_TEXT);
$ui = optional_param('ui', '', PARAM_TEXT);
$ue = optional_param('ue', '', PARAM_TEXT);

$pageurlparams = [];
if ($uf !== '') {
    $pageurlparams['uf'] = $uf;
}
if ($ul !== '') {
    $pageurlparams['ul'] = $ul;
}
if ($ui !== '') {
    $pageurlparams['ui'] = $ui;
}
if ($ue !== '') {
    $pageurlparams['ue'] = $ue;
}
$PAGE->set_url(new moodle_url('/local/tm_course/search.php', $pageurlparams));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('nav_search', 'local_tm_course'));
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/local/tm_course/styles.css');

$ctx = context_system::instance();
$cansearchrecords = has_capability('local/tm_course:manage', $ctx) || permissions_manager::user_can_batch_enrol($USER);
if (!$cansearchrecords) {
    throw new required_capability_exception($ctx, 'local/tm_course:manage', 'nopermissions', '');
}
$can_viewall = true;
$can_edit_system_roles = is_siteadmin();

$results = [];
$search_attempt = ($uf !== '' || $ul !== '' || $ui !== '' || $ue !== '');
$search_hit_limit = false;
$user_hit_limit = false;
$search_filters = [
    'firstname' => $uf,
    'lastname' => $ul,
    'institution' => $ui,
    'email' => $ue,
];
if ($search_attempt) {
    try {
        $results = enrolment_manager::search_enrolments_by_user_filters($search_filters, $can_viewall, $USER->id, 100);
        $search_hit_limit = count($results) >= 100;
    } catch (\moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
}

$user_results = [];
if ($search_attempt) {
    try {
        $user_results = enrolment_manager::search_moodle_users($search_filters, 100);
        $user_hit_limit = count($user_results) >= 100;
    } catch (\moodle_exception $e) {
        if ($e->errorcode !== 'search_user_field_too_short') {
            \core\notification::error($e->getMessage());
        }
    }
}

$user_system_roles = [];
if (!empty($user_results)) {
    $role_userids = array_values(array_unique(array_map(static function(\stdClass $u): int {
        return (int)$u->id;
    }, $user_results)));
    $user_system_roles = permissions_manager::get_users_system_role_shortnames($role_userids);
}

$cert_user_names = [];
foreach ($user_results as $ur) {
    $cert_user_names[(int)$ur->id] = fullname($ur);
}

$certificate_results = [];
$certificate_hit_limit = false;
if ($search_attempt && !empty($user_results)) {
    $searchuserids = array_values(array_unique(array_map(static function(\stdClass $u): int {
        return (int)$u->id;
    }, $user_results)));
    $certificate_results = certificate_helper::get_certificates_by_user_ids($searchuserids, 100);
    $certificate_hit_limit = count($certificate_results) >= 100;
}

$search_show_desk_col = false;
foreach ($results as $rx) {
    if ((string)($rx->session_delivery_mode ?? '') !== session_manager::DELIVERY_ONLINE) {
        $search_show_desk_col = true;
        break;
    }
}
$sm = get_string_manager();
$str = static function(string $key, string $fallback, $a = null) use ($sm): string {
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

$learning_status_meta = static function(\stdClass $r) use ($str): array {
    if ((int)$r->status === session_manager::ENROL_CANCELLED) {
        return ['closed', $str('learning_status_cancelled', 'Cancelled')];
    }
    if ((int)$r->status === session_manager::ENROL_REJECTED) {
        return ['rejected', $str('learning_status_rejected', 'Rejected')];
    }
    if ((int)$r->status === session_manager::ENROL_WAITLISTED) {
        return ['pending', $str('learning_status_waitlisted', 'Waitlisted')];
    }
    if ((int)$r->status === session_manager::ENROL_PENDING) {
        return ['pending', $str('learning_status_pending', 'Pending review')];
    }
    if ((int)$r->status === session_manager::ENROL_APPROVED) {
        if (isset($r->attended) && (int)$r->attended === \local_tm_course\attendance_manager::ATTEND_PRESENT) {
            return ['approved', $str('learning_status_completed', 'Completed')];
        }
        if (isset($r->attended) && (int)$r->attended === \local_tm_course\attendance_manager::ATTEND_ABSENT) {
            return ['rejected', $str('learning_status_absent', 'Absent')];
        }
        return ['approved', $str('learning_status_approved', 'Approved')];
    }
    return ['closed', get_string('session_status_unknown', 'local_tm_course')];
};

$enrol_source_label = static function(\stdClass $r) use ($str): string {
    if (!empty($r->submitter_firstname) || !empty($r->submitter_lastname)) {
        return trim((string)$r->submitter_firstname . ' ' . (string)$r->submitter_lastname);
    }
    return $str('enrol_source_self', 'Self-enrolment');
};

echo $OUTPUT->header();
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2>🔍 <?php echo get_string('nav_search', 'local_tm_course'); ?></h2>
</div>
<div class="tm-card mt-3">
<div class="tm-card-body">

<p class="text-muted small mb-3"><?php echo get_string('search_user_intro', 'local_tm_course'); ?></p>
<form method="get" action="">
    <div class="row">
        <div class="col-md-6 mb-2">
            <label class="small font-weight-bold d-block" for="tm-search-ul"><?php echo get_string('search_user_lastname', 'local_tm_course'); ?></label>
            <input type="text" class="form-control form-control-sm" name="ul" id="tm-search-ul"
                   value="<?php echo s($ul); ?>" maxlength="100" autofocus>
        </div>
        <div class="col-md-6 mb-2">
            <label class="small font-weight-bold d-block" for="tm-search-uf"><?php echo get_string('search_user_firstname', 'local_tm_course'); ?></label>
            <input type="text" class="form-control form-control-sm" name="uf" id="tm-search-uf"
                   value="<?php echo s($uf); ?>" maxlength="100">
        </div>
        <div class="col-md-6 mb-2">
            <label class="small font-weight-bold d-block" for="tm-search-ui"><?php echo get_string('search_user_institution', 'local_tm_course'); ?></label>
            <input type="text" class="form-control form-control-sm" name="ui" id="tm-search-ui"
                   value="<?php echo s($ui); ?>" maxlength="255">
        </div>
        <div class="col-md-6 mb-2">
            <label class="small font-weight-bold d-block" for="tm-search-ue"><?php echo get_string('search_user_email', 'local_tm_course'); ?></label>
            <input type="text" class="form-control form-control-sm" name="ue" id="tm-search-ue"
                   value="<?php echo s($ue); ?>" maxlength="255">
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <button type="submit" class="btn btn-tm-primary"><?php echo get_string('search_user_submit', 'local_tm_course'); ?></button>
        <?php if ($search_attempt): ?>
        <a href="<?php echo (new moodle_url('/local/tm_course/search.php'))->out(false); ?>" class="btn btn-secondary"><?php echo get_string('clear_button', 'local_tm_course'); ?></a>
        <?php endif; ?>
    </div>
</form>

<?php if ($search_attempt): ?>
<hr>
<h4><?php echo get_string('search_user_results', 'local_tm_course'); ?>
    <small class="text-muted">(<?php echo count($user_results); ?> <?php echo get_string('search_user_records_suffix', 'local_tm_course'); ?>)</small></h4>
<?php if ($user_hit_limit): ?>
<div class="tm-alert tm-alert-warning"><?php echo get_string('search_user_limit_note', 'local_tm_course'); ?></div>
<?php endif; ?>
<?php if (empty($user_results)): ?>
    <div class="tm-alert tm-alert-info"><?php echo get_string('search_no_results', 'local_tm_course'); ?></div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="tm-table">
<thead><tr>
    <th><?php echo $str('label_learner_name', 'Name'); ?></th>
    <th><?php echo get_string('label_email', 'local_tm_course'); ?></th>
    <th><?php echo $str('label_company', 'Company'); ?></th>
    <th><?php echo get_string('search_user_col_username', 'local_tm_course'); ?></th>
    <th><?php echo get_string('search_user_col_system_roles', 'local_tm_course'); ?></th>
    <th><?php echo get_string('search_user_col_lastaccess', 'local_tm_course'); ?></th>
    <th><?php echo get_string('search_user_col_created', 'local_tm_course'); ?></th>
</tr></thead>
<tbody>
<?php foreach ($user_results as $ur): ?>
<tr>
    <td><?php echo s(trim($ur->firstname . ' ' . $ur->lastname)); ?>
        <?php if (!empty($ur->suspended)): ?>
        <span class="badge badge-secondary ml-1"><?php echo get_string('search_user_suspended', 'local_tm_course'); ?></span>
        <?php endif; ?>
    </td>
    <td><?php echo s($ur->email); ?></td>
    <td><?php echo s($ur->institution); ?></td>
    <td><?php echo s($ur->username); ?></td>
    <td><?php
        $rolesdisplay = $user_system_roles[(int)$ur->id] ?? '';
        if ($can_edit_system_roles) {
            $roletext = $rolesdisplay !== '' ? $rolesdisplay : get_string('search_roles_empty', 'local_tm_course');
            echo '<span class="tm-search-roles-cell" tabindex="0" role="button"'
                . ' data-userid="' . (int)$ur->id . '"'
                . ' data-roles="' . s($rolesdisplay) . '"'
                . ' data-hint="' . s(get_string('search_roles_edit_hint', 'local_tm_course')) . '">';
            echo '<span class="tm-search-roles-text">' . s($roletext) . '</span>';
            echo '</span>';
        } else {
            echo s($rolesdisplay);
        }
    ?></td>
    <td><?php echo !empty($ur->lastaccess) ? userdate((int)$ur->lastaccess, get_string('strftimedatetimeshort'))
        : get_string('search_user_never_logged_in', 'local_tm_course'); ?></td>
    <td><?php echo userdate((int)$ur->timecreated, get_string('strftimedatetimeshort')); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<hr>
<h4><?php echo get_string('search_course_records', 'local_tm_course'); ?>
    <small class="text-muted">(<?php echo count($results); ?> <?php echo get_string('search_user_records_suffix', 'local_tm_course'); ?>)</small></h4>
<?php if ($search_hit_limit): ?>
<div class="tm-alert tm-alert-warning"><?php echo get_string('search_user_limit_note', 'local_tm_course'); ?></div>
<?php endif; ?>
<?php if (empty($results)): ?>
    <div class="tm-alert tm-alert-info"><?php echo get_string('search_no_results', 'local_tm_course'); ?></div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="tm-table">
<thead><tr>
    <th><?php echo $str('label_learner_name', 'Name'); ?></th>
    <th><?php echo get_string('label_email', 'local_tm_course'); ?></th>
    <th><?php echo $str('label_company', 'Company'); ?></th>
    <th><?php echo get_string('search_enrol_sales', 'local_tm_course'); ?></th>
    <th><?php echo get_string('session_name', 'local_tm_course'); ?></th>
    <th><?php echo get_string('label_start', 'local_tm_course'); ?></th>
    <?php if ($search_show_desk_col): ?>
    <th><?php echo get_string('label_desk', 'local_tm_course'); ?></th>
    <?php endif; ?>
    <th><?php echo $str('label_learning_status', 'Class status'); ?></th>
</tr></thead>
<tbody>
<?php foreach ($results as $r):
    [$badge_cls, $badge_lbl] = $learning_status_meta($r);
?>
<tr>
    <td><?php echo s($r->firstname.' '.$r->lastname); ?></td>
    <td><?php echo s($r->email); ?></td>
    <td><?php echo s($r->user_institution); ?></td>
    <td><?php echo s($enrol_source_label($r)); ?></td>
    <td><?php echo s($r->session_name); ?></td>
    <td><?php echo userdate($r->starttime, get_string('strftimedatetimeshort')); ?></td>
    <?php if ($search_show_desk_col): ?>
    <td><?php
    if ((string)($r->session_delivery_mode ?? '') === session_manager::DELIVERY_ONLINE) {
        echo '—';
    } else {
        echo (!empty($r->desk_number) && (int) $r->status === session_manager::ENROL_APPROVED)
            ? get_string('desk_assigned_to', 'local_tm_course', (int) $r->desk_number) : '—';
    }
    ?></td>
    <?php endif; ?>
    <td><span class="tm-badge tm-badge-<?php echo $badge_cls; ?>"><?php echo $badge_lbl; ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<hr>
<h4><?php echo $str('search_certificate_records', 'Certificate records'); ?>
    <small class="text-muted">(<?php echo count($certificate_results); ?> <?php echo get_string('search_user_records_suffix', 'local_tm_course'); ?>)</small></h4>
<?php if ($certificate_hit_limit): ?>
<div class="tm-alert tm-alert-warning"><?php echo get_string('search_user_limit_note', 'local_tm_course'); ?></div>
<?php endif; ?>
<?php if (empty($certificate_results)): ?>
    <div class="tm-alert tm-alert-info"><?php echo get_string('search_no_results', 'local_tm_course'); ?></div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="tm-table">
<thead><tr>
    <th><?php echo $str('label_learner_name', 'Name'); ?></th>
    <th><?php echo $str('certificate_course_name', 'Course name'); ?></th>
    <th><?php echo $str('certificate_issue_time', 'Awarded on'); ?></th>
    <th><?php echo $str('search_certificate_code', 'Code'); ?></th>
    <th><?php echo $str('label_certificate', 'Certificate'); ?></th>
</tr></thead>
<tbody>
<?php foreach ($certificate_results as $cert): ?>
<tr>
    <td><?php echo s($cert_user_names[(int)$cert->userid] ?? ''); ?></td>
    <td><?php echo format_string((string)$cert->coursename); ?></td>
    <td><?php echo !empty($cert->timecreated)
        ? userdate((int)$cert->timecreated, get_string('strftimedatetimeshort'))
        : '—'; ?></td>
    <td><?php echo !empty($cert->code) ? s((string)$cert->code) : '—'; ?></td>
    <td><?php
        $downloadurl = certificate_helper::get_download_url_for_viewer(
            (int)$cert->courseid,
            (int)$cert->userid,
            $USER,
            (int)$cert->cmid
        );
        if ($downloadurl) {
            echo html_writer::link(
                $downloadurl->out(false),
                $str('download_certificate', 'Download Certificate'),
                ['class' => 'btn btn-secondary btn-sm']
            );
        } else {
            echo '';
        }
    ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>

</div></div>

<?php if ($can_edit_system_roles && $search_attempt && !empty($user_results)): ?>
<div id="tm-search-roles-modal" class="tm-cancel-modal-backdrop" hidden>
    <div class="tm-cancel-modal-panel tm-mode-modal-panel" role="dialog" aria-modal="true" aria-labelledby="tm-search-roles-modal-title">
        <h4 id="tm-search-roles-modal-title"><?php echo get_string('search_roles_modal_title', 'local_tm_course'); ?></h4>
        <div id="tm-search-roles-modal-body" class="mb-3" style="max-height:22rem;overflow:auto"></div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-tm-primary" id="tm-search-roles-modal-save"><?php echo get_string('search_roles_save', 'local_tm_course'); ?></button>
            <button type="button" class="btn btn-secondary" id="tm-search-roles-modal-close"><?php echo get_string('cancel'); ?></button>
        </div>
    </div>
</div>
<?php
$searchrolesjs_path = __DIR__ . '/search_roles.js';
$searchrolesjs_ver = file_exists($searchrolesjs_path) ? filemtime($searchrolesjs_path) : time();
$searchroles_cfg = [
    'apiBase' => (new moodle_url('/local/tm_course/search_user_roles.php'))->out(false),
    'sesskey' => sesskey(),
    'str' => [
        'modalTitle' => get_string('search_roles_modal_user', 'local_tm_course'),
        'editHint' => get_string('search_roles_edit_hint', 'local_tm_course'),
        'emptyRoles' => get_string('search_roles_empty', 'local_tm_course'),
        'loading' => get_string('search_roles_loading', 'local_tm_course'),
        'save' => get_string('search_roles_save', 'local_tm_course'),
        'saving' => get_string('search_roles_saving', 'local_tm_course'),
        'loadError' => get_string('search_roles_load_error', 'local_tm_course'),
        'saveError' => get_string('search_roles_save_error', 'local_tm_course'),
        'noRolesAvailable' => get_string('search_roles_no_options', 'local_tm_course'),
        'readonlyHeader' => get_string('search_roles_readonly_header', 'local_tm_course'),
        'readonlyNote' => get_string('search_roles_readonly_note', 'local_tm_course'),
        'editableHeader' => get_string('search_roles_editable_header', 'local_tm_course'),
    ],
];
$searchrolesjs_url = new moodle_url('/local/tm_course/search_roles.js', ['v' => $searchrolesjs_ver]);
?>
<script>window.tmSearchRolesCfg=<?php echo json_encode($searchroles_cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="<?php echo $searchrolesjs_url->out(); ?>"></script>
<?php endif; ?>

<?php echo $OUTPUT->footer(); ?>
