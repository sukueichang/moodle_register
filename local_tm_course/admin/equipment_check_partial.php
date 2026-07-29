<?php
/**
 * Shared markup for the "設備檢查" (equipment check) section of the class prep page.
 * Renders one card per desk (or one card representing the instructor's own set for
 * online sessions), each with the applicable checklist items and a one-click
 * "sync to all desks" action.
 *
 * Expects: $equipmentview from equipment_check_manager::get_check_view(),
 *          $equipment_form_action POST target URL (string),
 *          $sessionid (int)
 *
 * @package    local_tm_course
 */
defined('MOODLE_INTERNAL') || die();

use local_tm_course\equipment_check_manager;

if (!isset($equipment_form_action)) {
    $equipment_form_action = '';
}
if (!isset($sessionid)) {
    $sessionid = 0;
}

$equip_items = $equipmentview['items'] ?? [];
$equip_desks = $equipmentview['desks'] ?? [];
$equip_isonline = !empty($equipmentview['is_online']);
$equip_deskcount = count($equip_desks);

if (empty($equip_items)):
?>
    <div class="tm-alert tm-alert-info">
        <?php echo get_string('equipment_check_no_items', 'local_tm_course'); ?>
    </div>
<?php else: ?>
    <div class="d-flex justify-content-end mb-2">
        <button type="button" id="tm-equip-save-all-btn" class="btn btn-sm btn-tm-primary">
            <?php echo get_string('equipment_check_save_all_button', 'local_tm_course'); ?>
        </button>
    </div>
    <form method="post" action="<?php echo s($equipment_form_action); ?>" id="tm-equip-save-all-form">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="sessionid" value="<?php echo (int) $sessionid; ?>">
        <input type="hidden" name="action" value="equipment_save_all">
    </form>
    <div class="tm-roster-grid tm-equip-grid">
    <?php foreach ($equip_desks as $desk): ?>
        <?php
        $desknumber = (int) $desk['desk_number'];
        $completed = (int) $desk['completed'];
        $total = (int) $desk['total'];
        $deskheading = $equip_isonline
            ? get_string('equipment_check_online_desk_label', 'local_tm_course')
            : get_string('equipment_check_desk_heading', 'local_tm_course', (object) ['n' => $desknumber]);
        ?>
        <div class="tm-roster-desk-card tm-equip-desk-card">
        <details class="tm-equip-desk-details">
            <summary class="tm-roster-desk-head tm-equip-desk-summary">
                <strong><?php echo s($deskheading); ?></strong>
                <span class="tm-equip-desk-summary-right">
                    <span class="text-muted small"><?php echo (int) $completed; ?>/<?php echo (int) $total; ?></span>
                    <span class="tm-equip-desk-arrow" aria-hidden="true">▸</span>
                </span>
            </summary>
            <form method="post" action="<?php echo s($equipment_form_action); ?>" class="tm-equip-form">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="sessionid" value="<?php echo (int) $sessionid; ?>">
                <input type="hidden" name="desknumber" value="<?php echo $desknumber; ?>">

                <?php foreach ($desk['items'] as $item): ?>
                    <?php
                    $itemid = (int) $item['itemid'];
                    $checktype = (string) $item['checktype'];
                    $checkstatus = (int) $item['checkstatus'];
                    $remark = (string) $item['remark'];
                    $fieldbase = 'equip[' . $itemid . ']';
                    ?>
                    <div class="tm-equip-item">
                        <div class="tm-equip-item-name"><?php echo s($item['itemname']); ?></div>
                        <div class="tm-equip-item-controls">
                            <?php if ($checktype === equipment_check_manager::TYPE_TASK): ?>
                                <label class="tm-equip-task-label">
                                    <input type="checkbox"
                                           name="<?php echo s($fieldbase); ?>[status]"
                                           value="done"
                                           <?php echo ($checkstatus === equipment_check_manager::STATUS_NORMAL) ? 'checked' : ''; ?>>
                                    <?php echo get_string('equipment_check_task_done', 'local_tm_course'); ?>
                                </label>
                            <?php else: ?>
                                <label class="tm-equip-radio-normal">
                                    <input type="radio"
                                           name="<?php echo s($fieldbase); ?>[status]"
                                           value="normal"
                                           <?php echo ($checkstatus === equipment_check_manager::STATUS_NORMAL) ? 'checked' : ''; ?>>
                                    <?php echo get_string('equipment_check_status_normal', 'local_tm_course'); ?>
                                </label>
                                <label class="tm-equip-radio-abnormal">
                                    <input type="radio"
                                           name="<?php echo s($fieldbase); ?>[status]"
                                           value="abnormal"
                                           <?php echo ($checkstatus === equipment_check_manager::STATUS_ABNORMAL) ? 'checked' : ''; ?>>
                                    <?php echo get_string('equipment_check_status_abnormal', 'local_tm_course'); ?>
                                </label>
                                <input type="text"
                                       class="form-control form-control-sm tm-equip-remark"
                                       name="<?php echo s($fieldbase); ?>[remark]"
                                       maxlength="255"
                                       placeholder="<?php echo s(get_string('equipment_check_remark_placeholder', 'local_tm_course')); ?>"
                                       value="<?php echo s($remark); ?>">
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($item['timemodified'])): ?>
                        <div class="text-muted small tm-equip-item-meta">
                            <?php echo get_string('equipment_check_last_checked', 'local_tm_course', (object) [
                                'name' => $item['checkedby_name'] !== '' ? $item['checkedby_name'] : '—',
                                'time' => userdate((int) $item['timemodified'], get_string('strftimedatetimeshort')),
                            ]); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="tm-equip-form-actions mt-2 d-flex flex-wrap gap-2">
                    <button type="submit" name="action" value="equipment_save" class="btn btn-sm btn-tm-success">
                        <?php echo get_string('equipment_check_save', 'local_tm_course'); ?>
                    </button>
                    <?php if ($equip_deskcount > 1): ?>
                    <button type="submit" name="action" value="equipment_sync" class="btn btn-sm btn-secondary"
                            onclick="return confirm(<?php echo json_encode(get_string('equipment_check_sync_confirm', 'local_tm_course'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);">
                        <?php echo get_string('equipment_check_sync_button', 'local_tm_course'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </details>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
