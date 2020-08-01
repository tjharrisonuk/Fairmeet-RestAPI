<?php
namespace fairmeet\model;
use Exception;


class MPCalc{

    private $_apiUrl;

    public function __construct(){
        //may change apis to use HERE mapping later on
        //for now though postcodes.io will be used to get postcodes from lat/lon
        //coordinate points.

        $_apiUrl = 'https://api.postcodes.io/postcodes';
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

}



$calc = new MPCalc();

$ret = $calc->toRad(20);

//echo $ret . '<br /><br />';

echo '<h3> Test Rad Function</h3>';
echo '10 to Rad == ' . $calc->toRad(10);

echo '<h3> Test Deg Function</h3>';
echo '10 to Deg == ' . $calc->toDegree(10);

echo '<h3>Calculate Midpoint Test</h3><br />';
//test E11 2SP -> E17 6ZA
$testResult = $calc->findMid(51.578178, 0.021849, 51.589306, -0.043014);

$lonMid = $testResult[0];
$latMid = $testResult[1];

echo 'Lon Mid : ' . $lonMid . '<br />';
echo 'Lat Mid : ' . $latMid;



