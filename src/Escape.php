<?php

declare(strict_types=1);

namespace ZealPHP;

/**
 * Context-specific output escaping for plain PHP templates (#509).
 *
 * Pick the method for the context the value lands in:
 *
 * | Context                                   | Method                  |
 * |-------------------------------------------|-------------------------|
 * | HTML text and quoted attribute values     | `Escape::html()` / `e()`|
 * | One URL component (path segment, query)   | `Escape::url()`         |
 * | A whole URL in `href` / `src`             | `Escape::safeUrl()`     |
 * | A value inside `<script>` or `data-*` JSON | `Escape::js()`         |
 * | Trusted markup you built yourself         | `Escape::raw()`         |
 *
 * `html()` encodes `& < > " '`, so the same call is safe for text and for
 * attribute values in single or double quotes. It is NOT safe for unquoted
 * attributes, inside `<script>`/`<style>`, or for event-handler attributes.
 *
 * Invalid UTF-8 is replaced with U+FFFD rather than returning an empty string.
 */
final class Escape
{
    private const HTML_FLAGS = ENT_QUOTES | ENT_SUBSTITUTE;

    private const JS_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /** URL schemes `safeUrl()` lets through; anything else becomes `#`. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Escape for HTML text or a quoted attribute value.
     *
     * `null` becomes an empty string. A {@see SafeHtml} value is returned
     * unchanged — it is already markup.
     */
    public static function html(mixed $value): string
    {
        if ($value instanceof SafeHtml) {
            return (string) $value;
        }
        return htmlspecialchars(self::toString($value), self::HTML_FLAGS, 'UTF-8');
    }

    /**
     * Percent-encode one URL component (RFC 3986). Use for a path segment or a
     * query value, not for a whole URL. The result is also HTML-safe.
     */
    public static function url(mixed $value): string
    {
        return rawurlencode(self::toString($value));
    }

    /**
     * HTML-escape a whole URL for `href` / `src`, refusing dangerous schemes.
     *
     * Relative URLs (`/path`, `?q=1`, `#frag`, `page.php`) and the schemes
     * http, https, mailto and tel pass through. Anything else — `javascript:`,
     * `data:`, `vbscript:`, including case or whitespace tricks — becomes `#`.
     */
    public static function safeUrl(mixed $value): string
    {
        $url = trim(self::toString($value));
        // Browsers drop ASCII control chars and whitespace inside a scheme
        // ("java\tscript:"), so strip them before reading the scheme.
        $probe = (string) preg_replace('/[\x00-\x20\x7F]+/', '', $url);
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $probe, $m) === 1
            && !in_array(strtolower($m[1]), self::SAFE_SCHEMES, true)) {
            return '#';
        }
        return self::html($url);
    }

    /**
     * Encode a value as a JavaScript literal (JSON) that is safe inside a
     * `<script>` block or an HTML attribute: `< > & ' "` are \u-escaped, so
     * the output can't close the script element or the attribute.
     *
     * ```php
     * <script>const user = <?= Escape::js($user) ?>;</script>
     * <div data-config='<?= Escape::js($config) ?>'></div>
     * ```
     */
    public static function js(mixed $value): string
    {
        if ($value instanceof SafeHtml) {
            $value = (string) $value;
        }
        return json_encode($value, self::JS_FLAGS);
    }

    /**
     * Mark a string as trusted markup so {@see html()} / {@see e()} emit it
     * as-is. Only wrap markup you built from escaped parts or a trusted source.
     */
    public static function raw(string $html): SafeHtml
    {
        return new SafeHtml($html);
    }

    private static function toString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }
        throw new \InvalidArgumentException(
            'Escape: cannot convert ' . get_debug_type($value) . ' to a string'
        );
    }
}
