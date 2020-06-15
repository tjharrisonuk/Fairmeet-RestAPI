<?php
namespace fairmeet\controller;
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

//if(array_key_exists("attendance"))

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
        $response->addMessage("Meet Id cannot be blank or must be numeric");
        $response->send();
        exit();
    }


    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        /** GET request
         *
         *  Get the details of a meet
         * -- must be one of the attendees to do this
         *
         */

        try {
            /*
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


            $query = $readDB->prepare('select id, title, description, DATE_FORMAT(scheduledTime, "%d/%m/%Y %H:%i") as scheduledTime, finalised, organiser, geolocationLon, geolocationLat, postcode, eventType from meets where id = :meetid');
            $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->RowCount();

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $meet = new Meet($row['id'], $row['title'], $row['description'], $row['scheduledTime'], $row['finalised'], $row['organiser'], $row['geolocationLon'], $row['geolocationLat'], $row['postcode'], $row['eventType'], "");
                $meet->setAttendees($attendeeArray);
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
                $response->setHttpStatusCode(404); //trying to delete a task that doesn't exist
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
         *  Update a meets details
         *  -- must be the organiser to do this
         *  -- use this to add attendee to a meet ??
         *  -- use this to remove an attendee??
         *
         */

        //do nothing


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

    /** meets/attending */



} elseif (empty($_GET)){

    /** if no meetid provided
     *
     *  route: /meet
     *  // get all meets the user owns / is organiser of
     */


    if($_SERVER['REQUEST_METHOD'] === 'GET'){

        /** GET request
         *
         *  TODO - return all meets - that this user is an attendee of
         */


    } else if ($_SERVER['REQUEST_METHOD'] === 'POST'){
        /** POST request
         *
         *  TODO - create a new meet
         *
         *  will need to post the user id into the attendance table otherwise no meet will be found
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

            /** TODO - find mandatory fields...
             *  validate on that basis
             */

            //get raw data

            //try to create new meet based on that. If that fails, it'll throw a meet exception

            //insert query

            //check succesful

            //return the inserted task to the user as per REST API best practice



        } catch (PDOException $e){
            error_log("Database query error - ".$e, 0);
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
