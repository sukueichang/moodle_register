<?php
/**
 * M4 — Permission rules for automatic batch-enrol access.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');

use local_tm_course\permissions_manager;

require_login();
require_capability('local/tm_course:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/tm_course/settings/permissions.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('nav_permissions', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$target = optional_param('target', permissions_manager::TARGET_BATCH, PARAM_ALPHANUMEXT);
if (!in_array($target, [permissions_manager::TARGET_BATCH, permissions_manager::TARGET_ATTENDANCE], true)) {
    $target = permissions_manager::TARGET_BATCH;
}

if ($action === 'add' && confirm_sesskey()) {
    $ruletype = optional_param('ruletype', '', PARAM_TEXT);
    $pattern = optional_param('pattern', '', PARAM_TEXT);
    try {
        permissions_manager::add_rule_for_target($target, $ruletype, $pattern);
        redirect($PAGE->url, get_string('perm_rule_added', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $t) {
        redirect($PAGE->url, $t->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'delete' && confirm_sesskey()) {
    $id = optional_param('id', 0, PARAM_INT);
    if ($id) {
        permissions_manager::delete_rule_for_target($target, $id);
        redirect($PAGE->url, get_string('perm_rule_deleted', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

if ($action === 'toggle' && confirm_sesskey()) {
    $id = optional_param('id', 0, PARAM_INT);
    $en = optional_param('enabled', 0, PARAM_INT);
    if ($id) {
        permissions_manager::toggle_rule_for_target($target, $id, $en);
        redirect($PAGE->url, get_string('changes_saved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$batchrules = permissions_manager::get_rules_for_target(permissions_manager::TARGET_BATCH);
$attendancerules = permissions_manager::get_rules_for_target(permissions_manager::TARGET_ATTENDANCE);

echo $OUTPUT->header();
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo get_string('nav_permissions', 'local_tm_course'); ?></h2>
</div>
<style>
    .tm-perm-entry {
        display:block;padding:12px 14px;border:1px solid #d8e2ea;border-radius:10px;
        background:#f8fbfd;text-decoration:none;color:inherit;cursor:pointer;
    }
    .tm-perm-entry.is-open {
        border-color:#0b5f7a;
        box-shadow:0 0 0 2px rgba(11,95,122,.15) inset;
        background:#eef6fb;
    }
    .tm-perm-section {
        border:1px solid #d5e3ec;border-radius:12px;overflow:hidden;background:#fff;
    }
    .tm-perm-section[hidden] { display:none !important; }
</style>
<div class="tm-card"><div class="tm-card-body">
    <p class="text-muted"><?php echo get_string('perm_rules_intro', 'local_tm_course'); ?></p>
    <p><a class="btn btn-sm btn-secondary" href="<?php echo (new moodle_url('/admin/roles/assign.php', ['contextid' => context_system::instance()->id]))->out(); ?>">
        <?php echo get_string('assignroles', 'role'); ?></a>
        <span class="ml-2"><?php echo get_string('perm_cap_batchenrol_hint', 'local_tm_course'); ?></span>
        <span class="ml-2"><?php echo get_string('perm_cap_attendance_hint', 'local_tm_course'); ?></span></p>

    <div class="mb-4" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
        <button type="button" class="tm-perm-entry" data-target="perm-batch">
            <div style="font-weight:700;color:#0b5f7a;"><?php echo s(get_string('perm_block_batch_title', 'local_tm_course')); ?></div>
            <div class="tm-session-muted" style="margin-top:4px;"><?php echo s(get_string('perm_block_batch_desc', 'local_tm_course')); ?></div>
        </button>
        <button type="button" class="tm-perm-entry" data-target="perm-attendance">
            <div style="font-weight:700;color:#0b5f7a;"><?php echo s(get_string('perm_block_attendance_title', 'local_tm_course')); ?></div>
            <div class="tm-session-muted" style="margin-top:4px;"><?php echo s(get_string('perm_block_attendance_desc', 'local_tm_course')); ?></div>
        </button>
    </div>

    <?php
    $render_rules = function(array $rules, string $target) {
        ?>
        <table class="tm-table">
            <thead><tr>
                <th><?php echo get_string('perm_rule_type', 'local_tm_course'); ?></th>
                <th><?php echo get_string('perm_pattern', 'local_tm_course'); ?></th>
                <th><?php echo get_string('perm_enabled', 'local_tm_course'); ?></th>
                <th><?php echo get_string('sessions_actions', 'local_tm_course'); ?></th>
            </tr></thead>
            <tbody>
            <?php if (empty($rules)): ?>
                <tr><td colspan="4"><?php echo get_string('perm_no_rules', 'local_tm_course'); ?></td></tr>
            <?php else: foreach ($rules as $r): ?>
                <tr>
                    <td><?php
                        if ($r->ruletype === permissions_manager::RULE_IDNUMBER) {
                            echo get_string('perm_rule_idnumber', 'local_tm_course');
                        } else if ($r->ruletype === permissions_manager::RULE_INSTITUTION) {
                            echo get_string('perm_rule_institution', 'local_tm_course');
                        } else {
                            echo get_string('perm_rule_name_contains', 'local_tm_course');
                        }
                    ?></td>
                    <td><?php echo s($r->pattern); ?></td>
                    <td>
                        <form method="post" action="" style="display:inline">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="target" value="<?php echo s($target); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">
                            <input type="hidden" name="enabled" value="<?php echo empty($r->enabled) ? '1' : '0'; ?>">
                            <button type="submit" class="btn btn-sm <?php echo empty($r->enabled) ? 'btn-outline-secondary' : 'btn-tm-success'; ?>">
                                <?php echo empty($r->enabled) ? get_string('no') : get_string('yes'); ?>
                            </button>
                        </form>
                    </td>
                    <td>
                        <form method="post" action="" style="display:inline" onsubmit="return confirm(<?php echo json_encode(get_string('perm_confirm_delete_rule', 'local_tm_course')); ?>);">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="target" value="<?php echo s($target); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">
                            <button type="submit" class="btn btn-sm btn-tm-danger"><?php echo get_string('delete'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <h4 class="mt-4"><?php echo get_string('perm_add_rule', 'local_tm_course'); ?></h4>
        <form method="post" action="" class="form-inline">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="target" value="<?php echo s($target); ?>">
            <select name="ruletype" class="form-control mr-2">
                <option value="<?php echo permissions_manager::RULE_IDNUMBER; ?>"><?php echo get_string('perm_rule_idnumber', 'local_tm_course'); ?></option>
                <option value="<?php echo permissions_manager::RULE_INSTITUTION; ?>"><?php echo get_string('perm_rule_institution', 'local_tm_course'); ?></option>
                <option value="<?php echo permissions_manager::RULE_NAME_CONTAINS; ?>"><?php echo get_string('perm_rule_name_contains', 'local_tm_course'); ?></option>
            </select>
            <input type="text" name="pattern" class="form-control mr-2" style="min-width:16rem" required maxlength="255"
                   placeholder="<?php echo s(get_string('perm_pattern_placeholder', 'local_tm_course')); ?>">
            <button type="submit" class="btn btn-tm-primary"><?php echo get_string('savechanges'); ?></button>
        </form>
        <?php
    };
    ?>

    <section id="perm-batch" class="mb-4 tm-perm-section" hidden>
        <div style="padding:12px 14px;background:#0b5f7a;color:#fff;">
            <div style="font-weight:700;"><?php echo s(get_string('perm_block_batch_title', 'local_tm_course')); ?></div>
            <div style="opacity:.9;font-size:13px;margin-top:2px;"><?php echo s(get_string('perm_block_batch_desc', 'local_tm_course')); ?></div>
        </div>
        <div style="padding:14px;">
            <?php $render_rules($batchrules, permissions_manager::TARGET_BATCH); ?>
        </div>
    </section>

    <section id="perm-attendance" class="mb-4 tm-perm-section" hidden>
        <div style="padding:12px 14px;background:#0b5f7a;color:#fff;">
            <div style="font-weight:700;"><?php echo s(get_string('perm_block_attendance_title', 'local_tm_course')); ?></div>
            <div style="opacity:.9;font-size:13px;margin-top:2px;"><?php echo s(get_string('perm_block_attendance_desc', 'local_tm_course')); ?></div>
        </div>
        <div style="padding:14px;">
            <?php $render_rules($attendancerules, permissions_manager::TARGET_ATTENDANCE); ?>
        </div>
    </section>
</div></div>
<script>
(function() {
    var entries = Array.prototype.slice.call(document.querySelectorAll('.tm-perm-entry'));
    var sections = Array.prototype.slice.call(document.querySelectorAll('.tm-perm-section'));
    function closeAll() {
        entries.forEach(function(btn) { btn.classList.remove('is-open'); });
        sections.forEach(function(sec) { sec.hidden = true; });
    }
    entries.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId = btn.getAttribute('data-target');
            var target = document.getElementById(targetId);
            if (!target) {
                return;
            }
            var willOpen = target.hidden;
            closeAll();
            if (willOpen) {
                target.hidden = false;
                btn.classList.add('is-open');
                target.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        });
    });
    closeAll();
})();
</script>
<?php
echo $OUTPUT->footer();
