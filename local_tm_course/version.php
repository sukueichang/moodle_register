<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * TM Course Management Plugin
 * Version: 5.28.11
 * @package    local_tm_course
 * @copyright  2024 Techman Robot
 * @license    GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026100806;   // Survey respondents and quiz finished-attempt grade
$plugin->release   = '5.28.11';
$plugin->requires  = 2020060900;   // Moodle 3.9+ (compatible with 3.10.x)
$plugin->component = 'local_tm_course';
$plugin->maturity  = MATURITY_BETA;
