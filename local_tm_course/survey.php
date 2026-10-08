<?php
/**
 * Course survey — email quick-access (QR) and logged-in redirect (SPEC §59 Phase 3).
 *
 * URL: /local/tm_course/survey.php?t=TOKEN
 *      /local/tm_course/survey.php?sessionid=N  (login → redirect to t=)
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/survey_manager.php');
require_once(__DIR__ . '/classes/permissions_manager.php');
require_once(__DIR__ . '/classes/enrolment_manager.php');
require_once(__DIR__ . '/classes/session_manager.php');

use local_tm_course\survey_manager;

$tokenparam = optional_param('t', '', PARAM_ALPHANUM);
$sessionidparam = optional_param('sessionid', 0, PARAM_INT);
$saved = optional_param('saved', 0, PARAM_BOOL);
$step = optional_param('step', '', PARAM_ALPHA);

global $DB, $USER, $OUTPUT, $PAGE, $SESSION, $CFG;

/**
 * Rate-limit email POSTs: max 30 / hour per IP (application cache).
 */
function local_tm_course_survey_rate_ok(): bool {
    global $SESSION;
    $ip = getremoteaddr();
    $hour = (string) floor(time() / 3600);
    $key = 'survey_quick_' . md5(($ip !== '' ? $ip : 'unknown') . '_' . $hour);
    try {
        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION, 'local_tm_course', 'survey_quick');
        $hits = (int) $cache->get($key);
        if ($hits >= 30) {
            return false;
        }
        $cache->set($key, $hits + 1);
        return true;
    } catch (\Throwable $e) {
        // Cache unavailable — session counter fallback.
    }
    if (empty($SESSION->tm_survey_rl)) {
        $SESSION->tm_survey_rl = ['n' => 0, 't' => time()];
    }
    if (time() - (int) $SESSION->tm_survey_rl['t'] > 3600) {
        $SESSION->tm_survey_rl = ['n' => 0, 't' => time()];
    }
    $SESSION->tm_survey_rl['n']++;
    return (int) $SESSION->tm_survey_rl['n'] <= 30;
}

/**
 * Render questionnaire fill or view (shared markup).
 *
 * @param array $structure
 * @param array $answers
 * @param moodle_url $formurl
 * @param bool $viewonly
 * @param string $error
 * @param bool $savedflag
 */
