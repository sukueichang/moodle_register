<?php
/**
 * Public email logo assets for batch_account_created HTML mail.
 *
 * Intentionally does NOT require login — Gmail/Outlook fetch images without
 * Moodle session cookies. Whitelisted keys only. Outputs raw image bytes with
 * no preceding whitespace/warnings.
 *
 * @package    local_tm_course
 */

define('NO_MOODLE_COOKIES', true);

// Avoid theme/session noise; still need config for moodle internals if any.
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/email_logo_assets.php');

use local_tm_course\email_logo_assets;

// Discard any accidental BOM/whitespace from included files before headers.
while (ob_get_level() > 0) {
    ob_end_clean();
}

$name = optional_param('name', '', PARAM_ALPHANUMEXT);
if ($name === '' || email_logo_assets::filename($name) === null) {
    header('HTTP/1.0 404 Not Found');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Logo not found';
    exit;
}

$bytes = email_logo_assets::image_bytes($name);
if ($bytes === null) {
    header('HTTP/1.0 404 Not Found');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Logo bytes missing';
    exit;
}

$filename = email_logo_assets::filename($name);
$mimetype = email_logo_assets::content_type($bytes);

header('Content-Type: ' . $mimetype);
header('Content-Length: ' . (string) strlen($bytes));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $bytes;
exit;
