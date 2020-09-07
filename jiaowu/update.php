<?php

require('../../config.php');
require_once("$CFG->libdir/formslib.php");

$instanceid = required_param('instanceid',PARAM_INT);
$type= optional_param('type',1,PARAM_INT);

$instance = $DB->get_record('enrol', array('enrol' => 'jiaowu', 'id' => $instanceid), '*', MUST_EXIST);

if ($type == 1) {
    require_once('lib.php');
    $plugin = enrol_get_plugin('jiaowu');
    $courseinfo = $DB->get_record('enrol_jiaowu',['enrolid' => $instanceid],'courseid');
    $userData = $plugin->get_jiaowu_users($courseinfo->courseid);
    $plugin->auto_enrol_jw($instance->id,$userData);
    redirect(new moodle_url('/enrol/instances.php?id='.$instance->courseid),get_string('syn_success','enrol_jiaowu'));
}

$course = $DB->get_record('course', array('id'=> $instance->courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id, MUST_EXIST);


require_login($course);

$PAGE->set_pagelayout('admin');
$PAGE->set_url('/enrol/jiaowu/update.php', array('instanceid'=>$instance->id,'type'=>2));
$PAGE->set_title(get_string('error_title','enrol_jiaowu'));
$PAGE->set_heading($course->fullname);

    echo $OUTPUT->header();
    echo $OUTPUT->heading($instance->name. get_string('error_title','enrol_jiaowu'));

$table = new html_table();

$headers = [];
$headers[] = get_string('stuid','enrol_jiaowu');
$headers[] = get_string('studept','enrol_jiaowu');
$headers[] = get_string('role','enrol_jiaowu');
$headers[] = get_string('error_reason','enrol_jiaowu');

$table->head = $headers;
$table->data  = array();

$errorlist = $DB->get_record('enrol_jiaowu',['enrolid'=>$instanceid],'collegename,data');


if ($errorlist) {
    $errors = json_decode($errorlist->data,true);
    foreach ($errors['error'] as $k => $v) {
        $role = $DB->get_record('role', array('id' => $v['roleid']));
        $rolename = role_get_name($role);
        $table->data[] = [$v['user'],$errorlist->collegename,$rolename,get_string('jiaowu_error_'.$v['error'],'enrol_jiaowu')];
    }
}


echo '<form action="handle_fail.php?instanceid=$instanceid" method="post">';

echo '<br /><div class="buttons"><div class="form-inline float-right">';

echo html_writer::start_tag('div', array('class' => 'btn-group','style' => 'padding:10px'));

echo '</div></div></div>';

echo html_writer::table($table);

echo '</form>';

echo $OUTPUT->footer();