<?php
/**
 * JSON API endpoint — same code on Apache and ZealPHP.
 *
 *   GET /api/users.php?id=42  → { "id": 42, "name": "User 42", ... }
 *   GET /api/users.php        → { "users": [...] }
 *
 * Uses ONLY $g->get, $g->server — no $_GET, no $_SERVER. Move this file
 * between servers, nothing changes.
 */
require_once __DIR__ . '/../../bootstrap/g.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$id = isset($g->get['id']) ? (int) $g->get['id'] : null;

if ($id !== null) {
    echo json_encode([
        'id'     => $id,
        'name'   => "User $id",
        'email'  => "user$id@example.com",
        'server' => $g->server['SERVER_SOFTWARE'] ?? 'unknown',
        'method' => $g->server['REQUEST_METHOD'] ?? 'GET',
    ], JSON_PRETTY_PRINT);
    return;
}

// No id → list endpoint
$users = [];
for ($i = 1; $i <= 5; $i++) {
    $users[] = ['id' => $i, 'name' => "User $i"];
}

echo json_encode([
    'users'  => $users,
    'count'  => count($users),
    'server' => $g->server['SERVER_SOFTWARE'] ?? 'unknown',
], JSON_PRETTY_PRINT);
