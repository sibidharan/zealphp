# Tic-Tac-Toe — ZealPHP WebSocket Demo

Multiplayer tic-tac-toe with rooms, spectators, and a persistent scoreboard.
No database, no Node.js — one PHP process handles HTTP and WebSocket.

## Run it

```bash
composer install
php app.php
```

Open [http://localhost:8080](http://localhost:8080) in two browser tabs. Enter the same room ID in both to play.

Third tab? Joins as spectator automatically.

## How it works

`app.php` is the entire backend (~130 lines):

- **WebSocket** (`App::ws('/ws/game', ...)`) handles connections, moves, and disconnects.
- **Store** (`Store::make(...)`) keeps game state in cross-worker shared memory — no database, no Redis.
- **Broadcast** iterates the player table and pushes JSON state to every fd in the room.

The client (`public/game.js`) opens a WebSocket, sends `{type:'move', cell:N}`, and renders whatever state the server pushes back. No polling, no REST — pure push.

## Requires

- PHP 8.3+
- OpenSwoole extension
- uopz extension

See the [main repo](https://github.com/sibidharan/zealphp) for install instructions, or use Docker:

```bash
# From the repo root:
docker compose up app
```
