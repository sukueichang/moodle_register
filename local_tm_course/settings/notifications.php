<?php
/**
 * Notification settings page: templates + recipients by scenario.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../classes/notification_helper.php');
require_once(__DIR__ . '/../classes/pre_class_notification_manager.php');
require_once(__DIR__ . '/../classes/bento_notification_manager.php');
require_once(__DIR__ . '/../classes/notification_editor_helper.php');

use local_tm_course\bento_notification_manager;
use local_tm_course\notification_editor_helper;
use local_tm_course\notification_helper;
use local_tm_course\pre_class_notification_manager;

require_login();
require_capability('local/tm_course:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/tm_course/settings/notifications.php'));
$PAGE->set_pagelayout('admin');
$sm = get_string_manager();
$str = function(string $key, string $fallback) use ($sm): string {
    if ($sm->string_exists($key, 'local_tm_course')) {
        return get_string($key, 'local_tm_course');
    }
    return $fallback;
};
$PAGE->set_title($str('nav_notifications', 'Notification settings'));
$PAGE->requires->css('/local/tm_course/styles.css');

$events = notification_helper::get_notification_events_config();
$availabletargets = notification_helper::get_recipient_target_options();
$eventdescriptions = [
    'new_enrolment' => $str('notify_event_new_enrolment_desc', 'Triggered when a learner submits a new enrolment request.'),
    'approval_result' => $str('notify_event_approval_result_desc', 'Triggered when an enrolment is approved or rejected.'),
    'cancelled' => $str('notify_event_cancelled_desc', 'Triggered when a learner cancels an enrolment.'),
    'pending_overdue' => $str('notify_event_pending_overdue_desc', 'Scheduled reminder for pending enrolments that exceed the threshold.'),
    'reservation_result' => $str('notify_event_reservation_result_desc', 'Triggered when a dedicated class application is approved or rejected.'),
    'reservation_submitted' => $str('notify_event_reservation_submitted_desc', 'Triggered when a user formally submits a dedicated class application (step 3/3).'),
    'batch_enrol_completed' => $str('notify_event_batch_enrol_completed_desc', 'Triggered when a user successfully submits a batch enrolment (pending records created).'),
    'batch_account_created' => $str('notify_event_batch_account_created_desc', 'Triggered when batch enrolment auto-creates a new Moodle account.'),
];

$preclasscolumnsfromrequest = function (): array {
    $columns = [];
    foreach (array_keys(pre_class_notification_manager::get_default_column_labels()) as $colkey) {
        $columns[$colkey] = optional_param('preclass_col_' . $colkey, '', PARAM_TEXT);
    }
    return $columns;
};

$bentocolumnsfromrequest = function (): array {
    $columns = [];
    foreach (array_keys(bento_notification_manager::get_default_column_labels()) as $colkey) {
        $columns[$colkey] = optional_param('bento_col_' . $colkey, '', PARAM_TEXT);
    }
    return $columns;
};

$preclassformoverrides = function () use ($preclasscolumnsfromrequest): array {
    return [
        'subject_zh_tw' => optional_param('preclass_subject_zh_tw', '', PARAM_TEXT),
        'body_zh_tw' => optional_param('preclass_body_zh_tw', '', PARAM_RAW_TRIMMED),
        'extra_emails' => optional_param('preclass_extra_emails', '', PARAM_RAW_TRIMMED),
        'cc_emails' => optional_param('preclass_cc_emails', '', PARAM_RAW_TRIMMED),
        'targets' => optional_param_array('preclass_targets', [], PARAM_ALPHANUMEXT),
        'columns' => $preclasscolumnsfromrequest(),
    ];
};

$preclasspreviewrequested = optional_param('action', '', PARAM_ALPHANUMEXT) === 'preview_preclass' && confirm_sesskey();

if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'test_preclass' && confirm_sesskey()) {
    $result = pre_class_notification_manager::send_test_email($preclassformoverrides());
    $msg = get_string('preclass_test_sent', 'local_tm_course', (object) [
        'sent' => $result['sent'],
        'failed' => $result['failed'],
    ]);
    if (!empty($result['recipients'])) {
        $msg .= ' ' . get_string('preclass_test_recipients', 'local_tm_course', (object) [
            'list' => implode(', ', $result['recipients']),
        ]);
    }
    $level = ($result['sent'] > 0) ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING;
    redirect($PAGE->url, $msg, null, $level);
}

if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'save_bento' && confirm_sesskey()) {
    bento_notification_manager::save_settings(
        optional_param('bento_extra_emails', '', PARAM_RAW_TRIMMED),
        optional_param('bento_cc_emails', '', PARAM_RAW_TRIMMED),
        optional_param('bento_subject_zh_tw', '', PARAM_TEXT),
        notification_editor_helper::read_submitted_body('bento_body_zh_tw'),
        $bentocolumnsfromrequest()
    );
    redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'save_preclass' && confirm_sesskey()) {
    pre_class_notification_manager::save_settings(
        (bool) optional_param('preclass_enabled', 0, PARAM_BOOL),
        optional_param('preclass_hour', 17, PARAM_INT),
        optional_param('preclass_minute', 0, PARAM_INT),
        optional_param('preclass_extra_emails', '', PARAM_RAW_TRIMMED),
        optional_param_array('preclass_targets', [], PARAM_ALPHANUMEXT),
        optional_param('preclass_subject_zh_tw', '', PARAM_TEXT),
        optional_param('preclass_body_zh_tw', '', PARAM_RAW_TRIMMED),
        $preclasscolumnsfromrequest(),
        optional_param('preclass_cc_emails', '', PARAM_RAW_TRIMMED)
    );
    redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'save' && confirm_sesskey()) {
    foreach ($events as $eventkey => $eventmeta) {
        $subjectzhtwkey = 'subject_' . $eventkey . '_zh_tw';
        $bodyzhtwkey = 'body_' . $eventkey . '_zh_tw';
        $subjectenkey = 'subject_' . $eventkey . '_en';
        $bodyenkey = 'body_' . $eventkey . '_en';
        $targetskey = 'targets_' . $eventkey;
        $roleskey = 'roles_' . $eventkey;

        $subjectzhtw = optional_param($subjectzhtwkey, '', PARAM_TEXT);
        $bodyzhtw = optional_param($bodyzhtwkey, '', PARAM_TEXT);
        $subjecten = optional_param($subjectenkey, '', PARAM_TEXT);
        $bodyen = optional_param($bodyenkey, '', PARAM_TEXT);
        $selectedtargets = optional_param_array($targetskey, [], PARAM_ALPHANUMEXT);
        $selectedroles = optional_param_array($roleskey, [], PARAM_INT);

        notification_helper::save_event_settings(
            $eventkey,
            $subjectzhtw,
            $bodyzhtw,
            $subjecten,
            $bodyen,
            $selectedtargets,
            $selectedroles
        );
    }
    redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$roles = role_fix_names(get_all_roles(context_system::instance()), context_system::instance(), ROLENAME_BOTH);
$roleoptions = [];
foreach ($roles as $role) {
    $roleoptions[(int)$role->id] = (string)$role->localname;
}

$preclass = pre_class_notification_manager::get_settings();
$preclasstargetoptions = pre_class_notification_manager::get_target_options();
$preclasssubject = $preclass['subject_zh_tw'] !== ''
    ? $preclass['subject_zh_tw']
    : pre_class_notification_manager::get_default_subject();
$preclassbody = $preclass['body_zh_tw'] !== ''
    ? $preclass['body_zh_tw']
    : pre_class_notification_manager::get_default_body();
$preclasscolumninputs = pre_class_notification_manager::get_column_input_values();
$preclasscolumndefaults = pre_class_notification_manager::get_default_column_labels();
$tomorrowrange = pre_class_notification_manager::get_tomorrow_range();

$bento = bento_notification_manager::get_settings();
$bentosubject = $bento['subject_zh_tw'] !== ''
    ? $bento['subject_zh_tw']
    : bento_notification_manager::get_default_subject();
$bentobody = $bento['body_zh_tw'] !== ''
    ? $bento['body_zh_tw']
    : bento_notification_manager::get_default_body();
$bentocolumndefaults = bento_notification_manager::get_default_column_labels();
$bentocolumninputs = bento_notification_manager::get_column_input_values();

echo $OUTPUT->header();

$preclasspreview = null;
if ($preclasspreviewrequested) {
    $preclasspreview = pre_class_notification_manager::render_preview($preclassformoverrides());
}
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2><?php echo s($str('nav_notifications', 'Notification settings')); ?></h2>
</div>
<style>
    .tm-notify-entry {
        display:block;padding:10px 14px;border:1px solid #d8e2ea;border-radius:10px;
        background:#f8fbfd;text-decoration:none;color:inherit;cursor:pointer;text-align:left;
    }
    .tm-notify-entry .tm-notify-entry-title {
        font-weight:700;color:#0b5f7a;line-height:1.35;
    }
    .tm-notify-entry.is-open {
        border-color:#0b5f7a;
        box-shadow:0 0 0 2px rgba(11,95,122,.15) inset;
        background:#eef6fb;
    }
    .tm-notify-section {
        border:1px solid #d5e3ec;border-radius:12px;overflow:hidden;background:#fff;
    }
    .tm-notify-section[hidden] { display:none !important; }
    .tm-notify-template-fold { margin-bottom:8px; border:1px solid #d5e3ec; border-radius:8px; padding:0; background:#fbfdff; }
    .tm-notify-template-fold > summary {
        cursor:pointer; list-style:none; padding:10px 12px; font-weight:600; color:#0b5f7a; user-select:none;
    }
    .tm-notify-template-fold > summary::-webkit-details-marker { display:none; }
    .tm-notify-template-fold[open] > summary { border-bottom:1px solid #e3edf4; }
    .tm-notify-template-fold .tm-notify-template-inner { padding:10px 12px 12px; }
    .tm-preclass-preview-box {
        border:1px solid #d5e3ec;border-radius:8px;background:#fff;
        padding:12px;max-height:480px;overflow:auto;margin-top:12px;
    }
</style>
<div class="tm-card"><div class="tm-card-body">
    <p class="text-muted"><?php echo s(get_string('notifications_intro', 'local_tm_course')); ?></p>
    <p class="text-muted"><?php echo s(get_string('notifications_channel_notice', 'local_tm_course')); ?></p>

    <div class="mb-4" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
        <button type="button"
                class="tm-notify-entry"
                data-target="event-preclass"
                title="<?php echo s(get_string('preclass_notify_desc', 'local_tm_course')); ?>">
            <div class="tm-notify-entry-title"><?php echo s(get_string('preclass_notify_title', 'local_tm_course')); ?></div>
        </button>
        <button type="button"
                class="tm-notify-entry"
                data-target="event-bento"
                title="<?php echo s(get_string('bento_notify_desc', 'local_tm_course')); ?>">
            <div class="tm-notify-entry-title"><?php echo s(get_string('bento_notify_title', 'local_tm_course')); ?></div>
        </button>
        <?php foreach ($events as $eventkey => $eventmeta): ?>
            <button type="button"
               class="tm-notify-entry"
               data-target="<?php echo s('event-' . $eventkey); ?>"
               title="<?php echo s($eventdescriptions[$eventkey] ?? ''); ?>">
                <div class="tm-notify-entry-title"><?php echo s($eventmeta['label']); ?></div>
            </button>
        <?php endforeach; ?>
    </div>

    <section id="event-preclass" class="mb-4 tm-notify-section" hidden>
        <div style="padding:12px 14px;background:#0b5f7a;color:#fff;">
            <div style="font-weight:700;"><?php echo s(get_string('preclass_notify_title', 'local_tm_course')); ?></div>
            <div style="opacity:.9;font-size:13px;margin-top:2px;">
                <?php echo s(get_string('preclass_notify_desc', 'local_tm_course')); ?>
            </div>
        </div>
        <div style="padding:14px;">
            <form method="post" action="" class="mb-0" id="tm-preclass-form">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <div class="form-group">
                    <label class="mb-0">
                        <input type="checkbox" name="preclass_enabled" value="1" <?php echo !empty($preclass['enabled']) ? 'checked' : ''; ?>>
                        <?php echo s(get_string('preclass_notify_enabled', 'local_tm_course')); ?>
                    </label>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="preclass_hour"><strong><?php echo s(get_string('preclass_notify_send_time', 'local_tm_course')); ?></strong></label>
                        <div class="d-flex" style="gap:8px;align-items:center;">
                            <select name="preclass_hour" id="preclass_hour" class="form-control">
                                <?php for ($h = 0; $h <= 23; $h++): ?>
                                    <option value="<?php echo $h; ?>" <?php echo ((int)$preclass['hour'] === $h) ? 'selected' : ''; ?>>
                                        <?php echo sprintf('%02d', $h); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <span>:</span>
                            <select name="preclass_minute" id="preclass_minute" class="form-control">
                                <?php for ($m = 0; $m <= 59; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo ((int)$preclass['minute'] === $m) ? 'selected' : ''; ?>>
                                        <?php echo sprintf('%02d', $m); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="tm-session-muted"><?php echo s(get_string('preclass_notify_send_time_desc', 'local_tm_course')); ?></div>
                    </div>
                </div>

                <details class="tm-notify-template-fold">
                    <summary><?php echo s($str('preclass_notify_columns_heading', '學員表格欄位名稱（按此展開）')); ?></summary>
                    <div class="tm-notify-template-inner">
                        <p class="tm-session-muted mb-2"><?php echo s($str('preclass_notify_columns_desc', '自訂課前通知信中 HTML 表格的欄位標題；留空則使用預設名稱。')); ?></p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
                            <?php foreach ($preclasscolumndefaults as $colkey => $defaultlabel): ?>
                                <div>
                                    <label class="font-weight-bold mb-1 d-block" for="preclass_col_<?php echo s($colkey); ?>">
                                        <?php echo s($defaultlabel); ?>
                                    </label>
                                    <input type="text"
                                           name="preclass_col_<?php echo s($colkey); ?>"
                                           id="preclass_col_<?php echo s($colkey); ?>"
                                           class="form-control"
                                           maxlength="80"
                                           value="<?php echo s($preclasscolumninputs[$colkey] ?? ''); ?>"
                                           placeholder="<?php echo s($defaultlabel); ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>

                <details class="tm-notify-template-fold" open>
                    <summary><?php echo s($str('notifications_fold_zh_tw', '繁體中文：主旨與內文模板（按此展開）')); ?></summary>
                    <div class="tm-notify-template-inner">
                        <div class="mb-2">
                            <label class="font-weight-bold" for="preclass_subject_zh_tw"><?php echo s($str('notifications_subject_label_zh_tw', '通知主旨模板（繁中）')); ?></label>
                            <input type="text" name="preclass_subject_zh_tw" id="preclass_subject_zh_tw" class="form-control" maxlength="255"
                                   value="<?php echo s($preclasssubject); ?>">
                            <div class="tm-session-muted"><?php echo s(get_string('preclass_notify_subject_hint', 'local_tm_course')); ?></div>
                        </div>
                        <div class="mb-0">
                            <label class="font-weight-bold" for="preclass_body_zh_tw"><?php echo s(get_string('preclass_notify_body_label', 'local_tm_course')); ?></label>
                            <textarea name="preclass_body_zh_tw" id="preclass_body_zh_tw" class="form-control" rows="6"><?php echo s($preclassbody); ?></textarea>
                            <div class="tm-session-muted"><?php echo s(get_string('preclass_notify_body_hint', 'local_tm_course')); ?></div>
                        </div>
                    </div>
                </details>

                <div class="mb-2 mt-2">
                    <div class="font-weight-bold"><?php echo s(get_string('notifications_supported_vars', 'local_tm_course')); ?></div>
                    <div class="tm-session-muted"><?php echo s(implode(', ', pre_class_notification_manager::get_template_tokens())); ?></div>
                </div>

                <div class="mb-2">
                    <div class="font-weight-bold"><?php echo s(get_string('notifications_targets_label', 'local_tm_course')); ?></div>
                    <div class="d-flex flex-wrap" style="gap:1rem;">
                        <?php foreach ($preclasstargetoptions as $targetkey => $targetlabel): ?>
                            <label class="mb-0">
                                <input type="checkbox"
                                       name="preclass_targets[]"
                                       value="<?php echo s($targetkey); ?>"
                                       <?php echo in_array($targetkey, $preclass['targets'], true) ? 'checked' : ''; ?>>
                                <?php echo s($targetlabel); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="tm-session-muted"><?php echo s(get_string('preclass_notify_targets_desc', 'local_tm_course')); ?></div>
                </div>

                <div class="form-group">
                    <label for="preclass_extra_emails"><strong><?php echo s(get_string('preclass_notify_extra_emails', 'local_tm_course')); ?></strong></label>
                    <textarea name="preclass_extra_emails" id="preclass_extra_emails" class="form-control" rows="3"
                        placeholder="email1@example.com, email2@example.com"><?php echo s($preclass['extra_emails']); ?></textarea>
                    <div class="tm-session-muted"><?php echo s(get_string('preclass_notify_extra_emails_desc', 'local_tm_course')); ?></div>
                </div>

                <div class="form-group">
                    <label for="preclass_cc_emails"><strong><?php echo s(get_string('preclass_cc_recipients_label', 'local_tm_course')); ?></strong></label>
                    <textarea name="preclass_cc_emails" id="preclass_cc_emails" class="form-control" rows="3"
                        placeholder="cc1@example.com"><?php echo s($preclass['cc_emails']); ?></textarea>
                    <div class="tm-session-muted"><?php echo s(get_string('preclass_cc_recipients_desc', 'local_tm_course')); ?></div>
                </div>

                <p class="tm-session-muted mb-2">
                    <?php echo s(get_string('preclass_notify_preview_hint', 'local_tm_course', (object)[
                        'date' => $tomorrowrange['label'],
                    ])); ?>
                </p>

                <?php if ($preclasspreview !== null): ?>
                    <div class="mb-2">
                        <strong><?php echo s(get_string('preclass_preview_heading', 'local_tm_course')); ?></strong>
                        <div class="tm-session-muted"><?php echo s(get_string('preclass_preview_subject', 'local_tm_course', (object)['subject' => $preclasspreview['subject']])); ?></div>
                    </div>
                    <div class="tm-preclass-preview-box"><?php echo $preclasspreview['html']; ?></div>
                <?php endif; ?>

                <div class="d-flex flex-wrap mt-3" style="gap:8px;">
                    <button type="submit" name="action" value="save_preclass" class="btn btn-tm-primary"><?php echo s(get_string('savechanges')); ?></button>
                    <button type="submit" name="action" value="preview_preclass" class="btn btn-secondary"><?php echo s(get_string('preclass_preview_button', 'local_tm_course')); ?></button>
                    <button type="submit" name="action" value="test_preclass" class="btn btn-secondary"
                            onclick="return confirm(<?php echo json_encode(get_string('preclass_test_confirm', 'local_tm_course')); ?>);">
                        <?php echo s(get_string('preclass_test_button', 'local_tm_course')); ?>
                    </button>
                </div>
                <p class="tm-session-muted mt-2 mb-0"><?php echo s(get_string('preclass_test_hint', 'local_tm_course')); ?></p>
            </form>
        </div>
    </section>

    <section id="event-bento" class="mb-4 tm-notify-section" hidden>
        <div style="padding:12px 14px;background:#0b5f7a;color:#fff;">
            <div style="font-weight:700;"><?php echo s(get_string('bento_notify_title', 'local_tm_course')); ?></div>
            <div style="opacity:.9;font-size:13px;margin-top:2px;">
                <?php echo s(get_string('bento_notify_desc', 'local_tm_course')); ?>
            </div>
        </div>
        <div style="padding:14px;">
            <form method="post" action="" class="mb-0">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <p class="text-muted"><?php echo s(get_string('bento_notify_settings_hint', 'local_tm_course')); ?></p>
                <div class="form-group">
                    <label for="bento_extra_emails"><strong><?php echo s(get_string('bento_recipients_label', 'local_tm_course')); ?></strong></label>
                    <textarea name="bento_extra_emails" id="bento_extra_emails" class="form-control" rows="3"
                        placeholder="email1@example.com"><?php echo s($bento['extra_emails']); ?></textarea>
                    <div class="tm-session-muted"><?php echo s(get_string('preclass_notify_extra_emails_desc', 'local_tm_course')); ?></div>
                </div>
                <div class="form-group">
                    <label for="bento_cc_emails"><strong><?php echo s(get_string('bento_cc_recipients_label', 'local_tm_course')); ?></strong></label>
                    <textarea name="bento_cc_emails" id="bento_cc_emails" class="form-control" rows="3"
                        placeholder="cc1@example.com"><?php echo s($bento['cc_emails']); ?></textarea>
                    <div class="tm-session-muted"><?php echo s(get_string('bento_cc_recipients_desc', 'local_tm_course')); ?></div>
                </div>
                <details class="tm-notify-template-fold">
                    <summary><?php echo s(get_string('bento_notify_columns_heading', 'local_tm_course')); ?></summary>
                    <div class="tm-notify-template-inner">
                        <p class="tm-session-muted mb-2"><?php echo s(get_string('bento_notify_columns_desc', 'local_tm_course')); ?></p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
                            <?php foreach ($bentocolumndefaults as $colkey => $defaultlabel): ?>
                                <div>
                                    <label class="font-weight-bold mb-1 d-block" for="bento_col_<?php echo s($colkey); ?>">
                                        <?php echo s($defaultlabel); ?>
                                    </label>
                                    <input type="text"
                                           name="bento_col_<?php echo s($colkey); ?>"
                                           id="bento_col_<?php echo s($colkey); ?>"
                                           class="form-control"
                                           maxlength="80"
                                           value="<?php echo s($bentocolumninputs[$colkey] ?? ''); ?>"
                                           placeholder="<?php echo s($defaultlabel); ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
                <details class="tm-notify-template-fold" open>
                    <summary><?php echo s($str('notifications_fold_zh_tw', '繁體中文：主旨與內文模板（按此展開）')); ?></summary>
                    <div class="tm-notify-template-inner">
                        <div class="mb-2">
                            <label class="font-weight-bold" for="bento_subject_zh_tw"><?php echo s(get_string('bento_subject_label', 'local_tm_course')); ?></label>
                            <input type="text" name="bento_subject_zh_tw" id="bento_subject_zh_tw" class="form-control" maxlength="255"
                                   value="<?php echo s($bentosubject); ?>">
                        </div>
                        <div class="mb-0">
                            <label class="font-weight-bold" for="bento_body_zh_tw"><?php echo s(get_string('bento_body_label', 'local_tm_course')); ?></label>
                            <?php notification_editor_helper::print_body_editor('bento_body_zh_tw', $bentobody, 10); ?>
                            <div class="tm-session-muted"><?php echo s(get_string('bento_notify_body_hint', 'local_tm_course')); ?></div>
                            <div class="tm-session-muted"><?php echo s(get_string('bento_notify_body_editor_hint', 'local_tm_course')); ?></div>
                        </div>
                    </div>
                </details>
                <div class="mb-2 mt-2">
                    <div class="font-weight-bold"><?php echo s(get_string('notifications_supported_vars', 'local_tm_course')); ?></div>
                    <div class="tm-session-muted"><?php echo s(implode(', ', bento_notification_manager::get_template_tokens())); ?></div>
                </div>
                <button type="submit" name="action" value="save_bento" class="btn btn-tm-primary mt-2"><?php echo s(get_string('savechanges')); ?></button>
            </form>
        </div>
    </section>

    <form method="post" action="">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

        <?php foreach ($events as $eventkey => $eventmeta): ?>
            <?php
                $targetsettings = notification_helper::get_event_target_settings($eventkey);
                $templatezhtw = notification_helper::get_event_template($eventkey, 'zh_tw');
                $templateen = notification_helper::get_event_template($eventkey, 'en');
            ?>
            <section id="<?php echo s('event-' . $eventkey); ?>" class="mb-4 tm-notify-section" hidden>
                <div style="padding:12px 14px;background:#0b5f7a;color:#fff;">
                    <div style="font-weight:700;"><?php echo s($eventmeta['label']); ?></div>
                    <div style="opacity:.9;font-size:13px;margin-top:2px;">
                        <?php echo s($eventdescriptions[$eventkey] ?? ''); ?>
                    </div>
                </div>
                <div style="padding:14px;">
                    <details class="tm-notify-template-fold">
                        <summary><?php echo s($str('notifications_fold_zh_tw', '繁體中文：主旨與內文模板（按此展開）')); ?></summary>
                        <div class="tm-notify-template-inner">
                            <div class="mb-2">
                                <label class="font-weight-bold"><?php echo s($str('notifications_subject_label_zh_tw', '通知主旨模板（繁中）')); ?></label>
                                <input type="text"
                                       name="<?php echo s('subject_' . $eventkey . '_zh_tw'); ?>"
                                       class="form-control"
                                       maxlength="255"
                                       value="<?php echo s($templatezhtw['subject']); ?>">
                            </div>
                            <div class="mb-0">
                                <label class="font-weight-bold"><?php echo s($str('notifications_body_label_zh_tw', '通知內文模板（繁中）')); ?></label>
                                <textarea name="<?php echo s('body_' . $eventkey . '_zh_tw'); ?>" class="form-control" rows="4"><?php echo s($templatezhtw['body']); ?></textarea>
                            </div>
                        </div>
                    </details>
                    <details class="tm-notify-template-fold">
                        <summary><?php echo s($str('notifications_fold_en', 'English: subject and body templates (click to expand)')); ?></summary>
                        <div class="tm-notify-template-inner">
                            <div class="mb-2">
                                <label class="font-weight-bold"><?php echo s($str('notifications_subject_label_en', 'Notification subject template (English)')); ?></label>
                                <input type="text"
                                       name="<?php echo s('subject_' . $eventkey . '_en'); ?>"
                                       class="form-control"
                                       maxlength="255"
                                       value="<?php echo s($templateen['subject']); ?>">
                            </div>
                            <div class="mb-0">
                                <label class="font-weight-bold"><?php echo s($str('notifications_body_label_en', 'Notification body template (English)')); ?></label>
                                <textarea name="<?php echo s('body_' . $eventkey . '_en'); ?>" class="form-control" rows="4"><?php echo s($templateen['body']); ?></textarea>
                            </div>
                        </div>
                    </details>
                    <div class="mb-2">
                        <div class="font-weight-bold"><?php echo s(get_string('notifications_supported_vars', 'local_tm_course')); ?></div>
                        <div class="tm-session-muted"><?php echo s(implode(', ', $eventmeta['tokens'])); ?></div>
                    </div>
                    <?php if ($eventkey === 'batch_account_created'): ?>
                        <p class="tm-session-muted mb-2"><?php echo s(get_string('notify_batch_account_bilingual_admin_hint', 'local_tm_course')); ?></p>
                    <?php endif; ?>
                    <div class="mb-2">
                        <div class="font-weight-bold"><?php echo s(get_string('notifications_targets_label', 'local_tm_course')); ?></div>
                        <div class="d-flex flex-wrap" style="gap:1rem;">
                            <?php foreach ($availabletargets as $targetkey => $targetlabel): ?>
                                <label class="mb-0">
                                    <input type="checkbox"
                                           name="<?php echo s('targets_' . $eventkey); ?>[]"
                                           value="<?php echo s($targetkey); ?>"
                                           <?php echo in_array($targetkey, $targetsettings['targets'], true) ? 'checked' : ''; ?>>
                                    <?php echo s($targetlabel); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-1">
                        <label class="font-weight-bold"><?php echo s(get_string('notifications_role_targets_label', 'local_tm_course')); ?></label>
                        <select name="<?php echo s('roles_' . $eventkey); ?>[]" class="form-control" multiple size="6">
                            <?php foreach ($roleoptions as $roleid => $rolename): ?>
                                <option value="<?php echo (int)$roleid; ?>" <?php echo in_array((int)$roleid, $targetsettings['roleids'], true) ? 'selected' : ''; ?>>
                                    <?php echo s($rolename); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="tm-session-muted"><?php echo s(get_string('notifications_role_targets_desc', 'local_tm_course')); ?></div>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-tm-primary"><?php echo s(get_string('savechanges')); ?></button>
    </form>
</div></div>
<script>
(function() {
    var entries = Array.prototype.slice.call(document.querySelectorAll('.tm-notify-entry'));
    var sections = Array.prototype.slice.call(document.querySelectorAll('.tm-notify-section'));
    function closeAll() {
        entries.forEach(function(btn) { btn.classList.remove('is-open'); });
        sections.forEach(function(sec) { sec.hidden = true; });
    }
    entries.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId = btn.getAttribute('data-target');
            var target = document.getElementById(targetId);
            if (!target) {
                return;
            }
            var willOpen = target.hidden;
            closeAll();
            if (willOpen) {
                target.hidden = false;
                btn.classList.add('is-open');
                target.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        });
    });
    closeAll();
    <?php if ($preclasspreview !== null): ?>
    (function() {
        var btn = document.querySelector('.tm-notify-entry[data-target="event-preclass"]');
        var sec = document.getElementById('event-preclass');
        if (btn && sec) {
            sec.hidden = false;
            btn.classList.add('is-open');
            sec.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
    })();
    <?php endif; ?>
})();
</script>
<?php
echo $OUTPUT->footer();
