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

$error = '';
$posted = null;
if ($surveyid > 0 && optional_param('action', '', PARAM_ALPHANUMEXT) === 'save' && confirm_sesskey()) {
    try {
        survey_manager::update_name($surveyid, required_param('name', PARAM_TEXT));
        survey_manager::set_enabled($surveyid, (bool) optional_param('enabled', 0, PARAM_BOOL));
        survey_manager::set_course_assignments($surveyid, optional_param_array('courseids', [], PARAM_INT));
        $savedversion = survey_manager::save_structure($surveyid, survey_admin_read_post(), (int) $USER->id);
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
        ];
        foreach ($surveys as $survey) {
            $url = new moodle_url('/local/tm_course/admin/surveys.php', ['id' => $survey->id]);
            $table->data[] = [
                html_writer::link($url, s($survey->name)),
                (int) $survey->enabled ? get_string('survey_enabled', 'local_tm_course') : get_string('survey_disabled', 'local_tm_course'),
                (int) $survey->versionno . ($survey->versionfrozen ? ' (' . get_string('survey_version_frozen', 'local_tm_course') . ')' : ''),
                (int) $survey->coursecount,
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

echo html_writer::start_tag('form', ['method' => 'post', 'id' => 'survey-editor']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $surveyid]);

$disabled = $iseditable ? [] : ['disabled' => 'disabled'];
echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('survey_name', 'local_tm_course'));
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'name', 'class' => 'form-control', 'maxlength' => 255,
    'value' => $posted !== null ? optional_param('name', '', PARAM_TEXT) : $survey->name,
] + $disabled);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
$enabledchecked = $posted !== null ? optional_param('enabled', 0, PARAM_BOOL) : (int) $survey->enabled;
echo html_writer::tag('label',
    html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'enabled', 'value' => '1'] + ($enabledchecked ? ['checked' => 'checked'] : []) + $disabled)
    . ' ' . get_string('survey_enabled', 'local_tm_course')
);
echo html_writer::end_div();

echo html_writer::tag('h3', get_string('survey_courses', 'local_tm_course'));
echo html_writer::tag('p', get_string('survey_courses_help', 'local_tm_course'), ['class' => 'text-muted']);
if (!$coursemenu) {
    echo html_writer::tag('p', get_string('survey_no_courses', 'local_tm_course'));
}
$postedcourses = $posted !== null ? optional_param_array('courseids', [], PARAM_INT) : $assigned;
foreach ($coursemenu as $courseid => $coursename) {
    $checked = in_array((int) $courseid, array_map('intval', $postedcourses), true) ? ['checked' => 'checked'] : [];
    echo html_writer::tag('label',
        html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'courseids[]', 'value' => (int) $courseid] + $checked + $disabled)
        . ' ' . s($coursename),
        ['class' => 'd-block']
    );
}

$types = [
    survey_manager::TYPE_SINGLE => get_string('survey_type_single', 'local_tm_course'),
    survey_manager::TYPE_MULTI => get_string('survey_type_multi', 'local_tm_course'),
    survey_manager::TYPE_SCALE => get_string('survey_type_scale', 'local_tm_course'),
    survey_manager::TYPE_TEXT => get_string('survey_type_text', 'local_tm_course'),
];

