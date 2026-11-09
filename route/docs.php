<?php

/**
 * /docs routes — narrative guides + auto-generated API reference.
 *
 *   /docs/                 — landing page (template/pages/docs/index.php)
 *   /docs/guide/{topic}    — renders docs/{topic}.md via league/commonmark
 *   /docs/api/             — phpDocumentor static HTML (built on first boot)
 *   /docs/api/{path...}    — static files under public/docs/api/
 *
 * The /docs/ landing is served by public/docs/index.php via the implicit
 * public-file route; this file owns the two dynamic paths.
 */

use ZealPHP\App;

$app = App::instance();

/**
 * /docs/guide/{topic} — render docs/{topic}.md as HTML through _master layout.
 */
$app->route('/docs/guide/{topic}', function (string $topic) {
    $repoRoot = dirname(__DIR__);
    $candidate = $repoRoot . '/docs/' . basename($topic) . '.md';

    if (!is_file($candidate)) {
        return 404;
    }

    $realRoot = realpath($repoRoot . '/docs');
    $realCandidate = realpath($candidate);
    if ($realRoot === false || $realCandidate === false
        || !str_starts_with($realCandidate, $realRoot . DIRECTORY_SEPARATOR)
    ) {
        return 404;
    }

    $markdown = (string) file_get_contents($candidate);
    $body = \ZealPHP\Docs\MarkdownRenderer::render($markdown);
    $desc = \ZealPHP\Docs\MarkdownRenderer::summary($markdown);

    $title = ucwords(str_replace(['-', '_'], ' ', $topic));

    App::render('/_master', [
        'title'       => $title . ' · ZealPHP Docs',
        'description' => $desc !== '' ? $desc : null,
        'page'        => 'docs/guide',
        'active'      => 'docs',
        'topic'       => $topic,
        'heading'     => $title,
        'body'        => $body,
    ]);
});

/**
 * /docs/api[/{path}...] — serve phpDocumentor static HTML.
 *
 * patternRoute is required because the implicit public-file route only
 * resolves *.php; phpDocumentor emits *.html that need explicit handling.
 * Container path is constrained via realpath() against public/docs/api/.
 */
$app->patternRoute('#^/docs/api(/.*)?$#', function ($response) {
    /** @var \ZealPHP\HTTP\Response $response */
    $g = \ZealPHP\G::instance();
    $uri = parse_url((string) ($g->server['REQUEST_URI'] ?? '/docs/api/'), PHP_URL_PATH) ?? '/docs/api/';
    $rel = substr($uri, strlen('/docs/api'));
    if ($rel === '' || $rel === '/') {
        $rel = '/index.html';
    } elseif (str_ends_with($rel, '/')) {
        $rel .= 'index.html';
    }

    $apiRoot = realpath(dirname(__DIR__) . '/public/docs/api');
    if ($apiRoot === false) {
        App::render('/_master', [
            'title'  => 'API reference being built · ZealPHP Docs',
            'page'   => 'docs/api-missing',
            'active' => 'docs',
        ]);
        return null;
    }

    // Curated API landing — replace phpDocumentor's flat Table-of-Contents
    // at the root with a class index grouped by namespace (built from the
    // generated class pages). Far more navigable than the phpdoc default.
    if ($rel === '/index.html') {
        App::render('/_master', [
            'title'     => 'API Reference · ZealPHP Docs',
            'page'      => 'docs/api-index',
            'active'    => 'docs',
            'apiGroups' => \ZealPHP\Docs\ApiIndex::groups($apiRoot . '/classes'),
        ]);
        return null;
    }

    // `G` is a runtime class_alias of RequestContext, so phpDocumentor emits
    // no ZealPHP-G.html page. Redirect the old short name to the canonical
    // class page instead of 404ing, so links/searches for `G` resolve.
    if ($rel === '/classes/ZealPHP-G.html') {
        $response->redirect('/docs/api/classes/ZealPHP-RequestContext.html', 301);
        return null;
    }

    $target = $apiRoot . $rel;
    $realTarget = realpath($target);
    if ($realTarget === false
        || !is_file($realTarget)
        || !str_starts_with($realTarget, $apiRoot . DIRECTORY_SEPARATOR)
    ) {
        return 404;
    }

    // HTML pages get wrapped in our learn-layout so the API ref reads as
    // part of the site, not a green phpDocumentor island (ApiPage extracts
    // the content + sidebar and rewrites links). Other assets (CSS, JS,
    // images, fonts) stream as-is via sendFile — and so does a page
    // ApiPage can't extract: better a green page than a 500.
    if (str_ends_with(strtolower($realTarget), '.html')) {
        $raw = file_get_contents($realTarget);
        if ($raw === false) {
            return 500;
        }
        $page = \ZealPHP\Docs\ApiPage::wrap($raw);
        if ($page !== null) {
            App::render('/_master', [
                'title'        => $page['title'] . ' · ZealPHP API',
                'page'         => 'docs/api-wrapped',
                'active'       => 'docs',
                'apiHtml'      => $page['html'],
                'apiSidebar'   => $page['sidebar'],
                'apiCssHref'   => $page['cssHref'],
                'apiTitle'     => $page['title'],
                'apiCrumb'     => \ZealPHP\Docs\ApiIndex::breadcrumb($rel),
            ]);
            return null;
        }
    }

    $response->sendFile($realTarget);
    $g->_streaming = true;
    return null;
});
