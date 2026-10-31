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

namespace mod_icontent;

use mod_icontent\local\presentation_importer;
use mod_icontent\local\assignment;
use Symfony\Component\Process\Process;

defined('MOODLE_INTERNAL') || die();

/**
 * Presentation parsing, conversion and page-set import tests.
 *
 * @package mod_icontent
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_icontent\local\presentation_importer
 */
final class presentation_importer_test extends \advanced_testcase {
    /** @var Process|null Dedicated test worker. */
    private ?Process $worker = null;

    /**
     * Reset test data.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Stop the test worker before Moodle removes its private data.
     */
    public function tearDown(): void {
        if ($this->worker !== null) {
            $this->worker->stop(1);
        }
        parent::tearDown();
    }

    /**
     * Run the production worker against the isolated PHPUnit workspace.
     *
     * @param string $office Converter executable, replaceable to exercise failures.
     */
    private function start_worker(string $office = '/usr/bin/libreoffice'): void {
        global $CFG;
        if (!is_executable('/usr/bin/libreoffice') || !is_executable('/usr/bin/bwrap')) {
            $this->markTestSkipped('LibreOffice and Bubblewrap are needed for conversion integration tests.');
        }
        $base = make_writable_directory($CFG->tempdir . '/icontent-presentations');
        $this->worker = new Process([
            PHP_BINARY, $CFG->dirroot . '/mod/icontent/cli/presentation_worker.php',
            '--workdir=' . $base, '--autoload=' . $CFG->root . '/vendor/autoload.php',
            '--office=' . $office, '--sandbox=/usr/bin/bwrap',
        ]);
        $this->worker->setTimeout(null);
        $this->worker->start();
        set_config('presentationworker', 1, 'mod_icontent');
        $deadline = microtime(true) + 10;
        while (!presentation_importer::is_available() && $this->worker->isRunning() && microtime(true) < $deadline) {
            usleep(100000);
        }
        $this->assertTrue(presentation_importer::is_available(), $this->worker->getErrorOutput());
    }

