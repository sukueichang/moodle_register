<?php
/**
 * Admin: course survey list and editor (SPEC §59 stage 1).
 *
 * URL: /local/tm_course/admin/surveys.php[?id=N][&versionid=N]
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/survey_manager.php');
require_once(__DIR__ . '/../classes/enabled_course_manager.php');

use local_tm_course\enabled_course_manager;
use local_tm_course\survey_manager;

require_login();
require_capability('local/tm_course:manage', context_system::instance());

$surveyid = optional_param('id', 0, PARAM_INT);
$versionid = optional_param('versionid', 0, PARAM_INT);
$pageparams = [];
if ($surveyid > 0) {
    $pageparams['id'] = $surveyid;
}
if ($versionid > 0) {
    $pageparams['versionid'] = $versionid;
}
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/tm_course/admin/surveys.php', $pageparams));
$PAGE->set_title(get_string('survey_list_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

/**
 * @return array<int,array<string,mixed>>
 */
function survey_admin_blank_section(): array {
    return [[
        'name' => '',
        'items' => [[
            'stablekey' => '',
            'qtype' => survey_manager::TYPE_SCALE,
            'title' => '',
            'help' => '',
            'required' => 1,
            'scalemin' => '',
            'scalemax' => '',
            'allowother' => 0,
            'options' => [],
        ]],
    ]];
}

/**
 * Parse "one option per line" textarea (+ optional stablekey lines) into option rows.
 * "其他" is not typed here; it comes from allowother.
 *
 * @return array<int,array{stablekey:string,label:string,isother:int}>
 */
function survey_admin_parse_options_text(string $text, string $keys = ''): array {
    $labels = preg_split("/\r\n|\r|\n/", $text) ?: [];
    $keylines = preg_split("/\r\n|\r|\n/", $keys) ?: [];
    $options = [];
    $ki = 0;
    foreach ($labels as $line) {
        $label = trim(clean_param((string) $line, PARAM_TEXT));
        if ($label === '') {
            continue;
        }
        $stablekey = '';
        if (isset($keylines[$ki])) {
            $stablekey = clean_param(trim((string) $keylines[$ki]), PARAM_ALPHANUMEXT);
        }
        $options[] = [
            'stablekey' => $stablekey,
            'label' => $label,
            'isother' => 0,
        ];
        $ki++;
    }
    return $options;
}

/**
 * Build textarea value and matching stablekey lines from stored options (skip isother).
 *
 * @param array<int,array<string,mixed>> $options
 * @return array{0:string,1:string}
 */
function survey_admin_options_textarea_payload(array $options): array {
    $labels = [];
    $keys = [];
    foreach ($options as $option) {
        if (!is_array($option) || !empty($option['isother'])) {
            continue;
        }
        $labels[] = (string) ($option['label'] ?? '');
        $keys[] = (string) ($option['stablekey'] ?? '');
    }
    return [implode("\n", $labels), implode("\n", $keys)];
}

/**
 * @return array<int,array<string,mixed>>
 */
