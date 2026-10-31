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

namespace mod_icontent\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/**
 * Upload a presentation for append-only editable import.
 *
 * @package mod_icontent
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class presentation_import extends \moodleform {
    /**
     * Define upload and acknowledgement controls.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('static', 'instructions', '', get_string('presentationimportnotice', 'icontent'));
        foreach (['id', 'submissionid'] as $name) {
            $mform->addElement('hidden', $name, $this->_customdata[$name]);
            $mform->setType($name, PARAM_INT);
        }
        $mform->addElement('filepicker', 'presentationfile', get_string('presentationfile', 'icontent'), null, [
            'accepted_types' => ['.pptx', '.ppt', '.odp', '.sxi'],
            'maxbytes' => $this->_customdata['maxbytes'],
        ]);
        $mform->addRule('presentationfile', null, 'required', null, 'client');
        $this->add_action_buttons(true, get_string('importpresentation', 'icontent'));
    }
}
