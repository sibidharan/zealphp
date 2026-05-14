<?php
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Store;

Store::make('chat_ratelimit', 512, [
    'ip'    => [\OpenSwoole\Table::TYPE_STRING, 45],
    'count' => [\OpenSwoole\Table::TYPE_INT, 4],
    'reset' => [\OpenSwoole\Table::TYPE_INT, 4],
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

    $ip = $g->server['REMOTE_ADDR'] ?? 'unknown';
    $now = time();
    $window = 3600;
    $limit = 60;

    $existing = Store::get('chat_ratelimit', $ip);
    if ($existing) {
        if ($now < $existing['reset']) {
            if ($existing['count'] >= $limit) {
                $response->sse(function($emit) use ($threadId, $existing, $now) {
                    $emit(json_encode(['thread_id' => $threadId]), 'thread');
                    $mins = ceil(($existing['reset'] - $now) / 60);
                    $emit(json_encode(['token' => "<p>Rate limit reached (60 questions/hour). Try again in ~{$mins} minutes.</p>"]), 'token');
                    $emit(json_encode(['done' => true]), 'done');
                });
                return;
            }
            Store::incr('chat_ratelimit', $ip, 'count', 1);
        } else {
            Store::set('chat_ratelimit', $ip, [
                'ip' => $ip, 'count' => 1, 'reset' => $now + $window,
            ]);
        }
    } else {
        Store::set('chat_ratelimit', $ip, [
            'ip' => $ip, 'count' => 1, 'reset' => $now + $window,
        ]);
    }

    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        $response->sse(function($emit) use ($threadId, $message) {
            $emit(json_encode(['thread_id' => $threadId]), 'thread');
            $fallback = "<p>I'm a demo running on ZealPHP's SSE streaming. "
                . "This response is being streamed token-by-token using "
                . "<code>\$response->sse()</code>.</p>"
                . "<p>To enable real AI responses, set the <code>OPENAI_API_KEY</code> "
                . "environment variable. The backend uses the <strong>OpenAI Agents SDK</strong> "
                . "with <code>SQLiteSession</code> for conversation threads — "
                . "the SDK remembers your entire conversation automatically.</p>"
                . "<p>ZealPHP makes this a 5-line feature, not a 50-line infrastructure project.</p>";
            foreach (explode(' ', $fallback) as $word) {
                usleep(60000);
                $emit(json_encode(['token' => $word . ' ']), 'token');
            }
            $emit(json_encode(['done' => true]), 'done');
        });
        return;
    }

    // Agents SDK handles conversation history via SQLiteSession —
    // we just pass the message and thread_id, the SDK does the rest.
    $response->sse(function($emit) use ($apiKey, $message, $threadId) {
        $payload = json_encode([
            'message' => $message,
            'thread_id' => $threadId,
        ]);
        $b64 = base64_encode($payload);
        $agent = App::$cwd . '/examples/agents/chat_agent.py';
        $cmd = 'OPENAI_API_KEY=' . escapeshellarg($apiKey)
             . ' uv run ' . escapeshellarg($agent) . ' ' . escapeshellarg($b64);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            $emit(json_encode(['error' => 'Failed to start agent']), 'error');
            $emit(json_encode(['done' => true]), 'done');
            return;
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        $buffer = '';

        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 4096);
            if ($chunk === false || $chunk === '') {
                usleep(50000);
                continue;
            }
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if (strpos($line, 'data: ') === 0) {
                    $jsonStr = substr($line, 6);
                    $data = json_decode($jsonStr, true);
                    if ($data) {
                        if (isset($data['token'])) {
                            $emit($jsonStr, 'token');
                        } elseif (isset($data['thread_id'])) {
                            $emit($jsonStr, 'thread');
                        } elseif (isset($data['done'])) {
                            $emit(json_encode(['done' => true]), 'done');
                        } elseif (isset($data['error'])) {
                            $emit($jsonStr, 'error');
                        }
                    }
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    });
});

$app->route('/api/chat/status', function() {
    return [
        'available' => true,
        'ai_enabled' => (bool)getenv('OPENAI_API_KEY'),
        'model' => getenv('OPENAI_API_KEY') ? 'gpt-4.1-mini (Agents SDK)' : 'demo-fallback',
        'sessions' => 'SQLiteSession',
    ];
});
