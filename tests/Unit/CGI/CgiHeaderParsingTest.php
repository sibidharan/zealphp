<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\CGI\Dispatcher;
use ZealPHP\CGI\FastCgiClient;

/**
 * C2 / C13 — CGI and FastCGI response header parsing.
 *
 * The header block ends at the FIRST blank line, CRLF or bare LF. Searching
 * for CRLF CRLF anywhere first let a script with LF headers and a body that
 * contains CRLF CRLF have its body parsed as headers (Location, Set-Cookie).
 * Status: values are validated (100–599) on both parsers.
 */
final class CgiHeaderParsingTest extends TestCase
{
    /** LF headers, then a body that contains CRLF CRLF followed by header-shaped text. */
    private const LF_HEADERS_CRLF_BODY = "Content-Type: text/plain\n\n"
        . "user comment: hi\r\nLocation: https://evil.example/\r\nSet-Cookie: sid=attacker\r\n\r\ntail";

    public function testParseCgiResponseStopsAtTheFirstLfBlankLine(): void
    {
        $r = Dispatcher::parseCgiResponse(self::LF_HEADERS_CRLF_BODY);
        $this->assertSame([['Content-Type', 'text/plain']], $r['headers'], 'body bytes are never headers');
        $this->assertNull($r['status']);
        $this->assertSame(
            "user comment: hi\r\nLocation: https://evil.example/\r\nSet-Cookie: sid=attacker\r\n\r\ntail",
            $r['body']
        );
    }

    public function testFastCgiParseStdoutStopsAtTheFirstLfBlankLine(): void
    {
        $r = (new FastCgiClient('127.0.0.1:9000', 1))->parseStdout(self::LF_HEADERS_CRLF_BODY, '', 0);
        $this->assertSame([['Content-Type', 'text/plain']], $r['headers']);
        $this->assertSame(200, $r['status']);
        $this->assertStringStartsWith('user comment: hi', $r['body']);
        $this->assertStringEndsWith("\r\n\r\ntail", $r['body']);
    }

    public function testCrlfBlankLineBeforeALaterLfLfStillSplitsAtTheFirst(): void
    {
        $raw = "Status: 404 Not Found\r\nX-A: 1\r\n\r\nbody\n\nX-B: 2\n";
        $r = Dispatcher::parseCgiResponse($raw);
        $this->assertSame(404, $r['status']);
        $this->assertSame([['X-A', '1']], $r['headers']);
        $this->assertSame("body\n\nX-B: 2\n", $r['body']);
    }

    /**
     * @return array<string, array{0:string, 1:array{0:string,1:string}|null}>
     */
    public static function splitCases(): array
    {
        return [
            'crlf'            => ["A: 1\r\nB: 2\r\n\r\nbody", ["A: 1\r\nB: 2\r\n", 'body']],
            'lf'              => ["A: 1\nB: 2\n\nbody", ["A: 1\nB: 2\n", 'body']],
            'crlf then lf'    => ["A: 1\r\n\nbody", ["A: 1\r\n", 'body']],
            'lf then crlf'    => ["A: 1\n\r\nbody", ["A: 1\n", 'body']],
            'leading blank'   => ["\nbody", ['', 'body']],
            'leading crlf'    => ["\r\nbody\r\n\r\nmore", ['', "body\r\n\r\nmore"]],
            'empty body'      => ["A: 1\n\n", ["A: 1\n", '']],
            'cr not alone'    => ["A: 1\n\r \nB: 2\n\nbody", ["A: 1\n\r \nB: 2\n", 'body']],
            'no blank line'   => ["A: 1\r\nB: 2", null],
            'empty'           => ['', null],
            'lone cr is text' => ["A: 1\r\rbody", null],
        ];
    }

    /**
     * @param array{0:string,1:string}|null $expected
     * @dataProvider splitCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('splitCases')]
    public function testSplitHeaderBlock(string $raw, ?array $expected): void
    {
        $this->assertSame($expected, FastCgiClient::splitHeaderBlock($raw));
    }

    /**
     * @return array<string, array{0:string, 1:int|null}>
     */
    public static function statusCases(): array
    {
        return [
            'code and reason' => ['302 Found', 302],
            'bare code'       => ['204', 204],
            'lowest'          => ['100 Continue', 100],
            'highest'         => ['599 Custom', 599],
            'too low'         => ['99 Nope', null],
            'too high'        => ['600 Nope', null],
            'huge'            => ['99999', null],
            'letters'         => ['abc', null],
            'signed'          => ['+200', null],
            'decimal'         => ['200.5 OK', null],
            'empty'           => ['', null],
            'leading zero'    => ['0200 OK', 200],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusCases')]
    public function testStatusCode(string $value, ?int $expected): void
    {
        $this->assertSame($expected, FastCgiClient::statusCode($value));
    }

    public function testFastCgiInvalidStatusIsIgnoredNotCastToZero(): void
    {
        // C13 — `(int) "abc"` made the response status 0, and "99999" passed
        // through; an invalid Status: is now ignored (the default 200 stays),
        // as parseCgiResponse() already did.
        $client = new FastCgiClient('127.0.0.1:9000', 1);
        foreach (['abc', '99999', '42', '-1'] as $bad) {
            $r = $client->parseStdout("Status: {$bad}\r\nX-Ok: 1\r\n\r\nbody", '', 0);
            $this->assertSame(200, $r['status'], "Status: {$bad}");
            $this->assertSame([['X-Ok', '1']], $r['headers'], 'Status: is consumed, never forwarded');
        }
        $this->assertSame(503, $client->parseStdout("Status: 503 Busy\n\n", '', 0)['status']);
    }
}
