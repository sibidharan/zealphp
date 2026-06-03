<?php
namespace ZealPHP\Learn;

/**
 * Data-access helpers for the `notes` table used by the Learn module.
 *
 * Provides CRUD + search operations for per-user notes. All methods are
 * stateless static helpers that accept a `\PDO` instance so callers control
 * the connection lifetime. Input validation (title length, body size, per-user
 * note cap) is enforced here rather than at the API layer.
 *
 * The per-user note cap defaults to `256` and is overridden by the
 * `ZEALPHP_LEARN_MAX_NOTES` environment variable.
 */
class Notes
{
    /**
     * Create a new note for `$userId`.
     *
     * Returns the new note's auto-increment id, or `null` when validation fails:
     * - `$title` is empty or exceeds 200 characters.
     * - `$body` exceeds 4096 bytes.
     * - The user already has `ZEALPHP_LEARN_MAX_NOTES` (default `256`) notes.
     */
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

    /**
     * List all notes for `$userId`, most recently updated first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function list(\PDO $db, int $userId): array
    {
        $stmt = $db->prepare('SELECT id, title, body, created_at, updated_at FROM notes WHERE user_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$userId]);
        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Fetch a single note by id, scoped to `$userId`.
     *
     * Returns `null` when the note does not exist or belongs to a different user.
     *
     * @return array<string, mixed>|null
     */
    public static function read(\PDO $db, int $userId, int $noteId): ?array
    {
        $stmt = $db->prepare('SELECT id, title, body, created_at, updated_at FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$noteId, $userId]);
        $r = $stmt->fetch();
        if (!is_array($r)) return null;
        /** @var array<string, mixed> $r */
        return $r;
    }

    /**
     * Update a note's title and/or body.
     *
     * Pass `null` for either field to keep the existing value. Applies the same
     * validation as `create()` (title length, body size). Returns `false` when
     * the note does not exist, belongs to a different user, or validation fails.
     */
    public static function update(\PDO $db, int $userId, int $noteId, ?string $title, ?string $body): bool
    {
        $existing = self::read($db, $userId, $noteId);
        if (!$existing) return false;
        // @phpstan-ignore-next-line — $existing is array<string, mixed> from DB; title coerced to string at boundary
        $newTitle = $title ?? (string)$existing['title'];
        // @phpstan-ignore-next-line — $existing is array<string, mixed> from DB; body coerced to string at boundary
        $newBody  = $body ?? (string)$existing['body'];
        $newTitle = trim($newTitle);
        if ($newTitle === '' || mb_strlen($newTitle) > 200) return false;
        if (strlen($newBody) > 4096) return false;
        $stmt = $db->prepare('UPDATE notes SET title = ?, body = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$newTitle, $newBody, time(), $noteId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a note by id, scoped to `$userId`.
     *
     * Returns `true` when a row was deleted, `false` when no matching row existed.
     */
    public static function delete(\PDO $db, int $userId, int $noteId): bool
    {
        $stmt = $db->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
        $stmt->execute([$noteId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Full-text `LIKE` search across `title` and `body` for `$userId`.
     *
     * Returns up to `$limit` notes (default `10`), ordered by `updated_at DESC`.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(\PDO $db, int $userId, string $query, int $limit = 10): array
    {
        $q = '%' . $query . '%';
        $stmt = $db->prepare('SELECT id, title, body, updated_at FROM notes WHERE user_id = ? AND (title LIKE ? OR body LIKE ?) ORDER BY updated_at DESC LIMIT ?');
        $stmt->execute([$userId, $q, $q, $limit]);
        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }
}
