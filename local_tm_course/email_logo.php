<?php
/**
 * Public email logo assets for batch_account_created HTML mail.
 *
 * Intentionally does NOT require login — Gmail/Outlook fetch images without
 * Moodle session cookies. Only whitelisted files under pix/email/ are served.
 *
 * @package    local_tm_course
 */

define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../config.php');

$name = required_param('name', PARAM_ALPHANUMEXT);

$allowed = [
    'tm_robot_logo' => 'tm_robot_logo.png',
    'training_center_logo' => 'training_center_logo.png',
];

if (!isset($allowed[$name])) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

$filename = $allowed[$name];
$filepath = __DIR__ . '/pix/email/' . $filename;

if (!is_readable($filepath) || !is_file($filepath)) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

// Public cacheable PNG for email clients (no auth, no redirect to login).
header('Content-Type: image/png');
header('Content-Length: ' . (string) filesize($filepath));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($filepath);
exit;
