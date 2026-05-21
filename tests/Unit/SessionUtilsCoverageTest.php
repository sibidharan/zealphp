<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\Session\zeal_session_start;
use function ZealPHP\Session\zeal_session_status;
use function ZealPHP\Session\zeal_session_encode;
use function ZealPHP\Session\php_session_decode_to_array;
use function ZealPHP\Session\zeal_session_unset;
use function ZealPHP\Session\zeal_session_abort;
use function ZealPHP\Session\zeal_session_id;

/**
 * Branch coverage for src/Session/utils.php not reached by SessionUtilsTest.php
 * / PhpSessionDecodeTest.php:
 *
 *   - zeal_session_start(): default save_path/name init, mkdir of a fresh
 *     save dir, first-visitor Set-Cookie emission, SessionHandlerInterface
 *     read branch.
 *   - zeal_session_status(): coroutine-mode (App::$superglobals=false) branch.
 *   - zeal_session_encode(): coroutine-mode source branch.
 *   - zeal_session_unset(): superglobals-mode unset($GLOBALS['_SESSION']).
 *   - zeal_session_abort(): superglobals-mode file-present and file-absent
 *     $GLOBALS['_SESSION'] mirroring.
 *
 * All state lives on the RequestContext singleton + temp dirs, fully reset in
 * tearDown. The declared typed `session` slot is reset via reflection (same as
 * SessionUtilsTest) so suite ordering can't leave it unset.
 */
class SessionUtilsCoverageTest extends TestCase
{
    private string $savePath;
    private ?bool $savedSuperglobals = null;
    private mixed $savedSuperSession = null;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        $this->savedSuperglobals = App::$superglobals;
        App::superglobals(true);
        $this->savePath = sys_get_temp_dir() . '/zealphp_sess_cov_' . bin2hex(random_bytes(6));

        $g = RequestContext::instance();
        // A prior coroutine-mode test (SessionHandlerWriteTest etc.) may have
        // unset() the declared typed `session` slot on the shared singleton.
        // Once unset, ReflectionProperty::setValue does NOT mark it initialized
        // again, so isset($g->session) stays false and abort/status become
        // no-ops. The only way to re-initialize the typed slot is a direct
        // write in coroutine mode (RequestContext::__set re-inits a
        // declared-but-unset slot when App::$superglobals is false).
        App::superglobals(false);
        $g->session = [];
        App::superglobals(true);

