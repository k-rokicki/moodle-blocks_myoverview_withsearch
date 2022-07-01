<?php
/**
 * This library includes the basic parts of enrol api.
 * It is available on each page.
 *
 * @package    core
 * @subpackage enrol
 * @copyright  2022 Kacper Rokicki <k.k.rokicki@gmail.com>
 * @copyright  based on work by 2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/lib/enrollib.php');

/**
 * Returns list of courses current $USER is enrolled in and can access
 *
 * The $fields param is a list of field names to ADD so name just the fields you really need,
 * which will be added and uniq'd.
 *
 * If $allaccessible is true, this will additionally return courses that the current user is not
 * enrolled in, but can access because they are open to the user for other reasons (course view
 * permission, currently viewing course as a guest, or course allows guest access without
 * password).
 *
 * @param string|array $fields Extra fields to be returned (array or comma-separated list).
 * @param string|null $sort Comma separated list of fields to sort by, defaults to respecting navsortmycoursessort.
 * Allowed prefixes for sort fields are: "ul" for the user_lastaccess table, "c" for the courses table,
 * "ue" for the user_enrolments table.
 * @param int $limit max number of courses
 * @param array $courseids the list of course ids to filter by
 * @param bool $allaccessible Include courses user is not enrolled in, but can access
 * @param int $offset Offset the result set by this number
 * @param array $excludecourses IDs of hidden courses to exclude from search
 * @param string|null $search course name filter
 * @return array
 */
