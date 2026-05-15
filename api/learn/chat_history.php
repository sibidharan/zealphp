<?php
// ZealAPI file: GET /api/learn/chat_history?thread_id=XYZ
// Streams each historical message as an HTML fragment via App::renderStream.

use ZealPHP\App;
use ZealPHP\G;

require_once App::$cwd . '/route/learn.php';

${basename(__FILE__, '.php')} = function () {
    $u = learn_current_user();
    if (!$u) {
        $this->response($this->json(['error' => 'auth_required']), 401);
        return;
    }
    $g = G::instance();
    $threadId = (string)($g->get['thread_id'] ?? '');
    if ($threadId === '') {
        $this->response($this->json(['error' => 'thread_id_required']), 422);
        return;
    }

    $db = learn_db_open();
    $rows = learn_chat_history_for_thread($db, $u['user_id'], $threadId);

    header('Content-Type: text/html; charset=utf-8');
    if (empty($rows)) {
        $this->response('<p class="chat-empty">No history yet — start a new conversation.</p>', 200);
        return;
    }
    $html = '';
    foreach ($rows as $row) {
        $html .= App::renderToString('/components/_chat_history_bubble', [
            'role'  => $row['role'],
            'items' => json_decode($row['items_json'], true) ?: [],
        ]);
    }
    $this->response($html, 200);
};
