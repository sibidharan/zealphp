<?php
// ZealAPI file: GET /api/learn/page?slug=routing
// Returns ONLY the <article class="lesson-content">...</article> for a lesson —
// the sidebar lives outside the swap zone and stays put across navigations.
// Used by htmx to swap lesson content without a full page reload.

use ZealPHP\App;
use ZealPHP\G;

${basename(__FILE__, '.php')} = function () {
    $g = G::instance();
    $slug = trim((string) ($g->get['slug'] ?? ''));

    $allowed = ['learn', 'learn/create-app', 'learn/first-page',
        'learn/mental-model', 'learn/project-structure', 'learn/routes', 'learn/lifecycle', 'learn/injection',
        'learn/responses', 'learn/middleware', 'learn/streaming', 'learn/store',
        'learn/components', 'learn/react-vs-php', 'learn/htmx', 'learn/sessions', 'learn/auth',
        'learn/notes', 'learn/ai-chat', 'learn/websocket', 'learn/tictactoe',
        'learn/cross-server-chat',
        'learn/routing', 'learn/async', 'learn/deployment'];

    if ($slug === '') $slug = 'learn';
    if (!in_array($slug, $allowed, true)) {
        $this->response($this->json(['error' => 'not_found']), 404);
        return;
    }

    $tplPath = $slug === 'learn' ? '/pages/learn' : '/pages/' . $slug;
    header('Content-Type: text/html; charset=utf-8');
    $html = App::renderToString($tplPath, ['active' => $slug, 'page' => $slug]);

    // Extract just the <article class="lesson-content">…</article> so the
    // htmx swap target receives the article alone — no nested layout/sidebar.
    if (preg_match('/<article class="lesson-content">.*?<\/article>/s', $html, $m)) {
        $html = $m[0];
    }

    $this->response($html, 200);
};
