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
 * These classes provide filters that modify the dynamic query that fetches the nodes. Depending on
 * what node is being requested and what that node's ancestor nodes are, a different combination
 * of filters will be applied. There is one class per type of node, and one method with the class
 * for the type of operation. If there is a courseid node as an ancestor, we want to use the
 * courseid::where_filter, but if we are asking for courseid nodes, we want the
 * courseid::count_select filter.
 *
 * @package    block
 * @subpackage ajax_marking
 * @copyright  2012 Matt Gibson
 * @author     Matt Gibson {@link http://moodle.org/user/view.php?id=81450}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot.'/blocks/ajax_marking/lib.php'); // For getting teacher courses.
require_once($CFG->dirroot.'/blocks/ajax_marking/filters/current_base.class.php');
require_once($CFG->dirroot.'/blocks/ajax_marking/filters/coursemoduleid/current_config.class.php');

/**
 * Holds the filters to group the coursemodule node together. This is complex because the joins needed
 * to get the module details have to be constructed dynamically. See superclass for details.
 */
class block_ajax_marking_filter_coursemoduleid_current
    extends block_ajax_marking_filter_coursemoduleid_current_config {

    /**
     * Makes SQL for the text labels for the course nodes.
     *
     * @static
     * @param block_ajax_marking_query $query
     */
    protected function alter_query(block_ajax_marking_query $query) {

        // Same order as the super query will need them. Prefixed so we will have it as the
        // first column for the GROUP BY.

        $query->add_from(array(
                              'join' => 'INNER JOIN',
                              'table' => 'course_modules',
                              'on' => 'course_modules.id = countwrapperquery.id'));
        $query->add_select(array(
                                'table' => 'course_modules',
                                'column' => 'id',
                                'alias' => 'coursemoduleid'));
        // The javascript needs this for styling.
        $query->add_select(array(
                                'table' => 'countwrapperquery',
                                'column' => 'modulename'));

        // This will add the stuff that joins to the various module tables and gets the right names.
        $moduleclasses = block_ajax_marking_get_module_classes();
        $introcoalesce = array();
        $namecoalesce = array();
        $orderbycoalesce = array();
        foreach ($moduleclasses as $moduleclass) {
            $moduletablename = $moduleclass->get_module_name();
            $query->add_from(array(
                                  'join' => 'LEFT JOIN',
                                  'table' => $moduletablename,
                                  // Ids of modules will be constant for one install, so we
                                  // don't need to worry about parameterising them for
                                  // query caching.
                                  'on' => "(course_modules.instance = ".$moduletablename.".id
                                            AND course_modules.module = '".$moduleclass->get_module_id()."')"
                             ));
            $namecoalesce[$moduletablename] = 'name';
            $introcoalesce[$moduletablename] = 'intro';
            $orderbycoalesce[$moduletablename] = $moduletablename.'.name';
        }
        $query->add_select(array(
                                'table' => 'course_modules',
                                'column' => 'id',
                                'alias' => 'coursemoduleid'));
        $query->add_select(array(
                                'table' => $namecoalesce,
                                'function' => 'COALESCE',
                                'column' => 'name',
                                'alias' => 'name'));
        $query->add_select(array(
                                'table' => $introcoalesce,
                                'function' => 'COALESCE',
                                'column' => 'intro',
                                'alias' => 'tooltip'));

        $query->add_orderby('COALESCE('.implode(', ', $orderbycoalesce).') ASC');
        // This will add the stuff that will show us the name of the actual module instance.
        // We use the same stuff for both config and marking trees, but the config tree doesn't need
        // the stuff to pull through submission counts.
        // TODO separate counts.

        // This allows us to have separate decorators, but may obfuscate what's happening a bit.
        // Code is not duplicated, though.
    }


}


