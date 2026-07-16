<?php

/**

 * Dedicated reservation verification file upload page.

 *

 * @package    local_tm_course

 */

require_once(__DIR__ . '/../../../config.php');

require_once(__DIR__ . '/../classes/permissions_manager.php');

require_once(__DIR__ . '/../classes/verification_manager.php');

require_once(__DIR__ . '/../classes/reservation_application.php');



require_login();



global $DB, $USER, $OUTPUT, $PAGE;



$context = context_system::instance();

$issiteadmin = is_siteadmin();

$canbatch = \local_tm_course\permissions_manager::user_can_batch_enrol();

if (!$issiteadmin && !$canbatch) {

    throw new required_capability_exception($context, 'local/tm_course:batchenrol', 'nopermissions', '');

}



$id = required_param('id', PARAM_INT);

$reservation = $DB->get_record('local_tm_course_reservation', ['id' => $id], '*', MUST_EXIST);

if ((int)$reservation->requesterid !== (int)$USER->id && !$issiteadmin) {

    throw new required_capability_exception($context, 'local/tm_course:manage', 'nopermissions', '');

}



if (\local_tm_course\reservation_application::is_formally_submitted($reservation) && !$issiteadmin) {

    redirect(

        new moodle_url('/local/tm_course/reservation/tracking.php'),

        get_string('reservation_error_already_submitted', 'local_tm_course'),

        null,

        \core\output\notification::NOTIFY_INFO

    );

}



$PAGE->set_context($context);

$PAGE->set_url(new moodle_url('/local/tm_course/reservation/verification.php', ['id' => $id]));

$PAGE->set_pagelayout('standard');

$PAGE->set_title(get_string('upload_required_files', 'local_tm_course'));

$PAGE->set_heading(get_string('upload_required_files', 'local_tm_course'));

$PAGE->requires->css('/local/tm_course/styles.css');



$courseids = \local_tm_course\verification_manager::get_reservation_course_ids($reservation);

$questions = \local_tm_course\verification_manager::get_questions_for_courses($courseids, (string)$reservation->delivery_mode);

$coursenames = [];

if (!empty($courseids)) {

    list($cinsql, $cinparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);

    $rows = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $cinsql", $cinparams);

    foreach ($rows as $r) {

        $coursenames[(int)$r->id] = format_string((string)$r->fullname);

    }

}

$questionsbycourse = [];

foreach ($questions as $q) {

    $cid = (int)($q->courseid ?? 0);

    if (empty($questionsbycourse[$cid])) {

        $questionsbycourse[$cid] = [];

    }

    $questionsbycourse[$cid][] = $q;

}

$existinglinks = \local_tm_course\verification_manager::get_reservation_file_links((int)$reservation->id);

$linksbyquestion = [];

foreach ($existinglinks as $l) {

    $linksbyquestion[(int)$l->questionid] = (int)$l->itemid;

}



$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHANUMEXT) === 'submit_verification') {

    require_sesskey();

    $finalconfirm = optional_param('final_confirm', 0, PARAM_INT) === 1;

    foreach ($questions as $q) {

        $qid = (int)$q->id;

        $fieldname = 'qfile_' . $qid;

        if (!empty($_FILES[$fieldname]) && is_array($_FILES[$fieldname])) {

            \local_tm_course\verification_manager::save_question_upload((int)$reservation->id, $qid, $_FILES[$fieldname], $context);

        }

    }

    if ($finalconfirm) {

        try {

            \local_tm_course\reservation_application::finalize_submission((int)$reservation->id, (int)$USER->id, $issiteadmin);

            redirect(new moodle_url('/local/tm_course/reservation/index.php', ['submitted' => 1]));

        } catch (\moodle_exception $e) {

            $errors[] = $e->getMessage();

        }

    }

    $reservation = $DB->get_record('local_tm_course_reservation', ['id' => $id], '*', MUST_EXIST);

}



$summarypayload = \local_tm_course\reservation_application::build_summary_payload($reservation);



// ===== 缺件提醒 modal 所需資料 =====

