<?php

require_once('Meet.php');
use fairmeet\model\Meet;
use fairmeet\model\MeetException;

// public function __construct($id, $title, $description, $scheduledTime, $finalised, $organiser, $geolocationLon, $geolocationLat, $postcode, $eventType){

try {
    $meet = new Meet(null, "Title", "desc", "01/01/2020 12:00", "y", 3, "o321982913", "wdokowq223", "E17 6ZA", "drinks");
    header('Content-type: application/json;charset=UTF-8');
    echo json_encode($meet->returnMeetAsArray());

} catch (MeetException $e){
    echo "Error ".$e->getMessage();
}

