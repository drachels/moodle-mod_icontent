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
 * Internal library of functions for module iContent.
 *
 * All the icontent specific functions, needed to implement the module logic, should go here.
 *
 * @package    mod_icontent
 * @copyright  2016 Leo Renis Santos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Constants.
 */
define('ICONTENT_PAGE_MIN_HEIGHT', 500);
define('ICONTENT_MAX_PER_PAGE', 1000);
define('ICONTENT_PER_PAGE', 20);
// Questions.
define('ICONTENT_QTYPE_MATCH', 'match');
define('ICONTENT_QTYPE_MULTICHOICE', 'multichoice');
define('ICONTENT_QTYPE_TRUEFALSE', 'truefalse');
define('ICONTENT_QTYPE_ESSAY', 'essay');
define('ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE', 'toevaluate');
define('ICONTENT_QTYPE_ESSAY_STATUS_VALUED', 'valued');
define('ICONTENT_QUESTION_FRACTION', 1);

require_once(dirname(__FILE__).'/lib.php');

/**
 * Add the icontent TOC sticky block to the default region.
 *
 * @param array $pages
 * @param object $page
 * @param object $icontent
 * @param object $cm
 * @param bool $edit
 */
function icontent_add_fake_block($pages, $page, $icontent, $cm, $edit) {
    global $OUTPUT, $PAGE;
    $toc = icontent_get_toc($pages, $page, $icontent, $cm, $edit, 0);
    $bc = new block_contents();
    $bc->title = get_string('icontentmenu', 'icontent');
    $bc->attributes['class'] = 'block block_icontent_toc';
    $bc->content = $toc;
    $defaultregion = $PAGE->blocks->get_default_region();
    $PAGE->blocks->add_fake_block($bc, $defaultregion);
}

/**
 * Generate toc structure.
 *
 * @param array $pages
 * @param object $page
 * @param object $icontent
 * @param object $cm
 * @param bool $edit
 * @return string
 */
function icontent_get_toc($pages, $page, $icontent, $cm, $edit) {
    global $USER, $OUTPUT;
    $context = context_module::instance($cm->id);
    $tpages = count($pages);
    $toc = '';
    $toc .= html_writer::start_tag('div', ['class' => 'icontent_toc clearfix']);
    // Teacher's TOC.
    if ($edit) {
        $toc .= html_writer::start_tag('ul');
        $i = 0;
        foreach ($pages as $pg) {
            $i ++;
            $title = trim(format_string($pg->title, true, ['context' => $context]));
            $toc .= html_writer::start_tag('li', ['class' => 'clearfix']); // Start <li>.
            $toc .= html_writer::link('#', $title,
                [
                    'title' => s($title),
                    'class' => 'load-page page'.$pg->pagenum,
                    'data-pagenum' => $pg->pagenum,
                    'data-cmid' => $pg->cmid,
                    'data-sesskey' => sesskey(),
                    'data-totalpages' => $tpages,
                ]
            );
            // Actions.
            $toc .= html_writer::start_tag('div', ['class' => 'action-list']); // Start <div>.
            if ($i != 1) {
                $toc .= html_writer::link(new moodle_url('move.php',
                    [
                        'id' => $cm->id,
                        'pageid' => $pg->id,
                        'up' => '1',
                        'sesskey' => $USER->sesskey,
                    ]),
                    $OUTPUT->pix_icon('t/up', get_string('up')), ['title' => get_string('up')]
                );
            }
            if ($i != count($pages)) {
                $toc .= html_writer::link(new moodle_url('move.php',
                    [
                        'id' => $cm->id,
                        'pageid' => $pg->id,
                        'up' => '0',
                        'sesskey' => $USER->sesskey,
                    ]),
                    $OUTPUT->pix_icon('t/down', get_string('down')), ['title' => get_string('down')]
                );
            }
            $toc .= html_writer::link(new moodle_url('edit.php',
                [
                    'cmid' => $pg->cmid,
                    'id' => $pg->id,
                    'sesskey' => $USER->sesskey,
                ]),
                $OUTPUT->pix_icon('t/edit', get_string('edit')), ['title' => get_string('edit')]);
            $toc .= html_writer::link(new moodle_url('delete.php',
                [
                    'id' => $pg->cmid,
                    'pageid' => $pg->id,
                    'sesskey' => $USER->sesskey,
                ]),
                $OUTPUT->pix_icon('t/delete', get_string('delete')), ['title' => get_string('delete')]
            );
            if ($pg->hidden) {
                $toc .= html_writer::link(new moodle_url('show.php',
                    [
                        'id' => $pg->cmid,
                        'pageid' => $pg->id,
                        'sesskey' => $USER->sesskey,
                    ]),
                    $OUTPUT->pix_icon('t/show', get_string('show')), ['title' => get_string('show')]
                );
            } else {
                $toc .= html_writer::link(new moodle_url('show.php',
                    [
                        'id' => $pg->cmid,
                        'pageid' => $pg->id,
                        'sesskey' => $USER->sesskey,
                    ]),
                    $OUTPUT->pix_icon('t/hide', get_string('hide')), ['title' => get_string('hide')]
                );
            }
            $toc .= html_writer::link(new moodle_url('edit.php',
                [
                    'cmid' => $pg->cmid,
                    'pagenum' => $pg->pagenum,
                    'sesskey' => $USER->sesskey,
                ]),
                $OUTPUT->pix_icon('add', get_string('addafter', 'mod_icontent'), 'mod_icontent'),
                    [
                        'title' => get_string('addafter', 'mod_icontent'),
                    ]
                );
            $toc .= html_writer::end_tag('div'); // End </div>.
            $toc .= html_writer::end_tag('li'); // End </li>.
        }
        $toc .= html_writer::end_tag('ul');
    } else {
        // Visualization to students.
        $toc .= html_writer::start_tag('ul');
        foreach ($pages as $pg) {
            if (!$pg->hidden) {
                $title = trim(format_string($pg->title, true, ['context' => $context]));
                $toc .= html_writer::start_tag('li', ['class' => 'clearfix']);
                $toc .= html_writer::link('#', $title,
                    [
                        'title' => s($title),
                        'class' => 'load-page page'.$pg->pagenum,
                        'data-pagenum' => $pg->pagenum,
                        'data-cmid' => $pg->cmid,
                        'data-sesskey' => sesskey(),
                        'data-totalpages' => $tpages,
                    ]
                );
                $toc .= html_writer::end_tag('li');
            }
        }
        $toc .= html_writer::end_tag('ul');
    }
    $toc .= html_writer::end_tag('div');
    return $toc;
}

/**
 * Add dynamic attributes in page loading screen.
 * @param object $pagestyle
 * @return void
 */
function icontent_add_properties_css($pagestyle) {
    $style = "background-color: #{$pagestyle->bgcolor}; ";
    $style .= "min-height: ". ICONTENT_PAGE_MIN_HEIGHT ."px; ";
    $style .= "border: {$pagestyle->borderwidth}px solid #{$pagestyle->bordercolor};";
    if ($pagestyle->bgimage) {
        $style .= "background-image: url('{$pagestyle->bgimage}')";
    }
    return $style;
}

/**
 * Add script that load tooltip twitter bootstrap.
 *
 * @return void
 */
function icontent_add_script_load_tooltip() {
    return html_writer::script("");
    // @codingStandardsIgnoreLine
    // ...$(function() { $('[data-toggle="tooltip"]').tooltip(); }).
}

/**
 * Get page style.
 *
 * This method checks if the current page have enough attributes to create your style. Otherwise returns Generic style plugin.
 *
 * @param object $icontent
 * @param object $page
 * @param object $context
 * @return pagestyle;
 */
function icontent_get_page_style($icontent, $page, $context) {
    $pagestyle = new stdClass;
    $pagestyle->bgcolor = $page->bgcolor ? $page->bgcolor : $icontent->bgcolor;
    $pagestyle->borderwidth = $page->borderwidth ? $page->borderwidth : $icontent->borderwidth;
    $pagestyle->bordercolor = $page->bordercolor ? $page->bordercolor : $icontent->bordercolor;
    $pagestyle->bgimage = false;
    if ($page->showbgimage) {
        $pagestyle->bgimage = icontent_get_page_bgimage($context, $page) ?
            icontent_get_page_bgimage($context, $page) : icontent_get_bgimage($context);
    }
    return icontent_add_properties_css($pagestyle);
}

/**
 * Add border options.
 *
 * @return array $arr
 */
function icontent_add_borderwidth_options() {
    $arr = [];
    // 20240216 Changed from 50 to 51.
    for ($i = 0; $i < 51; $i++) {
        $arr[$i] = $i.'px';
    }
    return $arr;
}

/**
 * Get background image of interactive content plugin <iContent>.
 *
 * @param object $context
 * @return string $fullpath
 */
function icontent_get_bgimage($context) {
    global $CFG;
    $fs = get_file_storage();
    // This is not very efficient!!
    $files = $fs->get_area_files($context->id,
        'mod_icontent',
        'icontent',
        0,
        'sortorder DESC,
        id ASC',
        false
    );
    if (count($files) >= 1) {
        $file = reset($files);
        unset($files);
        $path = '/'.$context->id.'/mod_icontent/icontent/'.$file->get_filepath().$file->get_filename();
        $fullurl = file_encode_url($CFG->wwwroot.'/pluginfile.php', $path, false);
        $mimetype = $file->get_mimetype();
        if (file_mimetype_in_typegroup($mimetype, 'web_image')) { // It's an image.
            return $fullurl;
        } else {
            return false;
        }
    }
    return false;
}

/**
 * Get background image of pages of interactive content plugin <iContent>.
 *
 * @param object $context
 * @param object $page
 * @return string $fullpath
 */
function icontent_get_page_bgimage($context, $page) {
    global $CFG;
    $fs = get_file_storage();
    // This is not very efficient!
    $files = $fs->get_area_files($context->id,
        'mod_icontent',
        'bgpage',
        $page->id,
        'sortorder DESC,
        id ASC',
        false
    );
    if (count($files) >= 1) {
        $file = reset($files);
        unset($files);
        $path = '/'.$context->id.'/mod_icontent/bgpage/'.$page->id.$file->get_filepath().$file->get_filename();
        $fullurl = file_encode_url($CFG->wwwroot.'/pluginfile.php', $path, false);
        $mimetype = $file->get_mimetype();
        if (file_mimetype_in_typegroup($mimetype, 'web_image')) { // It's an image.
            return $fullurl;
        } else {
            return false;
        }
    }
    return false;
}

/**
 * Delete question per page by id.
 *
 * Returns true or false
 *
 * @param int $id
 * @return boolean $result
 */
function icontent_remove_questionpagebyid($id) {
    global $DB;
    return $DB->delete_records('icontent_pages_questions', ['id' => $id]);
}

/**
 * Update question attempt.
 *
 * Returns true or false.
 *
 * @param object $attempt
 * @return boolean true or false
 */
function icontent_update_question_attempts($attempt) {
    global $DB;
    return $DB->update_record('icontent_question_attempts', $attempt);
}

/**
 * Loads full paging button bar.
 *
 * Returns buttons related pages.
 *
 * @param object $pages
 * @param object $cmid
 * @param int $startwithpage
 * @return string with $pgbuttons
 */
function icontent_full_paging_button_bar($pages, $cmid, $startwithpage = 1) {
    if (empty($pages)) {
        return false;
    }
    // Object button.
    $objbutton = new stdClass();
    $objbutton->name = get_string('previous', 'mod_icontent');
    $objbutton->title = get_string('previouspage', 'mod_icontent');
    $objbutton->cmid = $cmid;
    $objbutton->startwithpage = $startwithpage;
    // Create buttons!
    $npage = 0;
    $tpages = count($pages);
    $pgbuttons = html_writer::start_div('full-paging-buttonbar icontent-buttonbar', ['id' => 'idicontentbuttonbar']);
    $pgbuttons .= icontent_make_button_previous_page($objbutton, $tpages);
    foreach ($pages as $page) {
        if (!$page->hidden) {
            $npage ++;
            $pgbuttons .= html_writer::tag('button', $npage,
                [
                    'title' => s($page->title),
                    'class' => 'load-page mr-1 btn-icontent-page btn btn-secondary page'.$page->pagenum,
                    'data-toggle' => 'tooltip',
                    'data-totalpages' => $tpages,
                    'data-placement' => 'top',
                    'data-pagenum' => $page->pagenum,
                    'data-cmid' => $page->cmid,
                    'data-sesskey' => sesskey(),
                ]
            );
        }
    }
    $objbutton->name = get_string('next', 'mod_icontent');
    $objbutton->title = get_string('nextpage', 'mod_icontent');
    $pgbuttons .= icontent_make_button_next_page($objbutton, $tpages);
    $pgbuttons .= html_writer::end_div();
    return $pgbuttons;
}

