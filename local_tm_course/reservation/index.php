<?php
/**
 * Dedicated class request entry page (Phase 2 -> Phase 3 handoff).
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/permissions_manager.php');
require_once(__DIR__ . '/../classes/session_manager.php');
require_once(__DIR__ . '/../classes/enabled_course_manager.php');
require_once(__DIR__ . '/../classes/reservation_application.php');
require_once(__DIR__ . '/../classes/batch_enrol_helper.php');
require_once(__DIR__ . '/../classes/enrolment_manager.php');

require_login();

global $DB, $SESSION, $USER;

$context = context_system::instance();
$issiteadmin = is_siteadmin();
$canbatch = \local_tm_course\permissions_manager::user_can_batch_enrol();
if (!$issiteadmin && !$canbatch) {
    throw new required_capability_exception($context, 'local/tm_course:batchenrol', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/tm_course/reservation/index.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('reservation_page_title', 'local_tm_course'));
$PAGE->set_heading(get_string('reservation_page_title', 'local_tm_course'));
$PAGE->requires->css('/local/tm_course/styles.css');

if (optional_param('submitted', 0, PARAM_INT) === 1) {
    \core\notification::success(get_string('reservation_submit_success', 'local_tm_course'));
}

$disclaimer = (string)get_config('local_tm_course', 'reservation_disclaimer_text');
if (trim($disclaimer) === '') {
    $disclaimer = get_string('reservation_disclaimer_default', 'local_tm_course');
}

$courseoptions = [];
$courseoptionsraw = [];
$coursesql = "SELECT ec.courseid, c.fullname, ec.default_duration_hours
                FROM {local_tm_enabled_courses} ec
                JOIN {course} c ON c.id = ec.courseid
               WHERE ec.default_duration_hours > 0
            ORDER BY c.fullname ASC";
foreach ($DB->get_records_sql($coursesql) as $row) {
    $courseoptionsraw[(int)$row->courseid] = format_string((string)$row->fullname);
    $courseoptions[(int)$row->courseid] = format_string((string)$row->fullname) . ' (' .
        format_float((float)$row->default_duration_hours, 1) . 'h)';
}
$allowonsitemap = \local_tm_course\enabled_course_manager::get_allow_onsite_map();
$allowonlinemap = \local_tm_course\enabled_course_manager::get_allow_online_map();
$durationonsitemap = \local_tm_course\enabled_course_manager::get_duration_map_onsite();
$durationonlinemap = \local_tm_course\enabled_course_manager::get_duration_map_online();
$classroommap = \local_tm_course\enabled_course_manager::get_classroom_map();
$classroomlabels = [];
foreach ($DB->get_records('local_tm_classroom', [], '', 'id,name,location') as $room) {
    $label = trim((string)$room->name);
    $loc = trim((string)($room->location ?? ''));
    if ($loc !== '') {
        $label .= ' — ' . $loc;
    }
    $classroomlabels[(int)$room->id] = $label;
}

$formatcourselabel = function(int $cid, string $mode = '') use (&$courseoptionsraw, &$durationonsitemap, &$durationonlinemap): string {
    $name = $courseoptionsraw[$cid] ?? ('Course #' . $cid);
    $duration = ($mode === 'online')
        ? ($durationonlinemap[$cid] ?? 8.0)
        : ($durationonsitemap[$cid] ?? 8.0);
    return $name . ' (' . format_float((float)$duration, 1) . 'h)';
};
$ismaintenancecourse = function(int $cid) use (&$courseoptionsraw): bool {
    $name = strtolower(trim((string)($courseoptionsraw[$cid] ?? '')));
    if ($name === '') {
        return false;
    }
    return strpos($name, 'maintenance') !== false || strpos((string)($courseoptionsraw[$cid] ?? ''), '維修') !== false;
};

$agreekey = 'local_tm_course_reservation_disclaimer_agreed';
$errors = [];
$defaultdate = userdate(time() + (15 * DAYSECS), '%Y-%m-%d');
$mindate = date('Y-m-d', time() + (14 * DAYSECS));

$form = [
    'courseids' => [],
    'delivery_mode' => '',
    'teaching_language' => \local_tm_course\session_manager::LANG_ZH_TW,
    'preferred_classroomid' => 0,
    'preferred_date' => $defaultdate,
    'preferred_time' => '09:30',
    // Optional cap (hours/day) used to evenly split online courses across consecutive workdays.
    // Empty string or 0 = no manual split (existing behaviour).
    'online_daily_hours_limit' => '',
    'batch_submitternote' => '',
];
$manualrowsforview = [];

$editrid = optional_param('editrid', 0, PARAM_INT);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editrid > 0) {
    $editresv = $DB->get_record('local_tm_course_reservation', ['id' => $editrid], '*', IGNORE_MISSING);
    if (!empty($editresv) && ((int)$editresv->requesterid === (int)$USER->id || $issiteadmin)
        && ($issiteadmin || \local_tm_course\reservation_application::is_editable_draft($editresv))) {
        $prefillcourseids = [];
        if (!empty($editresv->courseids_json)) {
            $decoded = json_decode((string)$editresv->courseids_json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $cid) {
                    $cid = (int)$cid;
                    if ($cid > 0 && !empty($courseoptions[$cid])) {
                        $prefillcourseids[] = $cid;
                    }
                }
            }
        }
        if (empty($prefillcourseids) && (int)$editresv->courseid > 0 && !empty($courseoptions[(int)$editresv->courseid])) {
            $prefillcourseids[] = (int)$editresv->courseid;
        }
        $prefillcourseids = array_values(array_unique($prefillcourseids));
        if (!empty($prefillcourseids)) {
            $form['courseids'] = $prefillcourseids;
        }

        $mode = (string)($editresv->delivery_mode ?? 'onsite');
        $form['delivery_mode'] = in_array($mode, ['onsite', 'online'], true) ? $mode : 'onsite';

        $lang = (string)($editresv->teaching_language ?? \local_tm_course\session_manager::LANG_ZH_TW);
        if (!in_array($lang, [\local_tm_course\session_manager::LANG_ZH_TW, \local_tm_course\session_manager::LANG_ENGLISH], true)) {
            $lang = \local_tm_course\session_manager::LANG_ZH_TW;
        }
        $form['teaching_language'] = $lang;
        $prefroom = (int)($editresv->preferred_classroomid ?? 0);
        if ($prefroom <= 0) {
            $prefroom = (int)($editresv->classroomid ?? 0);
        }
        $form['preferred_classroomid'] = $prefroom;

        $ts = (int)($editresv->preferred_starttime ?? 0);
        if ($ts > 0) {
            $form['preferred_date'] = date('Y-m-d', $ts);
            $form['preferred_time'] = date('H:i', $ts);
            if ($form['delivery_mode'] === 'onsite') {
                $form['preferred_time'] = '09:30';
            }
        }

        // Restore optional online manual split cap (hours/day). Always normalize to integer hours.
        if (property_exists($editresv, 'online_daily_hours_limit')
            && $editresv->online_daily_hours_limit !== null
            && (float)$editresv->online_daily_hours_limit > 0) {
            $form['online_daily_hours_limit'] = (string)(int)round((float)$editresv->online_daily_hours_limit);
        }

        $learnerrows = $DB->get_records('local_tm_course_resv_learner', ['reservationid' => (int)$editresv->id], 'id ASC');
        foreach ($learnerrows as $lr) {
            $manualrowsforview[] = [
                'firstname' => (string)$lr->firstname,
                'lastname' => (string)$lr->lastname,
                'email' => (string)$lr->email,
                'institution' => (string)$lr->institution,
                'diet' => (string)($lr->diet_choice ?? ''),
                'special_note' => (string)($lr->diet_special_note ?? ''),
            ];
        }
        if (property_exists($editresv, 'batch_submitter_note')) {
            $form['batch_submitternote'] = (string)($editresv->batch_submitter_note ?? '');
        }
    }
}

$collectsubmission = function() use (&$form, &$errors, $defaultdate, $mindate, $courseoptions, $allowonsitemap, $allowonlinemap, $classroommap, $classroomlabels, $ismaintenancecourse, $durationonlinemap): array {
    $courseids = optional_param_array('courseids', [], PARAM_INT);
    $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids), function($v) {
        return $v > 0;
    })));
    $form['courseids'] = $courseids;
    $form['delivery_mode'] = optional_param('delivery_mode', '', PARAM_ALPHA);
    $form['teaching_language'] = optional_param('teaching_language', \local_tm_course\session_manager::LANG_ZH_TW, PARAM_ALPHANUMEXT);
    $form['preferred_classroomid'] = optional_param('preferred_classroomid', 0, PARAM_INT);
    $form['preferred_date'] = optional_param('preferred_date', $defaultdate, PARAM_RAW_TRIMMED);
    $form['preferred_time'] = optional_param('preferred_time', '09:30', PARAM_RAW_TRIMMED);
    $form['online_daily_hours_limit'] = trim((string)optional_param('online_daily_hours_limit', '', PARAM_RAW_TRIMMED));

    if (!in_array($form['delivery_mode'], ['onsite', 'online'], true)) {
        $errors[] = get_string('reservation_error_delivery_mode', 'local_tm_course');
    }
    $allowmap = $form['delivery_mode'] === 'online' ? $allowonlinemap : $allowonsitemap;
    if (empty($courseids)) {
        $errors[] = get_string('reservation_error_course_required', 'local_tm_course');
    } else {
        foreach ($courseids as $cid) {
            if (empty($courseoptions[$cid]) || empty($allowmap[(int)$cid])) {
                $errors[] = get_string('reservation_error_course_required', 'local_tm_course');
                break;
            }
        }
    }
    if (!in_array($form['teaching_language'], [
        \local_tm_course\session_manager::LANG_ZH_TW,
        \local_tm_course\session_manager::LANG_ENGLISH,
    ], true)) {
        $errors[] = get_string('error_teaching_language_invalid', 'local_tm_course');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$form['preferred_date'])) {
        $errors[] = get_string('reservation_error_date_required', 'local_tm_course');
    } else if (strtotime((string)$form['preferred_date'] . ' 00:00:00') < strtotime((string)$mindate . ' 00:00:00')) {
        $errors[] = '請選擇 ' . $mindate . '（含）之後的日期。';
    }
    if ($form['delivery_mode'] === 'onsite') {
        $intersection = null;
        foreach ($courseids as $cid) {
            if ($ismaintenancecourse((int)$cid)) {
                // Maintenance courses always follow their fixed classroom rule and
                // should not reduce the applicant-side preferred classroom intersection.
                continue;
            }
            $allowed = array_values(array_unique(array_map('intval', $classroommap[(int)$cid] ?? [])));
            if ($intersection === null) {
                $intersection = $allowed;
            } else {
                $intersection = array_values(array_intersect($intersection, $allowed));
            }
        }
        $intersection = $intersection === null ? [] : $intersection;
        $intersection = array_values(array_filter($intersection, function($rid) use ($classroomlabels) {
            return $rid > 0 && !empty($classroomlabels[(int)$rid]);
        }));
        if (empty($intersection)) {
            // No common classroom across selected onsite courses:
            // allow submission without a single preferred classroom.
            $form['preferred_classroomid'] = 0;
        } else if ((int)$form['preferred_classroomid'] <= 0 || !in_array((int)$form['preferred_classroomid'], $intersection, true)) {
            $errors[] = get_string('reservation_error_preferred_classroom_required', 'local_tm_course');
        }
        $form['preferred_time'] = '09:30';
    } else if (!preg_match('/^\d{2}:\d{2}$/', (string)$form['preferred_time'])) {
        $form['preferred_classroomid'] = 0;
        $errors[] = get_string('reservation_error_time_required', 'local_tm_course');
    } else {
        $form['preferred_classroomid'] = 0;
        $hour = (int)substr((string)$form['preferred_time'], 0, 2);
        $minute = substr((string)$form['preferred_time'], 3, 2);
        if ($hour < 8 || $hour > 18 || ($minute !== '00' && $minute !== '30')) {
            $errors[] = get_string('reservation_error_time_required', 'local_tm_course');
        }
    }

    if ($form['delivery_mode'] === 'online' && !empty($courseids)) {
        $missingonline = \local_tm_course\enabled_course_manager::get_missing_online_classroom_course_ids($courseids);
        if (!empty($missingonline)) {
            $errors[] = \local_tm_course\enabled_course_manager::format_missing_online_classroom_error(
                $missingonline,
                $courseoptions
            );
        }
    }

    // Online manual split: validate optional daily cap (hours/day).
    // Only enforced when delivery_mode = online; for onsite we silently discard the value.
    $sm = get_string_manager();
    $errstr = function(string $key, string $fallback) use ($sm): string {
        return $sm->string_exists($key, 'local_tm_course')
            ? get_string($key, 'local_tm_course')
            : $fallback;
    };
    if ($form['delivery_mode'] !== 'online') {
        $form['online_daily_hours_limit'] = '';
    } else if ((string)$form['online_daily_hours_limit'] !== '') {
        // Manual split cap is an INTEGER number of hours (no fractions).
        $raw = trim((string)$form['online_daily_hours_limit']);
        if (!preg_match('/^\d+$/', $raw)) {
            $errors[] = $errstr(
                'reservation_error_online_daily_hours_limit_format',
                '每日最多上課時數須為整數小時（1、2、3、…），或留空表示不切分。'
            );
        } else {
            $cap = (int)$raw;
            if ($cap <= 0) {
                $form['online_daily_hours_limit'] = '';
            } else {
                // Cap must not exceed the longest selected online course total_hours
                // (otherwise the cap is meaningless and the form would silently no-op).
                $maxonlinetotal = 0.0;
                foreach ($courseids as $cid) {
                    $h = (float)($durationonlinemap[(int)$cid] ?? 0);
                    if ($h > $maxonlinetotal) {
                        $maxonlinetotal = $h;
                    }
                }
                if ($maxonlinetotal > 0 && (float)$cap >= $maxonlinetotal) {
                    $maxdisplay = rtrim(rtrim(number_format($maxonlinetotal, 2, '.', ''), '0'), '.');
                    if ($sm->string_exists('reservation_error_online_daily_hours_limit_too_large', 'local_tm_course')) {
                        $errors[] = get_string('reservation_error_online_daily_hours_limit_too_large', 'local_tm_course', $maxdisplay);
                    } else {
                        $errors[] = '每日最多上課時數不可大於或等於所選課程中時數最長的一門（' . $maxdisplay . 'h）。請改填較小的值，或留空表示不切分。';
                    }
                }
                // Cap + preferred start time must fit within configured online day end limit.
                if (preg_match('/^\d{2}:\d{2}$/', (string)$form['preferred_time'])) {
                    $startts = strtotime('2025-01-06 ' . $form['preferred_time'] . ':00');
                    $dayendhhmm = \local_tm_course\session_manager::get_online_day_end_hhmm();
                    $dayendts = strtotime('2025-01-06 ' . $dayendhhmm . ':00');
                    $availhours = max(0.0, ($dayendts - $startts) / 3600.0);
                    if ((float)$cap > $availhours + 0.0001) {
                        $errors[] = $errstr(
                            'reservation_error_online_daily_hours_limit_over_dayend',
                            '切分後每天的結束時間將超過系統設定的最晚結束時間（' . $dayendhhmm . '）。請縮短「每日最多上課時數」或調整開始時間。'
                        );
                    }
                }
                $form['online_daily_hours_limit'] = (string)$cap;
            }
        }
    }

    $form['batch_submitternote'] = optional_param('batch_submitternote', '', PARAM_TEXT);
    $batchconfirmed = optional_param('batch_confirmed', 0, PARAM_INT);
    $rowdata = \local_tm_course\batch_enrol_helper::full_mode_post_arrays();
    $hasanyrow = false;
    $emails = $rowdata['emails'] ?? [];
    $firstnames = $rowdata['firstnames'] ?? [];
    $lastnames = $rowdata['lastnames'] ?? [];
    $ncheck = max(count($emails), count($firstnames), count($lastnames));
    for ($i = 0; $i < $ncheck; $i++) {
        $email = trim(strtolower((string)($emails[$i] ?? '')));
        $firstname = trim((string)($firstnames[$i] ?? ''));
        $lastname = trim((string)($lastnames[$i] ?? ''));
        if ($email !== '' || $firstname !== '' || $lastname !== '') {
            $hasanyrow = true;
            break;
        }
    }

    $learnerrows = [];
    if ($hasanyrow) {
        if ($batchconfirmed !== 1) {
            $errors[] = get_string('reservation_batch_confirm_required', 'local_tm_course');
        } else {
            global $USER;
            $isonline = ((string)$form['delivery_mode'] === 'online');
            $parsed = \local_tm_course\batch_enrol_helper::parse_full_mode_rows(
                (int)$USER->id,
                0,
                $rowdata,
                [
                    'allow_partial_placeholders' => false,
                    'isonline' => $isonline,
                    'require_diet' => !$isonline,
                    'create_missing_users' => false,
                ]
            );
            if (!$parsed['ok']) {
                $errors[] = $parsed['error'];
            } else if (empty($parsed['entries'])) {
                $errors[] = get_string('batch_need_one_row', 'local_tm_course');
            } else {
                $partition = \local_tm_course\reservation_application::partition_learners_by_prerequisites(
                    $form['courseids'] ?? [],
                    $parsed['entries']
                );
                // Unmet / missing-account rows are dropped: 學員數 and review list stay aligned.
                $learnerrows = $partition['kept'];
            }
        }
    }

    $timestamp = 0;
    if (empty($errors)) {
        $timestamp = (int)strtotime($form['preferred_date'] . ' ' . $form['preferred_time']);
        if ($timestamp <= 0) {
            $errors[] = get_string('reservation_error_date_required', 'local_tm_course');
        }
    }

    return [$learnerrows, $timestamp];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);

    if ($action === 'agree_disclaimer') {
        $agreed = optional_param('agree_disclaimer', 0, PARAM_INT);
        if ($agreed !== 1) {
            $errors[] = get_string('reservation_disclaimer_must_agree', 'local_tm_course');
        } else {
            $SESSION->$agreekey = 1;
            redirect(new moodle_url('/local/tm_course/reservation/index.php'));
        }
    } else if ($action === 'submit_request') {
        if (empty($SESSION->$agreekey)) {
            $errors[] = get_string('reservation_disclaimer_must_agree', 'local_tm_course');
        } else {
            [$learnerrows, $timestamp] = $collectsubmission();
            $manualrowsforview = [];
            foreach ($learnerrows as $lr) {
                $manualrowsforview[] = [
                    'firstname' => (string)($lr['firstname'] ?? ''),
                    'lastname' => (string)($lr['lastname'] ?? ''),
                    'email' => (string)($lr['email'] ?? ''),
                    'institution' => (string)($lr['institution'] ?? ''),
                    'diet' => (string)($lr['diet_choice'] ?? ''),
                    'special_note' => (string)($lr['diet_special_note'] ?? ''),
                ];
            }

            if (empty($errors)) {
                $draftid = optional_param('editrid', 0, PARAM_INT);
                $reservationid = 0;
                try {
                    $reservationid = \local_tm_course\reservation_application::save_draft_from_form(
                        $form,
                        $learnerrows,
                        (int) $timestamp,
                        (int) $USER->id,
                        $disclaimer,
                        (int) $draftid,
                        (string)($form['batch_submitternote'] ?? '')
                    );
                } catch (\moodle_exception $e) {
                    $errors[] = $e->getMessage();
                }
                if (empty($errors) && !empty($reservationid)) {
                    redirect(new moodle_url('/local/tm_course/reservation/calendar.php', ['id' => $reservationid]));
                }
            }
        }
    }
}

$showdisclaimergate = empty($SESSION->$agreekey);

echo $OUTPUT->header();
echo html_writer::start_div('tm-page-header');
echo html_writer::span('', 'tm-logo-dot');
echo html_writer::tag('h2', get_string('reservation_page_title', 'local_tm_course'));
echo html_writer::start_div('ml-auto');
echo html_writer::link(
    new moodle_url('/local/tm_course/reservation/tracking.php'),
    get_string('reservation_tracking_cta', 'local_tm_course'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('mb-2');
echo html_writer::tag('span', '基本資料 (1/3)', ['class' => 'badge badge-info mr-1']);
echo html_writer::tag('span', '月曆編排 (2/3)', ['class' => 'badge badge-secondary mr-1']);
echo html_writer::tag('span', '檢核資料 (3/3)', ['class' => 'badge badge-secondary']);
echo html_writer::end_div();

if (!empty($errors)) {
    echo html_writer::start_div('tm-alert tm-alert-error');
    foreach ($errors as $err) {
        echo html_writer::div(s($err));
    }
    echo html_writer::end_div();
}

echo html_writer::div(
    get_string('reservation_draft_flow_hint', 'local_tm_course'),
    'tm-alert tm-alert-info mb-3'
);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => '',
    'enctype' => 'multipart/form-data',
    'id' => 'tm-reservation-form',
    'data-tm-batch-form' => '1',
    'novalidate' => 'novalidate',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'submit_request']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
if ($editrid > 0) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'editrid', 'value' => (int) $editrid]);
}

echo html_writer::start_div('tm-card tm-resv-card');
echo html_writer::start_div('tm-card-body');
echo html_writer::tag('h4', get_string('reservation_section_course_info', 'local_tm_course'), ['class' => 'tm-resv-section-title']);
echo html_writer::tag('label', get_string('reservation_field_course_multi', 'local_tm_course') . ' *');
echo html_writer::start_div('tm-resv-chip-list', ['id' => 'res-course-chip-list']);
foreach ($courseoptions as $cid => $label) {
    $checked = in_array((int)$cid, $form['courseids'], true);
    $attrs = ['type' => 'checkbox', 'name' => 'courseids[]', 'value' => (int)$cid, 'class' => 'res-course-checkbox'];
    $attrs['data-allow-onsite'] = !empty($allowonsitemap[(int)$cid]) ? '1' : '0';
    $attrs['data-allow-online'] = !empty($allowonlinemap[(int)$cid]) ? '1' : '0';
    $attrs['data-course-name'] = (string)($courseoptionsraw[(int)$cid] ?? '');
    $attrs['data-duration-onsite'] = (string)($durationonsitemap[(int)$cid] ?? 8.0);
    $attrs['data-duration-online'] = (string)($durationonlinemap[(int)$cid] ?? 8.0);
    $attrs['data-allowed-rooms'] = implode(',', array_map('intval', $classroommap[(int)$cid] ?? []));
    if ($checked) { $attrs['checked'] = 'checked'; }
    if ($showdisclaimergate) { $attrs['disabled'] = 'disabled'; }
    echo html_writer::start_tag('label', ['class' => 'tm-resv-chip']);
    echo html_writer::empty_tag('input', $attrs);
    echo html_writer::span($formatcourselabel((int)$cid, (string)$form['delivery_mode']), 'tm-resv-chip-text');
    echo html_writer::end_tag('label');
}
echo html_writer::end_div();
echo html_writer::div(get_string('reservation_course_multi_hint', 'local_tm_course'), 'tm-session-muted');
echo html_writer::div(get_string('reservation_course_mode_filter_hint', 'local_tm_course'), 'tm-session-muted');
echo html_writer::start_div('tm-resv-pill-box', ['id' => 'res-selected-pill-box']);
echo html_writer::end_div();

echo html_writer::start_div('form-group mt-3');
echo html_writer::tag('label', get_string('reservation_field_delivery_mode', 'local_tm_course') . ' *');
echo html_writer::start_div('tm-resv-radio-row');
foreach (['onsite' => get_string('delivery_mode_onsite', 'local_tm_course'), 'online' => get_string('delivery_mode_online', 'local_tm_course')] as $k => $lbl) {
    $a = ['type' => 'radio', 'name' => 'delivery_mode', 'value' => $k];
    if ((string)$form['delivery_mode'] !== '' && (string)$form['delivery_mode'] === (string)$k) { $a['checked'] = 'checked'; }
    if ($showdisclaimergate) { $a['disabled'] = 'disabled'; }
    echo html_writer::tag('label', html_writer::empty_tag('input', $a) . ' ' . $lbl);
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-group mt-2');
echo html_writer::tag('label', get_string('session_teaching_language', 'local_tm_course') . ' *');
echo html_writer::start_div('tm-resv-radio-row');
$langzh = ['type' => 'radio', 'name' => 'teaching_language', 'value' => \local_tm_course\session_manager::LANG_ZH_TW];
if ((string)$form['teaching_language'] === \local_tm_course\session_manager::LANG_ZH_TW) { $langzh['checked'] = 'checked'; }
if ($showdisclaimergate) { $langzh['disabled'] = 'disabled'; }
echo html_writer::tag('label', html_writer::empty_tag('input', $langzh) . ' ' . get_string('teaching_language_zh_tw', 'local_tm_course'));
$langen = ['type' => 'radio', 'name' => 'teaching_language', 'value' => \local_tm_course\session_manager::LANG_ENGLISH];
if ((string)$form['teaching_language'] === \local_tm_course\session_manager::LANG_ENGLISH) { $langen['checked'] = 'checked'; }
if ($showdisclaimergate) { $langen['disabled'] = 'disabled'; }
echo html_writer::tag('label', html_writer::empty_tag('input', $langen) . ' ' . get_string('teaching_language_english', 'local_tm_course'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('form-group mt-2');
echo html_writer::tag('label', get_string('reservation_field_preferred_classroom', 'local_tm_course') . ' *', ['for' => 'res-preferred-classroom']);
$roomattrs = ['id' => 'res-preferred-classroom', 'name' => 'preferred_classroomid', 'class' => 'form-control', 'style' => 'max-width:28rem;'];
if ($showdisclaimergate) { $roomattrs['disabled'] = 'disabled'; $roomattrs['data-locked'] = '1'; }
echo html_writer::start_tag('select', $roomattrs);
echo html_writer::tag('option', get_string('reservation_select_placeholder', 'local_tm_course'), ['value' => '0']);
foreach ($classroomlabels as $rid => $label) {
    $o = ['value' => (int)$rid, 'data-room-id' => (int)$rid];
    if ((int)$form['preferred_classroomid'] === (int)$rid) { $o['selected'] = 'selected'; }
    echo html_writer::tag('option', s($label), $o);
}
echo html_writer::end_tag('select');
$dmodeform = (string)$form['delivery_mode'];
$hasmaintselection = false;
foreach ($form['courseids'] as $cid) {
    if ($ismaintenancecourse((int)$cid)) {
        $hasmaintselection = true;
        break;
    }
}
$hintonlinevis = $dmodeform === 'online';
$hintmaintvis = $dmodeform === 'onsite' && $hasmaintselection;
$hintdefaultvis = !$hintonlinevis && !$hintmaintvis;
echo html_writer::div(get_string('reservation_preferred_classroom_hint', 'local_tm_course'), 'tm-session-muted', [
    'id' => 'res-classroom-hint-default',
    'style' => $hintdefaultvis ? '' : 'display:none;',
]);
echo html_writer::div(get_string('reservation_preferred_classroom_hint_maintenance', 'local_tm_course'), 'tm-session-muted', [
    'id' => 'res-classroom-hint-maintenance',
    'style' => $hintmaintvis ? '' : 'display:none;',
]);
echo html_writer::div(get_string('reservation_classroom_hint', 'local_tm_course'), 'tm-session-muted', [
    'id' => 'res-classroom-hint-online',
    'style' => $hintonlinevis ? '' : 'display:none;',
]);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('tm-card tm-resv-card');
echo html_writer::start_div('tm-card-body');
echo html_writer::tag('h4', get_string('reservation_section_schedule', 'local_tm_course'), ['class' => 'tm-resv-section-title']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('reservation_field_preferred_date', 'local_tm_course') . ' *', ['for' => 'res-date']);
$effectivepreferreddate = (string)$form['preferred_date'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectivepreferreddate)
    || strtotime($effectivepreferreddate . ' 00:00:00') < strtotime($mindate . ' 00:00:00')) {
    $effectivepreferreddate = $mindate;
}
$dateattrs = [
    'type' => 'date',
    'id' => 'res-date',
    'name' => 'preferred_date',
    'class' => 'form-control',
    'value' => $effectivepreferreddate,
    'min' => (string)$mindate,
];
if ($showdisclaimergate) { $dateattrs['disabled'] = 'disabled'; }
echo html_writer::empty_tag('input', $dateattrs);
echo html_writer::div('最早可選日期：' . s($mindate), 'tm-session-muted');
echo html_writer::end_div();
echo html_writer::start_div('form-group mt-2');
echo html_writer::tag('label', get_string('reservation_field_preferred_time', 'local_tm_course') . ' *', ['for' => 'res-time-hour']);
$timeparts = explode(':', (string)$form['preferred_time']);
$timehour = isset($timeparts[0]) ? (int)$timeparts[0] : 9;
$timeminute = isset($timeparts[1]) ? (int)$timeparts[1] : 30;
if ($timehour < 8 || $timehour > 18) { $timehour = 9; }
if ($timeminute !== 0 && $timeminute !== 30) { $timeminute = 30; }
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'id' => 'res-time',
    'name' => 'preferred_time',
    'value' => sprintf('%02d:%02d', $timehour, $timeminute),
]);
echo html_writer::start_div('d-flex align-items-center');
$hourattrs = ['id' => 'res-time-hour', 'class' => 'form-control', 'style' => 'max-width:8rem;'];
$minattrs = ['id' => 'res-time-minute', 'class' => 'form-control ml-2', 'style' => 'max-width:8rem;'];
if ($showdisclaimergate) {
    $hourattrs['disabled'] = 'disabled';
    $minattrs['disabled'] = 'disabled';
}
echo html_writer::start_tag('select', $hourattrs);
for ($h = 8; $h <= 18; $h++) {
    $optattrs = ['value' => sprintf('%02d', $h)];
    if ($h === $timehour) { $optattrs['selected'] = 'selected'; }
    echo html_writer::tag('option', sprintf('%02d', $h), $optattrs);
}
echo html_writer::end_tag('select');
echo html_writer::start_tag('select', $minattrs);
foreach ([0, 30] as $m) {
    $optattrs = ['value' => sprintf('%02d', $m)];
    if ($m === $timeminute) { $optattrs['selected'] = 'selected'; }
    echo html_writer::tag('option', sprintf('%02d', $m), $optattrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();
echo html_writer::div(get_string('reservation_time_hint', 'local_tm_course'), 'tm-session-muted');
$sm = get_string_manager();
$tzhint = $sm->string_exists('reservation_time_timezone_hint', 'local_tm_course')
    ? get_string('reservation_time_timezone_hint', 'local_tm_course')
    : 'Please convert to Taiwan time zone (GMT+8).';
echo html_writer::div(s($tzhint), 'tm-session-muted');
echo html_writer::end_div();

// Online manual split: optional per-application daily cap (hours/day).
// Visible only when delivery_mode = online. Empty/0 means no manual split (current behaviour).
$splitvisible = ((string)$form['delivery_mode'] === 'online');
$splitlabel = $sm->string_exists('reservation_field_online_daily_hours_limit', 'local_tm_course')
    ? get_string('reservation_field_online_daily_hours_limit', 'local_tm_course')
    : '每日最多上課時數（小時）';
$splithint = $sm->string_exists('reservation_field_online_daily_hours_limit_hint', 'local_tm_course')
    ? get_string('reservation_field_online_daily_hours_limit_hint', 'local_tm_course')
    : '僅視訊課程適用。請填整數小時（例：4），留空或填 0 表示不切分。系統會將每門課的總時數均分到連續工作日，每天最多上 N 小時。';
$onlinedayendhhmm = \local_tm_course\session_manager::get_online_day_end_hhmm();
echo html_writer::start_div('form-group mt-2', [
    'id' => 'res-online-split-wrap',
    'style' => $splitvisible ? '' : 'display:none;',
]);
echo html_writer::tag('label', s($splitlabel), ['for' => 'res-online-daily-hours-limit']);
$splitinputattrs = [
    'type' => 'number',
    'id' => 'res-online-daily-hours-limit',
    'name' => 'online_daily_hours_limit',
    'class' => 'form-control',
    'style' => 'max-width:12rem;',
    'min' => '0',
    'max' => '24',
    'step' => '1',
    'inputmode' => 'numeric',
    'pattern' => '\\d+',
    'value' => (string)$form['online_daily_hours_limit'],
    'placeholder' => '0',
    'data-day-end' => (string)$onlinedayendhhmm,
];
if ($showdisclaimergate || !$splitvisible) { $splitinputattrs['disabled'] = 'disabled'; }
echo html_writer::empty_tag('input', $splitinputattrs);
echo html_writer::div(s($splithint), 'tm-session-muted');
echo html_writer::div('', 'tm-session-muted', ['id' => 'res-online-split-preview']);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('tm-card tm-resv-card');
echo html_writer::start_div('tm-card-body');
echo html_writer::tag('h4', get_string('reservation_section_enrollment', 'local_tm_course'), ['class' => 'tm-resv-section-title']);

$batchblockcfg = [
    'context' => 'reservation',
    'disabled' => $showdisclaimergate,
    'batch_submitternote' => (string)($form['batch_submitternote'] ?? ''),
    'context_hint' => get_string('reservation_batch_full_hint', 'local_tm_course'),
    'initial_rows' => $manualrowsforview,
    'emit_initial_rows_script' => true,
];
require(__DIR__ . '/../includes/batch_enrol_full_block.php');

echo html_writer::start_div('mt-3');
$proceedattrs = ['type' => 'button', 'class' => 'btn tm-enrol-btn', 'id' => 'tm-resv-proceed-calendar'];
if ($showdisclaimergate) { $proceedattrs['disabled'] = 'disabled'; }
echo html_writer::tag('button', get_string('reservation_proceed_calendar_button', 'local_tm_course'), $proceedattrs);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_tag('form');

if ($showdisclaimergate) {
    echo html_writer::start_div('tm-cancel-modal-backdrop');
    echo html_writer::start_div('tm-cancel-modal-panel', ['role' => 'dialog', 'aria-modal' => 'true']);
    echo html_writer::tag('h4', get_string('reservation_disclaimer_title', 'local_tm_course'));
    echo html_writer::div(format_text($disclaimer, FORMAT_PLAIN), 'mb-2');
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => '']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'agree_disclaimer']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('label',
        html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'agree_disclaimer', 'value' => '1']) . ' ' .
        get_string('reservation_disclaimer_agree_label', 'local_tm_course'),
        ['class' => 'mb-2 d-block']
    );
    echo html_writer::div(get_string('reservation_disclaimer_must_agree', 'local_tm_course'),
        'tm-alert tm-alert-error', ['id' => 'res-disclaimer-alert', 'style' => 'display:none; margin-bottom:.5rem;']);
    echo html_writer::tag('button', get_string('reservation_disclaimer_continue', 'local_tm_course'),
        ['type' => 'submit', 'class' => 'btn tm-enrol-btn', 'id' => 'res-disclaimer-submit']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::script("
(function() {
    var modeInputs = document.querySelectorAll('input[name=\"delivery_mode\"]');
    var timeHiddenInput = document.getElementById('res-time');
    var preferredClassroomSelect = document.getElementById('res-preferred-classroom');
    var timeHourSelect = document.getElementById('res-time-hour');
    var timeMinuteSelect = document.getElementById('res-time-minute');
    var chipInputs = document.querySelectorAll('.res-course-checkbox');
    var pillBox = document.getElementById('res-selected-pill-box');
    var splitWrap = document.getElementById('res-online-split-wrap');
    var splitInput = document.getElementById('res-online-daily-hours-limit');
    var splitPreview = document.getElementById('res-online-split-preview');

    function currentMode() {
        for (var i = 0; i < modeInputs.length; i++) {
            if (modeInputs[i].checked) { return modeInputs[i].value; }
        }
        return '';
    }
    function syncCourseVisibilityByMode() {
        var mode = currentMode();
        for (var i = 0; i < chipInputs.length; i++) {
            var inp = chipInputs[i];
            var cname = String(inp.getAttribute('data-course-name') || '');
            var d = mode === 'online'
                ? Number(inp.getAttribute('data-duration-online') || 8)
                : Number(inp.getAttribute('data-duration-onsite') || 8);
            var allow = mode === 'online'
                ? String(inp.getAttribute('data-allow-online') || '0') === '1'
                : (mode === 'onsite' ? String(inp.getAttribute('data-allow-onsite') || '0') === '1' : false);
            var chip = inp.closest ? inp.closest('.tm-resv-chip') : (inp.parentNode || null);
            var txt = chip ? chip.querySelector('.tm-resv-chip-text') : null;
            if (!allow) {
                inp.checked = false;
            }
            if (txt) {
                txt.textContent = cname + ' (' + d.toFixed(1) + 'h)';
            }
            if (chip) {
                chip.style.display = allow ? '' : 'none';
            }
        }
    }
    function currentSelectedCourseIds() {
        var selected = [];
        for (var i = 0; i < chipInputs.length; i++) {
            if (chipInputs[i].checked) {
                selected.push(Number(chipInputs[i].value || 0));
            }
        }
        return selected;
    }
    function isMaintenanceCourseName(name) {
        var n = String(name || '').toLowerCase();
        return n.indexOf('maintenance') !== -1 || String(name || '').indexOf('維修') !== -1;
    }
    function anySelectedMaintenanceCourse() {
        for (var i = 0; i < chipInputs.length; i++) {
            if (!chipInputs[i].checked) {
                continue;
            }
            if (isMaintenanceCourseName(String(chipInputs[i].getAttribute('data-course-name') || ''))) {
                return true;
            }
        }
        return false;
    }
    function syncClassroomHint() {
        var elDef = document.getElementById('res-classroom-hint-default');
        var elMaint = document.getElementById('res-classroom-hint-maintenance');
        var elOnline = document.getElementById('res-classroom-hint-online');
        if (!elDef || !elMaint || !elOnline) {
            return;
        }
        var mode = currentMode();
        if (mode === 'online') {
            elDef.style.display = 'none';
            elMaint.style.display = 'none';
            elOnline.style.display = '';
            return;
        }
        elOnline.style.display = 'none';
        if (mode !== 'onsite') {
            elMaint.style.display = 'none';
            elDef.style.display = '';
            return;
        }
        if (anySelectedMaintenanceCourse()) {
            elDef.style.display = 'none';
            elMaint.style.display = '';
        } else {
            elDef.style.display = '';
            elMaint.style.display = 'none';
        }
    }
    function getAllowedRoomsIntersection() {
        if (currentMode() !== 'onsite') {
            return [];
        }
        var selected = currentSelectedCourseIds();
        if (!selected.length) {
            return [];
        }
        var intersection = null;
        for (var i = 0; i < selected.length; i++) {
            var cid = selected[i];
            var src = null;
            for (var c = 0; c < chipInputs.length; c++) {
                if (Number(chipInputs[c].value || 0) === cid) {
                    src = chipInputs[c];
                    break;
                }
            }
            if (!src) {
                continue;
            }
            if (isMaintenanceCourseName(String(src.getAttribute('data-course-name') || ''))) {
                // Bypass maintenance courses in applicant-side intersection.
                continue;
            }
            var csv = String(src.getAttribute('data-allowed-rooms') || '');
            var rooms = csv ? csv.split(',').map(function(v) { return Number(v || 0); }).filter(function(v) { return v > 0; }) : [];
            if (intersection === null) {
                intersection = rooms.slice();
            } else {
                intersection = intersection.filter(function(v) { return rooms.indexOf(v) !== -1; });
            }
        }
        return intersection || [];
    }
    function syncPreferredClassroomBySelection() {
        if (!preferredClassroomSelect) {
            return;
        }
        if (preferredClassroomSelect.getAttribute('data-locked') === '1') {
            return;
        }
        var mode = currentMode();
        var allowed = getAllowedRoomsIntersection();
        var roomOptions = preferredClassroomSelect.querySelectorAll('option[data-room-id]');
        for (var i = 0; i < roomOptions.length; i++) {
            var rid = Number(roomOptions[i].getAttribute('data-room-id') || 0);
            var visible = mode === 'onsite' && allowed.indexOf(rid) !== -1;
            roomOptions[i].style.display = visible ? '' : 'none';
            if (!visible && roomOptions[i].selected) {
                preferredClassroomSelect.value = '0';
            }
        }
        if (mode === 'onsite') {
            preferredClassroomSelect.removeAttribute('disabled');
        } else {
            preferredClassroomSelect.value = '0';
            preferredClassroomSelect.setAttribute('disabled', 'disabled');
        }
        syncClassroomHint();
    }
    function syncModeUi() {
        var mode = currentMode();
        if (mode === 'onsite') {
            if (timeHourSelect) { timeHourSelect.value = '09'; timeHourSelect.setAttribute('disabled', 'disabled'); }
            if (timeMinuteSelect) { timeMinuteSelect.value = '30'; timeMinuteSelect.setAttribute('disabled', 'disabled'); }
        } else if (mode === 'online') {
            if (timeHourSelect) { timeHourSelect.removeAttribute('disabled'); }
            if (timeMinuteSelect) { timeMinuteSelect.removeAttribute('disabled'); }
        } else {
            if (timeHourSelect) { timeHourSelect.removeAttribute('disabled'); }
            if (timeMinuteSelect) { timeMinuteSelect.removeAttribute('disabled'); }
        }
        if (timeHiddenInput && timeHourSelect && timeMinuteSelect) {
            var hh = String(timeHourSelect.value || '09');
            var mm = String(timeMinuteSelect.value || '00');
            if (mm !== '00' && mm !== '30') { mm = '00'; }
            timeHiddenInput.value = hh + ':' + mm;
        }
        syncCourseVisibilityByMode();
        syncPreferredClassroomBySelection();
        syncSplitVisibility();
        syncSplitPreview();
        if (window.tmBatchCfg && window.tmBatchCfg.context === 'reservation') {
            window.tmBatchCfg.requireDiet = (mode === 'onsite');
        }
    }
    function syncSplitVisibility() {
        if (!splitWrap || !splitInput) { return; }
        var mode = currentMode();
        if (mode === 'online') {
            splitWrap.style.display = '';
            splitInput.removeAttribute('disabled');
        } else {
            splitWrap.style.display = 'none';
            splitInput.value = '';
            splitInput.setAttribute('disabled', 'disabled');
        }
    }
    function parseHhMmToHours(hhmm) {
        var s = String(hhmm || '');
        var m = s.match(/^(\d{2}):(\d{2})$/);
        if (!m) { return null; }
        return Number(m[1]) + Number(m[2]) / 60;
    }
    function syncSplitPreview() {
        if (!splitPreview || !splitInput) { return; }
        if (currentMode() !== 'online') {
            splitPreview.textContent = '';
            return;
        }
        var raw = String(splitInput.value || '').trim();
        if (raw === '' || Number(raw) <= 0) {
            splitPreview.textContent = '';
            splitPreview.style.color = '';
            return;
        }
        if (!/^\d+$/.test(raw)) {
            splitPreview.textContent = '⚠ 請填入正整數小時（例：4）。';
            splitPreview.style.color = '#c0392b';
            return;
        }
        var cap = Number(raw);
        if (!isFinite(cap) || cap <= 0) {
            splitPreview.textContent = '';
            splitPreview.style.color = '';
            return;
        }
        var dayEndHm = splitInput.getAttribute('data-day-end') || '22:30';
        var dayEndHours = parseHhMmToHours(dayEndHm);
        var startHours = parseHhMmToHours(timeHiddenInput ? (timeHiddenInput.value || '') : '');
        if (startHours !== null && dayEndHours !== null && cap > (dayEndHours - startHours) + 1e-6) {
            splitPreview.textContent = '⚠ 切分後每天結束時間將超過系統設定的最晚結束時間（' + dayEndHm + '）。';
            splitPreview.style.color = '#c0392b';
            return;
        }
        var selected = [];
        for (var i = 0; i < chipInputs.length; i++) {
            if (chipInputs[i].checked) {
                selected.push({
                    name: String(chipInputs[i].getAttribute('data-course-name') || ''),
                    total: Number(chipInputs[i].getAttribute('data-duration-online') || 0),
                });
            }
        }
        if (!selected.length) {
            splitPreview.textContent = '預覽：請先勾選課程。';
            splitPreview.style.color = '';
            return;
        }
        var lines = [];
        for (var j = 0; j < selected.length; j++) {
            var total = Number(selected[j].total || 0);
            if (total <= 0) { continue; }
            var days = Math.max(1, Math.ceil(total / cap));
            var per = total / days;
            lines.push(selected[j].name + '：' + total.toFixed(1) + 'h → ' + days + ' 天 × ' + per.toFixed(2) + 'h');
        }
        if (lines.length) {
            splitPreview.textContent = '預覽：' + lines.join('；');
            splitPreview.style.color = '';
        } else {
            splitPreview.textContent = '';
        }
    }
    function renderPills() {
        if (!pillBox) { return; }
        pillBox.innerHTML = '';
        var selected = [];
        for (var i = 0; i < chipInputs.length; i++) {
            if (chipInputs[i].checked) {
                selected.push(chipInputs[i]);
            }
        }
        for (var j = 0; j < selected.length; j++) {
            (function(inp) {
                var text = inp.parentNode ? (inp.parentNode.textContent || '').trim() : '';
                var pill = document.createElement('span');
                pill.className = 'tm-resv-pill';
                pill.textContent = text;
                var x = document.createElement('button');
                x.type = 'button';
                x.className = 'tm-resv-pill-remove';
                x.textContent = 'x';
                x.addEventListener('click', function() {
                    inp.checked = false;
                    renderPills();
                    syncPreferredClassroomBySelection();
                });
                pill.appendChild(x);
                pillBox.appendChild(pill);
            })(selected[j]);
        }
    }
    for (var i = 0; i < modeInputs.length; i++) {
        modeInputs[i].addEventListener('change', syncModeUi);
    }
    if (timeHourSelect) { timeHourSelect.addEventListener('change', syncModeUi); }
    if (timeMinuteSelect) { timeMinuteSelect.addEventListener('change', syncModeUi); }
    for (var c = 0; c < chipInputs.length; c++) {
        chipInputs[c].addEventListener('change', renderPills);
        chipInputs[c].addEventListener('change', syncPreferredClassroomBySelection);
        chipInputs[c].addEventListener('change', syncSplitPreview);
    }
    if (splitInput) {
        splitInput.addEventListener('input', syncSplitPreview);
        splitInput.addEventListener('change', syncSplitPreview);
    }
    syncModeUi();
    renderPills();

    var disclaimerBtn = document.getElementById('res-disclaimer-submit');
    if (disclaimerBtn) {
        disclaimerBtn.addEventListener('click', function(e) {
            var agree = document.querySelector('input[name=\"agree_disclaimer\"]');
            var alertBox = document.getElementById('res-disclaimer-alert');
            if (agree && !agree.checked) {
                e.preventDefault();
                if (alertBox) { alertBox.style.display = 'block'; }
                return false;
            }
            if (alertBox) { alertBox.style.display = 'none'; }
            return true;
        });
    }
})();
");

$resbatchisonline = ((string) $form['delivery_mode'] === \local_tm_course\session_manager::DELIVERY_ONLINE);
$resbatchjscfg = [
    'context' => 'reservation',
    'formId' => 'tm-reservation-form',
    'lookupBase' => (new moodle_url('/local/tm_course/batch_lookup.php'))->out(false),
    'sesskey' => sesskey(),
    'requireDiet' => !$resbatchisonline,
    'str' => [
        'batch_need_one_row' => get_string('batch_need_one_row', 'local_tm_course'),
        'error_batch_name_required' => get_string('error_batch_name_required', 'local_tm_course'),
        'error_institution_required' => get_string('error_institution_required', 'local_tm_course'),
        'error_batch_diet_required' => get_string('error_batch_diet_required', 'local_tm_course'),
        'batch_lookup_loading' => get_string('batch_lookup_loading', 'local_tm_course'),
        'batch_user_existing' => get_string('batch_user_existing', 'local_tm_course'),
        'batch_modal_email_not_registered' => get_string('reservation_batch_email_not_registered', 'local_tm_course'),
        'batch_modal_full_rows' => get_string('batch_modal_full_rows', 'local_tm_course'),
        'label_learner' => get_string('label_learner', 'local_tm_course'),
        'label_email' => get_string('label_email', 'local_tm_course'),
        'batch_user_type' => get_string('batch_user_type', 'local_tm_course'),
        'institution' => get_string('institution', 'local_tm_course'),
        'diet_survey_title' => get_string('diet_survey_title', 'local_tm_course'),
        'diet_choice_meat' => get_string('diet_choice_meat', 'local_tm_course'),
        'diet_choice_vegetarian' => get_string('diet_choice_vegetarian', 'local_tm_course'),
        'batch_modal_note_preview' => get_string('batch_modal_note_preview', 'local_tm_course'),
        'reservation_batch_context_title' => get_string('reservation_batch_context_title', 'local_tm_course'),
        'batch_prereq_warning_title' => get_string('batch_prereq_warning_title', 'local_tm_course'),
        'batch_prereq_met' => get_string('batch_prereq_met', 'local_tm_course'),
        'batch_prereq_not_met' => get_string('batch_prereq_not_met', 'local_tm_course'),
        'reservation_batch_prereq_account_missing' => get_string('reservation_batch_prereq_account_missing', 'local_tm_course'),
        'reservation_batch_prereq_summary' => get_string('reservation_batch_prereq_summary', 'local_tm_course', (object)[
            'met' => '{{met}}',
            'unmet' => '{{unmet}}',
        ]),
        'reservation_batch_prereq_excluded_hint' => get_string('reservation_batch_prereq_excluded_hint', 'local_tm_course'),
        'reservation_batch_prereq_will_include' => get_string('reservation_batch_prereq_will_include', 'local_tm_course'),
        'reservation_batch_prereq_need_courses' => get_string('reservation_batch_prereq_need_courses', 'local_tm_course'),
    ],
];
$resbatchjs_path = __DIR__ . '/../batch_enrol.js';
$resbatchjs_ver = file_exists($resbatchjs_path) ? filemtime($resbatchjs_path) : time();
?>
<script>window.tmBatchCfg=<?php echo json_encode($resbatchjscfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="<?php echo (new moodle_url('/local/tm_course/batch_enrol.js', ['v' => $resbatchjs_ver]))->out(); ?>"></script>
<?php
echo $OUTPUT->footer();
