<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\Tests\TestCase;

/**
 * #478 — App::requestPostMap() raw-body fallback for urlencoded POST bodies.
 *
 * OpenSwoole's pre-parsed `$request->post` can arrive empty even when the body
 * carries a valid `application/x-www-form-urlencoded` payload. requestPostMap()
 * reparses the raw body via parse_str() in that case (urlencoded only; never
 * multipart). These tests pin every branch.
 */
class RequestPostMapTest extends TestCase
{
    /**
     * @param array<string, mixed>  $post
     * @param array<string, string> $header
     * @return array<string, mixed>
     */
    private function resolve(array $post, array $header, string|bool $raw): array
    {
        // The body is read from the native request (->parent), so stub that.
        $native = new class ($raw) extends \OpenSwoole\Http\Request {
            /** @var string|bool */
            private $rawBody;

            public function __construct(string|bool $raw)
            {
                $this->rawBody = $raw;
            }

            public function rawContent(): string|bool
            {
                return $this->rawBody;
            }
        };
        $double = new ZRequest($native);
        $double->post = $post;
        $double->header = $header;

        $m = new \ReflectionMethod(App::class, 'requestPostMap');
        $m->setAccessible(true);
        /** @var array<string, mixed> $out */
        $out = $m->invoke(null, $double);
        return $out;
    }

    /**
     * A real OpenSwoole request parsed from raw HTTP, wrapped the way the
     * OnRequest handler wraps it.
     */
    private function realRequest(string $body): ZRequest
    {
        $native = \OpenSwoole\Http\Request::create();
        $this->assertInstanceOf(\OpenSwoole\Http\Request::class, $native);
        $native->parse(
            "POST /form HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
        return new ZRequest($native);
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function resolveCapturingWarnings(ZRequest $request): array
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $m = new \ReflectionMethod(App::class, 'requestPostMap');
            /** @var array<string, mixed> $out */
            $out = $m->invoke(null, $request);
        } finally {
            restore_error_handler();
        }
        return [$out, $warnings];
    }

    public function testRealRequestRawBodyFallbackParses(): void
    {
        // The wrapper's own rawContent() returned false, so the fallback
        // never ran on a real request.
        $request = $this->realRequest('name=Alice&age=30');
        $request->post = []; // the #478 shape: OpenSwoole's parsed map is empty
        [$out, $warnings] = $this->resolveCapturingWarnings($request);
        $this->assertSame(['name' => 'Alice', 'age' => '30'], $out);
        $this->assertSame([], $warnings);
    }

    public function testRealRequestEmptyUrlencodedBodyLogsNoWarning(): void
    {
        // A urlencoded POST with no body (an empty form, `curl -X POST`)
        // logged "rawContent(): http request is unavailable" on every request.
        [$out, $warnings] = $this->resolveCapturingWarnings($this->realRequest(''));
        $this->assertSame([], $out);
        $this->assertSame([], $warnings);
    }

    public function testPreParsedPostWinsAndSkipsFallback(): void
    {
        // OpenSwoole already parsed it → return as-is, never touch the raw body.
        $out = $this->resolve(
            ['name' => 'Bob'],
            ['content-type' => 'application/x-www-form-urlencoded'],
            'name=ShouldNotWin'
        );
        $this->assertSame(['name' => 'Bob'], $out);
    }

    public function testRawBodyParsedWhenPostEmptyAndUrlencoded(): void
    {
        $out = $this->resolve(
            [],
            ['content-type' => 'application/x-www-form-urlencoded'],
            'name=Alice&age=30'
        );
        $this->assertSame(['name' => 'Alice', 'age' => '30'], $out);
    }

    public function testCharsetParameterStillMatchesUrlencoded(): void
    {
        $out = $this->resolve(
            [],
            ['content-type' => 'application/x-www-form-urlencoded; charset=utf-8'],
            'device=router1&action=register'
        );
        $this->assertSame(['device' => 'router1', 'action' => 'register'], $out);
    }

    public function testBracketNestingFollowsParseStr(): void
    {
        $out = $this->resolve(
            [],
            ['content-type' => 'application/x-www-form-urlencoded'],
            'items[]=a&items[]=b&meta[k]=v'
        );
        $this->assertSame(['items' => ['a', 'b'], 'meta' => ['k' => 'v']], $out);
    }

    public function testEmptyRawBodyReturnsEmpty(): void
    {
        $out = $this->resolve([], ['content-type' => 'application/x-www-form-urlencoded'], '');
        $this->assertSame([], $out);
    }

    public function testFalseRawBodyReturnsEmpty(): void
    {
        // rawContent() returns false when there is no body.
        $out = $this->resolve([], ['content-type' => 'application/x-www-form-urlencoded'], false);
        $this->assertSame([], $out);
    }

    public function testMultipartIsNeverParsed(): void
    {
        // multipart needs boundary parsing — parse_str() would mangle it, so we
        // leave the (empty) OpenSwoole-parsed map untouched.
        $out = $this->resolve(
            [],
            ['content-type' => 'multipart/form-data; boundary=----x'],
            "------x\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n------x--"
        );
        $this->assertSame([], $out);
    }

    public function testJsonBodyIsNotParsed(): void
    {
        $out = $this->resolve([], ['content-type' => 'application/json'], '{"name":"Alice"}');
        $this->assertSame([], $out);
    }

    public function testMissingContentTypeReturnsEmpty(): void
    {
        $out = $this->resolve([], [], 'name=Alice');
        $this->assertSame([], $out);
    }
}
