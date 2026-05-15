<?php
namespace ZealPHP\Learn;

class ChatHistory
{
    public static function append(\PDO $db, int $userId, string $threadId, string $role, array $items): int
    {
        $stmt = $db->prepare('INSERT INTO chat_history (user_id, thread_id, role, items_json, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $threadId, $role, json_encode($items, JSON_UNESCAPED_UNICODE), time()]);
        return (int) $db->lastInsertId();
    }

    public static function forThread(\PDO $db, int $userId, string $threadId): array
    {
        $stmt = $db->prepare('SELECT id, role, items_json, created_at FROM chat_history WHERE user_id = ? AND thread_id = ? ORDER BY created_at ASC, id ASC');
        $stmt->execute([$userId, $threadId]);
        return $stmt->fetchAll();
    }

    public static function threads(\PDO $db, int $userId, int $limit = 10): array
    {
        $stmt = $db->prepare('SELECT thread_id, MAX(created_at) AS last_at, COUNT(*) AS turns FROM chat_history WHERE user_id = ? GROUP BY thread_id ORDER BY last_at DESC LIMIT ?');
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }
}
