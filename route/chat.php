<?php
use ZealPHP\App;
use ZealPHP\G;

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

    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        $response->sse(function($emit) use ($threadId, $message) {
            $emit(json_encode(['thread_id' => $threadId]), 'thread');
            $fallback = "I'm a demo running on ZealPHP's SSE streaming. "
                . "This response is being streamed token-by-token using `\$response->sse()`. "
                . "To enable real AI responses, set the `OPENAI_API_KEY` environment variable. "
                . "The backend uses the OpenAI Agents SDK with SQLiteSession for conversation threads — "
                . "the SDK remembers your entire conversation automatically. "
                . "ZealPHP makes this a 5-line feature, not a 50-line infrastructure project.";
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
