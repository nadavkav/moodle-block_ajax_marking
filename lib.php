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
 * The main library file for the AJAX Marking block
 *
 * @package    block
 * @subpackage ajax_marking
 * @copyright  2008 Matt Gibson
 * @author     Matt Gibson {@link http://moodle.org/user/view.php?id=81450}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Include the upgrade file so we have access to amb_update_modules() in case of no settings.
global $CFG;
require_once("{$CFG->dirroot}/enrol/locallib.php");


/**
 * Returns the sql and params array for 'IN (x, y, z)' where xyz are the ids of teacher or
 * non-editing teacher roles
 *
 * @return array $sql and $param
 */
function block_ajax_marking_teacherrole_sql() {

    global $DB;

    $mods = block_ajax_marking_get_module_classes();
    $capabilities = array();
    foreach ($mods as $mod) {
        $capabilities[] = $mod->get_capability();
    }
    list($capsql, $capparams) = $DB->get_in_or_equal($capabilities);

    $sql = "
        SELECT DISTINCT(role.id)
          FROM {role} role
    INNER JOIN {role_capabilities} rc
            ON role.id = rc.roleid
         WHERE rc.contextid = 1
           AND ".$DB->sql_compare_text('rc.capability')." ".$capsql;

    // TODO should be a site wide or block level setting.
    $teacherroles = $DB->get_records_sql($sql, $capparams);
    // This is in case we can't do get_in_or_equal() because no roles are found and we get false.
    if (!$teacherroles) {
        return array('', array());
    }
    $teacherroleids = array_keys($teacherroles);

    return $DB->get_in_or_equal($teacherroleids);
}

/**
 * Finds out how many levels there are in the largest hierarchy of categories across the site.
 * This is so that left joins can be done that will search up the entire category hierarchy for
 * roles that were assigned at category level that would give someone grading permission in a course
 *
 * @global moodle_database $DB
 * @param bool $reset clear the cache?
 * @return int
 */
function block_ajax_marking_get_number_of_category_levels($reset=false) {

    global $DB;

    /**
     * @var stdClass $categorylevels cache this in case this is called twice during one request
     */
    static $categorylevels;

    if (isset($categorylevels) && !$reset) {
        return $categorylevels;
    }

    $sql = 'SELECT MAX(cx.depth) as depth
              FROM {context} cx
             WHERE cx.contextlevel <= ? ';
    $params = array(CONTEXT_COURSECAT);

    $categorylevels = $DB->get_record_sql($sql, $params);

    $categorylevels = $categorylevels->depth;
    $categorylevels--; // Ignore site level category to get actual number of categories.

    return $categorylevels;
}

/**
 * This is to find out what courses a person has a teacher role. This is instead of
 * enrol_get_my_courses(), which would prevent teachers from being assigned at category level
 *
 * @param bool $returnsql flag to determine whether we want to get the sql and params to use as a
 * subquery for something else
 * @param bool $reset
 * @return array of courses keyed by courseid
 */
function block_ajax_marking_get_my_teacher_courses($returnsql = false, $reset = false) {

    // NOTE could also use subquery without union.
    /* @var stdClass $USER */
    global $DB, $USER;

    // If running in a unit test, we may well have different courses in the same script execution, so we want to
    // reset every time.
    if (defined('PHPUNIT_TEST')) {
        $reset = true;
    }

    // Cache to save DB queries.
    static $courses = '';
    static $query = '';
    static $params = '';

    if ($returnsql && !$reset) {

        if (!empty($query)) {
            return array($query, $params);
        }

    } else {

        if (!empty($courses) && !$reset) {
            return $courses;
        }
    }

    list($rolesql, $roleparams) = block_ajax_marking_teacherrole_sql();

    $fieldssql = 'DISTINCT(course.id)';
    // Only get extra columns back if we are returning the actual results. Subqueries won't need it.
    $fieldssql .= $returnsql ? '' : ', fullname, shortname';

    // Main bit.

    // All directly assigned roles.
    $select = "SELECT {$fieldssql}
                 FROM {course} course
           INNER JOIN {context} cx
                   ON cx.instanceid = course.id
           INNER JOIN {role_assignments} ra
                   ON ra.contextid = cx.id
                WHERE course.visible = 1
                  AND cx.contextlevel = ?
                  AND ra.userid = ?
                  AND ra.roleid {$rolesql} ";

    // Roles assigned in category 1 or 2 etc.
    // What if roles are assigned in two categories that are parent/child?
    $select .= " UNION

               SELECT {$fieldssql}
                 FROM {course} course

            LEFT JOIN {course_categories} cat1
                   ON course.category = cat1.id ";

    $where =   "WHERE course.visible = 1
                  AND EXISTS (SELECT 1
                                  FROM {context} cx
                            INNER JOIN {role_assignments} ra
                                    ON ra.contextid = cx.id
                                 WHERE cx.contextlevel = ?
                                   AND ra.userid = ?
                                   AND ra.roleid {$rolesql}
                                   AND (cx.instanceid = cat1.id ";

    // Loop adding extra join tables. $categorylevels = 2 means we only need one level of
    // categories (which we already have with the first left join above), so we start from 2
    // and only add anything if there are 3 levels or more.
    // TODO does this cope with no hierarchy at all? This would mean $categoryleveles = 1.
    $categorylevels = block_ajax_marking_get_number_of_category_levels();

    for ($i = 2; $i <= $categorylevels; $i++) {

        $previouscat = $i-1;
        $select .= "LEFT JOIN {course_categories} cat{$i}
                           ON cat{$previouscat}.parent = cat{$i}.id ";

        $where .= "OR cx.instanceid = cat{$i}.id ";
    }

    $query = $select.$where.'))';

    $params = array_merge(array(CONTEXT_COURSE, $USER->id),
                          $roleparams,
                          array(CONTEXT_COURSECAT, $USER->id), $roleparams);

    if ($returnsql) {
        return array($query, $params);
    } else {
        $courses = $DB->get_records_sql($query, $params);
        return $courses;
    }

}

/**
 * Instantiates all plugin classes and returns them as an array
 *
 * @param bool $reset
 * @global moodle_database $DB
 * @global stdClass $CFG
 * @return block_ajax_marking_module_base[] array of objects keyed by modulename, each one being
 * the module plugin for that name. Returns a reference.
 */
function &block_ajax_marking_get_module_classes($reset = false) {

    global $DB, $CFG;

    // Cache them so we don't waste them.
    static $moduleclasses = array();

    if ($moduleclasses && !$reset) {
        return $moduleclasses;
    }

    // See which modules are currently enabled.
    $sql = 'SELECT name
              FROM {modules}
             WHERE visible = 1';
    $enabledmods = $DB->get_records_sql($sql);
    $enabledmods = array_keys($enabledmods);

    foreach ($enabledmods as $enabledmod) {

        if ($enabledmod === 'journal') { // Just until it's fixed.
            continue;
        }

        $file = "{$CFG->dirroot}/blocks/ajax_marking/modules/{$enabledmod}/".
                "block_ajax_marking_{$enabledmod}.class.php";

        if (file_exists($file)) {
            require_once($file);
            $classname = 'block_ajax_marking_'.$enabledmod;
            $moduleclasses[$enabledmod] = new $classname();
        }
    }

    return $moduleclasses;

}

/**
 * Splits the node into display and returndata bits. Display will only involve certain things, so we
 * can hard code them to be shunted into where they belong. Anything else should be in returndata,
 * which will vary a lot, so we use that as the default.
 *
 * @param object $node
 * @param string $nextnodefilter name of the current filter
 * @return void
 */
function block_ajax_marking_format_node(&$node, $nextnodefilter) {

    $node->displaydata = new stdClass;
    $node->returndata  = new stdClass;
    $node->popupstuff  = new stdClass;
    $node->configdata  = new stdClass;

    // The things to go into display are fixed. Stuff for return data varies.
    $displayitems = array(
            'itemcount',
            'description',
            'firstname',
            'lastname',
            'modulename',
            'name',
            'seconds',
            'style',
            'summary',
            'tooltip',
            'timestamp',
            'recentcount',
            'mediumcount',
            'overduecount'
    );

    $configitems = array(
            'display',
            'groupsdisplay',
            'groups'
    );

    $ignorednames = array('displaydata', 'returndata', 'popupstuff', 'configdata');

    // Loop through the rest of the object's properties moving them to the returndata bit.
    foreach ($node as $varname => $value) {

        if (in_array($varname, $ignorednames)) {
            continue;
        }

        if ($varname == 'tooltip') {
            $value = block_ajax_marking_strip_html_tags($value);
        }

        if (in_array($varname, $displayitems)) {
            $node->displaydata->$varname = $value;
        } else if (in_array($varname, $configitems)) {
            $node->configdata->$varname = $value;
        } else if ($varname == $nextnodefilter) {
            $node->returndata->$varname = $value;
            $node->returndata->currentfilter = $varname;
        } else {
            $node->popupstuff->$varname = $value;
        }

        unset($node->$varname);

    }
}

/**
 * Makes the url for the grading pop up, collapsing all the supplied parameters into GET
 *
 * @param array $params
 * @return string
 */
function block_ajax_marking_form_url($params=array()) {

    global $CFG;

    $urlbits = array();

    $url = $CFG->wwwroot.'/blocks/ajax_marking/actions/grading_popup.php?';

    foreach ($params as $name => $value) {
        $urlbits[] = $name.'='.$value;
    }

    $url .= implode('&', $urlbits);

    return $url;

}

/**
 * strip_tags() leaves no spaces between what used to be different paragraphs. This (pinched
 * from a comment in the strip_tags() man page) replaces with spaces.
 *
 * @param string $text
 * @return string
 */
function block_ajax_marking_strip_html_tags($text) {
    $text = preg_replace(
        array(
             // Remove invisible content.
             '@<head[^>]*?>.*?</head>@siu',
             '@<style[^>]*?>.*?</style>@siu',
             '@<script[^>]*?.*?</script>@siu',
             '@<object[^>]*?.*?</object>@siu',
             '@<embed[^>]*?.*?</embed>@siu',
             '@<applet[^>]*?.*?</applet>@siu',
             '@<noframes[^>]*?.*?</noframes>@siu',
             '@<noscript[^>]*?.*?</noscript>@siu',
             '@<noembed[^>]*?.*?</noembed>@siu',
             // Add line breaks before and after blocks.
             '@</?((address)|(blockquote)|(center)|(del))>@iu',
             '@</?((div)|(h[1-9])|(ins)|(isindex)|(p)|(pre))>@iu',
             '@</?((dir)|(dl)|(dt)|(dd)|(li)|(menu)|(ol)|(ul))>@iu',
             '@</?((table)|(th)|(td)|(caption))>@iu',
             '@</?((form)|(button)|(fieldset)|(legend)|(input))>@iu',
             '@</?((label)|(select)|(optgroup)|(option)|(textarea))>@iu',
             '@</?((frameset)|(frame)|(iframe))>@iu',
        ),
        array(
             ' ',
             ' ',
             ' ',
             ' ',
             ' ',
             ' ',
             ' ',
             ' ',
             ' ',
             " ",
             " ",
             " ",
             " ",
             " ",
             " ",
             " ",
             " "), $text);

    $text =  strip_tags($text); // Lose any remaining tags.
    return preg_replace('/\s+/', ' ', trim($text)); // Lose duplicate whitespaces.
}

/**
 * We need a proper error message in case of a timed out session, not a dodgy redirect
 * @SuppressWarnings(PHPMD.ExitExpression)
 */
function block_ajax_marking_login_error() {

    global $CFG;

    if (!isloggedin()) {
        $notloggedin = get_string('sessiontimedout', 'block_ajax_marking', $CFG->wwwroot);
        $response = array('error' => $notloggedin,
                          'debuginfo' => 'sessiontimedout');
        echo json_encode($response);
        die();
    }
}

/**
 * One of the parameters will look like filtername => nextnodefilter instead of filtername => 898.
 * This returns it.
 *
 * @param array $params
 * @return bool|string False if not found, otherwise the filter name.
 */
function block_ajax_marking_get_nextnodefilter_from_params(array $params) {
    foreach ($params as $name => $value) {
        if ($value == 'nextnodefilter') {
            return $name;
        }
    }
    return false;
}

/**
 * In order to display the right things, we need to work out the visibility of each group for
 * each course module. This subquery lists all groups once for each coursemodule in the
 * user's courses, along with it's most relevant show/hide setting, i.e. a coursemodule level
 * override if it's there, otherwise a course level setting, or if neither, the site default.
 * This is potentially very expensive if there are hundreds of courses as it's effectively a
 * cartesian join between the groups and coursemodules tables, so we filter using the user's
 * courses. This may or may not impact on the query optimiser being able to cache the execution
 * plan between users.
 *
 * // Query visualisation:
 *               ______________________________________________________________
 *               |                                                            |
 * Course - Groups - coursemodules                                            |
 *               |   course |  |id__ block_ajax_marking_settings - block_ajax_marking_groups
 *               |          |            (for coursemodules)           (per coursemodule)
 *               |          |
 *               |          |_____ block_ajax_marking_settings --- block_ajax_marking_groups
 *               |                       (for courses)                   (per course)
 *               |____________________________________________________________|
 *
 *
 * Temp table we get back:
 * -------------------------------
 * | coursemoduleid    | groupid |
 * |-----------------------------|
 * |        543        |    67   |
 * |        342        |    6    |
 *
 * @internal param string $type We may want to know the combined visibility (coalesce) or just the
 * visibility at either (course) or (coursemodule) level. The latter two are for getting
 * the groups in their current settings states so config stuff can be adjusted, whereas
 * the combined one is for retrieving unmarked work.
 *
 * @return array SQL and params
 */
function block_ajax_marking_group_visibility_subquery() {

    global $DB, $USER;

    // In case the subquery is used twice, this variable allows us to feed the same teacher
    // courses in more than once because Moodle requires variables with different suffixes.
    static $counter = 0;
    $counter++;

    $courses = block_ajax_marking_get_my_teacher_courses();
    list($coursessql, $coursesparams) = $DB->get_in_or_equal(array_keys($courses),
                                                             SQL_PARAMS_NAMED,
                                                             "gvis_{$counter}_courses");

    $default = 1;
    $sql = <<<SQL

        SELECT gvisgroups.id AS groupid,
               gviscoursemodules.id AS coursemoduleid
          FROM {groups} gvisgroups
    INNER JOIN {course_modules} gviscoursemodules
            ON gviscoursemodules.course = gvisgroups.courseid

     LEFT JOIN {block_ajax_marking} gviscoursesettings
            ON gviscoursemodules.course = gviscoursesettings.instanceid
                AND gviscoursesettings.tablename = 'course'
                AND gviscoursesettings.userid = :gvis{$counter}user1

     LEFT JOIN {block_ajax_marking_groups} gviscoursesettingsgroups
            ON gviscoursesettingsgroups.configid = gviscoursesettings.id
               AND gviscoursesettingsgroups.groupid = gvisgroups.id

     LEFT JOIN {block_ajax_marking} gviscoursemodulesettings
            ON gviscoursemodules.id = gviscoursemodulesettings.instanceid
                AND gviscoursemodulesettings.tablename = 'course_modules'
                AND gviscoursemodulesettings.userid = :gvis{$counter}user2
     LEFT JOIN {block_ajax_marking_groups} gviscoursemodulesettingsgroups
            ON gviscoursemodulesettingsgroups.configid = gviscoursemodulesettings.id
               AND gviscoursemodulesettingsgroups.groupid = gvisgroups.id

         WHERE gviscoursemodules.course {$coursessql}
           AND COALESCE(gviscoursemodulesettingsgroups.display,
                        gviscoursesettingsgroups.display,
                        {$default}) = 0
SQL;

    $coursesparams['gvis'.$counter.'user1'] = $USER->id;
    $coursesparams['gvis'.$counter.'user2'] = $USER->id;
    return array($sql, $coursesparams);

}

/**
 * Helper function that defines what the SQL to hide groups that a teacher is not a member of
 * is. This is not the same as using the block config settings - we are talking about whatever
 * is already configured in Moodle.
 *
 * @static
 * @param int $counter
 * @global $USER
 * @return array
 */
function block_ajax_marking_get_group_hide_sql($counter) {

    global $USER, $DB;

    $separategroups = SEPARATEGROUPS;

    $params = array('teacheruserid'.$counter => $USER->id);

    // If a user is an admin in their course, we should show them all stuff for all groups.
    $oriscourseadminsql = '';
    $allowedcourses = array();
    $teachercourses = block_ajax_marking_get_my_teacher_courses();
    foreach (array_keys($teachercourses) as $courseid) {
        if (has_capability('moodle/site:accessallgroups',
                           context_course::instance($courseid))
        ) {
            $allowedcourses[] = $courseid;
        }
    }
    if ($allowedcourses) {
        list($oriscourseadminsql, $oriscourseadminparams) =
            $DB->get_in_or_equal($allowedcourses,
                                 SQL_PARAMS_NAMED,
                                 'groupsacess001',
                                 false);
        $oriscourseadminsql = ' OR group_groups.courseid '.$oriscourseadminsql;
        $params = array_merge($params, $oriscourseadminparams);
    }

    // TODO does moving the group id stuff into a WHERE xx OR IS NULL make it faster?
    // What things should we not show?
    $grouphidesql = <<<SQL
                /* Begin group hide SQL */

                 /* Course forces group mode on modules and it's not permissive */
                    (    (   (group_course.groupmodeforce = 1 AND
                              group_course.groupmode = {$separategroups}
                             )
                             OR
                             /* Modules can choose group mode and this module is not permissive */
                            ( group_course.groupmodeforce = 0 AND
                              group_course_modules.groupmode = {$separategroups}
                            )
                         )
                          AND
                          /* Teacher is not a group member */
                          NOT EXISTS ( SELECT 1
                                         FROM {groups_members} teachermemberships
                                        WHERE teachermemberships.groupid = group_groups.id
                                          AND teachermemberships.userid = :teacheruserid{$counter} )

                          /* Course is not one where the teacher can see all groups */
                          {$oriscourseadminsql}
                    )
    /* End group hide SQL */
SQL;
    return array($grouphidesql,
                 $params);
}

/**
 * This fragment needs to be used in several places as we need cross-db ways of reusing the same thing and SQL variables
 * are implemented differently, so we just copy/paste and let the optimiser deal with it.
 */
function block_ajax_marking_get_countwrapper_groupid_sql() {
    $sql = <<<SQL
        COALESCE(membergroupquery.groupid, 0)
SQL;
    return $sql;

}

/**
 * We have to do greatest-n-per-group to get the highest group id that's not specified as hidden by the user.
 * This involves repeating the same SQL twice because MySQL doesn't support CTEs.
 *
 * This function provides the base query which shows us the highest groupid per user per coursemodule. We need to also
 * take account of people who have no group memberships, so we need to distinguish between null results because the
 * group is hidden and null results because there is no group.
 */
function block_ajax_marking_group_max_subquery() {

    global $DB;

    // Params need new names every time.
    static $counter = 1;
    $counter++;

    list($visibilitysubquery, $visibilityparams) = block_ajax_marking_group_visibility_subquery();
    $courses = block_ajax_marking_get_my_teacher_courses();
    list($coursessql, $coursesparams) = $DB->get_in_or_equal(array_keys($courses),
                                                             SQL_PARAMS_NAMED,
                                                             "gmax_{$counter}_courses");

    // This only shows people who have group memberships, so we need to say if there isn't one or not in the outer
    // query. For this reason, this query will return all group memberships, plus whether they ought to be displayed.
    // The outer query can then do a left join.
    $sql = <<<SQL

             /* Start max groupid query */

        SELECT gmax_members{$counter}.userid,
               gmax_groups{$counter}.id AS groupid,
               gmax_course_modules{$counter}.id AS coursemoduleid,
               CASE
                   WHEN visquery{$counter}.groupid IS NULL THEN 1
                   ELSE 0
               END AS display
          FROM {groups_members} gmax_members{$counter}
    INNER JOIN {groups} gmax_groups{$counter}
            ON gmax_groups{$counter}.id = gmax_members{$counter}.groupid
    INNER JOIN {course_modules} gmax_course_modules{$counter}
            ON gmax_course_modules{$counter}.course = gmax_groups{$counter}.courseid
     LEFT JOIN ({$visibilitysubquery}) visquery{$counter}
            ON visquery{$counter}.groupid = gmax_groups{$counter}.id
               AND visquery{$counter}.coursemoduleid = gmax_course_modules{$counter}.id
           /* Limit the size of the subquery for performance */
         WHERE gmax_groups{$counter}.courseid {$coursessql}
           AND visquery{$counter}.groupid IS NULL

         /* End max groupid query */
SQL;

    return array($sql, array_merge($visibilityparams, $coursesparams));

}

/**
 * We have to do greatest-n-per-group to get the highest group id that's not specified as hidden by the user.
 * This involves repeating the same SQL twice because MySQL doesn't support CTEs.
 *
 * This function provides the base query which shows us all of the group members attached to their coursemodules. We can
 * then do a left join to block_ajax_marking_group_max_subquery() to make sure there's no higher group id that's visible
 * but whereas that has the visibility query within it, this doesn't because we want to use the possible null value
 * as an indicator that the user is not in any group.
 */
function block_ajax_marking_group_members_subquery() {

    global $DB;

    // Params need new names every time.
    static $counter = 1;
    $counter++;

    $courses = block_ajax_marking_get_my_teacher_courses();
    list($coursessql, $coursesparams) = $DB->get_in_or_equal(array_keys($courses),
                                                             SQL_PARAMS_NAMED,
                                                             "gmember_{$counter}_courses");

    // This only shows people who have group memberships, so we need to say if there isn't one or not in the outer
    // query. For this reason, this query will return all group memberships, plus whether they ought to be displayed.
    // The outer query can then do a left join.
    $sql = <<<SQL

             /* Start member query */

        SELECT gmember_members{$counter}.userid,
               gmember_groups{$counter}.id AS groupid,
               gmember_course_modules{$counter}.id AS coursemoduleid
          FROM {groups_members} gmember_members{$counter}
    INNER JOIN {groups} gmember_groups{$counter}
            ON gmember_groups{$counter}.id = gmember_members{$counter}.groupid
    INNER JOIN {course_modules} gmember_course_modules{$counter}
            ON gmember_course_modules{$counter}.course = gmember_groups{$counter}.courseid
            /* Limit the size of the subquery for performance */
         WHERE gmember_groups{$counter}.courseid {$coursessql}

         /* End member query */
SQL;

    return array($sql, $coursesparams);
}


