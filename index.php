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
 * For a given capability, show what permission it has for every role, and
 * everywhere that it is overridden.
 *
 * @package    tool
 * @subpackage assignroles
 * @copyright  2013 Steve Bond
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/datalib.php');
require_once($CFG->libdir.'/enrollib.php');
require_once('locallib.php');
require_once($CFG->dirroot.'/user/filters/lib.php');
require_once("$CFG->dirroot/enrol/locallib.php");

// Check permissions
require_login();
$systemcontext = context_system::instance();
$site = get_site();
require_capability('moodle/role:assign', $systemcontext);

$userid       = optional_param('userid',     0, PARAM_INT);  // $_GET['userid'];
$roleid       = optional_param('roleid',     3, PARAM_INT);  // $_GET['roleid'];  Default = teacher/editor
$add          = optional_param('add',    FALSE, PARAM_BOOL); // $_POST['add'];    Adding role
$remove       = optional_param('remove', FALSE, PARAM_BOOL); // $_POST['remove']; Removing role
    
// These are for user queries
$sort         = optional_param('sort',    'name', PARAM_ALPHA);
$dir          = optional_param('dir',     ' ASC', PARAM_ALPHA);
$page         = optional_param('page',         0, PARAM_INT);
$perpage      = optional_param('perpage',     30, PARAM_INT);   // how many per page

if (empty($CFG->loginhttps)) {
    $securewwwroot = $CFG->wwwroot;
} else {
    $securewwwroot = str_replace('http:','https:',$CFG->wwwroot);
}

$moodlebaseurl = '/admin/tool/assignroles/index.php';

$returnurl = new moodle_url($moodlebaseurl, array('sort' => $sort, 'dir' => $dir, 'perpage' => $perpage, 'page'=>$page));

$strconfirm = get_string('confirm');

// Print the header and page heading
admin_externalpage_setup('toolassignroles');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('assignroles', 'role'));
 
