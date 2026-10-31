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
 * Grade an iContent assignment submission.
 *
 * @package    mod_icontent
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

$id = required_param('id', PARAM_INT);
$submissionid = required_param('submissionid', PARAM_INT);

$cm = get_coursemodule_from_id('icontent', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$icontent = $DB->get_record('icontent', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/icontent:grade', $context);
if (empty($icontent->assignmentmode)) {
    throw new invalid_parameter_exception('This activity is not configured for submissions');
}

$submission = \mod_icontent\local\assignment::get_submission((int)$icontent->id, $submissionid);
if (!$submission) {
    throw new invalid_parameter_exception('Invalid submission');
}
if (!\mod_icontent\local\assignment::can_view_submission(
    $submission,
    $icontent,
    $cm,
    $context,
    (int)$USER->id
)) {
    throw new required_capability_exception($context, 'mod/icontent:grade', 'nopermissions', '');
}
if ($submission->status === \mod_icontent\local\assignment::STATUS_DRAFT) {
    throw new moodle_exception('submissionnotready', 'mod_icontent');
}

$scalegrade = (int)$icontent->grade < 0;
$maximumgrade = (int)$icontent->grade;
$minimumgrade = 0;
$step = '0.01';
if ($scalegrade) {
    $scale = $DB->get_field('scale', 'scale', ['id' => abs((int)$icontent->grade)], MUST_EXIST);
    $maximumgrade = count(explode(',', $scale));
    $minimumgrade = 1;
    $step = '1';
}
if ($maximumgrade <= 0) {
    throw new moodle_exception('gradeitemdisabled', 'mod_icontent');
}

if (data_submitted()) {
    require_sesskey();
    $rawgrade = required_param('grade', PARAM_RAW_TRIMMED);
    if (!is_numeric($rawgrade)) {
        throw new invalid_parameter_exception('Grade must be numeric');
    }
    $grade = (float)$rawgrade;
    if ($grade < $minimumgrade || $grade > $maximumgrade || ($scalegrade && floor($grade) !== $grade)) {
        throw new invalid_parameter_exception('Grade is outside the permitted range');
    }
    $feedback = optional_param('feedback', '', PARAM_TEXT);

    $users = [];
    if ($submission->groupid) {
        $members = get_enrolled_users($context, 'mod/icontent:submit', (int)$submission->groupid, 'u.id');
        foreach ($members as $member) {
            $users[(int)$member->id] = (int)$member->id;
        }
    } else {
        $users[(int)$submission->userid] = (int)$submission->userid;
    }
    if (!$users) {
        throw new moodle_exception('gradeassignmentnomembers', 'icontent');
    }

    $grades = [];
    foreach ($users as $userid) {
        $grades[$userid] = [
            'userid' => $userid,
            'rawgrade' => $grade,
            'usermodified' => (int)$USER->id,
        ];
    }
    $graderesult = grade_update(
        'mod/icontent',
        (int)$course->id,
        'mod',
        'icontent',
        (int)$icontent->id,
        0,
        $grades
    );
    if ($graderesult !== GRADE_UPDATE_OK) {
        throw new moodle_exception('gradeupdatefailed', 'icontent');
    }

    $submission->status = \mod_icontent\local\assignment::STATUS_GRADED;
    $submission->grade = $grade;
    $submission->feedback = $feedback;
    $submission->feedbackformat = FORMAT_PLAIN;
    $submission->grader = (int)$USER->id;
    $submission->timegraded = time();
    $submission->timemodified = time();
    $DB->update_record('icontent_submissions', $submission);

    redirect(
        new moodle_url('/mod/icontent/submissions.php', ['id' => $cm->id]),
        get_string('submissiongraded', 'icontent')
    );
}

$PAGE->set_url('/mod/icontent/grade_submission.php', ['id' => $cm->id, 'submissionid' => $submissionid]);
$PAGE->set_title($icontent->name);
$PAGE->set_heading($course->fullname);

$form = html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/mod/icontent/grade_submission.php'))->out(false),
]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
$form .= html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'submissionid',
    'value' => $submissionid,
]);
$form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$form .= html_writer::tag('label', get_string('gradeoutof', 'icontent', $maximumgrade), ['for' => 'id_grade']);
$form .= html_writer::empty_tag('input', [
    'type' => 'number',
    'id' => 'id_grade',
    'name' => 'grade',
    'min' => $minimumgrade,
    'max' => $maximumgrade,
    'step' => $step,
    'value' => $submission->grade === null ? '' : format_float((float)$submission->grade, 5),
    'required' => 'required',
    'class' => 'form-control',
]);
$form .= html_writer::tag('label', get_string('feedback', 'icontent'), ['for' => 'id_feedback']);
$form .= html_writer::tag(
    'textarea',
    s((string)$submission->feedback),
    ['id' => 'id_feedback', 'name' => 'feedback', 'rows' => 6, 'class' => 'form-control']
);
$form .= html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
$form .= html_writer::end_tag('form');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('gradeassignment', 'icontent'));
echo html_writer::link(
    new moodle_url('/mod/icontent/view.php', ['id' => $cm->id, 'submissionid' => $submissionid]),
    get_string('viewassignmentpages', 'icontent'),
    ['class' => 'btn btn-secondary mb-3']
);
echo $form;
echo $OUTPUT->footer();
