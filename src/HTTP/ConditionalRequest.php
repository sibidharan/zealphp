<?php
declare(strict_types=1);

namespace ZealPHP\HTTP;

/**
 * RFC 9110 conditional-request evaluator — a pure, server-free port of
 * Apache httpd's `ap_meets_conditions()` (modules/http/http_protocol.c).
 *
 * The class is intentionally a bag of static, side-effect-free functions: it
 * reads only the request method, the request headers (`If-Match`,
 * `If-Unmodified-Since`, `If-None-Match`, `If-Modified-Since`), and the
 * representation metadata that a server already computed (`ETag`,
 * `Last-Modified`, plus the current request time). It returns the HTTP status
 * the caller should emit — nothing more. This keeps it exhaustively unit
 * testable without an OpenSwoole server.
 *
 * Apache's precedence is mirrored EXACTLY:
 *
 *   1. If-Match            NOMATCH -> 412
 *   2. If-Unmodified-Since modified -> 412 (NOMATCH just blocks a later 304)
 *   3. If-None-Match       match -> 304 (GET/HEAD) or 412 (other methods)
 *   4. If-Modified-Since   not-modified -> 304 (GET/HEAD only)
 *
 * (Apache's fifth step, If-Range, lives in the Range layer and is not part of
 * this evaluator; the Range middleware owns that comparison.)
 *
 * ETag comparison follows Apache's `ap_find_etag_weak` / `ap_find_etag_strong`
 * (server/util.c): If-None-Match on GET/HEAD without a Range header uses WEAK
 * comparison (`W/"x"` matches `"x"`); If-Match, and If-None-Match on any other
 * method or when a Range header is present, use STRONG comparison (a weak tag
 * on either side never matches).
 */
final class ConditionalRequest
{
    /** No conditional header of this kind was present. */
    public const COND_NONE = 0;

    /** The condition was present and did not match. */
    public const COND_NOMATCH = 1;

    /** The condition matched using weak comparison semantics. */
    public const COND_WEAK = 2;

    /** The condition matched using strong comparison semantics. */
    public const COND_STRONG = 3;

    /**
     * Evaluate the conditional-request preconditions for a representation.
     *
     * Mirrors `ap_meets_conditions()`. Only invoked for otherwise-successful
     * (2xx) responses — callers must skip error responses, exactly as Apache
     * guards on `ap_is_HTTP_SUCCESS(r->status)`.
     *
     * @param string $method     The request method (e.g. "GET", "HEAD", "PUT").
     * @param array<string,string> $reqHeaders Lowercased-or-mixed request header
     *        map; only the four conditional headers and "range" are consulted.
     *        Lookups are case-insensitive.
     * @param string $etag         The representation's current ETag (may be
     *        weak `W/"..."` or strong `"..."`), or '' when none is known.
     * @param int|null $lastModified Representation mtime as a Unix timestamp,
     *        or null when unknown.
     * @param int|null $requestTime The server's request-receipt time as a Unix
     *        timestamp; defaults to `time()`. Used as the upper bound that makes
     *        future-dated If-Modified-Since / If-Unmodified-Since values invalid.
     *
     * @return int The HTTP status the caller should emit: 200 (proceed),
     *         304 (Not Modified), or 412 (Precondition Failed).
     */
    public static function evaluate(
        string $method,
        array $reqHeaders,
        string $etag,
        ?int $lastModified = null,
        ?int $requestTime = null
    ): int {
        $requestTime ??= \time();
        $isGetOrHead = self::isGetOrHead($method);
        $hasRange = self::header($reqHeaders, 'range') !== null;

        // not_modified is a tri-state: -1 unset, 0 forced-200, 1 eligible-304.
        $notModified = -1;

        // Step 1 — If-Match (strong comparison; '*' matches any representation).
        $cond = self::ifMatch($reqHeaders, $etag);
        if ($cond === self::COND_NOMATCH) {
            return 412;
        }

        // Step 2 — If-Unmodified-Since (412 when the resource WAS modified).
        $cond = self::ifUnmodifiedSince($reqHeaders, $lastModified, $requestTime, $hasRange);
        if ($cond === self::COND_NOMATCH) {
            $notModified = 0;
        } elseif ($cond >= self::COND_WEAK) {
            return 412;
        }

        // Step 3 — If-None-Match (304 for GET/HEAD, 412 for other methods).
        $cond = self::ifNoneMatch($reqHeaders, $etag, $isGetOrHead, $hasRange);
        if ($cond === self::COND_NOMATCH) {
            $notModified = 0;
        } elseif ($cond >= self::COND_WEAK) {
            if ($isGetOrHead) {
                if ($notModified !== 0) {
                    $notModified = 1;
                }
            } else {
                return 412;
            }
        }

        // Step 4 — If-Modified-Since (304 for GET/HEAD only; future dates invalid).
        $cond = self::ifModifiedSince($reqHeaders, $lastModified, $requestTime, $hasRange);
        if ($cond === self::COND_NOMATCH) {
            $notModified = 0;
        } elseif ($cond >= self::COND_WEAK) {
            if ($isGetOrHead) {
                if ($notModified !== 0) {
                    $notModified = 1;
                }
            }
        }

        if ($notModified === 1) {
            return 304;
        }

        return 200;
    }

