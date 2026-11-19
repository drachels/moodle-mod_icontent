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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_icontent\local;

/**
 * Native app note data and mutations using the browser's note visibility rules.
 *
 * @package mod_icontent
 * @copyright 2026 AL Rachels
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile_notes {
    /**
     * Return only notes the current user may see, with permitted actions.
     *
     * @param \stdClass $page Page.
     * @param \context_module $context Context.
     * @return array
     */
    public static function read(\stdClass $page, \context_module $context): array {
        global $DB, $USER;
        $result = [];
        foreach (['note', 'doubt'] as $tab) {
            foreach (\icontent_get_pagenotes($page->id, $page->cmid, $tab) as $note) {
                $note->userid = (int)$note->userid;
                $author = $DB->get_record('user', ['id' => $note->userid], '*', MUST_EXIST);
                $result[] = [
                    'id' => (int)$note->id, 'tab' => $tab, 'parent' => (int)$note->parent,
                    'author' => fullname($author), 'date' => userdate($note->timemodified),
                    'text' => format_text($note->comment, FORMAT_HTML, ['context' => $context, 'noclean' => false]),
                    'edittext' => html_entity_decode(strip_tags($note->comment), ENT_QUOTES, 'UTF-8'),
                    'private' => (bool)$note->private, 'featured' => (bool)$note->featured,
                    'tutoronly' => (bool)$note->doubttutor,
                    'canedit' => \icontent_user_can_edit_note($note, $context),
                    'canreply' => \icontent_user_can_reply_note($note, $context),
                    'canremove' => \icontent_user_can_remove_note($note, $context),
                    'canlike' => \icontent_user_can_likeunlike_note($note, $context),
                    'likes' => \icontent_count_pagenotelike($note->id),
                    'liked' => (bool)\icontent_get_pagenotelike($note->id, $USER->id, $page->cmid),
                ];
            }
        }
        return $result;
    }

    /**
     * Perform a scoped note action; caller has checked page and activity access.
     *
     * @param string $action Action.
     * @param array $payload Native form data.
     * @param \stdClass $page Page.
     * @param \stdClass $icontent Activity.
     * @param \context_module $context Context.
     * @return void
     */
    public static function mutate(
        string $action,
        array $payload,
        \stdClass $page,
        \stdClass $icontent,
        \context_module $context
    ): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/icontent/lib.php');
        if (!$icontent->shownotesarea) {
            throw new \required_capability_exception($context, 'mod/icontent:viewnotes', 'nopermissions', '');
        }
        require_capability('mod/icontent:viewnotes', $context);
        $noteid = (int)($payload['noteid'] ?? 0);
        $target = null;
        if ($action !== 'addnote') {
            $visible = self::read($page, $context);
            if (!in_array($noteid, array_column($visible, 'id'), true)) {
                throw new \required_capability_exception($context, 'mod/icontent:viewnotes', 'nopermissions', '');
            }
            $target = $DB->get_record('icontent_pages_notes', [
                'id' => $noteid, 'pageid' => $page->id, 'cmid' => $page->cmid,
            ], '*', MUST_EXIST);
            $target->userid = (int)$target->userid;
        }
        $permissions = [
            'editnote' => 'icontent_user_can_edit_note', 'replynote' => 'icontent_user_can_reply_note',
            'deletenote' => 'icontent_user_can_remove_note', 'likenote' => 'icontent_user_can_likeunlike_note',
        ];
        if ($target && (!$permissions[$action]($target, $context))) {
            throw new \required_capability_exception($context, 'mod/icontent:viewnotes', 'nopermissions', '');
        }
        if ($action === 'deletenote') {
            \mod_icontent\notes\icontent_note_options::icontent_remove_notes($page->id, $noteid);
            \mod_icontent\event\note_deleted::create_from_note($icontent, $context, $target)->trigger();
            return;
        }
        if ($action === 'likenote') {
            if (!\icontent_ajax_likenote((object)['pagenoteid' => $noteid, 'cmid' => $page->cmid, 'visible' => 1], $icontent)) {
                throw new \moodle_exception('error');
            }
            return;
        }
        $comment = trim((string)($payload['comment'] ?? ''));
        if ($comment === '' || \core_text::strlen($comment) > 1024) {
            throw new \invalid_parameter_exception('Notes must contain between 1 and 1024 characters.');
        }
        $note = (object)['cmid' => $page->cmid, 'pageid' => $page->id, 'comment' => nl2br(s($comment))];
        if ($action === 'addnote') {
            $tab = $payload['tab'] ?? 'note';
            if (!in_array($tab, ['note', 'doubt'], true)) {
                throw new \invalid_parameter_exception('Invalid notes tab.');
            }
            $note->tab = $tab;
            foreach (['private' => 'checkboxprivatenotes', 'featured' => 'checkboxfeaturednotes',
                    'doubttutor' => 'checkboxdoubttutornotes'] as $field => $capability) {
                $note->$field = empty($payload[$field]) ? 0 : 1;
                if ($note->$field) {
                    require_capability('mod/icontent:' . $capability, $context);
                }
            }
            $result = \icontent_ajax_savereturnnotes($page->id, $note, $icontent);
        } else if ($action === 'editnote') {
            $target->comment = $note->comment;
            $result = \icontent_ajax_editnote($target, $icontent);
        } else {
            $note->parent = $noteid;
            $result = \icontent_ajax_replynote($note, $icontent);
        }
        if (!$result) {
            throw new \moodle_exception('error');
        }
    }
}
