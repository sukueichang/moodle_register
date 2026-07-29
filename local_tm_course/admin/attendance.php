<?php
/**
 * Backward-compatible redirect: this page was renamed to class_prep.php
 * ("上課準備事項" — attendance + bento notification + equipment check).
 * Kept so old bookmarks/links (?sessionid=N) keep working.
 *
 * @package    local_tm_course
 * @copyright  2026 Techman Robot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../../config.php');

require_login();

$sessionid = required_param('sessionid', PARAM_INT);
redirect(new moodle_url('/local/tm_course/admin/class_prep.php', ['sessionid' => $sessionid]));
