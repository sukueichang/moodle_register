<?php
/**
 * Session-scoped live survey aggregates for class-prep users.
 *
 * URL: /local/tm_course/admin/survey_live.php?sessionid=N
 *      /local/tm_course/admin/survey_live.php?sessionid=N&ajax=1
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/survey_manager.php');
require_once(__DIR__ . '/../classes/survey_stats.php');
require_once(__DIR__ . '/../classes/survey_viz.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');

use local_tm_course\permissions_manager;
use local_tm_course\session_manager;
use local_tm_course\survey_manager;
use local_tm_course\survey_stats;
use local_tm_course\survey_viz;

require_login();
$ctx = context_system::instance();
if (!permissions_manager::user_can_attendance()) {
    throw new required_capability_exception($ctx, 'local/tm_course:attendance', 'nopermissions', '');
}

$sessionid = required_param('sessionid', PARAM_INT);
$ajax = optional_param('ajax', 0, PARAM_INT) === 1;

global $DB, $OUTPUT, $PAGE;

$session = session_manager::get_session($sessionid);
$course = $DB->get_record('course', ['id' => (int) $session->courseid], 'id, fullname', MUST_EXIST);
$snapshot = survey_stats::session_live_snapshot($sessionid);

$surveycrs = $DB->get_record('local_tm_course_svcrs', ['courseid' => (int) $session->courseid]);
$surveydef = $surveycrs ? survey_manager::get_survey((int) $surveycrs->surveyid) : null;
$surveytok = survey_manager::get_token_for_session($sessionid);
if ($surveydef) {
    if ($surveytok && (int) $surveytok->enabled) {
        $statuslabel = get_string('class_prep_survey_open', 'local_tm_course');
    } else if ($surveytok) {
        $statuslabel = get_string('class_prep_survey_closed', 'local_tm_course');
    } else {
        $statuslabel = get_string('class_prep_survey_not_ready', 'local_tm_course');
    }
} else {
    $statuslabel = get_string('class_prep_survey_none', 'local_tm_course');
}

$html = local_tm_course_survey_live_html($snapshot);
$countshtml = local_tm_course_survey_live_counts($snapshot);

if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'mapped' => (int) $snapshot['mapped_count'],
        'unmatched' => (int) $snapshot['unmatched_count'],
        'responses' => (int) $snapshot['response_count'],
        'eligible' => (int) ($snapshot['expected_headcount'] ?? 0),
        'html' => $html,
        'countsHtml' => $countshtml,
    ], JSON_UNESCAPED_UNICODE);
    die;
}

$PAGE->set_context($ctx);
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/tm_course/admin/survey_live.php', ['sessionid' => $sessionid]));
$PAGE->set_title(get_string('survey_live_title', 'local_tm_course'));
survey_viz::require_assets();

$back = new moodle_url('/local/tm_course/admin/class_prep.php', ['sessionid' => $sessionid]);
$pollurl = (new moodle_url('/local/tm_course/admin/survey_live.php', [
    'sessionid' => $sessionid,
    'ajax' => 1,
]))->out(false);

echo $OUTPUT->header();
echo html_writer::link($back, get_string('survey_live_back', 'local_tm_course'), ['class' => 'btn btn-sm btn-secondary mb-3']);
echo html_writer::start_div('tm-survey-live-page');
echo html_writer::tag('h2', get_string('survey_live_title', 'local_tm_course'), ['class' => 'tm-survey-panel-title']);
echo html_writer::start_div('tm-card mb-3');
echo html_writer::start_div('tm-card-body');
echo html_writer::tag('h3', s($course->fullname), ['class' => 'h5 mb-2']);
echo html_writer::tag('p', s($session->name) . ' — ' . userdate((int) $session->starttime, get_string('strftimedatetimeshort')), [
    'class' => 'mb-1',
]);
if ($surveydef) {
    echo html_writer::tag('p', s($surveydef->name), ['class' => 'mb-1 font-weight-bold']);
}
echo html_writer::tag('p', get_string('class_prep_survey_status', 'local_tm_course') . '：' . s($statuslabel), ['class' => 'mb-2']);
echo html_writer::start_div('', ['id' => 'tm-survey-live-counts']);
echo $countshtml;
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::div($html, '', ['id' => 'tm-survey-live-body']);
echo html_writer::end_div();

$polljson = json_encode($pollurl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$PAGE->requires->js_init_code(<<<JS
(function() {
    var url = {$polljson};
    var body = document.getElementById('tm-survey-live-body');
    var counts = document.getElementById('tm-survey-live-counts');
    if (!url || !body) { return; }
    function tick() {
        fetch(url, {credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.ok) { return; }
                if (typeof data.html === 'string') {
                    body.innerHTML = data.html;
                    if (window.tmSurveyViz) { window.tmSurveyViz.scan(body); }
                }
                if (counts && data.countsHtml) { counts.innerHTML = data.countsHtml; }
            })
            .catch(function() {});
    }
    setInterval(tick, 9000);
})();
JS
);
echo $OUTPUT->footer();

/**
 * @param array $snapshot
 */
function local_tm_course_survey_live_counts(array $snapshot): string {
    $eligible = (int) ($snapshot['expected_headcount'] ?? 0);
    $html = html_writer::tag('div',
        get_string('class_prep_survey_mapped', 'local_tm_course') . '：'
        . (int) $snapshot['mapped_count'] . ' / ' . $eligible
    );
    $html .= html_writer::tag('div',
        get_string('class_prep_survey_unmatched', 'local_tm_course') . '：'
        . (int) $snapshot['unmatched_count']
    );
    $html .= html_writer::tag('div',
        get_string('class_prep_survey_responses', 'local_tm_course') . '：'
        . (int) $snapshot['response_count']
        . ' · ' . get_string('class_prep_survey_eligible', 'local_tm_course') . '：' . $eligible,
        ['class' => 'text-muted small']
    );
    return $html;
}

/**
 * @param array $snapshot
 */
function local_tm_course_survey_live_html(array $snapshot): string {
    if (empty($snapshot['sections'])) {
        return html_writer::div(get_string('survey_viz_empty', 'local_tm_course'), 'tm-sviz-empty');
    }
    return survey_viz::html_sections($snapshot['sections']);
}
