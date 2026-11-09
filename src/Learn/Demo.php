<?php

declare(strict_types=1);

namespace ZealPHP\Learn;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Store;

/**
 * Helpers for the /learn demo endpoints — WebSocket fan-out broadcasters,
 * the public-demo rate-limit guard, and the standalone demo-page shell.
 *
 * Moved verbatim out of route/learn.php so the route file stays function-free
 * and hot-reloadable (a top-level function in a route file fatals
 * "Cannot redeclare" when App::reloadRoutes() re-includes it). Behaviour is
 * unchanged — pure move + rename.
 */
class Demo
{
    /** @param array<string, mixed> $payload */
    public static function learn_ws_broadcast(int $userId, array $payload): void
    {
        WS::broadcast($userId, $payload);
    }

    public static function ws_counter_demo_broadcast(int $value): void
    {
        $server = App::getServer();
        if (!$server) return;
        $payload = (string) json_encode(['value' => $value]);
        $table = Store::table('ws_counter_demo_clients');
        if ($table === null) return;
        foreach ($table as $fd => $_) {
            $fd = (int) $fd;
            // @phpstan-ignore-next-line method.notFound — WebSocket\Server::push at runtime
            if ($server->isEstablished($fd)) $server->push($fd, $payload);
        }
    }

    /**
     * Returns null if the caller is under the limit (proceed). Returns an
     * error payload + sets 429 + Retry-After if rate-limited — caller should
     * `return` this directly from its route handler.
     *
     * @return null|array{error: string, limit: int, window: int}
     */
    public static function demo_rate_check(): ?array
    {
        $g  = RequestContext::instance();
        $ip = (string) ($g->server['REMOTE_ADDR'] ?? 'unknown');
        if (Auth::rateLimit('demo_rate_limits', $ip, 30, 60)) return null;
        http_response_code(429);
        header('Retry-After: 60');
        header('Content-Type: application/json; charset=utf-8');
        return ['error' => 'rate_limit', 'limit' => 30, 'window' => 60];
    }

    public static function ws_session_counter_broadcast(string $sessionId, string $html): void
    {
        $server = App::getServer();
        if (!$server) return;
        $table = Store::table('ws_session_counter_clients');
        if ($table === null) return;
        foreach ($table as $fd => $row) {
            if (!is_array($row) || ($row['session_id'] ?? '') !== $sessionId) continue;
            $fd = (int) $fd;
            // @phpstan-ignore-next-line method.notFound — WebSocket\Server::push at runtime
            if ($server->isEstablished($fd)) $server->push($fd, $html);
        }
    }

    public static function ws_store_demo_broadcast(): void
    {
        $server = App::getServer();
        if (!$server) return;
        $row = Store::get('ws_store_demo_data', 'shared_row');
        if (!is_array($row)) $row = ['n' => 0, 'name' => '', 'who' => '', 'ts' => 0];
        $payload = (string) json_encode($row);
        $table = Store::table('ws_store_demo_clients');
        if ($table === null) return;
        foreach ($table as $fd => $_) {
            $fd = (int) $fd;
            // @phpstan-ignore-next-line method.notFound — WebSocket\Server::push at runtime
            if ($server->isEstablished($fd)) $server->push($fd, $payload);
        }
    }

    /**
     * A concept check's explanation, reduced to inert tags. The value
     * round-trips through a form hidden input (browser decodes once before
     * submit), so the raw payload is what the lesson author wrote — OR what
     * a malicious client crafted in a hand-rolled POST. strip_tags filters
     * tags but does NOT filter attributes, so <a> (would allow
     * href="javascript:…") and any tag with an event-handler attribute
     * surface are excluded. <code>/<em>/<strong>/<br> are inert — no
     * attributes worth abusing — so they're safe to allow.
     */
    public static function concept_check_explanation(string $explain): string
    {
        return strip_tags($explain, '<code><em><strong><br>');
    }
}
