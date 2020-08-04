<?php
namespace fairmeet\model;

class PostcodeHelper
{

    // (called by users.php) on user signup - if the user has provided only
    // postcode, the geolocation data for that postcode must be inserted in the
    // database..
    public static function findGeoCoordsFromPostcode($postcode)
    {

        $requestString = "https://api.postcodes.io/postcodes/" . $postcode;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $requestString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $output = json_decode(curl_exec($ch));
        curl_close($ch);

        $returnLon = $output->result->longitude;
        $returnLat = $output->result->latitude;

        return [$returnLon, $returnLat];

    }

    public static function findPostcodeFromGeoCords($lon, $lat){

        $requestString = "https://api.postcodes.io/postcodes?lon=" . $lon . "&lat=" . $lat;

        // create curl resource
        $ch = curl_init();
        // set url
        curl_setopt($ch, CURLOPT_URL, $requestString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $output = json_decode(curl_exec($ch));

        // close curl resource to free up system resources
        curl_close($ch);

        return $output->result[0]->postcode;

    }

    public static function validatePostcode($postcode)
    {

        $requestString = "https://api.postcodes.io/postcodes/" . $postcode . "/validate";

        // create curl resource
        $ch = curl_init();
        // set url
        curl_setopt($ch, CURLOPT_URL, $requestString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $output = json_decode(curl_exec($ch));

        // close curl resource to free up system resources
        curl_close($ch);

        if ($output->result === false) {
            return false;
        } else {
            return true;
        }
    }


    public static function validateGeoInLondon($lon, $lat){

        if (!is_numeric($lat) || !is_numeric($lon)){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("geolocation provided in invalid format");
            $response->send();
            exit();
        }

        //check that geo is in london
        //North - waltham cross = lat : 51.679998
        //South - redhill = lat : 51.264464
        //West - slough = lon : -0.508414
        //East - grays = lon : 0.322098

        if ($lat > 51.679998 || $lat < 51.264464 || $lon < -0.508414 || $lon > 0.322098 ){

            return false;
        }

        return true;

    }

}
