<?php
use ZealPHP\G;
use ZealPHP\Learn\DB;
use ZealPHP\Learn\Auth;

$ERROR_MESSAGES = [
    'rate_limit'        => 'Too many attempts. Please wait a few minutes.',
    'validation_failed' => 'Please enter a username and password.',
    'invalid_username'  => 'Username must be 3-64 characters.',
    'invalid_password'  => 'Password must be at least 8 characters.',
    'username_taken'    => 'That username is already taken.',
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

    if (!Auth::rateLimit('learn_register_rl', $ip, 5, 300)) { $fail('rate_limit', 429); return; }
    $creds = Auth::readCredentials($g);
    if (!$creds) { $fail('validation_failed', 422); return; }
    if (!Auth::validateUsername($creds['username'])) { $fail('invalid_username', 422); return; }
    if (!Auth::validatePassword($creds['password'])) { $fail('invalid_password', 422); return; }

    $db = DB::open();
    $userId = Auth::register($db, $creds['username'], $creds['password']);
    if ($userId === null) { $fail('username_taken', 409); return; }

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
