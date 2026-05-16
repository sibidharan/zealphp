<?php
namespace ZealPHP\Learn;

use ZealPHP\App;
use ZealPHP\RequestContext;

class Chat
{
    /**
     * @param mixed                $response  ZealPHP\HTTP\Response (or compatible) — calls $response->sse(callable)
     * @param array<string, mixed> $user
     */
    public static function mock($response, array $user, string $message, string $threadId): void
    {
        $db = DB::open();
        $userId = $user['user_id'];
        $msgLower = strtolower($message);

        ChatHistory::append($db, $userId, $threadId, 'user', [['type' => 'text', 'html' => '<p>' . htmlspecialchars($message) . '</p>']]);

        $response->sse(function ($emit) use ($db, $userId, $message, $msgLower, $threadId) {
            $items = [];
            $textBuf = '';
            $flushText = function () use (&$items, &$textBuf) {
                if ($textBuf !== '') { $items[] = ['type' => 'text', 'html' => $textBuf]; $textBuf = ''; }
            };
            $sse = function (string $data, string $event) use ($emit, &$items, &$textBuf, $flushText) {
                $emit($data, $event);
                $payload = json_decode($data, true) ?: [];
                if ($event === 'token') {
                    $textBuf .= (string) ($payload['token'] ?? '');
                } elseif ($event === 'tool_call') {
                    $flushText();
                    $items[] = ['type' => 'tool', 'id' => $payload['id'] ?? '?', 'name' => $payload['name'] ?? '?', 'status' => 'running', 'args' => '', 'result' => ''];
                } elseif ($event === 'tool_args') {
                    foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['args'] .= (string) ($payload['delta'] ?? ''); break; }
                } elseif ($event === 'tool_done') {
                    foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['status'] = $payload['status'] ?? 'ok'; $it['result'] = (string) ($payload['result_preview'] ?? ''); break; }
                }
            };

            $sse(json_encode(['thread_id' => $threadId]), 'thread');

            if (preg_match('/(list|show all|what\'?s in)/i', $msgLower)) {
                $sse(json_encode(['id' => 'm1', 'name' => 'list_notes', 'phase' => 'start']), 'tool_call');
                usleep(120000);
                $notes = Notes::list($db, $userId);
                $sse(json_encode(['id' => 'm1', 'status' => 'ok', 'result_preview' => count($notes) . ' notes']), 'tool_done');
                if (empty($notes)) {
                    $sse(json_encode(['token' => '<p>No notes yet. Try "create a note titled buy milk".</p>']), 'token');
                } else {
                    $html = '<ul>' . implode('', array_map(fn($n) => '<li>' . htmlspecialchars($n['title']) . ' — id ' . (int) $n['id'] . '</li>', $notes)) . '</ul>';
                    $sse(json_encode(['token' => '<p>Here are your notes:</p>' . $html]), 'token');
                }
            } elseif (preg_match('/(create|add)(\s+a)?\s+note(\s+(titled|called|saying))?\s+["\']?(.+?)["\']?$/i', $message, $m)) {
                $title = trim($m[5]);
                if ($title === '') {
                    $title = 'untitled';
                }
                $sse(json_encode(['token' => '<p>Got it, creating that note.</p>']), 'token');
                $sse(json_encode(['id' => 'm2', 'name' => 'create_note', 'phase' => 'start']), 'tool_call');
                $json = json_encode(['title' => $title, 'body' => '']);
                foreach (str_split($json, 12) as $chunk) {
                    $sse(json_encode(['id' => 'm2', 'delta' => $chunk]), 'tool_args');
                    usleep(40000);
                }
                $newId = Notes::create($db, $userId, $title, '');
                $sse(json_encode(['id' => 'm2', 'status' => $newId ? 'ok' : 'error', 'result_preview' => $newId ? "id: $newId" : 'failed']), 'tool_done');
                $sse(json_encode([]), 'notes_changed');
                WS::broadcast($userId, ['type' => 'note_changed', 'op' => 'create', 'id' => $newId]);
                $sse(json_encode(['token' => "<p>Created note <strong>" . htmlspecialchars($title) . "</strong>.</p>"]), 'token');
            } elseif (preg_match('/delete\s+(?:note\s+)?["\']?(.+?)["\']?$/i', $message, $m)) {
                $needle = trim($m[1]);
                $notes = Notes::list($db, $userId);
                $hit = null;
                foreach ($notes as $n) if (stripos($n['title'], $needle) !== false) { $hit = $n; break; }
                if (!$hit) {
                    $sse(json_encode(['token' => "<p>I couldn't find a note matching <em>" . htmlspecialchars($needle) . "</em>.</p>"]), 'token');
                } else {
                    $sse(json_encode(['id' => 'm3', 'name' => 'delete_note', 'phase' => 'start']), 'tool_call');
                    Notes::delete($db, $userId, (int) $hit['id']);
                    $sse(json_encode(['id' => 'm3', 'status' => 'ok', 'result_preview' => 'deleted id ' . $hit['id']]), 'tool_done');
                    $sse(json_encode([]), 'notes_changed');
                    WS::broadcast($userId, ['type' => 'note_changed', 'op' => 'delete', 'id' => (int) $hit['id']]);
                    $sse(json_encode(['token' => "<p>Deleted note <strong>" . htmlspecialchars($hit['title']) . "</strong>.</p>"]), 'token');
                }
            } elseif (preg_match('/(search|find)\s+(.+)/i', $message, $m)) {
                $q = trim($m[2]);
                $sse(json_encode(['id' => 'm4', 'name' => 'search_notes', 'phase' => 'start']), 'tool_call');
                $hits = Notes::search($db, $userId, $q);
                $sse(json_encode(['id' => 'm4', 'status' => 'ok', 'result_preview' => count($hits) . ' hits']), 'tool_done');
                if (empty($hits)) $sse(json_encode(['token' => "<p>No notes match <em>" . htmlspecialchars($q) . "</em>.</p>"]), 'token');
                else $sse(json_encode(['token' => '<ul>' . implode('', array_map(fn($n) => '<li>' . htmlspecialchars($n['title']) . '</li>', $hits)) . '</ul>']), 'token');
            } else {
                $sse(json_encode(['token' => '<p>Mock mode is active — set <code>OPENAI_API_KEY</code> for the real model. Try: <em>create a note titled buy milk</em>, <em>list notes</em>, <em>delete buy milk</em>, <em>search groceries</em>.</p>']), 'token');
            }

            $flushText();
            ChatHistory::append($db, $userId, $threadId, 'assistant', $items);
            $emit(json_encode(['done' => true]), 'done');
        });
    }

    /**
     * @param mixed                $response  ZealPHP\HTTP\Response (or compatible) — calls $response->sse(callable)
     * @param array<string, mixed> $user
     */
    public static function real($response, array $user, string $message, string $threadId, string $apiKey): void
    {
        $db = DB::open();
        $notes = Notes::list($db, $user['user_id']);
        $recent = array_slice(array_map(fn($n) => $n['title'], $notes), 0, 5);

        ChatHistory::append($db, $user['user_id'], $threadId, 'user', [['type' => 'text', 'html' => '<p>' . htmlspecialchars($message) . '</p>']]);

        $g = RequestContext::instance();
        $payload = [
            'message'    => $message,
            'thread_id'  => $threadId,
            'session_id' => session_id(),
            'api_base'   => 'http://127.0.0.1:' . ($g->server['SERVER_PORT'] ?? '8080'),
            'user_id'    => $user['user_id'],
            'profile'    => [
                'username'           => $user['username'],
                'note_count'         => count($notes),
                'recent_note_titles' => $recent,
            ],
        ];
        $b64 = base64_encode(json_encode($payload));
        $agentPath = App::$cwd . '/examples/agents/notes_agent.py';

        $response->sse(function ($emit) use ($apiKey, $b64, $agentPath, $threadId, $db, $user) {
            $model = (string) (getenv('ZEALPHP_LEARN_AI_MODEL') ?: 'gpt-4.1-mini');
            $cmd = 'OPENAI_API_KEY=' . escapeshellarg($apiKey)
                 . ' ZEALPHP_LEARN_AI_MODEL=' . escapeshellarg($model)
                 . ' PYTHONUNBUFFERED=1'
                 . ' uv run ' . escapeshellarg($agentPath) . ' ' . escapeshellarg($b64);

            $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $desc, $pipes);
            if (!is_resource($proc)) {
                $emit(json_encode(['thread_id' => $threadId]), 'thread');
                $emit(json_encode(['error' => 'agent_unavailable']), 'error');
                $emit(json_encode(['done' => true]), 'done');
                return;
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);

            $items = [];
            $textBuf = '';
            $flush = function () use (&$items, &$textBuf) { if ($textBuf !== '') { $items[] = ['type' => 'text', 'html' => $textBuf]; $textBuf = ''; } };
            $reemit = function (string $data, string $event) use ($emit, &$items, &$textBuf, $flush) {
                $emit($data, $event);
                $payload = json_decode($data, true) ?: [];
                if ($event === 'token') {
                    $textBuf .= (string) ($payload['token'] ?? '');
                } elseif ($event === 'tool_call') {
                    $flush();
                    $items[] = ['type' => 'tool', 'id' => $payload['id'] ?? '?', 'name' => $payload['name'] ?? '?', 'status' => 'running', 'args' => '', 'result' => ''];
                } elseif ($event === 'tool_args') {
                    foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['args'] .= (string) ($payload['delta'] ?? ''); break; }
                } elseif ($event === 'tool_done') {
                    foreach ($items as &$it) if ($it['type'] === 'tool' && $it['id'] === ($payload['id'] ?? '')) { $it['status'] = $payload['status'] ?? 'ok'; $it['result'] = (string) ($payload['result_preview'] ?? ''); break; }
                }
            };

            $buffer = '';
            $currentEvent = null;
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
                    $line = rtrim($line, "\r");
                    if (str_starts_with($line, 'event: ')) $currentEvent = trim(substr($line, 7));
                    elseif (str_starts_with($line, 'data: ')) $reemit(substr($line, 6), $currentEvent ?: 'token');
                }
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            $flush();
            ChatHistory::append($db, $user['user_id'], $threadId, 'assistant', $items);
        });
    }
}
