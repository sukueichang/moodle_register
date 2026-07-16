<?php
/**
 * Shared roster markup for attendance page (desk grid / online table + per-learner actions).
 *
 * Expects: $view from enrolment_manager::build_session_attendance_view(),
 *          $attendanceformaction POST target URL (string),
 *          $sessionid (int)
 *
 * @package    local_tm_course
 */
defined('MOODLE_INTERNAL') || die();

use local_tm_course\attendance_manager;

if (!isset($attendanceformaction)) {
    $attendanceformaction = '';
}
if (!isset($sessionid)) {
    $sessionid = 0;
}
if (!isset($attendance_can_edit_diet)) {
    $attendance_can_edit_diet = false;
}

/**
 * @param string $action present|absent
 * @param int $enrolid
 * @param string $label
 * @param string $btnclass
 * @param string $confirmkey lang string key for confirm()
 */
$render_mark_form = static function (
    string $action,
    int $enrolid,
    string $label,
    string $btnclass,
    string $confirmkey
) use ($attendanceformaction, $sessionid): void {
    if ($enrolid < 1 || $attendanceformaction === '') {
        return;
    }
    $confirm = get_string($confirmkey, 'local_tm_course');
    echo '<form method="post" action="' . s($attendanceformaction) . '" class="tm-attendance-action-form d-inline">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="sessionid" value="' . (int) $sessionid . '">';
    echo '<input type="hidden" name="action" value="' . s($action) . '">';
    echo '<input type="hidden" name="enrolid" value="' . $enrolid . '">';
    echo '<button type="submit" class="btn btn-sm ' . $btnclass . ' py-0 px-1"'
        . ' onclick="return confirm(' . json_encode($confirm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ');">'
        . s($label) . '</button>';
    echo '</form>';
};

/**
 * @param array $learner
 */
$render_attendance_status = static function (array $learner): void {
    $attended = (int) ($learner['attended'] ?? 0);
    if ($attended === attendance_manager::ATTEND_PRESENT) {
        echo '<div class="tm-attendance-status tm-attendance-status-present" role="status">';
        echo '<span class="tm-attendance-status-icon" aria-hidden="true">✓</span>';
        echo '<span>' . s(get_string('attendance_status_present', 'local_tm_course')) . '</span>';
        echo '</div>';
    } else if ($attended === attendance_manager::ATTEND_ABSENT) {
        echo '<div class="tm-attendance-status tm-attendance-status-absent" role="status">';
        echo '<span class="tm-attendance-status-icon" aria-hidden="true">✗</span>';
        echo '<span>' . s(get_string('attendance_status_absent', 'local_tm_course')) . '</span>';
        echo '</div>';
    }
};

/**
 * @param array $learner
 */
$render_attendance_actions = static function (array $learner) use ($render_mark_form): void {
    $enrolid = (int) ($learner['enrolid'] ?? 0);
    $attended = (int) ($learner['attended'] ?? 0);
    if ($enrolid < 1) {
        return;
    }
    echo '<span class="tm-attendance-actions ml-2">';
    if ($attended !== attendance_manager::ATTEND_PRESENT) {
        $render_mark_form(
            'present',
            $enrolid,
            '✓ ' . get_string('attendance_mark_present', 'local_tm_course'),
            'btn-tm-success',
            'attendance_mark_present_confirm'
        );
        echo ' ';
    }
    if ($attended !== attendance_manager::ATTEND_ABSENT) {
        $render_mark_form(
            'absent',
            $enrolid,
            '✗ ' . get_string('attendance_mark_absent', 'local_tm_course'),
            'btn-tm-danger',
            'attendance_mark_absent_confirm'
        );
    }
    echo '</span>';
};

/**
 * @param array $learner
 */
$render_diet_line = static function (array $learner) use ($attendance_can_edit_diet): void {
    $enrolid = (int) ($learner['enrolid'] ?? 0);
    $choice = strtoupper(trim((string) ($learner['diet_choice'] ?? '')));
    $note = (string) ($learner['diet_special_note'] ?? '');
    $label = (string) ($learner['diet_label'] ?? '—');
    if ($label === '' || $label === '—') {
        $label = get_string('attendance_diet_no_choice_label', 'local_tm_course');
    }

    $classes = 'tm-attendance-diet-line d-block';
    $attrs = ' data-enrolid="' . $enrolid . '"'
        . ' data-diet-choice="' . s($choice) . '"'
        . ' data-diet-note="' . s($note) . '"';
    if ($attendance_can_edit_diet && $enrolid > 0) {
        $classes .= ' tm-attendance-diet-editable';
        $attrs .= ' title="' . s(get_string('attendance_diet_click_edit', 'local_tm_course')) . '"'
            . ' tabindex="0" role="button"';
    }
    echo '<span class="' . $classes . '"' . $attrs . '>';
    echo '<span class="tm-attendance-diet-label">' . s($label) . '</span>';
    echo '</span>';
};

/**
 * @param array $learner
 */
$render_learner_block = static function (array $learner) use (
    $render_attendance_actions,
    $render_attendance_status,
    $render_diet_line
): void {
    echo '<li class="tm-roster-learner-item tm-attendance-learner-item">';
    echo '<div class="d-flex flex-wrap align-items-start">';
    echo '<div class="flex-grow-1">';
    echo '<div class="tm-attendance-name-row">';
    echo '<span class="tm-roster-learner-name">' . s($learner['displayname']) . '</span>';
    $render_attendance_actions($learner);
    echo '</div>';
    $render_diet_line($learner);
    $render_attendance_status($learner);
    if (!empty($learner['institution'])) {
        echo '<span class="text-muted small d-block">' . s($learner['institution']) . '</span>';
    }
    echo '<span class="text-muted small d-block tm-roster-source">' . s($learner['source_label']) . '</span>';
    echo '</div></div></li>';
};

if ((int) ($view['total'] ?? 0) === 0): ?>
    <div class="tm-alert tm-alert-info"><?php echo get_string('attendance_no_students', 'local_tm_course'); ?></div>
<?php elseif (!empty($view['is_online'])): ?>
    <p class="small text-muted"><?php echo get_string('session_roster_online_hint', 'local_tm_course'); ?></p>
    <table class="tm-table">
        <thead>
            <tr>
                <th><?php echo get_string('institution', 'local_tm_course'); ?></th>
                <th><?php echo get_string('label_learner_name', 'local_tm_course'); ?></th>
                <th><?php echo get_string('label_enrol_source', 'local_tm_course'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($view['online_groups'] as $instlabel => $group): ?>
            <?php foreach ($group as $idx => $learner): ?>
            <tr>
                <td><?php echo $idx === 0 ? s($instlabel) : ''; ?></td>
                <td>
                    <div class="tm-attendance-name-row">
                        <span class="tm-roster-learner-name"><?php echo s($learner['displayname']); ?></span>
                        <?php $render_attendance_actions($learner); ?>
                    </div>
                    <?php $render_diet_line($learner); ?>
                    <?php $render_attendance_status($learner); ?>
                </td>
                <td><?php echo s($learner['source_label']); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="tm-roster-grid">
    <?php foreach ($view['desks'] as $desk): ?>
        <?php
        $count = count($desk['learners']);
        $cap = (int) $desk['capacity'];
        ?>
        <div class="tm-roster-desk-card">
            <div class="tm-roster-desk-head">
                <strong><?php echo get_string('session_roster_desk_heading', 'local_tm_course', (object) ['n' => (int) $desk['desk_number']]); ?></strong>
                <span class="text-muted small"><?php echo get_string('session_roster_desk_count', 'local_tm_course', (object) ['n' => $count, 'cap' => $cap]); ?></span>
            </div>
            <?php if ($count === 0): ?>
                <p class="text-muted small mb-0"><?php echo get_string('session_roster_desk_empty', 'local_tm_course'); ?></p>
            <?php else: ?>
                <ul class="tm-roster-learner-list mb-0 pl-3">
                <?php foreach ($desk['learners'] as $learner): ?>
                    <?php $render_learner_block($learner); ?>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>

    <?php if (!empty($view['unassigned'])): ?>
    <div class="tm-roster-unassigned mt-4">
        <h4 class="h5"><?php echo get_string('session_roster_unassigned_heading', 'local_tm_course'); ?></h4>
        <ul class="tm-roster-learner-list mb-0 pl-3">
        <?php foreach ($view['unassigned'] as $learner): ?>
            <?php $render_learner_block($learner); ?>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
<?php endif; ?>
