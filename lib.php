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
 * Library of interface functions and constants for module icontent.
 *
 * All the core Moodle functions, neeeded to allow the module to work
 * integrated in Moodle should be placed here.
 *
 * All the icontent specific functions, needed to implement all the module
 * logic, should go to locallib.php. This will help to save some memory when
 * Moodle is performing actions across all modules.
 *
 * @package    mod_icontent
 * @copyright  2016 Leo Renis Santos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die(); // @codingStandardsIgnoreLine
use mod_icontent\local\icontent_info;

/**
 * Constant
 */
define('ICONTENT_ULTIMATE_ANSWER', 42);

/* Moodle core API */

/**
 * Returns the information on whether the module supports a feature
 *
 * See {@link https://yourmoodle/lib/moodlelib.php->plugin_supports()} for more info.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed true if the feature is supported, null if unknown
 */
function icontent_supports($feature) {
    global $CFG;
    if ((int)$CFG->branch > 311) {
        if ($feature === FEATURE_MOD_PURPOSE) {
            return MOD_PURPOSE_COLLABORATION;
        }
    }
    switch($feature) {
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return true;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_GROUPMEMBERSONLY:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_COLLABORATION;

        default:
            return null;
    }
}

/**
 * Saves a new instance of the icontent into the database.
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param stdClass $icontent Submitted data from the form in mod_form.php
 * @param mod_icontent_mod_form $mform The form instance itself (if needed)
 * @return int The id of the newly inserted icontent record
 */
function icontent_add_instance($icontent) {
    global $DB;

    $icontent->timecreated = time();

    // 20240828 Added timemodified entry.
    $icontent->timemodified = time();
    $icontent->id = $DB->insert_record('icontent', $icontent);

    // 20240828 Added calendar dates.
    icontent_info::icontent_update_calendar($icontent, $icontent->coursemodule);

    // 20240828 Added expected completion date.
    if (! empty($icontent->completionexpected)) {
        \core_completion\api::update_completion_date_event($icontent->coursemodule,
            'icontent',
            $icontent->id,
            $icontent->completionexpected
        );
    }

    icontent_grade_item_update($icontent);

    return $icontent->id;
}

/**
 * Updates an instance of the icontent in the database.
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param stdClass $icontent An object from the form in mod_form.php
 * @param mod_icontent_mod_form $mform The form instance itself (if needed)
 * @return boolean Success/Fail
 */
function icontent_update_instance($icontent) {
    global $DB;

    $icontent->timemodified = time();
    $icontent->id = $icontent->instance;

    // You may have to add extra stuff in here.

    $DB->update_record('icontent', $icontent);

    // 20240828 Added calendar dates.
    icontent_info::icontent_update_calendar($icontent, $icontent->coursemodule);

    // 20200901 Added expected completion date.
    $completionexpected = (! empty($icontent->completionexpected)) ? $icontent->completionexpected : null;
    \core_completion\api::update_completion_date_event($icontent->coursemodule, 'icontent', $icontent->id, $completionexpected);

    icontent_grade_item_update($icontent);

    return true;
}

/**
 * Removes an instance of the icontent from the database.
 *
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 */
function icontent_delete_instance($id) {
    global $DB;
    // Check if instance exists.
    if (! $icontent = $DB->get_record('icontent', ['id' => $id])) {
        return false;
    }
    if (!$cm = get_coursemodule_from_instance('icontent', $icontent->id)) {
        return false;
    }
    // Delete any dependent records here.
    $DB->delete_records('icontent_pages_notes_like', ['cmid' => $cm->id]);
    $DB->delete_records('icontent_pages_notes', ['cmid' => $cm->id]);
    $DB->delete_records('icontent_pages_questions', ['cmid' => $cm->id]);
    $DB->delete_records('icontent_question_attempts', ['cmid' => $cm->id]);
    $DB->delete_records('icontent_pages_displayed', ['cmid' => $cm->id]);
    $DB->delete_records('icontent_pages', ['icontentid' => $icontent->id]);
    $DB->delete_records('icontent_grades', ['cmid' => $cm->id]);
    $DB->delete_records('icontent', ['id' => $icontent->id]);
    // Delete grades.
    icontent_grade_item_delete($icontent);
    // Delete files.
    $context = context_module::instance($cm->id);
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_icontent');
    // Return.
    return true;
}

/**
 * Returns a small object with summary information about what a
 * user has done with a given particular instance of this module.
 * Used for user activity reports.
 *
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param stdClass $course The course record
 * @param stdClass $user The user record
 * @param cm_info|stdClass $mod The course module info object or record
 * @param stdClass $icontent The icontent instance record
 * @return stdClass|null
 */
function icontent_user_outline($course, $user, $mod, $icontent) {

    global $CFG;

    require_once("$CFG->libdir/gradelib.php");
    $grades = grade_get_grades($course->id, 'mod', 'icontent', $icontent->id, $user->id);

    $return = new stdClass();
    if (empty($grades->items[0]->grades)) {
        $return->info = get_string("no")." ".get_string("attempts", "icontent");
    } else {
        $grade = reset($grades->items[0]->grades);
        $return->info = get_string("grade") . ': ' . $grade->str_long_grade;

        // The datesubmitted == time created. The dategraded == time modified or time overridden.
        // If grade was last modified by the user themselves use date graded. Otherwise use date submitted.
        // TODO: Move this copied & pasted code somewhere in the grades API. See MDL-26704.
        if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
            $return->time = $grade->dategraded;
        } else {
            $return->time = $grade->datesubmitted;
        }
    }
    return $return;
}

