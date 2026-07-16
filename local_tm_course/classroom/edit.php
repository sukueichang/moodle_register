<?php
/**
 * Create / edit classroom.
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/classroom_manager.php');
require_once(__DIR__ . '/../classes/tcms_sync_manager.php');

use local_tm_course\classroom_manager;
use local_tm_course\tcms_sync_manager;

require_login();
require_capability('local/tm_course:manage', context_system::instance());

global $OUTPUT, $PAGE;

$id = optional_param('id', 0, PARAM_INT);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/tm_course/classroom/edit.php', $id ? ['id' => $id] : []));
$PAGE->set_title($id ? get_string('classroom_edit', 'local_tm_course') : get_string('classroom_add', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

$record = null;
if ($id) {
    $record = classroom_manager::get($id);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $data = [
        'name' => required_param('name', PARAM_TEXT),
        'location' => optional_param('location', '', PARAM_TEXT),
        'tcms_location' => optional_param('tcms_location', '', PARAM_TEXT),
        'table_count' => required_param('table_count', PARAM_INT),
    ];
    try {
        if ($id) {
            classroom_manager::update($id, $data);
            redirect(new moodle_url('/local/tm_course/classroom/index.php'),
                get_string('classroom_saved', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            classroom_manager::create($data);
            redirect(new moodle_url('/local/tm_course/classroom/index.php'),
                get_string('classroom_saved', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (\moodle_exception $e) {
        $errors[] = $e->getMessage();
    }
}

$v = (object) [
    'name' => $record->name ?? '',
    'location' => $record->location ?? '',
    'tcms_location' => $record->tcms_location ?? '',
    'table_count' => $record->table_count ?? 6,
];

$tcmsschema = tcms_sync_manager::get_schema_options();
$tcmslocations = $tcmsschema['locations'];
$currenttcmsloc = trim((string) $v->tcms_location);
if ($currenttcmsloc !== '' && !in_array($currenttcmsloc, $tcmslocations, true)) {
    array_unshift($tcmslocations, $currenttcmsloc);
}

echo $OUTPUT->header();
?>

<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo $id ? get_string('classroom_edit', 'local_tm_course') : get_string('classroom_add', 'local_tm_course'); ?></h2>
</div>

<div class="tm-card">
<div class="tm-card-body">
<?php foreach ($errors as $err): ?>
    <div class="tm-alert tm-alert-error"><?php echo s($err); ?></div>
<?php endforeach; ?>

<form method="post" action="">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <div class="mb-3">
        <label class="font-weight-bold"><?php echo get_string('classroom_name', 'local_tm_course'); ?> *</label>
        <input type="text" name="name" class="form-control" required maxlength="255" value="<?php echo s($v->name); ?>">
    </div>
    <div class="mb-3">
        <label><?php echo get_string('classroom_location', 'local_tm_course'); ?></label>
        <input type="text" name="location" class="form-control" maxlength="255" value="<?php echo s($v->location); ?>">
    </div>
    <div class="mb-3">
        <label><?php echo get_string('classroom_tcms_location', 'local_tm_course'); ?></label>
        <select name="tcms_location" class="form-control">
            <option value=""><?php echo s(get_string('tcms_map_use_default', 'local_tm_course')); ?></option>
            <?php foreach ($tcmslocations as $loc): ?>
                <option value="<?php echo s($loc); ?>" <?php echo ($currenttcmsloc === $loc) ? 'selected' : ''; ?>>
                    <?php echo s($loc); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="tm-form-hint"><?php echo s(get_string('classroom_tcms_location_help', 'local_tm_course')); ?></div>
    </div>
    <div class="mb-3">
        <label class="font-weight-bold"><?php echo get_string('classroom_table_count', 'local_tm_course'); ?> *</label>
        <input type="number" name="table_count" class="form-control" required
               min="<?php echo classroom_manager::MIN_TABLES; ?>"
               max="<?php echo classroom_manager::MAX_TABLES; ?>"
               value="<?php echo (int) $v->table_count; ?>">
        <div class="tm-form-hint"><?php echo get_string('classroom_table_count_help', 'local_tm_course'); ?></div>
    </div>
    <button type="submit" class="btn btn-tm-success"><?php echo get_string('save_changes', 'local_tm_course'); ?></button>
    <a class="btn btn-secondary" href="<?php echo (new moodle_url('/local/tm_course/classroom/index.php'))->out(); ?>">
        <?php echo get_string('cancel', 'local_tm_course'); ?></a>
</form>
</div>
</div>

<?php
echo $OUTPUT->footer();
