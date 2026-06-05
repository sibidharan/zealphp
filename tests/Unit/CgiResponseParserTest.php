<?php
declare(strict_types=1);
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Unit coverage for App::parseCgiResponse() — the pure RFC 3875 CGI response
 * parser extracted out of cgiInterpreterResponse() (which is itself
 * integration-only: subprocess spawn + $g->response emission).
 *
 * #260 — `headers` is an ordered list of `[name, value]` pairs (NOT a
 * name-keyed map), so multiple same-name headers (multi `Set-Cookie`, …) are
 * preserved instead of collapsing to the last.
 */
final class CgiResponseParserTest extends TestCase
{
    public function testCrlfCrlfSplit(): void
    {
        $raw = "Content-Type: text/plain\r\n\r\nhello world";
        $r = App::parseCgiResponse($raw);
        $this->assertNull($r['status']);
        $this->assertSame([['Content-Type', 'text/plain']], $r['headers']);
        $this->assertSame('hello world', $r['body']);
    }

    public function testLfLfFallbackSplit(): void
    {
        $raw = "Content-Type: text/plain\n\nhello";
        $r = App::parseCgiResponse($raw);
        $this->assertNull($r['status']);
        $this->assertSame([['Content-Type', 'text/plain']], $r['headers']);
        $this->assertSame('hello', $r['body']);
    }

    public function testStatusPseudoHeaderExtractedAndRemoved(): void
    {
        $raw = "Status: 418 I'm a teapot\r\nContent-Type: text/plain\r\n\r\nbrew";
        $r = App::parseCgiResponse($raw);
        $this->assertSame(418, $r['status']);
        $this->assertNotContains('Status', array_column($r['headers'], 0));
        $this->assertSame([['Content-Type', 'text/plain']], $r['headers']);
        $this->assertSame('brew', $r['body']);
    }

    public function testStatusCaseInsensitive(): void
    {
        $raw = "status: 503\r\n\r\ndown";
        $r = App::parseCgiResponse($raw);
        $this->assertSame(503, $r['status']);
        // Only a Status pseudo-header was sent — it is consumed, not forwarded.
        $this->assertSame([], $r['headers']);
    }

    public function testStatusOutOfRangeIgnored(): void
    {
        // 99 and 600 are outside the 100-599 RFC range -> not applied.
        $low = App::parseCgiResponse("Status: 99\r\n\r\nx");
        $this->assertNull($low['status']);
        $high = App::parseCgiResponse("Status: 600\r\n\r\nx");
        $this->assertNull($high['status']);
    }

    public function testStatusNonNumericIgnored(): void
    {
        $r = App::parseCgiResponse("Status: OK\r\n\r\nx");
        $this->assertNull($r['status']);
    }

    public function testMultipleHeadersIncludingCustom(): void
    {
        $raw = "Content-Type: application/json\r\nX-Custom: yes\r\n\r\n{}";
        $r = App::parseCgiResponse($raw);
        $this->assertSame(
            [['Content-Type', 'application/json'], ['X-Custom', 'yes']],
            $r['headers']
        );
        $this->assertSame('{}', $r['body']);
    }

    public function testMultipleSameNameHeadersPreserved(): void
    {
        // #260 — two Set-Cookie headers must BOTH survive as ordered pairs, not
        // collapse to the last (a name-keyed map would lose the first).
        $raw = "Set-Cookie: a=1\r\nSet-Cookie: b=2\r\nContent-Type: text/html\r\n\r\nx";
        $r = App::parseCgiResponse($raw);
        $this->assertSame(
            [['Set-Cookie', 'a=1'], ['Set-Cookie', 'b=2'], ['Content-Type', 'text/html']],
            $r['headers']
        );
    }

    public function testNoHeadersBlankLineFirstIsAllBody(): void
    {
        // Raw starts with the blank line -> empty header block, rest is body.
        $raw = "\r\n\r\nthis is all body";
        $r = App::parseCgiResponse($raw);
        $this->assertNull($r['status']);
        $this->assertSame([], $r['headers']);
        $this->assertSame('this is all body', $r['body']);
    }

    public function testBodyContainingBlankLinesOnlyFirstSeparatorSplits(): void
    {
        // The body itself has a blank line; only the FIRST blank line splits.
        $raw = "Content-Type: text/plain\r\n\r\npara1\r\n\r\npara2";
        $r = App::parseCgiResponse($raw);
        $this->assertSame([['Content-Type', 'text/plain']], $r['headers']);
        $this->assertSame("para1\r\n\r\npara2", $r['body']);
    }

    public function testHeaderValueWithColonPreserved(): void
    {
        // First colon splits; colons inside the value (URL with port) survive.
        $raw = "Location: http://x/y:8080\r\n\r\n";
        $r = App::parseCgiResponse($raw);
        $this->assertSame([['Location', 'http://x/y:8080']], $r['headers']);
        $this->assertSame('', $r['body']);
    }

    public function testNoSeparatorWholeInputIsBody(): void
    {
        $raw = "just some text no header block";
        $r = App::parseCgiResponse($raw);
        $this->assertNull($r['status']);
        $this->assertSame([], $r['headers']);
        $this->assertSame('just some text no header block', $r['body']);
    }

    public function testEmptyInput(): void
    {
        $r = App::parseCgiResponse('');
        $this->assertNull($r['status']);
        $this->assertSame([], $r['headers']);
        $this->assertSame('', $r['body']);
    }

    public function testLinesWithoutColonAreIgnored(): void
    {
        $raw = "Content-Type: text/html\r\ngarbage-line-no-colon\r\n\r\nbody";
        $r = App::parseCgiResponse($raw);
        $this->assertSame([['Content-Type', 'text/html']], $r['headers']);
        $this->assertSame('body', $r['body']);
    }

    public function testCrlfCrlfTakesPrecedenceOverLfLf(): void
    {
        // A bare \n\n appears in the header value region followed by a proper
        // \r\n\r\n separator. strpos finds the \r\n\r\n; the earlier \n\n is
        // only used as a fallback when no \r\n\r\n exists. Here \r\n\r\n wins
        // because it is searched first regardless of position.
        $raw = "Content-Type: text/plain\r\n\r\nbody-with-\n\n-inside";
        $r = App::parseCgiResponse($raw);
        $this->assertSame([['Content-Type', 'text/plain']], $r['headers']);
        $this->assertSame("body-with-\n\n-inside", $r['body']);
    }
}
