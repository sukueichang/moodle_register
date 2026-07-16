<?php
/**
 * Site-level Moodle tweaks (default roles, overlay cleanup, future optimizations).
 *
 * @package    local_tm_course
 */
namespace local_tm_course;

defined('MOODLE_INTERNAL') || die();

class moodle_optimization_manager {

    /** Plugin config: comma-separated overlay role shortnames for cleanup UI default. */
    public const CONFIG_OVERLAY_SHORTNAMES = 'moodle_opt_overlay_shortnames';

    /** Plugin config: JSON stats from the last cleanup run. */
    public const CONFIG_LAST_CLEANUP = 'moodle_opt_last_cleanup';

    /** Plugin config: JSON cleanup rules [{keep,remove[]}, ...]. */
    public const CONFIG_CLEANUP_RULES = 'moodle_opt_cleanup_rules';

    /** Plugin config: role id to assign to users missing a business system role. */
    public const CONFIG_FALLBACK_ROLEID = 'moodle_opt_fallback_roleid';

    /** Plugin config: 1 = also target users who only have overlay roles. */
    public const CONFIG_FALLBACK_INCLUDE_OVERLAY = 'moodle_opt_fallback_include_overlay';

    /** Plugin config: 1 = remove overlay roles after fallback assign. */
    public const CONFIG_FALLBACK_REMOVE_OVERLAY = 'moodle_opt_fallback_remove_overlay';

    /** Plugin config: JSON stats from the last fallback assign run. */
    public const CONFIG_LAST_FALLBACK = 'moodle_opt_last_fallback';

    /** Preview table row cap on the optimization page. */
    public const FALLBACK_PREVIEW_LIMIT = 80;

    /**
     * Role shortnames commonly used as site-wide default login roles (not business roles).
     *
     * @return string[]
     */
    public static function recommended_overlay_shortnames(): array {
        return ['visitor', 'academic', 'tm_staff', 'saml2', 'webserviceuser', 'guest', 'user'];
    }

