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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

namespace mod_icontent;

use mod_icontent\output\mobile;
use mod_icontent\local\assignment;

defined('MOODLE_INTERNAL') || die();

/**
 * App reader discovery, content and access boundary tests.
 *
 * @package mod_icontent
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
     * Moodle's real mobile discovery and content APIs expose the online reader.
     */
    public function test_mobile_api_discovery_and_content(): void {
        global $CFG;
        [$course, $icontent, $cm, $one] = $this->create_activity();
        $this->create_page($icontent, $cm);
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
        $this->assertStringContainsString('core-site-plugins-new-content', $result['templates'][0]['html']);
        $this->assertStringContainsString(get_string('mobilereadernotice', 'icontent'), $result['templates'][0]['html']);
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
        $this->assertSame(0, $DB->count_records('icontent_pages_displayed'));
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
