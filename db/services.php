<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Web service function declarations.
 *
 * No external service is declared here on purpose: this function is meant to be added to the
 * existing "MyProjek Sync" external service (Site administration > Server > Web services >
 * External services), reusing the token already issued for course-category sync.
 *
 * @package    local_myprojeksync
 * @copyright  2026 MyProjek2.0 integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_myprojeksync_create_quiz' => [
        'classname'    => 'local_myprojeksync\external\create_quiz',
        'methodname'   => 'execute',
        'description'  => 'Create a quiz activity with multiple-choice questions in a course.',
        'type'         => 'write',
        'ajax'         => false,
        'capabilities' => 'local/myprojeksync:createquiz',
    ],
    'local_myprojeksync_provision_sso_login' => [
        'classname'    => 'local_myprojeksync\external\provision_sso_login',
        'methodname'   => 'execute',
        'description'  => 'Find-or-create a Moodle user for the given email, grant either category-level manager access or course-level student enrolment, and issue a one-time SSO login token.',
        'type'         => 'write',
        'ajax'         => false,
        'capabilities' => 'local/myprojeksync:ssologin',
    ],
    'local_myprojeksync_sync_syllabus' => [
        'classname'    => 'local_myprojeksync\external\sync_syllabus',
        'methodname'   => 'execute',
        'description'  => 'Mirror a MyProjek2.0 Silibus into a course as one section + video Page per topic (idempotent).',
        'type'         => 'write',
        'ajax'         => false,
        'capabilities' => 'local/myprojeksync:syncsyllabus',
    ],
];
