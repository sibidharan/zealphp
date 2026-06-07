<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Session\Handler\FileSessionHandler;
use ZealPHP\Session\Handler\TableSessionHandler;

/**
 * Covers App::resolveActiveSessionHandler() — the single, memoised factory that
 * maps App::$session_handler to a concrete SessionHandlerInterface. The managers
 * and the zeal_session_* read sites all consult it (#295; the logic moved here
 * from the former private CoSessionManager::resolveHandler()).
 *
 * Mapping under test:
 *   - instance      → returned as-is
 *   - 'file'        → FileSessionHandler
 *   - 'table'       → TableSessionHandler
 *   - null/unknown  → null (caller keeps the inline FILE default)
 *
 * #295 behaviour note: `null` resolves to `null`, NOT TableSessionHandler. The
 * pre-#295 `resolveHandler()` mapped null→Table, but that result was never
 * actually used (the manager registered it via a no-op native
 * `session_set_save_handler()`), so the observable default was always the inline
 * file path. Preserving null→file keeps a session-wiring fix from silently
 * changing the durability of apps that never configured a handler.
 *
 * The 'redis' arm constructs a RedisSessionHandler (live connection) → integration
 * territory, not unit.
 *
 * Uses the App::sessionHandler() setter (not direct $session_handler writes) so
 * the per-worker memoisation is reset before each resolution.
 */
final class CoSessionHandlerResolveTest extends TestCase
{
    /** @var string|\SessionHandlerInterface|null */
    private $orig;

    protected function setUp(): void
    {
        $this->orig = App::sessionHandler();
    }

    protected function tearDown(): void
    {
        // Setter resets the memoisation flag, so the next test/file re-resolves.
        App::sessionHandler($this->orig);
    }

    public function testInstanceIsReturnedAsIs(): void
    {
        $handler = new FileSessionHandler();
        App::sessionHandler($handler);
        $this->assertSame($handler, App::resolveActiveSessionHandler());
    }

    public function testFileStringResolvesToFileHandler(): void
    {
        App::sessionHandler('file');
        $this->assertInstanceOf(FileSessionHandler::class, App::resolveActiveSessionHandler());
    }

    public function testTableStringResolvesToTableHandler(): void
    {
        App::sessionHandler('table');
        $this->assertInstanceOf(TableSessionHandler::class, App::resolveActiveSessionHandler());
    }

    public function testNullResolvesToNullPreservingFileDefault(): void
    {
        App::sessionHandler(null);
        $this->assertNull(
            App::resolveActiveSessionHandler(),
            '#295: null preserves the inline FILE default — it is NOT promoted to TableSessionHandler'
        );
    }

    public function testUnknownStringResolvesToNull(): void
    {
        App::sessionHandler('definitely-not-a-handler');
        $this->assertNull(App::resolveActiveSessionHandler());
    }

    public function testResolutionIsMemoisedUntilSetterChangesIt(): void
    {
        App::sessionHandler('file');
        $first = App::resolveActiveSessionHandler();
        $this->assertSame($first, App::resolveActiveSessionHandler(), 'same instance — memoised');
        App::sessionHandler('table'); // setter resets the memo
        $this->assertInstanceOf(TableSessionHandler::class, App::resolveActiveSessionHandler());
    }
}
