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

use context_course;
use core_courseformat\formatactions;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use section_info;
use stdClass;

/**
 * Mirror a MyProjek2.0 Silibus into a course: one section + one Page per topic.
 *
 * Idempotent. Only Pages whose course-module idnumber is "myprojek-topic-{key}" are managed;
 * anything else in the course (added by hand in Moodle) is never touched.
 *
 * @package    local_myprojeksync
 * @copyright  2026 MyProjek2.0 integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_syllabus extends external_api {

    /** @var string Prefix of the cm idnumber marking a page as MyProjek-managed. */
    const IDNUMBER_PREFIX = 'myprojek-topic-';

    /**
     * Declare the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course to sync the syllabus into.'),
            'topics' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_ALPHANUMEXT, 'Stable MyProjek topic id.'),
                    'name' => new external_value(PARAM_TEXT, 'Topic name (section + page name).'),
                    'description' => new external_value(PARAM_RAW, 'Topic description (plain text).', VALUE_DEFAULT, ''),
                    'video_url' => new external_value(PARAM_URL, 'Video link (YouTube/Vimeo/mp4).', VALUE_DEFAULT, ''),
                ]),
                'Ordered topics. An empty list removes every managed topic.',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Create/update/reorder/remove the managed topic sections so they match $topics.
     *
     * @param int $courseid
     * @param array $topics
     * @return array
     */
    public static function execute(int $courseid, array $topics = []): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        ['courseid' => $courseid, 'topics' => $topics] = self::validate_parameters(
            self::execute_parameters(),
            ['courseid' => $courseid, 'topics' => $topics]
        );

        $course = get_course($courseid);
        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/myprojeksync:syncsyllabus', $context);
        require_capability('moodle/course:manageactivities', $context);

        if (empty($course->enablecompletion)) {
            $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
            $course->enablecompletion = 1;
        }

        $sectionactions = formatactions::section($course);
        $cmactions = formatactions::cm($course);
        $managed = self::managed_cms($course->id);
        $result = [];
        $position = 1;

        foreach ($topics as $topic) {
            $idnumber = self::IDNUMBER_PREFIX . $topic['key'];
            $content = self::build_content($topic['description'], $topic['video_url']);

            if (isset($managed[$idnumber])) {
                $cm = $managed[$idnumber];
                unset($managed[$idnumber]);

                $DB->update_record('page', (object) [
                    'id' => $cm->instance,
                    'name' => $topic['name'],
                    'content' => $content,
                    'contentformat' => FORMAT_HTML,
                    'timemodified' => time(),
                ]);
                $sectionid = (int) $cm->section;
                $cmid = (int) $cm->id;
            } else {
                $section = $sectionactions->create($position);
                $cmid = self::create_page($course, (int) $section->section, $topic['name'], $content, $idnumber);
                $sectionid = (int) $section->id;
            }

            $sectioninfo = self::section_info($course->id, $sectionid);
            $sectionactions->update($sectioninfo, ['name' => $topic['name']]);

            $sectioninfo = self::section_info($course->id, $sectionid);
            if ((int) $sectioninfo->sectionnum !== $position) {
                $sectionactions->move_at($sectioninfo, $position);
            }

            $result[] = ['key' => $topic['key'], 'sectionid' => $sectionid, 'cmid' => $cmid];
            $position++;
        }

        foreach ($managed as $cm) {
            $sectionid = (int) $cm->section;
            $cmactions->delete((int) $cm->id);
            $remaining = $DB->count_records('course_modules', ['section' => $sectionid, 'deletioninprogress' => 0]);
            $sectioninfo = self::section_info($course->id, $sectionid);
            if ($remaining === 0 && $sectioninfo !== null && (int) $sectioninfo->sectionnum > 0) {
                // Never force: a section that still holds hand-added content must survive.
                $sectionactions->delete($sectioninfo, false);
            }
        }

        rebuild_course_cache($course->id, true);

        return ['sections' => $result];
    }

    /**
     * Fresh section_info for a section id (section numbers shift as sections are created/moved).
     *
     * @param int $courseid
     * @param int $sectionid
     * @return section_info|null
     */
    protected static function section_info(int $courseid, int $sectionid): ?section_info {
        get_fast_modinfo($courseid, 0, true);

        return get_fast_modinfo($courseid)->get_section_info_by_id($sectionid);
    }

    /**
     * Managed Page course modules in the course, keyed by idnumber.
     *
     * @param int $courseid
     * @return array<string, stdClass>
     */
    protected static function managed_cms(int $courseid): array {
        global $DB;

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'page'], MUST_EXIST);
        $rows = $DB->get_records_select(
            'course_modules',
            'course = ? AND module = ? AND deletioninprogress = 0 AND ' . $DB->sql_like('idnumber', '?'),
            [$courseid, $moduleid, self::IDNUMBER_PREFIX . '%']
        );

        $byidnumber = [];
        foreach ($rows as $row) {
            $byidnumber[$row->idnumber] = $row;
        }

        return $byidnumber;
    }

    /**
     * Page body: description paragraph + plain video link.
     *
     * @param string $description
     * @param string $videourl
     * @return string
     */
    protected static function build_content(string $description, string $videourl): string {
        $html = '';
        if (trim($description) !== '') {
            $html .= '<p>' . nl2br(s($description)) . '</p>';
        }
        if (trim($videourl) !== '') {
            // Plain link on purpose: Moodle's multimedia filter turns YouTube/Vimeo/mp4 links
            // into an embedded player, and a raw iframe would be stripped by the HTML purifier.
            $html .= '<p><a href="' . s($videourl) . '">' . s($videourl) . '</a></p>';
        }

        return $html !== '' ? $html : '<p></p>';
    }

    /**
     * Create the managed Page, completion-on-view so participant progress ticks automatically.
     *
     * @param stdClass $course
     * @param int $sectionnum
     * @param string $name
     * @param string $content
     * @param string $idnumber
     * @return int course module id
     */
    protected static function create_page(stdClass $course, int $sectionnum, string $name, string $content, string $idnumber): int {
        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'page';
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->visible = 1;
        $moduleinfo->name = $name;
        $moduleinfo->cmidnumber = $idnumber;
        $moduleinfo->introeditor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $moduleinfo->page = ['text' => $content, 'format' => FORMAT_HTML, 'itemid' => 0];
        $moduleinfo->display = 5; // RESOURCELIB_DISPLAY_OPEN.
        $moduleinfo->printintro = 0;
        $moduleinfo->printlastmodified = 0;
        $moduleinfo->completion = COMPLETION_TRACKING_AUTOMATIC;
        $moduleinfo->completionview = 1;
        $moduleinfo->completionexpected = 0;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;

        return (int) create_module($moduleinfo)->coursemodule;
    }

    /**
     * Define the webservice response.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sections' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_ALPHANUMEXT, 'MyProjek topic id.'),
                    'sectionid' => new external_value(PARAM_INT, 'course_sections.id holding the topic.'),
                    'cmid' => new external_value(PARAM_INT, 'Page course-module id.'),
                ])
            ),
        ]);
    }
}
