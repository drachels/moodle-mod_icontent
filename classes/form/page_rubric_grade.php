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
 * Grade every rubric page of one participant from Manual review.
 *
 * All pages share one form so that saving does not discard what was entered for the others.
 * Untouched rubrics are skipped; started rubrics must be completed.
 *
 * @package mod_icontent
 * @copyright 2026 AL Rachels
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_rubric_grade extends \moodleform {
    /**
     * Rubric element name for a review.
     *
     * The rubric renderer and its JavaScript derive HTML ids from this name, so it must be unique per review.
     *
     * @param int $reviewid Review record id.
     * @return string
     */
    public static function grading_element_name(int $reviewid): string {
        return 'advancedgrading_' . $reviewid;
    }

    /**
     * Feedback element name for a review.
     *
     * @param int $reviewid Review record id.
     * @return string
     */
    public static function feedback_element_name(int $reviewid): string {
        return 'feedback_' . $reviewid;
    }

    /**
     * Grading instance id element name for a review.
     *
     * @param int $reviewid Review record id.
     * @return string
     */
    public static function instanceid_element_name(int $reviewid): string {
        return 'advancedgradinginstanceid_' . $reviewid;
    }

    /**
     * Define the rubric grade form.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'userid', $this->_customdata['userid']);
        $mform->setType('userid', PARAM_INT);
        $mform->addElement('hidden', 'status', $this->_customdata['status']);
        $mform->setType('status', PARAM_ALPHA);

        foreach ($this->_customdata['reviews'] as $reviewid => $review) {
            $elementname = self::grading_element_name($reviewid);
            $feedbackname = self::feedback_element_name($reviewid);
            $instanceidname = self::instanceid_element_name($reviewid);

            $mform->addElement('html', \html_writer::tag('h5', s($review['heading']), ['class' => 'mt-4']));
            $mform->addElement('html', \html_writer::div($review['content'], 'icontent-page-rubric-content mb-3'));
            $mform->addElement('grading', $elementname, get_string('pluginname', 'gradingform_rubric'), [
                'gradinginstance' => $review['gradinginstance'],
            ]);
            // The element's own rule rejects untouched rubrics; validation() checks only the started ones.
            unset($mform->_rules[$elementname]);
            $mform->addElement('hidden', $instanceidname, $review['gradinginstance']->get_id());
            $mform->setType($instanceidname, PARAM_INT);
            $mform->addElement('textarea', $feedbackname, get_string('feedback', 'icontent'),
                'wrap="virtual" rows="4" cols="50"');
            $mform->setType($feedbackname, PARAM_TEXT);
            $mform->setDefault($feedbackname, (string)($review['feedback'] ?? ''));
        }
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Ids of the reviews whose rubric was started in the submitted data.
     *
     * @param array|\stdClass $data Submitted data.
     * @return int[]
     */
    public function get_started_reviewids($data): array {
        $data = (array)$data;
        $started = [];
        foreach ($this->_customdata['reviews'] as $reviewid => $review) {
            $value = $data[self::grading_element_name($reviewid)] ?? [];
            $feedback = trim((string)($data[self::feedback_element_name($reviewid)] ?? ''));
            $feedbackchanged = $feedback !== trim((string)($review['feedback'] ?? ''));
            if ($feedbackchanged || (is_array($value) && !$review['gradinginstance']->is_empty_form($value))) {
                $started[] = (int)$reviewid;
            }
        }
        return $started;
    }

    /**
     * Ids of the started reviews whose rubric has a level selected for every criterion.
     *
     * @param array|\stdClass $data Submitted data.
     * @return int[]
     */
    public function get_complete_reviewids($data): array {
        $data = (array)$data;
        $complete = [];
        foreach ($this->get_started_reviewids($data) as $reviewid) {
            $instance = $this->_customdata['reviews'][$reviewid]['gradinginstance'];
            $value = $data[self::grading_element_name($reviewid)] ?? [];
            if (is_array($value) && $instance->validate_grading_element($value)) {
                $complete[] = $reviewid;
            }
        }
        return $complete;
    }

    /**
     * Require every started rubric to be complete, and at least one rubric overall.
     *
     * @param array $data Submitted data.
     * @param array $files Uploaded files.
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $started = $this->get_started_reviewids($data);
        foreach ($started as $reviewid) {
            $instance = $this->_customdata['reviews'][$reviewid]['gradinginstance'];
            if (!$instance->validate_grading_element((array)($data[self::grading_element_name($reviewid)] ?? []))) {
                $errors[self::grading_element_name($reviewid)] = get_string('rubricincomplete', 'icontent');
            }
        }
        if (!$started && $this->_customdata['reviews']) {
            $first = array_key_first($this->_customdata['reviews']);
            $errors[self::grading_element_name($first)] = get_string('rubricincomplete', 'icontent');
        }
        return $errors;
    }
}
