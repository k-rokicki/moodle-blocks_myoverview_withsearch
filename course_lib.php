<?php
/**
 * Library of useful functions
 *
 * @copyright 2022 Kacper Rokicki <k.k.rokicki@gmail.com>
 * @copyright based on work by 1999 Martin Dougiamas  http://dougiamas.com
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @package core_course
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir.'/completionlib.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/datalib.php');
require_once($CFG->dirroot.'/course/format/lib.php');
require_once($CFG->dirroot."/blocks/myoverview_withsearch/lib_enrollib.php");

/**
 * Get the list of enrolled courses for the current user.
 *
 * This function returns a Generator. The courses will be loaded from the database
 * in chunks rather than a single query.
 *
 * @param int $limit Restrict result set to this amount
 * @param int $offset Skip this number of records from the start of the result set
 * @param string|null $sort SQL string for sorting
 * @param string|null $fields SQL string for fields to be returned
 * @param int $dbquerylimit The number of records to load per DB request
 * @param array $includecourses courses ids to be restricted
 * @param array $hiddencourses courses ids to be excluded
 * @param string|null $search course name filter
 * @return Generator
 */
function course_get_enrolled_courses_for_logged_in_user_with_search(
    int $limit = 0,
    int $offset = 0,
    string $sort = null,
    string $fields = null,
    int $dbquerylimit = COURSE_DB_QUERY_LIMIT,
    array $includecourses = [],
    array $hiddencourses = [],
    string $search = null
) : Generator {

    $haslimit = !empty($limit);
    $recordsloaded = 0;
    $querylimit = (!$haslimit || $limit > $dbquerylimit) ? $dbquerylimit : $limit;

    while ($courses = enrol_get_my_courses_with_search($fields, $sort, $querylimit, $includecourses, false, $offset, $hiddencourses, $search)) {
        yield from $courses;

        $recordsloaded += $querylimit;

        if (count($courses) < $querylimit) {
            break;
        }
        if ($haslimit && $recordsloaded >= $limit) {
            break;
        }

        $offset += $querylimit;
    }
}
