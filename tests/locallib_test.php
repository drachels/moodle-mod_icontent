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
 * Tests for iContent locallib branch navigation helpers.
 *
 * @package    mod_icontent
 * @category   test
 * @copyright  2026 AL Rachels <drachels@drachels.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_icontent;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/icontent/locallib.php');

/**
 * Testcases for branch-style iContent navigation.
 *
 * @covers ::icontent_get_toc
 * @covers ::icontent_get_fullpageicontent
 * @covers ::icontent_make_cover_page
 * @covers ::icontent_get_pagequestions
 * @covers ::icontent_get_pagequestion_maxmark
 * @covers ::icontent_get_totalmaxfraction_by_instance
 * @covers ::icontent_question_engine_phase1_bootstrap_usage
 * @covers ::icontent_phase3_process_qengine_attempts
 * @covers ::icontent_get_attempt_summary_by_page
 * @covers ::icontent_get_right_answers_by_attempt_summary_by_page
 * @covers ::xmldb_icontent_upgrade
 * @covers ::icontent_update_questionpage_routes
 * @covers ::icontent_get_question_routed_next_pageid
 * @covers \mod_icontent\local\assignment::question_meets_minimum_score
 * @covers \mod_icontent\local\assignment::page_grade_is_complete
 * @covers \mod_icontent\local\assignment::can_navigate_to_page
 * @covers ::icontent_get_infoanswer_by_questionid
 * @covers ::icontent_get_next_pagenum
 * @covers ::icontent_get_prev_pagenum
 * @covers ::icontent_duplicate_page
 * @covers ::icontent_get_startpagenum
 * @covers ::icontent_move_page
 * @covers ::icontent_reserve_page_position
 * @covers ::icontent_make_toolbar
 * @covers ::icontent_get_toc
 */
