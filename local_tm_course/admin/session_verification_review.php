<?php
/**
 * Admin: review session enrolment verification attachments (one submission).
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/verification_manager.php');
require_once(__DIR__ . '/../classes/session_verification_manager.php');

use local_tm_course\session_manager;
use local_tm_course\session_verification_manager;
use local_tm_course\verification_manager;

require_login();
require_capability('local/tm_course:approve', context_system::instance());

global $DB, $OUTPUT, $PAGE, $USER;

$submissionid = required_param('submissionid', PARAM_INT);
$submission = session_verification_manager::get_submission($submissionid);
$session = session_manager::get_session((int)$submission->sessionid);

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
            ? verification_manager::REVIEW_PASSED
            : verification_manager::REVIEW_FAILED;
        $exists = $DB->record_exists('local_tm_course_sess_vq_file', [
            'submissionid' => $submissionid,
            'questionid' => $questionid,
        ]);
        $qtext = (string)$DB->get_field('local_tm_course_vq_q', 'question_text', ['id' => $questionid]);
        if (!$exists) {
            redirect(
                new moodle_url('/local/tm_course/admin/session_verification_review.php', ['submissionid' => $submissionid]),
                $str('session_vq_review_save_no_file', 'No uploaded file for this question yet.'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        session_verification_manager::review_uploaded_file($submissionid, $questionid, $status, (int)$USER->id);
        $statuslabel = ($status === verification_manager::REVIEW_PASSED)
            ? $str('reservation_verification_review_status_passed', 'Passed')
            : $str('reservation_verification_review_status_failed', 'Failed');
        redirect(
            new moodle_url('/local/tm_course/admin/session_verification_review.php', ['submissionid' => $submissionid]),
            $str('session_vq_review_saved', 'Saved: {$a->question} — {$a->status}', (object)[
                'question' => $qtext !== '' ? $qtext : ('#' . $questionid),
                'status' => $statuslabel,
            ]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/tm_course/admin/session_verification_review.php', ['submissionid' => $submissionid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title($str('session_vq_review_title', 'Session enrolment: verification'));
$PAGE->set_heading($str('session_vq_review_title', 'Session enrolment: verification'));
$PAGE->requires->css('/local/tm_course/styles.css');

$courseid = (int)$session->courseid;
$courseids = [$courseid];
$coursenames = [];
if ($courseid > 0) {
    $coursenames[$courseid] = format_string((string)$DB->get_field('course', 'fullname', ['id' => $courseid], IGNORE_MISSING));
}
$questions = verification_manager::get_questions_for_courses(
    $courseids,
    (string)($session->delivery_mode ?? session_manager::DELIVERY_ONSITE)
);

$fs = get_file_storage();
$filearea = verification_manager::FILEAREA;

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

$filelinkbyqid = [];
$rs = $DB->get_recordset(
    'local_tm_course_sess_vq_file',
    ['submissionid' => $submissionid],
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
echo html_writer::tag('h2', $str('session_vq_review_title', 'Session enrolment: verification'));
echo html_writer::end_div();

$scopelabel = (string)$submission->scope === session_verification_manager::SCOPE_BATCH
    ? $str('session_vq_review_scope_batch', 'Batch')
    : $str('session_vq_review_scope_self', 'Self-service');
echo html_writer::div(
    $str('session_vq_review_intro', 'Submission #{$a->id} · {$a->session} · {$a->scope}', (object)[
        'id' => $submissionid,
        'session' => format_string($session->name),
        'scope' => $scopelabel,
    ]),
    'tm-alert tm-alert-info mb-3'
);

echo html_writer::link(
    new moodle_url('/local/tm_course/admin/enrolments.php', ['sessionid' => (int)$session->id]),
    get_string('back', 'moodle'),
    ['class' => 'btn btn-secondary mb-3']
);

if (empty($flatitems)) {
    echo html_writer::div($str('session_vq_review_none', 'No verification questions for this session.'), 'tm-alert tm-alert-warning');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_div('tm-card mb-3');
echo html_writer::start_div('tm-card-body');
echo html_writer::tag('h4', $str('reservation_verification_review_summary_title', 'Verification summary'), ['class' => 'mb-2']);
$progresstext = $str(
    'reservation_verification_review_summary_progress',
    'Uploaded: {$a->uploaded}/{$a->total} (required {$a->reqdone}/{$a->reqtotal})',
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
echo html_writer::div(
    ((int)$submission->submitted === 1)
        ? $str('session_vq_review_submitted_yes', 'Applicant has submitted this package.')
        : $str('session_vq_review_submitted_no', 'Applicant has not marked upload as complete yet.'),
    'small text-muted mb-2'
);
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
    } else if ((int)$it['review_status'] === verification_manager::REVIEW_PASSED) {
        $statuslabel = $str('reservation_verification_review_status_passed', 'Passed');
    } else if ((int)$it['review_status'] === verification_manager::REVIEW_FAILED) {
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
            echo html_writer::div(
                $str('reservation_verification_review_status_missing', 'Not uploaded yet'),
                'badge badge-warning mb-2'
            );
            echo html_writer::div(
                $str('reservation_verification_review_missing_hint', 'No file yet.'),
                'tm-alert tm-alert-warning mb-0'
            );
            echo html_writer::end_div();
            continue;
        }

        if ($reviewstatus === verification_manager::REVIEW_PASSED) {
            echo html_writer::div($str('reservation_verification_review_status_passed', 'Passed'), 'badge badge-success mb-2');
        } else if ($reviewstatus === verification_manager::REVIEW_FAILED) {
            echo html_writer::div($str('reservation_verification_review_status_failed', 'Failed'), 'badge badge-danger mb-2');
        } else {
            echo html_writer::div($str('reservation_verification_review_status_pending', 'Pending review'), 'badge badge-secondary mb-2');
        }

        foreach ($entry['files'] as $sf) {
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
                    ['target' => '_blank', 'rel' => 'noopener']
                );
            } else {
                $dl = $makeurl($itemid, $sf, true);
                echo html_writer::div(
                    html_writer::link($dl, s($fname), [
                        'class' => 'btn btn-sm btn-outline-primary',
                        'target' => '_blank',
                        'rel' => 'noopener',
                    ]),
                    'mt-1'
                );
            }
        }

        echo html_writer::start_div('mt-2 d-flex');
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mr-2']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submissionid', 'value' => $submissionid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'questionid', 'value' => $questionid]);
        echo html_writer::tag('button', $str('reservation_verification_review_mark_pass', 'Pass'), ['type' => 'submit', 'name' => 'action', 'value' => 'mark_pass', 'class' => 'btn btn-sm btn-success']);
        echo html_writer::end_tag('form');
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submissionid', 'value' => $submissionid]);
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
