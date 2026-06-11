<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\Session\zeal_session_start;
use function ZealPHP\Session\zeal_session_write_close;

/**
 * #379 — session mutations after request 1 were silently lost in
 * coroutine-legacy (Mode 4). zeal_session_start() used to bind
 * `$_SESSION = &$g->session`; ext-zealphp's per-coroutine superglobal restore
 * severed that reference on the first post-bind yield, so user writes landed
 * in the live `$GLOBALS['_SESSION']` while write_close() persisted the STALE
 * `$g->session` load-time copy — the phpMyAdmin CSRF login loop /
 * TinyFileManager lost-login / "session file frozen at request 1" class.
 *
 * Contract pinned here: in EVERY superglobals mode the canonical store is the
 * live `$GLOBALS['_SESSION']` — the start path populates it and DETACHES the
 * `$g->session` typed slot (the same v0.2.30 slot-detach alias design as the
 * other six superglobals, riding the v0.4.8 __get/__set/__isset proxy), and
 * write_close() persists the global. Pure coroutine mode keeps the typed slot
 * as the canonical store.
 */
class SessionLiveStoreTest extends TestCase
{
    private string $savePath = '';
    private bool $savedSg = false;
    private bool $savedIso = false;
    private bool $hadSession = false;
    /** @var mixed */
    private $savedSession = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        $this->savedSg = App::$superglobals;
        $this->savedIso = App::$coroutine_isolated_superglobals;
        $this->hadSession = array_key_exists('_SESSION', $GLOBALS);
        $this->savedSession = $GLOBALS['_SESSION'] ?? null;
        unset($GLOBALS['_SESSION']);

        $this->savePath = sys_get_temp_dir() . '/zealphp_379_' . bin2hex(random_bytes(6));
        @mkdir($this->savePath, 0777, true);

        $g = RequestContext::instance();
        $g->_session_started = null;
        $g->session_params = ['save_path' => $this->savePath];
        $g->session_loaded_keys = [];
        $g->cookie = [];
        $GLOBALS['_COOKIE'] = [];
    }

    protected function tearDown(): void
    {
        App::superglobals($this->savedSg);
        App::$coroutine_isolated_superglobals = $this->savedIso;
        $g = RequestContext::instance();
        $g->_session_started = null;
        $g->session_params = [];
        $g->session_loaded_keys = [];
        $g->session = [];                    // re-initialise the typed slot for other tests
        $g->cookie = [];
        if ($this->hadSession) {
            $GLOBALS['_SESSION'] = $this->savedSession;
        } else {
            unset($GLOBALS['_SESSION']);
        }
        foreach (glob($this->savePath . '/sess_*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->savePath);
        parent::tearDown();
    }

    private function sessionFile(): string
    {
        $sid = RequestContext::instance()->session_params['session_id'] ?? '';
        $this->assertIsString($sid);
        $this->assertNotSame('', $sid, 'start must mint a session id');
        return $this->savePath . '/sess_' . $sid;
    }

    // ── Mode 4 (coroutine-legacy): the bug class ─────────────────────

    public function testMode4StartDetachesSlotAndPopulatesGlobal(): void
    {
        App::superglobals(true);
        App::$coroutine_isolated_superglobals = true;

        zeal_session_start();

        $this->assertIsArray($GLOBALS['_SESSION'] ?? null,
            'start must populate the live $GLOBALS[_SESSION]');
        $prop = new \ReflectionProperty(RequestContext::class, 'session');
        $this->assertFalse($prop->isInitialized(RequestContext::instance()),
            '#379: the typed $g->session slot must be DETACHED so it proxies $_SESSION');
        // The proxy is the SAME store: a write through the global is visible via $g.
        $GLOBALS['_SESSION']['probe'] = 'via-global';
        $this->assertSame('via-global', RequestContext::instance()->session['probe'] ?? null);
    }

    public function testMode4WriteClosePersistsLiveGlobalMutations(): void
    {
        App::superglobals(true);
        App::$coroutine_isolated_superglobals = true;

        zeal_session_start();
        // The post-severance shape that used to be LOST: the mutation exists
        // ONLY in the live $GLOBALS['_SESSION'] (request-2+ user write).
        $GLOBALS['_SESSION']['login'] = 'ok';
        $GLOBALS['_SESSION']['n'] = 2;
        $file = $this->sessionFile();

        zeal_session_write_close();

        $persisted = (string)@file_get_contents($file);
        $this->assertStringContainsString('login|s:2:"ok"', $persisted,
            '#379: write_close must persist the LIVE $GLOBALS[_SESSION], not a stale copy');
        $this->assertStringContainsString('n|i:2', $persisted);
    }

    // ── Mixed mode (superglobals, sequential) — unchanged contract ──

    public function testMixedModeWriteClosePersistsGlobals(): void
    {
        App::superglobals(true);
        App::$coroutine_isolated_superglobals = false;

        zeal_session_start();
        $GLOBALS['_SESSION']['who'] = 'mixed';
        $file = $this->sessionFile();

        zeal_session_write_close();

        $this->assertStringContainsString('who|s:5:"mixed"', (string)@file_get_contents($file));
    }

    // ── Pure coroutine mode — typed slot stays canonical ─────────────

    public function testCoroutineModeStillPersistsTypedSlot(): void
    {
        App::superglobals(false);
        App::$coroutine_isolated_superglobals = false;

        zeal_session_start();
        $g = RequestContext::instance();
        $g->session['x'] = 'y';
        $file = $this->sessionFile();

        zeal_session_write_close();

        $this->assertStringContainsString('x|s:1:"y"', (string)@file_get_contents($file));
    }
}
