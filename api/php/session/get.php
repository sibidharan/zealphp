<?

use function ZealPHP\get_current_render_time;

$get = function() {
    $this->response($this->json([
        'sess_id'=>session_id(),
        'sess' => $_SESSION,
        'cookies'=>$this->_response->cookie,
        'request'=>get_current_render_time()
    ]), 200);
};