function survey_admin_read_post(): array {
    $raw = $_POST['section'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $sections = [];
    foreach ($raw as $section) {
        if (!is_array($section)) {
            continue;
        }
        $items = [];
        foreach (($section['item'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (array_key_exists('options_text', $item)) {
                $options = survey_admin_parse_options_text(
                    (string) ($item['options_text'] ?? ''),
                    (string) ($item['options_keys'] ?? '')
                );
            } else {
                $options = [];
                foreach (($item['option'] ?? []) as $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $options[] = [
                        'stablekey' => clean_param((string) ($option['stablekey'] ?? ''), PARAM_ALPHANUMEXT),
                        'label' => clean_param((string) ($option['label'] ?? ''), PARAM_TEXT),
                        'isother' => !empty($option['isother']) ? 1 : 0,
                    ];
                }
            }
            $items[] = [
                'stablekey' => clean_param((string) ($item['stablekey'] ?? ''), PARAM_ALPHANUMEXT),
                'qtype' => clean_param((string) ($item['qtype'] ?? ''), PARAM_ALPHANUMEXT),
                'title' => clean_param((string) ($item['title'] ?? ''), PARAM_TEXT),
                'help' => clean_param((string) ($item['help'] ?? ''), PARAM_TEXT),
                'required' => !empty($item['required']) ? 1 : 0,
                'scalemin' => clean_param((string) ($item['scalemin'] ?? ''), PARAM_TEXT),
                'scalemax' => clean_param((string) ($item['scalemax'] ?? ''), PARAM_TEXT),
                'allowother' => !empty($item['allowother']) ? 1 : 0,
                'options' => $options,
            ];
        }
        $sections[] = [
            'name' => clean_param((string) ($section['name'] ?? ''), PARAM_TEXT),
            'items' => $items,
        ];
    }
    return $sections;
}

if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'create' && confirm_sesskey()) {
    $newid = survey_manager::create_survey(
        required_param('name', PARAM_TEXT),
        (int) $USER->id
    );
    redirect(new moodle_url('/local/tm_course/admin/surveys.php', ['id' => $newid]));
}

if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'copy' && confirm_sesskey()) {
    $sourceid = required_param('copyid', PARAM_INT);
    $newid = survey_manager::copy_survey($sourceid, (int) $USER->id);
    redirect(
        new moodle_url('/local/tm_course/admin/surveys.php', ['id' => $newid]),
        get_string('survey_copied', 'local_tm_course'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$error = '';
$posted = null;
if ($surveyid > 0 && optional_param('action', '', PARAM_ALPHANUMEXT) === 'save' && confirm_sesskey()) {
    try {
        $currentbefore = survey_manager::current_version($surveyid);
        if ($versionid > 0 && (!$currentbefore || $versionid !== (int) $currentbefore->id)) {
            throw new moodle_exception('survey_error_notfound', 'local_tm_course');
        }
        $courseids = optional_param_array('courseids', [], PARAM_INT);
        // Validate course conflicts before any name/enabled/structure writes.
        survey_manager::assert_courses_assignable($surveyid, $courseids);
        $sections = survey_admin_read_post();
        survey_manager::normalise_sections($sections);
        survey_manager::update_name($surveyid, required_param('name', PARAM_TEXT));
        survey_manager::set_enabled($surveyid, (bool) optional_param('enabled', 0, PARAM_BOOL));
        survey_manager::set_course_assignments($surveyid, $courseids);
        $savedversion = survey_manager::save_structure($surveyid, $sections, (int) $USER->id);
        redirect(new moodle_url('/local/tm_course/admin/surveys.php', [
            'id' => $surveyid,
            'versionid' => $savedversion,
        ]), get_string('survey_saved', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        $error = $e->getMessage();
        $posted = survey_admin_read_post();
    }
}

echo $OUTPUT->header();

if ($surveyid <= 0) {
    $surveys = survey_manager::list_surveys();
    echo html_writer::tag('h2', get_string('survey_list_title', 'local_tm_course'));
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/local/tm_course/admin/survey_results.php'),
            get_string('survey_stats_title', 'local_tm_course'),
            ['class' => 'btn btn-secondary btn-sm mb-3']
        )
    );
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'mb-4']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'create']);
    echo html_writer::tag('label', get_string('survey_name', 'local_tm_course'), ['for' => 'survey-new-name', 'class' => 'mr-2']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'name', 'id' => 'survey-new-name', 'class' => 'form-control d-inline-block',
        'style' => 'width:20rem', 'maxlength' => 255, 'required' => 'required',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'class' => 'btn btn-primary ml-2', 'value' => get_string('survey_create', 'local_tm_course'),
    ]);
    echo html_writer::end_tag('form');

    if (!$surveys) {
        echo $OUTPUT->notification(get_string('survey_list_empty', 'local_tm_course'), 'info');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('survey_name', 'local_tm_course'),
            get_string('survey_enabled', 'local_tm_course'),
            get_string('survey_version', 'local_tm_course', ''),
            get_string('survey_courses', 'local_tm_course'),
            get_string('survey_copy', 'local_tm_course'),
        ];
        foreach ($surveys as $survey) {
            $url = new moodle_url('/local/tm_course/admin/surveys.php', ['id' => $survey->id]);
            $copyform = html_writer::start_tag('form', [
                'method' => 'post',
                'action' => (new moodle_url('/local/tm_course/admin/surveys.php'))->out(false),
                'class' => 'd-inline',
            ]);
            $copyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $copyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'copy']);
            $copyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'copyid', 'value' => (int) $survey->id]);
            $copyform .= html_writer::empty_tag('input', [
                'type' => 'submit',
                'class' => 'btn btn-outline-secondary btn-sm',
                'value' => get_string('survey_copy', 'local_tm_course'),
            ]);
            $copyform .= html_writer::end_tag('form');
            $table->data[] = [
                html_writer::link($url, s($survey->name)),
                (int) $survey->enabled ? get_string('survey_enabled', 'local_tm_course') : get_string('survey_disabled', 'local_tm_course'),
                (int) $survey->versionno . ($survey->versionfrozen ? ' (' . get_string('survey_version_frozen', 'local_tm_course') . ')' : ''),
                (int) $survey->coursecount,
                $copyform,
            ];
        }
        echo html_writer::table($table);
    }
    echo $OUTPUT->footer();
    exit;
}

