<?php

declare(strict_types=1);

namespace ZealPHP;

/**
 * GitHub stargazer-count cache.
 *
 * Renders directly into HTML (no client-side fetch, no empty span / number flicker).
 * Shared across workers via a 1-row OpenSwoole `Store` table; refreshed asynchronously
 * via a background coroutine when stale, so requests are never blocked on the
 * remote GitHub call.
 *
 * Usage:
 *
 * In `app.php` boot, before `$app->run()`:
 *
 * ```php
 * \ZealPHP\GithubStars::register('sibidharan/zealphp');
 * ```
 *
 * In templates / route handlers:
 *
 * ```php
 * <span>★ <?= htmlspecialchars(\ZealPHP\GithubStars::format()) ?></span>
 * ```
 */
final class GithubStars
{
    /** Cache TTL — refresh the value at most once per N seconds. */
    private const TTL_SECONDS = 900; // 15 minutes

    /** GitHub API timeout — bounded so a slow upstream can't stall a worker. */
    private const FETCH_TIMEOUT = 2.0;

    private const TABLE = 'github_stars';

    /** "owner/repo" — set by register(), read by the refresher. */
    private static ?string $repo = null;

    /** True when a refresh coroutine is currently in flight (per worker). */
    private static bool $refreshInFlight = false;

    /**
     * Create the shared `Store` table. Call ONCE before `$app->run()`.
     * The repo argument selects which `github.com/owner/repo` to track.
     */
    public static function register(string $repo): void
    {
        self::$repo = $repo;
        Store::make(self::TABLE, 1, [
            'count'      => [\OpenSwoole\Table::TYPE_INT, 4],
            'fetched_at' => [\OpenSwoole\Table::TYPE_INT, 4],
            // Formatted text (e.g. "1.2k") so the template doesn't have to know
            // the formatting rule. Capped at 16 bytes — plenty for "9999.9k".
            'formatted'  => [\OpenSwoole\Table::TYPE_STRING, 16],
        ]);
    }

    /**
     * Render-time accessor. Returns the cached formatted count immediately,
     * and triggers a background refresh if the cache is stale or empty.
     * Returns an empty string before the first successful fetch (template
     * should handle that gracefully — e.g. hide the span when empty).
     */
    public static function format(): string
    {
        $row = Store::get(self::TABLE, 'count');
        $now = time();
        $fetchedAt = (is_array($row) && isset($row['fetched_at']) && is_scalar($row['fetched_at']))
            ? (int) $row['fetched_at']
            : 0;
        $stale = ($now - $fetchedAt) >= self::TTL_SECONDS;

        if ($stale) {
            self::refreshAsync();
        }

        if (is_array($row) && isset($row['formatted']) && is_scalar($row['formatted']) && $row['formatted'] !== '') {
            return (string) $row['formatted'];
        }
        return '';
    }

    /**
     * Spawn a coroutine to refresh the cache. Idempotent — at most one
     * in-flight refresh per worker at a time (the per-worker flag guards
     * against thundering-herd on cold starts).
     */
    private static function refreshAsync(): void
    {
        if (self::$refreshInFlight || self::$repo === null) {
            return;
        }
        $repo = self::$repo;
        self::$refreshInFlight = true;
        try {
            \OpenSwoole\Coroutine::create(static function () use ($repo) {
                try {
                    self::fetchAndStore($repo);
                } catch (\Throwable $e) {
                    // Silent — stale cache is better than a fatal in the
                    // hot path. Log to debug for ops visibility.
                    elog('GithubStars refresh failed: ' . $e->getMessage());
                } finally {
                    self::$refreshInFlight = false;
                }
            });
        } catch (\Throwable $e) {
            self::$refreshInFlight = false;
            elog('GithubStars: failed to schedule refresh: ' . $e->getMessage());
        }
    }

    private static function fetchAndStore(string $repo): void
    {
        $client = new \OpenSwoole\Coroutine\Http\Client('api.github.com', 443, true);
        $client->setHeaders([
            'Host'       => 'api.github.com',
            'User-Agent' => 'ZealPHP',
            'Accept'     => 'application/vnd.github+json',
        ]);
        $client->set(['timeout' => self::FETCH_TIMEOUT]);
        $client->get('/repos/' . $repo);

        $status = is_int($client->statusCode) ? $client->statusCode : 0;
        $body   = is_string($client->body) ? $client->body : '';
        $client->close();

        if ($status !== 200) {
            elog('GithubStars: HTTP ' . $status . ' for /repos/' . $repo);
            return;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['stargazers_count']) || !is_int($data['stargazers_count'])) {
            elog('GithubStars: malformed payload');
            return;
        }
        $count     = $data['stargazers_count'];
        $formatted = self::formatCount($count);
        Store::set(self::TABLE, 'count', [
            'count'      => $count,
            'fetched_at' => time(),
            'formatted'  => $formatted,
        ]);
    }

    /**
     * `"k"`-suffixed formatting that matches the previous client-side script.
     * `999` → `"999"`; `1234` → `"1.2k"`; `12345` → `"12k"`.
     */
    private static function formatCount(int $n): string
    {
        if ($n < 1000) {
            return (string) $n;
        }
        $decimals = $n >= 10000 ? 0 : 1;
        return number_format($n / 1000, $decimals) . 'k';
    }
}
