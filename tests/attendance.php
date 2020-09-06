<?php
namespace fairmeet\controller;
use fairmeet\model\AttendanceListException;
use fairmeet\model\Response;
use fairmeet\controller\DB;
use fairmeet\model\AttendanceList;
use PDO;
use PDOException;

require_once('DB.php');
require_once('../model/AttendanceList.php');
require_once('../model/Response.php');

try {
    $writeDB = DB::connectWriteDB();
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

/**
 * If we have a meeting is
 *
 * example route: /attendance/2
 */
