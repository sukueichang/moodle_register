<?php
/**
 * Pre-class (tomorrow onsite) notification: settings, report build, scheduled send.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class pre_class_notification_manager {

    private const CFG = 'preclass_notify_';

    /** @var array<string,array{config:string,lang:string,fallback:string}> */
    private const COLUMN_FIELDS = [
        'session' => ['config' => 'col_session', 'lang' => 'preclass_col_session', 'fallback' => '場次'],
        'learner' => ['config' => 'col_learner', 'lang' => 'preclass_col_learner', 'fallback' => '學員名稱'],
        'enrol_type' => ['config' => 'col_enrol_type', 'lang' => 'preclass_col_enrol_type', 'fallback' => '報名方式'],
        'institution' => ['config' => 'col_institution', 'lang' => 'preclass_col_institution', 'fallback' => '機構'],
        'sales_name' => ['config' => 'col_sales_name', 'lang' => 'preclass_col_sales_name', 'fallback' => '批次業務'],
        'sales_institution' => ['config' => 'col_sales_institution', 'lang' => 'preclass_col_sales_institution', 'fallback' => '業務機構'],
        'sales_phone' => ['config' => 'col_sales_phone', 'lang' => 'preclass_col_sales_phone', 'fallback' => '業務電話'],
    ];

    /** @var string[] */
    public static function get_target_options(): array {
        return [
            notification_helper::TARGET_APPROVER => get_string('notify_target_approver', 'local_tm_course'),
            notification_helper::TARGET_BATCH_SUBMITTER => get_string('notify_target_batch_submitter', 'local_tm_course'),
        ];
    }

    /** @var string[] Summary table columns (institution merged; multiple sales comma-separated). */
    private const SUMMARY_COLUMN_KEYS = [
        'institution',
        'headcount',
        'sales_name',
        'sales_institution',
        'sales_phone',
    ];

    /** @var string[] */
    public static function get_template_tokens(): array {
        return ['{{date}}', '{{session_name}}', '{{institution_summary_table}}', '{{sessions_table}}'];
    }

    /**
     * @return array{
     *   enabled:bool,
     *   hour:int,
     *   minute:int,
     *   extra_emails:string,
     *   cc_emails:string,
     *   targets:string[],
     *   subject_zh_tw:string,
     *   body_zh_tw:string,
     *   columns:array<string,string>
     * }
     */
    public static function get_settings(): array {
        $targetsraw = (string) get_config('local_tm_course', self::CFG . 'targets');
        $targets = [];
        if ($targetsraw !== '') {
            $targets = preg_split('/\s*,\s*/', $targetsraw, -1, PREG_SPLIT_NO_EMPTY);
        } else if (!empty(get_config('local_tm_course', self::CFG . 'include_batch_submitters'))) {
            // Legacy migrate.
            $targets = [notification_helper::TARGET_BATCH_SUBMITTER];
        }
        $targets = array_values(array_intersect($targets, array_keys(self::get_target_options())));

        $body = (string) get_config('local_tm_course', self::CFG . 'body_zh_tw');
        if ($body === '') {
            $intro = (string) get_config('local_tm_course', self::CFG . 'intro_zh_tw');
            if ($intro !== '') {
                $body = trim($intro) . "\n\n{{sessions_table}}";
            }
        }

        return [
            'enabled' => !empty(get_config('local_tm_course', self::CFG . 'enabled')),
            'hour' => self::get_config_int(self::CFG . 'hour', 17, 0, 23),
            'minute' => self::get_config_int(self::CFG . 'minute', 0, 0, 59),
            'extra_emails' => (string) get_config('local_tm_course', self::CFG . 'extra_emails'),
            'cc_emails' => (string) get_config('local_tm_course', self::CFG . 'cc_emails'),
            'targets' => $targets,
            'subject_zh_tw' => (string) get_config('local_tm_course', self::CFG . 'subject_zh_tw'),
            'body_zh_tw' => $body,
            'columns' => self::resolve_column_labels([]),
        ];
    }

    /**
     * @return array<string,string> column key => header label
     */
    public static function get_default_column_labels(): array {
        $labels = [];
        foreach (self::COLUMN_FIELDS as $key => $meta) {
            $labels[$key] = self::lang($meta['lang'], $meta['fallback']);
        }
        return $labels;
    }

    /**
     * Raw saved column labels for the settings form (empty = use default).
     *
     * @return array<string,string>
     */
    public static function get_column_input_values(): array {
        $out = [];
        foreach (self::COLUMN_FIELDS as $key => $meta) {
            $out[$key] = trim((string) get_config('local_tm_course', self::CFG . $meta['config']));
        }
        return $out;
    }

    /**
     * @param array<string,string> $saved optional saved overrides (empty = use config)
     * @return array<string,string>
     */
    public static function resolve_column_labels(array $saved): array {
        $defaults = self::get_default_column_labels();
        $out = [];
        foreach (self::COLUMN_FIELDS as $key => $meta) {
            $raw = '';
            if (array_key_exists($key, $saved)) {
                $raw = trim((string) $saved[$key]);
            } else {
                $raw = trim((string) get_config('local_tm_course', self::CFG . $meta['config']));
            }
            $out[$key] = $raw !== '' ? $raw : ($defaults[$key] ?? $meta['fallback']);
        }
        return $out;
    }

    public static function save_settings(
        bool $enabled,
        int $hour,
        int $minute,
        string $extraemails,
        array $targets,
        string $subjectzhtw,
        string $bodyzhtw,
        array $columns = [],
        string $ccemails = ''
    ): void {
        set_config(self::CFG . 'enabled', $enabled ? 1 : 0, 'local_tm_course');
        set_config(self::CFG . 'hour', max(0, min(23, $hour)), 'local_tm_course');
        set_config(self::CFG . 'minute', max(0, min(59, $minute)), 'local_tm_course');
        set_config(self::CFG . 'extra_emails', trim($extraemails), 'local_tm_course');
        set_config(self::CFG . 'cc_emails', trim((string) ($ccemails ?? '')), 'local_tm_course');
        $targets = array_values(array_intersect($targets, array_keys(self::get_target_options())));
        set_config(self::CFG . 'targets', implode(',', $targets), 'local_tm_course');
        set_config(self::CFG . 'subject_zh_tw', trim($subjectzhtw), 'local_tm_course');
        set_config(self::CFG . 'body_zh_tw', trim($bodyzhtw), 'local_tm_course');
        foreach (self::COLUMN_FIELDS as $key => $meta) {
            $value = '';
            if (array_key_exists($key, $columns)) {
                $value = trim((string) $columns[$key]);
            }
            set_config(self::CFG . $meta['config'], $value, 'local_tm_course');
        }
    }

    private static function lang(string $identifier, string $fallback): string {
        $sm = get_string_manager();
        if ($sm->string_exists($identifier, 'local_tm_course')) {
            return get_string($identifier, 'local_tm_course');
        }
        return $fallback;
    }

    private static function get_config_int(string $key, int $default, int $min, int $max): int {
        $raw = get_config('local_tm_course', $key);
        if ($raw === false || $raw === null || $raw === '') {
            return $default;
        }
        return max($min, min($max, (int) $raw));
    }

    public static function get_default_subject(): string {
        return get_string('preclass_notify_default_subject', 'local_tm_course');
    }

    public static function get_default_body(): string {
        return get_string('preclass_notify_default_body', 'local_tm_course');
    }

    public static function should_send_now(): bool {
        $settings = self::get_settings();
        if (!$settings['enabled']) {
            return false;
        }
        $tz = \core_date::get_server_timezone_object();
        $now = new \DateTime('now', $tz);
        $todaykey = $now->format('Ymd');
        $lastsent = (string) get_config('local_tm_course', self::CFG . 'last_sent_date');
        if ($lastsent === $todaykey) {
            return false;
        }
        $sendminute = $settings['hour'] * 60 + $settings['minute'];
        $nowminute = ((int) $now->format('H')) * 60 + (int) $now->format('i');
        return $nowminute >= $sendminute && $nowminute < $sendminute + 60;
    }

    public static function send_if_due(): int {
        if (!self::should_send_now()) {
            return 0;
        }
        $settings = self::get_settings();
        $payload = self::build_tomorrow_report();
        if (empty($payload['sessions'])) {
            self::mark_sent_today();
            return 0;
        }
        $sent = self::dispatch_report($settings, $payload);
        if ($sent > 0) {
            self::mark_sent_today();
        }
        return $sent;
    }

    /**
     * Send test email with mock data to all configured recipient addresses.
     *
     * @param array|null $overrides optional unsaved form values: subject_zh_tw, body_zh_tw, extra_emails, targets
     * @return array{sent:int,failed:int,recipients:string[]}
     */
    public static function send_test_email(?array $overrides = null): array {
        $settings = self::merge_settings_overrides(self::get_settings(), $overrides);
        $payload = self::build_mock_report();
        $recipients = self::resolve_recipients($payload, $settings);
        if ($recipients === []) {
            return ['sent' => 0, 'failed' => 0, 'recipients' => []];
        }

        $tokeys = array_fill_keys(array_keys($recipients), true);
        $ccemails = notification_mail_helper::parse_cc_excluding_to($tokeys, $settings['cc_emails']);
        $from = \core_user::get_noreply_user();

        $sent = 0;
        $failed = 0;
        foreach ($payload['sessions'] as $block) {
            $sessionpayload = self::build_session_payload($payload, $block);
            $rendered = self::render_email($settings, $sessionpayload, true);
            if (notification_mail_helper::send_html_to_many(
                array_values($recipients),
                $from,
                $rendered['subject'],
                $rendered['plain'],
                $rendered['html'],
                $ccemails
            )) {
                $sent++;
            } else {
                $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed, 'recipients' => array_keys($recipients)];
    }

    /**
     * Render preview HTML (mock data). Uses form overrides when provided.
     *
     * @param array|null $overrides subject_zh_tw, body_zh_tw
     * @return array{subject:string,html:string,plain:string}
     */
    public static function render_preview(?array $overrides = null): array {
        $settings = self::merge_settings_overrides(self::get_settings(), $overrides);
        return self::render_email($settings, self::build_mock_report(), true);
    }

    /**
     * All configured recipient email addresses (for display before test send).
     *
     * @return string[]
     */
    public static function list_configured_recipient_emails(?array $overrides = null): array {
        $settings = self::merge_settings_overrides(self::get_settings(), $overrides);
        $recipients = self::resolve_test_recipients($settings);
        $out = [];
        foreach ($recipients as $u) {
            $e = strtolower(trim((string) ($u->email ?? '')));
            if ($e !== '') {
                $out[] = $e;
            }
        }
        sort($out);
        return $out;
    }

    private static function merge_settings_overrides(array $settings, ?array $overrides): array {
        if ($overrides === null) {
            return $settings;
        }
        if (array_key_exists('subject_zh_tw', $overrides)) {
            $settings['subject_zh_tw'] = (string) $overrides['subject_zh_tw'];
        }
        if (array_key_exists('body_zh_tw', $overrides)) {
            $settings['body_zh_tw'] = (string) $overrides['body_zh_tw'];
        }
        if (array_key_exists('extra_emails', $overrides)) {
            $settings['extra_emails'] = (string) $overrides['extra_emails'];
        }
        if (array_key_exists('cc_emails', $overrides)) {
            $settings['cc_emails'] = (string) $overrides['cc_emails'];
        }
        if (array_key_exists('targets', $overrides)) {
            $settings['targets'] = is_array($overrides['targets'])
                ? array_values(array_intersect($overrides['targets'], array_keys(self::get_target_options())))
                : [];
        }
        if (array_key_exists('columns', $overrides) && is_array($overrides['columns'])) {
            $settings['columns'] = self::resolve_column_labels($overrides['columns']);
        }
        return $settings;
    }

    private static function mark_sent_today(): void {
        $tz = \core_date::get_server_timezone_object();
        $todaykey = (new \DateTime('now', $tz))->format('Ymd');
        set_config(self::CFG . 'last_sent_date', $todaykey, 'local_tm_course');
    }

    public static function get_tomorrow_range(): array {
        $tz = \core_date::get_server_timezone_object();
        $tomorrow = new \DateTime('tomorrow', $tz);
        $dayafter = clone $tomorrow;
        $dayafter->modify('+1 day');
        return [
            'start' => $tomorrow->getTimestamp(),
            'end' => $dayafter->getTimestamp(),
            'label' => userdate($tomorrow->getTimestamp(), get_string('strftimedate', 'langconfig')),
        ];
    }

    public static function build_tomorrow_report(): array {
        $range = self::get_tomorrow_range();
        $candidates = session_manager::get_sessions([
            'from' => $range['start'],
            'to' => $range['end'] - 1,
        ]);

        $out = [];
        foreach ($candidates as $session) {
            if (session_manager::is_room_closed_session($session)) {
                continue;
            }
            if ((string) ($session->delivery_mode ?? '') !== session_manager::DELIVERY_ONSITE) {
                continue;
            }
            if ((int) $session->starttime < $range['start'] || (int) $session->starttime >= $range['end']) {
                continue;
            }
            $out[] = [
                'session' => $session,
                'rows' => self::get_session_enrol_rows((int) $session->id),
            ];
        }

        return ['range' => $range, 'sessions' => $out];
    }

    /**
     * Mock report: self-enrol, batch full roster, batch seat holds (two companies).
     *
     * @return array{sessions:array,range:array}
     */
    public static function build_mock_report(): array {
        $range = self::get_tomorrow_range();
        $start = $range['start'] + (9 * HOURSECS) + (30 * MINSECS);
        $end = $start + (8 * HOURSECS);

        $session = (object) [
            'id' => 0,
            'name' => self::lang('preclass_mock_session_combined', '【Mock】明日實體課程學員一覽（示意）'),
            'starttime' => $start,
            'endtime' => $end,
            'location' => self::lang('preclass_mock_location', '台北內湖實體教室 A'),
            'delivery_mode' => session_manager::DELIVERY_ONSITE,
        ];

        $rows = [
            self::mock_enrol_row([
                'userid' => 90001,
                'batch_submittedby' => 90001,
                'firstname' => get_string('preclass_mock_self_first', 'local_tm_course'),
                'lastname' => get_string('preclass_mock_self_last', 'local_tm_course'),
                'enrol_institution' => get_string('preclass_mock_self_inst', 'local_tm_course'),
                'profile_institution' => get_string('preclass_mock_self_inst', 'local_tm_course'),
            ]),
            self::mock_enrol_row([
                'userid' => 90002,
                'batch_submittedby' => 90010,
                'firstname' => get_string('preclass_mock_full_first', 'local_tm_course'),
                'lastname' => get_string('preclass_mock_full_last', 'local_tm_course'),
                'enrol_institution' => get_string('preclass_mock_full_learner_inst', 'local_tm_course'),
                'submitter_firstname' => get_string('preclass_mock_sales_wang_first', 'local_tm_course'),
                'submitter_lastname' => get_string('preclass_mock_sales_wang_last', 'local_tm_course'),
                'submitter_institution' => get_string('preclass_mock_sales_wang_inst', 'local_tm_course'),
                'submitter_phone' => '02-2757-0001',
            ]),
            self::mock_enrol_row([
                'userid' => 90005,
                'batch_submittedby' => 90011,
                'firstname' => 'Mock',
                'lastname' => 'Two',
                'enrol_institution' => get_string('preclass_mock_full_learner_inst', 'local_tm_course'),
                'submitter_firstname' => get_string('preclass_mock_sales_lin_first', 'local_tm_course'),
                'submitter_lastname' => get_string('preclass_mock_sales_lin_last', 'local_tm_course'),
                'submitter_institution' => get_string('preclass_mock_sales_lin_inst', 'local_tm_course'),
                'submitter_phone' => '0912-345-678',
            ]),
            self::mock_enrol_row([
                'userid' => 90003,
                'batch_submittedby' => 90011,
                'placeholder_seq' => 1,
                'seat_company' => get_string('preclass_mock_co_a', 'local_tm_course'),
                'submitter_firstname' => get_string('preclass_mock_sales_lin_first', 'local_tm_course'),
                'submitter_lastname' => get_string('preclass_mock_sales_lin_last', 'local_tm_course'),
                'submitter_institution' => get_string('preclass_mock_sales_lin_inst', 'local_tm_course'),
                'submitter_phone' => '0912-345-678',
            ]),
            self::mock_enrol_row([
                'userid' => 90004,
                'batch_submittedby' => 90011,
                'placeholder_seq' => 2,
                'seat_company' => get_string('preclass_mock_co_b', 'local_tm_course'),
                'submitter_firstname' => get_string('preclass_mock_sales_lin_first', 'local_tm_course'),
                'submitter_lastname' => get_string('preclass_mock_sales_lin_last', 'local_tm_course'),
                'submitter_institution' => get_string('preclass_mock_sales_lin_inst', 'local_tm_course'),
                'submitter_phone' => '0912-345-678',
            ]),
        ];

        return [
            'range' => $range,
            'sessions' => [
                ['session' => $session, 'rows' => $rows],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function mock_enrol_row(array $data): \stdClass {
        $row = (object) array_merge([
            'id' => 0,
            'enrol_institution' => '',
            'profile_institution' => '',
            'placeholder_seq' => 0,
            'seat_company' => '',
            'placeholder_name' => '',
            'linked_userid' => 0,
            'linked_email' => '',
            'lu_firstname' => '',
            'lu_lastname' => '',
            'lu_email' => '',
            'email' => 'mock@example.com',
            'submitter_firstname' => '',
            'submitter_lastname' => '',
            'submitter_institution' => '',
            'submitter_phone' => '',
        ], $data);
        return $row;
    }

    private static function get_session_enrol_rows(int $sessionid): array {
        global $DB;

        $sql = "SELECT e.id, e.userid, e.institution AS enrol_institution,
                       e.batch_submittedby, e.seat_company, e.placeholder_seq,
                       e.linked_userid, e.linked_email, e.placeholder_name,
                       u.firstname, u.lastname, u.email, u.institution AS profile_institution,
                       lu.firstname AS lu_firstname, lu.lastname AS lu_lastname, lu.email AS lu_email,
                       sb.firstname AS submitter_firstname,
                       sb.lastname AS submitter_lastname,
                       sb.institution AS submitter_institution,
                       sb.phone1 AS submitter_phone
                  FROM {local_tm_course_enrolments} e
                  JOIN {user} u ON u.id = e.userid AND u.deleted = 0
             LEFT JOIN {user} lu ON lu.id = e.linked_userid AND e.linked_userid > 0
             LEFT JOIN {user} sb ON sb.id = e.batch_submittedby AND sb.deleted = 0
                 WHERE e.sessionid = :sid
                   AND e.status = :approved
              ORDER BY COALESCE(e.desk_number, 9999) ASC, u.lastname ASC, u.firstname ASC";

        return array_values($DB->get_records_sql($sql, [
            'sid' => $sessionid,
            'approved' => session_manager::ENROL_APPROVED,
        ]));
    }

    private static function dispatch_report(array $settings, array $payload): int {
        if (empty($payload['sessions'])) {
            return 0;
        }

        $recipients = self::resolve_recipients($payload, $settings);
        if ($recipients === []) {
            return 0;
        }

        $tokeys = array_fill_keys(array_keys($recipients), true);
        $ccemails = notification_mail_helper::parse_cc_excluding_to($tokeys, $settings['cc_emails']);
        $from = \core_user::get_noreply_user();

        $sent = 0;
        foreach ($payload['sessions'] as $block) {
            $sessionpayload = self::build_session_payload($payload, $block);
            $rendered = self::render_email($settings, $sessionpayload, false);
            if (notification_mail_helper::send_html_to_many(
                array_values($recipients),
                $from,
                $rendered['subject'],
                $rendered['plain'],
                $rendered['html'],
                $ccemails
            )) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * @param array{range:array,sessions:array} $payload
     * @param array{session:\stdClass,rows:array} $block
     * @return array{range:array,sessions:array}
     */
    private static function build_session_payload(array $payload, array $block): array {
        return [
            'range' => $payload['range'],
            'sessions' => [$block],
        ];
    }

    /**
     * @return array{subject:string,html:string,plain:string}
     */
    private static function render_email(array $settings, array $payload, bool $istest): array {
        $datelabel = (string) $payload['range']['label'];
        if ($istest) {
            $datelabel .= ' (' . self::lang('preclass_mock_date_suffix', 'Mock 預覽') . ')';
        }

        $subjecttpl = $settings['subject_zh_tw'] !== '' ? $settings['subject_zh_tw'] : self::get_default_subject();
        $bodytpl = $settings['body_zh_tw'] !== '' ? $settings['body_zh_tw'] : self::get_default_body();
        $sessionname = self::resolve_payload_session_name($payload);

        $summaryhtml = self::render_institution_summary_table_html($payload, $settings);
        $rosterhtml = self::render_sessions_tables_html($payload, $settings);
        $subject = self::apply_template_tokens($subjecttpl, $datelabel, $sessionname);
        $bodyhtml = self::render_body_from_template(
            self::apply_template_tokens($bodytpl, $datelabel, $sessionname),
            $datelabel,
            $summaryhtml,
            $rosterhtml
        );

        $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.5;">'
            . $bodyhtml
            . '<p style="color:#666;font-size:12px;margin-top:16px;">'
            . s(self::lang('preclass_notify_footer', '此信由 TM 課程管理系統自動發送。'))
            . ($istest ? '<br>' . s(self::lang('preclass_test_footer', '【測試信】此為系統管理員觸發的課前通知測試。')) : '')
            . '</p></div>';

        return [
            'subject' => $istest ? '[TEST] ' . $subject : $subject,
            'html' => $html,
            'plain' => html_to_text($html),
        ];
    }

    private static function resolve_payload_session_name(array $payload): string {
        if (empty($payload['sessions'][0]['session'])) {
            return '';
        }
        return format_string($payload['sessions'][0]['session']->name);
    }

    private static function apply_template_tokens(string $template, string $datelabel, string $sessionname): string {
        return str_replace(
            ['{{date}}', '{{session_name}}'],
            [$datelabel, $sessionname],
            $template
        );
    }

    private static function render_body_from_template(
        string $bodytpl,
        string $datelabel,
        string $summaryhtml,
        string $rosterhtml
    ): string {
        $bodytpl = str_replace('{{date}}', $datelabel, $bodytpl);
        $hadroster = strpos($bodytpl, '{{sessions_table}}') !== false;
        $hassummary = strpos($bodytpl, '{{institution_summary_table}}') !== false;

        $tokens = [
            '{{institution_summary_table}}' => $summaryhtml,
            '{{sessions_table}}' => $rosterhtml,
        ];

        $html = '';
        $remaining = $bodytpl;
        while ($remaining !== '') {
            $earliest = null;
            $matched = null;
            foreach (array_keys($tokens) as $tok) {
                $pos = strpos($remaining, $tok);
                if ($pos !== false && ($earliest === null || $pos < $earliest)) {
                    $earliest = $pos;
                    $matched = $tok;
                }
            }
            if ($earliest === null) {
                $html .= nl2br(s($remaining));
                break;
            }
            if ($earliest > 0) {
                $html .= nl2br(s(substr($remaining, 0, $earliest)));
            }
            $html .= $tokens[$matched];
            $remaining = substr($remaining, $earliest + strlen($matched));
        }

        if (!$hadroster) {
            $html .= $rosterhtml;
        }
        if (!$hassummary) {
            // Legacy templates without summary token: unchanged (no auto-insert).
        }

        return $html;
    }

    private static function render_sessions_tables_html(array $payload, array $settings): string {
        return self::render_combined_roster_table_html($payload, $settings);
    }

    private static function render_institution_summary_table_html(array $payload, array $settings): string {
        $labels = $settings['columns'] ?? self::resolve_column_labels([]);
        $headcountlabel = self::lang('preclass_col_headcount', '人數');
        $colcount = count(self::SUMMARY_COLUMN_KEYS);

        $html = '<table border="1" cellpadding="8" cellspacing="0" '
            . 'style="border-collapse:collapse;width:100%;max-width:960px;border-color:#d8e2ea;margin-top:8px;margin-bottom:12px;">';
        $html .= '<thead style="background:#eef6fb;"><tr>';
        $html .= '<th>' . s($labels['institution'] ?? self::lang('preclass_col_institution', '機構')) . '</th>';
        $html .= '<th>' . s($headcountlabel) . '</th>';
        $html .= '<th>' . s($labels['sales_name'] ?? self::lang('preclass_col_sales_name', '批次業務')) . '</th>';
        $html .= '<th>' . s($labels['sales_institution'] ?? self::lang('preclass_col_sales_institution', '業務機構')) . '</th>';
        $html .= '<th>' . s($labels['sales_phone'] ?? self::lang('preclass_col_sales_phone', '業務電話')) . '</th>';
        $html .= '</tr></thead><tbody>';

        if (empty($payload['sessions'])) {
            $html .= '<tr><td colspan="' . $colcount . '" style="color:#666;">'
                . s(self::lang('preclass_no_sessions_tomorrow', '（明日無實體課程場次）')) . '</td></tr>';
            $html .= '</tbody></table>';
            return $html;
        }

        $summaryrows = self::build_institution_summary_rows($payload);
        if (empty($summaryrows)) {
            $html .= '<tr><td colspan="' . $colcount . '" style="color:#666;">'
                . s(self::lang('preclass_no_approved_rows', '（尚無已核准學員）')) . '</td></tr>';
        } else {
            foreach ($summaryrows as $row) {
                $html .= '<tr>';
                $html .= '<td>' . s($row['institution']) . '</td>';
                $html .= '<td>' . s($row['headcount']) . '</td>';
                $html .= '<td>' . s($row['sales_name']) . '</td>';
                $html .= '<td>' . s($row['sales_institution']) . '</td>';
                $html .= '<td>' . s($row['sales_phone']) . '</td>';
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Aggregate approved learners by institution (one row per institution).
     * Multiple batch sales contacts are comma-separated per column.
     *
     * @return array<int,array{institution:string,headcount:string,sales_name:string,sales_institution:string,sales_phone:string}>
     */
    private static function build_institution_summary_rows(array $payload): array {
        /** @var array<string,array{institution:string,count:int,sales:array<string,array{name:string,institution:string,phone:string}>}> */
        $groups = [];

        foreach ($payload['sessions'] as $block) {
            foreach ($block['rows'] as $row) {
                $inst = self::resolve_row_institution($row);
                if (!isset($groups[$inst])) {
                    $groups[$inst] = [
                        'institution' => $inst,
                        'count' => 0,
                        'sales' => [],
                    ];
                }
                $groups[$inst]['count']++;

                $sales = self::format_sales_cells($row);
                if ($sales['name'] === '—') {
                    continue;
                }
                $saleskey = $sales['name'] . "\0" . $sales['institution'] . "\0" . $sales['phone'];
                $groups[$inst]['sales'][$saleskey] = $sales;
            }
        }

        if ($groups === []) {
            return [];
        }

        uasort($groups, static function (array $a, array $b): int {
            return strcmp($a['institution'], $b['institution']);
        });

        $out = [];
        foreach ($groups as $group) {
            $saleslist = array_values($group['sales']);
            usort($saleslist, static function (array $a, array $b): int {
                $cmp = strcmp($a['name'], $b['name']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                $cmp = strcmp($a['institution'], $b['institution']);
                return $cmp !== 0 ? $cmp : strcmp($a['phone'], $b['phone']);
            });

            $out[] = [
                'institution' => $group['institution'],
                'headcount' => get_string('preclass_summary_headcount', 'local_tm_course', (object) [
                    'count' => $group['count'],
                ]),
                'sales_name' => self::join_summary_sales_field($saleslist, 'name'),
                'sales_institution' => self::join_summary_sales_field($saleslist, 'institution'),
                'sales_phone' => self::join_summary_sales_field($saleslist, 'phone'),
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array{name:string,institution:string,phone:string}> $saleslist
     */
    private static function join_summary_sales_field(array $saleslist, string $field): string {
        if ($saleslist === []) {
            return '—';
        }
        $parts = [];
        foreach ($saleslist as $sales) {
            $value = trim((string) ($sales[$field] ?? ''));
            $parts[] = $value !== '' && $value !== '—' ? $value : '—';
        }
        return implode(', ', $parts);
    }

    private static function resolve_row_institution(\stdClass $row): string {
        $placeholderseq = (int) ($row->placeholder_seq ?? 0);
        if ($placeholderseq > 0) {
            $cells = enrolment_manager::format_attendance_roster_cells($row);
            $inst = trim((string) ($cells['institution'] ?? ''));
            if ($inst !== '' && $inst !== '—') {
                return $inst;
            }
        }

        $inst = trim((string) ($row->enrol_institution ?? ''));
        if ($inst === '') {
            $inst = trim((string) ($row->profile_institution ?? ''));
        }
        if ($inst === '') {
            $cells = enrolment_manager::format_attendance_roster_cells($row);
            $fromcells = trim((string) ($cells['institution'] ?? ''));
            if ($fromcells !== '' && $fromcells !== '—') {
                $inst = $fromcells;
            }
        }

        return $inst !== '' ? $inst : '—';
    }

    private static function render_combined_roster_table_html(array $payload, array $settings): string {
        $labels = $settings['columns'] ?? self::resolve_column_labels([]);
        $colcount = 7;
        $html = '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:960px;border-color:#d8e2ea;margin-top:8px;">';
        $html .= '<thead style="background:#eef6fb;"><tr>';
        foreach (array_keys(self::COLUMN_FIELDS) as $colkey) {
            $html .= '<th>' . s($labels[$colkey] ?? '') . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if (empty($payload['sessions'])) {
            $html .= '<tr><td colspan="' . $colcount . '" style="color:#666;">'
                . s(self::lang('preclass_no_sessions_tomorrow', '（明日無實體課程場次）')) . '</td></tr>';
            $html .= '</tbody></table>';
            return $html;
        }

        $hasrows = false;
        foreach ($payload['sessions'] as $block) {
            $session = $block['session'];
            $rows = $block['rows'];
            $sessioncell = self::format_session_summary_html($session);

            if (empty($rows)) {
                $html .= '<tr>';
                $html .= '<td style="vertical-align:top;">' . $sessioncell . '</td>';
                $html .= '<td colspan="' . ($colcount - 1) . '" style="color:#666;">'
                    . s(self::lang('preclass_no_approved_rows', '（尚無已核准學員）')) . '</td>';
                $html .= '</tr>';
                continue;
            }

            foreach ($rows as $row) {
                $hasrows = true;
                $cells = enrolment_manager::format_attendance_roster_cells($row);
                $learner = (string) ($cells['displayname'] ?? $cells['name'] ?? '—');
                $inst = self::resolve_row_institution($row);
                $sales = self::format_sales_cells($row);
                $html .= '<tr>';
                $html .= '<td style="vertical-align:top;">' . $sessioncell . '</td>';
                $html .= '<td>' . s($learner) . '</td>';
                $html .= '<td>' . s(self::format_enrol_type_label($row)) . '</td>';
                $html .= '<td>' . s($inst !== '' ? $inst : '—') . '</td>';
                $html .= '<td>' . s($sales['name']) . '</td>';
                $html .= '<td>' . s($sales['institution']) . '</td>';
                $html .= '<td>' . s($sales['phone']) . '</td>';
                $html .= '</tr>';
            }
        }

        if (!$hasrows && !empty($payload['sessions'])) {
            $html .= '<tr><td colspan="' . $colcount . '" style="color:#666;">'
                . s(self::lang('preclass_no_approved_rows', '（尚無已核准學員）')) . '</td></tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private static function format_session_summary_html(\stdClass $session): string {
        $title = s(format_string($session->name));
        $when = s(userdate((int) $session->starttime, get_string('strftimedatetimeshort')))
            . ' – ' . s(userdate((int) $session->endtime, get_string('strftimetime', 'langconfig')));
        $location = trim(session_manager::resolve_session_location($session));
        if ($location === '') {
            $location = 'TBD';
        }
        return '<strong style="color:#005f7e;">' . $title . '</strong><br><span style="font-size:12px;color:#555;">'
            . $when . '<br>' . s(self::lang('session_location', '地點 / 教室')) . ': ' . s($location) . '</span>';
    }

    /**
     * Test send: extra emails + approver target users (batch submitters need real sessions).
     *
     * @return array<string,\stdClass>
     */
    private static function resolve_test_recipients(array $settings): array {
        global $DB;

        $byemail = [];
        foreach (self::parse_extra_emails($settings['extra_emails']) as $email) {
            $key = strtolower($email);
            $existing = $DB->get_record('user', ['email' => $email, 'deleted' => 0], '*', IGNORE_MISSING);
            $byemail[$key] = $existing ?: self::stub_user_for_email($email);
        }
        if (in_array(notification_helper::TARGET_APPROVER, $settings['targets'], true)) {
            foreach (self::collect_target_user_ids([notification_helper::TARGET_APPROVER], []) as $userid) {
                $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
                if ($user && !empty($user->email)) {
                    $byemail[strtolower(trim($user->email))] = $user;
                }
            }
        }
        return $byemail;
    }

    /**
     * @return array<string,\stdClass>
     */
    private static function resolve_recipients(array $payload, array $settings): array {
        global $DB;

        $byemail = [];

        foreach (self::parse_extra_emails($settings['extra_emails']) as $email) {
            $key = strtolower($email);
            $existing = $DB->get_record('user', ['email' => $email, 'deleted' => 0], '*', IGNORE_MISSING);
            $byemail[$key] = $existing ?: self::stub_user_for_email($email);
        }

        foreach (self::collect_target_user_ids($settings['targets'], $payload) as $userid) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
            if ($user && !empty($user->email)) {
                $byemail[strtolower(trim($user->email))] = $user;
            }
        }

        return $byemail;
    }

    /**
     * @param string[] $targets
     * @return int[]
     */
    private static function collect_target_user_ids(array $targets, array $payload): array {
        $ids = [];
        if (in_array(notification_helper::TARGET_APPROVER, $targets, true)) {
            $approvers = get_users_by_capability(\context_system::instance(), 'local/tm_course:approve', 'u.id');
            foreach ($approvers as $u) {
                $ids[] = (int) $u->id;
            }
        }
        if (in_array(notification_helper::TARGET_BATCH_SUBMITTER, $targets, true)) {
            foreach ($payload['sessions'] as $block) {
                foreach ($block['rows'] as $row) {
                    $submitterid = (int) ($row->batch_submittedby ?? 0);
                    $learnerid = (int) ($row->userid ?? 0);
                    if ($submitterid > 0 && $submitterid !== $learnerid) {
                        $ids[] = $submitterid;
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public static function parse_extra_emails(string $raw): array {
        $parts = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [];
        }
        $out = [];
        foreach ($parts as $p) {
            $email = clean_param(trim($p), PARAM_EMAIL);
            if ($email !== '') {
                $out[strtolower($email)] = $email;
            }
        }
        return array_values($out);
    }

    /**
     * email_to_user() / fullname() require a complete user row (all name fields + username).
     */
    private static function user_for_email_delivery(\stdClass $partial): ?\stdClass {
        global $DB;

        $email = trim((string) ($partial->email ?? ''));
        if ($email === '') {
            return null;
        }

        if (!empty($partial->id) && (int) $partial->id > 0) {
            $full = $DB->get_record('user', ['id' => (int) $partial->id, 'deleted' => 0], '*', IGNORE_MISSING);
            if ($full && !empty($full->email)) {
                return $full;
            }
        }

        $full = $DB->get_record('user', ['email' => $email, 'deleted' => 0], '*', IGNORE_MISSING);
        if ($full) {
            return $full;
        }

        return self::stub_user_for_email($email);
    }

    private static function stub_user_for_email(string $email): \stdClass {
        $localraw = strstr($email, '@', true);
        $local = clean_param($localraw !== false ? $localraw : 'recipient', PARAM_USERNAME);
        if ($local === '') {
            $local = 'recipient';
        }

        $stub = new \stdClass();
        // Moodle email_to_user() rejects empty($user->id); id=0 triggers "Can not send email to null user".
        // Use -1 for non-Moodle recipients (same pattern as mod_customcert).
        $stub->id = -1;
        $stub->email = $email;
        $stub->username = $local;
        $stub->firstname = $local;
        $stub->lastname = '.';
        $stub->middlename = '';
        $stub->alternatename = '';
        $stub->firstnamephonetic = '';
        $stub->lastnamephonetic = '';
        $stub->mailformat = 1;
        $stub->maildisplay = 1;
        $stub->maildigest = 0;
        $stub->deleted = 0;
        $stub->auth = 'manual';
        $stub->suspended = 0;
        $stub->confirmed = 1;
        $stub->lang = current_language();
        $stub->timezone = '99';
        return $stub;
    }

    private static function format_enrol_type_label(\stdClass $row): string {
        $learnerid = (int) ($row->userid ?? 0);
        $submitterid = (int) ($row->batch_submittedby ?? 0);
        if ($submitterid <= 0 || $submitterid === $learnerid) {
            return self::lang('preclass_enrol_type_self', '自主報名');
        }
        if ((int) ($row->placeholder_seq ?? 0) > 0 || trim((string) ($row->seat_company ?? '')) !== '') {
            return self::lang('preclass_enrol_type_batch_seat', '批次報名（卡位）');
        }
        return self::lang('preclass_enrol_type_batch_full', '批次報名（完整名單）');
    }

    private static function format_sales_cells(\stdClass $row): array {
        $learnerid = (int) ($row->userid ?? 0);
        $submitterid = (int) ($row->batch_submittedby ?? 0);
        if ($submitterid <= 0 || $submitterid === $learnerid) {
            return ['name' => '—', 'institution' => '—', 'phone' => '—'];
        }
        $name = trim((string) ($row->submitter_firstname ?? '') . ' ' . (string) ($row->submitter_lastname ?? ''));
        $inst = trim((string) ($row->submitter_institution ?? ''));
        $phone = trim((string) ($row->submitter_phone ?? ''));
        return [
            'name' => $name !== '' ? $name : '—',
            'institution' => $inst !== '' ? $inst : '—',
            'phone' => $phone !== '' ? $phone : '—',
        ];
    }
}
