<?php

namespace fairmeet\controller;

use PDO;
use PDOException;
require_once('../vendor/autoload.php');

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable('../');
$dotenv->load();

//new PDO('mysql:host=' . $_SERVER['DB_SERVER'] . ':' . $_SERVER['DB_PORT'] .
// ';dbname=' . $_SERVER['DB_NAME'] .
// ';charset=utf8', $_SERVER['DB_USERNAME'], $_SERVER['DB_PASSWORD']);

$options = array(
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
    PDO::MYSQL_ATTR_SSL_CA => 'ca-certificate.crt'
);

echo 'Connection String: ' . "mysql:host=" . $_SERVER['DB_SERVER'] . ';port=' . $_SERVER['DB_PORT'] . ';dbname=' . $_SERVER['DB_NAME'], $_SERVER['DB_USERNAME'], $_SERVER['DB_PASSWORD'], json_encode($options);
echo '<br /><br />';
try{
    $testConn = new PDO("mysql:host=" . $_SERVER['DB_SERVER'] . ';port=' . $_SERVER['DB_PORT'] . ';dbname=' . $_SERVER['DB_NAME'], $_SERVER['DB_USERNAME'], $_SERVER['DB_PASSWORD'], $options);
    $testConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo 'connected successfully';
        var_dump($testConn->query("SHOW STATUS LIKE 'Ssl_cipher';")->fetchAll());
        $testConn = null;
} catch (PDOException $e){
    echo "Connection failed: " . $e->getMessage();
}
