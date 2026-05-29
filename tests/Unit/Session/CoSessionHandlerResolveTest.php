<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Session\CoSessionManager;
use ZealPHP\Session\Handler\FileSessionHandler;
use ZealPHP\Session\Handler\TableSessionHandler;

/**
 * Covers CoSessionManager::resolveHandler() — the private static factory that
 * maps App::$session_handler to a concrete SessionHandlerInterface
 * (added in the concurrent-session-merge PR).
 *
 * Mapping under test:
 *   - instance      → returned as-is
 *   - 'file'        → FileSessionHandler
 *   - 'table'/null  → TableSessionHandler (concurrent-safe default)
 *   - unknown       → null
 *
 * The 'redis' arm constructs a RedisSessionHandler which opens a live Redis
 * connection in its constructor, so it is integration territory, not unit.
 */
final class CoSessionHandlerResolveTest extends TestCase
{
    /** @var string|\SessionHandlerInterface|null */
    private $orig;

    protected function setUp(): void
    {
        $this->orig = App::$session_handler;
    }

    protected function tearDown(): void
    {
        App::$session_handler = $this->orig;
    }

    /**
     * @return mixed
     */
    private function resolve()
    {
        $m = new \ReflectionMethod(CoSessionManager::class, 'resolveHandler');
        $m->setAccessible(true);
        return $m->invoke(null);
    }

    public function testInstanceIsReturnedAsIs(): void
    {
        $handler = new FileSessionHandler();
        App::$session_handler = $handler;
        $this->assertSame($handler, $this->resolve());
    }

    public function testFileStringResolvesToFileHandler(): void
    {
        App::$session_handler = 'file';
        $this->assertInstanceOf(FileSessionHandler::class, $this->resolve());
    }

    public function testTableStringResolvesToTableHandler(): void
    {
        App::$session_handler = 'table';
        $this->assertInstanceOf(TableSessionHandler::class, $this->resolve());
    }

    public function testNullResolvesToTableHandlerDefault(): void
    {
        App::$session_handler = null;
        $this->assertInstanceOf(
            TableSessionHandler::class,
            $this->resolve(),
            'null is the concurrent-safe default → TableSessionHandler'
        );
    }

    public function testUnknownStringResolvesToNull(): void
    {
        App::$session_handler = 'definitely-not-a-handler';
        $this->assertNull($this->resolve());
    }
}
