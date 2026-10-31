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
 * Privacy class for requesting user data.
 *
 * @package   mod_icontent
 * @copyright 2024 AL Rachels <drachels@drachels.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_icontent\privacy;

defined('MOODLE_INTERNAL') || die(); // phpcs:ignore

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

require_once($CFG->dirroot . '/mod/icontent/lib.php');

/**
 * Privacy class for requesting user data.
 *
 * @package   mod_icontent
 * @copyright 2019 AL Rachels <drachels@drachels.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Provides meta data that is stored about a user with mod_icontent.
     * The acutal student data is in the, mod_icontent_pages_notes table.
     *
     * @param collection $collection The initialized collection to add items to.
     * @return collection Returns the collection of metadata.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'icontent_pages_notes',
            [
                'pageid' => 'privacy:metadata:icontent_pages_notes:pageid',
                'userid' => 'privacy:metadata:icontent_pages_notes:userid',
                'cmid' => 'privacy:metadata:icontent_pages_notes:cmid',
                'comment' => 'privacy:metadata:icontent_pages_notes:comment',
                'timecreated' => 'privacy:metadata:icontent_pages_notes:timecreated',
                'timemodified' => 'privacy:metadata:icontent_pages_notes:timemodified',
                'tab' => 'privacy:metadata:icontent_pages_notes:tab',
                'path' => 'privacy:metadata:icontent_pages_notes:path',
                'parent' => 'privacy:metadata:icontent_pages_notes:parent',
                'private' => 'privacy:metadata:icontent_pages_notes:private',
                'featured' => 'privacy:metadata:icontent_pages_notes:featured',
                'doubttutor' => 'privacy:metadata:icontent_pages_notes:doubttutor',
            ],
            'privacy:metadata:icontent_pages_notes'
        );

        $collection->add_database_table(
            'icontent_pages_notes_like',
            [
                'pagenoteid' => 'privacy:metadata:icontent_pages_notes_like:pagenoteid',
                'userid' => 'privacy:metadata:icontent_pages_notes_like:userid',
                'cmid' => 'privacy:metadata:icontent_pages_notes_like:cmid',
                'timemodified' => 'privacy:metadata:icontent_pages_notes_like:timemodified',
            ],
            'privacy:metadata:icontent_pages_notes_like'
        );

        $collection->add_database_table(
            'icontent_pages_displayed',
            [
                'pageid' => 'privacy:metadata:icontent_pages_displayed:pageid',
                'userid' => 'privacy:metadata:icontent_pages_displayed:userid',
                'cmid' => 'privacy:metadata:icontent_pages_displayed:cmid',
                'timecreated' => 'privacy:metadata:icontent_pages_displayed:timecreated',
            ],
            'privacy:metadata:icontent_pages_displayed'
        );

        $collection->add_database_table(
            'icontent_question_attempts',
            [
                'pagesquestionsid' => 'privacy:metadata:icontent_question_attempts:pagesquestionsid',
                'questionid' => 'privacy:metadata:icontent_question_attempts:questionid',
                'userid' => 'privacy:metadata:icontent_question_attempts:userid',
                'cmid' => 'privacy:metadata:icontent_question_attempts:cmid',
                'fraction' => 'privacy:metadata:icontent_question_attempts:fraction',
                'rightanswer' => 'privacy:metadata:icontent_question_attempts:rightanswer',
                'answertext' => 'privacy:metadata:icontent_question_attempts:answertext',
                'timecreated' => 'privacy:metadata:icontent_question_attempts:timecreated',
            ],
            'privacy:metadata:icontent_question_attempts'
        );

        $collection->add_database_table(
            'icontent_grades',
            [
                'icontentid' => 'privacy:metadata:icontent_grades:icontentid',
                'userid' => 'privacy:metadata:icontent_grades:userid',
                'cmid' => 'privacy:metadata:icontent_grades:cmid',
                'grade' => 'privacy:metadata:icontent_grades:grade',
                'timemodified' => 'privacy:metadata:icontent_grades:timemodified',
            ],
            'privacy:metadata:icontent_grades'
        );

        $collection->add_database_table(
            'icontent_submissions',
            [
                'icontentid' => 'privacy:metadata:icontent_submissions:icontentid',
                'userid' => 'privacy:metadata:icontent_submissions:userid',
                'groupid' => 'privacy:metadata:icontent_submissions:groupid',
                'status' => 'privacy:metadata:icontent_submissions:status',
                'timesubmitted' => 'privacy:metadata:icontent_submissions:timesubmitted',
                'timecreated' => 'privacy:metadata:icontent_submissions:timecreated',
                'timemodified' => 'privacy:metadata:icontent_submissions:timemodified',
                'grade' => 'privacy:metadata:icontent_submissions:grade',
                'feedback' => 'privacy:metadata:icontent_submissions:feedback',
                'grader' => 'privacy:metadata:icontent_submissions:grader',
                'timegraded' => 'privacy:metadata:icontent_submissions:timegraded',
                'extensionduedate' => 'privacy:metadata:icontent_submissions:extensionduedate',
            ],
            'privacy:metadata:icontent_submissions'
        );

        $collection->add_database_table(
            'icontent_pages',
            [
                'submissionid' => 'privacy:metadata:icontent_pages:submissionid',
                'title' => 'privacy:metadata:icontent_pages:title',
                'pageicontent' => 'privacy:metadata:icontent_pages:pageicontent',
                'timecreated' => 'privacy:metadata:icontent_pages:timecreated',
                'timemodified' => 'privacy:metadata:icontent_pages:timemodified',
            ],
            'privacy:metadata:icontent_pages'
        );

        return $collection;
    }

    /** @var int */
    private static $modid;

    /**
     * Get the module id for the 'icontent' module.
     * @return false|mixed
     * @throws \dml_exception
     */
    private static function get_modid() {
        global $DB;
        if (self::$modid === null) {
            self::$modid = $DB->get_field('modules', 'id', ['name' => 'icontent']);
        }
        return self::$modid;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist $contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $modid = self::get_modid();
        if (!$modid) {
            return $contextlist; // The icontent module is not installed.
        }

                $params = [
                        'modid' => $modid,
                        'contextlevel' => CONTEXT_MODULE,
                        'useridnotes' => $userid,
                        'useridlikes' => $userid,
                        'useriddisplayed' => $userid,
                        'useridattempts' => $userid,
                        'useridgrades' => $userid,
                        'useridsubmissions' => $userid,
                        'useridgroupmembers' => $userid,
                ];

                $sql = '
                        SELECT DISTINCT c.id
                            FROM {context} c
                            JOIN {course_modules} cm
                                ON cm.id = c.instanceid
                             AND c.contextlevel = :contextlevel
                             AND cm.module = :modid
                 LEFT JOIN {icontent_pages_notes} n
                                ON n.cmid = cm.id
                             AND n.userid = :useridnotes
                 LEFT JOIN {icontent_pages_notes_like} l
                                ON l.cmid = cm.id
                             AND l.userid = :useridlikes
                 LEFT JOIN {icontent_pages_displayed} d
                                ON d.cmid = cm.id
                             AND d.userid = :useriddisplayed
                 LEFT JOIN {icontent_question_attempts} qa
                                ON qa.cmid = cm.id
                             AND qa.userid = :useridattempts
                 LEFT JOIN {icontent_grades} g
                                ON g.cmid = cm.id
                             AND g.userid = :useridgrades
                 LEFT JOIN {icontent_submissions} s
                                ON s.icontentid = cm.instance
                             AND (
                                 s.userid = :useridsubmissions
                                 OR s.groupid IN (
                                     SELECT groupid FROM {groups_members} WHERE userid = :useridgroupmembers
                                 )
                             )
                         WHERE n.id IS NOT NULL
                                OR l.id IS NOT NULL
                                OR d.id IS NOT NULL
                                OR qa.id IS NOT NULL
                                OR g.id IS NOT NULL
                                OR s.id IS NOT NULL
                ';

                $contextlist->add_from_sql($sql, $params);

                return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if (!is_a($context, \context_module::class)) {
            return;
        }

        $modid = self::get_modid();
        if (!$modid) {
            return; // Checklist module not installed.
        }

        if (!$cm = get_coursemodule_from_id('icontent', $context->instanceid)) {
                return;
        }

                $params = ['cmid' => $cm->id];

                $sql = '
                        SELECT userid FROM {icontent_pages_notes} WHERE cmid = :cmid
                        UNION
                        SELECT userid FROM {icontent_pages_notes_like} WHERE cmid = :cmid
                        UNION
                        SELECT userid FROM {icontent_pages_displayed} WHERE cmid = :cmid
                        UNION
                        SELECT userid FROM {icontent_question_attempts} WHERE cmid = :cmid
                        UNION
                        SELECT userid FROM {icontent_grades} WHERE cmid = :cmid
                        UNION
                        SELECT userid FROM {icontent_submissions}
                         WHERE icontentid = :icontentid AND userid > 0
                        UNION
                        SELECT gm.userid
                          FROM {icontent_submissions} s
                          JOIN {groups_members} gm ON gm.groupid = s.groupid
                         WHERE s.icontentid = :groupicontentid AND s.groupid > 0
                        UNION
                        SELECT grader FROM {icontent_submissions}
                         WHERE icontentid = :gradericontentid AND grader > 0
                ';
                $params['icontentid'] = (int)$cm->instance;
                $params['groupicontentid'] = (int)$cm->instance;
                $params['gradericontentid'] = (int)$cm->instance;

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            if (!$cm = get_coursemodule_from_id('icontent', $context->instanceid)) {
                continue;
            }

            $notes = $DB->get_records(
                'icontent_pages_notes',
                ['cmid' => $cm->id, 'userid' => $userid],
                'timecreated DESC'
            );
            $likes = $DB->get_records(
                'icontent_pages_notes_like',
                ['cmid' => $cm->id, 'userid' => $userid],
                'timemodified DESC'
            );
            $displayed = $DB->get_records(
                'icontent_pages_displayed',
                ['cmid' => $cm->id, 'userid' => $userid],
                'timecreated DESC'
            );
            $attempts = $DB->get_records(
                'icontent_question_attempts',
                ['cmid' => $cm->id, 'userid' => $userid],
                'timecreated DESC'
            );
            $grades = $DB->get_records(
                'icontent_grades',
                ['cmid' => $cm->id, 'userid' => $userid],
                'timemodified DESC'
            );
            $gradedsubmissions = $DB->get_records(
                'icontent_submissions',
                ['icontentid' => $cm->instance, 'grader' => $userid],
                'timegraded DESC',
                'id, status, timegraded'
            );
            $submissions = $DB->get_records_sql(
                'SELECT DISTINCT s.*
                   FROM {icontent_submissions} s
              LEFT JOIN {groups_members} gm ON gm.groupid = s.groupid AND gm.userid = :groupuserid
                  WHERE s.icontentid = :icontentid
                    AND (s.userid = :userid OR gm.userid IS NOT NULL)
               ORDER BY s.timecreated DESC',
                ['groupuserid' => $userid, 'icontentid' => $cm->instance, 'userid' => $userid]
            );
            $submissionpages = [];
            if ($submissions) {
                [$submissionidsql, $submissionparams] = $DB->get_in_or_equal(
                    array_keys($submissions),
                    SQL_PARAMS_NAMED,
                    'submissionid'
                );
                $submissionpages = $DB->get_records_select(
                    'icontent_pages',
                    "submissionid $submissionidsql",
                    $submissionparams,
                    'submissionid, pagenum',
                    'id, submissionid, title, pageicontent, pageicontentformat, timecreated, timemodified'
                );
            }

            $export = (object) [
                'notes' => array_map(static function ($note) {
                    return (object) [
                        'pageid' => $note->pageid,
                        'comment' => strip_tags($note->comment),
                        'tab' => $note->tab,
                        'path' => $note->path,
                        'parent' => $note->parent,
                        'private' => (int) $note->private,
                        'featured' => (int) $note->featured,
                        'doubttutor' => (int) $note->doubttutor,
                        'timecreated' => $note->timecreated ? transform::datetime($note->timecreated) : '',
                        'timemodified' => $note->timemodified ? transform::datetime($note->timemodified) : '',
                    ];
                }, array_values($notes)),
                'likes' => array_map(static function ($like) {
                    return (object) [
                        'pagenoteid' => $like->pagenoteid,
                        'timemodified' => $like->timemodified ? transform::datetime($like->timemodified) : '',
                        'visible' => (int) $like->visible,
                    ];
                }, array_values($likes)),
                'displayedpages' => array_map(static function ($row) {
                    return (object) [
                        'pageid' => $row->pageid,
                        'timecreated' => $row->timecreated ? transform::datetime($row->timecreated) : '',
                    ];
                }, array_values($displayed)),
                'questionattempts' => array_map(static function ($attempt) {
                    return (object) [
                        'pagesquestionsid' => $attempt->pagesquestionsid,
                        'questionid' => $attempt->questionid,
                        'fraction' => $attempt->fraction,
                        'rightanswer' => $attempt->rightanswer,
                        'answertext' => $attempt->answertext,
                        'timecreated' => $attempt->timecreated ? transform::datetime($attempt->timecreated) : '',
                    ];
                }, array_values($attempts)),
                'grades' => array_map(static function ($grade) {
                    return (object) [
                        'icontentid' => $grade->icontentid,
                        'grade' => $grade->grade,
                        'timemodified' => $grade->timemodified ? transform::datetime($grade->timemodified) : '',
                    ];
                }, array_values($grades)),
                'assignmentsubmissions' => array_map(static function ($submission) {
                    return (object)[
                        'groupid' => (int)$submission->groupid,
                        'status' => $submission->status,
                        'timesubmitted' => $submission->timesubmitted
                            ? transform::datetime($submission->timesubmitted) : '',
                        'timecreated' => $submission->timecreated
                            ? transform::datetime($submission->timecreated) : '',
                        'timemodified' => $submission->timemodified
                            ? transform::datetime($submission->timemodified) : '',
                        'grade' => $submission->grade,
                        'extensionduedate' => empty($submission->extensionduedate)
                            ? '' : transform::datetime($submission->extensionduedate),
                        'feedback' => $submission->feedback,
                        'timegraded' => $submission->timegraded
                            ? transform::datetime($submission->timegraded) : '',
                    ];
                }, array_values($submissions)),
                'assignmentpages' => array_map(static function ($page) {
                    return (object)[
                        'submissionid' => (int)$page->submissionid,
                        'title' => $page->title,
                        'content' => $page->pageicontent,
                        'contentformat' => (int)$page->pageicontentformat,
                        'timecreated' => $page->timecreated ? transform::datetime($page->timecreated) : '',
                        'timemodified' => $page->timemodified
                            ? transform::datetime($page->timemodified) : '',
                    ];
                }, array_values($submissionpages)),
                'gradedsubmissions' => array_map(static function ($submission) {
                    return (object)[
                        'submissionid' => (int)$submission->id,
                        'status' => $submission->status,
                        'timegraded' => $submission->timegraded
                            ? transform::datetime($submission->timegraded) : '',
                    ];
                }, array_values($gradedsubmissions)),
            ];

            self::export_icontent_data_for_user($export, $cm->id, $contextlist->get_user());
        }
    }

    /**
     * Export the supplied personal data for a single icontent activity, along with any generic data or area files.
     *
     * @param \stdClass $items The data for the icontent module.
     * @param int $cmid
     * @param \stdClass $user
     */
    protected static function export_icontent_data_for_user(\stdClass $items, int $cmid, \stdClass $user) {
        // Fetch the generic module data for the choice.
        $context = \context_module::instance($cmid);
        $contextdata = helper::get_context_data($context, $user);

        // Merge with icontent data and write it.
        $contextdata = (object)array_merge((array)$contextdata, (array)$items);
        writer::with_context($context)->export_data([], $contextdata);

        // Write generic module intro files.
        helper::export_context_files($context, $user);
    }

    /**
     * Remove the pages and associated data for selected submissions.
     *
     * @param int $cmid
     * @param array $submissionids
     * @return void
     */
    private static function delete_submission_pages(int $cmid, array $submissionids): void {
        global $DB;

        if (!$submissionids) {
            return;
        }

        [$submissionsql, $submissionparams] = $DB->get_in_or_equal(
            array_map('intval', $submissionids),
            SQL_PARAMS_NAMED,
            'privacy_submission'
        );
        $pageids = $DB->get_fieldset_select('icontent_pages', 'id', "submissionid $submissionsql", $submissionparams);
        if (!$pageids) {
            return;
        }

        [$pagesql, $pageparams] = $DB->get_in_or_equal(
            array_map('intval', $pageids),
            SQL_PARAMS_NAMED,
            'privacy_page'
        );
        $questionids = $DB->get_fieldset_select(
            'icontent_pages_questions',
            'id',
            "pageid $pagesql",
            $pageparams
        );
        if ($questionids) {
            [$questionsql, $questionparams] = $DB->get_in_or_equal(
                array_map('intval', $questionids),
                SQL_PARAMS_NAMED,
                'privacy_question'
            );
            $DB->delete_records_select(
                'icontent_question_attempts',
                "pagesquestionsid $questionsql",
                $questionparams
            );
            $DB->delete_records_select('icontent_pages_questions', "id $questionsql", $questionparams);
        }

        $noteids = $DB->get_fieldset_select('icontent_pages_notes', 'id', "pageid $pagesql", $pageparams);
        if ($noteids) {
            [$notesql, $noteparams] = $DB->get_in_or_equal(
                array_map('intval', $noteids),
                SQL_PARAMS_NAMED,
                'privacy_note'
            );
            $DB->delete_records_select('icontent_pages_notes_like', "pagenoteid $notesql", $noteparams);
            $DB->delete_records_select('icontent_pages_notes', "id $notesql", $noteparams);
        }

        $context = \context_module::instance($cmid);
        $fs = get_file_storage();
        foreach ($pageids as $pageid) {
            \core_tag_tag::remove_all_item_tags('mod_icontent', 'icontent_pages', (int)$pageid);
            $fs->delete_area_files($context->id, 'mod_icontent', 'page', (int)$pageid);
            $fs->delete_area_files($context->id, 'mod_icontent', 'bgpage', (int)$pageid);
        }

        $DB->delete_records_select('icontent_pages_displayed', "pageid $pagesql", $pageparams);
        $DB->delete_records_select('icontent_pages', "id $pagesql", $pageparams);
    }

    /**
     * Delete notes owned by selected users and all likes attached to those notes.
     *
     * @param int $cmid
     * @param array $userids
     * @return void
     */
    private static function delete_user_notes(int $cmid, array $userids): void {
        global $DB;

        if (!$userids) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal(
            array_map('intval', $userids),
            SQL_PARAMS_NAMED,
            'privacy_note_user'
        );
        $noteids = $DB->get_fieldset_select(
            'icontent_pages_notes',
            'id',
            "cmid = :cmid AND userid $usersql",
            array_merge(['cmid' => $cmid], $userparams)
        );
        if ($noteids) {
            [$notesql, $noteparams] = $DB->get_in_or_equal(
                array_map('intval', $noteids),
                SQL_PARAMS_NAMED,
                'privacy_note_id'
            );
            $DB->delete_records_select('icontent_pages_notes_like', "pagenoteid $notesql", $noteparams);
        }
        $DB->delete_records_select(
            'icontent_pages_notes_like',
            "cmid = :cmid AND userid $usersql",
            array_merge(['cmid' => $cmid], $userparams)
        );
        $DB->delete_records_select(
            'icontent_pages_notes',
            "cmid = :cmid AND userid $usersql",
            array_merge(['cmid' => $cmid], $userparams)
        );
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context) {
            return;
        }

        // This should not happen, but just in case.
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        if (!$cm = get_coursemodule_from_id('icontent', $context->instanceid)) {
            return;
        }

        $DB->delete_records('icontent_pages_notes_like', ['cmid' => $cm->id]);
        $DB->delete_records('icontent_pages_notes', ['cmid' => $cm->id]);
        $DB->delete_records('icontent_pages_displayed', ['cmid' => $cm->id]);
        $DB->delete_records('icontent_question_attempts', ['cmid' => $cm->id]);
        $DB->delete_records('icontent_grades', ['cmid' => $cm->id]);
        $submissionids = $DB->get_fieldset_select(
            'icontent_submissions',
            'id',
            'icontentid = :icontentid',
            ['icontentid' => (int)$cm->instance]
        );
        self::delete_submission_pages((int)$cm->id, $submissionids);
        $DB->delete_records('icontent_submissions', ['icontentid' => $cm->instance]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        if (!$contextlist->count()) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            if (!$cm = get_coursemodule_from_id('icontent', $context->instanceid)) {
                continue;
            }
            self::delete_user_notes((int)$cm->id, [(int)$userid]);
            $DB->delete_records('icontent_pages_displayed', ['cmid' => $cm->id, 'userid' => $userid]);
            $DB->delete_records('icontent_question_attempts', ['cmid' => $cm->id, 'userid' => $userid]);
            $DB->delete_records('icontent_grades', ['cmid' => $cm->id, 'userid' => $userid]);

            $submissionids = $DB->get_fieldset_select(
                'icontent_submissions',
                'id',
                'icontentid = :icontentid AND userid = :userid',
                ['icontentid' => (int)$cm->instance, 'userid' => $userid]
            );
            self::delete_submission_pages((int)$cm->id, $submissionids);
            if ($submissionids) {
                [$insql, $inparams] = $DB->get_in_or_equal($submissionids, SQL_PARAMS_NAMED, 'privacy_sub');
                $DB->delete_records_select(
                    'icontent_submissions',
                    "id $insql",
                    $inparams
                );
            }
            $DB->set_field('icontent_submissions', 'grader', 0, [
                'icontentid' => (int)$cm->instance,
                'grader' => $userid,
            ]);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if (!is_a($context, \context_module::class)) {
            return;
        }
        $modid = self::get_modid();
        if (!$modid) {
            return; // The icontent module is not installed.
        }
        if (!$cm = get_coursemodule_from_id('icontent', $context->instanceid)) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        self::delete_user_notes((int)$cm->id, $userids);
        $DB->delete_records_select(
            'icontent_pages_displayed',
            "userid $insql AND cmid = :cmid",
            array_merge($inparams, ['cmid' => $cm->id])
        );
        $DB->delete_records_select(
            'icontent_question_attempts',
            "userid $insql AND cmid = :cmid",
            array_merge($inparams, ['cmid' => $cm->id])
        );
        $DB->delete_records_select(
            'icontent_grades',
            "userid $insql AND cmid = :cmid",
            array_merge($inparams, ['cmid' => $cm->id])
        );

        $submissionids = $DB->get_fieldset_select(
            'icontent_submissions',
            'id',
            "icontentid = :icontentid AND userid $insql",
            array_merge(['icontentid' => (int)$cm->instance], $inparams)
        );
        self::delete_submission_pages((int)$cm->id, $submissionids);
        if ($submissionids) {
            [$submissioninsql, $submissioninparams] = $DB->get_in_or_equal(
                $submissionids,
                SQL_PARAMS_NAMED,
                'privacy_submission'
            );
            $DB->delete_records_select('icontent_submissions', "id $submissioninsql", $submissioninparams);
        }
        $DB->set_field_select(
            'icontent_submissions',
            'grader',
            0,
            "icontentid = :icontentid AND grader $insql",
            array_merge(['icontentid' => (int)$cm->instance], $inparams)
        );
    }
}
