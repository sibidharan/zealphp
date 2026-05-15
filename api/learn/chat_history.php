<?php
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Learn\DB;
use ZealPHP\Learn\Auth;
use ZealPHP\Learn\ChatHistory;

${basename(__FILE__, '.php')} = function () {
    $u = Auth::currentUser();
    if (!$u) {
        $this->response($this->json(['error' => 'auth_required']), 401);
        return;
    }
    $g = G::instance();
    $threadId = (string) ($g->get['thread_id'] ?? '');
    if ($threadId === '') {
        $this->response($this->json(['error' => 'thread_id_required']), 422);
        return;
    }

    $db = DB::open();
    $rows = ChatHistory::forThread($db, $u['user_id'], $threadId);

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
