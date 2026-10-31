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
 * View assignment submissions.
 *
 * @package    mod_icontent
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_icontent\local\assignment;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$mode = optional_param('mode', 'grading', PARAM_ALPHA);

$cm = get_coursemodule_from_id('icontent', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$icontent = $DB->get_record('icontent', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
$publicview = $mode === 'public';
if (empty($icontent->assignmentmode)) {
    throw new invalid_parameter_exception('This activity is not configured for submissions');
}
if ($publicview) {
    if (empty($icontent->publicsubmissions)) {
        throw new required_capability_exception($context, 'mod/icontent:view', 'nopermissions', '');
    }
} else {
    if (!has_any_capability(['mod/icontent:grade', 'mod/icontent:manage'], $context)) {
        throw new required_capability_exception($context, 'mod/icontent:grade', 'nopermissions', '');
    }
}
$cangrade = has_capability('mod/icontent:grade', $context);

$PAGE->set_url('/mod/icontent/submissions.php', ['id' => $cm->id, 'mode' => $mode]);
$PAGE->set_title($icontent->name);
$PAGE->set_heading($course->fullname);

$submissions = $DB->get_records(
    'icontent_submissions',
    ['icontentid' => $icontent->id],
    'timesubmitted DESC, timecreated DESC'
);
$table = new html_table();
$table->head = [
    get_string('fullname'),
    get_string('submissionstatus', 'icontent'),
    get_string('pages', 'mod_icontent'),
    get_string('timesubmitted', 'mod_icontent'),
];
if (!$publicview) {
    $table->head[] = get_string('submissiongrade', 'icontent');
}
$table->head[] = get_string('action');
$table->attributes['class'] = 'generaltable';

foreach ($submissions as $submission) {
    if ($publicview) {
        if ($submission->status === assignment::STATUS_DRAFT) {
            continue;
        }
        if (!assignment::can_view_submission($submission, $icontent, $cm, $context, (int)$USER->id)) {
            continue;
        }
    } else if (groups_get_activity_groupmode($cm) === SEPARATEGROUPS &&
        !has_capability('moodle/site:accessallgroups', $context)) {
        $allowedgroups = groups_get_activity_allowed_groups($cm);
        if ($submission->groupid && !isset($allowedgroups[(int)$submission->groupid])) {
            continue;
        }
        if (!$submission->groupid) {
            $usergroups = groups_get_user_groups((int)$course->id, (int)$submission->userid);
            $groupingid = (int)($cm->groupingid ?? 0);
            if (!array_intersect(array_values($usergroups[$groupingid] ?? []), array_keys($allowedgroups))) {
                continue;
            }
        }
    }

    if ($submission->groupid) {
        $ownername = get_string('group', 'group') . ': ' . format_string(groups_get_group_name((int)$submission->groupid));
    } else {
        $owner = $DB->get_record('user', ['id' => $submission->userid], 'id, firstname, lastname', IGNORE_MISSING);
        $ownername = $owner ? fullname($owner) : get_string('unknownuser');
    }

    $status = $publicview
        ? get_string('submissionsubmitted', 'icontent')
        : ($submission->status === assignment::STATUS_DRAFT
            ? get_string('submissiondraft', 'icontent')
            : ($submission->status === assignment::STATUS_GRADED
                ? get_string('submissiongraded', 'icontent')
                : get_string('submissionsubmitted', 'icontent')));
    if ($submission->status === assignment::STATUS_DRAFT && $icontent->duedate && time() > $icontent->duedate) {
        $status .= ' (' . get_string('submissionlate', 'icontent') . ')';
    } else if ($submission->timesubmitted && $icontent->duedate && $submission->timesubmitted > $icontent->duedate) {
        $status .= ' (' . get_string('submissionlate', 'icontent') . ')';
    }

    $pages = $DB->count_records('icontent_pages', [
        'icontentid' => $icontent->id,
        'submissionid' => $submission->id,
    ]);
    $submitted = $submission->timesubmitted ? userdate($submission->timesubmitted) : '-';
    $actions = html_writer::link(
        new moodle_url('/mod/icontent/view.php', [
            'id' => $cm->id,
            'submissionid' => $submission->id,
        ]),
        get_string('viewassignmentpages', 'icontent')
    );
    if (!$publicview && $cangrade) {
        $actions .= ' ' . html_writer::link(
            new moodle_url('/mod/icontent/grade_submission.php', [
                'id' => $cm->id,
                'submissionid' => $submission->id,
            ]),
            get_string('gradeassignment', 'icontent')
        );
    }

    $row = [$ownername, $status, $pages, $submitted];
    if (!$publicview) {
        $row[] = $submission->grade === null ? '-' : format_float((float)$submission->grade, 2);
    }
    $row[] = $actions;
    $table->data[] = $row;
}

echo $OUTPUT->header();
if (!$publicview && groups_get_activity_groupmode($cm) !== NOGROUPS) {
    echo groups_print_activity_menu(
        $cm,
        new moodle_url('/mod/icontent/submissions.php', ['id' => $cm->id]),
        true
    );
}
echo $OUTPUT->heading($publicview
    ? get_string('viewpublicsubmissions', 'icontent')
    : get_string('submissions', 'icontent'));
if (empty($table->data)) {
    echo $OUTPUT->notification(get_string('nosubmissions', 'icontent'), 'info');
} else {
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
