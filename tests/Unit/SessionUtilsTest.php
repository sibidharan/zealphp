<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\Session\zeal_session_encode;
use function ZealPHP\Session\zeal_session_decode;
use function ZealPHP\Session\zeal_session_status;
use function ZealPHP\Session\zeal_session_name;
use function ZealPHP\Session\zeal_session_id;
use function ZealPHP\Session\zeal_session_regenerate_id;
use function ZealPHP\Session\zeal_session_set_cookie_params;
use function ZealPHP\Session\zeal_session_get_cookie_params;
use function ZealPHP\Session\zeal_session_abort;
use function ZealPHP\Session\zeal_session_unset;
use function ZealPHP\Session\zeal_session_save_path;
use function ZealPHP\Session\zeal_session_module_name;
use function ZealPHP\Session\zeal_session_cache_limiter;
use function ZealPHP\Session\zeal_session_cache_expire;
use function ZealPHP\Session\zeal_session_commit;
use function ZealPHP\Session\zeal_session_write_close;
use function ZealPHP\Session\zeal_session_create_id;
use function ZealPHP\Session\php_session_decode_to_array;

/**
 * Coverage for the zeal_session_* family in src/Session/utils.php not already
 * pinned by PhpSessionDecodeTest.php / SessionHandlerWriteTest.php.
 *
 * Runs in superglobals(true) mode where $_SESSION and $g->session are the two
 * storage slots the functions mirror. Each test fully owns + restores
 * $g->session, $g->session_params, $g->cookie and $_SESSION so nothing leaks
 * across the suite.
 */
class SessionUtilsTest extends TestCase
{
    private string $savePath;
    /** @var array<string,mixed>|null */
    private $savedSuperSession;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $this->savePath = sys_get_temp_dir() . '/zealphp_sess_unit_' . getmypid();
        @mkdir($this->savePath, 0700, true);

