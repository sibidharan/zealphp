<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\Session\zeal_session_start;
use function ZealPHP\Session\zeal_session_write_close;
use function ZealPHP\Session\zeal_session_set_cookie_params;
use function ZealPHP\Session\zeal_session_abort;

/**
 * Guruprasanth session-cluster hardening — #369 #372 #373 #374 #375.
 * Pure unit tests against the real session functions (ext-zealphp is the
 * override engine; no uopz), each isolated to a temp save_path.
 */
final class SessionClusterHardeningTest extends TestCase
{
    private string $savePath = '';
    private bool $savedSg = false;
    private bool $savedIso = false;
    private string $savedAppSavePath = '';
    private bool $hadSession = false;
    /** @var mixed */
    private $savedSession = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        $this->savedSg = App::$superglobals;
        $this->savedIso = App::$coroutine_isolated_superglobals;
        $this->savedAppSavePath = App::$session_save_path;
        $this->hadSession = array_key_exists('_SESSION', $GLOBALS);
        $this->savedSession = $GLOBALS['_SESSION'] ?? null;
        unset($GLOBALS['_SESSION']);

        $this->savePath = sys_get_temp_dir() . '/zealphp_clu_' . bin2hex(random_bytes(6));
        @mkdir($this->savePath, 0700, true);

        App::superglobals(true);
        App::$coroutine_isolated_superglobals = false; // mixed: $GLOBALS['_SESSION'] store
        $g = RequestContext::instance();
        $g->_session_started = null;
        $g->session_params = [];
        $g->session_loaded_keys = [];
        $g->cookie = ['PHPSESSID' => 'clu-sid'];
        $GLOBALS['_COOKIE'] = ['PHPSESSID' => 'clu-sid'];
        $g->memo = [];
    }

    protected function tearDown(): void
    {
        App::superglobals($this->savedSg);
        App::$coroutine_isolated_superglobals = $this->savedIso;
        App::$session_save_path = $this->savedAppSavePath;
        App::sessionHandler(null);
        $g = RequestContext::instance();
        $g->_session_started = null;
        $g->session_params = [];
        $g->session_loaded_keys = [];
        $g->cookie = [];
        $g->memo = [];
        if ($this->hadSession) { $GLOBALS['_SESSION'] = $this->savedSession; }
        else { unset($GLOBALS['_SESSION']); }
        foreach (glob($this->savePath . '/sess_*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->savePath);
        parent::tearDown();
    }

    // ── #373: App::sessionSavePath() must drive the file backend ────
    public function testSavePathSeededFromAppSetter(): void
    {
        App::$session_save_path = $this->savePath;            // App::sessionSavePath() writes this
        RequestContext::instance()->session_params = [];      // nothing pre-seeded
        zeal_session_start();
        $this->assertSame(
            $this->savePath,
            RequestContext::instance()->session_params['save_path'] ?? null,
            '#373: save_path must come from App::$session_save_path, not the hardcoded literal'
        );
    }

    // ── #374: framework keys never enter the user session/store ─────
    public function testFrameworkKeysNotPersistedToStore(): void
    {
        App::$session_save_path = $this->savePath;
        $g = RequestContext::instance();
        $g->session_params = ['save_path' => $this->savePath, 'session_id' => 'clu-sid'];
        $GLOBALS['_SESSION'] = ['app_key' => 'app_val'];
        $g->session_loaded_keys = [];
        $g->_session_started = true;

        zeal_session_write_close();

        $file = $this->savePath . '/sess_clu-sid';
        $persisted = (string)@file_get_contents($file);
        $this->assertStringContainsString('app_key', $persisted);
        $this->assertStringNotContainsString('__start_time', $persisted,
            '#374: framework bookkeeping must not be persisted into the user store');
        $this->assertStringNotContainsString('UNIQUE_REQUEST_ID', $persisted);
    }

    public function testLegacyFrameworkKeysStrippedOnLoad(): void
    {
        // A store polluted by a pre-fix release must not resurrect the keys.
        App::$session_save_path = $this->savePath;
        file_put_contents(
            $this->savePath . '/sess_clu-sid',
            '__start_time|d:123.4;UNIQUE_REQUEST_ID|s:3:"old";app|s:2:"ok";'
        );
        RequestContext::instance()->session_params = ['save_path' => $this->savePath];
        zeal_session_start();
        $loaded = $GLOBALS['_SESSION'] ?? [];
        $this->assertArrayHasKey('app', $loaded);
        $this->assertArrayNotHasKey('__start_time', $loaded,
            '#374: legacy framework keys in a polluted store are stripped on load');
        $this->assertArrayNotHasKey('UNIQUE_REQUEST_ID', $loaded);
    }

    // ── #375: positional cookie params keep current values ──────────
    public function testCookieParamsPositionalKeepsSecurityFlags(): void
    {
        $g = RequestContext::instance();
        $g->session_params['cookie_params'] = [
            'lifetime' => 0, 'path' => '/', 'domain' => '',
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ];
        zeal_session_set_cookie_params(3600);                 // lifetime only
        $cp = $g->session_params['cookie_params'];
        $this->assertSame(3600, $cp['lifetime']);
        $this->assertTrue($cp['httponly'], '#375: omitted httponly must keep the configured true');
        $this->assertTrue($cp['secure'], '#375: omitted secure must keep the configured true');
        $this->assertSame('Lax', $cp['samesite'], 'samesite preserved');
    }

    public function testCookieParamsExplicitFalseStillApplies(): void
    {
        $g = RequestContext::instance();
        $g->session_params['cookie_params'] = ['secure' => true, 'httponly' => true];
        zeal_session_set_cookie_params(0, '/', '', false, false);
        $cp = $g->session_params['cookie_params'];
        $this->assertFalse($cp['secure'], 'an explicit false must still turn the flag off');
        $this->assertFalse($cp['httponly']);
    }

    // ── #372: session_abort() restores, never empties+persists ──────
    public function testAbortRestoresLoadedStateNotEmpty(): void
    {
        App::$session_save_path = $this->savePath;
        $sid = 'clu-sid';
        // Native-format session file (key|serialized;) with prior data.
        file_put_contents(
            $this->savePath . '/sess_' . $sid,
            'kept|s:5:"value";count|i:7;'
        );
        $g = RequestContext::instance();
        $g->session_params = ['save_path' => $this->savePath, 'session_id' => $sid];
        $g->session = ['kept' => 'value', 'count' => 7];
        $GLOBALS['_SESSION'] = ['kept' => 'value', 'count' => 7];
        // In-request mutation that abort must DISCARD.
        $GLOBALS['_SESSION']['count'] = 999;

        $this->assertTrue(zeal_session_abort());

        $restored = $GLOBALS['_SESSION'] ?? [];
        $this->assertSame('value', $restored['kept'] ?? null,
            '#372: abort must restore the loaded state (native decoder), not empty it');
        $this->assertSame(7, $restored['count'] ?? null,
            '#372: the in-request mutation is discarded, the stored value restored');
        // And the on-disk file is untouched (abort does not write).
        $this->assertStringContainsString('count|i:7', (string)file_get_contents($this->savePath . '/sess_' . $sid));
    }
}
