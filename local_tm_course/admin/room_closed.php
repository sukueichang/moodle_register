<?php
/**
 * Admin: 教室未開放 — classroom-only time block (no course / enrolment).
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/classroom_manager.php');

use local_tm_course\session_manager;
use local_tm_course\classroom_manager;

/**
 * @param string $date Y-m-d
 */
function local_tm_course_room_closed_combine(string $date, int $hour, int $minute): int {
    $minute = ((int) $minute === 30) ? 30 : 0;
    $hour = max(0, min(23, (int) $hour));
    $date = trim($date);
    if ($date === '') {
        return 0;
    }
    $ts = strtotime($date . ' ' . sprintf('%02d:%02d:00', $hour, $minute));
    return $ts === false ? 0 : $ts;
}

function local_tm_course_room_closed_split(int $ts): array {
    $i = (int) date('i', $ts);
    $m = ($i >= 30) ? 30 : 0;
    return [
        'date' => date('Y-m-d', $ts),
        'hour' => (int) date('G', $ts),
        'minute' => $m,
    ];
}

require_login();
require_capability('local/tm_course:manage', context_system::instance());

global $DB, $OUTPUT, $PAGE;

$id = optional_param('id', 0, PARAM_INT);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_url(new moodle_url('/local/tm_course/admin/room_closed.php', $id ? ['id' => $id] : []));
$PAGE->set_title(get_string('session_room_closed_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

$session = null;
if ($id) {
    $session = session_manager::get_session($id);
    if (!session_manager::is_room_closed_session($session)) {
        throw new \moodle_exception('error_room_closed_not_this_kind', 'local_tm_course');
    }
}

$classrooms = classroom_manager::get_all();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $start = local_tm_course_room_closed_combine(
        required_param('start_date', PARAM_TEXT),
        required_param('start_hour', PARAM_INT),
        required_param('start_minute', PARAM_INT)
    );
    $end = local_tm_course_room_closed_combine(
        required_param('end_date', PARAM_TEXT),
        required_param('end_hour', PARAM_INT),
        required_param('end_minute', PARAM_INT)
    );
    $payload = [
        'classroomid' => optional_param('classroomid', 0, PARAM_INT),
        'starttime' => $start,
        'endtime' => $end,
        'name' => optional_param('name', '', PARAM_TEXT),
    ];
    try {
        if ($id) {
            session_manager::update_room_closed_block($id, $payload);
            redirect(new moodle_url('/local/tm_course/admin/sessions.php'),
                get_string('session_updated', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        session_manager::create_room_closed_block($payload);
        redirect(new moodle_url('/local/tm_course/admin/sessions.php'),
            get_string('session_created', 'local_tm_course'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        $errors[] = $e->getMessage();
    }
}

if ($session) {
    $ss = local_tm_course_room_closed_split((int) $session->starttime);
    $es = local_tm_course_room_closed_split((int) $session->endtime);
} else {
    $t = time();
    $ss = local_tm_course_room_closed_split($t);
    $es = local_tm_course_room_closed_split($t + 3600);
}

$v = (object) [
    'classroomid' => (int) ($session->classroomid ?? 0),
    'name' => (string) ($session->name ?? ''),
    'start_date' => $ss['date'],
    'start_hour' => $ss['hour'],
    'start_minute' => $ss['minute'],
    'end_date' => $es['date'],
    'end_hour' => $es['hour'],
    'end_minute' => $es['minute'],
];

echo $OUTPUT->header();
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo get_string('session_room_closed_title', 'local_tm_course'); ?></h2>
</div>
<div class="tm-card"><div class="tm-card-body">
<p class="text-muted mb-3"><?php echo get_string('session_room_closed_help', 'local_tm_course'); ?></p>
<?php foreach ($errors as $err): ?>
    <div class="tm-alert tm-alert-error"><?php echo s($err); ?></div>
<?php endforeach; ?>
<form method="post" action="<?php echo $PAGE->url->out(false); ?>" class="tm-form">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <div class="form-group">
        <label for="classroomid"><?php echo get_string('session_classroom', 'local_tm_course'); ?></label>
        <select name="classroomid" id="classroomid" class="form-control" required>
            <option value="0">—</option>
            <?php foreach ($classrooms as $room): ?>
                <option value="<?php echo (int)$room->id; ?>" <?php echo ((int)$v->classroomid === (int)$room->id) ? 'selected' : ''; ?>>
                    <?php echo s($room->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="name"><?php echo get_string('session_name', 'local_tm_course'); ?> (<?php echo get_string('optional', 'form'); ?>)</label>
        <input type="text" name="name" id="name" class="form-control" maxlength="255"
               value="<?php echo s($v->name); ?>"
               placeholder="<?php echo s(get_string('session_room_closed_default_title', 'local_tm_course')); ?>">
    </div>
    <div class="form-row">
        <div class="form-group col-md-4">
            <label><?php echo get_string('session_room_closed_start_row', 'local_tm_course'); ?></label>
            <input type="date" name="start_date" class="form-control" required value="<?php echo s($v->start_date); ?>">
        </div>
        <div class="form-group col-md-4">
            <label><?php echo get_string('session_room_closed_time_suffix', 'local_tm_course'); ?></label>
            <div class="d-flex gap-1">
                <select name="start_hour" class="form-control">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                        <option value="<?php echo $h; ?>" <?php echo ((int)$v->start_hour === $h) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $h); ?></option>
                    <?php endfor; ?>
                </select>
                <select name="start_minute" class="form-control">
                    <option value="0" <?php echo ((int)$v->start_minute === 0) ? 'selected' : ''; ?>>00</option>
                    <option value="30" <?php echo ((int)$v->start_minute === 30) ? 'selected' : ''; ?>>30</option>
                </select>
            </div>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group col-md-4">
            <label><?php echo get_string('session_room_closed_end_row', 'local_tm_course'); ?></label>
            <input type="date" name="end_date" class="form-control" required value="<?php echo s($v->end_date); ?>">
        </div>
        <div class="form-group col-md-4">
            <label><?php echo get_string('session_room_closed_time_suffix', 'local_tm_course'); ?></label>
            <div class="d-flex gap-1">
                <select name="end_hour" class="form-control">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                        <option value="<?php echo $h; ?>" <?php echo ((int)$v->end_hour === $h) ? 'selected' : ''; ?>><?php echo sprintf('%02d', $h); ?></option>
                    <?php endfor; ?>
                </select>
                <select name="end_minute" class="form-control">
                    <option value="0" <?php echo ((int)$v->end_minute === 0) ? 'selected' : ''; ?>>00</option>
                    <option value="30" <?php echo ((int)$v->end_minute === 30) ? 'selected' : ''; ?>>30</option>
                </select>
            </div>
        </div>
    </div>
    <div class="mt-3">
        <button type="submit" class="btn btn-tm-primary"><?php echo get_string('savechanges', 'moodle'); ?></button>
        <a href="<?php echo (new moodle_url('/local/tm_course/admin/sessions.php'))->out(); ?>" class="btn btn-secondary"><?php echo get_string('cancel'); ?></a>
    </div>
</form>
</div></div>
<?php
echo $OUTPUT->footer();
