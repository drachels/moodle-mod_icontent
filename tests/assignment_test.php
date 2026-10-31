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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_icontent;

use mod_icontent\local\assignment;

defined('MOODLE_INTERNAL') || die();

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
}
