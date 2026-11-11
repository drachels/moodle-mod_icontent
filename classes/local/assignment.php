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
 * Assignment submission helpers for iContent.
 *
 * @package    mod_icontent
 * @copyright  2026 AL Rachels
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_icontent\local;

/**
 * Helpers for resolving and checking student/group page-set submissions.
 */
class assignment {
    /** Submission is still editable by its owner. */
    public const STATUS_DRAFT = 'draft';

    /** Submission has been sent for grading. */
    public const STATUS_SUBMITTED = 'submitted';

    /** Submission has been graded. */
    public const STATUS_GRADED = 'graded';

    /**
     * Get or create the current user's submission.
     *
     * @param \stdClass $icontent Activity record.
     * @param \stdClass $cm Course module record.
     * @param int $userid User id.
     * @param bool $create Whether a missing submission should be created.
     * @return \stdClass|null
     */
    public static function get_current_submission(
        \stdClass $icontent,
        \stdClass $cm,
        int $userid,
        bool $create = true
    ): ?\stdClass {
        global $DB;

        if (empty($icontent->assignmentmode)) {
            return null;
        }

        $owner = self::get_owner($icontent, $cm, $userid);
        if ($owner === null) {
            return null;
        }

        $conditions = [
            'icontentid' => (int)$icontent->id,
            'userid' => $owner['userid'],
            'groupid' => $owner['groupid'],
        ];
        $submission = $DB->get_record('icontent_submissions', $conditions);
        if ($submission || !$create) {
            return $submission ?: null;
        }

        $now = time();
        $submission = (object)($conditions + [
            'status' => self::STATUS_DRAFT,
            'timesubmitted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $submission->id = $DB->insert_record('icontent_submissions', $submission);
        return $submission;
    }

    /**
     * Get a submission by its id, constrained to the activity.
     *
     * @param int $icontentid Activity id.
     * @param int $submissionid Submission id.
     * @return \stdClass|null
     */
    public static function get_submission(int $icontentid, int $submissionid): ?\stdClass {
        global $DB;

        $submission = $DB->get_record('icontent_submissions', [
            'id' => $submissionid,
            'icontentid' => $icontentid,
        ]);
        return $submission ?: null;
    }

    /**
     * Get the submission deadline, including any owner-specific extension.
     *
     * @param \stdClass $icontent Activity record.
     * @param \stdClass $submission Submission record.
     * @return int Deadline timestamp, or zero for no deadline.
     */
    public static function get_due_date(\stdClass $icontent, \stdClass $submission): int {
        $duedate = (int)($icontent->duedate ?? 0);
        return $duedate ? max($duedate, (int)($submission->extensionduedate ?? 0)) : 0;
    }

    /**
     * Update an owner-specific extension and optionally reopen a submission without clearing grades.
     *
     * @param int $submissionid Submission id.
     * @param \stdClass $icontent Activity record.
     * @param \stdClass $cm Course module.
     * @param \context_module $context Module context.
     * @param int $extensionduedate Extension timestamp, or zero to remove it.
     * @param bool $reopen Return to an editable draft.
     * @return \stdClass Updated submission.
     */
    public static function manage_submission(
        int $submissionid,
        \stdClass $icontent,
        \stdClass $cm,
        \context_module $context,
        int $extensionduedate,
        bool $reopen
    ): \stdClass {
        global $DB, $USER;
        require_capability('mod/icontent:grade', $context);
        if (empty($icontent->assignmentmode)) {
            throw new \invalid_parameter_exception('This activity is not configured for submissions');
        }
        $submission = self::get_submission((int)$icontent->id, $submissionid);
        if (!$submission || !self::can_view_submission($submission, $icontent, $cm, $context, (int)$USER->id)) {
            throw new \required_capability_exception($context, 'mod/icontent:grade', 'nopermissions', '');
        }
        if ($extensionduedate < 0 || ($extensionduedate > 0 &&
            (empty($icontent->duedate) || $extensionduedate <= (int)$icontent->duedate))) {
            throw new \invalid_parameter_exception('An extension must be later than the activity due date');
        }
        if ($reopen && $submission->status === self::STATUS_DRAFT) {
            throw new \moodle_exception('submissionalreadydraft', 'icontent');
        }
        $submission->extensionduedate = $extensionduedate;
        if ($reopen) {
            $submission->status = self::STATUS_DRAFT;
            $submission->timesubmitted = 0;
        }
        $submission->timemodified = time();
        $DB->update_record('icontent_submissions', $submission);
        return $submission;
    }

    /**
     * Count logged page creation/edit events for a submission, or return null when logging is unavailable.
     *
     * @param \stdClass $submission Submission record.
     * @param \context_module $context Module context.
     * @return array|null User ids mapped to event counts.
     */
    public static function get_contributions(\stdClass $submission, \context_module $context): ?array {
        global $DB;

        $readers = get_log_manager()->get_readers('\core\log\sql_reader');
        $reader = null;
        foreach ($readers as $candidate) {
            if ($candidate->is_logging()) {
                $reader = $candidate;
                break;
            }
        }
        if ($reader === null) {
            return null;
        }

        // Older events lack submissionid; surviving page records identify their page set.
        $pageids = $DB->get_records('icontent_pages', [
            'icontentid' => $submission->icontentid,
            'submissionid' => $submission->id,
        ], '', 'id');
        $params = [
            'contextid' => $context->id,
            'created' => '\mod_icontent\event\page_created',
            'updated' => '\mod_icontent\event\page_updated',
            'start' => $submission->timecreated,
            'end' => $submission->timesubmitted ?: time(),
        ];
        $where = 'contextid = :contextid AND eventname IN (:created, :updated) ' .
            'AND timecreated >= :start AND timecreated <= :end';
        $counts = [];
        $offset = 0;
        do {
            $events = $reader->get_events_select($where, $params, 'timecreated ASC, id ASC', $offset, 500);
            foreach ($events as $event) {
                $eventsubmissionid = $event->other['submissionid'] ?? null;
                if (
                    ($eventsubmissionid !== null && (int)$eventsubmissionid === (int)$submission->id) ||
                    ($eventsubmissionid === null && isset($pageids[$event->objectid]))
                ) {
                    $counts[$event->userid] = ($counts[$event->userid] ?? 0) + 1;
                }
            }
            $offset += count($events);
        } while (count($events) === 500);
        return $counts;
    }

    /**
     * Validate a group grade and optional individual overrides and build gradebook updates.
     *
     * @param int[] $userids Eligible submission members.
     * @param string $rawgrade Group grade.
     * @param array $overrides User ids mapped to individual grade inputs.
     * @param int $maximumgrade Highest permitted grade.
     * @param bool $scalegrade Whether grades are scale positions.
     * @param int $grader Grading user.
     * @return array Gradebook updates keyed by user id.
     */
    public static function build_member_grades(
        array $userids,
        string $rawgrade,
        array $overrides,
        int $maximumgrade,
        bool $scalegrade,
        int $grader
    ): array {
        $validate = static function ($value) use ($maximumgrade, $scalegrade): float {
            if (!is_numeric($value)) {
                throw new \invalid_parameter_exception('Grade must be numeric');
            }
            $grade = (float)$value;
            if (
                !is_finite($grade) ||
                $grade < ($scalegrade ? 1 : 0) ||
                $grade > $maximumgrade ||
                ($scalegrade && floor($grade) !== $grade)
            ) {
                throw new \invalid_parameter_exception('Grade is outside the permitted range');
            }
            return $grade;
        };
        $groupgrade = $validate($rawgrade);
        if (array_diff(array_keys($overrides), $userids)) {
            throw new \invalid_parameter_exception('Individual grades must belong to submission members');
        }
        $grades = [];
        foreach ($userids as $userid) {
            $grades[$userid] = [
                'userid' => $userid,
                'rawgrade' => array_key_exists($userid, $overrides) ? $validate($overrides[$userid]) : $groupgrade,
                'usermodified' => $grader,
            ];
        }
        return $grades;
    }

    /**
     * Check whether the user owns or may view a submission.
     *
     * @param \stdClass $submission Submission record.
     * @param \stdClass $icontent Activity record.
     * @param \stdClass $cm Course module record.
     * @param \context_module $context Module context.
     * @param int $userid User id.
     * @return bool
     */
    public static function can_view_submission(
        \stdClass $submission,
        \stdClass $icontent,
        \stdClass $cm,
        \context_module $context,
        int $userid
    ): bool {
        if (has_any_capability(['mod/icontent:edit', 'mod/icontent:manage', 'mod/icontent:grade'], $context)) {
            if (
                groups_get_activity_groupmode($cm) === SEPARATEGROUPS &&
                !has_capability('moodle/site:accessallgroups', $context)
            ) {
                return self::submission_is_in_allowed_groups($submission, $cm);
            }
            return true;
        }

        if ((int)$submission->userid === $userid) {
            return true;
        }

        if (!empty($submission->groupid) && groups_is_member((int)$submission->groupid, $userid)) {
            return true;
        }

        return !empty($icontent->publicsubmissions) &&
            in_array($submission->status, [self::STATUS_SUBMITTED, self::STATUS_GRADED], true);
    }

    /**
     * Check whether a page belongs to a submission visible to the user.
     *
     * @param \stdClass $page Page record.
     * @param \stdClass $icontent Activity record.
     * @param \stdClass $cm Course module record.
     * @param \context_module $context Module context.
     * @param int $userid User id.
     * @return bool
     */
    public static function can_view_page(
        \stdClass $page,
        \stdClass $icontent,
        \stdClass $cm,
        \context_module $context,
        int $userid
    ): bool {
        if (empty($page->submissionid)) {
            return true;
        }

        $submission = self::get_submission((int)$icontent->id, (int)$page->submissionid);
        return $submission !== null && self::can_view_submission($submission, $icontent, $cm, $context, $userid);
    }

    /**
     * Check whether the user can edit a submission.
     *
     * @param \stdClass $submission Submission record.
     * @param \context_module $context Module context.
     * @param int $userid User id.
     * @return bool
     */
    public static function can_edit_submission(\stdClass $submission, \context_module $context, int $userid): bool {
        if (has_capability('mod/icontent:edit', $context)) {
            return true;
        }

        if (!has_capability('mod/icontent:submit', $context) || $submission->status !== self::STATUS_DRAFT) {
            return false;
        }

        if ((int)$submission->userid === $userid) {
            return true;
        }

        return !empty($submission->groupid) && groups_is_member((int)$submission->groupid, $userid);
    }

    /**
     * Check submission-page editing, including the activity's group visibility rules.
     *
     * @param \stdClass $page Page record.
     * @param \stdClass $icontent Activity record.
     * @param \stdClass $cm Course module.
     * @param \context_module $context Module context.
     * @param int $userid User id.
     * @return bool
     */
    public static function can_edit_page(
        \stdClass $page,
        \stdClass $icontent,
        \stdClass $cm,
        \context_module $context,
        int $userid
    ): bool {
        if (empty($icontent->assignmentmode) || empty($page->submissionid)) {
            return false;
        }
        $submission = self::get_submission((int)$icontent->id, (int)$page->submissionid);
        return $submission !== null &&
            self::can_view_submission($submission, $icontent, $cm, $context, $userid) &&
            self::can_edit_submission($submission, $context, $userid);
    }

    /**
     * Check whether a submission owner is in one of the user's allowed groups.
     *
     * @param \stdClass $submission
     * @param \stdClass $cm
     * @return bool
     */
    private static function submission_is_in_allowed_groups(\stdClass $submission, \stdClass $cm): bool {
        $allowedgroups = groups_get_activity_allowed_groups($cm);
        if (!empty($submission->groupid)) {
            return isset($allowedgroups[(int)$submission->groupid]);
        }

        return (bool)array_intersect(self::get_user_activity_groupids($cm, (int)$submission->userid), array_keys($allowedgroups));
    }

    /**
     * Find the owner identity for an individual or group submission.
     *
     * @param \stdClass $icontent Activity record.
     * @param \stdClass $cm Course module record.
     * @param int $userid User id.
     * @return array|null
     */
    private static function get_owner(\stdClass $icontent, \stdClass $cm, int $userid): ?array {
        if (empty($icontent->teamsubmission)) {
            return ['userid' => $userid, 'groupid' => 0];
        }

        if (groups_get_activity_groupmode($cm) === NOGROUPS) {
            return null;
        }

        $groupid = (int)groups_get_activity_group($cm, true);
        if (!$groupid) {
            $groupids = self::get_user_activity_groupids($cm, $userid);
            if (count($groupids) === 1) {
                $groupid = (int)reset($groupids);
            }
        }

        if (!$groupid || !groups_is_member($groupid, $userid)) {
            return null;
        }

        return ['userid' => 0, 'groupid' => $groupid];
    }

    /**
     * Get groups belonging to the activity's grouping for a user.
     *
     * @param \stdClass $cm Course module record.
     * @param int $userid User id.
     * @return array
     */
    private static function get_user_activity_groupids(\stdClass $cm, int $userid): array {
        $usergroups = groups_get_user_groups((int)$cm->course, $userid);
        $groupingid = (int)($cm->groupingid ?? 0);
        return array_values($usergroups[$groupingid] ?? []);
    }

    /** Grading area used by Moodle's built-in rubric. */
    public const GRADING_AREA = 'submission';

    /**
     * Return the built-in rubric controller when that method is active.
     *
     * Other gradingform plugins are ignored so every activity uses the same rubric.
     *
     * @param \context $context Module context.
     * @return \gradingform_controller|null
     */
    public static function get_grading_controller(\context $context): ?\gradingform_controller {
        global $CFG;
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $manager = get_grading_manager($context, 'mod_icontent', self::GRADING_AREA);
        if ($manager->get_active_method() !== 'rubric') {
            return null;
        }
        return $manager->get_controller('rubric');
    }

    /**
     * Prepare the current grader's built-in rubric instance for a submission.
     *
     * @param \context $context Module context.
     * @param \stdClass $icontent Activity record.
     * @param \stdClass $submission Submission record.
     * @param int $raterid Grading user.
     * @param int $instanceid Existing incomplete instance id, or 0 to create one.
     * @return \gradingform_instance|null
     */
    public static function prepare_grading_instance(
        \context $context,
        \stdClass $icontent,
        \stdClass $submission,
        int $raterid,
        int $instanceid = 0
    ): ?\gradingform_instance {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $controller = self::get_grading_controller($context);
        if (!$controller || !$controller->is_form_available()) {
            return null;
        }
        $controller->set_grade_range(make_grades_menu($icontent->grade), (int)$icontent->grade > 0);
        return $controller->get_or_create_instance($instanceid, $raterid, (int)$submission->id);
    }

    /**
     * Convert a completed built-in rubric into a grade string for the gradebook.
     *
     * @param \gradingform_instance $instance Rubric instance.
     * @param array $elementvalue Submitted rubric values.
     * @param int $submissionid Submission id used as the grading item id.
     * @param bool $scalegrade Whether the activity grade is a scale.
     * @return string
     */
    public static function grade_from_rubric(
        \gradingform_instance $instance,
        array $elementvalue,
        int $submissionid,
        bool $scalegrade
    ): string {
        if ($instance->is_empty_form($elementvalue)) {
            throw new \moodle_exception('rubricincomplete', 'icontent');
        }
        $grade = $instance->submit_and_get_grade($elementvalue, $submissionid);
        if (!is_numeric($grade) || (float)$grade < 0) {
            throw new \moodle_exception('rubricincomplete', 'icontent');
        }
        if ($scalegrade) {
            return (string)(int)round((float)$grade);
        }
        return (string)(float)$grade;
    }

    /**
     * Render the built-in rubric preview, or the completed rubric for a submission.
     *
     * @param \context $context Module context.
     * @param \stdClass $icontent Activity record.
     * @param int $submissionid Submission id.
     * @return string
     */
    public static function render_submission_rubric(
        \context $context,
        \stdClass $icontent,
        int $submissionid
    ): string {
        global $CFG, $PAGE;
        require_once($CFG->libdir . '/gradelib.php');

        $controller = self::get_grading_controller($context);
        if (!$controller || !$controller->is_form_defined()) {
            return '';
        }
        $controller->set_grade_range(make_grades_menu($icontent->grade), (int)$icontent->grade > 0);
        if ($submissionid && $controller->get_active_instances($submissionid)) {
            return $controller->render_grade($PAGE, $submissionid, [], '', false);
        }
        return $controller->render_preview($PAGE);
    }

    /** Keeps page-rubric grading instances distinct from submission ids. */
    public const PAGE_RUBRIC_ITEM_OFFSET = 1000000000;

    /** Manual-review status for a rubric page that has not been graded. */
    public const PAGE_RUBRIC_TOEVALUATE = 'toevaluate';

    /** Manual-review status for a graded rubric page. */
    public const PAGE_RUBRIC_VALUED = 'valued';

    /**
     * Sum reference-page scores and the learner's own page set, pooling group-page contributions.
     *
     * @param \stdClass $icontent Activity.
     * @param \stdClass $cm Course module.
     * @param int $userid Learner.
     * @param \stdClass|null $submission Explicit page set when a grader is viewing it.
     * @return \stdClass Totals and per-page rubric reviews.
     */
    public static function get_page_score_summary(
        \stdClass $icontent,
        \stdClass $cm,
        int $userid,
        ?\stdClass $submission = null
    ): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $submission = $submission ?? self::get_current_submission($icontent, $cm, $userid, false);
        if (!$submission && !empty($icontent->assignmentmode) && !empty($icontent->teamsubmission)) {
            $groupids = self::get_user_activity_groupids($cm, $userid);
            if ($groupids) {
                [$groupsql, $groupparams] = $DB->get_in_or_equal($groupids);
                $submissions = $DB->get_records_select(
                    'icontent_submissions',
                    "icontentid = ? AND groupid $groupsql",
                    array_merge([$icontent->id], $groupparams)
                );
                if (count($submissions) === 1) {
                    $submission = reset($submissions);
                }
            }
        }
        $members = [$userid];
        if ($submission && $submission->groupid) {
            $members = array_keys(get_enrolled_users(
                \context_module::instance((int)$cm->id),
                'mod/icontent:submit',
                (int)$submission->groupid,
                'u.id'
            ));
        }
        $pages = $DB->get_records_select('icontent_pages', 'cmid = ? AND (submissionid = 0 OR submissionid = ?)', [
            $cm->id, $submission->id ?? 0,
        ]);
        $summary = (object)[
            'earned' => 0.0, 'maximum' => 0.0, 'rubricearned' => 0.0, 'rubricmaximum' => 0.0,
            'totalanswers' => 0, 'pending' => 0, 'pages' => [], 'submission' => $submission,
        ];
        foreach ($pages as $page) {
            $userids = empty($page->submissionid) ? [$userid] : $members;
            if (!$userids) {
                continue;
            }
            [$usersql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'member');
            $params['pageid'] = $page->id;
            $questions = \icontent_get_pagequestions((int)$page->id, (int)$cm->id);
            $attempts = $DB->get_records_sql(
                "SELECT qa.*
                   FROM {icontent_question_attempts} qa
                   JOIN {icontent_pages_questions} pq ON pq.id = qa.pagesquestionsid
                  WHERE pq.pageid = :pageid AND qa.userid $usersql
               ORDER BY qa.timecreated, qa.id",
                $params
            );
            $latest = [];
            foreach ($attempts as $attempt) {
                $latest[$attempt->pagesquestionsid][$attempt->userid] = $attempt;
            }
            foreach ($questions as $question) {
                $contributions = $latest[$question->qpid] ?? [];
                if (!$contributions) {
                    $summary->maximum += (float)$question->maxmark;
                }
                foreach ($contributions as $attempt) {
                    $summary->maximum += (float)$attempt->maxmark > 0
                        ? (float)$attempt->maxmark : (float)$question->maxmark;
                    $summary->totalanswers++;
                    if ($attempt->rightanswer === self::PAGE_RUBRIC_TOEVALUATE) {
                        $summary->pending++;
                    } else {
                        $summary->earned += (float)$attempt->fraction;
                    }
                }
            }
            $rubric = (object)['earned' => 0.0, 'maximum' => 0.0, 'reviews' => []];
            if (!empty($page->userubric)) {
                $rubric->reviews = $DB->get_records_select(
                    'icontent_page_rubric_grades',
                    "pageid = :pageid AND userid $usersql",
                    $params,
                    'timemodified DESC, id DESC'
                );
                if (!$rubric->reviews) {
                    $rubric->maximum = (float)$page->rubricmaxmark;
                }
                foreach ($rubric->reviews as $review) {
                    $rubric->maximum += (float)$review->maxmark;
                    $summary->totalanswers++;
                    if ($review->status === self::PAGE_RUBRIC_VALUED && $review->grade !== null) {
                        $rubric->earned += (float)$review->grade;
                    } else {
                        $summary->pending++;
                    }
                }
            }
            $summary->rubricearned += $rubric->earned;
            $summary->rubricmaximum += $rubric->maximum;
            $summary->earned += $rubric->earned;
            $summary->maximum += $rubric->maximum;
            $summary->pages[$page->id] = $rubric;
        }
        $summary->grade = null;
        if ($summary->maximum > 0 && $summary->totalanswers > $summary->pending && (int)$icontent->grade !== 0) {
            $range = make_grades_menu($icontent->grade);
            $maximum = (float)max(array_keys($range));
            $summary->grade = max(0.0, min(1.0, $summary->earned / $summary->maximum)) * $maximum;
            if ((int)$icontent->grade < 0) {
                $summary->grade = max(1, (int)round($summary->grade));
            }
        }
        return $summary;
    }

    /**
     * Render private page totals and the activity grade for an owner or authorized grader.
     *
     * @param \stdClass $page Selected page.
     * @param \stdClass $icontent Activity.
     * @param \context_module $context Module context.
     * @return string
     */
    public static function render_page_results(
        \stdClass $page,
        \stdClass $icontent,
        \context_module $context
    ): string {
        global $CFG, $USER;
        require_once($CFG->libdir . '/gradelib.php');
        $cm = get_coursemodule_from_id('icontent', $context->instanceid, 0, false, MUST_EXIST);
        $grader = has_capability('mod/icontent:grade', $context);
        $userid = (int)$USER->id;
        $submission = null;
        if (!empty($page->submissionid)) {
            $submission = self::get_submission((int)$icontent->id, (int)$page->submissionid);
            if (!$submission || !self::can_view_submission($submission, $icontent, $cm, $context, $userid)) {
                return '';
            }
            if ($submission->groupid) {
                if (!$grader && !groups_is_member((int)$submission->groupid, $userid)) {
                    return '';
                }
                $members = get_enrolled_users($context, 'mod/icontent:submit', (int)$submission->groupid, 'u.id');
                if (!$members) {
                    return '';
                }
                if ($grader) {
                    $userid = (int)array_key_first($members);
                }
            } else {
                if (!$grader && $userid !== (int)$submission->userid) {
                    return '';
                }
                $userid = (int)$submission->userid;
            }
        } else if ($grader) {
            return '';
        }
        $summary = self::get_page_score_summary($icontent, $cm, $userid, $submission);
        $gradeinfo = grade_get_grades((int)$icontent->course, 'mod', 'icontent', (int)$icontent->id, $userid);
        $item = $gradeinfo->items[0] ?? null;
        $usergrade = $item->grades[$userid] ?? null;
        if (!$grader && $item && ($item->hidden || ($usergrade && $usergrade->hidden))) {
            return \html_writer::div(get_string('hidden', 'grades'), 'icontent-score-summary alert alert-info');
        }
        if ($summary->maximum <= 0 && (!$summary->submission || $summary->submission->grade === null)) {
            return '';
        }
        $points = static function (float $earned, float $maximum): string {
            return get_string('scoreoutof', 'icontent', (object)[
                'score' => format_float($earned, 2), 'maximum' => format_float($maximum, 2),
            ]);
        };
        $html = '';
        $rubric = $summary->pages[$page->id] ?? null;
        if ($rubric && $rubric->maximum > 0) {
            $html .= \html_writer::tag('p', get_string('pagerubricscore', 'icontent') . ': ' .
                $points($rubric->earned, $rubric->maximum));
            $completed = false;
            foreach ($rubric->reviews as $review) {
                if ($review->status === self::PAGE_RUBRIC_VALUED) {
                    $completed = true;
                    $html .= self::render_page_rubric($context, $icontent, (int)$review->id);
                }
            }
            if (!$completed) {
                $html .= self::render_page_rubric($context, $icontent);
            }
        }
        $html .= \html_writer::tag('p', get_string('pagescoresubtotal', 'icontent') . ': ' .
            $points($summary->earned, $summary->maximum));
        if ($summary->pending) {
            $html .= \html_writer::tag('p', get_string('scorespending', 'icontent', $summary->pending));
        }
        $finalgrade = $usergrade && $usergrade->grade !== null ? s($usergrade->str_grade) : get_string('nograde');
        $html .= \html_writer::tag('p', get_string('activitygrade', 'icontent') . ': ' . $finalgrade);
        if ($summary->submission && $summary->submission->grade !== null) {
            $html .= \html_writer::tag('p', get_string('pagesetgradeoverride', 'icontent') . ': ' .
                format_float((float)$summary->submission->grade, 2));
        }
        return \html_writer::div($html, 'icontent-score-summary alert alert-info');
    }

    /**
     * Grade-report columns use the same private subtotal and the authoritative gradebook value.
     *
     * Callers must require mod/icontent:grade.
     *
     * @param \stdClass $icontent Activity.
     * @param \stdClass $cm Course module.
     * @param int $userid Learner.
     * @return string[] Rubric subtotal and final activity grade.
     */
    public static function get_report_score_columns(\stdClass $icontent, \stdClass $cm, int $userid): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $summary = self::get_page_score_summary($icontent, $cm, $userid);
        $rubric = get_string('scoreoutof', 'icontent', (object)[
            'score' => format_float($summary->rubricearned, 2),
            'maximum' => format_float($summary->rubricmaximum, 2),
        ]);
        $gradeinfo = grade_get_grades((int)$icontent->course, 'mod', 'icontent', (int)$icontent->id, $userid);
        $grade = $gradeinfo->items[0]->grades[$userid] ?? null;
        return [$rubric, $grade && $grade->grade !== null ? s($grade->str_grade) : get_string('nograde')];
    }

