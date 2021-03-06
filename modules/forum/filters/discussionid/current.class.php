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
 * @package    block
 * @subpackage ajax_marking
 * @copyright  2012 Matt Gibson
 * @author     Matt Gibson {@link http://moodle.org/user/view.php?id=81450}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot.'/blocks/ajax_marking/filters/current_base.class.php');

/**
 * Deals with SQL wrapper stuff for the discussion nodes.
 */
class block_ajax_marking_forum_filter_discussionid_current extends block_ajax_marking_filter_current_base {

    /**
     * Adds SQL to construct a set of discussion nodes.
     *
     * @static
     * @param block_ajax_marking_query $query
     */
    protected function alter_query(block_ajax_marking_query $query) {

        // This will be derived form the coursemodule id, but how to get it cleanly?
        // The query will know, but not easy to get it out. Might have been prefixed.
        // TODO pass this properly somehow.
        $coursemoduleid = required_param('coursemoduleid', PARAM_INT);
        // Normal forum needs discussion title as label, participant usernames as
        // description eachuser needs username as title and discussion subject as
        // description.
        if (block_ajax_marking_forum::forum_is_eachuser($coursemoduleid)) {
            $query->add_select(array(
                                    'table' => 'firstpost',
                                    'column' => 'subject',
                                    'alias' => 'description'
                               ));
        } else {
            $query->add_select(array(
                                    'table' => 'firstpost',
                                    'column' => 'subject',
                                    'alias' => 'label'
                               ));
            // TODO need a SELECT bit to get all userids of people in the discussion
            // instead.
            $query->add_select(array(
                                    'table' => 'firstpost',
                                    'column' => 'message',
                                    'alias' => 'tooltip'
                               ));
        }

        $query->add_from(array(
                              'join' => 'INNER JOIN',
                              'table' => 'forum_discussions',
                              'alias' => 'outerdiscussions',
                              'on' => 'countwrapperquery.id = outerdiscussions.id'
                         ));

        $query->add_from(array(
                              'join' => 'INNER JOIN',
                              'table' => 'forum_posts',
                              'alias' => 'firstpost',
                              'on' => 'firstpost.id = outerdiscussions.firstpost'
                         ));

        $query->add_orderby("timestamp ASC");
    }
}
