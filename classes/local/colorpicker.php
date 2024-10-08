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
 * The mod_icontent color picker.
 *
 * @package    mod_icontent
 * @copyright  2016 Leo Renis Santos <leorenis@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Color picker for iContent.
 *
 * @copyright 2010 by Sam Hemelryk.
 * @copyright 2024 AL Rachels
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class icontent_setting_configcolorpicker extends admin_setting {
// phpcs:ignore
// ...class admin_setting_configcolorpicker extends admin_setting {.

    /**
     * Information for previewing the color.
     *
     * @var array|null
     */
    protected $previewconfig = null;

    /**
     * Use default when empty.
     *
     * @var array|null
     */
    protected $usedefaultwhenempty = true;

    /**
     * Constructor.
     *
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param string $defaultsetting
     * @param array $previewconfig
     * @param int $usedefaultwhenempty
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $previewconfig = null,
            $usedefaultwhenempty = true) {
        $this->previewconfig = $previewconfig;
        $this->usedefaultwhenempty = $usedefaultwhenempty;
        parent::__construct($name, $visiblename, $description, $defaultsetting);
        $this->set_force_ltr(true);
    }

    /**
     * Return the setting.
     *
     * @return mixed returns config if successful else null.
     */
    public function get_setting() {
        return $this->config_read($this->name);
    }

    /**
     * Saves the setting.
     *
     * @param string $data
     * @return bool
     */
    public function write_setting($data) {
        $data = $this->validate($data);
        if ($data === false) {
            return  get_string('validateerror', 'admin');
        }
        return ($this->config_write($this->name, $data) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Validates the color that was entered by the user.
     * List of valid HTML color names.
     *
     * @var array
     * @param string $data
     * @return string|false
     */
    protected function validate($data) {
        $colornames = [
            'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure',
            'beige', 'bisque', 'black', 'blanchedalmond', 'blue',
            'blueviolet', 'brown', 'burlywood', 'cadetblue', 'chartreuse',
            'chocolate', 'coral', 'cornflowerblue', 'cornsilk', 'crimson',
            'cyan', 'darkblue', 'darkcyan', 'darkgoldenrod', 'darkgray',
            'darkgrey', 'darkgreen', 'darkkhaki', 'darkmagenta',
            'darkolivegreen', 'darkorange', 'darkorchid', 'darkred',
            'darksalmon', 'darkseagreen', 'darkslateblue', 'darkslategray',
            'darkslategrey', 'darkturquoise', 'darkviolet', 'deeppink',
            'deepskyblue', 'dimgray', 'dimgrey', 'dodgerblue', 'firebrick',
            'floralwhite', 'forestgreen', 'fuchsia', 'gainsboro',
            'ghostwhite', 'gold', 'goldenrod', 'gray', 'grey', 'green',
            'greenyellow', 'honeydew', 'hotpink', 'indianred', 'indigo',
            'ivory', 'khaki', 'lavender', 'lavenderblush', 'lawngreen',
            'lemonchiffon', 'lightblue', 'lightcoral', 'lightcyan',
            'lightgoldenrodyellow', 'lightgray', 'lightgrey', 'lightgreen',
            'lightpink', 'lightsalmon', 'lightseagreen', 'lightskyblue',
            'lightslategray', 'lightslategrey', 'lightsteelblue', 'lightyellow',
            'lime', 'limegreen', 'linen', 'magenta', 'maroon',
            'mediumaquamarine', 'mediumblue', 'mediumorchid', 'mediumpurple',
            'mediumseagreen', 'mediumslateblue', 'mediumspringgreen',
            'mediumturquoise', 'mediumvioletred', 'midnightblue', 'mintcream',
            'mistyrose', 'moccasin', 'navajowhite', 'navy', 'oldlace', 'olive',
            'olivedrab', 'orange', 'orangered', 'orchid', 'palegoldenrod',
            'palegreen', 'paleturquoise', 'palevioletred', 'papayawhip',
            'peachpuff', 'peru', 'pink', 'plum', 'powderblue', 'purple', 'red',
            'rosybrown', 'royalblue', 'saddlebrown', 'salmon', 'sandybrown',
            'seagreen', 'seashell', 'sienna', 'silver', 'skyblue', 'slateblue',
            'slategray', 'slategrey', 'snow', 'springgreen', 'steelblue', 'tan',
            'teal', 'thistle', 'tomato', 'turquoise', 'violet', 'wheat', 'white',
            'whitesmoke', 'yellow', 'yellowgreen',
        ];

        if (preg_match('/^#?([[:xdigit:]]{3}){1,2}$/', $data)) {
            if (strpos($data, '#') !== 0) {
                $data = '#'.$data;
            }
            return $data;
        } else if (in_array(strtolower($data), $colornames)) {
            return $data;
        } else if (preg_match('/rgb\(\d{0,3}%?\, ?\d{0,3}%?, ?\d{0,3}%?\)/i', $data)) {
            return $data;
        } else if (preg_match('/rgba\(\d{0,3}%?\, ?\d{0,3}%?, ?\d{0,3}%?\, ?\d(\.\d)?\)/i', $data)) {
            return $data;
        } else if (preg_match('/hsl\(\d{0,3}\, ?\d{0,3}%, ?\d{0,3}%\)/i', $data)) {
            return $data;
        } else if (preg_match('/hsla\(\d{0,3}\, ?\d{0,3}%,\d{0,3}%\, ?\d(\.\d)?\)/i', $data)) {
            return $data;
        } else if (($data == 'transparent') || ($data == 'currentColor') || ($data == 'inherit')) {
            return $data;
        } else if (empty($data)) {
            if ($this->usedefaultwhenempty) {
                return $this->defaultsetting;
            } else {
                return '';
            }
        } else {
            return false;
        }
    }

    /**
     * Generates the HTML for the setting.
     *
     * @param string $data
     * @param string $query
     */
    public function output_html($data, $query = '') {
        global $PAGE, $OUTPUT;

        $icon = new pix_icon('i/loading', get_string('loading', 'admin'), 'moodle', ['class' => 'loadingicon']);
        $context = (object) [
            'id' => $this->get_id(),
            'name' => $this->get_full_name(),
            'value' => $data,
            'icon' => $icon->export_for_template($OUTPUT),
            'haspreviewconfig' => !empty($this->previewconfig),
            'forceltr' => $this->get_force_ltr(),
        ];

        $element = $OUTPUT->render_from_template('core_admin/setting_configcolorpicker', $context);
        $PAGE->requires->js_init_call('M.util.init_color_picker', [$this->get_id(), $this->previewconfig]);

        return format_admin_setting($this, $this->visiblename, $element, $this->description, true, '',
            $this->get_defaultsetting(), $query);
    }
}
