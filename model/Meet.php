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

    /* Setters including database validation*/


}
