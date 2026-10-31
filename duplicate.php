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
 * Duplicate icontent page.
 *
 * @package    mod_icontent
 * @copyright  2026 AL Rachels
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_icontent\local\icontent_info;
use mod_icontent\local\assignment;

require(dirname(__FILE__) . '/../../config.php');
require_once(dirname(__FILE__) . '/locallib.php');

global $DB;

$id = required_param('id', PARAM_INT); // Course Module ID.
$pageid = required_param('pageid', PARAM_INT); // Source page ID.
$requestedsubmissionid = optional_param('submissionid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('icontent', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$icontent = $DB->get_record('icontent', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
require_sesskey();

$context = context_module::instance($cm->id);
$sourcepage = $DB->get_record('icontent_pages', [
    'id' => $pageid,
    'icontentid' => $icontent->id,
    'cmid' => $cm->id,
], '*', MUST_EXIST);
if (!empty($icontent->assignmentmode) && !empty($sourcepage->submissionid)) {
    $submission = assignment::get_submission((int)$icontent->id, (int)$sourcepage->submissionid);
    if (
        !$submission ||
        ($requestedsubmissionid && $requestedsubmissionid !== (int)$submission->id) ||
        !assignment::can_edit_submission($submission, $context, (int)$USER->id)
    ) {
        throw new required_capability_exception($context, 'mod/icontent:submit', 'nopermissions', '');
    }
} else {
    require_capability('mod/icontent:edit', $context);
    if (
        $requestedsubmissionid !== (int)$sourcepage->submissionid ||
        !assignment::can_view_page($sourcepage, $icontent, $cm, $context, (int)$USER->id)
    ) {
        throw new required_capability_exception($context, 'mod/icontent:view', 'nopermissions', '');
    }
}
$createdpage = icontent_duplicate_page((int)$icontent->id, (int)$cm->id, (int)$pageid, $context);

icontent_info::icontent_preload_pages($icontent, (int)$createdpage->submissionid);
\mod_icontent\event\page_created::create_from_page($icontent, $context, $createdpage)->trigger();

$url = new moodle_url('/mod/icontent/view.php', [
    'id' => $cm->id,
    'pageid' => (int)$createdpage->id,
    'submissionid' => (int)$createdpage->submissionid,
]);
redirect($url, get_string('msgsucessduplicate', 'mod_icontent'));
