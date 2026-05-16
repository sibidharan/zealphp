<?php
namespace ZealPHP\Learn;

use ZealPHP\App;
use ZealPHP\Store;

class WS
{
    /** @param array<string, mixed> $payload */
    public static function broadcast(int $userId, array $payload): void
    {
        $server = App::getServer();
        if (!$server) return;
        $table = Store::table('learn_ws_clients');
        if ($table === null) return;
        $json = (string)json_encode($payload);
        foreach ($table as $fd => $row) {
            if (is_array($row) && (int) ($row['user_id'] ?? 0) === $userId) {
                // OpenSwoole\WebSocket\Server::push() — typed as Http\Server by App::getServer()
                // stub, but the real server is a WebSocket\Server (extends Http\Server) when ws()
                // routes are registered. Real push() exists at runtime.
                // @phpstan-ignore-next-line method.notFound
                try { @$server->push((int) $fd, $json); } catch (\Throwable $e) {}
            }
        }
    }
}