        $g->session_params = [];
        $g->cookie = [];
        $g->server = [];
        $g->openswoole_response = null;
        $this->savedSuperSession = $GLOBALS['_SESSION'] ?? null;
        $GLOBALS['_SESSION'] = [];
    }

    protected function tearDown(): void
    {
        $g = RequestContext::instance();
        // Leave the typed `session` slot initialized to [] for the next test,
        // using the coroutine-mode re-init (see setUp).
        App::superglobals(false);
        $g->session = [];
        App::superglobals(true);
        $g->session_params = [];
        $g->cookie = [];
        $g->server = [];
        $g->openswoole_response = null;

        if ($this->savedSuperSession === null) {
            unset($GLOBALS['_SESSION']);
        } else {
            $GLOBALS['_SESSION'] = $this->savedSuperSession;
        }
        if ($this->savedSuperglobals !== null) {
            App::superglobals($this->savedSuperglobals);
        }

        if (is_dir($this->savePath)) {
            foreach (glob($this->savePath . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->savePath);
        }
        parent::tearDown();
    }

    /** A writable openswoole_response double recording cookie() calls. */
    private function fakeResponse(bool $writable = true): object
    {
        return new class($writable) {
            /** @var array<int, array<int, mixed>> */
            public array $cookies = [];
            public function __construct(private bool $w) {}
            public function isWritable(): bool { return $this->w; }
            public function cookie(mixed ...$args): void { $this->cookies[] = $args; }
        };
    }

    public function testStartInitsDefaultParamsAndMakesSaveDir(): void
    {
        // session_params empty → defaults applied (save_path/name). Point
        // save_path at a not-yet-existing dir so the mkdir branch runs.
        $g = RequestContext::instance();
        $g->session_params = ['save_path' => $this->savePath, 'name' => 'PHPSESSID'];
        // Remove the keys to trip the !isset default-init guards.
        unset($g->session_params['save_path'], $g->session_params['name']);
        // But we still want the file tier to land in our temp dir, so set it
        // back after the default would have been applied is not possible; instead
        // assert the defaults are filled.
        $g->session_params = [];

        $this->assertTrue(zeal_session_start());
        $this->assertSame('/var/lib/php/sessions', $g->session_params['save_path']);
        $this->assertSame('PHPSESSID', $g->session_params['name']);
    }

    public function testStartCreatesMissingSaveDir(): void
    {
        $g = RequestContext::instance();
        $this->assertDirectoryDoesNotExist($this->savePath);
        $g->session_params = ['save_path' => $this->savePath, 'name' => 'PHPSESSID'];

        $this->assertTrue(zeal_session_start());
        $this->assertDirectoryExists($this->savePath);
    }

    public function testStartEmitsSetCookieForFirstTimeVisitor(): void
    {
        @mkdir($this->savePath, 0700, true);
        $g = RequestContext::instance();
        $g->session_params = ['save_path' => $this->savePath, 'name' => 'PHPSESSID'];
        $g->cookie = []; // no incoming session cookie
        $resp = $this->fakeResponse(true);
        $g->openswoole_response = $resp;

        $saved = ini_get('session.use_cookies');
        ini_set('session.use_cookies', '1');
        try {
            $this->assertTrue(zeal_session_start());
        } finally {
            ini_set('session.use_cookies', $saved === false ? '1' : $saved);
        }

        $this->assertNotEmpty($resp->cookies);
        $this->assertSame('PHPSESSID', $resp->cookies[0][0]);
    }

    public function testStartReadsFromSessionHandler(): void
    {
        @mkdir($this->savePath, 0700, true);
        $g = RequestContext::instance();
        $g->cookie = ['PHPSESSID' => 'handler-id'];
        zeal_session_id('handler-id');

        $handler = new class implements \SessionHandlerInterface {
            public function open(string $path, string $name): bool { return true; }
            public function close(): bool { return true; }
            public function read(string $id): string|false { return 'k|s:5:"value";'; }
            public function write(string $id, string $data): bool { return true; }
            public function destroy(string $id): bool { return true; }
            public function gc(int $maxlifetime): int|false { return 0; }
        };
        $g->session_params = [
            'save_path' => $this->savePath,
            'name'      => 'PHPSESSID',
            'handler'   => $handler,
        ];

        $this->assertTrue(zeal_session_start());
        // The handler's serialized payload decoded into the session store.
        $this->assertSame('value', $g->session['k'] ?? null);
        $this->assertSame('value', $GLOBALS['_SESSION']['k'] ?? null);
    }

    public function testStatusCoroutineModeBranch(): void
    {
        App::superglobals(false);
        $g = RequestContext::instance();
        $prop = new \ReflectionProperty(RequestContext::class, 'session');
        $prop->setValue($g, ['x' => 1]);
        $this->assertSame(PHP_SESSION_ACTIVE, zeal_session_status());
    }

    public function testEncodeCoroutineModeBranch(): void
    {
        App::superglobals(false);
        $g = RequestContext::instance();
        $prop = new \ReflectionProperty(RequestContext::class, 'session');
        $prop->setValue($g, ['co' => 'data']);
        $encoded = zeal_session_encode();
        $this->assertSame(['co' => 'data'], php_session_decode_to_array($encoded));
    }

    public function testUnsetEmptiesSuperglobalSession(): void
    {
        $g = RequestContext::instance();
        $g->session_params = ['name' => 'PHPSESSID', 'save_path' => $this->savePath];
        $g->cookie = ['PHPSESSID' => 'unset-id'];
        $GLOBALS['_SESSION'] = ['gone' => true];

        zeal_session_unset();

        // unset() empties (not removes) the superglobal in superglobals mode.
        $this->assertSame([], $GLOBALS['_SESSION']);
    }

    public function testAbortRestoresSuperglobalFromFile(): void
    {
        @mkdir($this->savePath, 0700, true);
        $g = RequestContext::instance();
        $g->session_params = ['name' => 'PHPSESSID', 'save_path' => $this->savePath];
        $g->cookie = ['PHPSESSID' => 'abort-cov-id'];
        zeal_session_id('abort-cov-id');
        file_put_contents($this->savePath . '/sess_abort-cov-id', serialize(['disk' => 'snapshot']));

        // In-memory mutation that abort must discard. Force the typed slot via
        // reflection so isset($g->session) is true (abort's guard) regardless
        // of any prior unset() in this class.
        (new \ReflectionProperty(RequestContext::class, 'session'))->setValue($g, ['memory' => 'dirty']);

        $this->assertTrue(zeal_session_abort());
        $this->assertSame(['disk' => 'snapshot'], $GLOBALS['_SESSION']);
    }

    public function testAbortResetsSuperglobalWhenNoFile(): void
    {
        @mkdir($this->savePath, 0700, true);
        $g = RequestContext::instance();
        $g->session_params = ['name' => 'PHPSESSID', 'save_path' => $this->savePath];
        $g->cookie = ['PHPSESSID' => 'abort-nofile-cov'];
        zeal_session_id('abort-nofile-cov');
        (new \ReflectionProperty(RequestContext::class, 'session'))->setValue($g, ['memory' => 'dirty']);
        $GLOBALS['_SESSION'] = ['memory' => 'dirty'];

        $this->assertTrue(zeal_session_abort());
        $this->assertSame([], $GLOBALS['_SESSION']);
    }
}
