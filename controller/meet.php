<?php
namespace fairmeet\controller;
use fairmeet\model\AttendanceList;
use fairmeet\model\AttendanceListException;
use fairmeet\model\MeetException;
use fairmeet\model\Response;
use fairmeet\controller\DB;
use fairmeet\model\Meet;
use PDO;
use PDOException;

require_once ('DB.php');
require_once ('../model/Meet.php');
require_once ('../model/Response.php');

try {

    $writeDB = DB::connectwriteDB();
    $readDB = DB::connectReadDB();

} catch (PDOException $e){
    error_log("Connection error - ".$e, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error");
    $response->send();
    exit();
}


/** Authorisation  */

if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1){
    $response = new Response();
    $response->setHttpStatusCode(401); //unauthorised
    $response->setSuccess(false);
    (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access Token is missing from the header") : false);
    (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access Token cannot be blank") : false);
    $response->send();
    exit();
}

$accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

try {
    //bring back user details / session details from the db
    $query = $writeDB->prepare('select userid, accesstokenexpiry, useractive, loginattempts from sessions, users where sessions.userid = users.id and accesstoken = :accesstoken');
    $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    //check that there is a session for this access token
    if ($rowCount === 0) {
        $response = new Response();
        $response->setHttpStatusCode(401); //unauthorised
        $response->setSuccess(false);
        $response->addMessage("Invalid access token");
        $response->send();
        exit();
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_userid = $row['userid'];
    $returned_accesstokenexpiry = $row['accesstokenexpiry'];
    $returned_useractive = $row['useractive'];
    $returned_loginattempts = $row['loginattempts'];

    /** ensure that the user is active, that they aren't locked out
     *  and that the access token is still valid
     */
    if ($returned_useractive !== 'Y') {
        $response = new Response();
        $response->setHttpStatusCode(401); //unauthorised
        $response->setSuccess(false);
        $response->addMessage("User Account Not Active");
        $response->send();
        exit();
    }

    if ($returned_loginattempts >= 3) {
        $response = new Response();
        $response->setHttpStatusCode(401); //unauthorised
        $response->setSuccess(false);
        $response->addMessage("User Account is currently locked out");
        $response->send();
        exit();
    }

    if (strtotime($returned_accesstokenexpiry) > time()) { /** TODO - time comparison not working as it should. */
        $response = new Response();
        $response->setHttpStatusCode(401); //unauthorised
        $response->setSuccess(false);
        $response->addMessage("Access token expired");
        $response->send();
        exit();
    }

} catch (PDOException $e){
    $response = new Response();
    $response->setHttpStatusCode(500); //unauthorised
    $response->setSuccess(false);
    $response->addMessage("There was an issue authenticating, please try again");
    $response->send();
    exit();

}
//end auth script



/** if we have a meeting id
 *
 *  example route: /meet/1009
 *
 */
if(array_key_exists("meetid", $_GET)) {

    $meetid = $_GET['meetid'];

    //validate not blank, is numeric
    if ($meetid == '' || !is_numeric($meetid)) {
        $response = new Response();
        $response->setHttpStatusCode(400); //Bad Request
        $response->setSuccess(false);
        $response->addMessage("Meet Id cannot be blank, must be numeric");
        $response->send();
        exit();
    }

    /** ATTENDANCE FUNCTIONALITY
     *
     * GET - no attending value needed - route = /meet.php?meetid={meetid}&attending == /meet/{meetid}/attendance
     *  returns a list of full names of users attending that meet (possibly their user ids???)
     *
     * DELETE - attending value mandatory - route = /meet.php?meetid={id}&attending={userid} == /meet/{meetid}/attendance/{userid}
     *
     * POST - attending value mandatory - route = /meet.php?meetid={id}&attending={userid} == /meet/{meetid}/attendance/{userid}
     */
    if(array_key_exists("attending", $_GET)){

        $attendingid = $_GET['attending'];

        /** TODO - should actually check whether or not an "attendingid" has been
         *  TODO provided, in POST or delete this would be a userid.
         */
        if(isset($attendingid) && ($attendingid != '')){
            echo 'yes';
        } else {
            echo 'no';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {

            //get a list of all users attending the meet event currently
            //by querying the attendance table with meet id and returning
            //associated userids


            //can then query the user table for their names OR refactor so that attendance table has
            //a copy of their names as well.

            try{

                $query = $readDB->prepare('select userid from attendance where meetid = :meetid');
                $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
                $query->execute();

                $rowCount = $query->rowCount();

                if($rowCount == 0){
                    $response = new Response();
                    $response->setHttpStatusCode(404); //not found
                    $response->setSuccess(false);
                    $response->addMessage("No attendees for this meet found");
                    $response->send();
                    exit();
                }

                /** do a validation check to ensure that the user requesting is
                 *  an attendee of the meet event.
                 */
                $validateUser = false;
                $attendeeArray = array();

                $idQueryString = "";
                $i = 0;

                /**
                 * loop through the returned userids - ensure that the current
                 * user is registered as an attendee and build up query string
                 * so that fullnames can be returned to the client.*/


                while ($row = $query->fetch(PDO::FETCH_ASSOC)) {

                    if ($row['userid'] === $returned_userid){
                        $validateUser = true;
                    }

                    if($i == 0) {
                        $idQueryString =  "" . $row['userid'] . "";
                    } else if ($i >= 1) {
                        $idQueryString .= " or id = " . $row['userid'] . "";
                    }

                    $i = $i + 1;
                }

                //if the user isn't currently in the attendance list for the meet event.
                if($validateUser == false){
                    $response = new Response();
                    $response->setHttpStatusCode(401); //unauthorised
                    $response->setSuccess(false);
                    $response->addMessage("Unauthorised user"); // or maybe the meet doesn't have any attendees yet
                    $response->send();
                    exit();
                }

                $query = $readDB->prepare('select fullname from users where id = ' . $idQueryString);
                $query->execute();

                $attendeeArray = array();

                while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                    $attendeeArray[] = $row['fullname'];
                }

                $rowCount = $query->rowCount();

                $returnDate = array();
                $returnData['rows_returned'] = $rowCount;
                $returnData['attendees'] = $attendeeArray;

                $response = new Response();
                $response->setHttpStatusCode(200);
                $response->setSuccess(true);
                $response->toCache(true);
                $response->setData($returnData);
                $response->send();
                exit();


            } catch (PDOException $e){
                error_log("Database query error - " . $e, 0);
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to get Meet" . $e);
                $response->send();
                exit();
            }

        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE'){

            //delete the userid from the attendance table assuming organiser
            //or that the user id = attendingid

            $validateUser = false;
            $isOrganiser = false;

            if($attendingid == $returned_userid){
                $validateUser = true;
            } else {
                //query the meet table to see if the logged in user is the organiser

                try{
                    $query = $readDB('select organiser from meets where id = :meetid and organiser = :userid');
                    $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
                    $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
                    $query->execute();

                    $rowCount = $query->rowCount();

                    if($rowCount === 0){
                        $response = new Response();
                        $response->setHttpStatusCode(401);
                        $response->setSuccess(false);
                        $response->addMessage('Not authorised to delete from this meet event');
                        $response->send();
                        exit();
                    } else if ($rowCount > 1) {
                        //something has gone badly wrong
                        echo "you should never see this";
                    } else {
                        $validateUser = true;
                    }
                } catch (PDOException $e) {
                    $response = new Response();
                    $response->setHttpStatusCode(500);
                    $response->setSuccess(false);
                    $response->addMessage('Database error - failed to remove attendee from meet event');
                    $response->send();
                    exit();
                }
            }

            if ($validateUser == true){
                $query = $writeDB->prepare('delete from attendance where meetid = :meetid and userid = :userid');
                $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
                if($isOrganiser = true) {
                    $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
                } else {
                    $query->bindParam(':userid', $attendingid, PDO::PARAM_INT);
                }
                $query->execute();

                $rowCount = $query->rowCount();

                if($rowCount == 0){
                    $response = new Response();
                    $response->setHttpStatusCode(500);
                    $response->setSuccess(false);
                    $response->addMessage('Failed to remove attendee from meet event');
                    $response->send();
                    exit();
                }

                //return the new attendee list to the client




            }




        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST'){

            //add a user to the attendace list if the logged in user is meets organiser
            //or if the the user has an invite to the meet (later functionality not worrying about for now)


        }
    }


    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        /** GET request
         *
         *  Get the details of a meet
         * -- must be one of the attendees to do this
         * -- attendance add / remove in attendance class
         */

        try {
            /**
             *  add auth to the end when ready
             */
            $query = $readDB->prepare('select id, userid, meetid from attendance where meetid = :meetid');
            $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            //validate that the meet id exists
            if($rowCount === 0) {
                //no meet found with that id
                $response = new Response();
                $response->setHttpStatusCode(404); //unauthorised
                $response->setSuccess(false);
                $response->addMessage("Meet not found");
                $response->send();
                exit();
            }

            /** Ensure that the user is an attendee for this meetup, and add the user ids of
             * all other users to an attendance array
             */
            $validateUser = false;
            $attendeeArray = array();

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                if ($row['userid'] === $returned_userid){
                    $validateUser = true;
                }
                $attendeeArray[] = $row['userid'];
            }

            if($validateUser === false){
                $response = new Response();
                $response->setHttpStatusCode(401); //unauthorised
                $response->setSuccess(false);
                $response->addMessage("Unauthorised user");
                $response->send();
                exit();
            }


            $query = $readDB->prepare("select id, title, description, DATE_FORMAT(scheduledTime, '%d/%m/%Y %H:%i') as scheduledTime, finalised, organiser, geolocationLon, geolocationLat, postcode, eventType from meets where id = :meetid");
            $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $meet = new Meet($row['id'], $row['title'], $row['description'], $row['scheduledTime'], $row['finalised'], $row['organiser'], $row['geolocationLon'], $row['geolocationLat'], $row['postcode'], $row['eventType']);
                //$meet->setAttendees($attendeeArray);
                $meetArray[] = $meet->returnMeetAsArray();
            }

            $returnDate = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['meet'] = $meetArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (PDOException $e) {
            error_log("Database query error - " . $e, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Meet" . $e);
            $response->send();
            exit();
        } catch (MeetException $me) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($me->getMessage());
            $response->send();
            exit();
        }


    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

        /** DELETE request
         *
         *  Delete a meet
         *  -- must be the organiser in order to do this
         *
         */

        try {

            $query = $writeDB->prepare('delete from meets where id = :meetid and organiser = :userid');
            $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404); //trying to delete a meet that doesn't exist
                $response->setSuccess(false);
                $response->addMessage('Meet not found');
                $response->send();
                exit();
            }

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage('Meet deleted');
            $response->send();
            exit();

        } catch (PDOException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage('Failed to delete meet');
            $response->send();
            exit();
        }


    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

        /** PATCH request
         *
         *  Update a meet events details -- requires a meet id
         *  -- must be the organiser to do this
         *
         */

        try {

            //check that we're dealing with json data
            if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Content Type header not set to json");
                $response->send();
                exit();
            }

            $rawPatchData = file_get_contents('php://input');

            //try to decode patch data as json
            if (!$jsonData = json_decode($rawPatchData)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Request body not valid JSON");
                $response->send();
                exit();
            }

            //possible fields that may have to be changed
            //attendees can't be updated from this endpoint
            $title_updated = false;
            $description_updated = false;
            $scheduledTime_updated = false;
            $finalised_updated = false;
            $geolocationLon_updated = false;
            $geolocationLat_updated = false;
            $postcode_updated = false;
            $eventType_updated = false;

            //need to dynamically build query depending on which fields need to be updated
            $queryFields = "";

            if (isset($jsonData->title)) {
                $title_updated = true;
                $queryFields .= "title = :title, ";
            }

            if (isset($jsonData->description)) {
                $description_updated = true;
                $queryFields .= "description = :description, ";
            }

            if (isset($jsonData->scheduledTime)) {
                $scheduledTime_updated = true;
                $queryFields .= "scheduledTime = STR_TO_DATE(:scheduledTime, '%d/%m/%Y %H:%i), ";
            }

            if (isset($jsonData->finalised)) {
                $finalised_updated = true;
                $queryFields .= "finalised = :finalised, ";
            }

            if (isset($jsonData->geolocationLon)) {
                $geolocationLon_updated = true;
                $queryFields .= "geolocationLon = :geolocationLon, ";
            }

            if (isset($jsonData->geolocationLat)) {
                $geolocationLat_updated = true;
                $queryFields .= "geolocationLat = :geolocationLat, ";
            }

            if (isset($jsonData->postcode)) {
                $postcode_updated = true;
                $queryFields .= "postcode= :postcode, ";
            }

            if (isset($jsonData->eventType)) {
                $eventType_updated = true;
                $queryFields .= "eventType= :eventType, ";
            }

            //remove the last comma and space so that this will be a valid mySQL query
            $queryFields = rtrim($queryFields, ", ");

            //if client hasn't actually updated anything
            if ($title_updated === false && $description_updated == false && $scheduledTime_updated == false && $finalised_updated == false && $geolocationLon_updated == false && $geolocationLat_updated == false && $postcode_updated == false && $eventType_updated == false) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("No meet fields provided");
                $response->send();
                exit();
            }

            //get the original meet from the database (assumings its there and the user requesting is the organiser)
            //select id, title, description, DATE_FORMAT(scheduledTime, "%d/%m/%Y %H:%i") as scheduledTime, finalised, organiser, geolocationLon, geolocationLat, postcode, eventType from meets where id = :lastMeetID and organiser = :organiser'

            $query = $writeDB->prepare('select id, title, description, DATE_FORMAT(scheduledTime, "%d/%m/%Y %H:%i") as scheduledTime, finalised, organiser, geolocationLon, geolocationLat, postcode, eventType from meets where id = :meetid and organiser = :userid');
            $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404); //not found
                $response->setSuccess(false);
                $response->addMessage("No meet found to update");
                $response->send();
                exit();
            }

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $meet = new Meet($row['id'], $row['title'], $row['description'], $row['scheduledTime'], $row['finalised'], $row['organiser'], $row['geolocationLon'], $row['geolocationLat'], $row['postcode'], $row['eventType']);
            }

            //start building the update query
            $queryString = 'update meets set '.$queryFields." where id = :meetid and organiser = :userid";
            $query = $writeDB->prepare($queryString);

            if($title_updated === true) {
                //set and then get back out, so its gone through validation
                //might have had some formatting etc. as well.
                $meet->setTitle($jsonData->title);
                $up_title = $meet->getTitle();
                $query->bindParam(':title', $up_title, PDO::PARAM_STR);
            }

            if($description_updated === true){
                $meet->setDescription($jsonData->description);
                $up_description = $meet->getDescription();
                $query->bindParam(':description', $up_description, PDO::PARAM_STR);
            }

            if($scheduledTime_updated === true){
                $meet->setScheduledTime($jsonData->scheduledTime);
                $up_scheduledTime = $meet->getScheduledTime();
                $query->bindParam(':scheduledTime', $up_scheduledTime, PDO::PARAM_STR);
            }

            if($finalised_updated === true){
                $meet->setFinalised($jsonData->finalised);
                $up_finalised = $meet->getFinalised();
                $query->bindParam(':finalised', $up_finalised, PDO::PARAM_STR);
            }

            if($geolocationLon_updated === true){
                $meet->setGeolocationLon($jsonData->geolocationLon);
                $up_geolocationLon = $meet->getGeolocationLon();
                $query->bindParam(':geolocationLon', $up_geolocationLon, PDO::PARAM_STR);
            }

            if($geolocationLat_updated === true) {
                $meet->setGeolocationLat($jsonData->geolocationLat);
                $up_geolocationLat = $meet->getGeolocationLat();
                $query->bindParam(':geolocationLat', $up_geolocationLat, PDO::PARAM_STR);
            }

            //postcode //eventType
            if($postcode_updated === true){
                $meet->setPostcode($jsonData->postcode);
                $up_postcode = $meet->getPostCode();
                $query->bindParam(':postcode', $up_postcode, PDO::PARAM_STR);
            }

            if($eventType_updated === true){
                $meet->setEventType($jsonData->eventType);
                $up_eventType = $meet->getEventType();
                $query->bindParam(':eventType', $up_eventType, PDO::PARAM_STR);
            }

            $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount == 0){
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Meet not updated");
                $response->send();
                exit();
            }

            //Get the newly updated meet event out of the database and return it to the user

            $query = $writeDB->prepare('select id, title, description, DATE_FORMAT(scheduledTime, "%d/%m/%Y %H:%i") as scheduledTime, finalised, organiser, geolocationLon, geolocationLat, postcode, eventType from meets where id = :meetid and organiser = :userid');
            $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount == 0){
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("No meet found after update".$rowCount);
                $response->send();
                exit();
            }

            $meetArray = array();

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $meet = new Meet($row['id'], $row['title'], $row['description'], $row['scheduledTime'], $row['finalised'], $row['organiser'], $row['geolocationLon'], $row['geolocationLat'], $row['postcode'], $row['eventType']);
                $meetArray[] = $meet->returnMeetAsArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['meets'] = $meetArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage('Meet updated');
            $response->setData($returnData);
            $response->send();
            exit();


        } catch (MeetException $e){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit();

        } catch (PDOException $e){
            error_log("Database Query Error - ".$e, 0);
            echo $e;
            $response = new Response();
            $response->setHttpStatusCode(500); //server error
            $response->setSuccess(false);
            $response->addMessage("Failed to update meet - check your data for errors");
            $response->send();
            exit();
        }

    } else {

        /** POST request
         *
         * Can't POST to a specific meetID as these
         * will be generated by the system
         *
         */
        $response = new Response();
        $response->setHttpStatusCode("405"); //method not allowed.
        $response->setSuccess(false);
        $response->addMessage("Request Message Not Allowed");
        $response->send();
        exit();
    }

} elseif(array_key_exists("attending", $_GET)){

    /** meets/attending
     *
     * this might need to be in its own endpoint /attending/
     *
     */
    echo "this is the attending endpoint";

    //GET to get a list of all users attending



} elseif (empty($_GET)){

    /** if no meetid provided
     *
     *  route: /meet
     *  // get all meets the user owns / is organiser of
     */


    if($_SERVER['REQUEST_METHOD'] === 'GET'){

        /** GET request
         *
         *  TODO - return all meets - that this user is organsing
         *  TODO - need a seperate REST method somewhere for getting all meets they are attending
         */
            //first need to get a list of all meets the user is an attendee of
            //so query the attendance table.

            /* TODO - make this a transaction as we're going to query the db twice, on different tables... or maybe join */
            /*$query = $readDB->prepare('select meetid from attendance where userid = :userid');
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $userattendsArray = array();

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $userattendsArray[] = $row['meetid'];
            }

            foreach ($userattendsArray as $value){
                echo $value;
            }*/

            /** for now just return a list of meets that the user is the organiser of */
            try {

                $query = $readDB->prepare('select id, title, description, DATE_FORMAT(scheduledTime, "%d/%m/%Y %H:%i") as scheduledTime, finalised, organiser, geolocationLon, geolocationLat, postcode, eventType from meets where organiser = :userid');
                $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
                $query->execute();

                $rowCount = $query->rowCount();
                $meetArray = array();

                while($row = $query->fetch(PDO::FETCH_ASSOC)){
                    $meet = new Meet($row['id'], $row['title'], $row['description'], $row['scheduledTime'], $row['finalised'], $row['organiser'], $row['geolocationLon'], $row['geolocationLat'], $row['postcode'], $row['eventType']);
                    $meetArray[] = $meet->returnMeetAsArray();
                }

                $returnData = array();
                $returnData['rows_returned'] = $rowCount;
                $returnData['meets'] = $meetArray;

                $response = new Response();
                $response->setHttpStatusCode(200);
                $response->setSuccess(true);
                $response->toCache(true);
                $response->setData($returnData);
                $response->send();
                exit();

            } catch (MeetException $e){

            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit();

            } catch (PDOException $e){
            error_log("Database query error - ".$e, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get meets");
            $response->send();
            exit();
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST'){
        /** POST request
         *  Create a new meet
         *  Add entry to the attendance table - organiser should be attending meet up event
         *
         */
        try {
            //ensure that headers are set
            if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Content type header not set to JSON");
                $response->send();
                exit();
            }

            //get raw data
            $rawPostData = file_get_contents('php://input');

            //make sure that the POST data is valid JSON
            if(!$jsonData = json_decode($rawPostData)){
                $response = new Response();
                $response->setHttpStatusCode(400); //bad request
                $response->setSuccess(false);
                $response->addMessage("Request body not valid JSON");
                $response->send();
                exit();
            }

            /** TODO - find mandatory fields...
             *  validate on that basis
             *  we'll definitely need a title, so putting that in for time being
             */
            if(!isset($jsonData->title)){
                $response = new Response();
                $response->setHttpStatusCode(400); //bad request
                $response->setSuccess(false);
                (!isset($jsonData->title) ? $response->addMessage("Title field is mandatory and must be provided") : false);
                $response->send();
                exit();
            }

            //try to create new meet based on that. If that fails, it'll throw a meet exception
            $newMeet = new Meet(
                null,
                $jsonData->title,
                (isset($jsonData->description) ? $jsonData->description : null),
                (isset($jsonData->scheduledTime) ? $jsonData->scheduledTime : null),
                (isset($jsonData->finalised) ? $jsonData->finalised : 'N'),
                $returned_userid, /** TODO - decide on this should it be up to client?? */
                (isset($jsonData->geolocationLon) ? $jsonData->geolocationLon : null),
                (isset($jsonData->geolocationLat) ? $jsonData->geolocationLat : null),
                (isset($jsonData->postcode) ? $jsonData->postcode : null),
                (isset($jsonData->eventType) ? $jsonData->eventType : null),
                /** TODO - seperate out attendance from meet *///null //start with no attendees
            );

            //get variables back out of the newly created meet object, they will now be validated
            $title = $newMeet->getTitle();
            $description = $newMeet->getDescription();
            $scheduledTime = $newMeet->getScheduledTime();
            $finalised = $newMeet->getFinalised();
            $organiser = $newMeet->getOrganiser();
            $geolocationLon = $newMeet->getGeolocationLon();
            $geolocationLat = $newMeet->getGeolocationLat();
            $postcode = $newMeet->getPostCode();
            $eventType = $newMeet->getEventType();

            //insert into database
            $query = $writeDB->prepare('insert into meets (id, title, description, scheduledTime, finalised, geolocationLon, geolocationLat, postcode, eventType, organiser) values (null, :title, :description, STR_TO_DATE(:scheduledTime, \'%d/%m/%Y %H:%i\'), :finalised, :geolocationLon, :geolocationLat, :postcode, :eventType, :organiser)');
            $query->bindParam(':title', $title, PDO::PARAM_STR);
            $query->bindParam(':description', $description, PDO::PARAM_STR);
            $query->bindParam(':scheduledTime', $scheduledTime, PDO::PARAM_STR);
            $query->bindParam(':finalised', $finalised, PDO::PARAM_STR);
            $query->bindParam(':organiser', $returned_userid, PDO::PARAM_STR); //set organiser to the current user
            $query->bindParam(':geolocationLon', $geolocationLon, PDO::PARAM_STR);
            $query->bindParam(':geolocationLat', $geolocationLat, PDO::PARAM_STR);
            $query->bindParam(':postcode', $postcode, PDO::PARAM_STR);
            $query->bindParam(':eventType', $eventType, PDO::PARAM_STR);

            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount == 0){
                $response = new Response();
                $response->setHttpStatusCode(500); //server issue
                $response->setSuccess(false);
                $response->addMessage("Failed to create meet");
                $response->send();
                exit();
            }

            //return the inserted meet to the user as per REST API best practice
            $lastMeetID = $writeDB->lastInsertId();

            $query = $writeDB->prepare('select id, title, description, DATE_FORMAT(scheduledTime, "%d/%m/%Y %H:%i") as scheduledTime, finalised, organiser, geolocationLon, geolocationLat, postcode, eventType from meets where id = :lastMeetID and organiser = :organiser');
            $query->bindParam(':lastMeetID', $lastMeetID, PDO::PARAM_INT);
            $query->bindParam(':organiser', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount == 0){
                $response = new Response();
                $response->setHttpStatusCode(500); //server error
                $response->setSuccess(false);
                $response->addMessage("Failed to retrieve meet after creation");
                $response->send();
                exit();
            }

            $meetArray = array();

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $meet = new Meet($row['id'], $row['title'], $row['description'], $row['scheduledTime'], $row['finalised'], $row['organiser'], $row['geolocationLon'], $row['geolocationLat'], $row['postcode'], $row['eventType']);
                $meetArray[] = $meet->returnMeetAsArray();
            }


            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['meet'] = $meetArray;

            $response = new Response();
            $response->setHttpStatusCode(201); //successfully created
            $response->setSuccess(true);
            $response->addMessage("Meet created");
            $response->setData($returnData);
            $response->send();
            exit();


        } catch (PDOException $e){
            error_log("Database query error - ".$e, 0);
            echo $e; //** TODO remove when not needed */
            $response = new Response();
            $response->setHttpStatusCode(500); //incorrect data
            $response->setSuccess(false);
            $response->addMessage("Failed to insert Meet into database");
            $response->send();
            exit();
        } catch (MeetException $e){
            $response = new Response();
            $response->setHttpStatusCode(400); //incorrect data
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit();
        }

    } else {
        /** can't patch into /meets/
         *  can't delete /meets/
         */
        $response = new Response();
        $response->setHttpStatusCode(400); //bad request
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed on this endpoint");
        $response->send();
        exit();
    }

}









/** show all finalised or unfinalised meets
 *
 *  maybe in v2
 *
 */

/** pagination
 *
 * maybe in v2
 *
 */
