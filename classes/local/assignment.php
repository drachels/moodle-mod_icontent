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
