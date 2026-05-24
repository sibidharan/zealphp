<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\CgiMode;

/**
 * Patch-coverage for the CgiMode backed enum + coerce helper.
 */
final class CgiModeEnumTest extends TestCase
{
    public function testEnumCases(): void
    {
        $this->assertSame('pool', CgiMode::Pool->value);
        $this->assertSame('proc', CgiMode::Proc->value);
        $this->assertSame('fcgi', CgiMode::Fcgi->value);
    }

    public function testCoerceFromEnum(): void
    {
        $this->assertSame(CgiMode::Pool, CgiMode::coerce(CgiMode::Pool));
        $this->assertSame(CgiMode::Proc, CgiMode::coerce(CgiMode::Proc));
        $this->assertSame(CgiMode::Fcgi, CgiMode::coerce(CgiMode::Fcgi));
    }

    public function testCoerceFromString(): void
    {
        $this->assertSame(CgiMode::Pool, CgiMode::coerce('pool'));
        $this->assertSame(CgiMode::Proc, CgiMode::coerce('proc'));
        $this->assertSame(CgiMode::Fcgi, CgiMode::coerce('fcgi'));
    }

    public function testCoerceIsCaseInsensitive(): void
    {
        $this->assertSame(CgiMode::Proc, CgiMode::coerce('PROC'));
        $this->assertSame(CgiMode::Fcgi, CgiMode::coerce('FCGI'));
    }

    public function testCoerceUnknownStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CgiMode::coerce('nonsense');
    }
}