    /**
     * @return string[]
     */
    public static function get_configured_overlay_shortnames(): array {
        $raw = (string)get_config('local_tm_course', self::CONFIG_OVERLAY_SHORTNAMES);
        if ($raw === '') {
            return self::recommended_overlay_shortnames();
        }
        $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $part) {
            $sn = \core_text::strtolower(trim($part));
            if ($sn !== '') {
                $out[] = $sn;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param string[] $shortnames
     */
    public static function save_overlay_shortnames(array $shortnames): void {
        $shortnames = array_values(array_unique(array_filter(array_map(static function(string $sn): string {
            return \core_text::strtolower(trim($sn));
        }, $shortnames))));
        set_config(self::CONFIG_OVERLAY_SHORTNAMES, implode(',', $shortnames), 'local_tm_course');
    }

    /**
     * Current core defaultuserroleid status for the admin UI.
     *
     * @return array{roleid:int,enabled:bool,rolename:string,shortname:string}
     */
    public static function get_default_user_role_status(): array {
        global $DB;

        $roleid = (int)get_config('core', 'defaultuserroleid');
        if ($roleid <= 0) {
            return [
                'roleid' => 0,
                'enabled' => false,
                'rolename' => '',
                'shortname' => '',
            ];
        }

        $role = $DB->get_record('role', ['id' => $roleid], '*', IGNORE_MISSING);
        $context = \context_system::instance();
        if ($role) {
            $fixed = role_fix_names([$role->id => $role], $context, ROLENAME_BOTH);
            $role = $fixed[$role->id] ?? $role;
        }

        return [
            'roleid' => $roleid,
            'enabled' => true,
            'rolename' => $role ? (string)($role->localname ?? $role->name) : (string)$roleid,
            'shortname' => $role ? (string)$role->shortname : '',
        ];
    }

    /**
     * Disable Moodle's "default role for all authenticated users" (defaultuserroleid = 0).
     */
    public static function disable_default_user_role(): void {
        set_config('defaultuserroleid', 0);
    }

    /**
     * Suggested cleanup rules: when user has keep role, remove listed roles only.
     *
     * @return array<int,array{keep:string,remove:string[]}>
     */
    public static function default_cleanup_rules(): array {
        return [
            [
                'keep' => 'ds',
                'remove' => ['visitor', 'academic', 'tm_staff', 'saml2', 'webserviceuser'],
            ],
            [
                'keep' => 'academic',
                'remove' => ['tm_staff', 'visitor'],
            ],
            [
                'keep' => 'tm_staff',
                'remove' => ['visitor', 'academic'],
            ],
        ];
    }

    /**
     * @return array<int,array{keep:string,remove:string[]}>
     */
    public static function get_cleanup_rules(): array {
        $raw = (string)get_config('local_tm_course', self::CONFIG_CLEANUP_RULES);
        if ($raw === '') {
            return self::default_cleanup_rules();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::default_cleanup_rules();
        }
        return self::normalize_cleanup_rules($decoded);
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @return array<int,array{keep:string,remove:string[]}>
     */
    public static function normalize_cleanup_rules(array $rules): array {
        $out = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $keep = \core_text::strtolower(trim((string)($rule['keep'] ?? '')));
            if ($keep === '') {
                continue;
            }
            $remove = [];
            foreach ((array)($rule['remove'] ?? []) as $sn) {
                $sn = \core_text::strtolower(trim((string)$sn));
                if ($sn !== '' && $sn !== $keep) {
                    $remove[] = $sn;
                }
            }
            $remove = array_values(array_unique($remove));
            if ($remove === []) {
                continue;
            }
            $out[] = ['keep' => $keep, 'remove' => $remove];
        }
        return $out;
    }

    /**
     * @param array<int,array{keep:string,remove:string[]}> $rules
     */
    public static function save_cleanup_rules(array $rules): void {
        $rules = self::normalize_cleanup_rules($rules);
        set_config(self::CONFIG_CLEANUP_RULES, json_encode($rules), 'local_tm_course');
    }

    /**
     * Resolve shortnames to role ids; skip unknown shortnames.
     *
     * @param array<int,array{keep:string,remove:string[]}> $rules
     * @return array<int,array{keep:int,remove:int[]}>
     */
    private static function resolve_cleanup_rule_ids(array $rules): array {
        global $DB;

        $shortnames = [];
        foreach ($rules as $rule) {
            $shortnames[$rule['keep']] = $rule['keep'];
            foreach ($rule['remove'] as $sn) {
                $shortnames[$sn] = $sn;
            }
        }
        if ($shortnames === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_values($shortnames), SQL_PARAMS_NAMED, 'sn');
        $records = $DB->get_records_select('role', "shortname $insql", $params);
        $idbyshort = [];
        foreach ($records as $role) {
            $idbyshort[\core_text::strtolower((string)$role->shortname)] = (int)$role->id;
        }

        $out = [];
        foreach ($rules as $rule) {
            if (!isset($idbyshort[$rule['keep']])) {
                continue;
            }
            $removeids = [];
            foreach ($rule['remove'] as $sn) {
                if (isset($idbyshort[$sn])) {
                    $removeids[] = $idbyshort[$sn];
                }
            }
            $removeids = array_values(array_unique($removeids));
            if ($removeids === []) {
                continue;
            }
            $out[] = [
                'keep' => $idbyshort[$rule['keep']],
                'remove' => $removeids,
            ];
        }
        return $out;
    }

    /**
     * Rule-based cleanup: if user has keep role, remove only the listed remove roles.
     *
     * @param array<int,array{keep:string,remove:string[]}>|null $rules
     * @return array{users:int,removed:int,rules:int,rulelabels:string[]}
     */
    public static function cleanup_by_rules(?array $rules = null): array {
        global $DB;

        $rules = self::normalize_cleanup_rules($rules ?? self::get_cleanup_rules());
        $resolved = self::resolve_cleanup_rule_ids($rules);

        $stats = [
            'users' => 0,
            'removed' => 0,
            'rules' => count($resolved),
            'rulelabels' => [],
        ];

        if ($resolved === []) {
            self::store_last_cleanup_stats($stats);
            return $stats;
        }

        $context = \context_system::instance();
        $syscontextid = (int)$context->id;
        $byuser = self::get_system_role_map_by_user();
        $touchedusers = [];

        foreach ($resolved as $rule) {
            $keeplabel = (string)$DB->get_field('role', 'shortname', ['id' => $rule['keep']]);
            $stats['rulelabels'][] = $keeplabel;
        }

        foreach ($byuser as $userid => $roleids) {
            if ($userid < 2) {
                continue;
            }
            $roleids = array_values(array_unique($roleids));
            $usertouched = false;

            foreach ($resolved as $rule) {
                if (!in_array($rule['keep'], $roleids, true)) {
                    continue;
                }
                foreach ($rule['remove'] as $removeid) {
                    if ($removeid === $rule['keep']) {
                        continue;
                    }
                    if (!in_array($removeid, $roleids, true)) {
                        continue;
                    }
                    role_unassign((int)$removeid, (int)$userid, $syscontextid);
                    $stats['removed']++;
                    $usertouched = true;
                    $roleids = array_values(array_diff($roleids, [$removeid]));
                }
            }

            if ($usertouched) {
                $touchedusers[$userid] = true;
            }
        }

        $stats['users'] = count($touchedusers);
        self::store_last_cleanup_stats($stats);
        return $stats;
    }

    /**
     * Build cleanup rules from admin form submission.
     *
     * @param array $keepbyindex rule index => keep shortname
     * @param array $removebyindex rule index => string[] remove shortnames
     * @return array<int,array{keep:string,remove:string[]}>
     */
    public static function parse_cleanup_rules_from_form(array $keepbyindex, array $removebyindex): array {
        $rules = [];
        foreach ($keepbyindex as $idx => $keep) {
            $keep = \core_text::strtolower(trim((string)$keep));
            if ($keep === '') {
                continue;
            }
            $remove = [];
            if (isset($removebyindex[$idx]) && is_array($removebyindex[$idx])) {
                foreach ($removebyindex[$idx] as $sn) {
                    $sn = \core_text::strtolower(trim((string)$sn));
                    if ($sn !== '' && $sn !== $keep) {
                        $remove[] = $sn;
                    }
                }
            }
            $rules[] = ['keep' => $keep, 'remove' => array_values(array_unique($remove))];
        }
        return self::normalize_cleanup_rules($rules);
    }

    /**
     * Roles offered as remove targets in cleanup rule editor.
     *
     * @return array<int,array{shortname:string,name:string}>
     */
    public static function get_cleanup_removable_role_options(): array {
        $seen = [];
        $out = [];
        foreach (array_merge(self::get_overlay_role_options(), self::get_assignable_role_options()) as $role) {
            $sn = (string)$role['shortname'];
            if ($sn === '' || $sn === 'admin' || isset($seen[$sn])) {
                continue;
            }
            $seen[$sn] = true;
            $out[] = ['shortname' => $sn, 'name' => (string)$role['name']];
        }
        usort($out, static function(array $a, array $b): int {
            return strcmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * @deprecated Use cleanup_by_rules().
     * @param string[] $overlayshortnames
     * @return array{users:int,removed:int,skipped_only_overlay:int,overlayroles:string[]}
     */
    public static function cleanup_overlay_roles(array $overlayshortnames): array {
        global $DB;

        $overlayshortnames = array_values(array_unique(array_filter(array_map(static function(string $sn): string {
            return \core_text::strtolower(trim($sn));
        }, $overlayshortnames))));

        $stats = [
            'users' => 0,
            'removed' => 0,
            'skipped_only_overlay' => 0,
            'overlayroles' => $overlayshortnames,
        ];

        if ($overlayshortnames === []) {
            self::store_last_cleanup_stats($stats);
            return $stats;
        }

        $overlayroleids = [];
        foreach ($overlayshortnames as $shortname) {
            $rid = (int)$DB->get_field('role', 'id', ['shortname' => $shortname]);
            if ($rid > 0) {
                $overlayroleids[$rid] = $rid;
            }
        }
        if ($overlayroleids === []) {
            self::store_last_cleanup_stats($stats);
            return $stats;
        }

        $syscontext = \context_system::instance();
        $syscontextid = (int)$syscontext->id;

        $sql = "SELECT ra.userid, ra.roleid
                  FROM {role_assignments} ra
                 WHERE ra.contextid = :ctx
              ORDER BY ra.userid ASC, ra.roleid ASC";
        $rs = $DB->get_recordset_sql($sql, ['ctx' => $syscontextid]);

        $byuser = [];
        foreach ($rs as $row) {
            $uid = (int)$row->userid;
            $rid = (int)$row->roleid;
            if (!isset($byuser[$uid])) {
                $byuser[$uid] = [];
            }
            $byuser[$uid][] = $rid;
        }
        $rs->close();

        foreach ($byuser as $userid => $roleids) {
            if ($userid < 2) {
                continue;
            }
            $roleids = array_values(array_unique($roleids));
            $overlayassigned = array_values(array_intersect($roleids, $overlayroleids));
            if ($overlayassigned === []) {
                continue;
            }
            $nonoverlay = array_values(array_diff($roleids, $overlayroleids));
            if ($nonoverlay === []) {
                $stats['skipped_only_overlay']++;
                continue;
            }

            $stats['users']++;
            foreach ($overlayassigned as $roleid) {
                role_unassign((int)$roleid, (int)$userid, $syscontextid);
                $stats['removed']++;
            }
        }

        self::store_last_cleanup_stats($stats);
        return $stats;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get_last_cleanup_stats(): ?array {
        $raw = (string)get_config('local_tm_course', self::CONFIG_LAST_CLEANUP);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $stats
     */
    private static function store_last_cleanup_stats(array $stats): void {
        $stats['time'] = time();
        set_config(self::CONFIG_LAST_CLEANUP, json_encode($stats), 'local_tm_course');
    }

    /**
     * @return array<int,array{id:int,shortname:string,name:string}>
     */
    public static function get_overlay_role_options(): array {
        global $DB;

        $context = \context_system::instance();
        $shortnames = self::get_configured_overlay_shortnames();
        if ($shortnames === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED, 'sn');
        $roles = $DB->get_records_select('role', "shortname $insql", $params, 'sortorder ASC');
        $roles = role_fix_names($roles, $context, ROLENAME_BOTH);

        $out = [];
        foreach ($roles as $role) {
            $out[] = [
                'id' => (int)$role->id,
                'shortname' => (string)$role->shortname,
                'name' => (string)($role->localname ?? $role->name),
            ];
        }
        usort($out, static function(array $a, array $b): int {
            return strcmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * Roles that may be chosen as the batch fallback system role.
     *
     * @return array<int,array{id:int,shortname:string,name:string}>
     */
    public static function get_assignable_role_options(): array {
        global $DB;

        $context = \context_system::instance();
        $assignablemenu = get_assignable_roles($context, ROLENAME_BOTH, false);
        if (!empty($assignablemenu)) {
            $roles = $DB->get_records_list('role', 'id', array_keys($assignablemenu), 'sortorder ASC');
        } else {
            $roles = get_all_roles($context);
        }
        $roles = role_fix_names($roles, $context, ROLENAME_BOTH);

        $out = [];
        foreach ($roles as $role) {
            $shortname = (string)($role->shortname ?? '');
            if ($shortname === '' || $shortname === 'admin') {
                continue;
            }
            $out[] = [
                'id' => (int)$role->id,
                'shortname' => $shortname,
                'name' => (string)($role->localname ?? $role->name),
            ];
        }
        usort($out, static function(array $a, array $b): int {
            return strcmp($a['name'], $b['name']);
        });
        return $out;
    }

    public static function get_configured_fallback_roleid(): int {
        return (int)get_config('local_tm_course', self::CONFIG_FALLBACK_ROLEID);
    }

    public static function is_fallback_include_overlay(): bool {
        return (int)get_config('local_tm_course', self::CONFIG_FALLBACK_INCLUDE_OVERLAY) === 1;
    }

    public static function is_fallback_remove_overlay(): bool {
        return (int)get_config('local_tm_course', self::CONFIG_FALLBACK_REMOVE_OVERLAY) === 1;
    }

    /**
     * @param int $roleid
     * @param bool $includeoverlay
     * @param bool $removeoverlay
     */
    public static function save_fallback_settings(int $roleid, bool $includeoverlay, bool $removeoverlay): void {
        set_config(self::CONFIG_FALLBACK_ROLEID, max(0, $roleid), 'local_tm_course');
        set_config(self::CONFIG_FALLBACK_INCLUDE_OVERLAY, $includeoverlay ? 1 : 0, 'local_tm_course');
        set_config(self::CONFIG_FALLBACK_REMOVE_OVERLAY, $removeoverlay ? 1 : 0, 'local_tm_course');
    }

    /**
     * @return int[] overlay role ids
     */
    private static function get_overlay_role_ids(): array {
        global $DB;

        $ids = [];
        foreach (self::get_configured_overlay_shortnames() as $shortname) {
            $rid = (int)$DB->get_field('role', 'id', ['shortname' => $shortname]);
            if ($rid > 0) {
                $ids[$rid] = $rid;
            }
        }
        return array_values($ids);
    }

    /**
     * Map userid => roleids at system context for active local users.
     *
     * @return array<int,int[]>
     */
    private static function get_system_role_map_by_user(): array {
        global $DB, $CFG;

        $syscontextid = (int)\context_system::instance()->id;
        $sql = "SELECT ra.userid, ra.roleid
                  FROM {role_assignments} ra
                  JOIN {user} u ON u.id = ra.userid
                 WHERE ra.contextid = :ctx
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND u.id > 1
                   AND u.mnethostid = :mnethostid
              ORDER BY ra.userid ASC, ra.roleid ASC";
        $rs = $DB->get_recordset_sql($sql, [
            'ctx' => $syscontextid,
            'mnethostid' => $CFG->mnet_localhost_id,
        ]);

        $byuser = [];
        foreach ($rs as $row) {
            $uid = (int)$row->userid;
            $rid = (int)$row->roleid;
            if (!isset($byuser[$uid])) {
                $byuser[$uid] = [];
            }
            $byuser[$uid][] = $rid;
        }
        $rs->close();
        return $byuser;
    }

    /**
     * Whether the user should receive the configured fallback role.
     *
     * @param int[] $roleids
     * @param int[] $overlayroleids
     */
    private static function user_needs_fallback_role(array $roleids, bool $includeoverlayonly, array $overlayroleids): bool {
        $roleids = array_values(array_unique($roleids));
        if ($roleids === []) {
            return true;
        }
        if (!$includeoverlayonly) {
            return false;
        }
        $nonoverlay = array_values(array_diff($roleids, $overlayroleids));
        return $nonoverlay === [];
    }

    /**
     * @return int[]
     */
    public static function find_userids_needing_fallback(bool $includeoverlayonly): array {
        global $DB, $CFG;

        $overlayroleids = self::get_overlay_role_ids();
        $byuser = self::get_system_role_map_by_user();

        $sql = "SELECT u.id
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND u.id > 1
                   AND u.mnethostid = :mnethostid
              ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC";
        $rs = $DB->get_recordset_sql($sql, ['mnethostid' => $CFG->mnet_localhost_id]);

        $out = [];
        foreach ($rs as $row) {
            $uid = (int)$row->id;
            $roleids = $byuser[$uid] ?? [];
            if (self::user_needs_fallback_role($roleids, $includeoverlayonly, $overlayroleids)) {
                $out[] = $uid;
            }
        }
        $rs->close();
        return $out;
    }

    public static function count_users_needing_fallback(bool $includeoverlayonly): int {
        return count(self::find_userids_needing_fallback($includeoverlayonly));
    }

    /**
     * @return array<int,array{id:int,fullname:string,email:string,roles:string}>
     */
    public static function preview_users_needing_fallback(bool $includeoverlayonly, int $limit = self::FALLBACK_PREVIEW_LIMIT): array {
        global $DB;

        $userids = self::find_userids_needing_fallback($includeoverlayonly);
        if ($userids === []) {
            return [];
        }
        if ($limit > 0) {
            $userids = array_slice($userids, 0, $limit);
        }

        $users = $DB->get_records_list('user', 'id', $userids);
        $roledisplay = permissions_manager::get_users_system_role_shortnames($userids);

        $out = [];
        foreach ($userids as $uid) {
            if (!isset($users[$uid])) {
                continue;
            }
            $user = $users[$uid];
            $roles = trim((string)($roledisplay[$uid] ?? ''));
            if ($roles === '') {
                $roles = get_string('moodle_opt_fallback_roles_none', 'local_tm_course');
            }
            $out[] = [
                'id' => $uid,
                'fullname' => fullname($user),
                'email' => (string)$user->email,
                'roles' => $roles,
            ];
        }
        return $out;
    }

    /**
     * @return array{matched:int,assigned:int,skipped:int,overlay_removed:int,roleid:int}
     */
    public static function bulk_assign_fallback_role(int $roleid, bool $includeoverlayonly, bool $removeoverlay): array {
        global $DB;

        $roleid = (int)$roleid;
        if ($roleid <= 0) {
            throw new \moodle_exception('moodle_opt_fallback_role_required', 'local_tm_course');
        }

        $allowed = array_column(self::get_assignable_role_options(), 'id');
        if (!in_array($roleid, $allowed, true)) {
            throw new \moodle_exception('invalidrole', 'error');
        }

        $context = \context_system::instance();
        if (!has_capability('moodle/role:assign', $context)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        self::save_fallback_settings($roleid, $includeoverlayonly, $removeoverlay);

        $overlayroleids = self::get_overlay_role_ids();
        $syscontextid = (int)$context->id;
        $userids = self::find_userids_needing_fallback($includeoverlayonly);

        $stats = [
            'matched' => count($userids),
            'assigned' => 0,
            'skipped' => 0,
            'overlay_removed' => 0,
            'roleid' => $roleid,
        ];

        foreach ($userids as $userid) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$user || isguestuser($user)) {
                $stats['skipped']++;
                continue;
            }

            $current = permissions_manager::get_user_system_roleids($userid);
            if (in_array($roleid, $current, true)) {
                $stats['skipped']++;
            } else {
                role_assign($roleid, $userid, $syscontextid);
                $stats['assigned']++;
            }

            if ($removeoverlay && $overlayroleids !== []) {
                foreach ($overlayroleids as $overlayid) {
                    if ($overlayid === $roleid) {
                        continue;
                    }
                    if (user_has_role_assignment($userid, $overlayid, $syscontextid)) {
                        role_unassign((int)$overlayid, $userid, $syscontextid);
                        $stats['overlay_removed']++;
                    }
                }
            }
        }

        $stats['time'] = time();
        $stats['includeoverlay'] = $includeoverlayonly ? 1 : 0;
        $stats['removeoverlay'] = $removeoverlay ? 1 : 0;
        set_config(self::CONFIG_LAST_FALLBACK, json_encode($stats), 'local_tm_course');
        return $stats;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get_last_fallback_stats(): ?array {
        $raw = (string)get_config('local_tm_course', self::CONFIG_LAST_FALLBACK);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