// Has a user id been passed?
if ($userid) {
    // Yes - get the current user's full record and output their name
    $user = $DB->get_record('user', array('id'=>$userid));
    $fullname = fullname($user, true);
    echo $OUTPUT->heading($fullname);

    // Get a list of roles that can be assigned at course level.
    $assignableroleids = get_roles_for_contextlevels(CONTEXT_COURSE);
    $assignableroles = array();
    foreach ($assignableroleids as $assignableroleid) {
        $role = $DB->get_record('role', array('id'=>$assignableroleid));
        $assignableroles[$assignableroleid] = $role;
    }
    $assignablerolenames = role_fix_names($assignableroles, $systemcontext, ROLENAME_ORIGINAL, true);

    // Check if this is an add or remove request and do it
    if (($form = data_submitted()) && confirm_sesskey()) {
        $errors = array();

        if ($add) {
            foreach ($form->addselect as $cid) {

                $cid = clean_param($cid, PARAM_INT);
                
                // Enrol user using function from enrollib.php - this will be a
                // manual enrolment. I should probably use the enrolment manager
                // object here like I do for removing roles, but this seems to work
                // so let's leave it.
                if (!enrol_try_internal_enrol($cid, $userid, $roleid, time())) {
                    $errstr = new stdClass();
                    $errstr->name = $fullname;
                    $errstr->role = $assignablerolenames[$roleid];
                    $errstr->cid = $cid;
                    $errors[] = get_string('couldntenrol','tool_assignroles', $errstr);
                }
                
            }
            
            if (count($errors)) {
                foreach ($errors as $err) {
                    print_error($err);
                }
            }
            
        } elseif ($remove) {

            foreach ($form->removeselect as $cid) {
                $cid = clean_param($cid, PARAM_INT);
                // Create a enrolment manager object for this course and use it to
                // unassign the chosen role
                $manager = new course_enrolment_manager($PAGE, get_course($cid));
                $manager->unassign_role_from_user($userid, $roleid);
                // Check if any roles remain, and unenrol the user if not
                $rolesremaining = $manager->get_user_roles($userid);
                if (count($rolesremaining) == 0) {
                    $enrolments = $manager->get_user_enrolments($userid);
                    // Check all manual and self enrolment methods for this course (cohort,
                    // meta, category and system should not be handled by this tool)
                    foreach ($enrolments as $enrolment) {
                        if ($enrolment->enrolmentinstance->enrol == 'manual'
                                or $enrolment->enrolmentinstance->enrol == 'self') {
                            $manager->unenrol_user($enrolment);
                        }
                    }
                }
            }
        }
    }

    /* Render the form for adding/removing roles
     * 
     */
    
    // Render 'role to assign' box
    $formurl = new moodle_url($moodlebaseurl, array('userid' => $userid));
    echo '<div style="text-align:center">';
    $selectbox = new single_select($formurl, 'roleid', $assignablerolenames, $roleid,
           array('0'=>get_string('listallroles', 'role').'...'), 'selectrole');
    $selectbox->label = get_string('roletoadd','tool_assignroles');
    echo $OUTPUT->render($selectbox);
    echo '</div>';
      
    // Render lists of existing and potential courses

    // Get lists of course objects where $userid holds/does not hold $roleid
    $existingcourses = get_my_courses_by_role($userid, $roleid, 'c.fullname');
    $courses = get_not_my_courses_by_role($userid, $roleid, 'c.fullname');

    if (count($courses) == 0 && count($existingcourses) == 0) { 
        print_error(get_string('nocourses','tool_assignroles'));
    }

    echo $OUTPUT->box_start();
    echo '<form id="assignrole" action="" method="POST">
        <div>
        <input type="hidden" name="userid" value="' . $userid . '" />
        <input type="hidden" name="roleid" value="' . $roleid . '" />
        <input type="hidden" name="sesskey" value="' .sesskey().'" />
        <table summary="" style="margin-left:auto;margin-right:auto" border="0" cellpadding="5" cellspacing="0">
            <tr>
              <td valign="top">
              <label for="removeselect">' . count($existingcourses) . ' '
              . get_string('currentcourses','tool_assignroles') . '</label>
              <br />
              <select name="removeselect[]" size="40" id="removeselect" multiple="multiple"
                  onfocus="getElementById(\'assignrole\').add.disabled=true;
                   getElementById(\'assignrole\').remove.disabled=false;
                   getElementById(\'assignrole\').addselect.selectedIndex=-1;">';
 
    // Abbreviate long names and create menu items for already-assigned courses
    foreach ($existingcourses as $key => $ec) {
        $ec->title = $ec->fullname;
        if (strlen($ec->fullname) > 40) {
            $ec->fullname = substr($ec->fullname, 0, 40) . ' ...';
        }
        // Disable the option if it is a cohort enrolment
        if ($ec->component == 'enrol_cohort') {
            $disabled = 'disabled';
            $label = $ec->fullname . ' (Cohort)';
        } else {
            $disabled = '';
            $label = $ec->fullname;           
        }
        echo "<option value=\"$ec->id\" title=\"$ec->title\" $disabled>$label</option>";
    } 
    
    // In XHTML, select must contain at least one option element
    if (count($existingcourses == 0)) echo '<option />';

    // Submit values evaluate to true when cast as a boolean
    echo '</select></td>
          <td valign="top">
              <p class="arrow_button">
                  <input name="add" id="add" type="submit" 
                      value="&nbsp;' .$PAGE->theme->larrow. ' &nbsp; &nbsp; '
                      .get_string('add') . '" title="' . get_string('add') . '" />
                  <br />
                  <input name="remove" id="remove" type="submit" 
                      value="&nbsp;' .$PAGE->theme->rarrow.' &nbsp; &nbsp; '
                      .get_string('remove') .'" title="'. get_string('remove'). '" />
              </p>
          </td>
          <td valign="top">
                <label for="addselect">' . count($courses) . ' '
                . get_string('potentialcourses','tool_assignroles') . '</label>
                <br />        
                <select name="addselect[]" size="40" multiple="multiple" id="addselect"
                    onfocus="getElementById(\'assignrole\').add.disabled=false;
                    getElementById(\'assignrole\').remove.disabled=true;
                    getElementById(\'assignrole\').removeselect.selectedIndex=-1;">';

    // Abbreviate long names and create menu items for unassigned courses
    foreach ($courses as $c) {
        $c->title = $c->fullname;
        if (strlen($c->fullname) > 40) { 
            $c->fullname = substr($c->fullname, 0, 40) . ' ...'; 
        }
        echo "<option value=\"$c->id\" title=\"$c->title\">$c->fullname</option>";
    }

    if (count($courses == 0)) echo '<option />'; // XHTML compliance

    echo '</select></td></tr></table></div></form>'; 
    echo $OUTPUT->box_end();
    
} else {
    // No - render the user selection form (search and browse)

    // All the following code stolen from admin/user.php and adapted
    
    // create the user filter form
    $ufiltering = new user_filtering();
    
    // Carry on with the user listing
    $context = context_system::instance();
    $extracolumns = get_extra_user_fields($context);
    $columns = array_merge(array('firstname', 'lastname'), $extracolumns,
            array('city', 'country', 'lastaccess'));

    foreach ($columns as $column) {
        $string[$column] = get_user_field_name($column);
        if ($sort != $column) {
            $columnicon = "";
            if ($column == "lastaccess") {
                $columndir = "DESC";
            } else {
                $columndir = "ASC";
            }
        } else {
            $columndir = $dir == "ASC" ? "DESC":"ASC";
            if ($column == "lastaccess") {
                $columnicon = ($dir == "ASC") ? "sort_desc" : "sort_asc";
            } else {
                $columnicon = ($dir == "ASC") ? "sort_asc" : "sort_desc";
            }
            $columnicon = "<img class='iconsort' src=\"" . $OUTPUT->pix_url('t/' . $columnicon) . "\" alt=\"\" />";

        }
        $$column = "<a href=\"{$_SERVER['PHP_SELF']}?sort=$column&amp;dir=$columndir\">".$string[$column]."</a>$columnicon";
    }

    $override = new stdClass();
    $override->firstname = 'firstname';
    $override->lastname = 'lastname';
    $fullnamelanguage = get_string('fullnamedisplay', '', $override);
    if (($CFG->fullnamedisplay == 'firstname lastname') or
        ($CFG->fullnamedisplay == 'firstname') or
        ($CFG->fullnamedisplay == 'language' and $fullnamelanguage == 'firstname lastname' )) {
        $fullnamedisplay = "$firstname / $lastname";
        if ($sort == "name") { // If sort has already been set to something else then ignore.
            $sort = "firstname";
        }
    } else { // ($CFG->fullnamedisplay == 'language' and $fullnamelanguage == 'lastname firstname').
        $fullnamedisplay = "$lastname / $firstname";
        if ($sort == "name") { // This should give the desired sorting based on fullnamedisplay.
            $sort = "lastname";
        }
    }

    list($extrasql, $params) = $ufiltering->get_sql_filter();
    $users = get_users_listing($sort, $dir, $page*$perpage, $perpage, '', '', '',
            $extrasql, $params, $context);
    $usercount = get_users(false);
    $usersearchcount = get_users(false, '', false, null, "", '', '', '', '', '*', $extrasql, $params);

    if ($extrasql !== '') {
        echo $OUTPUT->heading("$usersearchcount / $usercount ".get_string('users'));
        $usercount = $usersearchcount;
    } else {
        echo $OUTPUT->heading("$usercount ".get_string('users'));
    }

    $strall = get_string('all');

    $baseurl = new moodle_url($moodlebaseurl, array('sort' => $sort, 'dir' => $dir, 'perpage' => $perpage));
    echo $OUTPUT->paging_bar($usercount, $page, $perpage, $baseurl);

    flush();


    if (!$users) {
        $match = array();
        echo $OUTPUT->heading(get_string('nousersfound'));

        $table = NULL;

    } else {

        $countries = get_string_manager()->get_list_of_countries(false);
        if (empty($mnethosts)) {
            $mnethosts = $DB->get_records('mnet_host', null, 'id', 'id,wwwroot,name');
        }

        foreach ($users as $key => $user) {
            if (isset($countries[$user->country])) {
                $users[$key]->country = $countries[$user->country];
            }
        }
        if ($sort == "country") {  // Need to resort by full country name, not code
            foreach ($users as $user) {
                $susers[$user->id] = $user->country;
            }
            asort($susers);
            foreach ($susers as $key => $value) {
                $nusers[] = $users[$key];
            }
            $users = $nusers;
        }

        $table = new html_table();
        $table->head = array ();
        $table->align = array();
        $table->head[] = $fullnamedisplay;
        $table->align[] = 'left';
        foreach ($extracolumns as $field) {
            $table->head[] = ${$field};
            $table->align[] = 'left';
        }
        $table->head[] = $city;
        $table->align[] = 'left';
        $table->head[] = $country;
        $table->align[] = 'left';
        $table->head[] = $lastaccess;
        $table->align[] = 'left';
        $table->head[] = get_string('edit');
        $table->align[] = 'center';
        $table->head[] = "";
        $table->align[] = 'center';

        $table->width = "95%";
        foreach ($users as $user) {
            if (isguestuser($user)) {
                continue; // do not display guest here
            }

            $buttons = array();
            $lastcolumn = '';

            // the last column - confirm or mnet info
            if (is_mnet_remote_user($user)) {
                // all mnet users are confirmed, let's print just the name of the host there
                if (isset($mnethosts[$user->mnethostid])) {
                    $lastcolumn = get_string($accessctrl, 'mnet').': '.$mnethosts[$user->mnethostid]->name;
                } else {
                    $lastcolumn = get_string($accessctrl, 'mnet');
                }

            } else if ($user->confirmed == 0) {
                if (has_capability('moodle/user:update', $systemcontext)) {
                    $lastcolumn = html_writer::link(new moodle_url($returnurl, array('confirmuser'=>$user->id, 'sesskey'=>sesskey())), $strconfirm);
                } else {
                    $lastcolumn = "<span class=\"dimmed_text\">".get_string('confirm')."</span>";
                }
            }

            if ($user->lastaccess) {
                $strlastaccess = format_time(time() - $user->lastaccess);
            } else {
                $strlastaccess = get_string('never');
            }
            $fullname = fullname($user, true);

            $row = array ();
            $row[] = "<a href=\"{$_SERVER['PHP_SELF']}?userid={$user->id}\">$fullname</a>";
            
            foreach ($extracolumns as $field) {
                $row[] = $user->{$field};
            }
            $row[] = $user->city;
            $row[] = $user->country;
            $row[] = $strlastaccess;
            if ($user->suspended) {
                foreach ($row as $k=>$v) {
                    $row[$k] = html_writer::tag('span', $v, array('class'=>'usersuspended'));
                }
            }
            $row[] = implode(' ', $buttons);
            $row[] = $lastcolumn;
            $table->data[] = $row;
        }
    }

    // add filters
    $ufiltering->display_add();
    $ufiltering->display_active();

    if (!empty($table)) {
        echo html_writer::table($table);
        echo $OUTPUT->paging_bar($usercount, $page, $perpage, $baseurl);
    }

}

echo $OUTPUT->footer();
