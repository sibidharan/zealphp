<?php
namespace ZealPHP\Tests\Unit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Middleware\IniIsolationMiddleware;
use ZealPHP\Tests\TestCase;

/**
 * IniIsolationMiddleware — verifies that ini_set() changes made inside a
 * request handler are reverted when the request completes, so they don't
 * leak into the next request on the same long-running worker.
 */
class IniIsolationMiddlewareTest extends TestCase
{
    public function testRestoresChangedKeyAfterHandlerCompletes(): void
    {
        $originalTz = ini_get('date.timezone');
        $mw = new IniIsolationMiddleware(['date.timezone']);

        $handler = $this->handlerThat(function () {
            ini_set('date.timezone', 'Asia/Tokyo');
            return $this->mockResponse();
        });

        $mw->process($this->mockRequest(), $handler);

        $this->assertSame($originalTz, ini_get('date.timezone'), 'ini value must be restored after handler returns');
    }

    public function testRestoresValueEvenIfHandlerThrows(): void
    {
        $originalTz = ini_get('date.timezone');
        $mw = new IniIsolationMiddleware(['date.timezone']);

        $handler = $this->handlerThat(function () {
            ini_set('date.timezone', 'America/Adak');
            throw new \RuntimeException('handler error');
        });

        try {
            $mw->process($this->mockRequest(), $handler);
            $this->fail('exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('handler error', $e->getMessage());
        }

        $this->assertSame($originalTz, ini_get('date.timezone'), 'ini value must be restored even when handler throws');
    }

    public function testDoesNotRestoreUnchangedKeys(): void
    {
        // If the handler didn't touch a key, we shouldn't ini_set() it back to
        // the same value — that's wasteful and could trigger PHP_INI_USER
        // restrictions on keys the handler had no reason to touch.
        $callCount = 0;
        $mw = new IniIsolationMiddleware(['precision', 'serialize_precision']);

        $handler = $this->handlerThat(function () use (&$callCount) {
            $callCount++;
            return $this->mockResponse();
        });

        $beforePrecision = ini_get('precision');
        $beforeSerialize = ini_get('serialize_precision');

        $mw->process($this->mockRequest(), $handler);

        $this->assertSame(1, $callCount);
        $this->assertSame($beforePrecision, ini_get('precision'));
        $this->assertSame($beforeSerialize, ini_get('serialize_precision'));
    }

    public function testIsolatesMultipleKeysInOneRequest(): void
    {
        $originalErr = ini_get('error_reporting');
        $originalPrec = ini_get('precision');

        $mw = new IniIsolationMiddleware(['error_reporting', 'precision']);

        $handler = $this->handlerThat(function () {
            ini_set('error_reporting', (string) E_ERROR);
            ini_set('precision', '8');
            return $this->mockResponse();
        });

        $mw->process($this->mockRequest(), $handler);

        $this->assertSame($originalErr, ini_get('error_reporting'));
        $this->assertSame($originalPrec, ini_get('precision'));
    }

    public function testEmptyKeysListIsEffectivelyANoOp(): void
    {
        $mw = new IniIsolationMiddleware([]);
        $reached = false;

        $handler = $this->handlerThat(function () use (&$reached) {
            $reached = true;
            return $this->mockResponse();
        });

        $mw->process($this->mockRequest(), $handler);
        $this->assertTrue($reached, 'handler should still run when keys list is empty');
    }

    public function testDefaultKeysListIncludesExpectedSettings(): void
    {
        // Pin the contract: these are the per-request mutation targets we
        // promised in the class docblock and on the coroutines docs page.
        $defaults = IniIsolationMiddleware::DEFAULT_KEYS;
        $expected = [
            'date.timezone',
            'default_charset',
            'display_errors',
            'error_reporting',
            'log_errors',
            'memory_limit',
            'precision',
        ];
        foreach ($expected as $key) {
            $this->assertContains($key, $defaults, "DEFAULT_KEYS must include {$key}");
        }
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function handlerThat(callable $fn): RequestHandlerInterface
    {
        return new class($fn) implements RequestHandlerInterface {
            /** @var callable */
            private $fn;
            public function __construct(callable $fn) { $this->fn = $fn; }
            public function handle(ServerRequestInterface $request): ResponseInterface {
                return ($this->fn)($request);
            }
        };
    }

    private function mockRequest(): ServerRequestInterface
    {
        return $this->createMock(ServerRequestInterface::class);
    }

    private function mockResponse(): ResponseInterface
    {
        return $this->createMock(ResponseInterface::class);
    }
}
