<?php
namespace fairmeet\model;
use Exception;
class MeetException extends Exception {}

class Meet {

    private $_id;
    private $_title; //eg. Tom's Birthday Drinks
    private $_description;
    private $_scheduledTime; //a scheduled time that can be adjusted
    private $_finalised; //boolean has the event been confirmed
    private $_organiser; //a user object of id
    private $_attendees = array(); //an array of user objects or ids
    private $_geolocation;
    private $_postcode;
    private $_eventType; //eg bar

    public function __construct($id, $title, $description, $scheduledTime, $finalised, $organiser, $attendees, $geolocation, $postcode, $eventType){
        $this->setId($id);
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setScheduledTime($scheduledTime);
        $this->setFinalised($finalised);
        $this->setOrganiser($organiser);
        $this->setAttendees($attendees);
        $this->setGeolocation($geolocation);
        $this->setPostcode($postcode);
        $this->setEventType($eventType);
    }

    /** Getters */

    public function getID(){
        return $this->_id;
    }

    public function getTitle(){
        return $this->_title;
    }

    public function getDescription(){
        return $this->_description;
    }

    public function getScheduledTime(){
        return $this->_scheduledTime;
    }

    public function getFinalised(){
        return $this->_finalised;
    }

    public function getOrganiser(){
        return $this->_organiser;
    }

    public function getAttendees(){
        return $this->_attendees;
    }

    public function getGeolocation(){
        return $this->_geolocation;
    }

    public function getPostCode(){
        return $this->_postcode;
    }

    public function getEventType(){
        return $this->_eventType;
    }

    /** Setters */

    public function setId($id){

        // Can't be null
        // Must be numeric
        // Can't have a negative value
        // Cant be greater than BIGINT
        // Id can't have already been set

        if(($id !== null) && (!is_numeric($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null)){
            throw new Exception("Meet Id Error");
        }
        $this->_id = $id;
    }

    public function setTitle($title){
        if(strlen($title) < 0 || strlen($title) > 255){
            throw new MeetException("Meet title error");
        }

        $this->_title = $title;
    }

    public function setDescription($description){
        if(($description !== null) && (strlen($description) > 147772145)){
            throw new MeetException("MEet description error");
        }

        $this->_description = $description;
    }

    public function setScheduledTime($scheduledTime){

        if(($scheduledTime !== null) && date_format(date_create_from_format('d/m/Y H:i', $scheduledTime), 'd/m/Y H:i') != $scheduledTime){
            throw new MeetException("Meet Scheduled Time error");
        }

        $this->_scheduledTime = $scheduledTime;

    }

    public function setOrganiser($organiser){
        /** TODO - validation on this */
        $this->_organiser = $organiser;
    }

    public function setFinalised($finalised){
        /** TODO - validation on this */
        $this->_finalised = $finalised;
    }

    public function setAttendees($attendees){
        /** TODO - validation on this */
        $this->_attendees = $attendees;

    }

    public function setGeolocation($geolocation){
        /** TODO - validation on this
         *
         *  Consider whether this should be a public or
         *  private
         *
         */
        $this->_geolocation = $geolocation;
    }

    public function setPostcode($postcode){
        /** TODO - validation on this
         *
         * API call to postcodes.io??
         *
         */

        $this->_postcode = $postcode;
    }

    /**
     * Class Methods
     */

    public function addAttendee($attendee){
       /** TODO - addAttendee functionality for meet */

        //add an attendee to the array

        //add meetid and us
    }

    public function removeAttendee($attendee){
        //remove an attendee from the array
        /** TODO - removeAttendee functionality for meet */
    }


}
