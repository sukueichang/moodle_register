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
 * Minimal moodle_url stub for logo_urls() if called.
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
$html = batch_account_created_email::build_html($tokens, [
    'tm_robot' => 'https://cdn.example.test/local/tm_course/pix/email/tm_robot_logo.png',
    'training_center' => 'https://cdn.example.test/local/tm_course/pix/email/training_center_logo.png',
]);

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
assert_true(str_contains($html, 'pix/email/tm_robot_logo.png'), 'tm logo in html');
assert_true(str_contains($html, 'pix/email/training_center_logo.png'), 'training logo in html');
assert_true(is_file($plugin . '/pix/email/tm_robot_logo.png'), 'tm logo asset exists');
assert_true(is_file($plugin . '/pix/email/training_center_logo.png'), 'training logo asset exists');
assert_true(!preg_match('/Sign in:\s*https:\/\//i', $html), 'html not bare login line');

echo $fail === 0 ? "\nAll offline checks passed.\n" : "\n{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
