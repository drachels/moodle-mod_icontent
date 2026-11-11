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
 * Grade item mappings for Content Pages.
 *
 * @package   mod_icontent
 * @copyright 2026 AL Rachels <drachels@drachels.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_icontent\grades;

use core_grades\local\gradeitem\advancedgrading_mapping;
use core_grades\local\gradeitem\itemnumber_mapping;

/**
 * Map the activity grade item to Moodle's built-in rubric area.
 *
 * @package   mod_icontent
 * @copyright 2026 AL Rachels <drachels@drachels.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradeitems implements itemnumber_mapping, advancedgrading_mapping {
    /**
     * Return the grade item used for a submitted page set.
     *
     * @return array
     */
    public static function get_itemname_mapping_for_component(): array {
        return [
            0 => 'submission',
        ];
    }

    /**
     * Return the advanced grading area names.
     *
     * Only the built-in rubric uses this area. Question grades are not included.
     *
     * @return array
     */
    public static function get_advancedgrading_itemnames(): array {
        return [
            'submission',
        ];
    }
}