/**
 * Prints a detailed representation of what a user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * It is supposed to echo directly without returning a value.
 *
 * @param stdClass $course the current course record
 * @param stdClass $user the record of the user we are generating report for
 * @param cm_info $mod course module info
 * @param stdClass $icontent the module instance record
 */
function icontent_user_complete($course, $user, $mod, $icontent) {
}

/**
 * Given a course and a time, this module should find recent activity
 * that has occurred in icontent activities and print it out.
 *
 * @param stdClass $course The course record
 * @param bool $viewfullnames Should we display full names
 * @param int $timestart Print activity since this timestamp
 * @return boolean True if anything was printed, otherwise false
 */
function icontent_print_recent_activity($course, $viewfullnames, $timestart) {
    global $CFG, $USER, $DB, $OUTPUT;

    if (! get_config('icontent', 'showrecentactivity')) {
        return false;
    }

    $dbparams = [
        $timestart,
        $course->id,
        'icontent',
    ];

    $userfieldsapi = \core_user\fields::for_userpic();
    $namefields = $userfieldsapi->get_sql('u', false, '', 'userid', false)->selects;;
    // Need to adapt the following to apply to icontent notes and questions submitted.
    /*
    if (!$submissions = $DB->get_records_sql("SELECT asb.id, asb.timemodified, cm.id AS cmid, um.id as recordid,
                                                     $namefields
                                                FROM {assign_submission} asb
                                                     JOIN {assign} a      ON a.id = asb.assignment
                                                     JOIN {course_modules} cm ON cm.instance = a.id
                                                     JOIN {modules} md        ON md.id = cm.module
                                                     JOIN {user} u            ON u.id = asb.userid
                                                LEFT JOIN {assign_user_mapping} um ON um.userid = u.id AND um.assignment = a.id
                                               WHERE asb.timemodified > ? AND
                                                     asb.latest = 1 AND
                                                     a.course = ? AND
                                                     md.name = ? AND
                                                     asb.status = ?
                                            ORDER BY asb.timemodified ASC", $dbparams)) {
         return false;
    }
    */
    return false;
    // return true;

    // Might actually be easier to adapt the code for wiki.
    // Will need sql for notes/questions added to this.
    // Will probably need sql for notes_like also.
    // Will possibly need sql for question_attempts shown to teachers if it is an essay question attempt by a student.
    /*
    function wiki_print_recent_activity($course, $viewfullnames, $timestart) {
        global $CFG, $DB, $OUTPUT;

        $sql = "SELECT p.id, p.timemodified, p.subwikiid, sw.wikiid, w.wikimode, sw.userid, sw.groupid
                FROM {wiki_pages} p
                    JOIN {wiki_subwikis} sw ON sw.id = p.subwikiid
                    JOIN {wiki} w ON w.id = sw.wikiid
                WHERE p.timemodified > ? AND w.course = ?
                ORDER BY p.timemodified ASC";
        if (!$pages = $DB->get_records_sql($sql, array($timestart, $course->id))) {
            return false;
        }
        require_once($CFG->dirroot . "/mod/wiki/locallib.php");

        $wikis = array();

        $modinfo = get_fast_modinfo($course);

        $subwikivisible = array();
        foreach ($pages as $page) {
            if (!isset($subwikivisible[$page->subwikiid])) {
                $subwiki = (object)array('id' => $page->subwikiid, 'wikiid' => $page->wikiid,
                    'groupid' => $page->groupid, 'userid' => $page->userid);
                $wiki = (object)array('id' => $page->wikiid, 'course' => $course->id, 'wikimode' => $page->wikimode);
                $subwikivisible[$page->subwikiid] = wiki_user_can_view($subwiki, $wiki);
            }
            if ($subwikivisible[$page->subwikiid]) {
                $wikis[] = $page;
            }
        }
        unset($subwikivisible);
        unset($pages);

        if (!$wikis) {
            return false;
        }
        echo $OUTPUT->heading(get_string("updatedwikipages", 'wiki') . ':', 6);
        foreach ($wikis as $wiki) {
            $cm = $modinfo->instances['wiki'][$wiki->wikiid];
            $link = $CFG->wwwroot . '/mod/wiki/view.php?pageid=' . $wiki->id;
            print_recent_activity_note($wiki->timemodified, $wiki, $cm->name, $link, false, $viewfullnames);
        }

        return true; //  True if anything was printed, otherwise false
    }
    */
}

/**
 * Prepares the recent activity data.
 *
 * This callback function is supposed to populate the passed array with
 * custom activity records. These records are then rendered into HTML via
 * {@link https://yourmoodle/mod/icontent/lib.php->icontent_print_recent_mod_activity()}.
 *
 * Returns void, it adds items into $activities and increases $index.
 *
 * @param array $activities sequentially indexed array of objects with added 'cmid' property
 * @param int $index the index in the $activities to use for the next record
 * @param int $timestart append activity since this time
 * @param int $courseid the id of the course we produce the report for
 * @param int $cmid course module id
 * @param int $userid check for a particular user's activity only, defaults to 0 (all users)
 * @param int $groupid check for a particular group's activity only, defaults to 0 (all groups)
 */
