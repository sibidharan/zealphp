<?php
use ZealPHP\G;
use ZealPHP\Learn\DB;
use ZealPHP\Learn\Auth;

${basename(__FILE__, '.php')} = function () {
    $g = G::instance();
    $ip = $g->server['REMOTE_ADDR'] ?? 'unknown';
    if (!Auth::rateLimit('learn_login_rl', $ip, 10, 300)) {
        $this->response($this->json(['error' => 'rate_limit']), 429);
        return;
    }
    $creds = Auth::readCredentials($g);
    if (!$creds) { $this->response($this->json(['error' => 'validation_failed']), 422); return; }

    $db = DB::open();
    $userId = Auth::login($db, $creds['username'], $creds['password']);
    if ($userId === null) { $this->response($this->json(['error' => 'invalid_credentials']), 401); return; }

    session_start();
    $g->session['user_id'] = $userId;
    $g->session['username'] = $creds['username'];
    setcookie('PHPSESSID', session_id(), 0, '/', '', false, true);
    session_write_close();

    $ct = $g->server['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $this->response($this->json(['user_id' => $userId, 'username' => $creds['username']]), 200);
        return;
    }
    header('Location: /learn/notes');
    http_response_code(302);
};