function enrol_get_my_courses_with_search($fields = null, $sort = null, $limit = 0, $courseids = [], $allaccessible = false,
                                          $offset = 0, $excludecourses = [], $search = null) {
    global $DB, $USER, $CFG;

    // Allowed prefixes and field names.
    $allowedprefixesandfields = ['c' => array_keys($DB->get_columns('course')),
        'ul' => array_keys($DB->get_columns('user_lastaccess')),
        'ue' => array_keys($DB->get_columns('user_enrolments'))];

    // Re-Arrange the course sorting according to the admin settings.
    $sort = enrol_get_courses_sortingsql($sort);

    // Guest account does not have any enrolled courses.
    if (!$allaccessible && (isguestuser() or !isloggedin())) {
        return array();
    }

    $basefields = [
        'id', 'category', 'sortorder',
        'shortname', 'fullname', 'idnumber',
        'startdate', 'visible',
        'groupmode', 'groupmodeforce', 'cacherev',
        'showactivitydates', 'showcompletionconditions',
    ];

    if (empty($fields)) {
        $fields = $basefields;
    } else if (is_string($fields)) {
        // turn the fields from a string to an array
        $fields = explode(',', $fields);
        $fields = array_map('trim', $fields);
        $fields = array_unique(array_merge($basefields, $fields));
    } else if (is_array($fields)) {
        $fields = array_unique(array_merge($basefields, $fields));
    } else {
        throw new coding_exception('Invalid $fields parameter in enrol_get_my_courses()');
    }
    if (in_array('*', $fields)) {
        $fields = array('*');
    }

    $orderby = "";
    $sort    = trim($sort);
    $sorttimeaccess = false;
    if (!empty($sort)) {
        $rawsorts = explode(',', $sort);
        $sorts = array();
        foreach ($rawsorts as $rawsort) {
            $rawsort = trim($rawsort);
            // Make sure that there are no more white spaces in sortparams after explode.
            $sortparams = array_values(array_filter(explode(' ', $rawsort)));
            // If more than 2 values present then throw coding_exception.
            if (isset($sortparams[2])) {
                throw new coding_exception('Invalid $sort parameter in enrol_get_my_courses()');
            }
            // Check the sort ordering if present, at the beginning.
            if (isset($sortparams[1]) && (preg_match("/^(asc|desc)$/i", $sortparams[1]) === 0)) {
                throw new coding_exception('Invalid sort direction in $sort parameter in enrol_get_my_courses()');
            }

            $sortfield = $sortparams[0];
            $sortdirection = $sortparams[1] ?? 'asc';
            if (strpos($sortfield, '.') !== false) {
                $sortfieldparams = explode('.', $sortfield);
                // Check if more than one dots present in the prefix field.
                if (isset($sortfieldparams[2])) {
                    throw new coding_exception('Invalid $sort parameter in enrol_get_my_courses()');
                }
                list($prefix, $fieldname) = [$sortfieldparams[0], $sortfieldparams[1]];
                // Check if the field name matches with the allowed prefix.
                if (array_key_exists($prefix, $allowedprefixesandfields) &&
                    (in_array($fieldname, $allowedprefixesandfields[$prefix]))) {
                    if ($prefix === 'ul') {
                        $sorts[] = "COALESCE({$prefix}.{$fieldname}, 0) {$sortdirection}";
                        $sorttimeaccess = true;
                    } else {
                        // Check if the field name that matches with the prefix and just append to sorts.
                        $sorts[] = $rawsort;
                    }
                } else {
                    throw new coding_exception('Invalid $sort parameter in enrol_get_my_courses()');
                }
            } else {
                // Check if the field name matches with $allowedprefixesandfields.
                $found = false;
                foreach (array_keys($allowedprefixesandfields) as $prefix) {
                    if (in_array($sortfield, $allowedprefixesandfields[$prefix])) {
                        if ($prefix === 'ul') {
                            $sorts[] = "COALESCE({$prefix}.{$sortfield}, 0) {$sortdirection}";
                            $sorttimeaccess = true;
                        } else {
                            $sorts[] = "{$prefix}.{$sortfield} {$sortdirection}";
                        }
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    // The param is not found in $allowedprefixesandfields.
                    throw new coding_exception('Invalid $sort parameter in enrol_get_my_courses()');
                }
            }
        }
        $sort = implode(',', $sorts);
        $orderby = "ORDER BY $sort";
    }

    $wheres = array("c.id <> :siteid");
    $params = array('siteid'=>SITEID);

    if ($search !== null && strlen($search) > 0) {
        $wheres[] = "c.fullname LIKE CONCAT ('%', :search, '%')";
        $params['search'] = $search;
    }

    if (isset($USER->loginascontext) and $USER->loginascontext->contextlevel == CONTEXT_COURSE) {
        // list _only_ this course - anything else is asking for trouble...
        $wheres[] = "courseid = :loginas";
        $params['loginas'] = $USER->loginascontext->instanceid;
    }

    $coursefields = 'c.' .join(',c.', $fields);
    $ccselect = ', ' . context_helper::get_preload_record_columns_sql('ctx');
    $ccjoin = "LEFT JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel)";
    $params['contextlevel'] = CONTEXT_COURSE;
    $wheres = implode(" AND ", $wheres);

    $timeaccessselect = "";
    $timeaccessjoin = "";

    if (!empty($courseids)) {
        list($courseidssql, $courseidsparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $wheres = sprintf("%s AND c.id %s", $wheres, $courseidssql);
        $params = array_merge($params, $courseidsparams);
    }

    if (!empty($excludecourses)) {
        list($courseidssql, $courseidsparams) = $DB->get_in_or_equal($excludecourses, SQL_PARAMS_NAMED, 'param', false);
        $wheres = sprintf("%s AND c.id %s", $wheres, $courseidssql);
        $params = array_merge($params, $courseidsparams);
    }

    $courseidsql = "";
    // Logged-in, non-guest users get their enrolled courses.
    if (!isguestuser() && isloggedin()) {
        $courseidsql .= "
                SELECT DISTINCT e.courseid
                  FROM {enrol} e
                  JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = :userid1)
                 WHERE ue.status = :active AND e.status = :enabled AND ue.timestart <= :now1
                       AND (ue.timeend = 0 OR ue.timeend > :now2)";
        $params['userid1'] = $USER->id;
        $params['active'] = ENROL_USER_ACTIVE;
        $params['enabled'] = ENROL_INSTANCE_ENABLED;
        $params['now1'] = $params['now2'] = time();

        if ($sorttimeaccess) {
            $params['userid2'] = $USER->id;
            $timeaccessselect = ', ul.timeaccess as lastaccessed';
            $timeaccessjoin = "LEFT JOIN {user_lastaccess} ul ON (ul.courseid = c.id AND ul.userid = :userid2)";
        }
    }

    // When including non-enrolled but accessible courses...
    if ($allaccessible) {
        if (is_siteadmin()) {
            // Site admins can access all courses.
            $courseidsql = "SELECT DISTINCT c2.id AS courseid FROM {course} c2";
        } else {
            // If we used the enrolment as well, then this will be UNIONed.
            if ($courseidsql) {
                $courseidsql .= " UNION ";
            }

            // Include courses with guest access and no password.
            $courseidsql .= "
                    SELECT DISTINCT e.courseid
                      FROM {enrol} e
                     WHERE e.enrol = 'guest' AND e.password = :emptypass AND e.status = :enabled2";
            $params['emptypass'] = '';
            $params['enabled2'] = ENROL_INSTANCE_ENABLED;

            // Include courses where the current user is currently using guest access (may include
            // those which require a password).
            $courseids = [];
            $accessdata = get_user_accessdata($USER->id);
            foreach ($accessdata['ra'] as $contextpath => $roles) {
                if (array_key_exists($CFG->guestroleid, $roles)) {
                    // Work out the course id from context path.
                    $context = context::instance_by_id(preg_replace('~^.*/~', '', $contextpath));
                    if ($context instanceof context_course) {
                        $courseids[$context->instanceid] = true;
                    }
                }
            }

            // Include courses where the current user has moodle/course:view capability.
            $courses = get_user_capability_course('moodle/course:view', null, false);
            if (!$courses) {
                $courses = [];
            }
            foreach ($courses as $course) {
                $courseids[$course->id] = true;
            }

            // If there are any in either category, list them individually.
            if ($courseids) {
                list ($allowedsql, $allowedparams) = $DB->get_in_or_equal(
                    array_keys($courseids), SQL_PARAMS_NAMED);
                $courseidsql .= "
                        UNION
                       SELECT DISTINCT c3.id AS courseid
                         FROM {course} c3
                        WHERE c3.id $allowedsql";
                $params = array_merge($params, $allowedparams);
            }
        }
    }

    // Note: we can not use DISTINCT + text fields due to Oracle and MS limitations, that is why
    // we have the subselect there.
    $sql = "SELECT $coursefields $ccselect $timeaccessselect
              FROM {course} c
              JOIN ($courseidsql) en ON (en.courseid = c.id)
           $timeaccessjoin
           $ccjoin
             WHERE $wheres
          $orderby";

    $courses = $DB->get_records_sql($sql, $params, $offset, $limit);

    // preload contexts and check visibility
    foreach ($courses as $id=>$course) {
        context_helper::preload_from_record($course);
        if (!$course->visible) {
            if (!$context = context_course::instance($id, IGNORE_MISSING)) {
                unset($courses[$id]);
                continue;
            }
            if (!has_capability('moodle/course:viewhiddencourses', $context)) {
                unset($courses[$id]);
                continue;
            }
        }
        $courses[$id] = $course;
    }

    //wow! Is that really all? :-D

    return $courses;
}
