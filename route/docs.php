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

    // HTML pages get wrapped in our learn-layout so the API ref reads as
    // part of the site, not a green phpDocumentor island. Other assets
    // (CSS, JS, images, fonts) stream as-is via sendFile.
    if (str_ends_with(strtolower($realTarget), '.html')) {
        $raw = file_get_contents($realTarget);
        if ($raw === false) {
            return 500;
        }

        // Extract phpDocumentor's <main class="phpdocumentor"> inner HTML
        // via DOMDocument (regex is brittle for nested structures). Also
        // pull out the <title> and the first stylesheet href so the
        // wrapped page renders without phpdoc's own <head>.
        $libxmlPrev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $raw);
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlPrev);

        $xpath  = new DOMXPath($dom);

        // phpDocumentor templates: <main class="phpdocumentor">...</main>
        $mainNodes = $xpath->query('//main[contains(concat(" ", normalize-space(@class), " "), " phpdocumentor ")]');
        if (!$mainNodes || $mainNodes->length === 0) {
            // Fall back to raw-file serving if extraction fails — better
            // a green page than a 500.
            $response->sendFile($realTarget);
            $g->_streaming = true;
            return null;
        }

        $mainNode = $mainNodes->item(0);

        // Hoist phpDocumentor's own sidebar (Namespaces/Packages/Reports/
        // Indices tree) into $sidebarHtml so the wrap template can render
        // it inside a <details> accordion — then remove from the tree.
        // Also strip phpdoc's top header (we have our own nav) and the
        // mobile sidebar toggle (orphaned once the sidebar moves).
        $sidebarHtml = '';
        foreach ($xpath->query('.//aside[contains(@class, "phpdocumentor-sidebar")]', $mainNode) as $node) {
            $sidebarHtml = $dom->saveHTML($node);
            $node->parentNode->removeChild($node);
        }
        foreach ($xpath->query('.//header[contains(@class, "phpdocumentor-header")]', $mainNode) as $node) {
            $node->parentNode->removeChild($node);
        }
        foreach ($xpath->query('.//input[@id="sidebar-button"]', $mainNode) as $node) {
            $node->parentNode->removeChild($node);
        }
        foreach ($xpath->query('.//label[@for="sidebar-button"]', $mainNode) as $node) {
            $node->parentNode->removeChild($node);
        }

        // phpdoc uses <base href="../"> in <head> to make relative links
        // resolve to /docs/api/. We strip the <head>, so we must rewrite
        // every relative href/src to an absolute /docs/api/-prefixed path
        // so internal nav still works after the wrap.
        $base = '/docs/api/';
        $rewriteAttr = static function (string $val) use ($base): string {
            if ($val === '' || $val[0] === '#' || $val[0] === '/' || str_starts_with($val, 'http://') || str_starts_with($val, 'https://') || str_starts_with($val, 'mailto:') || str_starts_with($val, 'data:')) {
                return $val;
            }
            // Trim any leading ./ — base resolution treats them equal.
            $val = preg_replace('#^\./#', '', $val) ?? $val;
            return $base . $val;
        };
        foreach ($xpath->query('.//a[@href]', $mainNode) as $node) {
            $node->setAttribute('href', $rewriteAttr($node->getAttribute('href')));
        }
        foreach ($xpath->query('.//img[@src]|.//script[@src]', $mainNode) as $node) {
            $node->setAttribute('src', $rewriteAttr($node->getAttribute('src')));
        }
        // The sidebar HTML we extracted earlier also has relative links —
        // re-rewrite by re-parsing it. Cheap; only fires per page.
        if ($sidebarHtml !== '') {
            $sb = new DOMDocument();
            $libxmlPrev2 = libxml_use_internal_errors(true);
            $sb->loadHTML('<?xml encoding="utf-8"?>' . $sidebarHtml);
            libxml_clear_errors();
            libxml_use_internal_errors($libxmlPrev2);
            $sbx = new DOMXPath($sb);
            foreach ($sbx->query('//a[@href]') as $node) {
                $node->setAttribute('href', $rewriteAttr($node->getAttribute('href')));
            }
            $sidebarHtml = '';
            $asideRoot = $sb->getElementsByTagName('aside')->item(0);
            if ($asideRoot) {
                $sidebarHtml = $sb->saveHTML($asideRoot);
            }
        }

        $apiHtml = '';
        foreach ($mainNode->childNodes as $child) {
            $apiHtml .= $dom->saveHTML($child);
        }

        // Stylesheet — phpdoc emits <link rel="stylesheet" href="css/...">
        // and uses <base href="../"> in <head> to make it resolve to
        // /docs/api/css/... regardless of subpath. We strip the head, so
        // collapse to an absolute /docs/api/-prefixed path (same base
        // rewrite as the body links above).
        $apiCssHref = '/docs/api/css/template.css';  // sensible default
        foreach ($xpath->query('//link[@rel="stylesheet"]') as $link) {
            $href = $link->getAttribute('href');
            if ($href === '' || str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) continue;
            if (str_starts_with($href, '/')) {
                $apiCssHref = $href;
            } else {
                $apiCssHref = '/docs/api/' . ltrim(preg_replace('#^\./#', '', $href) ?? $href, '/');
            }
            break;
        }

        // Title — strip the trailing " | <project>" suffix phpdoc adds.
        $titleNodes = $xpath->query('//title');
        $apiTitle   = $titleNodes && $titleNodes->length
            ? trim((string) $titleNodes->item(0)->textContent)
            : 'API Reference';

        App::render('/_master', [
            'title'        => $apiTitle . ' · ZealPHP API',
            'page'         => 'docs/api-wrapped',
            'active'       => 'docs',
            'apiHtml'      => $apiHtml,
            'apiSidebar'   => $sidebarHtml,
            'apiCssHref'   => $apiCssHref,
            'apiTitle'     => $apiTitle,
        ]);
        return null;
    }

    $response->sendFile($realTarget);
    $g->_streaming = true;
    return null;
});
