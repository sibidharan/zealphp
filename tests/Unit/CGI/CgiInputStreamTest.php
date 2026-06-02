<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\CGI\CgiInputStream;

/**
 * Unit coverage for the CGI subprocess php:// stream wrapper.
 *
 * CgiInputStream serves `php://input` from $GLOBALS['__zeal_cgi_raw_input']
 * (the per-request raw body the pool/proc worker stashes) so legacy code and
 * the WordPress REST API can `file_get_contents('php://input')` under
 * OpenSwoole, where native CLI php://input is empty. Every other php:// stream
 * passes through to the original wrapper.
 *
 * The wrapper is registered as the 'php' handler for each test and restored in
 * tearDown so no other test sees the swap.
 */
class CgiInputStreamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', CgiInputStream::class);
    }

    protected function tearDown(): void
    {
        stream_wrapper_restore('php');
        unset($GLOBALS['__zeal_cgi_raw_input']);
        parent::tearDown();
    }

    public function testPhpInputServesStashedBody(): void
    {
        $GLOBALS['__zeal_cgi_raw_input'] = '{"hello":"world"}';
        $this->assertSame('{"hello":"world"}', file_get_contents('php://input'));
    }

    public function testPhpInputEmptyWhenNothingStashed(): void
    {
        unset($GLOBALS['__zeal_cgi_raw_input']);
        $this->assertSame('', file_get_contents('php://input'));
    }

    public function testChunkedReadThenEof(): void
    {
        $GLOBALS['__zeal_cgi_raw_input'] = 'abcdef';
        $h = fopen('php://input', 'r');
        $this->assertIsResource($h);
        $this->assertSame('abc', fread($h, 3));
        $this->assertSame('def', fread($h, 3));
        $this->assertTrue(feof($h));
        $this->assertSame('', fread($h, 3)); // nothing left
        fclose($h);
    }

    public function testSeekTellAndStatSize(): void
    {
        $GLOBALS['__zeal_cgi_raw_input'] = 'abcdefghij'; // 10 bytes
        $h = fopen('php://input', 'r');
        $this->assertIsResource($h);
        $this->assertSame(0, ftell($h));
        $this->assertSame(0, fseek($h, 4, SEEK_SET));   // SEEK_SET
        $this->assertSame(4, ftell($h));
        $this->assertSame('ef', fread($h, 2));          // pos → 6
        $this->assertSame(0, fseek($h, 2, SEEK_CUR));   // SEEK_CUR → pos 8
        $this->assertSame('ij', fread($h, 5));
        $this->assertSame(0, fseek($h, -3, SEEK_END));  // SEEK_END → pos 7
        $this->assertSame('hij', fread($h, 99));
        $st = fstat($h);
        $this->assertIsArray($st);
        $this->assertSame(10, $st['size']);
        fclose($h);
    }

    public function testSeekOutOfRangeFails(): void
    {
        $GLOBALS['__zeal_cgi_raw_input'] = 'abc';
        $h = fopen('php://input', 'r');
        $this->assertIsResource($h);
        $this->assertSame(-1, fseek($h, 100, SEEK_SET)); // beyond EOF → stream_seek false → fseek -1
        $this->assertSame(-1, fseek($h, -1, SEEK_SET));  // negative → false
        fclose($h);
    }

    public function testNonInputPhpStreamPassesThrough(): void
    {
        // php://temp must still work — the wrapper delegates non-input streams
        // to the original php handler.
        $h = fopen('php://temp', 'r+');
        $this->assertIsResource($h);
        $this->assertSame(11, fwrite($h, 'passthrough'));
        rewind($h);
        $this->assertSame('passthrough', stream_get_contents($h));
        fclose($h);
    }
}
