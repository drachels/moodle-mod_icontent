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
 * Move icontent page.
 *
 * @package    mod_icontent
 * @copyright  2015-2016 Leo Santos {@link http://github.com/leorenis}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$pageid = required_param('pageid', PARAM_INT);
$up = optional_param('up', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('icontent', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$icontent = $DB->get_record('icontent', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
require_sesskey();

$context = context_module::instance($cm->id);
$page = icontent_move_page($pageid, (bool)$up, $icontent, $cm, $context);

redirect(new moodle_url('/mod/icontent/view.php', [
    'id' => $cm->id,
    'pageid' => $page->id,
    'submissionid' => (int)$page->submissionid,
]));
