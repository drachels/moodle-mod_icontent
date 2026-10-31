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
 * Teacher controls for reopening submissions and extending deadlines.
 *
 * @package mod_icontent
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_management extends \moodleform {
    /**
     * Define the form.
     */
    public function definition() {
        $mform = $this->_form;
        foreach (['id', 'submissionid'] as $name) {
            $mform->addElement('hidden', $name);
            $mform->setType($name, PARAM_INT);
        }
        $mform->addElement('static', 'owner', get_string('fullname'), $this->_customdata['owner']);
        $mform->addElement('static', 'currentdeadline', get_string('duedate', 'icontent'),
            $this->_customdata['duedate'] ? userdate($this->_customdata['duedate']) : get_string('none'));
        if ($this->_customdata['duedate']) {
            $mform->addElement('date_time_selector', 'extensionduedate', get_string('extensionduedate', 'icontent'),
                ['optional' => true]);
            $mform->addHelpButton('extensionduedate', 'extensionduedate', 'icontent');
        } else {
            $mform->addElement('hidden', 'extensionduedate', 0);
            $mform->setType('extensionduedate', PARAM_INT);
        }
        if ($this->_customdata['submission']->status !== \mod_icontent\local\assignment::STATUS_DRAFT) {
            $mform->addElement('advcheckbox', 'reopen', get_string('reopensubmission', 'icontent'));
            $mform->addHelpButton('reopen', 'reopensubmission', 'icontent');
            $mform->setType('reopen', PARAM_BOOL);
        }
        $this->add_action_buttons();
    }

    /**
     * Validate an extension against the activity deadline.
     *
     * @param array $data Form data.
     * @param array $files Uploaded files.
     * @return array Validation errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['extensionduedate']) && $data['extensionduedate'] <= $this->_customdata['duedate']) {
            $errors['extensionduedate'] = get_string('extensionafterduedate', 'icontent');
        }
        return $errors;
    }
}
