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
 * Shows a particular instance of icontent to users.
 *
 * @package    mod_icontent
 * @copyright  2016 Leo Renis Santos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_icontent\local\icontent_info;
use mod_icontent\local\assignment;

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');
require_once(__DIR__ . '/lib.php');
require_once("$CFG->libdir/resourcelib.php");

$id = optional_param('id', 0, PARAM_INT); // Course_module ID.
$n  = optional_param('n', 0, PARAM_INT); // Icontent instance ID.
$edit = optional_param('edit', -1, PARAM_BOOL); // Edit mode.
$pageid = optional_param('pageid', null, PARAM_INT); // Chapter ID.
$requestedsubmissionid = optional_param('submissionid', 0, PARAM_INT);
$removeqpid = optional_param('removeqpid', 0, PARAM_INT);
$savetags = optional_param('savetags', 0, PARAM_BOOL);

if ($pageid !== null && $pageid <= 0) {
    throw new invalid_parameter_exception('Invalid pageid value');
}

if ($id && $n) {
    throw new invalid_parameter_exception('Invalid request: provide either id or n, not both');
}

if (!$id && !$n && $pageid === null) {
    throw new invalid_parameter_exception('Missing required parameter: id, n, or pageid');
}

$cm = null;
$course = null;
$icontent = null;
$page = null;

