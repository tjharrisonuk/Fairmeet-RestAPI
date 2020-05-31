<?php

require_once('DB.php');
require_once ('../model/Response.php');


try {
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectReadDB();
}
catch (PDOException $e){
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage('Database Connection Error');
    $response->send();
    exit;
}
