<?php
/**
 * Survey projection board — QR + live response counts (SPEC §59 Phase 3).
 *
 * URL: /local/tm_course/admin/survey_board.php?sessionid=N
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/survey_manager.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');
require_once(__DIR__ . '/../classes/qrcode_svg.php');

use local_tm_course\permissions_manager;
use local_tm_course\qrcode_svg;
use local_tm_course\session_manager;
use local_tm_course\survey_manager;

require_login();
$ctx = context_system::instance();
if (!permissions_manager::user_can_attendance()) {
    throw new required_capability_exception($ctx, 'local/tm_course:attendance', 'nopermissions', '');
}

$sessionid = required_param('sessionid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

global $DB, $OUTPUT, $PAGE;

$session = session_manager::get_session($sessionid);
$course = $DB->get_record('course', ['id' => $session->courseid], 'id, fullname', MUST_EXIST);

$PAGE->set_context($ctx);
$PAGE->set_pagelayout('popup');
$PAGE->set_url(new moodle_url('/local/tm_course/admin/survey_board.php', ['sessionid' => $sessionid]));
$PAGE->set_title(get_string('survey_board_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

if ($action !== '' && confirm_sesskey()) {
    if ($action === 'open') {
        survey_manager::ensure_session_survey_pin($sessionid);
        survey_manager::ensure_session_survey_token($sessionid, true);
    } else if ($action === 'close') {
        survey_manager::set_session_survey_token_enabled($sessionid, false);
    } else if ($action === 'regenerate') {
        survey_manager::ensure_session_survey_pin($sessionid);
        survey_manager::regenerate_session_survey_token($sessionid);
    }
    redirect(new moodle_url('/local/tm_course/admin/survey_board.php', ['sessionid' => $sessionid]));
}

survey_manager::ensure_session_survey_pin($sessionid);
$tok = survey_manager::get_token_for_session($sessionid);
if (!$tok) {
    $tok = survey_manager::ensure_session_survey_token($sessionid, true);
}

$fillurl = survey_manager::quick_fill_url((string) $tok->token);
$count = survey_manager::count_session_responses($sessionid);
$expected = survey_manager::expected_headcount($sessionid);
$rate = $expected > 0 ? round($count / $expected * 100, 1) : null;

$surveyname = '';
$pin = survey_manager::get_pin($sessionid);
if ($pin) {
    $ver = $DB->get_record('local_tm_course_svver', ['id' => $pin->versionid], 'id, surveyid');
    if ($ver) {
        $def = $DB->get_record('local_tm_course_svdef', ['id' => $ver->surveyid], 'id, name');
        $surveyname = $def ? (string) $def->name : '';
    }
}

$progressurl = (new moodle_url('/local/tm_course/admin/survey_progress.php', [
    'sessionid' => $sessionid,
]))->out(false);

echo $OUTPUT->header();
?>
<style>
.tm-survey-board { text-align:center; padding:1.5rem 1rem; font-family:inherit; }
.tm-survey-board h1 { font-size:2.4rem; margin:.4rem 0; }
.tm-survey-board h2 { font-size:1.6rem; font-weight:400; color:#444; margin:.2rem 0 1rem; }
.tm-survey-board .tm-survey-counts { font-size:3rem; font-weight:700; margin:1rem 0; }
.tm-survey-board .tm-survey-url { font-size:1.1rem; word-break:break-all; margin:1rem auto; max-width:40rem; }
.tm-survey-board .tm-survey-actions { margin-top:1.5rem; }
.tm-survey-board .tm-survey-actions form { display:inline-block; margin:.25rem; }
</style>
<div class="tm-survey-board">
    <h1><?php echo s($course->fullname); ?></h1>
    <h2><?php echo s($session->name); ?>
        — <?php echo userdate((int) $session->starttime, get_string('strftimedatetimeshort')); ?>
    </h2>
    <?php if ($surveyname !== ''): ?>
        <p style="font-size:1.3rem"><?php echo s($surveyname); ?></p>
    <?php endif; ?>

    <div class="mb-3">
        <?php echo qrcode_svg::img_html($fillurl->out(false), 320); ?>
    </div>
    <div class="tm-survey-url"><?php echo s($fillurl->out(false)); ?></div>

    <div class="tm-survey-counts">
        <span id="tm-survey-count"><?php echo (int) $count; ?></span>
        <?php if ($expected > 0): ?>
            / <span id="tm-survey-expected"><?php echo (int) $expected; ?></span>
            (<span id="tm-survey-rate"><?php echo s((string) $rate); ?></span>%)
        <?php endif; ?>
    </div>
    <p style="font-size:1.2rem"><?php echo get_string('survey_board_responses', 'local_tm_course'); ?></p>
    <p class="text-muted">
        <?php echo (int) $tok->enabled
            ? get_string('survey_board_token_open', 'local_tm_course')
            : get_string('survey_board_token_closed', 'local_tm_course'); ?>
    </p>

    <div class="tm-survey-actions">
        <form method="post" action="<?php echo $PAGE->url->out(false); ?>">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>"/>
            <input type="hidden" name="action" value="open"/>
            <button type="submit" class="btn btn-success"><?php echo get_string('survey_board_open', 'local_tm_course'); ?></button>
        </form>
        <form method="post" action="<?php echo $PAGE->url->out(false); ?>">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>"/>
            <input type="hidden" name="action" value="close"/>
            <button type="submit" class="btn btn-warning"><?php echo get_string('survey_board_close', 'local_tm_course'); ?></button>
        </form>
        <form method="post" action="<?php echo $PAGE->url->out(false); ?>">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>"/>
            <input type="hidden" name="action" value="regenerate"/>
            <button type="submit" class="btn btn-secondary"><?php echo get_string('survey_board_regenerate', 'local_tm_course'); ?></button>
        </form>
    </div>
</div>
<script>
(function() {
    var url = <?php echo json_encode($progressurl); ?>;
    function tick() {
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var c = document.getElementById('tm-survey-count');
                var e = document.getElementById('tm-survey-expected');
                var rate = document.getElementById('tm-survey-rate');
                if (c && typeof data.count !== 'undefined') { c.textContent = data.count; }
                if (e && typeof data.expected !== 'undefined') { e.textContent = data.expected; }
                if (rate && data.rate !== null && typeof data.rate !== 'undefined') {
                    rate.textContent = data.rate;
                }
            })
            .catch(function() {});
    }
    setInterval(tick, 8000);
})();
</script>
<?php
echo $OUTPUT->footer();
