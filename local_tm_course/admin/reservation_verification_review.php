<?php
/**
 * Admin: review all pre-course verification attachments for one reservation.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/verification_manager.php');

require_login();
require_capability('local/tm_course:approve', context_system::instance());

global $DB, $OUTPUT, $PAGE, $USER;

$id = required_param('id', PARAM_INT);
$reservation = $DB->get_record('local_tm_course_reservation', ['id' => $id], '*', MUST_EXIST);
$str = function(string $key, string $fallback, $a = null): string {
    $sm = get_string_manager();
    if ($sm->string_exists($key, 'local_tm_course')) {
        return get_string($key, 'local_tm_course', $a);
    }
    return $fallback;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    $questionid = optional_param('questionid', 0, PARAM_INT);
    if ($questionid > 0 && in_array($action, ['mark_pass', 'mark_fail'], true)) {
        $status = ($action === 'mark_pass')
            ? \local_tm_course\verification_manager::REVIEW_PASSED
            : \local_tm_course\verification_manager::REVIEW_FAILED;
        // 先確認 vq_file 紀錄真的存在再呼叫 review_uploaded_file（後者在缺紀錄時會 silently return）。
        // 同時把「哪題、改成什麼」回拋給管理員，避免事後無從比對。
        $exists = $DB->record_exists('local_tm_course_vq_file', [
            'reservationid' => (int)$reservation->id,
            'questionid' => $questionid,
        ]);
        $qtext = (string)$DB->get_field('local_tm_course_vq_q', 'question_text', ['id' => $questionid]);
        if (!$exists) {
            // 題目沒有任何上傳檔，本來就不該出現 Pass/Fail 按鈕（會走到這裡通常是並行操作或誤觸）。
            redirect(
                new moodle_url('/local/tm_course/admin/reservation_verification_review.php', ['id' => (int)$reservation->id]),
                $str('reservation_verification_review_save_no_file', 'No uploaded file for this question yet; review status unchanged: ') . ($qtext !== '' ? $qtext : ('#' . $questionid)),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        \local_tm_course\verification_manager::review_uploaded_file((int)$reservation->id, $questionid, $status, (int)$USER->id);
        $statuslabel = ($status === \local_tm_course\verification_manager::REVIEW_PASSED)
            ? $str('reservation_verification_review_status_passed', 'Passed')
            : $str('reservation_verification_review_status_failed', 'Failed');
        $detailmsg = $str(
            'reservation_verification_review_saved_detail',
            sprintf('"%s" marked as %s.', ($qtext !== '' ? $qtext : ('#' . $questionid)), $statuslabel),
            (object)[
                'question' => ($qtext !== '' ? $qtext : ('#' . $questionid)),
                'status' => $statuslabel,
            ]
        );
        redirect(
            new moodle_url('/local/tm_course/admin/reservation_verification_review.php', ['id' => (int)$reservation->id]),
            $detailmsg,
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/tm_course/admin/reservation_verification_review.php', ['id' => $id]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title($str('reservation_verification_review_title', 'Dedicated class: pre-course verification'));
$PAGE->set_heading($str('reservation_verification_review_title', 'Dedicated class: pre-course verification'));
$PAGE->requires->css('/local/tm_course/styles.css');

$courseids = \local_tm_course\verification_manager::get_reservation_course_ids($reservation);
$coursenames = [];
if (!empty($courseids)) {
    list($cinsql, $cinparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
    $crows = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id $cinsql", $cinparams);
    foreach ($crows as $c) {
        $coursenames[(int)$c->id] = format_string((string)$c->fullname);
    }
}

$fs = get_file_storage();
$filearea = \local_tm_course\verification_manager::FILEAREA;

$isinlineimage = function(\stored_file $file): bool {
    $mime = (string)$file->get_mimetype();
    if (strpos($mime, 'image/') === 0) {
        return true;
    }
    $name = strtolower($file->get_filename());
    return (bool)preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', $name);
};

$makeurl = function(int $itemid, \stored_file $file, bool $forcedownload) use ($context, $filearea): string {
    $url = moodle_url::make_pluginfile_url(
        $context->id,
        'local_tm_course',
        $filearea,
        $itemid,
        $file->get_filepath(),
        $file->get_filename()
    );
    $url->param('forcedownload', $forcedownload ? 1 : 0);
    return $url->out(false);
};

// 以「題目清單」為主體重構（spec.md §45）：
// 缺檔的題目也要在審核頁顯示一列「尚未上傳」，讓管理員可一眼判斷業務還缺哪些資料，
// 不能只依賴 local_tm_course_vq_file 反查，否則沒上傳的項目永遠不會浮現。
$questions = \local_tm_course\verification_manager::get_questions_for_courses(
    $courseids,
    (string)$reservation->delivery_mode
);

// 先建立 questionid => filelink 紀錄的查表（含 review_status / itemid），稍後再把實體檔掛上去。
$filelinkbyqid = [];
$rs = $DB->get_recordset(
    'local_tm_course_vq_file',
    ['reservationid' => (int)$reservation->id],
    'id ASC',
    'id, questionid, itemid, review_status, timereviewed'
);
foreach ($rs as $row) {
    $filelinkbyqid[(int)$row->questionid] = $row;
}
$rs->close();

$bycourse = [];
foreach ($questions as $q) {
    $cid = (int)($q->courseid ?? 0);
    $qid = (int)$q->id;
    $entry = [
        'questionid' => $qid,
        'question' => (string)$q->question_text,
        'is_required' => ((int)($q->is_required ?? 0) === 1),
        'sortorder' => (int)($q->sortorder ?? 0),
        'review_status' => 0,
        'timereviewed' => 0,
        'itemid' => 0,
        'files' => [],
        'has_files' => false,
    ];
    if (!empty($filelinkbyqid[$qid])) {
        $link = $filelinkbyqid[$qid];
        $itemid = (int)$link->itemid;
        $entry['itemid'] = $itemid;
        $entry['review_status'] = (int)($link->review_status ?? 0);
        $entry['timereviewed'] = (int)($link->timereviewed ?? 0);
        if ($itemid > 0) {
            $files = $fs->get_area_files(
                $context->id,
                'local_tm_course',
                $filearea,
                $itemid,
                'filename ASC',
                false
            );
            foreach ($files as $sf) {
                if ($sf->is_directory()) {
                    continue;
                }
                $entry['files'][] = $sf;
            }
            $entry['has_files'] = !empty($entry['files']);
        }
    }
    if (empty($bycourse[$cid])) {
        $bycourse[$cid] = [];
    }
    $bycourse[$cid][] = $entry;
}

$flatitems = [];
foreach ($bycourse as $cid => $items) {
    foreach ($items as $entry) {
        $flatitems[] = [
            'courseid' => (int)$cid,
            'course' => $coursenames[(int)$cid] ?? ('Course #' . (int)$cid),
            'questionid' => (int)$entry['questionid'],
            'question' => (string)$entry['question'],
            'is_required' => !empty($entry['is_required']),
            'has_files' => !empty($entry['has_files']),
            'review_status' => (int)($entry['review_status'] ?? 0),
        ];
    }
}

// 進度統計：總題數、已上傳題數，以及必填的已/總題數，呈現給管理員快速判斷補件完成度。
$progress = [
    'total' => count($flatitems),
    'uploaded' => 0,
    'reqtotal' => 0,
    'reqdone' => 0,
];
foreach ($flatitems as $it) {
    if (!empty($it['has_files'])) {
        $progress['uploaded']++;
    }
    if (!empty($it['is_required'])) {
        $progress['reqtotal']++;
        if (!empty($it['has_files'])) {
            $progress['reqdone']++;
        }
    }
}

echo $OUTPUT->header();

echo html_writer::start_div('tm-page-header');
echo html_writer::span('', 'tm-logo-dot');
echo html_writer::tag('h2', $str('reservation_verification_review_title', 'Dedicated class: pre-course verification'));
echo html_writer::end_div();

echo html_writer::div(
    $str('reservation_verification_review_intro', 'Request #' . (int)$id . ': attachments uploaded by the applicant (grouped by course). Images preview inline; Word/PDF and other files use download.', (object)['id' => $id]),
    'tm-alert tm-alert-info mb-3'
);

echo html_writer::link(
    new moodle_url('/local/tm_course/admin/review_center.php'),
    get_string('back', 'moodle'),
    ['class' => 'btn btn-secondary mb-3']
);

if (empty($flatitems)) {
    echo html_writer::div($str('reservation_verification_review_none', 'No stored attachments for this request yet.'), 'tm-alert tm-alert-warning');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_div('tm-card mb-3');
echo html_writer::start_div('tm-card-body');
echo html_writer::tag('h4', $str('reservation_verification_review_summary_title', 'Verification summary'), ['class' => 'mb-2']);

// 進度條：上傳 / 總題數、必填上傳 / 必填總數，讓管理員一眼看出補件狀態。
$progresstext = $str(
    'reservation_verification_review_summary_progress',
    'Uploaded: ' . (int)$progress['uploaded'] . '/' . (int)$progress['total']
        . ' (required ' . (int)$progress['reqdone'] . '/' . (int)$progress['reqtotal'] . ')',
    (object)[
        'uploaded' => (int)$progress['uploaded'],
        'total' => (int)$progress['total'],
        'reqdone' => (int)$progress['reqdone'],
        'reqtotal' => (int)$progress['reqtotal'],
    ]
);
$progressclass = ($progress['reqtotal'] > 0 && $progress['reqdone'] < $progress['reqtotal'])
    ? 'tm-alert tm-alert-warning mb-2'
    : 'tm-alert tm-alert-info mb-2';
echo html_writer::div($progresstext, $progressclass);

echo '<table class="tm-table"><thead><tr>'
    . '<th>#</th>'
    . '<th>' . s($str('reservation_verification_review_summary_course', 'Course')) . '</th>'
    . '<th>' . s($str('reservation_verification_review_summary_question', 'Question')) . '</th>'
    . '<th>' . s($str('reservation_verification_review_summary_status', 'Review status')) . '</th>'
    . '</tr></thead><tbody>';
$si = 0;
foreach ($flatitems as $it) {
    $si++;
    if (empty($it['has_files'])) {
        $statuslabel = $str('reservation_verification_review_status_missing', 'Not uploaded yet');
    } else if ((int)$it['review_status'] === \local_tm_course\verification_manager::REVIEW_PASSED) {
        $statuslabel = $str('reservation_verification_review_status_passed', 'Passed');
    } else if ((int)$it['review_status'] === \local_tm_course\verification_manager::REVIEW_FAILED) {
        $statuslabel = $str('reservation_verification_review_status_failed', 'Failed');
    } else {
        $statuslabel = $str('reservation_verification_review_status_pending', 'Pending review');
    }
    $requiredtag = !empty($it['is_required'])
        ? ' (' . s($str('reservation_verification_review_required_label', 'Required')) . ')'
        : '';
    echo '<tr>'
        . '<td>' . $si . '</td>'
        . '<td>' . s((string)$it['course']) . '</td>'
        . '<td>' . s((string)$it['question']) . $requiredtag . '</td>'
        . '<td>' . s($statuslabel) . '</td>'
        . '</tr>';
}
echo '</tbody></table>';
echo html_writer::end_div();
echo html_writer::end_div();

foreach ($bycourse as $cid => $items) {
    $title = $coursenames[$cid] ?? ('Course #' . (int)$cid);
    echo html_writer::start_div('tm-card mb-3');
    echo html_writer::start_div('tm-card-body');
    echo html_writer::tag(
        'h4',
        $str('reservation_verification_review_course_heading', 'Course: ' . $title, (object)['name' => $title]),
        ['class' => 'mb-3']
    );
    foreach ($items as $entry) {
        $itemid = (int)$entry['itemid'];
        $questionid = (int)$entry['questionid'];
        $reviewstatus = (int)($entry['review_status'] ?? 0);
        $qtext = (string)$entry['question'];
        $hasfiles = !empty($entry['has_files']);
        $isrequired = !empty($entry['is_required']);

        echo html_writer::start_div('mb-4 pb-3 border-bottom');
        $labelhtml = s($qtext);
        if ($isrequired) {
            $labelhtml .= ' ' . html_writer::tag(
                'span',
                $str('reservation_verification_review_required_label', 'Required'),
                ['class' => 'badge badge-info']
            );
        }
        echo html_writer::tag('div', $labelhtml, ['class' => 'font-weight-bold mb-2']);

        if (!$hasfiles) {
            // 業務尚未上傳此題附件，整列以警示樣式呈現，不渲染 Pass/Fail 按鈕（沒檔案可審）。
            echo html_writer::div(
                $str('reservation_verification_review_status_missing', 'Not uploaded yet'),
                'badge badge-warning mb-2'
            );
            echo html_writer::div(
                $str('reservation_verification_review_missing_hint', 'The applicant has not uploaded a file for this question yet. Mark / Pass / Fail will appear after upload.'),
                'tm-alert tm-alert-warning mb-0'
            );
            echo html_writer::end_div();
            continue;
        }

        if ($reviewstatus === \local_tm_course\verification_manager::REVIEW_PASSED) {
            echo html_writer::div($str('reservation_verification_review_status_passed', 'Passed'), 'badge badge-success mb-2');
        } else if ($reviewstatus === \local_tm_course\verification_manager::REVIEW_FAILED) {
            echo html_writer::div($str('reservation_verification_review_status_failed', 'Failed'), 'badge badge-danger mb-2');
        } else {
            echo html_writer::div($str('reservation_verification_review_status_pending', 'Pending review'), 'badge badge-secondary mb-2');
        }

        foreach ($entry['files'] as $sf) {
            /** @var \stored_file $sf */
            $fname = $sf->get_filename();
            if ($isinlineimage($sf)) {
                $src = $makeurl($itemid, $sf, false);
                echo html_writer::link(
                    $src,
                    html_writer::empty_tag('img', [
                        'src' => $src,
                        'alt' => s($fname),
                        'class' => 'rounded border',
                        'style' => 'max-width:180px;max-height:120px;width:auto;height:auto;object-fit:cover;',
                    ]),
                    ['target' => '_blank', 'rel' => 'noopener', 'title' => $str('reservation_verification_review_open_image_tab', 'Open image in new tab')]
                );
                echo html_writer::div($str('reservation_verification_review_thumbnail_hint', 'Click thumbnail to open original image in a new tab'), 'small text-muted mt-1');
            } else {
                $dl = $makeurl($itemid, $sf, true);
                echo html_writer::div(
                    html_writer::link($dl, s($fname), [
                        'class' => 'btn btn-sm btn-outline-primary',
                        'target' => '_blank',
                        'rel' => 'noopener',
                    ]) . ' ' .
                    html_writer::span(
                        '(' . $str('reservation_verification_review_download_hint', 'Download file') . ')',
                        'small text-muted'
                    ),
                    'mt-1'
                );
            }
        }

        echo html_writer::start_div('mt-2 d-flex');
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mr-2']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'questionid', 'value' => $questionid]);
        echo html_writer::tag('button', $str('reservation_verification_review_mark_pass', 'Pass'), ['type' => 'submit', 'name' => 'action', 'value' => 'mark_pass', 'class' => 'btn btn-sm btn-success']);
        echo html_writer::end_tag('form');
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'questionid', 'value' => $questionid]);
        echo html_writer::tag('button', $str('reservation_verification_review_mark_fail', 'Fail'), ['type' => 'submit', 'name' => 'action', 'value' => 'mark_fail', 'class' => 'btn btn-sm btn-danger']);
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
