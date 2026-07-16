<?php
/**
 * Shared HTML email delivery for TM notification managers.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class notification_mail_helper {

    /**
     * Send one email with multiple To addresses and optional CC list.
     *
     * @param \stdClass[] $recipients User-like rows with valid email
     * @param string[] $ccemails CC addresses (duplicates and To overlaps removed)
     */
    public static function send_html_to_many(
        array $recipients,
        \stdClass $from,
        string $subject,
        string $plain,
        string $html,
        array $ccemails = []
    ): bool {
        global $CFG;

        if (!empty($CFG->noemailever)) {
            return true;
        }

        $toaddresses = self::normalise_to_addresses($recipients);
        if ($toaddresses === []) {
            return false;
        }

        $mail = get_mailer();
        if (!$mail) {
            return false;
        }

        $mail->CharSet = 'utf-8';
        foreach ($toaddresses as $row) {
            $mail->addAddress($row['email'], $row['name']);
        }

        $tokeys = array_fill_keys(array_keys($toaddresses), true);
        foreach (self::normalise_cc_addresses($ccemails, $tokeys) as $ccemail) {
            $mail->addCC($ccemail);
        }

        $fromemail = clean_param(trim((string) ($from->email ?? '')), PARAM_EMAIL);
        if ($fromemail !== '' && validate_email($fromemail)) {
            $mail->setFrom($fromemail, fullname($from));
        } else {
            $support = \core_user::get_support_user();
            $mail->setFrom($support->email, fullname($support));
        }

        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $plain;
        $mail->isHTML(true);
        $mail->Encoding = 'base64';

        return (bool) $mail->send();
    }

    /**
     * @param \stdClass[] $recipients
     * @return array<string,array{email:string,name:string}>
     */
    public static function normalise_to_addresses(array $recipients): array {
        $out = [];
        foreach ($recipients as $recipient) {
            $email = clean_param(trim((string) ($recipient->email ?? '')), PARAM_EMAIL);
            if ($email === '' || !validate_email($email)) {
                continue;
            }
            $key = strtolower($email);
            if (isset($out[$key])) {
                continue;
            }
            $out[$key] = [
                'email' => $email,
                'name' => fullname($recipient),
            ];
        }
        return $out;
    }

    /**
     * @param string[] $ccemails
     * @param array<string,bool> $tokeys lowercase To emails
     * @return string[]
     */
    public static function normalise_cc_addresses(array $ccemails, array $tokeys): array {
        $out = [];
        foreach ($ccemails as $ccemail) {
            $ccemail = clean_param(trim((string) $ccemail), PARAM_EMAIL);
            if ($ccemail === '' || !validate_email($ccemail)) {
                continue;
            }
            $key = strtolower($ccemail);
            if (isset($tokeys[$key]) || isset($out[$key])) {
                continue;
            }
            $out[$key] = $ccemail;
        }
        return array_values($out);
    }

    /**
     * @param string[] $toemails lowercase keys or raw emails from recipients
     * @param string $rawcc comma/semicolon/newline separated CC list
     * @return string[]
     */
    public static function parse_cc_excluding_to(array $tokeys, string $rawcc): array {
        $parsed = pre_class_notification_manager::parse_extra_emails($rawcc);
        return self::normalise_cc_addresses($parsed, $tokeys);
    }
}
