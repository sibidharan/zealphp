<?php
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Learn\DB;
use ZealPHP\Learn\Auth;
use ZealPHP\Learn\Notes;

${basename(__FILE__, '.php')} = function () {
    $u = Auth::currentUser();
    if (!$u) { $this->response($this->json(['error' => 'auth_required']), 401); return; }
    $g = G::instance();
    $method = strtoupper($g->server['REQUEST_METHOD'] ?? 'GET');
    $db = DB::open();

    if ($method === 'POST') {
        $ct = $g->server['HTTP_CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $body = json_decode($g->zealphp_request->parent->getContent(), true) ?: [];
        } else {
            $body = $g->post;
        }
        $title    = (string) ($body['title'] ?? '');
        $bodyText = (string) ($body['body'] ?? '');
        $id = Notes::create($db, $u['user_id'], $title, $bodyText);
        if ($id === null) { $this->response($this->json(['error' => 'validation_failed']), 422); return; }
        if (function_exists('learn_ws_broadcast')) learn_ws_broadcast($u['user_id'], ['type' => 'note_changed', 'op' => 'create', 'id' => $id]);
        $note = Notes::read($db, $u['user_id'], $id);
        header('Content-Type: text/html; charset=utf-8');
        $this->response(App::renderToString('/components/_note_card', $note), 200);
        return;
    }

    // GET — list notes
    $notesList = Notes::list($db, $u['user_id']);
    header('Content-Type: text/html; charset=utf-8');
    if (empty($notesList)) {
        $this->response('<p class="notes-empty">No notes yet. Add one above.</p>', 200);
        return;
    }
    $html = '';
    foreach ($notesList as $n) {
        $html .= App::renderToString('/components/_note_card', $n);
    }
    $this->response($html, 200);
};
