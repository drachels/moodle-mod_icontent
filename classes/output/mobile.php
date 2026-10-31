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

namespace mod_icontent\output;

use mod_icontent\local\assignment;
use mod_icontent\local\icontent_info;

defined('MOODLE_INTERNAL') || die();

/**
 * Online Moodle-app reader. Interactive activity actions remain in the browser.
 *
 * @package mod_icontent
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * Render the requested visible page set for the authenticated app user.
     *
     * @param array $args App arguments.
     * @return array Mobile content response.
     */
    public static function mobile_view(array $args): array {
        global $DB, $USER, $OUTPUT, $CFG;
        require_once($CFG->dirroot . '/mod/icontent/locallib.php');
        $cmid = clean_param($args['cmid'] ?? 0, PARAM_INT);
        $submissionid = clean_param($args['submissionid'] ?? 0, PARAM_INT);
        $pageid = clean_param($args['pageid'] ?? 0, PARAM_INT);
        if ($cmid <= 0 || $submissionid < 0 || $pageid < 0 ||
            (isset($args['userid']) && (int)$args['userid'] !== (int)$USER->id)) {
            throw new \invalid_parameter_exception('Invalid mobile reader arguments.');
        }
        $cm = get_coursemodule_from_id('icontent', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        if (isset($args['courseid']) && (int)$args['courseid'] !== (int)$course->id) {
            throw new \invalid_parameter_exception('The requested course and activity do not match.');
        }
        require_login($course, false, $cm, false, true);
        $context = \context_module::instance($cm->id);
        require_capability('mod/icontent:view', $context);
        $icontent = $DB->get_record('icontent', ['id' => $cm->instance], '*', MUST_EXIST);
        $submission = null;
        if ($submissionid) {
            $submission = assignment::get_submission((int)$icontent->id, $submissionid);
            if (empty($icontent->assignmentmode) || !$submission ||
                !assignment::can_view_submission($submission, $icontent, $cm, $context, (int)$USER->id)) {
                throw new \required_capability_exception($context, 'mod/icontent:view', 'nopermissions', '');
            }
        }
        $browserurl = new \moodle_url('/mod/icontent/view.php', ['id' => $cm->id, 'submissionid' => $submissionid]);
        $data = [
            'cmid' => $cm->id,
            'courseid' => $course->id,
            'submissionid' => $submissionid,
            'notice' => get_string('mobilereadernotice', 'icontent'),
            'browserlabel' => get_string('mobileopenbrowser', 'icontent'),
            'referenceslabel' => get_string('referencepages', 'icontent'),
            'ownlabel' => get_string('mobileownpages', 'icontent'),
            'pageslabel' => get_string('modulename', 'icontent'),
            'pages' => [],
        ];
        $content = '';
        if (!icontent_info::icontent_available($icontent)) {
            $data['unavailable'] = get_string('mobileactivityunavailable', 'icontent');
        } else {
            $canedithidden = has_capability('mod/icontent:edit', $context);
            $pages = $DB->get_records('icontent_pages', [
                'icontentid' => $icontent->id, 'submissionid' => $submissionid,
            ], 'pagenum, id', 'id, title, pagenum, hidden');
            $pages = array_filter($pages, static fn($page) => !$page->hidden || $canedithidden);
            if ($pageid && !isset($pages[$pageid])) {
                throw new \required_capability_exception($context, 'mod/icontent:view', 'nopermissions', '');
            }
            $selected = $pageid ? $pages[$pageid] : reset($pages);
            foreach ($pages as $page) {
                $data['pages'][] = [
                    'id' => $page->id, 'title' => format_string($page->title, true, ['context' => $context]),
                    'current' => $selected && (int)$page->id === (int)$selected->id,
                ];
            }
            if ($selected) {
                $selected = $DB->get_record('icontent_pages', [
                    'id' => $selected->id, 'icontentid' => $icontent->id, 'submissionid' => $submissionid,
                ], '*', MUST_EXIST);
                $browserurl->param('pageid', $selected->id);
                $data['showtitle'] = !empty($selected->showtitle);
                $data['pagetitle'] = format_string($selected->title, true, ['context' => $context]);
                $data['pageid'] = $selected->id;
                $text = file_rewrite_pluginfile_urls(
                    $selected->pageicontent, 'webservice/pluginfile.php',
                    $context->id, 'mod_icontent', 'page', $selected->id
                );
                $text = \icontent_replace_dynamic_page_placeholders($text, $context);
                $content = format_text($text, $selected->pageicontentformat, [
                    'context' => $context, 'noclean' => false, 'overflowdiv' => true,
                ]);
                \mod_icontent\event\page_viewed::create_from_page($icontent, $context, $selected)->trigger();
            } else {
                $data['emptymessage'] = get_string('mobilenopages', 'icontent');
            }
            if (!empty($icontent->assignmentmode)) {
                $data['hasreferences'] = $submissionid !== 0;
                $own = assignment::get_current_submission($icontent, $cm, (int)$USER->id, false);
                if ($own && (int)$own->id !== $submissionid &&
                    assignment::can_view_submission($own, $icontent, $cm, $context, (int)$USER->id)) {
                    $data['ownsubmissionid'] = $own->id;
                }
            }
            $event = \mod_icontent\event\course_module_viewed::create([
                'objectid' => $icontent->id, 'context' => $context,
            ]);
            $event->add_record_snapshot('icontent', $icontent);
            $event->add_record_snapshot('course', $course);
            $event->trigger();
            (new \completion_info($course))->set_module_viewed($cm);
        }
        $data['browserurl'] = $browserurl->out(false);
        return [
            'templates' => [[
                'id' => 'main',
                'html' => $OUTPUT->render_from_template('mod_icontent/mobileapp/reader', $data),
            ]],
            'otherdata' => ['content' => $content],
        ];
    }

}
