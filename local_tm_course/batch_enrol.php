<?php
/**
 * M4 — Business batch enrolment with debrief modal.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/enrolment_manager.php');
require_once(__DIR__ . '/classes/permissions_manager.php');
require_once(__DIR__ . '/classes/notification_helper.php');
require_once(__DIR__ . '/classes/session_verification_manager.php');
require_once(__DIR__ . '/classes/batch_enrol_helper.php');
require_once(__DIR__ . '/classes/prerequisite_manager.php');

use local_tm_course\enrolment_manager;
use local_tm_course\notification_helper;
use local_tm_course\permissions_manager;
use local_tm_course\prerequisite_manager;
use local_tm_course\session_verification_manager;
use local_tm_course\session_manager;

require_login();
global $CFG, $DB, $USER;

session_manager::auto_close_elapsed_sessions();

$isadminmanage = has_capability('local/tm_course:manage', context_system::instance());
$issiteadmin = is_siteadmin($USER);
$canbatchsales = permissions_manager::user_can_batch_enrol();
if (!$isadminmanage && !$canbatchsales) {
    throw new moodle_exception('nopermissions', 'error');
}

$PAGE->set_url(new moodle_url('/local/tm_course/batch_enrol.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('nav_batch_enrol', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

$sessionid = optional_param('sessionid', 0, PARAM_INT);
$confirmed = optional_param('batch_confirmed', 0, PARAM_INT);

if ($isadminmanage) {
    $sessions = session_manager::get_sessions();
} else {
    $sessions = array_values(array_filter(
        session_manager::get_sessions(),
        static function ($s) {
            return session_manager::can_submit_enrolment($s, false);
        }
    ));
}

$selectedsession = null;
if ($sessionid > 0) {
    $selectedsession = session_manager::get_session($sessionid);
    if (!$isadminmanage && !session_manager::can_submit_enrolment($selectedsession, false)) {
        throw new moodle_exception('error_batch_closed_admin_only', 'local_tm_course');
    }
}

if ($sessionid && $confirmed && confirm_sesskey()) {
    $batchsubmitternote = optional_param('batch_submitternote', '', PARAM_TEXT);
    $batchmode = optional_param('batch_mode', '', PARAM_ALPHA);

    $finishbatch = function (array $entries) use ($sessionid, $USER, $isadminmanage, $issiteadmin, $batchsubmitternote, $DB): void {
        try {
            $result = enrolment_manager::batch_enrol_pending(
                $sessionid,
                $USER->id,
                $entries,
                $isadminmanage,
                $batchsubmitternote,
                $issiteadmin
            );
            if ((int)$result['processed'] > 0) {
                notification_helper::notify_batch_enrol_completed(
                    (int)$sessionid,
                    (int)$USER->id,
                    (int)$result['processed'],
                    enrolment_manager::normalise_batch_submitter_note($batchsubmitternote)
                );
                $sesscheck = session_manager::get_session($sessionid);
                if (session_verification_manager::session_has_verification_questions($sesscheck)) {
                    $subid = session_verification_manager::create_batch_submission($sessionid, (int)$USER->id);
                    foreach ($result['enrolment_ids'] as $eid) {
                        if ((int)$eid > 0) {
                            $DB->set_field('local_tm_course_enrolments', 'vq_submission_id', $subid, ['id' => (int)$eid]);
                        }
                    }
                    redirect(new moodle_url('/local/tm_course/enrol_session_verification.php', ['submissionid' => $subid]));
                }
            }
            $msg = get_string('batch_success_processed', 'local_tm_course', (object)['n' => $result['processed']]);
            if (!empty($result['capped'])) {
                $msg .= ' ' . get_string('batch_cap_notice', 'local_tm_course', (object)[
                    'requested' => $result['requested'],
                    'processed' => $result['processed'],
                ]);
            }
            if (!empty($result['skipped'])) {
                $msg .= ' ' . get_string('batch_skipped_count', 'local_tm_course', (object)['n' => count($result['skipped'])]);
                $reasonstext = enrolment_manager::format_batch_skipped_reasons_text($result['skipped']);
                if ($reasonstext !== '') {
                    $msg .= ' ' . $reasonstext;
                }
            }
            if ($issiteadmin && !empty($result['prereq_bypassed'])) {
                $lines = [];
                foreach ($result['prereq_bypassed'] as $pb) {
                    $label = trim((string)($pb['name'] ?? ''));
                    if ($label === '') {
                        $label = (string)($pb['email'] ?? '');
                    }
                    $missing = trim((string)($pb['missing_display'] ?? ''));
                    if ($missing === '') {
                        $missing = implode('; ', (array)($pb['missing'] ?? []));
                    }
                    $lines[] = $label . ($missing !== '' ? ' — ' . $missing : '');
                }
                $msg .= ' ' . get_string('batch_prereq_bypass_summary', 'local_tm_course', (object)[
                    'n' => count($result['prereq_bypassed']),
                    'list' => implode(' | ', $lines),
                ]);
            }
            redirect(new moodle_url('/local/tm_course/batch_enrol.php', ['sessionid' => $sessionid]),
                $msg, null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $ex) {
            \core\notification::error($ex->getMessage());
        }
    };

    if (!in_array($batchmode, ['seat', 'full'], true)) {
        \core\notification::error(get_string('error_batch_mode_required', 'local_tm_course'));
    } else if ($batchmode === 'seat') {
        $batchsession = session_manager::get_session($sessionid);
        if (prerequisite_manager::session_has_prerequisites($batchsession)) {
            \core\notification::error(get_string('error_batch_seat_prerequisite', 'local_tm_course'));
        } else {
        // One hidden field per placeholder seat (order = global placeholder_seq), after client-side cap preview.
        $slots = optional_param_array('seat_slot_company', [], PARAM_TEXT);
        $validslots = [];
        foreach ($slots as $companyraw) {
            $c = clean_param(trim((string)$companyraw), PARAM_TEXT);
            if ($c !== '') {
                $validslots[] = $c;
            }
        }
        $entries = [];
        $seq = 0;
        foreach ($validslots as $c) {
            $seq++;
            $userid = enrolment_manager::create_placeholder_holder_user($sessionid, $seq, $c);
            $entries[] = [
                'userid' => $userid,
                'institution' => $c,
                // Seat holds have no survey at submit time: default meat (A), no avoid flags (business may edit later).
                'diet' => [
                    'choice' => 'A',
                    'special_note' => '',
                ],
                'placeholder_seq' => $seq,
                'seat_company' => $c,
                'is_placeholder_holder' => true,
                'linked_email_pending' => null,
            ];
        }
        if (empty($entries)) {
            \core\notification::error(get_string('error_batch_co_rows_required', 'local_tm_course'));
        } else {
            $finishbatch($entries);
        }
        }
    } else {
        $parsed = \local_tm_course\batch_enrol_helper::parse_full_mode_rows(
            (int) $USER->id,
            (int) $sessionid,
            \local_tm_course\batch_enrol_helper::full_mode_post_arrays(),
            ['allow_partial_placeholders' => true]
        );
        if (!$parsed['ok']) {
            \core\notification::error($parsed['error']);
        } else if (empty($parsed['entries'])) {
            \core\notification::error(get_string('batch_need_one_row', 'local_tm_course'));
        } else {
            $entries = $parsed['entries'];
            foreach ($entries as $idx => $entry) {
                unset($entries[$idx]['learner']);
            }
            $finishbatch($entries);
        }
    }
}

echo $OUTPUT->header();
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h1><?php echo get_string('nav_batch_enrol', 'local_tm_course'); ?></h1>
</div>

<div class="tm-card mt-3"><div class="tm-card-body">
    <form method="post" action="" id="tm-batch-enrol-form" novalidate data-tm-batch-form="1">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="batch_confirmed" id="batch_confirmed" value="0">
        <input type="hidden" name="batch_mode" id="batch_mode_hidden" value="">

        <div class="form-group">
            <label for="tm-batch-sessionid"><strong><?php echo get_string('batch_select_session', 'local_tm_course'); ?></strong></label>
            <select name="sessionid" id="tm-batch-sessionid" class="form-control" required style="max-width:40rem">
                <option value=""><?php echo get_string('choosedots'); ?></option>
                <?php foreach ($sessions as $s):
                    $sel = ((int)$sessionid === (int)$s->id) ? 'selected' : '';
                    $cap = (int)$s->remaining_persons;
                    $hasprereq = prerequisite_manager::session_has_prerequisites($s);
                    ?>
                <option value="<?php echo (int)$s->id; ?>"
                        data-remaining="<?php echo $cap; ?>"
                        data-has-prereq="<?php echo $hasprereq ? '1' : '0'; ?>"
                        data-course-name="<?php echo s($s->name); ?>"
                        data-session-date="<?php echo s(userdate((int)$s->starttime, get_string('strftimedate'))); ?>"
                        data-session-start-time="<?php echo s(userdate((int)$s->starttime, get_string('strftimetime'))); ?>"
                        <?php echo $sel; ?>>
                    <?php echo s($s->name); ?> — <?php echo userdate($s->starttime, get_string('strftimedatetimeshort')); ?>
                    (<?php echo get_string('batch_remaining', 'local_tm_course'); ?>: <?php echo $cap; ?>)
                    <?php if ((int)$s->status === session_manager::STATUS_CLOSED): ?>
                        — <?php echo get_string('session_status_closed', 'local_tm_course'); ?>
                    <?php elseif ((int)$s->status === session_manager::STATUS_FULL): ?>
                        — <?php echo get_string('session_status_full', 'local_tm_course'); ?>
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <p class="text-muted" id="tm-batch-cap-hint"></p>

        <div class="form-group mt-3">
            <label for="batch_submitternote"><strong><?php echo get_string('batch_submitter_note_label', 'local_tm_course'); ?></strong></label>
            <textarea name="batch_submitternote" id="batch_submitternote" class="form-control" rows="3" maxlength="2000"></textarea>
            <div class="tm-form-hint text-muted small"><?php echo get_string('batch_submitter_note_hint', 'local_tm_course'); ?></div>
        </div>

        <p class="text-muted small tm-alert tm-alert-info" id="tm-batch-prereq-mode-hint" hidden>
            <?php echo get_string('batch_prereq_block_a_disabled', 'local_tm_course'); ?>
        </p>

        <div class="form-group tm-batch-mode-radios mt-4" id="tm-batch-mode-radios-wrap">
            <strong><?php echo get_string('batch_mode_choose', 'local_tm_course'); ?></strong>
            <div class="mt-2">
                <label class="mr-4">
                    <input type="radio" name="batch_mode_radio" value="seat" id="batch_mode_seat" checked>
                    <?php echo get_string('batch_mode_seat_label', 'local_tm_course'); ?>
                </label>
                <label>
                    <input type="radio" name="batch_mode_radio" value="full" id="batch_mode_full">
                    <?php echo get_string('batch_mode_full_label', 'local_tm_course'); ?>
                </label>
            </div>
        </div>

        <div class="tm-batch-section border rounded p-3 mt-3" id="tm-batch-block-seat-wrap">
        <fieldset class="border-0 p-0 m-0" id="tm-fieldset-seat">
            <legend class="w-auto px-0"><?php echo get_string('batch_block_seat_title', 'local_tm_course'); ?></legend>
            <p class="text-muted small"><?php echo get_string('batch_block_seat_intro', 'local_tm_course'); ?></p>
            <table class="tm-table" id="tm-batch-co-rows">
                <thead><tr>
                    <th><?php echo get_string('batch_co_company_col', 'local_tm_course'); ?></th>
                    <th><?php echo get_string('batch_co_seats_col', 'local_tm_course'); ?></th>
                    <th style="width:3rem"></th>
                </tr></thead>
                <tbody id="tm-batch-co-tbody">
                <tr id="tm-batch-co-prototype" class="tm-batch-is-prototype tm-batch-co-row">
                    <td><input type="text" class="form-control form-control-sm co_company" maxlength="255" disabled tabindex="-1"></td>
                    <td><input type="number" class="form-control form-control-sm co_seats" min="1" step="1" value="1" disabled tabindex="-1"></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger tm-batch-co-remove" disabled tabindex="-1">&times;</button></td>
                </tr>
                </tbody>
            </table>
        </fieldset>
        <button type="button" class="btn btn-sm btn-secondary mt-2" id="tm-batch-co-add-row"><?php echo get_string('batch_co_add_row', 'local_tm_course'); ?></button>
        </div>

        <div class="tm-batch-section border rounded p-3 mt-3">
        <fieldset class="border-0 p-0 m-0" id="tm-fieldset-full">
            <legend class="w-auto px-0"><?php echo get_string('batch_block_full_title', 'local_tm_course'); ?></legend>
            <p class="text-muted small"><?php echo get_string('batch_block_full_intro', 'local_tm_course'); ?></p>
            <table class="tm-table" id="tm-batch-rows">
                <thead><tr>
                    <th><?php echo get_string('batch_firstname', 'local_tm_course'); ?></th>
                    <th><?php echo get_string('batch_lastname', 'local_tm_course'); ?></th>
                    <th><?php echo get_string('label_email', 'local_tm_course'); ?></th>
                    <th><?php echo get_string('institution', 'local_tm_course'); ?></th>
                    <th><?php echo get_string('diet_survey_title', 'local_tm_course'); ?></th>
                    <th></th>
                </tr></thead>
                <tbody id="tm-batch-rows-tbody">
                <tr id="tm-batch-row-prototype" class="tm-batch-is-prototype tm-batch-row">
                    <td><input type="text" class="form-control form-control-sm entry_firstname" maxlength="100" disabled tabindex="-1"></td>
                    <td><input type="text" class="form-control form-control-sm entry_lastname" maxlength="100" disabled tabindex="-1"></td>
                    <td><input type="email" class="form-control form-control-sm entry_email" placeholder="user@example.com" disabled tabindex="-1"></td>
                    <td><input type="text" class="form-control form-control-sm entry_institution" maxlength="255" disabled tabindex="-1"></td>
                    <td>
                        <select class="form-control form-control-sm entry_diet" disabled tabindex="-1">
                            <option value="">—</option>
                            <option value="A"><?php echo get_string('diet_choice_meat', 'local_tm_course'); ?></option>
                            <option value="B"><?php echo get_string('diet_choice_vegetarian', 'local_tm_course'); ?></option>
                        </select>
                        <input type="text" class="form-control form-control-sm mt-1 entry_special_note" maxlength="255" placeholder="<?php echo s(get_string('diet_special_note', 'local_tm_course')); ?>" disabled tabindex="-1">
                    </td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger tm-batch-remove" disabled tabindex="-1">&times;</button></td>
                </tr>
                </tbody>
            </table>
        </fieldset>
        <button type="button" class="btn btn-sm btn-secondary mt-2" id="tm-batch-add-row"><?php echo get_string('batch_add_row', 'local_tm_course'); ?></button>
        </div>

        <div class="mt-3">
            <button type="button" class="btn btn-tm-primary" id="tm-batch-open-debrief"><?php echo get_string('batch_submit_preview', 'local_tm_course'); ?></button>
            <a class="btn btn-link" href="<?php echo (new moodle_url('/local/tm_course/index.php'))->out(); ?>"><?php echo get_string('cancel'); ?></a>
        </div>
    </form>
</div></div>

<div id="tm-batch-modal" class="tm-cancel-modal-backdrop" hidden>
    <div class="tm-cancel-modal-panel" style="max-width:48rem" role="dialog" aria-modal="true">
        <h4><?php echo get_string('batch_modal_title', 'local_tm_course'); ?></h4>
        <div id="tm-batch-modal-body" class="mb-3" style="max-height:22rem;overflow:auto"></div>
        <div class="d-flex gap-2">
            <button type="button" class="btn tm-enrol-btn" id="tm-batch-modal-confirm"><?php echo get_string('batch_confirm_submit', 'local_tm_course'); ?></button>
            <button type="button" class="btn btn-secondary" id="tm-batch-modal-close"><?php echo get_string('cancel'); ?></button>
        </div>
    </div>
</div>

<?php
$batch_enrol_js_cfg = [
    'lookupBase' => (new moodle_url('/local/tm_course/batch_lookup.php'))->out(false),
    'prereqCheckBase' => (new moodle_url('/local/tm_course/batch_prereq_check.php'))->out(false),
    'sesskey' => sesskey(),
    'str' => [
        'batch_remaining' => get_string('batch_remaining', 'local_tm_course'),
        'batch_select_session' => get_string('batch_select_session', 'local_tm_course'),
        'error_batch_co_rows_required' => get_string('error_batch_co_rows_required', 'local_tm_course'),
        'error_batch_seat_exceeds_remaining' => get_string('error_batch_seat_exceeds_remaining', 'local_tm_course'),
        'batch_modal_note_preview' => get_string('batch_modal_note_preview', 'local_tm_course'),
        'batch_modal_seat_expand_summary' => get_string('batch_modal_seat_expand_summary', 'local_tm_course'),
        'batch_cap_modal_notice' => get_string('batch_cap_modal_notice', 'local_tm_course'),
        'batch_co_company_col' => get_string('batch_co_company_col', 'local_tm_course'),
        'batch_modal_placeholder_seat' => get_string('batch_modal_placeholder_seat', 'local_tm_course'),
        'batch_modal_seat_no_email' => get_string('batch_modal_seat_no_email', 'local_tm_course'),
        'batch_need_one_row' => get_string('batch_need_one_row', 'local_tm_course'),
        'error_batch_name_required' => get_string('error_batch_name_required', 'local_tm_course'),
        'error_institution_required' => get_string('error_institution_required', 'local_tm_course'),
        'error_batch_diet_required' => get_string('error_batch_diet_required', 'local_tm_course'),
        'batch_lookup_loading' => get_string('batch_lookup_loading', 'local_tm_course'),
        'batch_user_existing' => get_string('batch_user_existing', 'local_tm_course'),
        'batch_modal_email_not_registered' => get_string('batch_modal_email_not_registered', 'local_tm_course'),
        'batch_modal_full_rows' => get_string('batch_modal_full_rows', 'local_tm_course'),
        'label_learner' => get_string('label_learner', 'local_tm_course'),
        'label_email' => get_string('label_email', 'local_tm_course'),
        'batch_user_type' => get_string('batch_user_type', 'local_tm_course'),
        'institution' => get_string('institution', 'local_tm_course'),
        'diet_survey_title' => get_string('diet_survey_title', 'local_tm_course'),
        'diet_choice_meat' => get_string('diet_choice_meat', 'local_tm_course'),
        'diet_choice_vegetarian' => get_string('diet_choice_vegetarian', 'local_tm_course'),
        'batch_modal_session_info_title' => get_string('batch_modal_session_info_title', 'local_tm_course'),
        'batch_modal_course_name' => get_string('batch_modal_course_name', 'local_tm_course'),
        'batch_modal_session_date' => get_string('batch_modal_session_date', 'local_tm_course'),
        'batch_modal_session_start_time' => get_string('batch_modal_session_start_time', 'local_tm_course'),
        'batch_prereq_warning_title' => get_string('batch_prereq_warning_title', 'local_tm_course'),
        'batch_prereq_met' => get_string('batch_prereq_met', 'local_tm_course'),
        'batch_prereq_not_met' => get_string('batch_prereq_not_met', 'local_tm_course'),
        'error_batch_seat_prerequisite' => get_string('error_batch_seat_prerequisite', 'local_tm_course'),
    ],
];
$batchjs_path = __DIR__ . '/batch_enrol.js';
$batchjs_ver = file_exists($batchjs_path) ? filemtime($batchjs_path) : time();
$batchjs_url = new moodle_url('/local/tm_course/batch_enrol.js', ['v' => $batchjs_ver]);
?>
<script>window.tmBatchCfg=<?php echo json_encode($batch_enrol_js_cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="<?php echo $batchjs_url->out(); ?>"></script>


<?php
echo $OUTPUT->footer();
