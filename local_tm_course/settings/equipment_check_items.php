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

use local_tm_course\enabled_course_manager;
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
            <table class="tm-table">
                <thead>
                    <tr>
                        <th><?php echo get_string('equipment_check_manage_course_col', 'local_tm_course'); ?></th>
                        <th style="width:12rem"><?php echo get_string('sessions_actions', 'local_tm_course'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($coursemenu as $cid => $cname): ?>
                    <tr>
                        <td><?php echo s($cname); ?></td>
                        <td>
                            <button type="button"
                                    class="btn btn-sm btn-tm-primary js-open-equip-modal"
                                    data-courseid="<?php echo (int) $cid; ?>"
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

<div id="tm-equip-modal-backdrop" class="tm-cancel-modal-backdrop" style="display:none;">
    <div class="tm-cancel-modal-panel" style="max-width:52rem;width:94%;">
        <div style="background:#c9660c;color:#fff;padding:.6rem .9rem;margin:-1rem -1rem .9rem;border-radius:.4rem .4rem 0 0;">
            <strong id="tm-equip-modal-title"></strong>
        </div>
        <div id="tm-equip-list"></div>
        <div class="mt-2">
            <button type="button" id="tm-equip-add" class="btn btn-sm btn-secondary">+ <?php echo get_string('equipment_check_item_add', 'local_tm_course'); ?></button>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button type="button" id="tm-equip-save" class="btn btn-tm-success"><?php echo get_string('save_changes', 'local_tm_course'); ?></button>
            <button type="button" id="tm-equip-close" class="btn btn-secondary"><?php echo get_string('cancel', 'local_tm_course'); ?></button>
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
    'delete' => get_string('equipment_check_item_delete', 'local_tm_course'),
    'placeholder' => get_string('equipment_check_item_text_placeholder', 'local_tm_course'),
    'title' => get_string('equipment_check_manage_title', 'local_tm_course'),
];
echo html_writer::script("
(function() {
    var apiUrl = " . json_encode((new moodle_url('/local/tm_course/settings/equipment_check_items_api.php'))->out(false)) . ";
    var sesskey = " . json_encode(sesskey()) . ";
    var S = " . json_encode($str, JSON_UNESCAPED_UNICODE) . ";
    var modal = document.getElementById('tm-equip-modal-backdrop');
    var title = document.getElementById('tm-equip-modal-title');
    var list = document.getElementById('tm-equip-list');
    var addBtn = document.getElementById('tm-equip-add');
    var saveBtn = document.getElementById('tm-equip-save');
    var closeBtn = document.getElementById('tm-equip-close');
    var currentCourseId = 0;

    function rowHtml(item) {
        var t = String(item.itemname || '').replace(/\"/g, '&quot;');
        var scope = String(item.scope || 'both');
        var checktype = String(item.checktype || 'status');
        var enabled = Number(item.enabled === undefined ? 1 : item.enabled) === 1 ? 'checked' : '';
        return '<div class=\"tm-equip-admin-row border rounded p-2 mb-2\">'
            + '<input type=\"text\" class=\"form-control form-control-sm js-eq-text\" placeholder=\"' + S.placeholder + '\" value=\"' + t + '\">'
            + '<div class=\"mt-2 d-flex flex-wrap align-items-center gap-2\">'
            + '<select class=\"form-control form-control-sm js-eq-scope\" style=\"max-width:10rem\">'
            + '<option value=\"onsite\" ' + (scope === 'onsite' ? 'selected' : '') + '>' + S.onsite + '</option>'
            + '<option value=\"online\" ' + (scope === 'online' ? 'selected' : '') + '>' + S.online + '</option>'
            + '<option value=\"both\" ' + (scope === 'both' ? 'selected' : '') + '>' + S.both + '</option>'
            + '</select>'
            + '<select class=\"form-control form-control-sm js-eq-type\" style=\"max-width:16rem\">'
            + '<option value=\"status\" ' + (checktype === 'status' ? 'selected' : '') + '>' + S.typestatus + '</option>'
            + '<option value=\"task\" ' + (checktype === 'task' ? 'selected' : '') + '>' + S.typetask + '</option>'
            + '</select>'
            + '<label class=\"mb-0\"><input type=\"checkbox\" class=\"js-eq-enabled\" ' + enabled + '> ' + S.enabled + '</label>'
            + '<button type=\"button\" class=\"btn btn-sm btn-outline-secondary js-eq-del\">' + S['delete'] + '</button>'
            + '</div></div>';
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
    function loadItems(courseId, courseName) {
        currentCourseId = Number(courseId || 0);
        title.textContent = S.title + ' - ' + String(courseName || '');
        fetch(apiUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({action: 'list', courseid: currentCourseId, sesskey: sesskey})
        }).then(function(r){ return r.json(); }).then(function(data) {
            list.innerHTML = '';
            var items = (data && data.items) ? data.items : [];
            if (!items.length) {
                list.innerHTML = rowHtml({itemname:'', scope:'both', checktype:'status', enabled:1});
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
        var rows = list.querySelectorAll('.tm-equip-admin-row');
        var out = [];
        for (var i = 0; i < rows.length; i++) {
            out.push({
                itemname: (rows[i].querySelector('.js-eq-text') || {}).value || '',
                scope: (rows[i].querySelector('.js-eq-scope') || {}).value || 'both',
                checktype: (rows[i].querySelector('.js-eq-type') || {}).value || 'status',
                enabled: (rows[i].querySelector('.js-eq-enabled') || {}).checked ? 1 : 0,
                sortorder: (i + 1) * 10
            });
        }
        return out;
    }
    var openers = document.querySelectorAll('.js-open-equip-modal');
    for (var i = 0; i < openers.length; i++) {
        openers[i].addEventListener('click', function(e) {
            var btn = e.target.closest('.js-open-equip-modal');
            loadItems(btn.getAttribute('data-courseid'), btn.getAttribute('data-coursename'));
        });
    }
    addBtn.addEventListener('click', function() {
        list.insertAdjacentHTML('beforeend', rowHtml({itemname:'', scope:'both', checktype:'status', enabled:1}));
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
