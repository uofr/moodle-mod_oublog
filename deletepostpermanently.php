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
 * This page allows a user to delete a blog posts
 *
 * @author Matt Clarkson <mattc@catalyst.net.nz>
 * @package oublog
 */
require_once("../../config.php");
require_once($CFG->dirroot . '/mod/oublog/locallib.php');
require_once($CFG->libdir . '/completionlib.php');

$blog = required_param('blog', PARAM_INT);         // Blog ID.
$postid = required_param('post', PARAM_INT);       // Post ID for editing.
$confirm = optional_param('confirm', 0, PARAM_INT);// Confirm that it is ok to delete post.
$delete = optional_param('delete', 0, PARAM_INT);
$email = optional_param('email', 0, PARAM_INT);    // Email author.
$referurl = optional_param('referurl', 0, PARAM_LOCALURL);

if (!$oublog = $DB->get_record("oublog", array("id"=>$blog))) {
    print_error('invalidblog', 'oublog');
}
if (!$post = oublog_get_post($postid, false)) {
    print_error('invalidpost', 'oublog');
}
if (!$cm = get_coursemodule_from_instance('oublog', $blog)) {
    print_error('invalidcoursemodule');
}
if (!$course = $DB->get_record("course", array("id"=>$oublog->course))) {
    print_error('coursemisconf');
}
$url = new moodle_url('/mod/oublog/deletepost.php',
        array('blog' => $blog, 'post' => $postid, 'confirm' => $confirm));
$PAGE->set_url($url);

// Check security.
$context = context_module::instance($cm->id);
oublog_check_view_permissions($oublog, $context, $cm);

$postauthor=$DB->get_field_sql("
SELECT
    i.userid
FROM
    {oublog_posts} p
    INNER JOIN {oublog_instances} i on p.oubloginstancesid=i.id
WHERE p.id = ?", array($postid));
if ($postauthor!=$USER->id) {
    require_capability('mod/oublog:manageposts', $context);
}

$oublogoutput = $PAGE->get_renderer('mod_oublog');

if ($oublog->global) {
    $blogtype = 'personal';
    $oubloguser = $USER;
    $viewurl = new moodle_url('/mod/oublog/view.php', array('user' => $postauthor));
    if (isset($referurl)) {
        $viewurl = new moodle_url($referurl);
    }
    // Print the header.
    $PAGE->navbar->add(fullname($oubloguser), new moodle_url('/user/view.php',
            array('id' => $oubloguser->id)));
    $PAGE->navbar->add(format_string($oublog->name));
} else {
    $blogtype = 'course';
    $viewurl = new moodle_url('/mod/oublog/view.php', array('id' => $cm->id));
    if (isset($referurl)) {
        $viewurl = new moodle_url($referurl);
    }
}


// delete the post permanently.
oublog_do_delete_permanently($course, $cm, $oublog, $post);
redirect($viewurl);

echo $OUTPUT->footer();

function oublog_do_delete_permanently($course, $cm, $oublog, $post) {
    global $DB, $USER;
   
   // Delete the post permanently.
    $DB->delete_records('oublog_posts', array('id'=>$post->id));

    // Log post deleted event.
    $context = context_module::instance($cm->id);
    $params = array(
        'context' => $context,
        'objectid' => $post->id,
        'other' => array(
            'oublogid' => $oublog->id
        )
    );
    $event = \mod_oublog\event\post_deleted::create($params);
    $event->trigger();
}

/**
 * Print a message along with three buttons buttoneone/buttontwo/Cancel
 *
 * If a string or moodle_url is given instead of a single_button, method defaults to post.
 *
 * @param string $message The question to ask the user.
 * @param single_button $buttonone The single_button component representing the buttontwo response.
 * @param single_button $buttontwo The single_button component representing the buttontwo response.
 * @param single_button $cancel The single_button component representing the Cancel response.
 * @return string HTML fragment
 */
function oublog_three_button($message, $buttonone, $buttontwo, $cancel) {
    global $OUTPUT;
    if (!($buttonone instanceof single_button)) {
        throw new coding_exception('The buttonone param must be an instance of a single_button.');
    }

    if (!($buttontwo instanceof single_button)) {
        throw new coding_exception('The buttontwo param must be an instance of a single_button.');
    }

    if (!($cancel instanceof single_button)) {
        throw new coding_exception('The cancel param must be an instance of a single_button.');
    }

    $output = $OUTPUT->box_start('generalbox', 'notice');
    $output .= html_writer::tag('p', $message);
    $buttons = $OUTPUT->render($buttonone) . $OUTPUT->render($buttontwo) . $OUTPUT->render($cancel);
    $output .= html_writer::tag('div', $buttons, array('class' => 'buttons'));
    $output .= $OUTPUT->box_end();
    return $output;
}
