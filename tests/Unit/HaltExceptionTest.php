<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\HaltException;

/**
 * Unit tests for ZealPHP\HaltException — the exit() / die() replacement that
 * lets a page halt cleanly without killing the OpenSwoole worker.
 *
 * The class is intentionally tiny — verify the contract surface only:
 *   - it extends \Error (a \Throwable that is NOT a \Exception), so an app-level
 *     `catch (\Exception)` around a halting response() does NOT swallow it (#194)
 *   - construction with and without a message works
 *   - it can be thrown and caught as itself and as \Throwable
 *
 * The cross-cutting behaviour (App::executeFile() / ZealAPI::runHandlerWithContract()
 * catching it and returning the captured output buffer) is covered elsewhere —
 * this class only owns the type-shape contract.
 */
class HaltExceptionTest extends TestCase
{
    public function testIsAnErrorNotAnException(): void
    {
        // #194: HaltException extends \Error (a \Throwable, NOT a \Exception) so an
        // app-level catch(\Exception) around a halting response() can't swallow it.
        $this->assertInstanceOf(\Error::class, new HaltException());
        $this->assertInstanceOf(\Throwable::class, new HaltException());
        $this->assertNotInstanceOf(\Exception::class, new HaltException());
    }

    public function testDefaultConstructorHasEmptyMessage(): void
    {
        $e = new HaltException();
        $this->assertSame('', $e->getMessage());
        $this->assertSame(0, $e->getCode());
    }

    public function testConstructorMessageAndCodeAreStored(): void
    {
        $e = new HaltException('redirect handoff', 302);
        $this->assertSame('redirect handoff', $e->getMessage());
        $this->assertSame(302, $e->getCode());
    }

    public function testCatchAsHaltException(): void
    {
        try {
            throw new HaltException('stop here');
        } catch (HaltException $caught) {
            $this->assertSame('stop here', $caught->getMessage());
            return;
        }
        $this->fail('HaltException was not caught as itself');
    }

    public function testNotCaughtByCatchException(): void
    {
        // The #194 footgun: a catch(\Exception) around a halt must NOT catch it,
        // or execution falls through past the halt and double-emits the response.
        $caughtByException = false;
        try {
            throw new HaltException('stop here');
        } catch (\Exception $caught) {
            $caughtByException = true;
        } catch (\Throwable $caught) {
            $this->assertInstanceOf(HaltException::class, $caught);
        }
        $this->assertFalse($caughtByException, 'catch(\Exception) must NOT swallow HaltException');
    }

    public function testCatchAsThrowable(): void
    {
        try {
            throw new HaltException();
        } catch (\Throwable $caught) {
            $this->assertInstanceOf(HaltException::class, $caught);
            return;
        }
        $this->fail('HaltException was not caught as \Throwable');
    }

    public function testWrappingPreviousException(): void
    {
        $prev = new \RuntimeException('upstream');
        $e    = new HaltException('halted by upstream', 0, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }
}
