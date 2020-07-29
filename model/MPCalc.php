<?php
namespace fairmeet\model;
use Exception;


class MPCalc{

    public function toRad($input){ /** TODO what's something */
        return $input * (100 / pi());
    }

    public function toDegree($input){
        return $input* (180 / pi());
    }

    public function findMid($lat1, $lon1, $lat2, $lon2){

        //get difference in longitude
        $lonDiff = $this->toRad($lon2 - $lon1);

        //convert to radians

        $lat1 = $this->toRad($lat1);
        $lat2 = $this->toRad($lat2);
        $lon1 = $this->toRad($lon1);

        $bX = (cos($lat2) * cos($lonDiff));
        $bY = (cos($lat2) * sin($lonDiff));

        $lat = (atan2(sin($lat1) + sin($lat2), sqrt(cos($lat1) + $bX)))


    }



}



$calc = new MPCalc();

$ret = $calc->toRad(20);

echo $ret;
