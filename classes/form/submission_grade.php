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
 * Grade a page-set submission with Moodle's built-in rubric.
 *
 * @package mod_icontent
 * @copyright 2026 AL Rachels
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_grade extends \moodleform {
    /**
     * Define the rubric grade form.
     */
    public function definition() {
        $mform = $this->_form;
        $submission = $this->_customdata['submission'];
        $instance = $this->_customdata['gradinginstance'];

        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'submissionid', $this->_customdata['submissionid']);
        $mform->setType('submissionid', PARAM_INT);
        $mform->addElement('grading', 'advancedgrading', get_string('pluginname', 'gradingform_rubric'), [
            'gradinginstance' => $instance,
        ]);
        $mform->addElement('hidden', 'advancedgradinginstanceid', $instance->get_id());
        $mform->setType('advancedgradinginstanceid', PARAM_INT);

        if (!empty($this->_customdata['reviewparticipation'])) {
            $mform->addElement('header', 'participation', get_string('participationgrading', 'icontent'));
            $notice = $this->_customdata['contributions'] === null
                ? 'contributionlogunavailable' : 'contributionreviewnotice';
            $mform->addElement('static', 'participationnotice', '', get_string($notice, 'icontent'));
            foreach ($this->_customdata['users'] as $userid => $member) {
                $existing = $this->_customdata['existinggrades'][$userid]->rawgrade ?? null;
                $hasoverride = $submission->grade !== null && $existing !== null &&
                    grade_floats_different((float)$existing, (float)$submission->grade);
                $flagged = $submission->grade === null && $this->_customdata['contributions'] !== null &&
                    empty($this->_customdata['contributions'][$userid]);
                $changes = $this->_customdata['contributions'] === null
                    ? get_string('notavailable')
                    : (string)($this->_customdata['contributions'][$userid] ?? 0);
                $mform->addElement('static', 'changes_' . $userid, fullname($member), $changes);
                $mform->addElement(
                    'advcheckbox',
                    'individual_' . $userid,
                    get_string('useindividualgrade', 'icontent')
                );
                $mform->setType('individual_' . $userid, PARAM_BOOL);
                $mform->setDefault('individual_' . $userid, $hasoverride || $flagged ? 1 : 0);
                $mform->addElement('text', 'individualgrade_' . $userid, get_string('individualgrade', 'icontent'));
                $mform->setType('individualgrade_' . $userid, PARAM_RAW_TRIMMED);
                if ($hasoverride) {
                    $mform->setDefault('individualgrade_' . $userid, format_float((float)$existing, 5, false));
                }
            }
        }

        $mform->addElement('textarea', 'feedback', get_string('feedback', 'icontent'), 'wrap="virtual" rows="6" cols="50"');
        $mform->setType('feedback', PARAM_TEXT);
        $mform->setDefault('feedback', (string)$submission->feedback);
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Require a completed built-in rubric.
     *
     * @param array $data Submitted data.
     * @param array $files Uploaded files.
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $instance = $this->_customdata['gradinginstance'];
        $value = $data['advancedgrading'] ?? [];
        if (!is_array($value) || $instance->is_empty_form($value)) {
            $errors['advancedgrading'] = get_string('rubricincomplete', 'icontent');
        }
        return $errors;
    }
}
