<?php
/**
 * Classroom list (admin).
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/classroom_manager.php');

use local_tm_course\classroom_manager;

require_login();
require_capability('local/tm_course:manage', context_system::instance());

global $OUTPUT, $PAGE;

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/tm_course/classroom/index.php'));
$PAGE->set_title(get_string('classroom_manage', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

$delete = optional_param('delete', 0, PARAM_INT);
if ($delete && confirm_sesskey()) {
    try {
        classroom_manager::delete($delete);
        redirect($PAGE->url, get_string('classroom_deleted', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        \core\notification::error($e->getMessage());
    }
}

$rows = classroom_manager::get_all();

echo $OUTPUT->header();
?>

<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo get_string('classroom_manage', 'local_tm_course'); ?></h2>
</div>

<p>
    <a class="btn btn-tm-success" href="<?php echo (new moodle_url('/local/tm_course/classroom/edit.php'))->out(); ?>">
        <?php echo get_string('classroom_add', 'local_tm_course'); ?>
    </a>
    <a class="btn btn-secondary" href="<?php echo (new moodle_url('/local/tm_course/admin/sessions.php'))->out(); ?>">
        <?php echo get_string('nav_sessions', 'local_tm_course'); ?>
    </a>
</p>

<div class="tm-card">
    <div class="tm-card-body p-0">
        <table class="table table-striped mb-0">
            <thead>
                <tr>
                    <th><?php echo get_string('classroom_name', 'local_tm_course'); ?></th>
                    <th><?php echo get_string('classroom_location', 'local_tm_course'); ?></th>
                    <th><?php echo get_string('classroom_table_count', 'local_tm_course'); ?></th>
                    <th><?php echo get_string('classroom_capacity_hint', 'local_tm_course'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5"><?php echo get_string('classroom_none', 'local_tm_course'); ?></td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo s($r->name); ?></td>
                    <td><?php echo s($r->location); ?></td>
                    <td><?php echo classroom_manager::table_count($r); ?></td>
                    <td><?php echo classroom_manager::table_count($r) * 3; ?></td>
                    <td class="text-right">
                        <a href="<?php echo (new moodle_url('/local/tm_course/classroom/edit.php', ['id' => $r->id]))->out(); ?>">
                            <?php echo get_string('edit'); ?></a>
                        |
                        <a href="<?php echo (new moodle_url('/local/tm_course/classroom/index.php', [
                            'delete' => $r->id,
                            'sesskey' => sesskey(),
                        ]))->out(); ?>"
                           class="text-danger"
                           onclick="return confirm(<?php echo json_encode(get_string('confirm_delete_classroom', 'local_tm_course')); ?>);">
                            <?php echo get_string('delete'); ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
echo $OUTPUT->footer();
