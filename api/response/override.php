<?
use function ZealPHP\response_set_status;

$override = function($response){
    \ZealPHP\elog("Reached API endpoint call");
    $response->status(404);
    $response->write("BAD REQUEST");
};