<?php
use ZealPHP\G;
use ZealPHP\Learn\Auth;
use ZealPHP\Learn\Chat;

${basename(__FILE__, '.php')} = function ($request, $response) {
    $u = Auth::currentUser();
    if (!$u) { http_response_code(401); header('Content-Type: application/json'); return ['error' => 'auth_required']; }
    $g = G::instance();
    $ip = $g->server['REMOTE_ADDR'] ?? 'unknown';
    $limit = (int) (getenv('ZEALPHP_LEARN_RATE_LIMIT') ?: 60);
    if (!Auth::rateLimit('learn_chat_rl', $ip, $limit, 3600)) {
        $response->sse(function ($emit) {
            $emit(json_encode(['error' => 'rate_limit']), 'error');
            $emit(json_encode(['done' => true]), 'done');
        });
        return;
    }
    $body = json_decode($g->zealphp_request->parent->getContent(), true) ?: [];
    $message  = trim((string) ($body['message'] ?? ''));
    $threadId = (string) ($body['thread_id'] ?? bin2hex(random_bytes(8)));
    if ($message === '' || strlen($message) > 2000) {
        $response->sse(function ($emit) use ($threadId) {
            $emit(json_encode(['thread_id' => $threadId]), 'thread');
            $emit(json_encode(['error' => 'invalid_message']), 'error');
            $emit(json_encode(['done' => true]), 'done');
        });
        return;
    }
    $key = (string) (getenv('OPENAI_API_KEY') ?: '');
    if ($key === '') {
        Chat::mock($response, $u, $message, $threadId);
    } else {
        Chat::real($response, $u, $message, $threadId, $key);
    }
};
