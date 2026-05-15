<?php
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Learn\DB;
use ZealPHP\Learn\Auth;

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
        $this->assertTrue(Auth::validateUsername('alice'));
        $this->assertTrue(Auth::validateUsername('alice_99'));
        $this->assertTrue(Auth::validateUsername(str_repeat('a', 64)));
    }

    public function test_validate_username_rejects_invalid(): void
    {
        $this->assertFalse(Auth::validateUsername('ab'));
        $this->assertFalse(Auth::validateUsername(str_repeat('a', 65)));
        $this->assertFalse(Auth::validateUsername('alice bob'));
        $this->assertFalse(Auth::validateUsername('alice-bob'));
        $this->assertFalse(Auth::validateUsername('alice!'));
    }

    public function test_validate_password_length(): void
    {
        $this->assertTrue(Auth::validatePassword(str_repeat('x', 8)));
        $this->assertTrue(Auth::validatePassword(str_repeat('x', 256)));
        $this->assertFalse(Auth::validatePassword(str_repeat('x', 7)));
        $this->assertFalse(Auth::validatePassword(str_repeat('x', 257)));
    }

    public function test_db_bootstrap_is_idempotent(): void
    {
        $db1 = DB::open();
        $db2 = DB::open();
        $this->assertInstanceOf(\PDO::class, $db1);
        $tables = $db1->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('users', $tables);
        $this->assertContains('notes', $tables);
    }

    public function test_register_and_login_roundtrip(): void
    {
        $db = DB::open();
        $userId = Auth::register($db, 'alice', 'password123');
        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);

        $loggedInId = Auth::login($db, 'alice', 'password123');
        $this->assertSame($userId, $loggedInId);

        $this->assertNull(Auth::login($db, 'alice', 'wrong'));
        $this->assertNull(Auth::login($db, 'nope', 'password123'));
    }

    public function test_register_duplicate_username_returns_null(): void
    {
        $db = DB::open();
        Auth::register($db, 'alice', 'password123');
        $this->assertNull(Auth::register($db, 'alice', 'differentpw99'));
    }
}
