<?php
namespace ZealPHP\Learn;

class Notes
{
    public static function create(\PDO $db, int $userId, string $title, string $body): ?int
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) return null;
        if (strlen($body) > 4096) return null;
        $max = (int) (getenv('ZEALPHP_LEARN_MAX_NOTES') ?: 256);
        $cnt = $db->prepare('SELECT COUNT(*) FROM notes WHERE user_id = ?');
        $cnt->execute([$userId]);
        if ((int) $cnt->fetchColumn() >= $max) return null;
        $now = time();
        $stmt = $db->prepare('INSERT INTO notes (user_id, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $title, $body, $now, $now]);
        return (int) $db->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public static function list(\PDO $db, int $userId): array
    {
        $stmt = $db->prepare('SELECT id, title, body, created_at, updated_at FROM notes WHERE user_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function read(\PDO $db, int $userId, int $noteId): ?array
    {
        $stmt = $db->prepare('SELECT id, title, body, created_at, updated_at FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$noteId, $userId]);
        $r = $stmt->fetch();
        return $r ?: null;
    }

    public static function update(\PDO $db, int $userId, int $noteId, ?string $title, ?string $body): bool
    {
        $existing = self::read($db, $userId, $noteId);
        if (!$existing) return false;
        $newTitle = $title ?? $existing['title'];
        $newBody  = $body ?? $existing['body'];
        $newTitle = trim($newTitle);
        if ($newTitle === '' || mb_strlen($newTitle) > 200) return false;
        if (strlen($newBody) > 4096) return false;
        $stmt = $db->prepare('UPDATE notes SET title = ?, body = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$newTitle, $newBody, time(), $noteId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function delete(\PDO $db, int $userId, int $noteId): bool
    {
        $stmt = $db->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$noteId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<int, array<string, mixed>> */
    public static function search(\PDO $db, int $userId, string $query, int $limit = 10): array
    {
        $q = '%' . $query . '%';
        $stmt = $db->prepare('SELECT id, title, body, updated_at FROM notes WHERE user_id = ? AND (title LIKE ? OR body LIKE ?) ORDER BY updated_at DESC LIMIT ?');
        $stmt->execute([$userId, $q, $q, $limit]);
        return $stmt->fetchAll();
    }
}
