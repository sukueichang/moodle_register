<?php
/**
 * Admin survey results / statistics (SPEC §59 Phase 4).
 *
 * URL: /local/tm_course/admin/survey_results.php
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/survey_manager.php');
require_once(__DIR__ . '/../classes/survey_stats.php');
require_once(__DIR__ . '/../classes/survey_viz.php');
require_once(__DIR__ . '/../classes/enabled_course_manager.php');

use local_tm_course\enabled_course_manager;
use local_tm_course\survey_manager;
use local_tm_course\survey_stats;
use local_tm_course\survey_viz;

require_login();
require_capability('local/tm_course:manage', context_system::instance());

$datefromstr = optional_param('datefrom', '', PARAM_RAW_TRIMMED);
$datetostr = optional_param('dateto', '', PARAM_RAW_TRIMMED);
$datefrom = 0;
$dateto = 0;
if ($datefromstr !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datefromstr)) {
    $datefrom = (int) make_timestamp((int) substr($datefromstr, 0, 4), (int) substr($datefromstr, 5, 2), (int) substr($datefromstr, 8, 2), 0, 0, 0);
}
if ($datetostr !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datetostr)) {
    // Inclusive end of day.
    $dateto = (int) make_timestamp((int) substr($datetostr, 0, 4), (int) substr($datetostr, 5, 2), (int) substr($datetostr, 8, 2), 23, 59, 59);
}

$surveyid = optional_param('surveyid', 0, PARAM_INT);
// Version id and email are not admin filters. Ignore them if present on the URL.
$params = [
    'surveyid' => $surveyid,
    'versionid' => 0,
    'courseid' => optional_param('courseid', 0, PARAM_INT),
    'sessionid' => optional_param('sessionid', 0, PARAM_INT),
    'datefrom' => $datefrom,
    'dateto' => $dateto,
    'email' => '',
    'mapped' => optional_param('mapped', -1, PARAM_INT),
];
$paramsui = [
    'surveyid' => $surveyid,
    'courseid' => $params['courseid'],
    'sessionid' => $params['sessionid'],
    'datefrom' => $datefromstr,
    'dateto' => $datetostr,
    'mapped' => $params['mapped'],
];

$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 50;

$coursemenu = [];
$sessionmenu = [];
if ($surveyid > 0) {
    $coursemenu = survey_results_course_options($surveyid);
    $sessionmenu = survey_results_session_options($surveyid);
    if (!isset($coursemenu[(int) $params['courseid']])) {
        $params['courseid'] = 0;
    }
    if (!isset($sessionmenu[(int) $params['sessionid']])) {
        $params['sessionid'] = 0;
    }
    $paramsui['courseid'] = $params['courseid'];
    $paramsui['sessionid'] = $params['sessionid'];
}

$filters = null;
$summary = null;
$qstats = null;
$total = 0;
$rows = [];
if ($surveyid > 0) {
    $filters = survey_stats::filters_from_params($params);
    $summary = survey_stats::summary($filters);
    $qstats = survey_stats::question_stats($filters);
    $total = survey_stats::count_responses($filters);
    $rows = survey_stats::list_responses($filters, $page, $perpage);
}

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$pageurlparams = [];
foreach ($paramsui as $k => $v) {
    if ($v === '' || $v === null) {
        continue;
    }
    if ($k === 'mapped') {
        if ((int) $v === -1) {
            continue;
        }
        $pageurlparams[$k] = $v;
        continue;
    }
    if ($v === 0 || $v === '0') {
        continue;
    }
    $pageurlparams[$k] = $v;
}
$PAGE->set_url(new moodle_url('/local/tm_course/admin/survey_results.php', $pageurlparams));
$PAGE->set_title(get_string('survey_stats_title', 'local_tm_course'));
survey_viz::require_assets();

$surveys = survey_manager::list_surveys();

echo $OUTPUT->header();
echo html_writer::tag('h2', get_string('survey_stats_title', 'local_tm_course'));
echo html_writer::link(
    new moodle_url('/local/tm_course/admin/surveys.php'),
    get_string('survey_back', 'local_tm_course'),
    ['class' => 'd-inline-block mb-3']
);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url('/local/tm_course/admin/survey_results.php'))->out(false), 'class' => 'mb-4']);
echo html_writer::start_div('form-row');

$selectedcourse = (int) $params['courseid'];
$selectedsession = (int) $params['sessionid'];
$selectedmapped = (int) $params['mapped'];

echo html_writer::start_div('form-group col-md-4');
echo html_writer::tag('label', get_string('survey_column', 'local_tm_course'));
echo html_writer::start_tag('select', ['name' => 'surveyid', 'class' => 'form-control', 'onchange' => 'this.form.submit()']);
echo html_writer::tag('option', '—', ['value' => 0]);
foreach ($surveys as $s) {
    $attrs = ['value' => (int) $s->id];
    if ((int) $s->id === $surveyid) {
        $attrs['selected'] = 'selected';
    }
    echo html_writer::tag('option', s($s->name), $attrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-4');
echo html_writer::tag('label', get_string('course'));
echo html_writer::start_tag('select', ['name' => 'courseid', 'class' => 'form-control']);
echo html_writer::tag('option', '—', ['value' => 0]);
foreach ($coursemenu as $cid => $cname) {
    $attrs = ['value' => (int) $cid];
    if ((int) $cid === $selectedcourse) {
        $attrs['selected'] = 'selected';
    }
    echo html_writer::tag('option', s($cname), $attrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-4');
echo html_writer::tag('label', get_string('survey_stats_session_filter', 'local_tm_course'));
echo html_writer::start_tag('select', ['name' => 'sessionid', 'class' => 'form-control']);
echo html_writer::tag('option', '—', ['value' => 0]);
foreach ($sessionmenu as $sid => $slabel) {
    $attrs = ['value' => (int) $sid];
    if ((int) $sid === $selectedsession) {
        $attrs['selected'] = 'selected';
    }
    echo html_writer::tag('option', s($slabel), $attrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::start_div('form-row');

echo html_writer::start_div('form-group col-md-3');
echo html_writer::tag('label', get_string('survey_mapped', 'local_tm_course'));
echo html_writer::start_tag('select', ['name' => 'mapped', 'class' => 'form-control']);
$mappedopts = [-1 => get_string('all'), 1 => get_string('survey_mapped', 'local_tm_course'), 0 => get_string('survey_unmapped', 'local_tm_course')];
foreach ($mappedopts as $val => $label) {
    $attrs = ['value' => $val];
    if ($selectedmapped === (int) $val) {
        $attrs['selected'] = 'selected';
    }
    echo html_writer::tag('option', $label, $attrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-3');
echo html_writer::tag('label', get_string('survey_stats_datefrom', 'local_tm_course'));
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'datefrom', 'class' => 'form-control',
    'value' => s($datefromstr),
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group col-md-3');
echo html_writer::tag('label', get_string('survey_stats_dateto', 'local_tm_course'));
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'dateto', 'class' => 'form-control',
    'value' => s($datetostr),
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group col-md-2 align-self-end');
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'class' => 'btn btn-primary',
    'value' => get_string('survey_stats_filter', 'local_tm_course'),
]);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('form');

if ($surveyid <= 0) {
    echo $OUTPUT->notification(get_string('survey_stats_pick_survey', 'local_tm_course'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Summary cards.
echo html_writer::start_div('row mb-4');
$cards = [
    get_string('survey_stats_responses', 'local_tm_course') => $summary['response_count'],
    get_string('survey_mapped', 'local_tm_course') => $summary['mapped_count'],
    get_string('survey_unmapped', 'local_tm_course') => $summary['unmatched_count'],
];
if ($summary['expected_headcount'] !== null) {
    $cards[get_string('survey_stats_expected', 'local_tm_course')] = $summary['expected_headcount'];
}
if ($summary['rate'] !== null) {
    $cards[get_string('survey_stats_rate', 'local_tm_course')] = $summary['rate'] . '%';
}
foreach ($cards as $label => $val) {
    echo html_writer::start_div('col-md-2');
    echo html_writer::start_div('border rounded p-3 mb-2 text-center');
    echo html_writer::tag('div', s((string) $val), ['style' => 'font-size:1.6rem;font-weight:700']);
    echo html_writer::tag('div', s($label), ['class' => 'text-muted small']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

$exportparams = array_merge($paramsui, ['sesskey' => sesskey()]);
echo html_writer::link(
    new moodle_url('/local/tm_course/admin/survey_export.php', $exportparams),
    get_string('survey_export_button', 'local_tm_course'),
    ['class' => 'btn btn-secondary mb-4']
);

// Question stats (shared visualization).
echo html_writer::tag('h3', get_string('survey_stats_questions', 'local_tm_course'));
if ($qstats['message'] !== '') {
    echo $OUTPUT->notification($qstats['message'], 'info');
}
if (!$qstats['questions']) {
    echo html_writer::div(get_string('survey_viz_empty', 'local_tm_course'), 'tm-sviz-empty');
} else {
    echo survey_viz::html($qstats['questions']);
}

// Response list.
echo html_writer::tag('h3', get_string('survey_stats_response_list', 'local_tm_course'));
$table = new html_table();
$table->head = [
    'ID',
    get_string('survey_quick_email', 'local_tm_course'),
    get_string('survey_mapped', 'local_tm_course'),
    get_string('survey_stats_session', 'local_tm_course'),
    get_string('course'),
    get_string('survey_stats_submitted', 'local_tm_course'),
];
foreach ($rows as $row) {
    $table->data[] = [
        (int) $row->id,
        s($row->email),
        ((int) $row->mapped === survey_manager::MAPPED)
            ? get_string('survey_mapped', 'local_tm_course')
            : get_string('survey_unmapped', 'local_tm_course'),
        s($row->sessionname),
        s($row->coursename),
        userdate((int) $row->timecreated),
    ];
}
echo html_writer::table($table);

$baseurl = new moodle_url('/local/tm_course/admin/survey_results.php', $pageurlparams);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

echo $OUTPUT->footer();

/**
 * Courses tied to this survey: current assignment, plus any course that already has responses.
 *
 * @return array<int,string>
 */
