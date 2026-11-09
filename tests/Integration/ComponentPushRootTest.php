<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * The push script over real HTTP (route/_push_test.php): one per page,
 * whether the layout calls componentPushRoot() or not, buffered or streamed.
 */
class ComponentPushRootTest extends TestCase
{
    /** @return list<string> data-zc-ids of each push script in the body */
    private function scripts(string $path): array
    {
        $r = $this->get($path);
        if ($r['status'] === 404 || $r['status'] === 500 && str_contains($this->get('/components')['body'], 'Demo disabled.')) {
            $this->markTestSkipped('Needs ZEALPHP_TEST_ROUTES=1 and the components demo (ZEALPHP_APP_KEY)');
        }
        $this->assertStatus(200, $r);
        $this->assertStringNotContainsString('<!--zealphp:push-root-->', $r['body']);
        preg_match_all('/<script src="\/_zeal\/c\/client\.js\?v=[0-9a-f]+"[^>]*data-zc-ids="([^"]*)"/', $r['body'], $m);
        return $m[1];
    }

    public function testTheSitePageHasOneScript(): void
    {
        $this->assertSame(['shared-likes-demo'], $this->scripts('/components'));
    }

    public function testWithoutTheRootTheFrameworkAddsIt(): void
    {
        $this->assertSame(['shared-likes-auto'], $this->scripts('/_test/push/auto'));
        $this->assertMatchesRegularExpression('#</script></body></html>$#', $this->get('/_test/push/auto')['body']);
    }

    public function testTheRootInTheHeadSeesComponentsAfterIt(): void
    {
        $this->assertSame(['shared-likes-head'], $this->scripts('/_test/push/head'));
    }

    public function testAStreamedPageGetsTheScript(): void
    {
        $this->assertSame(['shared-likes-stream'], $this->scripts('/_test/push/stream'));
    }

    public function testARawRouteStreamGetsTheScriptAndNoPlaceholder(): void
    {
        // The root is evaluated while streaming, before the component renders:
        // it prints nothing then, and the script rides in the </body> chunk.
        $this->assertSame(['shared-likes-raw'], $this->scripts('/_test/push/raw-stream'));
    }

    /**
     * The <head> chunk (with the placeholder) is sent before the component
     * renders, so the placeholder is emptied and the script rides in the
     * </body> chunk: one script, no placeholder on the wire.
     */
    public function testARawRoutePlaceholderBeforeTheStreamLeavesOneScript(): void
    {
        $this->assertSame(['shared-likes-rawhead'], $this->scripts('/_test/push/raw-head'));
        $body = $this->get('/_test/push/raw-head')['body'];
        $this->assertStringContainsString('<head></head>', $body);
        $this->assertMatchesRegularExpression('#</script></body></html>$#', $body);
    }

    public function testHeadReportsTheLengthGetSends(): void
    {
        $this->scripts('/_test/push/auto'); // skips when the routes or the demo are off
        $get = $this->get('/_test/push/auto');
        $head = $this->http('HEAD', '/_test/push/auto');
        $this->assertSame((string) strlen($get['body']), $head['headers']['content-length'] ?? null);
    }

    public function testAFragmentHasNone(): void
    {
        $this->assertSame([], $this->scripts('/_test/push/fragment'));
    }
}
