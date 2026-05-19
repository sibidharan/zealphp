<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\App;
use ZealPHP\RequestContext;

use function ZealPHP\Session\zeal_session_write_close;
use function ZealPHP\Session\zeal_session_destroy;
use function ZealPHP\Session\php_session_decode_to_array;

/**
 * Captured-write fake SessionHandlerInterface — records every call so
 * tests can assert behaviour without standing up Redis. Returning data
 * from read() drives the merge path.
 *
 * Promoted to a named class (rather than `new class implements …`) so
 * PHPStan can see the additional inspection properties (`$writes`,
 * `$writeLog`, `$destroys`) on a non-opaque type.
 */
final class FakeSessionHandlerSpy implements \SessionHandlerInterface
{
    public string $stored;
    public int $writes = 0;
    public int $destroys = 0;
    /** @var array<int, array{0: string, 1: string}> */
    public array $writeLog = [];

    public function __construct(string $initial = '')
    {
        $this->stored = $initial;
    }
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return $this->stored; }
    public function write(string $id, string $data): bool
    {
        $this->writes++;
        $this->writeLog[] = [$id, $data];
        $this->stored = $data;
        return true;
    }
    public function destroy(string $id): bool { $this->destroys++; return true; }
    public function gc(int $maxlifetime): int|false { return 0; }
}

/**
 * Unit tests for PR #14 — `zeal_session_write_close()` and
 * `zeal_session_destroy()` now delegate to `\SessionHandlerInterface` when
 * one is registered in `$g->session_params['handler']`, instead of always
 * hardcoding `file_put_contents` / `unlink`. PR #10 added the read-side
 * handler support; this PR closes the write/destroy gap so Redis (and
 * other custom handlers) actually persist and clean up sessions.
 *
 * The concurrent-merge guarantee is the other half: two requests both
 * doing read → modify → write back to the same session id must NOT lose
 * each other's top-level keys. We simulate that race by directly invoking
 * the handler's `write()` between our read and our write, then asserting
 * the resulting payload contains BOTH sets of keys.
 */
class SessionHandlerWriteTest extends TestCase
{
    protected function setUp(): void
    {
        // Each test starts from a clean RequestContext: coroutine-mode
        // semantics ($g->session is the canonical store), a writeable
        // OpenSwoole response stub so the session start path doesn't
        // short-circuit, and no inherited fragment state.
        App::superglobals(false);
        $g = RequestContext::instance();
        $g->session = [];
        $g->session_params = [
            'name'      => 'PHPSESSID',
            'save_path' => sys_get_temp_dir() . '/zealphp_handler_test_' . getmypid(),
        ];
        $g->cookie = ['PHPSESSID' => 'sess_test_id'];
    }

    public function testRegisteredHandlerReceivesWrite(): void
    {
        $h = new FakeSessionHandlerSpy();
        $g = RequestContext::instance();
        $g->session_params['handler'] = $h;
        $g->session = ['user_id' => 42];

        $this->assertTrue(zeal_session_write_close());
        $this->assertSame(1, $h->writes, 'handler->write should have been called exactly once');
        $this->assertSame('sess_test_id', $h->writeLog[0][0]);
        $payload = $h->writeLog[0][1];
        $decoded = php_session_decode_to_array($payload);
        $this->assertSame(['user_id' => 42], $decoded);
    }

    public function testNoHandlerStillWritesToFile(): void
    {
        $g = RequestContext::instance();
        // No handler registered → fall back to file.
        unset($g->session_params['handler']);
        $g->session = ['user_id' => 7];

        $savePath = $g->session_params['save_path'];
        assert(is_string($savePath));
        @mkdir($savePath, 0700, true);
        $expected = $savePath . '/sess_sess_test_id';
        @unlink($expected);

        $this->assertTrue(zeal_session_write_close());
        $this->assertFileExists($expected);
        $contents = file_get_contents($expected);
        $this->assertIsString($contents);
        $this->assertSame(['user_id' => 7], php_session_decode_to_array($contents));
        @unlink($expected);
    }

    public function testConcurrentMergePreservesDivergentTopLevelKeys(): void
    {
        // Simulate the race: request B already wrote {flash => 'saved'}
        // to the store; request A is about to write {cart => ['item-a']}
        // after having read the BEFORE-B state (so $g->session doesn't
        // know about flash). The merge in write_close must produce a
        // payload that contains BOTH keys.
        $existing = serialize(['flash' => 'saved']);
        $h = new FakeSessionHandlerSpy($existing);

        $g = RequestContext::instance();
        $g->session_params['handler'] = $h;
        $g->session = ['cart' => ['item-a']];

        $this->assertTrue(zeal_session_write_close());
        $this->assertSame(1, $h->writes);
        $stored = php_session_decode_to_array($h->stored);
        $this->assertArrayHasKey('flash', $stored,
            'flash from concurrent request B must survive the merge');
        $this->assertArrayHasKey('cart', $stored,
            'cart from this request A must be present');
        $this->assertSame('saved', $stored['flash']);
        $this->assertSame(['item-a'], $stored['cart']);
    }

    public function testMergePrefersThisRequestOnTopLevelKeyCollision(): void
    {
        // Both requests touched user_id. The version we're writing wins
        // at the top level — array_merge's documented shallow semantics.
        // (Nested-key conflicts are NOT resolved; see the implementation
        // comment in zeal_session_write_close.)
        $existing = serialize(['user_id' => 'OLD', 'untouched' => 'still-here']);
        $h = new FakeSessionHandlerSpy($existing);

        $g = RequestContext::instance();
        $g->session_params['handler'] = $h;
        $g->session = ['user_id' => 'NEW'];

        $this->assertTrue(zeal_session_write_close());
        $stored = php_session_decode_to_array($h->stored);
        $this->assertSame('NEW', $stored['user_id'],
            'this-request key value wins at top level');
        $this->assertSame('still-here', $stored['untouched'],
            'keys the concurrent request added survive');
    }

    public function testHandlerDestroyIsCalled(): void
    {
        $h = new FakeSessionHandlerSpy('user_id|i:7;');
        $g = RequestContext::instance();
        $g->session_params['handler'] = $h;

        $this->assertTrue(zeal_session_destroy());
        $this->assertSame(1, $h->destroys, 'handler->destroy should fire when registered');
    }

    public function testNoHandlerDestroyFallsBackToFileUnlink(): void
    {
        $g = RequestContext::instance();
        unset($g->session_params['handler']);

        $savePath = $g->session_params['save_path'];
        assert(is_string($savePath));
        @mkdir($savePath, 0700, true);
        $sessionFile = $savePath . '/sess_sess_test_id';
        file_put_contents($sessionFile, 'placeholder');
        $this->assertFileExists($sessionFile);

        $this->assertTrue(zeal_session_destroy());
        $this->assertFileDoesNotExist($sessionFile);
    }
}
