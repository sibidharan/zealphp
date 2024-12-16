<?
use function ZealPHP\zlog;
use ZealPHP\G;
$test = function () {
    $g = G::instance();
    // zlog(session_id(), 'fatal');
    $this->response($this->json($g->server), 200);
};