// Resolve activity from pageid when arriving from page links.
if ($pageid !== null) {
    $page = $DB->get_record('icontent_pages', ['id' => $pageid], 'id, cmid, submissionid', MUST_EXIST);
    $cm = get_coursemodule_from_id('icontent', $page->cmid, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $icontent = $DB->get_record('icontent', ['id' => $cm->instance], '*', MUST_EXIST);
}
if ($id) {
    $cm = get_coursemodule_from_id('icontent', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $icontent = $DB->get_record('icontent', ['id' => $cm->instance], '*', MUST_EXIST);
} else if ($n) {
    $icontent = $DB->get_record('icontent', ['id' => $n], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $icontent->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('icontent', $icontent->id, $course->id, false, MUST_EXIST);
}

if (!$cm) {
    throw new moodle_exception(get_string('incorrectmodule', 'icontent'));
}

if (!$course) {
    throw new moodle_exception(get_string('incorrectcourseid', 'icontent'));
}

if ($pageid !== null && (!$page || (int)$page->cmid !== (int)$cm->id)) {
    throw new invalid_parameter_exception('Invalid pageid for this icontent module');
}

// Check login access.
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
$allowedit = has_capability('mod/icontent:edit', $context);
$canmanage = has_any_capability(['mod/icontent:edit', 'mod/icontent:manage', 'mod/icontent:grade'], $context);
$isassignmentstudent = !empty($icontent->assignmentmode) &&
    has_capability('mod/icontent:submit', $context) && !$canmanage;
$submission = null;
$ownsubmission = null;
$submissionid = 0;
if (!empty($icontent->assignmentmode)) {
    if ($isassignmentstudent) {
        $ownsubmission = assignment::get_current_submission($icontent, $cm, (int)$USER->id);
        $hasreferencepages = $DB->record_exists('icontent_pages', [
            'icontentid' => (int)$icontent->id,
            'submissionid' => 0,
            'hidden' => 0,
        ]);
        if ($pageid !== null) {
            if ($requestedsubmissionid && $requestedsubmissionid !== (int)$page->submissionid) {
                throw new invalid_parameter_exception('The requested page and submission do not match');
            }
            $requestedsubmissionid = (int)$page->submissionid;
        } else if (!$requestedsubmissionid && !$hasreferencepages && $ownsubmission) {
            $requestedsubmissionid = (int)$ownsubmission->id;
        }
    }

    if ($requestedsubmissionid > 0) {
        $submission = assignment::get_submission((int)$icontent->id, $requestedsubmissionid);
        if (!$submission || !assignment::can_view_submission(
            $submission,
            $icontent,
            $cm,
            $context,
            (int)$USER->id
        )) {
            throw new required_capability_exception($context, 'mod/icontent:view', 'nopermissions', '');
        }
        $submissionid = (int)$submission->id;
    }
}

// Log this request.
$event = \mod_icontent\event\course_module_viewed::create(
    [
        'objectid' => $PAGE->cm->instance,
        'context' => $PAGE->context,
    ]
);
$event->add_record_snapshot('course', $PAGE->course);
$event->add_record_snapshot($PAGE->cm->modname, $icontent);
$event->trigger();

// Mark module as viewed for completion.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Check permissions.
$edit = icontent_has_permission_edition($allowedit, $edit);

if ($removeqpid > 0) {
    require_sesskey();
    if (!has_any_capability(['mod/icontent:edit', 'mod/icontent:manage'], $context)) {
        throw new required_capability_exception($context, 'mod/icontent:edit', 'nopermissions', '');
    }

    if ($pageid === null) {
        throw new moodle_exception(get_string('incorrectpage', 'icontent'));
    }

    $pagerecord = $DB->get_record('icontent_pages', [
        'id' => $pageid,
        'cmid' => $cm->id,
        'icontentid' => $icontent->id,
    ], '*', MUST_EXIST);
    if (!assignment::can_view_page($pagerecord, $icontent, $cm, $context, (int)$USER->id)) {
        throw new required_capability_exception($context, 'mod/icontent:view', 'nopermissions', '');
    }

    if (icontent_checks_answers_of_currentpage((int)$pagerecord->id, (int)$cm->id)) {
        redirect(
            new moodle_url('/mod/icontent/view.php', ['id' => $cm->id, 'pageid' => $pageid]),
            get_string('msgstatusdisplay', 'mod_icontent'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    $questionmapping = $DB->get_record('icontent_pages_questions', [
        'id' => $removeqpid,
        'pageid' => $pageid,
        'cmid' => $cm->id,
    ], '*', MUST_EXIST);

    icontent_remove_questionpagebyid((int)$questionmapping->id);

    redirect(
        new moodle_url('/mod/icontent/view.php', ['id' => $cm->id, 'pageid' => $pageid]),
        get_string('msgsucessexclusion', 'mod_icontent')
    );
}

if ($savetags) {
    require_sesskey();

    if ($pageid === null) {
        throw new moodle_exception(get_string('incorrectpage', 'icontent'));
    }

    require_capability('mod/icontent:edit', $context);
    $pagerecord = $DB->get_record('icontent_pages', [
        'id' => $pageid,
        'cmid' => $cm->id,
        'icontentid' => $icontent->id,
    ], '*', MUST_EXIST);
    if (!assignment::can_view_page($pagerecord, $icontent, $cm, $context, (int)$USER->id)) {
        throw new required_capability_exception($context, 'mod/icontent:view', 'nopermissions', '');
    }

    $pagetags = optional_param('pagetags', '', PARAM_RAW_TRIMMED);
    icontent_save_page_tags((int)$pageid, (int)$cm->id, $context, $pagetags);

    redirect(
        new moodle_url('/mod/icontent/view.php', ['id' => $cm->id, 'pageid' => $pageid]),
        get_string('msgsucess', 'mod_icontent')
    );
}

// Read pages.
$pages = icontent_info::icontent_preload_pages($icontent, $submissionid);

if ($allowedit && !$pages && (!$icontent->assignmentmode || !$submissionid)) {
    redirect('edit.php?cmid=' . $cm->id); // No pages - add new one.
}

// Print the page header.
$PAGE->set_url('/mod/icontent/view.php', ['id' => $cm->id, 'submissionid' => $submissionid]);
$PAGE->force_settings_menu();
$PAGE->set_title($icontent->name);
$PAGE->set_heading(format_string($course->fullname));
// Get renderer.
$renderer = $PAGE->get_renderer('mod_icontent');
// JS.
$renderer->icontent_requires_external_js();
$renderer->icontent_requires_internal_js();
// CSS.
$renderer->icontent_requires_css();
// Get first page to be presented.
$startwithpage = $pageid !== null
    ? icontent_get_pagenum_by_pageid($pageid)
    : icontent_get_startpagenum($icontent, $context, $submissionid);
$showpage = $pages
    ? icontent_get_fullpageicontent($startwithpage, $icontent, $context, $pageid ?? 0, $submissionid)
    : (object)['fullpageicontent' => ''];

icontent_add_fake_block($pages, $startwithpage, $icontent, $cm, $edit); // Add block summary.

// Content display HTML code.

// Check whether icontent is currently available.
$timenow = time();
if ($course->format == 'weeks' && $icontent->days) {
    $sectionnumber = $DB->get_field('course_sections', 'section', ['id' => $cm->section], MUST_EXIST);
    $timestart = $course->startdate + (($sectionnumber - 1) * WEEKSECS);
    if ($icontent->days) {
        $timefinish = $timestart + (3600 * 24 * $icontent->days);
    } else {
        $timefinish = $course->enddate;
    }
} else if (!(icontent_info::icontent_available($icontent))) {
    // Use configured availability time limits.
    $timestart = $icontent->timeopen;
    $timefinish = $icontent->timeclose;
    $icontent->days = 0;
} else {
    // No time limits for this icontent.
    $timestart = $timenow - 1;
    $timefinish = $timenow + 1;
    $icontent->days = 0;
}

// Output starts here.
echo $OUTPUT->header();
if (
    !empty($icontent->assignmentmode) &&
    !empty($icontent->teamsubmission) &&
    groups_get_activity_groupmode($cm) !== NOGROUPS
) {
    echo groups_print_activity_menu(
        $cm,
        new moodle_url('/mod/icontent/view.php', ['id' => $cm->id]),
        true
    );
}
// Output intro for pre-4.0 branches.
if (($icontent->intro) && ($CFG->branch < 400)) {
    echo $OUTPUT->heading($icontent->name);
    echo $OUTPUT->box(format_module_intro('icontent', $icontent, $cm->id), 'generalbox mod_introbox', 'icontentintro');
}

if (!empty($icontent->assignmentmode)) {
    echo html_writer::start_div('icontent-assignment-status mb-3');
    if ($isassignmentstudent) {
        if ($ownsubmission) {
            $myseturl = new moodle_url('/mod/icontent/view.php', [
                'id' => $cm->id,
                'submissionid' => $ownsubmission->id,
            ]);
            if ($submissionid !== (int)$ownsubmission->id) {
                echo $OUTPUT->single_button($myseturl, get_string('myassignmentpages', 'icontent'), 'get');
            } else if ($hasreferencepages = $DB->record_exists('icontent_pages', [
                'icontentid' => (int)$icontent->id,
                'submissionid' => 0,
                'hidden' => 0,
            ])) {
                echo $OUTPUT->single_button(
                    new moodle_url('/mod/icontent/view.php', ['id' => $cm->id]),
                    get_string('referencepages', 'icontent'),
                    'get'
                );
            }

            if ((int)$ownsubmission->id === $submissionid) {
                $status = $ownsubmission->status === assignment::STATUS_DRAFT
                    ? get_string('submissiondraft', 'icontent')
                    : ($ownsubmission->status === assignment::STATUS_GRADED
                        ? get_string('submissiongraded', 'icontent')
                        : get_string('submissionsubmitted', 'icontent'));
                echo html_writer::tag('p', get_string('submissionstatus', 'icontent') . ': ' . $status);
                if (!empty($icontent->duedate)) {
                    echo html_writer::tag(
                        'p',
                        get_string('duedate', 'icontent') . ': ' . userdate((int)$icontent->duedate)
                    );
                }

                if ($ownsubmission->status === assignment::STATUS_DRAFT) {
                    echo html_writer::tag('p', get_string('submissionprivate', 'icontent'));
                    echo $OUTPUT->single_button(
                        new moodle_url('/mod/icontent/edit.php', [
                            'cmid' => $cm->id,
                            'submissionid' => $ownsubmission->id,
                        ]),
                        get_string('addnewpage', 'icontent'),
                        'get'
                    );
                    if ($pageid !== null) {
                        echo $OUTPUT->single_button(
                            new moodle_url('/mod/icontent/edit.php', [
                                'cmid' => $cm->id,
                                'id' => $pageid,
                                'submissionid' => $ownsubmission->id,
                            ]),
                            get_string('edit'),
                            'get'
                        );
                    }
                    $submitform = html_writer::start_tag('form', [
                        'method' => 'post',
                        'action' => (new moodle_url('/mod/icontent/submit.php'))->out(false),
                    ]);
                    $submitform .= html_writer::empty_tag('input', [
                        'type' => 'hidden',
                        'name' => 'id',
                        'value' => $cm->id,
                    ]);
                    $submitform .= html_writer::empty_tag('input', [
                        'type' => 'hidden',
                        'name' => 'submissionid',
                        'value' => $ownsubmission->id,
                    ]);
                    $submitform .= html_writer::empty_tag('input', [
                        'type' => 'hidden',
                        'name' => 'sesskey',
                        'value' => sesskey(),
                    ]);
                    $submitform .= html_writer::tag(
                        'button',
                        get_string('submitforgrading', 'icontent'),
                        ['type' => 'submit', 'class' => 'btn btn-primary']
                    );
                    $submitform .= html_writer::end_tag('form');
                    echo $submitform;
                } else if ($icontent->duedate && $ownsubmission->timesubmitted > $icontent->duedate) {
                    echo html_writer::tag('p', get_string('submissionlate', 'icontent'));
                }

                if ($ownsubmission->status === assignment::STATUS_GRADED && $ownsubmission->grade !== null) {
                    echo html_writer::tag(
                        'p',
                        get_string($ownsubmission->groupid ? 'submissiongroupgrade' : 'submissiongrade', 'icontent') .
                            ': ' . format_float((float)$ownsubmission->grade, 2)
                    );
                    if ($ownsubmission->groupid) {
                        require_once($CFG->libdir . '/gradelib.php');
                        $gradeinfo = grade_get_grades(
                            (int)$course->id,
                            'mod',
                            'icontent',
                            (int)$icontent->id,
                            (int)$USER->id
                        );
                        foreach ($gradeinfo->items as $gradeitem) {
                            $usergrade = $gradeitem->grades[$USER->id];
                            echo html_writer::tag(
                                'p',
                                get_string('submissionindividualgrade', 'icontent') . ': ' .
                                    ($gradeitem->hidden || $usergrade->hidden
                                        ? get_string('hidden')
                                        : s($usergrade->str_grade))
                            );
                        }
                    }
                    if (trim((string)$ownsubmission->feedback) !== '') {
                        echo html_writer::div(
                            format_text($ownsubmission->feedback, (int)$ownsubmission->feedbackformat),
                            'icontent-assignment-feedback'
                        );
                    }
                }
            }
        } else {
            echo $OUTPUT->notification(get_string('groupsubmissionrequired', 'icontent'), 'warning');
        }

        if (!empty($icontent->publicsubmissions)) {
            echo $OUTPUT->single_button(
                new moodle_url('/mod/icontent/submissions.php', ['id' => $cm->id, 'mode' => 'public']),
                get_string('viewpublicsubmissions', 'icontent'),
                'get'
            );
        }
    } else if ($canmanage) {
        echo $OUTPUT->single_button(
            new moodle_url('/mod/icontent/submissions.php', ['id' => $cm->id]),
            get_string('submissions', 'icontent'),
            'get'
        );
    }
    echo html_writer::end_div();
}

// Check whether this icontent is open.
if ($timenow > $timestart) {
    // This echo gets render before the top of the slide. It prints on every slide.

    if ($timenow < $timefinish) {
        $showpagingbars = count($pages) > 1;
        // Decide whether or not to show upper navigation buttons.
        if ($showpagingbars) {
            if (count($pages) > 10) {
                echo icontent_simple_paging_button_bar(
                    $pages,
                    $cm->id,
                    $startwithpage,
                    'fgroup_id_buttonar',
                    $submissionid
                );
            } else {
                echo icontent_full_paging_button_bar($pages, $cm->id, $startwithpage, $submissionid);
            }
        }
        // Add all the content into a box.
        echo $OUTPUT->box_start('icontent-page', 'idicontentpages');
        echo $showpage->fullpageicontent;
        echo $OUTPUT->box_end();
        if ($showpagingbars) {
            echo icontent_simple_paging_button_bar(
                $pages,
                $cm->id,
                $startwithpage,
                'fgroup_id_buttonar',
                $submissionid
            );
        }
    } else {
        // Show message when activity period has ended.
        echo '<div class="editend"><strong>' . get_string('activityended', 'icontent') . ': ';
        echo userdate($timefinish) . '</strong></div>';
    }
} else {
    echo '<div class="warning">' . get_string('notopenuntil', 'icontent') . ': ';
    echo userdate($timestart) . '.</div>';
}

// Finish the page.
echo $OUTPUT->footer();
