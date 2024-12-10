<?
use function ZealPHP\zlog;
$test = function () {
    zlog(session_id(), 'fatal');
    $this->response($this->json($_SERVER), 200);
};