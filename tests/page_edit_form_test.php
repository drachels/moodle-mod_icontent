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

use mod_icontent\form\page_edit;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the autoloaded page-edit form and its legacy compatibility wrapper.
 *
 * @package mod_icontent
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_icontent\form\page_edit
 */
final class page_edit_form_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Create a module and configure the page context for form rendering.
     *
     * @return array Module and custom form data.
     */
    private function create_form_data(): array {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('icontent', ['course' => $course->id]);
        $context = \context_module::instance($module->cmid);
        $PAGE->set_context($context);
        $PAGE->set_course($course);
        $PAGE->set_url('/mod/icontent/edit.php', ['cmid' => $module->cmid]);
        return [$module, [
            'page' => (object)[
                'id' => null, 'icontentid' => $module->id, 'cmid' => $module->cmid,
                'pagenum' => 1, 'submissionid' => 0,
                'bgcolor' => 'abcdef', 'bordercolor' => '123456', 'titlecolor' => 'invalid',
            ],
            'pageicontentoptions' => ['context' => $context, 'maxfiles' => -1],
            'bgimagemaxbytes' => 1048576,
        ]];
    }

    /**
     * New-page controls and colour defaults survive the move to an autoloaded class.
     */
    public function test_new_page_controls(): void {
        [, $customdata] = $this->create_form_data();
        $form = new page_edit(null, $customdata);
        $html = $form->render();
        foreach ([
            'title', 'branchparentpageid', 'prevmode', 'prevpageid', 'nextmode', 'nextpageid',
            'layout', 'bgimage_filemanager', 'attemptsallowed', 'transitioneffect',
        ] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html);
        }
        $this->assertStringContainsString('pageicontent_editor', $html);
        $this->assertStringContainsString('icontent_bgcolor_picker', $html);
        $this->assertStringContainsString("input.type = 'color'", $html);
        $this->assertSame('#ABCDEF', $customdata['page']->bgcolor);
        $this->assertSame('#123456', $customdata['page']->bordercolor);
        $this->assertSame('#000000', $customdata['page']->titlecolor);
    }

    /**
     * Existing-page background links and the legacy constructor remain functional.
     */
    public function test_existing_page_and_legacy_wrapper(): void {
        global $CFG, $DB;
        [$module, $customdata] = $this->create_form_data();
        $customdata['page']->id = $DB->insert_record('icontent_pages', (object)[
            'icontentid' => $module->id, 'cmid' => $module->cmid,
            'pagenum' => 1, 'title' => 'Existing page', 'pageicontent' => '<p>Editable content</p>',
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($module->cmid)->id,
            'component' => 'mod_icontent', 'filearea' => 'bgpage',
            'itemid' => $customdata['page']->id, 'filepath' => '/', 'filename' => 'background.png',
        ], 'Original background bytes');
        $form = new page_edit(null, $customdata);
        $html = $form->render();
        $this->assertStringContainsString('background.png', $html);
        $this->assertStringContainsString('pluginfile.php', $html);
        require_once($CFG->dirroot . '/mod/icontent/edit_form.php');
        $legacy = new \icontent_pages_edit_form(null, $customdata);
        $this->assertInstanceOf(page_edit::class, $legacy);
        $this->assertStringContainsString('background.png', $legacy->render());
    }

    /**
     * Branch and custom-navigation validation is unchanged.
     */
    public function test_navigation_validation(): void {
        global $DB;
        [$module, $customdata] = $this->create_form_data();
        $currentid = $DB->insert_record('icontent_pages', (object)[
            'icontentid' => $module->id, 'cmid' => $module->cmid,
            'pagenum' => 1, 'title' => 'Current', 'pageicontent' => '<p>Current</p>',
        ]);
        $targetid = $DB->insert_record('icontent_pages', (object)[
            'icontentid' => $module->id, 'cmid' => $module->cmid,
            'pagenum' => 2, 'title' => 'Target', 'pageicontent' => '<p>Target</p>',
        ]);
        $form = new page_edit(null, $customdata);
        $data = [
            'id' => $currentid, 'icontentid' => $module->id, 'cmid' => $module->cmid,
            'branchparentpageid' => $targetid,
            'prevmode' => 2, 'nextmode' => 2, 'prevpageid' => $targetid, 'nextpageid' => $targetid,
        ];
        $this->assertSame([], $form->validation($data, []));
        $self = $data;
        $self['branchparentpageid'] = $currentid;
        $this->assertSame(
            get_string('errorclusterparentself', 'icontent'),
            $form->validation($self, [])['branchparentpageid']
        );
        $DB->set_field('icontent_pages', 'hidden', 1, ['id' => $targetid]);
        $errors = $form->validation($data, []);
        $this->assertSame(get_string('errorclusterparentinvalid', 'icontent'), $errors['branchparentpageid']);
        $this->assertSame(get_string('errorcustomnavinvalid', 'icontent'), $errors['prevpageid']);
        $this->assertSame(get_string('errorcustomnavinvalid', 'icontent'), $errors['nextpageid']);
        $data['branchparentpageid'] = 0;
        $data['prevmode'] = 3;
        $data['nextpageid'] = $currentid;
        $errors = $form->validation($data, []);
        $this->assertSame(get_string('errorinvalidnavigationmode', 'icontent'), $errors['prevmode']);
        $this->assertSame(get_string('errorcustomnavself', 'icontent'), $errors['nextpageid']);
    }
}
