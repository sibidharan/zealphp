<?php

declare(strict_types=1);

namespace ZealPHP\Session\Handler;

use OpenSwoole\Coroutine;
use ZealPHP\Store;

/**
 * Backend-agnostic session handler — rides whichever backend
 * `Store::defaultBackend()` is configured with.
 *
 * - `Store::defaultBackend(Store::BACKEND_TABLE)`  → sessions live in
 *   `OpenSwoole\Table` shared memory. Single-host. Lost on restart.
 * - `Store::defaultBackend(Store::BACKEND_REDIS)`  → sessions live in
 *   Redis/Valkey. CROSS-NODE, persistent with AOF/RDB. The session set
 *   on server A is readable from server B. Ideal for horizontally-
 *   scaled deployments behind a sticky-or-not load balancer.
 * - `Store::defaultBackend('tiered')` (when wired)   → L1 + L2.
 *
 * Storage shape: one row per session id in the `zealphp_sessions`
 * table with `data` (serialised session payload) + `expires_at`
 * columns. TTL respected by Redis natively when the Redis backend's
 * make() declared `mode => 'ttl'`; for Table backend the GC sweep
 * inspects `expires_at` and deletes expired rows.
 *
 * Usage:
 *   StoreSessionHandler::register(1440);       // session_max_lifetime in seconds
 *   session_set_save_handler(StoreSessionHandler::instance(), true);
 */
final class StoreSessionHandler implements \SessionHandlerInterface
{
    private const TABLE = 'zealphp_sessions';
    private static ?self $instance = null;
    private static bool $tableCreated = false;
    private static int $ttl = 1440;

    private function __construct() {}

    /**
     * Idempotent setup. Pass the session TTL in seconds (PHP's
     * `session.gc_maxlifetime` default is 1440). MUST be called BEFORE
     * `$app->run()` so the Store table is created in the master process
     * and inherited on fork (Table backend) or registered with the right
     * schema for Redis (TTL mode + 'expires_at' for the Table fallback).
     */
    public static function register(int $ttl = 1440): self
    {
        self::$ttl = max(1, $ttl);
        self::$instance ??= new self();
        if (!self::$tableCreated) {
            $opts = [];
            // For Redis: use TTL mode so individual session rows EXPIRE
            // server-side without a GC sweep. For Table backend the
            // ttl-mode opt is harmless (ignored) and the GC path below
            // handles expiry.
            if (Store::defaultBackend() instanceof \ZealPHP\Store\RedisBackend) {
                $opts = ['mode' => 'ttl', 'ttl' => self::$ttl];
            }
            Store::make(self::TABLE, 4096, [
                'data'       => [Store::TYPE_STRING, 8192],
                'expires_at' => [Store::TYPE_INT,    8],
            ], $opts);
            self::$tableCreated = true;
        }
        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('StoreSessionHandler::register() must be called first');
        }
        return self::$instance;
    }

    public function open($savePath, $sessionName): bool
    {
        return self::$tableCreated;
    }

    public function close(): bool { return true; }

    public function read($sessionId): string
    {
        $row = Store::get(self::TABLE, $sessionId);
        if (!is_array($row)) { return ''; }
        $expiresAt = is_numeric($row['expires_at'] ?? null) ? (int) $row['expires_at'] : 0;
        if ($expiresAt > 0 && $expiresAt < time()) {
            // Lazy expiry — clean up on read.
            Store::del(self::TABLE, $sessionId);
            return '';
        }
        return is_string($row['data'] ?? null) ? $row['data'] : '';
    }

    public function write($sessionId, $sessionData): bool
    {
        return Store::set(self::TABLE, $sessionId, [
            'data'       => $sessionData,
            'expires_at' => time() + self::$ttl,
        ]);
    }

    public function destroy($sessionId): bool
    {
        return Store::del(self::TABLE, $sessionId);
    }

    /**
     * Periodic sweep — relevant for the Table backend; on Redis the
     * native TTL takes care of expiry without needing this. Returns
     * the number of sessions deleted.
     */
    public function gc($maxlifetime): int|false
    {
        if (!self::$tableCreated) { return false; }   // register() not yet called
        if (Store::defaultBackend() instanceof \ZealPHP\Store\RedisBackend) {
            return 0; // Redis ttl-mode handles it natively
        }
        $now = time();
        $deleted = 0;
        foreach (Store::iterate(self::TABLE) as $key => $row) {
            $exp = is_numeric($row['expires_at'] ?? null) ? (int) $row['expires_at'] : 0;
            if ($exp > 0 && $exp < $now) {
                Store::del(self::TABLE, $key);
                $deleted++;
            }
        }
        return $deleted;
    }

    /** @internal — test helper to reset module state. */
    public static function reset(): void
    {
        self::$instance     = null;
        self::$tableCreated = false;
        self::$ttl          = 1440;
    }
}
