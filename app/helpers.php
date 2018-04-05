<?php
/**
 * Created by PhpStorm.
 * User: melon
 * Date: 2/11/18
 * Time: 7:20 PM
 */

use \Firebase\JWT\JWT;

function generateZoomJWT()
{
    $key = config('services.zoom.key');
    $secret = config('services.zoom.secret');
    $token = array("iss" => $key, "exp" => time() + 60);
    return JWT::encode($token, $secret);
}