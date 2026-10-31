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
}
