<?php
/**
 * Bento (lunch) request email for a single onsite session — present attendees only.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class bento_notification_manager {

    private const CFG = 'bento_notify_';

    /** @var array<string,array{config:string,lang:string,fallback:string}> */
    private const COLUMN_FIELDS = [
        'institution' => ['config' => 'col_institution', 'lang' => 'bento_col_institution', 'fallback' => '機構'],
        'present_count' => ['config' => 'col_present_count', 'lang' => 'bento_col_present_count', 'fallback' => '出席人數'],
        'meat' => ['config' => 'col_meat', 'lang' => 'bento_col_meat', 'fallback' => '葷食'],
        'vegetarian' => ['config' => 'col_vegetarian', 'lang' => 'bento_col_vegetarian', 'fallback' => '素食'],
        'diet_notes' => ['config' => 'col_diet_notes', 'lang' => 'bento_col_diet_notes', 'fallback' => '葷素備註'],
        'sales_name' => ['config' => 'col_sales_name', 'lang' => 'preclass_col_sales_name', 'fallback' => '批次業務'],
        'sales_institution' => ['config' => 'col_sales_institution', 'lang' => 'preclass_col_sales_institution', 'fallback' => '業務機構'],
        'sales_phone' => ['config' => 'col_sales_phone', 'lang' => 'preclass_col_sales_phone', 'fallback' => '業務電話'],
    ];

    /** @var string[] */
    public static function get_template_tokens(): array {
        return [
            '{{session_name}}',
            '{{session_date}}',
            '{{session_location}}',
            '{{bento_summary_table}}',
        ];
    }

    /**
     * @return array{
     *   extra_emails:string,
     *   cc_emails:string,
     *   subject_zh_tw:string,
     *   body_zh_tw:string,
     *   columns:array<string,string>
     * }
     */
    public static function get_settings(): array {
        return [
            'extra_emails' => (string) get_config('local_tm_course', self::CFG . 'extra_emails'),
            'cc_emails' => (string) get_config('local_tm_course', self::CFG . 'cc_emails'),
            'subject_zh_tw' => (string) get_config('local_tm_course', self::CFG . 'subject_zh_tw'),
            'body_zh_tw' => (string) get_config('local_tm_course', self::CFG . 'body_zh_tw'),
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
        string $extraemails,
        string $ccemails,
        string $subjectzhtw,
        string $bodyzhtw,
        array $columns = []
    ): void {
        set_config(self::CFG . 'extra_emails', trim($extraemails), 'local_tm_course');
        set_config(self::CFG . 'cc_emails', trim($ccemails), 'local_tm_course');
        set_config(self::CFG . 'subject_zh_tw', trim($subjectzhtw), 'local_tm_course');
        set_config(self::CFG . 'body_zh_tw', $bodyzhtw, 'local_tm_course');
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

    public static function get_default_subject(): string {
        return get_string('bento_notify_default_subject', 'local_tm_course');
    }

    public static function get_default_body(): string {
        return get_string('bento_notify_default_body', 'local_tm_course');
    }

    /**
     * Compose email for modal preview / send.
     *
     * @param int $sessionid
     * @param array{extra_emails?:string,subject_zh_tw?:string,body_zh_tw?:string} $overrides
     * @return array{
     *   subject:string,
     *   html:string,
     *   plain:string,
     *   present_count:int,
     *   diet_unknown_present:int
     * }
     */
    public static function compose_for_session(int $sessionid, array $overrides = []): array {
        $session = session_manager::get_session($sessionid);
        $settings = self::merge_overrides(self::get_settings(), $overrides);
        $rows = self::get_present_enrol_rows($sessionid);
        $labels = self::resolve_column_labels($settings['columns'] ?? []);
        $summaryhtml = self::render_bento_summary_table_html($rows, $labels);
        $tokens = self::build_tokens($session, $summaryhtml);
        $dietunknown = 0;
        foreach ($rows as $row) {
            $dc = (string) ($row->diet_choice ?? '');
            if ($dc !== 'A' && $dc !== 'B') {
                $dietunknown++;
            }
        }

        $subjecttpl = $settings['subject_zh_tw'] !== '' ? $settings['subject_zh_tw'] : self::get_default_subject();
        $bodytpl = $settings['body_zh_tw'] !== '' ? $settings['body_zh_tw'] : self::get_default_body();

        $subject = self::apply_tokens($subjecttpl, $tokens);
        $bodyhtml = notification_editor_helper::format_email_body_html(
            self::apply_tokens($bodytpl, $tokens)
        );
        if (strpos($bodytpl, '{{bento_summary_table}}') === false) {
            $bodyhtml .= $summaryhtml;
        }
        if ($dietunknown > 0) {
            $bodyhtml .= '<p style="color:#856404;font-size:13px;margin-top:10px;">'
                . s(get_string('bento_diet_unknown_note', 'local_tm_course', (object) ['n' => $dietunknown]))
                . '</p>';
        }

        $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.5;">' . $bodyhtml
            . '<p style="color:#666;font-size:12px;margin-top:16px;">'
            . s(get_string('bento_notify_footer', 'local_tm_course'))
            . '</p></div>';

        return [
            'subject' => $subject,
            'html' => $html,
            'plain' => html_to_text($html),
            'present_count' => count($rows),
            'diet_unknown_present' => $dietunknown,
        ];
    }

    /**
     * @param array{extra_emails?:string,cc_emails?:string,subject_zh_tw?:string,body_zh_tw?:string} $overrides
     * @return array{sent:int,failed:int,recipients:string[]}
     */
    public static function send_for_session(int $sessionid, array $overrides, int $senderid): array {
        $rendered = self::compose_for_session($sessionid, $overrides);
        if ($rendered['present_count'] === 0) {
            return ['sent' => 0, 'failed' => 0, 'recipients' => []];
        }

        $settings = self::merge_overrides(self::get_settings(), $overrides);
        $recipients = self::resolve_recipients($settings['extra_emails']);
        if ($recipients === []) {
            return ['sent' => 0, 'failed' => 0, 'recipients' => []];
        }

        $recipientusers = [];
        foreach ($recipients as $recipient) {
            $user = self::user_for_email_delivery($recipient);
            if (!$user) {
                continue;
            }
            $emailkey = strtolower(trim((string) $user->email));
            if ($emailkey !== '') {
                $recipientusers[$emailkey] = $user;
            }
        }
        if ($recipientusers === []) {
            return ['sent' => 0, 'failed' => 1, 'recipients' => []];
        }

        $tokeys = array_fill_keys(array_keys($recipientusers), true);
        $ccemails = notification_mail_helper::parse_cc_excluding_to($tokeys, $settings['cc_emails']);

        global $DB;
        $from = $DB->get_record('user', ['id' => $senderid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$from) {
            $from = \core_user::get_noreply_user();
        }

        try {
            if (notification_mail_helper::send_html_to_many(
                array_values($recipientusers),
                $from,
                $rendered['subject'],
                $rendered['plain'],
                $rendered['html'],
                $ccemails
            )) {
                return [
                    'sent' => 1,
                    'failed' => 0,
                    'recipients' => array_keys($recipientusers),
                ];
            }
        } catch (\Throwable $t) {
            debugging('TM Course bento email failed: ' . $t->getMessage(), DEBUG_DEVELOPER);
        }

        return ['sent' => 0, 'failed' => 1, 'recipients' => []];
    }

    /**
     * @return \stdClass[]
     */
    public static function get_present_enrol_rows(int $sessionid): array {
        global $DB;

        $sql = "SELECT e.id, e.userid, e.institution AS enrol_institution, e.diet_choice, e.diet_meat_other,
                       e.batch_submittedby, e.seat_company, e.placeholder_seq,
                       e.linked_userid, e.linked_email, e.placeholder_name,
                       u.firstname, u.lastname, u.email, u.institution AS profile_institution,
                       lu.firstname AS lu_firstname, lu.lastname AS lu_lastname, lu.email AS lu_email,
                       sb.firstname AS submitter_firstname, sb.lastname AS submitter_lastname,
                       sb.institution AS submitter_institution, sb.phone1 AS submitter_phone
                  FROM {local_tm_course_enrolments} e
                  JOIN {user} u ON u.id = e.userid AND u.deleted = 0
             LEFT JOIN {user} lu ON lu.id = e.linked_userid AND e.linked_userid > 0
             LEFT JOIN {user} sb ON sb.id = e.batch_submittedby AND sb.deleted = 0
                 WHERE e.sessionid = :sid
                   AND e.status = :approved
                   AND e.attended = :present
              ORDER BY u.lastname ASC, u.firstname ASC";

        return array_values($DB->get_records_sql($sql, [
            'sid' => $sessionid,
            'approved' => session_manager::ENROL_APPROVED,
            'present' => attendance_manager::ATTEND_PRESENT,
        ]));
    }

    /**
     * @param \stdClass[] $rows
     * @param array<string,string>|null $labels
     */
    public static function render_bento_summary_table_html(array $rows, ?array $labels = null): string {
        $summaryrows = self::build_summary_rows($rows);
        $labels = $labels ?? self::resolve_column_labels([]);
        $colcount = 8;

        $html = '<table border="1" cellpadding="8" cellspacing="0" '
            . 'style="border-collapse:collapse;width:100%;max-width:960px;border-color:#d8e2ea;margin-top:8px;margin-bottom:12px;">';
        $html .= '<thead style="background:#eef6fb;"><tr>';
        $html .= '<th>' . s($labels['institution'] ?? '') . '</th>';
        $html .= '<th>' . s($labels['present_count'] ?? '') . '</th>';
        $html .= '<th>' . s($labels['meat'] ?? '') . '</th>';
        $html .= '<th>' . s($labels['vegetarian'] ?? '') . '</th>';
        $html .= '<th>' . s($labels['diet_notes'] ?? '') . '</th>';
        $html .= '<th>' . s($labels['sales_name'] ?? '') . '</th>';
        $html .= '<th>' . s($labels['sales_institution'] ?? '') . '</th>';
        $html .= '<th>' . s($labels['sales_phone'] ?? '') . '</th>';
        $html .= '</tr></thead><tbody>';

        if ($summaryrows === []) {
            $html .= '<tr><td colspan="' . $colcount . '" style="color:#666;">'
                . s(get_string('bento_no_present_rows', 'local_tm_course')) . '</td></tr>';
        } else {
            $totalpresent = 0;
            $totalmeat = 0;
            $totalveg = 0;
            foreach ($summaryrows as $row) {
                $html .= '<tr>';
                $html .= '<td>' . s($row['institution']) . '</td>';
                $html .= '<td>' . s((string) $row['present_count']) . '</td>';
                $html .= '<td>' . s((string) $row['meat_count']) . '</td>';
                $html .= '<td>' . s((string) $row['vegetarian_count']) . '</td>';
                $html .= '<td>' . s($row['diet_notes']) . '</td>';
                $html .= '<td>' . s($row['sales_name']) . '</td>';
                $html .= '<td>' . s($row['sales_institution']) . '</td>';
                $html .= '<td>' . s($row['sales_phone']) . '</td>';
                $html .= '</tr>';
                $totalpresent += (int) $row['present_count'];
                $totalmeat += (int) $row['meat_count'];
                $totalveg += (int) $row['vegetarian_count'];
            }
            $html .= '<tr style="background:#f4f8fb;font-weight:bold;">';
            $html .= '<td>' . s(get_string('bento_row_total', 'local_tm_course')) . '</td>';
            $html .= '<td>' . s((string) $totalpresent) . '</td>';
            $html .= '<td>' . s((string) $totalmeat) . '</td>';
            $html .= '<td>' . s((string) $totalveg) . '</td>';
            $html .= '<td></td>';
            $html .= '<td colspan="3"></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * @param \stdClass[] $rows
     * @return array<int,array{
     *   institution:string,
     *   present_count:int,
     *   meat_count:int,
     *   vegetarian_count:int,
     *   diet_notes:string,
     *   sales_name:string,
     *   sales_institution:string,
     *   sales_phone:string
     * }>
     */
    private static function build_summary_rows(array $rows): array {
        /** @var array<string,array{institution:string,present_count:int,meat_count:int,vegetarian_count:int,diet_notes:array,sales:array}> */
        $groups = [];

        foreach ($rows as $row) {
            $inst = self::resolve_row_institution($row);
            if (!isset($groups[$inst])) {
                $groups[$inst] = [
                    'institution' => $inst,
                    'present_count' => 0,
                    'meat_count' => 0,
                    'vegetarian_count' => 0,
                    'diet_notes' => [],
                    'sales' => [],
                ];
            }
            $groups[$inst]['present_count']++;
            $dc = (string) ($row->diet_choice ?? '');
            if ($dc === 'A') {
                $groups[$inst]['meat_count']++;
            } else if ($dc === 'B') {
                $groups[$inst]['vegetarian_count']++;
            }

            $note = trim((string) ($row->diet_meat_other ?? ''));
            if ($note !== '') {
                $cells = enrolment_manager::format_attendance_roster_cells($row);
                $name = trim((string) ($cells['displayname'] ?? ''));
                if ($name === '' || $name === '—') {
                    $groups[$inst]['diet_notes'][] = format_string($note);
                } else {
                    $groups[$inst]['diet_notes'][] = $name . '：' . format_string($note);
                }
            }

            $sales = self::format_sales_cells($row);
            if ($sales['name'] !== '—') {
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
                return $cmp !== 0 ? $cmp : strcmp($a['institution'], $b['institution']);
            });
            $out[] = [
                'institution' => $group['institution'],
                'present_count' => $group['present_count'],
                'meat_count' => $group['meat_count'],
                'vegetarian_count' => $group['vegetarian_count'],
                'diet_notes' => self::join_diet_notes($group['diet_notes']),
                'sales_name' => self::join_sales_field($saleslist, 'name'),
                'sales_institution' => self::join_sales_field($saleslist, 'institution'),
                'sales_phone' => self::join_sales_field($saleslist, 'phone'),
            ];
        }
        return $out;
    }

    /**
     * @param string[] $notes
     */
    private static function join_diet_notes(array $notes): string {
        $notes = array_values(array_filter(array_map('trim', $notes)));
        if ($notes === []) {
            return '—';
        }
        return implode('；', $notes);
    }

    /**
     * @param array<int,array{name:string,institution:string,phone:string}> $saleslist
     */
    private static function join_sales_field(array $saleslist, string $field): string {
        if ($saleslist === []) {
            return '—';
        }
        $parts = [];
        foreach ($saleslist as $sales) {
            $value = trim((string) ($sales[$field] ?? ''));
            $parts[] = ($value !== '' && $value !== '—') ? $value : '—';
        }
        return implode(', ', $parts);
    }

    private static function resolve_row_institution(\stdClass $row): string {
        $cells = enrolment_manager::format_attendance_roster_cells($row);
        $inst = trim((string) ($cells['institution'] ?? ''));
        if ($inst !== '' && $inst !== '—') {
            return $inst;
        }
        $inst = trim((string) ($row->enrol_institution ?? ''));
        if ($inst === '') {
            $inst = trim((string) ($row->profile_institution ?? ''));
        }
        return $inst !== '' ? $inst : '—';
    }

    /**
     * @return array{name:string,institution:string,phone:string}
     */
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

    /**
     * @param array<string,string> $tokens
     */
    private static function apply_tokens(string $tpl, array $tokens): string {
        return str_replace(array_keys($tokens), array_values($tokens), $tpl);
    }

    /**
     * @return array<string,string>
     */
    private static function build_tokens(\stdClass $session, string $summaryhtml): array {
        $datelabel = userdate((int) $session->starttime, get_string('strftimedate', 'langconfig'));
        $location = trim(session_manager::resolve_session_location($session));
        if ($location === '') {
            $location = '—';
        }
        return [
            '{{session_name}}' => format_string($session->name),
            '{{session_date}}' => $datelabel,
            '{{session_location}}' => $location,
            '{{bento_summary_table}}' => $summaryhtml,
        ];
    }

    /**
     * @param array{extra_emails:string,cc_emails:string,subject_zh_tw:string,body_zh_tw:string,columns:array<string,string>} $base
     * @param array{extra_emails?:string,cc_emails?:string,subject_zh_tw?:string,body_zh_tw?:string,columns?:array<string,string>} $overrides
     * @return array{extra_emails:string,cc_emails:string,subject_zh_tw:string,body_zh_tw:string,columns:array<string,string>}
     */
    private static function merge_overrides(array $base, array $overrides): array {
        if (array_key_exists('extra_emails', $overrides)) {
            $base['extra_emails'] = (string) $overrides['extra_emails'];
        }
        if (array_key_exists('cc_emails', $overrides)) {
            $base['cc_emails'] = (string) $overrides['cc_emails'];
        }
        if (array_key_exists('subject_zh_tw', $overrides)) {
            $base['subject_zh_tw'] = (string) $overrides['subject_zh_tw'];
        }
        if (array_key_exists('body_zh_tw', $overrides)) {
            $base['body_zh_tw'] = (string) $overrides['body_zh_tw'];
        }
        if (array_key_exists('columns', $overrides) && is_array($overrides['columns'])) {
            $base['columns'] = self::resolve_column_labels($overrides['columns']);
        }
        return $base;
    }

    /**
     * @return array<string,\stdClass>
     */
    private static function resolve_recipients(string $raw): array {
        global $DB;
        $byemail = [];
        foreach (pre_class_notification_manager::parse_extra_emails($raw) as $email) {
            $key = strtolower($email);
            $existing = $DB->get_record('user', ['email' => $email, 'deleted' => 0], '*', IGNORE_MISSING);
            $byemail[$key] = $existing ?: self::stub_user_for_email($email);
        }
        return $byemail;
    }

    private static function stub_user_for_email(string $email): \stdClass {
        $localraw = strstr($email, '@', true);
        $local = clean_param($localraw !== false ? $localraw : 'recipient', PARAM_USERNAME);
        if ($local === '') {
            $local = 'recipient';
        }
        $stub = new \stdClass();
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
        return $full ?: self::stub_user_for_email($email);
    }
}
