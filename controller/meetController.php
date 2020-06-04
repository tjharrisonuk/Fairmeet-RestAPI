<?php
namespace fairmeet\controller;

require_once ('DB.php');
require_once ('../model/Meet.php');
require_once ('../mode/Response.php');

try {
    $writeDb = DB::connectWriteDB();
    $readDb = DB::connectReadDB();
} catch (PDOException $e){
    error_log("Connection error - ".$e, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error");
    $response->send();
    exit();
}

/** Authorization script goes here */

/** if we have a meeting id
 *
 *  example route: /meet/1009
 *
 */

    /** GET request
     *
     *  Get the details of a meet
     * -- must be one of the attendees to do this
     *
     */

    /** DELETE request
     *
     *  Delete a meet
     *  -- must be the organiser in order to do this
     *
     */

    /** PATCH request
     *
     *  Update a meets details
     *  -- must be the organiser to do this
     *  -- use this to add attendee to a meet ??
     *  -- use this to remove an attendee??
     *
     */

    /** POST request
     *
     * Can't POST to a specific meetID as these
     * will be generated by the system
     *
     */

/** show all finalised or unfinalised meets
 *
 *  maybe in v2
 *
 */

/** pagination
 *
 * maybe in v2
 *
 */


/** if no meetid provided
 *
 *  route: /meet
 *
 */

    /** GET request
     *
     */

    /** POST request
     *
     *  - create a new meet
     *
     */

/** endpoint not found  */