function icontent_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0) {
}

/**
 * Prints single activity item prepared by
 * {@link https://yourmoodle/mod/icontent/lib.php->icontent_get_recent_mod_activity()}.
 *
 * @param stdClass $activity Activity record with added 'cmid' property.
 * @param int $courseid The id of the course we produce the report for.
 * @param bool $detail Print detailed report.
 * @param array $modnames as returned by
 * {@link https://yourmoodle/course/lib.php->get_module_types_names()}
 * @param bool $viewfullnames display users' full names
 */
function icontent_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
}

/**
 * Function to be run periodically according to the moodle cron.
 *
 * This function searches for things that need to be done, such
 * as sending out mail, toggling flags etc ...
 *
 * Note that this has been deprecated in favour of scheduled task API.
 *
 * @return boolean
 */
function icontent_cron () {
    return true;
}

/**
 * Returns all other caps used in the module.
 *
 * For example, this could be array('moodle/site:accessallgroups') if the
 * module uses that capability.
 *
 * @return array
 */
function icontent_get_extra_capabilities() {
    return [];
}

/* Gradebook API */

/**
 * Is a given scale used by the instance of icontent?
 *
 * This function returns if a scale is being used by one icontent
 * if it has support for grading and scales.
 *
 * @param int $icontentid ID of an instance of this module
 * @param int $scaleid ID of the scale
 * @return bool true if the scale is used by the given icontent instance
 */
function icontent_scale_used($icontentid, $scaleid) {
    global $DB;

    if ($scaleid && $DB->record_exists('icontent', ['id' => $icontentid, 'grade' => -$scaleid])) {
        return true;
    } else {
        return false;
    }
}

/**
 * Checks if scale is being used by any instance of icontent.
 *
 * This is used to find out if scale used anywhere.
 *
 * @param int $scaleid ID of the scale
 * @return boolean true if the scale is used by any icontent instance
 */
function icontent_scale_used_anywhere($scaleid) {
    global $DB;

    if ($scaleid && $DB->record_exists('icontent', ['grade' => -$scaleid])) {
        return true;
    } else {
        return false;
    }
}

/**
 * Creates or updates grade item for the given icontent instance.
 *
 * Needed by {@link https://yourmoodle/lib/grade_lib.php->grade_update()}.
 *
 * @param stdClass $icontent instance object with extra cmidnumber and modname property
 * @param bool $reset reset grades in the gradebook
 * @return void
 */
function icontent_grade_item_update(stdClass $icontent, $reset=false) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    $item = [];
    $item['itemname'] = clean_param($icontent->name, PARAM_NOTAGS);
    $item['gradetype'] = GRADE_TYPE_VALUE;
    if ($icontent->grade > 0) {
        $item['gradetype'] = GRADE_TYPE_VALUE;
        $item['grademax']  = $icontent->grade;
        $item['grademin']  = 0;
    } else if ($icontent->grade < 0) {
        $item['gradetype'] = GRADE_TYPE_SCALE;
        $item['scaleid']   = -$icontent->grade;
    } else {
        $item['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($reset) {
        $item['reset'] = true;
    }

    grade_update('mod/icontent', $icontent->course, 'mod', 'icontent',
            $icontent->id, 0, null, $item);
}

/**
 * Delete grade item for given icontent instance.
 *
 * @param stdClass $icontent Instance object.
 * @return grade_item
 */
function icontent_grade_item_delete($icontent) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/icontent', $icontent->course, 'mod', 'icontent',
            $icontent->id, 0, null, ['deleted' => 1]);
}

/**
 * Update icontent grades in the gradebook.
 *
 * Needed by {@link https://yourmoodle/lib/grade_lib.php->grade_update()}.
 *
 * @param stdClass $icontent instance object with extra cmidnumber and modname property
 * @param int $userid update grade of specific user only, 0 means all participants
 */
function icontent_update_grades(stdClass $icontent, $userid = 0) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    // Populate array of grade objects indexed by userid.
    $grades = [];

    grade_update('mod/icontent', $icontent->course, 'mod', 'icontent', $icontent->id, 0, $grades);
}

/* File API */

/**
 * Returns the lists of all browsable file areas within the given module context.
 *
 * The file area 'intro' for the activity introduction field is added automatically
 * by {@link https://yourmoodle/lib/filelib.php->file_browser::get_file_info()}
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return array of [(string)filearea] => (string)description
 */
function icontent_get_file_areas($course, $cm, $context) {
    $areas['page'] = get_string('page', 'mod_icontent');
    return $areas;
}

/**
 * File browsing support for icontent file areas.
 *
 * @package mod_icontent
 * @category files
 *
 * @param file_browser $browser
 * @param array $areas
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @param string $filearea
 * @param int $itemid
 * @param string $filepath
 * @param string $filename
 * @return file_info instance or null if not found
 */
function icontent_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {

    return null;
}

