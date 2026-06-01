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

    public static function learn_demo_shell(string $title, string $body): string
    {
        $titleHtml = htmlspecialchars($title);
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$titleHtml} · ZealPHP Learn</title>
  <link rel="stylesheet" href="/css/learn.css">
  <style>body { font-family: ui-sans-serif, system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #1c1917; } nav { margin-bottom: 1rem; font-size: .85rem; } nav a { color: #f59e0b; text-decoration: none; margin-right: 1rem; }</style>
</head>
<body>
  <nav><a href="/learn/components">← Back to Lesson 4</a> · <strong>{$titleHtml}</strong></nav>
  {$body}
</body>
</html>
HTML;
    }
}
