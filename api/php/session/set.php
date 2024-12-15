<?
use ZealPHP\G;
use function ZealPHP\Session\zeal_session_id;
$set = function(){
    //set all $_GET to the session
    $g = G::getInstance();
    foreach($g->get as $key=>$val){
        $g->session[$key] = $val;
    }
    $this->response($this->json([
        'sess_id'=>zeal_session_id(),
        'cookies'=>$this->_response->cookie
    ]), 200);
};