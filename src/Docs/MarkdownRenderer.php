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
     * The guide slugs surfaced at `/docs/guide/{topic}`. Used to rewrite
     * sibling-guide cross-links (`../<slug>.md`) to on-site `/docs/guide/`
     * URLs; anything not listed resolves to a GitHub blob link instead.
     * Keep in sync with the whitelist in `api/docs/page.php` and the
     * `$groups` lists in `template/pages/docs/{index,_sidebar}.php`
     * (`route/docs.php` renders any `docs/{slug}.md` regardless). Note
     * `apache-parity` is intentionally absent — its on-site home is the
     * richer `/http#parity` section, not the standalone guide.
     *
     * @var list<string>
     */
    private const GUIDE_SLUGS = [
        'getting-started', 'directory-structure', 'runtime-architecture',
        'routing', 'api-layer', 'error-handling', 'templates-and-rendering', 'htmx',
        'streaming', 'websocket', 'WSROUTER-PRODUCTION', 'tasks-and-concurrency', 'middleware-and-authentication',
        'deployment', 'cli', 'hot-reload', 'fuzzing', 'coroutine-isolation-security-research',
        'fastcgi-backends', 'environment-variables',
        'compatibility-database', 'running-modern-apps', 'competitive-analysis', 'standards-and-roadmap',
    ];

    /** GitHub blob base for `.md` files that aren't surfaced guides. */
    private const GITHUB_BLOB = 'https://github.com/sibidharan/zealphp/blob/master/';

    /**
     * Extract a one-line summary from raw Markdown for use as the page
     * `<meta name="description">` + Open Graph description. Returns the
     * first real paragraph (skipping the H1 title, headings, code
     * fences, blockquotes, tables, and list markers), stripped of
     * Markdown syntax and clamped to ~200 chars. Empty string when no
     * prose paragraph is found (caller falls back to the site default).
     */
    public static function summary(string $markdown): string
    {
        $inFence = false;
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '```')) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence || $trimmed === '') {
                continue;
            }
            // Skip headings, blockquotes, table rows, list items, hr.
            if (preg_match('/^(#|>|\||-{3,}|\*|\d+\.\s|\-\s|\+\s)/', $trimmed)) {
                continue;
            }
            // First prose line — strip inline Markdown to plain text.
            $text = $trimmed;
            $text = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text); // links → label
            $text = (string) preg_replace('/[`*_~]+/', '', $text);                  // emphasis/code
            $text = trim((string) preg_replace('/\s+/', ' ', $text));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > 200) {
                $text = rtrim(mb_substr($text, 0, 197)) . '…';
            }
            return $text;
        }
        return '';
    }

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
     * Rewrite in-doc links so they resolve when served at /docs/guide/.
     * Guides cross-link each other and reference repo source files with
     * paths that are relative to the `docs/` directory on disk — none of
     * which map to a servable URL as-is.
     *
     *   - sibling guide (`routing.md`, `./routing.md`)        → `/docs/guide/routing`
     *   - any other repo file (`../src/App.php`, `../STANDARDS.md`,
     *     `../tests/Foo.php`, `../route/bar.php`)             → GitHub blob URL
     *   - already-absolute (`http(s)://`, `/site/path`, `#anchor`,
     *     `mailto:`, `data:`)                                 → left untouched
     */
    private static function rewriteMarkdownLinks(string $html): string
    {
        return (string) preg_replace_callback(
            '/href="([^"#]+)(#[^"]*)?"/i',
            static function (array $m): string {
                $path   = $m[1];
                $anchor = $m[2] ?? '';

                // Absolute / already-resolvable links — leave untouched.
                // ($path is always non-empty: the regex group is [^"#]+.)
                if ($path[0] === '/'
                    || str_starts_with($path, 'http://')
                    || str_starts_with($path, 'https://')
                    || str_starts_with($path, 'mailto:')
                    || str_starts_with($path, 'data:')
                    || str_starts_with($path, 'tel:')
                ) {
                    return $m[0];
                }

                // Sibling guide → the served guide URL.
                if (str_ends_with($path, '.md')) {
                    $slug = basename($path, '.md');
                    if (in_array($slug, self::GUIDE_SLUGS, true)) {
                        return 'href="/docs/guide/' . $slug . $anchor . '"';
                    }
                }

                // Any other relative path is a repo file reference. Guides are
                // served from docs/, so resolve the link against that base
                // instead of blindly stripping every ./ + ../ prefix (which
                // dropped the docs/ segment for any link that stayed WITHIN docs/
                // — e.g. ./architecture/state-isolation-reference.md 404'd as
                // master/architecture/… rather than master/docs/architecture/…):
                //   ./architecture/x.md → docs/architecture/x.md
                //   architecture/x.md   → docs/architecture/x.md
                //   ../STANDARDS.md      → STANDARDS.md   (climbs out of docs/)
                //   ../src/App.php       → src/App.php
                $resolved = [];
                foreach (explode('/', 'docs/' . $path) as $seg) {
                    if ($seg === '' || $seg === '.') {
                        continue;
                    }
                    if ($seg === '..') {
                        array_pop($resolved);
                        continue;
                    }
                    $resolved[] = $seg;
                }

                return 'href="' . self::GITHUB_BLOB . implode('/', $resolved) . $anchor . '"';
            },
            $html
        );
    }
}
