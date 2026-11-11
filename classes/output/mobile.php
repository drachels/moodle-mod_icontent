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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_icontent\output;

use mod_icontent\local\assignment;
use mod_icontent\local\icontent_info;
use mod_icontent\local\mobile_questions;
use mod_icontent\local\mobile_notes;

/**
 * Online Moodle-app pages, question attempts and page notes.
 *
 * @package mod_icontent
 * @copyright 2026 AL Rachels
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
        $action = $args['action'] ?? '';
        if (!in_array($action, ['', 'save', 'submit', 'retry', 'addnote', 'editnote', 'replynote', 'deletenote', 'likenote'], true)) {
            throw new \invalid_parameter_exception('Invalid mobile action.');
        }
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
        $questions = [];
        $notes = [];
        $pastanswers = [];
        if (!icontent_info::icontent_available($icontent)) {
            if ($action) {
                throw new \moodle_exception('mobileactivityunavailable', 'icontent');
            }
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
                $source = assignment::get_last_viewed_page((int)$cm->id, (int)$USER->id, $submissionid);
                $manager = has_any_capability(['mod/icontent:edit', 'mod/icontent:manage', 'mod/icontent:grade'], $context);
                if ($source && !$manager && !assignment::can_navigate_to_page($source, $selected, (int)$USER->id)) {
                    throw new \moodle_exception('pagegradingrequired', 'icontent');
                }
                $lock = \core\lock\lock_config::get_lock_factory('mod_icontent')->get_lock(
                    'mobile:' . $cm->id . ':' . $selected->id . ':' . $USER->id, 10
                );
                if (!$lock) {
                    throw new \moodle_exception('locktimeout');
                }
                try {
                    $payload = json_decode($args['payload'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($payload)) {
                        throw new \invalid_parameter_exception('Invalid mobile action payload.');
                    }
                    if ($action === 'save') {
                        mobile_questions::save($selected, (int)$USER->id, $payload);
                    } else if ($action === 'submit') {
                        mobile_questions::submit($selected, $icontent, (int)$USER->id, $payload);
                    } else if ($action === 'retry') {
                        if (!\icontent_user_can_remove_attempts_answers_for_tryagain($selected->id, $cm->id)) {
                            throw new \required_capability_exception($context, 'mod/icontent:answerquestionstryagain',
                                'nopermissions', '');
                        }
                        mobile_questions::delete(['pageid' => $selected->id, 'userid' => $USER->id]);
                        foreach ($DB->get_records('icontent_pages_questions', [
                            'pageid' => $selected->id, 'cmid' => $cm->id,
                        ], '', 'id') as $mapping) {
                            $DB->delete_records('icontent_question_attempts', [
                                'userid' => $USER->id, 'cmid' => $cm->id, 'pagesquestionsid' => $mapping->id,
                            ]);
                        }
                        \icontent_set_grade_item($icontent, $cm->id, $USER->id);
                    } else if ($action) {
                        mobile_notes::mutate($action, $payload, $selected, $icontent, $context);
                    }
                    if ($DB->record_exists('icontent_pages_questions', ['pageid' => $selected->id, 'cmid' => $cm->id])) {
                        [$quba, $usage, $unsupported] = mobile_questions::prepare($selected, (int)$USER->id);
                        $review = !empty($usage->timecompleted);
                        $questions = mobile_questions::render($quba, $review);
                        $data['usageid'] = $usage->qubaid;
                        $data['review'] = $review ? 'true' : 'false';
                        $data['unsupported'] = !empty($unsupported);
                        $gradeable = array_filter($questions, static fn($question) => $question['type'] !== 'description');
                        $data['cansubmit'] = !$unsupported && !$review && !empty($gradeable) &&
                            !\icontent_get_attempt_summary_by_page($selected->id, $cm->id);
                        $data['canretry'] = (bool)\icontent_get_attempt_summary_by_page($selected->id, $cm->id) &&
                            \icontent_user_can_remove_attempts_answers_for_tryagain($selected->id, $cm->id);
                        if (\icontent_get_attempt_summary_by_page($selected->id, $cm->id)) {
                            if (!$review) {
                                $questions = [];
                            }
                            $data['browserresults'] = true;
                            foreach (\icontent_get_submitted_answers_by_attempt_summary_by_page($selected->id, $cm->id) as $answer) {
                                $pastanswers[] = [
                                    'name' => $answer->questionname,
                                    'answer' => strip_tags($answer->answertext),
                                    'mark' => (float)$answer->fraction, 'maxmark' => (float)$answer->maxmark,
                                ];
                            }
                        }
                    }
                    if (!empty($icontent->shownotesarea) && has_capability('mod/icontent:viewnotes', $context)) {
                        $notes = mobile_notes::read($selected, $context);
                    }
                } finally {
                    $lock->release();
                }
                if (!$action) {
                    \icontent_add_pagedisplayed($selected->id, $cm->id);
                }
                $browserurl->param('pageid', $selected->id);
                $data['showtitle'] = !empty($selected->showtitle);
                $data['pagetitle'] = format_string($selected->title, true, ['context' => $context]);
                $data['pageid'] = $selected->id;
                $data['hasquestions'] = $DB->record_exists('icontent_pages_questions', [
                    'pageid' => $selected->id, 'cmid' => $cm->id,
                ]);
                $data['hasnotes'] = !empty($icontent->shownotesarea) &&
                    has_capability('mod/icontent:viewnotes', $context);
                $data['questionslabel'] = get_string('answerthequestions', 'icontent');
                $data['noteslabel'] = get_string('doubtandnotes', 'icontent');
                $data['interactionnotice'] = get_string('mobileinteractionnotice', 'icontent');
                $data['questionsbrowserlabel'] = get_string('mobileopenquestions', 'icontent');
                $data['notesbrowserlabel'] = get_string('mobileopennotes', 'icontent');
                foreach (['save', 'cancel', 'note', 'doubt', 'reply', 'edit', 'delete', 'like', 'unlike',
                        'private', 'featured', 'submit', 'tryagain'] as $label) {
                    $data[$label . 'label'] = get_string('mobilenative' . $label, 'icontent');
                }
                $data['unsupportednotice'] = get_string('mobilenativeunsupported', 'icontent');
                $data['deleteconfirm'] = get_string('mobilenativedeleteconfirm', 'icontent');
                $data['canprivate'] = has_capability('mod/icontent:checkboxprivatenotes', $context);
                $data['canfeatured'] = has_capability('mod/icontent:checkboxfeaturednotes', $context);
                $data['cantutor'] = has_capability('mod/icontent:checkboxdoubttutornotes', $context);
                $data['tutorlabel'] = get_string('mobilenativetutor', 'icontent');
                $data['pointslabel'] = get_string('mobilenativepoints', 'icontent');
                $data['confirmretry'] = get_string('mobilenativeretryconfirm', 'icontent');
                $data['savedraftlabel'] = get_string('mobilenativesavedraft', 'icontent');
                foreach ($data['pages'] as &$entry) {
                    $target = $DB->get_record('icontent_pages', ['id' => $entry['id']], '*', MUST_EXIST);
                    $entry['blocked'] = !$manager && !assignment::can_navigate_to_page($selected, $target, (int)$USER->id);
                }
                unset($entry);
                $questionsurl = clone $browserurl;
                $questionsurl->set_anchor('idquestionsarea');
                $data['questionsurl'] = $questionsurl->out(false);
                $notesurl = clone $browserurl;
                $notesurl->set_anchor('idnotesarea');
                $data['notesurl'] = $notesurl->out(false);
                $text = file_rewrite_pluginfile_urls(
                    $selected->pageicontent, 'webservice/pluginfile.php',
                    $context->id, 'mod_icontent', 'page', $selected->id
                );
                $text = \icontent_replace_dynamic_page_placeholders($text, $context);
                $content = format_text($text, $selected->pageicontentformat, [
                    'context' => $context, 'noclean' => false, 'overflowdiv' => true,
                ]);
                if (!$action) {
                    \mod_icontent\event\page_viewed::create_from_page($icontent, $context, $selected)->trigger();
                }
            } else {
                if ($action) {
                    throw new \invalid_parameter_exception('Select a page before performing an action.');
                }
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
            if (!$action) {
                $event = \mod_icontent\event\course_module_viewed::create([
                    'objectid' => $icontent->id, 'context' => $context,
                ]);
                $event->add_record_snapshot('icontent', $icontent);
                $event->add_record_snapshot('course', $course);
                $event->trigger();
                (new \completion_info($course))->set_module_viewed($cm);
            }
        }
        $data['browserurl'] = $browserurl->out(false);
        return [
            'templates' => [[
                'id' => 'main',
                'html' => $OUTPUT->render_from_template('mod_icontent/mobileapp/reader', $data),
            ]],
            'otherdata' => [
                'content' => $content, 'questions' => json_encode($questions, JSON_THROW_ON_ERROR),
                'notes' => json_encode($notes, JSON_THROW_ON_ERROR),
                'pastanswers' => json_encode($pastanswers, JSON_THROW_ON_ERROR),
                'questionsexpanded' => empty($selected->expandquestionsarea) ? '0' : '1',
                'notesexpanded' => empty($selected->expandnotesarea) ? '0' : '1',
                'editablequestions' => !empty($data['cansubmit']) ? '1' : '0',
                'usageid' => (string)($data['usageid'] ?? 0),
                'pageclosed' => get_string('mobilenativepageclosed', 'icontent'),
                'args' => json_encode(['cmid' => (int)$cmid, 'courseid' => (int)$course->id,
                    'submissionid' => (int)$submissionid, 'pageid' => (int)($data['pageid'] ?? 0)], JSON_THROW_ON_ERROR),
            ],
            'javascript' => file_get_contents($CFG->dirroot . '/mod/icontent/mobileapp/mobile.js'),
        ];
    }

}
