<?php
/* Copyright Panopto 2009 - 2013 / With contributions from Spenser Jones (sjones@ambrose.edu)
 * 
 * This file is part of the Panopto plugin for Moodle.
 * 
 * The Panopto plugin for Moodle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * The Panopto plugin for Moodle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with the Panopto plugin for Moodle.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');
require_once("block_panopto_lib.php");
require_once("PanoptoSoapClient.php");

class panopto_data {
    var $instancename;

    var $moodle_course_id;

    var $servername;
    var $applicationkey;

    var $soap_client;

    var $sessiongroup_id;

    function __construct($moodle_course_id) {
        global $USER, $CFG;

        // Fetch global settings from DB
        $this->instancename = $CFG->block_panopto_instance_name;
        $this->servername = $CFG->block_panopto_server_name;
        $this->applicationkey = $CFG->block_panopto_application_key;

        if(!empty($this->servername)) {
            if(isset($USER->username)) {
                $username = $USER->username;
            } else {
                $username = "guest";
            }

            // Compute web service credentials for current user.
            $apiuser_userkey = panopto_decorate_username($username);
            $apiuser_authcode = panopto_generate_auth_code($apiuser_userkey . "@" . $this->servername);

            // Instantiate our SOAP client.
            $this->soap_client = new PanoptoSoapClient($this->servername, $apiuser_userkey, $apiuser_authcode);
        }

        // Fetch current CC course mapping if we have a Moodle course ID.
        // Course will be null initially for batch-provisioning case.
        if(!empty($moodle_course_id)) {
            $this->moodle_course_id = $moodle_course_id;
            $this->sessiongroup_id = panopto_data::get_linked_panopto_folder($moodle_course_id);
        }
    }

    // returns SystemInfo
    function get_system_info() {
        return $this->soap_client->GetSystemInfo();
    }

    // Create the Panopto course and populate its ACLs.
    function provision_course($provisioning_info) {
        $course_info = $this->soap_client->ProvisionCourse($provisioning_info);

        if(!empty($course_info) && !empty($course_info->PublicID)) {
            panopto_data::set_panopto_course_id($this->moodle_course_id, $course_info->PublicID);
        }

        return $course_info;
    }

    public function provision_folder($provisioninginfo) {
        global $DB;
        $courseinfo = $this->soap_client->ProvisionCourse($provisioninginfo);
        
        if (!empty($courseinfo) and !empty($courseinfo->PublicID)) {
            // no record means is a standard linked folder
            $record = $DB->get_record('block_panopto_foldermap', array('courseid' => $this->moodle_course_id));
            // no record so create
            if (!$record) {
                $record = new stdClass();
                $record->courseid = $this->moodle_course_id;
                $record->folderid = $courseinfo->PublicID;
                $record->linkedfolderid = '';
                $record->syncuserlist = 1;
                $DB->insert_record('block_panopto_foldermap', $record);
            } else {
                $record->folderid = $courseinfo->PublicID;
                $record->linkedfolderid = '';
                $record->syncuserlist = 1;
                $DB->update_record('block_panopto_foldermap', $record);
            }
        }
        return $courseinfo;
    }

    /**
     * Fetches course information and membership data. Teachers are creators, Students are
     * viewers. If other courses link to this courses Panopto folder, Students from courses
     * are also added.
     *
     * @global type $DB
     * @staticvar type $teacherrole
     * @staticvar type $studentrole
     * @return \stdClass
     */
    public function get_provisioning_data() {
            global $DB;

            static $helpdeskrole = null;
            static $teacherroles = null;
            static $studentrole = null;

            $userfields = 'u.id, u.username, u.firstname, u.lastname, u.email';

            $admins = get_admins();

            $helpdeskusers = array();
            if (is_null($helpdeskrole)) {
                $helpdeskrole = $DB->get_record('role', array('shortname'=>'helpdesk'));
                if ($helpdeskrole) {
                    $helpdeskusers = get_role_users($helpdeskrole->id, context_system::instance());
                }
            }

            if (is_null($teacherroles)) {
                $teacherroles = $DB->get_records('role', array('archetype'=>'editingteacher'));
                if (! $teacherroles) {
                    print_error("No teacher roles exist!");
                }
            }

            if (is_null($studentrole)) {
                $studentrole = $DB->get_record('role', array('shortname'=>'student'));
                if (! $studentrole) {
                    print_error("Student role does not exist!");
                }
            }

            $course = $DB->get_record('course', array('id'=>$this->moodle_course_id), 'id, shortname, fullname');

            $data = new stdClass();

            $data->ShortName        = trim($course->shortname);
            $data->LongName         = trim($course->fullname);
            $data->ExternalCourseID = $this->instancename . ':' . $this->moodle_course_id;

            $data->Instructors = array();
            $data->Students    = array();

            $context = get_context_instance(CONTEXT_COURSE, $this->moodle_course_id);
            // main teachers
            $teachers = array();
            // editingteacher, coteacher etc
            foreach ($teacherroles as $teacherrole) {
                $teachersinrole = get_role_users($teacherrole->id, $context, false, $userfields);
                $teachers = array_merge($teachers, $teachersinrole);
            }
            // now add admins
            $teachers = array_merge($teachers, $admins);
            // now add helpdesk users
            $teachers = array_merge($teachers, $helpdeskusers);
            if ($teachers) {
                foreach ($teachers as $teacher) {
                    $creator = new stdClass;
                    $creator->UserKey = $this->panopto_decorate_username($teacher->username);
                    $creator->FirstName = $teacher->firstname;
                    $creator->LastName = $teacher->lastname;
                    $creator->Email = $teacher->email;
                    $creator->MailLectureNotifications = false;
                    $data->Instructors[$teacher->username] = $creator;
                }
            }
            // main students
            $students = get_role_users($studentrole->id, $context, false, $userfields);
            if ($students) {
                foreach ($students as $student) {
                    if (array_key_exists($student->username, array_keys($data->Instructors))) {
                        continue;
                    }
                    $viewer = new stdClass;
                    $viewer->UserKey = $this->panopto_decorate_username($student->username);
                    $viewer->MailLectureNotifications = false;
                    $data->Students[$student->username] = $viewer;
                }
            }

            $panoptofolderid = panopto_data::get_panopto_course_id($course->id);
            $courseslinkedtofolder = $DB->get_records('block_panopto_foldermap', array('linkedfolderid'=>$panoptofolderid));
            if ($courseslinkedtofolder) {
                foreach ($courseslinkedtofolder as $courselinkedtofolder) {
                    $context = get_context_instance(CONTEXT_COURSE, $courselinkedtofolder->courseid);
                    // Block exists in course?
                    $params = array('blockname'=>'panopto', 'parentcontextid'=>$context->id);
                    if (!$DB->record_exists('block_instances', $params)) {
                       continue; // skipping students from this paper as no block exists.
                    }
                    $students = get_role_users($studentrole->id, $context, false, $userfields);
                    if ($students) {
                        foreach ($students as $student) {
                            if (array_key_exists($student->username, array_keys($data->Instructors))) {
                                continue;
                            }
                            $viewer = new stdClass();
                            $viewer->UserKey = $this->panopto_decorate_username($student->username);
                            $viewer->MailLectureNotifications = false;
                            $data->Students[$student->username] = $viewer;
                        }
                    }

                }
            }
            return $data;
        }
    // Fetch course name and membership info from DB in preparation for provisioning operation.
    function get_provisioning_info() {
        global $DB;
        $provisioning_info->ShortName = $DB->get_field('course', 'shortname', array('id' => $this->moodle_course_id));
        $provisioning_info->LongName = $DB->get_field('course', 'fullname', array('id' => $this->moodle_course_id));
        $provisioning_info->ExternalCourseID = $this->instancename . ":" . $this->moodle_course_id;

        $course_context = context_course::instance($this->moodle_course_id, MUST_EXIST);

        // Lookup table to avoid adding instructors as Viewers as well as Creators.
        $instructor_hash = array();
         
        // moodle/course:update capability will include admins along with teachers, course creators, etc.
        // Could also use moodle/legacy:teacher, moodle/legacy:editingteacher, etc. if those turn out to be more appropriate.
        $instructors = get_users_by_capability($course_context, 'moodle/course:update');

        if(!empty($instructors)) {
            $provisioning_info->Instructors = array();
            foreach($instructors as $instructor) {
                $instructor_info = new stdClass;
                $instructor_info->UserKey = $this->panopto_decorate_username($instructor->username);
                $instructor_info->FirstName = $instructor->firstname;
                $instructor_info->LastName = $instructor->lastname;
                $instructor_info->Email = $instructor->email;
                $instructor_info->MailLectureNotifications = true;

                array_push($provisioning_info->Instructors, $instructor_info);

                $instructor_hash[$instructor->username] = true;
            }
        }

        // Give all enrolled users at least student-level access. Instructors will be filtered out below.
        // Use get_enrolled_users because, as of Moodle 2.0, capability moodle/course:view no longer corresponds to a participant list.
        $students = get_enrolled_users($course_context);

        if(!empty($students)) {
            $provisioning_info->Students = array();
            foreach($students as $student) {
                if(array_key_exists($student->username, $instructor_hash)) continue;

                $student_info = new stdClass;
                $student_info->UserKey = $this->panopto_decorate_username($student->username);

                array_push($provisioning_info->Students, $student_info);
            }
        }

        return $provisioning_info;
    }

    // Get courses visible to the current user.
    function get_courses() {
        $courses_result = $this->soap_client->GetCourses();
        $courses = array();
        if(!empty($courses_result->CourseInfo)) {
            $courses = $courses_result->CourseInfo;
            // Single-element return set comes back as scalar, not array (?)
            if(!is_array($courses)) {
                $courses = array($courses);
            }
        }
        	
        return $courses;
    }

    // Get info about the currently mapped course.
    function get_course() {
        return $this->soap_client->GetCourse($this->sessiongroup_id);
    }

    // Get ongoing Panopto sessions for the currently mapped course.
    function get_live_sessions() {
        $live_sessions_result = $this->soap_client->GetLiveSessions($this->sessiongroup_id);

        $live_sessions = array();
        if(!empty($live_sessions_result->SessionInfo)) {
            $live_sessions = $live_sessions_result->SessionInfo;
            // Single-element return set comes back as scalar, not array (?)
            if(!is_array($live_sessions)) {
                $live_sessions = array($live_sessions);
            }
        }

        return $live_sessions;
    }
    /**
     * Since people like static methods round here
     *
     * @global type $DB
     * @param type $courseid
     * @return guid : null
     */
    public static function get_linked_panopto_folder($courseid) {
        global $DB;

        $record = $DB->get_record('block_panopto_foldermap', array('courseid'=>$courseid));
        if ($record) {
            // we are sharing, linked folder has priority cause thats where recordings
            // are.
            if ($record->linkedfolderid) {
                return $record->linkedfolderid;
            }
            // no linked folder so send main
            if ($record->folderid) {
                return $record->folderid;
            }
        }
        return false;
    }
    // Get recordings available to view for the currently mapped course.
    function get_completed_deliveries() {
        $completed_deliveries_result = $this->soap_client->GetCompletedDeliveries($this->sessiongroup_id);

        $completed_deliveries = array();
        if(!empty($completed_deliveries_result->DeliveryInfo)) {
            $completed_deliveries = $completed_deliveries_result->DeliveryInfo;
            // Single-element return set comes back as scalar, not array (?)
            if(!is_array($completed_deliveries)) {
                $completed_deliveries = array($completed_deliveries);
            }
        }

        return $completed_deliveries;
    }

    // Instance method caches Moodle instance name from DB (vs. block_panopto_lib version).
    function panopto_decorate_username($moodle_username) {
        return ($this->instancename . "\\" . $moodle_username);
    }

    // We need to retrieve the current course mapping in the constructor, so this must be static.
    static function get_panopto_course_id($moodle_course_id) {
        global $DB;
        return $DB->get_field('block_panopto_foldermap', 'folderid', array('courseid' => $moodle_course_id));
    }

    // Called by Moodle block instance config save method, so must be static.
    static function set_panopto_course_id($moodle_course_id, $sessiongroup_id) {
        global $DB;
        if($DB->get_records('block_panopto_foldermap', array('courseid' => $moodle_course_id))) {
            return $DB->set_field('block_panopto_foldermap', 'folderid', $sessiongroup_id, array('courseid' => $moodle_course_id));
        } else {
            $row = (object) array('courseid' => $moodle_course_id, 'folderid' => $sessiongroup_id);
            return $DB->insert_record('block_panopto_foldermap', $row);
        }
    }

    function get_course_options() {
        $courses_by_access_level = array("Creator" => array(), "Viewer" => array(), "Public" => array());

        $panopto_courses = $this->get_courses();
        if(!empty($panopto_courses)) {
            foreach($panopto_courses as $course_info) {
                array_push($courses_by_access_level[$course_info->Access], $course_info);
            }

            $options = array();
            foreach(array_keys($courses_by_access_level) as $access_level) {
                $courses = $courses_by_access_level[$access_level];
                $group = array();
                foreach($courses as $course_info) {
                    $display_name = s($course_info->DisplayName);
                    $group[$course_info->PublicID] = $display_name;
                }
                $options[$access_level] = $group;
            }
        }
        else if(isset($panopto_courses)) {
            $options = array('Error' => array('-- No Courses Available --'));
        } else {
            $options = array('Error' => array('!! Unable to retrieve course list !!'));
        }

        return array('courses' => $options, 'selected' => $this->sessiongroup_id);
    }
}
/* End of file panopto_data.php */