/**
 * Serves the files from the icontent file areas.
 *
 * @package mod_icontent
 * @category files
 *
 * @param stdClass $course the course object
 * @param stdClass $cm the course module object
 * @param stdClass $context the icontent's context
 * @param string $filearea the name of the file area
 * @param array $args extra arguments (itemid, path)
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 */
function icontent_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=[]) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    if ($filearea === 'qtypebgimage') {
        $questionid = (int)array_shift($args);
        $qtype = clean_param((string)array_shift($args), PARAM_ALPHANUMEXT);
        $filename = clean_param((string)array_shift($args), PARAM_FILE);

        if (empty($questionid) || empty($qtype) || empty($filename)) {
            return false;
        }

        $question = $DB->get_record('question', ['id' => $questionid], 'id, qtype');
        if (!$question || (string)$question->qtype !== $qtype) {
            return false;
        }

        $fs = get_file_storage();
        $filerecord = $DB->get_record_sql(
            "SELECT id, contextid, itemid, filepath, filename
               FROM {files}
              WHERE component = ?
                AND filearea = ?
                AND itemid = ?
                AND filename = ?
                AND filesize > 0
           ORDER BY id DESC",
            ['qtype_' . $qtype, 'bgimage', $questionid, $filename],
            IGNORE_MULTIPLE
        );
        if (!$filerecord) {
            $filerecord = $DB->get_record_sql(
                "SELECT id, contextid, itemid, filepath, filename
                   FROM {files}
                  WHERE component = ?
                    AND filearea = ?
                    AND itemid = ?
                    AND filesize > 0
               ORDER BY id DESC",
                ['qtype_' . $qtype, 'bgimage', $questionid],
                IGNORE_MULTIPLE
            );
        }
        if (!$filerecord) {
            return false;
        }

        $file = $fs->get_file((int)$filerecord->contextid, 'qtype_' . $qtype, 'bgimage',
            (int)$filerecord->itemid, (string)$filerecord->filepath, (string)$filerecord->filename);
        if (!$file || $file->is_directory()) {
            return false;
        }

        send_stored_file($file, 0, 0, false, $options);
        return true;
    }

    if ($filearea === 'questiontextproxy') {
        $questionid = (int)array_shift($args);
        $itemid = (int)array_shift($args);
        $filename = clean_param((string)array_pop($args), PARAM_FILE);
        $filepath = '/';
        if (!empty($args)) {
            $filepath = '/' . implode('/', array_map(function($part) {
                return clean_param((string)$part, PARAM_PATH);
            }, $args)) . '/';
        }

        if (empty($questionid) || empty($itemid) || $filename === '') {
            return false;
        }

        $question = $DB->get_record('question', ['id' => $questionid], 'id');
        if (!$question) {
            return false;
        }

        $fs = get_file_storage();
        $filerecord = $DB->get_record_sql(
            "SELECT id, contextid, itemid, filepath, filename
               FROM {files}
              WHERE component = ?
                AND filearea = ?
                AND itemid = ?
                AND filename = ?
                AND filesize > 0
           ORDER BY id DESC",
            ['question', 'questiontext', $itemid, $filename],
            IGNORE_MULTIPLE
        );
        if (!$filerecord) {
            return false;
        }

        $file = $fs->get_file((int)$filerecord->contextid, 'question', 'questiontext', (int)$filerecord->itemid,
            (string)$filerecord->filepath, (string)$filerecord->filename);
        if (!$file || $file->is_directory()) {
            return false;
        }

        send_stored_file($file, 0, 0, false, $options);
        return true;
    }

    $itemid = 0;
    switch ($filearea) {
        case 'page':
        case 'bgpage':
            $pageid = (int) array_shift($args);
            $itemid = $pageid;
            if (!$page = $DB->get_record('icontent_pages', ['id' => $pageid])) {
                return false;
            }
            break;
        case 'icontent':
            $itemid = 0;
            break;
        default:
            return false;
            break;
    }

    if (!$icontent = $DB->get_record('icontent', ['id' => $cm->instance])) {
        return false;
    }

    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_icontent/$filearea/$itemid/$relativepath";

    $fs = get_file_storage();
    if ((!$file = $fs->get_file_by_hash(sha1($fullpath))) || ($file->is_directory())) {
        return false;
    }

    // Nasty hack because we do not have fSile revisions in icontent yet.
    $lifetime = $CFG->filelifetime;
    if ($lifetime > 60 * 10) {
        $lifetime = 60 * 10;
    }

    send_stored_file($file, 0, 0, true, $options); // Download MUST be forced - security!
    // Finally send the file.

    return false;
}

/**
 * Serve files that belong to questions attempted in mod_icontent QUBA usage.
 *
 * @param stdClass $course
 * @param context $context
 * @param string $component
 * @param string $filearea
 * @param int $qubaid
 * @param int $slot
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return void
 */
function mod_icontent_question_pluginfile($course, $context, $component,
        $filearea, $qubaid, $slot, $args, $forcedownload, array $options = []) {

    require_login($course);

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/$component/$filearea/$relativepath";

    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) || $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Delete files.
 *
 * @param stdClass $icontent
 */