function local_tm_course_survey_render_form(
    array $structure,
    array $answers,
    moodle_url $formurl,
    bool $viewonly,
    string $error,
    bool $savedflag
): void {
    global $OUTPUT, $PAGE;

    echo html_writer::start_div('tm-survey-fill');

    if ($savedflag) {
        echo $OUTPUT->notification(get_string('survey_submitted', 'local_tm_course'), 'success');
    }
    if ($error !== '') {
        echo html_writer::div($error, 'tm-survey-fill-error alert alert-danger');
    }

    if (!$structure) {
        echo $OUTPUT->notification(get_string('survey_error_no_survey', 'local_tm_course'), 'error');
        echo html_writer::end_div();
        return;
    }

    if ($viewonly) {
        echo html_writer::tag('p', get_string('survey_view_only', 'local_tm_course'), [
            'class' => 'tm-survey-fill-readonly-banner',
        ]);
        $qnum = 0;
        foreach ($structure as $sidx => $section) {
            echo html_writer::start_div('tm-survey-fill-section');
            $secname = trim((string) ($section['name'] ?? ''));
            if ($secname !== '') {
                echo html_writer::tag('h3',
                    html_writer::tag('span', sprintf('%02d', $sidx + 1), ['class' => 'tm-survey-section-num'])
                    . ' ' . s($secname),
                    ['class' => 'tm-survey-fill-section-title']
                );
            }
            foreach ($section['items'] ?? [] as $item) {
                $qnum++;
                $itemid = (int) $item['id'];
                $ans = $answers[$itemid] ?? null;
                echo html_writer::start_div('tm-survey-fill-qcard');
                echo html_writer::start_div('tm-survey-fill-qhead');
                echo html_writer::tag('span', get_string('survey_question_n', 'local_tm_course', $qnum), [
                    'class' => 'tm-survey-qnum',
                ]);
                $req = !empty($item['required'])
                    ? html_writer::tag('span', get_string('survey_required_yes', 'local_tm_course'), [
                        'class' => 'tm-survey-chip tm-survey-chip-req',
                    ])
                    : '';
                echo html_writer::tag('div', s($item['title']) . ' ' . $req, ['class' => 'tm-survey-fill-qtitle']);
                echo html_writer::end_div();
                if (($item['help'] ?? '') !== '') {
                    echo html_writer::tag('div', s($item['help']), ['class' => 'tm-survey-fill-help']);
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
                echo html_writer::tag('div', s($display), ['class' => 'tm-survey-fill-answer']);
                echo html_writer::end_div();
            }
            echo html_writer::end_div();
        }
        echo html_writer::end_div();
        return;
    }

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $formurl->out(false),
        'id' => 'survey-fill-form',
        'class' => 'tm-survey-fill-form',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'step', 'value' => 'answers']);

    $qnum = 0;
    foreach ($structure as $sidx => $section) {
        echo html_writer::start_div('tm-survey-fill-section');
        $secname = trim((string) ($section['name'] ?? ''));
        if ($secname !== '') {
            echo html_writer::tag('h3',
                html_writer::tag('span', sprintf('%02d', $sidx + 1), ['class' => 'tm-survey-section-num'])
                . ' ' . s($secname),
                ['class' => 'tm-survey-fill-section-title']
            );
        }
        foreach ($section['items'] ?? [] as $item) {
            $qnum++;
            $itemid = (int) $item['id'];
            $prefix = 'answer[' . $itemid . ']';
            $required = !empty($item['required']);
            echo html_writer::start_div('tm-survey-fill-qcard survey-fill-item');
            echo html_writer::start_div('tm-survey-fill-qhead');
            echo html_writer::tag('span', get_string('survey_question_n', 'local_tm_course', $qnum), [
                'class' => 'tm-survey-qnum',
            ]);
            $titlehtml = s($item['title']);
            if ($required) {
                $titlehtml .= ' ' . html_writer::tag('span', '*', [
                    'class' => 'tm-survey-req-star',
                    'aria-label' => get_string('survey_required_yes', 'local_tm_course'),
                ]);
            }
            echo html_writer::tag('div', $titlehtml, ['class' => 'tm-survey-fill-qtitle']);
            echo html_writer::end_div();
            if (($item['help'] ?? '') !== '') {
                echo html_writer::tag('div', s($item['help']), ['class' => 'tm-survey-fill-help']);
            }
            $qtype = (string) $item['qtype'];
            if ($qtype === survey_manager::TYPE_SCALE) {
                $min = (string) ($item['scalemin'] ?? '');
                $max = (string) ($item['scalemax'] ?? '');
                echo html_writer::start_div('tm-survey-scale');
                if ($min !== '' || $max !== '') {
                    echo html_writer::start_div('tm-survey-scale-ends');
                    echo html_writer::tag('span', s($min), ['class' => 'tm-survey-scale-min']);
                    echo html_writer::tag('span', s($max), ['class' => 'tm-survey-scale-max']);
                    echo html_writer::end_div();
                }
                echo html_writer::start_div('tm-survey-scale-options');
                for ($n = 1; $n <= 5; $n++) {
                    echo html_writer::tag('label',
                        html_writer::empty_tag('input', [
                            'type' => 'radio', 'name' => $prefix . '[value]', 'value' => $n,
                        ])
                        . html_writer::tag('span', (string) $n, ['class' => 'tm-survey-scale-n']),
                        ['class' => 'tm-survey-scale-opt']
                    );
                }
                echo html_writer::end_div();
                echo html_writer::end_div();
            } else if ($qtype === survey_manager::TYPE_TEXT) {
                echo html_writer::tag('textarea', '', [
                    'name' => $prefix . '[value]', 'class' => 'form-control tm-survey-text', 'rows' => 3,
                ]);
            } else if ($qtype === survey_manager::TYPE_SINGLE || $qtype === survey_manager::TYPE_MULTI) {
                $inputtype = $qtype === survey_manager::TYPE_SINGLE ? 'radio' : 'checkbox';
                $name = $qtype === survey_manager::TYPE_SINGLE ? $prefix . '[option]' : $prefix . '[options][]';
                echo html_writer::start_div('tm-survey-choices');
                foreach ($item['options'] as $option) {
                    $oid = (int) $option['id'];
                    $isother = !empty($option['isother']);
                    echo html_writer::start_div('tm-survey-choice' . ($isother ? ' tm-survey-choice-other' : ''));
                    echo html_writer::tag('label',
                        html_writer::empty_tag('input', [
                            'type' => $inputtype, 'name' => $name, 'value' => $oid,
                            'class' => 'survey-opt' . ($isother ? ' survey-opt-other' : ''),
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
                echo html_writer::end_div();
            }
            echo html_writer::end_div();
        }
        echo html_writer::end_div();
    }

    echo html_writer::start_div('tm-survey-fill-actions');
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary btn-lg',
        'id' => 'survey-submit-btn',
        'value' => get_string('survey_submit', 'local_tm_course'),
    ]);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

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
}

// ---- Path: sessionid without token → login + redirect (or view if closed + existing) ----
if ($tokenparam === '' && $sessionidparam > 0) {
    require_login();
    $sessionid = $sessionidparam;
    $session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid], '*', MUST_EXIST);
    survey_manager::ensure_session_survey_pin($sessionid);
    $tok = survey_manager::get_token_for_session($sessionid);
    $response = survey_manager::get_response_for_user_session($sessionid, (int) $USER->id);

    if ($tok && !(int) $tok->enabled && $response) {
        // Token closed but learner has a response — allow view while logged in.
        $PAGE->set_context(context_system::instance());
        $PAGE->set_pagelayout('admin');
        $PAGE->set_url(new moodle_url('/local/tm_course/survey.php', ['sessionid' => $sessionid]));
        $PAGE->requires->css('/local/tm_course/styles.css');
        $PAGE->set_title(get_string('survey_learner_title', 'local_tm_course'));
        $structure = survey_manager::get_version_structure((int) $response->versionid);
        $answers = survey_manager::get_response_answers((int) $response->id);
        echo $OUTPUT->header();
        echo html_writer::start_div('tm-survey-fill-page');
        echo html_writer::tag('h2', get_string('survey_learner_title', 'local_tm_course'), [
            'class' => 'tm-survey-fill-page-title',
        ]);
        echo html_writer::tag('p', s($session->name), ['class' => 'tm-survey-fill-page-meta']);
        local_tm_course_survey_render_form(
            $structure,
            $answers,
            new moodle_url('/local/tm_course/survey.php', ['sessionid' => $sessionid]),
            true,
            '',
            (bool) $saved
        );
        echo html_writer::end_div();
        echo $OUTPUT->footer();
        exit;
    }

    if (!$tok) {
        $tok = survey_manager::ensure_session_survey_token($sessionid, true);
    }
    redirect(survey_manager::quick_fill_url((string) $tok->token));
}

