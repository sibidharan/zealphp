<?php

declare(strict_types=1);

namespace ZealPHP\Learn;

use ZealPHP\App;
use ZealPHP\Store;

/**
 * Tic-tac-toe multiplayer helpers (Build-the-App capstone).
 *
 * Moved verbatim out of `route/learn.php` so the route file stays function-free
 * and hot-reloadable. Behaviour is unchanged — the same room sanitiser, winner
 * detector, and state-broadcast helpers the `/ws/tictactoe` handler calls.
 *
 * Game state is stored in the `ws_tictactoe_rooms` and `ws_tictactoe_clients`
 * `Store` tables, which must be created before `App::run()`.
 */
class TicTacToe
{
    /**
     * Sanitise a room name to a safe, lowercase slug.
     *
     * Lowercases, strips anything that is not `[a-z0-9-]`, and truncates to
     * 32 characters so the result is safe as a `Store` key.
     */
    public static function ttt_sanitize_room(string $room): string
    {
        $room = strtolower($room);
        $room = preg_replace('/[^a-z0-9-]/', '', $room) ?? '';
        return substr($room, 0, 32);
    }

    /**
     * Detect a winner on the given 9-character board string.
     *
     * The board is indexed `0`–`8` left-to-right, top-to-bottom; each cell holds
     * `'X'`, `'O'`, or `'_'` (empty). Returns a two-element tuple:
     * - `[winner_symbol, winning_indices]` when a winning line is found
     *   (e.g. `['X', [0, 1, 2]]`).
     * - `[null, null]` when there is no winner yet.
     *
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

    /**
     * Broadcast the current room state to all connected clients in `$room`.
     *
     * Reads the room row from the `ws_tictactoe_rooms` `Store` table and pushes
     * a `"state"` JSON message to every `fd` found in `ws_tictactoe_clients`.
     */
    public static function ttt_broadcast_state(string $room): void
    {
        self::broadcast($room, []);
    }

    /**
     * Broadcast the current room state merged with `$extras`.
     *
     * Use this variant to piggyback additional fields (e.g. `"event"`,
     * `"winner_line"`) onto the standard state payload in a single push.
     *
     * @param array<string, mixed> $extras  Extra key-value pairs merged into the payload.
     */
    public static function ttt_broadcast_state_with(string $room, array $extras): void
    {
        self::broadcast($room, $extras);
    }

    /**
     * Shared fan-out core for `ttt_broadcast_state()` / `ttt_broadcast_state_with()`.
     *
     * Builds the state payload (optionally merged with `$extras`) and pushes it to
     * every `fd` in the room. Single implementation so the two public entry points
     * can't drift in payload shape.
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

    /**
     * Coerce a mixed `Store` value to `int`.
     *
     * `Store` columns return `string` on read even for `TYPE_INT` columns.
     * Returns `0` for non-numeric values.
     */
    private static function asInt(mixed $v): int
    {
        if (is_int($v))                      { return $v; }
        if (is_numeric($v))                  { return (int) $v; }
        return 0;
    }
}
