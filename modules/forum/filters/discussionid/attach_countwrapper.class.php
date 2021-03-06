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

require_once($CFG->dirroot.'/blocks/ajax_marking/filters/attach_base.class.php');

/**
 * Deals with SQL wrapper stuff for the discussion nodes.
 */
class block_ajax_marking_forum_filter_discussionid_attach_countwrapper extends
    block_ajax_marking_filter_attach_base {

    /**
     * Adds SQL to construct a set of discussion nodes.
     *
     * @param block_ajax_marking_query $query
     * @return mixed|void
     */
    protected function alter_query(block_ajax_marking_query $query) {

        // We join like this because we can't put extra stuff into the UNION ALL bit
        // unless all modules have it and this is unique to forums.
        $query->add_from(array(
                              'table' => 'forum_posts',
                              'on' => 'moduleunion.subid = post.id',
                              'alias' => 'post')
        );
        $query->add_from(array(
                              'table' => 'forum_discussions',
                              'on' => 'discussion.id = post.discussion',
                              'alias' => 'discussion')
        );
        $query->add_select(array(
                                'table' => 'discussion',
                                'column' => 'id'), true
        );
    }
}
