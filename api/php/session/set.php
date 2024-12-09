<?
$set = function(){
    //set all $_GET to the session
    foreach($_GET as $key=>$val){
        $_SESSION[$key] = $val;
    }
    $this->response($this->json([
        'sess_id'=>session_id(),
        'cookies'=>$this->_response->cookie
    ]), 200);
};