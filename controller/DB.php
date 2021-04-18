<?php
namespace fairmeet\controller;
use PDO;

require_once realpath(__DIR__ . "/vendor/autoload.php");
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

class DB {

    /** Two database connections write and read
     *  set up. Can change these to seperate master/slave
     *  in future if needed.
    **/


    private static $writeDBConnection;
    private static $readDBConnection;


    public static function connectWriteDB(){

        if(self::$writeDBConnection === null){
            self::$writeDBConnection = new PDO($_SERVER['DB_SERVER'], $_SERVER['DB_USERNAME'], $_SERVER['DB_PASSWORD'], $_SERVER['DB_NAME']);
            self::$writeDBConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$writeDBConnection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
        return self::$writeDBConnection;

    }


    /*public static function connectWriteDB(){

        if(self::$writeDBConnection === null){
            self::$writeDBConnection = new PDO('mysql:host=localhost;dbname=fairmeetdb;charset=utf8', 'tom', 'test');
            self::$writeDBConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$writeDBConnection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
        return self::$writeDBConnection;

    }*/

    public static function connectReadDB(){
        if(self::$readDBConnection === null){
            self::$readDBConnection = new PDO('mysql:host=localhost;dbname=fairmeetdb;charset=utf8', 'tom', 'test');
            self::$readDBConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$readDBConnection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
        return self::$readDBConnection;
    }

}
