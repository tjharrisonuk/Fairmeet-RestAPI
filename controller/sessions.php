<?php
namespace fairmeet\controller;
use PDO;
use PDOException;
use fairmeet\model\Response;

require_once ('DB.php');
require_once ('../model/Response.php');

try{

    $writeDB = DB::connectWriteDB();

} catch (PDOException $e){
    error_log("Connection error: ".$e, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error");
    $response->send();
    exit();
}

if(array_key_exists("sessionid", $_GET)){

    // route eg. /sessions/2

    $sessionid = $_GET['sessionid'];

    if($sessionid == '' || !is_numeric($sessionid)){

        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);

        ($sessionid === '' ? $response->addMessage("Session id cannot be blank") : false);
        (!is_numeric($sessionid) ? $response->addMessage("Session id must be numeric") : false);

        $response->send();
        exit();
    }

    //validation on access token
    if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);

        (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access token is missing from the header") : false);
        (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access token cannot be blank") : false);

        $response->send();
        exit();
    }

    //get the value of the access token sent
    $accesstoken = $_SERVER['HTTP_AUTHORIZATION'];


    if($_SERVER['REQUEST_METHOD'] === 'DELETE'){ //log out (delete a session)

        try{

            $query = $writeDB->prepare('delete from sessions where id = :sessionid and accesstoken = :accesstoken');
            $query->bindParam(':sessionid', $sessionid, PDO::PARAM_INT);
            $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Failed to log out of this session using accesstoken provided");
                $response->send();
                exit();
            }

            $returnData = array();
            $returnData['session_id'] = intval($sessionid);

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Logged out");
            $response->setData($returnData);
            $response->send();
            exit();


        } catch (PDOException $e){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("There was an issue logging out - please try again");
            $response->send();
            exit();
        }


    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH'){
        //update/refresh a new access token

        if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Content Type Header not set to JSON");
            $response->send();
            exit();
        }

        //raw JSON data - including the refreshtoken
        $rawPatchData = file_get_contents('php://input');

        if(!$jsonData = json_decode($rawPatchData)){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Request body not valid JSON");
            $response->send();
            exit();
        }

        //check that refresh token provided
        if(!isset($jsonData->refresh_token) || strlen($jsonData->refresh_token) < 1){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);

            (!isset($jsonData->refresh_token) ? $response->addMessage("Refresh token not supplied") : false);
            (strlen($jsonData->refresh_token) < 1 ? $response->addMessage("Refresh token cannot be blank") : false);

            $response->send();
            exit();
        }

        try{

            $refreshtoken = $jsonData->refresh_token;

            //need to join the sessions and user table together to link session user id with user id.
            $query = $writeDB->prepare('select sessions.id as sessionid, sessions.userid as userid, accesstoken, refreshtoken, useractive, loginattempts, accesstokenexpiry, refreshtokenexpiry from sessions, users where users.id = sessions.userid and sessions.id = :sessionid and sessions.accesstoken = :accesstoken and sessions.refreshtoken = :refreshtoken');
            $query->bindParam(':sessionid', $sessionid, PDO::PARAM_INT);
            $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
            $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Access token or refresh token is incorrect for session id");
                $response->send();
                exit();
            }

            $row = $query->fetch(PDO::FETCH_ASSOC);

            $returned_sessionid = $row['sessionid'];
            $returned_userid = $row['userid'];
            $returned_accesstoken = $row['accesstoken'];
            $returned_refreshtoken = $row['refreshtoken'];
            $returned_useractive = $row['useractive'];
            $returned_loginattempts = $row['loginattempts'];
            $returned_accesstokenexpiry = $row['accesstokenexpiry'];
            $returned_refreshtokenexpiry = $row['refreshtokenexpiry'];

            if($returned_useractive !== 'Y'){
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("User account is not active");
                $response->send();
                exit();
            }

            if($returned_loginattempts >= 3){
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("User account is currently locked out");
                $response->send();
                exit();
            }

            if(strtotime($returned_refreshtokenexpiry) < time()){
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Refresh token has expired, please log in again");
                $response->send();
                exit();
            }


            $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
            $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

            $access_token_expiry_seconds = 1200; //20 mins
            $refresh_token_expiry_seconds = 1209600; //14 days

            $query = $writeDB->prepare('update sessions set accesstoken = :accesstoken, accesstokenexpiry = date_add(NOW(), INTERVAL :accesstokenexpiryseconds SECOND), refreshtoken = :refreshtoken, refreshtokenexpiry = date_add(NOW(), INTERVAL :refreshtokenexpiryseconds SECOND) where id = :sessionid and userid = :userid and accesstoken = :returnedaccesstoken and refreshtoken = :returnedrefreshtoken');

            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':sessionid', $returned_sessionid, PDO::PARAM_INT);
            $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
            $query->bindParam(':accesstokenexpiryseconds', $access_token_expiry_seconds, PDO::PARAM_INT);
            $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
            $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
            $query->bindParam(':returnedaccesstoken', $returned_accesstoken, PDO::PARAM_STR);
            $query->bindParam(':returnedrefreshtoken', $returned_refreshtoken, PDO::PARAM_STR);

            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Access token could not be refreshed - please log in again");
                $response->send();
                exit();
            }

            $returnData = array();
            $returnData['session_id'] = $returned_sessionid;
            $returnData['access_token'] = $accesstoken; //newly generated access token
            $returnData['access_token_expiry'] = $access_token_expiry_seconds;
            $returnData['refresh_token'] = $refreshtoken; //newly generated refresh token
            $returnData['refresh_token_expiry'] = $refresh_token_expiry_seconds;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Token refreshed");
            $response->setData($returnData);
            $response->send();
            exit();



        } catch (PDOException $e){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("There was an issue refreshing access token - please login again");
            $response->send();
            exit();
        }




    } else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit();
    }


} elseif(empty($_GET)){

    //creation of a session - POST to /sessions/
    if($_SERVER['REQUEST_METHOD'] !== 'POST'){
        $response = new Response();
        $response->setHttpStatusCode(405); //request method not allowed
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit();
    }

    //delay log in attempts by 1 second to delay brute force hack attempts
    sleep(1);

    if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
        $response = new Response();
        $response->setHttpStatusCode(400); //request method not allowed
        $response->setSuccess(false);
        $response->addMessage("Content Type header not set to JSON");
        $response->send();
        exit();
    }

    $rawPostData = file_get_contents('php://input');

    //check for valid JSON
    if(!$jsonData =  json_decode($rawPostData)){
        $response = new Response();
        $response->setHttpStatusCode(400); //request method not allowed
        $response->setSuccess(false);
        $response->addMessage("Request body not valid JSON");
        $response->send();
        exit();
    }

    //check on mandatory fields.
    if(!isset($jsonData->email) || !isset($jsonData->password)){
        $response = new Response();
        $response->setHttpStatusCode(400); //request method not allowed
        $response->setSuccess(false);

        (!isset($jsonData->email) ? $response->addMessage("Email address not supplied") : false);
        (!isset($jsonData->password) ? $response->addMessage("Password not supplied") : false);

        $response->send();
        exit();
    }

    //make sure data supplied in correct format
    if(strlen($jsonData->email) < 1 || strlen($jsonData->email) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255){
        $response = new Response();
        $response->setHttpStatusCode(400); //request method not allowed
        $response->setSuccess(false);

        (strlen($jsonData->username) < 1 ? $response->addMessage("Email cannot be blank") : false);
        (strlen($jsonData->username) > 255 ? $response->addMessage("Email cannot be more than 255 characters") : false);
        (strlen($jsonData->password) < 1 ? $response->addMessage("Password cannot be blank") : false);
        (strlen($jsonData->password) > 255 ? $response->addMessage("Password cannot be more than 255 characters") : false);

        $response->send();
        exit();
    }

    /** TODO - add email verification */

    //attempt to retrieve row based on username
    try{

        $email = $jsonData->email;
        $password = $jsonData->password;

        $query = $writeDB->prepare('select id, fullname, email, password, useractive, loginattempts from users where email = :email');
        $query->bindParam(':email', $email, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        //if username not found
        if($rowCount === 0){
            $response = new Response();
            $response->setHttpStatusCode(401); //unauthorised
            $response->setSuccess(false);
            $response->addMessage("Email / Password is incorrect");
            $response->send();
            exit();
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);

        $returned_id = $row['id'];
        $returned_fullname = $row['fullname'];
        $returned_email = $row['email'];
        $returned_password = $row['password'];
        $returned_useractive = $row['useractive'];
        $returned_loginattempts = $row['loginattempts'];

        //first check that the user is active
        if($returned_useractive !== 'Y'){
            $response = new Response();
            $response->setHttpStatusCode(401); //unauthorised
            $response->setSuccess(false);
            $response->addMessage("User account not active");
            $response->send();
            exit();
        }

        //check login attempts
        if($returned_loginattempts >= 3){
            $response = new Response();
            $response->setHttpStatusCode(401); //unauthorised
            $response->setSuccess(false);
            $response->addMessage("User account currently locked out");
            $response->send();
            exit();
        }

        //validate the password
        if(!password_verify($password, $returned_password)){

            $query = $writeDB->prepare('update users set loginattempts = loginattempts+1 where id = :id');
            $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
            $query->execute();

            $response = new Response();
            $response->setHttpStatusCode(401); //unauthorised
            $response->setSuccess(false);
            $response->addMessage("Username or password is incorrect");
            $response->send();
            exit();

        }

        //successful login
        //generate access tokens
        $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
        $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

        $access_token_expiry_seconds = 1200;
        $refresh_token_expiry_seconds = 1209600; //14 days


    } catch (PDOException $e){
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue logging in");
        $response->send();
        exit();
    }

    //new try and catch to allow for rollbacks on successful user logins
    try{

        /* transactions are atomic, do both queries but don't save until it's commited */
        $writeDB->beginTransaction();
        $query = $writeDB->prepare('update users set loginattempts = 0 where id = :id'); //set login attempts back to 0
        $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
        $query->execute();

        $query = $writeDB->prepare('insert into sessions (userid, accesstoken, accesstokenexpiry, refreshtoken, refreshtokenexpiry) values (:userid, :accesstoken, date_add(NOW(), INTERVAL :accesstokenexpiryseconds SECOND), :refreshtoken, date_add(NOW(), INTERVAL :refreshtokenexpiryseconds SECOND))');
        $query->bindParam(':userid', $returned_id, PDO::PARAM_INT);
        $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
        $query->bindParam(':accesstokenexpiryseconds', $access_token_expiry_seconds, PDO::PARAM_INT);
        $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
        $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);

        $query->execute();

        $lastSessionID = $writeDB->lastInsertId();

        $writeDB->commit();

        $returnData = array();
        $returnData['session_id'] = intval($lastSessionID);
        $returnData['access_token'] = $accesstoken;
        $returnData['access_token_expires_in'] = $access_token_expiry_seconds;
        $returnData['refresh_token'] = $refreshtoken;
        $returnData['refresh_token_expires_in'] = $refresh_token_expiry_seconds;

        $response = new Response();
        $response->setHttpStatusCode(201); // created
        $response->setSuccess(true);
        $response->setData($returnData);
        $response->send();
        exit();

    } catch (PDOException $e){
        $writeDB->rollBack(); //put data back the way it was
        $response = new Response();;
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue logging in - please try again");
        $response->send();
        exit();
    }
} else {
    $response = new Response();;
    $response->setHttpStatusCode(404);
    $response->setSuccess(false);
    $response->addMessage("Endpoint not found");
    $response->send();
    exit();
}