/**
 * Loads simple paging button bar.
 *
 * Returns buttons previous and next.
 *
 * @param object $pages
 * @param int $cmid
 * @param int $startwithpage
 * @param string $attrid
 * @return string with $controlbuttons
 */
function icontent_simple_paging_button_bar($pages, $cmid, $startwithpage = 1, $attrid = 'fgroup_id_buttonar') {
    // Object button.
    $objbutton = new stdClass();
    $objbutton->name  = get_string('previous', 'mod_icontent');
    $objbutton->title = get_string('previouspage', 'mod_icontent');
    $objbutton->cmid  = $cmid;
    $objbutton->startwithpage = $startwithpage;
    // Go back.
    $controlbuttons = icontent_make_button_previous_page($objbutton, count($pages),
        html_writer::tag('i', null,
            [
                'class' => 'fa fa-chevron-circle-left mr-2',
            ]
        )
    );
    $objbutton->name = get_string('advance', 'mod_icontent');
    $objbutton->title = get_string('nextpage', 'mod_icontent');
    // Advance.
    $controlbuttons .= icontent_make_button_next_page($objbutton, count($pages),
        html_writer::tag('i', null,
            [
                'class' => 'fa fa-chevron-circle-right ml-2',
            ]
        )
    );
    return html_writer::div($controlbuttons, "simple-paging-buttonbar icontent-buttonbar mt-2", ['id' => $attrid]);
}

/**
 * Get the number of the user home page logged in.
 *
 * Returns array of pages.
 * Please note the icontent/text of pages is not included.
 *
 * @param object $icontent
 * @param object $context
 * @return array of id=>icontent
 */
function icontent_get_startpagenum($icontent, $context) {
    global $DB;
    if (has_any_capability(['mod/icontent:edit', 'mod/icontent:manage'], $context)) {
        return icontent_get_minpagenum($icontent);
    }
    // Discover page to be presented to the student.
    global $USER;
    $cm = get_coursemodule_from_instance('icontent', $icontent->id);
    $pagedisplay = $DB->get_record_sql(
        "SELECT MAX(timecreated) AS maxtimecreated
           FROM {icontent_pages_displayed}
          WHERE cmid IN(?)
            AND userid IN(?);",
        [
            $cm->id,
            $USER->id,
        ]
    );
    $totalpagesvieweduser = $DB->count_records('icontent_pages_displayed', ['cmid' => $cm->id, 'userid' => $USER->id]);
    $totalpagesavailable = $DB->count_records('icontent_pages', ['cmid' => $cm->id, 'hidden' => 0]);
    if (!$pagedisplay->maxtimecreated || $totalpagesvieweduser === $totalpagesavailable) {
        return icontent_get_minpagenum($icontent);
    }
    $lastpagedisplay = $DB->get_record("icontent_pages_displayed",
        [
            'cmid' => $cm->id,
            'userid' => $USER->id,
            'timecreated' => $pagedisplay->maxtimecreated,
        ],
        'id,
        pageid'
    );
    $page = $DB->get_record("icontent_pages", ['id' => $lastpagedisplay->pageid], "id, pagenum");
    return $page->pagenum;
}

/**
 * Loads first page content.
 *
 * Returns array of pages.
 * Please note the icontent/text of pages is not included.
 *
 * @param object $icontent
 * @return array of id=>icontent
 */
function icontent_get_minpagenum($icontent) {
    global $DB;
    // Get object.
    $sql = "SELECT Min(pagenum) AS minpagenum FROM {icontent_pages} WHERE icontentid = ? AND hidden = ?;";
     $objpage = $DB->get_record_sql($sql, [$icontent->id, 0]);
     // Return min page.
    return $objpage->minpagenum;
}

/**
 * Get page previous.
 *
 * Return int  page previous.
 *
 * @param stdClass $objpage
 * @return int $page
 */
function icontent_get_prev_pagenum(stdClass $objpage) {
    global $DB;
    // Get page previous.
    $maxpagenum = $objpage->pagenum - 1;
    $page = $DB->get_record_sql(
        "SELECT max(pagenum) AS previous
           FROM {icontent_pages}
          WHERE cmid = ?
            AND hidden = ?
            AND pagenum BETWEEN ? and ?;",
        [
            $objpage->cmid,
            0,
            0,
            $maxpagenum,
        ]
    );
    return $page->previous;
}

/**
 * Get next page.
 *
 * Return int next page
 *
 * @param stdClass $objpage
 * @return int $next
 */
function icontent_get_next_pagenum(stdClass $objpage) {
    global $DB;
    // Get max valid pagenum.
    $pagenum = $DB->get_record_sql(
        "SELECT max(pagenum) AS max
           FROM {icontent_pages}
          WHERE cmid = ?
            AND hidden = ?;",
        [
            $objpage->cmid,
            0,
        ]
    );
    // Get next page.
    $minpagenum = $objpage->pagenum + 1;
    $page = $DB->get_record_sql(
        "SELECT min(pagenum) AS next
           FROM {icontent_pages}
          WHERE cmid = ?
            AND hidden = ?
            AND pagenum BETWEEN ? and ?;",
        [
            $objpage->cmid,
            0,
            $minpagenum,
            $pagenum->max,
        ]
    );
    return $page->next;
}

/**
 * Set updates for grades in table {grade_grades}.
 *
 * Returns true or false.
 *
 * @param stdClass $icontent
 * @param int $cmid
 * @param object $userid
 * @return boolean $return
 */
function icontent_set_grade_item(stdClass $icontent, $cmid, $userid) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');
    $params = [
        'itemname' => $icontent->name,
        'idnumber' => $cmid,
    ];
    $sumfraction = icontent_get_sumfraction_by_userid($cmid, $userid);
    $tquestinstance = icontent_get_totalquestions_by_instance($cmid);
    $finalgrade = ($sumfraction * $icontent->grade) / $tquestinstance;
    // Make set icontent_grade for <iContent>.
    $igrade = new stdClass();
    $igrade->icontentid = $icontent->id;
    $igrade->userid = $userid;
    $igrade->cmid = $cmid;
    $igrade->grade = $finalgrade;
    $igrade->timemodified = time();
    // Check if table {icontent_grades} has grade for user.
    $igradeid = $DB->get_field('icontent_grades', 'id',
        [
            'icontentid' => $icontent->id,
            'userid' => $userid,
            'cmid' => $cmid,
        ]
    );
    if ($igradeid) {
        $igrade->id = $igradeid;
        $DB->update_record('icontent_grades', $igrade);
    } else {
        $DB->insert_record('icontent_grades', $igrade);
    }
    // Make grade.
    $grade = new stdClass();
    $grade->rawgrade = number_format($finalgrade, 5);
    $grade->userid = $userid;
    // Update gradebook.
    grade_update('mod/icontent', $icontent->course, 'mod', 'icontent', $icontent->id, 0, $grade, $params);
}

/**
 * Get total questions of question bank.
 *
 * Returns int of total questions.
 *
 * @param object $coursecontext
 * @return int of $tquestions
 */
function icontent_count_questions_of_questionbank($coursecontext) {
    global $DB;
    // 20240106 This seems to be working!
    $questions = $DB->get_record_sql(
        'SELECT count(*) as total
           FROM {question_bank_entries} qbe
           JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
          WHERE qc.contextid = ?', [$coursecontext]);
    return (int) $questions->total;
}

/**
 * Get total attempts users of users by course modules ID.
 *
 * Returns int of total attempts users.
 *
 * @param object $cmid
 * @return int of $tattemptsusers
 */
function icontent_count_attempts_users($cmid) {
    global $DB;

    $sql = "SELECT Count(DISTINCT u.id) AS totalattemptsusers
              FROM {user} u
        INNER JOIN {icontent_question_attempts} qa
                ON u.id = qa.userid
             WHERE  qa.cmid = ?;";
    $totalattemptsusers = $DB->get_record_sql($sql, [$cmid]);
    return (int) $totalattemptsusers->totalattemptsusers;
}

/**
 * Get total attempts users of users with answers not evaluated by course modules ID.
 *
 * Returns int of total attempts users.
 *
 * @param object $cmid
 * @param null $status
 * @return int of $tattemptsusers
 */
function icontent_count_attempts_users_with_open_answers($cmid, $status = null) {
    global $DB;
    // Check if status is filled in.
    if (!isset($status)) {
        $status = ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE;
    }
    // SQL Query.
    $sql = "SELECT Count(DISTINCT u.id) AS totalattemptsusers
              FROM {user} u
        INNER JOIN {icontent_question_attempts} qa
                ON u.id = qa.userid
             WHERE  qa.cmid = ?
               AND qa.rightanswer IN (?);";
    $totalattemptsusers = $DB->get_record_sql($sql, [$cmid, $status]);
    return (int) $totalattemptsusers->totalattemptsusers;
}

/**
 * Get questions of current page.
 *
 * Returns array questionspage.
 *
 * @param int $pageid
 * @param int $cmid
 * @return array $questionspage
 */
function icontent_get_questions_of_currentpage($pageid, $cmid) {
    global $DB;
    return $DB->get_records('icontent_pages_questions', ['pageid' => $pageid, 'cmid' => $cmid], null, 'questionid, id');
}

/**
 * Get info answers by questionid.
 * Important: This function assumes that the naming patterns described in
 * <icontent_make_questions_answers_by_type> function were followed correctly.
 * Returns object infoanswer.
 *
 * @param int $questionid
 * @param int $qtype
 * @param string $answer
 * @return object $infoanswer
 */
function icontent_get_infoanswer_by_questionid($questionid, $qtype, $answer) {
    global $DB;
    // Check if var $qtype equals match. If true get $answerid.
    if (substr($qtype, 0, 5) === ICONTENT_QTYPE_MATCH) {
        list($strvar, $optionid) = explode('-', $qtype);
        $qtype = ICONTENT_QTYPE_MATCH;
    }
    // Creating and initializing the $infoanswer object.
    $infoanswer = new stdClass();
    $infoanswer->fraction = 0;
    $infoanswer->rightanswer = '';
    $infoanswer->answertext = '';
    // Set information by qtype.
    switch ($qtype) {
        case ICONTENT_QTYPE_MULTICHOICE:
        case ICONTENT_QTYPE_TRUEFALSE:
            // Check if answer is a checkbox. Otherwise, is radio.
            if (is_array($answer)) {
                $rightanswers = $DB->get_records_select('question_answers', 'question = ? AND fraction > ?', [$questionid, 0]);
                if (count($answer) === count($rightanswers)) {
                    // Get array with key ID answer.
                    $arrayoptionsids = icontent_get_array_options_answerid($answer);
                    // Checks answers correct.
                    foreach ($rightanswers as $rightanswer) {
                        $infoanswer->rightanswer .= $rightanswer->answer.';';
                        if (array_key_exists($rightanswer->id, $arrayoptionsids)) {
                            $infoanswer->fraction += $rightanswer->fraction;
                            $infoanswer->answertext .= $rightanswer->answer.';';
                        }
                    }
                    // Checks wrong answers.
                    if ($infoanswer->fraction < ICONTENT_QUESTION_FRACTION) {
                        $wronganswers = $DB->get_records_select(
                            'question_answers',
                            'question = ? AND fraction = ?',
                            [
                                $questionid,
                                0,
                            ]
                        );
                        foreach ($wronganswers as $wronganswer) {
                            if (array_key_exists($wronganswer->id, $arrayoptionsids)) {
                                $infoanswer->answertext .= $wronganswer->answer.';';
                            }
                        }
                    }
                    return $infoanswer;
                }
                return false;
            } else {
                // Get data answer. Pattern e.g. [qpid-8_answerid-2].
                list($qp, $dtanswer) = explode('_', $answer);
                list($stranswer, $answerid) = explode('-', $dtanswer);
                $currentanwser = $DB->get_record_select(
                    'question_answers',
                    'question = ? AND id = ?',
                    [
                        $questionid,
                        $answerid,
                    ]
                );
                $infoanswer->fraction = $currentanwser->fraction;
                $infoanswer->rightanswer = $currentanwser->answer;
                $infoanswer->answertext = $currentanwser->answer;

                if ($infoanswer->fraction < ICONTENT_QUESTION_FRACTION) {
                    $rightanwser = $DB->get_record_select(
                        'question_answers',
                        'question = ? AND fraction = ?',
                        [
                            $questionid,
                            ICONTENT_QUESTION_FRACTION,
                        ]
                    );
                    $infoanswer->rightanswer = $rightanwser->answer;
                }
                return $infoanswer;
            }
        break;
        case ICONTENT_QTYPE_MATCH:
            $rightanwser = $DB->get_record('qtype_match_subquestions', ['id' => $optionid]);
            // Clean answers.
            $currentanwser = trim(strip_tags($answer));
            $rightanwser->answertext = trim(strip_tags($rightanwser->answertext));
            // Fill object $infoanswer.
            $infoanswer->rightanswer = $rightanwser->answertext. '->'.$rightanwser->questiontext.';';
            $infoanswer->answertext = $currentanwser. '->'. $rightanwser->questiontext.';';
            // Checks if answer is correct.
            if ($rightanwser->answertext === $currentanwser) {
                $infoanswer->fraction = ICONTENT_QUESTION_FRACTION;
            }
            return $infoanswer;
            break;
        case ICONTENT_QTYPE_ESSAY:
            $infoanswer->rightanswer = ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE;    // Wait evaluation of tutor.
            $infoanswer->answertext = s($answer);
            return $infoanswer;
            break;
    }
    throw new Exception("QTYPE Invalid.");
}

/**
 * Get object with attempts of users by course modules ID <iContent>.
 *
 * Returns object attempt users.
 *
 * @param int $cmid
 * @param string $sort
 * @param int $page
 * @param int $perpage
 * @return object $attemptusers, otherwhise false.
 */
function icontent_get_attempts_users($cmid, $sort, $page = 0, $perpage = ICONTENT_PER_PAGE) {
    global $CFG, $DB;
    $sortparams = 'u.lastname '.$sort;
    $page = (int) $page;
    $perpage = (int) $perpage;
    // Setup pagination - when both $page and $perpage = 0, get all results.
    if ($page || $perpage) {
        if ($page < 0) {
            $page = 0;
        }
        if ($perpage > ICONTENT_MAX_PER_PAGE) {
            $perpage = ICONTENT_MAX_PER_PAGE;
        } else if ($perpage < 1) {
            $perpage = ICONTENT_PER_PAGE;
        }
    }

    // 20231225 Added Moodle branch check.
    if ($CFG->branch < 311) {
        $namefields = user_picture::fields('u', null, 'userid');
    } else {
        $userfieldsapi = \core_user\fields::for_userpic();
        $namefields = $userfieldsapi->get_sql('u', false, '', 'id', false)->selects;;
    }

    $sql = "SELECT DISTINCT $namefields,
                (SELECT Sum(fraction)
                   FROM {icontent_question_attempts}
                  WHERE userid = u.id
                    AND cmid = ?) AS sumfraction,
                        (SELECT Count(id)
                           FROM {icontent_question_attempts}
                          WHERE userid = u.id
                            AND cmid = ?) AS totalanswers,
                                (SELECT Count(id)
                                   FROM {icontent_question_attempts}
                                  WHERE userid = u.id
                                    AND cmid = ?
                                    AND rightanswer IN (?)) AS totalopenanswers
             FROM {user} u
       INNER JOIN {icontent_question_attempts} qa
               ON u.id = qa.userid
            WHERE qa.cmid = ?
            ORDER BY $sortparams";
    $params = [
        $cmid,
        $cmid,
        $cmid,
        ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE,
        $cmid,
    ]; // Field CMID used four times. Check (?).
    return $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);
}