final class locallib_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Covers use the normal HTML, embedded-file and context-aware filter pipeline.
     */
    public function test_cover_page_formats_content_and_filters(): void {
        global $DB;

        [$course, $module, $icontent] = $this->create_activity_fixture();
        $this->setAdminUser();
        $context = \context_module::instance($module->cmid);
        filter_set_global_state('multilang', TEXTFILTER_OFF);
        filter_set_local_state('multilang', $context->id, TEXTFILTER_ON);
        $pageid = $this->create_page($icontent->id, $module->cmid, 1, 'Cover', [
            'coverpage' => 1,
            'pageicontent' => '<p><a class="btn btn-primary" href="https://example.com/">Open link</a></p>' .
                '<span lang="en" class="multilang">English cover</span>' .
                '<span lang="fr" class="multilang">French cover</span>' .
                '<img src="@@PLUGINFILE@@/cover.png" alt="Cover image">',
        ]);
        $cover = \icontent_get_fullpageicontent(1, $icontent, $context);
        $html = $cover->fullpageicontent;
        $this->assertStringContainsString('<a class="btn btn-primary" href="https://example.com/">Open link</a>', $html);
        $this->assertStringContainsString('English cover', $html);
        $this->assertStringNotContainsString('French cover', $html);
        $this->assertStringNotContainsString('@@PLUGINFILE@@', $html);
        $fileurl = \moodle_url::make_pluginfile_url($context->id, 'mod_icontent', 'page', $pageid, '/', 'cover.png');
        $this->assertStringContainsString($fileurl->out(), $html);
        $this->assertStringNotContainsString('read-more-target', $html);

        $DB->set_field('icontent_pages', 'coverpage', 0, ['id' => $pageid]);
        $normal = \icontent_get_fullpageicontent(1, $icontent, $context);
        $this->assertStringContainsString('English cover', $normal->fullpageicontent);
        $this->assertStringNotContainsString('French cover', $normal->fullpageicontent);
        $this->assertStringContainsString($fileurl->out(), $normal->fullpageicontent);
    }

    /**
     * Long covers retain complete widgets, with a safe character-based preview.
     */
    public function test_cover_page_long_content_is_not_split_or_duplicated(): void {
        [$course, $module, $icontent] = $this->create_activity_fixture();
        $this->setAdminUser();
        $context = \context_module::instance($module->cmid);
        $text = str_repeat("\xc3\xa9", 490) . ' &lt;button&gt; ';
        $widget = '<button type="button" id="cover-widget">Open widget</button>';
        $this->create_page($icontent->id, $module->cmid, 1, 'Long cover', [
            'coverpage' => 1,
            'pageicontent' => '<p>' . $text . '</p>' . $widget,
        ]);

        $html = \icontent_get_fullpageicontent(1, $icontent, $context)->fullpageicontent;
        $this->assertStringContainsString('class="suspension-points"', $html);
        $this->assertStringContainsString('class="read-more-target"', $html);
        $this->assertStringContainsString('<p>' . $text . '</p>' . $widget, $html);
        $this->assertSame(1, substr_count($html, 'id="cover-widget"'));
        $this->assertStringContainsString('read-more-state-on', $html);
        $this->assertStringContainsString('read-more-state-off', $html);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);
        $preview = $xpath->query('//div[@class="suspension-points"]')->item(0);
        $this->assertSame(503, \core_text::strlen($preview->textContent));
        $this->assertSame(0, $preview->getElementsByTagName('button')->length);
    }

    /**
     * Media-only covers remain visible and 500 characters do not trigger Show more.
     */
    public function test_cover_page_media_and_preview_boundary(): void {
        global $DB;

        [$course, $module, $icontent] = $this->create_activity_fixture();
        $this->setAdminUser();
        $context = \context_module::instance($module->cmid);
        $pageid = $this->create_page($icontent->id, $module->cmid, 1, 'Media cover', [
            'coverpage' => 1,
            'pageicontent' => '<img src="@@PLUGINFILE@@/cover.png" alt="">',
        ]);
        $html = \icontent_get_fullpageicontent(1, $icontent, $context)->fullpageicontent;
        $this->assertStringContainsString('<img ', $html);
        $this->assertStringNotContainsString('contentcoverpage hide', $html);

        foreach ([500, 501] as $length) {
            $DB->set_field('icontent_pages', 'pageicontent', str_repeat("\xc3\xa9", $length), ['id' => $pageid]);
            $html = \icontent_get_fullpageicontent(1, $icontent, $context)->fullpageicontent;
            $this->assertSame($length > 500, str_contains($html, 'read-more-target'));
        }
    }

    /**
     * New bank versions change new attempts, not previously awarded points.
     */
    public function test_updated_question_points_preserve_historical_results(): void {
        global $DB, $SESSION;

        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setAdminUser();
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $original = $questiongenerator->create_question('multichoice', null, [
            'category' => $category->id,
            'defaultmark' => 4,
        ]);
        $pageid = $this->create_page($icontent->id, $module->cmid, 1, 'Question');
        $destinationid = $this->create_page($icontent->id, $module->cmid, 2, 'Destination');
        \icontent_add_questionpage([$original->id], $pageid, $module->cmid);
        $mapping = $DB->get_record('icontent_pages_questions', ['pageid' => $pageid], '*', MUST_EXIST);
        $mapping->minimumscore = 100;
        $mapping->minimumscorenextpageid = $destinationid;
        $DB->update_record('icontent_pages_questions', $mapping);
        $page = $DB->get_record('icontent_pages', ['id' => $pageid], '*', MUST_EXIST);
        $this->setUser($student);
        $attemptid = $DB->insert_record('icontent_question_attempts', (object)[
            'pagesquestionsid' => $mapping->id,
            'questionid' => $original->id,
            'cmid' => $module->cmid,
            'userid' => $student->id,
            'fraction' => 4,
            'maxmark' => 4,
            'rightanswer' => 'correct',
            'timecreated' => 100,
        ]);
        $questions = \icontent_get_pagequestions($pageid, $module->cmid);
        \icontent_question_engine_phase1_bootstrap_usage($page, $questions);
        $sessionkey = \icontent_question_engine_phase1_get_session_key($module->cmid, $pageid, $student->id);
        $originalusageid = $SESSION->mod_icontent_quba[$sessionkey];

        $this->setAdminUser();
        $latest = $questiongenerator->update_question($original, null, ['defaultmark' => 3]);
        $this->assertNotEquals($original->id, $latest->id);
        $this->setUser($student);
        $questions = \icontent_get_pagequestions($pageid, $module->cmid);
        $this->assertEquals(3.0, reset($questions)->maxmark);
        $this->assertEquals(3.0, \icontent_get_totalmaxfraction_by_instance($module->cmid));
        $this->assertEquals(4.0, \icontent_get_totalmaxfraction_by_instance($module->cmid, (int)$student->id));
        $summary = \icontent_get_attempt_summary_by_page($pageid, $module->cmid);
        $this->assertEquals(4.0, $summary->sumfraction);
        $this->assertEquals(4.0, $summary->maxfraction);
        $this->assertEquals(1, \icontent_get_right_answers_by_attempt_summary_by_page($pageid, $module->cmid)->totalrightanswers);
        $this->assertTrue(\mod_icontent\local\assignment::page_grade_is_complete($page, (int)$student->id));
        $this->assertSame($destinationid, \icontent_get_question_routed_next_pageid($page));

        \icontent_question_engine_phase1_bootstrap_usage($page, $questions);
        $usageid = $SESSION->mod_icontent_quba[$sessionkey];
        $this->assertNotEquals($originalusageid, $usageid);
        $quba = \question_engine::load_questions_usage_by_activity($usageid);
        $slots = $quba->get_slots();
        $slot = reset($slots);
        $this->assertEquals($latest->id, $quba->get_question($slot)->id);
        $this->assertEquals(3.0, $quba->get_question_max_mark($slot));
        \icontent_question_engine_phase1_bootstrap_usage($page, $questions);
        $this->assertEquals($usageid, $SESSION->mod_icontent_quba[$sessionkey]);

        // Also repair usages already cached with the latest version but old points.
        $quba->set_max_mark($slot, 4);
        \question_engine::save_questions_usage_by_activity($quba);
        \icontent_question_engine_phase1_bootstrap_usage($page, $questions);
        $this->assertNotEquals($usageid, $SESSION->mod_icontent_quba[$sessionkey]);
        $quba = \question_engine::load_questions_usage_by_activity($SESSION->mod_icontent_quba[$sessionkey]);
        $slots = $quba->get_slots();
        $slot = reset($slots);
        $this->assertEquals(3.0, $quba->get_question_max_mark($slot));

        $postdata = $questiongenerator->get_simulated_post_data_for_questions_in_usage($quba, [$slot => 'One; Three'], false);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $records = \icontent_phase3_process_qengine_attempts($postdata, $cm, $pageid);
        $this->assertCount(1, $records);
        $this->assertEquals(3.0, $records[0]->maxmark);
        $this->assertEquals(3.0, $records[0]->fraction);
        $DB->insert_records('icontent_question_attempts', $records);
        $this->assertEquals(4.0, $DB->get_field('icontent_question_attempts', 'fraction', ['id' => $attemptid]));
        $summary = \icontent_get_attempt_summary_by_page($pageid, $module->cmid);
        $this->assertEquals(3.0, $summary->sumfraction);
        $this->assertEquals(3.0, $summary->maxfraction);
        $this->assertEquals(3.0, \icontent_get_totalmaxfraction_by_instance($module->cmid, (int)$student->id));
        $this->assertTrue(\mod_icontent\local\assignment::page_grade_is_complete($page, (int)$student->id));
        $this->assertSame($destinationid, \icontent_get_question_routed_next_pageid($page));
        $this->assertEquals(4.0, $DB->get_field('icontent_pages_questions', 'maxmark', ['id' => $mapping->id]));
    }

    /**
     * Explicit mapping maxima and legacy zero-snapshot attempts keep their points.
     */
    public function test_question_point_overrides_and_legacy_attempts(): void {
        global $DB;

        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setAdminUser();
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $original = $questiongenerator->create_question('multichoice', null, [
            'category' => $category->id,
            'defaultmark' => 4,
        ]);
        $pageid = $this->create_page($icontent->id, $module->cmid, 1, 'Override');
        \icontent_add_questionpage([$original->id], $pageid, $module->cmid);
        $mapping = $DB->get_record('icontent_pages_questions', ['pageid' => $pageid], '*', MUST_EXIST);
        $DB->set_field('icontent_pages_questions', 'maxmark', 5, ['id' => $mapping->id]);
        $questiongenerator->update_question($original, null, ['defaultmark' => 3]);
        $questions = \icontent_get_pagequestions($pageid, $module->cmid);
        $this->assertEquals(5.0, reset($questions)->maxmark);

        $DB->set_field('icontent_pages_questions', 'maxmark', 4, ['id' => $mapping->id]);
        $this->setUser($student);
        $DB->insert_record('icontent_question_attempts', (object)[
            'pagesquestionsid' => $mapping->id,
            'questionid' => $original->id,
            'cmid' => $module->cmid,
            'userid' => $student->id,
            'fraction' => 4,
            'rightanswer' => 'correct',
            'timecreated' => 100,
        ]);
        $this->assertEquals(4.0, \icontent_get_attempt_summary_by_page($pageid, $module->cmid)->maxfraction);
        $this->assertEquals(4.0, \icontent_get_totalmaxfraction_by_instance($module->cmid, (int)$student->id));
        $answers = \icontent_get_submitted_answers_by_attempt_summary_by_page($pageid, $module->cmid);
        $this->assertEquals(4.0, reset($answers)->maxmark);
    }

    /**
     * The upgrade snapshots the old scale without changing earned or final grades.
     */
    public function test_question_point_upgrade_preserves_existing_grades(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/icontent/db/upgrade.php');
        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setAdminUser();
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('multichoice', null, [
            'category' => $category->id,
            'defaultmark' => 4,
        ]);
        $pageid = $this->create_page($icontent->id, $module->cmid, 1, 'Old answer');
        \icontent_add_questionpage([$question->id], $pageid, $module->cmid);
        $mapping = $DB->get_record('icontent_pages_questions', ['pageid' => $pageid], '*', MUST_EXIST);
        $attemptid = $DB->insert_record('icontent_question_attempts', (object)[
            'pagesquestionsid' => $mapping->id,
            'questionid' => $question->id,
            'cmid' => $module->cmid,
            'userid' => $student->id,
            'fraction' => 2,
            'rightanswer' => 'partial',
            'timecreated' => 100,
        ]);
        $gradeid = $DB->insert_record('icontent_grades', (object)[
            'icontentid' => $icontent->id,
            'cmid' => $module->cmid,
            'userid' => $student->id,
            'grade' => 50,
            'timemodified' => 100,
        ]);
        $questiongenerator->update_question($question, null, ['defaultmark' => 3]);
        set_config('version', 2026110501, 'mod_icontent');
        $this->assertTrue(\xmldb_icontent_upgrade(2026110501));
        $attempt = $DB->get_record('icontent_question_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $this->assertEquals(4.0, $attempt->maxmark);
        $this->assertEquals(2.0, $attempt->fraction);
        $grade = $DB->get_record('icontent_grades', ['id' => $gradeid], '*', MUST_EXIST);
        $this->assertEquals(50.0, $grade->grade);
        $this->assertEquals(100, $grade->timemodified);
    }

    /**
     * Ensure branch TOC output includes an indented default branch label.
     */
    public function test_get_toc_renders_default_branch_label(): void {
        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setUser($student);

        $parentpageid = $this->create_page($icontent->id, $module->cmid, 1, 'Main page');
        $this->create_page($icontent->id, $module->cmid, 2, 'Branch page A', [
            'branchref' => 'support-a',
            'branchparentpageid' => $parentpageid,
        ]);
        $this->create_page($icontent->id, $module->cmid, 3, 'Branch page B', [
            'branchref' => 'support-a',
            'branchparentpageid' => $parentpageid,
        ]);

        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $pages = \mod_icontent\local\icontent_info::icontent_preload_pages($icontent);
        $currentpage = $pages[$parentpageid];

        $toc = \icontent_get_toc($pages, $currentpage, $icontent, $cm, false);

        $this->assertStringContainsString('Branch 1', $toc);
        $this->assertStringContainsString('cluster-list', $toc);
        $this->assertStringContainsString('Branch page A', $toc);
        $this->assertStringContainsString('Branch page B', $toc);
    }

    /**
     * Branch disclosure opens only the current branch and preserves page visibility.
     */
    public function test_get_toc_collapsible_branches(): void {
        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setUser($student);

        $parentid = $this->create_page($icontent->id, $module->cmid, 1, 'Main page');
        $branchid = $this->create_page($icontent->id, $module->cmid, 2, 'Current branch page', [
            'branchref' => 'first',
            'branchname' => 'First branch',
            'branchparentpageid' => $parentid,
        ]);
        $otherid = $this->create_page($icontent->id, $module->cmid, 3, 'Other branch page', [
            'branchref' => 'second',
            'branchname' => 'Second branch',
            'branchparentpageid' => $parentid,
        ]);
        $hiddenid = $this->create_page($icontent->id, $module->cmid, 4, 'Hidden branch page', [
            'branchref' => 'hidden',
            'branchname' => 'Hidden branch',
            'branchparentpageid' => $parentid,
            'hidden' => 1,
        ]);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $pages = \mod_icontent\local\icontent_info::icontent_preload_pages($icontent);

        foreach ([$parentid, $branchid, $otherid] as $currentid) {
            $toc = \icontent_get_toc($pages, (string)$pages[$currentid]->pagenum, $icontent, $cm, false);
            $document = new \DOMDocument();
            $document->loadHTML($toc);
            $xpath = new \DOMXPath($document);
            $this->assertCount(2, $xpath->query('//details'));
            $this->assertCount(2, $xpath->query('//details/summary'));
            $this->assertCount($currentid === $parentid ? 0 : 1, $xpath->query('//details[@open]'));
            if ($currentid !== $parentid) {
                $this->assertCount(1, $xpath->query('//details[@open]//a[@data-pageid="' . $currentid . '"]'));
            }
            $this->assertStringNotContainsString('Hidden branch', $toc);
            $this->assertStringContainsString('data-toc-action="expand"', $toc);
            $this->assertStringContainsString('data-toc-action="collapse"', $toc);
        }

        $this->setAdminUser();
        $toc = \icontent_get_toc($pages, $pages[$hiddenid], $icontent, $cm, true);
        $this->assertStringContainsString('Hidden branch page', $toc);
        $this->assertStringContainsString('action-list', $toc);

        $toc = \icontent_get_toc([$parentid => $pages[$parentid]], $pages[$parentid], $icontent, $cm, false);
        $this->assertStringNotContainsString('<details', $toc);
        $this->assertStringNotContainsString('data-toc-action', $toc);
    }

    /**
     * Ensure routed next/branch traversal/history-aware previous work together.
     */
    public function test_branch_navigation_uses_routes_and_history(): void {
        global $DB;

        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setUser($student);

        $entrypageid = $this->create_page($icontent->id, $module->cmid, 1, 'Check-in');
        $branchpage1id = $this->create_page($icontent->id, $module->cmid, 2, 'Branch start', [
            'branchref' => 'remedial',
            'branchname' => 'Remedial path',
            'branchparentpageid' => $entrypageid,
        ]);
        $branchpage2id = $this->create_page($icontent->id, $module->cmid, 3, 'Branch follow-up', [
            'branchref' => 'remedial',
            'branchname' => 'Remedial path',
            'branchparentpageid' => $entrypageid,
        ]);
        $resumepageid = $this->create_page($icontent->id, $module->cmid, 4, 'Resume mainline');

        $time = time();
        $pagesquestionid = (int)$DB->insert_record('icontent_pages_questions', (object) [
            'pageid' => $entrypageid,
            'questionid' => 999999,
            'cmid' => $module->cmid,
            'timecreated' => $time,
            'timemodified' => $time,
            'maxmark' => 1,
            'remake' => 0,
            'qtype' => 'truefalse',
            'correctnextpageid' => $branchpage1id,
            'incorrectnextpageid' => 0,
            'manualreviewnextpageid' => 0,
            'defaultnextpageid' => 0,
        ]);
        $DB->insert_record('icontent_question_attempts', (object) [
            'pagesquestionsid' => $pagesquestionid,
            'questionid' => 999999,
            'userid' => $student->id,
            'cmid' => $module->cmid,
            'fraction' => 1,
            'rightanswer' => 'correct',
            'answertext' => 'Correct',
            'timecreated' => $time,
        ]);

        $entrypage = $DB->get_record('icontent_pages', ['id' => $entrypageid], '*', MUST_EXIST);
        $branchpage1 = $DB->get_record('icontent_pages', ['id' => $branchpage1id], '*', MUST_EXIST);
        $branchpage2 = $DB->get_record('icontent_pages', ['id' => $branchpage2id], '*', MUST_EXIST);
        $resumepage = $DB->get_record('icontent_pages', ['id' => $resumepageid], '*', MUST_EXIST);

        $this->assertSame((int)$branchpage1->pagenum, \icontent_get_next_pagenum($entrypage));
        $this->assertSame((int)$branchpage2->pagenum, \icontent_get_next_pagenum($branchpage1));
        $this->assertSame((int)$resumepage->pagenum, \icontent_get_next_pagenum($branchpage2));

        \icontent_record_page_navigation($entrypageid, $branchpage1id, $module->cmid);
        \icontent_record_page_navigation($branchpage1id, $branchpage2id, $module->cmid);
        \icontent_record_page_navigation($branchpage2id, $resumepageid, $module->cmid);

        $this->assertSame((int)$branchpage2->pagenum, \icontent_get_prev_pagenum($resumepage));
    }

    /**
     * Ensure duplicated pages are inserted after source and reset nav overrides.
     */
    public function test_duplicate_page_inserts_after_source_and_resets_nav_overrides(): void {
        global $DB;

        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setUser($student);

        $firstpageid = $this->create_page($icontent->id, $module->cmid, 1, 'Intro');
        $sourcepageid = $this->create_page($icontent->id, $module->cmid, 2, 'Source page', [
            'branchref' => 'branch-a',
            'branchname' => 'Branch A',
            'branchparentpageid' => $firstpageid,
            'prevmode' => 2,
            'prevpageid' => $firstpageid,
            'nextmode' => 2,
            'nextpageid' => $firstpageid,
            'pageicontent' => '<p>Sample content</p>',
        ]);
        $followingpageid = $this->create_page($icontent->id, $module->cmid, 3, 'After source');

        $context = \context_module::instance($module->cmid);
        $duplicated = \icontent_duplicate_page((int)$icontent->id, (int)$module->cmid, $sourcepageid, $context);

        $source = $DB->get_record('icontent_pages', ['id' => $sourcepageid], '*', MUST_EXIST);
        $following = $DB->get_record('icontent_pages', ['id' => $followingpageid], '*', MUST_EXIST);
        $newpage = $DB->get_record('icontent_pages', ['id' => (int)$duplicated->id], '*', MUST_EXIST);

        $this->assertSame((int)$source->pagenum + 1, (int)$newpage->pagenum);
        $this->assertSame(4, (int)$following->pagenum);

        $this->assertSame('branch-a', (string)$newpage->branchref);
        $this->assertSame('Branch A', (string)$newpage->branchname);
        $this->assertSame((int)$source->branchparentpageid, (int)$newpage->branchparentpageid);

        $this->assertSame(0, (int)$newpage->prevmode);
        $this->assertSame(0, (int)$newpage->prevpageid);
        $this->assertSame(1, (int)$newpage->nextmode);
        $this->assertSame(0, (int)$newpage->nextpageid);

        $this->assertSame((string)$source->pageicontent, (string)$newpage->pageicontent);
        $this->assertStringContainsString('(Copy)', (string)$newpage->title);
    }

    /**
     * Duplicating a submission page only changes page order within that submission.
     */
    public function test_duplicate_submission_page_keeps_submission_scope(): void {
        global $DB;

        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setUser($student);

        $sourceid = $this->create_page($icontent->id, $module->cmid, 1, 'Group page', [
            'submissionid' => 10,
        ]);
        $submissionfollowingid = $this->create_page($icontent->id, $module->cmid, 2, 'Next group page', [
            'submissionid' => 10,
        ]);
        $referenceid = $this->create_page($icontent->id, $module->cmid, 2, 'Reference page');

        $context = \context_module::instance($module->cmid);
        $duplicated = \icontent_duplicate_page((int)$icontent->id, (int)$module->cmid, $sourceid, $context);

        $newpage = $DB->get_record('icontent_pages', ['id' => (int)$duplicated->id], '*', MUST_EXIST);
        $submissionfollowing = $DB->get_record('icontent_pages', ['id' => $submissionfollowingid], '*', MUST_EXIST);
        $reference = $DB->get_record('icontent_pages', ['id' => $referenceid], '*', MUST_EXIST);

        $this->assertSame(10, (int)$newpage->submissionid);
        $this->assertSame(3, (int)$submissionfollowing->pagenum);
        $this->assertSame(2, (int)$reference->pagenum);
    }

    /**
     * Reference pages resume independently of a graded group's last displayed page.
     */
    public function test_reference_resume_excludes_graded_submission_pages(): void {
        global $DB;

        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setUser($student);
        $DB->set_field('icontent', 'assignmentmode', 1, ['id' => $icontent->id]);
        $icontent->assignmentmode = 1;
        $this->create_page($icontent->id, $module->cmid, 1, 'First reference');
        $referenceid = $this->create_page($icontent->id, $module->cmid, 2, 'Second reference');
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $submission = \mod_icontent\local\assignment::get_current_submission($icontent, $cm, (int)$student->id);
        $DB->set_field('icontent_submissions', 'status', 'graded', ['id' => $submission->id]);
        $groupid = $this->create_page($icontent->id, $module->cmid, 17, 'Group page', [
            'submissionid' => $submission->id,
        ]);
        $context = \context_module::instance($module->cmid);
        $DB->insert_record('icontent_pages_displayed', (object)[
            'cmid' => $module->cmid,
            'pageid' => $groupid,
            'userid' => $student->id,
            'timecreated' => time(),
        ]);
        $this->assertSame(1, (int)\icontent_get_startpagenum($icontent, $context));

        $DB->insert_record('icontent_pages_displayed', (object)[
            'cmid' => $module->cmid,
            'pageid' => $referenceid,
            'userid' => $student->id,
            'timecreated' => time() - 1,
        ]);
        $this->assertSame(2, (int)\icontent_get_startpagenum($icontent, $context));

        $DB->set_field('icontent_pages', 'hidden', 1, ['id' => $referenceid]);
        $this->assertSame(1, (int)\icontent_get_startpagenum($icontent, $context));
    }

    /**
     * New pages append, while explicit Add after inserts into only the chosen page set.
     */
    public function test_new_page_positions_append_or_insert_after(): void {
        global $DB;
        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $firstid = $this->create_page($icontent->id, $module->cmid, 1, 'First', ['submissionid' => 10]);
        $secondid = $this->create_page($icontent->id, $module->cmid, 2, 'Second', ['submissionid' => 10]);
        $referenceid = $this->create_page($icontent->id, $module->cmid, 2, 'Reference');
        $position = \icontent_reserve_page_position((int)$icontent->id, (int)$module->cmid, 10, 0);
        $thirdid = $this->create_page($icontent->id, $module->cmid, $position, 'Third', ['submissionid' => 10]);
        $this->assertSame(3, $position);
        $position = \icontent_reserve_page_position((int)$icontent->id, (int)$module->cmid, 10, 1);
        $insertedid = $this->create_page($icontent->id, $module->cmid, $position, 'Inserted', ['submissionid' => 10]);
        $ids = array_keys($DB->get_records('icontent_pages', [
            'icontentid' => $icontent->id, 'submissionid' => 10,
        ], 'pagenum, id', 'id'));
        $this->assertSame([$firstid, $insertedid, $secondid, $thirdid], $ids);
        $this->assertSame(2, (int)$DB->get_field('icontent_pages', 'pagenum', ['id' => $referenceid]));
    }

    /**
     * Draft group members can move pages and see only authorized TOC/toolbar actions.
     */
    public function test_group_members_can_reorder_and_edit_draft_pages(): void {
        global $DB;
        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $peer = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        foreach ([$peer, $outsider] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }
        $icontent->assignmentmode = 1;
        $icontent->teamsubmission = 1;
        $DB->update_record('icontent', $icontent);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $cm->groupmode = VISIBLEGROUPS;
        $DB->set_field('course_modules', 'groupmode', VISIBLEGROUPS, ['id' => $cm->id]);
        $groupid = groups_create_group((object)['courseid' => $course->id, 'name' => 'Draft authors']);
        groups_add_member($groupid, $student->id);
        groups_add_member($groupid, $peer->id);
        $this->setUser($student);
        $submission = \mod_icontent\local\assignment::get_current_submission($icontent, $cm, (int)$student->id);
        $firstid = $this->create_page($icontent->id, $cm->id, 1, 'First', ['submissionid' => $submission->id]);
        $secondid = $this->create_page($icontent->id, $cm->id, 2, 'Second', ['submissionid' => $submission->id]);
        $referenceid = $this->create_page($icontent->id, $cm->id, 1, 'Reference');
        $context = \context_module::instance($cm->id);
        $this->setUser($peer);
        $pages = \mod_icontent\local\icontent_info::icontent_preload_pages($icontent, (int)$submission->id);
        $toolbar = \icontent_make_toolbar($pages[$firstid], $icontent);
        $this->assertStringContainsString('icon-update', $toolbar);
        $this->assertStringContainsString('icon-duplicatepage', $toolbar);
        $toc = \icontent_get_toc($pages, $pages[$firstid], $icontent, $cm, false);
        $this->assertStringContainsString('move.php', $toc);
        $this->assertStringContainsString('edit.php', $toc);
        $this->assertStringNotContainsString('delete.php', $toc);
        $this->assertStringNotContainsString('show.php', $toc);
        $this->assertSame(2, (int)\icontent_move_page($firstid, false, $icontent, $cm, $context)->pagenum);
        $this->assertSame(1, (int)$DB->get_field('icontent_pages', 'pagenum', ['id' => $secondid]));
        $this->assertSame(1, (int)$DB->get_field('icontent_pages', 'pagenum', ['id' => $referenceid]));
        $this->assertSame(1, (int)\icontent_move_page($firstid, true, $icontent, $cm, $context)->pagenum);
        $this->assertSame(1, (int)\icontent_move_page($firstid, true, $icontent, $cm, $context)->pagenum);
        $this->assertSame(2, (int)\icontent_move_page($secondid, false, $icontent, $cm, $context)->pagenum);
        $this->setUser($outsider);
        $this->assertStringNotContainsString('move.php', \icontent_get_toc($pages, $pages[$firstid], $icontent, $cm, false));
        $this->assertStringNotContainsString('icon-update', \icontent_make_toolbar($pages[$firstid], $icontent));
        $this->expectException(\required_capability_exception::class);
        \icontent_move_page($firstid, false, $icontent, $cm, $context);
    }

    /**
     * Submitted and graded page sets cannot be reordered by students.
     */
    public function test_locked_submission_has_no_authoring_actions(): void {
        global $DB;
        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $icontent->assignmentmode = 1;
        $DB->update_record('icontent', $icontent);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $this->setUser($student);
        $submission = \mod_icontent\local\assignment::get_current_submission($icontent, $cm, (int)$student->id);
        $pageid = $this->create_page($icontent->id, $cm->id, 1, 'Draft', ['submissionid' => $submission->id]);
        $pages = \mod_icontent\local\icontent_info::icontent_preload_pages($icontent, (int)$submission->id);
        foreach (['submitted', 'graded'] as $status) {
            $DB->set_field('icontent_submissions', 'status', $status, ['id' => $submission->id]);
            $this->assertStringNotContainsString('move.php', \icontent_get_toc($pages, $pages[$pageid], $icontent, $cm, false));
            $toolbar = \icontent_make_toolbar($pages[$pageid], $icontent);
            $this->assertStringNotContainsString('icon-update', $toolbar);
            $this->assertStringNotContainsString('icon-duplicatepage', $toolbar);
        }
        $this->expectException(\required_capability_exception::class);
        \icontent_move_page($pageid, true, $icontent, $cm, $context);
    }

    /**
     * Students cannot reorder shared reference pages.
     */
    public function test_students_cannot_move_reference_pages(): void {
        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setUser($student);
        $icontent->assignmentmode = 1;
        $pageid = $this->create_page($icontent->id, $module->cmid, 1, 'Reference');
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $this->expectException(\required_capability_exception::class);
        \icontent_move_page($pageid, true, $icontent, $cm, \context_module::instance($cm->id));
    }

    /**
     * Ensure duplicate copies files from both page and bgpage fileareas.
     */
    public function test_duplicate_page_copies_page_and_bgpage_files(): void {
        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setUser($student);

        $sourcepageid = $this->create_page($icontent->id, $module->cmid, 1, 'Source with files');
        $context = \context_module::instance($module->cmid);

        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => (int)$context->id,
            'component' => 'mod_icontent',
            'filearea' => 'page',
            'itemid' => $sourcepageid,
            'filepath' => '/',
            'filename' => 'inline.txt',
        ], 'Inline page content file');
        $fs->create_file_from_string([
            'contextid' => (int)$context->id,
            'component' => 'mod_icontent',
            'filearea' => 'bgpage',
            'itemid' => $sourcepageid,
            'filepath' => '/',
            'filename' => 'background.png',
        ], 'PNGDATA');

        $duplicated = \icontent_duplicate_page((int)$icontent->id, (int)$module->cmid, $sourcepageid, $context);
        $newpageid = (int)$duplicated->id;

        $newpagefiles = $fs->get_area_files(
            (int)$context->id,
            'mod_icontent',
            'page',
            $newpageid,
            'filepath, filename',
            false
        );
        $newbgfiles = $fs->get_area_files(
            (int)$context->id,
            'mod_icontent',
            'bgpage',
            $newpageid,
            'filepath, filename',
            false
        );

        $this->assertCount(1, $newpagefiles);
        $this->assertCount(1, $newbgfiles);
        $this->assertSame('inline.txt', reset($newpagefiles)->get_filename());
        $this->assertSame('background.png', reset($newbgfiles)->get_filename());
    }

    /**
     * Minimum percentages use the latest net mark, not a count of selected options.
     */
    public function test_minimum_score_gates_navigation_and_overrides_other_question_routes(): void {
        global $DB;

        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setUser($student);
        $previousid = $this->create_page($icontent->id, $module->cmid, 1, 'Previous');
        $sourceid = $this->create_page($icontent->id, $module->cmid, 2, 'Question');
        $destinationid = $this->create_page($icontent->id, $module->cmid, 3, 'Threshold destination');
        $otherid = $this->create_page($icontent->id, $module->cmid, 4, 'Other outcome');
        $mappingid = (int)$DB->insert_record('icontent_pages_questions', (object)[
            'pageid' => $sourceid,
            'cmid' => $module->cmid,
            'questionid' => 999999,
            'maxmark' => 10,
        ]);
        $this->assertTrue(\icontent_update_questionpage_routes(
            $sourceid, (int)$module->cmid,
            [999999 => $otherid], [999999 => $otherid], [999999 => $otherid], [999999 => $otherid],
            [999999 => 70], [999999 => $destinationid]
        ));
        $source = $DB->get_record('icontent_pages', ['id' => $sourceid], '*', MUST_EXIST);
        $target = $DB->get_record('icontent_pages', ['id' => $destinationid], '*', MUST_EXIST);
        $previous = $DB->get_record('icontent_pages', ['id' => $previousid], '*', MUST_EXIST);
        $this->assertFalse(\mod_icontent\local\assignment::page_grade_is_complete($source, (int)$student->id));
        $this->assertSame(0, \icontent_get_question_routed_next_pageid($source));

        foreach ([
            [6.9, 'incorrect', false],
            [7, 'incorrect', true],
            [6, 'incorrect', false],
            [10, ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE, false],
            [10, ICONTENT_QTYPE_ESSAY_STATUS_VALUED, true],
            [-1, 'incorrect', false],
        ] as [$mark, $status, $meetsminimum]) {
            $DB->insert_record('icontent_question_attempts', (object)[
                'pagesquestionsid' => $mappingid,
                'questionid' => 999999,
                'cmid' => $module->cmid,
                'userid' => $student->id,
                'fraction' => $mark,
                'rightanswer' => $status,
                'timecreated' => 100,
            ]);
            $this->assertSame(
                $meetsminimum,
                \mod_icontent\local\assignment::can_navigate_to_page($source, $target, (int)$student->id)
            );
            $this->assertTrue(\mod_icontent\local\assignment::can_navigate_to_page($source, $previous, (int)$student->id));
            $this->assertSame($meetsminimum ? $destinationid : 0, \icontent_get_question_routed_next_pageid($source));
        }

        $this->assertTrue(\icontent_update_questionpage_routes(
            $sourceid, (int)$module->cmid, [], [999999 => $otherid], [], [], [999999 => 0], [999999 => 0]
        ));
        $this->assertTrue(\mod_icontent\local\assignment::can_navigate_to_page($source, $target, (int)$student->id));
        $this->assertSame($otherid, \icontent_get_question_routed_next_pageid($source));
    }

    /**
     * Multi-answer legacy grading includes distractor penalties in minimum scores.
     */
    public function test_minimum_score_uses_net_multichoice_grade(): void {
        global $DB;

        $answers = [];
        for ($index = 0; $index < 15; $index++) {
            $answerid = $DB->insert_record('question_answers', (object)[
                'question' => 999999,
                'answer' => 'Option ' . $index,
                'fraction' => $index < 10 ? 0.1 : -0.1,
                'feedback' => '',
            ]);
            $answers[] = 'qpid-1_answerid-' . $answerid;
        }
        $mapping = (object)['minimumscore' => 70, 'effectivemaxmark' => 10];
        $response = \icontent_get_infoanswer_by_questionid(999999, ICONTENT_QTYPE_MULTICHOICE, array_slice($answers, 0, 7));
        $this->assertEqualsWithDelta(0.7, $response->fraction, 0.0000001);
        $this->assertTrue(\mod_icontent\local\assignment::question_meets_minimum_score($mapping, (object)[
            'fraction' => $response->fraction * 10, 'rightanswer' => $response->rightanswer,
        ]));
        $response = \icontent_get_infoanswer_by_questionid(
            999999, ICONTENT_QTYPE_MULTICHOICE, array_merge(array_slice($answers, 0, 7), [$answers[10]])
        );
        $this->assertEqualsWithDelta(0.6, $response->fraction, 0.0000001);
        $this->assertFalse(\mod_icontent\local\assignment::question_meets_minimum_score($mapping, (object)[
            'fraction' => $response->fraction * 10, 'rightanswer' => $response->rightanswer,
        ]));
    }

    /**
     * Minimum-score routes can leave the last page and stay inside their submission.
     */
    public function test_minimum_score_route_from_last_submission_page(): void {
        global $DB;

        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $this->setUser($student);
        $targetid = $this->create_page($icontent->id, $module->cmid, 1, 'Destination', ['submissionid' => 10]);
        $sourceid = $this->create_page($icontent->id, $module->cmid, 2, 'Question', ['submissionid' => 10]);
        $mappingid = (int)$DB->insert_record('icontent_pages_questions', (object)[
            'pageid' => $sourceid,
            'cmid' => $module->cmid,
            'questionid' => 999999,
            'maxmark' => 5,
            'minimumscore' => 70,
            'minimumscorenextpageid' => $targetid,
        ]);
        $source = $DB->get_record('icontent_pages', ['id' => $sourceid], '*', MUST_EXIST);
        $target = $DB->get_record('icontent_pages', ['id' => $targetid], '*', MUST_EXIST);
        $this->assertFalse(\mod_icontent\local\assignment::can_navigate_to_page($source, $target, (int)$student->id));
        $DB->insert_record('icontent_question_attempts', (object)[
            'pagesquestionsid' => $mappingid,
            'questionid' => 999999,
            'cmid' => $module->cmid,
            'userid' => $student->id,
            'fraction' => 3.5,
            'rightanswer' => 'incorrect',
            'timecreated' => time(),
        ]);
        $this->assertSame($targetid, \icontent_get_question_routed_next_pageid($source));
        $this->assertSame(1, \icontent_get_next_pagenum($source));
        $this->assertTrue(\mod_icontent\local\assignment::can_navigate_to_page($source, $target, (int)$student->id));
    }

    /**
     * Invalid minimum scores and destinations are rejected without changing mappings.
     *
     * @dataProvider invalid_minimum_score_provider
     * @param string $score Submitted percentage.
     * @param string $target Destination fixture name.
     */
    public function test_invalid_minimum_score_is_rejected(string $score, string $target): void {
        global $DB;

        [$course, $module, $icontent, $student] = $this->create_activity_fixture();
        $sourceid = $this->create_page($icontent->id, $module->cmid, 1, 'Question');
        $targetids = [
            'none' => 0,
            'self' => $sourceid,
            'valid' => $this->create_page($icontent->id, $module->cmid, 2, 'Valid'),
            'hidden' => $this->create_page($icontent->id, $module->cmid, 3, 'Hidden', ['hidden' => 1]),
            'submission' => $this->create_page($icontent->id, $module->cmid, 1, 'Private', ['submissionid' => 10]),
        ];
        $DB->insert_record('icontent_pages_questions', (object)[
            'pageid' => $sourceid,
            'cmid' => $module->cmid,
            'questionid' => 999999,
        ]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidminimumscore', 'icontent'));
        \icontent_update_questionpage_routes(
            $sourceid, (int)$module->cmid, [], [], [], [], [999999 => $score], [999999 => $targetids[$target]]
        );
    }

    /**
     * Invalid score/route pairs.
     *
     * @return array
     */
    public static function invalid_minimum_score_provider(): array {
        return [
            'negative' => ['-1', 'valid'],
            'too high' => ['101', 'valid'],
            'fractional percentage' => ['70.5', 'valid'],
            'not numeric' => ['invalid', 'valid'],
            'missing destination' => ['70', 'none'],
            'disabled destination' => ['0', 'valid'],
            'same page' => ['70', 'self'],
            'hidden page' => ['70', 'hidden'],
            'different submission' => ['70', 'submission'],
        ];
    }

    /**
     * Create a baseline iContent activity fixture.
     *
     * @return array
     */
    private function create_activity_fixture(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $module = $generator->create_module('icontent', ['course' => $course->id, 'name' => 'Branch test iContent']);
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);

        return [$course, $module, $icontent, $student];
    }

    /**
     * Create an iContent page record.
     *
     * @param int $icontentid
     * @param int $cmid
     * @param int $pagenum
     * @param string $title
     * @param array $overrides
     * @return int
     */
    private function create_page(int $icontentid, int $cmid, int $pagenum, string $title, array $overrides = []): int {
        global $DB;

        $time = time();
        $record = (object) array_merge([
            'icontentid' => $icontentid,
            'cmid' => $cmid,
            'coverpage' => 0,
            'title' => $title,
            'branchref' => null,
            'branchname' => null,
            'branchparentpageid' => 0,
            'showtitle' => 1,
            'pageicontent' => $title . ' content',
            'pageicontentformat' => FORMAT_HTML,
            'showbgimage' => 0,
            'bgimage' => null,
            'bgcolor' => '#FCFCFC',
            'layout' => 1,
            'transitioneffect' => '0',
            'bordercolor' => '#E4E4E4',
            'borderwidth' => 1,
            'pagenum' => $pagenum,
            'hidden' => 0,
            'maxnotesperpages' => 15,
            'attemptsallowed' => 0,
            'prevmode' => 0,
            'prevpageid' => 0,
            'nextmode' => 0,
            'nextpageid' => 0,
            'expandnotesarea' => 0,
            'expandquestionsarea' => 0,
            'timecreated' => $time,
            'timemodified' => $time,
        ], $overrides);

        return (int)$DB->insert_record('icontent_pages', $record);
    }
}
