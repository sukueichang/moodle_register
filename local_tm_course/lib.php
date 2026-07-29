<?php
/**
 * lib.php — Moodle hook callbacks for local_tm_course
 * @package    local_tm_course
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Release label from version.php (e.g. "5.15.8 (2026071503)").
 *
 * @return array{release:string,version:int,label:string}
 */
function local_tm_course_plugin_version_info(): array {
    $plugin = new stdClass();
    include(__DIR__ . '/version.php');
    $release = isset($plugin->release) ? (string) $plugin->release : '';
    $version = isset($plugin->version) ? (int) $plugin->version : 0;
    $label = $release !== '' ? $release : (string) $version;
    if ($release !== '' && $version > 0) {
        $label = $release . ' (' . $version . ')';
    }
    return [
        'release' => $release,
        'version' => $version,
        'label' => $label,
    ];
}

/**
 * Inject local_tm_course stylesheet in <head> safely.
 *
 * @return string
 */
function local_tm_course_before_standard_html_head(): string {
    if (!isloggedin() || isguestuser()) {
        return '';
    }
    require_once(__DIR__ . '/classes/permissions_manager.php');
    if (!\local_tm_course\permissions_manager::user_can_view_tm_course()) {
        return '';
    }
    $href = (new moodle_url('/local/tm_course/styles.css'))->out(false);
    return html_writer::empty_tag('link', [
        'rel' => 'stylesheet',
        'type' => 'text/css',
        'href' => $href,
    ]);
}

/**
 * Extend the navigation tree with a left-side menu entry.
 * Appears under "Site administration" for admins,
 * and as a top-level item for all logged-in users.
 */
