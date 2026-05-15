<?php
namespace ZealPHP\Learn;

use ZealPHP\App;
use ZealPHP\Store;

class WS
{
    public static function broadcast(int $userId, array $payload): void
    {
        $server = App::getServer();
        if (!$server) return;
        $json = json_encode($payload);
        foreach (Store::table('learn_ws_clients') as $fd => $row) {
            if ((int) ($row['user_id'] ?? 0) === $userId) {
                try { @$server->push((int) $fd, $json); } catch (\Throwable $e) {}
            }
        }
    }
}