    /**
     * `ap_condition_if_match` — strong comparison only; '*' matches any tag.
     *
     * @param array<string,string> $reqHeaders
     */
    public static function ifMatch(array $reqHeaders, string $etag): int
    {
        $value = self::header($reqHeaders, 'if-match');
        if ($value === null) {
            return self::COND_NONE;
        }

        if (self::isWildcard($value)) {
            return self::COND_STRONG;
        }
        if ($etag !== '' && self::findEtagStrong($value, $etag)) {
            return self::COND_STRONG;
        }

        return self::COND_NOMATCH;
    }

    /**
     * `ap_condition_if_unmodified_since`.
     *
     * NOMATCH means the resource was NOT modified (precondition met). WEAK or
     * STRONG means it WAS modified after the supplied date (precondition fails
     * -> 412). A Range header forbids the weak (within-60s) outcome.
     *
     * @param array<string,string> $reqHeaders
     */
    public static function ifUnmodifiedSince(
        array $reqHeaders,
        ?int $lastModified,
        int $requestTime,
        bool $hasRange
    ): int {
        $value = self::header($reqHeaders, 'if-unmodified-since');
        if ($value === null) {
            return self::COND_NONE;
        }

        $ius = self::parseHttpDate($value);
        if ($ius === null) {
            return self::COND_NOMATCH;
        }

        $mtime = $lastModified ?? $requestTime;
        if ($mtime > $ius) {
            if ($requestTime < $mtime + 60) {
                return $hasRange ? self::COND_NOMATCH : self::COND_WEAK;
            }
            return self::COND_STRONG;
        }

        return self::COND_NOMATCH;
    }

    /**
     * `ap_condition_if_none_match`.
     *
     * '*' matches unconditionally (STRONG). GET/HEAD without a Range header use
     * weak comparison; any other method, or a Range request, requires strong
     * comparison.
     *
     * @param array<string,string> $reqHeaders
     */
    public static function ifNoneMatch(
        array $reqHeaders,
        string $etag,
        bool $isGetOrHead,
        bool $hasRange
    ): int {
        $value = self::header($reqHeaders, 'if-none-match');
        if ($value === null) {
            return self::COND_NONE;
        }

        if (self::isWildcard($value)) {
            return self::COND_STRONG;
        }

        if ($etag !== '') {
            if ($isGetOrHead && !$hasRange) {
                if (self::findEtagWeak($value, $etag)) {
                    return self::COND_WEAK;
                }
            } else {
                if (self::findEtagStrong($value, $etag)) {
                    return self::COND_STRONG;
                }
            }
        }

        return self::COND_NOMATCH;
    }

    /**
     * `ap_condition_if_modified_since`.
     *
     * Returns WEAK/STRONG (not-modified -> candidate 304) only when the IMS date
     * is within `[mtime, requestTime]`; a future-dated IMS is invalid (NOMATCH).
     * A Range header forbids the weak (within-60s) outcome.
     *
     * @param array<string,string> $reqHeaders
     */
    public static function ifModifiedSince(
        array $reqHeaders,
        ?int $lastModified,
        int $requestTime,
        bool $hasRange
    ): int {
        $value = self::header($reqHeaders, 'if-modified-since');
        if ($value === null) {
            return self::COND_NONE;
        }

        $ims = self::parseHttpDate($value);
        if ($ims === null) {
            return self::COND_NOMATCH;
        }

        $mtime = $lastModified ?? $requestTime;
        if ($ims >= $mtime && $ims <= $requestTime) {
            if ($requestTime < $mtime + 60) {
                return $hasRange ? self::COND_NOMATCH : self::COND_WEAK;
            }
            return self::COND_STRONG;
        }

        return self::COND_NOMATCH;
    }

