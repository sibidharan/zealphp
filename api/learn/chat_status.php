<?php
// ZealAPI file: GET /api/learn/chat_status maps here.
// The closure variable name MUST match basename($file, '.php') — here: $chat_status.
// Inside the closure, $this is the ZealAPI instance.
${basename(__FILE__, '.php')} = function () {
    $key = (string)(getenv('OPENAI_API_KEY') ?: '');
    $this->response($this->json([
        'ai_enabled' => $key !== '',
        'mock_mode'  => $key === '',
        'model'      => $key !== '' ? (getenv('ZEALPHP_LEARN_AI_MODEL') ?: 'gpt-4.1-mini') : 'mock-rules-v1',
    ]), 200);
};
