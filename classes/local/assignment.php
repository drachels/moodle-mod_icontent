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

defined('MOODLE_INTERNAL') || die();

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
    public static function get_current_submission(\stdClass $icontent, \stdClass $cm, int $userid, bool $create = true): ?\stdClass {
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
}
