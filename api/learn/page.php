<?php
// ZealAPI file: GET /api/learn/page?slug=routing
// Renders just the <div class="learn-layout">...</div> for a lesson.
// Used by htmx to swap lesson content without a full page reload.

use ZealPHP\App;
use ZealPHP\G;

${basename(__FILE__, '.php')} = function () {
    $g = G::instance();
    $slug = trim((string) ($g->get['slug'] ?? ''));

    $allowed = ['learn', 'learn/create-app', 'learn/first-page', 'learn/components',
        'learn/routing', 'learn/sessions', 'learn/htmx', 'learn/notes',
        'learn/ai-chat', 'learn/async', 'learn/deployment', 'learn/philosophy'];

    if ($slug === '') $slug = 'learn';
    if (!in_array($slug, $allowed, true)) {
        $this->response($this->json(['error' => 'not_found']), 404);
        return;
    }

    $tplPath = $slug === 'learn' ? '/pages/learn' : '/pages/' . $slug;
    header('Content-Type: text/html; charset=utf-8');
    $html = App::renderToString($tplPath, ['active' => $slug, 'page' => $slug]);
    $this->response($html, 200);
};
