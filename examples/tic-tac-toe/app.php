<?php
// Multiplayer Tic-Tac-Toe — ZealPHP WebSocket demo.
//
// Two players + unlimited spectators per room, real-time board sync,
// persistent scoreboard. No database, no Node.js — one PHP process.
//
//   composer install && php app.php
//   Open http://localhost:8080 in two browser tabs.

require_once __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\Store;

App::superglobals(false);

Store::make('rooms', 256, [
    'board'   => [Store::TYPE_STRING, 9],
    'turn'    => [Store::TYPE_STRING, 2],
    'winner'  => [Store::TYPE_STRING, 8],
    'px_fd'   => [Store::TYPE_INT,    8],
    'po_fd'   => [Store::TYPE_INT,    8],
    'px_name' => [Store::TYPE_STRING, 32],
    'po_name' => [Store::TYPE_STRING, 32],
    'starter' => [Store::TYPE_STRING, 2],
    'rounds'  => [Store::TYPE_INT,    4],
    'x_wins'  => [Store::TYPE_INT,    4],
    'o_wins'  => [Store::TYPE_INT,    4],
    'draws'   => [Store::TYPE_INT,    4],
]);

Store::make('players', 1024, [
    'room'   => [Store::TYPE_STRING, 32],
    'name'   => [Store::TYPE_STRING, 32],
    'symbol' => [Store::TYPE_STRING, 2],
]);

$app = App::init('0.0.0.0', 8080);

function detectWinner(string $board): array
{
    foreach ([[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]] as $line) {
        [$a, $b, $c] = $line;
        if ($board[$a] !== '_' && $board[$a] === $board[$b] && $board[$a] === $board[$c]) {
            return [$board[$a], $line];
        }
    }
    return [null, null];
}

function broadcast(string $room, array $extras = []): void
{
    $server = App::getServer();
    $row = Store::get('rooms', $room);
    if (!$server || !$row) return;

    $viewers = 0;
    foreach (Store::table('players') ?? [] as $c) {
        if ($c['room'] === $room && $c['symbol'] === 'S') $viewers++;
    }

    $state = json_encode(array_merge([
        'type'    => 'state',
        'board'   => $row['board'],
        'turn'    => $row['turn'],
        'winner'  => $row['winner'],
        'rounds'  => (int) $row['rounds'],
        'players' => [
            'X' => ['name' => $row['px_name'], 'connected' => (int) $row['px_fd'] > 0],
            'O' => ['name' => $row['po_name'], 'connected' => (int) $row['po_fd'] > 0],
        ],
        'score'   => [
            'X'    => (int) ($row['x_wins'] ?? 0),
            'O'    => (int) ($row['o_wins'] ?? 0),
            'draw' => (int) ($row['draws'] ?? 0),
        ],
        'viewers' => $viewers,
    ], $extras));

    foreach (Store::table('players') ?? [] as $fd => $c) {
        if ($c['room'] !== $room) continue;
        $fd = (int) $fd;
        if ($server->isEstablished($fd)) $server->push($fd, $state);
    }
}

