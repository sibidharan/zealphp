<?php

/**
 * /sitemap.xml — auto-generated XML sitemap for the ZealPHP docs site.
 *
 * Walks the disk-mapped routes (public/*.php for top-level pages,
 * template/pages/{learn,docs,case-studies}/*.php for nested sections)
 * and emits a standard sitemaps.org 0.9 XML document. No static file —
 * stays in sync as pages are added or removed without a build step.
 *
 * Includes <lastmod> per URL from the source file's mtime so search
 * engines can re-crawl only what's changed. Skips:
 *   - Files starting with `_` (partials: _master, _head, _nav, …)
 *   - public/index.php (rendered as `/`, included separately)
 *   - The phpDocumentor /docs/api/* tree (~hundreds of generated HTML
 *     files; the /docs/api/ index is included so crawlers can discover
 *     them, but the index pages themselves carry the sitemap weight).
 *
 * Cached at the HTTP layer via ETagMiddleware (already in the stack) so
 * repeat crawls land on a 304 when nothing has changed.
 */

use ZealPHP\App;
use ZealPHP\G;

$app = App::instance();

$app->route('/sitemap.xml', function () {
    $g       = G::instance();
    $srv     = $g->server ?? [];
    $https   = (($srv['HTTPS'] ?? '') === 'on')
        || (($srv['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($srv['SERVER_PORT'] ?? 0) === 443);
    $scheme  = $https ? 'https' : 'http';
    $host    = (string) ($srv['HTTP_HOST'] ?? $srv['SERVER_NAME'] ?? 'php.zeal.ninja');
    $baseUrl = $scheme . '://' . $host;

    $cwd = App::$cwd;

    /** @var list<array{loc: string, lastmod: string}> */
    $urls = [];

    // 1. Home page — public/index.php rendered as `/`.
    $indexFile = $cwd . '/public/index.php';
    if (is_file($indexFile)) {
        $urls[] = ['loc' => '/', 'lastmod' => date('Y-m-d', (int) filemtime($indexFile))];
    }

    // 2. Top-level pages: public/*.php (Apache-style implicit routing).
    //    Skip index (already added as `/`), partials (`_`-prefixed),
    //    benchmark probes, and `home` (known-stale file from a prior
    //    iteration — not a real route in any version).
    foreach (glob($cwd . '/public/*.php') ?: [] as $file) {
        $name = basename($file, '.php');
        if ($name === 'index' || $name === 'home' || str_starts_with($name, '_') || str_starts_with($name, 'bench')) {
            continue;
        }
        $urls[] = [
            'loc'     => '/' . $name,
            'lastmod' => date('Y-m-d', (int) filemtime($file)),
        ];
    }

    // 3. /learn + /case-studies — 1-to-1 file-to-URL mapping per
    //    route/{section}.php's pattern route. Skip partials + index.
    foreach (['learn', 'case-studies'] as $section) {
        $dir = $cwd . '/template/pages/' . $section;
        if (!is_dir($dir)) {
            continue;
        }
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if ($name === 'index' || str_starts_with($name, '_')) {
                continue;
            }
            $urls[] = [
                'loc'     => '/' . $section . '/' . $name,
                'lastmod' => date('Y-m-d', (int) filemtime($file)),
            ];
        }
    }

    // 4. /docs/guide/{topic} — `route/docs.php` registers a pattern route
    //    that renders `docs/{topic}.md` via league/commonmark. The
    //    CANONICAL source of valid topics is `docs/*.md` (NOT
    //    template/pages/docs/*.php — those are framework partials, not
    //    routable URLs). Skip UPPERCASE-named docs (CHANGES-SINCE-*,
    //    README, WSROUTER-PRODUCTION) — they're internal release notes.
    $docsDir = $cwd . '/docs';
    if (is_dir($docsDir)) {
        foreach (glob($docsDir . '/*.md') ?: [] as $file) {
            $name = basename($file, '.md');
            if ($name === '' || ctype_upper($name[0])) {
                continue;
            }
            $urls[] = [
                'loc'     => '/docs/guide/' . $name,
                'lastmod' => date('Y-m-d', (int) filemtime($file)),
            ];
        }
    }

    // 5. /docs/ landing page.
    $docsIndex = $cwd . '/template/pages/docs/index.php';
    if (is_file($docsIndex)) {
        $urls[] = [
            'loc'     => '/docs/',
            'lastmod' => date('Y-m-d', (int) filemtime($docsIndex)),
        ];
    }

    // 6. phpDocumentor index — surfaces the /docs/api/ tree to crawlers.
    //    Individual class pages aren't enumerated (would balloon the
    //    sitemap into hundreds of low-priority URLs); the API index
    //    page carries the sitemap weight and crawlers discover the
    //    rest via the page's own nav.
    $apiIndex = $cwd . '/public/docs/api/index.html';
    if (is_file($apiIndex)) {
        $urls[] = [
            'loc'     => '/docs/api/',
            'lastmod' => date('Y-m-d', (int) filemtime($apiIndex)),
        ];
    }

    // Dedupe + sort for deterministic output (helps caching, makes the
    // diff readable when someone curls the live sitemap to compare).
    $seen = [];
    $uniq = [];
    foreach ($urls as $u) {
        if (isset($seen[$u['loc']])) {
            continue;
        }
        $seen[$u['loc']] = true;
        $uniq[]          = $u;
    }
    usort($uniq, static fn (array $a, array $b): int => strcmp($a['loc'], $b['loc']));

    // Build the XML by hand — DOM/SimpleXML add ~20 LOC of ceremony for
    // a flat document with two leaf tags.
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($uniq as $u) {
        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . htmlspecialchars($baseUrl . $u['loc'], ENT_XML1 | ENT_QUOTES) . '</loc>' . "\n";
        $xml .= '    <lastmod>' . $u['lastmod'] . '</lastmod>' . "\n";
        $xml .= '  </url>' . "\n";
    }
    $xml .= '</urlset>' . "\n";

    $g->zealphp_response?->header('Content-Type', 'application/xml; charset=utf-8');

    return $xml;
});