    /**
     * Strong ETag comparison — port of `ap_find_etag_strong` / `find_list_item`
     * with `AP_ETAG_STRONG`. The stored ETag must be strong (start with `"`),
     * and any weak entries in the request list are skipped. Returns true when
     * the strong stored tag appears in the comma-separated request list.
     */
    public static function findEtagStrong(string $list, string $etag): bool
    {
        if ($etag === '' || $etag[0] !== '"') {
            // A weak stored ETag can never satisfy a strong comparison.
            return false;
        }
        foreach (self::splitList($list) as $candidate) {
            if ($candidate === '' || $candidate[0] !== '"') {
                // Weak request entries are not eligible for strong comparison.
                continue;
            }
            if ($candidate === $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * Weak ETag comparison — port of `ap_find_etag_weak` / `find_list_item`
     * with `AP_ETAG_WEAK`. Both the stored tag and each request-list entry have
     * any `W/` prefix stripped before the opaque quoted-strings are compared, so
     * `W/"x"` matches `"x"` and vice versa.
     */
    public static function findEtagWeak(string $list, string $etag): bool
    {
        $target = self::stripWeak($etag);
        if ($target === null) {
            return false;
        }
        foreach (self::splitList($list) as $candidate) {
            $stripped = self::stripWeak($candidate);
            if ($stripped !== null && $stripped === $target) {
                return true;
            }
        }
        return false;
    }

    /**
     * Strip a leading `W/` weak marker from an ETag token. Returns the bare
     * quoted-string, or null when the token is not a valid (quoted) ETag.
     */
    private static function stripWeak(string $tag): ?string
    {
        if (\str_starts_with($tag, 'W/"')) {
            $tag = \substr($tag, 2);
        }
        if ($tag === '' || $tag[0] !== '"') {
            return null;
        }
        return $tag;
    }

    /**
     * Split a comma-separated ETag list header into trimmed, non-empty tokens.
     * Commas only ever separate ETags here — they cannot appear unescaped inside
     * the opaque quoted-string of an entity-tag (RFC 9110 §8.8.3).
     *
     * @return list<string>
     */
    private static function splitList(string $list): array
    {
        $out = [];
        foreach (\explode(',', $list) as $part) {
            $part = \trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * True when a conditional header value is the `*` wildcard (allowing only
     * surrounding whitespace), matching Apache's `value[0] == '*'` test against
     * an already-trimmed header.
     */
    private static function isWildcard(string $value): bool
    {
        return \trim($value) === '*';
    }

    /** Whether the method takes the GET/HEAD conditional path (304-eligible). */
    private static function isGetOrHead(string $method): bool
    {
        $method = \strtoupper($method);
        return $method === 'GET' || $method === 'HEAD';
    }

    /**
     * Case-insensitive request-header lookup. Returns the raw value, or null
     * when the header is absent (an empty-string value is treated as present).
     *
     * @param array<string,string> $reqHeaders
     */
    private static function header(array $reqHeaders, string $name): ?string
    {
        if (isset($reqHeaders[$name])) {
            return $reqHeaders[$name];
        }
        foreach ($reqHeaders as $key => $value) {
            if (\strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Parse an HTTP-date into a Unix timestamp, or null when unparseable.
     *
     * Tolerant like Apache's `apr_date_parse_http` (the reference this class
     * ports), not as strict as a bare `strtotime`. `strtotime` already covers
     * the three RFC 9110 §5.6.7 formats — IMF-fixdate (RFC 1123), RFC 850, and
     * asctime — but it REJECTS the historical trailing `"; length=NNN"`
     * parameter some caches still append to `If-Modified-Since`/`If-Unmodified-Since`
     * (Netscape-era). `strtotime("…GMT; length=62")` returns false, which
     * downstream becomes `COND_NOMATCH` → a 304→200 cache miss, or on an unsafe
     * method a 412→bypass (#363). So strip any trailing parameter list (the
     * first `;` and everything after it) before parsing, then defer to
     * `strtotime`. Only a date with no recognisable timestamp yields null,
     * mirroring Apache's `APR_DATE_BAD`.
     */
    private static function parseHttpDate(string $value): ?int
    {
        $value = \trim($value);
        if ($value === '') {
            return null;
        }
        // Drop a legacy trailing parameter (e.g. "; length=62") -- apr_date_parse_http
        // tolerates it; strtotime does not. No rtrim is needed on the kept head:
        // strtotime() already ignores surrounding whitespace, and the only way the
        // head can be all-whitespace is a leading ';' (already removed by trim()),
        // which substr() turns into '' -- caught by the guard below.
        $semi = \strpos($value, ';');
        if ($semi !== false) {
            $value = \substr($value, 0, $semi);
            if (\trim($value) === '') {
                return null;
            }
        }
        $ts = \strtotime($value);
        return $ts === false ? null : $ts;
    }
}
