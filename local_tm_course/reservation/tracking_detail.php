<?php
/**
 * Tracking detail page for batch/custom requests.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/verification_manager.php');
require_once(__DIR__ . '/../classes/enrolment_manager.php');

use local_tm_course\permissions_manager;
use local_tm_course\enrolment_manager;
use local_tm_course\session_manager;

require_login();

$context = context_system::instance();
$issiteadmin = is_siteadmin();
$canbatch = permissions_manager::user_can_batch_enrol();
if (!$issiteadmin && !$canbatch) {
    throw new required_capability_exception($context, 'local/tm_course:batchenrol', 'nopermissions', '');
}

$type = optional_param('type', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
if (!in_array($type, ['batch', 'custom'], true) || $id <= 0) {
    throw new moodle_exception('invalidparameter');
}

$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_url(new moodle_url('/local/tm_course/reservation/tracking_detail.php', ['type' => $type, 'id' => $id]));
// 防止瀏覽器 / 反向代理把追蹤頁快取，避免管理員剛改完審核結果、業務這邊還在看舊狀態（spec.md §45）。
@header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@header('Pragma: no-cache');
$PAGE->set_title('Application detail');
$PAGE->set_heading('Application detail');
$PAGE->requires->css('/local/tm_course/styles.css');
$str = function(string $key, string $fallback): string {
    $sm = get_string_manager();
    if ($sm->string_exists($key, 'local_tm_course')) {
        return get_string($key, 'local_tm_course');
    }
    return $fallback;
};

$statuslabels = [
    session_manager::ENROL_PENDING => get_string('enrol_pending', 'local_tm_course'),
    session_manager::ENROL_APPROVED => get_string('enrol_approved', 'local_tm_course'),
    session_manager::ENROL_REJECTED => get_string('enrol_rejected', 'local_tm_course'),
];
$resvstatuslabels = [
    0 => get_string('enrol_pending', 'local_tm_course'),
    1 => get_string('enrol_approved', 'local_tm_course'),
    2 => get_string('enrol_rejected', 'local_tm_course'),
];

echo $OUTPUT->header();
echo html_writer::start_div('tm-page-header');
echo html_writer::span('', 'tm-logo-dot');
echo html_writer::tag('h2', $str('reservation_tracking_detail_title', 'Application detail'));
echo html_writer::end_div();
echo html_writer::start_div('tm-card');
echo html_writer::start_div('tm-card-body');
echo html_writer::link(new moodle_url('/local/tm_course/reservation/tracking.php'), $str('reservation_tracking_back', 'Back to tracking'), ['class' => 'btn btn-secondary mb-3']);

if ($type === 'batch') {
    $sql = "SELECT s.*, c.fullname
              FROM {local_tm_course_sessions} s
         LEFT JOIN {course} c ON c.id = s.courseid
             WHERE s.id = :sid";
    $session = $DB->get_record_sql($sql, ['sid' => $id], MUST_EXIST);
    $isbatcharchived = (bool)$DB->record_exists_sql(
        "SELECT 1
           FROM {local_tm_course_enrolments}
          WHERE sessionid = :sid
            AND batch_submittedby = :uid
            AND COALESCE(batch_archived, 0) = 1",
        ['sid' => $id, 'uid' => (int)$USER->id]
    );
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_sesskey();
        if ($isbatcharchived) {
            \core\notification::error(get_string('reservation_tracking_archived_locked', 'local_tm_course'));
        } else {
        $action = optional_param('action', '', PARAM_ALPHANUMEXT);
        if ($action === 'tmbatchprofilesync') {
            $enrolid = required_param('enrolid', PARAM_INT);
            $placeholderemail = optional_param('placeholder_email', '', PARAM_EMAIL);
            $placeholderfirstname = optional_param('placeholder_firstname', '', PARAM_TEXT);
            $placeholderlastname = optional_param('placeholder_lastname', '', PARAM_TEXT);
            $placeholderinstitution = optional_param('placeholder_institution', '', PARAM_TEXT);
            $fullname = trim($placeholderfirstname . ' ' . $placeholderlastname);
            try {
                enrolment_manager::update_batch_placeholder_details((int)$enrolid, (int)$USER->id, [
                    'placeholder_name' => $fullname,
                    'placeholder_institution' => $placeholderinstitution,
                    'diet_choice' => optional_param('diet_choice', 'A', PARAM_ALPHA),
                    'special_note' => optional_param('special_note', '', PARAM_TEXT),
                ]);
                $result = enrolment_manager::update_batch_placeholder_email(
                    (int)$enrolid,
                    (int)$USER->id,
                    (string)$placeholderemail,
                    (string)$placeholderfirstname,
                    (string)$placeholderlastname,
                    (string)$placeholderinstitution
                );
                if (!empty($result['linked'])) {
                    if (!empty($result['created'])) {
                        \core\notification::success($str('batch_fill_email_created', '帳號已建立並完成綁定，已發送啟用通知。'));
                    } else {
                        \core\notification::success($str('batch_fill_email_success', '信箱已更新並完成系統綁定。'));
                    }
                } else if (!empty($result['cleared'])) {
                    \core\notification::success($str('batch_fill_email_cleared', '已清除信箱與關聯。'));
                } else if (!empty($result['needprofile'])) {
                    \core\notification::warning($str('batch_fill_email_need_profile', '若 email 尚未註冊，需補齊姓名與機構才能建帳。'));
                } else {
                    \core\notification::warning($str('batch_fill_email_not_registered', '此信箱尚未註冊，已儲存但未綁定帳號。'));
                }
            } catch (\Throwable $ex) {
                \core\notification::error($ex->getMessage());
            }
        } 
        if ($action === 'batch_cancel_enrol') {
            $enrolid = required_param('enrolid', PARAM_INT);
            try {
                enrolment_manager::cancel_by_batch_submitter((int)$enrolid, (int)$USER->id);
                \core\notification::success(get_string('reservation_tracking_cancel_success', 'local_tm_course'));
            } catch (\Throwable $ex) {
                \core\notification::error($ex->getMessage());
            }
        }
        }
    }
    $rows = $DB->get_records_sql(
        "SELECT e.*, u.firstname, u.lastname, u.email,
                lu.firstname AS lu_firstname, lu.lastname AS lu_lastname, lu.email AS lu_email
           FROM {local_tm_course_enrolments} e
           JOIN {user} u ON u.id = e.userid
      LEFT JOIN {user} lu ON lu.id = e.linked_userid AND e.linked_userid > 0
          WHERE e.sessionid = :sid
            AND e.batch_submittedby = :uid
       ORDER BY e.timecreated ASC",
        ['sid' => $id, 'uid' => (int)$USER->id]
    );
    if (!$issiteadmin && empty($rows)) {
        throw new required_capability_exception($context, 'local/tm_course:batchenrol', 'nopermissions', '');
    }
    echo html_writer::tag('h3', $str('reservation_tracking_batch_title', 'Batch enrolment tracking'), ['class' => 'mb-2']);
    if ($isbatcharchived) {
        echo html_writer::div(get_string('reservation_tracking_archived_locked', 'local_tm_course'), 'tm-alert tm-alert-warning');
    }
    echo html_writer::start_tag('ul', ['class' => 'mb-3']);
    echo html_writer::tag('li', s(get_string('session_name', 'local_tm_course')) . ': ' . s((string)$session->name));
    echo html_writer::tag('li', s(get_string('course')) . ': ' . s(format_string((string)($session->fullname ?? ''))));
    echo html_writer::tag('li', s(get_string('label_start', 'local_tm_course')) . ': ' . userdate((int)$session->starttime, get_string('strftimedatetimeshort')));
    echo html_writer::end_tag('ul');
    $missingcount = enrolment_manager::count_missing_batch_profile((int)$session->id, (int)$USER->id);
    if ($missingcount > 0) {
        echo html_writer::div(
            s($str('batch_fill_pending_count', '尚未補資料')) . ': ' . (int)$missingcount,
            'tm-alert tm-alert-warning'
        );
    }
    if (empty($rows)) {
        echo html_writer::div($str('reservation_tracking_batch_empty', 'No batch enrolment submitted by you yet.'), 'tm-alert tm-alert-info');
    } else {
        echo html_writer::div(
            get_string('batch_tracking_followup_where_hint', 'local_tm_course'),
            'tm-alert tm-alert-info small mb-2'
        );
        $isonlinebatch = ((string)($session->delivery_mode ?? '')) === session_manager::DELIVERY_ONLINE;
        $cancelconfirmjs = json_encode(
            get_string('reservation_tracking_cancel_confirm', 'local_tm_course'),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
        echo html_writer::start_div('tm-table-responsive mb-2');
        echo '<table class="tm-table"><thead><tr>'
            . '<th>#</th><th>' . s(get_string('label_learner', 'local_tm_course')) . '</th><th>' . s(get_string('label_email', 'local_tm_course')) . '</th>'
            . '<th>' . s(get_string('batch_tracking_followup_details_col', 'local_tm_course')) . '</th>'
            . '<th>' . s(get_string('reservation_tracking_account_status', 'local_tm_course')) . '</th><th>' . s(get_string('label_status', 'local_tm_course')) . '</th><th>' . s(get_string('label_desk', 'local_tm_course')) . '</th><th>' . s(get_string('reject_reason_label', 'local_tm_course')) . '</th><th>' . s($str('reservation_tracking_reviewed_at', 'Reviewed at')) . '</th>'
            . '<th>' . s(get_string('reservation_tracking_actions', 'local_tm_course')) . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $deskcell = '—';
            if (!$isonlinebatch && (int)$row->status === session_manager::ENROL_APPROVED && !empty($row->desk_number)) {
                $deskcell = get_string('desk_assigned_to', 'local_tm_course', (int)$row->desk_number);
            }
            $fillvalue = trim((string)($row->linked_email ?? ''));
            if ($fillvalue === '' && !enrolment_manager::is_placeholder_holder_email((string)($row->email ?? ''))) {
                $fillvalue = trim((string)($row->email ?? ''));
            }
            $rowstatus = (int) $row->status;
            $cancellable = !$isbatcharchived && in_array($rowstatus, [
                session_manager::ENROL_PENDING,
                session_manager::ENROL_APPROVED,
                session_manager::ENROL_WAITLISTED,
            ], true);
            $cansupplement = $cancellable;
            $linkeduid = (int)($row->linked_userid ?? 0);
            $linkedem = trim((string)($row->linked_email ?? ''));
            $isresupplement = $linkeduid > 0 || $linkedem !== '';
            $trackcells = enrolment_manager::format_attendance_roster_cells($row);
            $showname = $trackcells['displayname'];
            $showemail = $trackcells['email'];

            $detailcell = '—';
            if ((int)($row->placeholder_seq ?? 0) > 0) {
                $detailcell = '<span class="text-muted">' . s(enrolment_manager::format_diet_summary($row)) . '</span>';
            }

            $supplementcell = '';
            if ($cansupplement) {
                $prefirst = trim((string)($row->lu_firstname ?? ''));
                $prelast = trim((string)($row->lu_lastname ?? ''));
                if ($prefirst === '' && $prelast === '') {
                    $pn = trim((string)($row->placeholder_name ?? ''));
                    $parts = preg_split('/\s+/', $pn);
                    $prefirst = trim((string)($parts[0] ?? ''));
                    $prelast = trim((string)implode(' ', array_slice($parts ?: [], 1)));
                }
                $dc = strtoupper(trim((string)($row->diet_choice ?? 'A')));
                if ($dc !== 'A' && $dc !== 'B') {
                    $dc = 'A';
                }
                $special = (string)($row->diet_meat_other ?? $row->diet_vegetarian_notes ?? '');
                $summlabel = get_string(
                    $isresupplement ? 'reservation_tracking_resupplement' : 'reservation_tracking_supplement',
                    'local_tm_course'
                );
                $closelabel = get_string('reservation_tracking_followup_collapse', 'local_tm_course');
                $supplementcell = '<details class="tm-batch-followup-details tm-batch-followup-in-actions">'
                    . '<summary class="btn btn-sm btn-outline-secondary tm-followup-summary" data-open-label="' . s($summlabel) . '" data-close-label="' . s($closelabel) . '">' . s($summlabel) . '</summary>'
                    . '<form method="post" class="tm-batch-followup-form small d-flex flex-wrap align-items-end mt-2 p-2 border rounded bg-white" style="gap:.35rem;min-width:18rem;max-width:46rem">'
                    . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
                    . '<input type="hidden" name="action" value="tmbatchprofilesync">'
                    . '<input type="hidden" name="enrolid" value="' . (int)$row->id . '">'
                    . '<input type="text" name="placeholder_firstname" class="form-control form-control-sm" maxlength="100" style="width:8rem" value="' . s($prefirst) . '" placeholder="' . s(get_string('batch_firstname', 'local_tm_course')) . '">'
                    . '<input type="text" name="placeholder_lastname" class="form-control form-control-sm" maxlength="100" style="width:8rem" value="' . s($prelast) . '" placeholder="' . s(get_string('batch_lastname', 'local_tm_course')) . '">'
                    . '<input type="text" name="placeholder_institution" class="form-control form-control-sm" maxlength="255" style="width:10rem" value="' . s((string)($row->seat_company ?? $row->institution ?? '')) . '" placeholder="' . s(get_string('institution', 'local_tm_course')) . '">'
                    . '<input type="email" name="placeholder_email" class="form-control form-control-sm" maxlength="255" style="width:12rem" value="' . s($fillvalue) . '" placeholder="user@example.com">'
                    . '<select name="diet_choice" class="form-control form-control-sm" style="width:8rem">'
                    . '<option value="A"' . ($dc === 'A' ? ' selected' : '') . '>' . s(get_string('diet_choice_meat', 'local_tm_course')) . '</option>'
                    . '<option value="B"' . ($dc === 'B' ? ' selected' : '') . '>' . s(get_string('diet_choice_vegetarian', 'local_tm_course')) . '</option>'
                    . '</select>'
                    . '<input type="text" name="special_note" class="form-control form-control-sm" maxlength="255" style="width:12rem" value="' . s($special) . '" placeholder="' . s(get_string('diet_special_note', 'local_tm_course')) . '">'
                    . '<button type="submit" class="btn btn-sm btn-tm-primary">' . s($str('batch_fill_email_save', '儲存')) . '</button>'
                    . '</form>'
                    . '</details>';
            }

            $cancelcell = '—';
            if ((int)$row->status === session_manager::ENROL_CANCELLED) {
                $accountstatus = get_string('enrol_cancelled', 'local_tm_course');
            } else {
                $accountstatus = get_string('reservation_tracking_account_pending', 'local_tm_course');
            }
            if (!empty($row->linked_userid)) {
                $accountstatus = get_string('reservation_tracking_account_linked', 'local_tm_course');
            } else if ((int)$row->status !== session_manager::ENROL_CANCELLED && !empty($row->linked_email)) {
                $accountstatus = get_string('reservation_tracking_account_email_saved', 'local_tm_course');
            }
            $needfollowuphighlight = $cansupplement && !$isresupplement;
            $rowclass = $needfollowuphighlight ? ' class="tm-row-followup-needed"' : '';
            if ($cancellable) {
                $onclickbody = 'if(!confirm(' . $cancelconfirmjs . ')){return false;}this.closest("form").submit();return false;';
                $cancelcell = '<form method="post" class="d-inline" style="display:inline!important">'
                    . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
                    . '<input type="hidden" name="action" value="batch_cancel_enrol">'
                    . '<input type="hidden" name="enrolid" value="' . (int) $row->id . '">'
                    . '<button type="button" class="btn btn-sm btn-outline-danger" onclick=' . "'" . $onclickbody . "'" . '>'
                    . s(get_string('reservation_tracking_cancel_enrol', 'local_tm_course'))
                    . '</button>'
                    . '</form>';
            }
            $actioncell = '<div class="d-flex flex-wrap align-items-center gap-1">' . $supplementcell . $cancelcell . '</div>';
            echo '<tr' . $rowclass . '>'
                . '<td>' . (int)$row->id . '</td>'
                . '<td>' . s($showname) . '</td>'
                . '<td>' . s($showemail) . '</td>'
                . '<td>' . $detailcell . '</td>'
                . '<td>' . s($accountstatus) . '</td>'
                . '<td>' . s($statuslabels[(int)$row->status] ?? '—') . '</td>'
                . '<td>' . s($deskcell) . '</td>'
                . '<td>' . s((string)($row->notes ?? '')) . '</td>'
                . '<td>' . userdate((int)$row->timemodified, get_string('strftimedatetimeshort')) . '</td>'
                . '<td>' . $actioncell . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
        echo html_writer::end_div();

        $followcfg = [
            'lookupUrl' => (new moodle_url('/local/tm_course/batch_lookup.php'))->out(false),
            'sesskey' => sesskey(),
            'str' => [
                'batch_followup_confirm_clear_body' => get_string('batch_followup_confirm_clear_body', 'local_tm_course'),
                'batch_lookup_loading' => get_string('batch_lookup_loading', 'local_tm_course'),
                'batch_followup_lookup_failed' => get_string('batch_followup_lookup_failed', 'local_tm_course'),
                'error_batch_diet_required' => get_string('error_batch_diet_required', 'local_tm_course'),
                'error_batch_name_required' => get_string('error_batch_name_required', 'local_tm_course'),
                'error_institution_required' => get_string('error_institution_required', 'local_tm_course'),
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
            ],
        ];
        echo '<div id="tm-followup-confirm-modal" class="tm-cancel-modal-backdrop" style="display:none" aria-hidden="true">'
            . '<div class="tm-cancel-modal-panel tm-mode-modal-panel" role="dialog" aria-modal="true">'
            . '<h4 class="mb-2">' . s(get_string('batch_followup_confirm_title', 'local_tm_course')) . '</h4>'
            . '<div id="tm-followup-confirm-body" class="mb-3" style="max-height:22rem;overflow:auto"></div>'
            . '<div class="d-flex gap-2">'
            . '<button type="button" class="btn tm-enrol-btn" id="tm-followup-confirm-ok">' . s(get_string('batch_confirm_submit', 'local_tm_course')) . '</button>'
            . '<button type="button" class="btn btn-secondary" id="tm-followup-confirm-close">' . s(get_string('cancel')) . '</button>'
            . '</div></div></div>';
        echo html_writer::script(
            'window.tmTrackingFollowupCfg=' . json_encode($followcfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';'
        );
        $fbjspath = __DIR__ . '/tracking_batch_followup.js';
        $fbjsver = file_exists($fbjspath) ? (int)filemtime($fbjspath) : time();
        echo html_writer::tag('script', '', [
            'type' => 'text/javascript',
            'src' => (new moodle_url('/local/tm_course/reservation/tracking_batch_followup.js', ['v' => $fbjsver]))->out(),
        ]);
    }
} else {
    $reservation = $DB->get_record('local_tm_course_reservation', ['id' => $id], '*', MUST_EXIST);
    if (!$issiteadmin && (int)$reservation->requesterid !== (int)$USER->id) {
        throw new required_capability_exception($context, 'local/tm_course:batchenrol', 'nopermissions', '');
    }
    $iscustomarchived = !empty($reservation->archived);
    $reuploadsaved = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_sesskey();
        if ($iscustomarchived) {
            \core\notification::error(get_string('reservation_tracking_archived_locked', 'local_tm_course'));
        } else {
        $action = optional_param('action', '', PARAM_ALPHANUMEXT);
        if ($action === 'reupload_verification') {
            $questionid = optional_param('questionid', 0, PARAM_INT);
            $field = 'replace_qfile_' . $questionid;
            if ($questionid > 0 && !empty($_FILES[$field]) && is_array($_FILES[$field])) {
                \local_tm_course\verification_manager::save_question_upload((int)$reservation->id, $questionid, $_FILES[$field], $context);
                $reuploadsaved = true;
            }
        }
        }
    }
    if (!empty($reuploadsaved)) {
        \core\notification::success($str('reservation_verification_reupload_saved', '檢附檔案已更新，審核狀態已重置為待審核。'));
    }
    $courseids = [];
    if (!empty($reservation->courseids_json)) {
        $decoded = json_decode((string)$reservation->courseids_json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $cid) {
                $cid = (int)$cid;
                if ($cid > 0) { $courseids[] = $cid; }
            }
        }
    }
    if (empty($courseids) && (int)$reservation->courseid > 0) {
        $courseids[] = (int)$reservation->courseid;
    }
    $coursenames = [];
    if (!empty($courseids)) {
        list($insql, $inparams) = $DB->get_in_or_equal(array_values(array_unique($courseids)), SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $insql", $inparams);
        foreach ($rows as $row) {
            $coursenames[] = format_string((string)$row->fullname);
        }
    }
    $classrooms = $DB->get_records('local_tm_classroom', [], '', 'id,name,location');
    $plan = json_decode((string)($reservation->calendar_plan_json ?? ''), true);
    if (!is_array($plan)) {
        $plan = [];
    }
    usort($plan, function($a, $b) {
        return ((int)($a['start'] ?? 0)) <=> ((int)($b['start'] ?? 0));
    });
    $createdsessions = $DB->get_records(
        'local_tm_course_sessions',
        ['source_reservation_id' => (int)$reservation->id],
        'starttime ASC',
        'id, name, starttime, endtime'
    );
    $questions = \local_tm_course\verification_manager::get_questions_for_courses($courseids, (string)$reservation->delivery_mode);
    $filelinks = \local_tm_course\verification_manager::get_reservation_file_links((int)$reservation->id);
    $progress = \local_tm_course\verification_manager::get_reservation_progress_summary((int)$reservation->id, $questions, $filelinks);
    $filebyqid = [];
    foreach ($filelinks as $fl) {
        $filebyqid[(int)$fl->questionid] = $fl;
    }
    $fs = get_file_storage();
    $isinlineimage = function(\stored_file $file): bool {
        $mime = (string)$file->get_mimetype();
        if (strpos($mime, 'image/') === 0) {
            return true;
        }
        return (bool)preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', strtolower($file->get_filename()));
    };
    echo html_writer::tag('h3', $str('reservation_tracking_custom_title', 'Dedicated class application tracking'), ['class' => 'mb-2']);
    if ($iscustomarchived) {
        echo html_writer::div(get_string('reservation_tracking_archived_locked', 'local_tm_course'), 'tm-alert tm-alert-warning');
    }
    echo html_writer::start_tag('ul', ['class' => 'mb-3']);
    echo html_writer::tag('li', 'ID: ' . (int)$reservation->id);
    echo html_writer::tag('li', s($str('reservation_review_courses', 'Courses')) . ': ' . s(implode(' / ', $coursenames)));
    echo html_writer::tag('li', s(get_string('reservation_field_delivery_mode', 'local_tm_course')) . ': ' . s((string)$reservation->delivery_mode));
    echo html_writer::tag('li', s(get_string('reservation_calendar_language', 'local_tm_course')) . ': ' . s((string)$reservation->teaching_language));
    echo html_writer::tag('li', s(get_string('label_status', 'local_tm_course')) . ': ' . s($resvstatuslabels[(int)$reservation->status] ?? '—'));
    echo html_writer::tag('li', s($str('reservation_review_last_calendar_update', 'Last calendar update')) . ': ' . userdate((int)$reservation->timemodified, get_string('strftimedatetimeshort')));
    $shownote = trim((string)($reservation->manager_note ?? ''));
    if ($shownote === '') {
        $shownote = '—';
    }
    echo html_writer::tag('li', s($str('reservation_tracking_manager_note', 'Review note')) . ': ' . s($shownote));
    echo html_writer::end_tag('ul');

    $progresslabel = get_string('reservation_tracking_verification_na', 'local_tm_course');
    $progresscls = 'closed';
    if ($progress['status'] === 'not_started') {
        $progresslabel = get_string('reservation_tracking_verification_not_started', 'local_tm_course');
    } else if ($progress['status'] === 'in_progress') {
        $progresslabel = get_string('reservation_tracking_verification_in_progress', 'local_tm_course');
        $progresscls = 'pending';
    } else if ($progress['status'] === 'complete') {
        $progresslabel = get_string('reservation_tracking_verification_complete', 'local_tm_course');
        $progresscls = 'approved';
    }
    if ((int)$progress['total'] > 0) {
        $progresslabel .= ' (' . (int)$progress['uploaded'] . '/' . (int)$progress['total'] . ')';
    }
    $rangestart = 0;
    foreach ($plan as $blk) {
        $bs = (int)($blk['start'] ?? 0);
        if ($bs > 0 && ($rangestart === 0 || $bs < $rangestart)) {
            $rangestart = $bs;
        }
    }
    $risktext = get_string('reservation_tracking_verification_reminder', 'local_tm_course');
    if ($rangestart > 0 && (int)$progress['total'] > 0 && !$progress['complete']) {
        $daystoleft = (int)floor(($rangestart - time()) / DAYSECS);
        if ($daystoleft <= 7) {
            $risktext = get_string('reservation_tracking_verification_risk_notice', 'local_tm_course');
        }
    }
    echo html_writer::div($risktext, 'tm-alert tm-alert-warning');
    echo html_writer::div(
        '<span class="tm-badge tm-badge-' . s($progresscls) . '">' . s($progresslabel) . '</span>',
        'mb-3'
    );

    echo html_writer::tag('h4', $str('reservation_tracking_plan_blocks', 'Planned blocks'), ['class' => 'mb-2']);
    if (empty($plan)) {
        echo html_writer::div('—', 'tm-alert tm-alert-info');
    } else {
        $classroomlabel = $str('classroom', 'Classroom');
        $endlabel = $str('label_end', 'End');
        echo html_writer::start_div('tm-table-responsive mb-2');
        echo '<table class="tm-table"><thead><tr>'
            . '<th>#</th><th>' . s($str('reservation_review_courses', 'Courses')) . '</th><th>' . s($classroomlabel) . '</th><th>' . s(get_string('label_start', 'local_tm_course')) . '</th><th>' . s($endlabel) . '</th>'
            . '</tr></thead><tbody>';
        foreach ($plan as $idx => $blk) {
            $cid = (int)($blk['courseId'] ?? 0);
            $roomid = (int)($blk['classroomId'] ?? 0);
            $roomlabel = '';
            if (!empty($classrooms[$roomid])) {
                $roomlabel = trim((string)$classrooms[$roomid]->name . ((string)$classrooms[$roomid]->location !== '' ? (' — ' . (string)$classrooms[$roomid]->location) : ''));
            }
            $start = (int)($blk['start'] ?? 0);
            $end = (int)($blk['end'] ?? 0);
            echo '<tr>'
                . '<td>' . ($idx + 1) . '</td>'
                . '<td>#' . $cid . '</td>'
                . '<td>' . s($roomlabel) . '</td>'
                . '<td>' . ($start > 0 ? userdate($start, get_string('strftimedatetimeshort')) : '—') . '</td>'
                . '<td>' . ($end > 0 ? userdate($end, get_string('strftimedatetimeshort')) : '—') . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
        echo html_writer::end_div();
    }

    echo html_writer::tag('h4', $str('reservation_tracking_created_sessions', 'Created sessions after approval'), ['class' => 'mb-2 mt-3']);
    if (empty($createdsessions)) {
        echo html_writer::div('—', 'tm-alert tm-alert-info');
    } else {
        $endlabel = $str('label_end', 'End');
        echo html_writer::start_div('tm-table-responsive mb-2');
        echo '<table class="tm-table"><thead><tr><th>#</th><th>' . s(get_string('session_name', 'local_tm_course')) . '</th><th>' . s(get_string('label_start', 'local_tm_course')) . '</th><th>' . s($endlabel) . '</th></tr></thead><tbody>';
        foreach ($createdsessions as $s) {
            echo '<tr>'
                . '<td>' . (int)$s->id . '</td>'
                . '<td>' . s((string)$s->name) . '</td>'
                . '<td>' . userdate((int)$s->starttime, get_string('strftimedatetimeshort')) . '</td>'
                . '<td>' . userdate((int)$s->endtime, get_string('strftimedatetimeshort')) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
        echo html_writer::end_div();
    }

    echo html_writer::tag('h4', $str('reservation_tracking_verification_title', '課前資料檢核結果'), ['class' => 'mb-2 mt-3']);
    if (empty($questions)) {
        echo html_writer::div('—', 'tm-alert tm-alert-info');
    } else {
        echo html_writer::start_div('tm-table-responsive mb-2');
        echo '<table class="tm-table"><thead><tr>'
            . '<th>#</th>'
            . '<th>' . s($str('reservation_tracking_verification_question', '檢核題目')) . '</th>'
            . '<th>' . s($str('reservation_tracking_verification_file', '目前檔案')) . '</th>'
            . '<th>' . s($str('reservation_tracking_verification_status', '審核狀態')) . '</th>'
            . '<th>' . s($str('reservation_tracking_verification_action', '重傳')) . '</th>'
            . '</tr></thead><tbody>';
        $idx = 0;
        foreach ($questions as $q) {
            $idx++;
            $qid = (int)$q->id;
            $link = $filebyqid[$qid] ?? null;
            $itemid = $link ? (int)$link->itemid : 0;
            $reviewstatus = $link ? (int)($link->review_status ?? 0) : 0;
            $reviewedts = $link ? (int)($link->timereviewed ?? 0) : 0;
            $statuslabel = $str('reservation_tracking_verification_pending', '待審核');
            if ($reviewstatus === \local_tm_course\verification_manager::REVIEW_PASSED) {
                $statuslabel = $str('reservation_tracking_verification_passed', '通過');
            } else if ($reviewstatus === \local_tm_course\verification_manager::REVIEW_FAILED) {
                $statuslabel = $str('reservation_tracking_verification_failed', '未通過');
            }
            // 已審狀態加註「審核於 ...」時間戳，方便業務驗證審核確實有發生（spec.md §45）。
            $statushtml = s($statuslabel);
            if ($reviewstatus !== \local_tm_course\verification_manager::REVIEW_PENDING && $reviewedts > 0) {
                $reviewedat = userdate($reviewedts, get_string('strftimedatetimeshort'));
                $statushtml .= '<div class="small text-muted mt-1">'
                    . s($str('reservation_tracking_verification_reviewed_at', '審核於')) . ' ' . s($reviewedat)
                    . '</div>';
            }
            $filecell = '—';
            if ($itemid > 0) {
                $stored = $fs->get_area_files($context->id, 'local_tm_course', \local_tm_course\verification_manager::FILEAREA, $itemid, 'filename ASC', false);
                foreach ($stored as $sf) {
                    if ($sf->is_directory()) {
                        continue;
                    }
                    $url = moodle_url::make_pluginfile_url(
                        $context->id,
                        'local_tm_course',
                        \local_tm_course\verification_manager::FILEAREA,
                        $itemid,
                        $sf->get_filepath(),
                        $sf->get_filename()
                    );
                    if ($isinlineimage($sf)) {
                        $url->param('forcedownload', 0);
                        $filecell = html_writer::empty_tag('img', [
                            'src' => $url->out(false),
                            'alt' => s($sf->get_filename()),
                            'style' => 'max-width:220px;height:auto;',
                        ]);
                    } else {
                        $url->param('forcedownload', 1);
                        $filecell = html_writer::link($url->out(false), s($sf->get_filename()), ['target' => '_blank', 'rel' => 'noopener']);
                    }
                    break;
                }
            }
            $reuploadcell = '';
            if (!$iscustomarchived) {
                $reuploadcell .= '<form method="post" enctype="multipart/form-data" style="display:flex;gap:.5rem;align-items:center;">';
                $reuploadcell .= '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
                $reuploadcell .= '<input type="hidden" name="action" value="reupload_verification">';
                $reuploadcell .= '<input type="hidden" name="questionid" value="' . $qid . '">';
                $reuploadcell .= '<input type="file" name="replace_qfile_' . $qid . '" class="form-control-file" required>';
                $reuploadcell .= '<button type="submit" class="btn btn-sm btn-secondary">' . s($str('reservation_tracking_verification_reupload', '重新上傳')) . '</button>';
                $reuploadcell .= '</form>';
            } else {
                $reuploadcell = s($str('reservation_tracking_verification_locked', '已核准後鎖定'));
            }
            echo '<tr>'
                . '<td>' . $idx . '</td>'
                . '<td>' . s((string)$q->question_text) . '</td>'
                . '<td>' . $filecell . '</td>'
                . '<td>' . $statushtml . '</td>'
                . '<td>' . $reuploadcell . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::script("(function(){var details=document.querySelectorAll('.tm-batch-followup-details');for(var i=0;i<details.length;i++){(function(d){var s=d.querySelector('.tm-followup-summary');if(!s){return;}var openLabel=s.getAttribute('data-open-label')||s.textContent;var closeLabel=s.getAttribute('data-close-label')||openLabel;var sync=function(){s.textContent=d.open?closeLabel:openLabel;};d.addEventListener('toggle',sync);sync();})(details[i]);}})();");
echo $OUTPUT->footer();
