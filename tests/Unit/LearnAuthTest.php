<?php
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../route/learn.php';

class LearnAuthTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/learn_test_' . uniqid() . '.db';
        putenv('ZEALPHP_LEARN_DB_PATH=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $p) {
            if (file_exists($p)) @unlink($p);
        }
    }

    public function test_validate_username_accepts_valid(): void
    {
        $this->assertTrue(\learn_validate_username('alice'));
        $this->assertTrue(\learn_validate_username('alice_99'));
        $this->assertTrue(\learn_validate_username(str_repeat('a', 64)));
    }

    public function test_validate_username_rejects_invalid(): void
    {
        $this->assertFalse(\learn_validate_username('ab'));
        $this->assertFalse(\learn_validate_username(str_repeat('a', 65)));
        $this->assertFalse(\learn_validate_username('alice bob'));
        $this->assertFalse(\learn_validate_username('alice-bob'));
        $this->assertFalse(\learn_validate_username('alice!'));
    }

    public function test_validate_password_length(): void
    {
        $this->assertTrue(\learn_validate_password(str_repeat('x', 8)));
        $this->assertTrue(\learn_validate_password(str_repeat('x', 256)));
        $this->assertFalse(\learn_validate_password(str_repeat('x', 7)));
        $this->assertFalse(\learn_validate_password(str_repeat('x', 257)));
    }

    public function test_db_bootstrap_is_idempotent(): void
    {
        $db1 = \learn_db_open();
        $db2 = \learn_db_open();
        $this->assertInstanceOf(\PDO::class, $db1);
        $tables = $db1->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('users', $tables);
        $this->assertContains('notes', $tables);
    }

    public function test_register_and_login_roundtrip(): void
    {
        $db = \learn_db_open();
        $userId = \learn_register_user($db, 'alice', 'password123');
        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);

        $loggedInId = \learn_login_user($db, 'alice', 'password123');
        $this->assertSame($userId, $loggedInId);

        $this->assertNull(\learn_login_user($db, 'alice', 'wrong'));
        $this->assertNull(\learn_login_user($db, 'nope', 'password123'));
    }

    public function test_register_duplicate_username_returns_null(): void
    {
        $db = \learn_db_open();
        \learn_register_user($db, 'alice', 'password123');
        $this->assertNull(\learn_register_user($db, 'alice', 'differentpw99'));
    }
}
