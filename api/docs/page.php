<?php
// ZealAPI file: GET /api/docs/page?slug=routing
// Returns ONLY the <article class="lesson-content">...</article> for a docs
// guide (or the landing) — the sidebar lives outside the swap zone and
// stays put across navigations. Mirrors api/learn/page.php verbatim, so
// the existing _master.php substep + scrollspy code lights up on docs too.

use ZealPHP\App;
use ZealPHP\G;

$page = function () {
    $g = G::instance();
    $slug = trim((string) ($g->get['slug'] ?? ''));

    // Whitelist must match template/pages/docs/{index,_sidebar}.php and
    // MarkdownRenderer::GUIDE_SLUGS — adding a new .md to docs/ requires
    // touching all of them (route/docs.php renders any docs/{slug}.md, but
    // this hx-swap endpoint, the sidebar/index lists, and the cross-link
    // rewriter are each separately gated).
    $allowed = [
        'getting-started', 'directory-structure', 'runtime-architecture',
        'routing', 'api-layer', 'error-handling', 'templates-and-rendering', 'htmx',
        'streaming', 'websocket', 'WSROUTER-PRODUCTION', 'tasks-and-concurrency', 'middleware-and-authentication',
        'deployment', 'cli', 'hot-reload', 'fuzzing', 'coroutine-isolation-security-research',
        'fastcgi-backends', 'environment-variables',
        'compatibility-database', 'running-modern-apps', 'competitive-analysis', 'standards-and-roadmap',
    ];

    // Landing page ('__index__' or empty slug) — re-render the docs landing
    // article, identical to what public/docs/index.php produces, but extract
    // only the <article>.
    if ($slug === '' || $slug === '__index__') {
        $html = App::renderToString('/pages/docs/index', [
            'title'  => 'Documentation',
            'page'   => 'docs/index',
            'active' => 'docs',
        ]);
        if (preg_match('/<article class="lesson-content[^"]*">.*?<\/article>/s', $html, $m)) {
            header('Content-Type: text/html; charset=utf-8');
            $this->response($m[0], 200);
            return;
        }
        $this->response($this->json(['error' => 'render_failed']), 500);
        return;
    }

    if (!in_array($slug, $allowed, true)) {
        $this->response($this->json(['error' => 'not_found']), 404);
        return;
    }

    $repoRoot = dirname(__DIR__, 2);
    $mdPath = $repoRoot . '/docs/' . $slug . '.md';

    $realRoot = realpath($repoRoot . '/docs');
    $realMd   = realpath($mdPath);
    if ($realMd === false || $realRoot === false
        || !str_starts_with($realMd, $realRoot . DIRECTORY_SEPARATOR)
    ) {
        $this->response($this->json(['error' => 'not_found']), 404);
        return;
    }

    $body  = \ZealPHP\Docs\MarkdownRenderer::render((string) file_get_contents($realMd));
    $title = ucwords(str_replace(['-', '_'], ' ', $slug));

    $html = App::renderToString('/pages/docs/guide', [
        'title'   => $title . ' · ZealPHP Docs',
        'page'    => 'docs/guide',
        'active'  => 'docs',
        'topic'   => $slug,
        'heading' => $title,
        'body'    => $body,
    ]);

    if (preg_match('/<article class="lesson-content[^"]*">.*?<\/article>/s', $html, $m)) {
        header('Content-Type: text/html; charset=utf-8');
        $this->response($m[0], 200);
        return;
    }
    $this->response($this->json(['error' => 'extract_failed']), 500);
};