if ($tokenparam === '') {
    print_error('invalidparameter', 'error');
}

// ---- Public token path (no require_login) ----
$tokrow = survey_manager::get_token_by_value($tokenparam);
if (!$tokrow || !(int) $tokrow->enabled) {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_pagelayout('popup');
    $PAGE->set_url(new moodle_url('/local/tm_course/survey.php', ['t' => $tokenparam]));
    $PAGE->set_title(get_string('survey_learner_title', 'local_tm_course'));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('survey_quick_token_invalid', 'local_tm_course'), 'error');
    echo $OUTPUT->footer();
    exit;
}

$sessionid = (int) $tokrow->sessionid;
$session = $DB->get_record('local_tm_course_sessions', ['id' => $sessionid], '*', MUST_EXIST);
$wanturl = survey_manager::quick_fill_url((string) $tokrow->token);

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('popup');
$PAGE->set_url($wanturl);
$PAGE->requires->css('/local/tm_course/styles.css');
$PAGE->set_title(get_string('survey_learner_title', 'local_tm_course'));

if (empty($SESSION->tm_survey_email) || !is_array($SESSION->tm_survey_email)) {
    $SESSION->tm_survey_email = [];
}

$error = '';
$email = '';
if (!empty($SESSION->tm_survey_email[$tokenparam])) {
    $email = survey_manager::normalize_email((string) $SESSION->tm_survey_email[$tokenparam]);
}

