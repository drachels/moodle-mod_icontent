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
 * Privacy provider tests.
 *
 * @package    mod_icontent
 * @category   test
 * @copyright  2026 AL Rachels <drachels@drachels.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_icontent\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\tests\provider_testcase;

/**
 * Testcases for mod_icontent privacy provider.
 *
 * @covers \mod_icontent\privacy\provider
 */
final class provider_test extends provider_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Ensure user contexts are discovered from iContent user-data tables.
     */
    public function test_get_contexts_for_userid(): void {
        [$cm1, $ctx1, $page1, $qmap1] = $this->create_icontent_page_and_questionmap();
        [$cm2, $ctx2, $page2, $qmap2] = $this->create_icontent_page_and_questionmap();

        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();

        $this->seed_user_data_for_cmid((int) $ctx1->instanceid, $page1, $qmap1, $u1->id);
        $this->seed_user_data_for_cmid((int) $ctx2->instanceid, $page2, $qmap2, $u2->id);

        $contextidsu1 = array_map('intval', provider::get_contexts_for_userid($u1->id)->get_contextids());
        $this->assertContains($ctx1->id, $contextidsu1);
        $this->assertNotContains($ctx2->id, $contextidsu1);
    }

    /**
     * Ensure deleting one user in a context removes only their iContent rows.
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        [$cm, $context, $pageid, $qmapid] = $this->create_icontent_page_and_questionmap();
        $cmid = (int) $context->instanceid;
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();

        $this->seed_user_data_for_cmid($cmid, $pageid, $qmapid, $u1->id);
        $this->seed_user_data_for_cmid($cmid, $pageid, $qmapid, $u2->id);

        $approved = new approved_contextlist($u1, 'mod_icontent', [$context->id]);
        provider::delete_data_for_user($approved);

        $this->assertSame(0, $DB->count_records('icontent_pages_notes', ['cmid' => $cmid, 'userid' => $u1->id]));
        $this->assertSame(1, $DB->count_records('icontent_pages_notes', ['cmid' => $cmid, 'userid' => $u2->id]));

        $this->assertSame(0, $DB->count_records('icontent_pages_notes_like', ['cmid' => $cmid, 'userid' => $u1->id]));
        $this->assertSame(1, $DB->count_records('icontent_pages_notes_like', ['cmid' => $cmid, 'userid' => $u2->id]));

        $this->assertSame(0, $DB->count_records('icontent_pages_displayed', ['cmid' => $cmid, 'userid' => $u1->id]));
        $this->assertSame(1, $DB->count_records('icontent_pages_displayed', ['cmid' => $cmid, 'userid' => $u2->id]));

        $this->assertSame(0, $DB->count_records('icontent_question_attempts', ['cmid' => $cmid, 'userid' => $u1->id]));
        $this->assertSame(1, $DB->count_records('icontent_question_attempts', ['cmid' => $cmid, 'userid' => $u2->id]));

        $this->assertSame(0, $DB->count_records('icontent_grades', ['cmid' => $cmid, 'userid' => $u1->id]));
        $this->assertSame(1, $DB->count_records('icontent_grades', ['cmid' => $cmid, 'userid' => $u2->id]));
    }

    /**
     * Ensure deleting all users in context clears all iContent user-data rows for that module.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        [$cm, $context, $pageid, $qmapid] = $this->create_icontent_page_and_questionmap();
        $cmid = (int) $context->instanceid;
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();

        $this->seed_user_data_for_cmid($cmid, $pageid, $qmapid, $u1->id);
        $this->seed_user_data_for_cmid($cmid, $pageid, $qmapid, $u2->id);

        provider::delete_data_for_all_users_in_context($context);

        $this->assertSame(0, $DB->count_records('icontent_pages_notes', ['cmid' => $cmid]));
        $this->assertSame(0, $DB->count_records('icontent_pages_notes_like', ['cmid' => $cmid]));
        $this->assertSame(0, $DB->count_records('icontent_pages_displayed', ['cmid' => $cmid]));
        $this->assertSame(0, $DB->count_records('icontent_question_attempts', ['cmid' => $cmid]));
        $this->assertSame(0, $DB->count_records('icontent_grades', ['cmid' => $cmid]));
    }

    /**
     * Ensure deleting selected users in context only removes selected users.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        [$cm, $context, $pageid, $qmapid] = $this->create_icontent_page_and_questionmap();
        $cmid = (int) $context->instanceid;
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();

        $this->seed_user_data_for_cmid($cmid, $pageid, $qmapid, $u1->id);
        $this->seed_user_data_for_cmid($cmid, $pageid, $qmapid, $u2->id);

        $approved = new approved_userlist($context, 'mod_icontent', [$u1->id]);
        provider::delete_data_for_users($approved);

        $this->assertSame(0, $DB->count_records('icontent_pages_notes', ['cmid' => $cmid, 'userid' => $u1->id]));
        $this->assertSame(1, $DB->count_records('icontent_pages_notes', ['cmid' => $cmid, 'userid' => $u2->id]));

        $this->assertSame(0, $DB->count_records('icontent_pages_notes_like', ['cmid' => $cmid, 'userid' => $u1->id]));
        $this->assertSame(1, $DB->count_records('icontent_pages_notes_like', ['cmid' => $cmid, 'userid' => $u2->id]));

        $this->assertSame(0, $DB->count_records('icontent_pages_displayed', ['cmid' => $cmid, 'userid' => $u1->id]));
        $this->assertSame(1, $DB->count_records('icontent_pages_displayed', ['cmid' => $cmid, 'userid' => $u2->id]));

        $this->assertSame(0, $DB->count_records('icontent_question_attempts', ['cmid' => $cmid, 'userid' => $u1->id]));
        $this->assertSame(1, $DB->count_records('icontent_question_attempts', ['cmid' => $cmid, 'userid' => $u2->id]));

        $this->assertSame(0, $DB->count_records('icontent_grades', ['cmid' => $cmid, 'userid' => $u1->id]));
        $this->assertSame(1, $DB->count_records('icontent_grades', ['cmid' => $cmid, 'userid' => $u2->id]));
    }

    /**
     * Erasing an individual assignment removes its page set but preserves reference pages.
     */
    public function test_delete_individual_assignment_submission(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('icontent', [
            'course' => $course->id,
            'assignmentmode' => 1,
        ]);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        $submissionid = (int)$DB->insert_record('icontent_submissions', (object)[
            'icontentid' => $module->id,
            'userid' => $user->id,
            'groupid' => 0,
            'status' => 'draft',
            'timesubmitted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'feedbackformat' => FORMAT_PLAIN,
            'grader' => 0,
            'timegraded' => 0,
        ]);
        $referencepageid = (int)$DB->insert_record('icontent_pages', (object)[
            'icontentid' => $module->id,
            'cmid' => $cm->id,
            'submissionid' => 0,
            'title' => 'Reference page',
            'pageicontent' => 'Reference content',
            'pageicontentformat' => FORMAT_HTML,
            'pagenum' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $submissionpageid = (int)$DB->insert_record('icontent_pages', (object)[
            'icontentid' => $module->id,
            'cmid' => $cm->id,
            'submissionid' => $submissionid,
            'title' => 'Student page',
            'pageicontent' => 'Student content',
            'pageicontentformat' => FORMAT_HTML,
            'pagenum' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $context = \context_module::instance($cm->id);
        $approved = new approved_contextlist($user, 'mod_icontent', [$context->id]);

        provider::delete_data_for_user($approved);

        $this->assertFalse($DB->record_exists('icontent_submissions', ['id' => $submissionid]));
        $this->assertFalse($DB->record_exists('icontent_pages', ['id' => $submissionpageid]));
        $this->assertTrue($DB->record_exists('icontent_pages', ['id' => $referencepageid]));
    }

    /**
     * Built-in rubric fillings are exported with a submission and removed with it.
     */
    public function test_rubric_fillings_follow_submission_privacy(): void {
        global $CFG, $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $module = $this->getDataGenerator()->create_module('icontent', [
            'course' => $course->id,
            'assignmentmode' => 1,
            'grade' => 100,
        ]);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $now = time();
        $submissionid = (int)$DB->insert_record('icontent_submissions', (object)[
            'icontentid' => $module->id,
            'userid' => $student->id,
            'groupid' => 0,
            'status' => 'graded',
            'timesubmitted' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'feedbackformat' => FORMAT_PLAIN,
            'grader' => $teacher->id,
            'timegraded' => $now,
        ]);
        $othersubmissionid = (int)$DB->insert_record('icontent_submissions', (object)[
            'icontentid' => $module->id,
            'userid' => $other->id,
            'groupid' => 0,
            'status' => 'graded',
            'timesubmitted' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'feedbackformat' => FORMAT_PLAIN,
            'grader' => $teacher->id,
            'timegraded' => $now,
        ]);

        $this->setUser($teacher);
        require_once($CFG->libdir . '/gradelib.php');
        $rubric = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')
            ->get_test_rubric($context, 'mod_icontent', 'submission');
        $rubric->set_grade_range(make_grades_menu(100), true);
        $studentinstance = null;
        foreach ([$submissionid, $othersubmissionid] as $itemid) {
            $instance = $rubric->get_or_create_instance(0, (int)$teacher->id, $itemid);
            if ($itemid === $submissionid) {
                $studentinstance = $instance;
            }
            $formdata = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')->get_test_form_data(
                $rubric,
                $itemid,
                2,
                'No mistakes',
                1,
                'One picture'
            );
            $instance->submit_and_get_grade($formdata, $itemid);
        }
        $this->assertSame(2, $DB->count_records('grading_instances'));

        $this->export_context_data_for_user((int)$student->id, $context, 'mod_icontent');
        $writer = \core_privacy\local\request\writer::with_context($context);
        $exported = $writer->get_data([
            get_string('gradingareasubmission', 'icontent'),
            $submissionid,
            get_string('rubric', 'gradingform_rubric'),
            $studentinstance->get_id(),
        ]);
        $this->assertNotEmpty($exported);
        $remarks = array_column((array)$exported, 'remark');
        $this->assertContains('No mistakes', $remarks);

        $fillings = $DB->get_records_sql(
            'SELECT rf.id, rf.remark
               FROM {gradingform_rubric_fillings} rf
               JOIN {grading_instances} gi ON gi.id = rf.instanceid
              WHERE gi.itemid = :itemid',
            ['itemid' => $submissionid]
        );
        $this->assertNotEmpty($fillings);

        provider::delete_data_for_user(new approved_contextlist($student, 'mod_icontent', [$context->id]));
        $this->assertSame(0, $DB->count_records_sql(
            'SELECT COUNT(1)
               FROM {grading_instances}
              WHERE itemid = :itemid',
            ['itemid' => $submissionid]
        ));
        $this->assertSame(1, $DB->count_records_sql(
            'SELECT COUNT(1)
               FROM {grading_instances}
              WHERE itemid = :itemid',
            ['itemid' => $othersubmissionid]
        ));

        provider::delete_data_for_all_users_in_context($context);
        $this->assertSame(0, $DB->count_records('grading_instances'));
    }

    /**
     * Deleting a user removes that user's page-rubric review only.
     */
    public function test_page_rubric_grades_follow_user_privacy(): void {
        global $DB;

        [$module, $context, $pageid] = $this->create_icontent_page_and_questionmap();
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        foreach ([$student, $other] as $user) {
            $DB->insert_record('icontent_page_rubric_grades', (object)[
                'pageid' => $pageid,
                'cmid' => $module->cmid,
                'userid' => $user->id,
                'status' => 'toevaluate',
                'maxmark' => 1,
                'feedbackformat' => FORMAT_PLAIN,
                'grader' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $approved = new approved_contextlist($student, 'mod_icontent', [$context->id]);
        provider::delete_data_for_user($approved);

        $this->assertSame(0, $DB->count_records('icontent_page_rubric_grades', ['userid' => $student->id]));
        $this->assertSame(1, $DB->count_records('icontent_page_rubric_grades', ['userid' => $other->id]));
    }

    /**
     * Native drafts are discoverable, exportable and erased with their question-engine data.
     */
    public function test_native_question_draft_privacy(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/icontent/locallib.php');
        [$module, $context, $pageid, $mappingid] = $this->create_icontent_page_and_questionmap();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('shortanswer', null, ['category' => $category->id]);
        $DB->update_record('icontent_pages_questions', (object)[
            'id' => $mappingid, 'questionid' => $question->id, 'maxmark' => 1,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        foreach ([$student, $other] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $cm->course, 'student');
            $this->setUser($user);
            \mod_icontent\output\mobile::mobile_view(['cmid' => $cm->id, 'pageid' => $pageid]);
        }
        $this->setUser($student);
        $usage = $DB->get_record('icontent_mobile_usages', ['pageid' => $pageid, 'userid' => $student->id], '*', MUST_EXIST);
        $quba = \question_engine::load_questions_usage_by_activity($usage->qubaid);
        $qa = $quba->get_question_attempt(1);
        \mod_icontent\output\mobile::mobile_view(['cmid' => $cm->id, 'pageid' => $pageid, 'action' => 'save',
            'payload' => json_encode(['usageid' => $usage->qubaid, 'answers' => [
                $qa->get_qt_field_name('answer') => 'Private draft answer',
                $qa->get_control_field_name('sequencecheck') => $qa->get_sequence_check_count(),
            ]], JSON_THROW_ON_ERROR)]);
        $DB->delete_records('icontent_pages_displayed', ['cmid' => $cm->id]);
        $saved = \question_engine::load_questions_usage_by_activity($usage->qubaid)->get_question_attempt(1);
        $this->assertSame('Private draft answer', $saved->get_last_qt_var('answer'));
        $this->assertContains((int)$context->id,
            array_map('intval', provider::get_contexts_for_userid($student->id)->get_contextids()));
        $this->export_context_data_for_user((int)$student->id, $context, 'mod_icontent');
        $exported = \core_privacy\local\request\writer::with_context($context)->get_data([
            'Mobile questions', $pageid, get_string('questions', 'core_question'), 1,
        ]);
        $steps = \core_privacy\local\request\writer::with_context($context)->get_related_data([
            'Mobile questions', $pageid, get_string('questions', 'core_question'), 1,
        ], 'steps');
        $this->assertStringContainsString('Private draft answer', json_encode($steps, JSON_THROW_ON_ERROR));
        $this->assertObjectNotHasProperty('generalfeedback', $exported);
        provider::delete_data_for_user(new approved_contextlist($student, 'mod_icontent', [$context->id]));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $usage->qubaid]));
        $this->assertFalse($DB->record_exists('icontent_mobile_usages', ['userid' => $student->id]));
        $this->assertSame(1, $DB->count_records('icontent_mobile_usages', ['userid' => $other->id]));
        provider::delete_data_for_all_users_in_context($context);
        $this->assertSame(0, $DB->count_records('icontent_mobile_usages', ['cmid' => $cm->id]));
    }

    /**
     * Create one iContent activity with one page and one page-question mapping.
     *
     * @return array
     */
    private function create_icontent_page_and_questionmap(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('icontent', ['course' => $course->id]);

        $time = time();
        $page = (object) [
            'icontentid' => $module->id,
            'cmid' => $module->cmid,
            'coverpage' => 0,
            'title' => 'Privacy test page',
            'showtitle' => 1,
            'pageicontent' => 'Privacy test content',
            'pageicontentformat' => FORMAT_HTML,
            'pagenum' => 1,
            'hidden' => 0,
            'timecreated' => $time,
            'timemodified' => $time,
        ];
        $pageid = (int) $DB->insert_record('icontent_pages', $page);

        $map = (object) [
            'pageid' => $pageid,
            'questionid' => 0,
            'cmid' => $module->cmid,
            'timecreated' => $time,
            'timemodified' => $time,
            'maxmark' => 0,
            'remake' => 0,
            'qtype' => 'essay',
        ];
        $qmapid = (int) $DB->insert_record('icontent_pages_questions', $map);

        return [
            $module,
            \context_module::instance($module->cmid),
            $pageid,
            $qmapid,
        ];
    }

    /**
     * Seed one user's data in all privacy-relevant iContent user tables.
     *
     * @param int $cmid
     * @param int $pageid
     * @param int $qmapid
     * @param int $userid
     */
    private function seed_user_data_for_cmid(int $cmid, int $pageid, int $qmapid, int $userid): void {
        global $DB;

        $icontentid = (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $time = time();
        $noteid = (int) $DB->insert_record('icontent_pages_notes', (object) [
            'pageid' => $pageid,
            'userid' => $userid,
            'cmid' => $cmid,
            'comment' => 'Privacy test note',
            'timecreated' => $time,
            'timemodified' => $time,
            'tab' => 'note',
            'path' => '0',
            'parent' => 0,
            'private' => 0,
            'featured' => 0,
            'doubttutor' => 0,
        ]);

        $DB->insert_record('icontent_pages_notes_like', (object) [
            'pagenoteid' => $noteid,
            'userid' => $userid,
            'cmid' => $cmid,
            'timemodified' => $time,
            'visible' => 1,
        ]);

        $DB->insert_record('icontent_pages_displayed', (object) [
            'pageid' => $pageid,
            'userid' => $userid,
            'cmid' => $cmid,
            'timecreated' => $time,
        ]);

        $DB->insert_record('icontent_question_attempts', (object) [
            'pagesquestionsid' => $qmapid,
            'questionid' => 0,
            'userid' => $userid,
            'cmid' => $cmid,
            'fraction' => 0,
            'rightanswer' => 'right',
            'answertext' => 'answer',
            'timecreated' => $time,
        ]);

        $DB->insert_record('icontent_grades', (object) [
            'icontentid' => $icontentid,
            'userid' => $userid,
            'cmid' => $cmid,
            'grade' => 0,
            'timemodified' => $time,
        ]);
    }
}