    /**
     * Return the last page a user viewed in one page set.
     *
     * Navigation records capture AJAX transitions; displayed-page records
     * cover direct opens and refreshes.
     *
     * @param int $cmid Course module id.
     * @param int $userid User id.
     * @param int $submissionid Submission id, or zero for shared pages.
     * @return \stdClass|null
     */
    public static function get_last_viewed_page(int $cmid, int $userid, int $submissionid): ?\stdClass {
        global $DB;

        $page = $DB->get_record_sql(
            'SELECT p.*
               FROM {icontent_pages} p
               JOIN (
                    SELECT d.pageid, d.timecreated, d.id, 0 AS navorder
                      FROM {icontent_pages_displayed} d
                     WHERE d.cmid = :displaycmid AND d.userid = :displayuserid
                    UNION ALL
                    SELECT n.topageid AS pageid, n.timecreated, n.id, 1 AS navorder
                      FROM {icontent_pages_nav} n
                     WHERE n.cmid = :navcmid AND n.userid = :navuserid
               ) pagehistory ON pagehistory.pageid = p.id
              WHERE p.cmid = :cmid
                AND p.submissionid = :submissionid
                AND p.hidden = 0
           ORDER BY pagehistory.timecreated DESC, pagehistory.navorder DESC, pagehistory.id DESC',
            [
                'displaycmid' => $cmid,
                'displayuserid' => $userid,
                'navcmid' => $cmid,
                'navuserid' => $userid,
                'cmid' => $cmid,
                'submissionid' => $submissionid,
            ],
            IGNORE_MULTIPLE
        );

        return $page ?: null;
    }

