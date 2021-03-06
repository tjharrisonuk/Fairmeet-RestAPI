<?php
namespace fairmeet\model;
use Exception;
class MeetException extends Exception {}

class Meet {

    private $_id;
    private $_title; // mandatory
    private $_description; // optional
    private $_scheduledTime; //a scheduled time that can be adjusted
    private $_finalised; //enum Y or N - has the event been confirmed
    private $_geolocationLon; //optional for geolocation and postcode - can be created without these fields
    private $_geolocationLat;
    private $_postcode;
    private $_eventType; //eg bar - optional
    //private $_attendees = array(); //an array of userids



    public function __construct($id, $title, $description, $scheduledTime, $finalised, $organiser, $geolocationLon, $geolocationLat, $postcode, $eventType){ //attendees
        $this->setId($id);
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setScheduledTime($scheduledTime);
        $this->setFinalised($finalised);
        $this->setOrganiser($organiser);
        $this->setGeolocationLon($geolocationLon);
        $this->setGeolocationLat($geolocationLat);
        $this->setPostcode($postcode);
        $this->setEventType($eventType);
        //$this->setAttendees($eventType);
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

    public function getGeolocationLon(){
        return $this->_geolocationLon;
    }

    public function getGeolocationLat(){
        return $this->_geolocationLat;
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
        //description stored as mediumint in db
        if(($description !== null) && (strlen($description) > 147772145)){
            throw new MeetException("Meet description error");
        }

        $this->_description = $description;
    }

    public function setScheduledTime($scheduledTime){

        if(($scheduledTime !== null) && date_format(date_create_from_format('d/m/Y H:i', $scheduledTime), 'd/m/Y H:i') != $scheduledTime){
            throw new MeetException("Meet deadline date and time error");
        }

        $this->_scheduledTime = $scheduledTime;

    }

    public function setOrganiser($organiser){
        /**
         * must be an integer as this is a passed in userid
         *
        */
        if(!is_int($organiser)){
            throw new MeetException("Not a valid organiser");
        }
        $this->_organiser = $organiser;
    }

    public function setFinalised($finalised){

        if(strtoupper($finalised) !== 'Y' && strtoupper($finalised) !== 'N'){
            throw new MeetException("Meet finalised must be Y or N");
        }

        $this->_finalised = $finalised;

    }

    public function setEventType($eventType){

        if(strlen($eventType) < 0 || strlen($eventType) > 255){
            throw new MeetException("Meet Event Type error");
        }

        $this->_eventType = $eventType;

    }

    /*public function setAttendees($attendees){
        /**
         * TODO - validation on this -- should it really be modelled here??
         *
         *  must be an array of users??

        $this->_attendees = $attendees;
    }*/

    public function setGeolocationLon($geolocationLon){
        /** TODO - validation on this
         *
         *  Consider whether this should be a public or
         *  private
         *
         */
        $this->_geolocationLon = $geolocationLon;
    }

    public function setGeolocationLat($geolocationLat){
        /** TODO - validation on this
         *
         *  Consider whether this should be a public or
         *  private
         *
         */
        $this->_geolocationLat = $geolocationLat;
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

    /*public function addAttendee($attendee){
       /** TODO - addAttendee functionality for meet */

        //add an attendee to the array
        //add meetid and us
        //may need to be worked out on client side
    //}

    /*public function removeAttendee($attendee){
        //remove an attendee from the array
        /**
         * TODO - removeAttendee functionality for meet
         * may need to be worked out on client side
         */
    //}

    public function returnMeetAsArray(){
        $meet = array();
        $meet['id'] = $this->getID();
        $meet['title'] = $this->getTitle();
        $meet['description'] = $this->getDescription();
        $meet['scheduledTime'] = $this->getScheduledTime();
        $meet['finalised'] = $this->getFinalised();
        $meet['organiser'] = $this->getOrganiser();
        $meet['geolocationLon'] = $this->getGeolocationLon();
        $meet['geolocationLat'] = $this->getGeolocationLat();
        $meet['postcode'] = $this->getPostcode();
        $meet['eventType'] = $this->getEventType();
        /*$meet['attendees'] = $this->getAttendees(); //remember this is an array too
        */
        return $meet;
    }

}