function survey_results_course_options(int $surveyid): array {
    global $DB;
    $labels = enabled_course_manager::get_course_menu();
    $ids = survey_manager::assigned_courseids($surveyid);
    $answered = $DB->get_fieldset_sql(
        "SELECT DISTINCT s.courseid
           FROM {local_tm_course_svresp} r
           JOIN {local_tm_course_svver} v ON v.id = r.versionid
           JOIN {local_tm_course_sessions} s ON s.id = r.sessionid
          WHERE v.surveyid = :surveyid",
        ['surveyid' => $surveyid]
    );
    foreach ($answered as $courseid) {
        $ids[] = (int) $courseid;
    }
    $ids = array_values(array_unique(array_filter($ids)));
    $out = [];
    foreach ($ids as $courseid) {
        if (isset($labels[$courseid])) {
            $out[$courseid] = $labels[$courseid];
            continue;
        }
        $name = $DB->get_field('course', 'fullname', ['id' => $courseid]);
        $out[$courseid] = $name ? (string) $name : ('#' . $courseid);
    }
    asort($out);
    return $out;
}

/**
 * Sessions for this survey. Label is date/time and course name, not the raw id.
 *
 * @return array<int,string>
 */
function survey_results_session_options(int $surveyid): array {
    global $DB;
    $rows = $DB->get_records_sql(
        "SELECT DISTINCT s.id, s.name, s.starttime, c.fullname
           FROM {local_tm_course_sessions} s
           JOIN {course} c ON c.id = s.courseid
          WHERE s.courseid IN (
                    SELECT courseid FROM {local_tm_course_svcrs} WHERE surveyid = :surveyid1
                )
             OR s.id IN (
                    SELECT r.sessionid
                      FROM {local_tm_course_svresp} r
                      JOIN {local_tm_course_svver} v ON v.id = r.versionid
                     WHERE v.surveyid = :surveyid2
                )
       ORDER BY s.starttime DESC, s.id DESC",
        ['surveyid1' => $surveyid, 'surveyid2' => $surveyid]
    );
    $out = [];
    foreach ($rows as $row) {
        $when = userdate((int) $row->starttime, '%Y/%m/%d %H:%M');
        $out[(int) $row->id] = $when . '－' . (string) $row->fullname;
    }
    return $out;
}
