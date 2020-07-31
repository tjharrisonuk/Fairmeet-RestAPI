<?php
namespace fairmeet\model;
use Exception;


class MPCalc{

    public function toRad($input){
        return ($input * pi()) / 180;
    }

    public function toDegree($input){
        return (($input * 180) / pi());
    }

    public function findMid($lat1, $lon1, $lat2, $lon2){

        //first calc longitudinal difference
        $longDiff = ($lon2 - $lon1);

        //convert to rad
        $longDiffRad = $this->toRad($longDiff);

        //convert others to rad
        $lat1Rad = $this->toRad($lat1);
        $lat2Rad = $this->toRad($lat2);
        $lon2Rad = $this->toRad($lon2);

        $bX = (cos($lat2Rad) * cos($longDiffRad));
        $bY = (cos($lat2Rad) * sin($longDiffRad));

        $lat3 = atan2((sin($lat1Rad) + sin($lat2Rad)), sqrt((cos($lat1Rad) + $bX) * (cos($lat1Rad) + $bX) + $bY * $bY));
        $lon3 = $lon1 + (atan2($bY, (cos($lat1Rad) + $bX)));


        return [$this->toDegree($lon3), $this->toDegree($lat3)];

        //$lon3 = $this->toDegree($lon1Deg + atan2($bY, cos($lat1 + $bX)));



    }


}



$calc = new MPCalc();

$ret = $calc->toRad(20);

//echo $ret . '<br /><br />';

echo '<h3>Calculate Midpoint Test</h3><br />';
$testResult = $calc->findMid(0.021849, 51.578178, -0.103921, 51.587121);

$lonMid = $testResult[0];
$latMid = $testResult[1];

echo 'Lon Mid : ' . $lonMid . '<br />';
echo 'Lat Mid : ' . $latMid;



