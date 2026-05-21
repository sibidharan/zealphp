<?php
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Store;
use ZealPHP\Learn\DB;
use ZealPHP\Learn\Auth;

class LearnAuthTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $root = defined('ZEALPHP_ROOT') ? constant('ZEALPHP_ROOT') : dirname(__DIR__, 2);
        App::$cwd = is_string($root) ? $root : dirname(__DIR__, 2);
        App::superglobals(true);
        $this->dbPath = sys_get_temp_dir() . '/learn_test_' . uniqid() . '.db';
        putenv('ZEALPHP_LEARN_DB_PATH=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $p) {
            if (file_exists($p)) @unlink($p);
        }
        // Clean the per-request singleton state we seeded so we don't leak
        // into ordering-fragile tests that share the superglobals-mode
        // RequestContext singleton.
        $g = RequestContext::instance();
        $g->session = [];
        $g->zealphp_request = null;
        $g->zealphp_response = null;
        putenv('ZEALPHP_LEARN_RATE_LIMIT_LOOPBACK');
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
        $stmt = $db1->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
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

    public function test_register_rejects_invalid_username_or_password(): void
    {
        $db = DB::open();
        $this->assertNull(Auth::register($db, 'ab', 'password123'));   // username too short
        $this->assertNull(Auth::register($db, 'validname', 'short'));  // password too short
    }

    public function test_current_user_returns_null_without_session(): void
    {
        $g = RequestContext::instance();
        $g->session = [];
        $this->assertNull(Auth::currentUser());
    }

    public function test_current_user_returns_session_user(): void
    {
        $db = DB::open();
        $id = Auth::register($db, 'alice', 'password123');

        $g = RequestContext::instance();
        $g->session = ['user_id' => $id, 'username' => 'alice'];

        $user = Auth::currentUser();
        $this->assertIsArray($user);
        $this->assertSame($id, $user['user_id']);
        $this->assertSame('alice', $user['username']);
    }

    public function test_current_user_clears_session_when_row_missing(): void
    {
        DB::open(); // bootstrap schema, no matching row for id 999
        $g = RequestContext::instance();
        $g->session = ['user_id' => 999, 'username' => 'ghost'];

        $this->assertNull(Auth::currentUser());
        $g = RequestContext::instance();
        $this->assertArrayNotHasKey('user_id', $g->session);
        $this->assertArrayNotHasKey('username', $g->session);
    }

    public function test_read_credentials_from_form_post(): void
    {
        $g = RequestContext::instance();
        $g->server = [];
        $g->post = ['username' => 'alice', 'password' => 'password123'];

        $creds = Auth::readCredentials($g);
        $this->assertSame(['username' => 'alice', 'password' => 'password123'], $creds);
    }

    public function test_read_credentials_returns_null_when_missing(): void
    {
        $g = RequestContext::instance();
        $g->server = [];
        $g->post = ['username' => 'alice', 'password' => ''];
        $this->assertNull(Auth::readCredentials($g));

        $g->post = [];
        $this->assertNull(Auth::readCredentials($g));
    }

    public function test_read_credentials_from_json_body(): void
    {
        $g = RequestContext::instance();
        $g->server = ['CONTENT_TYPE' => 'application/json'];
        $g->post = [];
        $g->zealphp_request = $this->jsonRequest('{"username":"bob","password":"hunter22pw"}');

        $creds = Auth::readCredentials($g);
        $this->assertSame(['username' => 'bob', 'password' => 'hunter22pw'], $creds);
    }

    public function test_read_credentials_json_invalid_body_returns_null(): void
    {
        $g = RequestContext::instance();
        $g->server = ['HTTP_CONTENT_TYPE' => 'application/json'];
        $g->post = [];
        $g->zealphp_request = $this->jsonRequest('not-json');

        $this->assertNull(Auth::readCredentials($g));
    }

    public function test_read_credentials_json_missing_fields_returns_null(): void
    {
        $g = RequestContext::instance();
        $g->server = ['CONTENT_TYPE' => 'application/json'];
        $g->post = [];
        $g->zealphp_request = $this->jsonRequest('{"username":"bob"}');

        $this->assertNull(Auth::readCredentials($g));
    }

    /**
     * Builds a ZealPHP\HTTP\Request whose ->parent->getContent() returns $body,
     * matching how Auth::readCredentials reads a JSON request body. The parent
     * is an OpenSwoole\Http\Request subclass overriding getContent() so no real
     * socket is required.
     */
    private function jsonRequest(string $body): \ZealPHP\HTTP\Request
    {
        $parent = new class extends \OpenSwoole\Http\Request {
            public string $fakeBody = '';

            public function getContent(): string
            {
                return $this->fakeBody;
            }
        };
        $parent->fakeBody = $body;

        return new class($parent) extends \ZealPHP\HTTP\Request {
            public function __construct(\OpenSwoole\Http\Request $parent)
            {
                // Skip the real constructor (it wires by-ref proxies to live
                // socket props); just stash the parent the reader needs.
                $this->parent = $parent;
            }
        };
    }

    public function test_rate_limit_disabled_when_limit_non_positive(): void
    {
        $this->assertTrue(Auth::rateLimit('rl_disabled', '8.8.8.8', 0, 60));
        $this->assertTrue(Auth::rateLimit('rl_disabled', '8.8.8.8', -5, 60));
    }

    public function test_rate_limit_bypasses_loopback_by_default(): void
    {
        putenv('ZEALPHP_LEARN_RATE_LIMIT_LOOPBACK');
        $this->assertTrue(Auth::rateLimit('rl_loop', '127.0.0.1', 1, 60));
        $this->assertTrue(Auth::rateLimit('rl_loop', '127.0.0.1', 1, 60));
        $this->assertTrue(Auth::rateLimit('rl_loop', '::1', 1, 60));
        $this->assertTrue(Auth::rateLimit('rl_loop', '::ffff:127.0.0.1', 1, 60));
        $this->assertTrue(Auth::rateLimit('rl_loop', '127.5.5.5', 1, 60));
    }

    public function test_rate_limit_enforces_window_for_remote_ip(): void
    {
        $table = 'rl_' . uniqid();
        Store::make($table, 64, [
            'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],
            'count' => [\OpenSwoole\Table::TYPE_INT, 8],
            'reset' => [\OpenSwoole\Table::TYPE_INT, 8],
        ]);

        $ip = '203.0.113.7';
        // First two allowed (limit 2), third blocked within the window.
        $this->assertTrue(Auth::rateLimit($table, $ip, 2, 60));
        $this->assertTrue(Auth::rateLimit($table, $ip, 2, 60));
        $this->assertFalse(Auth::rateLimit($table, $ip, 2, 60));
    }

    public function test_rate_limit_resets_after_window_expires(): void
    {
        $table = 'rl_' . uniqid();
        Store::make($table, 64, [
            'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],
            'count' => [\OpenSwoole\Table::TYPE_INT, 8],
            'reset' => [\OpenSwoole\Table::TYPE_INT, 8],
        ]);

        $ip = '203.0.113.8';
        // Seed an already-expired window: reset in the past forces a fresh window.
        Store::set($table, $ip, ['ip' => $ip, 'count' => 99, 'reset' => time() - 10]);
        $this->assertTrue(Auth::rateLimit($table, $ip, 2, 60));
        // Count should have been reset to 1, so another call still passes.
        $this->assertTrue(Auth::rateLimit($table, $ip, 2, 60));
        $this->assertFalse(Auth::rateLimit($table, $ip, 2, 60));
    }

    public function test_rate_limit_loopback_opt_in_via_env(): void
    {
        putenv('ZEALPHP_LEARN_RATE_LIMIT_LOOPBACK=1');
        $table = 'rl_' . uniqid();
        Store::make($table, 64, [
            'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],
            'count' => [\OpenSwoole\Table::TYPE_INT, 8],
            'reset' => [\OpenSwoole\Table::TYPE_INT, 8],
        ]);

        // With opt-in, loopback IPs are subject to the limiter.
        $this->assertTrue(Auth::rateLimit($table, '127.0.0.1', 1, 60));
        $this->assertFalse(Auth::rateLimit($table, '127.0.0.1', 1, 60));
    }
}
