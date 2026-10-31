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
 * Manage an individual or group submission.
 *
 * @package mod_icontent
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
require_capability('mod/icontent:grade', $context);
$submission = assignment::get_submission((int)$icontent->id, $submissionid);
if (empty($icontent->assignmentmode) || !$submission ||
    !assignment::can_view_submission($submission, $icontent, $cm, $context, (int)$USER->id)) {
    throw new required_capability_exception($context, 'mod/icontent:grade', 'nopermissions', '');
}
$owner = $submission->groupid
    ? get_string('group', 'group') . ': ' . format_string(groups_get_group_name((int)$submission->groupid))
    : s(fullname($DB->get_record('user', ['id' => $submission->userid], '*', MUST_EXIST)));
$url = new moodle_url('/mod/icontent/manage_submission.php', ['id' => $cm->id, 'submissionid' => $submissionid]);
$returnurl = new moodle_url('/mod/icontent/submissions.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title($icontent->name);
$PAGE->set_heading($course->fullname);
$form = new \mod_icontent\form\submission_management($url, [
    'owner' => $owner,
    'duedate' => (int)$icontent->duedate,
    'submission' => $submission,
]);
$form->set_data((object)[
    'id' => $cm->id,
    'submissionid' => $submissionid,
    'extensionduedate' => (int)$submission->extensionduedate,
]);
if ($form->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $form->get_data()) {
    require_sesskey();
    assignment::manage_submission(
        $submissionid, $icontent, $cm, $context, (int)$data->extensionduedate, !empty($data->reopen)
    );
    redirect($returnurl, get_string('submissionmanagementsaved', 'icontent'));
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managesubmission', 'icontent'));
$form->display();
echo $OUTPUT->footer();
