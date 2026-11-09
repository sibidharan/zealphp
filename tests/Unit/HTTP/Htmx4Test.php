<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Registry;
use ZealPHP\HTTP\HtmxResponse;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\RequestContext;
use ZealPHP\Tests\Unit\Component\Fixtures\Counter;

/**
 * htmx 4 request headers (tag#id identifiers, HX-Source, HX-Request-Type),
 * per-request version detection, and HtmxResponse::partial().
 */
class Htmx4Test extends TestCase
{
    private const V4 = ['hx-request' => 'true', 'hx-request-type' => 'partial'];

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(false);
        RequestContext::instance()->zealphp_request = null;
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->zealphp_request = null;
        Registry::reset();
        AppKey::reset();
        App::componentBinder(new HtmxBinder());
    }

    /** @param array<string, string> $headers */
    private function req(array $headers): ZRequest
    {
        $r = new \OpenSwoole\Http\Request();
        $r->header = $headers;
        return new ZRequest($r);
    }

    /** @param array<string, string> $headers */
    private function install(array $headers): void
    {
        RequestContext::instance()->zealphp_request = $this->req($headers);
    }

    public function testVersionDetection(): void
    {
        $this->assertNull($this->req([])->htmxVersion());
        $this->assertSame(2, $this->req(['hx-request' => 'true'])->htmxVersion());
        $this->assertSame(4, $this->req(self::V4)->htmxVersion());
        $this->assertSame('partial', $this->req(self::V4)->htmxRequestType());
        $this->assertSame('full', $this->req(['hx-request' => 'true', 'hx-request-type' => 'full'])->htmxRequestType());
        $this->assertNull($this->req(['hx-request' => 'true', 'hx-request-type' => 'other'])->htmxRequestType());
    }

    /** @return iterable<string, array{array<string, string>, string|null}> */
    public static function targets(): iterable
    {
        yield 'htmx 2 id' => [['hx-request' => 'true', 'hx-target' => 'main'], 'main'];
        yield 'htmx 4 tag#id' => [self::V4 + ['hx-target' => 'div#main'], 'main'];
        yield 'htmx 4 tag only (no id)' => [self::V4 + ['hx-target' => 'div'], null];
        yield 'htmx 4 encoded id' => [self::V4 + ['hx-target' => 'li#row%207'], 'row 7'];
        yield 'empty' => [['hx-request' => 'true', 'hx-target' => ''], null];
        yield 'absent' => [['hx-request' => 'true'], null];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('targets')]
    public function testTargetIsNormalisedToTheId(array $headers, ?string $id): void
    {
        $this->assertSame($id, $this->req($headers)->htmxTarget());
    }

    public function testTriggerComesFromHxSourceOnHtmx4(): void
    {
        $v4 = $this->req(self::V4 + ['hx-source' => 'button#save']);
        $this->assertSame('button#save', $v4->htmxSource());
        $this->assertSame('save', $v4->htmxTrigger());
        $this->assertNull($this->req(self::V4 + ['hx-source' => 'button'])->htmxTrigger());
        $this->assertSame('save', $this->req(['hx-request' => 'true', 'hx-trigger' => 'save'])->htmxTrigger());
        $this->assertNull($this->req(['hx-request' => 'true'])->htmxSource());
    }

    public function testHtmxRenderPicksTheFragmentFromAnHtmx4Target(): void
    {
        $this->install(self::V4 + ['hx-target' => 'div#main']);
        $this->assertSame('MAIN-REGION', App::render('frag', [], 'tests/fixtures/render', htmx: true, echo: false));
        // No target id: fall back to the triggering element's id (htmx 4 sends no HX-Trigger-Name).
        $this->install(self::V4 + ['hx-target' => 'body', 'hx-source' => 'button#side']);
        $this->assertSame('SIDE-REGION', App::render('frag', [], 'tests/fixtures/render', htmx: true, echo: false));
    }

    public function testBinderAnswersInTheDialectOfTheRequest(): void
    {
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        AppKey::set('base64:' . base64_encode(hash('sha256', 'htmx4', true)));
        Registry::reset();
        App::components([Counter::class]);
        App::componentBinder(new HtmxBinder()); // configured for htmx 2

        $page = (string) App::component(Counter::class, [], 'p');
        $this->assertStringContainsString('hx-disabled-elt="this"', $page, 'page render uses the setting');

        $this->install(self::V4);
        $v4 = (string) App::component(Counter::class, [], 'q');
        $this->assertStringContainsString('hx-disable="this"', $v4, 'an htmx 4 request gets htmx 4 attributes');
        $this->assertStringContainsString('hx-swap="outerMorph"', $v4);

        App::componentBinder(new HtmxBinder(htmx: 4));
        $this->install(['hx-request' => 'true']);
        $v2 = (string) App::component(Counter::class, [], 'r');
        $this->assertStringContainsString('hx-disabled-elt="this"', $v2, 'an htmx 2 request gets htmx 2 attributes');
    }

    public function testPartialForBothVersions(): void
    {
        $this->assertSame('<hx-partial hx-target="#cart" hx-swap="outerHTML"><b>3</b></hx-partial>', HtmxResponse::partial('#cart', '<b>3</b>', 'outerHTML', htmx: 4));
        $this->assertSame('<div hx-swap-oob="innerHTML:#cart"><b>3</b></div>', HtmxResponse::partial('#cart', '<b>3</b>', htmx: 2));
        $this->assertSame('<div hx-swap-oob="beforeend:#a&quot;b"></div>', HtmxResponse::partial('#a"b', '', 'beforeend', 2), 'attributes escaped');
    }

    public function testPartialDetectsTheVersionFromTheRequest(): void
    {
        $this->assertStringStartsWith('<div hx-swap-oob=', HtmxResponse::partial('#x', 'y'), 'no request → htmx 2');
        $this->install(self::V4);
        $this->assertStringStartsWith('<hx-partial ', HtmxResponse::partial('#x', 'y'));
        $this->assertStringStartsWith('<hx-partial ', HtmxResponse::partial('#x', \ZealPHP\Escape::raw('y')), 'accepts SafeHtml');
    }

    public function testPartialRefusesUnknownVersions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HtmxResponse::partial('#x', 'y', htmx: 3);
    }
}