/**
 * Get object with attempts of users with answers not evaluated by course modules ID <iContent>.
 *
 * Returns object attempt users.
 *
 * @param int $cmid
 * @param string $sort
 * @param string $status
 * @param int $page
 * @param int $perpage
 * @return object $attemptusers, otherwhise false.
 */
function icontent_get_attempts_users_with_open_answers($cmid, $sort, $status = null, $page = 0, $perpage = ICONTENT_PER_PAGE) {
    global $CFG, $DB;
    $sortparams = 'u.firstname '.$sort;
    $page = (int) $page;
    $perpage = (int) $perpage;
    // Check if status is filled in.
    if (!isset($status)) {
        $status = ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE;
    }
    // Setup pagination - when both $page and $perpage = 0, get all results.
    if ($page || $perpage) {
        if ($page < 0) {
            $page = 0;
        }
        if ($perpage > ICONTENT_MAX_PER_PAGE) {
            $perpage = ICONTENT_MAX_PER_PAGE;
        } else if ($perpage < 1) {
            $perpage = ICONTENT_PER_PAGE;
        }
    }

    // 20231225 Added Moodle branch check.
    if ($CFG->branch < 311) {
        $namefields = user_picture::fields('u', null, 'userid');
    } else {
        $userfieldsapi = \core_user\fields::for_userpic();
        $namefields = $userfieldsapi->get_sql('u', false, '', 'id', false)->selects;;
    }

    $sql = "SELECT DISTINCT $namefields,
                (SELECT Count(id)
                   FROM {icontent_question_attempts}
                  WHERE userid = u.id
                    AND cmid = ?
                    AND rightanswer IN (?)) AS totalopenanswers
             FROM {user} u
       INNER JOIN {icontent_question_attempts} qa
               ON u.id = qa.userid
            WHERE qa.cmid = ?
              AND qa.rightanswer IN (?)
         ORDER BY {$sortparams}";
    $params = [
        $cmid,
        $status,
        $cmid,
        $status,
    ]; // Field CMID used two times. Check (?).
    return $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);
}

/**
 * Get object with attempt summary of user the current page.
 *
 * Returns object attempt summary.
 *
 * @param int $pageid
 * @param int $cmid
 * @return object $attemptsummary, otherwhise false.
 */
function icontent_get_attempt_summary_by_page($pageid, $cmid) {
    global $DB, $USER;
    // SQL Query.
    $sql = "SELECT Sum(qa.fraction) AS sumfraction,
                   Count(qa.id) AS totalanswers,
                   qa.timecreated
              FROM {icontent_question_attempts} qa
        INNER JOIN {icontent_pages_questions} pq
                ON qa.pagesquestionsid = pq.id
             WHERE pq.pageid = ?
               AND pq.cmid = ?
               AND qa.userid = ?
          GROUP BY qa.timecreated;";
    // Get record.
    $attemptsummary = $DB->get_record_sql($sql, [$pageid, $cmid, $USER->id]);
    // Checks if a property isn't empty.
    if (!empty($attemptsummary->totalanswers)) {
        return $attemptsummary;
    }
    return false;
}

/**
 * Get object with right answers by attempt summary the current page.
 *
 * Returns object total right answers by attempt summary.
 *
 * @param int $pageid
 * @param int $cmid
 * @return object $rightanswers
 */
function icontent_get_right_answers_by_attempt_summary_by_page($pageid, $cmid) {
    global $DB, $USER;
    $sql = "SELECT Count(qa.id) AS totalrightanswers
              FROM {icontent_question_attempts} qa
        INNER JOIN {icontent_pages_questions} pq
                ON qa.pagesquestionsid = pq.id
             WHERE qa.fraction > 0
               AND pq.pageid = ?
               AND pq.cmid = ?
               AND qa.userid = ?;";
    return $DB->get_record_sql($sql, [$pageid, $cmid, $USER->id]);
}

/**
 * Get object with open answers by attempt summary the current page.
 *
 * Returns object total open answers by attempt summary.
 *
 * @param int $pageid
 * @param int $cmid
 * @return object $openanswers
 */
function icontent_get_open_answers_by_attempt_summary_by_page($pageid, $cmid) {
    global $DB, $USER;
    $sql = "SELECT Count(qa.id) AS totalopenanswers
              FROM {icontent_question_attempts} qa
        INNER JOIN {icontent_pages_questions} pq
                ON qa.pagesquestionsid = pq.id
             WHERE pq.pageid = ?
               AND pq.cmid = ?
               AND qa.userid = ?
               AND qa.rightanswer IN (?);";
    return $DB->get_record_sql($sql, [$pageid, $cmid, $USER->id, ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE]);
}

/**
 * Get object with questions and open answers by user the current page.
 *
 * Returns object questions and open answers by attempt summary.
 *
 * @param int $userid
 * @param int $cmid
 * @param string $status
 * @return object $qopenanswers
 */
function icontent_get_questions_and_open_answers_by_user($userid, $cmid, $status = null) {
    global $DB;
    // Check if status is filled in.
    if (!isset($status)) {
        $status = ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE;
    }
    // SQL query.
    $sql = "SELECT qa.id,
                   qa.userid,
                   qa.questionid,
                   qa.pagesquestionsid,
                   qa.answertext,
                   qa.fraction,
                   qa.timecreated,
                   q.questiontext,
                   pq.pageid
              FROM {icontent_question_attempts} qa
        INNER JOIN {question} q
                ON qa.questionid = q.id
        INNER JOIN {icontent_pages_questions} pq
                ON qa.pagesquestionsid = pq.id
             WHERE qa.cmid = ?
               AND qa.userid = ?
               AND qa.rightanswer IN (?);";
    // Get records and return.
    return $DB->get_records_sql($sql, [$cmid, $userid, $status]);
}

/**
 * Get sum fraction by instance and userid.
 *
 * Returns sum fraction.
 *
 * @param int $cmid
 * @param int $userid
 * @return float $sumfraction
 */
function icontent_get_sumfraction_by_userid($cmid, $userid) {
    global $DB;
    $sql = "SELECT Sum(fraction) AS sumfraction FROM {icontent_question_attempts}  WHERE  userid = ? AND cmid = ?;";
    $grade = $DB->get_record_sql($sql, [$userid, $cmid]);
    return $grade->sumfraction;
}

/**
 * Get array of the options of answers. Pattern input e.g. array options with [qpid-9_answerid-5].
 *
 * Returns array of $arrayoptionsid.
 *
 * @param array $answers
 * @return array $arrayoptionsid[$answerid] = $questionpage
 */
function icontent_get_array_options_answerid($answers) {
    $arrayoptionsids = [];
    foreach ($answers as $optanswer) {
        list($qp, $answer) = explode('_', $optanswer);
        list($stranswer, $answerid) = explode('-', $answer);
        $arrayoptionsids[$answerid] = $qp;
    }
    return $arrayoptionsids;
}

/**
 * Add preview in page if its not previewed.
 *
 * Returns object of pagedisplayed.
 *
 * @param int $pageid
 * @param int $cmid
 * @return object $pagedisplayed
 */
function icontent_add_pagedisplayed($pageid, $cmid) {
    global $DB, $USER;
    $pagedisplayed = icontent_get_pagedisplayed($pageid, $cmid);
    if (empty($pagedisplayed)) {
        $pagedisplayed = new stdClass;
        $pagedisplayed->pageid = $pageid;
        $pagedisplayed->cmid = $cmid;
        $pagedisplayed->userid = $USER->id;
        $pagedisplayed->timecreated = time();
        return $DB->insert_record('icontent_pages_displayed', $pagedisplayed);
    }
    return $pagedisplayed;
}

/**
 * Adds questions on a page.
 *
 * Returns true or false.
 *
 * @param array $questions
 * @param int $pageid
 * @param int $cmid
 * @return boolean true or false
 */
function icontent_add_questionpage($questions, $pageid, $cmid) {
    global $DB;
    $records = [];
    if ($questions) {
        // Remove questions this page.
        $DB->delete_records('icontent_pages_questions', ['pageid' => $pageid, 'cmid' => $cmid]);
        // Create array of objects questionpage.
        $i = 0;
        foreach ($questions as $question) {
            $records[$i] = new stdClass;
            $records[$i]->pageid = $pageid;
            $records[$i]->questionid = $question;
            $records[$i]->cmid = $cmid;
            $records[$i]->timecreated = time();
            $i ++;
        }
        // Persists objects.
        $DB->insert_records('icontent_pages_questions', $records);
        return true;
    }
    return false;
}

/**
 * Get page viewed.
 *
 * Returns string of pagedisplayed.
 *
 * @param int $pageid
 * @param int $cmid
 * @return object $pagedisplayed
 */
function icontent_get_pagedisplayed($pageid, $cmid) {
    global $DB, $USER;
    return $DB->get_record('icontent_pages_displayed',
        [
            'pageid' => $pageid,
            'cmid' => $cmid,
            'userid' => $USER->id,
        ],
        'id,
        timecreated'
    );
}

/**
 * Get questions by pageid.
 *
 * Returns array of questions.
 *
 * @param int $pageid
 * @param int $cmid
 * @return array $questions
 */
function icontent_get_pagequestions($pageid, $cmid) {
    global $DB;
    $sql = 'SELECT pq.id AS qpid,
                   q.id  AS qid,
                   q.name,
                   q.questiontext,
                   q.questiontextformat,
                   q.qtype
              FROM {icontent_pages_questions} pq
        INNER JOIN {question} q
                ON pq.questionid = q.id
             WHERE pq.pageid = ?
               AND pq.cmid = ?;';
    return $DB->get_records_sql($sql, [$pageid, $cmid]);
}

/**
 * Get total of questions and subquestions by instance <iContent>.
 *
 * Returns total of questions by instance.
 *
 * @param int $cmid
 * @return int $tquestions
 */
