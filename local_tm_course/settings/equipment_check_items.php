<?php
/**
 * Equipment check item maintenance ("設備檢查清單維護").
 * Standalone admin page: per-course equipment checklist templates for the
 * "上課準備事項" class prep page equipment check section.
 *
 * Access: same as the class prep / attendance page (permissions_manager::user_can_attendance()),
 * not restricted to local/tm_course:manage.
 *
 * @package    local_tm_course
 * @copyright  2026 Techman Robot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/enabled_course_manager.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');
require_once(__DIR__ . '/../classes/equipment_check_manager.php');

use local_tm_course\enabled_course_manager;
use local_tm_course\equipment_check_manager;
use local_tm_course\permissions_manager;

require_login();
$ctx = context_system::instance();
if (!permissions_manager::user_can_attendance()) {
    throw new required_capability_exception($ctx, 'local/tm_course:attendance', 'nopermissions', '');
}

$focuscourseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_context($ctx);
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/tm_course/settings/equipment_check_items.php'));
$PAGE->set_title(get_string('equipment_check_manage_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

$coursemenu = enabled_course_manager::get_course_menu();
$itemsbycourse = equipment_check_manager::get_items_by_courses(array_keys($coursemenu));

$scopelabels = [
    equipment_check_manager::SCOPE_ONSITE => get_string('equipment_check_item_scope_onsite', 'local_tm_course'),
    equipment_check_manager::SCOPE_ONLINE => get_string('equipment_check_item_scope_online', 'local_tm_course'),
    equipment_check_manager::SCOPE_BOTH => get_string('equipment_check_item_scope_both', 'local_tm_course'),
];
$typelabels = [
    equipment_check_manager::TYPE_STATUS => get_string('equipment_check_item_type_status', 'local_tm_course'),
    equipment_check_manager::TYPE_TASK => get_string('equipment_check_item_type_task', 'local_tm_course'),
];
$labelenabled = get_string('equipment_check_item_enabled', 'local_tm_course');
$labeldisabled = get_string('equipment_check_item_disabled', 'local_tm_course');
$labelnone = get_string('equipment_check_manage_none', 'local_tm_course');

/**
 * Build read-only overview HTML for one course's items.
 *
 * @param \stdClass[] $items
 */
$render_overview_detail = static function (array $items) use (
    $scopelabels,
    $typelabels,
    $labelenabled,
    $labeldisabled,
    $labelnone
): string {
    if (empty($items)) {
        return '<p class="tm-equip-overview-empty mb-0">' . s($labelnone) . '</p>';
    }
    $html = '<ol class="tm-equip-overview-list mb-0">';
    $n = 0;
    foreach ($items as $item) {
        $n++;
        $name = (string) ($item->itemname ?? '');
        $scope = (string) ($item->scope ?? equipment_check_manager::SCOPE_BOTH);
        $checktype = (string) ($item->checktype ?? equipment_check_manager::TYPE_STATUS);
        $enabled = !empty($item->enabled);
        $scopetext = $scopelabels[$scope] ?? $scope;
        $typetext = $typelabels[$checktype] ?? $checktype;
        $entext = $enabled ? $labelenabled : $labeldisabled;
        $liClass = $enabled ? '' : ' class="tm-equip-overview-disabled"';
        $html .= '<li' . $liClass . '>'
            . '<div class="tm-equip-overview-item-name">' . s($n . '. ' . $name) . '</div>'
            . '<div class="tm-equip-overview-item-meta">'
            . s($scopetext) . '｜' . s($typetext) . '｜' . s($entext)
            . '</div></li>';
    }
    $html .= '</ol>';
    return $html;
};

echo $OUTPUT->header();
?>

<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo get_string('equipment_check_manage_title', 'local_tm_course'); ?></h2>
</div>

<p><?php echo get_string('equipment_check_manage_intro', 'local_tm_course'); ?></p>

