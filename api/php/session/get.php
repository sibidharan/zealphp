<?

$get = function() {
    $this->response($this->json([
        'sess_id'=>session_id(),
        'sess' => $_SESSION,
        'cookies'=>$this->_response->cookie
    ]), 200);
};