    /**
     * Whether every gradeable item on a page has a recorded grade for a user.
     *
     * Pages without questions or a rubric have no grade requirement.
     *
     * @param \stdClass $page Page record.
     * @param int $userid Student id.
     * @return bool
     */
    public static function page_grade_is_complete(\stdClass $page, int $userid): bool {
        global $DB;

        if (!empty($page->userubric)) {
            $review = $DB->get_record('icontent_page_rubric_grades', [
                'pageid' => (int)$page->id,
                'cmid' => (int)$page->cmid,
                'userid' => $userid,
            ], 'status, grade');
            if (!$review || $review->status !== self::PAGE_RUBRIC_VALUED || $review->grade === null) {
                return false;
            }
        }

        $questions = $DB->get_records_sql(
            'SELECT pq.id, pq.minimumscore,
                    COALESCE(NULLIF(pq.maxmark, 0), q.defaultmark, 1) AS effectivemaxmark
               FROM {icontent_pages_questions} pq
          LEFT JOIN {question} q ON q.id = pq.questionid
              WHERE pq.pageid = ? AND pq.cmid = ?',
            [(int)$page->id, (int)$page->cmid]
        );
        foreach ($questions as $question) {
            $attempt = $DB->get_record_sql(
                'SELECT rightanswer, fraction, maxmark
                   FROM {icontent_question_attempts}
                  WHERE pagesquestionsid = :pagesquestionsid
                    AND userid = :userid
                    AND cmid = :cmid
               ORDER BY timecreated DESC, id DESC',
                [
                    'pagesquestionsid' => (int)$question->id,
                    'userid' => $userid,
                    'cmid' => (int)$page->cmid,
                ],
                IGNORE_MULTIPLE
            );
            if (!$attempt || $attempt->rightanswer === ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE) {
                return false;
            }
            if ((float)$attempt->maxmark > 0) {
                $question->effectivemaxmark = (float)$attempt->maxmark;
            }
            if ((int)$question->minimumscore > 0 && !self::question_meets_minimum_score($question, $attempt)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compare the latest graded net mark with a question's minimum percentage.
     *
     * @param \stdClass $question Mapping with minimumscore and effectivemaxmark.
     * @param \stdClass|null $attempt Latest attempt, or null if unanswered.
     * @return bool
     */
    public static function question_meets_minimum_score(\stdClass $question, ?\stdClass $attempt): bool {
        if (!$attempt || $attempt->rightanswer === ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE) {
            return false;
        }
        $maxmark = (float)$question->effectivemaxmark;
        return $maxmark > 0 &&
            (float)$attempt->fraction / $maxmark + 0.0000001 >= (int)$question->minimumscore / 100;
    }

    /**
     * Whether navigation from one page to another is allowed.
     *
     * Backward navigation remains available while a page is awaiting a grade,
     * except when a configured next or minimum-score route points backward.
     *
     * @param \stdClass $sourcepage Current page.
     * @param \stdClass $targetpage Requested page.
     * @param int $userid Student id.
     * @return bool
     */
    public static function can_navigate_to_page(\stdClass $sourcepage, \stdClass $targetpage, int $userid): bool {
        global $DB;

        if ((int)$targetpage->id === (int)$sourcepage->id) {
            return true;
        }

        $nextpagenum = (int)icontent_get_next_pagenum($sourcepage);
        if ((int)$targetpage->pagenum <= (int)$sourcepage->pagenum &&
                (int)$targetpage->pagenum !== $nextpagenum) {
            $isminimumscoreroute = $DB->record_exists_select(
                'icontent_pages_questions',
                'pageid = ? AND cmid = ? AND minimumscore > 0 AND minimumscorenextpageid = ?',
                [(int)$sourcepage->id, (int)$sourcepage->cmid, (int)$targetpage->id]
            );
            if (!$isminimumscoreroute) {
                return true;
            }
        }

        return self::page_grade_is_complete($sourcepage, $userid);
    }

    /**
     * Grading-instance item id for a page rubric review.
     *
     * @param int $reviewid Review record id.
     * @return int
     */
    public static function page_rubric_itemid(int $reviewid): int {
        return self::PAGE_RUBRIC_ITEM_OFFSET + $reviewid;
    }

    /**
     * Keep saved page reviews on the edited page's points scale without changing their percentage.
     *
     * @param \stdClass $page Saved page.
     * @param \stdClass $icontent Activity.
     */
    public static function sync_page_rubric_maxmark(\stdClass $page, \stdClass $icontent): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/icontent/locallib.php');
        $maximum = (float)$page->rubricmaxmark;
        if (!is_finite($maximum) || $maximum <= 0) {
            throw new \invalid_parameter_exception('The rubric page maximum must be a positive number');
        }
        $transaction = $DB->start_delegated_transaction();
        $reviews = $DB->get_records('icontent_page_rubric_grades', ['pageid' => $page->id, 'cmid' => $page->cmid]);
        $userids = [];
        foreach ($reviews as $review) {
            if ((float)$review->maxmark === $maximum) {
                continue;
            }
            if ((float)$review->maxmark <= 0) {
                throw new \coding_exception('A saved page rubric review has an invalid maximum');
            }
            if ($review->grade !== null) {
                $review->grade = ((float)$review->grade / (float)$review->maxmark) * $maximum;
            }
            $review->maxmark = $maximum;
            $DB->update_record('icontent_page_rubric_grades', $review);
            $userids[(int)$review->userid] = (int)$review->userid;
        }
        foreach ($userids as $userid) {
            \icontent_set_grade_item($icontent, (int)$page->cmid, $userid);
        }
        $transaction->allow_commit();
    }

    /**
     * Create a pending manual-review row when a student opens a rubric page.
     *
     * Teachers are not queued. An existing graded row is left unchanged.
     *
     * @param \stdClass $page Page record.
     * @param int $userid Student id.
     * @return \stdClass|null
     */
    public static function ensure_page_rubric_review(\stdClass $page, int $userid): ?\stdClass {
        global $DB;

        if (empty($page->userubric) || $userid <= 0) {
            return null;
        }
        $context = \context_module::instance((int)$page->cmid);
        if (has_capability('mod/icontent:grade', $context, $userid)) {
            return null;
        }
        $existing = $DB->get_record('icontent_page_rubric_grades', [
            'pageid' => (int)$page->id,
            'userid' => $userid,
        ]);
        if ($existing) {
            return $existing;
        }
        $now = time();
        $review = (object)[
            'pageid' => (int)$page->id,
            'cmid' => (int)$page->cmid,
            'userid' => $userid,
            'status' => self::PAGE_RUBRIC_TOEVALUATE,
            'maxmark' => (float)($page->rubricmaxmark ?? 1) > 0 ? (float)$page->rubricmaxmark : 1.0,
            'feedbackformat' => FORMAT_PLAIN,
            'grader' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $review->id = $DB->insert_record('icontent_page_rubric_grades', $review);
        return $review;
    }

    /**
     * Page rubric reviews for one student and status.
     *
     * @param int $cmid Course module id.
     * @param int $userid Student id.
     * @param string $status Review status.
     * @return array
     */
    public static function get_page_rubric_reviews(int $cmid, int $userid, string $status): array {
        global $DB;

        return $DB->get_records_sql(
            'SELECT pr.*, p.title, p.pageicontent, p.pageicontentformat, p.pagenum
               FROM {icontent_page_rubric_grades} pr
               JOIN {icontent_pages} p ON p.id = pr.pageid
              WHERE pr.cmid = :cmid
                AND pr.userid = :userid
                AND pr.status = :status
           ORDER BY p.pagenum, p.id',
            ['cmid' => $cmid, 'userid' => $userid, 'status' => $status]
        );
    }

    /**
     * Users with a page rubric review, including name fields required by user pictures.
     *
     * @param int $cmid Course module id.
     * @param string $status Review status.
     * @param int $groupid Group filter, or 0.
     * @return array
     */
    public static function get_page_rubric_users(int $cmid, string $status, int $groupid = 0): array {
        global $DB;

        $params = ['cmid' => $cmid, 'status' => $status];
        $groupsql = '';
        if ($groupid) {
            $groupsql = ' AND EXISTS (
                SELECT 1 FROM {groups_members} gm
                 WHERE gm.userid = pr.userid AND gm.groupid = :groupid
            )';
            $params['groupid'] = $groupid;
        }
        $counts = $DB->get_records_sql(
            "SELECT pr.userid,
                    COUNT(pr.id) AS totalopenanswers,
                    COALESCE(SUM(pr.grade), 0) AS sumfraction,
                    COALESCE(SUM(pr.maxmark), 0) AS maxfraction
               FROM {icontent_page_rubric_grades} pr
              WHERE pr.cmid = :cmid
                AND pr.status = :status
                    $groupsql
           GROUP BY pr.userid",
            $params
        );
        if (!$counts) {
            return [];
        }
        $userfields = \core_user\fields::for_userpic()->get_sql('u', false, '', 'id', false)->selects;
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($counts), SQL_PARAMS_NAMED);
        $users = $DB->get_records_sql("SELECT $userfields FROM {user} u WHERE u.id $insql", $inparams);
        $result = [];
        foreach ($users as $user) {
            $count = $counts[$user->id];
            $user->totalopenanswers = (int)$count->totalopenanswers;
            $user->sumfraction = (float)$count->sumfraction;
            $user->maxfraction = (float)$count->maxfraction;
            $result[(int)$user->id] = $user;
        }
        return $result;
    }

    /**
     * Add page-rubric users and scores to a Results user list.
     *
     * @param array $users Users already loaded from question attempts.
     * @param int $cmid Course module id.
     * @param string|null $status Limit rubric rows to this status, or null for every status.
     * @param int $groupid Group filter, or 0.
     * @return array
     */
    public static function merge_page_rubric_users(
        array $users,
        int $cmid,
        ?string $status,
        int $groupid = 0
    ): array {
        $statuses = $status === null
            ? [self::PAGE_RUBRIC_TOEVALUATE, self::PAGE_RUBRIC_VALUED]
            : [$status];
        foreach ($statuses as $reviewstatus) {
            foreach (self::get_page_rubric_users($cmid, $reviewstatus, $groupid) as $rubricuser) {
                $userid = (int)$rubricuser->id;
                if (!isset($users[$userid])) {
                    $rubricuser->totalanswers = 0;
                    $users[$userid] = $rubricuser;
                    continue;
                }
                $users[$userid]->totalopenanswers = (int)($users[$userid]->totalopenanswers ?? 0) +
                    (int)$rubricuser->totalopenanswers;
                $users[$userid]->sumfraction = (float)($users[$userid]->sumfraction ?? 0) +
                    (float)$rubricuser->sumfraction;
                $users[$userid]->maxfraction = (float)($users[$userid]->maxfraction ?? 0) +
                    (float)$rubricuser->maxfraction;
            }
        }
        return $users;
    }

    /**
     * Convert a completed page rubric into the page's contribution and store it.
     *
     * @param \context $context Module context.
     * @param \stdClass $icontent Activity record.
     * @param \stdClass $review Review record.
     * @param array $elementvalue Submitted rubric values.
     * @param int $graderid Grading user.
     * @param string $feedback Optional feedback.
     * @param int $instanceid Grading instance shown in the submitted form, or 0 to create one.
     * @return float Points contributed by this page.
     */
    public static function save_page_rubric_grade(
        \context $context,
        \stdClass $icontent,
        \stdClass $review,
        array $elementvalue,
        int $graderid,
        string $feedback = '',
        int $instanceid = 0
    ): float {
        global $DB;

        $instance = self::prepare_grading_instance(
            $context,
            $icontent,
            (object)['id' => self::page_rubric_itemid((int)$review->id)],
            $graderid,
            $instanceid
        );
        if (!$instance) {
            throw new \moodle_exception('rubricnotready', 'icontent');
        }
        $rawgrade = self::grade_from_rubric(
            $instance,
            $elementvalue,
            self::page_rubric_itemid((int)$review->id),
            (int)$icontent->grade < 0
        );
        $scale = make_grades_menu($icontent->grade);
        $scalemax = $scale ? (float)max(array_keys($scale)) : 0.0;
        $maxmark = (float)$review->maxmark > 0 ? (float)$review->maxmark : 1.0;
        $fraction = $scalemax > 0 ? ((float)$rawgrade / $scalemax) * $maxmark : 0.0;

        $review->status = self::PAGE_RUBRIC_VALUED;
        $review->grade = $fraction;
        $review->feedback = $feedback;
        $review->feedbackformat = FORMAT_PLAIN;
        $review->grader = $graderid;
        $review->timemodified = time();
        $DB->update_record('icontent_page_rubric_grades', $review);
        return $fraction;
    }

    /**
     * Render the built-in rubric for a page review, or its preview.
     *
     * @param \context $context Module context.
     * @param \stdClass $icontent Activity record.
     * @param int $reviewid Review id, or 0 for a preview.
     * @return string
     */
    public static function render_page_rubric(\context $context, \stdClass $icontent, int $reviewid = 0): string {
        return self::render_submission_rubric(
            $context,
            $icontent,
            $reviewid ? self::page_rubric_itemid($reviewid) : 0
        );
    }

    /**
     * URL for defining the activity's built-in rubric.
     *
     * @param \context $context Module context.
     * @param \moodle_url $returnurl URL to return to after editing.
     * @return \moodle_url
     */
    public static function get_rubric_editor_url(\context $context, \moodle_url $returnurl): \moodle_url {
        $manager = get_grading_manager($context, 'mod_icontent', self::GRADING_AREA);
        $controller = self::get_grading_controller($context);
        if ($controller) {
            return $controller->get_editor_url($returnurl);
        }
        return $manager->get_management_url($returnurl);
    }

    /**
     * Pages assigned the built-in rubric, with pending and graded review counts.
     *
     * @param int $cmid Course module id.
     * @return array
     */
    public static function get_rubric_pages(int $cmid): array {
        global $DB;

        return $DB->get_records_sql(
            "SELECT p.id, p.title, p.pagenum, p.rubricmaxmark,
                    SUM(CASE WHEN pr.status = :pending THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN pr.status = :graded THEN 1 ELSE 0 END) AS graded
               FROM {icontent_pages} p
          LEFT JOIN {icontent_page_rubric_grades} pr ON pr.pageid = p.id
              WHERE p.cmid = :cmid
                AND p.userubric = 1
           GROUP BY p.id, p.title, p.pagenum, p.rubricmaxmark
           ORDER BY p.pagenum, p.id",
            [
                'cmid' => $cmid,
                'pending' => self::PAGE_RUBRIC_TOEVALUATE,
                'graded' => self::PAGE_RUBRIC_VALUED,
            ]
        );
    }
}