$deadlinedays = (int)get_config('local_tm_course', 'reservation_verification_deadline_days');

if ($deadlinedays <= 0) {

    $deadlinedays = 7;

}

$earlieststart = 0;

if (!empty($reservation->calendar_plan_json)) {

    $planblocks = json_decode((string)$reservation->calendar_plan_json, true);

    if (is_array($planblocks)) {

        foreach ($planblocks as $b) {

            $s = (int)($b['start'] ?? 0);

            if ($s > 0 && ($earlieststart === 0 || $s < $earlieststart)) {

                $earlieststart = $s;

            }

        }

    }

}

$deadlinets = $earlieststart > 0 ? ($earlieststart - $deadlinedays * 86400) : 0;

$deadlinedatestr = $deadlinets > 0 ? userdate($deadlinets, get_string('strftimedatetimeshort', 'langconfig')) : '';



$requireduploaded = [];

foreach ($questions as $q) {

    if ((int)($q->is_required ?? 0) !== 1) {

        continue;

    }

    $qid = (int)$q->id;

    $itemid = (int)($linksbyquestion[$qid] ?? 0);

    $requireduploaded[$qid] = ($itemid > 0 && \local_tm_course\verification_manager::stored_area_has_file($context, $itemid));

}



$hasplan = !empty($reservation->calendar_plan_json);



echo $OUTPUT->header();

echo html_writer::start_div('tm-page-header');

echo html_writer::span('', 'tm-logo-dot');

echo html_writer::tag('h2', get_string('upload_required_files', 'local_tm_course'));

echo html_writer::end_div();

echo html_writer::start_div('mb-2');

echo html_writer::tag('span', '基本資料 (1/3)', ['class' => 'badge badge-secondary mr-1']);

echo html_writer::tag('span', '月曆編排 (2/3)', ['class' => 'badge badge-secondary mr-1']);

echo html_writer::tag('span', '檢核資料 (3/3)', ['class' => 'badge badge-info']);

echo html_writer::end_div();

if (!empty($errors)) {

    echo html_writer::start_div('tm-alert tm-alert-error');

    foreach ($errors as $err) {

        echo html_writer::div(s($err));

    }

    echo html_writer::end_div();

}

if (!$hasplan) {

    echo html_writer::div(get_string('reservation_error_plan_required', 'local_tm_course'), 'tm-alert tm-alert-warning mb-3');

}

echo html_writer::start_div('tm-card tm-resv-card');

echo html_writer::start_div('tm-card-body');

echo html_writer::tag('p', get_string('reservation_verification_intro', 'local_tm_course'));

echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data', 'id' => 'tm-verification-form']);

echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'submit_verification']);

echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::empty_tag('input', [

    'type' => 'hidden',

    'name' => 'acknowledge_incomplete',

    'id' => 'tm-verification-ack',

    'value' => '0',

]);

echo html_writer::empty_tag('input', [

    'type' => 'hidden',

    'name' => 'final_confirm',

    'id' => 'tm-verification-final-confirm',

    'value' => '0',

]);

echo html_writer::div(get_string('reservation_verification_submit_hint', 'local_tm_course'), 'tm-alert tm-alert-info mb-3');

if (empty($questions)) {

    echo html_writer::div(get_string('reservation_verification_no_files', 'local_tm_course'), 'tm-alert tm-alert-info');

} else {

    foreach ($questionsbycourse as $cid => $coursequestions) {

        $coursetitle = $coursenames[(int)$cid] ?? ('Course #' . (int)$cid);

        echo html_writer::start_div('mb-3 p-2 border rounded');

        echo html_writer::tag('h5', get_string('reservation_verification_course_heading', 'local_tm_course', (object)['course' => $coursetitle]), ['class' => 'mb-2']);

        foreach ($coursequestions as $q) {

            $qid = (int)$q->id;

            $isrequired = ((int)$q->is_required === 1);

            $hasupload = !empty($requireduploaded[$qid]);

            echo html_writer::start_div('mb-3 p-2 border rounded');

            echo html_writer::tag('label', s((string)$q->question_text) . ($isrequired ? ' *' : ''));

            $inputattrs = ['type' => 'file', 'name' => 'qfile_' . $qid, 'class' => 'form-control-file tm-verification-file'];

            if ($isrequired) {

                $inputattrs['data-required'] = '1';

            }

            if ($hasupload) {

                $inputattrs['data-has-upload'] = '1';

            }

            echo html_writer::empty_tag('input', $inputattrs);

            if (!empty($linksbyquestion[$qid])) {

                echo html_writer::div(get_string('reservation_verification_file_replace_hint', 'local_tm_course'), 'small text-muted mt-1');

            }

            echo html_writer::end_div();

        }

        echo html_writer::end_div();

    }

}

