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
 *   - it's an Exception subclass (so catch (\Throwable) and catch (\Exception)
 *     blocks in user code still catch it as expected)
 *   - construction with and without a message works
 *   - it can be thrown and caught as itself, as \Exception, and as \Throwable
 *
 * The cross-cutting behaviour (App::executeFile() catching it and returning
 * the captured output buffer) is the responsibility of FileExecutionContractTest
 * — this class only owns the type-shape contract.
 */
class HaltExceptionTest extends TestCase
{
    public function testIsAnException(): void
    {
        $this->assertInstanceOf(\Exception::class, new HaltException());
        $this->assertInstanceOf(\Throwable::class, new HaltException());
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

    public function testCatchAsGenericException(): void
    {
        try {
            throw new HaltException('stop here');
        } catch (\Exception $caught) {
            $this->assertInstanceOf(HaltException::class, $caught);
            return;
        }
        $this->fail('HaltException was not caught as \Exception');
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
