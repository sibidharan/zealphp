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
use League\CommonMark\GithubFlavoredMarkdownConverter;

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

    $converter = new GithubFlavoredMarkdownConverter([
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ]);
    $body = (string) $converter->convert(file_get_contents($candidate));

    $title = ucwords(str_replace(['-', '_'], ' ', $topic));

    App::render('/_master', [
        'title'   => $title . ' · ZealPHP Docs',
        'page'    => 'docs/guide',
        'active'  => 'docs',
        'topic'   => $topic,
        'heading' => $title,
        'body'    => $body,
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

    $target = $apiRoot . $rel;
    $realTarget = realpath($target);
    if ($realTarget === false
        || !is_file($realTarget)
        || !str_starts_with($realTarget, $apiRoot . DIRECTORY_SEPARATOR)
    ) {
        return 404;
    }

    $response->sendFile($realTarget);
    $g->_streaming = true;
    return null;
});
