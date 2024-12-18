<?

use function ZealPHP\get_current_render_time;
use ZealPHP\G;
use function ZealPHP\Session\zeal_session_id;
$get = function() {
    $this->response($this->json([
        'sess_id'=>zeal_session_id(),
        'sess' => G::get('session'),
        'cookies'=>G::get('cookie'),
        'request'=>get_current_render_time()
    ]), 200);
};