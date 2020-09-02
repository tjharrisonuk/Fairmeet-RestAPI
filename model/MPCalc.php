<?php
namespace fairmeet\model;
use fairmeet\controller\DB;
use fairmeet\model\Response;
use PDO;
use PDOException;
use Exception;

require_once ('../controller/DB.php');
require_once ('Response.php');


class MPCalc{

    /** todo might have to think about refactoring this somewhere else... doesn't really belong in
     * model, but the controllers are getting long enough as it is..
     */
    public function findMidPointForMeetEvent($meetid){


        try{
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

        try{
            //get a list of attendees by meet id from the attendance table
            $query = $readDB->prepare('select userid from attendance where meetid = :meetid');
            $query->bindParam(':meetid', $meetid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount == 0){
                $response = new Response();
                $response->setHttpStatusCode(500); //not found
                $response->setSuccess(false);
                $response->addMessage("An error occurred getting user information");
                $response->send();
                exit();
            }

            $userQueryString = "";

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $userQueryString .= "id = " . $row['userid'] . " or ";
            }

            //todo rowcount check

            //take off the last "or" from the string

            $userQueryString = substr($userQueryString, 0, -4);

            //var_dump($userArray);

            $query = $readDB->prepare('select id, geoLocationLat, geoLocationLon from users where ' . $userQueryString);
            $query->execute();

            $geoCodeArray = array();

            //set up maximum points. Intention being - feed the max lon and lat along with min long lat of the
            //entire attendance list into the findMidPoint function... (it could work??)

            $maxLon = null; // furtherst north
            $maxLat = null; // furthers east


            $i = 0;

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {

                //intially set the minimum lat and lon to the first row returned.
                if($i === 0){
                    $minLat = $row['geoLocationLat'];
                    $minLon = $row['geoLocationLon'];
                }

                //sort the maximumLat increase if further north
                if($row['geoLocationLat'] >= $maxLat){
                    $maxLat = $row['geoLocationLat'];
                }

                if($row['geoLocationLat'] < $minLat){
                    $minLat = $row['geoLocationLat'];
                }

                if($row['geoLocationLon'] >= $maxLon){
                    $maxLon = $row['geoLocationLon'];
                }

                if($row['geoLocationLon'] < $minLon){
                    $minLon = $row['geoLocationLon'];
                }

                $i++;


                $geoCodeArray[] = array($row['id'], $row['geoLocationLat'], $row['geoLocationLon']);
            }

            /*echo "max Lat : " . $maxLat . "</br />";
            echo "max Lon : " . $maxLon . "</br />";
            echo "min Lat : " . $minLat . "</br />";
            echo "min Lon : " . $minLon . "</br />";
            */

            $resultArray = $this->findMid($maxLat, $maxLon, $minLat, $minLon);

            /*echo 'Lat : ' . $resultArray[0] . '<br />';
            echo 'Lon : ' . $resultArray[1];
            */
            return $resultArray;


            //could return the array and leave it up to another controller here, but, just to experiment... going to try sorting
            //array and feeding into findMid function from here. It can then return the midpoint to client (probably meet controller) (and the function name / purpose
            //will have changed.

            //return $geoCodeArray;



        } catch (PDOException $e){
            error_log("Connection error - ".$e, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Database Connection Error");
            $response->send();
            exit();
        }

    }

    public function toRad($input){
        return $input * (pi() / 180);
    }

    public function toDegree($input){
        return $input * (180 / pi());
    }

    public function findMid($lat1, $lon1, $lat2, $lon2){

        //first calc longitudinal difference
        $longDiff = ($lon2 - $lon1);

        //convert to rad
        $longDiffRad = $this->toRad($longDiff);

        //convert others to rad
        $lat1Rad = $this->toRad($lat1);
        $lat2Rad = $this->toRad($lat2);
        $lon1Rad = $this->toRad($lon1);

        $cosLat2Rad = cos($lat2Rad);
        $cosLonDiffRad = cos($longDiffRad);

        $bX = $cosLat2Rad * $cosLonDiffRad;
        $bY = cos($lat2Rad) * sin($longDiffRad);

        //echo 'bX: '. $bX . '<br />';
        //echo 'bY: '. $bY . '<br />';

        //equation to find latitudinal midpoint
        $lat3 = atan2((sin($lat1Rad) + sin($lat2Rad)), sqrt((cos($lat1Rad) + $bX) * (cos($lat1Rad) + $bX + $bY * $bY)));

        //equation to find longitudinal midpoint
        $lon3 = $lon1Rad + atan2($bY, (cos($lat1Rad) + $bX));

        //return an array, with midpoint lon and lat coordinates
        return [$this->toDegree($lon3), $this->toDegree($lat3)];
    }

    //should take in an array of geolocations gotten from users
    public function setMeetLocation (){

    }

}


/*
$calc = new MPCalc();

echo '<h3>Calculate Midpoint Test</h3><br />';
//test E11 2SP -> E17 6ZA
$testResult = $calc->findMid(51.578178, 0.021849, 51.589306, -0.043014);

$lonMid = $testResult[0];
$latMid = $testResult[1];

echo 'Lon Mid : ' . $lonMid . '<br />';
echo 'Lat Mid : ' . $latMid;

echo '<br /><br />';
echo '<h3> Load Meet Attendees Test</h3>';

$calc->findMidPointForMeetEvent(1);

/*array(2) {
    [0]=> array(3) { [0]=> int(26) [1]=> string(9) "51.456133" [2]=> string(9) "-0.103237" }
    [1]=> array(3) { [0]=> int(28) [1]=> string(9) "51.587121" [2]=> string(9) "-0.103921" }
}*/

