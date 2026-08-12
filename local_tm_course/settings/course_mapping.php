<?php
/**
 * Select Moodle courses available in TM session "linked course" dropdown.
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/enabled_course_manager.php');
require_once(__DIR__ . '/../classes/prerequisite_manager.php');
require_once(__DIR__ . '/../classes/tcms_sync_manager.php');

use local_tm_course\enabled_course_manager;
use local_tm_course\prerequisite_manager;
use local_tm_course\tcms_sync_manager;

require_login();
require_capability('local/tm_course:manage', context_system::instance());

global $DB, $OUTPUT, $PAGE;

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/tm_course/settings/course_mapping.php'));
$PAGE->set_title(get_string('course_mapping_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

$enabled = array_flip(enabled_course_manager::get_enabled_ids());
$duration_onsite_by_course = enabled_course_manager::get_duration_map_onsite();
$duration_online_by_course = enabled_course_manager::get_duration_map_online();
$allow_onsite_by_course = enabled_course_manager::get_allow_onsite_map();
$allow_online_by_course = enabled_course_manager::get_allow_online_map();
$allowed_classrooms_by_course = enabled_course_manager::get_classroom_map();
$online_classroom_by_course = enabled_course_manager::get_online_classroom_map();
$classrooms = $DB->get_records('local_tm_classroom', [], 'name ASC');
$prereq_course_menu = enabled_course_manager::get_course_menu();
$tcms_type_by_course = enabled_course_manager::get_tcms_course_type_map();
$tcms_course_type_options = tcms_sync_manager::get_schema_options()['courseTypes'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('save', 0, PARAM_INT)) {
    require_sesskey();
    $ids = [];
    if (!empty($_POST['courseids']) && is_array($_POST['courseids'])) {
        foreach ($_POST['courseids'] as $cid) {
            $ids[] = clean_param($cid, PARAM_INT);
        }
    }
    $duronsitepost = [];
    if (!empty($_POST['duration_onsite']) && is_array($_POST['duration_onsite'])) {
        foreach ($_POST['duration_onsite'] as $k => $v) {
            $duronsitepost[(int) $k] = unformat_float($v, true);
        }
    }
    $duronlinepost = [];
    if (!empty($_POST['duration_online']) && is_array($_POST['duration_online'])) {
        foreach ($_POST['duration_online'] as $k => $v) {
            $duronlinepost[(int) $k] = unformat_float($v, true);
        }
    }
    $allowonsitepost = [];
    if (!empty($_POST['allow_onsite']) && is_array($_POST['allow_onsite'])) {
        foreach ($_POST['allow_onsite'] as $k => $v) {
            $allowonsitepost[(int)$k] = ((int)$v === 1);
        }
    }
    $allowonlinepost = [];
    if (!empty($_POST['allow_online']) && is_array($_POST['allow_online'])) {
        foreach ($_POST['allow_online'] as $k => $v) {
            $allowonlinepost[(int)$k] = ((int)$v === 1);
        }
    }
    $classpost = [];
    if (!empty($_POST['classroomids']) && is_array($_POST['classroomids'])) {
        foreach ($_POST['classroomids'] as $k => $vals) {
            $cid = (int)$k;
            $classpost[$cid] = [];
            if (!is_array($vals)) {
                continue;
            }
            foreach ($vals as $rid) {
                $rid = clean_param($rid, PARAM_INT);
                if ($rid > 0) {
                    $classpost[$cid][] = (int)$rid;
                }
            }
        }
    }
    $onlineclasspost = [];
    if (!empty($_POST['online_classroomid']) && is_array($_POST['online_classroomid'])) {
        foreach ($_POST['online_classroomid'] as $k => $v) {
            $onlineclasspost[(int)$k] = clean_param($v, PARAM_INT);
        }
    }
    $tcmstypepost = [];
    if (!empty($_POST['tcms_course_type']) && is_array($_POST['tcms_course_type'])) {
        foreach ($_POST['tcms_course_type'] as $k => $v) {
            $tcmstypepost[(int)$k] = trim(clean_param($v, PARAM_TEXT));
        }
    }
    $entries = [];
    foreach ($ids as $cid) {
        if ($cid <= 0) {
            continue;
        }
        $allowonline = !empty($allowonlinepost[$cid]);
        $onlineclassroomid = (int)($onlineclasspost[$cid] ?? 0);
        if ($allowonline && $onlineclassroomid <= 0) {
            redirect($PAGE->url, get_string('course_online_classroom_required', 'local_tm_course'),
                null, \core\output\notification::NOTIFY_ERROR);
        }
        if ($onlineclassroomid > 0 && empty($classrooms[$onlineclassroomid])) {
            redirect($PAGE->url, get_string('course_online_classroom_invalid', 'local_tm_course'),
                null, \core\output\notification::NOTIFY_ERROR);
        }
        $honsite = isset($duronsitepost[$cid]) && $duronsitepost[$cid] !== null ? (float)$duronsitepost[$cid] : 8.0;
        $honline = isset($duronlinepost[$cid]) && $duronlinepost[$cid] !== null ? (float)$duronlinepost[$cid] : 8.0;
        $hlegacy = $honsite;
        $entries[] = [
            'courseid' => $cid,
            'default_duration_hours' => $hlegacy,
            'default_duration_hours_onsite' => $honsite,
            'default_duration_hours_online' => $honline,
            'allow_onsite' => !empty($allowonsitepost[$cid]) ? 1 : 0,
            'allow_online' => $allowonline ? 1 : 0,
            'allowed_classroomids' => $classpost[$cid] ?? [],
            'online_classroomid' => $onlineclassroomid > 0 ? $onlineclassroomid : null,
            'tcms_course_type' => $tcmstypepost[$cid] ?? '',
        ];
    }
    enabled_course_manager::save_enabled($entries);
    redirect($PAGE->url, get_string('course_mapping_saved', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$sql = "SELECT id, fullname, shortname,
               visible AS visibility,
               category
          FROM {course}
         WHERE id <> :siteid
         ORDER BY category ASC, fullname ASC";
$courses = $DB->get_records_sql($sql, ['siteid' => SITEID]);

// Group courses by course category for a cleaner UI.
$courses_by_cat = [];
$catids = [];
foreach ($courses as $c) {
    $catid = (int)($c->category ?? 0);
    if (!isset($courses_by_cat[$catid])) {
        $courses_by_cat[$catid] = [];
    }
    $courses_by_cat[$catid][] = $c;
    $catids[$catid] = true;
}

// Build a category tree:
// - only show top-level (parent=0) categories as outer list
// - render subcategories inside their parents (layer-by-layer)
$allcatids = $catids; // start from course categories only

// Pull in ancestor categories so the tree is complete.
$queue = array_keys($catids);
while (!empty($queue)) {
    $queue = array_map('intval', $queue);
    list($insql, $params) = $DB->get_in_or_equal($queue, SQL_PARAMS_NAMED);
    $catrecs = $DB->get_records_sql("SELECT id, parent FROM {course_categories} WHERE id $insql", $params);

    $next = [];
    foreach ($catrecs as $cat) {
        $parent = (int)$cat->parent;
        if ($parent > 0 && !isset($allcatids[$parent])) {
            $allcatids[$parent] = true;
            $next[] = $parent;
        }
    }
    $queue = $next;
}

$catinfo = []; // id => (name, parent, sortorder)
$children = []; // parentid => [childid...]
$rootcatids = [];

if (!empty($allcatids)) {
    $catidlist = array_keys($allcatids);
    list($insql, $params) = $DB->get_in_or_equal($catidlist, SQL_PARAMS_NAMED);
    $catrecs = $DB->get_records_sql("SELECT id, name, parent, sortorder
                                       FROM {course_categories}
                                      WHERE id $insql", $params);

    foreach ($catrecs as $cat) {
        $catid = (int)$cat->id;
        $catinfo[$catid] = $cat;
    }

    // Build child map + determine roots.
    foreach ($catinfo as $catid => $cat) {
        $parent = (int)($cat->parent ?? 0);
        if ($parent > 0) {
            $children[$parent][] = (int)$catid;
        } else {
            $rootcatids[] = (int)$catid;
        }
    }
}

$memo_desc_count = [];
$count_descendants = function(int $catid) use (&$count_descendants, &$memo_desc_count, &$children, &$courses_by_cat): int {
    if (isset($memo_desc_count[$catid])) {
        return $memo_desc_count[$catid];
    }
    $direct = isset($courses_by_cat[$catid]) ? count($courses_by_cat[$catid]) : 0;
    $total = $direct;
    if (!empty($children[$catid])) {
        foreach ($children[$catid] as $childid) {
            $total += $count_descendants($childid);
        }
    }
    $memo_desc_count[$catid] = $total;
    return $total;
};

// Sort roots by sortorder (fallback: id).
usort($rootcatids, function(int $a, int $b) use (&$catinfo): int {
    $sa = (int)($catinfo[$a]->sortorder ?? 0);
    $sb = (int)($catinfo[$b]->sortorder ?? 0);
    if ($sa === $sb) {
        return $a <=> $b;
    }
    return $sa <=> $sb;
});

$render_category = function(int $catid) use (&$render_category, &$children, &$courses_by_cat, &$catinfo, &$count_descendants, &$enabled, &$duration_onsite_by_course, &$duration_online_by_course, &$allow_onsite_by_course, &$allow_online_by_course, &$allowed_classrooms_by_course, &$online_classroom_by_course, &$classrooms, &$prereq_course_menu, &$tcms_type_by_course, &$tcms_course_type_options): void {
    $catname = $catinfo[$catid]->name ?? ('Category #' . (string)$catid);

    $directcourses = $courses_by_cat[$catid] ?? [];
    $childids = $children[$catid] ?? [];
    if (empty($directcourses) && empty($childids)) {
        return;
    }

    ?>
    <details class="tm-course-category">
        <summary style="cursor:pointer; padding:0.35rem 0;">
            <strong><?php echo s($catname); ?></strong>
        </summary>

        <div class="mt-2">
            <?php if (!empty($directcourses)): ?>
                <table class="table table-sm mb-2">
                    <thead>
                        <tr>
                            <th style="width:3rem;"></th>
                            <th><?php echo get_string('fullnamecourse', 'local_tm_course'); ?></th>
                            <th><?php echo get_string('shortnamecourse', 'local_tm_course'); ?></th>
                            <th><?php echo get_string('visibility', 'local_tm_course'); ?></th>
                            <th style="width:7rem;"><?php echo get_string('course_allow_onsite', 'local_tm_course'); ?></th>
                            <th style="width:7rem;"><?php echo get_string('course_allow_online', 'local_tm_course'); ?></th>
                            <th style="width:14rem;"><?php echo get_string('course_online_classroom', 'local_tm_course'); ?></th>
                            <th style="width:8rem;"><?php echo get_string('course_duration_onsite_hours', 'local_tm_course'); ?></th>
                            <th style="width:8rem;"><?php echo get_string('course_duration_online_hours', 'local_tm_course'); ?></th>
                            <th style="width:16rem;"><?php echo get_string('course_allowed_classrooms', 'local_tm_course'); ?></th>
                            <th style="width:12rem;"><?php echo s(get_string('course_tcms_course_type', 'local_tm_course')); ?></th>
                            <th style="width:9rem;"><?php echo s(get_string('verification_settings', 'local_tm_course')); ?></th>
                            <th style="width:9rem;"><?php echo s(get_string('course_mapping_prerequisite_settings', 'local_tm_course')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($directcourses as $c): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="courseids[]" value="<?php echo (int)$c->id; ?>"
                                    <?php echo isset($enabled[$c->id]) ? 'checked' : ''; ?>>
                            </td>
                            <td><?php echo s($c->fullname); ?></td>
                            <td><?php echo s($c->shortname); ?></td>
                            <td>
                                <?php echo ((int)($c->visibility ?? 0) === 1)
                                    ? get_string('yes')
                                    : get_string('no'); ?>
                            </td>
                            <td>
                                <input type="checkbox" name="allow_onsite[<?php echo (int)$c->id; ?>]" value="1"
                                    <?php echo !empty($allow_onsite_by_course[(int)$c->id]) ? 'checked' : ''; ?>>
                            </td>
                            <td>
                                <input type="checkbox" name="allow_online[<?php echo (int)$c->id; ?>]" value="1"
                                    <?php echo !empty($allow_online_by_course[(int)$c->id]) ? 'checked' : ''; ?>>
                            </td>
                            <td>
                                <?php $onlineclassselected = (int)($online_classroom_by_course[(int)$c->id] ?? 0); ?>
                                <select name="online_classroomid[<?php echo (int)$c->id; ?>]" class="form-control form-control-sm"
                                        aria-label="<?php echo get_string('course_online_classroom', 'local_tm_course'); ?>">
                                    <option value="0"><?php echo get_string('choosedots'); ?></option>
                                    <?php foreach ($classrooms as $room) {
                                        $rid = (int)$room->id;
                                        $sel = ($onlineclassselected === $rid) ? 'selected' : '';
                                        echo '<option value="' . $rid . '" ' . $sel . '>' . s(format_string((string)$room->name)) . '</option>';
                                    } ?>
                                </select>
                                <div class="small text-muted"><?php echo s(get_string('course_online_classroom_help', 'local_tm_course')); ?></div>
                            </td>
                            <td>
                                <input type="text" inputmode="decimal" class="form-control form-control-sm"
                                       name="duration_onsite[<?php echo (int) $c->id; ?>]"
                                       value="<?php echo s(format_float($duration_onsite_by_course[(int) $c->id] ?? 8.0, 2, true)); ?>"
                                       style="max-width:6rem" aria-label="<?php echo get_string('course_duration_onsite_hours', 'local_tm_course'); ?>">
                            </td>
                            <td>
                                <input type="text" inputmode="decimal" class="form-control form-control-sm"
                                       name="duration_online[<?php echo (int) $c->id; ?>]"
                                       value="<?php echo s(format_float($duration_online_by_course[(int) $c->id] ?? 8.0, 2, true)); ?>"
                                       style="max-width:6rem" aria-label="<?php echo get_string('course_duration_online_hours', 'local_tm_course'); ?>">
                            </td>
                            <td>
                                <select name="classroomids[<?php echo (int)$c->id; ?>][]" class="form-control form-control-sm" multiple size="4">
                                    <?php
                                    $selectedrooms = $allowed_classrooms_by_course[(int)$c->id] ?? [];
                                    $selectedmap = array_flip(array_map('intval', $selectedrooms));
                                    foreach ($classrooms as $room) {
                                        $rid = (int)$room->id;
                                        $sel = isset($selectedmap[$rid]) ? 'selected' : '';
                                        echo '<option value="' . $rid . '" ' . $sel . '>' . s(format_string((string)$room->name)) . '</option>';
                                    }
                                    ?>
                                </select>
                                <div class="small text-muted"><?php echo s(get_string('course_allowed_classrooms_help', 'local_tm_course')); ?></div>
                            </td>
                            <td>
                                <?php
                                $currenttype = trim((string)($tcms_type_by_course[(int)$c->id] ?? ''));
                                $typeoptions = $tcms_course_type_options;
                                if ($currenttype !== '' && !in_array($currenttype, $typeoptions, true)) {
                                    array_unshift($typeoptions, $currenttype);
                                }
                                ?>
                                <select name="tcms_course_type[<?php echo (int)$c->id; ?>]" class="form-control form-control-sm">
                                    <option value=""><?php echo s(get_string('tcms_map_use_default', 'local_tm_course')); ?></option>
                                    <?php foreach ($typeoptions as $opt): ?>
                                        <option value="<?php echo s($opt); ?>" <?php echo ($currenttype === $opt) ? 'selected' : ''; ?>>
                                            <?php echo s($opt); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="small text-muted"><?php echo s(get_string('course_tcms_course_type_help', 'local_tm_course')); ?></div>
                            </td>
                            <td>
                                <button type="button"
                                        class="btn btn-sm btn-secondary js-open-verification-modal"
                                        data-courseid="<?php echo (int)$c->id; ?>"
                                        data-coursename="<?php echo s((string)$c->fullname); ?>">
                                    <?php echo s(get_string('verification_settings', 'local_tm_course')); ?>
                                </button>
                            </td>
                            <td>
                                <button type="button"
                                        class="btn btn-sm btn-secondary js-open-prerequisite-modal"
                                        data-courseid="<?php echo (int)$c->id; ?>"
                                        data-coursename="<?php echo s((string)$c->fullname); ?>">
                                    <?php echo s(get_string('course_mapping_prerequisite_settings', 'local_tm_course')); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php
            if (!empty($childids)) {
                // Keep UI stable: sort child categories by sortorder.
                usort($childids, function(int $a, int $b) use (&$catinfo): int {
                    $sa = (int)($catinfo[$a]->sortorder ?? 0);
                    $sb = (int)($catinfo[$b]->sortorder ?? 0);
                    if ($sa === $sb) return $a <=> $b;
                    return $sa <=> $sb;
                });

                foreach ($childids as $childid) {
                    $render_category($childid);
                }
            }
            ?>
        </div>
    </details>
    <?php
};

echo $OUTPUT->header();
$sm = get_string_manager();
$durationhelp = $sm->string_exists('course_mapping_duration_help_split', 'local_tm_course')
    ? get_string('course_mapping_duration_help_split', 'local_tm_course')
    : get_string('course_mapping_duration_help', 'local_tm_course');
?>

<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo get_string('course_mapping_title', 'local_tm_course'); ?></h2>
</div>

<p><?php echo get_string('course_mapping_intro', 'local_tm_course'); ?></p>
<p class="text-muted small"><?php echo s($durationhelp); ?></p>
<p class="text-muted small"><?php echo s(get_string('course_mapping_online_classroom_notice', 'local_tm_course')); ?></p>

<form method="post" action="">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="save" value="1">

    <div class="tm-card">
        <div class="tm-card-body" style="max-height:70vh; overflow:auto;">
            <?php if (empty($courses_by_cat)): ?>
                <div class="tm-alert tm-alert-info"><?php echo get_string('course_mapping_empty_hint', 'local_tm_course'); ?></div>
            <?php else: ?>
                <?php
                // Only render top-level categories as the outer list.
                foreach ($rootcatids as $rootcatid) {
                    if ($count_descendants($rootcatid) > 0) {
                        $render_category($rootcatid);
                    }
                }
                ?>
            <?php endif; ?>
        </div>
    </div>

    <p class="mt-3">
        <button type="submit" class="btn btn-tm-success"><?php echo get_string('save_changes', 'local_tm_course'); ?></button>
        <a class="btn btn-secondary" href="<?php echo (new moodle_url('/local/tm_course/admin/sessions.php'))->out(); ?>">
            <?php echo get_string('nav_sessions', 'local_tm_course'); ?></a>
    </p>
</form>

<div id="tm-verification-modal-backdrop" class="tm-cancel-modal-backdrop" style="display:none;">
    <div class="tm-cancel-modal-panel" style="max-width:52rem;width:94%;">
        <div style="background:#005f7e;color:#fff;padding:.6rem .9rem;margin:-1rem -1rem .9rem;border-radius:.4rem .4rem 0 0;">
            <strong id="tm-verification-modal-title"></strong>
        </div>
        <div id="tm-verification-list"></div>
        <div class="mt-2">
            <button type="button" id="tm-verification-add" class="btn btn-sm btn-secondary">+ 新增題目</button>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button type="button" id="tm-verification-save" class="btn btn-tm-success"><?php echo get_string('save_changes', 'local_tm_course'); ?></button>
            <button type="button" id="tm-verification-close" class="btn btn-secondary"><?php echo get_string('cancel', 'local_tm_course'); ?></button>
        </div>
    </div>
</div>

<style>
/* Course mapping prerequisite modal: fit viewport; scroll body; pin footer */
#tm-prerequisite-modal-backdrop {
    padding: 1rem;
    overflow-y: auto;
    align-items: flex-start;
    box-sizing: border-box;
}
#tm-prerequisite-modal-backdrop .tm-prereq-modal-panel {
    width: min(94vw, 52rem);
    max-width: 52rem;
    max-height: calc(100vh - 2rem);
    max-height: calc(100dvh - 2rem);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    padding: 0;
    margin: auto 0;
}
#tm-prerequisite-modal-backdrop .tm-prereq-modal-header {
    flex-shrink: 0;
    background: #005f7e;
    color: #fff;
    padding: .6rem .9rem;
    border-radius: 10px 10px 0 0;
}
#tm-prerequisite-modal-backdrop .tm-prereq-modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    padding: .85rem 1.1rem;
    -webkit-overflow-scrolling: touch;
}
#tm-prerequisite-modal-backdrop .tm-prereq-modal-footer {
    flex-shrink: 0;
    padding: .75rem 1.1rem 1rem;
    border-top: 1px solid var(--tm-border, #d5dde8);
    background: #fff;
    border-radius: 0 0 10px 10px;
}
#tm-prerequisite-modal-backdrop .tm-prereq-map-row {
    padding: .65rem .75rem;
    margin-bottom: .55rem;
}
#tm-prerequisite-modal-backdrop .tm-prereq-grade-cond {
    margin-bottom: .35rem;
}
body.tm-prereq-map-modal-open {
    overflow: hidden;
}
</style>
<div id="tm-prerequisite-modal-backdrop" class="tm-cancel-modal-backdrop" style="display:none;">
    <div class="tm-cancel-modal-panel tm-prereq-modal-panel" role="dialog" aria-modal="true" aria-labelledby="tm-prerequisite-modal-title">
        <div class="tm-prereq-modal-header">
            <strong id="tm-prerequisite-modal-title"></strong>
        </div>
        <div class="tm-prereq-modal-body">
            <p class="text-muted small mb-2"><?php echo s(get_string('course_mapping_prerequisite_intro', 'local_tm_course')); ?></p>
            <div class="form-inline mb-2">
                <label class="mr-2"><?php echo s(get_string('session_prerequisite_operator', 'local_tm_course')); ?></label>
                <select id="tm-prereq-map-operator" class="form-control form-control-sm">
                    <option value="and"><?php echo s(get_string('session_prerequisite_operator_and', 'local_tm_course')); ?></option>
                    <option value="or"><?php echo s(get_string('session_prerequisite_operator_or', 'local_tm_course')); ?></option>
                </select>
            </div>
            <div id="tm-prereq-map-rules"></div>
            <div class="mt-2">
                <button type="button" id="tm-prereq-map-add" class="btn btn-sm btn-secondary"><?php echo s(get_string('session_prerequisite_add_rule', 'local_tm_course')); ?></button>
                <button type="button" id="tm-prereq-map-clear" class="btn btn-sm btn-link"><?php echo s(get_string('course_mapping_prerequisite_clear', 'local_tm_course')); ?></button>
            </div>
        </div>
        <div class="tm-prereq-modal-footer d-flex gap-2">
            <button type="button" id="tm-prereq-map-save" class="btn btn-tm-success"><?php echo get_string('save_changes', 'local_tm_course'); ?></button>
            <button type="button" id="tm-prereq-map-close" class="btn btn-secondary"><?php echo get_string('cancel', 'local_tm_course'); ?></button>
        </div>
    </div>
