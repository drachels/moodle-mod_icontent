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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die;

/**
 * Compatibility wrapper for callers using the former page-edit form class.
 *
 * @package mod_icontent
 * @copyright 2015-2016 Leo Santos {@link http://github.com/leorenis}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @deprecated since iContent 4.0.0 Use \mod_icontent\form\page_edit instead.
 */
class icontent_pages_edit_form extends \mod_icontent\form\page_edit {
}