<div class="tm-card">
    <div class="tm-card-body" style="max-height:70vh; overflow:auto;">
        <?php if (empty($coursemenu)): ?>
            <div class="tm-alert tm-alert-info"><?php echo get_string('equipment_check_manage_empty_hint', 'local_tm_course'); ?></div>
        <?php else: ?>
            <table class="tm-table tm-equip-overview-table">
                <thead>
                    <tr>
                        <th><?php echo get_string('equipment_check_manage_course_col', 'local_tm_course'); ?></th>
                        <th style="width:6rem"><?php echo get_string('equipment_check_manage_count_col', 'local_tm_course'); ?></th>
                        <th style="width:12rem"><?php echo get_string('sessions_actions', 'local_tm_course'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($coursemenu as $cid => $cname):
                    $cid = (int) $cid;
                    $items = $itemsbycourse[$cid] ?? [];
                    $count = count($items);
                    $panelid = 'tm-equip-overview-panel-' . $cid;
                    ?>
                    <tr class="tm-equip-overview-row" data-courseid="<?php echo $cid; ?>">
                        <td>
                            <button type="button"
                                    class="tm-equip-overview-toggle js-equip-overview-toggle"
                                    data-courseid="<?php echo $cid; ?>"
                                    aria-expanded="false"
                                    aria-controls="<?php echo $panelid; ?>"
                                    title="<?php echo s(get_string('equipment_check_manage_expand', 'local_tm_course')); ?>">
                                <span class="tm-equip-overview-arrow" aria-hidden="true">▶</span>
                                <span class="tm-equip-overview-coursename"><?php echo s($cname); ?></span>
                            </button>
                            <div id="<?php echo $panelid; ?>"
                                 class="tm-equip-overview-detail js-equip-overview-detail"
                                 data-courseid="<?php echo $cid; ?>"
                                 hidden>
                                <?php echo $render_overview_detail($items); ?>
                            </div>
                        </td>
                        <td class="tm-equip-overview-count-cell">
                            <span class="js-equip-overview-count"><?php echo s(get_string('equipment_check_manage_count', 'local_tm_course', $count)); ?></span>
                        </td>
                        <td>
                            <button type="button"
                                    class="btn btn-sm btn-tm-primary js-open-equip-modal"
                                    data-courseid="<?php echo $cid; ?>"
                                    data-coursename="<?php echo s($cname); ?>">
                                <?php echo get_string('equipment_check_manage_open_button', 'local_tm_course'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<p class="mt-3">
    <a class="btn btn-secondary" href="<?php echo (new moodle_url('/local/tm_course/admin/sessions.php'))->out(); ?>">
        ← <?php echo get_string('nav_sessions', 'local_tm_course'); ?></a>
</p>

<div id="tm-equip-modal-backdrop" class="tm-cancel-modal-backdrop tm-equip-modal-backdrop" style="display:none;">
    <div class="tm-cancel-modal-panel tm-equip-modal-panel" style="max-width:56rem;width:94%;" role="dialog" aria-modal="true" aria-labelledby="tm-equip-modal-title">
        <div class="tm-equip-modal-header">
            <strong id="tm-equip-modal-title"></strong>
        </div>
        <div id="tm-equip-panel-edit" class="tm-equip-modal-section">
            <div class="tm-equip-modal-scroll">
                <div id="tm-equip-list"></div>
                <div class="mt-2 d-flex flex-wrap gap-2">
                    <button type="button" id="tm-equip-add" class="btn btn-sm btn-secondary">+ <?php echo get_string('equipment_check_item_add', 'local_tm_course'); ?></button>
                    <button type="button" id="tm-equip-import-open" class="btn btn-sm btn-outline-primary"><?php echo get_string('equipment_check_import_button', 'local_tm_course'); ?></button>
                </div>
            </div>
            <div class="tm-equip-modal-footer d-flex flex-wrap gap-2">
                <button type="button" id="tm-equip-save" class="btn btn-tm-success"><?php echo get_string('save_changes', 'local_tm_course'); ?></button>
                <button type="button" id="tm-equip-close" class="btn btn-secondary"><?php echo get_string('cancel', 'local_tm_course'); ?></button>
            </div>
        </div>
        <div id="tm-equip-panel-import" class="tm-equip-modal-section" style="display:none;">
            <div class="tm-equip-modal-scroll">
                <p class="tm-equip-import-hint mb-2"><?php echo get_string('equipment_check_import_hint', 'local_tm_course'); ?></p>
                <div id="tm-equip-import-upload" class="mb-2">
                    <input type="file" id="tm-equip-import-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control-file">
                    <div class="mt-2 d-flex flex-wrap gap-2">
                        <button type="button" id="tm-equip-import-preview" class="btn btn-sm btn-tm-primary"><?php echo get_string('equipment_check_import_preview', 'local_tm_course'); ?></button>
                        <button type="button" id="tm-equip-import-back" class="btn btn-sm btn-secondary"><?php echo get_string('equipment_check_import_back', 'local_tm_course'); ?></button>
                    </div>
                </div>
                <div id="tm-equip-import-status" class="mb-2" style="display:none;"></div>
                <div id="tm-equip-import-summary" class="mb-2" style="display:none;"></div>
                <div id="tm-equip-import-preview-wrap" style="display:none;">
                    <table class="tm-table tm-equip-import-table">
                        <thead>
                            <tr>
                                <th><?php echo get_string('equipment_check_import_col_row', 'local_tm_course'); ?></th>
                                <th><?php echo get_string('equipment_check_item_text_placeholder', 'local_tm_course'); ?></th>
                                <th><?php echo get_string('equipment_check_import_col_scope', 'local_tm_course'); ?></th>
                                <th><?php echo get_string('equipment_check_import_col_type', 'local_tm_course'); ?></th>
                                <th><?php echo get_string('equipment_check_item_enabled', 'local_tm_course'); ?></th>
                                <th><?php echo get_string('equipment_check_import_col_result', 'local_tm_course'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="tm-equip-import-preview-body"></tbody>
                    </table>
                </div>
                <div id="tm-equip-import-errors" class="mt-2" style="display:none;"></div>
            </div>
            <div class="tm-equip-modal-footer d-flex flex-wrap gap-2">
                <button type="button" id="tm-equip-import-commit" class="btn btn-tm-success" style="display:none;" disabled><?php echo get_string('equipment_check_import_commit', 'local_tm_course'); ?></button>
                <button type="button" id="tm-equip-import-back2" class="btn btn-secondary"><?php echo get_string('equipment_check_import_back', 'local_tm_course'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php
$str = [
    'onsite' => get_string('equipment_check_item_scope_onsite', 'local_tm_course'),
    'online' => get_string('equipment_check_item_scope_online', 'local_tm_course'),
    'both' => get_string('equipment_check_item_scope_both', 'local_tm_course'),
    'typestatus' => get_string('equipment_check_item_type_status', 'local_tm_course'),
    'typetask' => get_string('equipment_check_item_type_task', 'local_tm_course'),
    'enabled' => get_string('equipment_check_item_enabled', 'local_tm_course'),
    'disabled' => get_string('equipment_check_item_disabled', 'local_tm_course'),
    'delete' => get_string('equipment_check_item_delete', 'local_tm_course'),
    'placeholder' => get_string('equipment_check_item_text_placeholder', 'local_tm_course'),
    'resolutionlabel' => get_string('equipment_check_item_resolution_label', 'local_tm_course'),
    'resolutionhint' => get_string('equipment_check_item_resolution_hint', 'local_tm_course'),
    'supportlabel' => get_string('equipment_check_item_support_label', 'local_tm_course'),
    'supporthint' => get_string('equipment_check_item_support_hint', 'local_tm_course'),
    'title' => get_string('equipment_check_manage_title', 'local_tm_course'),
    'importtitle' => get_string('equipment_check_import_button', 'local_tm_course'),
    'resultok' => get_string('equipment_check_import_result_ok', 'local_tm_course'),
    'resultdup' => get_string('equipment_check_import_result_dup', 'local_tm_course'),
    'resulterr' => get_string('equipment_check_import_result_err', 'local_tm_course'),
    'summary' => get_string('equipment_check_import_summary', 'local_tm_course'),
    'done' => get_string('equipment_check_import_done', 'local_tm_course'),
    'needfile' => get_string('equipment_check_import_need_file', 'local_tm_course'),
    'previewing' => get_string('equipment_check_import_previewing', 'local_tm_course'),
    'committing' => get_string('equipment_check_import_committing', 'local_tm_course'),
    'countTpl' => get_string('equipment_check_manage_count', 'local_tm_course', '{n}'),
    'none' => get_string('equipment_check_manage_none', 'local_tm_course'),
    'expand' => get_string('equipment_check_manage_expand', 'local_tm_course'),
    'collapse' => get_string('equipment_check_manage_collapse', 'local_tm_course'),
];
echo html_writer::script("
(function() {
    var apiUrl = " . json_encode((new moodle_url('/local/tm_course/settings/equipment_check_items_api.php'))->out(false)) . ";
    var importApiUrl = " . json_encode((new moodle_url('/local/tm_course/settings/equipment_check_items_import_api.php'))->out(false)) . ";
    var sesskey = " . json_encode(sesskey()) . ";
    var S = " . json_encode($str, JSON_UNESCAPED_UNICODE) . ";
    var modal = document.getElementById('tm-equip-modal-backdrop');
    var title = document.getElementById('tm-equip-modal-title');
    var list = document.getElementById('tm-equip-list');
    var addBtn = document.getElementById('tm-equip-add');
    var saveBtn = document.getElementById('tm-equip-save');
    var closeBtn = document.getElementById('tm-equip-close');
    var panelEdit = document.getElementById('tm-equip-panel-edit');
    var panelImport = document.getElementById('tm-equip-panel-import');
    var importOpenBtn = document.getElementById('tm-equip-import-open');
    var importFile = document.getElementById('tm-equip-import-file');
    var importPreviewBtn = document.getElementById('tm-equip-import-preview');
    var importCommitBtn = document.getElementById('tm-equip-import-commit');
    var importBackBtn = document.getElementById('tm-equip-import-back');
    var importBackBtn2 = document.getElementById('tm-equip-import-back2');
    var importStatus = document.getElementById('tm-equip-import-status');
    var importSummary = document.getElementById('tm-equip-import-summary');
    var importPreviewWrap = document.getElementById('tm-equip-import-preview-wrap');
    var importPreviewBody = document.getElementById('tm-equip-import-preview-body');
    var importErrors = document.getElementById('tm-equip-import-errors');
    var currentCourseId = 0;
    var currentCourseName = '';
    var importToken = '';
    var commitBusy = false;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;');
    }
    function scopeLabel(scope) {
        if (scope === 'onsite') { return S.onsite; }
        if (scope === 'online') { return S.online; }
        return S.both;
    }
    function typeLabel(checktype) {
        return checktype === 'task' ? S.typetask : S.typestatus;
    }
    function countLabel(n) {
        return String(S.countTpl || '{n} 項').replace('{n}', String(n)).replace('{\$a}', String(n));
    }
    function overviewDetailHtml(items) {
        if (!items || !items.length) {
            return '<p class=\"tm-equip-overview-empty mb-0\">' + esc(S.none) + '</p>';
        }
        var html = '<ol class=\"tm-equip-overview-list mb-0\">';
        for (var i = 0; i < items.length; i++) {
            var it = items[i] || {};
            var enabled = Number(it.enabled) === 1;
            var meta = scopeLabel(it.scope) + '｜' + typeLabel(it.checktype) + '｜' + (enabled ? S.enabled : S.disabled);
            html += '<li' + (enabled ? '' : ' class=\"tm-equip-overview-disabled\"') + '>'
                + '<div class=\"tm-equip-overview-item-name\">' + esc((i + 1) + '. ' + (it.itemname || '')) + '</div>'
                + '<div class=\"tm-equip-overview-item-meta\">' + esc(meta) + '</div></li>';
        }
        html += '</ol>';
        return html;
    }
    function refreshOverview(courseId, items) {
        var row = document.querySelector('.tm-equip-overview-row[data-courseid=\"' + courseId + '\"]');
        if (!row) { return; }
        var countEl = row.querySelector('.js-equip-overview-count');
        var detail = row.querySelector('.js-equip-overview-detail');
        if (countEl) {
            countEl.textContent = countLabel((items && items.length) ? items.length : 0);
        }
        if (detail) {
            detail.innerHTML = overviewDetailHtml(items || []);
        }
    }
    function setOverviewExpanded(toggle, expanded) {
        var courseId = toggle.getAttribute('data-courseid');
        var detail = document.getElementById('tm-equip-overview-panel-' + courseId);
        var arrow = toggle.querySelector('.tm-equip-overview-arrow');
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.setAttribute('title', expanded ? S.collapse : S.expand);
        if (detail) {
            if (expanded) { detail.removeAttribute('hidden'); }
            else { detail.setAttribute('hidden', 'hidden'); }
        }
        if (arrow) { arrow.textContent = expanded ? '▼' : '▶'; }
        toggle.classList.toggle('is-open', !!expanded);
    }
    var toggles = document.querySelectorAll('.js-equip-overview-toggle');
    for (var ti = 0; ti < toggles.length; ti++) {
        toggles[ti].addEventListener('click', function(e) {
            var toggle = e.currentTarget;
            var open = toggle.getAttribute('aria-expanded') === 'true';
            setOverviewExpanded(toggle, !open);
        });
    }

    function rowHtml(item) {
        var t = esc(item.itemname || '');
        var scope = String(item.scope || 'both');
        var checktype = String(item.checktype || 'status');
        var enabled = Number(item.enabled === undefined ? 1 : item.enabled) === 1 ? 'checked' : '';
        var resolutionText = '';
        if (item.resolution_methods_text != null) {
            resolutionText = String(item.resolution_methods_text || '');
        } else if (item.resolution_methods && item.resolution_methods.length) {
            resolutionText = item.resolution_methods.join('\\n');
        }
        var support = esc(item.external_support || '');
        return '<div class=\"tm-equip-admin-row border rounded p-2 mb-2\">'
            + '<input type=\"text\" class=\"form-control form-control-sm js-eq-text\" placeholder=\"' + esc(S.placeholder) + '\" value=\"' + t + '\">'
            + '<div class=\"mt-2 d-flex flex-wrap align-items-center gap-2\">'
            + '<select class=\"form-control form-control-sm js-eq-scope\" style=\"max-width:10rem\">'
            + '<option value=\"onsite\" ' + (scope === 'onsite' ? 'selected' : '') + '>' + esc(S.onsite) + '</option>'
            + '<option value=\"online\" ' + (scope === 'online' ? 'selected' : '') + '>' + esc(S.online) + '</option>'
            + '<option value=\"both\" ' + (scope === 'both' ? 'selected' : '') + '>' + esc(S.both) + '</option>'
            + '</select>'
            + '<select class=\"form-control form-control-sm js-eq-type\" style=\"max-width:16rem\">'
            + '<option value=\"status\" ' + (checktype === 'status' ? 'selected' : '') + '>' + esc(S.typestatus) + '</option>'
            + '<option value=\"task\" ' + (checktype === 'task' ? 'selected' : '') + '>' + esc(S.typetask) + '</option>'
            + '</select>'
            + '<label class=\"mb-0\"><input type=\"checkbox\" class=\"js-eq-enabled\" ' + enabled + '> ' + esc(S.enabled) + '</label>'
            + '<button type=\"button\" class=\"btn btn-sm btn-outline-secondary js-eq-del\">' + esc(S['delete']) + '</button>'
            + '</div>'
            + '<label class=\"tm-equip-admin-sublabel mt-2 mb-1 d-block\">' + esc(S.resolutionlabel) + '</label>'
            + '<textarea class=\"form-control form-control-sm js-eq-resolution\" rows=\"3\" placeholder=\"' + esc(S.resolutionhint) + '\">' + esc(resolutionText) + '</textarea>'
            + '<label class=\"tm-equip-admin-sublabel mt-2 mb-1 d-block\">' + esc(S.supportlabel) + '</label>'
            + '<textarea class=\"form-control form-control-sm js-eq-support\" rows=\"2\" placeholder=\"' + esc(S.supporthint) + '\">' + support + '</textarea>'
            + '</div>';
    }
    function bindDeleteHandlers() {
        var btns = list.querySelectorAll('.js-eq-del');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function(e) {
                var row = e.target.closest('.tm-equip-admin-row');
                if (row) { row.remove(); }
            });
        }
    }
    function showEditPanel() {
        panelImport.style.display = 'none';
        panelEdit.style.display = 'flex';
        title.textContent = S.title + ' - ' + String(currentCourseName || '');
        resetImportUi();
    }
    function showImportPanel() {
        panelEdit.style.display = 'none';
        panelImport.style.display = 'flex';
        title.textContent = S.importtitle + ' - ' + String(currentCourseName || '');
        resetImportUi();
    }
    function resetImportUi() {
        importToken = '';
        commitBusy = false;
        if (importFile) { importFile.value = ''; }
        importStatus.style.display = 'none';
        importStatus.textContent = '';
        importSummary.style.display = 'none';
        importSummary.innerHTML = '';
        importPreviewWrap.style.display = 'none';
        importPreviewBody.innerHTML = '';
        importErrors.style.display = 'none';
        importErrors.innerHTML = '';
        importCommitBtn.style.display = 'none';
        importCommitBtn.disabled = true;
        importPreviewBtn.disabled = false;
    }
    function setImportStatus(msg, isError) {
        importStatus.style.display = 'block';
        importStatus.className = 'mb-2 tm-alert ' + (isError ? 'tm-alert-danger' : 'tm-alert-info');
        importStatus.textContent = msg || '';
    }
    function renderPreview(data) {
        var summary = data.summary || {};
        importSummary.style.display = 'block';
        importSummary.textContent = data.summary_text
            || ('總筆數：' + (summary.total || 0)
                + '　可匯入：' + (summary.ok || 0)
                + '　重複：' + (summary.duplicate || 0)
                + '　錯誤：' + (summary.error || 0));
        var rows = data.rows || [];
        var errHtml = [];
        importPreviewBody.innerHTML = '';
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var resultLabel = S.resultok;
            var resultClass = 'tm-equip-import-ok';
            if (r.result === 'duplicate') {
                resultLabel = S.resultdup;
                resultClass = 'tm-equip-import-dup';
            } else if (r.result === 'error') {
                resultLabel = S.resulterr;
                resultClass = 'tm-equip-import-err';
            }
            importPreviewBody.insertAdjacentHTML('beforeend',
                '<tr class=\"' + resultClass + '\">'
                + '<td>' + esc(r.excel_row) + '</td>'
                + '<td>' + esc(r.itemname) + '</td>'
                + '<td>' + esc(r.scope_label) + '</td>'
                + '<td>' + esc(r.checktype_label) + '</td>'
                + '<td>' + esc(r.enabled_label) + '</td>'
                + '<td>' + esc(resultLabel) + '</td>'
                + '</tr>');
            if (r.errors && r.errors.length) {
                for (var j = 0; j < r.errors.length; j++) {
                    if (r.result === 'error') {
                        errHtml.push('<li>' + esc(r.errors[j]) + '</li>');
                    }
                }
            }
        }
        importPreviewWrap.style.display = 'block';
        if (errHtml.length) {
            importErrors.style.display = 'block';
            importErrors.innerHTML = '<ul class=\"mb-0\">' + errHtml.join('') + '</ul>';
        } else {
            importErrors.style.display = 'none';
            importErrors.innerHTML = '';
        }
        importToken = data.token || '';
        var canCommit = !!data.can_commit;
        importCommitBtn.style.display = canCommit ? 'inline-block' : 'none';
        importCommitBtn.disabled = !canCommit;
    }
    function fillList(items) {
        list.innerHTML = '';
        if (!items.length) {
            list.innerHTML = rowHtml({itemname:'', scope:'both', checktype:'status', enabled:1});
        } else {
            for (var i = 0; i < items.length; i++) {
                list.insertAdjacentHTML('beforeend', rowHtml(items[i]));
            }
        }
        bindDeleteHandlers();
    }
    function openModal() {
        document.body.classList.add('tm-equip-modal-open');
        modal.style.display = 'flex';
    }
    function closeModal() {
        document.body.classList.remove('tm-equip-modal-open');
        modal.style.display = 'none';
    }
    function loadItems(courseId, courseName) {
        currentCourseId = Number(courseId || 0);
        currentCourseName = String(courseName || '');
        title.textContent = S.title + ' - ' + currentCourseName;
        fetch(apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({action: 'list', courseid: currentCourseId, sesskey: sesskey})
        }).then(function(r){ return r.json(); }).then(function(data) {
            fillList((data && data.items) ? data.items : []);
            showEditPanel();
            openModal();
        });
    }
    function collectRows() {
        var rows = list.querySelectorAll('.tm-equip-admin-row');
        var out = [];
        for (var i = 0; i < rows.length; i++) {
            out.push({
                itemname: (rows[i].querySelector('.js-eq-text') || {}).value || '',
                scope: (rows[i].querySelector('.js-eq-scope') || {}).value || 'both',
                checktype: (rows[i].querySelector('.js-eq-type') || {}).value || 'status',
                enabled: (rows[i].querySelector('.js-eq-enabled') || {}).checked ? 1 : 0,
                sortorder: (i + 1) * 10,
                resolution_methods_text: (rows[i].querySelector('.js-eq-resolution') || {}).value || '',
                external_support: (rows[i].querySelector('.js-eq-support') || {}).value || ''
            });
        }
        return out;
    }
    var openers = document.querySelectorAll('.js-open-equip-modal');
    for (var i = 0; i < openers.length; i++) {
        openers[i].addEventListener('click', function(e) {
            e.stopPropagation();
            var btn = e.target.closest('.js-open-equip-modal');
            loadItems(btn.getAttribute('data-courseid'), btn.getAttribute('data-coursename'));
        });
    }
    addBtn.addEventListener('click', function() {
        list.insertAdjacentHTML('beforeend', rowHtml({
            itemname:'', scope:'both', checktype:'status', enabled:1,
            resolution_methods_text:'', external_support:''
        }));
        bindDeleteHandlers();
    });
    saveBtn.addEventListener('click', function() {
        var payloadItems = collectRows();
        fetch(apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({action: 'save', courseid: currentCourseId, items: payloadItems, sesskey: sesskey})
        }).then(function(r){ return r.json(); }).then(function(data) {
            if (data && data.ok) {
                // Re-list so overview matches persisted rows (empty names skipped server-side).
                fetch(apiUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    credentials: 'same-origin',
                    body: JSON.stringify({action: 'list', courseid: currentCourseId, sesskey: sesskey})
                }).then(function(r){ return r.json(); }).then(function(listData) {
                    refreshOverview(currentCourseId, (listData && listData.items) ? listData.items : []);
                    closeModal();
                });
            }
        });
    });
    closeBtn.addEventListener('click', function() { closeModal(); });
    importOpenBtn.addEventListener('click', function() { showImportPanel(); });
    importBackBtn.addEventListener('click', function() { showEditPanel(); });
    importBackBtn2.addEventListener('click', function() { showEditPanel(); });
    importPreviewBtn.addEventListener('click', function() {
        if (!importFile.files || !importFile.files.length) {
            setImportStatus(S.needfile, true);
            return;
        }
        var fd = new FormData();
        fd.append('action', 'preview');
        fd.append('courseid', String(currentCourseId));
        fd.append('sesskey', sesskey);
        fd.append('file', importFile.files[0]);
        importPreviewBtn.disabled = true;
        setImportStatus(S.previewing, false);
        fetch(importApiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).then(function(r){ return r.json().then(function(j){ return {http:r, json:j}; }); })
          .then(function(res) {
            importPreviewBtn.disabled = false;
            if (!res.json || !res.json.ok) {
                setImportStatus((res.json && res.json.error) ? res.json.error : S.resulterr, true);
                importCommitBtn.style.display = 'none';
                importCommitBtn.disabled = true;
                return;
            }
            setImportStatus('', false);
            importStatus.style.display = 'none';
            renderPreview(res.json);
        }).catch(function() {
            importPreviewBtn.disabled = false;
            setImportStatus(S.resulterr, true);
        });
    });
    importCommitBtn.addEventListener('click', function() {
        if (commitBusy || !importToken || importCommitBtn.disabled) { return; }
        commitBusy = true;
        importCommitBtn.disabled = true;
        setImportStatus(S.committing, false);
        var fd = new FormData();
        fd.append('action', 'commit');
        fd.append('courseid', String(currentCourseId));
        fd.append('sesskey', sesskey);
        fd.append('token', importToken);
        fetch(importApiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).then(function(r){ return r.json(); }).then(function(data) {
            if (!data || !data.ok) {
                commitBusy = false;
                setImportStatus((data && data.error) ? data.error : S.resulterr, true);
                importCommitBtn.disabled = false;
                return;
            }
            var msg = (data && data.message) ? data.message
                : ('匯入完成。成功新增：' + (data.inserted || 0)
                    + '；重複跳過：' + (data.skipped || 0)
                    + '；失敗：' + (data.failed || 0));
            setImportStatus(msg, false);
            importToken = '';
            fetch(apiUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify({action: 'list', courseid: currentCourseId, sesskey: sesskey})
            }).then(function(r){ return r.json(); }).then(function(listData) {
                var items = (listData && listData.items) ? listData.items : [];
                fillList(items);
                refreshOverview(currentCourseId, items);
                window.setTimeout(function() { showEditPanel(); }, 600);
            });
        }).catch(function() {
            commitBusy = false;
            importCommitBtn.disabled = false;
            setImportStatus(S.resulterr, true);
        });
    });

    var focusCourseId = " . (int) $focuscourseid . ";
    if (focusCourseId > 0) {
        var opener = document.querySelector('.js-open-equip-modal[data-courseid=\"' + focusCourseId + '\"]');
        if (opener) {
            loadItems(opener.getAttribute('data-courseid'), opener.getAttribute('data-coursename'));
        }
    }
})();
"); ?>

<?php
echo $OUTPUT->footer();
