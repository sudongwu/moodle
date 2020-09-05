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
    redirect(new moodle_url('/enrol/instances.php?id='.$instance->courseid),'更新成功');
}

$course = $DB->get_record('course', array('id'=> $instance->courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id, MUST_EXIST);


require_login($course);

$PAGE->set_pagelayout('admin');
$PAGE->set_url('/enrol/jiaowu/update.php', array('instanceid'=>$instance->id,'type'=>2));
$PAGE->set_title('异常列表');
$PAGE->set_heading($course->fullname);

    echo $OUTPUT->header();
    echo $OUTPUT->heading($instance->name. '同步异常列表');

$table = new html_table();

$headers = [];

//$mastercheckbox = new \core\output\checkbox_toggleall('participants-table', true, [
//    'id' => 'select-all-participants',
////    'name' => 'xuanze1',
//    'label' => false ? get_string('deselectall') : get_string('selectall'),
//    'labelclasses' => 'sr-only',
//    'classes' => 'm-1',
//    'checked' => false
//]);
//$headers[] = $OUTPUT->render($mastercheckbox);
$headers[] = '学号';
$headers[] = '姓名';
$headers[] = '电子邮箱地址';
$headers[] = '系别';
$headers[] = '角色';
$headers[] = '异常原因';

$table->head = $headers;
//$table->align = array('left', 'center', 'center', 'center','center','center');
$table->data  = array();

$errorlist = $DB->get_record('enrol_jiaowu',['enrolid'=>$instanceid],'collegename,data');


if ($errorlist) {
    $errors = json_decode($errorlist->data,true);
    foreach ($errors['error'] as $k => $v) {
//        $checkbox = new \core\output\checkbox_toggleall('participants-table', false, [
//            'classes' => 'usercheckbox m-1',
//            'id' => 'user-' . $v['user'],
//            'name' => 'xuanze[]',
//            'value' => $v['user'],
//        ]);
//        $dd = $OUTPUT->render($checkbox);
        $role = $DB->get_record('role', array('id' => $v['roleid']));
        $rolename = role_get_name($role);
        $table->data[] = [$v['user'],$v['user'],'',$errorlist->collegename,$rolename,get_string('jiaowu_error_'.$v['error'],'enrol_jiaowu')];
    }
}


echo '<form action="handle_fail.php?instanceid=$instanceid" method="post">';

echo '<br /><div class="buttons"><div class="form-inline float-right">';

echo html_writer::start_tag('div', array('class' => 'btn-group','style' => 'padding:10px'));

//echo html_writer::empty_tag('input', array('type' => 'submit', 'class' => 'btn btn-secondary',
//    'value' => '重新执行选课'));
//
//echo '&nbsp';
//echo html_writer::empty_tag('input', array('type' => 'submit', 'class' => 'btn btn-secondary',
//    'value' => '批量删除选课'));

echo '</div></div></div>';

echo html_writer::table($table);

echo '</form>';

//echo html_writer::script("console.log('12312')");

echo $OUTPUT->footer();