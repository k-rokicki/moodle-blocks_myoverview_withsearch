<?php

/**
 * @copyright  2022 Kacper Rokicki
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$functions = array(
    'get_enrolled_courses_by_timeline_classification_with_search' => array(
        'classname' => 'myoverview_withsearch_external',
        'methodname' => 'get_enrolled_courses_by_timeline_classification',
        'classpath' => 'blocks/myoverview_withsearch/externallib.php',
        'description' => 'List of enrolled courses for the given timeline classification (past, inprogress, or future).',
        'type' => 'read',
        'ajax' => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    )
);
