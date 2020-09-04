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
 * Self enrolment plugin settings and presets.
 *
 * @package    enrol_jiaowu
 * @copyright  2010 Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    //--- general settings -----------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_jiaowu_settings', '', get_string('pluginname_desc', 'enrol_jiaowu')));

    $settings->add(new admin_setting_configcheckbox('enrol_jiaowu/requirepassword',
        get_string('requirepassword', 'enrol_jiaowu'), get_string('requirepassword_desc', 'enrol_jiaowu'), 0));

    $settings->add(new admin_setting_configcheckbox('enrol_jiaowu/usepasswordpolicy',
        get_string('usepasswordpolicy', 'enrol_jiaowu'), get_string('usepasswordpolicy_desc', 'enrol_jiaowu'), 0));

    $settings->add(new admin_setting_configcheckbox('enrol_jiaowu/showhint',
        get_string('showhint', 'enrol_jiaowu'), get_string('showhint_desc', 'enrol_jiaowu'), 0));

    // Note: let's reuse the ext sync constants and strings here, internally it is very similar,
    //       it describes what should happend when users are not supposed to be enerolled any more.
    $options = array(
        ENROL_EXT_REMOVED_KEEP           => get_string('extremovedkeep', 'enrol'),
        ENROL_EXT_REMOVED_SUSPENDNOROLES => get_string('extremovedsuspendnoroles', 'enrol'),
        ENROL_EXT_REMOVED_UNENROL        => get_string('extremovedunenrol', 'enrol'),
    );
    $settings->add(new admin_setting_configselect('enrol_jiaowu/expiredaction', get_string('expiredaction', 'enrol_jiaowu'), get_string('expiredaction_help', 'enrol_jiaowu'), ENROL_EXT_REMOVED_KEEP, $options));

    $options = array();
    for ($i=0; $i<24; $i++) {
        $options[$i] = $i;
    }
    $settings->add(new admin_setting_configselect('enrol_jiaowu/expirynotifyhour', get_string('expirynotifyhour', 'core_enrol'), '', 6, $options));

    //--- enrol instance defaults ----------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_jiaowu_defaults',
        get_string('enrolinstancedefaults', 'admin'), get_string('enrolinstancedefaults_desc', 'admin')));

    $settings->add(new admin_setting_configcheckbox('enrol_jiaowu/defaultenrol',
        get_string('defaultenrol', 'enrol'), get_string('defaultenrol_desc', 'enrol'), 1));

    $options = array(ENROL_INSTANCE_ENABLED  => get_string('yes'),
                     ENROL_INSTANCE_DISABLED => get_string('no'));
    $settings->add(new admin_setting_configselect('enrol_jiaowu/status',
        get_string('status', 'enrol_jiaowu'), get_string('status_desc', 'enrol_jiaowu'), ENROL_INSTANCE_DISABLED, $options));

    $options = array(1  => get_string('yes'), 0 => get_string('no'));
    $settings->add(new admin_setting_configselect('enrol_jiaowu/newenrols',
        get_string('newenrols', 'enrol_jiaowu'), get_string('newenrols_desc', 'enrol_jiaowu'), 1, $options));

    $options = array(1  => get_string('yes'),
                     0 => get_string('no'));
    $settings->add(new admin_setting_configselect('enrol_jiaowu/groupkey',
        get_string('groupkey', 'enrol_jiaowu'), get_string('groupkey_desc', 'enrol_jiaowu'), 0, $options));

    if (!during_initial_install()) {
        $options = get_default_enrol_roles(context_system::instance());
        $student = get_archetype_roles('student');
        $student = reset($student);
        $settings->add(new admin_setting_configselect('enrol_jiaowu/roleid',
            get_string('defaultrole', 'enrol_jiaowu'), get_string('defaultrole_desc', 'enrol_jiaowu'), $student->id, $options));
    }

    $settings->add(new admin_setting_configduration('enrol_jiaowu/enrolperiod',
        get_string('enrolperiod', 'enrol_jiaowu'), get_string('enrolperiod_desc', 'enrol_jiaowu'), 0));

    $options = array(0 => get_string('no'),
                     1 => get_string('expirynotifyenroller', 'enrol_jiaowu'),
                     2 => get_string('expirynotifyall', 'enrol_jiaowu'));
    $settings->add(new admin_setting_configselect('enrol_jiaowu/expirynotify',
        get_string('expirynotify', 'core_enrol'), get_string('expirynotify_help', 'core_enrol'), 0, $options));

    $settings->add(new admin_setting_configduration('enrol_jiaowu/expirythreshold',
        get_string('expirythreshold', 'core_enrol'), get_string('expirythreshold_help', 'core_enrol'), 86400, 86400));

    $options = array(0 => get_string('never'),
                     1800 * 3600 * 24 => get_string('numdays', '', 1800),
                     1000 * 3600 * 24 => get_string('numdays', '', 1000),
                     365 * 3600 * 24 => get_string('numdays', '', 365),
                     180 * 3600 * 24 => get_string('numdays', '', 180),
                     150 * 3600 * 24 => get_string('numdays', '', 150),
                     120 * 3600 * 24 => get_string('numdays', '', 120),
                     90 * 3600 * 24 => get_string('numdays', '', 90),
                     60 * 3600 * 24 => get_string('numdays', '', 60),
                     30 * 3600 * 24 => get_string('numdays', '', 30),
                     21 * 3600 * 24 => get_string('numdays', '', 21),
                     14 * 3600 * 24 => get_string('numdays', '', 14),
                     7 * 3600 * 24 => get_string('numdays', '', 7));
    $settings->add(new admin_setting_configselect('enrol_jiaowu/longtimenosee',
        get_string('longtimenosee', 'enrol_jiaowu'), get_string('longtimenosee_help', 'enrol_jiaowu'), 0, $options));

    $settings->add(new admin_setting_configtext('enrol_jiaowu/maxenrolled',
        get_string('maxenrolled', 'enrol_jiaowu'), get_string('maxenrolled_help', 'enrol_jiaowu'), 0, PARAM_INT));

    $settings->add(new admin_setting_configselect('enrol_jiaowu/sendcoursewelcomemessage',
            get_string('sendcoursewelcomemessage', 'enrol_jiaowu'),
            get_string('sendcoursewelcomemessage_help', 'enrol_jiaowu'),
            ENROL_SEND_EMAIL_FROM_COURSE_CONTACT,
            enrol_send_welcome_email_options()));
}