function local_tm_course_extend_navigation(global_navigation $nav): void {
    global $USER, $CFG;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    require_once(__DIR__ . '/classes/permissions_manager.php');
    if (!class_exists('local_tm_course\permissions_manager', false)
        && class_exists('local_tm_course_beta\permissions_manager', false)) {
        class_alias('local_tm_course_beta\permissions_manager', 'local_tm_course\permissions_manager');
    }
    if (!\local_tm_course\permissions_manager::user_can_view_tm_course()) {
        return;
    }

    $sm = get_string_manager();
    $safe = static function(string $key, string $fallback) use ($sm): string {
        return $sm->string_exists($key, 'local_tm_course') ? get_string($key, 'local_tm_course') : $fallback;
    };

    // Main entry: Course Registration Management
    $node = $nav->add(
        get_string('nav_manage', 'local_tm_course'),
        new moodle_url('/local/tm_course/index.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'tm_course',
        new pix_icon('i/course', '')
    );
    $node->showinflatnavigation = true;
    $node->force_open();

    // Sub-item: My records (all logged-in users).
    $node->add(
        $safe('nav_my_records', 'My learning and enrolment records'),
        new moodle_url('/local/tm_course/my_records.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'tm_course_my_records'
    );

    // M4: Batch enrolment (capability or auto-rule).
    // 搜尋紀錄不放在左側導覽（依站台慣例僅管理員可見左欄）；改由首頁 Dashboard 按鈕進入。
    if (\local_tm_course\permissions_manager::user_can_batch_enrol()) {
        $node->add(
            get_string('nav_batch_enrol', 'local_tm_course'),
            new moodle_url('/local/tm_course/batch_enrol.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'tm_course_batch_enrol'
        );
        $node->add(
            get_string('nav_reservation', 'local_tm_course'),
            new moodle_url('/local/tm_course/reservation/index.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'tm_course_reservation'
        );
    }

    // Admin-only sub-items
    if (has_capability('local/tm_course:manage', context_system::instance())) {
        $node->add(get_string('nav_classrooms', 'local_tm_course'), new moodle_url('/local/tm_course/classroom/index.php'), navigation_node::TYPE_CUSTOM, null, 'tm_course_classrooms');
        $node->add(get_string('nav_course_mapping', 'local_tm_course'), new moodle_url('/local/tm_course/settings/course_mapping.php'), navigation_node::TYPE_CUSTOM, null, 'tm_course_mapping');
        if (is_siteadmin()) {
            $node->add(
                $safe('nav_moodle_optimization', 'Moodle optimization'),
                new moodle_url('/local/tm_course/settings/moodle_optimization.php'),
                navigation_node::TYPE_CUSTOM,
                null,
                'tm_course_moodle_optimization'
            );
        }
        $node->add(get_string('nav_permissions', 'local_tm_course'), new moodle_url('/local/tm_course/settings/permissions.php'), navigation_node::TYPE_CUSTOM, null, 'tm_course_permissions');
        $node->add(get_string('nav_notifications', 'local_tm_course'), new moodle_url('/local/tm_course/settings/notifications.php'), navigation_node::TYPE_CUSTOM, null, 'tm_course_notifications');
        $node->add(get_string('nav_m5_settings', 'local_tm_course'), new moodle_url('/admin/settings.php', ['section' => 'local_tm_course_m5']), navigation_node::TYPE_CUSTOM, null, 'tm_course_m5');
        $node->add(get_string('nav_dashboard_settings', 'local_tm_course'), new moodle_url('/admin/settings.php', ['section' => 'local_tm_course_dashboard']), navigation_node::TYPE_CUSTOM, null, 'tm_course_dashboard_settings');
        $node->add(get_string('nav_sessions', 'local_tm_course'), new moodle_url('/local/tm_course/admin/sessions.php'), navigation_node::TYPE_CUSTOM, null, 'tm_course_sessions');
        $node->add(get_string('nav_enrolments', 'local_tm_course'), new moodle_url('/local/tm_course/admin/review_center.php'), navigation_node::TYPE_CUSTOM, null, 'tm_course_enrolments');
        $node->add(get_string('nav_class_prep', 'local_tm_course'), new moodle_url('/local/tm_course/admin/sessions.php'), navigation_node::TYPE_CUSTOM, null, 'tm_course_attendance');
        $node->add(get_string('equipment_check_manage_title', 'local_tm_course'), new moodle_url('/local/tm_course/settings/equipment_check_items.php'), navigation_node::TYPE_CUSTOM, null, 'tm_course_equipment_check');
    }
}

/**
 * Extend flat navigation (boost theme sidebar).
 */
function local_tm_course_extend_navigation_frontpage(navigation_node $node): void {
    // Nothing extra needed here — handled by extend_navigation
}

/**
 * Add TM Course entries to the settings (cog) menu on the site home / front page.
 * Lets administrators open the plugin without going only through Site administration.
 *
 * @param settings_navigation $settingsnav
 * @param context               $context Page context when the menu is built
 */
function local_tm_course_extend_settings_navigation(settings_navigation $settingsnav, context $context): void {
    global $PAGE;

    if (!has_capability('local/tm_course:manage', context_system::instance())) {
        return;
    }

    // Site home viewed as a course (Boost cog menu): course context + SITEID.
    $on_site_home = ($context->contextlevel === CONTEXT_COURSE && (int) $context->instanceid === (int) SITEID);
    // Site admin → Appearance → Front page (same screen uses system context in core).
    $on_frontpage_settings = ($context->contextlevel === CONTEXT_SYSTEM
        && $PAGE->url->compare(new moodle_url('/admin/settings.php', ['section' => 'frontpagesettings'])));

    if (!$on_site_home && !$on_frontpage_settings) {
        return;
    }

    $root = $settingsnav->add(
        get_string('nav_manage', 'local_tm_course'),
        new moodle_url('/local/tm_course/index.php'),
        navigation_node::TYPE_SETTING,
        null,
        'tm_course_fp_manage',
        new pix_icon('i/course', '')
    );

    $root->add(get_string('nav_classrooms', 'local_tm_course'), new moodle_url('/local/tm_course/classroom/index.php'), navigation_node::TYPE_SETTING, null, 'tm_course_fp_classrooms');
    $root->add(get_string('nav_course_mapping', 'local_tm_course'), new moodle_url('/local/tm_course/settings/course_mapping.php'), navigation_node::TYPE_SETTING, null, 'tm_course_fp_mapping');
    if (is_siteadmin()) {
        $root->add(
            get_string('nav_moodle_optimization', 'local_tm_course'),
            new moodle_url('/local/tm_course/settings/moodle_optimization.php'),
            navigation_node::TYPE_SETTING,
            null,
            'tm_course_fp_moodle_optimization'
        );
    }
    $root->add(get_string('nav_permissions', 'local_tm_course'), new moodle_url('/local/tm_course/settings/permissions.php'), navigation_node::TYPE_SETTING, null, 'tm_course_fp_permissions');
    $root->add(get_string('nav_notifications', 'local_tm_course'), new moodle_url('/local/tm_course/settings/notifications.php'), navigation_node::TYPE_SETTING, null, 'tm_course_fp_notifications');
    $root->add(get_string('nav_m5_settings', 'local_tm_course'), new moodle_url('/admin/settings.php', ['section' => 'local_tm_course_m5']), navigation_node::TYPE_SETTING, null, 'tm_course_fp_m5');
    $root->add(get_string('nav_dashboard_settings', 'local_tm_course'), new moodle_url('/admin/settings.php', ['section' => 'local_tm_course_dashboard']), navigation_node::TYPE_SETTING, null, 'tm_course_fp_dashboard_settings');
    $root->add(get_string('nav_sessions', 'local_tm_course'), new moodle_url('/local/tm_course/admin/sessions.php'), navigation_node::TYPE_SETTING, null, 'tm_course_fp_sessions');
    $root->add(get_string('nav_enrolments', 'local_tm_course'), new moodle_url('/local/tm_course/admin/review_center.php'), navigation_node::TYPE_SETTING, null, 'tm_course_fp_enrolments');
    $root->add(get_string('nav_class_prep', 'local_tm_course'), new moodle_url('/local/tm_course/admin/sessions.php'), navigation_node::TYPE_SETTING, null, 'tm_course_fp_attendance');
    $root->add(get_string('equipment_check_manage_title', 'local_tm_course'), new moodle_url('/local/tm_course/settings/equipment_check_items.php'), navigation_node::TYPE_SETTING, null, 'tm_course_fp_equipment_check');
}

/**
 * Dashboard widget visibility switch with role-aware override.
 *
 * @param string $widgetkey
 * @param string $audience user|sales|admin
 * @return bool
 */
function local_tm_course_dashboard_widget_enabled(string $widgetkey, string $audience = 'user'): bool {
    if (!in_array($audience, ['user', 'sales', 'admin'], true)) {
        $audience = 'user';
    }
    $rolespecific = get_config('local_tm_course', 'dashboard_widget_' . $audience . '_' . $widgetkey);
    if ($rolespecific !== false && $rolespecific !== null && $rolespecific !== '') {
        return (int)$rolespecific === 1;
    }
    // Backward compatibility with old global key.
    $legacy = get_config('local_tm_course', 'dashboard_widget_' . $widgetkey);
    if ($legacy !== false && $legacy !== null && $legacy !== '') {
        return (int)$legacy === 1;
    }
    return true;
}

/**
 * Serve local_tm_course files.
 */
function local_tm_course_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel !== CONTEXT_SYSTEM) {
        send_file_not_found();
    }
    if (!isloggedin() || isguestuser()) {
        send_file_not_found();
    }
    if ($filearea !== 'resvcheck') {
        send_file_not_found();
    }
    if (!has_capability('local/tm_course:approve', $context) &&
        !\local_tm_course\permissions_manager::user_can_batch_enrol() &&
        !is_siteadmin()) {
        send_file_not_found();
    }
    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = '/' . implode('/', $args) . '/';
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_tm_course', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }
    send_stored_file($file, 0, 0, (bool)$forcedownload, $options);
}

/**
 * Render dashboard section in frontpage main content (Plan A: no core patch).
 *
 * @return string HTML injected near top of body.
 */
function local_tm_course_before_standard_top_of_body_html(): string {
    global $PAGE, $USER, $DB;

    if (!isloggedin() || isguestuser()) {
        return '';
    }
    if (empty($PAGE) || (string)($PAGE->pagelayout ?? '') !== 'frontpage') {
        return '';
    }

    $PAGE->requires->js('/local/tm_course/local_time_display.js');

    require_once(__DIR__ . '/classes/permissions_manager.php');
    if (!class_exists('local_tm_course\permissions_manager', false)
        && class_exists('local_tm_course_beta\permissions_manager', false)) {
        class_alias('local_tm_course_beta\permissions_manager', 'local_tm_course\permissions_manager');
    }
    if (!\local_tm_course\permissions_manager::user_can_view_tm_course()) {
        return '';
    }

    require_once(__DIR__ . '/classes/user_dashboard_helper.php');
    require_once(__DIR__ . '/classes/enrolment_manager.php');
    require_once(__DIR__ . '/classes/permissions_manager.php');
    if (!class_exists('local_tm_course\permissions_manager', false)
        && class_exists('local_tm_course_beta\permissions_manager', false)) {
        class_alias('local_tm_course_beta\permissions_manager', 'local_tm_course\permissions_manager');
    }

    $issiteadmin = is_siteadmin($USER);
    $canreservation = $issiteadmin || \local_tm_course\permissions_manager::user_can_batch_enrol();
    $visiblecfg = get_config('local_tm_course', 'front_dashboard_visible');
    $isvisible = ($visiblecfg === false) ? 1 : (int)$visiblecfg;
    if (!$isvisible && !$issiteadmin) {
        return '';
    }
    $positioncfg = (string)(get_config('local_tm_course', 'front_dashboard_position') ?: 'afternews');
    if (!in_array($positioncfg, ['aftertitle', 'afternews', 'bottom'], true)) {
        $positioncfg = 'afternews';
    }

    $uid = (int)$USER->id;
    $upcoming = \local_tm_course\user_dashboard_helper::get_upcoming_sessions($uid);
    $pending = \local_tm_course\user_dashboard_helper::get_pending_requests($uid);
    $audience = $issiteadmin ? 'admin' : (\local_tm_course\permissions_manager::user_can_batch_enrol() ? 'sales' : 'user');
    $recentlimit = ($audience === 'sales') ? 3 : 5;
    $recentreservation = $canreservation
        ? \local_tm_course\user_dashboard_helper::get_recent_reservation_requests($uid, $recentlimit)
        : [];
    $recentbatch = $canreservation
        ? \local_tm_course\user_dashboard_helper::get_recent_batch_submissions($uid, $recentlimit)
        : [];
    $showupcoming = local_tm_course_dashboard_widget_enabled('upcoming', $audience);
    $showpending = local_tm_course_dashboard_widget_enabled('pending', $audience);
    $showrecentreservation = $canreservation && local_tm_course_dashboard_widget_enabled('recent_reservation', $audience);
    $showrecentbatch = $canreservation && local_tm_course_dashboard_widget_enabled('recent_batch', $audience);
    $showcalendar = local_tm_course_dashboard_widget_enabled('calendar', $audience);
    $recentreservationcoursenames = [];
    if (!empty($recentreservation)) {
        $courseids = [];
        foreach ($recentreservation as $row) {
            $decoded = json_decode((string)($row->courseids_json ?? '[]'), true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $cid) {
                $cid = (int)$cid;
                if ($cid > 0) {
                    $courseids[$cid] = $cid;
                }
            }
        }
        if (!empty($courseids)) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_values($courseids), SQL_PARAMS_NAMED);
            $recentreservationcoursenames = $DB->get_records_sql_menu(
                "SELECT id, fullname FROM {course} WHERE id $insql",
                $inparams
            );
        }
    }
    $targeturl = (new moodle_url('/local/tm_course/index.php'))->out(false);
    $reservationurl = (new moodle_url('/local/tm_course/reservation/index.php'))->out(false);
    $returnurl = $PAGE->url->out(false);
    $ctrlbaseparams = ['return' => $returnurl, 'sesskey' => sesskey()];

    $out = html_writer::start_div('tm-home-dashboard-wrapper');
    $out .= html_writer::start_div('tm-card');
    $out .= html_writer::start_div('tm-card-body');

    $out .= html_writer::start_div('tm-dashboard-head');
    $out .= html_writer::tag('h3', '🧭 ' . get_string('dashboard_home_title', 'local_tm_course'),
        ['class' => 'tm-dashboard-section-title tm-dashboard-home-title mb-2']);
    if ($issiteadmin) {
        $out .= html_writer::start_div('tm-dashboard-admin-controls');
        // Visible toggle.
        if ($isvisible) {
            $hideurl = new moodle_url('/local/tm_course/dashboard_control.php', $ctrlbaseparams + ['action' => 'hide']);
            $out .= html_writer::link($hideurl->out(false), get_string('dashboard_hide_for_users', 'local_tm_course'),
                ['class' => 'btn btn-sm tm-dashboard-btn']);
        } else {
            $showurl = new moodle_url('/local/tm_course/dashboard_control.php', $ctrlbaseparams + ['action' => 'show']);
            $out .= html_writer::link($showurl->out(false), get_string('dashboard_show_for_users', 'local_tm_course'),
                ['class' => 'btn btn-sm tm-dashboard-btn']);
        }
        // Position buttons.
        $positions = [
            'aftertitle' => get_string('dashboard_pos_aftertitle', 'local_tm_course'),
            'afternews' => get_string('dashboard_pos_afternews', 'local_tm_course'),
            'bottom' => get_string('dashboard_pos_bottom', 'local_tm_course'),
        ];
        foreach ($positions as $code => $label) {
            $cls = 'btn btn-sm tm-dashboard-btn' . ($positioncfg === $code ? ' tm-dashboard-btn-active' : '');
            $purl = new moodle_url('/local/tm_course/dashboard_control.php', $ctrlbaseparams + ['action' => 'position', 'position' => $code]);
            $out .= html_writer::link($purl->out(false), $label, ['class' => $cls]);
        }
        $out .= html_writer::end_div();
    }
    $out .= html_writer::end_div();

    if (!$isvisible && $issiteadmin) {
        $out .= html_writer::div(get_string('dashboard_hidden_admin_hint', 'local_tm_course'), 'tm-dashboard-empty');
    }

    $sm = get_string_manager();
    $dashboardmyrecordslabel = $sm->string_exists('dashboard_my_records', 'local_tm_course')
        ? get_string('dashboard_my_records', 'local_tm_course')
        : 'My learning and enrolment records';

    $out .= html_writer::start_div('tm-dashboard-action-groups');
    // 學習與報名：探索、我的紀錄、搜尋（業務）
    $out .= html_writer::start_div('tm-dashboard-action-group');
    $out .= html_writer::tag('h4', get_string('dashboard_group_learning', 'local_tm_course'),
        ['class' => 'tm-dashboard-group-title']);
    $out .= html_writer::start_div('tm-dashboard-action-row');
    $out .= html_writer::link($targeturl, get_string('dashboard_explore_cta', 'local_tm_course'),
        ['class' => 'btn tm-dashboard-btn']);
    $out .= html_writer::link((new moodle_url('/local/tm_course/my_records.php'))->out(false), $dashboardmyrecordslabel,
        ['class' => 'btn tm-dashboard-btn']);
    if (\local_tm_course\permissions_manager::user_can_batch_enrol()) {
        $out .= html_writer::link((new moodle_url('/local/tm_course/search.php'))->out(false),
            get_string('nav_search', 'local_tm_course'),
            ['class' => 'btn tm-dashboard-btn']);
    }
    $out .= html_writer::end_div();
    $out .= html_writer::end_div();

    if ($canreservation) {
        $out .= html_writer::start_div('tm-dashboard-action-group');
        $out .= html_writer::tag('h4', get_string('dashboard_group_applications', 'local_tm_course'),
            ['class' => 'tm-dashboard-group-title']);
        $out .= html_writer::start_div('tm-dashboard-action-row');
        $out .= html_writer::link($reservationurl, get_string('dashboard_reservation_cta', 'local_tm_course'),
            ['class' => 'btn tm-dashboard-btn']);
        $out .= html_writer::link((new moodle_url('/local/tm_course/reservation/tracking.php'))->out(false),
            get_string('dashboard_reservation_tracking_cta', 'local_tm_course'),
            ['class' => 'btn tm-dashboard-btn']);
        $out .= html_writer::end_div();
        $out .= html_writer::end_div();
    }

    if ($issiteadmin) {
        $out .= html_writer::start_div('tm-dashboard-action-group');
        $out .= html_writer::tag('h4', get_string('dashboard_group_operations', 'local_tm_course'),
            ['class' => 'tm-dashboard-group-title']);
        $out .= html_writer::start_div('tm-dashboard-action-row');
        $out .= html_writer::link((new moodle_url('/local/tm_course/admin/sessions.php'))->out(false),
            get_string('dashboard_admin_sessions', 'local_tm_course'), ['class' => 'btn tm-dashboard-btn']);
        $out .= html_writer::link((new moodle_url('/local/tm_course/classroom/index.php'))->out(false),
            get_string('dashboard_admin_classrooms', 'local_tm_course'), ['class' => 'btn tm-dashboard-btn']);
        $out .= html_writer::link((new moodle_url('/local/tm_course/settings/course_mapping.php'))->out(false),
            get_string('dashboard_admin_mapping', 'local_tm_course'), ['class' => 'btn tm-dashboard-btn']);
        $out .= html_writer::link((new moodle_url('/local/tm_course/admin/review_center.php'))->out(false),
            get_string('dashboard_admin_approvals', 'local_tm_course'), ['class' => 'btn tm-dashboard-btn']);
        $out .= html_writer::end_div();
        $out .= html_writer::end_div();
    }
    $out .= html_writer::end_div();

    if ($showupcoming) {
        $out .= html_writer::tag('h4', get_string('dashboard_section_upcoming', 'local_tm_course'),
            ['class' => 'tm-dashboard-section-title']);
        if (empty($upcoming)) {
            $out .= html_writer::div(get_string('dashboard_empty_upcoming', 'local_tm_course'), 'tm-dashboard-empty');
        } else {
            $out .= html_writer::start_div('tm-dashboard-card-grid-5');
            foreach ($upcoming as $row) {
                $name = format_string($row->name ?? '');
                $when = userdate((int)$row->starttime, get_string('strftimedatetimeshort'));
                $sessiononline = ((string)($row->delivery_mode ?? '') === \local_tm_course\session_manager::DELIVERY_ONLINE);
                if ($sessiononline) {
                    $loc = get_string('delivery_mode_online', 'local_tm_course');
                } else {
                    $loc = trim((string)($row->location ?? ''));
                }
                $desk = !empty($row->desk_number)
                    ? get_string('desk_assigned_to', 'local_tm_course', (int)$row->desk_number)
                    : '—';
                $diet = \local_tm_course\enrolment_manager::format_diet_summary($row);
                if (!$sessiononline) {
                    if ($loc === '') {
                        $loc = '—';
                    } else {
                        $loc = format_string($loc);
                    }
                }
                $card = html_writer::tag('div', $name, ['class' => 'tm-dashboard-card-title']);
                $card .= html_writer::div(
                    html_writer::tag('strong', get_string('dashboard_label_start', 'local_tm_course') . ': ')
                    . html_writer::span($when, 'js-tm-local-time', [
                        'data-tm-local-mode' => 'starts',
                        'data-tm-start-ts' => (int)$row->starttime,
                    ]),
                    'tm-dashboard-meta'
                );
                $locline = html_writer::tag('strong',
                    get_string('dashboard_label_location', 'local_tm_course') . ': ');
                if ($sessiononline) {
                    $locline .= html_writer::span($loc, 'tm-session-location-online');
                    $meetingbtn = \local_tm_course\user_dashboard_helper::render_join_meeting_button(
                        (string) ($row->meeting_link ?? ''),
                        ''
                    );
                    if ($meetingbtn !== '') {
                        $locline .= ' ' . $meetingbtn;
                    }
                } else {
                    $locline .= $loc;
                }
                $card .= html_writer::div($locline, 'tm-dashboard-meta tm-session-location-line');
                if (!$sessiononline) {
                    $card .= html_writer::div(html_writer::tag('strong',
                        get_string('label_desk', 'local_tm_course') . ': ') . $desk, 'tm-dashboard-meta');
                    $card .= html_writer::div(html_writer::tag('strong',
                        get_string('diet_survey_title', 'local_tm_course') . ': ') . $diet, 'tm-dashboard-meta');
                }
                $card .= html_writer::start_div('tm-dashboard-card-actions');
                $card .= html_writer::link(
                    (new moodle_url('/local/tm_course/enrol_cancel.php', ['enrolid' => (int)$row->enrolmentid]))->out(false),
                    get_string('cancel_enrolment', 'local_tm_course'),
                    ['class' => 'btn btn-sm tm-dashboard-btn tm-dashboard-btn-danger']
                );
                if (!$sessiononline) {
                    $card .= html_writer::link(
                        (new moodle_url('/local/tm_course/enrol_diet_edit.php', ['enrolid' => (int)$row->enrolmentid]))->out(false),
                        get_string('change_diet_habit', 'local_tm_course'),
                        ['class' => 'btn btn-sm tm-dashboard-btn']
                    );
                }
                $card .= html_writer::end_div();
                $out .= html_writer::div($card, 'tm-session-card tm-dashboard-card-upcoming');
            }
            $out .= html_writer::end_div();
        }
    }

    if ($showpending) {
        $out .= html_writer::tag('h4', get_string('dashboard_section_pending', 'local_tm_course'),
            ['class' => 'tm-dashboard-section-title mt-3']);
        if (empty($pending)) {
            $out .= html_writer::div(get_string('dashboard_empty_pending', 'local_tm_course'), 'tm-dashboard-empty');
        } else {
            $out .= html_writer::start_div('tm-dashboard-card-stack');
            foreach ($pending as $row) {
                $name = format_string($row->sessionname ?? '');
                $when = userdate((int)$row->starttime, get_string('strftimedatetimeshort'));
                $loc = trim((string)($row->location ?? ''));
                if ($loc === '') {
                    $loc = '—';
                } else {
                    $loc = format_string($loc);
                }
                $head = html_writer::span(get_string('dashboard_badge_pending', 'local_tm_course'),
                    'tm-dashboard-pending-badge');
                $head .= html_writer::tag('div', $name, ['class' => 'tm-dashboard-pending-title']);
                $card = html_writer::div($head, 'tm-dashboard-pending-head');
                $card .= html_writer::div(
                    html_writer::tag('strong', get_string('dashboard_label_start', 'local_tm_course') . ': ')
                    . html_writer::span($when, 'js-tm-local-time', [
                        'data-tm-local-mode' => 'starts',
                        'data-tm-start-ts' => (int)$row->starttime,
                    ]),
                    'tm-dashboard-meta'
                );
                $card .= html_writer::div(html_writer::tag('strong',
                    get_string('dashboard_label_location', 'local_tm_course') . ': ') . $loc, 'tm-dashboard-meta');
                $out .= html_writer::div($card, 'tm-dashboard-pending-card');
            }
            $out .= html_writer::end_div();
        }
    }

    if ($showrecentreservation) {
        $out .= html_writer::tag('h4', get_string('dashboard_section_recent_reservation', 'local_tm_course'),
            ['class' => 'tm-dashboard-section-title mt-3']);
        if (empty($recentreservation)) {
            $out .= html_writer::div(get_string('dashboard_empty_recent_reservation', 'local_tm_course'), 'tm-dashboard-empty');
        } else {
            $out .= html_writer::start_div('tm-dashboard-card-stack');
            foreach ($recentreservation as $row) {
                $status = (int)$row->status;
                $statuslabel = get_string('enrol_pending', 'local_tm_course');
                if ($status === 1) {
                    $statuslabel = get_string('enrol_approved', 'local_tm_course');
                } else if ($status === 2) {
                    $statuslabel = get_string('enrol_rejected', 'local_tm_course');
                }
                $updated = userdate((int)max((int)$row->timemodified, (int)$row->timecreated), get_string('strftimedatetimeshort'));
                $coursenames = [];
                $courseids = json_decode((string)($row->courseids_json ?? '[]'), true);
                if (is_array($courseids)) {
                    foreach ($courseids as $cid) {
                        $cid = (int)$cid;
                        if ($cid > 0) {
                            $coursenames[] = format_string((string)($recentreservationcoursenames[$cid] ?? ('#' . $cid)));
                        }
                    }
                }
                $card = html_writer::div('R' . (int)$row->id . ' · ' . $statuslabel, 'tm-dashboard-pending-head');
                $card .= html_writer::div(html_writer::tag('strong',
                    get_string('reservation_field_course_multi', 'local_tm_course') . ': ') . s(implode(' / ', $coursenames)), 'tm-dashboard-meta');
                $card .= html_writer::div(html_writer::tag('strong',
                    get_string('dashboard_label_last_update', 'local_tm_course') . ': ') . $updated, 'tm-dashboard-meta');
                $card .= html_writer::start_div('tm-dashboard-card-actions');
                $card .= html_writer::link(
                    (new moodle_url('/local/tm_course/reservation/tracking_detail.php', ['type' => 'custom', 'id' => (int)$row->id]))->out(false),
                    get_string('reservation_tracking_view_detail', 'local_tm_course'),
                    ['class' => 'btn btn-sm tm-dashboard-btn']
                );
                $card .= html_writer::end_div();
                $out .= html_writer::div($card, 'tm-dashboard-pending-card');
            }
            $out .= html_writer::end_div();
        }
    }

    if ($showrecentbatch) {
        $out .= html_writer::tag('h4', get_string('dashboard_section_recent_batch', 'local_tm_course'),
            ['class' => 'tm-dashboard-section-title mt-3']);
        if (empty($recentbatch)) {
            $out .= html_writer::div(get_string('dashboard_empty_recent_batch', 'local_tm_course'), 'tm-dashboard-empty');
        } else {
            $out .= html_writer::start_div('tm-dashboard-card-grid-5');
            foreach ($recentbatch as $row) {
                $updated = userdate((int)$row->sorttime, get_string('strftimedatetimeshort'));
                $name = format_string((string)$row->sessionname);
                $stats = get_string('dashboard_batch_stats', 'local_tm_course', (object)[
                    'pending' => (int)$row->pendingcount,
                    'approved' => (int)$row->approvedcount,
                    'rejected' => (int)$row->rejectedcount,
                ]);
                $card = html_writer::tag('div', $name, ['class' => 'tm-dashboard-pending-title']);
                $card .= html_writer::div(html_writer::tag('strong',
                    get_string('dashboard_label_last_update', 'local_tm_course') . ': ') . $updated, 'tm-dashboard-meta');
                $card .= html_writer::div($stats, 'tm-dashboard-meta');
                $card .= html_writer::start_div('tm-dashboard-card-actions');
                $card .= html_writer::link(
                    (new moodle_url('/local/tm_course/reservation/tracking_detail.php', ['type' => 'batch', 'id' => (int)$row->sessionid]))->out(false),
                    get_string('reservation_tracking_view_detail', 'local_tm_course'),
                    ['class' => 'btn btn-sm tm-dashboard-btn']
                );
                $card .= html_writer::end_div();
                $out .= html_writer::div($card, 'tm-dashboard-pending-card');
            }
            $out .= html_writer::end_div();
        }
    }

    if ($showcalendar) {
        $out .= html_writer::tag('h4', get_string('dashboard_section_calendar', 'local_tm_course'),
            ['class' => 'tm-dashboard-section-title mt-3']);
        $out .= html_writer::start_div('tm-card mt-2');
        $out .= html_writer::start_div('tm-card-body tm-calendar-panel');
        $out .= html_writer::div('', '', ['id' => 'tm-dashboard-month-calendar']);
        $out .= html_writer::end_div();
        $out .= html_writer::end_div();
    }

    $out .= html_writer::end_div();
    $out .= html_writer::end_div();
    $out .= html_writer::end_div();

    if ($showcalendar) {
        $out .= html_writer::empty_tag('link', [
            'rel' => 'stylesheet',
            'type' => 'text/css',
            'href' => 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/main.min.css',
        ]);
        $out .= html_writer::tag('script', '', [
            'src' => 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.14/index.global.min.js',
        ]);
        $calendarapiurl = (new moodle_url('/local/tm_course/calendar_events.php', ['sesskey' => sesskey()]))->out(false);
        $fclocale = str_replace('_', '-', current_language());
        $out .= html_writer::script("
            (function() {
                var root = document.getElementById('tm-dashboard-month-calendar');
                if (!root) { return; }
                var init = function() {
                    if (typeof FullCalendar === 'undefined') { return false; }
                    if (root.getAttribute('data-fc-init') === '1') { return true; }
                    root.setAttribute('data-fc-init', '1');
                    var cal = new FullCalendar.Calendar(root, {
                        initialView: 'dayGridMonth',
                        locale: " . json_encode($fclocale) . ",
                        height: 'auto',
                        headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
                        eventClassNames: function(arg) {
                            var ext = arg.event.extendedProps || {};
                            var a = [];
                            if (ext.ownDedicatedSession) { a.push('tm-fc-event-own-dedicated'); }
                            if (ext.isRoomClosed) { a.push('tm-fc-event-room-closed'); }
                            return a;
                        },
                        eventDidMount: function(info) {
                            var ext = info.event.extendedProps || {};
                            if (ext.ownDedicatedSessionLabel) {
                                info.el.setAttribute('title', ext.ownDedicatedSessionLabel);
                            }
                        },
                        events: function(info, success, failure) {
                            var from = Math.floor(info.start.getTime() / 1000);
                            var to = Math.floor(info.end.getTime() / 1000);
                            fetch(" . json_encode($calendarapiurl) . " + '&from=' + from + '&to=' + to, { credentials: 'same-origin' })
                                .then(function(r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
                                .then(function(d) {
                                    var items = (d && d.events) ? d.events : [];
                                    var mapped = items.map(function(item) {
                                        if (!item || !item.start) {
                                            return item;
                                        }
                                        var start = new Date(item.start);
                                        if (isNaN(start.getTime())) {
                                            return item;
                                        }
                                        var copy = Object.assign({}, item);
                                        var tl = (window.tmCourseLocalTime && window.tmCourseLocalTime.formatEventHmWithTz)
                                            ? window.tmCourseLocalTime.formatEventHmWithTz(start)
                                            : '';
                                        if (tl) {
                                            var base = String(copy.title || '');
                                            copy.title = base + ' (' + tl + ')';
                                        }
                                        return copy;
                                    });
                                    success(mapped);
                                })
                                .catch(failure);
                        }
                    });
                    cal.render();
                    return true;
                };
                var retry = 0;
                var tick = function() {
                    if (init()) { return; }
                    retry++;
                    if (retry < 80) { setTimeout(tick, 100); }
                };
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', tick);
                } else {
                    tick();
                }
            })();
        ");
    }

    // Safety net style block: ensure dashboard button style works even if theme/css
    // loading order differs on some frontpage layouts.
    $out .= html_writer::tag('style', '
        .tm-home-dashboard-wrapper { margin: 1rem 0; }
        .tm-home-dashboard-wrapper .tm-dashboard-btn {
            background: #e3e6ea !important;
            border: 1px solid #c7ccd1 !important;
            color: #2f353b !important;
            border-radius: 6px !important;
            font-weight: 600 !important;
        }
        .tm-home-dashboard-wrapper .tm-dashboard-btn:hover,
        .tm-home-dashboard-wrapper .tm-dashboard-btn:focus {
            background: #74b42a !important;
            border-color: #74b42a !important;
            color: #fff !important;
            text-decoration: none !important;
        }
        .tm-home-dashboard-wrapper .tm-dashboard-btn.tm-dashboard-btn-danger {
            background: #cf3347 !important;
            border-color: #b92a3c !important;
            color: #fff !important;
        }
        .tm-home-dashboard-wrapper .tm-dashboard-btn.tm-dashboard-btn-active {
            background: #005f7e !important;
            border-color: #005f7e !important;
            color: #fff !important;
        }
        .tm-home-dashboard-wrapper .tm-dashboard-action-groups {
            display: flex !important;
            flex-direction: column !important;
            gap: 0.85rem !important;
            margin-bottom: 0.5rem !important;
        }
        .tm-home-dashboard-wrapper .tm-dashboard-group-title {
            color: #005f7e !important;
            font-size: 0.95rem !important;
            font-weight: 700 !important;
            margin: 0 0 0.35rem 0 !important;
            padding-bottom: 0.25rem !important;
            border-bottom: 1px solid #d0dde3 !important;
        }
        .tm-home-dashboard-wrapper .tm-dashboard-action-group .tm-dashboard-action-row { margin-bottom: 0 !important; }
    ');

    // Move widget into frontpage main region (center column), with admin-configured position.
    $out .= html_writer::script("
        (function() {
            var position = " . json_encode($positioncfg) . ";
            function placeDashboard() {
                var block = document.querySelector('.tm-home-dashboard-wrapper');
                if (!block) { return false; }
                var main = document.getElementById('region-main')
                    || document.querySelector('#region-main .region_main_settings_menu_proxy')
                    || document.querySelector('main #region-main');
                if (!main) { return false; }
                if (block.parentNode === main) { return true; }

                var anchors = main.querySelectorAll(':scope > .card, :scope > .activity, :scope > section, :scope > div');
                if (position === 'aftertitle') {
                    if (main.firstChild) {
                        main.insertBefore(block, main.firstChild.nextSibling);
                    } else {
                        main.appendChild(block);
                    }
                    return true;
                }
                if (position === 'bottom') {
                    main.appendChild(block);
                    return true;
                }
                // Default/afternews: place after first visible content card if available.
                if (anchors.length > 0) {
                    var first = anchors[0];
                    if (first && first.nextSibling) {
                        main.insertBefore(block, first.nextSibling);
                    } else {
                        main.appendChild(block);
                    }
                } else {
                    main.insertBefore(block, main.firstChild);
                }
                return true;
            }

            var retries = 0;
            function runPlacement() {
                if (placeDashboard()) { return; }
                retries += 1;
                if (retries < 20) {
                    setTimeout(runPlacement, 120);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', runPlacement);
            } else {
                runPlacement();
            }
        })();
    ");

    return $out;
}
