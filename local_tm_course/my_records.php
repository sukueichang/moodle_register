<?php
/**
 * My learning and enrolment records page.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/enrolment_manager.php');
require_once(__DIR__ . '/classes/certificate_helper.php');
require_once(__DIR__ . '/classes/permissions_manager.php');

use local_tm_course\enrolment_manager;
use local_tm_course\certificate_helper;
use local_tm_course\permissions_manager;
use local_tm_course\session_manager;

require_login();
permissions_manager::require_view_access();
$PAGE->set_url(new moodle_url('/local/tm_course/my_records.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/local/tm_course/styles.css');

$records = array_values(enrolment_manager::get_user_records((int)$USER->id));
$certificates = certificate_helper::get_user_certificates((int)$USER->id);

$showdeskcol = false;
foreach ($records as $row) {
    if ((string)($row->session_delivery_mode ?? '') !== session_manager::DELIVERY_ONLINE) {
        $showdeskcol = true;
        break;
    }
}

$sm = get_string_manager();
$str = static function(string $key, string $fallback, $a = null) use ($sm): string {
    if ($sm->string_exists($key, 'local_tm_course')) {
        return get_string($key, 'local_tm_course', $a);
    }
    if ($a === null) {
        return $fallback;
    }
    if (is_scalar($a)) {
        return str_replace('{$a}', (string)$a, $fallback);
    }
    if (is_object($a)) {
        foreach (get_object_vars($a) as $k => $v) {
            $fallback = str_replace('{$a->' . $k . '}', (string)$v, $fallback);
        }
    }
    return $fallback;
};
$PAGE->set_title($str('nav_my_records', 'My learning and enrolment records'));

$learningstatusmeta = static function(\stdClass $r) use ($str): array {
    if ((int)$r->status === session_manager::ENROL_CANCELLED) {
        return ['closed', $str('learning_status_cancelled', 'Cancelled')];
    }
    if ((int)$r->status === session_manager::ENROL_REJECTED) {
        return ['rejected', $str('learning_status_rejected', 'Rejected')];
    }
    if ((int)$r->status === session_manager::ENROL_WAITLISTED) {
        return ['pending', $str('learning_status_waitlisted', 'Waitlisted')];
    }
    if ((int)$r->status === session_manager::ENROL_PENDING) {
        return ['pending', $str('learning_status_pending', 'Pending review')];
    }
    if ((int)$r->status === session_manager::ENROL_APPROVED) {
        if (isset($r->attended) && (int)$r->attended === \local_tm_course\attendance_manager::ATTEND_PRESENT) {
            return ['approved', $str('learning_status_completed', 'Completed')];
        }
        if (isset($r->attended) && (int)$r->attended === \local_tm_course\attendance_manager::ATTEND_ABSENT) {
            return ['rejected', $str('learning_status_absent', 'Absent')];
        }
        return ['approved', $str('learning_status_approved', 'Approved')];
    }
    return ['closed', get_string('session_status_unknown', 'local_tm_course')];
};

$enrolsourcelabel = static function(\stdClass $r) use ($str): string {
    if (!empty($r->submitter_firstname) || !empty($r->submitter_lastname)) {
        return trim((string)$r->submitter_firstname . ' ' . (string)$r->submitter_lastname);
    }
    return $str('enrol_source_self', 'Self-enrolment');
};

echo $OUTPUT->header();
?>
<div class="tm-page-header">
    <span class="tm-logo-dot"></span>
    <h2>🗂️ <?php echo $str('nav_my_records', 'My learning and enrolment records'); ?></h2>
</div>

<div class="tm-card mt-3"><div class="tm-card-body">
    <h4><?php echo $str('my_course_records', 'Course records'); ?></h4>
    <?php if (empty($records)): ?>
        <div class="tm-alert tm-alert-info"><?php echo get_string('search_no_results', 'local_tm_course'); ?></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="tm-table">
    <thead><tr>
        <th><?php echo get_string('session_name', 'local_tm_course'); ?></th>
        <th><?php echo get_string('label_start', 'local_tm_course'); ?></th>
        <?php if ($showdeskcol): ?><th><?php echo get_string('label_desk', 'local_tm_course'); ?></th><?php endif; ?>
        <th><?php echo $str('label_learning_status', 'Class status'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($records as $r): [$badgecls, $badgelbl] = $learningstatusmeta($r); ?>
    <tr>
        <td><?php echo s($r->session_name); ?></td>
        <td><?php echo userdate((int)$r->starttime, get_string('strftimedatetimeshort')); ?></td>
        <?php if ($showdeskcol): ?>
        <td><?php
            if ((string)($r->session_delivery_mode ?? '') === session_manager::DELIVERY_ONLINE) {
                echo '—';
            } else {
                echo (!empty($r->desk_number) && (int)$r->status === session_manager::ENROL_APPROVED)
                    ? get_string('desk_assigned_to', 'local_tm_course', (int)$r->desk_number) : '—';
            }
        ?></td>
        <?php endif; ?>
        <td><span class="tm-badge tm-badge-<?php echo $badgecls; ?>"><?php echo $badgelbl; ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    <?php endif; ?>
</div></div>

<div class="tm-card mt-3"><div class="tm-card-body">
    <h4><?php echo $str('my_enrolment_records', 'Enrolment records'); ?></h4>
    <?php if (empty($records)): ?>
        <div class="tm-alert tm-alert-info"><?php echo get_string('search_no_results', 'local_tm_course'); ?></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="tm-table">
    <thead><tr>
        <th><?php echo get_string('session_name', 'local_tm_course'); ?></th>
        <th><?php echo $str('label_enrol_source', 'Enrolment source'); ?></th>
        <th><?php echo get_string('label_applied_at', 'local_tm_course'); ?></th>
        <th><?php echo $str('label_learning_status', 'Class status'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($records as $r): [$badgecls, $badgelbl] = $learningstatusmeta($r); ?>
    <tr>
        <td><?php echo s($r->session_name); ?></td>
        <td><?php echo s($enrolsourcelabel($r)); ?></td>
        <td><?php echo !empty($r->timecreated) ? userdate((int)$r->timecreated, get_string('strftimedatetimeshort')) : '—'; ?></td>
        <td><span class="tm-badge tm-badge-<?php echo $badgecls; ?>"><?php echo $badgelbl; ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    <?php endif; ?>
</div></div>

<div class="tm-card mt-3"><div class="tm-card-body">
    <h4><?php echo $str('my_certificate_records', 'Certificate records'); ?></h4>
    <?php if (empty($certificates)): ?>
        <div class="tm-alert tm-alert-info"><?php echo get_string('search_no_results', 'local_tm_course'); ?></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="tm-table">
    <thead><tr>
        <th><?php echo $str('certificate_course_name', 'Course name'); ?></th>
        <th><?php echo $str('certificate_issue_time', 'Certificate issue time'); ?></th>
        <th><?php echo $str('label_certificate', 'Certificate'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($certificates as $cert): ?>
    <tr>
        <td><?php echo format_string((string)$cert->coursename); ?></td>
        <td><?php echo !empty($cert->timecreated)
            ? userdate((int)$cert->timecreated, get_string('strftimedatetimeshort'))
            : '—'; ?></td>
        <td><?php
            echo html_writer::link(
                (new moodle_url('/mod/customcert/view.php', ['id' => (int)$cert->cmid, 'downloadown' => 1]))->out(false),
                $str('download_certificate', 'Download Certificate'),
                ['class' => 'btn btn-secondary btn-sm']
            );
        ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    <?php endif; ?>
</div></div>

<?php
echo $OUTPUT->footer();
