<?php

declare(strict_types=1);

namespace ZealPHP\Docs;

/**
 * Turns a phpDocumentor page (public/docs/api/**.html) into the pieces the
 * site's `docs/api-wrapped` template renders inside its own layout, so the
 * API reference reads as part of the site, not a green phpDocumentor island.
 *
 * From the page's `<main class="phpdocumentor">` it keeps the content and
 * hoists phpDocumentor's sidebar (Namespaces/Packages/Reports/Indices) out
 * for the template's accordion; it drops phpdoc's header, mobile sidebar
 * toggle and search UI (the site has its own). Because the page's `<head>`
 * (and its `<base href="../">`) is discarded, every relative href/src is
 * rewritten to an absolute /docs/api/ path, and internal API page links get
 * htmx attributes so navigating swaps `.lesson-content` in place, like the
 * guide nav.
 */
final class ApiPage
{
    private const BASE = '/docs/api/';

    /**
     * @return array{html: string, sidebar: string, cssHref: string, title: string}|null
     *         null when the page has no `<main class="phpdocumentor">` (serve the
     *         raw file instead)
     */
    public static function wrap(string $raw): ?array
    {
        $dom = self::parse($raw);
        $xpath = new \DOMXPath($dom);

        $mainNode = self::nodes($xpath, '//main[contains(concat(" ", normalize-space(@class), " "), " phpdocumentor ")]')[0] ?? null;
        if ($mainNode === null) {
            return null;
        }

        // Hoist the sidebar into $sidebarHtml so the wrap template can render
        // it inside a <details> accordion, then remove it from the tree.
        $sidebarHtml = '';
        foreach (self::nodes($xpath, './/aside[contains(@class, "phpdocumentor-sidebar")]', $mainNode) as $node) {
            $sidebarHtml = (string) $dom->saveHTML($node);
            self::remove($node);
        }
        // phpdoc's top header (the site has its own nav), the mobile sidebar
        // toggle (orphaned once the sidebar moves) and phpdoc's search UI:
        // the search form AND the results modal, which would otherwise render
        // as an empty "Search results" header (phpdoc's search.js isn't
        // running).
        foreach ([
            './/header[contains(@class, "phpdocumentor-header")]',
            './/input[@id="sidebar-button"]',
            './/label[@for="sidebar-button"]',
            './/section[contains(@class, "phpdocumentor-search-results")]',
            './/section[contains(@class, "phpdocumentor-search") and not(contains(@class, "phpdocumentor-search-results"))]',
        ] as $query) {
            foreach (self::nodes($xpath, $query, $mainNode) as $node) {
                self::remove($node);
            }
        }

        foreach (self::nodes($xpath, './/a[@href]', $mainNode) as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $href = self::absolute($node->getAttribute('href'));
            $node->setAttribute('href', $href);
            // Internal API page links (a clean /docs/api/*.html with no
            // #fragment) swap .lesson-content in place — no full reload,
            // sidebar preserved. Anchor (#method) links keep plain behaviour
            // (in-page jumps).
            if (preg_match('~^/docs/api/[^#?]+\.html$~', $href) === 1) {
                $node->setAttribute('hx-get', $href);
                $node->setAttribute('hx-target', '.lesson-content');
                $node->setAttribute('hx-select', '.lesson-content');
                $node->setAttribute('hx-swap', 'outerHTML show:.learn-layout:top');
                $node->setAttribute('hx-push-url', $href);
            }
        }
        foreach (self::nodes($xpath, './/img[@src]|.//script[@src]', $mainNode) as $node) {
            if ($node instanceof \DOMElement) {
                $node->setAttribute('src', self::absolute($node->getAttribute('src')));
            }
        }

        // The hoisted sidebar has relative links too: re-parse and rewrite.
        if ($sidebarHtml !== '') {
            $sb = self::parse($sidebarHtml);
            foreach (self::nodes(new \DOMXPath($sb), '//a[@href]') as $node) {
                if ($node instanceof \DOMElement) {
                    $node->setAttribute('href', self::absolute($node->getAttribute('href')));
                }
            }
            $sidebarHtml = '';
            $asideRoot = $sb->getElementsByTagName('aside')->item(0);
            if ($asideRoot !== null) {
                $sidebarHtml = (string) $sb->saveHTML($asideRoot);
            }
        }

        $apiHtml = '';
        foreach ($mainNode->childNodes as $child) {
            $apiHtml .= (string) $dom->saveHTML($child);
        }

        return [
            'html'    => self::backticksToCode($apiHtml),
            'sidebar' => $sidebarHtml,
            'cssHref' => self::stylesheet($xpath),
            'title'   => self::title($xpath),
        ];
    }

    /** Parse HTML as UTF-8 without libxml warnings leaking out. */
    private static function parse(string $html): \DOMDocument
    {
        $libxmlPrev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlPrev);
        return $dom;
    }

    /** @return list<\DOMNode> */
    private static function nodes(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): array
    {
        $list = $xpath->query($query, $context);
        if ($list === false) {
            return [];
        }
        $out = [];
        foreach ($list as $node) {
            if ($node instanceof \DOMNode) {
                $out[] = $node;
            }
        }
        return $out;
    }

    private static function remove(\DOMNode $node): void
    {
        $node->parentNode?->removeChild($node);
    }

    /** A relative href/src made absolute under /docs/api/ (what phpdoc's <base> did). */
    private static function absolute(string $val): string
    {
        if ($val === '' || $val[0] === '#' || $val[0] === '/' || str_starts_with($val, 'http://') || str_starts_with($val, 'https://') || str_starts_with($val, 'mailto:') || str_starts_with($val, 'data:')) {
            return $val;
        }
        // Trim any leading ./ — base resolution treats them equal.
        $val = preg_replace('#^\./#', '', $val) ?? $val;
        return self::BASE . $val;
    }

    /**
     * phpdoc keeps backticks as literal characters in summaries (its default
     * template doesn't run markdown). Single-line backtick pairs become <code>
     * chips; ranges already inside <code>/<pre>/<script>/<style> pass through,
     * and the one-line limit keeps multi-line ```fences``` from being
     * swallowed.
     */
    private static function backticksToCode(string $html): string
    {
        return (string) preg_replace_callback(
            '#(<(?:code|pre|script|style)\b[^>]*>.*?</(?:code|pre|script|style)>)|`([^`\n<>]{1,160})`#si',
            static function (array $m): string {
                if ($m[1] !== '') {
                    return $m[1];
                }
                return '<code>' . $m[2] . '</code>';
            },
            $html
        );
    }

    /**
     * The page's first local stylesheet, made absolute under /docs/api/
     * (phpdoc emits `css/...` relative to its <base>).
     */
    private static function stylesheet(\DOMXPath $xpath): string
    {
        $cssHref = '/docs/api/css/template.css';  // sensible default
        foreach (self::nodes($xpath, '//link[@rel="stylesheet"]') as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }
            $href = $link->getAttribute('href');
            if ($href === '' || str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                continue;
            }
            if (str_starts_with($href, '/')) {
                $cssHref = $href;
            } else {
                $cssHref = self::BASE . ltrim(preg_replace('#^\./#', '', $href) ?? $href, '/');
            }
            break;
        }
        return $cssHref;
    }

    private static function title(\DOMXPath $xpath): string
    {
        $titleNode = self::nodes($xpath, '//title')[0] ?? null;
        return $titleNode !== null ? trim($titleNode->textContent) : 'API Reference';
    }
}
