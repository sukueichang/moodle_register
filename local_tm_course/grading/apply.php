<?php
/**
 * Sales: create a grading request.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/grading_request_manager.php');

use local_tm_course\grading_request_manager;

require_login();
$context = context_system::instance();
$PAGE->set_context($context);

grading_request_manager::require_can_apply();

$PAGE->set_url(new moodle_url('/local/tm_course/grading/apply.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('grading_apply_title', 'local_tm_course'));
$PAGE->set_heading(get_string('grading_apply_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $cmid = required_param('cmid', PARAM_INT);
    $userids = optional_param_array('userids', [], PARAM_INT);
    $note = optional_param('note', '', PARAM_RAW_TRIMMED);
    try {
        $rid = grading_request_manager::create_request($cmid, $userids, $note, (int)$USER->id);
        redirect(
            new moodle_url('/local/tm_course/grading/request.php', ['id' => $rid]),
            get_string('grading_submit_success', 'local_tm_course'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
}

$courses = grading_request_manager::enabled_courses();
$sesskey = sesskey();
$searchurl = (new moodle_url('/local/tm_course/grading/search_users.php'))->out(false);
$acturl = (new moodle_url('/local/tm_course/grading/activities.php'))->out(false);

echo $OUTPUT->header();
echo html_writer::start_div('tm-page-header');
echo html_writer::span('', 'tm-logo-dot');
echo html_writer::tag('h2', get_string('grading_apply_title', 'local_tm_course'));
echo html_writer::end_div();

echo html_writer::div(get_string('grading_apply_intro', 'local_tm_course'), 'mb-3');
echo html_writer::div(
    html_writer::link(new moodle_url('/local/tm_course/grading/queue.php', ['view' => 'mine']),
        get_string('grading_queue_mine', 'local_tm_course')),
    'mb-3'
);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out(false),
    'id' => 'tm-grading-apply',
    'class' => 'tm-card tm-card-body tm-grading-apply',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'id' => 'tm-gr-cmid', 'value' => '']);

echo html_writer::tag('label', get_string('grading_label_course', 'local_tm_course'), ['for' => 'tm-gr-course']);
echo html_writer::start_tag('select', ['id' => 'tm-gr-course', 'class' => 'form-control mb-2']);
echo html_writer::tag('option', get_string('choosedots'), ['value' => '']);
foreach ($courses as $c) {
    echo html_writer::tag('option', format_string($c->fullname), ['value' => (int)$c->id]);
}
echo html_writer::end_tag('select');

echo html_writer::tag('label', get_string('grading_label_activity', 'local_tm_course'), ['for' => 'tm-gr-activity']);
echo html_writer::start_tag('select', ['id' => 'tm-gr-activity', 'class' => 'form-control mb-2', 'disabled' => 'disabled']);
echo html_writer::tag('option', get_string('choosedots'), ['value' => '']);
echo html_writer::end_tag('select');

echo html_writer::tag('label', get_string('grading_label_search', 'local_tm_course'), ['for' => 'tm-gr-q']);
echo html_writer::start_div('tm-grading-search-row');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'tm-gr-q',
    'class' => 'form-control',
    'placeholder' => get_string('grading_search_placeholder', 'local_tm_course'),
]);
echo html_writer::tag('button', get_string('search'), [
    'type' => 'button',
    'id' => 'tm-gr-search',
    'class' => 'btn tm-dashboard-btn',
]);
echo html_writer::end_div();
echo html_writer::div('', 'tm-grading-search-results mb-2', ['id' => 'tm-gr-results']);

echo html_writer::tag('h4', get_string('grading_cart_title', 'local_tm_course'), ['class' => 'tm-dashboard-section-title']);
echo html_writer::div(get_string('grading_cart_empty', 'local_tm_course'), 'tm-dashboard-empty', ['id' => 'tm-gr-cart-empty']);
echo html_writer::div('', '', ['id' => 'tm-gr-cart']);

echo html_writer::tag('label', get_string('grading_label_note', 'local_tm_course'), ['for' => 'tm-gr-note']);
echo html_writer::tag('textarea', '', [
    'name' => 'note',
    'id' => 'tm-gr-note',
    'class' => 'form-control mb-2',
    'rows' => 3,
]);

echo html_writer::tag('button', get_string('grading_submit', 'local_tm_course'), [
    'type' => 'submit',
    'class' => 'btn tm-dashboard-btn tm-dashboard-btn-active',
    'id' => 'tm-gr-submit',
]);
echo html_writer::end_tag('form');
?>
<script>
(function() {
    var sesskey = <?php echo json_encode($sesskey); ?>;
    var actUrl = <?php echo json_encode($acturl); ?>;
    var searchUrl = <?php echo json_encode($searchurl); ?>;
    var courseSel = document.getElementById('tm-gr-course');
    var actSel = document.getElementById('tm-gr-activity');
    var cmidInput = document.getElementById('tm-gr-cmid');
    var results = document.getElementById('tm-gr-results');
    var cart = document.getElementById('tm-gr-cart');
    var cartEmpty = document.getElementById('tm-gr-cart-empty');
    var selected = {};

    function renderCart() {
        var ids = Object.keys(selected);
        cart.innerHTML = '';
        cartEmpty.style.display = ids.length ? 'none' : '';
        ids.forEach(function(id) {
            var u = selected[id];
            var row = document.createElement('div');
            row.className = 'tm-grading-cart-row';
            row.textContent = u.fullname + ' (' + u.email + ') ';
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'btn btn-sm tm-dashboard-btn';
            rm.textContent = <?php echo json_encode(get_string('remove')); ?>;
            rm.addEventListener('click', function() {
                delete selected[id];
                renderCart();
            });
            var hid = document.createElement('input');
            hid.type = 'hidden';
            hid.name = 'userids[]';
            hid.value = id;
            row.appendChild(rm);
            row.appendChild(hid);
            cart.appendChild(row);
        });
    }

    courseSel.addEventListener('change', function() {
        actSel.innerHTML = '<option value=""><?php echo s(get_string('choosedots')); ?></option>';
        actSel.disabled = true;
        cmidInput.value = '';
        selected = {};
        renderCart();
        results.innerHTML = '';
        var cid = courseSel.value;
        if (!cid) {
            return;
        }
        fetch(actUrl + '?courseid=' + encodeURIComponent(cid) + '&sesskey=' + encodeURIComponent(sesskey), {credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(data) {
                (data.activities || []).forEach(function(a) {
                    var opt = document.createElement('option');
                    opt.value = a.cmid;
                    opt.textContent = a.name + ' (' + a.modname + ')';
                    actSel.appendChild(opt);
                });
                actSel.disabled = false;
            });
    });

    actSel.addEventListener('change', function() {
        cmidInput.value = actSel.value || '';
        selected = {};
        renderCart();
        results.innerHTML = '';
    });

    document.getElementById('tm-gr-search').addEventListener('click', function() {
        var cmid = actSel.value;
        var q = document.getElementById('tm-gr-q').value.trim();
        results.innerHTML = '';
        if (!cmid) {
            results.textContent = <?php echo json_encode(get_string('grading_pick_activity_first', 'local_tm_course')); ?>;
            return;
        }
        fetch(searchUrl + '?cmid=' + encodeURIComponent(cmid) + '&q=' + encodeURIComponent(q) + '&sesskey=' + encodeURIComponent(sesskey), {credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) {
                    results.textContent = data.error;
                    return;
                }
                var list = data.users || [];
                if (!list.length) {
                    results.textContent = <?php echo json_encode(get_string('grading_search_none', 'local_tm_course')); ?>;
                    return;
                }
                list.forEach(function(u) {
                    var row = document.createElement('div');
                    row.className = 'tm-grading-search-row-item';
                    var label = document.createElement('label');
                    var cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.disabled = !!u.blocked;
                    if (selected[u.id]) {
                        cb.checked = true;
                    }
                    cb.addEventListener('change', function() {
                        if (cb.checked) {
                            selected[u.id] = u;
                        } else {
                            delete selected[u.id];
                        }
                        renderCart();
                    });
                    label.appendChild(cb);
                    var text = u.fullname + ' (' + u.email + ')';
                    if (u.blocked) {
                        text += ' — ' + <?php echo json_encode(get_string('grading_already_on_request', 'local_tm_course')); ?> + ' #' + u.blockrequestid;
                    }
                    label.appendChild(document.createTextNode(' ' + text));
                    row.appendChild(label);
                    results.appendChild(row);
                });
            });
    });

    document.getElementById('tm-grading-apply').addEventListener('submit', function(e) {
        if (!cmidInput.value || !Object.keys(selected).length) {
            e.preventDefault();
            alert(<?php echo json_encode(get_string('grading_error_no_users', 'local_tm_course')); ?>);
        }
    });
    renderCart();
})();
</script>
<?php
echo $OUTPUT->footer();
