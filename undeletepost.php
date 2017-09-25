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
$url = new moodle_url('/mod/oublog/undeletepost.php',
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

// Mark the post as undeleted.
oublog_do_undelete($course, $cm, $oublog, $post);
redirect($viewurl);
    
echo $OUTPUT->footer();

function oublog_do_undelete($course, $cm, $oublog, $post) {
    global $DB, $USER;
    $updatepost = (object)array(
            'id' => $post->id,
            'deletedby' => null,
            'timedeleted' => null
    );

    $transaction = $DB->start_delegated_transaction();
    $DB->update_record('oublog_posts', $updatepost);
    if (!oublog_update_item_tags($post->oubloginstancesid, $post->id,
            array(), $post->visibility)) {
        print_error('tagupdatefailed', 'oublog');
    }
    if (oublog_search_installed()) {
        $doc = oublog_get_search_document($updatepost, $cm);
        $doc->delete();
    }
    // Inform completion system, if available.
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm) && ($oublog->completionposts)) {
        $completion->update_state($cm, COMPLETION_INCOMPLETE, $post->userid);
    }
    $transaction->allow_commit();

    // Log post deleted event.
    $context = context_module::instance($cm->id);
    $params = array(
        'context' => $context,
        'objectid' => $post->id,
        'other' => array(
            'oublogid' => $oublog->id
        )
    );

    $event = \mod_oublog\event\post_undeleted::create($params);
    $event->trigger();
}