$submitattrs = ['type' => 'submit', 'class' => 'btn tm-enrol-btn', 'id' => 'tm-verification-submit'];

if (!$hasplan) {

    $submitattrs['disabled'] = 'disabled';

}

echo html_writer::tag('button', get_string('reservation_final_submit_button', 'local_tm_course'), $submitattrs);

echo ' ';

echo html_writer::link(new moodle_url('/local/tm_course/reservation/calendar.php', ['id' => $id]), get_string('reservation_back_calendar', 'local_tm_course'), ['class' => 'btn btn-secondary']);

echo ' ';

echo html_writer::link(new moodle_url('/local/tm_course/reservation/index.php', ['editrid' => $id]), get_string('reservation_back_form', 'local_tm_course'), ['class' => 'btn btn-secondary']);

echo html_writer::end_tag('form');

echo html_writer::end_div();

echo html_writer::end_div();



if ($deadlinedatestr !== '') {

    $modalbody = get_string('reservation_verification_incomplete_modal_body', 'local_tm_course', (object)[

        'deadline' => $deadlinedatestr,

        'days' => $deadlinedays,

    ]);

} else {

    $modalbody = get_string('reservation_verification_incomplete_modal_body_no_date', 'local_tm_course', (object)['days' => $deadlinedays]);

}

echo html_writer::start_div('tm-modal-backdrop', ['id' => 'tm-verification-modal', 'style' => 'display:none;']);

echo html_writer::start_div('tm-modal-dialog');

echo html_writer::tag('h4', get_string('reservation_verification_incomplete_modal_title', 'local_tm_course'), ['class' => 'mb-2']);

echo html_writer::tag('p', $modalbody, ['class' => 'mb-3']);

echo html_writer::start_div('tm-modal-actions');

echo html_writer::tag('button', get_string('reservation_verification_incomplete_confirm', 'local_tm_course'), [

    'type' => 'button',

    'id' => 'tm-verification-modal-confirm',

    'class' => 'btn tm-enrol-btn',

]);

echo ' ';

echo html_writer::tag('button', get_string('cancel', 'moodle'), [

    'type' => 'button',

    'id' => 'tm-verification-modal-cancel',

    'class' => 'btn btn-secondary',

]);

echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::end_div();



echo html_writer::start_div('tm-modal-backdrop', ['id' => 'tm-summary-modal', 'style' => 'display:none;']);

echo html_writer::start_div('tm-modal-dialog', ['style' => 'max-width:640px;max-height:85vh;overflow:auto;']);

echo html_writer::tag('h4', get_string('reservation_final_summary_title', 'local_tm_course'), ['class' => 'mb-2']);

echo html_writer::tag('p', get_string('reservation_final_summary_intro', 'local_tm_course'), ['class' => 'mb-3 text-muted']);

echo html_writer::start_div('', ['id' => 'tm-summary-modal-body']);

echo html_writer::end_div();

echo html_writer::start_div('tm-modal-actions mt-3');

echo html_writer::tag('button', get_string('reservation_final_summary_confirm', 'local_tm_course'), [

    'type' => 'button',

    'id' => 'tm-summary-modal-confirm',

    'class' => 'btn tm-enrol-btn',

]);

echo ' ';

echo html_writer::tag('button', get_string('reservation_final_summary_back', 'local_tm_course'), [

    'type' => 'button',

    'id' => 'tm-summary-modal-cancel',

    'class' => 'btn btn-secondary',

]);

echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::end_div();



$summaryjson = json_encode($summarypayload, JSON_UNESCAPED_UNICODE);

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

.tm-modal-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }

.tm-summary-section { margin-bottom: 1rem; }

.tm-summary-section h5 { font-size: 1rem; margin-bottom: 0.5rem; }

.tm-summary-section ul { margin: 0; padding-left: 1.1rem; }

.tm-summary-section li { margin-bottom: 0.25rem; }

</style>

<script>

(function () {

    var form = document.getElementById('tm-verification-form');

    if (!form) { return; }

    var ack = document.getElementById('tm-verification-ack');

    var finalConfirm = document.getElementById('tm-verification-final-confirm');

    var incompleteModal = document.getElementById('tm-verification-modal');

    var incompleteConfirmBtn = document.getElementById('tm-verification-modal-confirm');

    var incompleteCancelBtn = document.getElementById('tm-verification-modal-cancel');

    var summaryModal = document.getElementById('tm-summary-modal');

    var summaryBody = document.getElementById('tm-summary-modal-body');

    var summaryConfirmBtn = document.getElementById('tm-summary-modal-confirm');

    var summaryCancelBtn = document.getElementById('tm-summary-modal-cancel');

    var summaryData = <?php echo $summaryjson ?: '{}'; ?>;



    function hasMissingRequired() {

        var inputs = form.querySelectorAll('input.tm-verification-file[data-required="1"]');

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



    function openModal(el) {

        if (el) { el.style.display = 'flex'; }

    }

    function closeModal(el) {

        if (el) { el.style.display = 'none'; }

    }



    function renderSummary() {

        if (!summaryBody || !summaryData || !summaryData.sections) {

            return;

        }

        summaryBody.innerHTML = '';

        summaryData.sections.forEach(function (section) {

            var wrap = document.createElement('div');

            wrap.className = 'tm-summary-section';

            var h = document.createElement('h5');

            h.textContent = section.title || '';

            wrap.appendChild(h);

            var ul = document.createElement('ul');

            (section.items || []).forEach(function (item) {

                var li = document.createElement('li');

                li.textContent = (item.label || '') + '：' + (item.value || '');

                ul.appendChild(li);

            });

            wrap.appendChild(ul);

            summaryBody.appendChild(wrap);

        });

    }



    function proceedToSummary() {

        renderSummary();

        openModal(summaryModal);

    }



    function submitForm() {

        if (typeof form.requestSubmit === 'function') {

            form.requestSubmit();

        } else {

            form.submit();

        }

    }



    form.addEventListener('submit', function (e) {

        if (finalConfirm && finalConfirm.value === '1') {

            return;

        }

        e.preventDefault();

        if (hasMissingRequired() && (!ack || ack.value !== '1')) {

            openModal(incompleteModal);

            return;

        }

        proceedToSummary();

    });



    if (incompleteConfirmBtn) {

        incompleteConfirmBtn.addEventListener('click', function () {

            if (ack) { ack.value = '1'; }

            closeModal(incompleteModal);

            proceedToSummary();

        });

    }

    if (incompleteCancelBtn) {

        incompleteCancelBtn.addEventListener('click', function () {

            closeModal(incompleteModal);

        });

    }

    if (incompleteModal) {

        incompleteModal.addEventListener('click', function (e) {

            if (e.target === incompleteModal) { closeModal(incompleteModal); }

        });

    }



    if (summaryConfirmBtn) {

        summaryConfirmBtn.addEventListener('click', function () {

            if (finalConfirm) { finalConfirm.value = '1'; }

            closeModal(summaryModal);

            submitForm();

        });

    }

    if (summaryCancelBtn) {

        summaryCancelBtn.addEventListener('click', function () {

            closeModal(summaryModal);

        });

    }

    if (summaryModal) {

        summaryModal.addEventListener('click', function (e) {

            if (e.target === summaryModal) { closeModal(summaryModal); }

        });

    }

})();

</script>

<?php

echo $OUTPUT->footer();


