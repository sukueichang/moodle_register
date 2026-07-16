<?php
/**
 * Dashboard visibility/position controls for site admins.
 *
 * @package    local_tm_course
 */
require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

if (!is_siteadmin()) {
    throw new required_capability_exception(
        context_system::instance(),
        'moodle/site:config',
        'nopermissions',
        ''
    );
}

$action = required_param('action', PARAM_ALPHA);
$position = optional_param('position', '', PARAM_ALPHA);
$widget = optional_param('widget', '', PARAM_ALPHANUMEXT);
$return = optional_param('return', (new moodle_url('/'))->out(false), PARAM_LOCALURL);

switch ($action) {
    case 'show':
        set_config('front_dashboard_visible', 1, 'local_tm_course');
        break;
    case 'hide':
        set_config('front_dashboard_visible', 0, 'local_tm_course');
        break;
    case 'position':
        if (!in_array($position, ['aftertitle', 'afternews', 'bottom'], true)) {
            throw new moodle_exception('invalidparameter', 'error');
        }
        set_config('front_dashboard_position', $position, 'local_tm_course');
        break;
    case 'widgetshow':
    case 'widgethide':
        $allowedwidgets = [
            'upcoming',
            'pending',
            'recent_reservation',
            'recent_batch',
            'calendar',
        ];
        if (!in_array($widget, $allowedwidgets, true)) {
            throw new moodle_exception('invalidparameter', 'error');
        }
        $cfgkey = 'dashboard_widget_' . $widget;
        set_config($cfgkey, $action === 'widgetshow' ? 1 : 0, 'local_tm_course');
        break;
    default:
        throw new moodle_exception('invalidparameter', 'error');
}

redirect(new moodle_url($return));
