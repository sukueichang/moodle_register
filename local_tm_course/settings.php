<?php
/**
 * Admin settings page for local_tm_course
 * @package    local_tm_course
 */
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $sm = get_string_manager();
    $str = function(string $key, string $fallback, $a = null) use ($sm): string {
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
    // Add a dedicated category in Site Administration.
    if (!$ADMIN->locate('local_tm_course_cat')) {
        $ADMIN->add('localplugins', new admin_category(
            'local_tm_course_cat',
            get_string('pluginname', 'local_tm_course')
        ));
    }

    // ---- Plugin version (easy to check anytime) ----
    require_once(__DIR__ . '/lib.php');
    $verinfo = local_tm_course_plugin_version_info();
    $aboutsettings = new admin_settingpage(
        'local_tm_course_about',
        $str('nav_plugin_about', 'Plugin version')
    );
    if ($ADMIN->fulltree) {
        $aboutsettings->add(new admin_setting_heading(
            'local_tm_course_about_heading',
            $str('setting_plugin_version', 'Installed version'),
            $str(
                'setting_plugin_version_desc',
                'Current local_tm_course release: <strong>{$a->label}</strong>',
                (object) ['label' => $verinfo['label']]
            )
        ));
    }
    if (!$ADMIN->locate('local_tm_course_about')) {
        $ADMIN->add('local_tm_course_cat', $aboutsettings);
    }

    // ---- M5: Notification settings ----
    $m5settings = new admin_settingpage(
        'local_tm_course_m5',
        get_string('nav_m5_settings', 'local_tm_course')
    );
    if ($ADMIN->fulltree) {
        $choices = [];
        for ($m = 5; $m <= 55; $m += 5) {
            $choices[$m * MINSECS] = get_string('reminder_minutes_option', 'local_tm_course', $m);
        }
        for ($h = 1; $h <= 24; $h++) {
            $choices[$h * HOURSECS] = get_string('reminder_hours_option', 'local_tm_course', $h);
        }
        $m5settings->add(new admin_setting_configselect(
            'local_tm_course/reminder_threshold',
            get_string('setting_reminder_threshold', 'local_tm_course'),
            get_string('setting_reminder_threshold_desc', 'local_tm_course'),
            24 * HOURSECS,
            $choices
        ));
        $auditchoices = [];
        for ($h = 1; $h <= 24; $h++) {
            $auditchoices[$h * HOURSECS] = get_string('reminder_hours_option', 'local_tm_course', $h);
        }
        $m5settings->add(new admin_setting_configselect(
            'local_tm_course/sync_audit_interval_seconds',
            get_string('setting_sync_audit_interval', 'local_tm_course'),
            get_string('setting_sync_audit_interval_desc', 'local_tm_course'),
            1 * HOURSECS,
            $auditchoices
        ));
        $m5settings->add(new admin_setting_configtext(
            'local_tm_course/physical_daily_limit',
            get_string('setting_physical_daily_limit', 'local_tm_course'),
            get_string('setting_physical_daily_limit_desc', 'local_tm_course'),
            '7',
            PARAM_FLOAT
        ));
        $m5settings->add(new admin_setting_configtext(
            'local_tm_course/online_day_end_time',
            get_string('setting_online_day_end_time', 'local_tm_course'),
            get_string('setting_online_day_end_time_desc', 'local_tm_course'),
            '22:30',
            PARAM_RAW_TRIMMED
        ));
        $m5settings->add(new admin_setting_configtext(
            'local_tm_course/session_auto_close_days_before',
            get_string('setting_session_auto_close_days_before', 'local_tm_course'),
            get_string('setting_session_auto_close_days_before_desc', 'local_tm_course'),
            '1',
            PARAM_INT
        ));
        $m5settings->add(new admin_setting_configtext(
            'local_tm_course/reservation_verification_deadline_days',
            get_string('setting_reservation_verification_deadline_days', 'local_tm_course'),
            get_string('setting_reservation_verification_deadline_days_desc', 'local_tm_course'),
            '7',
            PARAM_INT
        ));
        $m5settings->add(new admin_setting_configtextarea(
            'local_tm_course/reservation_disclaimer_text',
            get_string('setting_reservation_disclaimer_text', 'local_tm_course'),
            get_string('setting_reservation_disclaimer_text_desc', 'local_tm_course'),
            get_string('reservation_disclaimer_default', 'local_tm_course'),
            PARAM_RAW_TRIMMED
        ));
    }
    if (!$ADMIN->locate('local_tm_course_m5')) {
        $ADMIN->add('local_tm_course_cat', $m5settings);
    }

    // ---- Dashboard display settings ----
    $dashsettings = new admin_settingpage(
        'local_tm_course_dashboard',
        $str('nav_dashboard_settings', 'Dashboard display settings')
    );
    if ($ADMIN->fulltree) {
        $roleoptions = [];
        $allroles = role_fix_names(get_all_roles(context_system::instance()), context_system::instance(), ROLENAME_BOTH);
        foreach ($allroles as $role) {
            $roleoptions[(int)$role->id] = (string)$role->localname;
        }
        $dashsettings->add(new admin_setting_configmultiselect(
            'local_tm_course/dashboard_view_roleids',
            $str('setting_dashboard_view_roles', 'Roles allowed to view plugin and dashboard'),
            $str('setting_dashboard_view_roles_desc', 'Select Moodle system roles that may see the left navigation, frontpage dashboard, and course calendar. Leave empty to allow all logged-in users. Site administrators always have access.'),
            [],
            $roleoptions
        ));
        $dashsettings->add(new admin_setting_configmultiselect(
            'local_tm_course/self_enrol_roleids',
            $str('setting_self_enrol_roles', 'Allowed roles for self-enrolment'),
            $str('setting_self_enrol_roles_desc', 'Select Moodle system roles that can self-enrol in TM course sessions. Leave empty to keep legacy behavior (all users with enrol capability can self-enrol).'),
            [],
            $roleoptions
        ));

        $dashsettings->add(new admin_setting_configcheckbox(
            'local_tm_course/front_dashboard_visible',
            $str('setting_front_dashboard_visible', 'Enable frontpage dashboard'),
            $str('setting_front_dashboard_visible_desc', 'When disabled, TM dashboard is hidden on frontpage for users (site admins can re-enable here).'),
            1
        ));
        $dashsettings->add(new admin_setting_configselect(
            'local_tm_course/front_dashboard_position',
            $str('setting_front_dashboard_position', 'Frontpage dashboard position'),
            $str('setting_front_dashboard_position_desc', 'Choose where the dashboard is inserted in the frontpage main content.'),
            'afternews',
            [
                'aftertitle' => $str('dashboard_pos_aftertitle', 'Position: below frontpage title'),
                'afternews' => $str('dashboard_pos_afternews', 'Position: after Site news'),
                'bottom' => $str('dashboard_pos_bottom', 'Position: bottom of main content'),
            ]
        ));
        $roles = [
            'user' => $str('dashboard_role_user', 'General users'),
            'sales' => $str('dashboard_role_sales', 'Sales users'),
            'admin' => $str('dashboard_role_admin', 'System administrators'),
        ];
        $widgets = [
            'upcoming' => $str('dashboard_section_upcoming', 'Upcoming sessions'),
            'pending' => $str('dashboard_section_pending', 'Pending review'),
            'recent_reservation' => $str('dashboard_section_recent_reservation', 'Recent class opening requests'),
            'recent_batch' => $str('dashboard_section_recent_batch', 'Recent batch enrolments'),
            'calendar' => $str('dashboard_section_calendar', 'Course month view'),
        ];
        foreach ($roles as $rolekey => $rolelabel) {
            $dashsettings->add(new admin_setting_heading(
                'local_tm_course/dashboard_role_heading_' . $rolekey,
                $str('setting_dashboard_role_heading', 'Role: {$a}', $rolelabel),
                ''
            ));
            foreach ($widgets as $widgetkey => $widgetlabel) {
                $dashsettings->add(new admin_setting_configcheckbox(
                    'local_tm_course/dashboard_widget_' . $rolekey . '_' . $widgetkey,
                    $widgetlabel,
                    $str('setting_dashboard_widget_desc', 'Controls whether this section is shown in the frontpage dashboard.'),
                    1
                ));
            }
        }
    }
    if (!$ADMIN->locate('local_tm_course_dashboard')) {
        $ADMIN->add('local_tm_course_cat', $dashsettings);
    }

    // ---- TCMS integration (Phase 1) ----
    require_once(__DIR__ . '/classes/admin_setting_tcms_sync_enabled.php');
    $tcmssettings = new admin_settingpage(
        'local_tm_course_tcms',
        $str('nav_tcms_sync', 'TCMS sync')
    );
    if ($ADMIN->fulltree) {
        $tcmsver = local_tm_course_plugin_version_info();
        $tcmssettings->add(new admin_setting_heading(
            'local_tm_course_tcms_version',
            $str('setting_plugin_version', 'Installed version'),
            $str(
                'setting_plugin_version_desc',
                'Current local_tm_course release: <strong>{$a->label}</strong>',
                (object) ['label' => $tcmsver['label']]
            )
        ));
        $tcmssettings->add(new \local_tm_course\admin_setting_tcms_sync_enabled(
            'local_tm_course/tcms_sync_enabled',
            $str('setting_tcms_sync_enabled', 'Sync sessions to TCMS'),
            $str('setting_tcms_sync_enabled_desc', 'When enabled, standard sessions for linked courses are pushed to TCMS (A-1). When disabled, all sessions previously pushed from this Moodle are removed from TCMS. Default off.'),
            0
        ));
        $tcmssettings->add(new admin_setting_configtext(
            'local_tm_course/tcms_api_base_url',
            $str('setting_tcms_api_base_url', 'TCMS API base URL'),
            $str('setting_tcms_api_base_url_desc', 'Example: https://tcms-e49a5.web.app'),
            'https://tcms-e49a5.web.app',
            PARAM_URL
        ));
        $tcmssettings->add(new admin_setting_configpasswordunmask(
            'local_tm_course/tcms_sync_token',
            $str('setting_tcms_sync_token', 'TCMS sync token'),
            $str('setting_tcms_sync_token_desc', 'Bearer token shared with TCMS integration API. Must match TCMS_MOODLE_SYNC_TOKEN.'),
            '',
            PARAM_RAW_TRIMMED
        ));
        $tcmssettings->add(new admin_setting_configtext(
            'local_tm_course/tcms_sync_from_date',
            $str('setting_tcms_sync_from_date', 'Only sync sessions starting on or after'),
            $str('setting_tcms_sync_from_date_desc', 'YYYY-MM-DD (server timezone). Sessions before this date are never pushed to TCMS. Leave empty to sync all dates. Default: 2026-08-01.'),
            '2026-08-01',
            PARAM_TEXT
        ));
        $tcmssettings->add(new admin_setting_heading(
            'local_tm_course_tcms_inbound',
            $str('setting_tcms_inbound_hint', 'Phase 2 classroom occupancy (TCMS → Moodle)'),
            $str('setting_tcms_inbound_hint_desc', 'TCMS calls /local/tm_course/api/classrooms.php and /local/tm_course/api/room_closed.php with the same token.')
        ));
        $tcmssettings->add(new admin_setting_heading(
            'local_tm_course_tcms_mapping_hint',
            $str('setting_tcms_mapping_hint', 'Course / classroom mapping for sync'),
            $str('setting_tcms_mapping_hint_desc', 'Set TCMS course type in Course mapping, and TCMS location in Classroom edit. JSON maps are no longer configured here.')
        ));
        $reconcilechoices = [
            HOURSECS => $str('tcms_reconcile_1h', 'Every hour'),
            6 * HOURSECS => $str('tcms_reconcile_6h', 'Every 6 hours'),
            DAYSECS => $str('tcms_reconcile_1d', 'Every day'),
            3 * DAYSECS => $str('tcms_reconcile_3d', 'Every 3 days'),
            7 * DAYSECS => $str('tcms_reconcile_7d', 'Every week'),
        ];
        $tcmssettings->add(new admin_setting_configselect(
            'local_tm_course/tcms_sync_reconcile_interval',
            $str('setting_tcms_reconcile_interval', 'Reconcile interval'),
            $str('setting_tcms_reconcile_interval_desc', 'How often to re-push eligible sessions when sync is enabled.'),
            DAYSECS,
            $reconcilechoices
        ));
    }
    if (!$ADMIN->locate('local_tm_course_tcms')) {
        $ADMIN->add('local_tm_course_cat', $tcmssettings);
    }

    // External pages inside TM Course Management.
    if (!$ADMIN->locate('local_tm_course_classrooms')) {
        $ADMIN->add('local_tm_course_cat', new admin_externalpage(
            'local_tm_course_classrooms',
            $str('nav_classrooms', 'Classrooms'),
            new moodle_url('/local/tm_course/classroom/index.php')
        ));
    }
    if (!$ADMIN->locate('local_tm_course_course_mapping')) {
        $ADMIN->add('local_tm_course_cat', new admin_externalpage(
            'local_tm_course_course_mapping',
            $str('nav_course_mapping', 'Course mapping'),
            new moodle_url('/local/tm_course/settings/course_mapping.php')
        ));
    }
    if (!$ADMIN->locate('local_tm_course_moodle_optimization')) {
        $ADMIN->add('local_tm_course_cat', new admin_externalpage(
            'local_tm_course_moodle_optimization',
            $str('nav_moodle_optimization', 'Moodle optimization'),
            new moodle_url('/local/tm_course/settings/moodle_optimization.php')
        ));
    }
    if (!$ADMIN->locate('local_tm_course_permissions')) {
        $ADMIN->add('local_tm_course_cat', new admin_externalpage(
            'local_tm_course_permissions',
            $str('nav_permissions', 'Permission rules'),
            new moodle_url('/local/tm_course/settings/permissions.php')
        ));
    }
    if (!$ADMIN->locate('local_tm_course_notifications')) {
        $ADMIN->add('local_tm_course_cat', new admin_externalpage(
            'local_tm_course_notifications',
            $str('nav_notifications', 'Notification settings'),
            new moodle_url('/local/tm_course/settings/notifications.php')
        ));
    }
    if (!$ADMIN->locate('local_tm_course_sessions')) {
        $ADMIN->add('local_tm_course_cat', new admin_externalpage(
            'local_tm_course_sessions',
            $str('nav_sessions', 'Session management'),
            new moodle_url('/local/tm_course/admin/sessions.php')
        ));
    }
    if (!$ADMIN->locate('local_tm_course_enrolments')) {
        $ADMIN->add('local_tm_course_cat', new admin_externalpage(
            'local_tm_course_enrolments',
            $str('nav_enrolments', 'Enrolment and dedicated class review'),
            new moodle_url('/local/tm_course/admin/review_center.php')
        ));
    }
    if (!$ADMIN->locate('local_tm_course_search')) {
        $ADMIN->add('local_tm_course_cat', new admin_externalpage(
            'local_tm_course_search',
            $str('nav_search', 'Search records'),
            new moodle_url('/local/tm_course/search.php')
        ));
    }
    if (!$ADMIN->locate('local_tm_course_attendance')) {
        $ADMIN->add('local_tm_course_cat', new admin_externalpage(
            'local_tm_course_attendance',
            $str('nav_attendance', 'Attendance'),
            new moodle_url('/local/tm_course/admin/sessions.php')
        ));
    }
}
