<?php

declare(strict_types=1);

namespace ZealPHP\Learn;

use ZealPHP\App;
use ZealPHP\Store;

/**
 * Tic-tac-toe multiplayer helpers (Build-the-App capstone).
 *
 * Moved verbatim out of route/learn.php so the route file stays function-free
 * and hot-reloadable. Behaviour is unchanged — the same room sanitiser, winner
 * detector, and state-broadcast helpers the /ws/tictactoe handler calls.
 */
class TicTacToe
{
    public static function ttt_sanitize_room(string $room): string
    {
        $room = strtolower($room);
        $room = preg_replace('/[^a-z0-9-]/', '', $room) ?? '';
        return substr($room, 0, 32);
    }

    /**
     * @return array{0: ?string, 1: ?array<int, int>}
     */
    public static function ttt_detect_winner(string $board): array
    {
        $lines = [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
        foreach ($lines as $line) {
            [$a, $b, $c] = $line;
            $s = $board[$a];
            if ($s !== '_' && $s === $board[$b] && $s === $board[$c]) {
                return [$s, $line];
            }
        }
        return [null, null];
    }

    public static function ttt_broadcast_state(string $room): void
    {
        self::broadcast($room, []);
    }

    /**
     * @param array<string, mixed> $extras
     */
    public static function ttt_broadcast_state_with(string $room, array $extras): void
    {
        self::broadcast($room, $extras);
    }

    /**
     * Shared fan-out core for ttt_broadcast_state / _with — builds the state
     * payload (optionally merged with $extras) and pushes it to every fd in
     * the room. Single implementation so the two public entry points can't
     * drift in payload shape.
     *
     * @param array<string, mixed> $extras
     */
    private static function broadcast(string $room, array $extras): void
    {
        $server = App::getServer();
        if (!$server) return;
        $row = Store::get('ws_tictactoe_rooms', $room);
        if (!is_array($row)) return;

        // Count viewers in the room (everyone with symbol 'S')
        $viewers = 0;
        $clients = Store::table('ws_tictactoe_clients');
        if ($clients !== null) {
            foreach ($clients as $_ => $c) {
                if (!is_array($c)) continue;
                if (($c['room'] ?? '') === $room && ($c['symbol'] ?? '') === 'S') $viewers++;
            }
        }

        $payload = (string) json_encode(array_merge([
            'type'    => 'state',
            'board'   => $row['board'],
            'turn'    => $row['turn'],
            'winner'  => $row['winner'],
            'rounds'  => self::asInt($row['rounds'] ?? 0),
            'players' => [
                'X' => ['name' => $row['px_name'], 'connected' => self::asInt($row['px_fd'] ?? 0) > 0],
                'O' => ['name' => $row['po_name'], 'connected' => self::asInt($row['po_fd'] ?? 0) > 0],
            ],
            'score'   => [
                'X'    => self::asInt($row['x_wins'] ?? 0),
                'O'    => self::asInt($row['o_wins'] ?? 0),
                'draw' => self::asInt($row['draws']  ?? 0),
            ],
            'viewers' => $viewers,
        ], $extras));

        if ($clients === null) return;
        foreach ($clients as $fd => $c) {
            if (!is_array($c) || ($c['room'] ?? '') !== $room) continue;
            $fd = (int) $fd;
            // @phpstan-ignore-next-line method.notFound — WebSocket\Server::push at runtime
            if ($server->isEstablished($fd)) $server->push($fd, $payload);
        }
    }

    private static function asInt(mixed $v): int
    {
        if (is_int($v))                      { return $v; }
        if (is_numeric($v))                  { return (int) $v; }
        return 0;
    }
}
