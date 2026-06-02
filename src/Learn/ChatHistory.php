<?php
namespace ZealPHP\Learn;

/**
 * Data-access helpers for the `chat_history` table used by the Learn module.
 *
 * Each row stores one conversational turn: a `role` (`"user"` or `"assistant"`),
 * an array of content `items` (serialised as JSON), and a `thread_id` that groups
 * turns into a logical conversation. All methods are stateless static helpers
 * that accept a `\PDO` instance so callers control the connection lifetime.
 */
class ChatHistory
{
    /**
     * Append one conversational turn to the `chat_history` table.
     *
     * @param array<int, array<string, mixed>> $items  Content items for this turn
     *                                                  (e.g. text blocks, tool results).
     * @return int  The auto-increment id of the inserted row.
     */
    public static function append(\PDO $db, int $userId, string $threadId, string $role, array $items): int
    {
        $stmt = $db->prepare('INSERT INTO chat_history (user_id, thread_id, role, items_json, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $threadId, $role, json_encode($items, JSON_UNESCAPED_UNICODE), time()]);
        return (int) $db->lastInsertId();
    }

    /**
     * Fetch all turns for a thread in chronological order.
     *
     * @return array<int, array<string, mixed>>  Rows ordered by `created_at ASC, id ASC`.
     */
    public static function forThread(\PDO $db, int $userId, string $threadId): array
    {
        $stmt = $db->prepare('SELECT id, role, items_json, created_at FROM chat_history WHERE user_id = ? AND thread_id = ? ORDER BY created_at ASC, id ASC');
        $stmt->execute([$userId, $threadId]);
        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * List the most recent threads for a user, newest first.
     *
     * Each row contains `thread_id`, `last_at` (Unix timestamp of the latest turn),
     * and `turns` (total turn count for that thread).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function threads(\PDO $db, int $userId, int $limit = 10): array
    {
        $stmt = $db->prepare('SELECT thread_id, MAX(created_at) AS last_at, COUNT(*) AS turns FROM chat_history WHERE user_id = ? GROUP BY thread_id ORDER BY last_at DESC LIMIT ?');
        $stmt->execute([$userId, $limit]);
        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }
}
