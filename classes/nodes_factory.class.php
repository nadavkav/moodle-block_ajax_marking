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
 * Class file for the block_ajax_marking_nodes_factory class
 *
 * @package    block
 * @subpackage ajax_marking
 * @copyright  2011 Matt Gibson
 * @author     Matt Gibson {@link http://moodle.org/user/view.php?id=81450}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot.'/blocks/ajax_marking/classes/query_base.class.php');

/**
 * This is to build a query based on the parameters passed in from the client. Without parameters,
 * the query should return all unmarked items across all of the site.
 *
 * The query has 3 layers: the innermost is a UNION of several queries that go and fetch the unmarked submissions from
 * each module (1 for each module as they all store unmarked work differently). The middle layer attaches standard
 * filters  via apply_sql_xxxx_settings() functions e.g. 'only show submissions from currently enrolled students' and
 * 'only show submissions that I have not configured to be hidden'. It also applies filters so that drilling down
 * through the nodes tree, the lower levels filter by the upper levels e.g. expanding a course node leads to a
 * 'WHERE courseid = xxx' clause being added. Finally, a GROUP BY statement is added for the current node level e.g.
 * for coursemodule nodes, we want to use coursemoduleid for this, then count the submissions. The outermost layer
 * then joins to the GROUP BY ids and counts (the only two columns that the middle query provides) to supply the
 * display details e.g. the name of the coursemodule. This arrangement is needed because Oracle doesn't allow text
 * fields and GROUP BY to be mixed.
 */
class block_ajax_marking_nodes_factory {

    /**
     * This will take the parameters which were supplied by the clicked node and its ancestors and
     * construct an SQL query to get the relevant work from the database. It can be used by the
     * grading popups in cases where there are multiple items e.g. multiple attempts at a quiz, but
     * it is mostly for making the queries used to get the next set of nodes.
     *
     * @param array $filters
     * @param block_ajax_marking_module_base $moduleclass e.g. quiz, assignment
     * @return block_ajax_marking_query_base
     */
    public static function get_unmarked_module_query(array $filters,
                                                     block_ajax_marking_module_base $moduleclass) {

        // Might be a config nodes query, in which case, we want to leave off the unmarked work
        // stuff and make sure we add the display select stuff to this query instead of leaving
        // it for the outer displayquery that the unmarked work needs
        $confignodes = isset($filters['config']) ? true : false;
        if ($confignodes) {
            $query = new block_ajax_marking_query_base($moduleclass);
            $query->add_from(array(
                    'table' => $moduleclass->get_module_name(),
                    'alias' => 'moduletable',
            ));
        } else {
            $query = $moduleclass->query_factory($confignodes);
        }

        $query->add_select(array('table'  => 'course_modules',
                                 'column' => 'id',
                                 'alias'  =>'coursemoduleid'));
        // Need the course to join to other filters
        $query->add_select(array('table'  => 'moduletable',
                                 'column' => 'course'));
        // Some filters need a coursemoduleid to join to, so we need to make it part of every query.
        $query->add_from(array('table' => 'course_modules',
                               'on'    => 'course_modules.instance = moduletable.id AND
                                           course_modules.module = '.$query->get_module_id()));
        // Some modules need to add stuff by joining the moduleunion back to the sub table. This
        // gets round the way we can't add stuff from just one module's sub table into the UNION bit
        $query->add_select(array('table'  => 'sub',
                                 'column' => 'id',
                                 'alias'  =>'subid'));
        // Need to pass this through sometimes for the javascript to know what sort of node it is.
        $query->add_select(array('column' => "'".$query->get_modulename()."'",
                                 'alias'  =>'modulename'));

        return $query;
    }

    /**
     * This is to build whatever query is needed in order to return the requested nodes. It may be
     * necessary to compose this query from quite a few different pieces. Without filters, this
     * should return all unmarked work across the whole site for this teacher.
     *
     * The main union query structure involves two levels of nesting: First, all modules provide a
     * query that counts the unmarked work and leaves us with
     *
     * In:
     * - filters as an array. course, coursemodule, student, others (as defined by module base
     *   classes
     *
     * Issues:
     * - maintainability: easy to add and subtract query filters
     * - readability: this is very complex
     *
     * @global moodle_database $DB
     * @param array $filters list of functions to run on the query. Methods of this or the module
     * class
     * @return array
     */
    public static function get_unmarked_nodes($filters = array()) {

        global $DB;

        // if not a union query, we will want to remember which module we are narrowed down to so we
        // can apply the postprocessing hook later

        $modulequeries = array();
        $moduleid = false;
        $moduleclass = '';
        $moduleclasses = block_ajax_marking_get_module_classes();
        if (!$moduleclasses) {
            return array(); // No nodes
        }

        $filternames = array_keys($filters);
        $havecoursemodulefilter = in_array('coursemoduleid', $filternames);
        $makingcoursemodulenodes = ($filters['nextnodefilter'] === 'coursemoduleid');

        // If one of the filters is coursemodule, then we want to avoid querying all of the module
        // tables and just stick to the one with that coursemodule. If not, we do a UNION of all
        // the modules
        if ($havecoursemodulefilter) {
            // Get the right module id
            $moduleid = $DB->get_field('course_modules', 'module',
                                       array('id' => $filters['coursemoduleid']));
        }

        foreach ($moduleclasses as $modname => $moduleclass) {
            /** @var $moduleclass block_ajax_marking_module_base */

            if ($moduleid && $moduleclass->get_module_id() !== $moduleid) {
                // We don't want this one as we're filtering by a single coursemodule
                continue;
            }

            $modulequeries[$modname] = self::get_unmarked_module_query($filters, $moduleclass);

            if ($moduleid) {
                break; // No need to carry on once we've got the only one we need
            }
        }

        // Make an array of queries to join with UNION ALL. This will get us the counts for each
        // module. Implode separate subqueries with UNION ALL. Must use ALL to cope with duplicate
        // rows with same counts and ids across the UNION. Doing it this way keeps the other items
        // needing to be placed into the SELECT  out of the way of the GROUP BY bit, which makes
        // Oracle bork up.

        // We want the bare minimum here. The idea is to avoid problems with GROUP BY ambiguity,
        // so we just get the counts as well as the node ids

        $countwrapperquery = new block_ajax_marking_query_base();
        // We find out how many submissions we have here. Not DISTINCT as we are grouping by
        // nextnodefilter in the superquery
        $countwrapperquery->add_select(array('table' => 'moduleunion',
                                             'column' => 'userid',
                                             'alias' => 'itemcount', // COUNT is a reserved word
                                             'function' => 'COUNT'));

        if ($havecoursemodulefilter || $makingcoursemodulenodes) {
            // Needed to access the correct javascript so we can open the correct popup, so
            // we include the name of the module
            $countwrapperquery->add_select(array('table' => 'moduleunion',
                                                 'column' => 'modulename'));
        }

        $countwrapperquery->add_from(array('table' => $modulequeries,
                                           'alias' => 'moduleunion',
                                           'union' => true,
                                           'subquery' => true));

        // Apply all the standard filters. These only make sense when there's unmarked work
        self::apply_sql_enrolled_students($countwrapperquery, $filters);
        self::apply_sql_visible($countwrapperquery, 'moduleunion.coursemoduleid',
                                'moduleunion.course');
        self::apply_sql_display_settings($countwrapperquery);
        self::apply_sql_owncourses($countwrapperquery, 'moduleunion.course');

        // The outermost query just joins the already counted nodes with their display data e.g. we
        // already have a count for each courseid, now we want course name and course description
        // but we don't do this in the counting bit so as to avoid weird issues with group by on
        // oracle
        $displayquery = new block_ajax_marking_query_base();
        $displayquery->add_select(array(
                'table'    => 'countwrapperquery',
                'column'   => 'id',
                'alias'    => $filters['nextnodefilter']));
        $displayquery->add_select(array(
                'table'    => 'countwrapperquery',
                'column'   => 'itemcount'));
        if ($havecoursemodulefilter) { // Need to have this pass through in case we have a mixture
            $displayquery->add_select(array(
                'table'    => 'countwrapperquery',
                'column'   => 'modulename'));
        }
        $displayquery->add_from(array(
                'table'    => $countwrapperquery,
                'alias'    => 'countwrapperquery',
                'subquery' => true));

        foreach ($filters as $name => $value) {

            if ($name == 'nextnodefilter') {
                $filterfunctionname = 'apply_'.$value.'_filter';
                // The new node filter is in the form 'nextnodefilter => 'functionname', rather
                // than 'filtername' => <rowid> We want to pass the name of the filter in with
                // an empty value, so we set the value here.
                $value = false;
                $operation = 'countselect';
            } else {
                $filterfunctionname = 'apply_'.$name.'_filter';
                $operation = 'where';
            }

            // Find the function. Core ones are part of the factory class, others will be methods of
            // the module object.
            // If we are filtering by a specific module, look there first
            if (method_exists($moduleclass, $filterfunctionname)) {
                // All core filters are methods of query_base and module specific ones will be
                // methods of the module-specific subclass. If we have one of these, it will
                // always be accompanied by a coursemoduleid, so will only be called on the
                // relevant module query and not the rest
                $moduleclass->$filterfunctionname($displayquery, $operation, $value);
            } else if (method_exists(__CLASS__, $filterfunctionname)) {
                // config tree needs to have select stuff that doesn't mention sub. Like for the
                // outer wrappers of the normal query for the unmarked work nodes
                self::$filterfunctionname($displayquery, $operation, $value);
            }
        }

        // Adds the config options if there are any, so JavaScript knows what to ask for
        self::apply_config_filter($displayquery, 'configselect');

        // This is just for copying and pasting from the paused debugger into a DB GUI
        $debugquery = block_ajax_marking_debuggable_query($displayquery);

        $nodes = $displayquery->execute();

        $nodes = self::attach_groups_to_nodes($nodes, $filters);

        if ($moduleid) {
            // this does e.g. allowing the forum module to tweak the name depending on forum type
            $moduleclass->postprocess_nodes_hook($nodes, $filters);
        }
        return $nodes;
    }

    /**
     * Applies the filter needed for course nodes or their descendants
     *
     * @param block_ajax_marking_query_base $query
     * @param bool|string $operation If we are gluing many module queries together, we will need to
     *                    run a wrapper query that will select from the UNIONed subquery
     * @param int $courseid Optional. Will apply SELECT and GROUP BY for nodes if missing
     * @return void|string
     */
    private static function apply_courseid_filter($query, $operation, $courseid = 0) {
        global $USER;

        $selects = array();
        $countwrapper = '';
        if ($operation != 'configdisplay' && $operation != 'configwhere') {
            $countwrapper = $query->get_subquery('countwrapperquery');
        }

        switch ($operation) {

            case 'where':

                // This is for when a courseid node is an ancestor of the node that has been
                // selected, so we just do a where
                $countwrapper->add_where(array(
                        'type' => 'AND',
                        'condition' => 'moduleunion.course = :courseidfiltercourseid'));
                $query->add_param('courseidfiltercourseid', $courseid);
                break;

            case 'configwhere':

                // This is for when a courseid node is an ancestor of the node that has been
                // selected, so we just do a where
                $query->add_where(array(
                        'type' => 'AND',
                        'condition' => 'course_modules.course = :courseidfiltercourseid'));
                $query->add_param('courseidfiltercourseid', $courseid);
                break;

            case 'countselect':

                $countwrapper->add_select(array(
                        'table'    => 'moduleunion',
                        'column'   => 'course',
                        'alias'    => 'id'), true
                );

                // This is for the displayquery when we are making course nodes
                $query->add_from(array(
                        'table' =>'course',
                        'alias' => 'course',
                        'on' => 'countwrapperquery.id = course.id'
                ));
                $query->add_select(array(
                    'table'    => 'course',
                    'column'   => 'shortname',
                    'alias'    => 'name'));
                $query->add_select(array(
                    'table'    => 'course',
                    'column'   => 'fullname',
                    'alias'    => 'tooltip'));
                break;

            case 'configdisplay':

                // This is for the displayquery when we are making course nodes
                $query->add_from(array(
                        'table' =>'course',
                        'alias' => 'course',
                        'on' => 'course_modules.course = course.id'
                ));
                $query->add_select(array(
                    'table'    => 'course',
                    'column'   => 'id',
                    'alias' => 'courseid',
                    'distinct' => true));
                $query->add_select(array(
                    'table'    => 'course',
                    'column'   => 'shortname',
                    'alias'    => 'name'));
                $query->add_select(array(
                    'table'    => 'course',
                    'column'   => 'fullname',
                    'alias'    => 'tooltip'));

                // We need the config settings too, if there are any
                // TODO this should be in the config filter
                $query->add_from(array(
                        'join' => 'LEFT JOIN',
                        'table' =>'block_ajax_marking',
                        'alias' => 'settings',
                        'on' => "settings.instanceid = course.id
                                 AND settings.tablename = 'course'
                                 AND settings.userid = :settingsuserid"
                ));
                $query->add_param('settingsuserid', $USER->id);
                $query->add_select(array(
                    'table'    => 'settings',
                    'column'   => 'display'));
                $query->add_select(array(
                    'table'    => 'settings',
                    'column'   => 'groupsdisplay'));
                $query->add_select(array(
                    'table'    => 'settings',
                    'column'   => 'id',
                    'alias'    => 'settingsid'));
                break;

        }

        foreach ($selects as $select) {
            $query->add_select($select);
        }
    }



    /**
     *
     * @param block_ajax_marking_query_base $query
     * @param $operation
     * @param bool|int $groupid
     * @return void
     */
    private static function apply_groupid_filter($query, $operation, $groupid = 0) {

        $countwrapper = '';
        if ($operation != 'configdisplay' && $operation != 'configwhere') {
            $countwrapper = $query->get_subquery('countwrapperquery');
        }

        // We only want to add the bit that appends a groupid to each submission if we are going to use it
        // as the calculations are expensive.
        if ($operation == 'where' || $operation == 'countselect') {
            // This adds the subquery that can tell us wht the display settings are for each group. Once we have
            // filtered out those submissions with no visible groups, we choose the best match i.e. randomly
            // assign the submissions to one of its visible groups (there will usually only be one) so it's
            // not counted twice in case the user is in two groups in this context
            list($maxgroupidsubquery, $maxgroupidparams) = self::get_sql_max_groupid_subquery();
            $countwrapper->add_params($maxgroupidparams);
            $countwrapper->add_from(array(
                    'join' => 'LEFT JOIN',
                    'table' => $maxgroupidsubquery,
                    'on' => 'maxgroupidsubquery.cmid = moduleunion.coursemoduleid AND
                             maxgroupidsubquery.userid = moduleunion.userid',
                    'alias' => 'maxgroupidsubquery',
                    'subquery' => true));
        }

        switch ($operation) {

            case 'where':

                $countwrapper->add_where(array('type' => 'AND',
                                        'condition' => 'COALESCE(maxgroupidsubquery.groupid, 0) = :groupid'));
                $countwrapper->add_param('groupid', $groupid);
                break;

            // This is when we make group nodes and need group name etc
            case 'countselect':

                $countwrapper->add_select(array(
                        'table' => array('maxgroupidsubquery' => 'groupid',
                                         '0'),
                        'function' => 'COALESCE',
                        'alias' => 'id'));

                // This is for the displayquery when we are making course nodes
                $query->add_from(array(
                    'join' => 'LEFT JOIN', // group id 0 will not match anything
                    'table' => 'groups',
                    'on' => 'countwrapperquery.id = groups.id'
                ));
                // We may get a load of people in no group
                $query->add_select(array(
                    'function' => 'COALESCE',
                    'table'    => array('groups' => 'name',
                                        get_string('notingroup', 'block_ajax_marking')),
                    'alias' => 'name'));
                $query->add_select(array(
                    'function' => 'COALESCE',
                    'table'    => array('groups' => 'description',
                                        get_string('notingroupdescription', 'block_ajax_marking')),
                    'alias'    => 'tooltip'));
                break;

        }

    }

    /**
     * Applies a filter so that only nodes from a certain cohort are returned
     *
     * @param block_ajax_marking_query_base|bool $query
     * @param $operation
     * @param bool|int $cohortid
     * @global moodle_database $DB
     * @return void
     */
    private static function apply_cohortid_filter(block_ajax_marking_query_base $query,
                                                  $operation, $cohortid = false) {

        $selects = array();
        /**
         * @var block_ajax_marking_query_base $countwrapper
         */
        $countwrapper = $query->get_subquery('countwrapperquery');

        // Note: Adding a cohort filter after any other filter will cause a problem as e.g. courseid on ancestors
        // will not include the code below which limits users to just those who are in a cohort. This
        // means that the total count may well be higher when the tree is loaded than when it is expanded

        // We need to join the userid to the cohort, if there is one.
        // TODO when is there not one?
        // Add join to cohort_members
        $countwrapper->add_from(array(
                'join' => 'INNER JOIN',
                'table' => 'cohort_members',
                'on' => 'cohort_members.userid = moduleunion.userid'
        ));
        $countwrapper->add_from(array(
                'join' => 'INNER JOIN',
                'table' => 'cohort',
                'on' => 'cohort_members.cohortid = cohort.id'
        ));

        switch ($operation) {

            case 'where':

                // Apply WHERE clause
                $countwrapper->add_where(array(
                        'type' => 'AND',
                        'condition' => 'cohort.id = :cohortidfiltercohortid'));
                $countwrapper->add_param('cohortidfiltercohortid', $cohortid);
                break;

            case 'countselect':

                $countwrapper->add_select(array(
                        'table'    => 'cohort',
                        'column'   => 'id'));

                // What do we need for the nodes?
                $query->add_from(array(
                        'join' => 'INNER JOIN',
                        'table' => 'cohort',
                        'on' => 'countwrapperquery.id = cohort.id'
                ));
                $selects = array(
                    array(
                        'table'    => 'cohort',
                        'column'   => 'name'),
                    array(
                        'table'    => 'cohort',
                        'column'   => 'description'));
                break;
        }

        foreach ($selects as $select) {
            $query->add_select($select);
        }
    }

    /**
     * Applies the filter needed for assessment nodes or their descendants
     *
     * @param block_ajax_marking_query_base $query
     * @param int $coursemoduleid optional. Will apply SELECT and GROUP BY for nodes if missing
     * @param bool $operation
     * @return void
     */
    private static function apply_coursemoduleid_filter($query, $operation, $coursemoduleid = 0 ) {
        global $USER;

        $countwrapper = '';
        if ($operation != 'configdisplay') {
            $countwrapper = $query->get_subquery('countwrapperquery');
        }

        switch ($operation) {

            case 'where':
                $countwrapper->add_where(array(
                        'type' => 'AND',
                        'condition' => 'moduleunion.coursemoduleid = :coursemoduleidfiltercmid'));
                $query->add_param('coursemoduleidfiltercmid', $coursemoduleid);
                break;

            case 'countselect':

                // Same order as the super query will need them. Prefixed so we will have it as the
                // first column for the GROUP BY
                $countwrapper->add_select(array(
                        'table' => 'moduleunion',
                        'column' => 'coursemoduleid',
                        'alias' => 'id'), true);
                $query->add_from(array(
                        'join' => 'INNER JOIN',
                        'table' => 'course_modules',
                        'on' => 'course_modules.id = countwrapperquery.id'));
                $query->add_select(array(
                        'table'    => 'course_modules',
                        'column'   => 'id',
                        'alias'    => 'coursemoduleid'));
                $query->add_select(array(
                        'table'    => 'countwrapperquery',
                        'column'   => 'modulename'));

            case 'configdisplay':

                // Awkwardly, the course_module table doesn't hold the name and description of the
                // module instances, so we need to join to the module tables. This will cause a mess
                // unless we specify that only coursemodules with a specific module id should join
                // to a specific module table
                $moduleclasses = block_ajax_marking_get_module_classes();
                $introcoalesce = array();
                $namecoalesce = array();
                foreach ($moduleclasses as $moduleclass) {
                    $query->add_from(array(
                        'join' => 'LEFT JOIN',
                        'table' => $moduleclass->get_module_table(),
                        'on' => "(course_modules.instance = ".$moduleclass->get_module_table().".id
                                  AND course_modules.module = '".$moduleclass->get_module_id()."')"
                    ));
                    $namecoalesce[$moduleclass->get_module_table()] = 'name';
                    $introcoalesce[$moduleclass->get_module_table()] = 'intro';
                }
                $query->add_select(array(
                        'table'    => 'course_modules',
                        'column'   => 'id',
                        'alias'    => 'coursemoduleid'));
                $query->add_select(array(
                        'table'    => $namecoalesce,
                        'function' => 'COALESCE',
                        'column'   => 'name',
                        'alias'    => 'name'));
                $query->add_select(array(
                        'table'    => $introcoalesce,
                        'function' => 'COALESCE',
                        'column'   => 'intro',
                        'alias'    => 'tooltip'));

                // We need the config settings too, if there are any
                $query->add_from(array(
                        'join' => 'LEFT JOIN',
                        'table' =>'block_ajax_marking',
                        'alias' => 'settings',
                        'on' => "settings.instanceid = course_modules.id
                                 AND settings.tablename = 'course_modules'
                                 AND settings.userid = :settingsuserid"
                ));
                $query->add_param('settingsuserid', $USER->id);
                $query->add_select(array(
                    'table'    => 'settings',
                    'column'   => 'display'));
                $query->add_select(array(
                    'table'    => 'settings',
                    'column'   => 'groupsdisplay'));
                $query->add_select(array(
                    'table'    => 'settings',
                    'column'   => 'id',
                    'alias'    => 'settingsid'));

                break;

        }

    }

    /**
     * In order to display the right things, we need to work out the visibility of each group for each course module.
     * This subquery lists all submodules once for each coursemodule in the user's courses, along with it's most
     * relevant show/hide setting, i.e. a coursemodule level override if it's there, other wise a course level
     * setting, or if neither, the site default. This is potentially very expensive if there are hundreds of courses as
     * it's effectively a cartesian join between the groups and coursemodules tables, so we filter using the user's
     * courses. This may or may not impact on the query optimiser being able to cache the execution plan between users.
     *
     * @return array SQL and params
     */
    private function get_sql_group_visibility_subquery() {

        global $DB;

        // This make it work
//        return array('SELECT 1 as cmid', array());

        /**
         * We may need to reuse this subquery. Because it uses the user's teacher courses as a filter (less calculation
         * that way), we might have issues with the query optimiser not reusing the execution plan. Hopefully not.
         * This variable allows us to feed the same teacher courses in more than once because Moodle requires variables
         * with different names for different parts of the query - you cannot reuse one with the same name in more
         * than one place. The number of params must match the number of array items.
         */
        static $counter = 0;

        $courses = block_ajax_marking_get_my_teacher_courses();
        list($coursessql, $coursesparams) = $DB->get_in_or_equal(array_keys($courses),
                                                                 SQL_PARAMS_NAMED,
                                                                 "groups{$counter}courses");
        $counter++;

        // TODO use proper constant
        $sitedefault = 1; // configurable in future

        $groupdisplaysubquery = <<<SQL
        SELECT group_course_modules.id AS cmid,
               group_groups.id AS groupid,
               COALESCE(group_cmconfig_groups.display,
                        group_courseconfig_groups.display,
                        {$sitedefault}) AS display

          FROM {course_modules} group_course_modules
    INNER JOIN {groups} group_groups
            ON group_groups.courseid = group_course_modules.course

     LEFT JOIN {block_ajax_marking} group_cmconfig
            ON group_course_modules.id = group_cmconfig.instanceid
                AND group_cmconfig.tablename = 'course_modules'
     LEFT JOIN {block_ajax_marking_groups} group_cmconfig_groups
            ON group_cmconfig_groups.configid = group_cmconfig.id

     LEFT JOIN {block_ajax_marking} group_courseconfig
            ON group_courseconfig.instanceid = group_course_modules.course
                AND group_courseconfig.tablename = 'course'
     LEFT JOIN {block_ajax_marking_groups} group_courseconfig_groups
            ON group_courseconfig_groups.configid = group_courseconfig.id

         WHERE group_courseconfig_groups.groupid = group_cmconfig_groups.groupid
            OR group_courseconfig_groups.groupid IS NULL
            OR group_cmconfig_groups.groupid IS NULL
           AND group_course_modules.course {$coursessql}

SQL;
        //$debugquery = block_ajax_marking_debuggable_query($groupdisplaysubquery, $coursesparams);

        return array($groupdisplaysubquery, $coursesparams);


    }

    /**
     * We need to check whether the activity can be displayed (the user may have hidden it using the settings).
     * This sql can be dropped into a query so that it will get the right students. This will also
     * make sure that if only some groups are being displayed, the submission is by a user who
     * is in one of the displayed groups.
     *
     * @param block_ajax_marking_query_base $query a query object to apply these changes to
     * @return void
     */
    private static function apply_sql_display_settings($query) {

        global $DB;

        // Groups stuff - new version
//        $joincmconfig = " LEFT JOIN {block_ajax_marking} cmconfig
//                                  ON cmconfig.table = 'coursemodules'
//                                     AND cmconfig.instanceid = :coursemoduleid
//                           LEFT JOIN {block_ajax_marking_groups} cmconfiggroups
//                                  ON cmconfig.id = cmconfiggroups.configid ";

        // TODO am I actually using these joins?
        // Probably to check for display = 1

        $query->add_from(array('table' => 'block_ajax_marking',
                               'join' => 'LEFT JOIN',
                               'on' => "cmconfig.tablename = 'coursemodules'
                                        AND cmconfig.instanceid = moduleunion.coursemoduleid",
                               'alias' => 'cmconfig' ));
//        $query->add_from(array('table' => 'block_ajax_marking_groups',
//                               'join' => 'LEFT JOIN',
//                               'on' => "cmconfig.id = cmconfiggroups.configid",
//                               'alias' => 'cmconfiggroups' ));


//$joincourseconfig = " LEFT JOIN {block_ajax_marking} courseconfig
//                                 ON courseconfig.table = 'course'
//                                    AND courseconfig.instanceid = :courseid
//                          LEFT JOIN {block_ajax_marking_groups} courseconfiggroups
//                                 ON courseconfig.id = courseconfiggroups.configid ";

        $query->add_from(array('table' => 'block_ajax_marking',
                                       'join' => 'LEFT JOIN',
                                       'on' => "courseconfig.tablename = 'course'
                                               AND courseconfig.instanceid = moduleunion.course",
                                       'alias' => 'courseconfig' ));
//        $query->add_from(array('table' => 'block_ajax_marking_groups',
//                               'join' => 'LEFT JOIN',
//                               'on' => "ourseconfig.id = courseconfiggroups.configid",
//                               'alias' => 'courseconfiggroups' ));






        // We want to keep it so that when we select the MAX groupid from the above table, we don't get any of
        // the hidden groups we exclude below in the WHERE clause.
//        $query->add_where(array(
//                'type' => 'AND',
//                'condition' => 'leftjoinvisibilitysubquery.display = 1'));



        // Here, we filter out the users with no group memberships, where the users without group memberships have
        // been set to be hidden for this coursemodule.
        // Second bit (after OR) filters out those who have group memberships, but all of them are set to be hidden
        $sitedefaultnogroup = 1; // what to do with users who have no group membership?
        list($existsvisibilitysubquery, $existsparams) = self::get_sql_group_visibility_subquery();
        $query->add_params($existsparams);
        $hidden = <<<SQL
(
    ( NOT EXISTS (SELECT NULL
            FROM {groups_members} groups_members
      INNER JOIN {groups} groups
              ON groups_members.groupid = groups.id
           WHERE groups_members.userid = moduleunion.userid
             AND groups.courseid = moduleunion.course)

      AND ( COALESCE(cmconfig.showorphans, courseconfig.showorphans, {$sitedefaultnogroup}) = 1 ) )

    OR

    ( EXISTS (SELECT NULL
                FROM {groups_members} groups_members
          INNER JOIN {groups} groups
                  ON groups_members.groupid = groups.id
          INNER JOIN ({$existsvisibilitysubquery}) existsvisibilitysubquery
                  ON existsvisibilitysubquery.groupid = groups.id
               WHERE groups_members.userid = moduleunion.userid
                 AND existsvisibilitysubquery.cmid = moduleunion.coursemoduleid
                 AND groups.courseid = moduleunion.course
                 AND existsvisibilitysubquery.display = 1)
    )
)
SQL;
        $query->add_where(array('type' => 'AND',
                                'condition' => $hidden));























        // old stuff

        // User settings for individual activities
        $coursemodulescompare = $DB->sql_compare_text('settings_course_modules.tablename');
        $query->add_from(array(
                'join'  => 'LEFT JOIN',
                'table' => 'block_ajax_marking',
                'alias' => 'settings_course_modules',
                'on'    => "(course_modules.id = settings_course_modules.instanceid ".
                           "AND {$coursemodulescompare} = 'course_modules')"
        ));
        // User settings for courses (defaults in case of no activity settings)
        $coursecompare = $DB->sql_compare_text('settings_course.tablename');
        $query->add_from(array(
                'join'  => 'LEFT JOIN',
                'table' => 'block_ajax_marking',
                'alias' => 'settings_course',
                'on'    => "(course_modules.course = settings_course.instanceid ".
                           "AND {$coursecompare} = 'course')"
        ));
        // User settings for groups per course module. Will have a value if there is any groups
        // settings for this user and coursemodule
//        list ($groupuserspersetting, $groupsparams) = self::get_sql_groups_subquery();
//        list ($groupuserspersetting, $groupsparams) = self::get_sql_excluded_groups_subquery();
//        $query->add_params($groupsparams);
//        $query->add_from(array(
//                'join'  => 'LEFT JOIN',
//                'table' => $groupuserspersetting,
//                'subquery' => true,
//                'alias' => 'settings_course_modules_groups',
//                'on'    => "settings_course_modules.id = settings_course_modules_groups.configid".
//                           " AND settings_course_modules_groups.userid = moduleunion.userid"
//        ));

        // We have to get a group-id for each item, however, it may be in more than one group of none


        // Need to get the sql again to regenerate the params to a unique set of placeholders. Can't reuse params.
       // list ($excludedgroupssql, $excludedgroupsparams) = self::get_sql_excluded_groups_subquery();
       // $query->add_params($excludedgroupsparams);
//        $getgroupidsql = "SELECT MAX(groupmembers.groupid) AS groupid,
//                                 groupmembers.userid
//                            FROM {groups_members} groupmembers
//                      INNER JOIN {groups} groups
//                              ON groups.id = groups_members.groupid
//                           WHERE groupmembers.groupid NOT IN ($excludedgroupssql)
//                             AND groups.groupid = MAX(groupmembers.groupid)
//                        GROUP BY groupmembers.userid) groups";
//        $query->add_from(array(
//                'join'  => 'LEFT JOIN',
//                'table' => $getgroupidsql,
//                'subquery' => true,
//                'alias' => 'excluded_groups',
//                'on'    => "course_modules.id = excluded_groups.coursemoduleid".
//                           " AND moduleunion.groupid = excluded_groups.groupid"
//        ));


        // TODO this really goes in the groupid filter

        // This section will add the highest non-hidden groupid if there is one. We need a further test so that null
        // values are dealt with properly.

        // These are left joins because a user may not be in any groups.
//        $query->add_from(array(
//                        'join'  => 'LEFT JOIN',
//                        'table' => 'SELECT gm.groupid,
//                                           gm.userid,
//                                           g.courseid
//                                      FROM {groups_members} gm
//                                INNER JOIN {groups} g
//                                        ON g.id = gm.groupid',
//                        'subquery' => true,
//                        'alias' => 'combogroups',
//                        'on'    => "(combogroups.userid = moduleunion.userid
//                                     AND combogroups.courseid = moduleunion.course)"
//                ));
//        $query->add_where(array('type' => 'AND',
//                                'condition' => "combogroups.groupid NOT IN ($excludedgroupssql)"));

        /*

        SELECT MAX(groupmembers.groupid) AS groupid, groupmembers.userid
			  FROM mdl_groups_members groupmembers
			 WHERE groupmembers.groupid NOT IN (6)
		  GROUP BY groupmembers.userid) groups

        */

        // Hierarchy of displaying things, simplest first. Hopefully lazy evaluation will make it
        // quick.
        // - No display settings (default to show without any groups)
        // - settings_course_modules display is 1, settings_course_modules.groupsdisplay is 0.
        //   Overrides any course settings
        // - settings_course_modules display is 1, groupsdisplay is 1 and user is in OK group
        // - settings_course_modules display is null, settings_course.display is 1,
        //   settings_course.groupsdisplay is 0
        // - settings_course_modules display is null, settings_course.display is 1,
        //   settings_course.groupsdisplay is 1 and user is in OK group.
        //   Only used if there is no setting at course_module level, so overrides that hide stuff
        //   which is shown at course level work.
        // - settings_course_modules display is null, settings_course.display is 1,
        //   settings_course.groupsdisplay is 1 and user is in OK group.
        //   Only used if there is no setting at course_module level, so overrides that hide stuff
        //   which is shown at course level work.
        $query->add_where(array(
                'type' => 'AND',
                'condition' => "
            ( (settings_course_modules.display IS NULL
               AND settings_course.display IS NULL)

              OR

              settings_course_modules.display = 1

              OR

              (settings_course_modules.display IS NULL
               AND settings_course.display = 1)

            )")
        );


        // Get the most suitable groupid so we can use it to make group nodes. This is tricky if the user is in more
        // than one group. We can normally assume that complicated stuff like that will be sorted out using the
        // settings, but in case it isn't, we don't want the user's submission counted twice because they're in two
        // groups. We can't count twice anyway, as we'll have a duplicate submission id, which moodle hates.

    }

    /**
     * For any submission, we want to know what group to count it in. This gives us a list of groups that should NOT
     * be shown. We then choose the highest id of those that are left (hopefully the users will have made settings
     * sensibly so that there's only one
     *
     * @todo not used - temporary experiment
     * @return array
     */
    private function get_sql_excluded_groups_subquery() {
        global $USER, $DB;

        // TODO do the INNER JOINs here cause a problem if there is no record? Should they be left join too?
        $coursecompare = $DB->sql_compare_text('coursesettings.tablename');
        $coursemodulescompare = $DB->sql_compare_text('cmsettings.tablename');
        $courseoverridemodulescompare = $DB->sql_compare_text('courseoverridesettings.tablename');
        // TODO change this to use WHERE NOT EXISTS as correlated subquery
        $sql = "
            SELECT cmsettings.instanceid AS coursemoduleid,
                   cmgroupsetting.groupid
              FROM {block_ajax_marking} cmsettings
        INNER JOIN {block_ajax_marking_groups} cmgroupsetting
                ON cmgroupsetting.configid = cmsettings.id
             WHERE {$coursemodulescompare} = 'course_modules'
               AND cmgroupsetting.display = 0
               AND cmsettings.userid = :exgroupsettingsuserid1 ".

        // This is getting any groups hidden at course level, i.e. there are no coursemodule records
        // TODO change this to use WHERE NOT EXISTS as correlated subquery

                  "UNION

            SELECT cm.id AS coursemoduleid,
                   coursegroupsetting.groupid
              FROM {course_modules} cm
        INNER JOIN {block_ajax_marking} coursesettings
                ON cm.course = coursesettings.instanceid
        INNER JOIN {block_ajax_marking_groups} coursegroupsetting
                ON coursegroupsetting.configid = coursesettings.id
         LEFT JOIN (SELECT courseoverridesettings.instanceid AS cmid,
                           courseoverridegroup.groupid
                      FROM {block_ajax_marking} courseoverridesettings
                INNER JOIN {block_ajax_marking_groups} courseoverridegroup
                        ON courseoverridegroup.configid = courseoverridesettings.id
                     WHERE {$courseoverridemodulescompare} = 'course_modules' ) overrides
                ON (cm.id = overrides.cmid
                    AND overrides.groupid = coursegroupsetting.groupid)
               AND {$coursecompare} = 'course'
               AND coursegroupsetting.display = 0
               AND overrides.display IS NULL
               AND coursesettings.userid = :exgroupsettingsuserid2
         ";
        // Adding userid to reduce the results set so that the SQL can be more efficient
        $params = array('exgroupsettingsuserid1' => $USER->id,
                        'exgroupsettingsuserid2' => $USER->id);

        return array($sql, $params);

    }


    /**
     * All modules have a common need to hide work which has been submitted to items that are now
     * hidden. Not sure if this is relevant so much, but it's worth doing so that test data and test
     * courses don't appear. General approach is to use cached context info from user session to
     * find a small list of contexts that a teacher cannot grade in within the courses where they
     * normally can, then do a NOT IN thing with it. Also the obvious visible = 1 stuff.
     *
     * @param block_ajax_marking_query_base $query
     * @param string $coursemodulejoin What table.column to join to course_modules.id
     * @param bool $includehidden Do we want to have hidden coursemodules included? Config = yes
     * @return array The join string, where string and params array. Note, where starts with 'AND'
     */
    private static function apply_sql_visible(block_ajax_marking_query_base $query,
                                              $coursemodulejoin = '', $includehidden = false) {
        global $DB;

        if ($coursemodulejoin) { // only needed if the table is not already there
            $query->add_from(array(
                    'join' => 'INNER JOIN',
                    'table' => 'course_modules',
                    'on' => 'course_modules.id = '.$coursemodulejoin
            ));
        }
        $query->add_from(array(
                'join' => 'INNER JOIN',
                'table' => 'course',
                'on' => 'course.id = course_modules.course'
        ));

        // Get coursemoduleids for all items of this type in all courses as one query. Won't come
        // back empty or else we would not have gotten this far
        $courses = block_ajax_marking_get_my_teacher_courses();
        // TODO Note that change to login as... in another tab may break this. Needs testing.
        list($coursesql, $params) = $DB->get_in_or_equal(array_keys($courses), SQL_PARAMS_NAMED);
        // Get all coursemodules the current user could potentially access.
        $sql = "SELECT id
                  FROM {course_modules}
                 WHERE course {$coursesql}";
        // no point caching - only one request per module per page request:
        $coursemoduleids = $DB->get_records_sql($sql, $params);

        // Get all contexts (will cache them). This is expensive and hopefully has been cached in
        // the session already, so we take advantage of it.
        /**
         * @var array $contexts PHPDoc needs updating for get_context_instance()
         */
        $contexts = get_context_instance(CONTEXT_MODULE, array_keys($coursemoduleids));
        // Use has_capability to loop through them finding out which are blocked. Unset all that we
        // have permission to grade, leaving just those we are not allowed (smaller list). Hopefully
        // this will never exceed 1000 (oracle hard limit on number of IN values).
        $mods = block_ajax_marking_get_module_classes();
        $modids = array();
        foreach ($mods as $mod) {
            $modids[] = $mod->get_module_id(); // Save these for later
            foreach ($contexts as $key => $context) {
                // If we don't find any capabilities for a context, it will remain and be excluded
                // from the SQL. Hopefully this will be a small list.
                if (has_capability($mod->get_capability(), $context)) { // this is cached, so fast
                    unset($contexts[$key]);
                }
            }
        }
        // return a get_in_or_equals with NOT IN if there are any, or empty strings if there aren't.
        if (!empty($contexts)) {
            list($contextssql, $contextsparams) = $DB->get_in_or_equal(array_keys($contexts),
                                                                       SQL_PARAMS_NAMED,
                                                                       'context0000',
                                                                       false);
            $query->add_where(array('type' => 'AND',
                                    'condition' => "course_modules.id {$contextssql}"));
            $query->add_params($contextsparams);
        }

        // Only show enabled mods
        list($visiblesql, $visibleparams) = $DB->get_in_or_equal($modids, SQL_PARAMS_NAMED,
                                                                 'visible000');
        $query->add_where(array(
                'type'      => 'AND',
                'condition' => "course_modules.module {$visiblesql}"));
        // We want the coursmeodules that are hidden to be gone form the main trees. For config,
        // We may want to show them greyed out so that settings can be sorted before they are shown
        // to students.
        if (!$includehidden) {
            $query->add_where(array('type' => 'AND', 'condition' => 'course_modules.visible = 1'));
        }
        $query->add_where(array('type' => 'AND', 'condition' => 'course.visible = 1'));

        $query->add_params($visibleparams);

    }

    /**
     * Makes sure we only get stuff for the courses this user is a teacher in
     *
     * @param block_ajax_marking_query_base $query
     * @param string $coursecolumn
     * @return void
     */
    private static function apply_sql_owncourses(block_ajax_marking_query_base $query,
                                                 $coursecolumn = '') {

        global $DB;

        $courses = block_ajax_marking_get_my_teacher_courses();

        $courseids = array_keys($courses);

        if ($courseids) {
            list($sql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED,
                                                       'courseid0000');

            $query->add_where(array(
                    'type' => 'AND',
                    'condition' => $coursecolumn.' '.$sql));
            $query->add_params($params);
        }
    }

    /**
     * Returns an SQL snippet that will tell us whether a student is directly enrolled in this
     * course
     *
     * @param block_ajax_marking_query_base $query
     * @param array $filters So we can filter by cohortid if we need to
     * @return array The join and where strings, with params. (Where starts with 'AND)
     */
    private static function apply_sql_enrolled_students(block_ajax_marking_query_base $query,
                                                        array $filters) {

        global $DB, $CFG, $USER;

        // Hide users added by plugins which are now disabled.
        if (isset($filters['cohortid']) || $filters['nextnodefilter'] == 'cohortid') {
            // We need to specify only people enrolled via a cohort
            $enabledsql = " = 'cohort'";
        } else if ($CFG->enrol_plugins_enabled) {
            // returns list of english names of enrolment plugins
            $plugins = explode(',', $CFG->enrol_plugins_enabled);
            list($enabledsql, $params) = $DB->get_in_or_equal($plugins,
                                                              SQL_PARAMS_NAMED,
                                                              'enrol001');
            $query->add_params($params);
        } else {
            // no enabled enrolment plugins
            $enabledsql = ' = :sqlenrollednever';
            $query->add_param('sqlenrollednever', -1);
        }

        $sql = "SELECT NULL
                  FROM {enrol} enrol
            INNER JOIN {user_enrolments} user_enrolments
                    ON user_enrolments.enrolid = enrol.id
                 WHERE enrol.enrol {$enabledsql}
                   AND enrol.courseid = moduleunion.course
                   AND user_enrolments.userid != :enrolcurrentuser
                   AND user_enrolments.userid = moduleunion.userid
        ";

        $query->add_where(array('type' => 'AND',
                                'condition' => "EXISTS ({$sql})"));
        $query->add_param('enrolcurrentuser', $USER->id, false);
    }

    /**
     * Provides a subquery with all users who are in groups that ought to be displayed, per config
     * setting e.g. which users are in displayed groups for items where groups display is
     * enabled or inherited as enabled. We use a SELECT 1 to see if the user of the submission is
     * there for the relevant config thing.
     *
     * @return array SQL fragment and params
     */
    private function get_sql_groups_subquery() {
        global $USER;

        static $count = 1; // If we reuse this, we cannot have the same names for the params

        // If a user is in two groups, this will lead to duplicates. We use DISTINCT in the
        // SELECT to prevent this. Possibly one group will say 'display' and the other will say
        // 'hide'. We assume display if it's there, using MAX to get any 1 that there is. Same concept applies
        // to the groupid. we can't count them twice, but hopefully the unnecessary duplicates for any activity
        // will be set to hidden. Default to highest id number for now.
        $groupsql = " SELECT DISTINCT gm.userid, groups_settings.configid,
                             MAX(groups_settings.display) AS display,
                             MAX(groups_settings.groupid) AS groupid
                        FROM {groups_members} gm
                  INNER JOIN {groups} g
                          ON gm.groupid = g.id
                  INNER JOIN {block_ajax_marking_groups} groups_settings
                          ON g.id = groups_settings.groupid
                  INNER JOIN {block_ajax_marking} settings
                          ON groups_settings.configid = settings.id
                       WHERE settings.groupsdisplay = 1
                         AND settings.userid = :groupsettingsuserid{$count}
                    GROUP BY gm.userid, groups_settings.configid";
        // Adding userid to reduce the results set so that the SQL can be more efficient
        $params = array('groupsettingsuserid'.$count => $USER->id);
        $count++;

        return array($groupsql, $params);

//        $newquery = "SELECT groups_members.groupid,
//                            groups_members.userid,
//                            course_modules.id
//                       FROM {groups_members} groups_members
//                 INNER JOIN {groups} groups
//                         ON groups.id = groups_members.groupid
//                 INNER JOIN {course_modules} course_modules
//                         ON course_modules.course = groups.courseid
//                  LEFT JOIN {block_ajax_marking_groups} groupsdisplay
//                         ON groupsdisplay.groupid = group.id
//
//        ";
    }

    /**
     * For the config nodes, we want all of the coursemodules. No need to worry about counting etc.
     * There is also no need for a dynamic rearrangement of the nodes, so we have two simple queries
     *
     * @param array $filters
     * @return array
     */
    public static function get_config_nodes($filters) {

        // The logic is that we need to filter the course modules because some of them will be
        // hidden or the user will not have access to them. Then we m,ay or may not group them by
        // course
        $configbasequery = new block_ajax_marking_query_base();
        $configbasequery->add_from(array('table' => 'course_modules'));

        // Now apply the filters.
        self::apply_sql_owncourses($configbasequery, 'course_modules.course');
        self::apply_sql_visible($configbasequery, '', true);

        // Now we either want the courses, grouped via DISTINCT, or the whole lot
        foreach ($filters as $name => $value) {

            if ($name == 'nextnodefilter') {
                $filterfunctionname = 'apply_'.$value.'_filter';
                // The new node filter is in the form 'nextnodefilter => 'functionname', rather
                // than 'filtername' => <rowid> We want to pass the name of the filter in with
                // an empty value, so we set the value here.
                $value = false;
                $operation = 'configdisplay';
            } else {
                $filterfunctionname = 'apply_'.$name.'_filter';
                $operation = 'configwhere';
            }

            // Find the function. Core ones are part of the factory class, others will be methods of
            // the module object.
            // If we are filtering by a specific module, look there first
            if (method_exists(__CLASS__, $filterfunctionname)) {
                // config tree needs to have select stuff that doesn't mention sub. Like for the
                // outer wrappers of the normal query for the unmarked work nodes
                self::$filterfunctionname($configbasequery, $operation, $value);
            }
        }

        // This is just for copying and pasting from the paused debugger into a DB GUI
        $debugquery = block_ajax_marking_debuggable_query($configbasequery);

        $nodes = $configbasequery->execute();

        $nodes = self::attach_groups_to_nodes($nodes, $filters);

        return $nodes;

    }

    /**
     * In order to set the groups display properly, we need to know what groups are available. This takes the nodes
     * we have and attaches the groups to them if there are any.
     *
     * @param array $nodes
     * @param array $filters
     * @return array
     */
    private function attach_groups_to_nodes($nodes, $filters) {

        global $DB, $USER;

        if (!$nodes) {
            return array();
        }

        // Need to get all groups for each node. Can't do this in the main query as there are
        // possibly multiple groups settings for each node. There is a limit to how many things we
        // can have in an SQL IN statement
        // Join to the config table and

        // Get the ids of the nodes
        $courseids = array();
        $coursemoduleids = array();
        foreach ($nodes as $node) {
            if (isset($node->courseid)) {
                $courseids[] = $node->courseid;
            }
            if (isset($node->coursemoduleid)) {
                $coursemoduleids[] = $node->coursemoduleid;
            }
        }

        if ($filters['nextnodefilter'] == 'courseid') {
            // Retrieve all groups that we may need. This includes those with no settings yet as
            // otherwise, we won't be able to offer to create settings for them. Only for courses
            list($coursesql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $sql = "SELECT groups.id, groups.courseid AS courseid,
                           groups.name, combinedsettings.display
                      FROM {groups} groups
                 LEFT JOIN (SELECT groupssettings.display, settings.instanceid AS courseid,
                                   groupssettings.groupid
                              FROM {block_ajax_marking} settings
                        INNER JOIN {block_ajax_marking_groups} groupssettings
                                ON groupssettings.configid = settings.id
                             WHERE settings.tablename = 'course'
                               AND settings.userid = :settingsuserid) combinedsettings
                        ON (combinedsettings.courseid = groups.courseid
                            AND combinedsettings.groupid = groups.id)
                   WHERE groups.courseid {$coursesql}
                        ";
            $params['settingsuserid'] = $USER->id;

            $debugquery = block_ajax_marking_debuggable_query($sql, $params);
            $groups = $DB->get_records_sql($sql, $params);

            foreach ($groups as $group) {
                if (!isset($nodes[$group->courseid]->groups)) {
                    $nodes[$group->courseid]->groups = array();
                }
                $nodes[$group->courseid]->groups[] = $group;
            }

        } else if ($filters['nextnodefilter'] == 'coursemoduleid' && $coursemoduleids) {
            // Here, we just want data o override the course stuff if necessary
            list($cmsql, $params) = $DB->get_in_or_equal($coursemoduleids, SQL_PARAMS_NAMED);
            $sql = "SELECT groups.id, settings.instanceid AS coursemoduleid,
                           groups.name, groupssettings.display
                      FROM {groups} groups
                INNER JOIN {block_ajax_marking_groups} groupssettings
                        ON groupssettings.groupid = groups.id
                INNER JOIN {block_ajax_marking} settings
                        ON settings.id = groupssettings.configid
                     WHERE settings.tablename = 'course_modules'
                       AND settings.userid = :settingsuserid
                      AND groups.courseid = :settingscourseid
                      AND settings.instanceid {$cmsql}
                        ";
            $params['settingscourseid'] = $filters['courseid'];
            $params['settingsuserid'] = $USER->id;

            $debugquery = block_ajax_marking_debuggable_query($sql, $params);
            $groups = $DB->get_records_sql($sql, $params);

            foreach ($groups as $group) {
                if (!isset($nodes[$group->coursemoduleid]->groups)) {
                    $nodes[$group->coursemoduleid]->groups = array();
                }
                $nodes[$group->coursemoduleid]->groups[] = $group;
            }
        }

        return $nodes;

    }

    /**
     * Config nodes need some stuff to be returned from the config tables so we can have settings
     * adjusted based on existing values.
     *
     * @param block_ajax_marking_query_base $query
     * @param $operation
     * @return void
     */
    private static function apply_config_filter(block_ajax_marking_query_base $query, $operation) {

        switch ($operation) {

            case 'where':
                break;

            case 'countselect':
                break;

            case 'configselect':

                // Join to config tables so we can have the settings sent along with the nodes when relevant
                // We need to join to the correct table: course or course_modules
                $table = '';
                if ($query->has_join_table('course_modules')) {
                    $table = 'course_modules';
                } else if ($query->has_join_table('course')) {
                    $table = 'course';
                }
                if (!$table) {
                    return;
                }

                $query->add_from(array(
                                     'join' => 'LEFT JOIN',
                                     'table' => 'block_ajax_marking',
                                     'alias' => 'config',
                                     'on' => "config.instanceid = {$table}.id AND
                                              config.tablename = '{$table}'"
                                 ));

                // Get display setting
                $query->add_select(array(
                                       'table' =>'config',
                                       'column' => 'display'
                                   ));
                $query->add_select(array(
                                       'table' => 'config',
                                       'column' => 'groupsdisplay'
                                   ));

                // Get groups display setting

                // Get JSON of current groups settings?
                // - what groups could have settings
                // - what groups actually have settings
                break;

            case 'displayselect':
                break;
        }

    }

    /**
     * Returns an SQL fragment that checks whether a group membership exists in the form EXISTS (<sql here>).
     * Needs to be told what userid
     *
     * @return string
     */
    private function get_sql_group_membership_exists() {
        $checkmemberships = <<<SQL
EXISTS (SELECT NULL
          FROM {groups_members} groups_members
    INNER JOIN {groups} groups
            ON groups_members.groupid = groups.id
         WHERE groups_members.userid = :userid
           AND groups.id = :groupid)
SQL;
        return $checkmemberships;
    }

    /**
     * Once we have filtered out the ones we don't want based on display settings, those that are left may have
     * memberships in more than one group. We want to choose one of these so that the piece of work is not
     * counted twice. This query returns the maximum (in terms of DB row id) groupid for each student/coursemodule
     * pair where coursemodules are in the courses that the user teaches. This has the potential to be expensive, so
     * hopefully the inner join will be used by the optimiser to limit the rows that are actually calculated to the ones
     * that the external query needs.
     *
     * @return array sql and params
     */
    private function get_sql_max_groupid_subquery() {

        list($visibilitysubquery, $params) = self::get_sql_group_visibility_subquery();

        $maxgroupsql = <<<SQL
         SELECT members.userid,
                MAX(displaytable.groupid) AS groupid,
                displaytable.cmid
           FROM ({$visibilitysubquery}) AS displaytable
     INNER JOIN mdl_groups_members members
             ON members.groupid = displaytable.groupid
       GROUP BY members.userid,
                displaytable.cmid
SQL;
        return array($maxgroupsql, $params);

    }

}
