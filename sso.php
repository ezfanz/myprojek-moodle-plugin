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
 * Consumes a one-time SSO token issued by local_myprojeksync_provision_sso_login and logs
 * the browser in as that user — deliberately NOT a webservice function (those only ever
 * return JSON, they can't set a browser session) and deliberately does NOT call
 * require_login(), since consuming the token correctly *is* the login. Mirrors the same
 * signed-token-in-a-URL pattern Moodle's own password-reset confirmation links use.
 *
 * @package    local_myprojeksync
 * @copyright  2026 MyProjek2.0 integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');

$token = required_param('token', PARAM_ALPHANUM);

$record = $DB->get_record('local_myprojeksync_sso', ['token' => $token]);

if (!$record || $record->used || $record->expires < time()) {
    print_error('invalidtoken', 'local_myprojeksync');
}

$DB->set_field('local_myprojeksync_sso', 'used', 1, ['id' => $record->id]);

$user = $DB->get_record('user', ['id' => $record->userid, 'deleted' => 0], '*', MUST_EXIST);

complete_user_login($user);

if (!empty($record->courseid)) {
    redirect(new moodle_url('/course/view.php', ['id' => $record->courseid]));
} else {
    redirect(new moodle_url('/my/'));
}
