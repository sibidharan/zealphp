<?php
use ZealPHP\G;
use ZealPHP\Learn\DB;
use ZealPHP\Learn\Auth;

$ERROR_MESSAGES = [
    'rate_limit'          => 'Too many attempts. Please wait a few minutes.',
    'validation_failed'   => 'Please enter a username and password.',
    'invalid_credentials' => 'Wrong username or password.',
];

${basename(__FILE__, '.php')} = function () use ($ERROR_MESSAGES) {
    $g = G::instance();
    $wantsJson = stripos($g->server['HTTP_CONTENT_TYPE'] ?? '', 'application/json') !== false;
    $ip = $g->server['REMOTE_ADDR'] ?? 'unknown';

    $fail = function (string $code, int $status) use ($wantsJson, $ERROR_MESSAGES) {
        $msg = $ERROR_MESSAGES[$code] ?? $code;
        if ($wantsJson) {
            $this->response($this->json(['error' => $code]), $status);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            $this->response('<p class="auth-error">' . htmlspecialchars($msg) . '</p>', $status);
        }
    };

    if (!Auth::rateLimit('learn_login_rl', $ip, 10, 300)) { $fail('rate_limit', 429); return; }
    $creds = Auth::readCredentials($g);
    if (!$creds) { $fail('validation_failed', 422); return; }

    $db = DB::open();
    $userId = Auth::login($db, $creds['username'], $creds['password']);
    if ($userId === null) { $fail('invalid_credentials', 401); return; }

    $g->session['user_id'] = $userId;
    $g->session['username'] = $creds['username'];

    if ($wantsJson) {
        $this->response($this->json(['user_id' => $userId, 'username' => $creds['username']]), 200);
        return;
    }
    header('HX-Redirect: /learn/notes');
    header('Location: /learn/notes');
    http_response_code(302);
};
