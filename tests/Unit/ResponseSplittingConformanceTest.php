<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;

/**
 * Conformance: HTTP response-splitting / header-injection defense.
 *
 * `header("X-Foo: " . $userInput)` with a CR/LF in the input is the classic
 * response-splitting vector (smuggle a second header or a whole second
 * response). Native PHP's header() refuses it since 4.4.2; ZealPHP's uopz
 * override must do the same — including for `Location:` built from user input.
 * (Cookie-side CR/LF/NUL rejection is pinned in Rfc6265CookieConformanceTest.)
 */
class ResponseSplittingConformanceTest extends TestCase
{
    public function testCrlfInHeaderValueIsRejected(): void
    {
        $this->assertFalse(@\ZealPHP\header("X-Foo: bar\r\nSet-Cookie: evil=1"));
    }

    public function testLocationSplittingIsRejected(): void
    {
        // A redirect target built from tainted input must not forge headers.
        $this->assertFalse(@\ZealPHP\header("Location: /next\r\nSet-Cookie: sid=hijack"));
    }

    public function testBareCrAndBareLfRejected(): void
    {
        $this->assertFalse(@\ZealPHP\header("X-A: v\rinjected"));
        $this->assertFalse(@\ZealPHP\header("X-B: v\ninjected"));
    }

    public function testNulByteInHeaderRejected(): void
    {
        $this->assertFalse(@\ZealPHP\header("X-C: v\0null"));
    }

    public function testHeaderRemoveCrlfRejected(): void
    {
        // header_remove must not be an injection bypass either.
        $this->assertFalse(@\ZealPHP\header("Set-Cookie: a=1\r\nLocation: //evil"));
    }
}
