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

namespace local_myprojeksync\external;

use context_coursecat;
use context_course;
use context_system;
use core\exception\invalid_parameter_exception;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use stdClass;

/**
 * Find-or-create a Moodle user for a MyProjek2.0 user, grant them access (either the
 * manager role at a course category, or a student enrolment in a specific course), and
 * issue a one-time SSO login token consumed by sso.php.
 *
 * @package    local_myprojeksync
 * @copyright  2026 MyProjek2.0 integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provision_sso_login extends external_api {

    /** @var int Token lifetime in seconds. */
    const TOKEN_TTL = 60;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email' => new external_value(PARAM_EMAIL, 'Email address identifying the MyProjek2.0 user.'),
            'firstname' => new external_value(PARAM_TEXT, 'First name.'),
            'lastname' => new external_value(PARAM_TEXT, 'Last name.'),
            'categoryid' => new external_value(PARAM_INT, 'Course category to assign the manager role at (required when role=manager).', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Course to redirect into after login, and to enrol into when role=student (required when role=student).', VALUE_DEFAULT, 0),
            'role' => new external_value(PARAM_ALPHA, 'Access to grant: "manager" (category-level role) or "student" (course enrolment).', VALUE_DEFAULT, 'manager'),
        ]);
    }

    public static function execute(string $email, string $firstname, string $lastname, int $categoryid = 0, int $courseid = 0, string $role = 'manager'): array {
        global $DB, $CFG;

        [
            'email' => $email,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'categoryid' => $categoryid,
            'courseid' => $courseid,
            'role' => $role,
        ] = self::validate_parameters(self::execute_parameters(), [
            'email' => $email,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'categoryid' => $categoryid,
            'courseid' => $courseid,
            'role' => $role,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/myprojeksync:ssologin', $context);

        $user = self::find_or_create_user($email, $firstname, $lastname);

        if ($role === 'student') {
            if ($courseid <= 0) {
                throw new invalid_parameter_exception('courseid is required when role=student');
            }
            self::ensure_student_enrolment($user->id, $courseid);
        } else {
            if ($categoryid <= 0) {
                throw new invalid_parameter_exception('categoryid is required when role=manager');
            }
            self::ensure_manager_role($user->id, $categoryid);
        }

        $token = self::issue_token($user->id, $courseid);

        return ['token' => $token, 'userid' => $user->id];
    }

    protected static function find_or_create_user(string $email, string $firstname, string $lastname): stdClass {
        global $CFG;

        $existing = \core\user::get_user_by_email($email);
        if ($existing) {
            return $existing;
        }

        $newuser = new stdClass();
        $newuser->username = self::generate_username($email);
        $newuser->email = $email;
        $newuser->firstname = trim($firstname) !== '' ? $firstname : $email;
        $newuser->lastname = trim($lastname) !== '' ? $lastname : $newuser->firstname;
        $newuser->auth = 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = $CFG->mnet_localhost_id;
        // Random, policy-satisfying, never given out — this account only ever logs in via
        // the one-time SSO token, never a typed password.
        $newuser->password = 'Sso!'.bin2hex(random_bytes(16)).'@1';

        \core\user::create_user($newuser, true, false);

        return \core\user::get_user_by_email($email);
    }

    /**
     * Sanitize an email into a valid, unique Moodle username, mirroring the numeric-suffix
     * collision-avoidance loop create_quiz.php already uses for question category names.
     */
    protected static function generate_username(string $email): string {
        global $DB, $CFG;

        $base = \core_text::strtolower(preg_replace('/[^a-z0-9._-]/', '', str_replace('@', '_at_', \core_text::strtolower($email))));
        if ($base === '') {
            $base = 'ssouser';
        }

        $username = $base;
        $suffix = 1;
        while ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
            $suffix++;
            $username = $base.$suffix;
        }

        return $username;
    }

    protected static function ensure_manager_role(int $userid, int $categoryid): void {
        global $DB;

        $categorycontext = context_coursecat::instance($categoryid);
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        if (!user_has_role_assignment($userid, $managerroleid, $categorycontext->id)) {
            role_assign($managerroleid, $userid, $categorycontext->id);
        }
    }

    /**
     * Enrol the user as 'student' in the course via manual enrolment — a role_assign() alone
     * would grant the student capability but NOT make them a real enrolled participant (course
     * wouldn't show in their course list, completion tracking wouldn't associate). Mirrors the
     * exact instance-lookup-or-create + enrol_user() pattern Moodle core's own
     * enrol/manual/externallib.php uses.
     */
    protected static function ensure_student_enrolment(int $userid, int $courseid): void {
        global $DB;

        $course = get_course($courseid);
        $plugin = enrol_get_plugin('manual');

        $manualinstance = null;
        foreach (enrol_get_instances($courseid, true) as $instance) {
            if ($instance->enrol === 'manual') {
                $manualinstance = $instance;
                break;
            }
        }
        if ($manualinstance === null) {
            $instanceid = $plugin->add_instance($course);
            $manualinstance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
        }

        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        if (!is_enrolled(context_course::instance($courseid), $userid)) {
            $plugin->enrol_user($manualinstance, $userid, $studentroleid);
        }
    }

    protected static function issue_token(int $userid, int $courseid): string {
        global $DB;

        $token = bin2hex(random_bytes(32));

        $record = new stdClass();
        $record->token = $token;
        $record->userid = $userid;
        $record->courseid = $courseid ?: null;
        $record->expires = time() + self::TOKEN_TTL;
        $record->used = 0;
        $record->timecreated = time();
        $DB->insert_record('local_myprojeksync_sso', $record);

        return $token;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'token' => new external_value(PARAM_ALPHANUM, 'One-time SSO login token.'),
            'userid' => new external_value(PARAM_INT, 'Id of the Moodle user the token logs in as.'),
        ]);
    }
}
