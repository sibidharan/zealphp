<?php
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Store;

Store::make('chat_threads', 256, [
    'messages' => [\OpenSwoole\Table::TYPE_STRING, 16384],
    'updated'  => [\OpenSwoole\Table::TYPE_INT, 4],
]);

$app = App::instance();

$app->route('/api/chat', ['methods' => ['POST']], function($request, $response) {
    $g = G::instance();

    $body = $g->zealphp_request->parent->getContent();
    $input = json_decode($body, true);
    $message = trim($input['message'] ?? '');
    $threadId = $input['thread_id'] ?? bin2hex(random_bytes(8));

    if (empty($message) || strlen($message) > 2000) {
        header('Content-Type: application/json');
        http_response_code(400);
        return ['error' => 'Message required (max 2000 chars)'];
    }

    $apiKey = getenv('ANTHROPIC_API_KEY');
    if (!$apiKey) {
        $response->sse(function($emit) use ($threadId, $message) {
            $emit(json_encode(['thread_id' => $threadId]), 'thread');
            $fallback = "I'm a demo running on ZealPHP's SSE streaming. "
                . "This response is being streamed token-by-token using `\$response->sse()`. "
                . "To enable real AI responses, set the `ANTHROPIC_API_KEY` environment variable. "
                . "Each word you see is a separate SSE event — "
                . "the same mechanism that powers ChatGPT-style streaming UIs. "
                . "ZealPHP makes this a 5-line feature, not a 50-line infrastructure project.";
            foreach (explode(' ', $fallback) as $word) {
                usleep(60000);
                $emit(json_encode(['token' => $word . ' ']), 'token');
            }
            $emit(json_encode(['done' => true]), 'done');
        });
        return;
    }

    // Build messages array with thread history
    $messages = [];
    $existing = Store::get('chat_threads', $threadId);
    if ($existing) {
        $messages = json_decode($existing['messages'], true) ?: [];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    // Keep only last 10 messages to fit Store column size
    if (count($messages) > 10) {
        $messages = array_slice($messages, -10);
    }

    $response->sse(function($emit) use ($apiKey, $messages, $threadId) {
        $emit(json_encode(['thread_id' => $threadId]), 'thread');

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 512,
                'stream' => true,
                'system' => 'You are a helpful assistant embedded in the ZealPHP framework website. '
                    . 'Keep responses concise (2-3 sentences). You can use basic markdown. '
                    . 'If asked about ZealPHP, highlight its streaming, coroutine, and performance features.',
                'messages' => $messages,
            ]),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($emit, &$fullResponse) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, 'data: ') !== 0) continue;
                    $json = substr($line, 6);
                    if ($json === '[DONE]') continue;
                    $event = json_decode($json, true);
                    if (!$event) continue;

                    if (($event['type'] ?? '') === 'content_block_delta') {
                        $text = $event['delta']['text'] ?? '';
                        if ($text !== '') {
                            $fullResponse .= $text;
                            $emit(json_encode(['token' => $text]), 'token');
                        }
                    }
                }
                return strlen($data);
            },
        ]);

        $fullResponse = '';
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $emit(json_encode(['error' => 'API returned ' . $httpCode]), 'error');
            return;
        }

        // Save thread with assistant response
        $messages[] = ['role' => 'assistant', 'content' => $fullResponse];
        if (count($messages) > 10) {
            $messages = array_slice($messages, -10);
        }
        Store::set('chat_threads', $threadId, [
            'messages' => json_encode($messages),
            'updated'  => time(),
        ]);

        $emit(json_encode(['done' => true]), 'done');
    });
});

// GET endpoint to check if chat is available
$app->route('/api/chat/status', function() {
    return [
        'available' => true,
        'ai_enabled' => (bool)getenv('ANTHROPIC_API_KEY'),
        'model' => getenv('ANTHROPIC_API_KEY') ? 'claude-sonnet-4-20250514' : 'demo-fallback',
    ];
});
