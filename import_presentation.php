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
 * Import presentation slides into editable iContent pages.
 *
 * @package mod_icontent
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_icontent\local\presentation_importer;

require_once(__DIR__ . '/../../config.php');
$id = required_param('id', PARAM_INT);
$submissionid = optional_param('submissionid', 0, PARAM_INT);
$cm = get_coursemodule_from_id('icontent', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$icontent = $DB->get_record('icontent', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
presentation_importer::require_access($icontent, $cm, $context, $submissionid);
if (!presentation_importer::is_available()) {
    throw new moodle_exception('presentationtoolsunavailable', 'icontent');
}
$PAGE->set_url('/mod/icontent/import_presentation.php', ['id' => $cm->id, 'submissionid' => $submissionid]);
$PAGE->set_title($icontent->name);
$PAGE->set_heading($course->fullname);
$returnurl = new moodle_url('/mod/icontent/view.php', ['id' => $cm->id, 'submissionid' => $submissionid]);
$form = new \mod_icontent\form\presentation_import(null, [
    'id' => $cm->id, 'submissionid' => $submissionid,
    'maxbytes' => get_user_max_upload_file_size(
        $context, $CFG->maxbytes, $course->maxbytes, presentation_importer::MAX_FILE_SIZE
    ),
]);
if ($form->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $form->get_data()) {
    require_sesskey();
    $draftcontext = context_user::instance($USER->id);
    $files = get_file_storage()->get_area_files(
        $draftcontext->id, 'user', 'draft', (int)$data->presentationfile, 'id', false
    );
    if (count($files) !== 1) {
        throw new moodle_exception('presentationinvalid', 'icontent');
    }
    \core_php_time_limit::raise(180);
    $result = presentation_importer::import(reset($files), $icontent, $cm, $context, $submissionid);
    $returnurl->param('pageid', $result['firstid']);
    redirect(
        $returnurl,
        get_string('presentationimported', 'icontent', $result['count']) .
            ($result['warnings'] ? ' ' . get_string('presentationimportwarnings', 'icontent', $result['warnings']) : ''),
        null,
        $result['warnings'] ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
    );
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('importpresentation', 'icontent'));
$form->display();
echo $OUTPUT->footer();
