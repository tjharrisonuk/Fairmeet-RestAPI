<?php
namespace fairmeet\model;
use Exception;
class AttendanceListException extends Exception{}

class AttendanceList{

    private $_id;
    private $_meetid;
    private $_attendees = array();

    public function __construct($id, $meetid, $attendees){
        $this->setId($id);
        $this->setMeetId($meetid);
        $this->setAttendees($attendees);
    }

    public function getId(){
        return $this->_id;
    }

    public function getMeetId(){
        return $this->_meetid;
    }

    public function getAttendees(){
        return $this->_attendees;
    }

    public function setId($id){
        /**
         * can't be null, must be numeric, no neg values, not greater than BIGINT
         */
        if(($id !== null) && (!is_numeric($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null)){
            throw new Exception("Attendance List Id Error");
        }
        $this->_id = $id;
    }

    public function setMeetId($meetid){
        /**
         * can't be null, must be numeric, no neg vals, not greater than BIGINT
         */
        if(($meetid !== null) && (!is_numeric($meetid) || $meetid <= 0 || $meetid > 9223372036854775807 || $this->_meetid !== null)){
            throw new Exception("Attendance List Id Error");
        }
        $this->_id = $id;
    }

    public function setAttendees($attendees){
        /** must be an array of integers (user ids) with each int conforming to the standard set for a userid*/
        //first check that the data type actually is an array
        if(!is_array($attendees)){
            throw new AttendanceListException('Attendees must be an array');
        }
        //make sure that every userid in the array is a valid one
        /**TODO - decide on whether this needed*/
        foreach ($attendees as $userId){
            if(!is_numeric($userId) || $userId <= 0 || $userId > 9223372036854775807){
                throw new Exception("Attendance List Id Error");
            }
        }
        $this->_attendees = $attendees;
    }

    private function getAllUserGeos(){
        /**method to generate array with lat and lon information for
         * every user attending the meet
         * TODO - this is just one idea for implementation at this stage
         * */

        foreach ($this->getAttendees() as $a){

            //open db connection
            //read geo info for each user
            //add info to multi-dem array

            // || id || userid || geocodelon || geocodelat

            

        }

    }

    public function returnAttendanceListAsArray(){
        $attendanceList = array();
        $attendanceList['id'] = $this->getId();
        $attendanceList['meetId'] = $this->getMeetId();
        $attendanceList['attendees'] = $this->getAttendees();
    }


}
