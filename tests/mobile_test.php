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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_icontent;

use mod_icontent\output\mobile;
use mod_icontent\local\assignment;

/**
 * App reader discovery, content and access boundary tests.
 *
 * @package mod_icontent
 * @copyright 2026 AL Rachels
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_icontent\output\mobile
 */
final class mobile_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create an assignment and two enrolled students.
     *
     * @param array $options Module options.
     * @return array
     */
    private function create_activity(array $options = []): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        foreach ([$one, $two] as $student) {
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        }
        $module = $this->getDataGenerator()->create_module('icontent', array_merge([
            'course' => $course->id, 'assignmentmode' => 1,
        ], $options));
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $this->setUser($one);
        return [$course, $icontent, $cm, $one, $two];
    }

    /**
     * Create a page in a selected page set.
     *
     * @param \stdClass $icontent Activity.
     * @param \stdClass $cm Module.
     * @param array $options Page values.
     * @return int
     */
    private function create_page(\stdClass $icontent, \stdClass $cm, array $options = []): int {
        global $DB;
        return $DB->insert_record('icontent_pages', (object)array_merge([
            'icontentid' => $icontent->id, 'cmid' => $cm->id, 'submissionid' => 0,
            'pagenum' => 1, 'title' => 'Reference page', 'pageicontent' => '<p>Reference text</p>',
            'pageicontentformat' => FORMAT_HTML, 'showtitle' => 1, 'hidden' => 0,
        ], $options));
    }

    /**
     * Add a real versioned question to a test page.
     *
     * @param \stdClass $cm Module.
     * @param int $pageid Page.
     * @param string $type Question type.
     * @param array $overrides Question settings.
     * @return \stdClass
     */
    private function add_question(\stdClass $cm, int $pageid, string $type = 'multichoice', array $overrides = []): \stdClass {
        global $USER, $CFG;
        require_once($CFG->dirroot . '/mod/icontent/locallib.php');
        $user = clone $USER;
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question($type, null, ['category' => $category->id] + $overrides);
        \icontent_add_questionpage([$question->id], $pageid, $cm->id);
        $this->setUser($user);
        return $question;
    }

    /**
     * Moodle's real mobile discovery and content APIs expose the online reader.
     */
    public function test_mobile_api_discovery_and_content(): void {
        global $CFG, $DB;
        [$course, $icontent, $cm, $one] = $this->create_activity(['shownotesarea' => 1]);
        $pageid = $this->create_page($icontent, $cm);
        $this->add_question($cm, $pageid);
        require_once($CFG->dirroot . '/admin/tool/mobile/classes/external.php');
        $plugins = \tool_mobile\api::get_plugins_supporting_mobile();
        $plugin = array_values(array_filter($plugins, static fn($plugin) => $plugin['component'] === 'mod_icontent'));
        $this->assertCount(1, $plugin);
        $handlers = json_decode($plugin[0]['handlers'], true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('CoreCourseModuleDelegate', $handlers['icontent']['delegate']);
        $this->assertSame([], $handlers['icontent']['offlinefunctions']);
        $result = \tool_mobile\external::get_content('mod_icontent', 'mobile_view', [
            ['name' => 'cmid', 'value' => (string)$cm->id],
            ['name' => 'courseid', 'value' => (string)$course->id],
            ['name' => 'userid', 'value' => (string)$one->id],
        ]);
        $this->assertSame('main', $result['templates'][0]['id']);
        $this->assertStringContainsString('Reference text', $result['otherdata'][0]['value']);
        $this->assertStringContainsString('core-format-text', $result['templates'][0]['html']);
        $this->assertStringContainsString('navigateNativePage', $result['templates'][0]['html']);
        $this->assertStringContainsString(get_string('mobilereadernotice', 'icontent'), $result['templates'][0]['html']);
        $this->assertStringContainsString('core-question', $result['templates'][0]['html']);
        $this->assertStringContainsString('saveNativeNote()', $result['templates'][0]['html']);
        $this->assertStringContainsString('CoreQuestionProvider.parseQuestions', $result['javascript']);
        $this->assertSame([], $result['files']);
    }

    /**
     * Slide order, embedded file URLs, cleaned text and logging are preserved.
     */
    public function test_reader_content_and_logging(): void {
        global $DB;
        [, $icontent, $cm] = $this->create_activity();
        $secondid = $this->create_page($icontent, $cm, [
            'pagenum' => 2, 'title' => 'Second page',
            'pageicontent' => '<p>Editable text</p><img src="@@PLUGINFILE@@/image.png">' .
                '<script>alert("not for the app")</script>',
        ]);
        $this->create_page($icontent, $cm, ['title' => 'First page']);
        $this->create_page($icontent, $cm, ['pagenum' => 3, 'title' => 'Hidden page', 'hidden' => 1]);
        $sink = $this->redirectEvents();
        $result = mobile::mobile_view(['cmid' => $cm->id, 'pageid' => $secondid]);
        $html = $result['templates'][0]['html'];
        $this->assertLessThan(strpos($html, 'Second page</ion-label>'), strpos($html, 'First page</ion-label>'));
        $this->assertStringNotContainsString('Hidden page', $html);
        $this->assertStringContainsString('webservice/pluginfile.php', $result['otherdata']['content']);
        $this->assertStringNotContainsString('@@PLUGINFILE@@', $result['otherdata']['content']);
        $this->assertStringNotContainsString('<script', $result['otherdata']['content']);
        $events = $sink->get_events();
        $this->assertSame('\mod_icontent\event\page_viewed', $events[0]->eventname);
        $this->assertSame($secondid, (int)$events[0]->objectid);
        $this->assertSame('\mod_icontent\event\course_module_viewed', $events[1]->eventname);
        $this->assertSame(1, $DB->count_records('icontent_pages_displayed'));
    }

    /**
     * Interactive sections stay discoverable whether browser headings start open or closed.
     */
    public function test_native_interactions_preserve_page_set_and_expansion_settings(): void {
        global $DB;

        [, $icontent, $cm, $one] = $this->create_activity(['shownotesarea' => 1]);
        $submission = assignment::get_current_submission($icontent, $cm, (int)$one->id);
        $pageid = $this->create_page($icontent, $cm, ['submissionid' => $submission->id]);
        $this->add_question($cm, $pageid);
        foreach ([0, 1] as $expanded) {
            $DB->set_field('icontent_pages', 'expandquestionsarea', $expanded, ['id' => $pageid]);
            $DB->set_field('icontent_pages', 'expandnotesarea', $expanded, ['id' => $pageid]);
            $result = mobile::mobile_view([
                'cmid' => $cm->id, 'submissionid' => $submission->id, 'pageid' => $pageid,
            ]);
            $html = $result['templates'][0]['html'];
            $this->assertStringContainsString(get_string('answerthequestions', 'icontent'), $html);
            $this->assertStringContainsString(get_string('doubtandnotes', 'icontent'), $html);
            $this->assertStringContainsString('core-question', $html);
            $this->assertStringContainsString('saveNativeNote()', $html);
            $this->assertSame((string)$expanded, $result['otherdata']['questionsexpanded']);
            $this->assertSame((string)$expanded, $result['otherdata']['notesexpanded']);
            $args = json_decode($result['otherdata']['args'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame((int)$submission->id, $args['submissionid']);
            $this->assertSame($pageid, $args['pageid']);
        }
    }

    /**
     * Notes visibility follows the browser's activity setting and capability checks.
     */
    public function test_notes_handoff_requires_setting_and_permission(): void {
        global $DB;

        [, $icontent, $cm, $one] = $this->create_activity(['shownotesarea' => 0]);
        $pageid = $this->create_page($icontent, $cm);
        $args = ['cmid' => $cm->id, 'pageid' => $pageid];
        $result = mobile::mobile_view($args);
        $this->assertStringNotContainsString(get_string('doubtandnotes', 'icontent'), $result['templates'][0]['html']);
        $this->assertStringNotContainsString(get_string('answerthequestions', 'icontent'), $result['templates'][0]['html']);
        $DB->set_field('icontent', 'shownotesarea', 1, ['id' => $icontent->id]);
        $result = mobile::mobile_view($args);
        $this->assertStringContainsString(get_string('doubtandnotes', 'icontent'), $result['templates'][0]['html']);
        $context = \context_module::instance($cm->id);
        $roles = get_user_roles($context, $one->id);
        foreach ($roles as $role) {
            assign_capability('mod/icontent:viewnotes', CAP_PROHIBIT, $role->roleid, $context->id);
        }
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($one);
        $result = mobile::mobile_view($args);
        $this->assertStringNotContainsString(get_string('doubtandnotes', 'icontent'), $result['templates'][0]['html']);
    }

    /**
     * Native engine submissions persist, grade correctly and unlock navigation.
     */
    public function test_native_question_submission_grading_replay_and_navigation(): void {
        global $DB;

        [, $icontent, $cm, $one, $two] = $this->create_activity(['grade' => 100]);
        $pageid = $this->create_page($icontent, $cm, ['expandquestionsarea' => 1]);
        $destinationid = $this->create_page($icontent, $cm, ['pagenum' => 2, 'title' => 'Next page']);
        $question = $this->add_question($cm, $pageid, 'multichoice', ['defaultmark' => 3]);
        $mapping = $DB->get_record('icontent_pages_questions', ['pageid' => $pageid], '*', MUST_EXIST);
        $DB->update_record('icontent_pages_questions', (object)[
            'id' => $mapping->id, 'minimumscore' => 100, 'minimumscorenextpageid' => $destinationid,
        ]);
        $args = ['cmid' => $cm->id, 'pageid' => $pageid];
        $result = mobile::mobile_view($args);
        $usage = $DB->get_record('icontent_mobile_usages', ['pageid' => $pageid, 'userid' => $one->id], '*', MUST_EXIST);
        $quba = \question_engine::load_questions_usage_by_activity($usage->qubaid);
        $this->assertEquals(3.0, $quba->get_question_max_mark(1));
        $rendered = json_decode($result['otherdata']['questions'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('sequencecheck', $rendered[0]['html']);
        mobile::mobile_view($args);
        $this->assertSame(1, $DB->count_records('icontent_mobile_usages', ['pageid' => $pageid, 'userid' => $one->id]));
        try {
            mobile::mobile_view(['cmid' => $cm->id, 'pageid' => $destinationid]);
            $this->fail('The unanswered grade gate must block app navigation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pagegradingrequired', $e->errorcode);
        }
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $answers = $generator->get_simulated_post_data_for_questions_in_usage($quba, [1 => 'One; Three'], false);
        $payload = json_encode(['usageid' => (int)$usage->qubaid, 'answers' => $answers], JSON_THROW_ON_ERROR);
        $this->setUser($two);
        try {
            mobile::mobile_view($args + ['action' => 'submit', 'payload' => $payload]);
            $this->fail('Another student must not submit this usage.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }
        $this->setUser($one);
        $sink = $this->redirectEvents();
        $review = mobile::mobile_view($args + ['action' => 'submit', 'payload' => $payload]);
        $attempt = $DB->get_record('icontent_question_attempts', ['pagesquestionsid' => $mapping->id], '*', MUST_EXIST);
        $this->assertEquals(3.0, $attempt->fraction);
        $this->assertEquals(3.0, $attempt->maxmark);
        $this->assertEquals($question->id, $attempt->questionid);
        $this->assertEquals(100.0, $DB->get_field('icontent_grades', 'grade', ['userid' => $one->id, 'cmid' => $cm->id]));
        $this->assertStringContainsString('[review]="true"', $review['templates'][0]['html']);
        $this->assertContains('\mod_icontent\event\question_attempt_created', array_column($sink->get_events(), 'eventname'));
        try {
            mobile::mobile_view($args + ['action' => 'submit', 'payload' => $payload]);
            $this->fail('A duplicate submission must not add marks twice.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame(1, $DB->count_records('icontent_question_attempts', ['pagesquestionsid' => $mapping->id]));
        }
        $next = mobile::mobile_view(['cmid' => $cm->id, 'pageid' => $destinationid]);
        $this->assertStringContainsString('Next page', $next['templates'][0]['html']);
    }

    /**
     * Empty/stale submissions never finalize the usage or save partial grades.
     */
    public function test_native_question_validation_and_essay_manual_review(): void {
        global $DB;

        [, $icontent, $cm, $one] = $this->create_activity();
        $pageid = $this->create_page($icontent, $cm);
        $this->add_question($cm, $pageid, 'essay', ['attachments' => 0, 'responseformat' => 'editor']);
        $args = ['cmid' => $cm->id, 'pageid' => $pageid];
        mobile::mobile_view($args);
        $usage = $DB->get_record('icontent_mobile_usages', ['pageid' => $pageid, 'userid' => $one->id], '*', MUST_EXIST);
        $quba = \question_engine::load_questions_usage_by_activity($usage->qubaid);
        $qa = $quba->get_question_attempt(1);
        foreach ([[], [$qa->get_control_field_name('sequencecheck') => $qa->get_sequence_check_count()]] as $answers) {
            try {
                mobile::mobile_view($args + ['action' => 'submit',
                    'payload' => json_encode(['usageid' => $usage->qubaid, 'answers' => $answers], JSON_THROW_ON_ERROR)]);
                $this->fail('An empty essay must not be recorded.');
            } catch (\invalid_parameter_exception $e) {
                $this->assertSame(0, $DB->count_records('icontent_question_attempts', ['cmid' => $cm->id]));
                $this->assertEquals(0, $DB->get_field('icontent_mobile_usages', 'timecompleted', ['id' => $usage->id]));
            }
        }
        $answers = [
            $qa->get_control_field_name('sequencecheck') => $qa->get_sequence_check_count(),
            $qa->get_qt_field_name('answer') => '<p>My essay answer</p>',
            $qa->get_qt_field_name('answerformat') => FORMAT_HTML,
        ];
        mobile::mobile_view($args + ['action' => 'submit',
            'payload' => json_encode(['usageid' => $usage->qubaid, 'answers' => $answers], JSON_THROW_ON_ERROR)]);
        $attempt = $DB->get_record('icontent_question_attempts', ['cmid' => $cm->id], '*', MUST_EXIST);
        $this->assertSame(ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE, $attempt->rightanswer);
        $this->assertSame('<p>My essay answer</p>', $attempt->answertext);
        $this->assertEquals(FORMAT_HTML, $attempt->answertextformat);
    }

    /**
     * Native notes support both tabs, ownership checks, replies, likes and deletion.
     */
    public function test_native_notes_actions_and_visibility(): void {
        global $DB;

        [, $icontent, $cm, $one, $two] = $this->create_activity(['shownotesarea' => 1]);
        $pageid = $this->create_page($icontent, $cm);
        $args = ['cmid' => $cm->id, 'pageid' => $pageid];
        $result = mobile::mobile_view($args + ['action' => 'addnote',
            'payload' => json_encode(['comment' => 'Public note', 'tab' => 'note'], JSON_THROW_ON_ERROR)]);
        $notes = json_decode($result['otherdata']['notes'], true, 512, JSON_THROW_ON_ERROR);
        $publicid = $notes[0]['id'];
        $this->assertSame('Public note', strip_tags($notes[0]['text']));
        $this->assertTrue($notes[0]['canedit']);
        mobile::mobile_view($args + ['action' => 'addnote',
            'payload' => json_encode(['comment' => 'Private question', 'tab' => 'doubt', 'private' => true], JSON_THROW_ON_ERROR)]);
        $privateid = $DB->get_field('icontent_pages_notes', 'id', ['pageid' => $pageid, 'private' => 1], MUST_EXIST);
        $this->setUser($two);
        $result = mobile::mobile_view($args);
        $notes = json_decode($result['otherdata']['notes'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $notes);
        $this->assertFalse($notes[0]['canedit']);
        foreach (['editnote', 'replynote', 'deletenote', 'likenote'] as $action) {
            try {
                mobile::mobile_view($args + ['action' => $action,
                    'payload' => json_encode(['noteid' => $privateid, 'comment' => 'Hidden target'], JSON_THROW_ON_ERROR)]);
                $this->fail('Hidden notes must not be mutated by guessing their IDs.');
            } catch (\required_capability_exception $e) {
                $this->assertTrue($DB->record_exists('icontent_pages_notes', ['id' => $privateid]));
            }
        }
        try {
            mobile::mobile_view($args + ['action' => 'editnote',
                'payload' => json_encode(['noteid' => $publicid, 'comment' => 'Unauthorized edit'], JSON_THROW_ON_ERROR)]);
            $this->fail('Students must not edit another student\'s notes.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('Public note', $DB->get_field('icontent_pages_notes', 'comment', ['id' => $publicid]));
        }
        mobile::mobile_view($args + ['action' => 'replynote',
            'payload' => json_encode(['noteid' => $publicid, 'comment' => 'My reply'], JSON_THROW_ON_ERROR)]);
        $replyid = $DB->get_field('icontent_pages_notes', 'id', ['parent' => $publicid]);
        mobile::mobile_view($args + ['action' => 'likenote',
            'payload' => json_encode(['noteid' => $publicid], JSON_THROW_ON_ERROR)]);
        $this->assertSame(1, $DB->count_records('icontent_pages_notes_like', ['pagenoteid' => $publicid]));
        mobile::mobile_view($args + ['action' => 'likenote',
            'payload' => json_encode(['noteid' => $publicid], JSON_THROW_ON_ERROR)]);
        $this->assertSame(0, $DB->count_records('icontent_pages_notes_like', ['pagenoteid' => $publicid]));
        $this->setUser($one);
        mobile::mobile_view($args + ['action' => 'editnote',
            'payload' => json_encode(['noteid' => $publicid, 'comment' => 'Edited note'], JSON_THROW_ON_ERROR)]);
        $this->assertSame('Edited note', $DB->get_field('icontent_pages_notes', 'comment', ['id' => $publicid]));
        mobile::mobile_view($args + ['action' => 'deletenote',
            'payload' => json_encode(['noteid' => $publicid], JSON_THROW_ON_ERROR)]);
        $this->assertFalse($DB->record_exists('icontent_pages_notes', ['id' => $publicid]));
        $this->assertFalse($DB->record_exists('icontent_pages_notes', ['id' => $replyid]));
        $this->assertSame(1, $DB->count_records('icontent_pages_notes', ['pageid' => $pageid]));
    }

    /**
     * Every Questions mutation returns the refreshed lists and permitted tools for both tabs.
     */
    public function test_native_questions_tab_returns_entries_and_tools_after_mutations(): void {
        [, $icontent, $cm] = $this->create_activity(['shownotesarea' => 1]);
        $pageid = $this->create_page($icontent, $cm);
        $args = ['cmid' => $cm->id, 'pageid' => $pageid];
        foreach (['note', 'doubt'] as $tab) {
            $result = mobile::mobile_view($args + ['action' => 'addnote',
                'payload' => json_encode(['tab' => $tab, 'comment' => 'Entry in ' . $tab], JSON_THROW_ON_ERROR)]);
        }
        $notes = json_decode($result['otherdata']['notes'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['note', 'doubt'], array_column($notes, 'tab'));
        $questionid = $notes[1]['id'];
        foreach ($notes as $note) {
            foreach (['canedit', 'canreply', 'canremove', 'canlike'] as $permission) {
                $this->assertTrue($note[$permission]);
            }
        }
        foreach (['editnote', 'replynote', 'likenote'] as $action) {
            $result = mobile::mobile_view($args + ['action' => $action,
                'payload' => json_encode(['noteid' => $questionid, 'comment' => 'Updated question'], JSON_THROW_ON_ERROR)]);
            $notes = json_decode($result['otherdata']['notes'], true, 512, JSON_THROW_ON_ERROR);
            $questions = array_values(array_filter($notes, static fn($note) => $note['tab'] === 'doubt'));
            $this->assertSame('note', $notes[0]['tab']);
            $this->assertSame('Updated question', $questions[0]['edittext']);
            $this->assertTrue($questions[0]['canedit']);
            $this->assertTrue($questions[0]['canreply']);
            if ($action === 'replynote') {
                $this->assertCount(2, $questions);
                $this->assertSame($questionid, $questions[1]['parent']);
                $this->assertTrue($questions[1]['canedit']);
            } else if ($action === 'likenote') {
                $this->assertTrue($questions[0]['liked']);
                $this->assertSame(1, $questions[0]['likes']);
            }
        }
        $result = mobile::mobile_view($args + ['action' => 'deletenote',
            'payload' => json_encode(['noteid' => $questionid], JSON_THROW_ON_ERROR)]);
        $notes = json_decode($result['otherdata']['notes'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $notes);
        $this->assertSame('note', $notes[0]['tab']);
    }

    /**
     * Built-in text, choice, numeric, matching and cloze questions use native engine fields.
     */
    public function test_native_core_question_types(): void {
        global $DB;

        [, $icontent, $cm, $one] = $this->create_activity();
        foreach (['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'multianswer',
                'calculated', 'calculatedmulti', 'ddwtos'] as $index => $type) {
            $pageid = $this->create_page($icontent, $cm, ['pagenum' => $index + 1]);
            $question = $this->add_question($cm, $pageid, $type);
            if (str_starts_with($type, 'calculated')) {
                foreach ($DB->get_records('question_datasets', ['question' => $question->id]) as $dataset) {
                    $DB->set_field('question_dataset_definitions', 'itemcount', 1, ['id' => $dataset->datasetdefinition]);
                    if (!$DB->record_exists('question_dataset_items', [
                        'definition' => $dataset->datasetdefinition, 'itemnumber' => 1,
                    ])) {
                        $DB->insert_record('question_dataset_items', (object)[
                            'definition' => $dataset->datasetdefinition, 'itemnumber' => 1, 'value' => 3,
                        ]);
                    }
                }
            }
            $args = ['cmid' => $cm->id, 'pageid' => $pageid];
            $result = mobile::mobile_view($args);
            $usage = $DB->get_record('icontent_mobile_usages', ['pageid' => $pageid, 'userid' => $one->id], '*', MUST_EXIST);
            $quba = \question_engine::load_questions_usage_by_activity($usage->qubaid);
            $qa = $quba->get_question_attempt(1);
            $answers = [$qa->get_control_field_name('sequencecheck') => $qa->get_sequence_check_count()];
            foreach ($qa->get_question()->get_correct_response() as $name => $value) {
                $answers[$qa->get_qt_field_name($name)] = $value;
            }
            $questions = json_decode($result['otherdata']['questions'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($type, $questions[0]['type']);
            mobile::mobile_view($args + ['action' => 'submit',
                'payload' => json_encode(['usageid' => $usage->qubaid, 'answers' => $answers], JSON_THROW_ON_ERROR)]);
            $mappingid = $DB->get_field('icontent_pages_questions', 'id', ['pageid' => $pageid]);
            $attempt = $DB->get_record('icontent_question_attempts', ['pagesquestionsid' => $mappingid], '*', MUST_EXIST);
            $this->assertEquals($attempt->maxmark, $attempt->fraction, $type);
        }
    }

    /**
     * Retry removes only the current user's page answers and respects attempt limits.
     */
    public function test_native_retry_is_page_scoped_and_honours_permissions(): void {
        global $DB;

        [, $icontent, $cm, $one, $two] = $this->create_activity();
        $pageid = $this->create_page($icontent, $cm, ['attemptsallowed' => 1]);
        $otherpageid = $this->create_page($icontent, $cm, ['pagenum' => 2]);
        $question = $this->add_question($cm, $pageid);
        $other = $this->add_question($cm, $otherpageid);
        foreach ([[$pageid, $question->id, $one->id], [$pageid, $question->id, $two->id],
                [$otherpageid, $other->id, $one->id]] as [$id, $qid, $userid]) {
            $mappingid = $DB->get_field('icontent_pages_questions', 'id', ['pageid' => $id]);
            $DB->insert_record('icontent_question_attempts', (object)[
                'pagesquestionsid' => $mappingid, 'questionid' => $qid,
                'cmid' => $cm->id, 'userid' => $userid,
                'fraction' => 1, 'maxmark' => 1, 'rightanswer' => 'Correct', 'timecreated' => 100,
            ]);
        }
        $args = ['cmid' => $cm->id, 'pageid' => $pageid, 'action' => 'retry'];
        try {
            mobile::mobile_view($args);
            $this->fail('A fixed-attempt page must reject retry.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(3, $DB->count_records('icontent_question_attempts', ['cmid' => $cm->id]));
        }
        $DB->set_field('icontent_pages', 'attemptsallowed', 0, ['id' => $pageid]);
        mobile::mobile_view($args);
        $this->assertSame(2, $DB->count_records('icontent_question_attempts', ['cmid' => $cm->id]));
        $this->assertSame(1, $DB->count_records('icontent_question_attempts', ['cmid' => $cm->id, 'userid' => $two->id]));
        $this->assertTrue($DB->record_exists('icontent_question_attempts', [
            'pagesquestionsid' => $DB->get_field('icontent_pages_questions', 'id', ['pageid' => $otherpageid]),
        ]));
    }

    /**
     * Native drafts refresh their bank version while completed marks remain historical.
     */
    public function test_native_question_versions_refresh_pending_usage(): void {
        global $DB;

        [, $icontent, $cm, $one] = $this->create_activity();
        $pageid = $this->create_page($icontent, $cm);
        $question = $this->add_question($cm, $pageid, 'multichoice', ['defaultmark' => 4]);
        $args = ['cmid' => $cm->id, 'pageid' => $pageid];
        mobile::mobile_view($args);
        $oldid = $DB->get_field('icontent_mobile_usages', 'qubaid', ['pageid' => $pageid, 'userid' => $one->id]);
        $this->setAdminUser();
        $latest = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->update_question($question, null, ['defaultmark' => 3]);
        $this->setUser($one);
        $result = mobile::mobile_view($args);
        $usageid = $DB->get_field('icontent_mobile_usages', 'qubaid', ['pageid' => $pageid, 'userid' => $one->id]);
        $this->assertNotEquals($oldid, $usageid);
        $quba = \question_engine::load_questions_usage_by_activity($usageid);
        $this->assertEquals($latest->id, $quba->get_question(1)->id);
        $questions = json_decode($result['otherdata']['questions'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals(3.0, $questions[0]['maxmark']);
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $oldid]));
        $qa = $quba->get_question_attempt(1);
        $answers = [$qa->get_control_field_name('sequencecheck') => $qa->get_sequence_check_count()];
        foreach ($qa->get_question()->get_correct_response() as $name => $value) {
            $answers[$qa->get_qt_field_name($name)] = $value;
        }
        mobile::mobile_view($args + ['action' => 'submit',
            'payload' => json_encode(['usageid' => $usageid, 'answers' => $answers], JSON_THROW_ON_ERROR)]);
        $this->setAdminUser();
        $this->getDataGenerator()->get_plugin_generator('core_question')
            ->update_question($latest, null, ['defaultmark' => 2]);
        $this->setUser($one);
        $review = mobile::mobile_view($args);
        $questions = json_decode($review['otherdata']['questions'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals(3.0, $questions[0]['maxmark']);
        $this->assertEquals($usageid,
            $DB->get_field('icontent_mobile_usages', 'qubaid', ['pageid' => $pageid, 'userid' => $one->id]));
    }

    /**
     * File-upload essays require an explicit fallback, never a partial page grade.
     */
    public function test_native_file_upload_fallback(): void {
        global $DB;
        [, $icontent, $cm, $one] = $this->create_activity();
        $pageid = $this->create_page($icontent, $cm);
        $this->add_question($cm, $pageid);
        $this->add_question($cm, $pageid, 'essay', ['attachments' => 1]);
        $args = ['cmid' => $cm->id, 'pageid' => $pageid];
        $result = mobile::mobile_view($args);
        $this->assertSame('0', $result['otherdata']['editablequestions']);
        $this->assertStringContainsString(get_string('mobilenativeunsupported', 'icontent'), $result['templates'][0]['html']);
        $this->assertStringContainsString(s(get_string('mobileopenquestions', 'icontent')), $result['templates'][0]['html']);
        $usageid = $DB->get_field('icontent_mobile_usages', 'qubaid', ['pageid' => $pageid, 'userid' => $one->id]);
        try {
            mobile::mobile_view($args + ['action' => 'submit',
                'payload' => json_encode(['usageid' => $usageid, 'answers' => []], JSON_THROW_ON_ERROR)]);
            $this->fail('An unsupported question must prevent partial grading.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame(0, $DB->count_records('icontent_question_attempts', ['cmid' => $cm->id]));
        }
    }

    /**
     * Native embedded files obey ownership, page access and deferred-feedback rules.
     */
    public function test_native_question_media_access(): void {
        global $DB;
        [, $icontent, $cm, $one, $two] = $this->create_activity();
        $pageid = $this->create_page($icontent, $cm);
        $this->add_question($cm, $pageid);
        $args = ['cmid' => $cm->id, 'pageid' => $pageid];
        mobile::mobile_view($args);
        $usageid = (int)$DB->get_field('icontent_mobile_usages', 'qubaid', ['pageid' => $pageid, 'userid' => $one->id]);
        $quba = \question_engine::load_questions_usage_by_activity($usageid);
        $question = $quba->get_question(1);
        $path = [$question->id, 'image.png'];
        $access = static function(string $area) use ($usageid, $question, $path): bool {
            return \mod_icontent\local\mobile_questions::can_access_file(
                $usageid, 1, (int)$question->contextid, 'question', $area, $path, false
            );
        };
        $this->assertTrue($access('questiontext'));
        $this->assertFalse($access('generalfeedback'));
        $this->setUser($two);
        $this->assertFalse($access('questiontext'));
        $this->setUser($one);
        $DB->set_field('icontent_pages', 'hidden', 1, ['id' => $pageid]);
        $this->assertFalse($access('questiontext'));
        $DB->set_field('icontent_pages', 'hidden', 0, ['id' => $pageid]);
        $qa = $quba->get_question_attempt(1);
        $answers = [$qa->get_control_field_name('sequencecheck') => $qa->get_sequence_check_count()];
        foreach ($question->get_correct_response() as $name => $value) {
            $answers[$qa->get_qt_field_name($name)] = $value;
        }
        mobile::mobile_view($args + ['action' => 'submit',
            'payload' => json_encode(['usageid' => $usageid, 'answers' => $answers], JSON_THROW_ON_ERROR)]);
        $this->assertTrue($access('generalfeedback'));
        $DB->set_field('icontent', 'timeclose', time() - 60, ['id' => $icontent->id]);
        $this->assertFalse($access('questiontext'));
    }

    /**
     * The real app endpoint accepts mutations and returns refreshed native data.
     */
    public function test_native_notes_external_api_and_validation(): void {
        global $CFG, $DB;
        [, $icontent, $cm] = $this->create_activity(['shownotesarea' => 1]);
        $pageid = $this->create_page($icontent, $cm);
        require_once($CFG->dirroot . '/admin/tool/mobile/classes/external.php');
        $result = \tool_mobile\external::get_content('mod_icontent', 'mobile_view', [
            ['name' => 'cmid', 'value' => (string)$cm->id],
            ['name' => 'pageid', 'value' => (string)$pageid],
            ['name' => 'action', 'value' => 'addnote'],
            ['name' => 'payload', 'value' => json_encode(['tab' => 'doubt', 'comment' => 'App question'], JSON_THROW_ON_ERROR)],
        ]);
        $otherdata = array_column($result['otherdata'], 'value', 'name');
        $notes = json_decode($otherdata['notes'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('doubt', $notes[0]['tab']);
        $this->assertSame('App question', strip_tags($notes[0]['text']));
        foreach ([['comment' => ''], ['comment' => str_repeat('x', 1025)], ['comment' => 'Invalid tab', 'tab' => 'wrong']] as $payload) {
            try {
                mobile::mobile_view(['cmid' => $cm->id, 'pageid' => $pageid, 'action' => 'addnote',
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
                $this->fail('Invalid notes must be rejected.');
            } catch (\invalid_parameter_exception $e) {
                $this->assertSame(1, $DB->count_records('icontent_pages_notes', ['pageid' => $pageid]));
            }
        }
    }

    /**
     * Resetting one activity erases native usages without touching another activity.
     */
    public function test_native_usage_reset_is_activity_scoped(): void {
        global $DB, $USER;
        [, $icontent, $cm] = $this->create_activity();
        $pageid = $this->create_page($icontent, $cm);
        $this->add_question($cm, $pageid);
        mobile::mobile_view(['cmid' => $cm->id, 'pageid' => $pageid]);
        $usageid = $DB->get_field('icontent_mobile_usages', 'qubaid', ['pageid' => $pageid]);
        $other = $this->getDataGenerator()->create_module('icontent', ['course' => $cm->course]);
        $othercm = get_coursemodule_from_id('icontent', $other->cmid, 0, false, MUST_EXIST);
        $otherpageid = $this->create_page($other, $othercm);
        $this->add_question($othercm, $otherpageid);
        \mod_icontent\local\mobile_questions::prepare(
            $DB->get_record('icontent_pages', ['id' => $otherpageid], '*', MUST_EXIST), (int)$USER->id
        );
        $otherusageid = $DB->get_field('icontent_mobile_usages', 'qubaid', ['pageid' => $otherpageid]);
        $this->assertTrue(\icontent_reset_instance($icontent->id));
        $this->assertFalse($DB->record_exists('icontent_mobile_usages', ['cmid' => $cm->id]));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $usageid]));
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $otherusageid]));
        $this->assertTrue(\icontent_delete_instance($other->id));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $otherusageid]));
        $this->assertFalse($DB->record_exists('icontent_mobile_usages', ['cmid' => $othercm->id]));
    }

    /**
     * Drafts survive a new API request without grading, and stale sequences are rejected.
     */
    public function test_native_draft_persistence_and_sequence_validation(): void {
        global $DB;

        [, $icontent, $cm, $one] = $this->create_activity();
        $pageid = $this->create_page($icontent, $cm);
        $this->add_question($cm, $pageid, 'shortanswer');
        $args = ['cmid' => $cm->id, 'pageid' => $pageid];
        mobile::mobile_view($args);
        $usageid = $DB->get_field('icontent_mobile_usages', 'qubaid', ['pageid' => $pageid, 'userid' => $one->id]);
        $quba = \question_engine::load_questions_usage_by_activity($usageid);
        $qa = $quba->get_question_attempt(1);
        $answers = [
            $qa->get_control_field_name('sequencecheck') => $qa->get_sequence_check_count(),
            $qa->get_qt_field_name('answer') => 'My draft',
            $qa->get_control_field_name('finish') => 1,
        ];
        $payload = json_encode(['usageid' => $usageid, 'answers' => $answers], JSON_THROW_ON_ERROR);
        $sink = $this->redirectEvents();
        mobile::mobile_view($args + ['action' => 'save', 'payload' => $payload]);
        $this->assertNotContains('\mod_icontent\event\page_viewed', array_column($sink->get_events(), 'eventname'));
        $sink->close();
        $result = mobile::mobile_view($args);
        $quba = \question_engine::load_questions_usage_by_activity($usageid);
        $this->assertSame('My draft', $quba->get_question_attempt(1)->get_last_qt_var('answer'));
        $this->assertFalse($quba->get_question_attempt(1)->get_state()->is_finished());
        $this->assertSame(0, $DB->count_records('icontent_question_attempts', ['cmid' => $cm->id]));
        $questions = json_decode($result['otherdata']['questions'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('My draft', $questions[0]['html']);
        $this->expectException(\invalid_parameter_exception::class);
        mobile::mobile_view($args + ['action' => 'save', 'payload' => $payload]);
    }

    /**
     * Hidden pages cannot be retrieved by guessing their IDs.
     */
    public function test_hidden_page_rejected(): void {
        [, $icontent, $cm] = $this->create_activity();
        $id = $this->create_page($icontent, $cm, ['hidden' => 1]);
        $this->expectException(\required_capability_exception::class);
        mobile::mobile_view(['cmid' => $cm->id, 'pageid' => $id]);
    }

    /**
     * Group members share the reader; an outsider cannot request draft content.
     */
    public function test_group_reader_access(): void {
        global $DB;
        [$course, $icontent, $cm, $one, $two] = $this->create_activity(['teamsubmission' => 1]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $cm->id]);
        $cm->groupmode = SEPARATEGROUPS;
        $groupid = groups_create_group((object)['courseid' => $course->id, 'name' => 'Readers']);
        groups_add_member($groupid, $one->id);
        groups_add_member($groupid, $two->id);
        $submission = assignment::get_current_submission($icontent, $cm, (int)$one->id);
        $this->create_page($icontent, $cm, ['submissionid' => $submission->id, 'pageicontent' => '<p>Group draft</p>']);
        $this->setUser($two);
        $result = mobile::mobile_view(['cmid' => $cm->id, 'submissionid' => $submission->id]);
        $this->assertStringContainsString('Group draft', $result['otherdata']['content']);
        $this->assertStringContainsString(get_string('referencepages', 'icontent'), $result['templates'][0]['html']);
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($outsider->id, $course->id, 'student');
        $this->setUser($outsider);
        $this->expectException(\required_capability_exception::class);
        mobile::mobile_view(['cmid' => $cm->id, 'submissionid' => $submission->id]);
    }

    /**
     * Public submissions are readable after submission but become private again when reopened.
     */
    public function test_public_submission_and_reopened_draft(): void {
        global $DB;
        [, $icontent, $cm, $one, $two] = $this->create_activity(['publicsubmissions' => 1]);
        $submission = assignment::get_current_submission($icontent, $cm, (int)$one->id);
        $this->create_page($icontent, $cm, ['submissionid' => $submission->id, 'pageicontent' => '<p>Public work</p>']);
        $DB->set_field('icontent_submissions', 'status', assignment::STATUS_SUBMITTED, ['id' => $submission->id]);
        $this->setUser($two);
        $result = mobile::mobile_view(['cmid' => $cm->id, 'submissionid' => $submission->id]);
        $this->assertStringContainsString('Public work', $result['otherdata']['content']);
        $DB->set_field('icontent_submissions', 'status', assignment::STATUS_DRAFT, ['id' => $submission->id]);
        $this->expectException(\required_capability_exception::class);
        mobile::mobile_view(['cmid' => $cm->id, 'submissionid' => $submission->id]);
    }

    /**
     * References do not create empty submissions; existing own page sets have an app link.
     */
    public function test_reference_reader_is_read_only(): void {
        global $DB;
        [, $icontent, $cm, $one] = $this->create_activity();
        mobile::mobile_view(['cmid' => $cm->id]);
        $this->assertSame(0, $DB->count_records('icontent_submissions'));
        $submission = assignment::get_current_submission($icontent, $cm, (int)$one->id);
        $result = mobile::mobile_view(['cmid' => $cm->id]);
        $this->assertStringContainsString('submissionid: ' . $submission->id, $result['templates'][0]['html']);
        $this->assertStringContainsString(get_string('mobilenopages', 'icontent'), $result['templates'][0]['html']);
    }

    /**
     * Neither future opening times nor expired closing times disclose content.
     */
    public function test_activity_time_window(): void {
        global $DB;
        [, $icontent, $cm] = $this->create_activity();
        $DB->set_field('icontent', 'timeopen', time() + HOURSECS, ['id' => $icontent->id]);
        $this->create_page($icontent, $cm);
        $result = mobile::mobile_view(['cmid' => $cm->id]);
        $this->assertSame('', $result['otherdata']['content']);
        $this->assertStringContainsString(get_string('mobileactivityunavailable', 'icontent'), $result['templates'][0]['html']);
        $this->assertStringNotContainsString(get_string('mobileopenquestions', 'icontent'), $result['templates'][0]['html']);
        $this->assertStringNotContainsString(get_string('mobileopennotes', 'icontent'), $result['templates'][0]['html']);
        $DB->set_field('icontent', 'timeopen', 0, ['id' => $icontent->id]);
        $DB->set_field('icontent', 'timeclose', time() - HOURSECS, ['id' => $icontent->id]);
        $result = mobile::mobile_view(['cmid' => $cm->id]);
        $this->assertSame('', $result['otherdata']['content']);
    }

    /**
     * A page from another page set cannot be requested through the references reader.
     */
    public function test_cross_submission_page_rejected(): void {
        [, $icontent, $cm, $one] = $this->create_activity();
        $submission = assignment::get_current_submission($icontent, $cm, (int)$one->id);
        $id = $this->create_page($icontent, $cm, ['submissionid' => $submission->id]);
        $this->expectException(\required_capability_exception::class);
        mobile::mobile_view(['cmid' => $cm->id, 'pageid' => $id]);
    }

    /**
     * App-supplied user identifiers cannot impersonate another student.
     */
    public function test_user_argument_cannot_impersonate(): void {
        [, , $cm, , $two] = $this->create_activity();
        $this->expectException(\invalid_parameter_exception::class);
        mobile::mobile_view(['cmid' => $cm->id, 'userid' => $two->id]);
    }

    /**
     * A course ID inconsistent with the module is rejected.
     */
    public function test_course_argument_must_match(): void {
        [, , $cm] = $this->create_activity();
        $othercourse = $this->getDataGenerator()->create_course();
        $this->expectException(\invalid_parameter_exception::class);
        mobile::mobile_view(['cmid' => $cm->id, 'courseid' => $othercourse->id]);
    }

    /**
     * An unenrolled user cannot retrieve course content through the app endpoint.
     */
    public function test_unenrolled_user_rejected(): void {
        [, $icontent, $cm] = $this->create_activity();
        $this->create_page($icontent, $cm);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\require_login_exception::class);
        mobile::mobile_view(['cmid' => $cm->id]);
    }

    /**
     * App activity views satisfy the same view-completion rule as browser views.
     */
    public function test_view_completion(): void {
        set_config('enablecompletion', 1);
        [$course, $icontent, $cm, $one] = $this->create_activity([
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1,
        ]);
        $this->create_page($icontent, $cm);
        $completion = new \completion_info($course);
        $this->assertSame(COMPLETION_INCOMPLETE, (int)$completion->get_data($cm, false, $one->id)->completionstate);
        mobile::mobile_view(['cmid' => $cm->id]);
        $this->assertSame(COMPLETION_COMPLETE, (int)$completion->get_data($cm, false, $one->id)->completionstate);
    }

}