function icontent_delete_files(stdClass $icontent) {

    $fs = get_file_storage();
    // This is not very efficient!
    $files = $fs->get_area_files($context->id, 'mod_icontent', 'filearea', 'itemid', 'sortorder DESC, id ASC', false);
}

/* Navigation API */

/**
 * Extends the global navigation tree by adding icontent nodes if there is a relevant icontent.
 *
 * This can be called by an AJAX request so do not rely on $PAGE as it might not be set up properly.
 *
 * @param navigation_node $navref An object representing the navigation tree node of the icontent module instance
 * @param stdClass $course current course record
 * @param stdClass $module current icontent instance record
 * @param cm_info $cm course module information
 */
function icontent_extend_navigation(navigation_node $navref, stdClass $course, stdClass $module, cm_info $cm) {
    // Delete this function and its docblock, or implement it.
}

/**
 * Extend the icontent navigation settings.
 *
 * This function is called when the context for the page is a icontent module. This is not called by AJAX
 * so it is safe to rely on the $PAGE.
 *
 * @param settings_navigation $settingsnav
 * @param navigation_node $icontentnode
 * @return void
 */
function icontent_extend_settings_navigation(settings_navigation $settingsnav, $icontentnode = null) {
    global $PAGE, $DB;
    // Get instance object icontent.
    $icontent = $DB->get_record('icontent', ['id' => $PAGE->cm->instance], '*', MUST_EXIST);
    // View menu.
    if (has_any_capability(['mod/icontent:edit', 'mod/icontent:manage'], $PAGE->cm->context)) {
        $url = new moodle_url('/mod/icontent/view.php', ['id' => $PAGE->cm->id]);
        $icontentnode->add(get_string('preview', 'mod_icontent'), $url);
    }
    // Check capabilities for students.
    if (has_capability('mod/icontent:viewnotes', $PAGE->cm->context) && $icontent->shownotesarea) {
        // Notes.
        $resultsnode = $icontentnode->add(get_string('comments', 'mod_icontent'));
        $url = new moodle_url('/mod/icontent/notes.php',
            ['id' => $PAGE->cm->id, 'action' => 'featured', 'featured' => 1]);
        $resultsnode->add(get_string('highlighted', 'mod_icontent'), $url);
        $url = new moodle_url('/mod/icontent/notes.php',
            ['id' => $PAGE->cm->id, 'action' => 'likes', 'likes' => 1]);
        $resultsnode->add(get_string('likes', 'mod_icontent'), $url);
        $url = new moodle_url('/mod/icontent/notes.php',
            ['id' => $PAGE->cm->id, 'action' => 'private', 'private' => 1]);
        $resultsnode->add(get_string('privates', 'mod_icontent'), $url);

        // Doubts.
        $resultsnode = $icontentnode->add(get_string('doubts', 'mod_icontent'));
        $url = new moodle_url('/mod/icontent/doubts.php',
            ['id' => $PAGE->cm->id, 'action' => 'doubttutor', 'doubttutor' => 1,  'tab' => 'doubt']);
        $resultsnode->add(get_string('doubtstotutor', 'mod_icontent'), $url);
        $url = new moodle_url('/mod/icontent/doubts.php',
            ['id' => $PAGE->cm->id, 'action' => 'alldoubts', 'tab' => 'doubt']);
        $resultsnode->add(get_string('alldoubts', 'mod_icontent'), $url);
    }

    // Menu items for manager.
    if (has_capability('mod/icontent:grade', $PAGE->cm->context)) {
        $resultsnode = $icontentnode->add(get_string('results', 'mod_icontent'));
        $url = new moodle_url('/mod/icontent/grade.php',
            ['id' => $PAGE->cm->id, 'action' => 'overview']);
        $resultsnode->add(get_string('grades'), $url);
        $url = new moodle_url('/mod/icontent/grading.php',
            ['id' => $PAGE->cm->id, 'action' => 'grading']);
        $resultsnode->add(get_string('manualreview', 'mod_icontent'), $url);
    }
}

/* Ajax API */

/**
 * Retrieve the content page according to the parameters pagenum and icontentid.
 * @param int $pagenum
 * @param object $icontent
 * @param object $context
 * @return array $pageicontent
 */
function icontent_ajax_getpage($pagenum, $icontent, $context) {
    require_once(dirname(__FILE__).'/locallib.php');
    $objpage = icontent_get_fullpageicontent($pagenum, $icontent, $context);
    return $objpage;
}

/**
 * Save a new record in {icontent_pages_notes} and returns a list page notes.
 * @param int $pageid
 * @param object $note
 * @param object $icontent
 * @return array $pagenotes
 */
function icontent_ajax_savereturnnotes($pageid, $note, $icontent) {
    global $USER, $DB;

    $note->pageid = $pageid;
    $note->userid = $USER->id;
    $note->timecreated = time();
    $note->parent = 0;

    // Insert note.
    $insert = $DB->insert_record('icontent_pages_notes', $note);

    $return = false;
    if ($insert) {
        $note->id = $insert;
        $note->path = "/".$insert;
        $note->timemodified = time();
        $DB->update_record('icontent_pages_notes', $note);

        // Get notes this page.
        require_once(dirname(__FILE__).'/locallib.php');
        $pagenotes = icontent_get_pagenotes($note->pageid, $note->cmid, $note->tab);
        $page = $DB->get_record('icontent_pages', ['id' => $pageid], 'id, title, cmid');
        \mod_icontent\event\note_created::create_from_note($icontent, context_module::instance($page->cmid), $note)->trigger();
        $list = new stdClass;
        $list->notes = icontent_make_listnotespage($pagenotes, $icontent, $page);
        $list->totalnotes = count($pagenotes);
        // Return object list.
        $return = $list;
    }
    return $return;
}

