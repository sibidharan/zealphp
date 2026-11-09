<?php

declare(strict_types=1);

namespace ZealPHP\Site;

use ZealPHP\App;
use ZealPHP\SafeHtml;

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
     * big top-nav or footer. The shell + breadcrumb lives in
     * template/components/_demo_shell.php; $body is the page's view from
     * template/demos/ (description + demo sections).
     */
    public static function demo_render(string $title, SafeHtml $body, string $back_slug, string $back_label): string
    {
        return (string) App::component('demo_shell', [
            'title'      => $title,
            'slot'       => $body,
            'back_slug'  => $back_slug,
            'back_label' => $back_label,
        ]);
    }

    /**
     * The payload a "Response" section shows: JSON pretty-printed when the
     * content type is JSON (and $pretty), anything else unchanged.
     * Rendered by template/demos/components/response.php.
     */
    public static function demo_payload(string $contentType, string $payload, bool $pretty = true): string
    {
        if ($pretty && stripos($contentType, 'json') !== false) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($encoded !== false) {
                    return $encoded;
                }
            }
        }
        return $payload;
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