echo html_writer::start_div('', ['id' => 'survey-sections']);
foreach (array_values($structure) as $sidx => $section) {
    echo html_writer::start_div('tm-card mt-3 survey-section');
    echo html_writer::start_div('tm-card-body');
    echo html_writer::tag('label', get_string('survey_section', 'local_tm_course'));
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => "section[$sidx][name]", 'class' => 'form-control mb-3',
        'value' => (string) ($section['name'] ?? ''),
    ] + $disabled);
    $items = $section['items'] ?? [];
    if (!$items) {
        $items = survey_admin_blank_section()[0]['items'];
    }
    foreach (array_values($items) as $iidx => $item) {
        $prefix = "section[$sidx][item][$iidx]";
        echo html_writer::start_div('border rounded p-3 mb-3 survey-item');
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $prefix . '[stablekey]', 'value' => (string) ($item['stablekey'] ?? '')]);
        echo html_writer::tag('label', get_string('survey_question', 'local_tm_course'));
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'name' => $prefix . '[title]', 'class' => 'form-control mb-2',
            'value' => (string) ($item['title'] ?? ''),
        ] + $disabled);
        echo html_writer::tag('label', get_string('survey_help', 'local_tm_course'));
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'name' => $prefix . '[help]', 'class' => 'form-control mb-2',
            'value' => (string) ($item['help'] ?? ''),
        ] + $disabled);
        echo html_writer::tag('label', get_string('survey_type', 'local_tm_course'));
        $select = html_writer::start_tag('select', ['name' => $prefix . '[qtype]', 'class' => 'form-control mb-2 survey-qtype'] + $disabled);
        foreach ($types as $type => $typelabel) {
            $sel = ((string) ($item['qtype'] ?? '') === $type) ? ['selected' => 'selected'] : [];
            $select .= html_writer::tag('option', $typelabel, ['value' => $type] + $sel);
        }
        $select .= html_writer::end_tag('select');
        echo $select;
        $req = !empty($item['required']) ? ['checked' => 'checked'] : [];
        echo html_writer::tag('label',
            html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => $prefix . '[required]', 'value' => '1'] + $req + $disabled)
            . ' ' . get_string('survey_required', 'local_tm_course'),
            ['class' => 'd-block mb-2']
        );
        echo html_writer::start_div('survey-scale');
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
        echo html_writer::start_div('survey-other');
        echo html_writer::tag('label',
            html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => $prefix . '[allowother]', 'value' => '1'] + $other + $disabled)
            . ' ' . get_string('survey_allow_other', 'local_tm_course')
        );
        echo html_writer::end_div();
        echo html_writer::start_div('survey-options');
        echo html_writer::tag('label', get_string('survey_options', 'local_tm_course'));
        $options = $item['options'] ?? [];
        if (!$options) {
            $options = [['stablekey' => '', 'label' => '', 'isother' => 0]];
        }
        foreach (array_values($options) as $oidx => $option) {
            $op = $prefix . "[option][$oidx]";
            echo html_writer::start_div('d-flex mb-1');
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $op . '[stablekey]', 'value' => (string) ($option['stablekey'] ?? '')]);
            echo html_writer::empty_tag('input', [
                'type' => 'text', 'name' => $op . '[label]', 'class' => 'form-control',
                'value' => (string) ($option['label'] ?? ''),
            ] + $disabled);
            echo html_writer::end_div();
        }
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

if ($iseditable) {
    echo html_writer::tag('button', get_string('survey_add_section', 'local_tm_course'), [
        'type' => 'button', 'class' => 'btn btn-outline-secondary mr-2', 'id' => 'survey-add-section',
    ]);
    echo html_writer::tag('button', get_string('survey_add_question', 'local_tm_course'), [
        'type' => 'button', 'class' => 'btn btn-outline-secondary mr-2', 'id' => 'survey-add-question',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('survey_save', 'local_tm_course'),
    ]);
}
echo html_writer::end_tag('form');

if ($iseditable) {
    $PAGE->requires->js_init_code(<<<'JS'
document.getElementById('survey-add-question').addEventListener('click', function() {
    var sections = document.querySelectorAll('#survey-sections .survey-section');
    var section = sections[sections.length - 1];
    if (!section) { return; }
    var items = section.querySelectorAll('.survey-item');
    var clone = items[items.length - 1].cloneNode(true);
    var sidx = sections.length - 1;
    var iidx = items.length;
    clone.querySelectorAll('input, select, textarea').forEach(function(el) {
        if (el.name) {
            el.name = el.name.replace(/item\]\[\d+\]/, 'item][' + iidx + ']');
        }
        if (el.type === 'checkbox' || el.type === 'radio') { el.checked = false; }
        else if (el.tagName !== 'SELECT') { el.value = ''; }
    });
    items[items.length - 1].after(clone);
});
document.getElementById('survey-add-section').addEventListener('click', function() {
    var sections = document.querySelectorAll('#survey-sections .survey-section');
    var clone = sections[sections.length - 1].cloneNode(true);
    var sidx = sections.length;
    clone.querySelectorAll('input, select, textarea').forEach(function(el) {
        if (!el.name) { return; }
        el.name = el.name.replace(/section\[\d+\]/, 'section[' + sidx + ']');
        el.name = el.name.replace(/item\]\[\d+\]/, 'item][0]');
        if (el.type === 'checkbox' || el.type === 'radio') { el.checked = false; }
        else if (el.tagName !== 'SELECT') { el.value = ''; }
    });
    var extras = clone.querySelectorAll('.survey-item');
    for (var i = extras.length - 1; i > 0; i--) { extras[i].remove(); }
    sections[sections.length - 1].after(clone);
});
JS
    );
}

echo $OUTPUT->footer();