function icontent_get_totalquestions_by_instance($cmid) {
    global $DB;
    // Get total subquestions.
    $sql = 'SELECT Count(*)
              FROM {qtype_match_subquestions} qms
        INNER JOIN {icontent_pages_questions} pq
                ON qms.questionid = pq.questionid
             WHERE pq.cmid = ?;';
    $tquest = $DB->count_records_sql($sql, [$cmid]);
    // Get total questions.
    $sql = 'SELECT Count(*)
              FROM {icontent_pages_questions} pq
        INNER JOIN {question} q
                ON pq.questionid = q.id
             WHERE q.qtype NOT IN (?)
               AND pq.cmid = ?;';
    $tsub = $DB->count_records_sql($sql, [ICONTENT_QTYPE_MATCH, $cmid]);
    return $tsub + $tquest;
}

/**
 * Get pagenotes by pageid according to the user's capability logged.
 *
 * Returns array of pagenotes.
 *
 * @param int $pageid
 * @param int $cmid
 * @param string $tab
 * @return object $pagenotes
 */
function icontent_get_pagenotes($pageid, $cmid, $tab) {
    global $DB, $USER;
    if (icontent_has_permission_manager(context_module::instance($cmid))) {
        // If manager.
        return $DB->get_records('icontent_pages_notes', ['pageid' => $pageid, 'cmid' => $cmid, 'tab' => $tab], 'path');
    }
    // If student.
    $sql = 'SELECT *
              FROM {icontent_pages_notes}
             WHERE pageid = ?
               AND cmid = ?
               AND tab = ?
               AND (userid = ? OR private = ?)
          ORDER BY path ASC;';
    return $DB->get_records_sql($sql, [$pageid, $cmid, $tab, $USER->id, 0]);
}

/**
 * Get likes of page.
 *
 * Returns object of  {icontent_pages_notes_like}.
 *
 * @param int $pagenoteid
 * @param int $userid
 * @param int $cmid
 * @return object $pagenotelike
 */
function icontent_get_pagenotelike($pagenoteid, $userid, $cmid) {
    global $DB;
    return $DB->get_record('icontent_pages_notes_like', ['pagenoteid' => $pagenoteid, 'userid' => $userid, 'cmid' => $cmid], 'id');
}

/**
 * Check if expandnotesarea or expandquestionsarea field are true or false and returns toggle object.
 *
 * Returns toggle area object.
 *
 * @param boolean $expandarea
 * @return object $attrtogglearea
 */
function icontent_get_toggle_area_object($expandarea) {
    $attrtogglearea = new stdClass();
    if (!$expandarea) {
        $attrtogglearea->icon = '<i class="fa fa-caret-right" aria-hidden="true"></i>&nbsp;';
        $attrtogglearea->style = "display: none;";
        $attrtogglearea->class = "closed";
        return $attrtogglearea;
    }
    $attrtogglearea->icon = '<i class="fa fa-caret-down" aria-hidden="true"></i>&nbsp;';
    $attrtogglearea->style = '';
    $attrtogglearea->class = '';
    return  $attrtogglearea;
}

/**
 * Get pages number interactive content <iContent>
 *
 * Returns pagenum.
 *
 * @param int $icontentid
 * @return int pagenum
 */
function icontent_count_pages($icontentid) {
    global $DB;
    return $DB->count_records('icontent_pages', ['icontentid' => $icontentid]);
}

/**
 * Get pages number viewed by user.
 *
 * Returns page viewed by user.
 *
 * @param int $userid
 * @param int $cmid
 * @return int $pageviewedbyuser
 */
function icontent_count_pageviewedbyuser($userid, $cmid) {
    global $DB;
    return $DB->count_records('icontent_pages_displayed', ['userid' => $userid, 'cmid' => $cmid]);
}

/**
 * Get count of likes a note {icontent_pages_notes_like}.
 *
 * Returns count.
 *
 * @param int $pagenoteid
 * @return int count
 */
function icontent_count_pagenotelike($pagenoteid) {
    global $DB;
    return $DB->count_records('icontent_pages_notes_like', ['pagenoteid' => $pagenoteid]);
}

/**
 * Get page number by pageid.
 *
 * Returns pagenum.
 *
 * @param int $pageid
 * @return int pagenum
 */
function icontent_get_pagenum_by_pageid($pageid) {
    global $DB;
    $sql = "SELECT pagenum  FROM {icontent_pages} WHERE id = ?;";
    $obj = $DB->get_record_sql($sql, [$pageid]);
    return $obj->pagenum;
}

/**
 * Get the level of depth this note.
 *
 * Returns levels.
 *
 * @param string $path
 * @return int $levels
 */
function icontent_get_noteparentinglevels($path) {
    $countpath = count(explode('/', $path)) - 1;
    if (!$countpath) {
        return 1;
    } else if ($countpath > 12) {
        return 12;
    } else {
        return $countpath;
    }
}

/**
 * Get user by ID.
 *
 * Returns object $user.
 *
 * @param int $userid
 * @return object $user
 */
function icontent_get_user_by_id($userid) {
    global $DB;
    return $DB->get_record('user',
        ['id' => $userid],
        'id,
        firstname,
        lastname,
        email,
        picture,
        firstnamephonetic,
        lastnamephonetic,
        middlename,
        alternatename,
        imagealt'
    );
}

/**
 * Recursive function that gets notes daughters.
 *
 * Returns array $notesdaughters.
 *
 * @param int $pagenoteid
 * @return array $notesdaughters
 */
function icontent_get_notes_daughters($pagenoteid) {
    global $DB;
    $pagenotes = $DB->get_records('icontent_pages_notes', ['parent' => $pagenoteid]);
    if ($pagenotes) {
        $notesdaughters = [];
        foreach ($pagenotes as $pagenote) {
            $notesdaughters[$pagenote->id] = $pagenote->comment;
            $tree = icontent_get_notes_daughters($pagenote->id);
            if ($tree) {
                $notesdaughters = $notesdaughters + $tree;
            }
        }
        return $notesdaughters;
    }
    return $pagenotes;
}

/**
 * Check that the value of param is a valid SQL clause.
 *
 * Returns string ASC or DESC.
 *
 * @param string $sortsql
 * @return $sort ASC or DESC.
 */
function icontent_check_value_sort($sortsql) {
    $sortsql = strtolower($sortsql);
    switch ($sortsql) {
        case 'desc':
            return 'DESC';
            break;
        default:
            return "ASC";
    }
}

/**
 * Checks if exists answers the questions of current page.
 *
 * Returns array answerspage
 *
 * @param int $pageid
 * @param int $cmid
 * @return array $answerspage
 */
function icontent_checks_answers_of_currentpage($pageid, $cmid) {
    global $DB;
    $sql = "SELECT Count(qa.id)     AS totalanswers
            FROM   {icontent_question_attempts} qa
                   INNER JOIN {icontent_pages_questions} pq
                           ON qa.pagesquestionsid = pq.id
            WHERE  pq.pageid = ?
                   AND pq.cmid = ?;";
    $totalanswers = $DB->get_record_sql($sql, [$pageid, $cmid]);
    // Checks if a property isn't empty.
    if (!empty($totalanswers->totalanswers)) {
        return $totalanswers;
    }
    return false;
}

/**
 * Check if has permission for edition.
 *
 * @param boolean $allowedit
 * @param boolean $edit Received by parameter in the URL.
 * @return boolean true if the user has this permission. Otherwise false.
 */
function icontent_has_permission_edition($allowedit, $edit = 0) {
    global $USER;
    if ($allowedit) {
        if ($edit != -1 && confirm_sesskey()) {
            $USER->editing = $edit;
        } else {
            if (isset($USER->editing)) {
                $edit = $USER->editing;
            } else {
                $edit = 0;
            }
        }
    } else {
        $edit = 0;
    }
    return $edit;
}

// FUNCTIONS CAPABILITYES.
/**
 * Check if has permission of manager.
 * @param string $context
 * @return boolean true if the user has this permission. Otherwise false.
 */
function icontent_has_permission_manager($context) {
    if (has_any_capability(['mod/icontent:edit', 'mod/icontent:manage'], $context)) {
        return true;
    }
    return false;
}

/**
 * Check if the user is owner the note.
 * @param object $pagenote
 * @return boolean true if the user has this permission. Otherwise false.
 */
function icontent_check_user_isowner_note($pagenote) {
    global $USER;
    if ($USER->id === $pagenote->userid) {
        return true;
    }
    return false;
}

/**
 * Check if user can remove note.
 * @param object $pagenote
 * @param string $context
 * @return boolean true if the user has this permission. Otherwise false.
 */