        $g = RequestContext::instance();
        // A prior superglobals(false) test (e.g. SessionHandlerWriteTest's
        // destroy/write_close) may have unset() the declared typed `session`
        // slot on the shared static singleton. Once unset, a plain
        // `$g->session = []` in superglobals(true) mode routes through __set
        // to $_SESSION and leaves the typed slot uninitialized — which makes
        // isset($g->session) false and trips zeal_session_abort's guard.
        // Re-initialize the typed slot directly via reflection so the
        // function's isset() contract holds regardless of suite ordering.
        $prop = new \ReflectionProperty(RequestContext::class, 'session');
        $prop->setValue($g, []);
        $g->session = [];
        $g->session_params = [
            'name'      => 'PHPSESSID',
            'save_path' => $this->savePath,
        ];
        $g->cookie = [];
        $this->savedSuperSession = $GLOBALS['_SESSION'] ?? null;
        $GLOBALS['_SESSION'] = [];
    }

    protected function tearDown(): void
    {
        // Restore $_SESSION superglobal.
        if ($this->savedSuperSession === null) {
            unset($GLOBALS['_SESSION']);
        } else {
            $GLOBALS['_SESSION'] = $this->savedSuperSession;
        }
        // Reset RequestContext slots.
        $g = RequestContext::instance();
        $g->session = [];
        $g->session_params = [];
        $g->cookie = [];
        $g->cache_limiter = null;
        $g->cache_expire = null;
        $g->session_module_name = null;
        // Clean temp session files.
        if (is_dir($this->savePath)) {
            foreach (glob($this->savePath . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->savePath);
        }
    }

    // ── encode / decode round-trip ───────────────────────────────

    public function testEncodeReflectsSuperglobalSession(): void
    {
        $GLOBALS['_SESSION'] = ['user_id' => 42, 'name' => 'alice'];
        $encoded = zeal_session_encode();
        $this->assertSame(['user_id' => 42, 'name' => 'alice'], php_session_decode_to_array($encoded));
    }

    public function testDecodePopulatesBothStores(): void
    {
        $payload = serialize(['k' => 'v', 'n' => 99]);
        $this->assertTrue(zeal_session_decode($payload));
        $g = RequestContext::instance();
        $this->assertSame(['k' => 'v', 'n' => 99], $g->session);
        $this->assertSame(['k' => 'v', 'n' => 99], $GLOBALS['_SESSION']);
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        $GLOBALS['_SESSION'] = ['a' => 1, 'b' => ['nested' => true]];
        $encoded = zeal_session_encode();
        $GLOBALS['_SESSION'] = [];
        $this->assertTrue(zeal_session_decode($encoded));
        $this->assertSame(['a' => 1, 'b' => ['nested' => true]], $GLOBALS['_SESSION']);
    }

    public function testDecodeEmptyStringReturnsFalse(): void
    {
        $this->assertFalse(zeal_session_decode(''));
    }

    public function testDecodeMalformedReturnsFalse(): void
    {
        $this->assertFalse(zeal_session_decode('not-valid-serialized'));
    }

    public function testDecodeNonArrayPayloadReturnsFalse(): void
    {
        // serialize() of a scalar is valid but not an array → false.
        $this->assertFalse(zeal_session_decode(serialize('just a string')));
    }

    public function testDecodeDropsNonStringKeys(): void
    {
        $this->assertTrue(zeal_session_decode(serialize([0 => 'x', 'named' => 'y'])));
        $this->assertSame(['named' => 'y'], $GLOBALS['_SESSION']);
    }

    // ── status ───────────────────────────────────────────────────

    public function testStatusActiveWhenSuperSessionPresent(): void
    {
        $GLOBALS['_SESSION'] = [];
        $this->assertSame(PHP_SESSION_ACTIVE, zeal_session_status());
    }

    public function testStatusNoneWhenSuperSessionUnset(): void
    {
        unset($GLOBALS['_SESSION']);
        $this->assertSame(PHP_SESSION_NONE, zeal_session_status());
    }

    // ── name ─────────────────────────────────────────────────────

    public function testNameGetDefault(): void
    {
        $g = RequestContext::instance();
        unset($g->session_params['name']);
        $this->assertSame('PHPSESSID', zeal_session_name());
    }

    public function testNameSet(): void
    {
        $this->assertSame('MYSESS', zeal_session_name('MYSESS'));
        $this->assertSame('MYSESS', zeal_session_name());
    }

    // ── id ───────────────────────────────────────────────────────

    public function testIdGeneratedWhenNoCookie(): void
    {
        $g = RequestContext::instance();
        $g->cookie = [];
        $id = zeal_session_id();
        $this->assertIsString($id);
        $this->assertNotSame('', $id);
        // Generated id is stashed into the cookie store.
        $this->assertSame($id, $g->cookie['PHPSESSID']);
    }

    public function testIdReadFromExistingCookie(): void
    {
        $g = RequestContext::instance();
        $g->cookie['PHPSESSID'] = 'preexisting-id';
        $this->assertSame('preexisting-id', zeal_session_id());
    }

    public function testIdExplicitSet(): void
    {
        $g = RequestContext::instance();
        $this->assertSame('forced-id', zeal_session_id('forced-id'));
        $this->assertSame('forced-id', $g->cookie['PHPSESSID']);
    }

    public function testCreateIdProducesString(): void
    {
        $id = zeal_session_create_id();
        $this->assertIsString($id);
        $this->assertNotSame('', $id);
    }

    // ── regenerate_id ────────────────────────────────────────────

    public function testRegenerateIdChangesIdAndMovesFile(): void
    {
        $g = RequestContext::instance();
        $g->cookie['PHPSESSID'] = 'old-sess-id';
        $oldFile = $this->savePath . '/sess_old-sess-id';
        file_put_contents($oldFile, serialize(['carried' => 'over']));

        $this->assertTrue(zeal_session_regenerate_id(false));
        $newId = $g->cookie['PHPSESSID'];
        $this->assertNotSame('old-sess-id', $newId);
        $this->assertFileDoesNotExist($oldFile);
        $newFile = $this->savePath . '/sess_' . $newId;
        $this->assertFileExists($newFile);
        $this->assertSame(['carried' => 'over'], php_session_decode_to_array((string)file_get_contents($newFile)));
    }

    public function testRegenerateIdDeleteOld(): void
    {
        $g = RequestContext::instance();
        $g->cookie['PHPSESSID'] = 'del-old-id';
        $oldFile = $this->savePath . '/sess_del-old-id';
        file_put_contents($oldFile, serialize(['x' => 1]));

        $this->assertTrue(zeal_session_regenerate_id(true));
        $this->assertFileDoesNotExist($oldFile);
        $newId = $g->cookie['PHPSESSID'];
        $this->assertFileDoesNotExist($this->savePath . '/sess_' . $newId);
    }

    public function testRegenerateIdWithNoExistingFile(): void
    {
        $g = RequestContext::instance();
        $g->cookie['PHPSESSID'] = 'no-file-id';
        $this->assertTrue(zeal_session_regenerate_id(false));
        $this->assertNotSame('no-file-id', $g->cookie['PHPSESSID']);
    }

    // ── cookie params ────────────────────────────────────────────

    public function testGetCookieParamsDefaultsWhenUnset(): void
    {
        $g = RequestContext::instance();
        unset($g->session_params['cookie_params']);
        $params = zeal_session_get_cookie_params();
        $this->assertSame(0, $params['lifetime']);
        $this->assertSame('/', $params['path']);
        $this->assertFalse($params['secure']);
        $this->assertTrue($params['httponly']);
    }

    public function testSetCookieParamsPositional(): void
    {
        zeal_session_set_cookie_params(3600, '/app', 'example.com', true, true);
        $params = zeal_session_get_cookie_params();
        $this->assertSame(3600, $params['lifetime']);
        $this->assertSame('/app', $params['path']);
        $this->assertSame('example.com', $params['domain']);
        $this->assertTrue($params['secure']);
        $this->assertTrue($params['httponly']);
    }

    public function testSetCookieParamsOptionsArray(): void
    {
        zeal_session_set_cookie_params([
            'lifetime' => 120,
            'path'     => '/x',
            'samesite' => 'Strict',
        ]);
        $params = zeal_session_get_cookie_params();
        $this->assertSame(120, $params['lifetime']);
        $this->assertSame('/x', $params['path']);
        $this->assertSame('Strict', $params['samesite']);
        // Unspecified keys fall back to merge defaults.
        $this->assertSame('', $params['domain']);
    }

    // ── abort ────────────────────────────────────────────────────
    //
    // zeal_session_abort()'s restore-from-disk path is guarded by
    // isset($g->session) on the DECLARED typed slot. In superglobals(true)
    // operation that slot is intentionally left uninitialized (it aliases
    // $_SESSION via __get/__set), so the guard is false and abort is a no-op
    // — which is the real production behaviour for that mode. We therefore
    // exercise the restore logic in coroutine mode (superglobals(false)),
    // where the typed slot IS the canonical store and the guard fires.
    // These two tests own the mode switch and restore it.

    public function testAbortRestoresFromFile(): void
    {
        App::superglobals(false);
        $g = RequestContext::instance();
        // coroutine-mode instance() may be the static singleton in unit
        // context; ensure a clean, initialized typed session slot.
        $prop = new \ReflectionProperty(RequestContext::class, 'session');
        $prop->setValue($g, []);
        $g->session_params = ['name' => 'PHPSESSID', 'save_path' => $this->savePath];
        $g->cookie = ['PHPSESSID' => 'abort-id'];
        // Pin the session id so zeal_session_abort() reads OUR file, not a
        // stale id leaked from an earlier test in the full-suite ordering.
        zeal_session_id('abort-id');

        $sessionFile = $this->savePath . '/sess_abort-id';
        file_put_contents($sessionFile, serialize(['persisted' => 'disk-value']));
        // In-memory diverges from disk.
        $g->session = ['persisted' => 'memory-value', 'extra' => 'dropped'];

        $this->assertTrue(zeal_session_abort());
        // Abort discards in-memory changes, reloading the disk snapshot.
        $this->assertSame(['persisted' => 'disk-value'], $g->session);

        App::superglobals(true);
    }

    public function testAbortWithNoFileResetsToEmpty(): void
    {
        App::superglobals(false);
        $g = RequestContext::instance();
        $prop = new \ReflectionProperty(RequestContext::class, 'session');
        $prop->setValue($g, []);
        $g->session_params = ['name' => 'PHPSESSID', 'save_path' => $this->savePath];
        $g->cookie = ['PHPSESSID' => 'abort-nofile-id'];
        zeal_session_id('abort-nofile-id');
        $g->session = ['will' => 'be-cleared'];

        $this->assertTrue(zeal_session_abort());
        $this->assertSame([], $g->session);

        App::superglobals(true);
    }

    // ── unset ────────────────────────────────────────────────────

    public function testUnsetClearsBothStores(): void
    {
        $g = RequestContext::instance();
        $g->session = ['a' => 1];
        $GLOBALS['_SESSION'] = ['a' => 1];
        zeal_session_unset();
        $this->assertSame([], $g->session);
        $this->assertSame([], $GLOBALS['_SESSION']);
    }

    // ── save_path / module_name / cache helpers ──────────────────

    public function testSavePathGetSet(): void
    {
        $this->assertSame($this->savePath, zeal_session_save_path());
        $this->assertSame('/custom/path', zeal_session_save_path('/custom/path'));
        $this->assertSame('/custom/path', zeal_session_save_path());
    }

    public function testSavePathDefaultWhenUnset(): void
    {
        $g = RequestContext::instance();
        unset($g->session_params['save_path']);
        $this->assertSame('/var/lib/php/sessions', zeal_session_save_path());
    }

    public function testModuleNameGetSet(): void
    {
        $this->assertSame('files', zeal_session_module_name());
        $this->assertSame('redis', zeal_session_module_name('redis'));
        $this->assertSame('redis', zeal_session_module_name());
    }

    public function testCacheLimiterGetSet(): void
    {
        $this->assertSame('nocache', zeal_session_cache_limiter());
        $this->assertSame('public', zeal_session_cache_limiter('public'));
        $this->assertSame('public', zeal_session_cache_limiter());
    }

    public function testCacheExpireGetSet(): void
    {
        $this->assertSame(180, zeal_session_cache_expire());
        $this->assertSame(60, zeal_session_cache_expire(60));
        $this->assertSame(60, zeal_session_cache_expire());
    }

    // ── commit (delegates to write_close) ────────────────────────

    public function testCommitWritesSuperSessionToFile(): void
    {
        $g = RequestContext::instance();
        $g->cookie['PHPSESSID'] = 'commit-id';
        $GLOBALS['_SESSION'] = ['committed' => 'yes'];

        $this->assertTrue(zeal_session_commit());
        $file = $this->savePath . '/sess_commit-id';
        $this->assertFileExists($file);
        $this->assertSame(['committed' => 'yes'], php_session_decode_to_array((string)file_get_contents($file)));
    }

    // ── #2: write_close idempotency under the double-close lifecycle ──────

    public function testWriteCloseIsIdempotentInMode4(): void
    {
        // ext-zealphp #2: under coroutine-isolated superglobals (Mode 4) the
        // framework runs a DOUBLE close — a handler may call session_write_close()
        // directly AND the manager's `finally` calls it again. The second close
        // must not wipe the session (the old code reset the store through the
        // $_SESSION ref and then re-ran the deletion loop, alternating the data
        // present/absent across requests). This pins the fix:
        //   (1) write_close resets _session_started so the manager's gated finally
        //       skips the second close, and (2) it resets session_loaded_keys so a
        //       stale deletion loop can never fire, and (3) a re-entrant close does
        //       not wipe the file.
        $saved = App::$coroutine_isolated_superglobals;
        App::$coroutine_isolated_superglobals = true;
        try {
            $g = RequestContext::instance();
            $sid = 'mode4-idem';
            $g->cookie['PHPSESSID'] = $sid;
            $g->session_params['session_id'] = $sid;
            $g->session = ['counter' => 5];
            $g->session_loaded_keys = ['counter'];
            $g->_session_started = true;

            $this->assertTrue(zeal_session_write_close());
            $file = $this->savePath . '/sess_' . $sid;
            $this->assertSame(['counter' => 5], php_session_decode_to_array((string)file_get_contents($file)));
            $this->assertFalse($g->_session_started, 'write_close must reset _session_started so the manager skips the 2nd close');
            $this->assertSame([], $g->session_loaded_keys, 'loaded-keys snapshot reset so a stale deletion loop cannot wipe');

            // The re-entrant second close (the failure path) must NOT wipe the file.
            zeal_session_write_close();
            $this->assertSame(
                ['counter' => 5],
                php_session_decode_to_array((string)file_get_contents($file)),
                'second close must not wipe the session (#2)'
            );
        } finally {
            App::$coroutine_isolated_superglobals = $saved;
            $g = RequestContext::instance();
            $g->_session_started = false;
        }
    }
}