    /**
     * Create an original two-slide ODP fixture with editable text and an image.
     *
     * @param string $directory Test directory.
     * @return string Fixture path.
     */
    private function create_deck(string $directory): string {
        $path = $directory . '/fixture.odp';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.presentation');
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/manifest.xml',
            '<?xml version="1.0"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">' .
            '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.presentation"/>' .
            '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>' .
            '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>' .
            '<manifest:file-entry manifest:full-path="Pictures/test.png" manifest:media-type="image/png"/>' .
            '</manifest:manifest>');
        $zip->addFromString('styles.xml',
            '<?xml version="1.0"?><office:document-styles office:version="1.2" ' .
            'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" ' .
            'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" ' .
            'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0">' .
            '<office:styles/><office:automatic-styles><style:page-layout style:name="pm1">' .
            '<style:page-layout-properties fo:page-width="28cm" fo:page-height="21cm" style:print-orientation="landscape"/>' .
            '</style:page-layout></office:automatic-styles><office:master-styles>' .
            '<style:master-page style:name="Default" style:page-layout-name="pm1"/>' .
            '</office:master-styles></office:document-styles>');
        $zip->addFromString('content.xml',
            '<?xml version="1.0"?><office:document-content office:version="1.2" ' .
            'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" ' .
            'xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" ' .
            'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" ' .
            'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" ' .
            'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" ' .
            'xmlns:xlink="http://www.w3.org/1999/xlink" ' .
            'xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" ' .
            'xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0">' .
            '<office:automatic-styles><style:style style:name="Bold" style:family="text">' .
            '<style:text-properties fo:font-weight="bold"/></style:style></office:automatic-styles>' .
            '<office:body><office:presentation>' .
            '<draw:page draw:name="First slide" draw:master-page-name="Default">' .
            '<draw:frame svg:x="1cm" svg:y="1cm" svg:width="15cm" svg:height="5cm"><draw:text-box>' .
            '<text:p>First editable text &amp; safe &lt;script&gt;</text:p>' .
            '<text:p><text:span text:style-name="Bold">Bold text</text:span></text:p>' .
            '<text:list><text:list-item><text:p>List item</text:p></text:list-item></text:list>' .
            '</draw:text-box></draw:frame><draw:frame svg:x="1cm" svg:y="8cm" svg:width="2cm" svg:height="2cm">' .
            '<draw:image xlink:href="Pictures/test.png" xlink:type="simple"/></draw:frame>' .
            '</draw:page><draw:page draw:name="Second slide" draw:master-page-name="Default">' .
            '<draw:frame svg:x="1cm" svg:y="1cm" svg:width="15cm" svg:height="5cm"><draw:text-box>' .
            '<text:p>Second editable text</text:p></draw:text-box></draw:frame>' .
            '</draw:page></office:presentation></office:body></office:document-content>');
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $zip->addFromString('Pictures/test.png', ob_get_clean());
        imagedestroy($image);
        $zip->close();
        return $path;
    }

    /**
     * Parser preserves text, lists, inline formatting, slide order and embedded images.
     */
    public function test_editable_odp_parser(): void {
        $slides = presentation_importer::read_slides($this->create_deck(make_request_directory()));
        $this->assertCount(2, $slides);
        $this->assertSame('First slide', $slides[0]->title);
        $this->assertStringContainsString('First editable text', $slides[0]->html);
        $this->assertStringContainsString('font-weight:bold', $slides[0]->html);
        $this->assertStringContainsString('<ul>', $slides[0]->html);
        $this->assertStringContainsString('&lt;script&gt;', $slides[0]->html);
        $this->assertStringNotContainsString('<script>', $slides[0]->html);
        $this->assertArrayHasKey('test.png', $slides[0]->images);
        $this->assertStringContainsString('@@PLUGINFILE@@/test.png', $slides[0]->html);
        $this->assertStringContainsString('Second editable text', $slides[1]->html);
    }

    /**
     * DTD-bearing packages are explicitly rejected.
     */
    public function test_parser_rejects_dtd(): void {
        $path = $this->create_deck(make_request_directory());
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('content.xml', '<!DOCTYPE document [<!ENTITY x "bad">]><document>&x;</document>');
        $zip->close();
        $this->expectException(\moodle_exception::class);
        presentation_importer::read_slides($path);
    }

    /**
     * The reader enforces the exact 100-slide upper bound.
     */
    public function test_slide_count_limit(): void {
        $path = $this->create_deck(make_request_directory());
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName('content.xml');
        $xml = preg_replace(
            '/<office:presentation>.*<\/office:presentation>/s',
            '<office:presentation>' . str_repeat(
                '<draw:page draw:name="Slide"><draw:text-box><text:p>Editable</text:p></draw:text-box></draw:page>',
                100
            ) . '</office:presentation>',
            $xml
        );
        $zip->addFromString('content.xml', $xml);
        $zip->close();
        $this->assertCount(100, presentation_importer::read_slides($path));
        $zip->open($path);
        $zip->addFromString('content.xml', str_replace(
            '</office:presentation>', '<draw:page draw:name="Excess"/></office:presentation>', $xml
        ));
        $zip->close();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('presentationslidecount', 'icontent', 100));
        presentation_importer::read_slides($path);
    }

    /**
     * Small compressed packages cannot bypass the per-part expanded-size limit.
     */
    public function test_expanded_package_limit(): void {
        $path = $this->create_deck(make_request_directory());
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('oversized.xml', str_repeat('x', 20971521));
        $zip->setCompressionName('oversized.xml', \ZipArchive::CM_DEFLATE);
        $zip->close();
        $this->assertLessThan(presentation_importer::MAX_FILE_SIZE, filesize($path));
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('presentationtoolarge', 'icontent'));
        presentation_importer::read_slides($path);
    }

    /**
     * Group peers append to their draft; references, outsiders and final submissions stay protected.
     */
    public function test_group_import_access_and_isolation(): void {
        global $DB;
        $this->start_worker();
        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        foreach ([$one, $two, $outsider] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }
        $module = $this->getDataGenerator()->create_module('icontent', [
            'course' => $course->id, 'assignmentmode' => 1, 'teamsubmission' => 1,
        ]);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'groupmode', VISIBLEGROUPS, ['id' => $cm->id]);
        $cm->groupmode = VISIBLEGROUPS;
        $groupid = groups_create_group((object)['courseid' => $course->id, 'name' => 'Presentation group']);
        groups_add_member($groupid, $one->id);
        groups_add_member($groupid, $two->id);
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $this->setUser($one);
        $submission = assignment::get_current_submission($icontent, $cm, (int)$one->id);
        $this->setAdminUser();
        $file = get_file_storage()->create_file_from_pathname([
            'contextid' => $context->id, 'component' => 'mod_icontent',
            'filearea' => 'test', 'itemid' => 0, 'filepath' => '/', 'filename' => 'fixture.odp',
        ], $this->create_deck(make_request_directory()));
        presentation_importer::import($file, $icontent, $cm, $context, 0);
        $references = $DB->get_records('icontent_pages', ['icontentid' => $icontent->id, 'submissionid' => 0]);
        $this->setUser($one);
        $first = presentation_importer::import($file, $icontent, $cm, $context, (int)$submission->id);
        $this->setUser($two);
        presentation_importer::import($file, $icontent, $cm, $context, (int)$submission->id);
        $pages = array_values($DB->get_records('icontent_pages', ['submissionid' => $submission->id], 'pagenum'));
        $this->assertCount(4, $pages);
        $this->assertSame(range(1, 4), array_map(static fn($page) => (int)$page->pagenum, $pages));
        $this->assertSame($first['firstid'], (int)$pages[0]->id);
        $this->assertEquals($references, $DB->get_records(
            'icontent_pages', ['icontentid' => $icontent->id, 'submissionid' => 0]
        ));
        foreach ([
            [$outsider, (int)$submission->id, assignment::STATUS_DRAFT],
            [$two, 0, assignment::STATUS_DRAFT],
            [$two, (int)$submission->id, assignment::STATUS_SUBMITTED],
            [$two, (int)$submission->id, assignment::STATUS_GRADED],
        ] as [$user, $target, $status]) {
            $DB->set_field('icontent_submissions', 'status', $status, ['id' => $submission->id]);
            $this->setUser($user);
            try {
                presentation_importer::import($file, $icontent, $cm, $context, $target);
                $this->fail('Unauthorized presentation import was accepted.');
            } catch (\required_capability_exception $exception) {
                $this->assertSame('nopermissions', $exception->errorcode);
            }
        }
        $this->assertSame(6, $DB->count_records('icontent_pages', ['icontentid' => $icontent->id]));
    }

    /**
     * Upload controls include all four file types and the append-only explanation.
     */
    public function test_upload_form(): void {
        global $PAGE;
        $this->setAdminUser();
        $PAGE->set_context(\context_system::instance());
        $form = new \mod_icontent\form\presentation_import(null, [
            'id' => 1, 'submissionid' => 2, 'maxbytes' => presentation_importer::MAX_FILE_SIZE,
        ]);
        $html = $form->render();
        $this->assertStringContainsString(get_string('presentationimportnotice', 'icontent'), $html);
        $this->assertStringContainsString('name="presentationfile"', $html);
        $this->assertStringContainsString('name="submissionid"', $html);
        $this->assertStringContainsString(get_string('importpresentation', 'icontent'), $html);
    }

    /**
     * Conversion failures surface explicitly without creating pages or retaining job files.
     */
    public function test_worker_conversion_failure_is_atomic(): void {
        global $DB, $CFG;
        $this->start_worker('/usr/bin/false');
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('icontent', ['course' => $course->id]);
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $file = get_file_storage()->create_file_from_pathname([
            'contextid' => $context->id, 'component' => 'mod_icontent',
            'filearea' => 'test', 'itemid' => 0, 'filepath' => '/', 'filename' => 'fixture.odp',
        ], $this->create_deck(make_request_directory()));
        try {
            presentation_importer::import($file, $icontent, $cm, $context, 0);
            $this->fail('The failed converter was reported as a successful import.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('presentationconversionfailed', $exception->errorcode);
        }
        $this->assertSame(0, $DB->count_records('icontent_pages', ['icontentid' => $icontent->id]));
        foreach (new \DirectoryIterator($CFG->tempdir . '/icontent-presentations') as $entry) {
            $this->assertFalse($entry->isDir() && !$entry->isDot(), 'A failed import retained its job directory.');
        }
        $this->assertTrue(presentation_importer::is_available());
    }

    /**
     * The worker refuses source symlinks instead of exposing files outside a job.
     */
    public function test_worker_rejects_source_symlink(): void {
        global $CFG;
        $this->start_worker();
        $path = $this->create_deck(make_request_directory());
        $directory = $CFG->tempdir . '/icontent-presentations/' . bin2hex(random_bytes(16));
        mkdir($directory, 0700);
        try {
            symlink($path, $directory . '/presentation.odp');
            file_put_contents($directory . '/request.json', '{"extension":"odp"}');
            $deadline = microtime(true) + 10;
            while (!file_exists($directory . '/result.json') && microtime(true) < $deadline) {
                usleep(100000);
            }
            $this->assertFileExists($directory . '/result.json');
            $result = json_decode(file_get_contents($directory . '/result.json'), true, 8, JSON_THROW_ON_ERROR);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('Invalid presentation source', $result['detail']);
            $this->assertDirectoryDoesNotExist($directory . '/output');
        } finally {
            remove_dir($directory);
        }
    }

    /**
     * Actual LibreOffice conversion and editable import succeed for every requested format.
     */
    public function test_all_four_formats_import_as_editable_pages(): void {
        global $DB;
        $this->start_worker();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('icontent', ['course' => $course->id]);
        $icontent = $DB->get_record('icontent', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('icontent', $module->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $directory = make_request_directory();
        $original = $this->create_deck($directory);
        foreach (['odp' => null, 'pptx' => 'Impress MS PowerPoint 2007 XML', 'ppt' => 'MS PowerPoint 97',
            'sxi' => null] as $extension => $filter) {
            $path = $original;
            if ($extension === 'sxi') {
                $path = $directory . '/fixture.sxi';
                $sourcezip = new \ZipArchive();
                $sourcezip->open($original);
                $legacyzip = new \ZipArchive();
                $legacyzip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                $replacements = [];
                foreach (['office', 'drawing', 'text', 'style', 'presentation', 'manifest'] as $namespace) {
                    $replacements['urn:oasis:names:tc:opendocument:xmlns:' . $namespace . ':1.0'] =
                        'http://openoffice.org/2000/' . $namespace;
                }
                $replacements['urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0'] = 'http://www.w3.org/1999/XSL/Format';
                $replacements['urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0'] = 'http://www.w3.org/2000/svg';
                $replacements['application/vnd.oasis.opendocument.presentation'] = 'application/vnd.sun.xml.impress';
                $replacements['<office:presentation>'] = '';
                $replacements['</office:presentation>'] = '';
                $replacements['<office:body>'] = '<office:body office:class="presentation">';
                $replacements['style:page-layout'] = 'style:page-master';
                $replacements['style:text-properties'] = 'style:properties';
                $replacements['text:list>'] = 'text:unordered-list>';
                $replacements['xlink:href="Pictures/'] = 'xlink:href="#Pictures/';
                for ($index = 0; $index < $sourcezip->numFiles; $index++) {
                    $name = $sourcezip->getNameIndex($index);
                    $bytes = $sourcezip->getFromIndex($index);
                    if ($name === 'content.xml') {
                        // OpenOffice 1.0 places geometry on shapes, without ODF's frame wrapper.
                        $bytes = preg_replace(
                            '/<draw:frame([^>]*)><draw:(text-box|image)\b/',
                            '<draw:$2$1',
                            $bytes
                        );
                        $bytes = str_replace('</draw:frame>', '', $bytes);
                    }
                    $legacyzip->addFromString($name, str_ends_with($name, '.xml') || $name === 'mimetype'
                        ? strtr($bytes, $replacements) : $bytes);
                }
                $legacyzip->close();
                $sourcezip->close();
            } else if ($filter !== null) {
                $process = new Process([
                    '/usr/bin/libreoffice', '-env:UserInstallation=file://' . $directory . '/fixtureprofile',
                    '--headless', '--convert-to', $extension . ':' . $filter, '--outdir', $directory, $original,
                ]);
                $process->setTimeout(120);
                $process->mustRun();
                $path = $directory . '/fixture.' . $extension;
                $this->assertFileExists($path);
            }
            $file = get_file_storage()->create_file_from_pathname([
                'contextid' => $context->id, 'component' => 'mod_icontent',
                'filearea' => 'test', 'itemid' => 0, 'filepath' => '/', 'filename' => 'fixture.' . $extension,
            ], $path);
            $result = presentation_importer::import($file, $icontent, $cm, $context, 0);
            $this->assertSame(2, $result['count'], $extension);
            $pages = array_values($DB->get_records('icontent_pages', ['icontentid' => $icontent->id], 'pagenum'));
            $this->assertStringContainsString('First editable text', $pages[count($pages) - 2]->pageicontent, $extension);
            $this->assertStringContainsString('Second editable text', $pages[count($pages) - 1]->pageicontent, $extension);
            $files = get_file_storage()->get_area_files(
                $context->id, 'mod_icontent', 'page', $result['firstid'], 'id', false
            );
            $this->assertNotEmpty($files, $extension);
        }
        $this->assertSame(8, $DB->count_records('icontent_pages', ['icontentid' => $icontent->id]));
        $this->assertSame(range(1, 8), array_map(static fn($pg) => (int)$pg->pagenum, $pages));
    }
}
