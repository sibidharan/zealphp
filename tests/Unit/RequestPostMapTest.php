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
        $double = new class ($post, $header, $raw) extends ZRequest {
            /** @var string|bool */
            private $rawBody;

            /**
             * @param array<string, mixed>  $post
             * @param array<string, string> $header
             * @param string|bool           $raw
             */
            public function __construct(array $post, array $header, string|bool $raw)
            {
                parent::__construct(new \OpenSwoole\Http\Request());
                $this->post = $post;
                $this->header = $header;
                $this->rawBody = $raw;
            }

            public function rawContent(): string|bool
            {
                return $this->rawBody;
            }
        };

        $m = new \ReflectionMethod(App::class, 'requestPostMap');
        $m->setAccessible(true);
        /** @var array<string, mixed> $out */
        $out = $m->invoke(null, $double);
        return $out;
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
