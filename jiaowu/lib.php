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
 * Self enrolment plugin.
 *
 * @package    enrol_jiaowu
 * @copyright  2010 Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Self enrolment plugin implementation.
 * @author Petr Skoda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_jiaowu_plugin extends enrol_plugin
{

    protected $lasternoller = null;
    protected $lasternollerinstanceid = 0;

    /**
     * Returns optional enrolment information icons.
     *
     * This is used in course list for quick overview of enrolment options.
     *
     * We are not using single instance parameter because sometimes
     * we might want to prevent icon repetition when multiple instances
     * of one type exist. One instance may also produce several icons.
     *
     * @param array $instances all enrol instances of this type in one course
     * @return array of pix_icon
     */
    public function get_info_icons(array $instances)
    {
        $key = false;
        $nokey = false;
        foreach ($instances as $instance) {
            if ($this->can_self_enrol($instance, false) !== true) {
                // User can not enrol himself.
                // Note that we do not check here if user is already enrolled for performance reasons -
                // such check would execute extra queries for each course in the list of courses and
                // would hide self-enrolment icons from guests.
                continue;
            }
            if ($instance->password or $instance->customint1) {
                $key = true;
            } else {
                $nokey = true;
            }
        }
        $icons = array();
        if ($nokey) {
            $icons[] = new pix_icon('withoutkey', get_string('pluginname', 'enrol_jiaowu'), 'enrol_jiaowu');
        }
        if ($key) {
            $icons[] = new pix_icon('withkey', get_string('pluginname', 'enrol_jiaowu'), 'enrol_jiaowu');
        }
        return $icons;
    }

    /**
     * Returns localised name of enrol instance
     *
     * @param stdClass $instance (null is accepted too)
     * @return string
     */
    public function get_instance_name($instance)
    {
        global $DB;

        if (empty($instance->name)) {
            if (!empty($instance->roleid) and $role = $DB->get_record('role', array('id' => $instance->roleid))) {
                $role = ' (' . role_get_name($role, context_course::instance($instance->courseid, IGNORE_MISSING)) . ')';
            } else {
                $role = '';
            }
            $enrol = $this->get_name();
            return get_string('pluginname', 'enrol_' . $enrol) . $role;
        } else {
            return format_string($instance->name);
        }
    }

    public function roles_protected()
    {
        // Users may tweak the roles later.
        return false;
    }

    public function allow_unenrol(stdClass $instance)
    {
        // Users with unenrol cap may unenrol other users manually manually.
        return true;
    }

    public function allow_manage(stdClass $instance)
    {
        // Users with manage cap may tweak period and status.
        return true;
    }

    /**
     * Return true if we can add a new instance to this course.
     *
     * @param int $courseid
     * @return boolean
     */
    public function can_add_instance($courseid)
    {
        $context = context_course::instance($courseid, MUST_EXIST);

        if (!has_capability('moodle/course:enrolconfig', $context) or !has_capability('enrol/jiaowu:config', $context)) {
            return false;
        }

        return true;
    }

    /**
     * Self enrol user to course
     *
     * @param stdClass $instance enrolment instance
     * @param stdClass $data data needed for enrolment.
     * @return bool|array true if enroled else eddor code and messege
     */
    public function enrol_jiaowu(stdClass $instance, $data = null)
    {
        global $DB, $USER, $CFG;

        // Don't enrol user if password is not passed when required.
        if ($instance->password && !isset($data->enrolpassword)) {
            return;
        }

        $timestart = time();
        if ($instance->enrolperiod) {
            $timeend = $timestart + $instance->enrolperiod;
        } else {
            $timeend = 0;
        }

        $this->enrol_user($instance, $USER->id, $instance->roleid, $timestart, $timeend);

        \core\notification::success(get_string('youenrolledincourse', 'enrol'));

        if ($instance->customint1 and $data->enrolpassword !== $instance->password) {
            // It must be a group enrolment, let's assign group too.
            $groups = $DB->get_records('groups', array('courseid' => $instance->courseid), 'id', 'id, enrolmentkey');
            foreach ($groups as $group) {
                if (empty($group->enrolmentkey)) {
                    continue;
                }
                if ($group->enrolmentkey === $data->enrolpassword) {
                    // Add user to group.
                    require_once($CFG->dirroot . '/group/lib.php');
                    groups_add_member($group->id, $USER->id);
                    break;
                }
            }
        }
        // Send welcome message.
        if ($instance->customint4 != ENROL_DO_NOT_SEND_EMAIL) {
            $this->email_welcome_message($instance, $USER);
        }
    }

    /**
     * Creates course enrol form, checks if form submitted
     * and enrols user if necessary. It can also redirect.
     *
     * @param stdClass $instance
     * @return string html text, usually a form in a text box
     */
    public function enrol_page_hook(stdClass $instance)
    {
        global $CFG, $OUTPUT, $USER;

        require_once("$CFG->dirroot/enrol/jiaowu/locallib.php");

        $enrolstatus = $this->can_self_enrol($instance);

        if (true === $enrolstatus) {
            // This user can self enrol using this instance.
            $form = new enrol_jiaowu_enrol_form(null, $instance);
            $instanceid = optional_param('instance', 0, PARAM_INT);
            if ($instance->id == $instanceid) {
                if ($data = $form->get_data()) {
                    $this->enrol_jiaowu($instance, $data);
                }
            }
        } else if ($enrolstatus == 666) {
            return '';
        } else {
            // This user can not self enrol using this instance. Using an empty form to keep
            // the UI consistent with other enrolment plugins that returns a form.
            $data = new stdClass();
            $data->header = $this->get_instance_name($instance);
            $data->info = $enrolstatus;

            // The can_self_enrol call returns a button to the login page if the user is a
            // guest, setting the login url to the form if that is the case.
            $url = isguestuser() ? get_login_url() : null;
            $form = new enrol_jiaowu_empty_form($url, $data);
        }

        ob_start();
        $form->display();
        $output = ob_get_clean();
        return $OUTPUT->box($output);
    }

    /**
     * Checks if user can self enrol.
     *
     * @param stdClass $instance enrolment instance
     * @param bool $checkuserenrolment if true will check if user enrolment is inactive.
     *             used by navigation to improve performance.
     * @return bool|string true if successful, else error message or false.
     */
    public function can_self_enrol(stdClass $instance, $checkuserenrolment = true)
    {
        global $CFG, $DB, $OUTPUT, $USER;

        if ($checkuserenrolment) {
            if (isguestuser()) {
                // Can not enrol guest.
                return get_string('noguestaccess', 'enrol') . $OUTPUT->continue_button(get_login_url());
            }
            // Check if user is already enroled.
            if ($DB->get_record('user_enrolments', array('userid' => $USER->id, 'enrolid' => $instance->id))) {
                return get_string('canntenrol', 'enrol_jiaowu');
            }
        }

        if ($instance->enrol == 'jiaowu') {
            return 666;
        }

        if ($instance->status != ENROL_INSTANCE_ENABLED) {
            return get_string('canntenrol', 'enrol_jiaowu');
        }

        if ($instance->enrolstartdate != 0 and $instance->enrolstartdate > time()) {
            return get_string('canntenrolearly', 'enrol_jiaowu', userdate($instance->enrolstartdate));
        }

        if ($instance->enrolenddate != 0 and $instance->enrolenddate < time()) {
            return get_string('canntenrollate', 'enrol_jiaowu', userdate($instance->enrolenddate));
        }

        if (!$instance->customint6) {
            // New enrols not allowed.
            return get_string('canntenrol', 'enrol_jiaowu');
        }

        if ($DB->record_exists('user_enrolments', array('userid' => $USER->id, 'enrolid' => $instance->id))) {
            return get_string('canntenrol', 'enrol_jiaowu');
        }

        if ($instance->customint3 > 0) {
            // Max enrol limit specified.
            $count = $DB->count_records('user_enrolments', array('enrolid' => $instance->id));
            if ($count >= $instance->customint3) {
                // Bad luck, no more self enrolments here.
                return get_string('maxenrolledreached', 'enrol_jiaowu');
            }
        }

        if ($instance->customint5) {
            require_once("$CFG->dirroot/cohort/lib.php");
            if (!cohort_is_member($instance->customint5, $USER->id)) {
                $cohort = $DB->get_record('cohort', array('id' => $instance->customint5));
                if (!$cohort) {
                    return null;
                }
                $a = format_string($cohort->name, true, array('context' => context::instance_by_id($cohort->contextid)));
                return markdown_to_html(get_string('cohortnonmemberinfo', 'enrol_jiaowu', $a));
            }
        }


        return true;
    }

    /**
     * Returns the user who is responsible for self enrolments in given instance.
     *
     * Usually it is the first editing teacher - the person with "highest authority"
     * as defined by sort_by_roleassignment_authority() having 'enrol/jiaowu:manage'
     * capability.
     *
     * @param int $instanceid enrolment instance id
     * @return stdClass user record
     */
    protected function get_enroller($instanceid)
    {
        global $DB;

        if ($this->lasternollerinstanceid == $instanceid and $this->lasternoller) {
            return $this->lasternoller;
        }

        $instance = $DB->get_record('enrol', array('id' => $instanceid, 'enrol' => $this->get_name()), '*', MUST_EXIST);
        $context = context_course::instance($instance->courseid);

        if ($users = get_enrolled_users($context, 'enrol/jiaowu:manage')) {
            $users = sort_by_roleassignment_authority($users, $context);
            $this->lasternoller = reset($users);
            unset($users);
        } else {
            $this->lasternoller = parent::get_enroller($instanceid);
        }

        $this->lasternollerinstanceid = $instanceid;

        return $this->lasternoller;
    }

    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid)
    {
        global $DB;
        if ($step->get_task()->get_target() == backup::TARGET_NEW_COURSE) {
            $merge = false;
        } else {
            $merge = array(
                'courseid' => $data->courseid,
                'enrol' => $this->get_name(),
                'status' => $data->status,
                'roleid' => $data->roleid,
            );
        }
        if ($merge and $instances = $DB->get_records('enrol', $merge, 'id')) {
            $instance = reset($instances);
            $instanceid = $instance->id;
        } else {
            if (!empty($data->customint5)) {
                if ($step->get_task()->is_samesite()) {
                    // Keep cohort restriction unchanged - we are on the same site.
                } else {
                    // Use some id that can not exist in order to prevent self enrolment,
                    // because we do not know what cohort it is in this site.
                    $data->customint5 = -1;
                }
            }
            $instanceid = $this->add_instance($course, (array)$data);
        }
        $step->set_mapping('enrol', $oldid, $instanceid);
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $oldinstancestatus
     * @param int $userid
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus)
    {
        $this->enrol_user($instance, $userid, null, $data->timestart, $data->timeend, $data->status);
    }

    /**
     * Restore role assignment.
     *
     * @param stdClass $instance
     * @param int $roleid
     * @param int $userid
     * @param int $contextid
     */
    public function restore_role_assignment($instance, $roleid, $userid, $contextid)
    {
        // This is necessary only because we may migrate other types to this instance,
        // we do not use component in manual or self enrol.
        role_assign($roleid, $userid, $contextid, '', 0);
    }

    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_delete_instance($instance)
    {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/jiaowu:config', $context);
    }

    /**
     * Is it possible to hide/show enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_hide_show_instance($instance)
    {
        $context = context_course::instance($instance->courseid);

        if (!has_capability('enrol/jiaowu:config', $context)) {
            return false;
        }

        // If the instance is currently disabled, before it can be enabled,
        // we must check whether the password meets the password policies.
        if ($instance->status == ENROL_INSTANCE_DISABLED) {
            if ($this->get_config('requirepassword')) {
                if (empty($instance->password)) {
                    return false;
                }
            }
            // Only check the password if it is set.
            if (!empty($instance->password) && $this->get_config('usepasswordpolicy')) {
                if (!check_password_policy($instance->password, $errmsg)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Return an array of valid options for the status.
     *
     * @return array
     */
    protected function get_status_options()
    {
        $options = array(ENROL_INSTANCE_ENABLED => get_string('yes'),
            ENROL_INSTANCE_DISABLED => get_string('no'));
        return $options;
    }

    /**
     * Return an array of valid options for the groups.
     *
     * @param context $coursecontext
     * @return array
     */
    protected function get_group_options($coursecontext) {
        $groups = array(0 => get_string('none'));
        if (has_capability('moodle/course:managegroups', $coursecontext)) {
            $groups[-1] = get_string('creategroup', 'enrol_cohort');
        }

        foreach (groups_get_all_groups($coursecontext->instanceid) as $group) {
            $groups[$group->id] = format_string($group->name, true, array('context' => $coursecontext));
        }

        return $groups;
    }

    /**
     * The self enrollment plugin has several bulk operations that can be performed.
     * @param course_enrolment_manager $manager
     * @return array
     */
    public function get_bulk_operations(course_enrolment_manager $manager)
    {
        global $CFG;
        require_once($CFG->dirroot . '/enrol/jiaowu/locallib.php');
        $context = $manager->get_context();
        $bulkoperations = array();
        if (has_capability("enrol/jiaowu:manage", $context)) {
            $bulkoperations['editselectedusers'] = new enrol_jiaowu_editselectedusers_operation($manager, $this);
        }
        if (has_capability("enrol/jiaowu:unenrol", $context)) {
            $bulkoperations['deleteselectedusers'] = new enrol_jiaowu_deleteselectedusers_operation($manager, $this);
        }
        return $bulkoperations;
    }


    /**
     * Add elements to the edit instance form.
     *
     * @param stdClass $instance
     * @param MoodleQuickForm $mform
     * @param context $context
     * @return bool
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $context)
    {
        global $CFG, $DB, $USER;

        $nameattribs = array('size' => '20', 'maxlength' => '255');
        $mform->addElement('text', 'name', get_string('custominstancename', 'enrol'), $nameattribs);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'server');

        // 教务选课
        $options = $this->get_status_options();
        $mform->addElement('select', 'status', get_string('jiaowu', 'enrol_jiaowu'), $options);

        // 开课学院
        if ($instance->id) {
            $ck1 = $DB->get_record('enrol_jiaowu', ['enrolid' => $instance->id]);
            $cname = $ck1->collegename . '-' . $ck1->coursename . '-' . $ck1->stunum;
            $mform->addElement('static', 'description', get_string('courseinfo', 'enrol_jiaowu'),$cname);
        } else {
            $college = $this->get_jiaowu_course();
            if (empty($college)) {
                redirect(new moodle_url('/enrol/instances.php?id='.$instance->courseid),get_string('notfound','enrol_jiaowu'));
            }

            $mform->addElement('select', 'college', get_string('courseinfo', 'enrol_jiaowu'), $college);
        }

        $coursecontext =  context_course::instance($instance->courseid);

        $groups = $this->get_group_options($coursecontext);
        $mform->addElement('select', 'customint2', get_string('addgroup', 'enrol_jiaowu'), $groups);

        $mform->addElement('hidden', 'roleid');
        $mform->setType('roleid', PARAM_TEXT);
        $mform->setDefault('roleid', 5);

        $mform->addElement('hidden', 'expirynotify');
        $mform->setType('expirynotify', PARAM_TEXT);
        $mform->setDefault('expirynotify', 0);

    }

    /**
     * We are a good plugin and don't invent our own UI/validation code path.
     *
     * @return boolean
     */
    public function use_standard_editing_ui()
    {
        return true;
    }

    /**
     * Perform custom validation of the data used to edit the instance.
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @param object $instance The instance loaded from the DB
     * @param context $context The context of the instance we are editing
     * @return array of "element_name"=>"error_description" if there are errors,
     *         or an empty array if everything is OK.
     * @return void
     */
    public function edit_instance_validation($data, $files, $instance, $context)
    {
        return array();
    }

    /**
     * Add new instance of enrol plugin.
     * @param object $course
     * @param array $fields instance fields
     * @return int id of new instance, null if can not be created
     */
    public function add_instance($course, array $fields = null)
    {
        global $CFG, $DB;
        $coursedata = [];
        if (!empty($fields) && !empty($fields['college'])) {
            $dd = explode('~', $fields['college']);
            $coursedata = [
                'termid' => $dd[0],  // 学期
                'courseid' => $dd[1],  // 教务系统上课程id
                'collegename' => $dd[2],  // 学院名
                'coursename' => $dd[3],  // 课程名
                'stunum' => $dd[4],  // 选课人数
            ];
        }
        if (!empty($fields['customint2']) && $fields['customint2'] == -1) {
            // Create a new group for the cohort if requested.
            $context = context_course::instance($course->id);
            require_capability('moodle/course:managegroups', $context);
            $groupid = enrol_jiaowu_create_new_group($course->id, $coursedata['coursename']);
            $fields['customint2'] = $groupid;
        }
        $new = parent::add_instance($course, $fields);

        if ($new) {
            $userData = $this->get_jiaowu_users($coursedata['courseid']);
            $this->auto_enrol_jw($new, $userData, $coursedata);
        }
        require_once("$CFG->dirroot/enrol/cohort/locallib.php");
        $trace = new null_progress_trace();
        enrol_cohort_sync($trace, $course->id);
        $trace->finished();
        return true;
    }

    // 获取token
    public function get_jiaowu_token()
    {
        $config = get_config('enrol_jiaowu');
        $url = $config->url;
        $cid = $config->clientid;
        $cselect = $config->selectid;
        if (empty($url) || empty($cid) || empty($cselect)) {
            return '';
        }


        $timestamp = time();
        $sign = sha1($cid.$timestamp.$cselect);

        $postdata = [
            'clientid'=> $cid,
            'timestamp'=> $timestamp,
            'sign' => $sign
        ];
        $curl = new curl();
        $res = $curl->post($url,$postdata);
        $res = json_decode($res,true);
        $token = '';
        if ($res['status'] == 1 ) {
            $token = $res['data']['token'];
        }
        return $token;
    }

    // 获取当前教师的课程信息
    public function get_jiaowu_course() {
        global $USER;

        $token = $this->get_jiaowu_token();
        if (empty($token)) {
            return [];
        }
        $curl = new curl();
        $config = get_config('enrol_jiaowu');
        $url = $config->course;
//        $url = 'http://moodle.scnu.edu.cn/wsbs/outside/getcourse';

        $data = [
//            'username' => 19881016,
            'username' => $USER->username,
            'token' => $token,
        ];
        $cd = $curl->post($url,$data);
        $cd = json_decode($cd,true);
        $course = [];
        $currentYear = date('Y');
        if ($cd['status'] == 1) {
            foreach ($cd['data'] as $k => $v) {
                $course[$currentYear . '~' .$v['idnumber'] . '~' . $v['category'] . '~' . $v['shortname'] . '~' . $v['stunum']] = $v['category'] . '-' . $v['shortname'] . '-' . $v['stunum'];
            }
        }
        return $course;
    }

    // 获取课程下的学员信息
    public function get_jiaowu_users($courseid)
    {
        $token = $this->get_jiaowu_token();
        $curl = new curl();
        $config = get_config('enrol_jiaowu');
        $url = $config->students;
//        $url = 'http://moodle.scnu.edu.cn/wsbs/outside/getstudent';
        $data = [
            'idnumber' => $courseid,
            'token' => $token,
        ];
        $res = $curl->post($url,$data);
        $res = json_decode($res,true);
        $userData = [];
        if ($res['status'] == 1) {
            foreach ($res['data'] as $v) {
                $userData[] = [
                    'username' => $v,
                    'roleid' => 5
                ];
            }
        }

        return $userData;
    }

    /**
     * Update instance of enrol plugin.
     * @param stdClass $instance
     * @param stdClass $data modified instance fields
     * @return boolean
     */
    public function update_instance($instance, $data)
    {
        global $DB;
        // In the form we are representing 2 db columns with one field.
        if ($data->expirynotify == 2) {
            $data->expirynotify = 1;
            $data->notifyall = 1;
        } else {
            $data->notifyall = 0;
        }
        // Keep previous/default value of disabled expirythreshold option.
        if (!$data->expirynotify) {
            $data->expirythreshold = $instance->expirythreshold;
        }
        // Add previous value of newenrols if disabled.
        if (!isset($data->customint6)) {
            $data->customint6 = $instance->customint6;
        }
        $sql = "update {groups_members} set groupid = ? where itemid = ?";
        $DB->execute($sql,[$data->customint2,$instance->id]);
        return parent::update_instance($instance, $data);
    }

    public function delete_instance($instance)
    {
        global $DB;
        $DB->delete_records('enrol_jiaowu', ['enrolid' => $instance->id]);
        return parent::delete_instance($instance); // TODO: Change the autogenerated stub
    }

    public function auto_enrol_jw($instanceid, $userdata, $coursedata = [])
    {
        global $CFG, $DB;

        // 获取 报名方式 的对象信息
        $instance = $DB->get_record('enrol', array('enrol' => 'jiaowu', 'id' => $instanceid), '*', MUST_EXIST);
        $enroldata = [];

        if (count($userdata) > 0) {  // 当有用户信息的时候
            foreach ($userdata as $k => $v) {
                $userid = $DB->get_field('user', 'id', ['username' => $v['username']]);  // 获取用户的id
                if ($userid) {
                    $sql = "select ue.* from {user_enrolments} ue join {enrol} e on ue.enrolid = e.id where e.courseid = $instance->courseid and ue.userid = $userid";
                    $ck1 = $DB->get_record_sql($sql); // 查看用户是否进行过报名
                    if ($ck1) {
                        if ($instance->customint2) {
                            require_once("$CFG->dirroot/group/lib.php");
                            if (!groups_is_member($instance->customint2,$userid)) { // 如果不是该组成员
                                groups_add_member($instance->customint2,$userid,'enrol_jiaowu',$instanceid);
                            }
                        }
                        if ($ck1->status == 1) { // 如果课程状态为已暂停，则启用他
                            $us = "update {user_enrolments} set status = 0 where userid = ? and enrolid = ?";
                            $DB->execute($us, [$userid, $instanceid]);
                        }
                        $enroldata['success'][] = $v['username'];
                        continue;
                    }


                    $getenrol = $this->enrol_user($instance, $userid, $v['roleid']);
                    if (empty($getenrol)) {  // 没有返回则报名成功
                        if ($instance->customint2) {
                            require_once("$CFG->dirroot/group/lib.php");
                            if (!groups_is_member($instance->customint2,$userid)) { // 如果不是该组成员
                                groups_add_member($instance->customint2,$userid,'enrol_jiaowu',$instanceid);
                            }
                            $enroldata['success'][] = $v['username'];
                        }

                        continue;
                    }

                } else {  // 没有账号
                    $enroldata['error'][$v['username']] = [
                        'error' => 'notfind',
                        'user' => $v['username'],
                        'roleid' => $v['roleid'],
                    ];
                }
            }

            $ck2 = $DB->get_record('enrol_jiaowu', ['enrolid' => $instanceid]); // 获取存放在 enrol_jiaowu 中的数据，用于进行更新操作
            if ($ck2) {
                $ever = json_decode($ck2->data, true);  // 获取同步课程数据
                $dif = [];
                if (!empty($ever['success'])) {
                    if (empty($enroldata['success'])) {
                        $dif = $ever['success'];
                    } else {
                        $dif = array_diff($ever['success'], $enroldata['success']);
                    }
                }
                if (!empty($ever['error']) && !empty($enroldata['error'])) {
                    $ever['error'] = array_merge($ever['error'], $enroldata['error']);
                }
                if ($dif) {
                    foreach ($dif as $vv) {
                        $euid = $DB->get_field('user', 'id', ['username' => $vv]);  // 退选的用户id
                        $us = "update {user_enrolments} set status = 1 where userid = ? and enrolid = ?";
                        $DB->execute($us, [$euid, $instanceid]);
                        $ever['error'][$vv] = [
                            'error' => 'exit',
                            'user' => $vv,
                            'roleid' => 5,
                        ];
                    }
                }
                if (!empty($ever['error'])) {
                    foreach ($ever['error'] as $kk => $vvv) {
                        $euid = $DB->get_field('user', 'id', ['username' => $kk]);  // 用户id
                        $ck3 = $DB->get_record('user_enrolments', ['enrolid' => $instanceid, 'userid' => $euid, 'status' => 0]);
                        if ($ck3) {
                            unset($ever['error'][$kk]);
                        }
                    }
                }
                $ever['success'] = empty($enroldata['success']) ? [] : $enroldata['success'];
                $sql = "update {enrol_jiaowu} set data = ? , timemodified = ? where enrolid = ?";
                $DB->execute($sql, [json_encode($ever), time(), $instanceid]);
                return true;
            }

            $data = $this->get_jiaowu_data($instanceid, $coursedata, json_encode($enroldata, true));
            $DB->insert_record('enrol_jiaowu', $data);
        }
    }

    public function get_jiaowu_data($instanceid, $coursedata, $userdata)
    {
        $res = [
            'enrolid' => $instanceid, // 报名方式id
            'termid' => $coursedata['termid'],  // 学期
            'collegename' => $coursedata['collegename'],  // 学院名
            'courseid' => $coursedata['courseid'],  // 教务系统上课程id
            'coursename' => $coursedata['coursename'],  // 课程名
            'stunum' => $coursedata['stunum'],  // 选课人数
            'data' => $userdata,  // 同步情况
            'timecreated' => time(),
        ];
        return $res;
    }

    /**
     * Returns edit icons for the page with list of instances.
     * @param stdClass $instance
     * @return array
     */
    public function get_action_icons(stdClass $instance)
    {
        global $OUTPUT;

        $context = context_course::instance($instance->courseid);

        $icons = array();
        if (has_capability('enrol/jiaowu:manage', $context)) {
            $managelink = new moodle_url("/enrol/jiaowu/update.php", ['instanceid' => $instance->id]);
            $unpass = new moodle_url('/enrol/jiaowu/update.php', ['instanceid' => $instance->id, 'type' => 2]);
            $icons[] = $OUTPUT->action_icon($unpass, new pix_icon('i/cohort', get_string('error_title','enrol_jiaowu'), 'core', array('class' => 'iconsmall')));
            $icons[] = $OUTPUT->action_icon($managelink, new pix_icon('a/refresh', get_string('reupdate','enrol_jiaowu'), 'core', array('class' => 'iconsmall')));
        }
        $parenticons = parent::get_action_icons($instance);
        $icons = array_merge($icons, $parenticons);

        return $icons;
    }

}

/**
 * Create a new group with the cohorts name.
 *
 * @param int $courseid
 * @param int $cohortid
 * @return int $groupid Group ID for this cohort.
 */
function enrol_jiaowu_create_new_group($courseid, $groupname) {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/group/lib.php');

    // Create a new group for the cohort.
    $groupdata = new stdClass();
    $groupdata->courseid = $courseid;
    $groupdata->name = $groupname;
    $groupid = groups_create_group($groupdata);

    return $groupid;
}

