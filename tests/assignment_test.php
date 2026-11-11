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
 * Assignment ownership and visibility tests.
 *
 * @package    mod_icontent
 * @category   test
 * @copyright  2026 AL Rachels
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_icontent;

use mod_icontent\local\assignment;

/**
 * Tests for assignment submissions.
 *
 * @covers \mod_icontent\local\assignment
 */
final class assignment_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Individual submissions remain isolated until public submissions are enabled.
     */
    public function test_individual_submission_visibility(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $studentone = $this->getDataGenerator()->create_user();
        $studenttwo = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studentone->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($studenttwo->id, $course->id, 'student');
        $module = $this->getDataGenerator()->create_module('icontent', [
            'course' => $course->id,
            'assignmentmode' => 1,
            'publicsubmissions' => 0,
        ]);
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $this->setUser($studentone);
        $one = assignment::get_current_submission($icontent, $cm, (int)$studentone->id);
        $this->setUser($studenttwo);
        $two = assignment::get_current_submission($icontent, $cm, (int)$studenttwo->id);

        $this->assertNotSame((int)$one->id, (int)$two->id);
        $this->setUser($studentone);
        $this->assertFalse(assignment::can_view_submission($two, $icontent, $cm, $context, (int)$studentone->id));

        $DB->set_field('icontent_submissions', 'status', assignment::STATUS_SUBMITTED, ['id' => $two->id]);
        $this->assertFalse(assignment::can_view_submission($two, $icontent, $cm, $context, (int)$studentone->id));
        $two->status = assignment::STATUS_SUBMITTED;
        $DB->set_field('icontent', 'publicsubmissions', 1, ['id' => $icontent->id]);
        $icontent->publicsubmissions = 1;
        $this->assertTrue(assignment::can_view_submission($two, $icontent, $cm, $context, (int)$studentone->id));
    }

    /**
     * Group members share one draft and outsiders cannot view it.
     */
    public function test_group_submission_is_shared_only_with_members(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $studentone = $this->getDataGenerator()->create_user();
        $studenttwo = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        foreach ([$studentone, $studenttwo, $outsider] as $student) {
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        }

        $module = $this->getDataGenerator()->create_module('icontent', [
            'course' => $course->id,
            'assignmentmode' => 1,
            'teamsubmission' => 1,
        ]);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', VISIBLEGROUPS, ['id' => $cm->id]);
        $cm->groupmode = VISIBLEGROUPS;
        $groupid = groups_create_group((object)['courseid' => $course->id, 'name' => 'Assignment group']);
        groups_add_member($groupid, $studentone->id);
        groups_add_member($groupid, $studenttwo->id);
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $this->setUser($studentone);
        $one = assignment::get_current_submission($icontent, $cm, (int)$studentone->id);
        $this->setUser($studenttwo);
        $two = assignment::get_current_submission($icontent, $cm, (int)$studenttwo->id);

        $this->assertSame((int)$one->id, (int)$two->id);
        $this->assertTrue(assignment::can_edit_submission($one, $context, (int)$studenttwo->id));
        $this->assertFalse(assignment::can_view_submission($one, $icontent, $cm, $context, (int)$outsider->id));
        $one->status = assignment::STATUS_SUBMITTED;
        $this->assertFalse(assignment::can_edit_submission($one, $context, (int)$studentone->id));
    }

    /**
     * Individual grades replace the shared grade without changing other members' grades.
     */
    public function test_individual_grade_overrides_reach_gradebook(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        foreach ([$one, $two] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }
        $module = $this->getDataGenerator()->create_module('icontent', [
            'course' => $course->id,
            'assignmentmode' => 1,
            'participationgrading' => 1,
        ]);
        $this->setAdminUser();
        $grades = assignment::build_member_grades(
            [(int)$one->id, (int)$two->id], '80', [$two->id => '25.5'], 100, false, 2
        );
        $this->assertSame(GRADE_UPDATE_OK, grade_update(
            'mod/icontent', $course->id, 'mod', 'icontent', $module->id, 0, $grades
        ));
        $item = \grade_item::fetch(['itemmodule' => 'icontent', 'iteminstance' => $module->id, 'itemnumber' => 0]);
        $this->assertEquals(80, $DB->get_field('grade_grades', 'rawgrade', ['itemid' => $item->id, 'userid' => $one->id]));
        $this->assertEquals(25.5, $DB->get_field('grade_grades', 'rawgrade', ['itemid' => $item->id, 'userid' => $two->id]));
        $grades = assignment::build_member_grades(
            [(int)$one->id, (int)$two->id], '90', [$two->id => '25.5'], 100, false, 2
        );
        $this->assertSame(GRADE_UPDATE_OK, grade_update(
            'mod/icontent', $course->id, 'mod', 'icontent', $module->id, 0, $grades
        ));
        $this->assertEquals(25.5, $DB->get_field('grade_grades', 'rawgrade', ['itemid' => $item->id, 'userid' => $two->id]));
        $gradeinfo = grade_get_grades($course->id, 'mod', 'icontent', $module->id, (int)$two->id);
        $this->assertEmpty($gradeinfo->errors);
        $this->assertEquals(25.5, $gradeinfo->items[0]->grades[$two->id]->grade);
    }

    /**
     * Only eligible members may receive individual grades.
     */
    public function test_individual_grade_rejects_outsiders(): void {
        $this->expectException(\invalid_parameter_exception::class);
        assignment::build_member_grades([10, 11], '80', [12 => '0'], 100, false, 2);
    }

    /**
     * Owner-specific extensions never shorten the activity deadline.
     */
    public function test_extended_due_date(): void {
        $activity = (object)['duedate' => 1000];
        $submission = (object)['extensionduedate' => 2000];
        $this->assertSame(2000, assignment::get_due_date($activity, $submission));
        $activity->duedate = 3000;
        $this->assertSame(3000, assignment::get_due_date($activity, $submission));
        $submission->extensionduedate = 0;
        $this->assertSame(3000, assignment::get_due_date($activity, $submission));
        $activity->duedate = 0;
        $this->assertSame(0, assignment::get_due_date($activity, $submission));
    }

    /**
     * Reopening restores private drafts for individuals and groups without removing grades.
     */
    public function test_reopen_preserves_grades_and_restores_editing(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $peer = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        foreach ([$student, $peer, $outsider] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }
        foreach ([0, 1] as $teamsubmission) {
            $module = $this->getDataGenerator()->create_module('icontent', [
                'course' => $course->id,
                'assignmentmode' => 1,
                'teamsubmission' => $teamsubmission,
                'publicsubmissions' => 1,
                'duedate' => time() + 1000,
            ]);
            $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
            $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
            if ($teamsubmission) {
                $cm->groupmode = VISIBLEGROUPS;
                $DB->set_field('course_modules', 'groupmode', VISIBLEGROUPS, ['id' => $cm->id]);
                $groupid = groups_create_group((object)['courseid' => $course->id, 'name' => 'Reopened group']);
                groups_add_member($groupid, $student->id);
                groups_add_member($groupid, $peer->id);
            }
            $context = \context_module::instance($cm->id);
            $this->setUser($student);
            $submission = assignment::get_current_submission($icontent, $cm, (int)$student->id);
            $submission->status = assignment::STATUS_GRADED;
            $submission->grade = 80;
            $submission->feedback = 'Retain this feedback';
            $submission->grader = 2;
            $submission->timegraded = time();
            $submission->timesubmitted = time();
            $DB->update_record('icontent_submissions', $submission);
            $this->assertFalse(assignment::can_edit_submission($submission, $context, (int)$student->id));
            $this->assertTrue(assignment::can_view_submission($submission, $icontent, $cm, $context, (int)$outsider->id));
            $this->setAdminUser();
            $grades = assignment::build_member_grades(
                [(int)$student->id, (int)$peer->id], '80', [$peer->id => '30'], 100, false, 2
            );
            $this->assertSame(GRADE_UPDATE_OK, grade_update(
                'mod/icontent', $course->id, 'mod', 'icontent', $module->id, 0, $grades
            ));
            $item = \grade_item::fetch(['itemmodule' => 'icontent', 'iteminstance' => $module->id, 'itemnumber' => 0]);
            $updated = assignment::manage_submission(
                (int)$submission->id, $icontent, $cm, $context, (int)$icontent->duedate + 1000, true
            );
            $this->assertSame(assignment::STATUS_DRAFT, $updated->status);
            $this->assertSame(0, $updated->timesubmitted);
            $this->assertEquals(80, $updated->grade);
            $this->assertSame('Retain this feedback', $updated->feedback);
            $this->assertSame((int)$icontent->duedate + 1000, assignment::get_due_date($icontent, $updated));
            $this->assertEquals(30, $DB->get_field('grade_grades', 'rawgrade', ['itemid' => $item->id, 'userid' => $peer->id]));
            $this->setUser($student);
            $this->assertTrue(assignment::can_edit_submission($updated, $context, (int)$student->id));
            $this->assertFalse(assignment::can_view_submission($updated, $icontent, $cm, $context, (int)$outsider->id));
            if ($teamsubmission) {
                $this->setUser($peer);
                $this->assertTrue(assignment::can_edit_submission($updated, $context, (int)$peer->id));
            }
            $this->setAdminUser();
            $updated = assignment::manage_submission((int)$submission->id, $icontent, $cm, $context, 0, false);
            $this->assertSame((int)$icontent->duedate, assignment::get_due_date($icontent, $updated));
        }
    }

    /**
     * Students cannot change submission status or grant themselves extensions.
     */
    public function test_students_cannot_manage_submissions(): void {
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('icontent', ['course' => $course->id, 'assignmentmode' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        assignment::manage_submission(1, $module, $cm, \context_module::instance($cm->id), 0, true);
    }

    /**
     * The teacher form exposes reopening for locked submissions and validates extensions.
     */
    public function test_submission_management_form(): void {
        $this->setAdminUser();
        $form = new \mod_icontent\form\submission_management(null, [
            'owner' => 'Test group',
            'duedate' => time() + 1000,
            'submission' => (object)['status' => assignment::STATUS_GRADED],
        ]);
        $html = $form->render();
        $this->assertStringContainsString('name="reopen"', $html);
        $this->assertStringContainsString('extensionduedate', $html);
        $errors = $form->validation(['extensionduedate' => time()], []);
        $this->assertArrayHasKey('extensionduedate', $errors);
        $this->assertEmpty($form->validation(['extensionduedate' => time() + 2000], []));
        $this->assertEmpty($form->validation(['extensionduedate' => 0], []));
    }

    /**
     * Extensions cannot shorten a deadline and cannot modify another activity's submission.
     */
    public function test_management_rejects_invalid_extensions_and_activity(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('icontent', [
            'course' => $course->id, 'assignmentmode' => 1, 'duedate' => time() + 1000,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $this->setUser($student);
        $submission = assignment::get_current_submission($icontent, $cm, (int)$student->id);
        $this->setAdminUser();
        $context = \context_module::instance($cm->id);
        try {
            assignment::manage_submission((int)$submission->id, $icontent, $cm, $context, (int)$icontent->duedate, false);
            $this->fail('An extension equal to the activity deadline was accepted');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertSame(0, (int)$DB->get_field('icontent_submissions', 'extensionduedate', ['id' => $submission->id]));
        }
        $otheractivity = clone $icontent;
        $otheractivity->id = -1;
        $this->expectException(\required_capability_exception::class);
        assignment::manage_submission((int)$submission->id, $otheractivity, $cm, $context, 0, false);
    }

    /**
     * Selected members must receive a valid grade, not an implicit zero.
     */
    public function test_individual_grade_rejects_blank_values(): void {
        $this->expectException(\invalid_parameter_exception::class);
        assignment::build_member_grades([10, 11], '80', [11 => ''], 100, false, 2);
    }

    /**
     * Zero and the maximum are allowed; grades above the maximum are rejected.
     */
    public function test_individual_grade_numeric_boundaries(): void {
        $grades = assignment::build_member_grades([10, 11], '100', [11 => '0'], 100, false, 2);
        $this->assertSame(100.0, $grades[10]['rawgrade']);
        $this->assertSame(0.0, $grades[11]['rawgrade']);
        $this->expectException(\invalid_parameter_exception::class);
        assignment::build_member_grades([10, 11], '100', [11 => '100.01'], 100, false, 2);
    }

    /**
     * Non-finite grades must not reach the gradebook.
     */
    public function test_individual_grade_rejects_nonfinite_value(): void {
        $this->expectException(\invalid_parameter_exception::class);
        assignment::build_member_grades([10, 11], '80', [11 => '1e309'], 100, false, 2);
    }

    /**
     * Individual scale grades must be valid integer scale positions.
     */
    public function test_individual_scale_grade_validation(): void {
        $grades = assignment::build_member_grades([10, 11], '3', [11 => '1'], 3, true, 2);
        $this->assertSame(1.0, $grades[11]['rawgrade']);
        $this->expectException(\invalid_parameter_exception::class);
        assignment::build_member_grades([10, 11], '3', [11 => '1.5'], 3, true, 2);
    }

    /**
     * Logs distinguish submissions, retain deleted-page evidence and exclude post-submission edits.
     */
    public function test_contribution_review_uses_scoped_log_events(): void {
        global $DB;
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $module = $this->getDataGenerator()->create_module('icontent', [
            'course' => $course->id,
            'assignmentmode' => 1,
        ]);
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $this->setUser($student);
        $submission = assignment::get_current_submission($icontent, $cm, (int)$student->id);
        $pageid = $DB->insert_record('icontent_pages', (object)[
            'icontentid' => $icontent->id,
            'cmid' => $cm->id,
            'submissionid' => $submission->id,
            'title' => 'Contribution test page',
            'pageicontent' => 'Content',
            'timecreated' => time(),
        ]);
        $page = $DB->get_record('icontent_pages', ['id' => $pageid], '*', MUST_EXIST);
        \mod_icontent\event\page_created::create_from_page($icontent, $context, $page)->trigger();
        \mod_icontent\event\page_updated::create_from_page($icontent, $context, $page)->trigger();
        $DB->delete_records('icontent_pages', ['id' => $pageid]);
        $page->submissionid = 0;
        \mod_icontent\event\page_created::create_from_page($icontent, $context, $page)->trigger();
        $this->assertSame(2, assignment::get_contributions($submission, $context)[$student->id]);

        $DB->set_field_select('logstore_standard_log', 'timecreated', time() + 100, 'eventname = ?', [
            '\mod_icontent\event\page_updated',
        ]);
        $submission->timesubmitted = time();
        $this->assertSame(1, assignment::get_contributions($submission, $context)[$student->id]);
        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
        $this->assertNull(assignment::get_contributions($submission, $context));
    }

    /**
     * A completed built-in rubric becomes the gradebook score. Other methods are ignored.
     */
    public function test_builtin_rubric_grades_submission_and_ignores_other_methods(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $module = $this->getDataGenerator()->create_module('icontent', [
            'course' => $course->id,
            'assignmentmode' => 1,
            'grade' => 100,
        ]);
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $this->assertNull(assignment::get_grading_controller($context));
        $direct = assignment::build_member_grades([(int)$student->id], '75', [], 100, false, (int)$teacher->id);
        $this->assertEquals(75, $direct[$student->id]['rawgrade']);

        $manager = get_grading_manager($context, 'mod_icontent', assignment::GRADING_AREA);
        $manager->set_active_method('guide');
        $this->assertNull(assignment::get_grading_controller($context));
        $this->assertSame('', assignment::render_submission_rubric($context, $icontent, 0));

        $this->setUser($teacher);
        $rubric = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')
            ->get_test_rubric($context, 'mod_icontent', assignment::GRADING_AREA);
        $this->assertSame('rubric', $manager->get_active_method());
        $controller = assignment::get_grading_controller($context);
        $this->assertInstanceOf(\gradingform_rubric_controller::class, $controller);
        $this->assertNotSame('', assignment::render_submission_rubric($context, $icontent, 0));

        $this->setUser($student);
        $submission = assignment::get_current_submission($icontent, $cm, (int)$student->id);
        $this->setUser($teacher);
        $instance = assignment::prepare_grading_instance($context, $icontent, $submission, (int)$teacher->id);
        $this->assertNotNull($instance);
        $formdata = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')->get_test_form_data(
            $rubric,
            (int)$submission->id,
            2,
            'No mistakes',
            2,
            'Several pictures'
        );
        $grade = assignment::grade_from_rubric($instance, $formdata, (int)$submission->id, false);
        $this->assertEquals(100, $grade);

        $filled = assignment::render_submission_rubric($context, $icontent, (int)$submission->id);
        $this->assertStringContainsString('Spelling is important', $filled);
        $this->assertStringContainsString('No mistakes', $filled);

        $this->assertTrue($instance->is_empty_form(['criteria' => []]));
        $this->expectException(\moodle_exception::class);
        assignment::grade_from_rubric($instance, ['criteria' => []], (int)$submission->id, false);
    }

    /**
     * A rubric page appears in Results and Manual review, then contributes its scaled score.
     */
    public function test_page_rubric_is_queued_and_graded(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/icontent/locallib.php');

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $module = $this->getDataGenerator()->create_module('icontent', [
            'course' => $course->id,
            'grade' => 100,
        ]);
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $page = (object)[
            'icontentid' => $icontent->id,
            'cmid' => $cm->id,
            'submissionid' => 0,
            'pagenum' => 1,
            'title' => 'Rubric page',
            'pageicontent' => '<p>Review this page</p>',
            'pageicontentformat' => FORMAT_HTML,
            'showtitle' => 1,
            'hidden' => 0,
            'userubric' => 1,
            'rubricmaxmark' => 2,
        ];
        $page->id = $DB->insert_record('icontent_pages', $page);

        $this->setUser($teacher);
        $this->assertNull(assignment::ensure_page_rubric_review($page, (int)$teacher->id));
        $this->setUser($student);
        $review = assignment::ensure_page_rubric_review($page, (int)$student->id);
        $again = assignment::ensure_page_rubric_review($page, (int)$student->id);
        $this->assertSame((int)$review->id, (int)$again->id);
        $this->assertSame(assignment::PAGE_RUBRIC_TOEVALUATE, $review->status);

        $pages = assignment::get_rubric_pages((int)$cm->id);
        $this->assertArrayHasKey($page->id, $pages);
        $this->assertEquals(1, $pages[$page->id]->pending);
        $users = icontent_get_attempts_users_with_open_answers($cm->id, 'ASC');
        $this->assertArrayHasKey($student->id, $users);
        $this->assertSame(1, icontent_count_attempts_users_with_open_answers($cm->id));
        $this->assertEquals(2.0, icontent_get_totalmaxfraction_by_instance($cm->id));

        $this->setUser($teacher);
        $rubric = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')
            ->get_test_rubric($context, 'mod_icontent', assignment::GRADING_AREA);
        $formdata = $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')->get_test_form_data(
            $rubric,
            assignment::page_rubric_itemid((int)$review->id),
            2,
            'No mistakes',
            2,
            'Several pictures'
        );
        $score = assignment::save_page_rubric_grade(
            $context,
            $icontent,
            $review,
            $formdata,
            (int)$teacher->id,
            'Well done'
        );
        $this->assertEquals(2.0, $score);
        $this->assertEquals(2.0, icontent_get_sumfraction_by_userid($cm->id, $student->id));
        $graded = $DB->get_record('icontent_page_rubric_grades', ['id' => $review->id], '*', MUST_EXIST);
        $this->assertSame(assignment::PAGE_RUBRIC_VALUED, $graded->status);
        $this->assertSame(0, icontent_count_attempts_users_with_open_answers($cm->id));
        $this->assertSame(1, icontent_count_attempts_users($cm->id));
        $gradedusers = icontent_get_attempts_users($cm->id, 'ASC');
        $this->assertArrayHasKey($student->id, $gradedusers);
        $this->assertEquals(2.0, $gradedusers[$student->id]->sumfraction);
        \icontent_set_grade_item($icontent, $cm->id, $student->id);
        $this->setUser($student);
        $html = assignment::render_page_results($page, $icontent, $context);
        $this->assertStringContainsString('Page rubric score: 2.00 out of 2.00', $html);
        $this->assertStringContainsString('Page-score subtotal: 2.00 out of 2.00', $html);
        $this->assertStringContainsString('Activity grade: 100.00', $html);
        $this->assertStringContainsString('No mistakes', $html);
    }

    /**
     * Owned pages contribute once per learner/item; group contributions include passive members.
     *
     * @param bool $group Whether the page set is group-owned.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('page_score_ownership_provider')]
    public function test_assignment_page_totals_and_final_overrides(bool $group): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/icontent/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }
        [$student, $passive, $outsider] = $students;
        $module = $generator->create_module('icontent', [
            'course' => $course->id, 'grade' => 100, 'assignmentmode' => 1,
            'teamsubmission' => (int)$group, 'publicsubmissions' => 1,
        ]);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        if ($group) {
            $DB->set_field('course_modules', 'groupmode', VISIBLEGROUPS, ['id' => $cm->id]);
            $cm->groupmode = VISIBLEGROUPS;
            $team = $generator->create_group(['courseid' => $course->id]);
            groups_add_member($team->id, $student->id);
            groups_add_member($team->id, $passive->id);
            $otherteam = $generator->create_group(['courseid' => $course->id]);
            groups_add_member($otherteam->id, $outsider->id);
        }
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $this->setUser($student);
        $submission = assignment::get_current_submission($icontent, $cm, (int)$student->id);
        $page = (object)[
            'icontentid' => $icontent->id, 'cmid' => $cm->id, 'submissionid' => $submission->id,
            'pagenum' => 1, 'title' => 'Owned page', 'pageicontent' => 'Content',
            'userubric' => 1, 'rubricmaxmark' => 10,
        ];
        $page->id = $DB->insert_record('icontent_pages', $page);
        $review = assignment::ensure_page_rubric_review($page, (int)$student->id);
        $pending = assignment::get_page_score_summary($icontent, $cm, (int)$student->id);
        $this->assertNull($pending->grade);
        \icontent_set_grade_item($icontent, $cm->id, $student->id);
        $pendinggrade = grade_get_grades($course->id, 'mod', 'icontent', $icontent->id, $student->id);
        $this->assertNull($pendinggrade->items[0]->grades[$student->id]->grade);
        $review->grade = 6;
        $review->status = assignment::PAGE_RUBRIC_VALUED;
        $DB->update_record('icontent_page_rubric_grades', $review);
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('shortanswer', null, [
            'category' => $category->id, 'defaultmark' => 5,
        ]);
        \icontent_add_questionpage([$question->id], $page->id, $cm->id);
        $mapping = $DB->get_record('icontent_pages_questions', ['pageid' => $page->id], '*', MUST_EXIST);
        foreach ([1, 4] as $score) {
            $DB->insert_record('icontent_question_attempts', (object)[
                'cmid' => $cm->id, 'pagesquestionsid' => $mapping->id, 'questionid' => $question->id,
                'userid' => $student->id, 'fraction' => $score, 'maxmark' => 5, 'rightanswer' => 'correct',
            ]);
        }
        // A visitor's scores on the public page are not owner/group contributions.
        $DB->insert_record('icontent_question_attempts', (object)[
            'cmid' => $cm->id, 'pagesquestionsid' => $mapping->id, 'questionid' => $question->id,
            'userid' => $outsider->id, 'fraction' => 5, 'maxmark' => 5, 'rightanswer' => 'correct',
        ]);
        $otherpage = clone $page;
        unset($otherpage->id);
        $otherpage->submissionid = $DB->insert_record('icontent_submissions', (object)[
            'icontentid' => $icontent->id, 'userid' => $group ? 0 : $outsider->id,
            'groupid' => $group ? $otherteam->id : 0, 'status' => assignment::STATUS_SUBMITTED,
        ]);
        $otherpage->id = $DB->insert_record('icontent_pages', $otherpage);
        $DB->insert_record('icontent_page_rubric_grades', (object)[
            'cmid' => $cm->id, 'pageid' => $otherpage->id, 'userid' => $student->id,
            'status' => assignment::PAGE_RUBRIC_VALUED, 'grade' => 99, 'maxmark' => 100,
        ]);
        $summary = assignment::get_page_score_summary($icontent, $cm, (int)$student->id);
        $this->assertEquals(10.0, $summary->earned);
        $this->assertEquals(15.0, $summary->maximum);
        $this->assertEqualsWithDelta(100 * 10 / 15, $summary->grade, 0.0001);
        $this->assertEquals(2, $summary->totalanswers);
        \icontent_set_grade_item($icontent, $cm->id, $student->id);
        $grade = grade_get_grades($course->id, 'mod', 'icontent', $icontent->id, $student->id);
        $this->assertEqualsWithDelta($summary->grade, $grade->items[0]->grades[$student->id]->grade, 0.0001);
        if ($group) {
            $passivegrade = grade_get_grades($course->id, 'mod', 'icontent', $icontent->id, $passive->id);
            $this->assertEqualsWithDelta($summary->grade, $passivegrade->items[0]->grades[$passive->id]->grade, 0.0001);
            $users = \icontent_get_attempts_users($cm->id, 'ASC', 0, 0);
            $this->assertArrayHasKey($passive->id, $users);
            $this->assertEquals(10, $users[$passive->id]->sumfraction);
        }
        $columns = assignment::get_report_score_columns($icontent, $cm, (int)$student->id);
        $this->assertSame('6.00 out of 10.00', $columns[0]);
        $html = assignment::render_page_results($page, $icontent, $context);
        $this->assertStringContainsString('Page rubric score: 6.00 out of 10.00', $html);
        $this->assertStringContainsString('Page-score subtotal: 10.00 out of 15.00', $html);
        $this->setAdminUser();
        $this->assertStringContainsString('Page rubric score: 6.00 out of 10.00',
            assignment::render_page_results($page, $icontent, $context));
        $this->setUser($outsider);
        $this->assertSame('', assignment::render_page_results($page, $icontent, $context));

        $this->setUser($student);
        $item = \grade_item::fetch([
            'courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'icontent',
            'iteminstance' => $icontent->id, 'itemnumber' => 0,
        ]);
        $item->set_hidden(1);
        $html = assignment::render_page_results($page, $icontent, $context);
        $this->assertStringNotContainsString('6.00', $html);
        $item->set_hidden(0);
        $submission->grade = 90;
        $submission->status = assignment::STATUS_GRADED;
        $DB->update_record('icontent_submissions', $submission);
        grade_update('mod/icontent', $course->id, 'mod', 'icontent', $icontent->id, 0, [
            $student->id => ['userid' => $student->id, 'rawgrade' => 82],
        ]);
        \icontent_set_grade_item($icontent, $cm->id, $student->id);
        $columns = assignment::get_report_score_columns($icontent, $cm, (int)$student->id);
        $this->assertStringContainsString('82.00', $columns[1]);
        $html = assignment::render_page_results($page, $icontent, $context);
        $this->assertStringContainsString('Page-set grade (final override): 90.00', $html);
        $this->assertStringContainsString('Activity grade: 82.00', $html);
        $this->assertStringContainsString('Page-score subtotal: 10.00 out of 15.00', $html);
        if ($group) {
            $DB->insert_record('icontent_question_attempts', (object)[
                'cmid' => $cm->id, 'pagesquestionsid' => $mapping->id, 'questionid' => $question->id,
                'userid' => $passive->id, 'fraction' => 3, 'maxmark' => 5, 'rightanswer' => 'correct',
            ]);
            $summary = assignment::get_page_score_summary($icontent, $cm, (int)$student->id, $submission);
            $this->assertEquals(13, $summary->earned);
            $this->assertEquals(20, $summary->maximum);
            $this->assertEquals(65, $summary->grade);
            $submission->grade = null;
            $DB->update_record('icontent_submissions', $submission);
            \icontent_update_grades($icontent, (int)$student->id);
            $gradeinfo = grade_get_grades($course->id, 'mod', 'icontent', $icontent->id, [
                $student->id, $passive->id,
            ]);
            foreach ([$student->id, $passive->id] as $userid) {
                $this->assertEquals(65, $gradeinfo->items[0]->grades[$userid]->grade);
            }
        }
    }

    /**
     * @return array
     */
    public static function page_score_ownership_provider(): array {
        return ['individual' => [false], 'group' => [true]];
    }

    /**
     * Editing the page maximum rescales completed reviews and leaves pending reviews ungraded.
     */
    public function test_page_rubric_maximum_change_preserves_percentage(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('icontent', [
            'course' => $course->id, 'assignmentmode' => 1, 'grade' => 100,
        ]);
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $submission = assignment::get_current_submission($icontent, $cm, (int)$student->id);
        $page = (object)[
            'icontentid' => $icontent->id, 'cmid' => $cm->id, 'submissionid' => $submission->id,
            'pagenum' => 1, 'title' => 'Rescaled rubric', 'pageicontent' => 'Content',
            'userubric' => 1, 'rubricmaxmark' => 10,
        ];
        $page->id = $DB->insert_record('icontent_pages', $page);
        $review = assignment::ensure_page_rubric_review($page, (int)$student->id);
        $review->grade = 9;
        $review->status = assignment::PAGE_RUBRIC_VALUED;
        $DB->update_record('icontent_page_rubric_grades', $review);
        $pendingstudent = $generator->create_user();
        $generator->enrol_user($pendingstudent->id, $course->id, 'student');
        $pending = assignment::ensure_page_rubric_review($page, (int)$pendingstudent->id);
        $page->rubricmaxmark = 100;
        $DB->update_record('icontent_pages', $page);
        assignment::sync_page_rubric_maxmark($page, $icontent);
        $rescaled = $DB->get_record('icontent_page_rubric_grades', ['id' => $review->id], '*', MUST_EXIST);
        $this->assertEquals(90, $rescaled->grade);
        $this->assertEquals(100, $rescaled->maxmark);
        $ungraded = $DB->get_record('icontent_page_rubric_grades', ['id' => $pending->id], '*', MUST_EXIST);
        $this->assertNull($ungraded->grade);
        $this->assertEquals(100, $ungraded->maxmark);
        $summary = assignment::get_page_score_summary($icontent, $cm, (int)$student->id);
        $this->assertEquals(90, $summary->earned);
        $this->assertEquals(100, $summary->maximum);
        $this->assertEquals(90, $summary->grade);
        assignment::sync_page_rubric_maxmark($page, $icontent);
        $this->assertEquals(90, $DB->get_field('icontent_page_rubric_grades', 'grade', ['id' => $review->id]));
    }

    /**
     * Forward navigation waits for every question and rubric grade on the page.
     */
    public function test_page_grade_is_required_before_forward_navigation(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $module = $this->getDataGenerator()->create_module('icontent', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);

        $sourcepage = (object)[
            'icontentid' => $module->id,
            'cmid' => $cm->id,
            'submissionid' => 0,
            'pagenum' => 1,
            'title' => 'Graded page',
            'pageicontent' => 'Page content',
            'pageicontentformat' => FORMAT_HTML,
            'userubric' => 1,
            'rubricmaxmark' => 1,
        ];
        $sourcepage->id = $DB->insert_record('icontent_pages', $sourcepage);
        $targetpage = (object)[
            'icontentid' => $module->id,
            'cmid' => $cm->id,
            'submissionid' => 0,
            'pagenum' => 2,
            'title' => 'Next page',
            'pageicontent' => 'Next page content',
            'pageicontentformat' => FORMAT_HTML,
            'userubric' => 0,
            'rubricmaxmark' => 1,
        ];
        $targetpage->id = $DB->insert_record('icontent_pages', $targetpage);
        $DB->insert_record('icontent_pages_displayed', (object)[
            'pageid' => $sourcepage->id,
            'userid' => $student->id,
            'cmid' => $cm->id,
            'timecreated' => time() - 10,
        ]);
        $this->assertSame(
            (int)$sourcepage->id,
            (int)assignment::get_last_viewed_page((int)$cm->id, (int)$student->id, 0)->id
        );
        $DB->insert_record('icontent_pages_nav', (object)[
            'cmid' => $cm->id,
            'userid' => $student->id,
            'frompageid' => $sourcepage->id,
            'topageid' => $targetpage->id,
            'timecreated' => time() - 5,
        ]);
        $this->assertSame(
            (int)$targetpage->id,
            (int)assignment::get_last_viewed_page((int)$cm->id, (int)$student->id, 0)->id
        );
        $plainpage = (object)[
            'id' => $targetpage->id + 1,
            'cmid' => $cm->id,
            'pagenum' => 1,
        ];

        $this->setUser($student);
        $this->assertTrue(assignment::can_navigate_to_page($plainpage, $targetpage, (int)$student->id));
        $this->assertFalse(assignment::can_navigate_to_page($sourcepage, $targetpage, (int)$student->id));
        $this->assertTrue(assignment::can_navigate_to_page($sourcepage, $sourcepage, (int)$student->id));

        $review = assignment::ensure_page_rubric_review($sourcepage, (int)$student->id);
        $this->assertFalse(assignment::can_navigate_to_page($sourcepage, $targetpage, (int)$student->id));
        $review->status = assignment::PAGE_RUBRIC_VALUED;
        $review->grade = 0;
        $DB->update_record('icontent_page_rubric_grades', $review);

        $question = (object)[
            'pageid' => $sourcepage->id,
            'questionid' => 12345,
            'cmid' => $cm->id,
            'maxmark' => 1,
        ];
        $question->id = $DB->insert_record('icontent_pages_questions', $question);
        $this->assertFalse(assignment::can_navigate_to_page($sourcepage, $targetpage, (int)$student->id));

        $attempt = (object)[
            'pagesquestionsid' => $question->id,
            'questionid' => $question->questionid,
            'userid' => $student->id,
            'cmid' => $cm->id,
            'fraction' => 0,
            'rightanswer' => ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE,
            'timecreated' => time(),
        ];
        $DB->insert_record('icontent_question_attempts', $attempt);
        $this->assertFalse(assignment::can_navigate_to_page($sourcepage, $targetpage, (int)$student->id));

        $DB->set_field('icontent_question_attempts', 'rightanswer', ICONTENT_QTYPE_ESSAY_STATUS_VALUED, [
            'pagesquestionsid' => $question->id,
            'userid' => $student->id,
            'cmid' => $cm->id,
        ]);
        $this->assertTrue(assignment::can_navigate_to_page($sourcepage, $targetpage, (int)$student->id));
    }
}