$app->ws('/ws/game',
    onMessage: function ($server, $frame) {
        $me = Store::get('players', (string) $frame->fd);
        if (!$me) return;
        $msg = json_decode($frame->data, true);
        if (!is_array($msg)) return;

        $room = $me['room'];
        $row  = Store::get('rooms', $room);
        if (!$row) return;

        if (($msg['type'] ?? '') === 'move') {
            if ($me['symbol'] === 'S' || $row['winner'] !== '' || $me['symbol'] !== $row['turn']) return;
            $cell = (int) ($msg['cell'] ?? -1);
            if ($cell < 0 || $cell > 8) return;
            $board = $row['board'];
            if ($board[$cell] !== '_') return;

            $board[$cell] = $me['symbol'];
            [$winner, $winLine] = detectWinner($board);
            $update = ['board' => $board];
            $extras = [];

            if ($winner) {
                $update['winner'] = $winner;
                $update['turn']   = '';
                $update['rounds'] = (int) $row['rounds'] + 1;
                $key = $winner === 'X' ? 'x_wins' : 'o_wins';
                $update[$key] = (int) ($row[$key] ?? 0) + 1;
                $extras['win_line'] = $winLine;
            } elseif (strpos($board, '_') === false) {
                $update['winner'] = 'draw';
                $update['turn']   = '';
                $update['rounds'] = (int) $row['rounds'] + 1;
                $update['draws']  = (int) ($row['draws'] ?? 0) + 1;
            } else {
                $update['turn'] = $row['turn'] === 'X' ? 'O' : 'X';
            }

            Store::set('rooms', $room, $update);
            broadcast($room, $extras);
            return;
        }

        if (($msg['type'] ?? '') === 'reset' && $me['symbol'] !== 'S') {
            $starter = ($row['starter'] ?? 'X') === 'X' ? 'O' : 'X';
            Store::set('rooms', $room, [
                'board' => '_________', 'turn' => $starter, 'winner' => '', 'starter' => $starter,
            ]);
            broadcast($room);
            return;
        }

        if (($msg['type'] ?? '') === 'reset_score' && $me['symbol'] !== 'S') {
            Store::set('rooms', $room, ['x_wins' => 0, 'o_wins' => 0, 'draws' => 0, 'rounds' => 0]);
            broadcast($room);
        }
    },

    onOpen: function ($server, $request) {
        $name = substr(trim($request->get['name'] ?? ''), 0, 24);
        $room = substr(preg_replace('/[^a-z0-9-]/', '', strtolower($request->get['room'] ?? '')), 0, 32);
        if ($name === '' || $room === '') {
            $server->disconnect($request->fd, 1008, 'name_and_room_required');
            return;
        }

        $row = Store::get('rooms', $room);
        if (!$row) {
            Store::set('rooms', $room, [
                'board' => '_________', 'turn' => 'X', 'winner' => '',
                'px_fd' => 0, 'po_fd' => 0, 'px_name' => '', 'po_name' => '',
                'starter' => 'X', 'rounds' => 0, 'x_wins' => 0, 'o_wins' => 0, 'draws' => 0,
            ]);
            $row = Store::get('rooms', $room);
        }

        $symbol = 'S';
        if ((int) $row['px_fd'] === 0) {
            $symbol = 'X';
            Store::set('rooms', $room, ['px_fd' => $request->fd, 'px_name' => $name]);
        } elseif ((int) $row['po_fd'] === 0) {
            $symbol = 'O';
            Store::set('rooms', $room, ['po_fd' => $request->fd, 'po_name' => $name]);
        }

        Store::set('players', (string) $request->fd, [
            'room' => $room, 'name' => $name, 'symbol' => $symbol,
        ]);
        $server->push($request->fd, json_encode([
            'type' => 'welcome', 'symbol' => $symbol, 'room' => $room,
        ]));
        broadcast($room);
    },

    onClose: function ($server, $fd) {
        $me = Store::get('players', (string) $fd);
        Store::del('players', (string) $fd);
        if (!$me) return;

        $room = $me['room'];
        $row = Store::get('rooms', $room);
        if (!$row) return;

        $update = [];
        if ((int) $row['px_fd'] === $fd) $update['px_fd'] = 0;
        if ((int) $row['po_fd'] === $fd) $update['po_fd'] = 0;
        if ($update) Store::set('rooms', $room, $update);
        broadcast($room);
    },
);

$app->route('/', fn () => file_get_contents(__DIR__ . '/public/index.html'));

$app->route('/style.css', function ($response) {
    $response->header('Content-Type', 'text/css');
    return file_get_contents(__DIR__ . '/public/style.css');
});

$app->route('/game.js', function ($response) {
    $response->header('Content-Type', 'application/javascript');
    return file_get_contents(__DIR__ . '/public/game.js');
});

$app->run();
