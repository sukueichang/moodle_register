<?php
/**
 * Offline layout checks for batch_account_created email (no Moodle bootstrap).
 * Run: php tools/verify_batch_account_email_layout.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = $root . DIRECTORY_SEPARATOR . 'local_tm_course';
$fail = 0;

function assert_true(bool $cond, string $msg): void {
    global $fail;
    if ($cond) {
        echo "PASS  {$msg}\n";
    } else {
        echo "FAIL  {$msg}\n";
        $fail++;
    }
}

if (!defined('MOODLE_INTERNAL')) {
    define('MOODLE_INTERNAL', true);
}

/**
 * Minimal moodle_url stub for offline logo URL checks.
 */
class moodle_url {
    /** @var string */
    private $path;
    public function __construct(string $path) {
        $this->path = $path;
    }
    public function out($escaped = true): string {
        return 'https://moodle.example.test' . $this->path;
    }
}

/**
 * Minimal $OUTPUT stub: image_url → theme/image.php style path (no hand-built production URLs).
 */
class offline_output_stub {
    public function image_url(string $imagename, string $component = 'moodle'): moodle_url {
        $imagename = ltrim(str_replace('\\', '/', $imagename), '/');
        return new moodle_url('/theme/image.php/classic/' . $component . '/1/' . $imagename);
    }
}

/**
 * Minimal $PAGE stub for logo_urls() context guard.
 */
class offline_page_stub {
    /** @var object|null */
    public $context = null;
    public function set_context($context): void {
        $this->context = $context;
    }
}

class context_system {
    public static function instance(): object {
        return (object)['id' => 1];
    }
}

$GLOBALS['OUTPUT'] = new offline_output_stub();
$GLOBALS['PAGE'] = new offline_page_stub();

require_once $plugin . '/classes/batch_account_created_email.php';

use local_tm_course\batch_account_created_email;

$tokens = [
    'learner' => 'Su Waylon',
    'username' => 'waylon.su_very_long_username_example',
    'initial_password' => 'Ab1!XyZ9_QwertyLongPass',
    'session' => "TM AI Cobot Beginner's Training",
    'submitter' => 'TM Online Training',
    'login_url' => 'https://moodle.example.test/login/index.php',
    'reset_url' => 'https://moodle.example.test/login/forgot_password.php',
];

$plain = batch_account_created_email::build_plain($tokens);
$urls = batch_account_created_email::logo_urls();
$html = batch_account_created_email::build_html($tokens, $urls);

assert_true(str_contains($plain, 'Username: waylon.su_very_long_username_example'), 'plain username');
assert_true(str_contains($plain, 'Initial password: Ab1!XyZ9_QwertyLongPass'), 'plain password');
assert_true(str_contains($plain, 'login/index.php'), 'plain login url');
assert_true(str_contains($plain, 'forgot_password.php'), 'plain reset url');
assert_true(str_contains($plain, '您好 Su Waylon：'), 'plain zh learner');
assert_true(str_contains($html, 'font-size:23px'), 'html username size');
assert_true(str_contains($html, 'font-size:26px'), 'html password size');
assert_true(str_contains($html, 'Sign in / 登入學習平台'), 'html primary button');
assert_true(str_contains($html, 'Forgot password / 忘記密碼'), 'html secondary button');
assert_true(str_contains($html, 'href="https://moodle.example.test/login/index.php"'), 'html login href');
assert_true(!str_contains(strtolower($html), '<script'), 'no javascript');
assert_true(!preg_match('/Sign in:\s*https:\/\//i', $html), 'html not bare login line');

assert_true(str_contains($urls['tm_robot'], '/theme/image.php/'), 'tm logo uses theme/image.php');
assert_true(str_contains($urls['tm_robot'], 'local_tm_course'), 'tm logo component');
assert_true(str_contains($urls['tm_robot'], 'email/tm_robot_logo'), 'tm logo image name');
assert_true(str_contains($urls['training_center'], '/theme/image.php/'), 'tc logo uses theme/image.php');
assert_true(str_contains($urls['training_center'], 'email/training_center_logo'), 'tc logo image name');
assert_true(str_contains($html, $urls['tm_robot']), 'html embeds tm theme image url');
assert_true(str_contains($html, $urls['training_center']), 'html embeds tc theme image url');
assert_true(!str_contains($html, 'data:image/'), 'html does not use data-URI logos');
assert_true(!str_contains($html, 'cid:'), 'html does not use CID logos');

$paths = batch_account_created_email::logo_plugin_paths();
assert_true(is_file($plugin . '/' . $paths['tm_robot']), 'tm logo asset exists');
assert_true(is_file($plugin . '/' . $paths['training_center']), 'training logo asset exists');
assert_true(str_ends_with($paths['training_center'], '.jpg'), 'training logo path uses .jpg');
assert_true(!is_file($plugin . '/email_logo.php'), 'email_logo.php removed');
assert_true(!is_file($plugin . '/classes/email_logo_assets.php'), 'email_logo_assets.php removed');

$tmbytes = file_get_contents($plugin . '/' . $paths['tm_robot']);
$tcbytes = file_get_contents($plugin . '/' . $paths['training_center']);
assert_true(is_string($tmbytes) && strncmp($tmbytes, "\x89PNG\r\n\x1a\n", 8) === 0, 'tm logo is PNG');
assert_true(is_string($tcbytes) && strncmp($tcbytes, "\xff\xd8\xff", 3) === 0, 'training logo is JPEG bytes');

$libsrc = file_get_contents($plugin . '/lib.php');
assert_true(!str_contains($libsrc, "filearea === 'emaillogo'"), 'no emaillogo pluginfile branch');
assert_true(!str_contains($libsrc, 'email_logo_assets'), 'lib.php has no email_logo_assets');

$emailsrc = file_get_contents($plugin . '/classes/batch_account_created_email.php');
assert_true(str_contains($emailsrc, "image_url('email/tm_robot_logo', 'local_tm_course')"), 'uses OUTPUT image_url tm');
assert_true(str_contains($emailsrc, "image_url('email/training_center_logo', 'local_tm_course')"), 'uses OUTPUT image_url tc');
assert_true(!str_contains($emailsrc, 'logo_data_uris'), 'no logo_data_uris');
assert_true(!str_contains($emailsrc, 'logo_cids'), 'no logo_cids');
assert_true(!str_contains($emailsrc, 'make_pluginfile_url'), 'no pluginfile logo urls');

$notifysrc = file_get_contents($plugin . '/classes/notification_helper.php');
assert_true(str_contains($notifysrc, 'email_to_user'), 'uses email_to_user');
assert_true(!str_contains($notifysrc, 'logo_data_uris'), 'notify does not use data-URI');
assert_true(!str_contains($notifysrc, 'send_batch_account_created_email'), 'no custom CID mailer');

$enrolsrc = file_get_contents($plugin . '/classes/enrolment_manager.php');
assert_true(str_contains($enrolsrc, "set_user_preference('auth_forcepasswordchange', 1"), 'force pw preference set');
assert_true(!str_contains($enrolsrc, "set_field('user', 'forcepasswordchange'"), 'no user.forcepasswordchange column write');

echo $fail === 0 ? "\nAll offline checks passed.\n" : "\n{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
