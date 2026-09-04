<?php
/**
 * Edit diet preference for an enrolment.
 * Learner (holder/linked), batch submitter, or admin/approver.
 * URL: /local/tm_course/enrol_diet_edit.php?enrolid=N
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/enrolment_manager.php');
require_once(__DIR__ . '/classes/permissions_manager.php');

use local_tm_course\session_manager;
use local_tm_course\enrolment_manager;
use local_tm_course\permissions_manager;

require_login();
permissions_manager::require_view_access();

global $DB, $OUTPUT, $PAGE, $USER;

$enrolid = required_param('enrolid', PARAM_INT);
$PAGE->set_url(new moodle_url('/local/tm_course/enrol_diet_edit.php', ['enrolid' => $enrolid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->requires->css('/local/tm_course/styles.css');

$enrol = $DB->get_record('local_tm_course_enrolments', ['id' => $enrolid], '*', MUST_EXIST);
if (!enrolment_manager::user_can_edit_diet($enrol, (int) $USER->id)) {
    throw new moodle_exception('nopermissions', 'error');
}
$session = session_manager::get_session((int) $enrol->sessionid);

$backurl = new moodle_url('/local/tm_course/index.php');
if ((int) ($enrol->batch_submittedby ?? 0) === (int) $USER->id
    && (int) $enrol->userid !== (int) $USER->id
    && (int) ($enrol->linked_userid ?? 0) !== (int) $USER->id) {
    $backurl = new moodle_url('/local/tm_course/batch_enrol.php', ['sessionid' => (int) $enrol->sessionid]);
}
if (has_capability('local/tm_course:approve', context_system::instance())
    || has_capability('local/tm_course:manage', context_system::instance())) {
    $backurl = new moodle_url('/local/tm_course/admin/enrolments.php', ['sessionid' => (int) $enrol->sessionid]);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $dietchoice = strtoupper(trim((string) optional_param('diet_choice', '', PARAM_ALPHA)));
    $specialnote = (string) optional_param('diet_special_note', '', PARAM_TEXT);

    $diet = [];
    if ($dietchoice === 'A') {
        $diet = [
            'choice' => 'A',
            'special_note' => $specialnote,
        ];
    } else if ($dietchoice === 'B') {
        $diet = [
            'choice' => 'B',
            'special_note' => $specialnote,
        ];
    } else {
        $errors[] = get_string('error_diet_choice_required', 'local_tm_course');
    }

    if (empty($errors)) {
        try {
            enrolment_manager::update_diet_by_actor($enrolid, (int) $USER->id, $diet);
            redirect($backurl,
                get_string('diet_updated', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (\moodle_exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

echo $OUTPUT->header();
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo get_string('change_diet_habit', 'local_tm_course'); ?></h2>
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
        <form method="post" action="">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <div class="mb-3">
                <label class="font-weight-bold">
                    <input type="radio" name="diet_choice" value="A" id="diet_choice_meat" <?php echo ((string)$enrol->diet_choice !== 'B') ? 'checked' : ''; ?>>
                    <?php echo get_string('diet_choice_meat', 'local_tm_course'); ?>
                </label>
            </div>
            <div class="pl-3 mb-3 tm-diet-meat">
            </div>
            <div class="mb-3 mt-4">
                <label class="font-weight-bold">
                    <input type="radio" name="diet_choice" value="B" id="diet_choice_veg" <?php echo ((string)$enrol->diet_choice === 'B') ? 'checked' : ''; ?>>
                    <?php echo get_string('diet_choice_vegetarian', 'local_tm_course'); ?>
                </label>
            </div>
            <div class="pl-3 mb-3 tm-diet-veg" style="display:none">
            </div>
            <div class="pl-3 mb-3">
                <div class="mb-2">
                    <label class="font-weight-bold"><?php echo get_string('diet_special_note', 'local_tm_course'); ?></label>
                    <input type="text" name="diet_special_note" class="form-control form-control-sm" maxlength="255"
                           value="<?php echo s((string)($enrol->diet_meat_other ?? $enrol->diet_vegetarian_notes ?? '')); ?>"
                           placeholder="<?php echo s(get_string('diet_special_note', 'local_tm_course')); ?>">
                </div>
            </div>
            <div class="tm-diet-form-actions">
                <button type="submit" class="btn btn-tm-diet-submit"><?php echo get_string('savechanges'); ?></button>
                <a class="btn btn-tm-diet-cancel" href="<?php echo $backurl->out(); ?>"><?php echo get_string('cancel'); ?></a>
            </div>
        </form>
    </div>
</div>
<script>
(function() {
    var meat = document.getElementById('diet_choice_meat');
    var veg = document.getElementById('diet_choice_veg');
    function sync() {
        // Meat / veg panels reserved for future option flags.
    }
    if (meat) { meat.addEventListener('change', sync); }
    if (veg) { veg.addEventListener('change', sync); }
    sync();
})();
</script>
<?php
echo $OUTPUT->footer();
