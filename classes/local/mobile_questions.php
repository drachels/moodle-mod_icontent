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
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace mod_icontent\local;

/**
 * Persistent online app question usages, independent of web-service sessions.
 *
 * @package mod_icontent
 * @copyright 2026 AL Rachels
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile_questions {
    /**
     * Load or create the user's page usage. Call while holding the page/user lock.
     *
     * @param \stdClass $page Page.
     * @param int $userid User.
     * @return array Usage, mapping record, and unsupported question types.
     */
    public static function prepare(\stdClass $page, int $userid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        $record = $DB->get_record('icontent_mobile_usages', ['pageid' => $page->id, 'userid' => $userid]);
        if ($record && $record->timecompleted && !\icontent_get_attempt_summary_by_page($page->id, $page->cmid)) {
            self::delete(['id' => $record->id]);
            $record = false;
        }
        if ($record && $record->timecompleted) {
            return [\question_engine::load_questions_usage_by_activity($record->qubaid), $record,
                json_decode($record->unsupported, true, 512, JSON_THROW_ON_ERROR)];
        }
        $definitions = [];
        $unsupported = [];
        foreach (\icontent_get_pagequestions($page->id, $page->cmid) as $mapping) {
            $qid = \icontent_get_latest_question_version_id((int)$mapping->qid);
            $question = \question_bank::load_question($qid);
            $type = $question->get_type_name();
            if (!in_array($type, self::supported_types(), true) ||
                    ($type === 'essay' && ($question->attachments || $question->responseformat === 'editorfilepicker'))) {
                $unsupported[] = $type;
                continue;
            }
            $definitions[(int)$mapping->qpid] = [$mapping, $question];
        }
        $unsupported = array_values(array_unique($unsupported));
        if ($record) {
            $quba = \question_engine::load_questions_usage_by_activity($record->qubaid);
            $slots = json_decode($record->slots, true, 512, JSON_THROW_ON_ERROR);
            $matches = count($slots) === count($definitions) &&
                json_decode($record->unsupported, true, 512, JSON_THROW_ON_ERROR) === $unsupported;
            foreach ($slots as $slot => $source) {
                if (!isset($definitions[$source['qpid']])) {
                    $matches = false;
                    break;
                }
                [$mapping, $question] = $definitions[$source['qpid']];
                if ((int)$quba->get_question($slot)->id !== (int)$question->id ||
                        abs($quba->get_question_max_mark($slot) - $mapping->maxmark) > 0.00001) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return [$quba, $record, $unsupported];
            }
            self::delete(['id' => $record->id]);
        }
        $quba = \question_engine::make_questions_usage_by_activity('mod_icontent', \context_module::instance($page->cmid));
        $quba->set_preferred_behaviour('deferredfeedback');
        $slots = [];
        foreach ($definitions as [$mapping, $question]) {
            $slot = $quba->add_question($question, (float)$mapping->maxmark);
            $slots[$slot] = ['qpid' => (int)$mapping->qpid, 'qid' => (int)$mapping->qid];
        }
        $quba->start_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);
        $record = (object)[
            'cmid' => $page->cmid, 'pageid' => $page->id, 'userid' => $userid, 'qubaid' => $quba->get_id(),
            'slots' => json_encode($slots, JSON_THROW_ON_ERROR),
            'unsupported' => json_encode(array_values(array_unique($unsupported)), JSON_THROW_ON_ERROR),
            'timecompleted' => 0,
        ];
        $record->id = $DB->insert_record('icontent_mobile_usages', $record);
        return [$quba, $record, $unsupported];
    }

    /**
     * Core app question renderers supported by this first native implementation.
     *
     * @return string[]
     */
    public static function supported_types(): array {
        return ['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'multianswer',
            'essay', 'calculated', 'calculatedsimple', 'calculatedmulti', 'ddwtos', 'ddimageortext', 'ddmarker', 'description'];
    }

    /**
     * Render engine HTML for the app's core-question component.
     *
     * @param \question_usage_by_activity $quba Usage.
     * @param bool $review Whether responses have been submitted.
     * @return array
     */
    public static function render(\question_usage_by_activity $quba, bool $review): array {
        $options = self::display_options($review);
        $questions = [];
        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            $questions[] = [
                'slot' => $slot, 'type' => $qa->get_question()->get_type_name(), 'questionnumber' => (string)$slot,
                'html' => $quba->render_question($slot, $options, $slot),
                'state' => (string)$qa->get_state(), 'status' => $qa->get_state()->default_string(true),
                'stateclass' => $qa->get_state()->get_state_class(true),
                'maxmark' => $qa->get_max_mark(), 'sequencecheck' => $qa->get_sequence_check_count(),
                'sequencefield' => $qa->get_control_field_name('sequencecheck'),
            ];
        }
        return $questions;
    }

    /**
     * Keep rendered feedback and embedded-file permissions consistent.
     *
     * @param bool $review Whether the attempt is completed.
     * @return \question_display_options
     */
    private static function display_options(bool $review): \question_display_options {
        $options = new \question_display_options();
        $options->readonly = $review;
        $options->marks = \question_display_options::MAX_ONLY;
        $options->flags = \question_display_options::HIDDEN;
        $options->correctness = $review ? \question_display_options::VISIBLE : \question_display_options::HIDDEN;
        $options->feedback = $options->generalfeedback = $options->rightanswer = $options->correctness;
        return $options;
    }

    /**
     * Authorize native question media using the usage owner and engine review settings.
     *
     * @param int $usageid Usage.
     * @param int $slot Slot.
     * @param int $filecontextid File context.
     * @param string $component File component.
     * @param string $filearea File area.
     * @param array $args File path.
     * @param bool $forcedownload Download flag.
     * @return bool Whether this file is accessible.
     */
    public static function can_access_file(
        int $usageid, int $slot, int $filecontextid, string $component, string $filearea, array $args, bool $forcedownload
    ): bool {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/mod/icontent/locallib.php');
        $usage = $DB->get_record('icontent_mobile_usages', ['qubaid' => $usageid, 'userid' => $USER->id]);
        if (!$usage) {
            return false;
        }
        $cm = get_coursemodule_from_id('icontent', $usage->cmid, 0, false, MUST_EXIST);
        require_login($cm->course, false, $cm, false, true);
        $context = \context_module::instance($cm->id);
        require_capability('mod/icontent:view', $context);
        $icontent = $DB->get_record('icontent', ['id' => $cm->instance], '*', MUST_EXIST);
        $page = $DB->get_record('icontent_pages', ['id' => $usage->pageid, 'cmid' => $cm->id], '*', MUST_EXIST);
        if (!icontent_info::icontent_available($icontent) ||
                ($page->hidden && !has_capability('mod/icontent:edit', $context))) {
            return false;
        }
        if ($page->submissionid) {
            $submission = assignment::get_submission((int)$icontent->id, (int)$page->submissionid);
            if (!$submission || !assignment::can_view_submission($submission, $icontent, $cm, $context, (int)$USER->id)) {
                return false;
            }
        }
        $quba = \question_engine::load_questions_usage_by_activity($usageid);
        if (!in_array($slot, $quba->get_slots(), true) || (int)$quba->get_question($slot)->contextid !== $filecontextid) {
            return false;
        }
        return $quba->check_file_access($slot, self::display_options(!empty($usage->timecompleted)),
            $component, $filearea, $args, $forcedownload);
    }

    /**
     * Process only response fields, rejecting replay/stale form data and behaviour controls.
     *
     * @param \stdClass $page Page.
     * @param int $userid User.
     * @param array $payload Submitted usage id and engine fields.
     * @param bool $finish Whether unsupported questions must prevent processing.
     * @return array Usage and mapping record.
     */
    private static function process(\stdClass $page, int $userid, array $payload, bool $finish): array {
        [$quba, $record, $unsupported] = self::prepare($page, $userid);
        if ((int)($payload['usageid'] ?? 0) !== (int)$record->qubaid || $record->timecompleted ||
                \icontent_get_attempt_summary_by_page($page->id, $page->cmid)) {
            throw new \invalid_parameter_exception('This question attempt is no longer available for submission.');
        }
        if (($finish && $unsupported) || !$quba->get_slots() || !is_array($payload['answers'] ?? null)) {
            throw new \invalid_parameter_exception('This page contains questions that require the browser.');
        }
        $answers = [];
        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            foreach ($qa->get_question()->get_expected_data() as $field => $type) {
                $name = $qa->get_qt_field_name($field);
                if (array_key_exists($name, $payload['answers'])) {
                    $answers[$name] = $payload['answers'][$name];
                }
            }
            $name = $qa->get_control_field_name('sequencecheck');
            if (isset($payload['answers'][$name])) {
                $answers[$name] = $payload['answers'][$name];
            }
        }
        $answers['slots'] = implode(',', $quba->get_slots());
        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            $name = $qa->get_control_field_name('sequencecheck');
            if (!isset($answers[$name]) || (int)$answers[$name] !== $qa->get_sequence_check_count()) {
                throw new \invalid_parameter_exception('Stale question response. Refresh the page before answering.');
            }
        }
        $quba->process_all_actions(time(), $answers);
        return [$quba, $record];
    }

    /**
     * Persist draft responses without grading or completing the page.
     *
     * @param \stdClass $page Page.
     * @param int $userid User.
     * @param array $payload Engine responses.
     * @return void
     */
    public static function save(\stdClass $page, int $userid, array $payload): void {
        [$quba] = self::process($page, $userid, $payload, false);
        \question_engine::save_questions_usage_by_activity($quba);
    }

    /**
     * Finish and record a complete attempt, using the engine's marks.
     *
     * @param \stdClass $page Page.
     * @param \stdClass $icontent Activity.
     * @param int $userid User.
     * @param array $payload Engine responses.
     * @return void
     */
    public static function submit(\stdClass $page, \stdClass $icontent, int $userid, array $payload): void {
        global $DB;
        [$quba, $record] = self::process($page, $userid, $payload, true);
        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            $question = $qa->get_question();
            if ($question instanceof \question_with_responses && !$question->is_complete_response($qa->get_last_qt_data())) {
                throw new \invalid_parameter_exception('Answer every question before submitting.');
            }
        }
        $quba->finish_all_questions();
        $slots = json_decode($record->slots, true, 512, JSON_THROW_ON_ERROR);
        $now = time();
        $records = [];
        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            if ($qa->get_question()->get_type_name() === 'description') {
                continue;
            }
            $manual = $qa->get_fraction() === null;
            $essay = $qa->get_question()->get_type_name() === 'essay';
            $records[] = (object)[
                'pagesquestionsid' => $slots[$slot]['qpid'], 'questionid' => $slots[$slot]['qid'],
                'userid' => $userid, 'cmid' => $page->cmid,
                'fraction' => $manual ? 0 : $qa->get_mark(), 'maxmark' => $qa->get_max_mark(),
                'rightanswer' => $manual ? ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE : $qa->get_right_answer_summary(),
                'answertext' => $essay ? $qa->get_last_qt_var('answer', '') : $qa->get_response_summary(),
                'answertextformat' => $essay ? (int)$qa->get_last_qt_var('answerformat', FORMAT_HTML) : FORMAT_PLAIN,
                'timecreated' => $now,
            ];
        }
        if (!$records) {
            throw new \invalid_parameter_exception('There are no gradeable questions on this page.');
        }
        $transaction = $DB->start_delegated_transaction();
        \question_engine::save_questions_usage_by_activity($quba);
        $DB->insert_records('icontent_question_attempts', $records);
        $DB->set_field('icontent_mobile_usages', 'timecompleted', $now, ['id' => $record->id]);
        \icontent_set_grade_item($icontent, $page->cmid, $userid);
        \mod_icontent\event\question_attempt_created::create_from_question_attempt(
            $icontent, \context_module::instance($page->cmid), $page->id
        )->trigger();
        $transaction->allow_commit();
    }

    /**
     * Remove usages and engine data using explicit, scoped conditions.
     *
     * @param array $conditions Mapping-table conditions.
     * @return void
     */
    public static function delete(array $conditions): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        foreach ($DB->get_records('icontent_mobile_usages', $conditions) as $usage) {
            \question_engine::delete_questions_usage_by_activity($usage->qubaid);
            $DB->delete_records('icontent_mobile_usages', ['id' => $usage->id]);
        }
    }
}
