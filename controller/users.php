<?php
namespace fairmeet\controller;

use fairmeet\model\Response;
use PDO;
use PDOException;
use fairmeet\model\PostcodeHelper;

require_once ('DB.php');
require_once ('../model/Response.php');
require_once ('../model/PostcodeHelper.php');

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



//mandatory information checks --
//can have either postcode or geolocation
//if we have geolocation - must have both lat and lon

$geoLocationSupplied = false;
$postcodeSupplied = false;

if(isset($jsonData->postcode)){
    $postcodeSupplied = true;
}

//shouldn't be user input in the client app so no need to test validity for now. Maybe should be in the future....
if(isset($jsonData->geoLocationLon) && isset($jsonData->geoLocationLat)){
    $geoLocationSupplied = true;
}

if(!isset($jsonData->fullname) || !isset($jsonData->email) || !isset($jsonData->password) || ($geoLocationSupplied === false && $postcodeSupplied === false)){
    $response = new Response();
    $response->setHttpStatusCode(400); //hasn't supplied mandatory
    $response->setSuccess(false);

    (!isset($jsonData->fullname) ? $response->addMessage("Full name not supplied") : false);
    (!isset($jsonData->email) ? $response->addMessage("Email not supplied") : false);
    (!isset($jsonData->password) ? $response->addMessage("Password not supplied") : false);
    ($geoLocationSupplied === false && $postcodeSupplied === false ? $response->addMessage('Full location data or postcode not supplied') : false);
    $response->send();
    exit();
}

//validate that json data has valid values)
if(strlen($jsonData->fullname) < 1 ||
    strlen($jsonData->fullname) > 255 ||
    strlen($jsonData->email) < 1 ||
    strlen($jsonData->email) > 255 ||
    strlen($jsonData->password) < 1 ||
    strlen($jsonData->password) > 255 ||
    !filter_var($jsonData->email, FILTER_VALIDATE_EMAIL)) {

    $response = new Response();
    $response->setHttpStatusCode(400); //request method not allowed
    $response->setSuccess(false);

    (strlen($jsonData->fullname) < 1 ? $response->addMessage("Full name cannot be blank") : false);
    (strlen($jsonData->fullname) > 255 ? $response->addMessage("Full name cannot be over 255 characters") : false);

    (strlen($jsonData->email) < 1 ? $response->addMessage("Email cannot be blank") : false);
    (strlen($jsonData->email) > 255 ? $response->addMessage("Email cannot be over 255 characters") : false);
    (!filter_var($jsonData->email, FILTER_VALIDATE_EMAIL) ? $response->addMessage("Email address not in valid format") : false);

    (strlen($jsonData->password) < 1 ? $response->addMessage("Password cannot be blank") : false);
    (strlen($jsonData->password) > 255 ? $response->addMessage("Password cannot be over 255 characters") : false);

    $response->send();
    exit();
}

//fill in the database tables with either provided values in the json - if not fill in the blanks with requests from
//postcodes.io using helper class
if ($geoLocationSupplied === true) {

    // todo - validation on geolocation input

    $geoLocationLon = $jsonData->geoLocationLon;
    $geoLocationLon = $jsonData->geoLocationLat;

}

if($postcodeSupplied === true){

    //validate the postcode
    if(!PostcodeHelper::validatePostcode($jsonData->postcode)){
        $response = new Response();
        $response->setHttpStatusCode(400); //request method not allowed
        $response->setSuccess(false);
        $response->addMessage("Postcode supplied was invalid");
        $response->send();
        exit();
    }

    $postcode = $jsonData->postcode;

}

if($geoLocationSupplied === false && $postcodeSupplied === true){
    //call to postcode helper -> make a request to postcodes.io to fill in the missing
    //geolocation information

    $geoData = PostcodeHelper::findGeoCoordsFromPostcode($jsonData->postcode);

    //postcodes.io supplied geolocation variables
    $geoLocationLon = $geoData[0];
    $geoLocationLat = $geoData[1];
}



if ($postcodeSupplied === false && $geoLocationSupplied === true){

    //first validate the geo-cords - make sure they're both valid and within the london area

    $isValidLondonGeo = PostcodeHelper::validateGeoInLondon($jsonData->geoLocationLon, $jsonData->geoLocationLat);

    if($isValidLondonGeo) {
        $postcode = PostcodeHelper::findPostcodeFromGeoCords($jsonData->geoLocationLon, $jsonData->geoLocationLat);
        $geoLocationLon = $jsonData->geoLocationLon;
        $geoLocationLat = $jsonData->geoLocationLat;
    }

    // todo error code here

}

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

    $query = $writeDB->prepare('insert into users (fullname, email, password, postcode, geoLocationLon, geoLocationLat) values (:fullname, :email, :password, :postcode, :geoLocationLon, :geoLocationLat)');
    $query->bindParam('fullname', $fullname, PDO::PARAM_STR);
    $query->bindParam('email', $email, PDO::PARAM_STR);
    $query->bindParam('password', $hashed_password, PDO::PARAM_STR);
    $query->bindParam('postcode', $postcode, PDO::PARAM_STR);
    $query->bindParam('geoLocationLon', $geoLocationLon, PDO::PARAM_STR);
    $query->bindParam('geoLocationLat', $geoLocationLat, PDO::PARAM_STR);
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
    $returnData['postcode'] = $postcode;
    $returnData['geoLocationLon'] = $geoLocationLon;
    $returnData['geoLocationLat'] = $geoLocationLat;


    $response = new Response();
    $response->setHttpStatusCode(201); //creation
    $response->setSuccess(true);
    $response->addMessage("User has been successfully created");
    $response->setData($returnData);
    $response->send();
    exit();


} catch (PDOException $e){
    echo $e;
    error_log("Database Query Error - ".$e, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("There was an issue creating user account - please try again");
    $response->send();
    exit();
}
