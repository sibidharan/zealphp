<?php

declare(strict_types=1);

namespace ZealPHP\Docs;

/**
 * Builds a curated landing for /docs/api/ from the phpDocumentor output.
 *
 * phpDocumentor's own index is a flat "Table of Contents" dump (Packages
 * → Namespaces → a long list of loose functions) that buries the classes
 * a reader actually wants. This class scans the generated
 * `public/docs/api/classes/*.html` files and groups them by namespace
 * (Core, HTTP, Middleware, Session, …) so the landing surfaces every
 * class directly. It's regenerated from the files on each request, so it
 * never drifts as classes are added or removed.
 *
 * Also provides the breadcrumb trail shown atop each wrapped API page —
 * the phpDocumentor header (which carried its own breadcrumb) is stripped
 * during wrapping, so we synthesise one from the file path.
 */
final class ApiIndex
{
    /**
     * Preferred group order + display titles. Groups not listed here
     * (future namespaces) are appended alphabetically after these.
     *
     * @var array<string, string>
     */
    private const GROUP_TITLES = [
        'Core'        => 'Core',
        'HTTP'        => 'HTTP',
        'Middleware'  => 'Middleware',
        'Session'     => 'Session',
        'Legacy'      => 'Legacy / CGI',
        'Cache'       => 'Cache',
        'Log'         => 'Logging',
        'Input'       => 'Input',
        'Diagnostics' => 'Diagnostics',
        'Docs'        => 'Docs',
        'Learn'       => 'Learn (demo app)',
    ];

    /**
     * Group the generated class pages by namespace.
     *
     * @return array<string, list<array{label: string, href: string}>>
     *         Display-title → list of {short class label, page href},
     *         in the preferred group order.
     */
    public static function groups(string $classesDir): array
    {
        $files = glob(rtrim($classesDir, '/') . '/*.html') ?: [];
        sort($files);

        /** @var array<string, list<array{label: string, href: string}>> $byGroup */
        $byGroup = [];
        foreach ($files as $file) {
            $base  = basename($file, '.html');         // ZealPHP-Middleware-CorsMiddleware
            $parts = explode('-', $base);
            if ($parts[0] !== 'ZealPHP' || count($parts) < 2) {
                continue;
            }
            $rest = array_slice($parts, 1);            // [Middleware, CorsMiddleware]
            if (count($rest) === 1) {
                $group = 'Core';                        // top-level: App, Store, ZealAPI…
                $label = $rest[0];
            } else {
                $group = $rest[0];                      // Middleware / HTTP / Session…
                $label = implode('\\', array_slice($rest, 1)); // CorsMiddleware, Factory\RequestFactory
            }
            $byGroup[$group][] = ['label' => $label, 'href' => '/docs/api/classes/' . $base . '.html'];
        }

        // Emit in preferred order, then any leftover groups alphabetically.
        $ordered = [];
        foreach (self::GROUP_TITLES as $key => $title) {
            if (isset($byGroup[$key])) {
                $ordered[$title] = $byGroup[$key];
                unset($byGroup[$key]);
            }
        }
        ksort($byGroup);
        foreach ($byGroup as $key => $items) {
            $ordered[$key] = $items;
        }

        return $ordered;
    }

    /**
     * Breadcrumb trail for an API page, derived from its path relative to
     * /docs/api (e.g. "/classes/ZealPHP-Middleware-CorsMiddleware.html").
     * The last segment has a null href (current page).
     *
     * @return list<array{label: string, href: ?string}>
     */
    public static function breadcrumb(string $rel): array
    {
        $trail = [
            ['label' => 'Docs', 'href' => '/docs/'],
            ['label' => 'API Reference', 'href' => '/docs/api/'],
        ];

        // A class page: surface namespace segments + the class short name.
        if (preg_match('#^/classes/(ZealPHP-[^/]+)\.html$#', $rel, $m) === 1) {
            $rest = array_slice(explode('-', $m[1]), 1); // drop "ZealPHP"
            $class = array_pop($rest);
            foreach ($rest as $ns) {                      // namespace segments (no link)
                $trail[] = ['label' => $ns, 'href' => null];
            }
            $trail[] = ['label' => (string) $class, 'href' => null];
            return $trail;
        }

        // Other page types (namespaces/packages/reports/indices/functions).
        if (preg_match('#^/([a-z-]+)/#', $rel, $m) === 1) {
            $trail[] = ['label' => ucfirst(str_replace('-', ' ', $m[1])), 'href' => null];
        }

        return $trail;
    }
}