$survey = survey_manager::get_survey($surveyid);
$versions = survey_manager::list_versions($surveyid);
$current = survey_manager::current_version($surveyid);
$viewversion = $versionid > 0 ? $versionid : ($current ? (int) $current->id : 0);
$ownsversion = false;
foreach ($versions as $version) {
    if ((int) $version->id === $viewversion) {
        $ownsversion = true;
        break;
    }
}
if (!$ownsversion) {
    $viewversion = $current ? (int) $current->id : 0;
}
$iseditable = $current && $viewversion === (int) $current->id;
$structure = $posted !== null ? $posted : ($viewversion > 0 ? survey_manager::get_version_structure($viewversion) : []);
if (!$structure) {
    $structure = survey_admin_blank_section();
}

$coursemenu = enabled_course_manager::get_course_menu();
$assigned = survey_manager::assigned_courseids($surveyid);
foreach ($assigned as $courseid) {
    if (!isset($coursemenu[$courseid])) {
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', IGNORE_MISSING);
        $coursemenu[$courseid] = $course ? $course->fullname : ('#' . $courseid);
    }
}

echo html_writer::link(new moodle_url('/local/tm_course/admin/surveys.php'), get_string('survey_back', 'local_tm_course'));
echo html_writer::tag('h2', s($survey->name));
if ($error !== '') {
    echo $OUTPUT->notification($error, 'error');
}
echo html_writer::start_div('mb-3');
foreach ($versions as $version) {
    $url = new moodle_url('/local/tm_course/admin/surveys.php', ['id' => $surveyid, 'versionid' => $version->id]);
    $label = get_string('survey_version', 'local_tm_course', (int) $version->versionno);
    if ((int) $version->id === (int) $current->id) {
        $label .= ' (' . get_string('survey_version_current', 'local_tm_course') . ')';
    }
    if (!empty($version->isfrozen)) {
        $label .= ' / ' . get_string('survey_version_frozen', 'local_tm_course');
    }
    $class = ((int) $version->id === $viewversion) ? 'btn btn-primary btn-sm mr-1' : 'btn btn-outline-secondary btn-sm mr-1';
    echo html_writer::link($url, $label, ['class' => $class]);
}
echo html_writer::end_div();

if (!$iseditable) {
    echo $OUTPUT->notification(get_string('survey_readonly_old', 'local_tm_course'), 'info');
}

$types = [
    survey_manager::TYPE_SINGLE => get_string('survey_type_single', 'local_tm_course'),
    survey_manager::TYPE_MULTI => get_string('survey_type_multi', 'local_tm_course'),
    survey_manager::TYPE_SCALE => get_string('survey_type_scale', 'local_tm_course'),
    survey_manager::TYPE_TEXT => get_string('survey_type_text', 'local_tm_course'),
];
$disabled = $iseditable ? [] : ['disabled' => 'disabled'];
$postedcourses = $posted !== null ? optional_param_array('courseids', [], PARAM_INT) : $assigned;
$namevalue = $posted !== null ? optional_param('name', '', PARAM_TEXT) : $survey->name;
$enabledchecked = $posted !== null ? optional_param('enabled', 0, PARAM_BOOL) : (int) $survey->enabled;

echo html_writer::start_tag('form', ['method' => 'post', 'id' => 'survey-editor', 'class' => 'tm-survey-editor']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $surveyid]);

