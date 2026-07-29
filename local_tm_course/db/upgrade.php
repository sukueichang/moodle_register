<?php
/**
 * db/upgrade.php ??runs when upgrading an existing installation
 * Fresh installs use install.xml; upgrades need this file.
 * @package    local_tm_course
 */
defined('MOODLE_INTERNAL') || die();

function xmldb_local_tm_course_upgrade($oldversion) {
    global $DB, $CFG;
    $dbman = $DB->get_manager();

    // ----------------------------------------------------------------
    // 2026033102 ??Create all tables (safe: checks existence first)
    // ----------------------------------------------------------------
    if ($oldversion < 2026033102) {

        // ---- Table: local_tm_course_sessions ----
        if (!$dbman->table_exists('local_tm_course_sessions')) {
            $table = new xmldb_table('local_tm_course_sessions');
            $table->add_field('id',                    XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('courseid',              XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('name',                  XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('description',           XMLDB_TYPE_TEXT,    'medium', null, null,        null);
            $table->add_field('location',              XMLDB_TYPE_CHAR,    '255', null, null,           null, '');
            $table->add_field('starttime',             XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('endtime',               XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('duration_hours',        XMLDB_TYPE_NUMBER,   '6',  2,    XMLDB_NOTNULL, null, '8');
            $table->add_field('num_desks',             XMLDB_TYPE_INTEGER,  '2',  null, XMLDB_NOTNULL, null, '6');
            $table->add_field('persons_per_desk',      XMLDB_TYPE_INTEGER,  '1',  null, XMLDB_NOTNULL, null, '2');
            $table->add_field('approval_mode',         XMLDB_TYPE_INTEGER,  '1',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('prerequisite_courseid', XMLDB_TYPE_INTEGER,  '10', null, null,           null);
            $table->add_field('status',                XMLDB_TYPE_INTEGER,  '1',  null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated',           XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified',          XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby',             XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary',      XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_starttime', XMLDB_INDEX_NOTUNIQUE, ['starttime']);
            $table->add_index('idx_status',    XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('idx_courseid',  XMLDB_INDEX_NOTUNIQUE, ['courseid']);

            $dbman->create_table($table);
        }

        // ---- Table: local_tm_course_enrolments ----
        if (!$dbman->table_exists('local_tm_course_enrolments')) {
            $table = new xmldb_table('local_tm_course_enrolments');
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('sessionid',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid',       XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status',       XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('desk_number',  XMLDB_TYPE_INTEGER, '2',   null, null,           null);
            $table->add_field('attended',     XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('institution',  XMLDB_TYPE_CHAR,    '255', null, null,           null);
            $table->add_field('notes',        XMLDB_TYPE_TEXT,    'medium', null, null,        null);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary',        XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('uq_session_user',XMLDB_KEY_UNIQUE,  ['sessionid', 'userid']);
            $table->add_index('idx_userid',   XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('idx_status',   XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('idx_sessionid',XMLDB_INDEX_NOTUNIQUE, ['sessionid']);

            $dbman->create_table($table);
        }

        // ---- Table: local_tm_course_batch ----
        if (!$dbman->table_exists('local_tm_course_batch')) {
            $table = new xmldb_table('local_tm_course_batch');
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('batch_name',   XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('repeat_type',  XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('repeat_count', XMLDB_TYPE_INTEGER, '3',   null, XMLDB_NOTNULL, null, '1');
            $table->add_field('session_ids',  XMLDB_TYPE_TEXT,    'medium', null, null,        null);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('createdby',    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026033102, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026033103 ??Bug fixes + M3 columns on sessions table
    // ----------------------------------------------------------------
    if ($oldversion < 2026033103) {

        $table = new xmldb_table('local_tm_course_sessions');

        // Add groupid column (Moodle group linked to this session)
        $field = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add attendance_cmid column (mod_attendance course module id)
        $field = new xmldb_field('attendance_cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'groupid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add attendance_sessionid column (mod_attendance session slot id)
        $field = new xmldb_field('attendance_sessionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'attendance_cmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026033103, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040201 ??I18n: added de, fr, ko, ja, zh_tw, zh_cn language packs
    //              (no DB changes ??savepoint only)
    // ----------------------------------------------------------------
    if ($oldversion < 2026040201) {
        upgrade_plugin_savepoint(true, 2026040201, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040202 ??Fix attendance.php HTTP 500 (no DB changes)
    // ----------------------------------------------------------------
    if ($oldversion < 2026040202) {
        upgrade_plugin_savepoint(true, 2026040202, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040203 ??Fix PHP 7.2/7.3 compat: arrow fn() ??function()
    // ----------------------------------------------------------------
    if ($oldversion < 2026040203) {
        upgrade_plugin_savepoint(true, 2026040203, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040204 ??Fix fullname() SQL: add middlename/phonetic/alternatename
    // ----------------------------------------------------------------
    if ($oldversion < 2026040204) {
        upgrade_plugin_savepoint(true, 2026040204, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040205 ??Fix attendance_manager: direct DB insert, \ prefix, try-catch
    // ----------------------------------------------------------------
    if ($oldversion < 2026040205) {
        upgrade_plugin_savepoint(true, 2026040205, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040210 ??Classrooms, enabled courses, session.classroomid
    // ----------------------------------------------------------------
    if ($oldversion < 2026040210) {

        if (!$dbman->table_exists('local_tm_classroom')) {
            $table = new xmldb_table('local_tm_classroom');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('location', XMLDB_TYPE_CHAR, '255', null, null, null, '');
            $table->add_field('table_count', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '6');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        if (!$dbman->table_exists('local_tm_enabled_courses')) {
            $table = new xmldb_table('local_tm_enabled_courses');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('uq_course', XMLDB_KEY_UNIQUE, ['courseid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_tm_course_sessions');
        $field = new xmldb_field('classroomid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('idx_classroomid', XMLDB_INDEX_NOTUNIQUE, ['classroomid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026040210, 'local', 'tm_course');
    }

    if ($oldversion < 2026040211) {
        upgrade_plugin_savepoint(true, 2026040211, 'local', 'tm_course');
    }

    if ($oldversion < 2026040212) {
        upgrade_plugin_savepoint(true, 2026040212, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040213 ??Ensure local_tm_classroom.table_count exists (partial installs)
    // ----------------------------------------------------------------
    if ($oldversion < 2026040213) {
        $table = new xmldb_table('local_tm_classroom');
        $field = new xmldb_field('table_count', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '6', 'location');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026040213, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040214 ??Remove legacy mock sessions inserted during testing
    // ----------------------------------------------------------------
    if ($oldversion < 2026040214) {
        $sql = "name = :n1 OR name LIKE :n2";
        $params = [
            'n1' => 'TM Robot Training ??Test Session 001',
            'n2' => 'TM Batch Training ??Weekly Series%',
        ];
        $mockids = $DB->get_fieldset_select('local_tm_course_sessions', 'id', $sql, $params);
        if (!empty($mockids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($mockids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_tm_course_enrolments', "sessionid $insql", $inparams);
            $DB->delete_records_select('local_tm_course_sessions', "id $insql", $inparams);
        }
        upgrade_plugin_savepoint(true, 2026040214, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040215 ??Ensure mock cleanup SQL is reliable
    // ----------------------------------------------------------------
    if ($oldversion < 2026040215) {
        // Run the same cleanup again using stable SQL.
        $sql = "name = :n1 OR name LIKE :n2";
        $params = [
            'n1' => 'TM Robot Training ??Test Session 001',
            'n2' => 'TM Batch Training ??Weekly Series%',
        ];
        $mockids = $DB->get_fieldset_select('local_tm_course_sessions', 'id', $sql, $params);
        if (!empty($mockids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($mockids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_tm_course_enrolments', "sessionid $insql", $inparams);
            $DB->delete_records_select('local_tm_course_sessions', "id $insql", $inparams);
        }
        upgrade_plugin_savepoint(true, 2026040215, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040216 ??Course mapping visibility fix + category grouping UI
    // ----------------------------------------------------------------
    if ($oldversion < 2026040216) {
        upgrade_plugin_savepoint(true, 2026040216, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040217 ??Fix time conflict error key + session_manager SQL
    //              (no DB schema changes)
    // ----------------------------------------------------------------
    if ($oldversion < 2026040217) {
        upgrade_plugin_savepoint(true, 2026040217, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040218 ??Enrolment diet survey fields
    // ----------------------------------------------------------------
    if ($oldversion < 2026040218) {
        $table = new xmldb_table('local_tm_course_enrolments');

        $field = new xmldb_field('diet_choice', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, '', 'institution');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('diet_avoid_beef', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'diet_choice');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('diet_avoid_seafood', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'diet_avoid_beef');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('diet_meat_other', XMLDB_TYPE_TEXT, null, null, null, null, null, 'diet_avoid_seafood');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('diet_vegetarian_notes', XMLDB_TYPE_TEXT, null, null, null, null, null, 'diet_meat_other');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026040218, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040219 ??Fix enrol diet duplicate entry handling (no DB changes)
    // ----------------------------------------------------------------
    if ($oldversion < 2026040219) {
        upgrade_plugin_savepoint(true, 2026040219, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040220 ??UI mapping: approval_mode-driven enrol button + remove
    //                sessions '#' column, plus i18n status labels cleanup
    // ----------------------------------------------------------------
    if ($oldversion < 2026040220) {
        upgrade_plugin_savepoint(true, 2026040220, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040221 ??Validation/flow fixes:
    // - same-day same-classroom guard
    // - enrol conflict with date hint
    // - manual approval desk assignment support
    // ----------------------------------------------------------------
    if ($oldversion < 2026040221) {
        upgrade_plugin_savepoint(true, 2026040221, 'local', 'tm_course');
    }

    // ----------------------------------------------------------------
    // 2026040700 ??Course default session duration (auto schedule) on
    //              local_tm_enabled_courses
    // ----------------------------------------------------------------
    if ($oldversion < 2026040700) {
        $table = new xmldb_table('local_tm_enabled_courses');
        $field = new xmldb_field('default_duration_hours', XMLDB_TYPE_NUMBER, '6', null, XMLDB_NOTNULL, null, '8', 'courseid');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026040700, 'local', 'tm_course');
    }

    // 2026040701 ??Session form JS/lang: planned-hours end sync, drop desk preview grid (no DB)
    if ($oldversion < 2026040701) {
        upgrade_plugin_savepoint(true, 2026040701, 'local', 'tm_course');
    }

    // 2026040702 ??Desk assignment visualization + remaining persons emphasis (no DB)
    if ($oldversion < 2026040702) {
        upgrade_plugin_savepoint(true, 2026040702, 'local', 'tm_course');
    }

    // 2026040703 ??Store learner cancellation reason (code + optional text)
    if ($oldversion < 2026040703) {
        $table = new xmldb_table('local_tm_course_enrolments');

        $field = new xmldb_field('cancel_reason_code', XMLDB_TYPE_CHAR, '30', null, null, null, '', 'notes');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('cancel_reason_text', XMLDB_TYPE_TEXT, null, null, null, null, null, 'cancel_reason_code');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026040703, 'local', 'tm_course');
    }

    // 2026040704 ??Localization/UI polish + enrolment filter fix + unapprove action (no DB)
    if ($oldversion < 2026040704) {
        upgrade_plugin_savepoint(true, 2026040704, 'local', 'tm_course');
    }

    // 2026040705 ??Auto attendance setup on save + UI/localization polish (no DB)
    if ($oldversion < 2026040705) {
        upgrade_plugin_savepoint(true, 2026040705, 'local', 'tm_course');
    }

    // 2026040706 ??Cleanup linked Moodle resources on session delete (no DB)
    if ($oldversion < 2026040706) {
        upgrade_plugin_savepoint(true, 2026040706, 'local', 'tm_course');
    }

    // 2026040707 ??Attendance: course_modules.section = course_sections.id; reuse any existing attendance (no DB)
    if ($oldversion < 2026040707) {
        upgrade_plugin_savepoint(true, 2026040707, 'local', 'tm_course');
    }

    // 2026040708 ??zh_tw full translation + ensure group auto-sync on approval (no DB)
    if ($oldversion < 2026040708) {
        upgrade_plugin_savepoint(true, 2026040708, 'local', 'tm_course');
    }

    // 2026040709 ??Remove group membership on unapprove/cancel (no DB)
    if ($oldversion < 2026040709) {
        upgrade_plugin_savepoint(true, 2026040709, 'local', 'tm_course');
    }

    // 2026040710 ??Remove group membership on reject when previously approved (no DB)
    if ($oldversion < 2026040710) {
        upgrade_plugin_savepoint(true, 2026040710, 'local', 'tm_course');
    }

    // 2026040711 ??Reject action switches to POST form, no inline JS dependency (no DB)
    if ($oldversion < 2026040711) {
        upgrade_plugin_savepoint(true, 2026040711, 'local', 'tm_course');
    }

    // 2026040712 ??Show learner re-apply after rejection + allow rejected status reactivation (no DB)
    if ($oldversion < 2026040712) {
        upgrade_plugin_savepoint(true, 2026040712, 'local', 'tm_course');
    }

    // 2026040713 — Reject reason label + learner/admin visibility (no DB)
    if ($oldversion < 2026040713) {
        upgrade_plugin_savepoint(true, 2026040713, 'local', 'tm_course');
    }

    // 2026040800 — M4: perm rules table + batch_submittedby on enrolments
    if ($oldversion < 2026040800) {
        $table = new xmldb_table('local_tm_course_perm_rule');
        if (!$dbman->table_exists('local_tm_course_perm_rule')) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('ruletype', XMLDB_TYPE_CHAR, '30', null, null, null, null);
            $table->add_field('pattern', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        $etable = new xmldb_table('local_tm_course_enrolments');
        $field = new xmldb_field('batch_submittedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timemodified');
        if (!$dbman->field_exists($etable, $field)) {
            $dbman->add_field($etable, $field);
        }

        upgrade_plugin_savepoint(true, 2026040800, 'local', 'tm_course');
    }

    // 2026040801 — XMLDB fix: avoid empty-string defaults on CHAR fields.
    if ($oldversion < 2026040801) {
        $ptable = new xmldb_table('local_tm_course_perm_rule');
        if ($dbman->table_exists($ptable)) {
            $ruletype = new xmldb_field('ruletype', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'id');
            if ($dbman->field_exists($ptable, $ruletype)) {
                $dbman->change_field_notnull($ptable, $ruletype);
                $dbman->change_field_default($ptable, $ruletype);
            }

            $pattern = new xmldb_field('pattern', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'ruletype');
            if ($dbman->field_exists($ptable, $pattern)) {
                $dbman->change_field_notnull($ptable, $pattern);
                $dbman->change_field_default($ptable, $pattern);
            }
        }
        upgrade_plugin_savepoint(true, 2026040801, 'local', 'tm_course');
    }

    // 2026040802 — M4 UI fixes (no DB): delete-confirm string key + card shortcut to batch enrol.
    if ($oldversion < 2026040802) {
        upgrade_plugin_savepoint(true, 2026040802, 'local', 'tm_course');
    }

    // 2026040803 — M4 batch flow: require names + auto-create user by email + debrief user type (no DB).
    if ($oldversion < 2026040803) {
        upgrade_plugin_savepoint(true, 2026040803, 'local', 'tm_course');
    }

    // 2026040804 — M5 notification helper/providers/task + reminder threshold setting (no DB).
    if ($oldversion < 2026040804) {
        upgrade_plugin_savepoint(true, 2026040804, 'local', 'tm_course');
    }

    // 2026040805 — M5 N3: task explicitly reads threshold and applies dynamic cutoff (no DB).
    if ($oldversion < 2026040805) {
        upgrade_plugin_savepoint(true, 2026040805, 'local', 'tm_course');
    }

    // 2026040806 — zh_tw notification strings: avoid PHP interpolation notices (no DB).
    if ($oldversion < 2026040806) {
        upgrade_plugin_savepoint(true, 2026040806, 'local', 'tm_course');
    }

    // 2026040807 — notification send hardening to avoid email processor noise (no DB).
    if ($oldversion < 2026040807) {
        upgrade_plugin_savepoint(true, 2026040807, 'local', 'tm_course');
    }

    // 2026040808 — M6 calendar month view UI + events API + hover details (no DB).
    if ($oldversion < 2026040808) {
        upgrade_plugin_savepoint(true, 2026040808, 'local', 'tm_course');
    }

    // 2026040809 — M6 role-based calendar click routing + sales mode modal (no DB).
    if ($oldversion < 2026040809) {
        upgrade_plugin_savepoint(true, 2026040809, 'local', 'tm_course');
    }

    // 2026040810 — M6 fix: calendar events API includes all statuses in date range (no DB).
    if ($oldversion < 2026040810) {
        upgrade_plugin_savepoint(true, 2026040810, 'local', 'tm_course');
    }

    // 2026040811 — M6 reliability: AJAX endpoint mode + visible calendar API errors (no DB).
    if ($oldversion < 2026040811) {
        upgrade_plugin_savepoint(true, 2026040811, 'local', 'tm_course');
    }

    // 2026040812 — M6: wait for footer-loaded FullCalendar + ISO8601 API times (no DB).
    if ($oldversion < 2026040812) {
        upgrade_plugin_savepoint(true, 2026040812, 'local', 'tm_course');
    }

    // 2026040813 — M6: calendar_events API must set $PAGE context before format_string (no DB).
    if ($oldversion < 2026040813) {
        upgrade_plugin_savepoint(true, 2026040813, 'local', 'tm_course');
    }

    // 2026040814 — M6 UI/UX implementation: solid cards, hover lift, progress, today/weekend/grid polish (no DB).
    if ($oldversion < 2026040814) {
        upgrade_plugin_savepoint(true, 2026040814, 'local', 'tm_course');
    }

    // 2026040815 — M6 UX fixes: hover float strength + anchored viewport-safe popover + View Details CTA (no DB).
    if ($oldversion < 2026040815) {
        upgrade_plugin_savepoint(true, 2026040815, 'local', 'tm_course');
    }

    // 2026040816 — M6 i18n polish: remove speaker info and localize calendar labels/locale (no DB).
    if ($oldversion < 2026040816) {
        upgrade_plugin_savepoint(true, 2026040816, 'local', 'tm_course');
    }

    // 2026040817 — Session status open/closed control + enrol CTA routing and language label updates (no DB).
    if ($oldversion < 2026040817) {
        upgrade_plugin_savepoint(true, 2026040817, 'local', 'tm_course');
    }

    // 2026040818 — Auto-close rule task + closed-session admin batch add entry and permission guard (no DB).
    if ($oldversion < 2026040818) {
        upgrade_plugin_savepoint(true, 2026040818, 'local', 'tm_course');
    }

    // 2026040819 — Session metadata fields: teaching language, delivery mode, meeting link (no data loss).
    if ($oldversion < 2026040819) {
        $stable = new xmldb_table('local_tm_course_sessions');
        if ($dbman->table_exists($stable)) {
            $field = new xmldb_field('teaching_language', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'zh_tw', 'location');
            if (!$dbman->field_exists($stable, $field)) {
                $dbman->add_field($stable, $field);
            }
            $field = new xmldb_field('delivery_mode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'onsite', 'teaching_language');
            if (!$dbman->field_exists($stable, $field)) {
                $dbman->add_field($stable, $field);
            }
            $field = new xmldb_field('meeting_link', XMLDB_TYPE_TEXT, null, null, null, null, null, 'delivery_mode');
            if (!$dbman->field_exists($stable, $field)) {
                $dbman->add_field($stable, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026040819, 'local', 'tm_course');
    }

    // 2026040820 — M7: configurable physical daily limit + AJAX auto-duration calculation wiring (no DB).
    if ($oldversion < 2026040820) {
        upgrade_plugin_savepoint(true, 2026040820, 'local', 'tm_course');
    }

    // 2026040821 — M7: onsite auto mode behavior and +1h lunch-time formula correction (no DB).
    if ($oldversion < 2026040821) {
        upgrade_plugin_savepoint(true, 2026040821, 'local', 'tm_course');
    }

    // 2026040836 — Dedicated class request Phase 1: base tables.
    if ($oldversion < 2026040836) {
        $table = new xmldb_table('local_tm_course_reservation');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('requesterid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('delivery_mode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'onsite');
            $table->add_field('classroomid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('preferred_starttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('preferred_endtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('manager_note', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('disclaimer_snapshot', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_requesterid', XMLDB_INDEX_NOTUNIQUE, ['requesterid']);
            $table->add_index('idx_status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('idx_starttime', XMLDB_INDEX_NOTUNIQUE, ['preferred_starttime']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_tm_course_resv_learner');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('reservationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('firstname', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('lastname', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('email', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('institution', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('source_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_reservationid', XMLDB_INDEX_NOTUNIQUE, ['reservationid']);
            $table->add_index('idx_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026040836, 'local', 'tm_course');
    }

    // 2026040837 — Dedicated class request Phase 2: disclaimer gate + initial form flow (no DB changes).
    if ($oldversion < 2026040837) {
        upgrade_plugin_savepoint(true, 2026040837, 'local', 'tm_course');
    }

    // 2026040838 — Reservation Phase 2.5: multi-course, classroom mapping, learner source metadata.
    if ($oldversion < 2026040838) {
        $etable = new xmldb_table('local_tm_enabled_courses');
        $field = new xmldb_field('allowed_classroomids', XMLDB_TYPE_TEXT, null, null, null, null, null, 'default_duration_hours');
        if ($dbman->table_exists($etable) && !$dbman->field_exists($etable, $field)) {
            $dbman->add_field($etable, $field);
        }

        $rtable = new xmldb_table('local_tm_course_reservation');
        if ($dbman->table_exists($rtable)) {
            $field = new xmldb_field('courseids_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'courseid');
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
            $field = new xmldb_field('preferred_meeting_link', XMLDB_TYPE_TEXT, null, null, null, null, null, 'classroomid');
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
            $field = new xmldb_field('learner_source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual', 'preferred_endtime');
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
            $field = new xmldb_field('cohortid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'learner_source');
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
            $field = new xmldb_field('excel_filename', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'cohortid');
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026040838, 'local', 'tm_course');
    }

    // 2026040839 — Reservation phase2 UI/flow alignment (no DB changes).
    if ($oldversion < 2026040839) {
        upgrade_plugin_savepoint(true, 2026040839, 'local', 'tm_course');
    }

    // 2026040840 — Reservation form simplification + teaching language on reservation.
    if ($oldversion < 2026040840) {
        $rtable = new xmldb_table('local_tm_course_reservation');
        $field = new xmldb_field('teaching_language', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'zh_tw', 'delivery_mode');
        if ($dbman->table_exists($rtable) && !$dbman->field_exists($rtable, $field)) {
            $dbman->add_field($rtable, $field);
        }
        upgrade_plugin_savepoint(true, 2026040840, 'local', 'tm_course');
    }

    // 2026040841 — Reservation form allows empty learner list (no DB changes).
    if ($oldversion < 2026040841) {
        upgrade_plugin_savepoint(true, 2026040841, 'local', 'tm_course');
    }

    // 2026040842 — Phase 4: calendar plan JSON + onboarding flag + submitted flag.
    if ($oldversion < 2026040842) {
        $rtable = new xmldb_table('local_tm_course_reservation');
        if ($dbman->table_exists($rtable)) {
            $field = new xmldb_field('calendar_plan_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timemodified');
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
            $field = new xmldb_field('calendar_onboarding_seen', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'calendar_plan_json');
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
            $field = new xmldb_field('calendar_plan_submitted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'calendar_onboarding_seen');
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026040842, 'local', 'tm_course');
    }

    // 2026040843 — Phase A/B: course mapping supports onsite/online enable flags and per-mode durations.
    if ($oldversion < 2026040843) {
        $etable = new xmldb_table('local_tm_enabled_courses');
        if ($dbman->table_exists($etable)) {
            $field = new xmldb_field('default_duration_hours_onsite', XMLDB_TYPE_NUMBER, '6', '2', XMLDB_NOTNULL, null, '8', 'default_duration_hours');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }
            $field = new xmldb_field('default_duration_hours_online', XMLDB_TYPE_NUMBER, '6', '2', XMLDB_NOTNULL, null, '8', 'default_duration_hours_onsite');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }
            $field = new xmldb_field('allow_onsite', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'default_duration_hours_online');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }
            $field = new xmldb_field('allow_online', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'allow_onsite');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }
            // Backfill mode-specific durations from legacy duration column where possible.
            $DB->execute("UPDATE {local_tm_enabled_courses}
                             SET default_duration_hours_onsite = COALESCE(default_duration_hours_onsite, default_duration_hours),
                                 default_duration_hours_online = COALESCE(default_duration_hours_online, default_duration_hours)");
        }
        upgrade_plugin_savepoint(true, 2026040843, 'local', 'tm_course');
    }

    // 2026040845 — Phase 5: review center UI and custom reservation review flow (no DB schema changes).
    if ($oldversion < 2026040845) {
        upgrade_plugin_savepoint(true, 2026040845, 'local', 'tm_course');
    }

    // 2026040846 — Phase 5 refinement: pending-default filters, review note UX fixes, tracking page (no DB).
    if ($oldversion < 2026040846) {
        upgrade_plugin_savepoint(true, 2026040846, 'local', 'tm_course');
    }

    // 2026040847 — tracking detail drill-down pages (no DB changes).
    if ($oldversion < 2026040847) {
        upgrade_plugin_savepoint(true, 2026040847, 'local', 'tm_course');
    }

    // 2026040848 — sortable date/time columns in list pages (no DB changes).
    if ($oldversion < 2026040848) {
        upgrade_plugin_savepoint(true, 2026040848, 'local', 'tm_course');
    }

    // 2026040849 — reservation preferred classroom field for applicant-side onsite preference.
    if ($oldversion < 2026040849) {
        $rtable = new xmldb_table('local_tm_course_reservation');
        if ($dbman->table_exists($rtable)) {
            $field = new xmldb_field('preferred_classroomid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'classroomid');
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
            // Backfill from legacy classroomid so existing reservations keep expected behavior.
            $DB->execute("UPDATE {local_tm_course_reservation}
                             SET preferred_classroomid = classroomid
                           WHERE preferred_classroomid IS NULL
                             AND classroomid IS NOT NULL");
        }
        upgrade_plugin_savepoint(true, 2026040849, 'local', 'tm_course');
    }

    // 2026040850 — dashboard widgets and admin bulk session delete tools (no DB schema changes).
    if ($oldversion < 2026040850) {
        upgrade_plugin_savepoint(true, 2026040850, 'local', 'tm_course');
    }

    // 2026040851 — centralized dashboard display settings page (no DB schema changes).
    if ($oldversion < 2026040851) {
        upgrade_plugin_savepoint(true, 2026040851, 'local', 'tm_course');
    }

    // 2026040854 — enrolment sync audit fields for admin status/hover diagnostics.
    if ($oldversion < 2026040854) {
        $etable = new xmldb_table('local_tm_course_enrolments');
        if ($dbman->table_exists($etable)) {
            $field = new xmldb_field('sync_health', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'batch_submittedby');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }

            $field = new xmldb_field('sync_lastcheck', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'sync_health');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }

            $field = new xmldb_field('sync_statusjson', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sync_lastcheck');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }

            $field = new xmldb_field('sync_error', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sync_statusjson');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026040854, 'local', 'tm_course');
    }

    // 2026040855 — configurable hourly sync-audit interval setting (no DB schema changes).
    if ($oldversion < 2026040855) {
        upgrade_plugin_savepoint(true, 2026040855, 'local', 'tm_course');
    }

    // 2026040856 — review center: online meeting link on approve + list delivery mode column (no DB).
    if ($oldversion < 2026040856) {
        upgrade_plugin_savepoint(true, 2026040856, 'local', 'tm_course');
    }

    // 2026040858 — batch enrolment: optional submitter remark stored on each enrolment row.
    if ($oldversion < 2026040858) {
        $etable = new xmldb_table('local_tm_course_enrolments');
        if ($dbman->table_exists($etable)) {
            $field = new xmldb_field('batch_submitter_note', XMLDB_TYPE_TEXT, 'medium', null, null, null, null, 'batch_submittedby');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026040858, 'local', 'tm_course');
    }

    // 2026040859 — batch_enrol.php: pass submitter note into batch_enrol_pending (PHP-only fix).
    if ($oldversion < 2026040859) {
        upgrade_plugin_savepoint(true, 2026040859, 'local', 'tm_course');
    }

    // 2026040860 — attendance page diet summary + force standard attendance activity name on create.
    if ($oldversion < 2026040860) {
        upgrade_plugin_savepoint(true, 2026040860, 'local', 'tm_course');
    }

    // 2026040861 — attendance sync robustness + default statuses now Present/Late/Absent.
    if ($oldversion < 2026040861) {
        upgrade_plugin_savepoint(true, 2026040861, 'local', 'tm_course');
    }

    // 2026040862 — attendance marking now auto-relinks/recreates activity and backfills existing marks.
    if ($oldversion < 2026040862) {
        upgrade_plugin_savepoint(true, 2026040862, 'local', 'tm_course');
    }

    // 2026040863 — auto-created attendance uses separate groups; rebuild default statuses when active set is missing.
    if ($oldversion < 2026040863) {
        upgrade_plugin_savepoint(true, 2026040863, 'local', 'tm_course');
    }

    // 2026040864 — default status acronyms use full words; slot matching prefers exact session description.
    if ($oldversion < 2026040864) {
        upgrade_plugin_savepoint(true, 2026040864, 'local', 'tm_course');
    }

    // 2026040865 — avoid redirect break from debug output; default acronyms shortened to fit DB limits.
    if ($oldversion < 2026040865) {
        upgrade_plugin_savepoint(true, 2026040865, 'local', 'tm_course');
    }

    // 2026040866 — attendance sync reliability improvements + admin shortcut to valid take.php URL.
    if ($oldversion < 2026040866) {
        upgrade_plugin_savepoint(true, 2026040866, 'local', 'tm_course');
    }

    // 2026040867 — attendance link includes grouptype; sync targets only plugin-owned attendance activity.
    if ($oldversion < 2026040867) {
        upgrade_plugin_savepoint(true, 2026040867, 'local', 'tm_course');
    }

    // 2026042102 — pre-qualification verification questions and reservation file links.
    if ($oldversion < 2026042102) {
        $table = new xmldb_table('local_tm_course_vq_q');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('apply_mode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'both');
            $table->add_field('question_text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('is_required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_tm_course_vq_file');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('reservationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_reservationid', XMLDB_INDEX_NOTUNIQUE, ['reservationid']);
            $table->add_index('idx_questionid', XMLDB_INDEX_NOTUNIQUE, ['questionid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026042102, 'local', 'tm_course');
    }

    // 2026042103 — review center: fix get_records_sql duplicate keys for verification files; admin verification review page.
    if ($oldversion < 2026042103) {
        upgrade_plugin_savepoint(true, 2026042103, 'local', 'tm_course');
    }

    // 2026042104 — verification attachment review status fields.
    if ($oldversion < 2026042104) {
        $table = new xmldb_table('local_tm_course_vq_file');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('review_status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'itemid');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $field = new xmldb_field('review_note', XMLDB_TYPE_TEXT, null, null, null, null, null, 'review_status');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $field = new xmldb_field('reviewedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'review_note');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $field = new xmldb_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'reviewedby');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026042104, 'local', 'tm_course');
    }

    // 2026050504 — Sessions link to dedicated-class reservation (source_reservation_id) + backfill from legacy names.
    if ($oldversion < 2026050504) {
        $stable = new xmldb_table('local_tm_course_sessions');
        if ($dbman->table_exists($stable)) {
            $field = new xmldb_field('source_reservation_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'createdby');
            if (!$dbman->field_exists($stable, $field)) {
                $dbman->add_field($stable, $field);
            }
            $index = new xmldb_index('idx_source_reservation', XMLDB_INDEX_NOTUNIQUE, ['source_reservation_id']);
            if (!$dbman->index_exists($stable, $index)) {
                $dbman->add_index($stable, $index);
            }
            // Backfill from session names like "... | R123-1".
            $sessions = $DB->get_records_sql(
                "SELECT id, name FROM {local_tm_course_sessions}
                  WHERE source_reservation_id IS NULL OR source_reservation_id = 0"
            );
            foreach ($sessions as $row) {
                $name = (string)$row->name;
                if ($name === '') {
                    continue;
                }
                if (!preg_match('/\|\s*R(\d+)-\d+\s*$/', $name, $matches)) {
                    continue;
                }
                $rid = (int)$matches[1];
                if ($rid <= 0 || !$DB->record_exists('local_tm_course_reservation', ['id' => $rid])) {
                    continue;
                }
                $DB->set_field('local_tm_course_sessions', 'source_reservation_id', $rid, ['id' => (int)$row->id]);
            }
        }
        upgrade_plugin_savepoint(true, 2026050504, 'local', 'tm_course');
    }

    // 2026050602 — Batch placeholder support fields + post-approval email linking metadata.
    if ($oldversion < 2026050602) {
        $etable = new xmldb_table('local_tm_course_enrolments');
        if ($dbman->table_exists($etable)) {
            $field = new xmldb_field('seat_company', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'batch_submitter_note');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }

            $field = new xmldb_field('seat_note', XMLDB_TYPE_TEXT, null, null, null, null, null, 'seat_company');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }

            $field = new xmldb_field('placeholder_seq', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'seat_note');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }

            $field = new xmldb_field('placeholder_name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'placeholder_seq');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }

            $field = new xmldb_field('linked_userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'placeholder_name');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }

            $field = new xmldb_field('linked_email', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'linked_userid');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026050602, 'local', 'tm_course');
    }

    // 2026050603 — Batch seat-holder Moodle accounts + approval/sync behaviour (PHP/strings only if upgraded from older plugin copies).
    if ($oldversion < 2026050603) {
        upgrade_plugin_savepoint(true, 2026050603, 'local', 'tm_course');
    }

    // 2026050623 — Attendance permission rules table.
    if ($oldversion < 2026050623) {
        $table = new xmldb_table('local_tm_perm_att_rule');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('ruletype', XMLDB_TYPE_CHAR, '30', null, null, null, null);
            $table->add_field('pattern', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026050623, 'local', 'tm_course');
    }

    // 2026050625 — Tracking archive flags for batch/custom lists.
    if ($oldversion < 2026050625) {
        $etable = new xmldb_table('local_tm_course_enrolments');
        if ($dbman->table_exists($etable)) {
            $field = new xmldb_field('batch_archived', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'sync_error');
            if (!$dbman->field_exists($etable, $field)) {
                $dbman->add_field($etable, $field);
            }
        }

        $rtable = new xmldb_table('local_tm_course_reservation');
        if ($dbman->table_exists($rtable)) {
            $field = new xmldb_field('archived', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'calendar_plan_submitted');
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026050625, 'local', 'tm_course');
    }

    // 2026051201 — Reservation online manual split: per-application daily cap (hours).
    if ($oldversion < 2026051201) {
        $rtable = new xmldb_table('local_tm_course_reservation');
        if ($dbman->table_exists($rtable)) {
            $field = new xmldb_field(
                'online_daily_hours_limit',
                XMLDB_TYPE_NUMBER,
                '5,2',
                null,
                null,
                null,
                null,
                'archived'
            );
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026051201, 'local', 'tm_course');
    }

    // 2026051202 — Defensive re-check: ensure online_daily_hours_limit column exists.
    // The original 2026051201 step was effectively correct, but during diagnosis of a
    // separate plan API bug (missing $PAGE->set_context() in plan_events.php) we kept
    // this idempotent re-check so any environment that may have skipped the prior
    // savepoint will still end up with the field present.
    if ($oldversion < 2026051202) {
        $rtable = new xmldb_table('local_tm_course_reservation');
        if ($dbman->table_exists($rtable)) {
            $field = new xmldb_field(
                'online_daily_hours_limit',
                XMLDB_TYPE_NUMBER,
                '5,2',
                null,
                null,
                null,
                null,
                'archived'
            );
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026051202, 'local', 'tm_course');
    }

    // 2026051401 — Session registration auto-close: optional exempt per session + configurable days.
    if ($oldversion < 2026051401) {
        $stable = new xmldb_table('local_tm_course_sessions');
        if ($dbman->table_exists($stable)) {
            $field = new xmldb_field(
                'auto_close_exempt',
                XMLDB_TYPE_INTEGER,
                '1',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'status'
            );
            if (!$dbman->field_exists($stable, $field)) {
                $dbman->add_field($stable, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026051401, 'local', 'tm_course');
    }

    // 2026051504 — Mark legacy TM-created mod_attendance activities with a fixed course_modules.idnumber
    // so the plugin only reuses its own activity and never binds to unrelated teacher-made attendance.
    if ($oldversion < 2026051504) {
        require_once(__DIR__ . '/../classes/attendance_manager.php');
        $idn = \local_tm_course\attendance_manager::TM_ATTENDANCE_CM_IDNUMBER;
        $aname = \local_tm_course\attendance_manager::COURSE_ATTENDANCE_NAME;

        if ($dbman->table_exists('attendance') && $dbman->table_exists('course_modules')) {
            $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'attendance'], IGNORE_MISSING);
            if ($moduleid) {
                $sql = "SELECT cm.id, cm.course
                          FROM {course_modules} cm
                          JOIN {attendance} a ON a.id = cm.instance AND a.course = cm.course
                         WHERE cm.module = :mid
                           AND cm.deletioninprogress = 0
                           AND (cm.idnumber = :empty OR cm.idnumber IS NULL)
                           AND a.name = :aname";
                $rows = $DB->get_records_sql($sql, [
                    'mid' => $moduleid,
                    'empty' => '',
                    'aname' => $aname,
                ]);
                $bycourse = [];
                foreach ($rows as $r) {
                    $c = (int)$r->course;
                    if (!isset($bycourse[$c])) {
                        $bycourse[$c] = [];
                    }
                    $bycourse[$c][] = (int)$r->id;
                }
                require_once($CFG->dirroot . '/lib/moodlelib.php');
                foreach ($bycourse as $cid => $cmids) {
                    if (count($cmids) === 1) {
                        $DB->set_field('course_modules', 'idnumber', $idn, ['id' => $cmids[0]]);
                        rebuild_course_cache($cid);
                    }
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026051504, 'local', 'tm_course');
    }

    // 2026051600 — session_kind: classroom-only blocks (教室未開放) vs standard sessions.
    if ($oldversion < 2026051600) {
        $table = new xmldb_table('local_tm_course_sessions');
        $field = new xmldb_field(
            'session_kind',
            XMLDB_TYPE_CHAR,
            '32',
            null,
            XMLDB_NOTNULL,
            null,
            'standard',
            'source_reservation_id'
        );
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026051600, 'local', 'tm_course');
    }

    // 2026051700 — Session enrolment verification (self + batch) linked to course vq questions.
    if ($oldversion < 2026051700) {
        $etable = new xmldb_table('local_tm_course_enrolments');
        $efield = new xmldb_field(
            'vq_submission_id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'batch_archived'
        );
        if ($dbman->table_exists($etable) && !$dbman->field_exists($etable, $efield)) {
            $dbman->add_field($etable, $efield);
        }
        $eidx = new xmldb_index('idx_vq_submission', XMLDB_INDEX_NOTUNIQUE, ['vq_submission_id']);
        if ($dbman->table_exists($etable) && !$dbman->index_exists($etable, $eidx)) {
            $dbman->add_index($etable, $eidx);
        }

        $sub = new xmldb_table('local_tm_course_sess_vq_sub');
        if (!$dbman->table_exists($sub)) {
            $sub->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $sub->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $sub->add_field('scope', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'self');
            $sub->add_field('actor_userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $sub->add_field('submitted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $sub->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $sub->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $sub->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $sub->add_index('idx_session_scope_actor', XMLDB_INDEX_NOTUNIQUE, ['sessionid', 'scope', 'actor_userid']);
            $sub->add_index('idx_sessionid', XMLDB_INDEX_NOTUNIQUE, ['sessionid']);
            $dbman->create_table($sub);
        }

        $vf = new xmldb_table('local_tm_course_sess_vq_file');
        if (!$dbman->table_exists($vf)) {
            $vf->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $vf->add_field('submissionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $vf->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $vf->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $vf->add_field('review_status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $vf->add_field('review_note', XMLDB_TYPE_TEXT, 'medium', null, null, null);
            $vf->add_field('reviewedby', XMLDB_TYPE_INTEGER, '10', null, null, null);
            $vf->add_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $vf->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $vf->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $vf->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $vf->add_index('idx_submissionid', XMLDB_INDEX_NOTUNIQUE, ['submissionid']);
            $vf->add_index('idx_questionid', XMLDB_INDEX_NOTUNIQUE, ['questionid']);
            $dbman->create_table($vf);
        }

        upgrade_plugin_savepoint(true, 2026051700, 'local', 'tm_course');
    }

    // 2026051701 — enrol_apply_step: PAGE context before enrol (fix notification / format_string).
    if ($oldversion < 2026051701) {
        upgrade_plugin_savepoint(true, 2026051701, 'local', 'tm_course');
    }

    if ($oldversion < 2026051702) {
        upgrade_plugin_savepoint(true, 2026051702, 'local', 'tm_course');
    }

    // 2026051704 — Per-course online reservation classroom (course mapping).
    if ($oldversion < 2026051704) {
        $etable = new xmldb_table('local_tm_enabled_courses');
        $field = new xmldb_field('online_classroomid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'allowed_classroomids');
        if ($dbman->table_exists($etable) && !$dbman->field_exists($etable, $field)) {
            $dbman->add_field($etable, $field);
        }
        upgrade_plugin_savepoint(true, 2026051704, 'local', 'tm_course');
    }

    // 2026051705 — Rollback test UI: fix sessions.php encoding; revert online_classroomid feature code.
    if ($oldversion < 2026051705) {
        upgrade_plugin_savepoint(true, 2026051705, 'local', 'tm_course');
    }

    // 2026051706 — Re-enable per-course online reservation classroom (course mapping).
    if ($oldversion < 2026051706) {
        $etable = new xmldb_table('local_tm_enabled_courses');
        $field = new xmldb_field('online_classroomid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'allowed_classroomids');
        if ($dbman->table_exists($etable) && !$dbman->field_exists($etable, $field)) {
            $dbman->add_field($etable, $field);
        }
        upgrade_plugin_savepoint(true, 2026051706, 'local', 'tm_course');
    }

    // 2026051707 — Online classroom: application + calendar validation (no DB change).
    if ($oldversion < 2026051707) {
        upgrade_plugin_savepoint(true, 2026051707, 'local', 'tm_course');
    }

    // 2026051708 — Session roster view (no DB change).
    if ($oldversion < 2026051708) {
        upgrade_plugin_savepoint(true, 2026051708, 'local', 'tm_course');
    }

    // 2026051709 — Roster link on index cards; rename to 查看報名狀況 (no DB change).
    if ($oldversion < 2026051709) {
        upgrade_plugin_savepoint(true, 2026051709, 'local', 'tm_course');
    }

    // 2026051710 — Session roster: show self vs batch submitter (no DB change).
    if ($oldversion < 2026051710) {
        upgrade_plugin_savepoint(true, 2026051710, 'local', 'tm_course');
    }

    // 2026051711 — Remove waitlist: migrate waitlisted enrolments to pending (no schema change).
    if ($oldversion < 2026051711) {
        $DB->set_field('local_tm_course_enrolments', 'status', 0, ['status' => 4]);
        upgrade_plugin_savepoint(true, 2026051711, 'local', 'tm_course');
    }

    // 2026051712 — Onsite full = all desks occupied; block enrol when full/closed (online: closed only).
    if ($oldversion < 2026051712) {
        require_once($CFG->dirroot . '/local/tm_course/classes/session_manager.php');
        $ids = $DB->get_fieldset_select(
            'local_tm_course_sessions',
            'id',
            'status IN (?, ?)',
            [\local_tm_course\session_manager::STATUS_OPEN, \local_tm_course\session_manager::STATUS_FULL]
        );
        foreach ($ids as $sid) {
            \local_tm_course\session_manager::recalculate_status((int) $sid);
        }
        upgrade_plugin_savepoint(true, 2026051712, 'local', 'tm_course');
    }

    // 2026051713 — Pre-class notification (no DB schema change).
    if ($oldversion < 2026051713) {
        upgrade_plugin_savepoint(true, 2026051713, 'local', 'tm_course');
    }

    // 2026051714 — Pre-class: body template, targets UI, preview/test (no DB schema change).
    if ($oldversion < 2026051714) {
        upgrade_plugin_savepoint(true, 2026051714, 'local', 'tm_course');
    }

    // 2026051716 — Admin physical sessions: respect selected start time, chain-after-previous, same-day non-overlap.
    if ($oldversion < 2026051716) {
        upgrade_plugin_savepoint(true, 2026051716, 'local', 'tm_course');
    }

    // 2026051717 — Reservation calendar: chain plan blocks after prior end; normalize on save (see docs/SCHEDULING_REQUIREMENTS.md).
    if ($oldversion < 2026051717) {
        upgrade_plugin_savepoint(true, 2026051717, 'local', 'tm_course');
    }

    // 2026051718 — Room-closed hours count toward physical daily limit; no weekend spans in auto calc.
    if ($oldversion < 2026051718) {
        upgrade_plugin_savepoint(true, 2026051718, 'local', 'tm_course');
    }

    // 2026051720 — Admin edit session: preserve afternoon chain start; manual schedule mode (no DB change).
    if ($oldversion < 2026051720) {
        upgrade_plugin_savepoint(true, 2026051720, 'local', 'tm_course');
    }

    // 2026051721 — Role whitelist for plugin/dashboard/calendar visibility (config only, no schema).
    if ($oldversion < 2026051721) {
        upgrade_plugin_savepoint(true, 2026051721, 'local', 'tm_course');
    }

    // 2026052501 — Reservation draft vs formal submit (tracking only after step 3).
    if ($oldversion < 2026052501) {
        $rtable = new xmldb_table('local_tm_course_reservation');
        if ($dbman->table_exists($rtable)) {
            $field = new xmldb_field(
                'application_submitted',
                XMLDB_TYPE_INTEGER,
                '1',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'archived'
            );
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
            $field = new xmldb_field(
                'timesubmitted',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'application_submitted'
            );
            if (!$dbman->field_exists($rtable, $field)) {
                $dbman->add_field($rtable, $field);
            }
            // Existing rows were created under the old flow (submit at step 1).
            $DB->execute(
                "UPDATE {local_tm_course_reservation}
                    SET application_submitted = 1,
                        timesubmitted = CASE WHEN timesubmitted > 0 THEN timesubmitted ELSE timecreated END
                  WHERE COALESCE(application_submitted, 0) = 0"
            );
        }
        upgrade_plugin_savepoint(true, 2026052501, 'local', 'tm_course');
    }

    // 2026051719 — Fix upgrade order: re-run 1712 status recalc if upgrade stopped at 1718 (no schema change).
    if ($oldversion < 2026051719) {
        require_once($CFG->dirroot . '/local/tm_course/classes/session_manager.php');
        $ids = $DB->get_fieldset_select(
            'local_tm_course_sessions',
            'id',
            'status IN (?, ?)',
            [\local_tm_course\session_manager::STATUS_OPEN, \local_tm_course\session_manager::STATUS_FULL]
        );
        foreach ($ids as $sid) {
            \local_tm_course\session_manager::recalculate_status((int) $sid);
        }
        upgrade_plugin_savepoint(true, 2026051719, 'local', 'tm_course');
    }

    // 2026052901 — Attendance roster UI + bento lunch request (§52; config-only defaults via lang).
    if ($oldversion < 2026052901) {
        upgrade_plugin_savepoint(true, 2026052901, 'local', 'tm_course');
    }

    // 2026052902 — Per-learner attendance POST actions, status UI, bento CC config.
    if ($oldversion < 2026052902) {
        upgrade_plugin_savepoint(true, 2026052902, 'local', 'tm_course');
    }

    // 2026052903 — Bento summary column labels + HTML body editor for templates.
    if ($oldversion < 2026052903) {
        upgrade_plugin_savepoint(true, 2026052903, 'local', 'tm_course');
    }

    // 2026052904 — Bento modal max-height, scrollable body, fixed action footer.
    if ($oldversion < 2026052904) {
        upgrade_plugin_savepoint(true, 2026052904, 'local', 'tm_course');
    }

    // 2026060101 — Dedicated class: batch full-list learners + per-session enrol review links.
    if ($oldversion < 2026060101) {
        $rtable = new xmldb_table('local_tm_course_reservation');
        $rfield = new xmldb_field(
            'batch_submitter_note',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'online_daily_hours_limit'
        );
        if ($dbman->table_exists($rtable) && !$dbman->field_exists($rtable, $rfield)) {
            $dbman->add_field($rtable, $rfield);
        }

        $ltable = new xmldb_table('local_tm_course_resv_learner');
        $dietchoice = new xmldb_field(
            'diet_choice',
            XMLDB_TYPE_CHAR,
            '1',
            null,
            null,
            null,
            null,
            'institution'
        );
        $dietspecial = new xmldb_field(
            'diet_special_note',
            XMLDB_TYPE_CHAR,
            '255',
            null,
            null,
            null,
            null,
            'diet_choice'
        );
        if ($dbman->table_exists($ltable)) {
            if (!$dbman->field_exists($ltable, $dietchoice)) {
                $dbman->add_field($ltable, $dietchoice);
            }
            if (!$dbman->field_exists($ltable, $dietspecial)) {
                $dbman->add_field($ltable, $dietspecial);
            }
        }

        upgrade_plugin_savepoint(true, 2026060101, 'local', 'tm_course');
    }

    // 2026060800 — Prerequisite rules JSON (AND/OR, activities, TM-enabled courses).
    if ($oldversion < 2026060800) {
        require_once(__DIR__ . '/../classes/prerequisite_manager.php');

        $table = new xmldb_table('local_tm_course_sessions');
        $field = new xmldb_field(
            'prerequisite_rules',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'prerequisite_courseid'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $sessions = $DB->get_records_select(
            'local_tm_course_sessions',
            'prerequisite_courseid IS NOT NULL AND prerequisite_courseid > 0'
        );
        foreach ($sessions as $s) {
            $rules = \local_tm_course\prerequisite_manager::normalize_rules([
                'operator' => \local_tm_course\prerequisite_manager::OPERATOR_AND,
                'rules' => [
                    [
                        'courseid' => (int)$s->prerequisite_courseid,
                        'verify_type' => \local_tm_course\prerequisite_manager::VERIFY_COURSE,
                    ],
                ],
            ]);
            $json = \local_tm_course\prerequisite_manager::encode_for_storage($rules);
            if ($json !== null) {
                $DB->set_field('local_tm_course_sessions', 'prerequisite_rules', $json, ['id' => (int)$s->id]);
            }
        }

        upgrade_plugin_savepoint(true, 2026060800, 'local', 'tm_course');
    }

    // 2026060801 — Course mapping default prerequisite rules + batch Block A guard.
    if ($oldversion < 2026060801) {
        $etable = new xmldb_table('local_tm_enabled_courses');
        $efield = new xmldb_field(
            'default_prerequisite_rules',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'online_classroomid'
        );
        if (!$dbman->field_exists($etable, $efield)) {
            $dbman->add_field($etable, $efield);
        }
        upgrade_plugin_savepoint(true, 2026060801, 'local', 'tm_course');
    }

    // 2026061600 — Flag enrolments created at dedicated-class approval (vs later batch_enrol).
    if ($oldversion < 2026061600) {
        $etable = new xmldb_table('local_tm_course_enrolments');
        $efield = new xmldb_field(
            'reservation_initial_enrol',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'vq_submission_id'
        );
        if ($dbman->table_exists($etable) && !$dbman->field_exists($etable, $efield)) {
            $dbman->add_field($etable, $efield);
        }

        // Backfill: match learners stored on the reservation application.
        $sessions = $DB->get_records_select(
            'local_tm_course_sessions',
            'source_reservation_id IS NOT NULL AND source_reservation_id > 0',
            null,
            'id ASC',
            'id, source_reservation_id'
        );
        foreach ($sessions as $sess) {
            $rid = (int)$sess->source_reservation_id;
            $learners = $DB->get_records_select(
                'local_tm_course_resv_learner',
                'reservationid = :rid AND userid > 1',
                ['rid' => $rid],
                '',
                'userid'
            );
            if (empty($learners)) {
                continue;
            }
            $userids = array_map(function($lr) {
                return (int)$lr->userid;
            }, $learners);
            list($uinsql, $uinparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
            $params = ['sid' => (int)$sess->id] + $uinparams;
            $DB->execute(
                "UPDATE {local_tm_course_enrolments}
                    SET reservation_initial_enrol = 1
                  WHERE sessionid = :sid
                    AND userid $uinsql",
                $params
            );
        }

        upgrade_plugin_savepoint(true, 2026061600, 'local', 'tm_course');
    }

    // 2026070700 — TCMS Phase 1 sync fields on sessions.
    if ($oldversion < 2026070700) {
        $table = new xmldb_table('local_tm_course_sessions');
        $fields = [
            new xmldb_field('tcms_session_id', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'session_kind'),
            new xmldb_field('tcms_sync_status', XMLDB_TYPE_CHAR, '16', null, null, null, null, 'tcms_session_id'),
            new xmldb_field('tcms_sync_error', XMLDB_TYPE_TEXT, null, null, null, null, null, 'tcms_sync_status'),
            new xmldb_field('tcms_sync_hash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'tcms_sync_error'),
            new xmldb_field('tcms_last_synced', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'tcms_sync_hash'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026070700, 'local', 'tm_course');
    }

    // 2026071200 — TCMS mapping via dropdowns: per-course type + per-classroom location.
    if ($oldversion < 2026071200) {
        $ctable = new xmldb_table('local_tm_classroom');
        $cfield = new xmldb_field('tcms_location', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'location');
        if ($dbman->table_exists($ctable) && !$dbman->field_exists($ctable, $cfield)) {
            $dbman->add_field($ctable, $cfield);
        }

        $etable = new xmldb_table('local_tm_enabled_courses');
        $efield = new xmldb_field('tcms_course_type', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'default_prerequisite_rules');
        if ($dbman->table_exists($etable) && !$dbman->field_exists($etable, $efield)) {
            $dbman->add_field($etable, $efield);
        }

        upgrade_plugin_savepoint(true, 2026071200, 'local', 'tm_course');
    }

    // 2026072401 — Equipment check (上課準備事項): item templates + per-session per-desk log.
    if ($oldversion < 2026072401) {

        if (!$dbman->table_exists('local_tm_equip_check_item')) {
            $table = new xmldb_table('local_tm_equip_check_item');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('scope', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'both');
            $table->add_field('checktype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'status');
            $table->add_field('itemname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $dbman->create_table($table);
        }

        if (!$dbman->table_exists('local_tm_equip_check_log')) {
            $table = new xmldb_table('local_tm_equip_check_log');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('desknumber', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('checkstatus', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('remark', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('checkedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('uq_session_desk_item', XMLDB_KEY_UNIQUE, ['sessionid', 'desknumber', 'itemid']);
            $table->add_index('idx_sessionid', XMLDB_INDEX_NOTUNIQUE, ['sessionid']);
            $table->add_index('idx_itemid', XMLDB_INDEX_NOTUNIQUE, ['itemid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072401, 'local', 'tm_course');
    }

    return true;
}


