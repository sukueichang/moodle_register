<?php
/**
 * PHPUnit: batch_account_created branded HTML + plain email layout.
 *
 * @package    local_tm_course
 * @category   test
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/tm_course/classes/batch_account_created_email.php');
require_once($CFG->dirroot . '/local/tm_course/classes/enrolment_manager.php');
require_once($CFG->dirroot . '/local/tm_course/classes/notification_helper.php');

/**
 * @covers \local_tm_course\batch_account_created_email
 * @covers \local_tm_course\enrolment_manager::provision_or_link_batch_user
 */
class batch_account_created_email_test extends \advanced_testcase {

    /** @return array<string,string> */
    private function sample_tokens(): array {
        return [
            'learner' => 'Su Waylon',
            'username' => 'waylon.su_very_long_username_example',
            'initial_password' => 'Ab1!XyZ9_QwertyLongPass',
            'session' => 'TM AI Cobot Beginner\'s Training',
            'submitter' => 'TM Online Training',
            'login_url' => 'https://moodle.example.test/login/index.php',
            'reset_url' => 'https://moodle.example.test/login/forgot_password.php',
            'learner_email' => 'waylon@example.test',
        ];
    }

    public function test_plain_text_contains_all_tokens_and_bilingual_blocks(): void {
        $tokens = $this->sample_tokens();
        $plain = batch_account_created_email::build_plain($tokens);

        $this->assertStringContainsString('Hello Su Waylon,', $plain);
        $this->assertStringContainsString('Username: waylon.su_very_long_username_example', $plain);
        $this->assertStringContainsString('Initial password: Ab1!XyZ9_QwertyLongPass', $plain);
        $this->assertStringContainsString('Session: TM AI Cobot Beginner\'s Training', $plain);
        $this->assertStringContainsString('Submitted by: TM Online Training', $plain);
        $this->assertStringContainsString('https://moodle.example.test/login/index.php', $plain);
        $this->assertStringContainsString('https://moodle.example.test/login/forgot_password.php', $plain);
        $this->assertStringContainsString('您好 Su Waylon：', $plain);
        $this->assertStringContainsString('登入帳號：waylon.su_very_long_username_example', $plain);
        $this->assertStringContainsString('初始密碼：Ab1!XyZ9_QwertyLongPass', $plain);
        $this->assertStringContainsString('來源場次：', $plain);
        $this->assertStringContainsString('提交業務：', $plain);
        $this->assertStringContainsString('This is an automated email. Please do not reply.', $plain);
    }

