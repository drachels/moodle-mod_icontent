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
 * Finalise an iContent assignment submission.
 *
 * @package    mod_icontent
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_icontent\local\assignment;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$submissionid = required_param('submissionid', PARAM_INT);

$cm = get_coursemodule_from_id('icontent', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$icontent = $DB->get_record('icontent', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/icontent:submit', $context);
require_sesskey();
if (!data_submitted()) {
    throw new invalid_parameter_exception('Submission finalisation requires a POST request');
}
if (empty($icontent->assignmentmode)) {
    throw new invalid_parameter_exception('This activity is not configured for submissions');
}

$submission = assignment::get_current_submission($icontent, $cm, (int)$USER->id, false);
if (
    !$submission ||
    (int)$submission->id !== $submissionid ||
    !assignment::can_edit_submission($submission, $context, (int)$USER->id)
) {
    throw new required_capability_exception($context, 'mod/icontent:submit', 'nopermissions', '');
}

if (!$DB->record_exists('icontent_pages', ['submissionid' => $submissionid, 'icontentid' => $icontent->id])) {
    redirect(
        new moodle_url('/mod/icontent/view.php', ['id' => $cm->id, 'submissionid' => $submissionid]),
        get_string('assignmentemptysubmission', 'icontent'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$now = time();
$submission->status = assignment::STATUS_SUBMITTED;
$submission->timesubmitted = $now;
$submission->timemodified = $now;
$DB->update_record('icontent_submissions', $submission);

redirect(
    new moodle_url('/mod/icontent/view.php', ['id' => $cm->id, 'submissionid' => $submissionid]),
    get_string('submissionsubmitted', 'icontent')
);
