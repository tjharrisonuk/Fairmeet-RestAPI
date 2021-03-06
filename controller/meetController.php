<?php
namespace fairmeet\controller;
use fairmeet\model\AttendanceList;
use fairmeet\model\AttendanceListException;
use fairmeet\model\MeetException;
use fairmeet\model\MPCalc;
use fairmeet\model\PostcodeHelper;
use fairmeet\model\Response;
use fairmeet\controller\DB;
use fairmeet\model\Meet;
use PDO;
use PDOException;

require_once ('DB.php');
require_once ('../model/Meet.php');
require_once ('../model/Response.php');
require_once ('../model/MPCalc.php');
require_once ('../model/PostcodeHelper.php');

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
    $query = $writeDB->prepare('select userid, fullname, geoLocationLon, geoLocationLat, postcode, accesstokenexpiry, useractive, loginattempts from sessions, users where sessions.userid = users.id and accesstoken = :accesstoken');
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
    $returned_userfullname = $row['fullname']; //used to add into attendance table later
    $returned_accesstokenexpiry = $row['accesstokenexpiry'];
    $returned_useractive = $row['useractive']; //for future functionality (ability to lock / block a users account)
    $returned_loginattempts = $row['loginattempts'];
    $returned_userPostcode = $row['postcode']; // to be used by POST new meet
    $returned_userGeoLat = $row['geoLocationLat']; // to be used by POST new meet
    $returned_userGeoLon = $row['geoLocationLon']; // to be used by POST new meet

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

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {

            /**
             * Can only get a list of attendees for a meet event if no attendingid
             * parameter has actually been specified in the request
             */

            if($attendingid !== '') {
                $response = new Response();
                $response->setHttpStatusCode(400); //Bad Request
                $response->setSuccess(false);
                $response->addMessage("Cannot get specified user ids attending. Must be blank");
                $response->send();
                exit();
            }

            /**
             * get a list of all users attending the meet event currently
             * by querying the attendance table with meet id and returning
             * associated userids
            */

            try{

                $query = $readDB->prepare('select userid, fullname from attendance where meetid = :meetid');
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

                /**
                 * loop through the returned userids - to find out if the logged in
                 * is able to return to this list back to the client.
                 *
                 * While doing so, add the fullnames to a list in case they do have
                 * that level of privilege.
                 */

                $attendeeArray = array();
                $validateUser = false;

                while ($row = $query->fetch(PDO::FETCH_ASSOC)) {

                    if ($row['userid'] === $returned_userid){
                        $validateUser = true;
                    }

                    $attendeeArray[] = $row['fullname'];

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

                /*$query = $readDB->prepare('select fullname from users where id = ' . $idQueryString);
                $query->execute();*/

                /*while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                    $attendeeArray[] = $row['fullname'];
                }*/

                $rowCount = $query->rowCount();

                $returnData = array();
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

            /**
             *  delete the userid from the attendance table assuming organiser or that the user id = attendingid
             */

            $validateUser = false;
            $isOrganiser = false;

            try {

                if($attendingid == $returned_userid){

                    //logged in user is the one trying to delete from attendance so...
                    $validateUser = true;

                } else {

                    // only other person able to do this is the organiser .. so check if logged user is he/she...

                    $query = $readDB->prepare('select organiser from meets where id = :meetid and organiser = :userid');
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
                    }

                    $validateUser = true;
                    $isOrganiser = true;
                }


                //Now that we have a vaid user

                if ($validateUser == true) {

                    $query = $writeDB->prepare('delete from attendance where meetid = :meetid and userid = :userid');
                    $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);

                    ($isOrganiser == true ? $query->bindParam(':userid', $attendingid, PDO::PARAM_INT) : $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT));
                    $query->execute();

                    $rowCount = $query->rowCount();

                    if ($rowCount == 0) {
                        $response = new Response();
                        $response->setHttpStatusCode(500);
                        $response->setSuccess(false);
                        $response->addMessage('Failed to remove attendee from meet event');
                        $response->send();
                        exit();
                    }

                    $calc = new MPCalc();
                    $newMeetGeo = $calc->findMidPointForMeetEvent($meetid);
                    $newLon = strval($newMeetGeo[1]);
                    $newLat = strval($newMeetGeo[0]);
                    $newPostCode = PostcodeHelper::findPostcodeFromGeoCords($newMeetGeo[0], $newMeetGeo[1]);

                    $updateQuery = $writeDB->prepare('update meets set geolocationLon=:geolocationLon, geolocationLat=:geolocationLat, postcode=:postcode where id = :meetid');
                    $updateQuery->bindParam(':geolocationLon', $newLon, PDO::PARAM_STR);
                    $updateQuery->bindParam(':geolocationLat', $newLat, PDO::PARAM_STR);
                    $updateQuery->bindParam(':postcode', $newPostCode, PDO::PARAM_STR);
                    $updateQuery->bindParam(':meetid', $meetid, PDO::PARAM_INT);
                    $updateQuery->execute();
                    //Has been successful. Return the updated list of attendees to the client.


                    $returnQuery = $readDB->prepare('select fullname from attendance where meetid = :meetid');
                    $returnQuery->bindParam(":meetid", $meetid, PDO::PARAM_INT);
                    $returnQuery->execute();


                    $rowCount = $returnQuery->rowCount();

                    $attendeeArray = array();

                    while ($row = $returnQuery->fetch(PDO::FETCH_ASSOC)) {
                        $attendeeArray[] = $row['fullname'];
                    }

                    $returnRowCount = $returnQuery->rowCount();

                    $returnData = array();
                    $returnData['rows_returned'] = $returnRowCount;
                    $returnData['attendees'] = $attendeeArray;


                    $response = new Response();
                    $response->setHttpStatusCode(200); //successful deletion
                    $response->setSuccess(true);
                    $response->addMessage("User successfully removed from Meet");
                    $response->setData($returnData);
                    $response->send();
                    exit();


                } else {
                    //logged in user doesn't have permission to delete
                    $response = new Response();
                    $response->setHttpStatusCode(401); //unauthorised
                    $response->setSuccess(false);
                    $response->addMessage("Logged in user is not authorised to delete this user from Meet");
                    $response->send();
                }


            } catch (PDOException $e){
                $response = Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage('Failed to remove attendee from meet event');
                $response->send();
                exit();
            }

        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST'){

            //add a user to the attendace list if the logged in user is meets organiser
            //or if the the user has an invite to the meet (later functionality not worrying about for now)

            /** Doesn't need to take in any parameters as these should already be stored in the
             *  users table.
             *
             *  What needs doing is the values :userid and :fullname to be copied over into
             *  attendance table if the current logged in user is the organiser of the event
             **/
            try{

                $query = $readDB->prepare('select organiser from meets where id = :meetid and organiser = :userid');
                $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
                $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
                $query->execute();

                $rowCount = $query->rowCount();

                if($rowCount === 0){
                    $response = new Response();
                    $response->setHttpStatusCode(401);
                    $response->setSuccess(false);
                    $response->addMessage('Not authorised to add new attendees to this Meet Event');
                    $response->send();
                    exit();
                }

                //first find out if the user and meet event are already in the attendance table

                $query = $readDB->prepare('select id from attendance where meetid = :meetid and userid = :userid');
                $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
                $query->bindParam(':userid', $attendingid, PDO::PARAM_INT);
                $query->execute();

                $rowCount = $query->rowCount();

                if($rowCount != 0){
                    $response = new Response();
                    $response->setHttpStatusCode(400);
                    $response->setSuccess(false);
                    $response->addMessage('User is already attending Meet');
                    $response->send();
                    exit();
                }

                //////////////


                //get the fullname from the users table for the user id to be added
                $query = $readDB->prepare('select fullname from users where id = :userid');
                $query->bindParam(':userid', $attendingid, PDO::PARAM_INT);
                $query->execute();

                $rowCount = $query->rowCount();

                if($rowCount === 0){
                    $response = new Response();
                    $response->setHttpStatusCode(404);
                    $response->setSuccess(false);
                    $response->addMessage('User not found');
                    $response->send();
                    exit();
                }

                $newName = '';

                while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                    $newName = $row['fullname'];
                }

                $query = $writeDB->prepare('insert into attendance (userid, meetid, fullname) values (:userid, :meetid, :fullname)');
                $query->bindParam(':userid', $attendingid, PDO::PARAM_INT);
                $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
                $query->bindParam(':fullname', $newName, PDO::PARAM_INT);
                $query->execute();

                $rowCount = $query->rowCount();

                if($rowCount === 0){
                    $response = new Response();
                    $response->setHttpStatusCode(500); // server error
                    $response->setSuccess(false);
                    $response->addMessage('Could not add attendee to list');
                    $response->send();
                    exit();
                }

                 //Has been successful. Return the updated list of attendees to the client.
                 //But first adjust the geocoodinates ot the meet event using the calculator

                $calc = new MPCalc();
                $newMeetGeo = $calc->findMidPointForMeetEvent($meetid);
                $newLon = strval($newMeetGeo[1]);
                $newLat = strval($newMeetGeo[0]);
                $newPostCode = PostcodeHelper::findPostcodeFromGeoCords($newMeetGeo[0], $newMeetGeo[1]);

                $updateQuery = $writeDB->prepare('update meets set geolocationLon=:geolocationLon, geolocationLat=:geolocationLat, postcode=:postcode where id = :meetid');
                $updateQuery->bindParam(':geolocationLon', $newLon, PDO::PARAM_STR);
                $updateQuery->bindParam(':geolocationLat', $newLat, PDO::PARAM_STR);
                $updateQuery->bindParam(':postcode', $newPostCode, PDO::PARAM_STR);
                $updateQuery->bindParam(':meetid', $meetid, PDO::PARAM_INT);
                $updateQuery->execute();

                $rowCount = $updateQuery->rowCount();

                if($rowCount == 0){
                    $response = new Response();
                    $response->setHttpStatusCode(500);
                    $response->setSuccess(false);
                    $response->addMessage("User has not been added to meet");
                    $response->send();
                    exit();
                }

                /** if all ok then return the attendance list to the client */

                $returnQuery = $readDB->prepare('select fullname from attendance where meetid = :meetid');
                $returnQuery->bindParam(":meetid", $meetid, PDO::PARAM_INT);
                $returnQuery->execute();

                $rowCount = $returnQuery->rowCount();

                $attendeeArray = array();

                while ($row = $returnQuery->fetch(PDO::FETCH_ASSOC)) {
                    $attendeeArray[] = $row['fullname'];
                }

                $returnRowCount = $returnQuery->rowCount();

                $returnData = array();
                $returnData['rows_returned'] = $returnRowCount;
                $returnData['attendees'] = $attendeeArray;


                $response = new Response();
                $response->setHttpStatusCode(200); //successful deletion
                $response->setSuccess(true);
                $response->addMessage("User successfully added to Meet");
                $response->setData($returnData);
                $response->send();
                exit();


            } catch (PDOException $e){
                /** TODO - server log this? */
                echo $e;
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage('Failed to add user to the meet event');
                $response->send();
                exit();
            }


        }

    }

    /** GENERAL METHODS if meet id - no attendance
     *  meet/{id}
     *
     *
     *
     *
     */

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        /** GET request
         *
         *  Get the details of a meet
         * -- must be one of the attendees to do this
         */

        try {
            /**
             *  add auth to the end when ready
             */
            $query = $readDB->prepare('select id, userid, meetid, fullname from attendance where meetid = :meetid'); /**TODO is this right? **/
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

            //first delete all rows in the attendance table that reference this meet
            $query = $writeDB->prepare('delete from attendance where meetid = :meetid');
            $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
            $query->execute();

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
            echo $e;
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

            /** @ $response */

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

} elseif (empty($_GET)){

    /** if no meetid provided
     *
     *  route: /meet
     *  // get all meets the user owns / is organiser of
     */


    if($_SERVER['REQUEST_METHOD'] === 'GET'){

        /** GET request
         *
         *
         *
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

            // ensure that mandatory fields for the meet are set .. at the moment we only require a title. todo expand if necessary

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
                null, //auto assigned
                $jsonData->title,
                (isset($jsonData->description) ? $jsonData->description : null),
                (isset($jsonData->scheduledTime) ? $jsonData->scheduledTime : null),
                (isset($jsonData->finalised) ? $jsonData->finalised : 'N'),
                $returned_userid, /** userid posting the meet will be auto assigned as its organiser */
                $returned_userGeoLon,
                $returned_userGeoLat,
                $returned_userPostcode,
                //(isset($jsonData->geolocationLon) ? $jsonData->geolocationLon : null), /** todo this should originally be set to users geopoint */
                //(isset($jsonData->geolocationLat) ? $jsonData->geolocationLat : null), // and this
                //(isset($jsonData->postcode) ? $jsonData->postcode : null), //and this
                (isset($jsonData->eventType) ? $jsonData->eventType : null),
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

             // Start transaction as the meet will need to be inserted into the db
             // the organiser (userid posting) will need also to be added into the attendance table

            $writeDB->beginTransaction();

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
            $newMeetID; //use this to add into attendance table.

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $meet = new Meet($row['id'], $row['title'], $row['description'], $row['scheduledTime'], $row['finalised'], $row['organiser'], $row['geolocationLon'], $row['geolocationLat'], $row['postcode'], $row['eventType']);
                $newMeetID = $row['id'];
                $meetArray[] = $meet->returnMeetAsArray();
            }

            //find out the name of the organiser
            $query = $readDB->prepare('select fullname from users where id = :userid');
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $orgFullname;

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $orgFullname = $row['fullname'];
            }

            /** Add the organiser to the attendee tables */
            $query = $writeDB->prepare('insert into attendance (userid, meetid, fullname) values (:userid, :meetid, :fullname)');
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':meetid', $newMeetID, PDO::PARAM_INT);
            $query->bindParam(':fullname', $orgFullname, PDO::PARAM_INT);
            $query->execute();

            /** TODO - some error checking to make sure its been added successfully */


            //transaction completed.
            $writeDB->commit();

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
            //echo $e; //**
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
        /** can't PATCH into /meets/
         *  can't DELETE /meets/
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
