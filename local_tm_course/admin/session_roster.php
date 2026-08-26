<?php
/**
 * Read-only session roster: desk layout (onsite) or learner list (online).
 * URL: /local/tm_course/admin/session_roster.php?sessionid=N
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/enrolment_manager.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');

use local_tm_course\enrolment_manager;
use local_tm_course\permissions_manager;

require_login();

$ctx = context_system::instance();
$canview = is_siteadmin()
    || has_capability('local/tm_course:manage', $ctx)
    || permissions_manager::user_can_batch_enrol();
if (!$canview) {
    throw new required_capability_exception($ctx, 'local/tm_course:manage', 'nopermissions', '');
}

$sessionid = required_param('sessionid', PARAM_INT);

// Approvers/admins: interactive desk board (drag / approve / batch add). Sales stay on read-only.
if (is_siteadmin()
    || has_capability('local/tm_course:approve', $ctx)
    || has_capability('local/tm_course:manage', $ctx)) {
    redirect(new moodle_url('/local/tm_course/admin/enrolments.php', ['sessionid' => $sessionid]));
}

try {
    $view = enrolment_manager::build_session_roster_view($sessionid);
} catch (\dml_missing_record_exception $ex) {
    throw new moodle_exception('invalidrecord', 'error');
}

$session = $view['session'];
$backurl = has_capability('local/tm_course:manage', $ctx)
    ? new moodle_url('/local/tm_course/admin/sessions.php')
    : new moodle_url('/local/tm_course/index.php');

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/tm_course/admin/session_roster.php', ['sessionid' => $sessionid]));
$PAGE->set_pagelayout(has_capability('local/tm_course:manage', $ctx) ? 'admin' : 'standard');
$PAGE->set_title(get_string('session_roster_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

echo $OUTPUT->header();
?>

<div class="tm-page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div class="d-flex align-items-center gap-2">
        <span class="tm-logo-dot"></span>
        <h2 class="mb-0"><?php echo get_string('session_roster_title', 'local_tm_course'); ?></h2>
    </div>
    <a href="<?php echo $backurl->out(); ?>" class="btn btn-sm btn-secondary">
        <?php echo get_string('back'); ?>
    </a>
</div>

<div class="tm-card mt-3"><div class="tm-card-body">
    <div class="row mb-3">
        <div class="col-md-4">
            <strong><?php echo get_string('label_name', 'local_tm_course'); ?>:</strong><br>
            <?php echo s($session->name); ?>
        </div>
        <div class="col-md-4">
            <strong><?php echo get_string('label_start', 'local_tm_course'); ?>:</strong><br>
            <?php echo userdate((int) $session->starttime, get_string('strftimedatetimeshort')); ?>
        </div>
        <div class="col-md-4">
            <strong><?php echo get_string('label_location', 'local_tm_course'); ?>:</strong><br>
            <?php echo s($session->location); ?>
        </div>
    </div>
    <p class="text-muted mb-3">
        <?php echo get_string('session_roster_approved_only', 'local_tm_course'); ?>
        &mdash;
        <?php echo get_string('session_roster_total', 'local_tm_course', (object) ['n' => (int) $view['total']]); ?>
    </p>

<?php if ((int) $view['total'] === 0): ?>
    <div class="tm-alert tm-alert-info"><?php echo get_string('session_roster_empty', 'local_tm_course'); ?></div>
<?php elseif (!empty($view['is_online'])): ?>
    <p class="small text-muted"><?php echo get_string('session_roster_online_hint', 'local_tm_course'); ?></p>
    <table class="tm-table">
        <thead>
            <tr>
                <th><?php echo get_string('institution', 'local_tm_course'); ?></th>
                <th><?php echo get_string('label_learner_name', 'local_tm_course'); ?></th>
                <th><?php echo get_string('label_enrol_source', 'local_tm_course'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($view['online_groups'] as $instlabel => $group): ?>
            <?php foreach ($group as $idx => $learner): ?>
            <tr>
                <td><?php echo $idx === 0 ? s($instlabel) : ''; ?></td>
                <td><?php echo s($learner['displayname']); ?></td>
                <td><?php echo s($learner['source_label']); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="tm-roster-grid">
    <?php foreach ($view['desks'] as $desk): ?>
        <?php
        $count = count($desk['learners']);
        $cap = (int) $desk['capacity'];
        ?>
        <div class="tm-roster-desk-card">
            <div class="tm-roster-desk-head">
                <strong><?php echo get_string('session_roster_desk_heading', 'local_tm_course', (object) ['n' => (int) $desk['desk_number']]); ?></strong>
                <span class="text-muted small"><?php echo get_string('session_roster_desk_count', 'local_tm_course', (object) ['n' => $count, 'cap' => $cap]); ?></span>
            </div>
            <?php if ($count === 0): ?>
                <p class="text-muted small mb-0"><?php echo get_string('session_roster_desk_empty', 'local_tm_course'); ?></p>
            <?php else: ?>
                <ul class="tm-roster-learner-list mb-0 pl-3">
                <?php foreach ($desk['learners'] as $learner): ?>
                    <li class="tm-roster-learner-item">
                        <span class="tm-roster-learner-name"><?php echo s($learner['displayname']); ?></span>
                        <?php if ($learner['institution'] !== ''): ?>
                        <span class="text-muted small d-block"><?php echo s($learner['institution']); ?></span>
                        <?php endif; ?>
                        <span class="text-muted small d-block tm-roster-source"><?php echo s($learner['source_label']); ?></span>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>

    <?php if (!empty($view['unassigned'])): ?>
    <div class="tm-roster-unassigned mt-4">
        <h4 class="h5"><?php echo get_string('session_roster_unassigned_heading', 'local_tm_course'); ?></h4>
        <ul class="tm-roster-learner-list mb-0 pl-3">
        <?php foreach ($view['unassigned'] as $learner): ?>
            <li class="tm-roster-learner-item">
                <span class="tm-roster-learner-name"><?php echo s($learner['displayname']); ?></span>
                <?php if ($learner['institution'] !== ''): ?>
                <span class="text-muted small d-block"><?php echo s($learner['institution']); ?></span>
                <?php endif; ?>
                <span class="text-muted small d-block tm-roster-source"><?php echo s($learner['source_label']); ?></span>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
<?php endif; ?>

</div></div>

<?php
echo $OUTPUT->footer();
