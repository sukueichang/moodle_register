<?php
/**
 * M4 — Auto-grant rules for batch enrolment capability.
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class permissions_manager {

    public const RULE_IDNUMBER       = 'idnumber';
    /** Profile field institution (機構/公司) equals pattern. */
    public const RULE_INSTITUTION    = 'institution';
    public const RULE_NAME_CONTAINS  = 'name_contains';
    public const TARGET_BATCH = 'batch';
    public const TARGET_ATTENDANCE = 'attendance';

    /**
     * True if user may use batch enrolment: explicit capability OR matches an enabled rule.
     */
    public static function user_can_batch_enrol(?\stdClass $user = null): bool {
        global $USER;
        $user = $user ?? $USER;
        if (empty($user->id) || isguestuser($user)) {
            return false;
        }
        $ctx = \context_system::instance();
        if (has_capability('local/tm_course:batchenrol', $ctx, $user)) {
            return true;
        }
        return self::user_matches_any_rule_for_target($user, self::TARGET_BATCH);
    }

    /**
     * True if user may open attendance pages: explicit capability OR matches enabled attendance rule.
     */
    public static function user_can_attendance(?\stdClass $user = null): bool {
        global $USER;
        $user = $user ?? $USER;
        if (empty($user->id) || isguestuser($user)) {
            return false;
        }
        $ctx = \context_system::instance();
        if (has_capability('local/tm_course:attendance', $ctx, $user) || has_capability('local/tm_course:manage', $ctx, $user)) {
            return true;
        }
        return self::user_matches_any_rule_for_target($user, self::TARGET_ATTENDANCE);
    }

    /**
     * True if user is allowed to self-enrol based on configured system roles.
     *
     * Backward compatibility: when no role is configured, keep legacy behavior
     * (all users with enrol capability are treated as allowed by role filter).
     */
    public static function user_can_self_enrol_by_role(?\stdClass $user = null): bool {
        global $USER;
        $user = $user ?? $USER;
        if (empty($user->id) || isguestuser($user)) {
            return false;
        }

        $configuredroleids = self::get_configured_self_enrol_roleids();
        if (empty($configuredroleids)) {
            return true;
        }

        $userroleids = self::get_user_system_roleids((int)$user->id);
        if (empty($userroleids)) {
            return false;
        }

        return !empty(array_intersect($configuredroleids, $userroleids));
    }

    /**
     * @return int[]
     */
    public static function get_configured_self_enrol_roleids(): array {
        return self::parse_configured_roleids((string)get_config('local_tm_course', 'self_enrol_roleids'));
    }

    /**
     * True if user may see plugin navigation, frontpage dashboard, and main calendar pages.
     *
     * Backward compatibility: when no role is configured, all logged-in users may view.
     * Site administrators always may view regardless of configuration.
     */
    public static function user_can_view_tm_course(?\stdClass $user = null): bool {
        global $USER;
        $user = $user ?? $USER;
        if (empty($user->id) || isguestuser($user)) {
            return false;
        }
        if (is_siteadmin($user)) {
            return true;
        }

        $configuredroleids = self::get_configured_dashboard_view_roleids();
        if (empty($configuredroleids)) {
            return true;
        }

        $userroleids = self::get_user_system_roleids((int)$user->id);
        if (empty($userroleids)) {
            return false;
        }

        return !empty(array_intersect($configuredroleids, $userroleids));
    }

    /**
     * @return int[]
     */
    public static function get_configured_dashboard_view_roleids(): array {
        return self::parse_configured_roleids((string)get_config('local_tm_course', 'dashboard_view_roleids'));
    }

    /**
     * Redirect to site home (or JSON 403 for AJAX) when view access is denied.
     */
    public static function require_view_access(bool $ajax = false): void {
        if (self::user_can_view_tm_course()) {
            return;
        }

        $message = get_string('error_view_access_denied', 'local_tm_course');
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['error' => $message, 'events' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        redirect(new \moodle_url('/'), $message, null, \core\output\notification::NOTIFY_WARNING);
    }

    /**
     * @return int[]
     */
    private static function parse_configured_roleids(string $raw): array {
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($parts)) {
            return [];
        }

        $roleids = array_map('intval', $parts);
        $roleids = array_filter($roleids, static function(int $roleid): bool {
            return $roleid > 0;
        });
        return array_values(array_unique($roleids));
    }

    /**
     * @return int[]
     */
    public static function get_user_system_roleids(int $userid): array {
        $ctx = \context_system::instance();
        $assignments = get_user_roles($ctx, $userid, true);
        if (empty($assignments)) {
            return [];
        }
        $roleids = [];
        foreach ($assignments as $assignment) {
            $roleids[] = (int)$assignment->roleid;
        }
        $roleids = array_filter($roleids, static function(int $roleid): bool {
            return $roleid > 0;
        });
        return array_values(array_unique($roleids));
    }

    /**
     * Localised custom role full name (as in Site administration → Define roles).
     */
    public static function role_display_name(\stdClass $role, ?\context $context = null): string {
        $context = $context ?? \context_system::instance();
        return (string)role_get_name($role, $context, ROLENAME_ORIGINAL);
    }

    /**
     * System role display names per user at site context, comma-separated (name ASC).
     *
     * @param int[] $userids
     * @return array<int,string> userid => e.g. "經銷商, 訪客"
     */
    public static function get_users_system_role_shortnames(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids), static function(int $id): bool {
            return $id > 0;
        })));
        if ($userids === []) {
            return [];
        }

        $context = \context_system::instance();
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $params['sysctx'] = $context->id;

        $sql = "SELECT ra.userid, ra.roleid
                  FROM {role_assignments} ra
                 WHERE ra.contextid = :sysctx
                   AND ra.userid $insql
              ORDER BY ra.userid ASC, ra.roleid ASC";

        $byuser = array_fill_keys($userids, []);
        $roleids = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $uid = (int)$row->userid;
            $roleid = (int)$row->roleid;
            $byuser[$uid][] = $roleid;
            $roleids[$roleid] = $roleid;
        }
        $rs->close();

        $rolesbyid = [];
        if ($roleids !== []) {
            $rolesbyid = $DB->get_records_list('role', 'id', array_values($roleids));
            $rolesbyid = role_fix_names($rolesbyid, $context, ROLENAME_ORIGINAL, false);
        }

        $adminrole = null;
        foreach ($rolesbyid as $role) {
            if ((string)$role->shortname === 'admin') {
                $adminrole = $role;
                break;
            }
        }
        if (!$adminrole) {
            $adminrecord = $DB->get_record('role', ['shortname' => 'admin'], '*', IGNORE_MISSING);
            if ($adminrecord) {
                $adminfixed = role_fix_names([$adminrecord->id => $adminrecord], $context, ROLENAME_ORIGINAL, false);
                $adminrole = $adminfixed[$adminrecord->id] ?? $adminrecord;
            }
        }
        $admindisplay = $adminrole ? self::role_display_name($adminrole, $context) : 'admin';

        $result = [];
        foreach ($byuser as $uid => $assignedroleids) {
            $names = [];
            $hasadminassignment = false;
            foreach (array_values(array_unique($assignedroleids)) as $roleid) {
                if (!isset($rolesbyid[$roleid])) {
                    continue;
                }
                $role = $rolesbyid[$roleid];
                if ((string)$role->shortname === 'admin') {
                    $hasadminassignment = true;
                }
                $names[] = self::role_display_name($role, $context);
            }
            if (is_siteadmin($uid) && !$hasadminassignment) {
                $names[] = $admindisplay;
            }
            $names = array_values(array_unique($names));
            sort($names, SORT_STRING);
            $result[$uid] = implode(', ', $names);
        }
        return $result;
    }

    /**
     * System role shortnames that cannot be granted/revoked via search role editor.
     *
     * @return string[]
     */
    public static function protected_system_role_shortnames(): array {
        return ['admin'];
    }

    public static function is_protected_system_role_shortname(string $shortname): bool {
        return in_array($shortname, self::protected_system_role_shortnames(), true);
    }

    /**
     * @return int[]
     */
    public static function get_protected_system_role_ids(): array {
        global $DB;
        $shortnames = self::protected_system_role_shortnames();
        if ($shortnames === []) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED, 'sn');
        $ids = $DB->get_fieldset_sql("SELECT id FROM {role} WHERE shortname $insql", $params);
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Protected system roles shown read-only in the editor (no checkbox).
     *
     * @return array<int,array{shortname:string,name:string,virtual?:bool}>
     */
    public static function get_user_readonly_system_roles(int $userid): array {
        global $DB;

        $context = \context_system::instance();
        $assignments = get_user_roles($context, $userid, false);
        $out = [];
        $seen = [];
        foreach ($assignments as $assignment) {
            $shortname = (string)$assignment->shortname;
            if (!self::is_protected_system_role_shortname($shortname)) {
                continue;
            }
            $seen[$shortname] = true;
            $role = $DB->get_record('role', ['id' => (int)$assignment->roleid], '*', IGNORE_MISSING);
            $displayname = $role ? self::role_display_name($role, $context) : (string)($assignment->name ?? $shortname);
            $out[] = [
                'shortname' => $shortname,
                'name' => $displayname,
            ];
        }

        if (is_siteadmin($userid) && empty($seen['admin'])) {
            $record = $DB->get_record('role', ['shortname' => 'admin'], '*', IGNORE_MISSING);
            $out[] = [
                'shortname' => 'admin',
                'name' => $record ? self::role_display_name($record, $context) : 'admin',
                'virtual' => true,
            ];
        }

        usort($out, static function(array $a, array $b): int {
            return strcmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * Roles that may be assigned at site (system) context in the role editor.
     *
     * @return array<int,array{id:int,shortname:string,name:string}>
     */
    public static function get_system_roles_options(): array {
        global $DB;

        $context = \context_system::instance();
        // get_assignable_roles(..., false) returns roleid => display name strings only.
        // Load full {role} records so shortname is available for the editor UI.
        $assignablemenu = get_assignable_roles($context, ROLENAME_BOTH, false);
        if (!empty($assignablemenu)) {
            $roles = $DB->get_records_list('role', 'id', array_keys($assignablemenu), 'sortorder ASC');
        } else if (is_siteadmin()) {
            $roles = get_all_roles($context);
        } else {
            $roles = [];
        }
        $roles = role_fix_names($roles, $context, ROLENAME_BOTH, false);
        $out = [];
        foreach ($roles as $roleid => $role) {
            $shortname = (string)($role->shortname ?? '');
            if ($shortname === '' || self::is_protected_system_role_shortname($shortname)) {
                continue;
            }
            $out[] = [
                'id' => (int)$roleid,
                'shortname' => $shortname,
                'name' => self::role_display_name($role, $context),
            ];
        }
        usort($out, static function(array $a, array $b): int {
            return strcmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * Update explicit system-context role assignments for a user.
     *
     * @param int $targetuserid
     * @param int[] $roleids
     */
    public static function set_user_system_roles(int $targetuserid, array $roleids): void {
        global $DB;

        if (!is_siteadmin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        if ($targetuserid < 2) {
            throw new \moodle_exception('invaliduser', 'error');
        }

        $target = $DB->get_record('user', ['id' => $targetuserid, 'deleted' => 0], '*', MUST_EXIST);
        if (isguestuser($target)) {
            throw new \moodle_exception('invaliduser', 'error');
        }

        $context = \context_system::instance();
        if (!has_capability('moodle/role:assign', $context)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $allowedids = array_map(static function(array $role): int {
            return (int)$role['id'];
        }, self::get_system_roles_options());

        $protectedids = self::get_protected_system_role_ids();

        $roleids = array_values(array_unique(array_filter(array_map('intval', $roleids), static function(int $id): bool {
            return $id > 0;
        })));
        foreach ($roleids as $roleid) {
            if (!in_array($roleid, $allowedids, true)) {
                throw new \moodle_exception('invalidrole', 'error');
            }
        }

        $current = self::get_user_system_roleids($targetuserid);
        $keepprotected = array_values(array_intersect($current, $protectedids));
        $roleids = array_values(array_unique(array_merge($keepprotected, $roleids)));

        $toassign = array_diff($roleids, $current);
        $tounassign = array_diff($current, $roleids);
        $toassign = array_values(array_diff($toassign, $protectedids));
        $tounassign = array_values(array_diff($tounassign, $protectedids));

        foreach ($toassign as $roleid) {
            role_assign((int)$roleid, $targetuserid, $context->id);
        }
        foreach ($tounassign as $roleid) {
            role_unassign((int)$roleid, $targetuserid, $context->id);
        }
    }

    /**
     * @return \stdClass[] indexed by id
     */
    public static function get_rules(): array {
        return self::get_rules_for_target(self::TARGET_BATCH);
    }

    /**
     * @return \stdClass[] indexed by id
     */
    public static function get_rules_for_target(string $target): array {
        global $DB;
        return $DB->get_records(self::table_for_target($target), [], 'sortorder ASC, id ASC');
    }

    public static function add_rule(string $ruletype, string $pattern): int {
        return self::add_rule_for_target(self::TARGET_BATCH, $ruletype, $pattern);
    }

    public static function add_rule_for_target(string $target, string $ruletype, string $pattern): int {
        global $DB;
        $pattern = trim($pattern);
        $allowed = [self::RULE_IDNUMBER, self::RULE_INSTITUTION, self::RULE_NAME_CONTAINS];
        if ($pattern === '' || !in_array($ruletype, $allowed, true)) {
            throw new \moodle_exception('perm_rule_invalid', 'local_tm_course');
        }
        $table = self::table_for_target($target);
        $max = (int) $DB->get_field_sql("SELECT MAX(sortorder) FROM {{$table}}");
        $rec = (object) [
            'ruletype'    => $ruletype,
            'pattern'     => $pattern,
            'enabled'     => 1,
            'sortorder'   => $max + 1,
            'timecreated' => time(),
        ];
        return (int) $DB->insert_record($table, $rec);
    }

    public static function delete_rule(int $id): void {
        self::delete_rule_for_target(self::TARGET_BATCH, $id);
    }

    public static function delete_rule_for_target(string $target, int $id): void {
        global $DB;
        $DB->delete_records(self::table_for_target($target), ['id' => $id]);
    }

    public static function toggle_rule(int $id, int $enabled): void {
        self::toggle_rule_for_target(self::TARGET_BATCH, $id, $enabled);
    }

    public static function toggle_rule_for_target(string $target, int $id, int $enabled): void {
        global $DB;
        $DB->set_field(self::table_for_target($target), 'enabled', $enabled ? 1 : 0, ['id' => $id]);
    }

    public static function user_matches_any_rule(\stdClass $user): bool {
        return self::user_matches_any_rule_for_target($user, self::TARGET_BATCH);
    }

    public static function user_matches_any_rule_for_target(\stdClass $user, string $target): bool {
        foreach (self::get_rules_for_target($target) as $rule) {
            if (empty($rule->enabled)) {
                continue;
            }
            if (self::user_matches_rule($user, $rule)) {
                return true;
            }
        }
        return false;
    }

    private static function table_for_target(string $target): string {
        if ($target === self::TARGET_ATTENDANCE) {
            return 'local_tm_perm_att_rule';
        }
        return 'local_tm_course_perm_rule';
    }

    /**
     * @param \stdClass $user moodle user row
     * @param \stdClass $rule row from local_tm_course_perm_rule
     */
    public static function user_matches_rule(\stdClass $user, \stdClass $rule): bool {
        $pattern = trim((string) $rule->pattern);
        if ($pattern === '') {
            return false;
        }
        if ($rule->ruletype === self::RULE_IDNUMBER) {
            $idn = trim((string) ($user->idnumber ?? ''));
            return \core_text::strtolower($idn) === \core_text::strtolower($pattern);
        }
        if ($rule->ruletype === self::RULE_INSTITUTION) {
            $inst = trim((string) ($user->institution ?? ''));
            return \core_text::strtolower($inst) === \core_text::strtolower($pattern);
        }
        if ($rule->ruletype === self::RULE_NAME_CONTAINS) {
            $hay = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '') . ' ' . ($user->email ?? ''));
            return \core_text::strpos(\core_text::strtolower($hay), \core_text::strtolower($pattern)) !== false;
        }
        return false;
    }
}
