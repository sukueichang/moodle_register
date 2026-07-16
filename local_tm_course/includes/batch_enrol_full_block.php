<?php
/**
 * Reusable batch enrolment UI — full learner list (block B) only.
 *
 * Expected variables in scope:
 *   $batchblockcfg (array)
 *
 * @package    local_tm_course
 */

defined('MOODLE_INTERNAL') || die();

$bbcfg = $batchblockcfg ?? [];
$bbdisabled = !empty($bbcfg['disabled']);
$bbnote = (string) ($bbcfg['batch_submitternote'] ?? '');
$bbhint = (string) ($bbcfg['context_hint'] ?? '');
$bbrows = $bbcfg['initial_rows'] ?? [];
if (!is_array($bbrows)) {
    $bbrows = [];
}

?>
<div class="tm-batch-reservation-wrap" data-tm-batch-context="<?php echo s((string)($bbcfg['context'] ?? 'session')); ?>">
<?php if ($bbhint !== ''): ?>
    <p class="text-muted small mb-2"><?php echo $bbhint; ?></p>
<?php endif; ?>

<div class="form-group mt-2">
    <label for="batch_submitternote"><strong><?php echo get_string('batch_submitter_note_label', 'local_tm_course'); ?></strong></label>
    <textarea name="batch_submitternote" id="batch_submitternote" class="form-control" rows="3" maxlength="2000"
        <?php echo $bbdisabled ? 'disabled="disabled"' : ''; ?>><?php echo s($bbnote); ?></textarea>
    <div class="tm-form-hint text-muted small"><?php echo get_string('batch_submitter_note_hint', 'local_tm_course'); ?></div>
</div>

<input type="hidden" name="batch_mode" value="full">
<input type="hidden" name="batch_confirmed" id="batch_confirmed" value="0">

<div class="tm-batch-section border rounded p-3 mt-3">
<fieldset class="border-0 p-0 m-0" id="tm-fieldset-full">
    <legend class="w-auto px-0"><?php echo get_string('batch_block_full_title', 'local_tm_course'); ?></legend>
    <p class="text-muted small"><?php echo get_string('batch_block_full_intro', 'local_tm_course'); ?></p>
    <table class="tm-table" id="tm-batch-rows">
        <thead><tr>
            <th><?php echo get_string('batch_firstname', 'local_tm_course'); ?></th>
            <th><?php echo get_string('batch_lastname', 'local_tm_course'); ?></th>
            <th><?php echo get_string('label_email', 'local_tm_course'); ?></th>
            <th><?php echo get_string('institution', 'local_tm_course'); ?></th>
            <th><?php echo get_string('diet_survey_title', 'local_tm_course'); ?></th>
            <th></th>
        </tr></thead>
        <tbody id="tm-batch-rows-tbody">
        <tr id="tm-batch-row-prototype" class="tm-batch-is-prototype tm-batch-row">
            <td><input type="text" class="form-control form-control-sm entry_firstname" maxlength="100" disabled tabindex="-1"></td>
            <td><input type="text" class="form-control form-control-sm entry_lastname" maxlength="100" disabled tabindex="-1"></td>
            <td><input type="email" class="form-control form-control-sm entry_email" placeholder="user@example.com" disabled tabindex="-1"></td>
            <td><input type="text" class="form-control form-control-sm entry_institution" maxlength="255" disabled tabindex="-1"></td>
            <td>
                <select class="form-control form-control-sm entry_diet" disabled tabindex="-1">
                    <option value="">—</option>
                    <option value="A"><?php echo get_string('diet_choice_meat', 'local_tm_course'); ?></option>
                    <option value="B"><?php echo get_string('diet_choice_vegetarian', 'local_tm_course'); ?></option>
                </select>
                <input type="text" class="form-control form-control-sm mt-1 entry_special_note" maxlength="255"
                    placeholder="<?php echo s(get_string('diet_special_note', 'local_tm_course')); ?>" disabled tabindex="-1">
            </td>
            <td><button type="button" class="btn btn-sm btn-outline-danger tm-batch-remove" disabled tabindex="-1">&times;</button></td>
        </tr>
        </tbody>
    </table>
</fieldset>
<button type="button" class="btn btn-sm btn-secondary mt-2" id="tm-batch-add-row"
    <?php echo $bbdisabled ? 'disabled="disabled"' : ''; ?>><?php echo get_string('batch_add_row', 'local_tm_course'); ?></button>
</div>
</div>

<div id="tm-batch-modal" class="tm-cancel-modal-backdrop" hidden>
    <div class="tm-cancel-modal-panel" style="max-width:48rem" role="dialog" aria-modal="true">
        <h4><?php echo get_string('batch_modal_title', 'local_tm_course'); ?></h4>
        <div id="tm-batch-modal-body" class="mb-3" style="max-height:22rem;overflow:auto"></div>
        <div class="d-flex gap-2">
            <button type="button" class="btn tm-enrol-btn" id="tm-batch-modal-confirm"><?php echo get_string('batch_confirm_submit', 'local_tm_course'); ?></button>
            <button type="button" class="btn btn-secondary" id="tm-batch-modal-close"><?php echo get_string('cancel'); ?></button>
        </div>
    </div>
</div>

<?php
if (!empty($bbcfg['emit_initial_rows_script'])) {
    echo html_writer::script('window.tmBatchInitialRows = ' . json_encode($bbrows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';');
}
