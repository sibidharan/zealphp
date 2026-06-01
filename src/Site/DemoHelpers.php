<?php

declare(strict_types=1);

namespace ZealPHP\Site;

use ZealPHP\App;

/**
 * Shared helpers for the ZealPHP OSS website's live-demo routes.
 *
 * Extracted verbatim from route/demo.php so the route file stays function-free
 * (top-level functions in a route file fatal "Cannot redeclare" when
 * App::reloadRoutes() re-includes it). These are pure presentation helpers:
 * timing utilities and the demo-viewer shell renderer.
 */
class DemoHelpers
{
    public static function demo_t(): float
    {
        return microtime(true);
    }

    public static function demo_ms(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * Render a demo viewer page through a clean standalone shell —
     * site CSS (zealphp.css + learn.css) for typography and colors, but no
     * big top-nav or footer. The whole shell + breadcrumb + body lives in
     * template/components/_demo_shell.php.
     *
     * @param array<int, array{heading: string, body: string}> $sections
     */
    public static function demo_render(string $title, string $description, array $sections, string $back_slug, string $back_label): string
    {
        return App::renderToString('/components/_demo_shell', [
            'title'       => $title,
            'description' => $description,
            'sections'    => $sections,
            'back_slug'   => $back_slug,
            'back_label'  => $back_label,
        ]);
    }

    /**
     * Renders one "Response" section showing status + content-type + payload.
     *
     * @return array{heading: string, body: string}
     */
    public static function demo_section_response(int $status, string $contentType, string $payload, bool $pretty = true): array
    {
        if ($pretty && stripos($contentType, 'json') !== false) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($encoded !== false) {
                    $payload = $encoded;
                }
            }
        }
        $cls = 's' . substr((string)$status, 0, 1) . 'xx';
        $body = '<dl class="demo-kv">'
              . '<dt>Status</dt><dd><span class="demo-status ' . $cls . '">' . $status . ' ' . htmlspecialchars(self::_demo_phrase($status)) . '</span></dd>'
              . '<dt>Content-Type</dt><dd>' . htmlspecialchars($contentType) . '</dd>'
              . '</dl>'
              . '<pre class="demo-payload" style="margin-top:.6rem">' . htmlspecialchars($payload) . '</pre>';
        return ['heading' => 'Response', 'body' => $body];
    }

    public static function _demo_phrase(int $s): string
    {
        return match($s) {
            200 => 'OK', 204 => 'No Content', 301 => 'Moved Permanently', 302 => 'Found',
            304 => 'Not Modified', 400 => 'Bad Request', 401 => 'Unauthorized',
            403 => 'Forbidden', 404 => 'Not Found', 500 => 'Internal Server Error',
            default => '',
        };
    }
}
