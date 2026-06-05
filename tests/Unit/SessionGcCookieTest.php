<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\RequestContext;
use function ZealPHP\Session\zeal_session_gc;
use function ZealPHP\Session\zeal_session_get_cookie_params;

/**
 * Architecture-review hardening: the deterministic session GC sweep (replacing
 * PHP's probabilistic GC that ZealPHP had dropped — unbounded sess_* growth +
 * indefinitely-replayable sessions) and the SameSite cookie-param invariants.
 */
final class SessionGcCookieTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/zealgc_' . getmypid() . '_' . substr(md5(uniqid('', true)), 0, 8);
        @mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        $g = RequestContext::instance();
        $g->session_params = [];
    }

    // ── zeal_session_gc — default file storage ────────────────────

    public function testGcRemovesExpiredFilesKeepsFreshAndNonSession(): void
    {
        $g = RequestContext::instance();
        $g->session_params = ['save_path' => $this->dir];

        $old = $this->dir . '/sess_oldid';
        file_put_contents($old, 'x');
        touch($old, time() - 100000);            // stale

        $fresh = $this->dir . '/sess_freshid';
        file_put_contents($fresh, 'x');           // mtime ~now

        $other = $this->dir . '/other.txt';       // not a session file
        file_put_contents($other, 'x');
        touch($other, time() - 100000);

        $removed = zeal_session_gc(1440);

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
        $this->assertFileExists($other);          // non-sess_* untouched
        $this->assertSame(1, $removed);
    }

    public function testGcMissingDirReturnsZero(): void
    {
        $g = RequestContext::instance();
        $g->session_params = ['save_path' => $this->dir . '/does-not-exist'];
        $this->assertSame(0, zeal_session_gc(1440));
    }

    public function testGcDelegatesToRegisteredHandler(): void
    {
        $g = RequestContext::instance();
        $handler = new class implements \SessionHandlerInterface {
            public int $calls = 0;
            public function close(): bool
            {
                return true;
            }
            public function destroy(string $id): bool
            {
                return true;
            }
            public function gc(int $max): int
            {
                $this->calls++;
                return 7;
            }
            public function open(string $path, string $name): bool
            {
                return true;
            }
            public function read(string $id): string
            {
                return '';
            }
            public function write(string $id, string $data): bool
            {
                return true;
            }
        };
        $g->session_params = ['handler' => $handler, 'save_path' => $this->dir];

        // Handler owns GC — the file sweep is bypassed entirely.
        $this->assertSame(7, zeal_session_gc(1440));
        $this->assertSame(1, $handler->calls);
    }

    // ── SameSite cookie-param invariants ──────────────────────────

    public function testSameSiteNoneForcesSecure(): void
    {
        $g = RequestContext::instance();
        $g->session_params = ['cookie_params' => [
            'lifetime' => 0, 'path' => '/', 'domain' => '',
            'secure' => false, 'httponly' => true, 'samesite' => 'None',
        ]];
        $p = zeal_session_get_cookie_params();
        $this->assertTrue($p['secure']);            // None ⇒ Secure enforced
        $this->assertSame('None', $p['samesite'] ?? null);
    }

    public function testLaxKeepsConfiguredSecureFlag(): void
    {
        $g = RequestContext::instance();
        $g->session_params = ['cookie_params' => [
            'lifetime' => 0, 'path' => '/', 'domain' => '',
            'secure' => false, 'httponly' => true, 'samesite' => 'Lax',
        ]];
        $p = zeal_session_get_cookie_params();
        $this->assertFalse($p['secure']);
        $this->assertSame('Lax', $p['samesite'] ?? null);
    }

    public function testDefaultsIncludeSameSiteLax(): void
    {
        $g = RequestContext::instance();
        $g->session_params = [];
        $p = zeal_session_get_cookie_params();
        $this->assertSame('Lax', $p['samesite'] ?? null);
        $this->assertSame('/', $p['path']);
        $this->assertTrue($p['httponly']);
    }
}
