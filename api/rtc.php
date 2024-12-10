<?
$rtc = function() {
    $this->response($this->json([
        'error'=>'methood_not_callable', 
        'method'=>'rtc'
    ]), 404);
};