// POST email step.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $poststep = optional_param('step', '', PARAM_ALPHA);
    if ($poststep === 'email' || ($poststep === '' && optional_param('email', '', PARAM_RAW) !== '')) {
        if (!local_tm_course_survey_rate_ok()) {
            $error = get_string('survey_quick_rate_limited', 'local_tm_course');
        } else {
            $rawemail = optional_param('email', '', PARAM_RAW_TRIMMED);
            $norm = survey_manager::normalize_email($rawemail);
            if ($norm === '') {
                $error = get_string('survey_error_invalid_email', 'local_tm_course');
            } else {
                $SESSION->tm_survey_email[$tokenparam] = $norm;
                redirect(new moodle_url('/local/tm_course/survey.php', ['t' => $tokenparam, 'step' => 'form']));
            }
        }
    } else if ($poststep === 'answers') {
        if ($email === '') {
            redirect(new moodle_url('/local/tm_course/survey.php', ['t' => $tokenparam]));
        }
        try {
            survey_manager::submit_response_by_email($sessionid, $email, $_POST['answer'] ?? []);
            redirect(new moodle_url('/local/tm_course/survey.php', [
                't' => $tokenparam,
                'step' => 'form',
                'saved' => 1,
            ]));
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'survey_error_already_submitted') {
                redirect(new moodle_url('/local/tm_course/survey.php', [
                    't' => $tokenparam,
                    'step' => 'form',
                    'saved' => 1,
                ]));
            }
            $error = $e->getMessage();
        }
    }
}

$surveyname = '';
$pin = survey_manager::get_pin($sessionid);
if (!$pin) {
    survey_manager::ensure_session_survey_pin($sessionid);
    $pin = survey_manager::get_pin($sessionid);
}
$versionid = $pin ? (int) $pin->versionid : 0;
if ($versionid > 0) {
    $ver = $DB->get_record('local_tm_course_svver', ['id' => $versionid], 'id, surveyid');
    if ($ver) {
        $def = $DB->get_record('local_tm_course_svdef', ['id' => $ver->surveyid], 'id, name');
        $surveyname = $def ? (string) $def->name : '';
    }
}

echo $OUTPUT->header();
echo html_writer::start_div('tm-survey-fill-page');
echo html_writer::tag('h2', get_string('survey_learner_title', 'local_tm_course'), [
    'class' => 'tm-survey-fill-page-title',
]);
if ($surveyname !== '') {
    echo html_writer::tag('h3', s($surveyname), ['class' => 'tm-survey-fill-survey-name']);
}
echo html_writer::tag('p', s($session->name), ['class' => 'tm-survey-fill-page-meta']);

if (!survey_manager::is_quick_survey_accepting($sessionid) && $email === '') {
    echo $OUTPUT->notification(get_string('survey_not_open', 'local_tm_course'), 'info');
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// Step 1: email form when session email not set.
if ($email === '' || ($step !== 'form' && $step !== 'answers' && empty($SESSION->tm_survey_email[$tokenparam]))) {
    if ($email === '') {
        $prefill = '';
        if (isloggedin() && !isguestuser() && !empty($USER->email)) {
            $prefill = (string) $USER->email;
        }
        echo html_writer::start_div('tm-card tm-survey-email-card');
        echo html_writer::start_div('tm-card-body');
        echo html_writer::tag('p', get_string('survey_quick_email_help', 'local_tm_course'));
        if ($error !== '') {
            echo $OUTPUT->notification($error, 'error');
        }
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $wanturl->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'step', 'value' => 'email']);
        echo html_writer::tag('label', get_string('survey_quick_email', 'local_tm_course'), ['for' => 'survey-email']);
        echo html_writer::empty_tag('input', [
            'type' => 'email',
            'name' => 'email',
            'id' => 'survey-email',
            'class' => 'form-control mb-3',
            'required' => 'required',
            'value' => $prefill,
            'autocomplete' => 'email',
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-primary',
            'value' => get_string('survey_quick_continue', 'local_tm_course'),
        ]);
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo $OUTPUT->footer();
        exit;
    }
}

// Step 2: questionnaire / view.
$response = null;
if ($email !== '' && $versionid > 0) {
    $response = survey_manager::get_response_by_email($sessionid, $versionid, $email);
}
$viewonly = $response !== null;
$structure = $versionid > 0 ? survey_manager::get_version_structure($versionid) : [];
$answers = $viewonly ? survey_manager::get_response_answers((int) $response->id) : [];

if (!$viewonly && !survey_manager::is_quick_survey_accepting($sessionid)) {
    echo $OUTPUT->notification(get_string('survey_not_open', 'local_tm_course'), 'info');
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

local_tm_course_survey_render_form($structure, $answers, $wanturl, $viewonly, $error, (bool) $saved);
echo html_writer::end_div();
echo $OUTPUT->footer();
