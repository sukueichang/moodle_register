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
require_once(__DIR__ . '/../classes/enabled_course_manager.php');

use local_tm_course\enabled_course_manager;
use local_tm_course\survey_manager;
use local_tm_course\survey_stats;

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

$params = [
    'surveyid' => optional_param('surveyid', 0, PARAM_INT),
    'versionid' => optional_param('versionid', 0, PARAM_INT),
    'courseid' => optional_param('courseid', 0, PARAM_INT),
    'sessionid' => optional_param('sessionid', 0, PARAM_INT),
    'datefrom' => $datefrom,
    'dateto' => $dateto,
    'email' => optional_param('email', '', PARAM_RAW_TRIMMED),
    'mapped' => optional_param('mapped', -1, PARAM_INT),
];
// Keep ISO date strings in the form / export query so filters round-trip.
$paramsui = $params;
$paramsui['datefrom'] = $datefromstr;
$paramsui['dateto'] = $datetostr;

$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 50;

$filters = survey_stats::filters_from_params($params);
$summary = survey_stats::summary($filters);
$qstats = survey_stats::question_stats($filters);
$total = survey_stats::count_responses($filters);
$rows = survey_stats::list_responses($filters, $page, $perpage);

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
$PAGE->requires->css('/local/tm_course/styles.css');

$surveys = survey_manager::list_surveys();
$coursemenu = enabled_course_manager::get_course_menu();

echo $OUTPUT->header();
echo html_writer::tag('h2', get_string('survey_stats_title', 'local_tm_course'));
echo html_writer::link(
    new moodle_url('/local/tm_course/admin/surveys.php'),
    get_string('survey_back', 'local_tm_course'),
    ['class' => 'd-inline-block mb-3']
);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url('/local/tm_course/admin/survey_results.php'))->out(false), 'class' => 'mb-4']);
echo html_writer::start_div('form-row');

echo html_writer::start_div('form-group col-md-3');
echo html_writer::tag('label', get_string('survey_column', 'local_tm_course'));
echo html_writer::start_tag('select', ['name' => 'surveyid', 'class' => 'form-control']);
echo html_writer::tag('option', '—', ['value' => 0]);
foreach ($surveys as $s) {
    $attrs = ['value' => (int) $s->id];
    if ((int) $s->id === (int) $filters->surveyid) {
        $attrs['selected'] = 'selected';
    }
    echo html_writer::tag('option', s($s->name), $attrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::tag('label', get_string('survey_stats_versionid', 'local_tm_course'));
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'versionid', 'class' => 'form-control',
    'value' => (int) $filters->versionid, 'min' => 0,
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-3');
echo html_writer::tag('label', get_string('course'));
echo html_writer::start_tag('select', ['name' => 'courseid', 'class' => 'form-control']);
echo html_writer::tag('option', '—', ['value' => 0]);
foreach ($coursemenu as $cid => $cname) {
    $attrs = ['value' => (int) $cid];
    if ((int) $cid === (int) $filters->courseid) {
        $attrs['selected'] = 'selected';
    }
    echo html_writer::tag('option', s($cname), $attrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::tag('label', get_string('survey_stats_sessionid', 'local_tm_course'));
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'sessionid', 'class' => 'form-control',
    'value' => (int) $filters->sessionid, 'min' => 0,
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::tag('label', get_string('survey_mapped', 'local_tm_course'));
echo html_writer::start_tag('select', ['name' => 'mapped', 'class' => 'form-control']);
$mappedopts = [-1 => get_string('all'), 1 => get_string('survey_mapped', 'local_tm_course'), 0 => get_string('survey_unmapped', 'local_tm_course')];
foreach ($mappedopts as $val => $label) {
    $attrs = ['value' => $val];
    if ((int) $filters->mapped === (int) $val) {
        $attrs['selected'] = 'selected';
    }
    echo html_writer::tag('option', $label, $attrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('form-row');
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
echo html_writer::start_div('form-group col-md-4');
echo html_writer::tag('label', get_string('survey_quick_email', 'local_tm_course'));
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'email', 'class' => 'form-control',
    'value' => s($filters->email),
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

// Question stats.
echo html_writer::tag('h3', get_string('survey_stats_questions', 'local_tm_course'));
if ($qstats['message'] !== '') {
    echo $OUTPUT->notification($qstats['message'], 'info');
}
foreach ($qstats['questions'] as $q) {
    echo html_writer::start_div('border rounded p-3 mb-3');
    echo html_writer::tag('div', s($q['title']) . ' (' . s($q['qtype']) . ')', ['class' => 'font-weight-bold']);
    echo html_writer::tag('div', get_string('survey_stats_answered', 'local_tm_course', $q['answered']), ['class' => 'text-muted small mb-2']);
    if ($q['qtype'] === survey_manager::TYPE_SCALE) {
        echo html_writer::tag('div', get_string('survey_stats_average', 'local_tm_course', $q['average'] !== null ? $q['average'] : '—'));
        $table = new html_table();
        $table->head = ['1', '2', '3', '4', '5'];
        $table->data = [[
            $q['scale_counts'][1] ?? 0,
            $q['scale_counts'][2] ?? 0,
            $q['scale_counts'][3] ?? 0,
            $q['scale_counts'][4] ?? 0,
            $q['scale_counts'][5] ?? 0,
        ]];
        echo html_writer::table($table);
    } else if ($q['qtype'] === survey_manager::TYPE_SINGLE || $q['qtype'] === survey_manager::TYPE_MULTI) {
        $table = new html_table();
        $table->head = [
            get_string('survey_options', 'local_tm_course'),
            get_string('survey_stats_count', 'local_tm_course'),
            '%',
        ];
        foreach ($q['options'] as $opt) {
            $table->data[] = [s($opt['label']), $opt['count'], $opt['pct'] . '%'];
        }
        echo html_writer::table($table);
        if ($q['qtype'] === survey_manager::TYPE_MULTI) {
            echo html_writer::tag('p', get_string('survey_stats_multi_note', 'local_tm_course'), ['class' => 'small text-muted']);
        }
    } else if ($q['qtype'] === survey_manager::TYPE_TEXT) {
        echo html_writer::start_tag('ul');
        foreach ($q['texts'] as $text) {
            echo html_writer::tag('li', s($text));
        }
        echo html_writer::end_tag('ul');
    }
    echo html_writer::end_div();
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

$baseurl = new moodle_url('/local/tm_course/admin/survey_results.php', $params);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