/**
 * Runs the like or unlike in table {icontent_pages_notes_like}.
 *
 * @param stdClass $notelike
 * @param stdClass $icontent
 * @return array $result
 */
function icontent_ajax_likenote(stdClass $notelike, stdClass $icontent) {
    global $USER, $DB;
    // Set values.
    $notelike->userid = $USER->id;
    $notelike->timemodified = time();
    // Get values.
    require_once(dirname(__FILE__).'/locallib.php');
    $pagenotelike = icontent_get_pagenotelike($notelike->pagenoteid, $notelike->userid, $notelike->cmid);
    $pageid = $DB->get_field('icontent_pages_notes', 'pageid', ['id' => $notelike->pagenoteid]);
    $countlikes = icontent_count_pagenotelike($notelike->pagenoteid);
    // Make object for return.
    $return = new stdClass;
    // Check if like or unlike.
    if (empty($pagenotelike)) {
        // Insert notelike.
        $insertid = $DB->insert_record('icontent_pages_notes_like', $notelike, true);
        $notelike->id = $insertid;
        $return->likes = get_string('unlike', 'icontent', $countlikes + 1);
        // Event Log.
        $notelike->pageid = $pageid;
        \mod_icontent\event\note_like_created::create_from_note_like($icontent,
            context_module::instance($notelike->cmid), $notelike)->trigger();
        // Return object return.
        return $insertid ? $return : false;
    }
    // Execute unlike.
    $unlike = $DB->delete_records('icontent_pages_notes_like', ['id' => $pagenotelike->id]);
    // Event Log.
    $notelike->id = $pagenotelike->id;
    $notelike->pageid = $pageid;
    \mod_icontent\event\note_like_deleted::create_from_note_like($icontent,
        context_module::instance($notelike->cmid), $notelike)->trigger();
    // Make return.
    $return->likes = get_string('like', 'icontent', $countlikes - 1);
    // Return object.
    return $unlike ? $return : false;
}

/**
 * Runs update in note at table {icontent_pages_notes_like}.
 * @param stdClass $pagenote
 * @param stdClass $icontent
 * @return string $pagenote
 */
function icontent_ajax_editnote(stdClass $pagenote, stdClass $icontent) {
    global $DB;

    $pagenote->timemodified = time();
    $update = $DB->update_record('icontent_pages_notes', $pagenote);

    if ($update) {
        \mod_icontent\event\note_updated::create_from_note($icontent,
            context_module::instance($pagenote->cmid), $pagenote)->trigger();
        return $pagenote;
    }
    return false;
}

/**
 * Inserts responses of notes in table {icontent_pages_notes}.
 * @param stdClass $pagenote
 * @param stdClass $icontent
 * @return string $reply
 */
function icontent_ajax_replynote(stdClass $pagenote, stdClass $icontent) {
    global $DB, $USER;

    // Recovers pagenote father.
    $objparent = $DB->get_record('icontent_pages_notes',
        ['id' => $pagenote->parent],
        'pageid,
        tab,
        path,
        private,
        featured,
        doubttutor'
    );

    $pagenote->userid = $USER->id;
    $pagenote->timecreated = time();
    $pagenote->pageid = $objparent->pageid;
    $pagenote->tab = $objparent->tab;
    $pagenote->private = $objparent->private;
    $pagenote->featured = $objparent->featured;
    $pagenote->doubttutor = $objparent->doubttutor;

    // Insert pagenote.
    $insert = $DB->insert_record('icontent_pages_notes', $pagenote);

    $return = false;
    if ($insert) {
        $pagenote->id = $insert;
        $pagenote->path = $objparent->path."/".$insert;
        $pagenote->timemodified = time();
        $DB->update_record('icontent_pages_notes', $pagenote);
        \mod_icontent\event\note_replied::create_from_note($icontent,
            context_module::instance($pagenote->cmid), $pagenote)->trigger();
        // Get notes reply.
        require_once(dirname(__FILE__).'/locallib.php');

        $return = new stdClass;
        $return->reply = icontent_make_pagenotereply($pagenote, $icontent);
        $return->tab = $pagenote->tab;
        $return->parent = $pagenote->parent;
        $return->totalnotes = $DB->count_records('icontent_pages_notes',
            [
                'pageid' => $pagenote->pageid,
                'cmid' => $pagenote->cmid,
                'tab' => $pagenote->tab,
            ]
        );
    }
    return $return;
}

/**
 * Process Phase 3 question engine actions for supported qtypes and return attempt records.
 *
 * @param array $postdata
 * @param stdClass $cm
 * @param int $pageid
 * @return array
 */
