<?php

declare(strict_types=1);

namespace ZealPHP\Learn;

use ZealPHP\Store;

/**
 * Lesson 22 — multi-room group chat (SQLite persistence, no Redis required).
 *
 * Three pure-PHP responsibilities:
 *   1. Persist messages in the local SQLite (`chatroom_messages` table).
 *   2. Recall the last N messages on join (so a refresh shows history).
 *   3. List the rooms that have any traffic (the "lobby" view).
 *
 * Fan-out to connected sockets is the WS handler's job (route/learn_chatroom.php).
 * Single-server uses a local fd map; the lesson shows how to swap that for
 * `WSRouter::room()` to scale to N nodes — same call sites, different fabric.
 */
final class Chatroom
{
    private const MAX_MESSAGE_LEN  = 2000;
    private const MAX_USERNAME_LEN = 32;
    private const DEFAULT_TAIL     = 50;

    /**
     * Persist a message in the room. Trims user content + rejects empty bodies.
     * Returns the persisted row (with assigned id + server-side timestamp).
     *
     * @return array{id:int, room:string, username:string, body:string, kind:string, created_at:int}
     */
    public static function saveMessage(string $room, string $username, string $body, string $kind = 'message'): array
    {
        [$room, $username, $body] = self::normalize($room, $username, $body);
        if ($body === '') {
            throw new \InvalidArgumentException('Chatroom::saveMessage: empty body');
        }
        $kind = in_array($kind, ['message', 'system'], true) ? $kind : 'message';

        $db = DB::open();
        $now = time();
        $stmt = $db->prepare('INSERT INTO chatroom_messages (room, username, body, kind, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$room, $username, $body, $kind, $now]);
        return [
            'id'         => (int) $db->lastInsertId(),
            'room'       => $room,
            'username'   => $username,
            'body'       => $body,
            'kind'       => $kind,
            'created_at' => $now,
        ];
    }

    /**
     * Last $tail messages from this room in chronological order (oldest first).
     *
     * @return list<array{id:int, room:string, username:string, body:string, kind:string, created_at:int}>
     */
    public static function recent(string $room, int $tail = self::DEFAULT_TAIL): array
    {
        $tail = max(1, min(500, $tail));
        $db = DB::open();
        $stmt = $db->prepare('SELECT id, room, username, body, kind, created_at FROM chatroom_messages WHERE room = ? ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $room, \PDO::PARAM_STR);
        $stmt->bindValue(2, $tail, \PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach (array_reverse($stmt->fetchAll()) as $row) {
            if (!is_array($row)) { continue; }
            $out[] = self::shapeMessage($row);
        }
        return $out;
    }

    /**
     * @param  array<int|string, mixed> $row
     * @return array{id:int, room:string, username:string, body:string, kind:string, created_at:int}
     */
    private static function shapeMessage(array $row): array
    {
        return [
            'id'         => self::asInt($row['id']         ?? 0),
            'room'       => self::asString($row['room']       ?? ''),
            'username'   => self::asString($row['username']   ?? ''),
            'body'       => self::asString($row['body']       ?? ''),
            'kind'       => self::asString($row['kind']       ?? 'message'),
            'created_at' => self::asInt($row['created_at'] ?? 0),
        ];
    }

    private static function asInt(mixed $v): int
    {
        if (is_int($v))                          { return $v; }
        if (is_string($v) && is_numeric($v))     { return (int) $v; }
        if (is_float($v))                        { return (int) $v; }
        return 0;
    }

    private static function asString(mixed $v): string
    {
        if (is_string($v))    { return $v; }
        if (is_scalar($v))    { return (string) $v; }
        return '';
    }

    /**
     * Lobby — every room with at least one message + the latest activity timestamp.
     *
     * @return list<array{room:string, last_msg_at:int, count:int}>
     */
    public static function listRooms(): array
    {
        $db = DB::open();
        $stmt = $db->query('SELECT room, MAX(created_at) AS last_msg_at, COUNT(*) AS count FROM chatroom_messages GROUP BY room ORDER BY last_msg_at DESC');
        if ($stmt === false) { return []; }
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) { continue; }
            $out[] = [
                'room'        => self::asString($row['room']        ?? ''),
                'last_msg_at' => self::asInt($row['last_msg_at'] ?? 0),
                'count'       => self::asInt($row['count']       ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @return array{0:string,1:string,2:string}  trimmed room/username/body
     */
    private static function normalize(string $room, string $username, string $body): array
    {
        $room     = trim($room);
        $username = trim($username);
        $body     = trim($body);
        if (mb_strlen($username) > self::MAX_USERNAME_LEN) {
            $username = mb_substr($username, 0, self::MAX_USERNAME_LEN);
        }
        if (mb_strlen($body) > self::MAX_MESSAGE_LEN) {
            $body = mb_substr($body, 0, self::MAX_MESSAGE_LEN);
        }
        if ($room === '')     { $room = 'general'; }
        if ($username === '') { $username = 'anonymous'; }
        return [$room, $username, $body];
    }

    /**
     * Fan-out helper: iterate the cluster-wide fd map and push to every fd whose
     * row's `room` matches. Works across workers (any worker can $server->push
     * any fd) AND across the cluster when the Store backend is Redis (the table
     * iteration spans every node — federated chat for free).
     *
     * @param array<string, mixed> $payload
     */
    public static function broadcast_to_room(mixed $server, string $room, array $payload, int $excludeFd = 0): void
    {
        if (!($server instanceof \OpenSwoole\WebSocket\Server)) { return; }
        $data = (string) json_encode($payload);
        foreach (Store::iterate('chatroom_fds') as $fd => $info) {
            if (($info['room'] ?? null) !== $room) { continue; }
            $fdInt = (int) $fd;
            if ($fdInt === $excludeFd) { continue; }   // typing-presence skips sender
            if ($server->isEstablished($fdInt)) {
                $server->push($fdInt, $data);
            }
        }
    }
}