    public function test_html_highlights_credentials_uses_buttons_and_plugin_logos(): void {
        $tokens = $this->sample_tokens();
        $html = batch_account_created_email::build_html($tokens, [
            'tm_robot' => 'https://cdn.example.test/tm_robot_logo.png',
            'training_center' => 'https://cdn.example.test/training_center_logo.png',
        ]);

        $this->assertNotSame('', $html);
        $this->assertStringContainsString('waylon.su_very_long_username_example', $html);
        $this->assertStringContainsString('Ab1!XyZ9_QwertyLongPass', $html);
        $this->assertStringContainsString('Su Waylon', $html);
        $this->assertStringContainsString('TM AI Cobot Beginner&#039;s Training', $html);
        $this->assertStringContainsString('TM Online Training', $html);
        $this->assertStringContainsString('Sign in / 登入學習平台', $html);
        $this->assertStringContainsString('Forgot password / 忘記密碼', $html);
        $this->assertStringContainsString('href="https://moodle.example.test/login/index.php"', $html);
        $this->assertStringContainsString('href="https://moodle.example.test/login/forgot_password.php"', $html);
        $this->assertStringContainsString('https://cdn.example.test/tm_robot_logo.png', $html);
        $this->assertStringContainsString('https://cdn.example.test/training_center_logo.png', $html);
        $this->assertStringContainsString('font-size:23px', $html);
        $this->assertStringContainsString('font-size:26px', $html);
        $this->assertStringContainsString('Courier New', $html);
        $this->assertStringContainsString('word-break:break-all', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
        // Primary CTAs are buttons (anchor styled), not bare URL as main copy.
        $this->assertSame(0, preg_match('/Sign in:\s*https:\/\/moodle\.example\.test\/login\/index\.php/i', $html));
        $paths = batch_account_created_email::logo_plugin_paths();
        $root = dirname(__DIR__);
        $this->assertFileExists($root . '/' . $paths['tm_robot']);
        $this->assertFileExists($root . '/' . $paths['training_center']);
        $this->assertFileExists($root . '/classes/email_logo_assets.php');
    }

    public function test_default_html_uses_cid_logo_refs(): void {
        $html = batch_account_created_email::build_html($this->sample_tokens());
        $this->assertStringContainsString('cid:tm_robot_logo', $html);
        $this->assertStringContainsString('cid:training_center_logo', $html);
    }

    public function test_logo_urls_point_at_public_pluginfile_emaillogo(): void {
        $urls = batch_account_created_email::logo_urls();
        $this->assertStringContainsString('/pluginfile.php/', $urls['tm_robot']);
        $this->assertStringContainsString('/local_tm_course/emaillogo/', $urls['tm_robot']);
        $this->assertStringContainsString('tm_robot_logo.png', $urls['tm_robot']);
        $this->assertStringContainsString('/pluginfile.php/', $urls['training_center']);
        $this->assertStringContainsString('training_center_logo.png', $urls['training_center']);
    }

    public function test_provision_creates_user_with_password_and_does_not_break_existing(): void {
        global $DB, $CFG;
        $this->resetAfterTest(true);
        $this->preventResetByRollback();

        $submitter = $this->getDataGenerator()->create_user(['firstname' => 'Sales', 'lastname' => 'Rep']);
        $email = 'new.learner.' . time() . '@example.test';

        $created = enrolment_manager::provision_or_link_batch_user(
            0,
            $email,
            'New',
            'Learner',
            'Techman',
            (int)$submitter->id
        );
        $this->assertTrue($created['created']);
        $this->assertNotSame('', $created['initial_password']);
        $this->assertGreaterThan(1, $created['userid']);

        $user = $DB->get_record('user', ['id' => $created['userid']], '*', MUST_EXIST);
        $this->assertSame($email, \core_text::strtolower($user->email));
        $this->assertSame('manual', $user->auth);
        // Moodle core uses user preference auth_forcepasswordchange (not a user-table column).
        $this->assertEquals(1, (int) get_user_preferences('auth_forcepasswordchange', 0, $user->id));

        // Existing account path: no second create, empty initial password, no force-flag change required.
        $again = enrolment_manager::provision_or_link_batch_user(
            0,
            $email,
            'New',
            'Learner',
            'Techman',
            (int)$submitter->id
        );
        $this->assertFalse($again['created']);
        $this->assertTrue($again['linked']);
        $this->assertSame((int)$user->id, (int)$again['userid']);
        $this->assertSame('', $again['initial_password']);
        $this->assertSame(1, $DB->count_records('user', ['email' => $email, 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id]));
        // Preference remains 1 from create (link path must not clear it; also must not be unset-only).
        $this->assertEquals(1, (int) get_user_preferences('auth_forcepasswordchange', 0, $user->id));
    }

    public function test_default_targets_unchanged_for_batch_account_created(): void {
        $this->resetAfterTest(true);
        // Clear any site overrides so defaults apply.
        unset_config('notifyrecips_batch_account_created_targets', 'local_tm_course');
        $settings = notification_helper::get_event_target_settings('batch_account_created');
        $this->assertContains(notification_helper::TARGET_LEARNER, $settings['targets']);
        $this->assertContains(notification_helper::TARGET_BATCH_SUBMITTER, $settings['targets']);
    }
}
