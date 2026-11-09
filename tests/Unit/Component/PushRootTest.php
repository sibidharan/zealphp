<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use OpenSwoole\Core\Psr\Response;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\Push;
use ZealPHP\HTTP\Response as ZealResponse;
use ZealPHP\RequestContext;

/**
 * Where the push script goes: componentPushRoot() anywhere in a buffered
 * page, or nowhere (the framework adds it before </body>); one script per
 * page; fragments, JSON and pages without topics untouched.
 */
class PushRootTest extends TestCase
{
    protected function setUp(): void
    {
        App::superglobals(false);
        AppKey::set('base64:' . base64_encode(hash('sha256', 'push-root-test', true)));
        Push::reset();
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = ['PHPSESSID' => 'sess-root'];
        $g->session_params = ['session_id' => 'sess-root'];
        $g->_streaming = null;
        $g->zealphp_response = null;
    }

    protected function tearDown(): void
    {
        Push::reset();
        AppKey::reset();
        $g = RequestContext::instance();
        $g->memo = [];
        $g->_streaming = null;
        $g->zealphp_response = null;
    }

    /** What a buffered request does: defer, render, finish. */
    private static function page(callable $render, string $contentType = ''): string
    {
        Push::deferRoot();
        $body = $render();
        assert(is_string($body));
        $response = new Response($body, 200, '', $contentType === '' ? [] : ['Content-Type' => $contentType]);
        return (string) Push::finish($response)->getBody();
    }

    private static function rendered(string $id, string $topic = 'demo:likes'): void
    {
        Push::recordRendered($id, 'likes', [$topic]);
    }

    /** @return list<string> the data-zc-ids of each push script */
    private static function scripts(string $html): array
    {
        preg_match_all('/<script [^>]*data-zc-push[^>]*data-zc-ids="([^"]*)"/', $html, $m);
        return $m[1];
    }

    public function testWithoutTheRootTheScriptGoesBeforeBody(): void
    {
        $html = self::page(static function (): string {
            self::rendered('a');
            return '<html><body><div id="a"></div></BODY></html>';
        });
        $this->assertSame(['a'], self::scripts($html));
        $this->assertMatchesRegularExpression('#<div id="a"></div><script [^>]+></script></BODY></html>$#', $html);
    }

    public function testTheRootInTheHeadCarriesComponentsRenderedAfterIt(): void
    {
        $html = self::page(static function (): string {
            $head = '<head>' . App::componentPushRoot() . '</head>';
            self::rendered('a');
            self::rendered('b', 'demo:other');
            return '<html>' . $head . '<body></body></html>';
        });
        $this->assertSame(['a b'], self::scripts($html));
        $this->assertStringStartsWith('<html><head><script ', $html);
        $this->assertStringEndsWith('</head><body></body></html>', $html);
        $this->assertStringNotContainsString(Push::ROOT_MARKER, $html);
    }

    public function testCallingTheRootTwiceStillGivesOneScript(): void
    {
        $html = self::page(static function (): string {
            self::rendered('a');
            return '<body>' . App::componentPushRoot() . '<p></p>' . App::componentPushRoot() . '</body>';
        });
        $this->assertSame(['a'], self::scripts($html));
        $this->assertStringNotContainsString(Push::ROOT_MARKER, $html);
        $this->assertStringEndsWith('<p></p></body>', $html);
    }

    public function testTheRootWithoutTopicsLeavesNothing(): void
    {
        $html = self::page(static fn(): string => '<body>' . App::componentPushRoot() . '</body>');
        $this->assertSame('<body></body>', $html);
    }

    public function testAPageWithoutTopicsIsTheSameResponse(): void
    {
        Push::deferRoot();
        $response = new Response('<body></body>');
        $this->assertSame($response, Push::finish($response));
    }

    public function testFragmentsAreLeftAlone(): void
    {
        $html = self::page(static function (): string {
            self::rendered('a');
            return '<div id="a" data-zc></div>';
        });
        $this->assertSame('<div id="a" data-zc></div>', $html);
    }

    public function testNonHtmlResponsesAreLeftAlone(): void
    {
        $body = '{"html":"<body></body>"}';
        $json = self::page(static function () use ($body): string {
            self::rendered('a');
            return $body;
        }, 'application/json');
        $this->assertSame($body, $json);
    }

    public function testTheWrapperContentTypeCounts(): void
    {
        $wrapper = (new \ReflectionClass(ZealResponse::class))->newInstanceWithoutConstructor();
        $wrapper->headersList = [['Content-Type', 'text/plain']];
        RequestContext::instance()->zealphp_response = $wrapper;
        $text = self::page(static function (): string {
            self::rendered('a');
            return '</body>';
        });
        $this->assertSame('</body>', $text);

        Push::reset();
        RequestContext::instance()->memo = [];
        $wrapper->headersList = [['content-type', 'text/html; charset=utf-8']];
        $html = self::page(static function (): string {
            self::rendered('a');
            return '</body>';
        });
        $this->assertSame(['a'], self::scripts($html));
    }

    public function testContentLengthFollowsTheNewBody(): void
    {
        Push::deferRoot();
        self::rendered('a');
        $response = Push::finish(new Response('<body></body>', 200, '', ['Content-Length' => '13']));
        $this->assertSame((string) strlen((string) $response->getBody()), $response->getHeaderLine('Content-Length'));
        $this->assertGreaterThan(13, (int) $response->getHeaderLine('Content-Length'));
    }

    public function testAStreamGetsTheScriptInTheChunkWithBodyOnce(): void
    {
        Push::deferRoot();
        Push::streaming();
        self::rendered('a');
        $this->assertSame('<p>part</p>', Push::spliceStream('<p>part</p>'));
        $end = Push::spliceStream('</body></html>');
        $this->assertSame(['a'], self::scripts($end));
        $this->assertStringEndsWith('></script></body></html>', $end);
        $this->assertSame('</body>', Push::spliceStream('</body>'), 'one script per page');
    }

    public function testInAStreamTheRootPrintsTheScriptAndBodyAddsNoSecond(): void
    {
        Push::deferRoot();
        Push::streaming();
        self::rendered('a');
        $root = (string) App::componentPushRoot();
        $this->assertSame(['a'], self::scripts($root));
        $this->assertSame('</body>', Push::spliceStream('</body>'));
    }

    public function testAPlaceholderRenderedBeforeTheStreamIsFilledIn(): void
    {
        Push::deferRoot();
        $head = (string) App::componentPushRoot();   // rendered before the handler returned its generator
        Push::streaming();
        self::rendered('a');
        $chunk = Push::spliceStream($head . '<body>');
        $this->assertSame(['a'], self::scripts($chunk));
        $this->assertStringNotContainsString(Push::ROOT_MARKER, $chunk);
    }

    public function testOutsideARequestTheRootPrintsTheScriptOnce(): void
    {
        self::rendered('a');
        $this->assertSame(['a'], self::scripts((string) App::componentPushRoot()));
        $this->assertSame('', (string) App::componentPushRoot());
    }

    public function testStreamingResponsesAreNotRewritten(): void
    {
        Push::deferRoot();
        self::rendered('a');
        RequestContext::instance()->_streaming = true;
        $response = new Response('</body>');
        $this->assertSame($response, Push::finish($response));
    }
}
