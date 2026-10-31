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

use mod_icontent\output\renderer;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for renderer discovery, compatibility and JavaScript initialization.
 *
 * @package mod_icontent
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_icontent\output\renderer
 */
final class renderer_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Moodle selects the autoloaded renderer without changing callers.
     */
    public function test_renderer_discovery(): void {
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url('/mod/icontent/view.php');
        $renderer = $page->get_renderer('mod_icontent');
        $this->assertSame(renderer::class, get_class($renderer));
        $this->assertInstanceOf(\plugin_renderer_base::class, $renderer);
    }

    /**
     * Existing renderer subclasses retain the same methods.
     */
    public function test_legacy_renderer_compatibility(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/icontent/renderer.php');
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $legacy = new \mod_icontent_renderer($page, RENDERER_TARGET_GENERAL);
        $this->assertInstanceOf(renderer::class, $legacy);
        $this->assertTrue(is_callable([$legacy, 'icontent_requires_internal_js']));
        $this->assertNull($legacy->icontent_requires_css());
        $this->assertNull($legacy->icontent_requires_external_js());
    }

    /**
     * Both existing AMD modules still initialize, with no added CSS or external JS.
     */
    public function test_javascript_initialization(): void {
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url('/mod/icontent/view.php');
        $renderer = $page->get_renderer('mod_icontent');
        $renderer->icontent_requires_css();
        $renderer->icontent_requires_external_js();
        $renderer->icontent_requires_internal_js();
        $html = $page->requires->get_end_code();
        $this->assertStringContainsString('mod_icontent/module', $html);
        $this->assertStringContainsString('mod_icontent/actions', $html);
        $this->assertStringNotContainsString('/mod/icontent/js/jquery/', $html);
        $this->assertStringNotContainsString('/mod/icontent/js/bootstrap/', $html);
    }
}
