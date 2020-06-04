<?php
namespace fairmeet\controller;
use PDO;
use PDOException;

require_once ('DB.php');
require_once ('../model/Meet.php');
require_once ('../model/Response.php');

// set up connections to the DB
try {
    $writeDb = DB:: connectWriteDB();

} catch (PDOException $e){
    error_log("Connection error - ".$e, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error");
    $response->send();
    exit();
}
