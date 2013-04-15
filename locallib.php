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
 * Definition of classes used by assignroles tool
 *
 * @package    tool
 * @subpackage assignroles
 * @copyright  2013 Steve Bond <s.bond1@lse.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Provides various utilities to be used by the admin tool.
 * Note: Courses that count as "assigned" are those for which a role has been assigned.
 * I do not check for enrolment as well, because by default it is only the 'manager'
 * role that can be assigned without enrolment, and typically we aren't concerned
 * with that role here. In future, we might decide to add "moodle/course:view" to
 * other roles, allowing them to be assigned without enrolment (and thus allow "hidden
 * assignments"). If that happens, I may revisit this and enable such roles to be added
 * in bulk.
*/
 
function get_my_courses_by_role($userid, $roleid, $sortorder='c.sortorder',
    $fields='c.id, c.fullname, ra.contextid, ra.component', $contextlevel='50') {

    // Find all the courses for which a role is assigned to this user
    
    global $CFG, $DB;
    $myrolecourses = array();
    
    // Note here we are getting all enrolments that assign a role in the course context.
    // Normally this will be only manual, self and cohort.
    $rs = $DB->get_recordset_sql("
        SELECT $fields
        FROM {$CFG->prefix}role_assignments ra
        INNER JOIN {$CFG->prefix}context x ON x.id = ra.contextid
        INNER JOIN {$CFG->prefix}course c ON x.instanceid = c.id AND x.contextlevel = $contextlevel
        WHERE ra.roleid = $roleid AND ra.userid = $userid
        ORDER BY $sortorder");

    if ($rs && count($rs) > 0) {
        foreach ($rs as $course) {
            if ($course->id != SITEID) {
                $myrolecourses[$course->id] = $course;
            }
        }
    }
    
    $rs->close();
    
    return $myrolecourses;
}

function get_not_my_courses_by_role($userid, $roleid, $sortorder='c.sortorder',
    $fields='c.id, c.fullname, x.id AS contextid', $contextlevel='50') {
    
    // Find all the courses in which no role is assigned to this user
    
    global $CFG, $DB;
    $notmyrolecourses = array();
    
    $rs = $DB->get_recordset_sql("
        SELECT $fields
        FROM {$CFG->prefix}context x
        INNER JOIN {$CFG->prefix}course c ON x.instanceid = c.id AND x.contextlevel = $contextlevel
        WHERE x.instanceid NOT IN 
        (SELECT x.instanceid
        FROM {$CFG->prefix}role_assignments ra
        INNER JOIN {$CFG->prefix}context x ON x.id = ra.contextid AND x.contextlevel = $contextlevel
        WHERE ra.roleid = $roleid AND ra.userid = $userid)
        ORDER BY $sortorder");
    
    if ($rs && count($rs) > 0) {
        foreach ($rs as $course) {
            if ($course->id != SITEID) {
                $notmyrolecourses[$course->id] = $course;
            }
        }
    }
    
    $rs->close();
        
    return $notmyrolecourses;
}
?>
