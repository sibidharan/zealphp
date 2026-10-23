<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\Session\php_session_decode_to_array;
use function ZealPHP\Session\php_session_encode_from_array;
use function ZealPHP\Session\zeal_session_start;
use function ZealPHP\Session\zeal_session_write_close;

/**
 * Session-store fixes from the independent review, in default coroutine mode
 * (superglobals off, the request's session is $g->session).
 */
final class SessionReviewFixesTest extends TestCase
{
    private const SID = 'reviewfix0123456789abcdef0123456';

    private string $dir = '';
    private bool $savedSuperglobals = false;
    private string $savedSavePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSuperglobals = App::$superglobals;
        $this->savedSavePath = App::$session_save_path;
        App::superglobals(false);
        $this->dir = sys_get_temp_dir() . '/zp_review_sess_' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0700, true);
        $g = RequestContext::instance();
        $g->session = [];
        $g->session_params = ['save_path' => $this->dir, 'name' => 'PHPSESSID'];
        $g->cookie = ['PHPSESSID' => self::SID];
        $g->openswoole_response = null;
        $g->_session_started = false;
        $g->_session_persisted_hash = null;
        $g->session_loaded_keys = [];
        $g->session_loaded_digest = [];
    }

    protected function tearDown(): void
    {
        $g = RequestContext::instance();
        $g->session = [];
        $g->session_params = [];
        $g->cookie = [];
        $g->_session_started = false;
        $g->session_loaded_keys = [];
        $g->session_loaded_digest = [];
        App::$session_save_path = $this->savedSavePath;
        App::superglobals($this->savedSuperglobals);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        putenv('ZEALPHP_SESSION_DIR');
        parent::tearDown();
    }

    private function file(): string
    {
        return $this->dir . '/sess_' . self::SID;
    }

    /** A1: request A loaded the session; B emptied it (logout); A's close must not write its copy back. */
    public function testAnEmptiedSessionIsNotResurrectedByAnInFlightRequest(): void
    {
        file_put_contents($this->file(), php_session_encode_from_array(['user' => 'alice']));
        zeal_session_start();
        $this->assertSame('alice', RequestContext::instance()->session['user'] ?? null);

        file_put_contents($this->file(), '');   // request B logs the user out
        zeal_session_write_close();

        $this->assertSame('', (string) file_get_contents($this->file()), 'the logout was undone');
    }

    /** A2: a session KEY built from input must not be read back as the whole session. */
    public function testAFirstKeyThatLooksLikeASerializedArrayCannotReplaceTheSession(): void
    {
        $key = 'a:1:{s:4:"role";s:5:"admin";}';
        $encoded = php_session_encode_from_array([$key => 1, 'user' => 'bob']);
        $decoded = php_session_decode_to_array($encoded);
        $this->assertArrayNotHasKey('role', $decoded);
        $this->assertSame('bob', $decoded['user'] ?? null);
        $this->assertSame(1, $decoded[$key] ?? null);
    }

    public function testRealPhpSerializeDataStillDecodes(): void
    {
        $this->assertSame(['a' => 1, 'b' => 'x'], php_session_decode_to_array(serialize(['a' => 1, 'b' => 'x'])));
    }

    /** A7: the app's own session_start() after the manager's must not reload over this request's writes. */
    public function testASecondStartKeepsWhatTheRequestWrote(): void
    {
        file_put_contents($this->file(), php_session_encode_from_array(['user' => 'alice']));
        zeal_session_start();
        $g = RequestContext::instance();
        $s = $g->session;
        $s['csrf'] = 'token-issued-on-this-get';
        $g->session = $s;

        zeal_session_start();
        $this->assertSame('token-issued-on-this-get', $g->session['csrf'] ?? null);
    }

    /** A5: the default store is ZealPHP's own 0700 directory, not PHP's session.save_path. */
    public function testDefaultSessionDirIsPrivate(): void
    {
        $dir = $this->dir . '/own';
        putenv('ZEALPHP_SESSION_DIR=' . $dir);
        App::$session_save_path = '';
        $this->assertSame($dir, App::sessionSavePath());
        $this->assertDirectoryExists($dir);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));
        @rmdir($dir);
    }

    public function testNewSessionFilesAreNotWorldReadable(): void
    {
        RequestContext::instance()->cookie = [];
        zeal_session_start();
        $g = RequestContext::instance();
        $g->session = ['x' => 1];
        $sid = (string) $g->session_params['session_id'];
        zeal_session_write_close();
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/sess_' . $sid)), -4));
    }
}