</div>

<?php echo html_writer::script("
(function() {
    var apiUrl = " . json_encode((new moodle_url('/local/tm_course/settings/check_questions_api.php'))->out(false)) . ";
    var sesskey = " . json_encode(sesskey()) . ";
    var modal = document.getElementById('tm-verification-modal-backdrop');
    var title = document.getElementById('tm-verification-modal-title');
    var list = document.getElementById('tm-verification-list');
    var addBtn = document.getElementById('tm-verification-add');
    var saveBtn = document.getElementById('tm-verification-save');
    var closeBtn = document.getElementById('tm-verification-close');
    var currentCourseId = 0;

    function rowHtml(item) {
        var t = String(item.question_text || '').replace(/\"/g, '&quot;');
        var mode = String(item.apply_mode || 'both');
        var req = Number(item.is_required || 0) === 1 ? 'checked' : '';
        return '<div class=\"tm-vq-row border rounded p-2 mb-2\">'
            + '<input type=\"text\" class=\"form-control form-control-sm js-vq-text\" placeholder=\"題目內容\" value=\"' + t + '\">'
            + '<div class=\"mt-2 d-flex align-items-center gap-2\">'
            + '<select class=\"form-control form-control-sm js-vq-mode\" style=\"max-width:12rem\">'
            + '<option value=\"onsite\" ' + (mode === 'onsite' ? 'selected' : '') + '>實體</option>'
            + '<option value=\"online\" ' + (mode === 'online' ? 'selected' : '') + '>視訊</option>'
            + '<option value=\"both\" ' + (mode === 'both' ? 'selected' : '') + '>兩者皆可</option>'
            + '</select>'
            + '<label class=\"mb-0\"><input type=\"checkbox\" class=\"js-vq-required\" ' + req + '> 是否必填</label>'
            + '<button type=\"button\" class=\"btn btn-sm btn-outline-secondary js-vq-del\">x</button>'
            + '</div></div>';
    }
    function bindDeleteHandlers() {
        var btns = list.querySelectorAll('.js-vq-del');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function(e) {
                var row = e.target.closest('.tm-vq-row');
                if (row) { row.remove(); }
            });
        }
    }
    function loadQuestions(courseId, courseName) {
        currentCourseId = Number(courseId || 0);
        title.textContent = " . json_encode(get_string('verification_settings', 'local_tm_course')) . " + ' - ' + String(courseName || '');
        fetch(apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({action: 'list', courseid: currentCourseId, sesskey: sesskey})
        }).then(function(r){ return r.json(); }).then(function(data) {
            list.innerHTML = '';
            var items = (data && data.items) ? data.items : [];
            if (!items.length) {
                list.innerHTML = rowHtml({question_text:'', apply_mode:'both', is_required:0});
            } else {
                for (var i = 0; i < items.length; i++) {
                    list.insertAdjacentHTML('beforeend', rowHtml(items[i]));
                }
            }
            bindDeleteHandlers();
            modal.style.display = 'flex';
        });
    }
    function collectRows() {
        var rows = list.querySelectorAll('.tm-vq-row');
        var out = [];
        for (var i = 0; i < rows.length; i++) {
            out.push({
                question_text: (rows[i].querySelector('.js-vq-text') || {}).value || '',
                apply_mode: (rows[i].querySelector('.js-vq-mode') || {}).value || 'both',
                is_required: (rows[i].querySelector('.js-vq-required') || {}).checked ? 1 : 0,
                sortorder: (i + 1) * 10
            });
        }
        return out;
    }
    var openers = document.querySelectorAll('.js-open-verification-modal');
    for (var i = 0; i < openers.length; i++) {
        openers[i].addEventListener('click', function(e) {
            loadQuestions(e.target.getAttribute('data-courseid'), e.target.getAttribute('data-coursename'));
        });
    }
    addBtn.addEventListener('click', function() {
        list.insertAdjacentHTML('beforeend', rowHtml({question_text:'', apply_mode:'both', is_required:0}));
        bindDeleteHandlers();
    });
    saveBtn.addEventListener('click', function() {
        fetch(apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({action: 'save', courseid: currentCourseId, items: collectRows(), sesskey: sesskey})
        }).then(function(r){ return r.json(); }).then(function(data) {
            if (data && data.ok) { modal.style.display = 'none'; }
        });
    });
    closeBtn.addEventListener('click', function() { modal.style.display = 'none'; });
})();
"); ?>

