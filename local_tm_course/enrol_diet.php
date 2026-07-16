<?php
/**
 * Self-enrol confirmation — institution + diet (onsite only).
 * URL: /local/tm_course/enrol_diet.php?sessionid=N
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/enrolment_manager.php');
require_once(__DIR__ . '/classes/session_verification_manager.php');

use local_tm_course\session_manager;
use local_tm_course\enrolment_manager;
use local_tm_course\session_verification_manager;

require_login();
require_capability('local/tm_course:enrol', context_system::instance());

global $DB, $OUTPUT, $PAGE, $SESSION, $USER;

$sessionid = required_param('sessionid', PARAM_INT);
$PAGE->set_url(new moodle_url('/local/tm_course/enrol_diet.php', ['sessionid' => $sessionid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('enrol_confirm_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

$session = session_manager::get_session($sessionid);
$isonline = session_manager::is_online_session($session);

$pending = $SESSION->local_tm_course_diet_pending ?? null;
$pending_ok = $pending && (int)($pending->sessionid ?? 0) === (int)$sessionid;

if (!$pending_ok) {
    redirect(new moodle_url('/local/tm_course/index.php'),
        get_string('error_enrol_flow_expired', 'local_tm_course'), null,
        \core\output\notification::NOTIFY_ERROR);
}

$institution_value = trim((string)($USER->institution ?? ''));
$diet_choice_value = 'A';
$diet_note_value = '';
if (!empty($pending->confirmed)) {
    if (trim((string)($pending->institution ?? '')) !== '') {
        $institution_value = (string)$pending->institution;
    }
    $diet_choice_value = strtoupper((string)($pending->diet_choice ?? 'A'));
    if (!in_array($diet_choice_value, ['A', 'B'], true)) {
        $diet_choice_value = 'A';
    }
    $diet_note_value = (string)($pending->diet_special_note ?? '');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $institution = clean_param(trim((string)optional_param('institution', '', PARAM_TEXT)), PARAM_TEXT);
    $diet_choice = '';
    $special_note = '';

    if ($institution === '') {
        $errors[] = get_string('error_institution_required', 'local_tm_course');
    }

    if (!$isonline) {
        $diet_choice = strtoupper(trim((string)optional_param('diet_choice', '', PARAM_ALPHA)));
        $special_note = (string)optional_param('diet_special_note', '', PARAM_TEXT);
        if (!in_array($diet_choice, ['A', 'B'], true)) {
            $errors[] = get_string('error_diet_choice_required', 'local_tm_course');
        }
    }

    if (empty($errors)) {
        try {
            enrolment_manager::sync_user_institution((int)$USER->id, $institution);

            $SESSION->local_tm_course_diet_pending = (object) [
                'sessionid' => (int)$sessionid,
                'institution' => $institution,
                'diet_choice' => $diet_choice,
                'diet_special_note' => $special_note,
                'confirmed' => true,
                'timecreated' => time(),
            ];

            if (session_verification_manager::session_has_verification_questions($session)) {
                redirect(new moodle_url('/local/tm_course/enrol_session_verification.php', ['sessionid' => $sessionid]));
            }

            enrolment_manager::enrol($sessionid, (int)$USER->id, $institution, [
                'choice' => $diet_choice,
                'special_note' => $special_note,
            ]);

            unset($SESSION->local_tm_course_diet_pending);
            redirect(new moodle_url('/local/tm_course/index.php'),
                get_string('enrol_submit_success', 'local_tm_course'),
                null,
                \core\output\notification::NOTIFY_SUCCESS);
        } catch (\moodle_exception $e) {
            $errors[] = $e->getMessage();
            $institution_value = $institution;
            if (!$isonline) {
                $diet_choice_value = $diet_choice !== '' ? $diet_choice : $diet_choice_value;
                $diet_note_value = $special_note;
            }
        }
    } else {
        $institution_value = $institution;
        if (!$isonline) {
            $diet_choice_value = $diet_choice !== '' ? $diet_choice : $diet_choice_value;
            $diet_note_value = $special_note;
        }
    }
}

echo $OUTPUT->header();
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo get_string('enrol_confirm_title', 'local_tm_course'); ?></h2>
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
                <label class="font-weight-bold" for="enrol_institution">
                    <?php echo get_string('institution', 'local_tm_course'); ?> *
                </label>
                <input type="text"
                       name="institution"
                       id="enrol_institution"
                       class="form-control form-control-sm"
                       required
                       maxlength="255"
                       value="<?php echo s($institution_value); ?>">
            </div>

            <fieldset id="tm-enrol-diet-fieldset" class="tm-enrol-diet-fieldset mb-3"<?php echo $isonline ? ' disabled' : ''; ?>>
                <legend class="font-weight-bold mb-2"><?php echo get_string('enrol_confirm_diet_section', 'local_tm_course'); ?></legend>
                <?php if ($isonline): ?>
                    <p class="tm-form-hint tm-session-muted mb-0"><?php echo get_string('enrol_confirm_diet_online_hint', 'local_tm_course'); ?></p>
                <?php else: ?>
                    <div class="mb-3">
                        <label class="font-weight-bold">
                            <input type="radio" name="diet_choice" value="A" id="diet_choice_meat"
                                <?php echo $diet_choice_value === 'A' ? 'checked' : ''; ?>>
                            <?php echo get_string('diet_choice_meat', 'local_tm_course'); ?>
                        </label>
                    </div>

                    <div class="pl-3 mb-3 tm-diet-meat"></div>

                    <div class="mb-3 mt-4">
                        <label class="font-weight-bold">
                            <input type="radio" name="diet_choice" value="B" id="diet_choice_veg"
                                <?php echo $diet_choice_value === 'B' ? 'checked' : ''; ?>>
                            <?php echo get_string('diet_choice_vegetarian', 'local_tm_course'); ?>
                        </label>
                    </div>

                    <div class="pl-3 mb-3 tm-diet-veg" style="display:none"></div>
                    <div class="pl-3 mb-3">
                        <div class="mb-2">
                            <label class="font-weight-bold" for="diet_special_note"><?php echo get_string('diet_special_note', 'local_tm_course'); ?></label>
                            <input type="text"
                                   name="diet_special_note"
                                   id="diet_special_note"
                                   class="form-control form-control-sm"
                                   maxlength="255"
                                   value="<?php echo s($diet_note_value); ?>"
                                   placeholder="<?php echo s(get_string('diet_special_note', 'local_tm_course')); ?>">
                        </div>
                    </div>
                <?php endif; ?>
            </fieldset>

            <div class="tm-diet-form-actions">
                <button type="submit" class="btn btn-tm-diet-submit"><?php echo get_string('diet_submit_button', 'local_tm_course'); ?></button>
                <a class="btn btn-tm-diet-cancel" href="<?php echo (new moodle_url('/local/tm_course/index.php'))->out(); ?>"><?php echo get_string('cancel'); ?></a>
            </div>
        </form>

        <?php if (!$isonline): ?>
        <script>
            (function() {
                const meat = document.getElementById('diet_choice_meat');
                const veg = document.getElementById('diet_choice_veg');
                const meatBox = document.querySelector('.tm-diet-meat');
                const vegBox = document.querySelector('.tm-diet-veg');
                function sync() {
                    if (!veg || !meat || !vegBox || !meatBox) {
                        return;
                    }
                    if (veg.checked) {
                        vegBox.style.display = 'block';
                        meatBox.style.display = 'none';
                    } else {
                        vegBox.style.display = 'none';
                        meatBox.style.display = 'block';
                    }
                }
                if (meat && veg) {
                    meat.addEventListener('change', sync);
                    veg.addEventListener('change', sync);
                }
                sync();
            })();
        </script>
        <?php endif; ?>
    </div>
</div>

<?php
echo $OUTPUT->footer();
