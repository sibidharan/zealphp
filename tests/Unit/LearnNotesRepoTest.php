<?php
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../route/learn.php';

class LearnNotesRepoTest extends TestCase
{
    private string $dbPath;
    private \PDO $db;
    private int $aliceId;
    private int $bobId;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/learn_test_' . uniqid() . '.db';
        putenv('ZEALPHP_LEARN_DB_PATH=' . $this->dbPath);
        // Reset the static PDO cache so each test gets a fresh DB.
        // The cache is keyed by path, and unique uniqid() means cache miss anyway.
        $this->db = \learn_db_open();
        $this->aliceId = \learn_register_user($this->db, 'alice', 'password123');
        $this->bobId = \learn_register_user($this->db, 'bob', 'password123');
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $p) {
            if (file_exists($p)) @unlink($p);
        }
    }

    public function test_create_and_list_notes(): void
    {
        $id = \learn_notes_create($this->db, $this->aliceId, 'Buy milk', 'Whole, not skim');
        $this->assertIsInt($id);
        $notes = \learn_notes_list($this->db, $this->aliceId);
        $this->assertCount(1, $notes);
        $this->assertSame('Buy milk', $notes[0]['title']);
    }

    public function test_user_isolation(): void
    {
        \learn_notes_create($this->db, $this->aliceId, 'Alice note', '');
        \learn_notes_create($this->db, $this->bobId, 'Bob note', '');
        $this->assertCount(1, \learn_notes_list($this->db, $this->aliceId));
        $this->assertCount(1, \learn_notes_list($this->db, $this->bobId));
        $this->assertSame('Alice note', \learn_notes_list($this->db, $this->aliceId)[0]['title']);
    }

    public function test_update_scoped_to_user(): void
    {
        $id = \learn_notes_create($this->db, $this->aliceId, 'orig', 'body');
        $this->assertTrue(\learn_notes_update($this->db, $this->aliceId, $id, 'new', null));
        $note = \learn_notes_read($this->db, $this->aliceId, $id);
        $this->assertSame('new', $note['title']);
        $this->assertSame('body', $note['body']);
        $this->assertFalse(\learn_notes_update($this->db, $this->bobId, $id, 'hacked', null));
    }

    public function test_delete_scoped_to_user(): void
    {
        $id = \learn_notes_create($this->db, $this->aliceId, 't', 'b');
        $this->assertFalse(\learn_notes_delete($this->db, $this->bobId, $id));
        $this->assertCount(1, \learn_notes_list($this->db, $this->aliceId));
        $this->assertTrue(\learn_notes_delete($this->db, $this->aliceId, $id));
        $this->assertCount(0, \learn_notes_list($this->db, $this->aliceId));
    }

    public function test_title_length_limit(): void
    {
        $this->assertNull(\learn_notes_create($this->db, $this->aliceId, str_repeat('a', 201), ''));
    }

    public function test_body_length_limit(): void
    {
        $this->assertNull(\learn_notes_create($this->db, $this->aliceId, 't', str_repeat('a', 4097)));
    }

    public function test_search_notes(): void
    {
        \learn_notes_create($this->db, $this->aliceId, 'Buy groceries', 'Apples and bread');
        \learn_notes_create($this->db, $this->aliceId, 'Pay rent', 'Due Friday');
        \learn_notes_create($this->db, $this->bobId,   'Bob groceries', 'shopping');
        $hits = \learn_notes_search($this->db, $this->aliceId, 'groceries');
        $this->assertCount(1, $hits);
        $this->assertSame('Buy groceries', $hits[0]['title']);
    }

    public function test_chat_history_append_and_fetch(): void
    {
        $items = [['type' => 'text', 'html' => '<p>hi</p>']];
        $id = \learn_chat_history_append($this->db, $this->aliceId, 't1', 'user', $items);
        $this->assertIsInt($id);
        $rows = \learn_chat_history_for_thread($this->db, $this->aliceId, 't1');
        $this->assertCount(1, $rows);
        $this->assertSame('user', $rows[0]['role']);
        $this->assertSame($items, json_decode($rows[0]['items_json'], true));
    }

    public function test_chat_history_user_isolation(): void
    {
        \learn_chat_history_append($this->db, $this->aliceId, 't1', 'user', [['type' => 'text', 'html' => 'alice']]);
        \learn_chat_history_append($this->db, $this->bobId,   't1', 'user', [['type' => 'text', 'html' => 'bob']]);
        $aliceRows = \learn_chat_history_for_thread($this->db, $this->aliceId, 't1');
        $this->assertCount(1, $aliceRows);
        $this->assertStringContainsString('alice', $aliceRows[0]['items_json']);
    }

    public function test_chat_history_thread_list(): void
    {
        \learn_chat_history_append($this->db, $this->aliceId, 't1', 'user', [['type' => 'text', 'html' => 'a']]);
        \learn_chat_history_append($this->db, $this->aliceId, 't2', 'user', [['type' => 'text', 'html' => 'b']]);
        $threads = \learn_chat_history_threads($this->db, $this->aliceId);
        $this->assertCount(2, $threads);
        $this->assertContains('t1', array_column($threads, 'thread_id'));
    }
}