// ---- Basics card ----
echo html_writer::start_div('tm-card tm-survey-basics mt-3');
echo html_writer::start_div('tm-card-body');
echo html_writer::tag('h3', get_string('survey_basics', 'local_tm_course'), ['class' => 'tm-survey-panel-title']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('survey_name', 'local_tm_course'));
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'name', 'class' => 'form-control', 'maxlength' => 255,
    'value' => $namevalue,
] + $disabled);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label',
    html_writer::empty_tag('input', [
        'type' => 'checkbox', 'name' => 'enabled', 'value' => '1',
    ] + ($enabledchecked ? ['checked' => 'checked'] : []) + $disabled)
    . ' ' . get_string('survey_enabled', 'local_tm_course')
);
echo html_writer::end_div();
echo html_writer::tag('h4', get_string('survey_courses', 'local_tm_course'), ['class' => 'h6 mt-3']);
echo html_writer::tag('p', get_string('survey_courses_help', 'local_tm_course'), ['class' => 'text-muted small']);
if (!$coursemenu) {
    echo html_writer::tag('p', get_string('survey_no_courses', 'local_tm_course'));
}
echo html_writer::start_div('tm-survey-course-list');
foreach ($coursemenu as $courseid => $coursename) {
    $checked = in_array((int) $courseid, array_map('intval', $postedcourses), true) ? ['checked' => 'checked'] : [];
    echo html_writer::tag('label',
        html_writer::empty_tag('input', [
            'type' => 'checkbox', 'name' => 'courseids[]', 'value' => (int) $courseid,
        ] + $checked + $disabled)
        . ' ' . s($coursename),
        ['class' => 'd-block']
    );
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// ---- Content ----
echo html_writer::start_div('tm-survey-content mt-4');
echo html_writer::tag('h3', get_string('survey_content', 'local_tm_course'), ['class' => 'tm-survey-panel-title']);
echo html_writer::start_div('', ['id' => 'survey-sections']);

$qnum = 0;
foreach (array_values($structure) as $sidx => $section) {
    $secnum = $sidx + 1;
    $secname = (string) ($section['name'] ?? '');
    echo html_writer::start_div('tm-card tm-survey-section-card survey-section mt-3');
    echo html_writer::start_div('tm-card-body');
    echo html_writer::start_div('tm-survey-section-head');
    echo html_writer::tag('span', sprintf('%02d', $secnum), ['class' => 'tm-survey-section-num']);
    echo html_writer::start_div('tm-survey-section-name-wrap flex-grow-1');
    echo html_writer::tag('label', get_string('survey_section', 'local_tm_course'), ['class' => 'sr-only']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => "section[$sidx][name]",
        'class' => 'form-control survey-section-name',
        'placeholder' => get_string('survey_section', 'local_tm_course'),
        'value' => $secname,
    ] + $disabled);
    echo html_writer::end_div();
    echo html_writer::end_div();

    $items = $section['items'] ?? [];
    if (!$items) {
        $items = survey_admin_blank_section()[0]['items'];
    }
    echo html_writer::start_div('tm-survey-qlist');
    foreach (array_values($items) as $iidx => $item) {
        $qnum++;
        $prefix = "section[$sidx][item][$iidx]";
        $qtype = (string) ($item['qtype'] ?? survey_manager::TYPE_SCALE);
        $typelabel = $types[$qtype] ?? $qtype;
        $title = (string) ($item['title'] ?? '');
        $required = !empty($item['required']);
        $reqlabel = $required
            ? get_string('survey_required_yes', 'local_tm_course')
            : get_string('survey_required_no', 'local_tm_course');

        echo html_writer::start_div('tm-survey-qcard survey-item', ['data-qnum' => $qnum]);
        // Summary (collapsed default).
        echo html_writer::start_div('tm-survey-qcard-summary');
        echo html_writer::tag('span', get_string('survey_question_n', 'local_tm_course', $qnum), [
            'class' => 'tm-survey-qnum survey-qnum-label',
        ]);
        echo html_writer::start_div('tm-survey-qcard-meta');
        echo html_writer::tag('div', s($title !== '' ? $title : get_string('survey_question', 'local_tm_course')), [
            'class' => 'tm-survey-qcard-title survey-summary-title',
        ]);
        echo html_writer::tag('div',
            html_writer::tag('span', s($typelabel), ['class' => 'tm-survey-chip survey-summary-type'])
            . ' '
            . html_writer::tag('span', s($reqlabel), [
                'class' => 'tm-survey-chip ' . ($required ? 'tm-survey-chip-req' : 'tm-survey-chip-opt')
                    . ' survey-summary-req',
            ]),
            ['class' => 'tm-survey-qcard-chips']
        );
        echo html_writer::end_div();
        if ($iseditable) {
            echo html_writer::start_div('tm-survey-qcard-actions');
            echo html_writer::tag('button', get_string('survey_edit_question', 'local_tm_course'), [
                'type' => 'button', 'class' => 'btn btn-sm btn-outline-primary survey-q-edit',
            ]);
            echo html_writer::tag('button', get_string('survey_delete_question', 'local_tm_course'), [
                'type' => 'button', 'class' => 'btn btn-sm btn-outline-danger survey-q-delete',
            ]);
            echo html_writer::end_div();
        }
        echo html_writer::end_div();

        // Detail (hidden until Edit).
        echo html_writer::start_div('tm-survey-qcard-detail', ['style' => 'display:none']);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => $prefix . '[stablekey]',
            'value' => (string) ($item['stablekey'] ?? ''),
        ]);
        echo html_writer::tag('label', get_string('survey_question', 'local_tm_course'));
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'name' => $prefix . '[title]',
            'class' => 'form-control mb-2 survey-title-input',
            'value' => $title,
        ] + $disabled);
        echo html_writer::tag('label', get_string('survey_help', 'local_tm_course'));
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'name' => $prefix . '[help]', 'class' => 'form-control mb-2',
            'value' => (string) ($item['help'] ?? ''),
        ] + $disabled);
        echo html_writer::tag('label', get_string('survey_type', 'local_tm_course'));
        $select = html_writer::start_tag('select', [
            'name' => $prefix . '[qtype]', 'class' => 'form-control mb-2 survey-qtype',
        ] + $disabled);
        foreach ($types as $type => $typelabelopt) {
            $sel = ($qtype === $type) ? ['selected' => 'selected'] : [];
            $select .= html_writer::tag('option', $typelabelopt, ['value' => $type] + $sel);
        }
        $select .= html_writer::end_tag('select');
        echo $select;
        $req = $required ? ['checked' => 'checked'] : [];
        echo html_writer::tag('label',
            html_writer::empty_tag('input', [
                'type' => 'checkbox', 'name' => $prefix . '[required]', 'value' => '1',
                'class' => 'survey-required-input',
            ] + $req + $disabled)
            . ' ' . get_string('survey_required', 'local_tm_course'),
            ['class' => 'd-block mb-2 survey-field-required']
        );
        echo html_writer::start_div('survey-field-scale');
        echo html_writer::tag('label', get_string('survey_scale_min', 'local_tm_course'));
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'name' => $prefix . '[scalemin]', 'class' => 'form-control mb-2',
            'value' => (string) ($item['scalemin'] ?? ''),
        ] + $disabled);
        echo html_writer::tag('label', get_string('survey_scale_max', 'local_tm_course'));
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'name' => $prefix . '[scalemax]', 'class' => 'form-control mb-2',
            'value' => (string) ($item['scalemax'] ?? ''),
        ] + $disabled);
        echo html_writer::end_div();
        $other = !empty($item['allowother']) ? ['checked' => 'checked'] : [];
        echo html_writer::start_div('survey-field-other');
        echo html_writer::tag('label',
            html_writer::empty_tag('input', [
                'type' => 'checkbox', 'name' => $prefix . '[allowother]', 'value' => '1',
            ] + $other + $disabled)
            . ' ' . get_string('survey_allow_other', 'local_tm_course')
        );
        echo html_writer::end_div();
        list($optionstext, $optionskeys) = survey_admin_options_textarea_payload($item['options'] ?? []);
        echo html_writer::start_div('survey-field-options');
        echo html_writer::tag('label', get_string('survey_options', 'local_tm_course'));
        echo html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => $prefix . '[options_keys]', 'value' => $optionskeys,
            'class' => 'survey-options-keys',
        ]);
        echo html_writer::tag('textarea', s($optionstext), [
            'name' => $prefix . '[options_text]', 'class' => 'form-control mb-2 survey-options-text',
            'rows' => 4, 'placeholder' => get_string('survey_options', 'local_tm_course'),
        ] + $disabled);
        echo html_writer::end_div();
        if ($iseditable) {
            echo html_writer::tag('button', get_string('survey_collapse_question', 'local_tm_course'), [
                'type' => 'button', 'class' => 'btn btn-sm btn-secondary survey-q-done',
            ]);
        }
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div(); // qlist

    if ($iseditable) {
        echo html_writer::tag('button', get_string('survey_add_question', 'local_tm_course'), [
            'type' => 'button',
            'class' => 'btn btn-outline-secondary btn-sm mt-2 survey-add-question-in-section',
        ]);
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div(); // survey-sections

if ($iseditable) {
    echo html_writer::start_div('tm-survey-content-actions mt-3');
    echo html_writer::tag('button', get_string('survey_add_section', 'local_tm_course'), [
        'type' => 'button', 'class' => 'btn btn-outline-secondary mr-2', 'id' => 'survey-add-section',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('survey_save', 'local_tm_course'),
    ]);
    echo html_writer::end_div();
}
echo html_writer::end_div(); // content
echo html_writer::end_tag('form');

$jsstrings = json_encode([
    'question' => get_string('survey_question', 'local_tm_course'),
    'questionN' => get_string('survey_question_n', 'local_tm_course', '__N__'),
    'requiredYes' => get_string('survey_required_yes', 'local_tm_course'),
    'requiredNo' => get_string('survey_required_no', 'local_tm_course'),
    'types' => $types,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$PAGE->requires->js_init_code(<<<JS
(function() {
    var STR = {$jsstrings};

    function applyQtypeVisibility(item) {
        if (!item) { return; }
        var detail = item.querySelector('.tm-survey-qcard-detail') || item;
        var sel = detail.querySelector('select.survey-qtype');
        var qtype = sel ? sel.value : '';
        var showScale = (qtype === 'scale');
        var showChoice = (qtype === 'single' || qtype === 'multi');
        detail.querySelectorAll('.survey-field-scale').forEach(function(el) {
            el.style.display = showScale ? '' : 'none';
        });
        detail.querySelectorAll('.survey-field-options, .survey-field-other').forEach(function(el) {
            el.style.display = showChoice ? '' : 'none';
        });
    }

    function syncSummary(item) {
        if (!item) { return; }
        var titleInput = item.querySelector('.survey-title-input');
        var typeSel = item.querySelector('select.survey-qtype');
        var reqInput = item.querySelector('.survey-required-input');
        var titleEl = item.querySelector('.survey-summary-title');
        var typeEl = item.querySelector('.survey-summary-type');
        var reqEl = item.querySelector('.survey-summary-req');
        if (titleEl && titleInput) {
            var t = (titleInput.value || '').trim();
            titleEl.textContent = t !== '' ? t : STR.question;
        }
        if (typeEl && typeSel) {
            var opt = typeSel.options[typeSel.selectedIndex];
            typeEl.textContent = opt ? opt.text : typeSel.value;
        }
        if (reqEl && reqInput) {
            reqEl.textContent = reqInput.checked ? STR.requiredYes : STR.requiredNo;
            reqEl.classList.toggle('tm-survey-chip-req', !!reqInput.checked);
            reqEl.classList.toggle('tm-survey-chip-opt', !reqInput.checked);
        }
    }

    function renumberQuestions() {
        var n = 0;
        document.querySelectorAll('#survey-sections .survey-item').forEach(function(item) {
            n++;
            item.setAttribute('data-qnum', String(n));
            var label = item.querySelector('.survey-qnum-label');
            if (label) {
                label.textContent = STR.questionN.replace('__N__', String(n));
            }
        });
    }

    function setEditing(item, on) {
        if (!item) { return; }
        var summary = item.querySelector('.tm-survey-qcard-summary');
        var detail = item.querySelector('.tm-survey-qcard-detail');
        if (!summary || !detail) { return; }
        if (on) {
            item.classList.add('is-editing');
            summary.style.display = 'none';
            detail.style.display = '';
            applyQtypeVisibility(item);
        } else {
            item.classList.remove('is-editing');
            syncSummary(item);
            summary.style.display = '';
            detail.style.display = 'none';
        }
    }

    function clearItemFields(item) {
        item.querySelectorAll('input, select, textarea').forEach(function(el) {
            if (el.type === 'checkbox' || el.type === 'radio') { el.checked = false; }
            else if (el.tagName !== 'SELECT') { el.value = ''; }
        });
        var sel = item.querySelector('select.survey-qtype');
        if (sel) { sel.value = 'scale'; }
        var req = item.querySelector('.survey-required-input');
        if (req) { req.checked = true; }
        syncSummary(item);
        applyQtypeVisibility(item);
    }

    function reindexSection(section, sidx) {
        section.querySelectorAll('.survey-item').forEach(function(item, iidx) {
            item.querySelectorAll('input, select, textarea').forEach(function(el) {
                if (!el.name) { return; }
                el.name = el.name
                    .replace(/section\[\d+\]/, 'section[' + sidx + ']')
                    .replace(/item\]\[\d+\]/, 'item][' + iidx + ']');
            });
        });
        var nameInput = section.querySelector('.survey-section-name');
        if (nameInput) {
            nameInput.name = 'section[' + sidx + '][name]';
        }
        var numEl = section.querySelector('.tm-survey-section-num');
        if (numEl) {
            numEl.textContent = (sidx + 1 < 10 ? '0' : '') + (sidx + 1);
        }
    }

    function reindexAll() {
        document.querySelectorAll('#survey-sections .survey-section').forEach(function(section, sidx) {
            reindexSection(section, sidx);
        });
        renumberQuestions();
    }

    var root = document.getElementById('survey-sections');
    if (root) {
        root.addEventListener('change', function(e) {
            var t = e.target;
            if (!t) { return; }
            var item = t.closest('.survey-item');
            if (t.classList && t.classList.contains('survey-qtype')) {
                applyQtypeVisibility(item);
                syncSummary(item);
            }
            if (t.classList && t.classList.contains('survey-required-input')) {
                syncSummary(item);
            }
        });
        root.addEventListener('input', function(e) {
            var t = e.target;
            if (t && t.classList && t.classList.contains('survey-title-input')) {
                syncSummary(t.closest('.survey-item'));
            }
        });
        root.addEventListener('click', function(e) {
            var t = e.target;
            if (!t || !t.classList) { return; }
            var item = t.closest('.survey-item');
            if (t.classList.contains('survey-q-edit')) {
                e.preventDefault();
                setEditing(item, true);
            } else if (t.classList.contains('survey-q-done')) {
                e.preventDefault();
                setEditing(item, false);
            } else if (t.classList.contains('survey-q-delete')) {
                e.preventDefault();
                var section = t.closest('.survey-section');
                var items = section ? section.querySelectorAll('.survey-item') : [];
                if (items.length <= 1) {
                    clearItemFields(item);
                    setEditing(item, false);
                } else {
                    item.remove();
                    reindexAll();
                }
            } else if (t.classList.contains('survey-add-question-in-section')) {
                e.preventDefault();
                var section = t.closest('.survey-section');
                if (!section) { return; }
                var items = section.querySelectorAll('.survey-item');
                var clone = items[items.length - 1].cloneNode(true);
                clearItemFields(clone);
                items[items.length - 1].after(clone);
                reindexAll();
                setEditing(clone, true);
            }
        });
        document.querySelectorAll('#survey-sections .survey-item').forEach(function(item) {
            applyQtypeVisibility(item);
            syncSummary(item);
            setEditing(item, false);
        });
    }

    var addS = document.getElementById('survey-add-section');
    if (addS) {
        addS.addEventListener('click', function() {
            var sections = document.querySelectorAll('#survey-sections .survey-section');
            var clone = sections[sections.length - 1].cloneNode(true);
            var nameInput = clone.querySelector('.survey-section-name');
            if (nameInput) { nameInput.value = ''; }
            var extras = clone.querySelectorAll('.survey-item');
            for (var i = extras.length - 1; i > 0; i--) { extras[i].remove(); }
            clearItemFields(extras[0]);
            sections[sections.length - 1].after(clone);
            reindexAll();
            setEditing(clone.querySelector('.survey-item'), true);
        });
    }
})();
JS
);

echo $OUTPUT->footer();
