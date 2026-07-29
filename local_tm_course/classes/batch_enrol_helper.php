<?php
/**
 * Shared batch enrolment parsing (full learner list mode).
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/session_manager.php');
require_once(__DIR__ . '/enrolment_manager.php');
require_once(__DIR__ . '/session_verification_manager.php');

class batch_enrol_helper {

    /**
     * Parse POST arrays for batch full-mode rows into enrolment_manager entries.
     *
     * @param int    $submitterid
     * @param int    $sessionid   0 when no session yet (reservation draft).
     * @param array  $rowdata     Keys: emails, firstnames, lastnames, institutions, choices, specialnotes (parallel arrays).
     * @param array  $options {
     *     @type bool        $allow_partial_placeholders Allow incomplete rows → placeholder seats (default false).
     *     @type bool|null   $require_diet               Force A/B diet; null = auto from session when sessionid>0.
     *     @type bool        $needsvq                    Override VQ+diet relax for online (optional).
     *     @type bool        $isonline                   Delivery hint when sessionid=0.
     *     @type bool        $create_missing_users       Create Moodle accounts for unknown emails (default true).
     *                                                   When false, unknown emails become entries with userid=0
     *                                                   and account_missing=true (no user created).
     * }
     * @return array{ok:bool,error:string,entries:array<int,array>}
     */
    public static function parse_full_mode_rows(
        int $submitterid,
        int $sessionid,
        array $rowdata,
        array $options = []
    ): array {
        global $DB, $CFG;

        $allowpartial = !empty($options['allow_partial_placeholders']);
        $requireddiet = array_key_exists('require_diet', $options) ? $options['require_diet'] : null;
        $isonline = !empty($options['isonline']);
        $createmissing = !array_key_exists('create_missing_users', $options) || !empty($options['create_missing_users']);

        $batchneedsvq = false;
        if ($sessionid > 0) {
            $sessrow = session_manager::get_session($sessionid);
            $isonline = ((string)($sessrow->delivery_mode ?? '')) === session_manager::DELIVERY_ONLINE;
            $batchneedsvq = session_verification_manager::session_has_verification_questions($sessrow);
        } else if (array_key_exists('needsvq', $options)) {
            $batchneedsvq = (bool) $options['needsvq'];
        }

        if ($requireddiet === null) {
            $requireddiet = !$batchneedsvq || !$isonline;
        }

        $emails = $rowdata['emails'] ?? [];
        $firstnames = $rowdata['firstnames'] ?? [];
        $lastnames = $rowdata['lastnames'] ?? [];
        $insts = $rowdata['institutions'] ?? [];
        $choices = $rowdata['choices'] ?? [];
        $specialnotes = $rowdata['specialnotes'] ?? [];

        $entries = [];
        $seenemails = [];
        $placeholderseq = 0;
        $n = max(count($emails), count($choices), count($firstnames), count($lastnames));

        for ($i = 0; $i < $n; $i++) {
            $emailraw = isset($emails[$i]) ? trim((string) $emails[$i]) : '';
            $email = $emailraw !== '' ? \core_text::strtolower($emailraw) : '';
            $firstname = trim((string) ($firstnames[$i] ?? ''));
            $lastname = trim((string) ($lastnames[$i] ?? ''));
            $instcell = isset($insts[$i]) ? clean_param(trim((string) $insts[$i]), PARAM_TEXT) : '';
            $ch = strtoupper((string) ($choices[$i] ?? ''));
            $special = (string) ($specialnotes[$i] ?? '');

            if ($email === '' && $firstname === '' && $lastname === '' && $instcell === '') {
                continue;
            }

            if ($instcell === '') {
                return ['ok' => false, 'error' => get_string('error_institution_required', 'local_tm_course'), 'entries' => []];
            }

            $isfullprofile = ($email !== '' && $firstname !== '' && $lastname !== '');
            if ($isfullprofile) {
                if (isset($seenemails[$email])) {
                    return ['ok' => false, 'error' => get_string('error_batch_duplicate_email', 'local_tm_course'), 'entries' => []];
                }
                $seenemails[$email] = true;
                if ($requireddiet) {
                    if (!in_array($ch, ['A', 'B'], true)) {
                        return ['ok' => false, 'error' => get_string('error_batch_diet_required', 'local_tm_course'), 'entries' => []];
                    }
                } else if ($batchneedsvq && $isonline) {
                    if (!in_array($ch, ['A', 'B'], true)) {
                        $ch = 'A';
                    }
                } else if (!in_array($ch, ['A', 'B'], true)) {
                    return ['ok' => false, 'error' => get_string('error_batch_diet_required', 'local_tm_course'), 'entries' => []];
                }

                $learnerpayload = [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'email' => $email,
                    'institution' => $instcell,
                    'diet_choice' => $ch,
                    'diet_special_note' => $special,
                ];
                if (!$createmissing) {
                    $existing = $DB->get_record('user', [
                        'email' => $email,
                        'deleted' => 0,
                        'mnethostid' => $CFG->mnet_localhost_id,
                    ], 'id,institution', IGNORE_MISSING);
                    if (!$existing) {
                        $entries[] = [
                            'userid' => 0,
                            'account_missing' => true,
                            'institution' => $instcell,
                            'diet' => [
                                'choice' => $ch,
                                'special_note' => $special,
                            ],
                            'learner' => $learnerpayload,
                        ];
                        continue;
                    }
                    if (trim((string)$existing->institution) === '') {
                        $DB->set_field('user', 'institution', $instcell, ['id' => (int)$existing->id]);
                    }
                    $entries[] = [
                        'userid' => (int) $existing->id,
                        'account_missing' => false,
                        'institution' => $instcell,
                        'diet' => [
                            'choice' => $ch,
                            'special_note' => $special,
                        ],
                        'learner' => $learnerpayload,
                    ];
                    continue;
                }
                try {
                    $provisioned = enrolment_manager::provision_or_link_batch_user(
                        $sessionid,
                        $email,
                        $firstname,
                        $lastname,
                        $instcell,
                        $submitterid
                    );
                } catch (\moodle_exception $ex) {
                    return ['ok' => false, 'error' => $ex->getMessage(), 'entries' => []];
                }
                $entries[] = [
                    'userid' => (int) $provisioned['userid'],
                    'institution' => $instcell,
                    'diet' => [
                        'choice' => $ch,
                        'special_note' => $special,
                    ],
                    'learner' => $learnerpayload,
                ];
                continue;
            }

            if (!$allowpartial) {
                if ($email === '' || $firstname === '' || $lastname === '') {
                    return ['ok' => false, 'error' => get_string('reservation_batch_error_full_row_required', 'local_tm_course'), 'entries' => []];
                }
                if (!validate_email($email)) {
                    return ['ok' => false, 'error' => get_string('reservation_error_manual_email_invalid', 'local_tm_course'), 'entries' => []];
                }
            }

            if (!$allowpartial || $sessionid <= 0) {
                return ['ok' => false, 'error' => get_string('reservation_batch_error_full_row_required', 'local_tm_course'), 'entries' => []];
            }

            $placeholderseq++;
            $userid = enrolment_manager::create_placeholder_holder_user($sessionid, $placeholderseq, $instcell);
            $entries[] = [
                'userid' => $userid,
                'institution' => $instcell,
                'diet' => [
                    'choice' => 'A',
                    'special_note' => '',
                ],
                'placeholder_seq' => $placeholderseq,
                'seat_company' => $instcell,
                'is_placeholder_holder' => true,
                'linked_email_pending' => $email !== '' ? $email : null,
            ];
        }

        return ['ok' => true, 'error' => '', 'entries' => $entries];
    }

    /**
     * Read full-mode batch fields from current POST request.
     *
     * @return array{emails:array,firstnames:array,lastnames:array,institutions:array,choices:array,specialnotes:array}
     */
    public static function full_mode_post_arrays(): array {
        return [
            'emails' => optional_param_array('entry_email', [], PARAM_TEXT),
            'firstnames' => optional_param_array('entry_firstname', [], PARAM_TEXT),
            'lastnames' => optional_param_array('entry_lastname', [], PARAM_TEXT),
            'institutions' => optional_param_array('entry_institution', [], PARAM_TEXT),
            'choices' => optional_param_array('entry_diet', [], PARAM_ALPHA),
            'specialnotes' => optional_param_array('entry_special_note', [], PARAM_TEXT),
        ];
    }

    /**
     * Build batch_enrol_pending entries from stored reservation learners.
     *
     * @return array<int,array>
     */
    public static function pending_entries_from_reservation_learners(int $reservationid): array {
        global $DB;

        $learners = $DB->get_records('local_tm_course_resv_learner', ['reservationid' => $reservationid], 'id ASC');
        $entries = [];
        foreach ($learners as $lr) {
            $uid = (int) ($lr->userid ?? 0);
            if ($uid < 2) {
                continue;
            }
            $dietchoice = strtoupper(trim((string) ($lr->diet_choice ?? '')));
            if (!in_array($dietchoice, ['A', 'B'], true)) {
                $dietchoice = 'A';
            }
            $entries[$uid] = [
                'userid' => $uid,
                'institution' => (string) ($lr->institution ?? ''),
                'diet' => [
                    'choice' => $dietchoice,
                    'special_note' => (string) ($lr->diet_special_note ?? ''),
                ],
                'reservation_initial' => 1,
            ];
        }
        return array_values($entries);
    }
}