function icontent_user_can_remove_note($pagenote, $context) {
    if (has_capability('mod/icontent:removenotes', $context)) {
        if (icontent_check_user_isowner_note($pagenote)) {
            return true;
        }
        if (icontent_has_permission_manager($context)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user can edit note.
 * @param object $pagenote
 * @param string $context
 * @return boolean true if the user has this permission. Otherwise false.
 */
function icontent_user_can_edit_note($pagenote, $context) {
    if (has_capability('mod/icontent:editnotes', $context)) {
        if (icontent_check_user_isowner_note($pagenote)) {
            return true;
        }
        if (icontent_has_permission_manager($context)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user can reply note.
 * @param object $pagenote
 * @param string $context
 * @return boolean true if the user has this permission. Otherwise false.
 */
function icontent_user_can_reply_note($pagenote, $context) {
    if (has_capability('mod/icontent:replynotes', $context)) {
        if (icontent_has_permission_manager($context)) {
            return true;
        }
        if ($pagenote->doubttutor) {
            return false;
        }
        return true;
    }
    return false;
}

/**
 * Check if user can like or do not like the note.
 * @param object $pagenote
 * @param string $context
 * @return boolean true if the user has this permission. Otherwise false.
 */
function icontent_user_can_likeunlike_note($pagenote, $context) {
    if (has_capability('mod/icontent:likenotes', $context)) {
        return true;
    }
    return false;
}

/**
 * Check if user can view private field.
 * @param string $context
 * @return boolean true if the user has this permission. Otherwise false.
 */
function icontent_user_can_view_checkbox_field_private($context) {
    if (has_capability('mod/icontent:checkboxprivatenotes', $context)) {
        return true;
    }
    return false;
}

/**
 * Check if user can view featured field.
 * @param string $context
 * @return boolean true if the user has this permission. Otherwise false.
 */
function icontent_user_can_view_checkbox_field_featured($context) {
    if (has_capability('mod/icontent:checkboxfeaturednotes', $context)) {
        return true;
    }
    return false;
}

/**
 * Check if user can view doubttutor field.
 * @param string $context
 * @return boolean true if the user has this permission. Otherwise false.
 */
function icontent_user_can_view_checkbox_field_doubttutor($context) {
    if (has_capability('mod/icontent:checkboxdoubttutornotes', $context)) {
        return true;
    }
    return false;
}

/**
 * Check if user can remove attempts answers for try again.
 * @param int $pageid
 * @param int $cmid
 * @return boolean true if the user has this permission. Otherwise false.
 */
function icontent_user_can_remove_attempts_answers_for_tryagain($pageid, $cmid) {
    global $DB;
    // Get context.
    $context = context_module::instance($cmid);
    if (icontent_has_permission_manager($context)) {
        return true;
    }
    if (has_capability('mod/icontent:answerquestionstryagain', $context)) {
        // Get object page.
        $objpage = $DB->get_record('icontent_pages', ['id' => $pageid], 'id, pagenum, attemptsallowed', MUST_EXIST);
        if ((int)$objpage->attemptsallowed === 0) {
            return true;
        }
    }
    return false;
}

// FUNCTIONS CREATING AND RETURNS HTML.

 /**
  * Create button previous page.
  *
  * Returns button.
  *
  * @param object $button
  * @param int $tpages
  * @param string $icon
  * @return string with $btnprevious
  */
function icontent_make_button_previous_page($button, $tpages, $icon = null) {
    $objpage = new stdClass();
    $objpage->pagenum = $button->startwithpage;
    $objpage->cmid = $button->cmid;
    $pageprevious = icontent_get_prev_pagenum($objpage);
    $attributes = [
        'title' => $button->title,
        'class' => 'load-page btn-previous-page btn btn-secondary mr-1',
        'data-toggle' => 'tooltip',
        'data-totalpages' => $tpages,
        'data-placement' => 'top',
        'data-pagenum' => $pageprevious,
        'data-cmid' => $button->cmid,
        'data-sesskey' => sesskey(),
    ];
    if (!$pageprevious) {
        $attributes = $attributes + ['disabled' => 'disabled'];
    }
    return html_writer::tag('button', $icon. $button->name, $attributes);
}

/**
 * Create button next page.
 *
 * Returns button.
 *
 * @param object $button
 * @param int $tpages
 * @param string $icon
 * @return string with $btnnext
 */
function icontent_make_button_next_page($button, $tpages, $icon = null) {
    $objpage = new stdClass();
    $objpage->pagenum = $button->startwithpage;
    $objpage->cmid = $button->cmid;
    $nextpage = icontent_get_next_pagenum($objpage);
    $attributes = [
        'title' => $button->title,
        'class' => 'load-page btn-next-page btn btn-secondary' ,
        'data-toggle' => 'tooltip',
        'data-totalpages' => $tpages,
        'data-placement' => 'top',
        'data-pagenum' => $nextpage,
        'data-cmid' => $button->cmid,
        'data-sesskey' => sesskey(),
    ];
    if (!$nextpage) {
        $attributes = $attributes + ['disabled' => 'disabled'];
    }
    return html_writer::tag('button', $button->name. $icon, $attributes);
}

/**
 * This is the function responsible for creating a list of answers to the notes that will be removed.
 *
 * Return list of answers.
 *
 * @param array $notesdaughters
 * @return string $listgroup
 */
function icontent_make_list_group_notesdaughters($notesdaughters) {
    if ($notesdaughters) {
        $listgroup = html_writer::start_tag('ul');
        $likes = '';
        foreach ($notesdaughters as $key => $note) {
            $likes = html_writer::span(icontent_count_pagenotelike($key), 'badge');
            $listgroup .= html_writer::tag('li', $note. $likes, ['class' => 'list-group-item']);
        }
        $listgroup .= html_writer::end_tag('ul');
        return $listgroup;
    }
    return false;
}

/**
 * This is the function responsible for creating a progress bar.
 *
 * Return progress bar.
 *
 * @param object $objpage
 * @param object $icontent
 * @param object $context
 * @return string $progressbar
 */
function icontent_make_progessbar($objpage, $icontent, $context) {
    if (!$icontent->progressbar) {
        return false;
    }
    global $USER;
    $npages = icontent_count_pages($icontent->id);
    $npagesviewd = icontent_count_pageviewedbyuser($USER->id, $objpage->cmid);
    $percentage = ($npagesviewd * 100) / $npages;
    $percent = html_writer::span(get_string('labelprogressbar', 'icontent', $percentage), 'sr-only');
    $progressbar = html_writer::div($percent, 'progress-bar progress-bar-striped active',
        [
            'role' => 'progressbar',
            'aria-valuenow' => $percentage,
            'aria-valuemin' => '0',
            'aria-valuemax' => '100',
            'style' => "width: {$percentage}%;",
        ]
    );
    $progress = html_writer::div($progressbar, 'progress');
    return $progress;
}

/**
 * This is the function responsible for creating the area questions on pages.
 *
 * Returns questions area.
 *
 * @param object $objpage
 * @param object $icontent
 * @return string $questionsarea
 */
function icontent_make_questionsarea($objpage, $icontent) {
    $questions = icontent_get_pagequestions($objpage->id, $objpage->cmid);
    if (!$questions) {
        return false;
    }
    if (icontent_get_attempt_summary_by_page($objpage->id, $objpage->cmid)) {
        return icontent_make_attempt_summary_by_page($objpage->id, $objpage->cmid);
    }
    // Add the triangle that toggles the list of questions visible and not visible.
    $togglearea = icontent_get_toggle_area_object($objpage->expandquestionsarea);
    // Title in h4 style for the questions part of the page.
    $title = html_writer::tag('h4', $togglearea->icon. get_string('answerthequestions', 'mod_icontent'),
        [
            'class' => 'titlequestions text-uppercase '.$togglearea->class,
            'id' => 'idtitlequestionsarea',
        ]
    );
    $qlist = '';
    foreach ($questions as $question) {
        // Assemble the listing of all the questions on a slide/page.
        $qlist .= icontent_make_questions_answers_by_type($question);
    }
    // Hidden form fields.
    $hiddenfields = html_writer::empty_tag('input',
        [
            'type' => 'hidden',
            'name' => 'id',
            'value' => $objpage->cmid,
            'id' => 'idhfieldcmid',
        ]
    );
    $hiddenfields .= html_writer::empty_tag('input',
        [
            'type' => 'hidden',
            'name' => 'pageid',
            'value' => $objpage->id,
            'id' => 'idhfieldpageid',
        ]
    );
    $hiddenfields .= html_writer::empty_tag('input',
        [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
            'id' => 'idhfieldsesskey',
        ]
    );
    // Button send questions.
    $qbtnsend = html_writer::empty_tag('input',
        [
            'type' => 'submit',
            'name' => 'qbtnsend',
            'class' => 'btn-sendanswers btn-primary pull-right',
            'value' => get_string('sendanswers', 'mod_icontent'),
        ]
    );
    $coldivbtnsend = html_writer::div($qbtnsend, 'col align-self-end');
    $divbtnsend = html_writer::div($coldivbtnsend, 'row sendanswers mb-2');
    // Tag form.
    $qform = html_writer::tag('form', $hiddenfields. $qlist. $divbtnsend,
        [
            'action' => '',
            'method' => 'POST',
            'id' => 'idformquestions',
        ]
    );
    $divcontent = html_writer::div($qform, 'contentquestionsarea',
        [
            'id' => 'idcontentquestionsarea',
            'style' => $togglearea->style,
        ]
    );
    return html_writer::div($title. $divcontent, 'questionsarea', ['id' => 'idquestionsarea']);
}

/**
 * This is the function responsible for creating the answers of questions area.
 *
 * Patterns for field names and values of question types:
 * Multichoice name = qpid-QPID_qid-QID_QTYPE or qpid-QPID_qid-QID_QTYPE[];
 * Multichoice value = qpid-QPID_answerid-ID;
 * Match name = qpid-QPID_qid-QID_QTYPE-ID;
 * Truefalse name = qpid-QPID_qid-QID_QTYPE;
 * Essay name = qpid-QPID_qid-QID_QTYPE.
 *
 * Important: Items in capital letters must be replaced by variables.
 *
 * Returns fields and answers by type.
 *
 * @param object $question
 * @return string $answers
 */
function icontent_make_questions_answers_by_type($question) {
    global $DB;
    switch ($question->qtype) {
        case ICONTENT_QTYPE_MULTICHOICE:
            $answers = $DB->get_records('question_answers', ['question' => $question->qid]);
            shuffle($answers); // 20240718 Trying to shuffle the answers for multichoice question. Appears to work!
            $totalrightanswers = $DB->count_records_select(
                'question_answers',
                'question = ? AND fraction > ?',
                [
                    $question->qid,
                    0,
                ],
                'COUNT(fraction)'
            );
            // Print out the prompts. If there is more than one correct answer use the if, Choice {$a} options:,
            // and if there is only one answer use the else, Choice a:.
            if ($totalrightanswers > 1) {
                $type = 'checkbox';
                $brackets = '[]';
                $strprompt = get_string('choiceoneormore', 'mod_icontent', $totalrightanswers);
            } else {
                $type = 'radio';
                $brackets = '';
                $strprompt = get_string('choiceone', 'mod_icontent');
            }
            $strpromptinfo = html_writer::span($strprompt, 'label label-info');
            $questionanswers = html_writer::start_div('question '.ICONTENT_QTYPE_MULTICHOICE);
            $questionanswers .= html_writer::div(strip_tags($question->questiontext, '<b><strong>'), 'questiontext');
            $questionanswers .= html_writer::div($strpromptinfo, 'prompt');
            $questionanswers .= html_writer::start_div('optionslist'); // Start div options list.
            foreach ($answers as $anwswer) {
                $fieldname = 'qpid-'.$question->qpid.'_qid-'.$question->qid.'_'.ICONTENT_QTYPE_MULTICHOICE.$brackets;
                $value = 'qpid-'.$question->qpid.'_answerid-'.$anwswer->id;
                $fieldid = 'idfield-qpid:'.$question->qpid.'_answerid:'.$anwswer->id;
                $check = html_writer::empty_tag(
                    'input',
                    [
                        'id' => $fieldid,
                        'name' => $fieldname,
                        'type' => $type,
                        'value' => $value,
                        'class' => 'mr-2',
                    ]
                );
                $label = html_writer::label(strip_tags($anwswer->answer), $fieldid);
                $questionanswers .= html_writer::div($check. $label);
            }
            $questionanswers .= html_writer::end_div(); // End div options list.
            $questionanswers .= html_writer::end_div();
            return $questionanswers;
            break;
        case ICONTENT_QTYPE_MATCH:
            $options = $DB->get_records('qtype_match_subquestions', ['questionid' => $question->qid], 'answertext');
            $questionanswers = html_writer::start_div('question '.ICONTENT_QTYPE_MATCH);
            $questionanswers .= html_writer::div(strip_tags($question->questiontext, '<b><strong>'), 'questiontext mr-2');
            $questionanswers .= html_writer::start_div('optionslist'); // Start div options list.
            $contenttable = '';
            $arrayanswers = [];
            // 20240721 This shuffles the order of match list, but the correct answer goes with it.
            shuffle($options); // 20240718 Trying to shuffle the answers for matching question.
            foreach ($options as $option) {
                $optanswertext = trim(strip_tags($option->answertext));
                $arrayanswers[$optanswertext] = $optanswertext;
            }
            // ...shuffle($arrayanswers); // 20240718 Trying to shuffle the answers for matching question...
            // 20240705 Shuffling here does jumble the answers the way I want, but they then ALWAYS get marked as being wrong.
            // Maybe need to use this shuffle and then rework the code elsewhere to see if the selections are correct.

            foreach ($options as $option) {
                $fieldname = 'qpid-'.$question->qpid.'_qid-'.$question->qid.'_'.ICONTENT_QTYPE_MATCH.'-'.$option->id;
                $qtext = html_writer::tag('td', strip_tags($option->questiontext), ['class' => 'matchoptions']);
                // ...shuffle($arrayanswers); // 20240718 Trying to shuffle the answers for matching question...
                $answertext = html_writer::tag(
                    'td',
                    html_writer::select($arrayanswers, $fieldname, null,
                        [
                            '' => 'choosedots',
                        ],
                        [
                            'required' => 'required',
                        ]
                    )
                );
                $contenttable .= html_writer::tag('tr', $qtext. $answertext);
            }
            // ...shuffle($arrayanswers); // 20240718 Trying to shuffle the answers for matching question...
            // 20240705 Trying to shuffle the answers here does not do what is need.

            $questionanswers .= html_writer::tag('table', $contenttable);
            $questionanswers .= html_writer::end_div(); // End div options list.
            $questionanswers .= html_writer::end_div();
            return $questionanswers;
            break;
        case ICONTENT_QTYPE_TRUEFALSE:
            $answers = $DB->get_records('question_answers', ['question' => $question->qid]);
            shuffle($answers); // 20240718 Trying to shuffle the answers for true/false question. Appears to work!
            $strpromptinfo = html_writer::span(get_string('choiceoneoption', 'mod_icontent'), 'label label-info'.'test3');
            $questionanswers = html_writer::start_div('question '.ICONTENT_QTYPE_TRUEFALSE);
            $questionanswers .= html_writer::div(strip_tags($question->questiontext, '<b><strong>'), 'questiontext');
            $questionanswers .= html_writer::div($strpromptinfo, 'prompt');
            $questionanswers .= html_writer::start_div('optionslist'); // Start div options list.
            foreach ($answers as $anwswer) {
                $fieldname = 'qpid-'.$question->qpid.'_qid-'.$question->qid.'_'.ICONTENT_QTYPE_TRUEFALSE;
                $value = 'qpid-'.$question->qpid.'_answerid-'.$anwswer->id;
                $fieldid = 'idfield-qpid:'.$question->qpid.'_answerid:'.$anwswer->id;
                $radio = html_writer::empty_tag('input',
                    [
                        'id' => $fieldid,
                        'name' => $fieldname,
                        'type' => 'radio',
                        'value' => $value,
                        'class' => 'mr-2',
                    ]
                );
                $label = html_writer::label(strip_tags($anwswer->answer), $fieldid);
                $questionanswers .= html_writer::div($radio. $label, 'options');
            }
            $questionanswers .= html_writer::end_div(); // End div options list.
            $questionanswers .= html_writer::end_div();
            return $questionanswers;
            break;
        case ICONTENT_QTYPE_ESSAY:
            $fieldname = 'qpid-'.$question->qpid.'_qid-'.$question->qid.'_'.ICONTENT_QTYPE_ESSAY;
            $qoptions = $DB->get_records('qtype_essay_options', ['questionid' => $question->qid]);
            $questionanswers = html_writer::start_div('question essay');
            $questionanswers .= html_writer::div(strip_tags($question->questiontext, '<b><strong>'), 'questiontext');
            // 20240204 Modified params. See ticket iContent_1188.
            $questionanswers .= html_writer::tag('textarea', null,
                [
                    'name' => $fieldname,
                    'class' => 'col-12 answertextarea',
                    'required' => 'required',
                    'placeholder' => get_string('writeessay', 'mod_icontent'),
                ]
            );
            $questionanswers .= html_writer::end_div();
            return $questionanswers;
            break;
        default:
            return false;
    }
}

/**
 * This is the function responsible for creating the attempt summary the current page.
 *
 * Returns attempt summary.
 *
 * @param int $pageid
 * @param int $cmid
 * @return string $attemptsummary
 */
function icontent_make_attempt_summary_by_page($pageid, $cmid) {
    global $DB;
    // Get objects that create summary attempt.
    $summaryattempt = icontent_get_attempt_summary_by_page($pageid, $cmid);
    $rightanswer = icontent_get_right_answers_by_attempt_summary_by_page($pageid, $cmid); // Items with hits.
    $openanswer = icontent_get_open_answers_by_attempt_summary_by_page($pageid, $cmid);
    $allownewattempts = icontent_user_can_remove_attempts_answers_for_tryagain($pageid, $cmid);
    // Check capabilities for new attempts.
    $straction = null;
    $iconrepeatattempt = null;
    if ($allownewattempts) {
        $straction = get_string('action', 'mod_icontent');
        // Icon repeat attempt.
        $iconrepeatattempt = html_writer::link(
            new moodle_url('deleteattempt.php',
                [
                    'id' => $cmid,
                    'pageid' => $pageid,
                    'sesskey' => sesskey(),
                ]
            ),
            '<i class="fa fa-repeat fa-lg"></i>',
            [
                'title' => get_string('tryagain', 'mod_icontent'),
                'data-toggle' => 'tooltip',
                'data-placement' => 'top',
            ]
        );
    }
    $expandarea = $DB->get_field('icontent_pages', 'expandquestionsarea', ['id' => $pageid]);
    $togglearea = icontent_get_toggle_area_object($expandarea);
    // Create title.
    $title = html_writer::tag('h4', $togglearea->icon. get_string('resultlastattempt', 'mod_icontent'),
        [
            'class' => 'titlequestions text-uppercase '.$togglearea->class,
            'id' => 'idtitlequestionsarea',
        ]
    );
    // Create table.
    $summarygrid = new html_table();
    $summarygrid->id = "idcontentquestionsarea";
    $summarygrid->attributes = [
        'class' => 'table table-hover contentquestionsarea icontentattemptsummary',
        'style' => $togglearea->style,
    ];
    $summarygrid->head = [
        get_string('state', 'mod_icontent'),
        get_string('answers', 'mod_icontent'),
        get_string('rightanswers', 'mod_icontent'),
        get_string('result', 'mod_icontent'),
        $straction,
    ];
    $state = get_string('strstate', 'mod_icontent', userdate($summaryattempt->timecreated));
    $totalanswers = $summaryattempt->totalanswers;
    $totalrightanswers = $rightanswer->totalrightanswers;
    $stropenanswer = $openanswer->totalopenanswers ?
        get_string('stropenanswer', 'mod_icontent', $openanswer->totalopenanswers) : '';
    // String.
    $evaluate = new stdClass();
    $evaluate->fraction = number_format($summaryattempt->sumfraction, 2);
    $evaluate->maxfraction = number_format($summaryattempt->totalanswers, 2);
    $evaluate->percentage = round(($summaryattempt->sumfraction * 100) / $summaryattempt->totalanswers);
    $evaluate->openanswer = $stropenanswer;
    $strevaluate = get_string('strtoevaluate', 'mod_icontent', $evaluate);
    // Set data.
    $summarygrid->data[] = [$state, $totalanswers, $totalrightanswers, $strevaluate, $iconrepeatattempt];

    // Create table summary attempt.
    $tablesummary = html_writer::table($summarygrid);
    return html_writer::div($title. $tablesummary, 'questionsarea', ['id' => 'idquestionsarea']);
}

/**
 * This is the function responsible for creating the area comments on pages.
 *
 * Returns notes area.
 *
 * @param object $objpage
 * @param object $icontent
 * @return string $notesarea
 */
function icontent_make_notesarea($objpage, $icontent) {
    if (!$icontent->shownotesarea) {
        return false;
    }
    $context = context_module::instance($objpage->cmid);
    if (!has_capability('mod/icontent:viewnotes', $context)) {
        return false;
    }
    global $OUTPUT, $USER;
    $togglearea = icontent_get_toggle_area_object($objpage->expandnotesarea);

    // Title page.
    $title = html_writer::tag('h4', $togglearea->icon.get_string('doubtandnotes', 'mod_icontent'),
        [
            'class' => 'titlenotes text-uppercase '.$togglearea->class,
            'id' => 'idtitlenotes',
        ]
    );

    // User image used under the Notes and Question tabs.
    $picture = html_writer::tag('div', $OUTPUT->user_picture($USER,
        [
            'size' => 120,
            'class' => 'img-thumbnail',
        ]),
        [
            'class' => 'col-2 userpicture',
        ]
    );

    // Fields. Create text area for notes.
    $textareanote = html_writer::tag('textarea', null,
        [
            'name' => 'comment',
            'id' => 'idcommentnote',
            'class' => 'col-12',
            'maxlength' => '1024',
            'required' => 'required',
            'placeholder' => get_string('writenotes', 'mod_icontent'),
        ]
    );

    // Create checkboxes for private and featured, right under the notes textarea.
    $spanprivate = icontent_make_span_checkbox_field_private($objpage);
    $spanfeatured = icontent_make_span_checkbox_field_featured($objpage);

    // Create the, Save, button under the right side of the note textarea.
    $btnsavenote = html_writer::tag('button', get_string('save', 'mod_icontent'),
        [
            'class' => 'btn btn-primary pull-right',
            'id' => 'idbtnsavenote',
            'data-pageid' => $objpage->id,
            'data-cmid' => $objpage->cmid,
            'data-sesskey' => sesskey(),
        ]
    );

    // Create text area for questions.
    $textareadoubt = html_writer::tag('textarea', null,
        [
            'name' => 'comment',
            'id' => 'idcommentdoubt',
            'class' => 'col-12',
            'maxlength' => '1024',
            'required' => 'required',
            'placeholder' => get_string('writedoubt', 'mod_icontent'),
        ]
    );
    // Create check box for, Ask tutor only.
    $spandoubttutor = icontent_make_span_checkbox_field_doubttutor($objpage);
    // Create the question save button.
    $btnsavedoubt = html_writer::tag('button', get_string('save', 'mod_icontent'),
        [
            'class' => 'btn btn-primary pull-right',
            'id' => 'idbtnsavedoubt',
            'data-pageid' => $objpage->id,
            'data-cmid' => $objpage->cmid,
            'data-sesskey' => sesskey(),
        ]
    );

    // Create text area for tags.
    /*
    $textareatag = html_writer::tag('textarea', null,
        [
            'name' => 'comment',
            'id' => 'idcommenttag',
            'class' => 'col-12',
            'maxlength' => '1024',
            'required' => 'required',
            'placeholder' => get_string('writetag', 'mod_icontent'),
        ]
    );
    */
    // Create check box for, Ask tutor only. NOT NEEDED.
    // $spandoubttutor = icontent_make_span_checkbox_field_doubttutor($objpage); NOT NEEDED.
    // Create the question save button.
    // I think a tag save button will NOT BE NEEDED.
    /*
    $btnsavetag = html_writer::tag('button', get_string('save', 'mod_icontent'),
        [
            'class' => 'btn btn-primary pull-right',
            'id' => 'idbtnsavedoubt',
            'data-pageid' => $objpage->id,
            'data-cmid' => $objpage->cmid,
            'data-sesskey' => sesskey(),
        ]
    );
    */

    // Data page.
    $datapagenotesnote = icontent_get_pagenotes($objpage->id, $objpage->cmid, 'note'); // Data page notes note.
    $datapagenotesdoubt = icontent_get_pagenotes($objpage->id, $objpage->cmid, 'doubt'); // Data page notes question.
    // Placeholder for tags code.
    // $datapagenotestag = icontent_get_pagenotes($objpage->id, $objpage->cmid, 'doubt'); // Data page notes tag.
    $pagenotesnote = html_writer::div(icontent_make_listnotespage($datapagenotesnote, $icontent, $objpage),
        'pagenotesnote',
        [
            'id' => 'idpagenotesnote',
        ]
    );
    $pagenotesdoubt = html_writer::div(icontent_make_listnotespage($datapagenotesdoubt, $icontent, $objpage),
        'pagenotesdoubt',
        [
            'id' => 'idpagenotesdoubt',
        ]
    );

    // Fields.
    $fieldsnote = html_writer::tag('div', $textareanote.$spanprivate.$spanfeatured.$btnsavenote.$pagenotesnote,
        [
            'class' => 'col-10',
        ]
    );
    $fieldsdoubt = html_writer::tag('div', $textareadoubt.$spandoubttutor.$btnsavedoubt.$pagenotesdoubt,
        [
            'class' => 'col-10',
        ]
    );
    // Placeholder for tags code.
    /*
    $fieldstag = html_writer::tag('div', $textareatag.$btnsavetag.$pagenotestag,
        [
            'class' => 'col-10'
        ]
    );
    */

    // Forms.
    $formnote = html_writer::tag('div', $picture.$fieldsnote, ['class' => 'row fields mt-2']);
    $formdoubt = html_writer::tag('div', $picture.$fieldsdoubt, ['class' => 'row fields mt-2']);
    // Placeholder for tags code.
    // $formdoubt = html_writer::tag('div', $picture.$fieldstag, ['class' => 'row fields mt-2']);

    // TAB NAVS.
    $note = html_writer::tag('li',
        html_writer::link('#note', get_string('note', 'icontent', count($datapagenotesnote)),
            [
                'id' => 'note-tab',
                'aria-controls' => 'note',
                'role' => 'tab',
                'data-toggle' => 'tab',
                'class' => 'nav-link active',
            ]
        ),
        [
            'class' => 'nav-item',
            'role' => 'presentation',
        ]
    );
    $doubt = html_writer::tag('li',
        html_writer::link('#doubt', get_string('doubt', 'icontent', count($datapagenotesdoubt)),
            [
                'id' => 'doubt-tab',
                'aria-controls' => 'doubt',
                'role' => 'tab',
                'data-toggle' => 'tab',
                'class' => 'nav-link',
            ]
        ),
        [
            'class' => 'nav-item',
            'role' => 'presentation',
        ]
    );
    // Placeholder for tags code.
    /*
    $tag = html_writer::tag('li',
        html_writer::link('#tag', get_string('tag', 'icontent', count($datapagenotestag)),
            [
                'id' => 'tag-tab',
                'aria-controls' => 'tag',
                'role' => 'tab',
                'data-toggle' => 'tab',
                'class' => 'nav-link',
            ]
        ),
        [
            'class' => 'nav-item',
            'role' => 'presentation',
        ]
    );
    */
    $tabnav = html_writer::tag('ul', $note .$doubt, ['class' => 'nav nav-tabs', 'id' => 'tabnav']);
    // Placeholder for tags code.
    // $tabnav = html_writer::tag('ul', $note .$doubt .$tag, ['class' => 'nav nav-tabs', 'id' => 'tabnav']);
    // TAB CONTENT.
    $icontentnote = html_writer::div($formnote, 'tab-pane active', ['role' => 'tabpanel', 'id' => 'note']);
    $icontentdoubt = html_writer::div($formdoubt, 'tab-pane', ['role' => 'tabpanel', 'id' => 'doubt']);
    // Placeholder for tags code.
    // $icontenttag = html_writer::div($formtag, 'tab-pane', ['role' => 'tabpanel', 'id' => 'tag']);
    $tabicontent = html_writer::div($icontentnote.$icontentdoubt, 'tab-content', ['id' => 'idtabicontent']);
    $fulltab = html_writer::div($tabnav.$tabicontent, 'fulltab', ['id' => 'idfulltab', 'style' => $togglearea->style]);
    // Return notes area.
    return html_writer::tag('div', $title.$fulltab, ['class' => 'notesarea', 'id' => 'idnotesarea']);
}

/**
 * This is the function responsible for creating checkbox field private.
 *
 * Returns span with checkbox field.
 *
 * @param string $page
 * @return string $spancheckbox
 */
function icontent_make_span_checkbox_field_private($page) {
    $context = context_module::instance($page->cmid);
    if (icontent_user_can_view_checkbox_field_private($context)) {
        $checkprivate = html_writer::tag('input', null,
            [
                'name' => 'private',
                'type' => 'checkbox',
                'id' => 'idprivate',
                'class' => 'icontent-checkbox mr-2',
            ]
        );
        $labelprivate = html_writer::tag('label', get_string('private', 'mod_icontent'),
            [
                'for' => 'idprivate',
                'class' => 'icontent-label',
            ]
        );
        // Return span.
        return html_writer::tag('span', $checkprivate. $labelprivate, ['class' => 'fieldprivate font-weight-light']);
    }
    return false;
}

/**
 * This is the function responsible for creating checkbox field featured.
 *
 * Returns span with checkbox featured.
 *
 * @param string $page
 * @return string $spancheckbox
 */
function icontent_make_span_checkbox_field_featured($page) {
    $context = context_module::instance($page->cmid);
    if (icontent_user_can_view_checkbox_field_featured($context)) {
        $checkfeatured = html_writer::tag('input', null,
            [
                'name' => 'featured',
                'type' => 'checkbox',
                'id' => 'idfeatured',
                'class' => 'icontent-checkbox mr-2',
            ]
        );
        $labelfeatured = html_writer::tag('label', get_string('featured', 'mod_icontent'),
            [
                'for' => 'idfeatured',
                'class' => 'icontent-label',
            ]
        );
        // Return span.
        return html_writer::tag('span', $checkfeatured. $labelfeatured, ['class' => 'fieldfeatured font-weight-light']);
    }
    return false;
}

/**
 * This is the function responsible for creating checkbox field doubttutor.
 *
 * Returns span with checkbox doubttutor
 * @param object $page
 * @return string $spancheckbox
 */
function icontent_make_span_checkbox_field_doubttutor($page) {
    $context = context_module::instance($page->cmid);
    if (icontent_user_can_view_checkbox_field_doubttutor($context)) {
        $checkdoubttutor = html_writer::tag('input', null,
            [
                'name' => 'doubttutor',
                'type' => 'checkbox',
                'id' => 'iddoubttutor',
                'class' => 'icontent-checkbox mr-2',
            ]
        );
        $labeldoubttutor = html_writer::tag('label', get_string('doubttutor', 'mod_icontent'),
            [
                'for' => 'iddoubttutor',
                'class' => 'icontent-label',
            ]
        );
        // Return span.
        return html_writer::tag('span', $checkdoubttutor. $labeldoubttutor, ['class' => 'fielddoubttutor font-weight-light']);
    }
    return false;
}

/**
 * This is the function responsible for creating notes list by page.
 *
 * Returns notes list
 *
 * @param object $pagenotes
 * @param object $icontent
 * @param object $page
 * @return string $listnotes
 */
function icontent_make_listnotespage($pagenotes, $icontent, $page) {
    global $OUTPUT;
    if (!empty($pagenotes)) {
        $divnote = '';
        $context = context_module::instance($page->cmid);
        foreach ($pagenotes as $pagenote) {
            // Object user.
            $user = icontent_get_user_by_id($pagenote->userid);
            // Get picture for use with the note listing.
            $picture = $OUTPUT->user_picture($user, ['size' => 35, 'class' => 'img-thumbnail pull-left']);
            // Note header comprised of the user first name and the title of the slide.
            $linkfirstname = html_writer::link(new moodle_url('/user/view.php',
                [
                    'id' => $user->id,
                    'course' => $icontent->course,
                ]),
                $user->firstname.' '.$user->lastname,
                [
                    'title' => $user->firstname,
                ]
            );
            $noteon = html_writer::tag('em', get_string('notedon', 'icontent'), ['class' => 'noteon mr-2 ml-2']);
            // Reply header.
            $replyon = html_writer::tag('em', ' '.strtolower(trim(get_string('respond', 'icontent'))).': ',
                [
                    'class' => 'noteon mr-2 ml-2',
                ]
            );
            $notepagetitle = html_writer::span($page->title, 'notepagetitle');
            $noteheader = $pagenote->parent ? html_writer::div($linkfirstname. $replyon, 'noteheader') :
                html_writer::div($linkfirstname.$noteon.$notepagetitle, 'noteheader');
            // Note comments.
            $notecomment = html_writer::div($pagenote->comment, 'notecomment',
                [
                    'data-pagenoteid' => $pagenote->id,
                    'data-cmid' => $pagenote->cmid,
                    'data-sesskey' => sesskey(),
                ]
            );
            // Note footer.
            $noteedit = icontent_make_link_edit_note($pagenote, $context);
            $noteremove = icontent_make_link_remove_note($pagenote, $context);
            $notelike = icontent_make_likeunlike($pagenote, $context);
            $notereply = icontent_make_link_reply_note($pagenote, $context);
            $notedate = html_writer::tag('span', userdate($pagenote->timecreated), ['class' => 'notedate pull-right']);
            // Create footer with items in the order given here.
            $notefooter = html_writer::div($noteedit.$noteremove.$notereply.$notelike. $notedate, 'notefooter');
            // Verify path levels.
            $pathlevels = icontent_get_noteparentinglevels($pagenote->path);

            // Assemle all the notes into just one Div list.
            $noterowicontent = html_writer::div($noteheader.$notecomment.$notefooter, 'noterowicontent');
            $divnote .= html_writer::div($picture.$noterowicontent, "pagenoterow level-$pathlevels",
                [
                    'data-level' => $pathlevels,
                    'id' => "pnote{$pagenote->id}",
                ]
            );
        }
        $divnotes = html_writer::div($divnote, 'span notelist');
        return $divnotes;
    }
    // Do this if there are not any notes.
    return html_writer::div(get_string('nonotes', 'icontent'));
}

/**
 * This is the function responsible for creating the responses of notes.
 *
 * Returns responses of notes.
 *
 * @param object $pagenote
 * @param object $icontent
 * @return string $pagenotereply
 */
function icontent_make_pagenotereply($pagenote, $icontent) {
    global $OUTPUT;
    $user = icontent_get_user_by_id($pagenote->userid);
    $context = context_module::instance($pagenote->cmid);
    // Get picture for use with the reply listing.
    $picture = $OUTPUT->user_picture($user, ['size' => 30, 'class' => 'img-thumbnail pull-left']);
    // Note header.
    $linkfirstname = html_writer::link(new moodle_url('/user/view.php',
        [
            'id' => $user->id,
            'course' => $icontent->course,
        ]),
        $user->firstname,
        [
            'title' => $user->firstname,
        ]
    );
    $replyon = html_writer::tag('em', ' '.strtolower(trim(get_string('respond', 'icontent'))).': ',
        [
            'class' => 'noteon mr-2 ml-2',
        ]
    );
    $noteheader = html_writer::div($linkfirstname. $replyon, 'noteheader');
    // Note comments.
    $notecomment = html_writer::div($pagenote->comment, 'notecomment',
        [
            'data-pagenoteid' => $pagenote->id,
            'data-cmid' => $pagenote->cmid,
            'data-sesskey' => sesskey(),
        ]
    );
    // Note footer.
    $noteedit = icontent_make_link_edit_note($pagenote, $context);
    $noteremove = icontent_make_link_remove_note($pagenote, $context);
    $notelike = icontent_make_likeunlike($pagenote, $context);
    $notereply = icontent_make_link_reply_note($pagenote, $context);
    $notedate = html_writer::tag('span', userdate($pagenote->timecreated), ['class' => 'notedate pull-right']);
    $notefooter = html_writer::div($noteedit. $noteremove. $notereply. $notelike. $notedate, 'notefooter');
    // Verify path levels.
    $pathlevels = icontent_get_noteparentinglevels($pagenote->path);
    // Div list page notes.
    $noterowicontent = html_writer::div($noteheader. $notecomment. $notefooter, 'noterowicontent');
    // Return reply.
    return html_writer::div($picture. $noterowicontent, "pagenoterow level-{$pathlevels}",
        [
            'data-level' => $pathlevels,
            'id' => "pnote{$pagenote->id}",
        ]
    );
}

/**
 * This is the function responsible for creating link to remove note.
 *
 * Returns link.
 *
 * @param object $pagenote
 * @param object $context
 * @return string $link
 */
function icontent_make_link_remove_note($pagenote, $context) {
    if (icontent_user_can_remove_note($pagenote, $context)) {
        return html_writer::link(new moodle_url('deletenote.php',
            [
                'id' => $pagenote->cmid,
                'pnid' => $pagenote->id,
                'sesskey' => sesskey(),
            ]),
            "<i class='fa fa-times'></i>".get_string('remove', 'icontent'),
            [
                'class' => 'removenote',
            ]
        );
    }
    return false;
}

/**
 * This is the function responsible for creating link to edit note.
 *
 * Returns link.
 *
 * @param object $pagenote
 * @param object $context
 * @return string $link
 */
function icontent_make_link_edit_note($pagenote, $context) {
    if (icontent_user_can_edit_note($pagenote, $context)) {
        return html_writer::link(null, "<i class='fa fa-pencil'></i>".get_string('edit', 'icontent'), ['class' => 'editnote']);
    }
    return false;
}

/**
 * This is the function responsible for creating link to reply note.
 *
 * Returns link.
 *
 * @param object $pagenote
 * @param object $context
 * @return string $link
 */
function icontent_make_link_reply_note($pagenote, $context) {
    if (icontent_user_can_reply_note($pagenote, $context)) {
        return html_writer::link(null, "<i class='fa fa-reply-all'></i>".get_string('reply', 'icontent'), ['class' => 'replynote']);
    }
    return false;
}

/**
 * This is the function responsible for creating links like and do not like.
 *
 * Returns links.
 *
 * @param object $pagenote
 * @param object $context
 * @return string $likeunlike
 */
function icontent_make_likeunlike($pagenote, $context) {
    global $USER;
    if (icontent_user_can_likeunlike_note($pagenote, $context)) {
        $pagenotelike = icontent_get_pagenotelike($pagenote->id, $USER->id, $pagenote->cmid);
        $countlikes = icontent_count_pagenotelike($pagenote->id);
        $notelinklabel = html_writer::span(get_string('like', 'icontent', $countlikes));
        if (!empty($pagenotelike)) {
            $notelinklabel = html_writer::span(get_string('unlike', 'icontent', $countlikes));
        }
        return html_writer::link(null, "<i class='fa fa-star-o'></i>".$notelinklabel,
            [
                'class' => 'likenote',
                'data-cmid' => $pagenote->cmid,
                'data-pagenoteid' => $pagenote->id,
                'data-sesskey' => sesskey(),
            ]
        );
    }
    return false;
}

/**
 * This is the function responsible for creating the toolbar.
 *
 * @param object $page
 * @param object $icontent
 * @return string $toolbar
 */
function icontent_make_toolbar($page, $icontent) {
    global $USER;
    // Icons for all users.
    $comments = html_writer::link('#idnotesarea', '<i class="fa fa-comments fa-lg"></i>',
        [
            'title' => s(get_string('comments', 'icontent')),
            'class' => 'icon icon-comments',
            'data-toggle' => 'tooltip',
            'data-placement' => 'top',
        ]
    );
    $icondisplayed = icontent_get_pagedisplayed($page->id, $page->cmid) ?
        '<i class="fa fa-check-square-o fa-lg"></i>' :
        '<i class="fa fa-square-o fa-lg"></i>';
    $displayed = html_writer::link('#', $icondisplayed,
        [
            'title' => s(get_string('statusview', 'icontent')),
            'class' => 'icon icon-displayed',
            'data-toggle' => 'tooltip',
            'data-placement' => 'top',
        ]
    );
    $highcontrast = html_writer::link('#!', '<i class="fa fa-adjust fa-lg"></i>',
        [
            'title' => s(get_string('highcontrast', 'icontent')),
            'class' => 'icon icon-highcontrast togglehighcontrast',
            'data-toggle' => 'tooltip',
            'data-placement' => 'top',
        ]
    );
    $update = false;
    $new = false;
    $addquestion = false;
    // Check if editing exists for $USER.
    if (property_exists($USER, 'editing')) {
        $context = context_module::instance($page->cmid);
        // Edit mode (view.php). Icons for teachers.
        if ($USER->editing && has_any_capability(['mod/icontent:edit', 'mod/icontent:manage'], $context)) {
            // Add new question.
            $addquestion = html_writer::link(
                new moodle_url('addquestionpage.php',
                    [
                        'id' => $page->cmid,
                        'pageid' => $page->id,
                    ]
                ),
                '<i class="fa fa-question-circle fa-lg"></i>',
                [
                    'title' => s(get_string('addquestion', 'mod_icontent')),
                    'class' => 'icon icon-addquestion',
                    'data-toggle' => 'tooltip',
                    'data-placement' => 'top',
                ]
            );
            // Update page.
            $update = html_writer::link(
                new moodle_url('edit.php',
                    [
                        'cmid' => $page->cmid,
                        'id' => $page->id,
                        'sesskey' => $USER->sesskey,
                    ]
                ),
                '<i class="fa fa-pencil-square-o fa-lg"></i>',
                [
                    'title' => s(get_string('editcurrentpage', 'mod_icontent')),
                    'class' => 'icon icon-update',
                    'data-toggle' => 'tooltip',
                    'data-placement' => 'top',
                    ]
                );
            // Add new page.
            $new = html_writer::link(
                new moodle_url('edit.php',
                    [
                        'cmid' => $page->cmid,
                        'pagenum' => $page->pagenum,
                        'sesskey' => $USER->sesskey,
                    ]
                ),
                '<i class="fa fa-plus-circle fa-lg"></i>',
                [
                    'title' => s(get_string('addnewpage', 'mod_icontent')),
                    'class' => 'icon icon-new',
                    'data-toggle' => 'tooltip',
                    'data-placement' => 'top',
                ]
            );
        }
    }
    // Make toolbar.
    $toolbar = html_writer::tag('div', $highcontrast.$comments.$displayed.$addquestion.$update.$new,
        [
            'class' => 'toolbarpage ',
        ]
    );
    // Return toolbar.
    return $toolbar;
}

/**
 * This is the function responsible for creating the content for cover page.
 *
 * Returns a string with the cover page.
 *
 * @param object $icontent
 * @param object $objpage
 * @param object $context
 * @return string $coverpage
 */
function icontent_make_cover_page($icontent, $objpage, $context) {
    $limitcharshow = 500;
    $displaynone = false;
    $strcontent = strip_tags($objpage->pageicontent);
    $tchars = strlen($strcontent);
    if ($tchars > $limitcharshow) {
        $chars = html_writer::start_tag('p', ['class' => 'read-more-wrap']);
        $chars .= substr($strcontent, 0, $limitcharshow);
        $chars .= html_writer::span('...', 'suspension-points');
        $chars .= html_writer::span(substr($strcontent, $limitcharshow, $tchars), 'read-more-target');
        $chars .= html_writer::end_tag('p');
        $buttons = html_writer::link(null, '<i class="fa fa-plus"></i>&nbsp;'.get_string('showmore', 'mod_icontent'),
            [
                'class' => 'btn btn-default read-more-state-on',
            ]
        );
        $buttons .= html_writer::link(null, '<i class="fa fa-minus"></i>&nbsp;'.get_string('showless', 'mod_icontent'),
            [
                'class' => 'btn btn-default read-more-state-off',
            ]
        );
        $chars .= html_writer::div($buttons, 'state-readmore');
    } else {
        $chars = html_writer::tag('p', $strcontent);
        // Checks if content is empty.
        $nospace = str_replace('&nbsp;', '', $strcontent);
        $nospace = str_replace('.', '', $nospace);
        $nospace = trim($nospace);
        // Add class 'hide' to hide element and builds the page.
        $displaynone = empty($nospace) ? 'hide' : false;
    }
    $script = icontent_add_script_load_tooltip();
    $title = html_writer::tag('h1', $objpage->title, ['class' => 'titlecoverpage']);
    $header = $objpage->showtitle ? html_writer::div($title, 'headercoverpage row ') : false;
    $content = html_writer::div($chars, "contentcoverpage ". $displaynone);
    $coverpage = html_writer::tag('div', $header. $content. $script,
        [
            'class' => 'fulltextpage coverpage',
            'data-pagenum' => $objpage->pagenum,
            'style' => icontent_get_page_style($icontent, $objpage, $context),
        ]
    );
    // Set page preview, log event and return page.
    icontent_add_pagedisplayed($objpage->id, $objpage->cmid);
    \mod_icontent\event\page_viewed::create_from_page($icontent, $context, $objpage)->trigger();
    return $coverpage;
}

/**
 * This is the function responsible for creating the content of a page.
 *
 * Returns an object with the page content.
 *
 * @param int $pagenum or $startpage
 * @param object $icontent
 * @param object $context
 * @return object $fullpage
 */
function icontent_get_fullpageicontent($pagenum, $icontent, $context) {
    global $DB, $CFG, $OUTPUT;
    //use core_tag_tag;

    // Get page.
    $objpage = $DB->get_record('icontent_pages', ['pagenum' => $pagenum, 'icontentid' => $icontent->id]);
    if (!$objpage) {
        $objpage = new stdClass();
        $objpage->fullpageicontent = html_writer::div(get_string('pagenotfound', 'mod_icontent'),
            'alert alert-warning',
            [
                'role' => 'alert',
            ]
        );
        return $objpage;
    }
    if ($objpage->coverpage) {
        // Make cover page.
        $objpage->fullpageicontent = icontent_make_cover_page($icontent, $objpage, $context);
        // Control button.
        $objpage->previous = icontent_get_prev_pagenum($objpage);
        $objpage->next = icontent_get_next_pagenum($objpage);
        return $objpage;
    }
    // Add tooltip.
    $script = icontent_add_script_load_tooltip();
    // Elements toolbar.
    $toolbarpage = icontent_make_toolbar($objpage, $icontent);
    // Add title page.
    $title = $objpage->showtitle ? html_writer::tag('h3', '<i class="fa fa-hand-o-right"></i> '.$objpage->title,
        [
            'class' => 'pagetitle',
        ]
    ) : false;
    // Make content.
    $objpage->pageicontent = file_rewrite_pluginfile_urls($objpage->pageicontent,
        'pluginfile.php',
        $context->id,
        'mod_icontent',
        'page',
        $objpage->id
    );
    $objpage->pageicontent = format_text($objpage->pageicontent, $objpage->pageicontentformat,
        [
            'noclean' => true,
            'overflowdiv' => false,
            'context' => $context,
        ]
    );
    $objpage->pageicontent = html_writer::div($objpage->pageicontent, 'page-layout columns-'.$objpage->layout);
    // Element page number.
    $npage = html_writer::tag('div', get_string('page', 'icontent', $objpage->pagenum), ['class' => 'pagenum']);
    // Progress bar.
    $progbar = icontent_make_progessbar($objpage, $icontent, $context);
    // Go assemble the list of Questions for this slide/page.
    $qtsareas = icontent_make_questionsarea($objpage, $icontent);
    // Form notes.
    $notesarea = icontent_make_notesarea($objpage, $icontent);
    // 20240920 If tags exist add them to each entry.
    $tarea = $OUTPUT->tag_list(
        core_tag_tag::get_item_tags(
            'mod_icontent',
            'icontent_pages',
            $objpage->id,
        ),
        null,
        'icontent-tags'
    );

    // Control button.
    $objpage->previous = icontent_get_prev_pagenum($objpage);
    $objpage->next = icontent_get_next_pagenum($objpage);
    // Content page for return.
    $objpage->fullpageicontent = html_writer::tag('div',
        $toolbarpage.
        $title.
        $objpage->pageicontent.
        $npage.
        $progbar.
        $qtsareas.
        $notesarea.
        $tarea.
        $script,
        [
            'class' => 'fulltextpage',
            'data-pagenum' => $objpage->pagenum,
            'style' => icontent_get_page_style($icontent, $objpage, $context),
        ]
    );
    // Set page preview, log event and return page.
    icontent_add_pagedisplayed($objpage->id, $objpage->cmid);
    \mod_icontent\event\page_viewed::create_from_page($icontent, $context, $objpage)->trigger();
    unset($objpage->pageicontent);
    return $objpage;
}

/**
 * Returns icontent pages tagged with a specified tag.
 *
 * This is a callback used by the tag area mod_icontent/icontent_pages to search for icontent pages
 * tagged with a specific tag.
 *
 * @param core_tag_tag $tag
 * @param bool $exclusivemode if set to true it means that no other entities tagged with this tag
 *             are displayed on the page and the per-page limit may be bigger
 * @param int $fromctx context id where the link was displayed, may be used by callbacks
 *            to display items in the same context first
 * @param int $ctx context id where to search for records
 * @param bool $rec search in subcontexts as well
 * @param int $page 0-based number of page being displayed
 * @return \core_tag\output\tagindex
 */
function mod_icontent_get_tagged_pages($tag, $exclusivemode = false, $fromctx = 0, $ctx = 0, $rec = 1, $page = 0) {
    global $OUTPUT;
    $perpage = $exclusivemode ? 20 : 5;

    // Build the SQL query.
    $ctxselect = context_helper::get_preload_record_columns_sql('ctx');
    $query = "SELECT ip.id, ip.title, ip.icontentid,
                     cm.id AS cmid, c.id AS courseid, c.shortname, c.fullname, $ctxselect
                FROM {icontent_pages} ip
                JOIN {icontent} ic ON ip.icontentid = ic.id
                JOIN {modules} m ON m.name='icontent'
                JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = ic.id
                JOIN {tag_instance} tt ON ip.id = tt.itemid
                JOIN {course} c ON cm.course = c.id
                JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :coursemodulecontextlevel
               WHERE tt.itemtype = :itemtype AND tt.tagid = :tagid AND tt.component = :component
                 AND cm.deletioninprogress = 0
                 AND ip.id %ITEMFILTER% AND c.id %COURSEFILTER%";

    $params = ['itemtype' => 'icontent_pages',
        'tagid' => $tag->id,
        'component' => 'mod_icontent',
        'coursemodulecontextlevel' => CONTEXT_MODULE,
    ];

    if ($ctx) {
        $context = $ctx ? context::instance_by_id($ctx) : context_system::instance();
        $query .= $rec ? ' AND (ctx.id = :contextid OR ctx.path LIKE :path)' : ' AND ctx.id = :contextid';
        $params['contextid'] = $context->id;
        $params['path'] = $context->path.'/%';
    }

    $query .= " ORDER BY ";
    if ($fromctx) {
        // In order-clause specify that modules from inside "fromctx" context should be returned first.
        $fromcontext = context::instance_by_id($fromctx);
        $query .= ' (CASE WHEN ctx.id = :fromcontextid OR ctx.path LIKE :frompath THEN 0 ELSE 1 END),';
        $params['fromcontextid'] = $fromcontext->id;
        $params['frompath'] = $fromcontext->path.'/%';
    }
    $query .= ' c.sortorder, cm.id, ip.id';

    $totalpages = $page + 1;

    // Use core_tag_index_builder to build and filter the list of items.
    $builder = new core_tag_index_builder('mod_icontent', 'icontent_pages', $query, $params, $page * $perpage, $perpage + 1);
    while ($item = $builder->has_item_that_needs_access_check()) {
        context_helper::preload_from_record($item);
        $courseid = $item->courseid;
        if (!$builder->can_access_course($courseid)) {
            $builder->set_accessible($item, false);
            continue;
        }
        $modinfo = get_fast_modinfo($builder->get_course($courseid));
        // Set accessibility of this item and all other items in the same course.
        $builder->walk(function ($taggeditem) use ($courseid, $modinfo, $builder) {
            if ($taggeditem->courseid == $courseid) {
                $accessible = false;
                if (($cm = $modinfo->get_cm($taggeditem->cmid)) && $cm->uservisible) {
                    // Not sure if this is needed for iContent.
                    //$subicontent = (object)array('id' => $taggeditem->subicontentid, 'groupid' => $taggeditem->groupid,
                    //    'userid' => $taggeditem->userid, 'icontentid' => $taggeditem->icontentid);
                    //$icontent = (object)array('id' => $taggeditem->icontentid, 'icontentmode' => $taggeditem->icontentmode,
                    $icontent = (object)['id' => $taggeditem->icontentid, 'course' => $cm->course];
                    $accessible = icontent_user_can_view($subicontent, $icontent);
                }
                $builder->set_accessible($taggeditem, $accessible);
            }
        });
    }

    $items = $builder->get_items();
    if (count($items) > $perpage) {
        $totalpages = $page + 2; // We don't need exact page count, just indicate that the next page exists.
        array_pop($items);
    }

    // Build the display contents.
    if ($items) {
        $tagfeed = new core_tag\output\tagfeed();
        foreach ($items as $item) {
            context_helper::preload_from_record($item);
            $modinfo = get_fast_modinfo($item->courseid);
            $cm = $modinfo->get_cm($item->cmid);
            $pageurl = new moodle_url('/mod/icontent/view.php', ['pageid' => $item->id]);
            $pagename = format_string($item->title, true, ['context' => context_module::instance($item->cmid)]);
            $pagename = html_writer::link($pageurl, $pagename);
            $courseurl = course_get_url($item->courseid, $cm->sectionnum);
            $cmname = html_writer::link($cm->url, $cm->get_formatted_name());
            $coursename = format_string($item->fullname, true, ['context' => context_course::instance($item->courseid)]);
            $coursename = html_writer::link($courseurl, $coursename);
            $icon = html_writer::link($pageurl, html_writer::empty_tag('img', ['src' => $cm->get_icon_url()]));
            $tagfeed->add($icon, $pagename, $cmname.'<br>'.$coursename);
        }

        $content = $OUTPUT->render_from_template('core_tag/tagfeed',
                $tagfeed->export_for_template($OUTPUT));

        // These are only printed when you click on a tag item in a Tag Block.
        //print_object($item);
        //print_object($tag);
        //print_object($content);
        //print_object($exclusivemode);
        //print_object($fromctx);
        //print_object($ctx);
        //print_object($rec);
        //print_object($page);
        //print_object($totalpages);
        //die;

        return new core_tag\output\tagindex($tag, 'mod_icontent', 'icontent_pages', $content,
                $exclusivemode, $fromctx, $ctx, $rec, $page, $totalpages);
    }
}
