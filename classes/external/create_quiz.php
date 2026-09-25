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

use core\exception\invalid_parameter_exception;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_question\local\bank\question_bank_helper;
use context_course;
use context_module;
use mod_quiz\quiz_settings;
use question_bank;
use stdClass;

/**
 * Create a quiz activity with multiple-choice questions in one atomic call.
 *
 * Used by the MyProjek2.0 external system, which has no other way to create quizzes: Moodle's
 * stock external functions only cover attempting/reviewing an already-existing quiz.
 *
 * @package    local_myprojeksync
 * @copyright  2026 MyProjek2.0 integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_quiz extends external_api {

    /** @var int Number of choices every question must have. */
    const NUM_CHOICES = 4;

    /**
     * Declare the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course to create the quiz in.'),
            'name' => new external_value(PARAM_TEXT, 'Quiz name.'),
            'intro' => new external_value(PARAM_RAW, 'Quiz description (HTML).', VALUE_DEFAULT, ''),
            'questions' => new external_multiple_structure(
                new external_single_structure([
                    'questiontext' => new external_value(PARAM_RAW, 'Question text (HTML).'),
                    'choices' => new external_multiple_structure(
                        new external_value(PARAM_RAW, 'Choice text.'),
                        'Exactly ' . self::NUM_CHOICES . ' answer choices, in order.'
                    ),
                    'correctanswer' => new external_value(
                        PARAM_INT,
                        '1-based index into choices identifying the correct answer (1-' . self::NUM_CHOICES . ').'
                    ),
                ]),
                'Ordered list of questions to create in the quiz.',
                VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Create the quiz activity and all its questions.
     *
     * @param int $courseid
     * @param string $name
     * @param string $intro
     * @param array $questions
     * @return array
     */
    public static function execute(int $courseid, string $name, string $intro, array $questions): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        [
            'courseid' => $courseid,
            'name' => $name,
            'intro' => $intro,
            'questions' => $questions,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'name' => $name,
            'intro' => $intro,
            'questions' => $questions,
        ]);

        $course = get_course($courseid);
        $coursecontext = context_course::instance($courseid);
        self::validate_context($coursecontext);
        require_capability('local/myprojeksync:createquiz', $coursecontext);
        require_capability('mod/quiz:addinstance', $coursecontext);

        self::validate_quiz_payload($name, $questions);

        $transaction = $DB->start_delegated_transaction();

        $quizcm = self::create_quiz_activity($course, $name, $intro);
        $quiz = new stdClass();
        $quiz->id = $quizcm->instance;
        $quiz->course = $course->id;
        $quiz->cmid = $quizcm->id;

        $qbankcm = question_bank_helper::get_default_open_instance_system_type($course, true);
        $qbankcontext = context_module::instance($qbankcm->id);
        require_capability('moodle/question:add', $qbankcontext);

        $category = self::create_question_category($qbankcontext, $name);

        $questionresults = [];
        foreach ($questions as $index => $question) {
            $savedquestion = self::save_multichoice_question($category, $qbankcontext, $question);
            quiz_add_quiz_question($savedquestion->id, $quiz);
            // The quiz was just created empty and questions are added strictly in order,
            // so the slot number is simply the 1-based position in this request.
            $questionresults[] = [
                'index' => $index,
                'questionid' => $savedquestion->id,
                'slot' => $index + 1,
            ];
        }

        // Slots were added with each question's own defaultmark (1 each), but the quiz's
        // own sumgrades stayed at its initial 0 (set in create_quiz_activity()) — without
        // recomputing it here, quiz_create_attempt() rejects every attempt with
        // "cannotstartgradesmismatch" (grade=100 but sumgrades=0, so marks can't be scaled).
        // Mirrors mod_quiz\external\add_random_questions's own call to this after adding slots.
        quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

        $transaction->allow_commit();

        return [
            'quizid' => $quiz->id,
            'cmid' => $quizcm->id,
            'courseid' => $course->id,
            'questioncategoryid' => $category->id,
            'questions' => $questionresults,
        ];
    }

    /**
     * Validate the whole payload before any DB writes happen.
     *
     * @param string $name
     * @param array $questions
     */
    protected static function validate_quiz_payload(string $name, array $questions): void {
        if (trim($name) === '') {
            throw new invalid_parameter_exception(get_string('erroremptyname', 'local_myprojeksync'));
        }

        if (empty($questions)) {
            throw new invalid_parameter_exception(get_string('errornoquestions', 'local_myprojeksync'));
        }

        foreach ($questions as $i => $question) {
            if (trim($question['questiontext']) === '') {
                throw new invalid_parameter_exception(
                    get_string('erroremptyquestiontext', 'local_myprojeksync', $i)
                );
            }

            if (count($question['choices']) !== self::NUM_CHOICES) {
                throw new invalid_parameter_exception(
                    get_string('errorwrongchoicecount', 'local_myprojeksync', (object) [
                        'index' => $i,
                        'expected' => self::NUM_CHOICES,
                        'actual' => count($question['choices']),
                    ])
                );
            }

            foreach ($question['choices'] as $choice) {
                if (trim($choice) === '') {
                    throw new invalid_parameter_exception(
                        get_string('erroremptychoice', 'local_myprojeksync', $i)
                    );
                }
            }

            if ($question['correctanswer'] < 1 || $question['correctanswer'] > self::NUM_CHOICES) {
                throw new invalid_parameter_exception(
                    get_string('errorbadcorrectanswer', 'local_myprojeksync', (object) [
                        'index' => $i,
                        'max' => self::NUM_CHOICES,
                    ])
                );
            }
        }
    }

    /**
     * Create the quiz course module, appended to the end of the course.
     *
     * @param stdClass $course
     * @param string $name
     * @param string $intro
     * @return \cm_info
     */
    protected static function create_quiz_activity(stdClass $course, string $name, string $intro): \cm_info {
        global $DB;

        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'quiz';
        $moduleinfo->module = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
        $moduleinfo->course = $course->id;
        $moduleinfo->section = course_get_format($course)->get_last_section_number();
        $moduleinfo->visible = 1;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->name = trim($name);
        $moduleinfo->intro = $intro;
        $moduleinfo->introformat = FORMAT_HTML;

        // Quiz-specific settings, matching mod_quiz's own test generator defaults
        // (mod/quiz/tests/generator/lib.php) so quiz_process_options() has everything it needs.
        $moduleinfo->timeopen = 0;
        $moduleinfo->timeclose = 0;
        $moduleinfo->preferredbehaviour = 'deferredfeedback';
        $moduleinfo->attempts = 0;
        $moduleinfo->attemptonlast = 0;
        $moduleinfo->grademethod = QUIZ_GRADEHIGHEST;
        $moduleinfo->decimalpoints = 2;
        $moduleinfo->questiondecimalpoints = -1;
        $moduleinfo->attemptduring = 1;
        $moduleinfo->correctnessduring = 1;
        $moduleinfo->maxmarksduring = 1;
        $moduleinfo->marksduring = 1;
        $moduleinfo->specificfeedbackduring = 1;
        $moduleinfo->generalfeedbackduring = 1;
        $moduleinfo->rightanswerduring = 1;
        $moduleinfo->overallfeedbackduring = 0;
        $moduleinfo->attemptimmediately = 1;
        $moduleinfo->correctnessimmediately = 1;
        $moduleinfo->maxmarksimmediately = 1;
        $moduleinfo->marksimmediately = 1;
        $moduleinfo->specificfeedbackimmediately = 1;
        $moduleinfo->generalfeedbackimmediately = 1;
        $moduleinfo->rightanswerimmediately = 1;
        $moduleinfo->overallfeedbackimmediately = 1;
        $moduleinfo->attemptopen = 1;
        $moduleinfo->correctnessopen = 1;
        $moduleinfo->maxmarksopen = 1;
        $moduleinfo->marksopen = 1;
        $moduleinfo->specificfeedbackopen = 1;
        $moduleinfo->generalfeedbackopen = 1;
        $moduleinfo->rightansweropen = 1;
        $moduleinfo->overallfeedbackopen = 1;
        $moduleinfo->attemptclosed = 1;
        $moduleinfo->correctnessclosed = 1;
        $moduleinfo->maxmarksclosed = 1;
        $moduleinfo->marksclosed = 1;
        $moduleinfo->specificfeedbackclosed = 1;
        $moduleinfo->generalfeedbackclosed = 1;
        $moduleinfo->rightanswerclosed = 1;
        $moduleinfo->overallfeedbackclosed = 1;
        $moduleinfo->questionsperpage = 1;
        $moduleinfo->shuffleanswers = 1;
        $moduleinfo->sumgrades = 0;
        $moduleinfo->grade = 100;
        $moduleinfo->timelimit = 0;
        $moduleinfo->overduehandling = 'autosubmit';
        $moduleinfo->graceperiod = 86400;
        $moduleinfo->quizpassword = '';
        $moduleinfo->subnet = '';
        $moduleinfo->browsersecurity = '';
        $moduleinfo->delay1 = 0;
        $moduleinfo->delay2 = 0;
        $moduleinfo->showuserpicture = 0;
        $moduleinfo->showblocks = 0;
        $moduleinfo->navmethod = QUIZ_NAVMETHOD_FREE;

        $mod = add_moduleinfo($moduleinfo, $course);

        return get_fast_modinfo($course)->get_cm($mod->coursemodule);
    }

    /**
     * Create a fresh question-bank category for this quiz, under the course's shared
     * "system" question bank instance (auto-created if this is the course's first sync),
     * following the same pattern core's own question importer uses
     * (question/format.php: get_default_open_instance_system_type + question_get_top_category).
     *
     * @param context_module $qbankcontext
     * @param string $quizname
     * @return stdClass the new question_categories record
     */
    protected static function create_question_category(context_module $qbankcontext, string $quizname): stdClass {
        global $DB;

        $topcategory = question_get_top_category($qbankcontext->id, true);

        $basename = shorten_text(trim($quizname), 1300);
        $categoryname = $basename;
        $suffix = 1;
        while ($DB->record_exists('question_categories', [
            'contextid' => $qbankcontext->id,
            'parent' => $topcategory->id,
            'name' => $categoryname,
        ])) {
            $suffix++;
            $categoryname = $basename . ' (' . $suffix . ')';
        }

        $category = new stdClass();
        $category->name = $categoryname;
        $category->info = '';
        $category->infoformat = FORMAT_HTML;
        $category->contextid = $qbankcontext->id;
        $category->parent = $topcategory->id;
        $category->sortorder = 999;
        $category->stamp = make_unique_id_code();
        $category->id = $DB->insert_record('question_categories', $category);

        return $category;
    }

    /**
     * Save one single-answer multichoice question, mirroring the field shape used by
     * question/format/xml/format.php when importing multichoice questions.
     *
     * @param stdClass $category the question_categories record to file the question under
     * @param context_module $qbankcontext
     * @param array $questiondata one entry from the 'questions' parameter
     * @return stdClass the saved question record
     */
    protected static function save_multichoice_question(
        stdClass $category,
        context_module $qbankcontext,
        array $questiondata
    ): stdClass {
        $textformat = ['text' => '', 'format' => FORMAT_HTML];

        $form = new stdClass();
        $form->category = $category->id . ',' . $qbankcontext->id;
        $form->name = shorten_text(trim(strip_tags($questiondata['questiontext'])), 60);
        if ($form->name === '') {
            $form->name = '-';
        }
        $form->questiontext = ['text' => $questiondata['questiontext'], 'format' => FORMAT_HTML];
        $form->generalfeedback = $textformat;
        $form->defaultmark = 1;
        $form->penalty = 0.3333333;
        $form->single = 1;
        $form->shuffleanswers = 1;
        $form->answernumbering = 'abc';
        $form->showstandardinstruction = 0;
        $form->correctfeedback = $textformat;
        $form->partiallycorrectfeedback = $textformat;
        $form->incorrectfeedback = $textformat;

        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];
        foreach ($questiondata['choices'] as $i => $choice) {
            $form->answer[$i] = ['text' => $choice, 'format' => FORMAT_HTML];
            $form->fraction[$i] = ($i + 1) === $questiondata['correctanswer'] ? 1.0 : 0.0;
            $form->feedback[$i] = $textformat;
        }

        $question = new stdClass();
        $question->qtype = 'multichoice';

        return question_bank::get_qtype('multichoice')->save_question($question, $form);
    }

    /**
     * Define the webservice response.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'quizid' => new external_value(PARAM_INT, 'Id of the created quiz instance.'),
            'cmid' => new external_value(PARAM_INT, 'Id of the created course module.'),
            'courseid' => new external_value(PARAM_INT, 'Id of the course the quiz was created in.'),
            'questioncategoryid' => new external_value(PARAM_INT, 'Id of the question-bank category the questions were filed under.'),
            'questions' => new external_multiple_structure(
                new external_single_structure([
                    'index' => new external_value(PARAM_INT, '0-based position in the request questions array.'),
                    'questionid' => new external_value(PARAM_INT, 'Id of the created question.'),
                    'slot' => new external_value(PARAM_INT, 'Slot number within the quiz.'),
                ])
            ),
        ]);
    }
}