function icontent_phase3_process_qengine_attempts(array $postdata, stdClass $cm, int $pageid): array {
    global $CFG, $SESSION, $USER, $DB;

    if (!icontent_question_engine_phase1_enabled()) {
        return [];
    }

    if (empty($USER->id) || empty($SESSION->mod_icontent_quba) || !is_array($SESSION->mod_icontent_quba)) {
        return [];
    }

    $sessionkey = icontent_question_engine_phase1_get_session_key($cm->id, $pageid, $USER->id);
    $qubaid = $SESSION->mod_icontent_quba[$sessionkey] ?? 0;
    if (empty($qubaid)) {
        return [];
    }

    require_once($CFG->libdir.'/questionlib.php');

    try {
        $quba = question_engine::load_questions_usage_by_activity($qubaid);
        $quba->process_all_actions(time(), $postdata);
        question_engine::save_questions_usage_by_activity($quba);
    } catch (Exception $e) {
        return [];
    }

    $slotbyqid = [];
    foreach ($quba->get_slots() as $slot) {
        try {
            $slotquestion = $quba->get_question($slot);
            if (!empty($slotquestion->id)) {
                $slotbyqid[(int)$slotquestion->id] = $slot;
            }
        } catch (Exception $e) {
            continue;
        }
    }

    $records = [];
    $supportedqtypes = icontent_question_engine_phase1_supported_qtypes();
    $pagequestions = icontent_get_pagequestions($pageid, $cm->id);
    if (empty($pagequestions)) {
        return [];
    }

    foreach ($pagequestions as $pagequestion) {
        if (empty($pagequestion->qtype) || !in_array($pagequestion->qtype, $supportedqtypes)) {
            continue;
        }

        $qid = (int)$pagequestion->qid;
        if (empty($slotbyqid[$qid])) {
            continue;
        }

        try {
            $qa = $quba->get_question_attempt($slotbyqid[$qid]);
            $ismanuallyreviewed = ($pagequestion->qtype === ICONTENT_QTYPE_ESSAY);
            if (!$ismanuallyreviewed && $pagequestion->qtype === 'poodllrecording') {
                static $poodllsketchcache = [];
                if (!array_key_exists($qid, $poodllsketchcache)) {
                    $responseformat = $DB->get_field('qtype_poodllrecording_opts', 'responseformat', ['questionid' => $qid]);
                    $poodllsketchcache[$qid] = ($responseformat === 'picture');
                }
                $ismanuallyreviewed = $poodllsketchcache[$qid];
            }

            $submittedresponse = null;
            $questiondef = $qa->get_question();
            $expecteddata = method_exists($questiondef, 'get_expected_data') ? (array)$questiondef->get_expected_data() : [];
            $submittedresponse = [];
            $hasanswerfield = array_key_exists('answer', $expecteddata);
            $submittedanswer = '';

            foreach (array_keys($expecteddata) as $varname) {
                $fieldname = $qa->get_qt_field_name($varname);
                if (array_key_exists($fieldname, $postdata)) {
                    $submittedresponse[$varname] = $postdata[$fieldname];
                }
            }

            if (empty($submittedresponse)) {
                foreach (array_keys($expecteddata) as $varname) {
                    $lastvalue = $qa->get_last_qt_var($varname, null);
                    if ($lastvalue !== null && $lastvalue !== '') {
                        $submittedresponse[$varname] = $lastvalue;
                    }
                }
            }

            if ($hasanswerfield) {
                $answerfield = $qa->get_qt_field_name('answer');
                if (array_key_exists($answerfield, $postdata)) {
                    $submittedanswer = trim((string)$postdata[$answerfield]);
                } else {
                    $submittedanswer = trim((string)$qa->get_last_qt_var('answer', ''));
                }
            }

            if ($hasanswerfield
                    && $pagequestion->qtype !== ICONTENT_QTYPE_MULTICHOICE
                    && !$ismanuallyreviewed
                    && $submittedanswer === '') {
                continue;
            }

            if ($hasanswerfield && $submittedanswer !== '') {
                $submittedresponse['answer'] = $submittedanswer;
            }

            if (empty($submittedresponse) && !$ismanuallyreviewed) {
                $submittedresponse = null;
            }

            $lastqtdata = $qa->get_last_qt_data([]);
            if (empty($lastqtdata) && $submittedresponse === null) {
                continue;
            }

            if ($submittedresponse === null && method_exists($questiondef, 'is_complete_response')
                    && !$questiondef->is_complete_response($lastqtdata)) {
                continue;
            }

            $fraction = ($qa->get_fraction() === null) ? 0 : (float)$qa->get_fraction();
            $rightanswer = (string)$qa->get_right_answer_summary();
            $answertext = (string)$qa->get_response_summary();

            if ($submittedresponse !== null) {
                if (method_exists($questiondef, 'summarise_response')) {
                    $summary = $questiondef->summarise_response($submittedresponse);
                    if ($summary !== null && $summary !== '') {
                        $answertext = (string)$summary;
                    }
                } else if (array_key_exists('answer', $submittedresponse)) {
                    $answertext = (string)$submittedresponse['answer'];
                }

                if (method_exists($questiondef, 'grade_response')) {
                    [$gradedfraction, ] = $questiondef->grade_response($submittedresponse);
                    if ($gradedfraction !== null) {
                        $fraction = (float)$gradedfraction;
                    }
                }

                if ($pagequestion->qtype === ICONTENT_QTYPE_TRUEFALSE) {
                    if ($answertext === '1') {
                        $answertext = get_string('true', 'question');
                    } else if ($answertext === '0') {
                        $answertext = get_string('false', 'question');
                    }
                }
            }

            if ($ismanuallyreviewed) {
                $fraction = 0;
                $rightanswer = ICONTENT_QTYPE_ESSAY_STATUS_TOEVALUATE;

                if ($answertext === '' && method_exists($questiondef, 'summarise_response')) {
                    $fallbacksummary = $questiondef->summarise_response($lastqtdata);
                    if ($fallbacksummary !== null && $fallbacksummary !== '') {
                        $answertext = (string)$fallbacksummary;
                    }
                }

                if ($answertext === '' && !empty($submittedresponse['answer'])) {
                    $answertext = question_utils::to_plain_text((string)$submittedresponse['answer']);
                }
            }

            $records[] = (object) [
                'pagesquestionsid' => (int)$pagequestion->qpid,
                'questionid' => $qid,
                'userid' => (int)$USER->id,
                'cmid' => (int)$cm->id,
                'fraction' => $fraction,
                'rightanswer' => $rightanswer,
                'answertext' => $answertext,
                'timecreated' => time(),
            ];
        } catch (Exception $e) {
            continue;
        }
    }

    return $records;
}

