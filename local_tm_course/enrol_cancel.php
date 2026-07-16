<?php
/**
 * Cancel enrolment page with required reason.
 * URL: /local/tm_course/enrol_cancel.php?enrolid=N
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/enrolment_manager.php');

use local_tm_course\session_manager;
use local_tm_course\enrolment_manager;

require_login();
require_capability('local/tm_course:enrol', context_system::instance());

global $DB, $OUTPUT, $PAGE, $USER;

$enrolid = required_param('enrolid', PARAM_INT);
$PAGE->set_url(new moodle_url('/local/tm_course/enrol_cancel.php', ['enrolid' => $enrolid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->requires->css('/local/tm_course/styles.css');

$enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid, 'userid' => $USER->id], '*', MUST_EXIST);
$session = session_manager::get_session((int)$enrol->sessionid);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $reasoncode = optional_param('cancel_reason_code', '', PARAM_ALPHANUMEXT);
    $reasontext = optional_param('cancel_reason_other', '', PARAM_TEXT);
    try {
        enrolment_manager::cancel($enrolid, (int)$USER->id, (string)$reasoncode, (string)$reasontext);
        redirect(new moodle_url('/local/tm_course/index.php'),
            get_string('enrol_cancelled', 'local_tm_course'), null, \core\output\notification::NOTIFY_WARNING);
    } catch (\moodle_exception $e) {
        $errors[] = $e->getMessage();
    }
}

echo $OUTPUT->header();
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo get_string('cancel_enrolment', 'local_tm_course'); ?></h2>
</div>
<?php if (!empty($errors)): ?>
<div class="tm-alert tm-alert-error">
    <?php foreach ($errors as $err): ?>
    <div><?php echo s($err); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="tm-card">
    <div class="tm-card-body" style="max-width:720px;">
        <div class="mb-3">
            <strong><?php echo s($session->name); ?></strong>
            <div class="text-muted"><?php echo userdate($session->starttime, get_string('strftimedatetimeshort')); ?></div>
        </div>
        <form method="post" action="" id="tm-enrol-cancel-form">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <div class="mb-2">
                <label class="font-weight-bold"><?php echo get_string('cancel_modal_prompt', 'local_tm_course'); ?></label>
                <select name="cancel_reason_code" id="cancel_reason_code_ui" class="form-control">
                    <option value=""><?php echo get_string('cancel_reason_select', 'local_tm_course'); ?></option>
                    <option value="work"><?php echo get_string('cancel_reason_work', 'local_tm_course'); ?></option>
                    <option value="other_session"><?php echo get_string('cancel_reason_other_session', 'local_tm_course'); ?></option>
                    <option value="other"><?php echo get_string('cancel_reason_other', 'local_tm_course'); ?></option>
                </select>
                <textarea id="cancel_reason_other_ui" name="cancel_reason_other" class="form-control mt-2" maxlength="1000" rows="3"
                    placeholder="<?php echo get_string('cancel_reason_other_placeholder', 'local_tm_course'); ?>" style="display:none"></textarea>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-tm-danger" id="tm-enrol-cancel-submit"><?php echo get_string('cancel_modal_confirm', 'local_tm_course'); ?></button>
                <a class="btn btn-secondary" href="<?php echo (new moodle_url('/local/tm_course/index.php'))->out(); ?>"><?php echo get_string('cancel'); ?></a>
            </div>
        </form>
    </div>
</div>
<script>
(function() {
    var codeEl = document.getElementById('cancel_reason_code_ui');
    var otherEl = document.getElementById('cancel_reason_other_ui');
    var formEl = document.getElementById('tm-enrol-cancel-form');
    function syncReasonUi() {
        if (!codeEl || !otherEl) { return; }
        otherEl.style.display = (codeEl.value === 'other') ? 'block' : 'none';
    }
    if (codeEl) { codeEl.addEventListener('change', syncReasonUi); }
    syncReasonUi();
    var finalMsg = <?php echo json_encode(get_string('cancel_final_confirm', 'local_tm_course'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    var errNeedReason = <?php echo json_encode(get_string('error_cancel_reason_required', 'local_tm_course'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    var errNeedOther = <?php echo json_encode(get_string('error_cancel_reason_other_required', 'local_tm_course'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    if (formEl) {
        formEl.addEventListener('submit', function(e) {
            e.preventDefault();
            var code = codeEl ? codeEl.value : '';
            if (!code) {
                alert(errNeedReason);
                return;
            }
            var other = '';
            if (code === 'other') {
                other = (otherEl && otherEl.value) ? String(otherEl.value).trim() : '';
                if (!other) {
                    alert(errNeedOther);
                    return;
                }
            }
            if (!confirm(finalMsg)) {
                return;
            }
            formEl.submit();
        });
    }
})();
</script>
<?php
echo $OUTPUT->footer();
