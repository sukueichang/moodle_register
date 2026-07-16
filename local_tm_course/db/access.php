<?php
/**
 * Capability definitions for local_tm_course
 * @package    local_tm_course
 */
defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Full management: create/edit/delete sessions
    'local/tm_course:manage' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager'       => CAP_ALLOW,
            'coursecreator' => CAP_ALLOW,
        ],
    ],

    // Approve or reject enrolment applications
    'local/tm_course:approve' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Enrol oneself into a session
    'local/tm_course:enrol' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager'        => CAP_ALLOW,
            'coursecreator'  => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'student'        => CAP_ALLOW,
            'user'           => CAP_ALLOW,
        ],
    ],

    // View all enrolment records (admin / business role)
    'local/tm_course:viewall' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager'       => CAP_ALLOW,
            'coursecreator' => CAP_ALLOW,
        ],
    ],

    // Business batch enrolment (M4); may also be granted via auto-rules in permissions UI
    'local/tm_course:batchenrol' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Open attendance page / mark attendance (can also be auto-granted by attendance permission rules).
    'local/tm_course:attendance' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
