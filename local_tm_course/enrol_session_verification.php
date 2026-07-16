<?php
/**
 * Session enrolment — pre-course verification upload (self-service or batch after enrol rows created).
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/enrolment_manager.php');
require_once(__DIR__ . '/classes/permissions_manager.php');
require_once(__DIR__ . '/classes/verification_manager.php');
require_once(__DIR__ . '/classes/session_verification_manager.php');

use local_tm_course\enrolment_manager;
use local_tm_course\permissions_manager;
use local_tm_course\session_manager;
use local_tm_course\session_verification_manager;
use local_tm_course\verification_manager;

require_login();
global $DB, $OUTPUT, $PAGE, $SESSION, $USER;

$context = context_system::instance();
$sessionid = optional_param('sessionid', 0, PARAM_INT);
$submissionid = optional_param('submissionid', 0, PARAM_INT);

$submission = null;
$session = null;
$isbatch = false;

if ($submissionid > 0) {
    $isbatch = true;
    $submission = session_verification_manager::get_submission($submissionid);
    if ((string)$submission->scope !== session_verification_manager::SCOPE_BATCH) {
        throw new moodle_exception('invalidparameter', 'error');
    }
    $issiteadmin = is_siteadmin();
    $canbatch = permissions_manager::user_can_batch_enrol();
    if (!$issiteadmin && !$canbatch) {
        throw new required_capability_exception($context, 'local/tm_course:batchenrol', 'nopermissions', '');
    }
    if ((int)$submission->actor_userid !== (int)$USER->id && !$issiteadmin) {
        throw new moodle_exception('nopermissions', 'error');
    }
    $session = session_manager::get_session((int)$submission->sessionid);
    if ((int)$submission->submitted === 1) {
        redirect(new moodle_url('/local/tm_course/batch_enrol.php', ['sessionid' => (int)$session->id]),
            get_string('session_vq_batch_done', 'local_tm_course'),
            null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
} else if ($sessionid > 0) {
    require_capability('local/tm_course:enrol', $context);
    $session = session_manager::get_session($sessionid);
    $pending = $SESSION->local_tm_course_diet_pending ?? null;
    if (!$pending || (int)($pending->sessionid ?? 0) !== (int)$sessionid) {
        redirect(new moodle_url('/local/tm_course/index.php'),
            get_string('error_enrol_flow_expired', 'local_tm_course'),
            null,
            \core\output\notification::NOTIFY_ERROR);
    }
    if (empty($pending->confirmed)) {
        redirect(new moodle_url('/local/tm_course/enrol_diet.php', ['sessionid' => $sessionid]));
    }
    if (!session_verification_manager::session_has_verification_questions($session)) {
        redirect(new moodle_url('/local/tm_course/enrol_apply_step.php', ['sessionid' => $sessionid]));
    }
    $submission = session_verification_manager::get_or_create_self_submission($sessionid, (int)$USER->id);
    $submissionid = (int)$submission->id;
} else {
    throw new moodle_exception('invalidparameter', 'error');
}

if (!session_verification_manager::session_has_verification_questions($session)) {
    redirect(new moodle_url('/local/tm_course/index.php'));
}

$url = $isbatch
    ? new moodle_url('/local/tm_course/enrol_session_verification.php', ['submissionid' => $submissionid])
    : new moodle_url('/local/tm_course/enrol_session_verification.php', ['sessionid' => $sessionid]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('session_vq_page_title', 'local_tm_course'));
$PAGE->set_heading(get_string('session_vq_page_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

if (!$isbatch) {
    $doneenrol = $DB->get_record('local_tm_course_enrolments', [
        'sessionid' => (int)$session->id,
        'userid' => (int)$USER->id,
    ], '*', IGNORE_MISSING);
    $doneterminal = $doneenrol && in_array((int)$doneenrol->status, [
        session_manager::ENROL_CANCELLED,
        session_manager::ENROL_REJECTED,
    ], true);
    if ($doneenrol && !$doneterminal
            && (int)($doneenrol->vq_submission_id ?? 0) === (int)$submissionid
            && (int)$submission->submitted === 1) {
        unset($SESSION->local_tm_course_diet_pending);
        redirect(new moodle_url('/local/tm_course/index.php'),
            get_string('enrol_submit_success', 'local_tm_course'),
            null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}

$courseid = (int)$session->courseid;
$questions = verification_manager::get_questions_for_courses(
    [$courseid],
    (string)($session->delivery_mode ?? session_manager::DELIVERY_ONSITE)
);
$coursename = format_string((string)$DB->get_field('course', 'fullname', ['id' => $courseid], IGNORE_MISSING));
if ($coursename === '') {
    $coursename = 'Course #' . $courseid;
}
$questionsbycourse = [];
foreach ($questions as $q) {
    $cid = (int)($q->courseid ?? 0);
    if (empty($questionsbycourse[$cid])) {
        $questionsbycourse[$cid] = [];
    }
    $questionsbycourse[$cid][] = $q;
}
$existinglinks = session_verification_manager::get_file_links($submissionid);
$linksbyquestion = [];
foreach ($existinglinks as $l) {
    $linksbyquestion[(int)$l->questionid] = (int)$l->itemid;
}
$requireduploaded = [];
foreach ($questions as $q) {
    if ((int)($q->is_required ?? 0) !== 1) {
        continue;
    }
    $qid = (int)$q->id;
    $itemid = (int)($linksbyquestion[$qid] ?? 0);
    $requireduploaded[$qid] = ($itemid > 0 && verification_manager::stored_area_has_file($context, $itemid));
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHANUMEXT) === 'submit_verification') {
    require_sesskey();
    foreach ($questions as $q) {
        $qid = (int)$q->id;
        $fieldname = 'qfile_' . $qid;
        if (!empty($_FILES[$fieldname]) && is_array($_FILES[$fieldname])) {
            session_verification_manager::save_question_upload($submissionid, $qid, $_FILES[$fieldname], $context);
        }
    }

    if (!$isbatch) {
        $enrolrow = $DB->get_record('local_tm_course_enrolments', [
            'sessionid' => $sessionid,
            'userid' => (int)$USER->id,
        ], '*', IGNORE_MISSING);
        if (!$enrolrow || (int)($enrolrow->vq_submission_id ?? 0) !== $submissionid) {
            $pendingenrol = $SESSION->local_tm_course_diet_pending;
            $institution = (string)($pendingenrol->institution ?? '');
            try {
                $enrolid = enrolment_manager::enrol($sessionid, (int)$USER->id, $institution, [
                    'choice' => (string)($pendingenrol->diet_choice ?? ''),
                    'special_note' => (string)($pendingenrol->diet_special_note ?? ''),
                ]);
                $DB->set_field('local_tm_course_enrolments', 'vq_submission_id', $submissionid, ['id' => $enrolid]);
            } catch (\moodle_exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    if (empty($errors)) {
        session_verification_manager::mark_submitted($submissionid);
        if (!$isbatch) {
            unset($SESSION->local_tm_course_diet_pending);
            redirect(new moodle_url('/local/tm_course/index.php'),
                get_string('enrol_submit_success', 'local_tm_course'),
                null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect(new moodle_url('/local/tm_course/batch_enrol.php', ['sessionid' => (int)$session->id]),
            get_string('session_vq_batch_done', 'local_tm_course'),
            null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('session_vq_page_title', 'local_tm_course'));
if (!empty($errors)) {
    echo html_writer::start_div('tm-alert tm-alert-error');
    foreach ($errors as $err) {
        echo html_writer::div(s($err));
    }
    echo html_writer::end_div();
}

echo html_writer::div(
    get_string('session_vq_intro', 'local_tm_course', (object)['name' => format_string($session->name)]),
    'tm-alert tm-alert-info mb-3'
);

echo html_writer::start_div('tm-card');
echo html_writer::start_div('tm-card-body');
echo html_writer::tag('p', get_string('session_vq_upload_hint', 'local_tm_course'));
echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data', 'id' => 'tm-session-vq-form']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'submit_verification']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
if ($isbatch) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submissionid', 'value' => $submissionid]);
} else {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sessionid', 'value' => $sessionid]);
}
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'acknowledge_incomplete',
    'id' => 'tm-session-vq-ack',
    'value' => '0',
]);

if (empty($questions)) {
    echo html_writer::div(get_string('session_vq_none', 'local_tm_course'), 'tm-alert tm-alert-info');
} else {
    foreach ($questionsbycourse as $cid => $coursequestions) {
        $coursetitle = ($cid === $courseid) ? $coursename : ('Course #' . (int)$cid);
        echo html_writer::start_div('mb-3 p-2 border rounded');
        echo html_writer::tag('h5', get_string('session_vq_course_heading', 'local_tm_course', (object)['course' => $coursetitle]), ['class' => 'mb-2']);
        foreach ($coursequestions as $q) {
            $qid = (int)$q->id;
            $isrequired = ((int)$q->is_required === 1);
            $hasupload = !empty($requireduploaded[$qid]);
            echo html_writer::start_div('mb-3 p-2 border rounded');
            echo html_writer::tag('label', s((string)$q->question_text) . ($isrequired ? ' *' : ''));
            $inputattrs = ['type' => 'file', 'name' => 'qfile_' . $qid, 'class' => 'form-control-file tm-session-vq-file'];
            if ($isrequired) {
                $inputattrs['data-required'] = '1';
            }
            if ($hasupload) {
                $inputattrs['data-has-upload'] = '1';
            }
            echo html_writer::empty_tag('input', $inputattrs);
            if (!empty($linksbyquestion[$qid])) {
                echo html_writer::div(get_string('session_vq_reupload_hint', 'local_tm_course'), 'small text-muted mt-1');
            }
            echo html_writer::end_div();
        }
        echo html_writer::end_div();
    }
}

echo html_writer::tag('button', get_string('session_vq_submit', 'local_tm_course'), [
    'type' => 'submit',
    'class' => 'btn tm-enrol-btn mt-2',
    'id' => 'tm-session-vq-submit',
]);
echo ' ';
if ($isbatch) {
    echo html_writer::link(new moodle_url('/local/tm_course/batch_enrol.php', ['sessionid' => (int)$session->id]),
        get_string('cancel'), ['class' => 'btn btn-secondary mt-2']);
} else {
    echo html_writer::link(new moodle_url('/local/tm_course/index.php'),
        get_string('cancel'), ['class' => 'btn btn-secondary mt-2']);
}
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

$modalbody = get_string('session_vq_modal_body', 'local_tm_course');
echo html_writer::start_div('tm-modal-backdrop', ['id' => 'tm-session-vq-modal', 'style' => 'display:none;']);
echo html_writer::start_div('tm-modal-dialog');
echo html_writer::tag('h4', get_string('session_vq_modal_title', 'local_tm_course'), ['class' => 'mb-2']);
echo html_writer::tag('p', $modalbody, ['class' => 'mb-3']);
echo html_writer::start_div('tm-modal-actions');
echo html_writer::tag('button', get_string('session_vq_modal_confirm', 'local_tm_course'), [
    'type' => 'button',
    'id' => 'tm-session-vq-modal-confirm',
    'class' => 'btn tm-enrol-btn',
]);
echo ' ';
echo html_writer::tag('button', get_string('cancel'), [
    'type' => 'button',
    'id' => 'tm-session-vq-modal-cancel',
    'class' => 'btn btn-secondary',
]);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
?>
<style>
.tm-modal-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,0.45);
    display: flex; align-items: center; justify-content: center; z-index: 10500;
}
.tm-modal-dialog {
    background: #fff; border-radius: 8px; padding: 24px 28px;
    max-width: 520px; width: calc(100% - 32px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.18);
}
.tm-modal-actions { display: flex; gap: 8px; justify-content: flex-end; }
</style>
<script>
(function () {
    var form = document.getElementById('tm-session-vq-form');
    if (!form) { return; }
    var ack = document.getElementById('tm-session-vq-ack');
    var modal = document.getElementById('tm-session-vq-modal');
    var confirmBtn = document.getElementById('tm-session-vq-modal-confirm');
    var cancelBtn = document.getElementById('tm-session-vq-modal-cancel');
    function hasMissingRequired() {
        var inputs = form.querySelectorAll('input.tm-session-vq-file[data-required="1"]');
        for (var i = 0; i < inputs.length; i++) {
            var el = inputs[i];
            var alreadyUploaded = el.getAttribute('data-has-upload') === '1';
            var pickedNow = el.files && el.files.length > 0;
            if (!alreadyUploaded && !pickedNow) {
                return true;
            }
        }
        return false;
    }
    function openModal() { if (modal) { modal.style.display = 'flex'; } }
    function closeModal() { if (modal) { modal.style.display = 'none'; } }
    form.addEventListener('submit', function (e) {
        if (ack && ack.value === '1') { return; }
        if (hasMissingRequired()) {
            e.preventDefault();
            openModal();
        }
    });
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            if (ack) { ack.value = '1'; }
            closeModal();
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    }
    if (cancelBtn) { cancelBtn.addEventListener('click', closeModal); }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) { closeModal(); }
        });
    }
})();
</script>
<?php
echo $OUTPUT->footer();
