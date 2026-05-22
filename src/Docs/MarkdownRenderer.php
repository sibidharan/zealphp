<?php

declare(strict_types=1);

namespace ZealPHP\Docs;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Renders the `docs/*.md` guides to HTML for the `/docs/guide/{topic}`
 * surface. Shared by `route/docs.php` (full-page render) and
 * `api/docs/page.php` (htmx swap) so the conversion + link-rewrite rules
 * never drift between the two.
 *
 * The one non-obvious job here is rewriting in-doc `.md` links. A guide
 * that writes `[routing](./routing.md)` produces `href="./routing.md"`,
 * which resolves to `/docs/guide/routing.md` (a 404 — the route is
 * `/docs/guide/routing`, no extension). `rewriteMarkdownLinks()` fixes
 * that: sibling-guide links collapse to `/docs/guide/<slug>` and any
 * other `.md` (e.g. `../STANDARDS.md`, `../README.md`) points at the
 * GitHub blob so it still resolves.
 */
final class MarkdownRenderer
{
    /**
     * The 16 guide slugs surfaced at `/docs/guide/{topic}`. Must match
     * the whitelist in `api/docs/page.php` + `route/docs.php` + the
     * sidebar in `template/pages/docs/_sidebar.php`. Adding a guide
     * means touching all four sites.
     *
     * @var list<string>
     */
    private const GUIDE_SLUGS = [
        'getting-started', 'directory-structure', 'runtime-architecture',
        'routing', 'api-layer', 'error-handling', 'templates-and-rendering',
        'streaming', 'websocket', 'tasks-and-concurrency', 'middleware-and-authentication',
        'deployment', 'fuzzing', 'apache-parity', 'competitive-analysis', 'standards-and-roadmap',
    ];

    /** GitHub blob base for `.md` files that aren't surfaced guides. */
    private const GITHUB_BLOB = 'https://github.com/sibidharan/zealphp/blob/master/';

    /**
     * Convert GitHub-flavoured Markdown to HTML, then rewrite in-doc
     * `.md` links to resolvable URLs.
     */
    public static function render(string $markdown): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = (string) $converter->convert($markdown);

        return self::rewriteMarkdownLinks($html);
    }

    /**
     * Rewrite `href="….md"` (with optional `#anchor`) so the link
     * resolves:
     *   - sibling guide (`routing.md`, `./routing.md`) → `/docs/guide/routing`
     *   - any other `.md` (`../STANDARDS.md`)          → GitHub blob URL
     *   - already-absolute http(s) links               → left untouched
     */
    private static function rewriteMarkdownLinks(string $html): string
    {
        return (string) preg_replace_callback(
            '/href="([^"#]+\.md)(#[^"]*)?"/i',
            static function (array $m): string {
                $path   = $m[1];
                $anchor = $m[2] ?? '';

                if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                    return $m[0];
                }

                $slug = basename($path, '.md');
                if (in_array($slug, self::GUIDE_SLUGS, true)) {
                    return 'href="/docs/guide/' . $slug . $anchor . '"';
                }

                // Non-guide .md — strip leading ./ and ../ and resolve
                // against the repo root on GitHub.
                $clean = (string) preg_replace('#^(?:\.\./|\./)+#', '', $path);
                $clean = ltrim($clean, '/');

                return 'href="' . self::GITHUB_BLOB . $clean . $anchor . '"';
            },
            $html
        );
    }
}