/**
 * Saves attempts to answers to the questions of the current page in table {icontent_question_attempt}.
 * @param string $formdata
 * @param stdClass $cm
 * @param object $icontent
 * @return string $response
 */
function icontent_ajax_saveattempt($formdata, stdClass $cm, $icontent) {
    global $USER, $DB;
    require_once(dirname(__FILE__).'/locallib.php');
    // Get form data.
    parse_str($formdata, $data);
    $pageid = $data['pageid'];

    // Phase 3: process question engine actions for supported qtypes.
    $qenginerecords = icontent_phase3_process_qengine_attempts($data, $cm, (int)$pageid);
    $qengineqids = [];
    foreach ($qenginerecords as $record) {
        $qengineqids[(int)$record->questionid] = true;
    }

    // Destroy unused fields.
    unset($data['id']);
    unset($data['pageid']);
    unset($data['sesskey']);
    // Create array object for attempt.
    $i = 0;
    $records = [];
    foreach ($data as $key => $value) {
        if (!preg_match('/^qpid-\d+_qid-\d+_[a-z0-9]+/i', $key)) {
            continue;
        }
        list($qpage, $question, $qtype) = explode('_', $key);
        list($strvar, $qpid) = explode('-', $qpage);
        list($strvar, $qid) = explode('-', $question);

        if (isset($qengineqids[(int)$qid])) {
            continue;
        }

        $infoanswer = icontent_get_infoanswer_by_questionid($qid, $qtype, $value);
        $records[$i] = new stdClass();
        $records[$i]->pagesquestionsid = (int) $qpid;
        $records[$i]->questionid = (int) $qid;
        $records[$i]->userid = (int) $USER->id;
        $records[$i]->cmid = (int) $cm->id;
        $records[$i]->fraction = $infoanswer->fraction;
        $records[$i]->rightanswer = $infoanswer->rightanswer;
        $records[$i]->answertext = $infoanswer->answertext;
        $records[$i]->timecreated = time();
        $i ++;
    }

    if (!empty($qenginerecords)) {
        $records = array_merge($records, $qenginerecords);
    }

    if (empty($records)) {
        $summary = new stdClass();
        $summary->grid = icontent_make_questionsarea($DB->get_record('icontent_pages', ['id' => $pageid], '*', MUST_EXIST), $icontent);
        return $summary;
    }

    // Save records.
    $DB->insert_records('icontent_question_attempts', $records);
    // Update grade.
    icontent_set_grade_item($icontent, $cm->id, $USER->id);
    // Event log.
    \mod_icontent\event\question_attempt_created::create_from_question_attempt($icontent,
        context_module::instance($cm->id), $pageid)->trigger();
    // Create object summary attempt.
    $summary = new stdClass();
    $summary->grid = icontent_make_attempt_summary_by_page($pageid, $cm->id);

    return $summary;
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all posts from the specified icontent
 * and clean up any related data.
 *
 * @param stdClass $data
 * @return array
 */
function icontent_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->dirroot . "/mod/icontent/locallib.php");

    $componentstr = get_string('modulenameplural', 'icontent');
    $status = [];

    // 20240920 Working on tags stuff based off wiki. Might need reset for notes, note replies, questions, question replies.
    // Will need to research the db tables.

    if (!empty($data->reset_icontent)) {
        $instances = $DB->get_records('icontent', ['course' => $data->courseid]);
        foreach ($instances as $instance) {
            if (reset_instance($instance->id)) {
                $status[] = [
                    'component' => get_string('modulenameplural', 'icontent'),
                    'item' => get_string('reseticontent', 'icontent').': '.$instance->name,
                    'error' => false,
                ];
            }
        }
    }
    return $status;
}
