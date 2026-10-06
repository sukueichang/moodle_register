<?php
/**
 * Learner course survey fill-in / view (SPEC §59 stage 2).
 *
 * URL: /local/tm_course/survey.php?sessionid=N
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/survey_manager.php');
require_once(__DIR__ . '/classes/permissions_manager.php');
require_once(__DIR__ . '/classes/enrolment_manager.php');
require_once(__DIR__ . '/classes/session_manager.php');

use local_tm_course\permissions_manager;
use local_tm_course\survey_manager;

$sessionid = required_param('sessionid', PARAM_INT);
$wanturl = new moodle_url('/local/tm_course/survey.php', ['sessionid' => $sessionid]);
require_login();
permissions_manager::require_view_access();

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_url($wanturl);
$PAGE->requires->css('/local/tm_course/styles.css');
$PAGE->set_title(get_string('survey_learner_title', 'local_tm_course'));

global $DB, $USER, $OUTPUT;

$session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid], '*', MUST_EXIST);
$enrol = survey_manager::find_user_enrolment_for_session($sessionid, (int) $USER->id);
$error = '';
$saved = optional_param('saved', 0, PARAM_BOOL);

if (!$enrol) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('survey_error_not_eligible', 'local_tm_course'), 'error');
    echo html_writer::link(new moodle_url('/local/tm_course/my_records.php'), get_string('survey_back_records', 'local_tm_course'));
    echo $OUTPUT->footer();
    exit;
}

$state = survey_manager::my_records_survey_state($enrol, (int) $USER->id);
$response = survey_manager::get_response_by_enrolid((int) $enrol->id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    try {
        survey_manager::submit_response($sessionid, (int) $USER->id, $_POST['answer'] ?? []);
        redirect(new moodle_url('/local/tm_course/survey.php', [
            'sessionid' => $sessionid,
            'saved' => 1,
        ]));
    } catch (\moodle_exception $e) {
        if ($e->errorcode === 'survey_error_already_submitted') {
            redirect(new moodle_url('/local/tm_course/survey.php', [
                'sessionid' => $sessionid,
                'saved' => 1,
            ]));
        }
        $error = $e->getMessage();
        $state = survey_manager::STATE_FILL;
        $response = survey_manager::get_response_by_enrolid((int) $enrol->id);
        if ($response) {
            $state = survey_manager::STATE_VIEW;
        }
    }
}

$pin = survey_manager::get_pin($sessionid);
$versionid = 0;
if ($response) {
    $versionid = (int) $response->versionid;
    $state = survey_manager::STATE_VIEW;
} else if ($pin) {
    $versionid = (int) $pin->versionid;
} else if ($state === survey_manager::STATE_FILL || $state === survey_manager::STATE_NOT_OPEN) {
    survey_manager::ensure_session_survey_pin($sessionid);
    $pin = survey_manager::get_pin($sessionid);
    $versionid = $pin ? (int) $pin->versionid : 0;
}

$structure = $versionid > 0 ? survey_manager::get_version_structure($versionid) : [];
$answers = ($response && $state === survey_manager::STATE_VIEW)
    ? survey_manager::get_response_answers((int) $response->id)
    : [];

$surveyname = '';
if ($versionid > 0) {
    $ver = $DB->get_record('local_tm_course_svver', ['id' => $versionid], 'id, surveyid');
    if ($ver) {
        $def = $DB->get_record('local_tm_course_svdef', ['id' => $ver->surveyid], 'id, name');
        $surveyname = $def ? (string) $def->name : '';
    }
}

echo $OUTPUT->header();
echo html_writer::link(
    new moodle_url('/local/tm_course/my_records.php'),
    get_string('survey_back_records', 'local_tm_course'),
    ['class' => 'd-inline-block mb-3']
);
echo html_writer::tag('h2', get_string('survey_learner_title', 'local_tm_course'));
echo html_writer::tag('p', s($session->name) . ($surveyname !== '' ? ' — ' . s($surveyname) : ''), ['class' => 'text-muted']);

if ($saved) {
    echo $OUTPUT->notification(get_string('survey_submitted', 'local_tm_course'), 'success');
}
if ($error !== '') {
    echo $OUTPUT->notification($error, 'error');
}

if ($state === survey_manager::STATE_NONE) {
    echo $OUTPUT->notification(get_string('survey_error_not_eligible', 'local_tm_course'), 'info');
    echo $OUTPUT->footer();
    exit;
}

if ($state === survey_manager::STATE_NOT_OPEN) {
    echo $OUTPUT->notification(get_string('survey_not_open', 'local_tm_course'), 'info');
    echo $OUTPUT->footer();
    exit;
}

if ($state === survey_manager::STATE_VIEW) {
    echo html_writer::tag('p', get_string('survey_view_only', 'local_tm_course'), ['class' => 'font-weight-bold']);
    if (!$structure) {
        echo $OUTPUT->notification(get_string('survey_error_no_survey', 'local_tm_course'), 'error');
        echo $OUTPUT->footer();
        exit;
    }
    foreach ($structure as $section) {
        if (($section['name'] ?? '') !== '') {
            echo html_writer::tag('h3', s($section['name']), ['class' => 'mt-3']);
        }
        foreach ($section['items'] ?? [] as $item) {
            $itemid = (int) $item['id'];
            $ans = $answers[$itemid] ?? null;
            echo html_writer::start_div('border rounded p-3 mb-3');
            echo html_writer::tag('div', s($item['title']) . (!empty($item['required']) ? ' *' : ''), ['class' => 'font-weight-bold']);
            if (($item['help'] ?? '') !== '') {
                echo html_writer::tag('div', s($item['help']), ['class' => 'text-muted small mb-2']);
            }
            $display = '—';
            if ($ans) {
                if ($item['qtype'] === survey_manager::TYPE_SCALE) {
                    $display = (string) ($ans['valueint'] ?? '—');
                } else if ($item['qtype'] === survey_manager::TYPE_TEXT) {
                    $display = $ans['valuetext'] !== '' ? $ans['valuetext'] : '—';
                } else if ($item['qtype'] === survey_manager::TYPE_SINGLE) {
                    $label = '—';
                    foreach ($item['options'] as $option) {
                        if ((int) $option['id'] === (int) ($ans['valueint'] ?? 0)) {
                            $label = (string) $option['label'];
                            break;
                        }
                    }
                    $display = $label;
                    if ($ans['othertext'] !== '') {
                        $display .= ' (' . $ans['othertext'] . ')';
                    }
                } else if ($item['qtype'] === survey_manager::TYPE_MULTI) {
                    $labels = [];
                    $picked = array_map('intval', $ans['optionids']);
                    foreach ($item['options'] as $option) {
                        if (in_array((int) $option['id'], $picked, true)) {
                            $labels[] = (string) $option['label'];
                        }
                    }
                    $display = $labels ? implode(', ', $labels) : '—';
                    if ($ans['othertext'] !== '') {
                        $display .= ' (' . $ans['othertext'] . ')';
                    }
                }
            }
            echo html_writer::tag('div', s($display));
            echo html_writer::end_div();
        }
    }
    echo $OUTPUT->footer();
    exit;
}

// Fill mode.
if (!$structure || $versionid <= 0) {
    echo $OUTPUT->notification(get_string('survey_error_no_survey', 'local_tm_course'), 'error');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $wanturl->out(false),
    'id' => 'survey-fill-form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

foreach ($structure as $section) {
    if (($section['name'] ?? '') !== '') {
        echo html_writer::tag('h3', s($section['name']), ['class' => 'mt-3']);
    }
    foreach ($section['items'] ?? [] as $item) {
        $itemid = (int) $item['id'];
        $prefix = 'answer[' . $itemid . ']';
        echo html_writer::start_div('border rounded p-3 mb-3 survey-fill-item');
        echo html_writer::tag('div', s($item['title']) . (!empty($item['required']) ? ' *' : ''), ['class' => 'font-weight-bold mb-1']);
        if (($item['help'] ?? '') !== '') {
            echo html_writer::tag('div', s($item['help']), ['class' => 'text-muted small mb-2']);
        }
        $qtype = (string) $item['qtype'];
        if ($qtype === survey_manager::TYPE_SCALE) {
            $min = (string) ($item['scalemin'] ?? '');
            $max = (string) ($item['scalemax'] ?? '');
            if ($min !== '' || $max !== '') {
                echo html_writer::tag('div', s($min) . ' ← → ' . s($max), ['class' => 'small text-muted mb-2']);
            }
            echo html_writer::start_div('d-flex flex-wrap');
            for ($n = 1; $n <= 5; $n++) {
                echo html_writer::tag('label',
                    html_writer::empty_tag('input', [
                        'type' => 'radio', 'name' => $prefix . '[value]', 'value' => $n,
                        'class' => 'mr-1',
                    ]) . ' ' . $n,
                    ['class' => 'mr-3 mb-1']
                );
            }
            echo html_writer::end_div();
        } else if ($qtype === survey_manager::TYPE_TEXT) {
            echo html_writer::tag('textarea', '', [
                'name' => $prefix . '[value]', 'class' => 'form-control', 'rows' => 3,
            ]);
        } else if ($qtype === survey_manager::TYPE_SINGLE || $qtype === survey_manager::TYPE_MULTI) {
            $inputtype = $qtype === survey_manager::TYPE_SINGLE ? 'radio' : 'checkbox';
            $name = $qtype === survey_manager::TYPE_SINGLE ? $prefix . '[option]' : $prefix . '[options][]';
            foreach ($item['options'] as $option) {
                $oid = (int) $option['id'];
                $isother = !empty($option['isother']);
                echo html_writer::start_div('mb-1');
                echo html_writer::tag('label',
                    html_writer::empty_tag('input', [
                        'type' => $inputtype, 'name' => $name, 'value' => $oid,
                        'class' => 'mr-1 survey-opt' . ($isother ? ' survey-opt-other' : ''),
                        'data-item' => $itemid,
                    ]) . ' ' . s($option['label'])
                );
                if ($isother) {
                    echo html_writer::empty_tag('input', [
                        'type' => 'text', 'name' => $prefix . '[other]',
                        'class' => 'form-control form-control-sm mt-1 survey-other-text',
                        'data-item' => $itemid,
                        'placeholder' => get_string('survey_option_other', 'local_tm_course'),
                    ]);
                }
                echo html_writer::end_div();
            }
        }
        echo html_writer::end_div();
    }
}

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'id' => 'survey-submit-btn',
    'value' => get_string('survey_submit', 'local_tm_course'),
]);
echo html_writer::end_tag('form');

$PAGE->requires->js_init_code(<<<'JS'
(function() {
    var form = document.getElementById('survey-fill-form');
    var btn = document.getElementById('survey-submit-btn');
    if (!form || !btn) { return; }
    var locked = false;
    form.addEventListener('submit', function() {
        if (locked) { return false; }
        locked = true;
        btn.disabled = true;
        btn.value = btn.value + '…';
    });
})();
JS
);

echo $OUTPUT->footer();
