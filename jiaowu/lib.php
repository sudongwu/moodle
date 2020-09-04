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

    public function show_enrolme_link(stdClass $instance)
    {

        if (true !== $this->can_self_enrol($instance, false)) {
            return false;
        }

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

        if ($instance->password and $instance->customint1 and $data->enrolpassword !== $instance->password) {
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
     * Return information for enrolment instance containing list of parameters required
     * for enrolment, name of enrolment plugin etc.
     *
     * @param stdClass $instance enrolment instance
     * @return stdClass instance info.
     */
    public function get_enrol_info(stdClass $instance)
    {

        $instanceinfo = new stdClass();
        $instanceinfo->id = $instance->id;
        $instanceinfo->courseid = $instance->courseid;
        $instanceinfo->type = $this->get_name();
        $instanceinfo->name = $this->get_instance_name($instance);
        $instanceinfo->status = $this->can_self_enrol($instance);

        if ($instance->password) {
            $instanceinfo->requiredparam = new stdClass();
            $instanceinfo->requiredparam->enrolpassword = get_string('password', 'enrol_jiaowu');
        }

        // If enrolment is possible and password is required then return ws function name to get more information.
        if ((true === $instanceinfo->status) && $instance->password) {
            $instanceinfo->wsfunction = 'enrol_jiaowu_get_instance_info';
        }
        return $instanceinfo;
    }

    /**
     * Add new instance of enrol plugin with default settings.
     * @param stdClass $course
     * @return int id of new instance
     */
    public function add_default_instance($course)
    {
        $fields = $this->get_instance_defaults();

        if ($this->get_config('requirepassword')) {
            $fields['password'] = generate_password(20);
        }

        return $this->add_instance($course, $fields);
    }

    /**
     * Returns defaults for new instances.
     * @return array
     */
    public function get_instance_defaults()
    {
        $expirynotify = $this->get_config('expirynotify');
        if ($expirynotify == 2) {
            $expirynotify = 1;
            $notifyall = 1;
        } else {
            $notifyall = 0;
        }

        $fields = array();
        $fields['status'] = $this->get_config('status');
        $fields['roleid'] = $this->get_config('roleid');
        $fields['enrolperiod'] = $this->get_config('enrolperiod');
        $fields['expirynotify'] = $expirynotify;
        $fields['notifyall'] = $notifyall;
        $fields['expirythreshold'] = $this->get_config('expirythreshold');
        $fields['customint1'] = $this->get_config('groupkey');
        $fields['customint2'] = $this->get_config('longtimenosee');
        $fields['customint3'] = $this->get_config('maxenrolled');
        $fields['customint4'] = $this->get_config('sendcoursewelcomemessage');
        $fields['customint5'] = 0;
        $fields['customint6'] = $this->get_config('newenrols');

        return $fields;
    }

    /**
     * Send welcome email to specified user.
     *
     * @param stdClass $instance
     * @param stdClass $user user record
     * @return void
     */
    protected function email_welcome_message($instance, $user)
    {
        global $CFG, $DB;

        $course = $DB->get_record('course', array('id' => $instance->courseid), '*', MUST_EXIST);
        $context = context_course::instance($course->id);

        $a = new stdClass();
        $a->coursename = format_string($course->fullname, true, array('context' => $context));
        $a->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id&course=$course->id";

        if (trim($instance->customtext1) !== '') {
            $message = $instance->customtext1;
            $key = array('{$a->coursename}', '{$a->profileurl}', '{$a->fullname}', '{$a->email}');
            $value = array($a->coursename, $a->profileurl, fullname($user), $user->email);
            $message = str_replace($key, $value, $message);
            if (strpos($message, '<') === false) {
                // Plain text only.
                $messagetext = $message;
                $messagehtml = text_to_html($messagetext, null, false, true);
            } else {
                // This is most probably the tag/newline soup known as FORMAT_MOODLE.
                $messagehtml = format_text($message, FORMAT_MOODLE, array('context' => $context, 'para' => false, 'newlines' => true, 'filter' => true));
                $messagetext = html_to_text($messagehtml);
            }
        } else {
            $messagetext = get_string('welcometocoursetext', 'enrol_jiaowu', $a);
            $messagehtml = text_to_html($messagetext, null, false, true);
        }

        $subject = get_string('welcometocourse', 'enrol_jiaowu', format_string($course->fullname, true, array('context' => $context)));

        $sendoption = $instance->customint4;
        $contact = $this->get_welcome_email_contact($sendoption, $context);

        // Directly emailing welcome message rather than using messaging.
        email_to_user($user, $contact, $subject, $messagetext, $messagehtml);
    }

    /**
     * Sync all meta course links.
     *
     * @param progress_trace $trace
     * @param int $courseid one course, empty mean all
     * @return int 0 means ok, 1 means error, 2 means plugin disabled
     */
    public function sync(progress_trace $trace, $courseid = null)
    {
        global $DB;

        if (!enrol_is_enabled('self')) {
            $trace->finished();
            return 2;
        }

        // Unfortunately this may take a long time, execution can be interrupted safely here.
        core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);

        $trace->output('Verifying self-enrolments...');

        $params = array('now' => time(), 'useractive' => ENROL_USER_ACTIVE, 'courselevel' => CONTEXT_COURSE);
        $coursesql = "";
        if ($courseid) {
            $coursesql = "AND e.courseid = :courseid";
            $params['courseid'] = $courseid;
        }

        // Note: the logic of self enrolment guarantees that user logged in at least once (=== u.lastaccess set)
        //       and that user accessed course at least once too (=== user_lastaccess record exists).

        // First deal with users that did not log in for a really long time - they do not have user_lastaccess records.
        $sql = "SELECT e.*, ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'self' AND e.customint2 > 0)
                  JOIN {user} u ON u.id = ue.userid
                 WHERE :now - u.lastaccess > e.customint2
                       $coursesql";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $instance) {
            $userid = $instance->userid;
            unset($instance->userid);
            $this->unenrol_user($instance, $userid);
            $days = $instance->customint2 / DAYSECS;
            $trace->output("unenrolling user $userid from course $instance->courseid " .
                "as they did not log in for at least $days days", 1);
        }
        $rs->close();

        // Now unenrol from course user did not visit for a long time.
        $sql = "SELECT e.*, ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol = 'self' AND e.customint2 > 0)
                  JOIN {user_lastaccess} ul ON (ul.userid = ue.userid AND ul.courseid = e.courseid)
                 WHERE :now - ul.timeaccess > e.customint2
                       $coursesql";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $instance) {
            $userid = $instance->userid;
            unset($instance->userid);
            $this->unenrol_user($instance, $userid);
            $days = $instance->customint2 / DAYSECS;
            $trace->output("unenrolling user $userid from course $instance->courseid " .
                "as they did not access the course for at least $days days", 1);
        }
        $rs->close();

        $trace->output('...user self-enrolment updates finished.');
        $trace->finished();

        $this->process_expirations($trace, $courseid);

        return 0;
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

        // Merge these two settings to one value for the single selection element.
        if ($instance->notifyall and $instance->expirynotify) {
            $instance->expirynotify = 2;
        }
        unset($instance->notifyall);

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
            $mform->addElement('text', 'college', get_string('courseinfo', 'enrol_jiaowu'), 'readonly'); // 设置只读
            $mform->setType('college', PARAM_TEXT);
            $cname = $ck1->collegename . '-' . $ck1->coursename . '-' . $ck1->stunum;
            $mform->setDefault('college', $cname);
        } else {
            $college = [];
            $college = [
                '123123~123~AAAAA~BBBBB~666' => 'AAAAA-BBBBB-666',
                '124124~456~AAAAA~BBBBB~777' => 'AAAAA-BBBBB-777',
                '124124~789~AAAAA~BBBBB~888' => 'AAAAA-BBBBB-888',
            ];
            $mform->addElement('select', 'college', get_string('courseinfo', 'enrol_jiaowu'), $college);
        }

        $mform->addElement('hidden', 'roleid');
        $mform->setType('roleid', PARAM_TEXT);
        $mform->setDefault('status', 5);

        $mform->addElement('hidden', 'customint6');
        $mform->setType('customint6', PARAM_TEXT);
        $mform->setDefault('status', 1);

        $mform->addElement('hidden', 'customint1');
        $mform->setType('customint1', PARAM_TEXT);
        $mform->setDefault('status', 0);

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
        global $DB;
        $new = parent::add_instance($course, $fields);
        if ($new) {
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
            $userData = $this->get_jiaowu_users();
            $this->auto_enrol_jw($new, $userData, $coursedata);
        }
        return true;
    }

    public function get_jiaowu_users()
    {
        $userData = array(
            [
                'username' => 's1',
                'roleid' => 5,
            ],
            [
                'username' => 's2',
                'roleid' => 5,
            ],
            [
                'username' => 's3',
                'roleid' => 5,
            ],
            [
                'username' => 'xswl',
                'roleid' => 5,
            ],
//            [
//                'username' => 'yqh',
//                'roleid' => 5,
//            ],
            [
                'username' => 'test1',
                'roleid' => 3,
            ],

        );
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
        global $DB;

        $instance = $DB->get_record('enrol', array('enrol' => 'jiaowu', 'id' => $instanceid), '*', MUST_EXIST);
        $enroldata = [];

        if (count($userdata) > 0) {
            foreach ($userdata as $k => $v) {
                $userid = $DB->get_field('user', 'id', ['username' => $v['username']]);
                if ($userid) {  // 有账号
                    $ck1 = $DB->get_record('user_enrolments', ['enrolid' => $instanceid, 'userid' => $userid]);
                    if ($ck1) {
                        if ($ck1->status == 1) {
                            $us = "update {user_enrolments} set status = 0 where userid = ? and enrolid = ?";
                            $DB->execute($us, [$userid, $instanceid]);
                        }
                        $enroldata['success'][] = $v['username'];
                        continue;
                    }

                    $getenrol = $this->enrol_user($instance, $userid, $v['roleid']);
                    if (empty($getenrol)) {  // 没有返回则报名成功
                        $enroldata['success'][] = $v['username'];
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

            $ck2 = $DB->get_record('enrol_jiaowu', ['enrolid' => $instanceid]);
            if ($ck2) {
                $ever = json_decode($ck2->data, true);
                $dif = array_diff($ever['success'], $enroldata['success']);
                $ever['error'] = array_merge($ever['error'], $enroldata['error']);

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
                foreach ($ever['error'] as $kk => $vvv) {
                    $euid = $DB->get_field('user', 'id', ['username' => $kk]);  // 用户id
                    $ck3 = $DB->get_record('user_enrolments', ['enrolid' => $instanceid, 'userid' => $euid, 'status' => 0]);
                    if ($ck3) {
                        unset($ever['error'][$kk]);
                    }
                }
                $ever['success'] = $enroldata['success'];
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
     * Gets a list of roles that this user can assign for the course as the default for self-enrolment.
     *
     * @param context $context the context.
     * @param integer $defaultrole the id of the role that is set as the default for self-enrolment
     * @return array index is the role id, value is the role name
     */
    public function extend_assignable_roles($context, $defaultrole)
    {
        global $DB;

        $roles = get_assignable_roles($context, ROLENAME_BOTH);
        if (!isset($roles[$defaultrole])) {
            if ($role = $DB->get_record('role', array('id' => $defaultrole))) {
                $roles[$defaultrole] = role_get_name($role, $context, ROLENAME_BOTH);
            }
        }
        return $roles;
    }

    /**
     * Get the "from" contact which the email will be sent from.
     *
     * @param int $sendoption send email from constant ENROL_SEND_EMAIL_FROM_*
     * @param $context context where the user will be fetched
     * @return mixed|stdClass the contact user object.
     */
    public function get_welcome_email_contact($sendoption, $context)
    {
        global $CFG;

        $contact = null;
        // Send as the first user assigned as the course contact.
        if ($sendoption == ENROL_SEND_EMAIL_FROM_COURSE_CONTACT) {
            $rusers = array();
            if (!empty($CFG->coursecontact)) {
                $croles = explode(',', $CFG->coursecontact);
                list($sort, $sortparams) = users_order_by_sql('u');
                // We only use the first user.
                $i = 0;
                do {
                    $allnames = get_all_user_name_fields(true, 'u');
                    $rusers = get_role_users($croles[$i], $context, true, 'u.id,  u.confirmed, u.username, ' . $allnames . ',
                    u.email, r.sortorder, ra.id', 'r.sortorder, ra.id ASC, ' . $sort, null, '', '', '', '', $sortparams);
                    $i++;
                } while (empty($rusers) && !empty($croles[$i]));
            }
            if ($rusers) {
                $contact = array_values($rusers)[0];
            }
        } else if ($sendoption == ENROL_SEND_EMAIL_FROM_KEY_HOLDER) {
            // Send as the first user with enrol/jiaowu:holdkey capability assigned in the course.
            list($sort) = users_order_by_sql('u');
            $keyholders = get_users_by_capability($context, 'enrol/jiaowu:holdkey', 'u.*', $sort);
            if (!empty($keyholders)) {
                $contact = array_values($keyholders)[0];
            }
        }

        // If send welcome email option is set to no reply or if none of the previous options have
        // returned a contact send welcome message as noreplyuser.
        if ($sendoption == ENROL_SEND_EMAIL_FROM_NOREPLY || empty($contact)) {
            $contact = core_user::get_noreply_user();
        }

        return $contact;
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
            $icons[] = $OUTPUT->action_icon($unpass, new pix_icon('i/cohort', '选课异常列表', 'core', array('class' => 'iconsmall')));
            $icons[] = $OUTPUT->action_icon($managelink, new pix_icon('a/refresh', '重新执行选课', 'core', array('class' => 'iconsmall')));
        }
        $parenticons = parent::get_action_icons($instance);
        $icons = array_merge($icons, $parenticons);

        return $icons;
    }
}

/**
 * Get icon mapping for font-awesome.
 */
function enrol_jiaowu_get_fontawesome_icon_map()
{
    return [
        'enrol_jiaowu:withkey' => 'fa-key',
        'enrol_jiaowu:withoutkey' => 'fa-sign-in',
    ];
}

function dd()
{
    var_dump('halo');
    die();
}
