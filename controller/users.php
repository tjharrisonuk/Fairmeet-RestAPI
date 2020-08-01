<?php
namespace fairmeet\controller;

use fairmeet\model\Response;
use PDO;
use PDOException;

require_once ('DB.php');
require_once ('../model/Response.php');

// set up connections to the DB
try {
    $writeDB = DB::connectWriteDB();

} catch (PDOException $e){
    error_log("Connection error - ".$e, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error");
    $response->send();
    exit();
}

/** v1. Can only POST to create new user in db **/

//check http methods - needs to be POST request
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    $response = new Response();
    $response->setHttpStatusCode(405); //request method not allowed
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit();
}

//check that content type is set to json
if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
    $response = new Response();
    $response->setHttpStatusCode(400); //request method not allowed
    $response->setSuccess(false);
    $response->addMessage("Content type header not set to json");
    $response->send();
    exit();
}

$rawPostData = file_get_contents('php://input');

//check valid json
if(!$jsonData = json_decode($rawPostData)){
    $response = new Response();
    $response->setHttpStatusCode(400); //request method not allowed
    $response->setSuccess(false);
    $response->addMessage("Request body is not valid json");
    $response->send();
    exit();
}

//make sure that mandatory fields are set
if(!isset($jsonData->fullname) || !isset($jsonData->email) || !isset($jsonData->password)){
    $response = new Response();
    $response->setHttpStatusCode(400); //hasn't supplied mandatory
    $response->setSuccess(false);

    (!isset($jsonData->fullname) ? $response->addMessage("Full name not supplied") : false);
    (!isset($jsonData->email) ? $response->addMessage("Email not supplied") : false);
    (!isset($jsonData->password) ? $response->addMessage("Password not supplied") : false);

    $response->send();
    exit();
}

//either postcode or both geocodes must be set
if(!isset($jsonData->postcode) || (!isset($jsonData->geolocationLon) || !isset($jsonData->geolocationLat))){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage('Postcode or Geolocation Data must be supplied');
    $response->send();
}

//validate that json data has correct length)
if(strlen($jsonData->fullname) < 1 || strlen($jsonData->fullname) > 255 || strlen($jsonData->email) < 1 || strlen($jsonData->email) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255){
    $response = new Response();
    $response->setHttpStatusCode(400); //request method not allowed
    $response->setSuccess(false);

    (strlen($jsonData->fullname) < 1 ? $response->addMessage("Full name cannot be blank") : false);
    (strlen($jsonData->fullname) > 255 ? $response->addMessage("Full name cannot be over 255 characters") : false);

    (strlen($jsonData->email) < 1 ? $response->addMessage("Email cannot be blank") : false);
    (strlen($jsonData->email) > 255 ? $response->addMessage("Email cannot be over 255 characters") : false);

    (strlen($jsonData->password) < 1 ? $response->addMessage("Password cannot be blank") : false);
    (strlen($jsonData->password) > 255 ? $response->addMessage("Password cannot be over 255 characters") : false);

    $response->send();
    exit();
}

//validate that a valid email address has been provided
if(!filter_var($jsonData->email, FILTER_VALIDATE_EMAIL)){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Email address must be valid");
    $response->send();
    exit();
}

/** TODO - validate the postcode and or the geolocation data */



//get rid of whitespace
$fullname = trim($jsonData->fullname);
$email = trim($jsonData->email);
//space can be valid char in passwords
$password = $jsonData->password;

//query db to see if username is already taken
try{

    $query = $writeDB->prepare('select id from users where email = :email');
    $query->bindParam(':email', $email, PDO::PARAM_STR);
    $query->execute();

    //make sure that no rows are returned, therefore the table doesn't contain the email address already
    $rowCount = $query->rowCount();

    if($rowCount !== 0){
        $response = new Response();
        $response->setHttpStatusCode(409); //conflict
        $response->setSuccess(false);
        $response->addMessage("Email address already associated with a user");
        $response->send();
        exit();
    }

    //hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT); //always uses the most up to date hashing algorithm supported by PHP

    $query = $writeDB->prepare('insert into users (fullname, email, password) values (:fullname, :email, :password)');
    $query->bindParam('fullname', $fullname, PDO::PARAM_STR);
    $query->bindParam('email', $email, PDO::PARAM_STR);
    $query->bindParam('password', $hashed_password, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if($rowCount === 0){
        $response = new Response();
        $response->setHttpStatusCode(500); //db
        $response->setSuccess(false);
        $response->addMessage("There was an issue creating a user account - please try again");
        $response->send();
        exit();
    }

    //return back to the client the inserted row (user)
    $lastUserID = $writeDB->lastInsertId();

    $returnData = array();
    $returnData['user_id'] = $lastUserID;
    $returnData['fullname'] = $fullname;
    $returnData['email'] = $email;

    $response = new Response();
    $response->setHttpStatusCode(201); //creation
    $response->setSuccess(true);
    $response->addMessage("User has been successfully created");
    $response->setData($returnData);
    $response->send();
    exit();


} catch (PDOException $e){
    error_log("Database Query Error - ".$e, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("There was an issue creating user account - please try again");
    $response->send();
    exit();
}
