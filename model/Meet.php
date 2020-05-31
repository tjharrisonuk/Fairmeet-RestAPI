<?php

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
    private $_eventType;


    /* Getters */

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

    /* Setters including database validation*/
    public function setId($id){

        // Can't be null
        // Must be numeric
        // Can't have a negative value
        // Cant be greater than BIGINT
        // Id can't have already been set

        if(($id !== null) && (!is_numeric($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null)){
            throw new MeetException("Meet Id Error");
        }
        $this->_id = $id;
    }

    public function setTitle($title){
        if(strlen($title) < 0 || strlen($title) > 255){
            throw new TaskException("Meet title error");
        }

        $this->_title = $title;
    }

    public function setDescription($description){
        if(($description !== null) && (strlen($description) > 147772145)){
            throw new TaskException("Task description error");
        }

        $this->_description = $description;
    }

    public function setScheduledTime($scheduledTime){

        if(($scheduledTime !== null) && date_format(date_create_from_format('d/m/Y H:i', $scheduledTime), 'd/m/Y H:i') != $scheduledTime){
            throw new TaskException("Meet Scheduled Time error");
        }

        $this->_scheduledTime = $scheduledTime;

    }


    /**
     * Deal with attendees
     */

    public function addAttendee($attendee){
        //add an attendee to the array

        //add meetid and us
    }

    public function removeAttendee($attendee){
        //remove an attendee from the array
    }

    private function setGeolocation(){
        /** TODO - code for calculating the geolocation
         *
         */

        //iterate through the attendees array,
        //store each geolocation

        /** ||id||userid||meetid */
    }

}