<?php
$prereqmapjs = [
    'apiUrl' => (new moodle_url('/local/tm_course/settings/course_prerequisite_api.php'))->out(false),
    'activitiesUrl' => (new moodle_url('/local/tm_course/prerequisite_activities.php', ['sesskey' => sesskey()]))->out(false),
    'sesskey' => sesskey(),
    'courseMenu' => $prereq_course_menu,
    'str' => [
        'verifyCourse' => get_string('session_prerequisite_verify_course', 'local_tm_course'),
        'verifyActivities' => get_string('session_prerequisite_verify_activities', 'local_tm_course'),
        'verifyGrades' => get_string('session_prerequisite_verify_grades', 'local_tm_course'),
        'activityAll' => get_string('session_prerequisite_activity_all', 'local_tm_course'),
        'activityAny' => get_string('session_prerequisite_activity_any', 'local_tm_course'),
        'gradeAll' => get_string('session_prerequisite_grade_all', 'local_tm_course'),
        'gradeAny' => get_string('session_prerequisite_grade_any', 'local_tm_course'),
        'addGradeCondition' => get_string('session_prerequisite_add_grade_condition', 'local_tm_course'),
        'gradeMinLabel' => get_string('session_prerequisite_grade_min_label', 'local_tm_course'),
        'gradeMaxLabel' => get_string('session_prerequisite_grade_max_label', 'local_tm_course'),
        'choose' => get_string('choosedots'),
    ],
];
echo html_writer::script("
(function() {
    var cfg = " . json_encode($prereqmapjs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ";
    var modal = document.getElementById('tm-prerequisite-modal-backdrop');
    var title = document.getElementById('tm-prerequisite-modal-title');
    var rulesBox = document.getElementById('tm-prereq-map-rules');
    var opSel = document.getElementById('tm-prereq-map-operator');
    var addBtn = document.getElementById('tm-prereq-map-add');
    var clearBtn = document.getElementById('tm-prereq-map-clear');
    var saveBtn = document.getElementById('tm-prereq-map-save');
    var closeBtn = document.getElementById('tm-prereq-map-close');
    var currentCourseId = 0;
    var S = cfg.str;

    function courseOptions(selected) {
        var html = '<option value=\"0\">' + S.choose + '</option>';
        Object.keys(cfg.courseMenu || {}).forEach(function (cid) {
            var sel = String(selected) === String(cid) ? ' selected' : '';
            html += '<option value=\"' + cid + '\"' + sel + '>' + String(cfg.courseMenu[cid]).replace(/</g, '&lt;') + '</option>';
        });
        return html;
    }

    function verifyType(rule) {
        var v = (rule && rule.verify_type) ? rule.verify_type : 'course';
        if (v === 'activities' || v === 'grades') {
            return v;
        }
        return 'course';
    }

    function gradeConditionRowHtml(cond) {
        cond = cond || {};
        var useMin = cond.min !== null && cond.min !== undefined && cond.min !== '';
        var useMax = cond.max !== null && cond.max !== undefined && cond.max !== '';
        if (!useMin && !useMax) {
            useMin = true;
        }
        var minVal = useMin ? String(cond.min != null ? cond.min : 100) : '';
        var maxVal = useMax ? String(cond.max) : '';
        return '<div class=\"tm-prereq-grade-cond border rounded p-1 mb-1\">'
            + '<div class=\"d-flex flex-wrap align-items-center gap-1\">'
            + '<select class=\"form-control form-control-sm js-grade-cmid\" style=\"min-width:9rem\" data-cmid=\"' + String(cond.cmid || 0) + '\"><option value=\"0\">' + S.choose + '</option></select>'
            + '<label class=\"mb-0 small\"><input type=\"checkbox\" class=\"js-grade-use-min\"' + (useMin ? ' checked' : '') + '> ' + S.gradeMinLabel + '</label>'
            + '<input type=\"number\" class=\"form-control form-control-sm js-grade-min\" style=\"width:4.5rem\" min=\"0\" max=\"100\" step=\"0.01\" value=\"' + minVal + '\"' + (useMin ? '' : ' disabled') + '>'
            + '<span class=\"small\">%</span>'
            + '<label class=\"mb-0 small\"><input type=\"checkbox\" class=\"js-grade-use-max\"' + (useMax ? ' checked' : '') + '> ' + S.gradeMaxLabel + '</label>'
            + '<input type=\"number\" class=\"form-control form-control-sm js-grade-max\" style=\"width:4.5rem\" min=\"0\" max=\"100\" step=\"0.01\" value=\"' + maxVal + '\"' + (useMax ? '' : ' disabled') + '>'
            + '<span class=\"small\">%</span>'
            + '<button type=\"button\" class=\"btn btn-sm btn-outline-secondary js-grade-rm\">×</button>'
            + '</div></div>';
    }

    function gradeConditionsHtml(conditions) {
        conditions = conditions || [];
        if (!conditions.length) {
            conditions = [{cmid: 0, min: 100, max: null}];
        }
        var html = '';
        conditions.forEach(function (c) {
            html += gradeConditionRowHtml(c);
        });
        return html;
    }

    function ruleHtml(rule) {
        rule = rule || {};
        var verify = verifyType(rule);
        var actop = rule.activity_operator === 'any' ? 'any' : 'all';
        var gradeop = rule.grade_operator === 'any' ? 'any' : 'all';
        var cmids = JSON.stringify(rule.cmids || []);
        return '<div class=\"tm-prereq-map-row border rounded p-2 mb-2\">'
            + '<div class=\"mb-1\"><select class=\"form-control form-control-sm js-pr-map-course\">' + courseOptions(rule.courseid || 0) + '</select></div>'
            + '<div class=\"mb-1\"><select class=\"form-control form-control-sm js-pr-map-verify\">'
            + '<option value=\"course\"' + (verify === 'course' ? ' selected' : '') + '>' + S.verifyCourse + '</option>'
            + '<option value=\"activities\"' + (verify === 'activities' ? ' selected' : '') + '>' + S.verifyActivities + '</option>'
            + '<option value=\"grades\"' + (verify === 'grades' ? ' selected' : '') + '>' + S.verifyGrades + '</option>'
            + '</select></div>'
            + '<div class=\"js-pr-map-act-wrap\"' + (verify === 'activities' ? '' : ' style=\"display:none\"') + '>'
            + '<select class=\"form-control form-control-sm js-pr-map-actop mb-1\">'
            + '<option value=\"all\"' + (actop === 'all' ? ' selected' : '') + '>' + S.activityAll + '</option>'
            + '<option value=\"any\"' + (actop === 'any' ? ' selected' : '') + '>' + S.activityAny + '</option>'
            + '</select>'
            + '<select class=\"form-control form-control-sm js-pr-map-cmids\" multiple size=\"4\" data-selected=\"' + cmids.replace(/\"/g, '&quot;') + '\"></select>'
            + '</div>'
            + '<div class=\"js-pr-map-grade-wrap\"' + (verify === 'grades' ? '' : ' style=\"display:none\"') + '>'
            + '<select class=\"form-control form-control-sm js-pr-map-grade-op mb-1\">'
            + '<option value=\"all\"' + (gradeop === 'all' ? ' selected' : '') + '>' + S.gradeAll + '</option>'
            + '<option value=\"any\"' + (gradeop === 'any' ? ' selected' : '') + '>' + S.gradeAny + '</option>'
            + '</select>'
            + '<div class=\"js-pr-map-grade-list\">' + gradeConditionsHtml(rule.grade_conditions) + '</div>'
            + '<button type=\"button\" class=\"btn btn-sm btn-outline-secondary js-pr-map-grade-add mt-1\">' + S.addGradeCondition + '</button>'
            + '</div>'
            + '<button type=\"button\" class=\"btn btn-sm btn-outline-secondary js-pr-map-del\">×</button>'
            + '</div>';
    }

    function bindGradeConditionRow(condRow, row) {
        var useMin = condRow.querySelector('.js-grade-use-min');
        var useMax = condRow.querySelector('.js-grade-use-max');
        var minIn = condRow.querySelector('.js-grade-min');
        var maxIn = condRow.querySelector('.js-grade-max');
        var rmBtn = condRow.querySelector('.js-grade-rm');
        function syncInputs() {
            if (minIn) {
                minIn.disabled = !(useMin && useMin.checked);
            }
            if (maxIn) {
                maxIn.disabled = !(useMax && useMax.checked);
            }
        }
        if (useMin) {
            useMin.addEventListener('change', syncInputs);
        }
        if (useMax) {
            useMax.addEventListener('change', syncInputs);
        }
        if (rmBtn) {
            rmBtn.addEventListener('click', function () {
                var list = row.querySelector('.js-pr-map-grade-list');
                if (list && list.querySelectorAll('.tm-prereq-grade-cond').length <= 1) {
                    return;
                }
                condRow.remove();
            });
        }
        syncInputs();
        populateGradeCmidSelect(condRow, row);
    }

    function populateGradeCmidSelect(condRow, row) {
        var course = row.querySelector('.js-pr-map-course');
        var cmSel = condRow.querySelector('.js-grade-cmid');
        if (!course || !cmSel) {
            return;
        }
        var courseid = parseInt(course.value, 10) || 0;
        var selected = parseInt(cmSel.getAttribute('data-cmid') || '0', 10) || 0;
        cmSel.innerHTML = '<option value=\"0\">' + S.choose + '</option>';
        if (!courseid) {
            return;
        }
        fetch(cfg.activitiesUrl + '&courseid=' + encodeURIComponent(String(courseid)), {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                (j.gradeable || []).forEach(function (a) {
                    var opt = document.createElement('option');
                    opt.value = String(a.cmid);
                    opt.textContent = a.label;
                    if (selected === parseInt(a.cmid, 10)) {
                        opt.selected = true;
                    }
                    cmSel.appendChild(opt);
                });
                cmSel.removeAttribute('data-cmid');
            });
    }

    function wireGradeWrap(row) {
        var gradeWrap = row.querySelector('.js-pr-map-grade-wrap');
        if (!gradeWrap) {
            return;
        }
        var list = gradeWrap.querySelector('.js-pr-map-grade-list');
        var addBtn = gradeWrap.querySelector('.js-pr-map-grade-add');
        if (list) {
            list.querySelectorAll('.tm-prereq-grade-cond').forEach(function (condRow) {
                bindGradeConditionRow(condRow, row);
            });
        }
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                if (!list) {
                    return;
                }
                list.insertAdjacentHTML('beforeend', gradeConditionRowHtml({min: 100}));
                bindGradeConditionRow(list.lastElementChild, row);
            });
        }
    }

    function bindRow(row) {
        var verify = row.querySelector('.js-pr-map-verify');
        var course = row.querySelector('.js-pr-map-course');
        var actWrap = row.querySelector('.js-pr-map-act-wrap');
        var gradeWrap = row.querySelector('.js-pr-map-grade-wrap');
        var cmSel = row.querySelector('.js-pr-map-cmids');
        function syncVerify() {
            var mode = verify.value;
            if (actWrap) {
                actWrap.style.display = mode === 'activities' ? '' : 'none';
            }
            if (gradeWrap) {
                gradeWrap.style.display = mode === 'grades' ? '' : 'none';
            }
            if (mode === 'activities') {
                loadCmids(row);
            }
            if (mode === 'grades') {
                wireGradeWrap(row);
            }
        }
        verify.addEventListener('change', syncVerify);
        course.addEventListener('change', function () {
            if (verify.value === 'activities') {
                if (cmSel) {
                    cmSel.innerHTML = '';
                    cmSel.setAttribute('data-selected', '[]');
                }
                loadCmids(row);
            }
            if (verify.value === 'grades') {
                var list = row.querySelector('.js-pr-map-grade-list');
                if (list) {
                    list.querySelectorAll('.js-grade-cmid').forEach(function (sel) {
                        sel.innerHTML = '<option value=\"0\">' + S.choose + '</option>';
                    });
                    list.querySelectorAll('.tm-prereq-grade-cond').forEach(function (condRow) {
                        populateGradeCmidSelect(condRow, row);
                    });
                }
            }
        });
        row.querySelector('.js-pr-map-del').addEventListener('click', function () {
            row.remove();
        });
        syncVerify();
    }

    function loadCmids(row) {
        var course = row.querySelector('.js-pr-map-course');
        var cmSel = row.querySelector('.js-pr-map-cmids');
        if (!course || !cmSel) {
            return;
        }
        var courseid = parseInt(course.value, 10) || 0;
        var selected = [];
        try { selected = JSON.parse(cmSel.getAttribute('data-selected') || '[]'); } catch (e) {}
        cmSel.innerHTML = '';
        if (!courseid) {
            return;
        }
        fetch(cfg.activitiesUrl + '&courseid=' + encodeURIComponent(String(courseid)), {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                (j.activities || []).forEach(function (a) {
                    var opt = document.createElement('option');
                    opt.value = String(a.cmid);
                    opt.textContent = a.label;
                    if (selected.indexOf(parseInt(a.cmid, 10)) !== -1) {
                        opt.selected = true;
                    }
                    cmSel.appendChild(opt);
                });
                cmSel.removeAttribute('data-selected');
            });
    }

    function renderRules(rules) {
        rulesBox.innerHTML = '';
        var items = (rules && rules.rules) ? rules.rules : [];
        if (!items.length) {
            rulesBox.insertAdjacentHTML('beforeend', ruleHtml(null));
        } else {
            items.forEach(function (rule) {
                rulesBox.insertAdjacentHTML('beforeend', ruleHtml(rule));
            });
        }
        rulesBox.querySelectorAll('.tm-prereq-map-row').forEach(bindRow);
    }

    function collectGradeConditions(row) {
        var out = [];
        row.querySelectorAll('.tm-prereq-grade-cond').forEach(function (condRow) {
            var cmid = parseInt((condRow.querySelector('.js-grade-cmid') || {}).value, 10) || 0;
            if (cmid <= 0) {
                return;
            }
            var useMin = condRow.querySelector('.js-grade-use-min');
            var useMax = condRow.querySelector('.js-grade-use-max');
            var minIn = condRow.querySelector('.js-grade-min');
            var maxIn = condRow.querySelector('.js-grade-max');
            var item = {cmid: cmid, min: null, max: null};
            if (useMin && useMin.checked && minIn && minIn.value !== '') {
                item.min = parseFloat(minIn.value);
            }
            if (useMax && useMax.checked && maxIn && maxIn.value !== '') {
                item.max = parseFloat(maxIn.value);
            }
            if (item.min === null && item.max === null) {
                return;
            }
            out.push(item);
        });
        return out;
    }

    function collectRules() {
        var rules = [];
        rulesBox.querySelectorAll('.tm-prereq-map-row').forEach(function (row) {
            var courseid = parseInt((row.querySelector('.js-pr-map-course') || {}).value, 10) || 0;
            if (courseid <= 0) {
                return;
            }
            var verify = (row.querySelector('.js-pr-map-verify') || {}).value || 'course';
            var item = {courseid: courseid, verify_type: verify};
            if (verify === 'activities') {
                item.activity_operator = (row.querySelector('.js-pr-map-actop') || {}).value || 'all';
                item.cmids = [];
                var cmSel = row.querySelector('.js-pr-map-cmids');
                if (cmSel) {
                    Array.prototype.slice.call(cmSel.options).forEach(function (opt) {
                        if (opt.selected) {
                            item.cmids.push(parseInt(opt.value, 10));
                        }
                    });
                }
            } else if (verify === 'grades') {
                item.grade_operator = (row.querySelector('.js-pr-map-grade-op') || {}).value || 'all';
                item.grade_conditions = collectGradeConditions(row);
            }
            rules.push(item);
        });
        return {operator: opSel.value || 'and', rules: rules};
    }

    function openPrereqModalUi() {
        modal.style.display = 'flex';
        document.body.classList.add('tm-prereq-map-modal-open');
        var body = modal.querySelector('.tm-prereq-modal-body');
        if (body) {
            body.scrollTop = 0;
        }
    }

    function closePrereqModalUi() {
        modal.style.display = 'none';
        document.body.classList.remove('tm-prereq-map-modal-open');
    }

    function openModal(courseId, courseName) {
        currentCourseId = Number(courseId || 0);
        title.textContent = " . json_encode(get_string('course_mapping_prerequisite_settings', 'local_tm_course')) . " + ' - ' + String(courseName || '');
        fetch(cfg.apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({action: 'list', courseid: currentCourseId, sesskey: cfg.sesskey})
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.course_menu) {
                cfg.courseMenu = data.course_menu;
            }
            opSel.value = (data.rules && data.rules.operator) ? data.rules.operator : 'and';
            renderRules(data.rules || {rules: []});
            openPrereqModalUi();
        });
    }

    document.querySelectorAll('.js-open-prerequisite-modal').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(btn.getAttribute('data-courseid'), btn.getAttribute('data-coursename'));
        });
    });
    addBtn.addEventListener('click', function () {
        rulesBox.insertAdjacentHTML('beforeend', ruleHtml(null));
        bindRow(rulesBox.lastElementChild);
    });
    clearBtn.addEventListener('click', function () {
        opSel.value = 'and';
        renderRules({rules: []});
    });
    saveBtn.addEventListener('click', function () {
        fetch(cfg.apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({action: 'save', courseid: currentCourseId, rules: collectRules(), sesskey: cfg.sesskey})
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.ok) {
                closePrereqModalUi();
            } else if (data && data.error) {
                window.alert(data.error);
            }
        });
    });
    closeBtn.addEventListener('click', closePrereqModalUi);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            closePrereqModalUi();
        }
    });
})();
"); ?>

<?php
echo $OUTPUT->footer();
