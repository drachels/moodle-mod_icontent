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

namespace mod_icontent\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Standalone editable presentation import using a restricted LibreOffice process.
 *
 * @package mod_icontent
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class presentation_importer {
    /** Accepted presentation extensions. */
    public const EXTENSIONS = ['pptx', 'ppt', 'odp', 'sxi'];
    /** Maximum uploaded bytes. */
    public const MAX_FILE_SIZE = 52428800;
    /** Maximum total unpacked bytes. */
    private const MAX_EXPANDED_SIZE = 209715200;
    /** Maximum single package part. */
    private const MAX_PART_SIZE = 20971520;
    /** Maximum slides per import. */
    private const MAX_SLIDES = 100;
    /** ODF namespaces used by the editable reader. */
    private const NS = [
        'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
        'draw' => 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0',
        'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
        'table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
        'style' => 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
        'fo' => 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0',
        'xlink' => 'http://www.w3.org/1999/xlink',
        'presentation' => 'urn:oasis:names:tc:opendocument:xmlns:presentation:1.0',
    ];

    /**
     * Determine whether the dedicated conversion worker is enabled and responding.
     *
     * @return bool
     */
    public static function is_available(): bool {
        global $CFG;
        if (PHP_OS_FAMILY !== 'Linux' || !get_config('mod_icontent', 'presentationworker')) {
            return false;
        }
        $heartbeat = $CFG->tempdir . '/icontent-presentations/worker.ready';
        clearstatcache(true, $heartbeat);
        return is_file($heartbeat) && filemtime($heartbeat) > time() - 10;
    }

    /**
     * Authorize importing into references or an editable submission.
     *
     * @param \stdClass $icontent Activity.
     * @param \stdClass $cm Course module.
     * @param \context_module $context Context.
     * @param int $submissionid Target page set.
     */
    public static function require_access(
        \stdClass $icontent, \stdClass $cm, \context_module $context, int $submissionid
    ): void {
        global $USER;
        if ($submissionid === 0) {
            require_capability('mod/icontent:edit', $context);
        } else if (!assignment::can_edit_page(
            (object)['submissionid' => $submissionid], $icontent, $cm, $context, (int)$USER->id
        )) {
            throw new \required_capability_exception($context, 'mod/icontent:submit', 'nopermissions', '');
        }
    }

    /**
     * Ask the dedicated worker to normalize a deck inside its no-network sandbox.
     *
     * @param string $source Source pathname.
     * @param string $directory Private request directory.
     * @return string Converted ODP path.
     */
    public static function convert(string $source, string $directory): string {
        if (!self::is_available()) {
            throw new \moodle_exception('presentationtoolsunavailable', 'icontent');
        }
        $factory = \core\lock\lock_config::get_lock_factory('mod_icontent');
        $lock = $factory->get_lock('presentation-conversion', 5);
        if (!$lock) {
            throw new \moodle_exception('presentationworkerbusy', 'icontent');
        }
        try {
            return self::request_conversion($source, $directory);
        } finally {
            $lock->release();
        }
    }

    /**
     * Publish one job and wait for its worker result while holding the conversion lock.
     *
     * @param string $source Source pathname.
     * @param string $directory Private job directory.
     * @return string Converted ODP path.
     */
    private static function request_conversion(string $source, string $directory): string {
        $request = json_encode(['extension' => strtolower(pathinfo($source, PATHINFO_EXTENSION))], JSON_THROW_ON_ERROR);
        if (file_put_contents($directory . '/request.tmp', $request) === false ||
            !rename($directory . '/request.tmp', $directory . '/request.json')) {
            throw new \moodle_exception('presentationconversionfailed', 'icontent');
        }
        $deadline = microtime(true) + 150;
        $resultpath = $directory . '/result.json';
        do {
            clearstatcache(true, $resultpath);
            if (is_file($resultpath)) {
                $result = json_decode(file_get_contents($resultpath), true, 8, JSON_THROW_ON_ERROR);
                $converted = $directory . '/output/presentation.odp';
                if (empty($result['success']) || !is_file($converted) || filesize($converted) > self::MAX_FILE_SIZE) {
                    throw new \moodle_exception(
                        'presentationconversionfailed', 'icontent', '', null, $result['detail'] ?? 'Missing converted file.'
                    );
                }
                return $converted;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        throw new \moodle_exception('presentationworkertimeout', 'icontent');
    }

    /**
     * Create a private job directory shared only with this site's worker.
     *
     * @return string
     */
    private static function make_job_directory(): string {
        global $CFG;
        $base = make_writable_directory($CFG->tempdir . '/icontent-presentations');
        $directory = $base . '/' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) {
            throw new \moodle_exception('presentationconversionfailed', 'icontent');
        }
        \core\shutdown_manager::register_function('remove_dir', [$directory]);
        return $directory;
    }

    /**
     * Parse an ODF presentation into editable HTML slides and embedded raster image bytes.
     *
     * @param string $path ODP pathname.
     * @return array Slide records with title, html, images and warnings.
     */
    public static function read_slides(string $path): array {
        $zip = self::open_package($path);
        try {
            $xml = $zip->getFromName('content.xml');
            if ($xml === false || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                throw new \moodle_exception('presentationinvalid', 'icontent');
            }
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                if (!$document->loadXML($xml, LIBXML_NONET)) {
                    throw new \moodle_exception('presentationinvalid', 'icontent');
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $xpath = new \DOMXPath($document);
            foreach (self::NS as $prefix => $namespace) {
                $xpath->registerNamespace($prefix, $namespace);
            }
            $pages = $xpath->query('/office:document-content/office:body/office:presentation/draw:page');
            if ($pages->length === 0 || $pages->length > self::MAX_SLIDES) {
                throw new \moodle_exception('presentationslidecount', 'icontent', '', self::MAX_SLIDES);
            }
            $slides = [];
            foreach ($pages as $index => $page) {
                $images = [];
                $warnings = 0;
                $title = '';
                $titles = $xpath->query('.//draw:frame[@presentation:class="title"]//text:p', $page);
                if ($titles !== false && $titles->length) {
                    $title = trim($titles->item(0)->textContent);
                }
                $html = '';
                foreach ($page->childNodes as $node) {
                    $html .= self::render_node($node, $zip, $images, $warnings, $xpath);
                }
                if ($title === '') {
                    $title = $page->getAttributeNS(self::NS['draw'], 'name') ?: get_string('importedslidetitle', 'icontent', $index + 1);
                }
                if (trim($html) === '') {
                    $html = '<p>' . s(get_string('presentationemptyslide', 'icontent')) . '</p>';
                    $warnings++;
                }
                $slides[] = (object)[
                    'title' => \core_text::substr($title, 0, 255), 'html' => $html,
                    'images' => $images, 'warnings' => $warnings,
                ];
            }
            return $slides;
        } finally {
            $zip->close();
        }
    }

    /**
     * Open a package after checking decompressed size limits, including before conversion.
     *
     * @param string $path Package pathname.
     * @return \ZipArchive Open package; caller must close it.
     */
    private static function open_package(string $path): \ZipArchive {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \moodle_exception('presentationinvalid', 'icontent');
        }
        try {
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false || $stat['size'] > self::MAX_PART_SIZE ||
                    ($total += $stat['size']) > self::MAX_EXPANDED_SIZE) {
                    throw new \moodle_exception('presentationtoolarge', 'icontent');
                }
            }
        } catch (\Throwable $exception) {
            $zip->close();
            throw $exception;
        }
        return $zip;
    }

    /**
     * Render only recognized editable ODF nodes; never pass uploaded markup through as HTML.
     *
     * @param \DOMNode $node ODF node.
     * @param \ZipArchive $zip Package.
     * @param array $images Embedded images.
     * @param int $warnings Unsupported content counter.
     * @param \DOMXPath $xpath Namespace-aware query.
     * @return string HTML.
     */
    private static function render_node(
        \DOMNode $node, \ZipArchive $zip, array &$images, int &$warnings, \DOMXPath $xpath
    ): string {
        if ($node instanceof \DOMText) {
            return s($node->nodeValue);
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $name = $node->localName;
        $ns = $node->namespaceURI;
        if ($ns === self::NS['office']) {
            return '';
        }
        if ($ns === self::NS['draw'] && $name === 'image') {
            $href = ltrim($node->getAttributeNS(self::NS['xlink'], 'href'), './');
            if (!preg_match('#^Pictures/[A-Za-z0-9_.-]+$#D', $href)) {
                $warnings++;
                return '';
            }
            $bytes = $zip->getFromName($href);
            $info = $bytes === false ? false : @getimagesizefromstring($bytes);
            if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                $warnings++;
                return '';
            }
            $filename = basename($href);
            $images[$filename] = $bytes;
            return '<p><img src="@@PLUGINFILE@@/' . s($filename) . '" alt="" class="img-fluid"></p>';
        }
        if ($ns === self::NS['draw'] && in_array($name, ['object', 'object-ole', 'plugin', 'applet'], true)) {
            $warnings++;
            return '';
        }
        if ($ns === self::NS['text'] && $name === 's') {
            return str_repeat('&#160;', min(100, max(1, (int)$node->getAttributeNS(self::NS['text'], 'c'))));
        }
        if ($ns === self::NS['text'] && $name === 'line-break') {
            return '<br>';
        }
        if ($ns === self::NS['text'] && $name === 'tab') {
            return '&#160;&#160;&#160;&#160;';
        }
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= self::render_node($child, $zip, $images, $warnings, $xpath);
        }
        $tags = $ns === self::NS['text']
            ? ['p' => 'p', 'h' => 'h3', 'list' => 'ul', 'list-item' => 'li', 'span' => 'span']
            : ($ns === self::NS['table'] ? ['table' => 'table', 'table-row' => 'tr', 'table-cell' => 'td'] : []);
        if (isset($tags[$name])) {
            $style = '';
            $stylename = $node->getAttributeNS(self::NS['text'], 'style-name');
            if ($stylename !== '') {
                foreach ($xpath->query('//office:automatic-styles/style:style') as $definition) {
                    if ($definition->getAttributeNS(self::NS['style'], 'name') !== $stylename) {
                        continue;
                    }
                    foreach ($xpath->query('style:text-properties', $definition) as $properties) {
                        foreach (['font-weight' => ['bold'], 'font-style' => ['italic']] as $property => $values) {
                            $value = $properties->getAttributeNS(self::NS['fo'], $property);
                            if (in_array($value, $values, true)) {
                                $style .= $property . ':' . $value . ';';
                            }
                        }
                        $color = $properties->getAttributeNS(self::NS['fo'], 'color');
                        if (preg_match('/^#[0-9a-f]{6}$/i', $color)) {
                            $style .= 'color:' . $color . ';';
                        }
                    }
                }
            }
            return \html_writer::tag($tags[$name], $html, $style ? ['style' => $style] : []);
        }
        return $html;
    }

    /**
     * Import all slides atomically into the target page set.
     *
     * @param \stored_file $file Uploaded deck.
     * @param \stdClass $icontent Activity.
     * @param \stdClass $cm Course module.
     * @param \context_module $context Context.
     * @param int $submissionid Target page set.
     * @return array Created count, first page id and unsupported-content count.
     */
    public static function import(
        \stored_file $file, \stdClass $icontent, \stdClass $cm, \context_module $context, int $submissionid
    ): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/icontent/locallib.php');
        self::require_access($icontent, $cm, $context, $submissionid);
        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true) || $file->get_filesize() === 0 ||
            $file->get_filesize() > self::MAX_FILE_SIZE) {
            throw new \moodle_exception('presentationinvalid', 'icontent');
        }
        if (!self::is_available()) {
            throw new \moodle_exception('presentationtoolsunavailable', 'icontent');
        }
        $directory = self::make_job_directory();
        $createdfiles = [];
        try {
            $source = $directory . '/presentation.' . $extension;
            if (!$file->copy_content_to($source)) {
                throw new \moodle_exception('presentationinvalid', 'icontent');
            }
            if ($extension !== 'ppt') {
                self::open_package($source)->close();
            }
            $slides = self::read_slides(self::convert($source, $directory));
            self::require_access($icontent, $cm, $context, $submissionid);
            $transaction = $DB->start_delegated_transaction();
            $number = \icontent_reserve_page_position((int)$icontent->id, (int)$cm->id, $submissionid, 0);
            $firstid = 0;
            $warnings = 0;
            foreach ($slides as $slide) {
                $page = (object)[
                    'icontentid' => $icontent->id, 'cmid' => $cm->id, 'submissionid' => $submissionid,
                    'pagenum' => $number++, 'title' => $slide->title,
                    'pageicontent' => $slide->html, 'pageicontentformat' => FORMAT_HTML,
                    'showtitle' => 1, 'hidden' => 0, 'coverpage' => 0, 'layout' => 1,
                    'bgcolor' => \icontent_normalize_hex_colour($icontent->bgcolor, 'FCFCFC'),
                    'bordercolor' => \icontent_normalize_hex_colour($icontent->bordercolor, 'E4E4E4'),
                    'borderwidth' => $icontent->borderwidth, 'showbgimage' => 0,
                    'maxnotesperpages' => $icontent->maxnotesperpages,
                    'timecreated' => time(), 'timemodified' => time(),
                ];
                $page->id = $DB->insert_record('icontent_pages', $page);
                foreach ($slide->images as $filename => $bytes) {
                    $createdfiles[] = get_file_storage()->create_file_from_string([
                        'contextid' => $context->id, 'component' => 'mod_icontent',
                        'filearea' => 'page', 'itemid' => $page->id, 'filepath' => '/', 'filename' => $filename,
                    ], $bytes);
                }
                $page = $DB->get_record('icontent_pages', ['id' => $page->id], '*', MUST_EXIST);
                \mod_icontent\event\page_created::create_from_page($icontent, $context, $page)->trigger();
                $firstid = $firstid ?: (int)$page->id;
                $warnings += $slide->warnings;
            }
            $transaction->allow_commit();
            return ['count' => count($slides), 'firstid' => $firstid, 'warnings' => $warnings];
        } catch (\Throwable $exception) {
            foreach ($createdfiles as $createdfile) {
                $createdfile->delete();
            }
            if (isset($transaction)) {
                $transaction->rollback($exception);
            }
            throw $exception;
        } finally {
            remove_dir($directory);
        }
    }
}
