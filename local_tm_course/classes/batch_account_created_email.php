<?php
/**
 * Fixed HTML + plain-text layout for batch_account_created notifications.
 *
 * Admin subject templates remain editable; body layout is system-owned so
 * operators never edit HTML tables/CSS. Existing notifytpl body configs are
 * left in the database for backward compatibility but are not used for send.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class batch_account_created_email {

    /** Brand green from TM Robot logo (approx). */
    public const COLOR_GREEN = '#8BC53F';
    /** Brand teal/blue from TM Robot logo (approx). */
    public const COLOR_TEAL = '#006D8A';

    /**
     * Absolute public URLs for email logos via core pluginfile.php (no login).
     *
     * Direct /pix/... and a standalone email_logo.php returned HTTP 404 on the
     * test site when those files were not present on disk; pluginfile.php is
     * always present in Moodle core and is updated with lib.php on ZIP install.
     *
     * @return array{tm_robot:string,training_center:string}
     */
    public static function logo_urls(): array {
        $context = \context_system::instance();
        return [
            'tm_robot' => \moodle_url::make_pluginfile_url(
                $context->id,
                'local_tm_course',
                'emaillogo',
                0,
                '/',
                'tm_robot_logo.png'
            )->out(false),
            'training_center' => \moodle_url::make_pluginfile_url(
                $context->id,
                'local_tm_course',
                'emaillogo',
                0,
                '/',
                'training_center_logo.png'
            )->out(false),
        ];
    }

    /**
     * Relative plugin paths for logo assets (for tests / packaging checks).
     *
     * @return array{tm_robot:string,training_center:string}
     */
    public static function logo_plugin_paths(): array {
        return [
            'tm_robot' => 'pix/email/tm_robot_logo.png',
            'training_center' => 'pix/email/training_center_logo.png',
        ];
    }

    /**
     * Plain-text alternative (EN then ZH) with credentials and raw URLs.
     *
     * @param array<string,string> $tokens
     */
    public static function build_plain(array $tokens): string {
        $learner = self::token($tokens, 'learner');
        $username = self::token($tokens, 'username');
        $password = self::token($tokens, 'initial_password');
        $session = self::token($tokens, 'session');
        $submitter = self::token($tokens, 'submitter');
        $login = self::token($tokens, 'login_url');
        $reset = self::token($tokens, 'reset_url');

        $en = "Hello {$learner},\n\n"
            . "Your TM Online Training account has been created.\n\n"
            . "Username: {$username}\n"
            . "Initial password: {$password}\n\n"
            . "Session: {$session}\n"
            . "Submitted by: {$submitter}\n\n"
            . "Sign in:\n{$login}\n\n"
            . "Forgot password:\n{$reset}\n\n"
            . "Please use the initial password above to sign in. "
            . "You will be asked to change your password after your first login.";

        $zh = "您好 {$learner}：\n\n"
            . "系統已建立您的 TM Online Training 學習帳號。\n\n"
            . "登入帳號：{$username}\n"
            . "初始密碼：{$password}\n\n"
            . "來源場次：{$session}\n"
            . "提交業務：{$submitter}\n\n"
            . "登入：\n{$login}\n\n"
            . "忘記密碼：\n{$reset}\n\n"
            . "請使用上述初始密碼登入，首次登入後系統將要求您變更密碼。";

        $footer = "This is an automated email. Please do not reply.\n"
            . "此為系統自動寄送信件，請勿直接回覆。\n\n"
            . "Techman Robot Co., Ltd.\n"
            . "TM Online Training / Training Center";

        return $en . "\n\n---\n\n" . $zh . "\n\n" . $footer;
    }

    /**
     * Email-safe HTML (table layout + inline CSS). No JavaScript.
     *
     * @param array<string,string> $tokens
     * @param array{tm_robot?:string,training_center?:string}|null $logourls Override public logo URLs (tests).
     */
    public static function build_html(array $tokens, ?array $logourls = null): string {
        $learner = self::e(self::token($tokens, 'learner'));
        $username = self::e(self::token($tokens, 'username'));
        $password = self::e(self::token($tokens, 'initial_password'));
        $session = self::e(self::token($tokens, 'session'));
        $submitter = self::e(self::token($tokens, 'submitter'));
        $login = self::e(self::token($tokens, 'login_url'));
        $reset = self::e(self::token($tokens, 'reset_url'));

        $logos = $logourls ?? self::logo_urls();
        $tmlogo = self::e((string)($logos['tm_robot'] ?? ''));
        $tclogo = self::e((string)($logos['training_center'] ?? ''));

        $teal = self::COLOR_TEAL;
        $green = self::COLOR_GREEN;

        // Outer wrapper: light tech-tint background (dots via sparse table cells avoided —
        // use subtle gradient-like stripes with nested tables only).
        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Created / 學習帳號建立完成</title>
</head>
<body style="margin:0;padding:0;background-color:#e8eef1;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;background-color:#e8eef1;">
<tr>
<td align="center" style="padding:24px 12px;background-color:#e8eef1;background-image:linear-gradient(135deg,#e8eef1 0%,#f5faf7 50%,#e6f0f3 100%);">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;max-width:600px;background-color:#ffffff;border-radius:8px;overflow:hidden;">
<!-- accent bar -->
<tr>
<td style="height:4px;line-height:4px;font-size:0;background-color:' . $teal . ';">&nbsp;</td>
</tr>
<!-- header logos -->
<tr>
<td style="padding:28px 28px 12px 28px;background-color:#ffffff;text-align:center;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="border-collapse:collapse;margin:0 auto;">
<tr>
<td align="center" valign="middle" style="padding:0 12px;">
<img src="' . $tmlogo . '" width="120" height="auto" alt="Techman Robot" style="display:block;width:120px;max-width:140px;height:auto;border:0;outline:none;text-decoration:none;">
</td>
<td align="center" valign="middle" style="padding:0 12px;">
<img src="' . $tclogo . '" width="88" height="auto" alt="Techman Robot Training Center" style="display:block;width:88px;max-width:100px;height:auto;border:0;outline:none;text-decoration:none;">
</td>
</tr>
</table>
</td>
</tr>
<!-- title -->
<tr>
<td style="padding:8px 28px 4px 28px;background-color:#ffffff;text-align:center;">
<div style="font-family:Arial,Helvetica,sans-serif;font-size:22px;line-height:1.35;font-weight:700;color:#1a1a1a;">Account Created</div>
<div style="font-family:Arial,Helvetica,sans-serif;font-size:18px;line-height:1.4;font-weight:700;color:#1a1a1a;margin-top:4px;">學習帳號建立完成</div>
<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.4;color:#5a6a72;margin-top:10px;">TM Online Training</div>
</td>
</tr>
<!-- credential card (shared EN+ZH) -->
<tr>
<td style="padding:20px 28px 8px 28px;background-color:#ffffff;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;background-color:#f0f7f4;border:1px solid #cfe6d8;border-radius:8px;">
<tr>
<td style="padding:18px 20px;">
<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.4;font-weight:700;color:#006D8A;text-transform:uppercase;letter-spacing:0.04em;">Username / 登入帳號</div>
<div style="font-family:Arial,Helvetica,sans-serif;font-size:23px;line-height:1.35;font-weight:700;color:#1a1a1a;margin-top:6px;word-break:break-all;overflow-wrap:anywhere;">' . $username . '</div>
<div style="height:14px;line-height:14px;font-size:0;">&nbsp;</div>
<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.4;font-weight:700;color:#006D8A;text-transform:uppercase;letter-spacing:0.04em;">Initial Password / 初始密碼</div>
<div style="font-family:Consolas,\'Courier New\',Courier,monospace;font-size:26px;line-height:1.35;font-weight:700;color:#1a1a1a;margin-top:6px;word-break:break-all;overflow-wrap:anywhere;">' . $password . '</div>
</td>
</tr>
</table>
</td>
</tr>
<!-- CTA buttons -->
<tr>
<td style="padding:16px 28px 8px 28px;background-color:#ffffff;text-align:center;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="border-collapse:collapse;margin:0 auto;">
<tr>
<td align="center" style="border-radius:6px;background-color:' . $teal . ';">
<a href="' . $login . '" target="_blank" style="display:inline-block;padding:13px 22px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.3;font-weight:700;color:#ffffff;text-decoration:none;border-radius:6px;">Sign in / 登入學習平台</a>
</td>
</tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="border-collapse:collapse;margin:12px auto 0 auto;">
<tr>
<td align="center" style="border-radius:6px;border:2px solid ' . $teal . ';background-color:#ffffff;">
<a href="' . $reset . '" target="_blank" style="display:inline-block;padding:11px 20px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.3;font-weight:700;color:' . $teal . ';text-decoration:none;border-radius:6px;">Forgot password / 忘記密碼</a>
</td>
</tr>
</table>
</td>
</tr>
<!-- English copy -->
<tr>
<td style="padding:20px 28px 8px 28px;background-color:#ffffff;">
<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#2c2c2c;">
Hello ' . $learner . ',
<br><br>
Your TM Online Training account has been created.
<br><br>
<strong>Session:</strong> ' . $session . '<br>
<strong>Submitted by:</strong> ' . $submitter . '
<br><br>
Please use the username and initial password shown above to sign in.
<br><br>
You will be asked to change your password after your first login.
<br><br>
If you are unable to sign in, please use the &ldquo;Forgot password&rdquo; button above.
</div>
</td>
</tr>
<!-- divider -->
<tr>
<td style="padding:8px 28px;background-color:#ffffff;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
<tr><td style="border-top:1px solid #d5dde2;font-size:0;line-height:0;height:1px;">&nbsp;</td></tr>
</table>
</td>
</tr>
<!-- Chinese copy -->
<tr>
<td style="padding:8px 28px 20px 28px;background-color:#ffffff;">
<div style="font-family:Arial,Helvetica,\'Microsoft JhengHei\',\'Noto Sans TC\',sans-serif;font-size:15px;line-height:1.65;color:#2c2c2c;">
您好 ' . $learner . '：
<br><br>
系統已建立您的 TM Online Training 學習帳號。
<br><br>
<strong>來源場次：</strong>' . $session . '<br>
<strong>提交業務：</strong>' . $submitter . '
<br><br>
請使用上方的登入帳號與初始密碼登入。
<br><br>
首次登入後，系統將要求您變更密碼。
<br><br>
若無法登入，請使用上方「忘記密碼」按鈕重新設定密碼。
</div>
</td>
</tr>
<!-- footer -->
<tr>
<td style="padding:18px 28px 28px 28px;background-color:#f7f9fa;border-top:1px solid #e1e7eb;">
<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.55;color:#6a7780;text-align:center;">
This is an automated email. Please do not reply.<br>
此為系統自動寄送信件，請勿直接回覆。
<br><br>
Techman Robot Co., Ltd.<br>
TM Online Training / Training Center
</div>
</td>
</tr>
</table>
<!-- subtle green accent under card -->
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;max-width:600px;">
<tr>
<td style="height:3px;line-height:3px;font-size:0;background-color:' . $green . ';">&nbsp;</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>';
    }

    /**
     * @param array<string,string|int|float|null> $tokens
     */
    private static function token(array $tokens, string $key): string {
        return isset($tokens[$key]) ? (string)$tokens[$key] : '';
    }

    private static function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
