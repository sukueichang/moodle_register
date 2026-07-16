<?php
/**
 * Moodle optimization settings — site-level tweaks without server shell access.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/moodle_optimization_manager.php');

use local_tm_course\moodle_optimization_manager;

require_login();
if (!is_siteadmin()) {
    throw new \moodle_exception('nopermissions', 'error');
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/tm_course/settings/moodle_optimization.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('nav_moodle_optimization', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

if ($action === 'disable_default_role' && confirm_sesskey()) {
    moodle_optimization_manager::disable_default_user_role();
    redirect(
        $PAGE->url,
        get_string('moodle_opt_default_disabled', 'local_tm_course'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'cleanup_overlay' && confirm_sesskey()) {
    $keepbyindex = optional_param_array('rule_keep', [], PARAM_ALPHANUMEXT);
    $rules = moodle_optimization_manager::parse_cleanup_rules_from_form(
        $keepbyindex,
        isset($_POST['rule_remove']) && is_array($_POST['rule_remove']) ? $_POST['rule_remove'] : []
    );
    if ($rules === []) {
        redirect($PAGE->url, get_string('moodle_opt_cleanup_rules_required', 'local_tm_course'), null, \core\output\notification::NOTIFY_ERROR);
    }
    moodle_optimization_manager::save_cleanup_rules($rules);
    $stats = moodle_optimization_manager::cleanup_by_rules($rules);
    redirect(
        $PAGE->url,
        get_string('moodle_opt_cleanup_done', 'local_tm_course', (object)[
            'users' => (int)$stats['users'],
            'removed' => (int)$stats['removed'],
            'rules' => (int)$stats['rules'],
        ]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'reset_cleanup_rules' && confirm_sesskey()) {
    moodle_optimization_manager::save_cleanup_rules(moodle_optimization_manager::default_cleanup_rules());
    redirect($PAGE->url, get_string('moodle_opt_cleanup_rules_reset', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'assign_fallback' && confirm_sesskey()) {
    $roleid = required_param('fallback_roleid', PARAM_INT);
    $includeoverlay = optional_param('include_overlay', 0, PARAM_BOOL);
    $removeoverlay = optional_param('remove_overlay', 0, PARAM_BOOL);
    try {
        $stats = moodle_optimization_manager::bulk_assign_fallback_role($roleid, $includeoverlay, $removeoverlay);
        $rolename = '';
        foreach (moodle_optimization_manager::get_assignable_role_options() as $role) {
            if ((int)$role['id'] === (int)$stats['roleid']) {
                $rolename = $role['name'];
                break;
            }
        }
        redirect(
            $PAGE->url,
            get_string('moodle_opt_fallback_done', 'local_tm_course', (object)[
                'matched' => (int)$stats['matched'],
                'assigned' => (int)$stats['assigned'],
                'skipped' => (int)$stats['skipped'],
                'removed' => (int)$stats['overlay_removed'],
                'role' => $rolename !== '' ? $rolename : (string)$stats['roleid'],
            ]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\moodle_exception $e) {
        redirect($PAGE->url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$fallbackroleid = optional_param('fallback_roleid', moodle_optimization_manager::get_configured_fallback_roleid(), PARAM_INT);
$includeoverlaypreview = optional_param('include_overlay', moodle_optimization_manager::is_fallback_include_overlay() ? 1 : 0, PARAM_BOOL);
$removeoverlaypreview = optional_param('remove_overlay', moodle_optimization_manager::is_fallback_remove_overlay() ? 1 : 0, PARAM_BOOL);

$defaultstatus = moodle_optimization_manager::get_default_user_role_status();
$cleanuprules = moodle_optimization_manager::get_cleanup_rules();
$cleanupkeepoptions = moodle_optimization_manager::get_assignable_role_options();
$cleanupremoveoptions = moodle_optimization_manager::get_cleanup_removable_role_options();
$lastcleanup = moodle_optimization_manager::get_last_cleanup_stats();
$assignableroles = moodle_optimization_manager::get_assignable_role_options();
$fallbackcount = moodle_optimization_manager::count_users_needing_fallback((bool)$includeoverlaypreview);
$fallbackpreview = moodle_optimization_manager::preview_users_needing_fallback((bool)$includeoverlaypreview);
$lastfallback = moodle_optimization_manager::get_last_fallback_stats();

echo $OUTPUT->header();
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo get_string('nav_moodle_optimization', 'local_tm_course'); ?></h2>
</div>
<div class="tm-card"><div class="tm-card-body">
    <p class="text-muted"><?php echo get_string('moodle_opt_intro', 'local_tm_course'); ?></p>

    <section class="mb-4" style="border:1px solid #d5e3ec;border-radius:12px;overflow:hidden;background:#fff;">
        <div style="padding:12px 14px;background:#0b5f7a;color:#fff;">
            <div style="font-weight:700;"><?php echo s(get_string('moodle_opt_block_default_title', 'local_tm_course')); ?></div>
            <div style="opacity:.9;font-size:13px;margin-top:2px;"><?php echo s(get_string('moodle_opt_block_default_desc', 'local_tm_course')); ?></div>
        </div>
        <div style="padding:14px;">
            <?php if ($defaultstatus['enabled']): ?>
                <p><?php echo get_string('moodle_opt_default_status_on', 'local_tm_course', (object)[
                    'name' => $defaultstatus['rolename'],
                    'shortname' => $defaultstatus['shortname'],
                ]); ?></p>
                <form method="post" action="" onsubmit="return confirm(<?php echo json_encode(get_string('moodle_opt_default_confirm', 'local_tm_course')); ?>);">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="disable_default_role">
                    <button type="submit" class="btn btn-tm-primary"><?php echo get_string('moodle_opt_default_disable_btn', 'local_tm_course'); ?></button>
                </form>
            <?php else: ?>
                <p class="mb-0 text-success"><?php echo get_string('moodle_opt_default_status_off', 'local_tm_course'); ?></p>
            <?php endif; ?>
        </div>
    </section>

    <section class="mb-4" style="border:1px solid #d5e3ec;border-radius:12px;overflow:hidden;background:#fff;">
        <div style="padding:12px 14px;background:#0b5f7a;color:#fff;">
            <div style="font-weight:700;"><?php echo s(get_string('moodle_opt_block_cleanup_title', 'local_tm_course')); ?></div>
            <div style="opacity:.9;font-size:13px;margin-top:2px;"><?php echo s(get_string('moodle_opt_block_cleanup_desc', 'local_tm_course')); ?></div>
        </div>
        <div style="padding:14px;">
            <p><?php echo get_string('moodle_opt_cleanup_help', 'local_tm_course'); ?></p>
            <?php if ($lastcleanup): ?>
                <p class="tm-session-muted">
                    <?php echo get_string('moodle_opt_cleanup_last', 'local_tm_course', (object)[
                        'time' => userdate((int)($lastcleanup['time'] ?? 0)),
                        'users' => (int)($lastcleanup['users'] ?? 0),
                        'removed' => (int)($lastcleanup['removed'] ?? 0),
                        'rules' => (int)($lastcleanup['rules'] ?? 0),
                    ]); ?>
                </p>
            <?php endif; ?>

            <?php if (empty($cleanupkeepoptions) || empty($cleanupremoveoptions)): ?>
                <p class="text-warning"><?php echo get_string('moodle_opt_cleanup_no_roles', 'local_tm_course'); ?></p>
            <?php else: ?>
                <form method="post" action="" onsubmit="return confirm(<?php echo json_encode(get_string('moodle_opt_cleanup_confirm', 'local_tm_course')); ?>);">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="cleanup_overlay">
                    <div class="table-responsive mb-3">
                        <table class="table table-sm tm-table">
                            <thead>
                                <tr>
                                    <th><?php echo get_string('moodle_opt_cleanup_col_keep', 'local_tm_course'); ?></th>
                                    <th><?php echo get_string('moodle_opt_cleanup_col_remove', 'local_tm_course'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cleanuprules as $idx => $rule): ?>
                                    <tr>
                                        <td style="min-width:14rem;vertical-align:top;">
                                            <select class="custom-select" name="rule_keep[<?php echo (int)$idx; ?>]">
                                                <option value=""><?php echo get_string('moodle_opt_fallback_role_choose', 'local_tm_course'); ?></option>
                                                <?php foreach ($cleanupkeepoptions as $role): ?>
                                                    <option value="<?php echo s($role['shortname']); ?>"
                                                        <?php echo $rule['keep'] === $role['shortname'] ? 'selected' : ''; ?>>
                                                        <?php echo s($role['name']); ?> (<?php echo s($role['shortname']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:4px 12px;">
                                                <?php foreach ($cleanupremoveoptions as $removerole): ?>
                                                    <?php
                                                    $rid = 'rule_remove_' . $idx . '_' . $removerole['shortname'];
                                                    $checked = in_array($removerole['shortname'], $rule['remove'], true);
                                                    $disabled = $rule['keep'] === $removerole['shortname'];
                                                    ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox"
                                                               name="rule_remove[<?php echo (int)$idx; ?>][]"
                                                               id="<?php echo s($rid); ?>"
                                                               value="<?php echo s($removerole['shortname']); ?>"
                                                            <?php echo $checked ? 'checked' : ''; ?>
                                                            <?php echo $disabled ? 'disabled' : ''; ?>>
                                                        <label class="form-check-label" for="<?php echo s($rid); ?>">
                                                            <?php echo s($removerole['name']); ?>
                                                            <span class="tm-session-muted">(<?php echo s($removerole['shortname']); ?>)</span>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="btn btn-tm-primary"><?php echo get_string('moodle_opt_cleanup_btn', 'local_tm_course'); ?></button>
                </form>
                <form method="post" action="" class="mb-3" onsubmit="return confirm(<?php echo json_encode(get_string('moodle_opt_cleanup_reset_confirm', 'local_tm_course')); ?>);">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="reset_cleanup_rules">
                    <button type="submit" class="btn btn-secondary"><?php echo get_string('moodle_opt_cleanup_reset_btn', 'local_tm_course'); ?></button>
                </form>
                <p class="tm-session-muted mb-0"><?php echo get_string('moodle_opt_cleanup_example', 'local_tm_course'); ?></p>
            <?php endif; ?>
        </div>
    </section>

    <section class="mb-4" style="border:1px solid #d5e3ec;border-radius:12px;overflow:hidden;background:#fff;">
        <div style="padding:12px 14px;background:#0b5f7a;color:#fff;">
            <div style="font-weight:700;"><?php echo s(get_string('moodle_opt_block_fallback_title', 'local_tm_course')); ?></div>
            <div style="opacity:.9;font-size:13px;margin-top:2px;"><?php echo s(get_string('moodle_opt_block_fallback_desc', 'local_tm_course')); ?></div>
        </div>
        <div style="padding:14px;">
            <p><?php echo get_string('moodle_opt_fallback_help', 'local_tm_course'); ?></p>

            <?php if ($lastfallback): ?>
                <p class="tm-session-muted">
                    <?php echo get_string('moodle_opt_fallback_last', 'local_tm_course', (object)[
                        'time' => userdate((int)($lastfallback['time'] ?? 0)),
                        'matched' => (int)($lastfallback['matched'] ?? 0),
                        'assigned' => (int)($lastfallback['assigned'] ?? 0),
                        'skipped' => (int)($lastfallback['skipped'] ?? 0),
                        'removed' => (int)($lastfallback['overlay_removed'] ?? 0),
                    ]); ?>
                </p>
            <?php endif; ?>

            <?php if (empty($assignableroles)): ?>
                <p class="text-warning"><?php echo get_string('moodle_opt_fallback_no_roles', 'local_tm_course'); ?></p>
            <?php else: ?>
                <form method="get" action="" class="mb-3">
                    <div class="form-group">
                        <label for="fallback_roleid"><strong><?php echo get_string('moodle_opt_fallback_role_label', 'local_tm_course'); ?></strong></label>
                        <select class="custom-select" name="fallback_roleid" id="fallback_roleid" style="max-width:28rem;">
                            <option value="0"><?php echo get_string('moodle_opt_fallback_role_choose', 'local_tm_course'); ?></option>
                            <?php foreach ($assignableroles as $role): ?>
                                <option value="<?php echo (int)$role['id']; ?>" <?php echo (int)$fallbackroleid === (int)$role['id'] ? 'selected' : ''; ?>>
                                    <?php echo s($role['name']); ?> (<?php echo s($role['shortname']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="include_overlay" id="include_overlay" value="1"
                            <?php echo $includeoverlaypreview ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="include_overlay">
                            <?php echo get_string('moodle_opt_fallback_include_overlay', 'local_tm_course'); ?>
                        </label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remove_overlay" id="remove_overlay" value="1"
                            <?php echo $removeoverlaypreview ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="remove_overlay">
                            <?php echo get_string('moodle_opt_fallback_remove_overlay', 'local_tm_course'); ?>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-secondary"><?php echo get_string('moodle_opt_fallback_refresh', 'local_tm_course'); ?></button>
                </form>

                <p><strong><?php echo get_string('moodle_opt_fallback_count', 'local_tm_course', (object)['count' => $fallbackcount]); ?></strong></p>

                <?php if (!empty($fallbackpreview)): ?>
                    <p class="tm-session-muted"><?php echo get_string('moodle_opt_fallback_preview_note', 'local_tm_course', (object)[
                        'shown' => count($fallbackpreview),
                        'total' => $fallbackcount,
                    ]); ?></p>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm tm-table">
                            <thead>
                                <tr>
                                    <th><?php echo get_string('fullname'); ?></th>
                                    <th><?php echo get_string('email'); ?></th>
                                    <th><?php echo get_string('search_user_col_system_roles', 'local_tm_course'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fallbackpreview as $row): ?>
                                    <tr>
                                        <td><?php echo s($row['fullname']); ?></td>
                                        <td><?php echo s($row['email']); ?></td>
                                        <td><?php echo s($row['roles']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-success mb-3"><?php echo get_string('moodle_opt_fallback_none', 'local_tm_course'); ?></p>
                <?php endif; ?>

                <form method="post" action="" onsubmit="return confirm(<?php echo json_encode(get_string('moodle_opt_fallback_confirm', 'local_tm_course')); ?>);">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="assign_fallback">
                    <input type="hidden" name="fallback_roleid" value="<?php echo (int)$fallbackroleid; ?>">
                    <input type="hidden" name="include_overlay" value="<?php echo $includeoverlaypreview ? 1 : 0; ?>">
                    <input type="hidden" name="remove_overlay" value="<?php echo $removeoverlaypreview ? 1 : 0; ?>">
                    <button type="submit" class="btn btn-tm-primary" <?php echo $fallbackroleid < 1 || $fallbackcount < 1 ? 'disabled' : ''; ?>>
                        <?php echo get_string('moodle_opt_fallback_btn', 'local_tm_course'); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section style="border:1px dashed #c5d5e0;border-radius:12px;padding:14px;background:#f8fbfd;">
        <p class="mb-0 tm-session-muted"><?php echo get_string('moodle_opt_future_hint', 'local_tm_course'); ?></p>
    </section>
</div></div>
<?php
echo $OUTPUT->footer();
