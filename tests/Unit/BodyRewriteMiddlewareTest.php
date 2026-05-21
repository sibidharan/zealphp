<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\BodyRewriteMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class BodyRewriteMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->_streaming = null;
    }

    /**
     * @param array<int, array{pattern: string, replacement: string}> $rules
     */
    private function process(
        array $rules,
        string $body,
        string $contentType = 'text/html'
    ): ResponseInterface {
        $middleware = new BodyRewriteMiddleware($rules);

        $request = new ServerRequest('/', 'GET', '', []);

        $handler = new class($body, $contentType) implements RequestHandlerInterface {
            public function __construct(private string $body, private string $ct) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, 200, '', ['Content-Type' => $this->ct]);
            }
        };

        return $middleware->process($request, $handler);
    }

    public function testSubstitutesMatchingPattern(): void
    {
        $response = $this->process(
            [['pattern' => '#http://internal\.lan#', 'replacement' => 'https://example.com']],
            '<a href="http://internal.lan/x">link</a>'
        );
        $this->assertSame('<a href="https://example.com/x">link</a>', (string)$response->getBody());
    }

    public function testRewrittenBodyIsExactBytes(): void
    {
        // Pins the EXACT rewritten body and the recomputed Content-Length.
        // Also kills the fseek mutants (offset -1 / +1 / removal): if the
        // stream isn't rewound to 0 the read-back body would be truncated or
        // shifted and would not equal the expected bytes.
        $body = str_repeat('foo BAR foo ', 50);
        $response = $this->process(
            [['pattern' => '/BAR/', 'replacement' => 'baz']],
            $body
        );
        $expected = str_repeat('foo baz foo ', 50);
        $this->assertSame($expected, (string)$response->getBody());
        $this->assertSame((string)strlen($expected), $response->getHeaderLine('Content-Length'));
    }

    public function testFseekRewindsToStartShortBody(): void
    {
        // Short body amplifies any fseek offset error: an off-by-one rewind
        // would drop or duplicate the first byte.
        $response = $this->process(
            [['pattern' => '/X/', 'replacement' => 'Y']],
            'XbcdefXX'
        );
        $this->assertSame('YbcdefYY', (string)$response->getBody());
        $this->assertSame('8', $response->getHeaderLine('Content-Length'));
    }

    public function testStreamIsRewoundToStartAfterWrite(): void
    {
        // Kills the fseek mutants at L108 (offset -1 / +1 / call removal).
        // We read via getContents() (NOT (string) cast) because the Stream's
        // __toString() seeks to 0 itself, masking the middleware's own rewind.
        // getContents() reads from the CURRENT position, so it only returns
        // the full body if the middleware seeked back to 0:
        //   - fseek(0)  (correct) => position 0 => full body
        //   - fseek(-1)          => seek fails  => position at EOF => ''
        //   - fseek(1)           => position 1  => body minus first char
        //   - no fseek           => position at EOF => ''
        $body = 'ALPHA middle ALPHA';
        $response = $this->process(
            [['pattern' => '/ALPHA/', 'replacement' => 'OMEGA']],
            $body
        );
        $expected = 'OMEGA middle OMEGA';
        $this->assertSame($expected, $response->getBody()->getContents());
    }

    public function testMultipleRulesAppliedInOrder(): void
    {
        $response = $this->process(
            [
                ['pattern' => '/one/', 'replacement' => 'two'],
                ['pattern' => '/two/', 'replacement' => 'three'],
            ],
            'one'
        );
        // Rule 1: one -> two; Rule 2: two -> three. Order matters.
        $this->assertSame('three', (string)$response->getBody());
    }

    public function testNonMatchingBodyIsUnchanged(): void
    {
        $body = 'nothing to rewrite here';
        $response = $this->process(
            [['pattern' => '/ABSENT/', 'replacement' => 'X']],
            $body
        );
        $this->assertSame($body, (string)$response->getBody());
        // No Content-Length rewrite when body is unchanged.
        $this->assertFalse($response->hasHeader('Content-Length'));
    }

    public function testNonTextishContentTypeIsSkipped(): void
    {
        // Kills the UnwrapStrToLower mutant at L75 only indirectly; primary
        // intent: an image CT is not text-ish so the rule never runs.
        $body = 'BAR raw bytes BAR';
        $response = $this->process(
            [['pattern' => '/BAR/', 'replacement' => 'baz']],
            $body,
            'image/png'
        );
        $this->assertSame($body, (string)$response->getBody());
    }

    public function testUppercaseTextishContentTypeStillRewrites(): void
    {
        // Kills the UnwrapStrToLower mutant at L75: an uppercase TEXT/HTML CT
        // must lowercase before the text-ish prefix check, otherwise the
        // rewrite would be skipped.
        $response = $this->process(
            [['pattern' => '/BAR/', 'replacement' => 'baz']],
            'BAR here',
            'TEXT/HTML; charset=utf-8'
        );
        $this->assertSame('baz here', (string)$response->getBody());
    }

    public function testStreamingResponseIsNotRewritten(): void
    {
        // Kills the Coalesce mutant at L71 ($g->_streaming ?? false ->
        // false ?? $g->_streaming). When _streaming is true the body has
        // already been flushed and must NOT be rewritten.
        RequestContext::instance()->_streaming = true;
        try {
            $response = $this->process(
                [['pattern' => '/BAR/', 'replacement' => 'baz']],
                'BAR should stay'
            );
            $this->assertSame('BAR should stay', (string)$response->getBody());
        } finally {
            RequestContext::instance()->_streaming = null;
        }
    }

    public function testEmptyRulesPassThrough(): void
    {
        $body = 'unchanged';
        $response = $this->process([], $body);
        $this->assertSame($body, (string)$response->getBody());
    }

    public function testEmptyBodyPassThrough(): void
    {
        $response = $this->process(
            [['pattern' => '/x/', 'replacement' => 'y']],
            ''
        );
        $this->assertSame('', (string)$response->getBody());
    }

    public function testInvalidPatternIsSkippedLeavingBodyIntact(): void
    {
        // A malformed regex makes preg_replace return null; the rule is skipped
        // and the body is left intact (no crash).
        $body = 'keep me';
        $response = $this->process(
            [['pattern' => '/[unterminated', 'replacement' => 'X']],
            $body
        );
        $this->assertSame($body, (string)$response->getBody());
    }

    public function testJsonContentTypeIsTextish(): void
    {
        $response = $this->process(
            [['pattern' => '/old/', 'replacement' => 'new']],
            '{"v":"old"}',
            'application/json'
        );
        $this->assertSame('{"v":"new"}', (string)$response->getBody());
    }
}
