<?php
/**
 * Checkbox that purges TCMS mirrors when sync is turned off.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/tcms_sync_manager.php');

/**
 * Extends sync-enabled checkbox: disabling removes previously pushed TCMS sessions.
 */
class admin_setting_tcms_sync_enabled extends \admin_setting_configcheckbox {

    /**
     * @param string $data form data
     * @return string empty if ok, else error string
     */
    public function write_setting($data) {
        $wasenabled = (bool) get_config('local_tm_course', 'tcms_sync_enabled');
        $result = parent::write_setting($data);
        if ($result !== '') {
            return $result;
        }
        $nowenabled = (bool) get_config('local_tm_course', 'tcms_sync_enabled');
        if ($wasenabled && !$nowenabled) {
            tcms_sync_manager::purge_all_pushed_mirrors();
        }
        return '';
    }
}
