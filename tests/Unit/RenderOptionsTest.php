<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\RequestContext;
use ZealPHP\SafeHtml;

/**
 * The consolidated rendering API: App::render() options (fragment:, htmx:,
 * stream:, echo:) and App::component('/absolute') for markup. The old
 * helpers (renderToString/renderStream/renderHtmx) stay as aliases.
 */
class RenderOptionsTest extends TestCase
{
    private const DIR = 'tests/fixtures/render';
    private string $prevCwd = '';

    protected function setUp(): void
    {
        $this->prevCwd = App::$cwd;
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(false);
        $g = RequestContext::instance();
        $g->memo = [];
        $g->status = null;
        $g->zealphp_request = null;
    }

    protected function tearDown(): void
    {
        App::$cwd = $this->prevCwd;
        RequestContext::instance()->zealphp_request = null;
    }

    /**
     * @param callable(): mixed $fn
     * @return array{0: string, 1: mixed}
     */
    private function capture(callable $fn): array
    {
        ob_start();
        try {
            $ret = $fn();
        } finally {
            $out = (string) ob_get_clean();
        }
        return [$out, $ret];
    }

    private function htmxRequest(array $headers): void
    {
        $or = new \OpenSwoole\Http\Request();
        $or->header = ['hx-request' => 'true'] + $headers;
        RequestContext::instance()->zealphp_request = new ZRequest($or);
    }

    public function testFragmentOptionSelectsARegionAndBeatsTheArgsKey(): void
    {
        $this->assertSame('W[CB|CRI|CA]', App::render('parent_frag', [], self::DIR, fragment: 'want', echo: false));
        $this->assertSame('W[CB|CRI|CA]', App::render('parent_frag', ['fragment' => 'other'], self::DIR, fragment: 'want', echo: false));
    }

    public function testEchoFalseOnlyReturns(): void
    {
        [$out, $ret] = $this->capture(fn() => App::render('echo_only', [], self::DIR, echo: false));
        $this->assertSame('', $out);
        $this->assertSame('hello-echo', $ret);
        [$out2] = $this->capture(fn() => App::render('echo_only', [], self::DIR));
        $this->assertSame('hello-echo', $out2, 'echo stays the default');
    }

    public function testStreamOptionReturnsAGeneratorAndNeverEchoes(): void
    {
        [$out, $gen] = $this->capture(fn() => App::render('return_generator', [], self::DIR, stream: true));
        $this->assertSame('', $out);
        $this->assertInstanceOf(\Generator::class, $gen);
        $old = implode('', iterator_to_array(App::renderStream('return_generator', [], self::DIR), false));
        $this->assertSame($old, implode('', iterator_to_array($gen, false)), 'same output as the deprecated helper');

        [$out2, $gen2] = $this->capture(fn() => App::render('echo_only', [], self::DIR, stream: true));
        $this->assertSame('', $out2);
        $this->assertSame(['hello-echo'], iterator_to_array($gen2, false));
    }

    public function testHtmxOptionMatchesRenderHtmx(): void
    {
        // A normal page load gets the whole template, even with a fragment named.
        $this->assertSame('MAIN-REGIONSIDE-REGION', App::render('frag', [], self::DIR, htmx: true, fragment: 'main', echo: false));
        $this->assertSame('MAIN-REGIONSIDE-REGION', App::render('frag', ['fragment' => 'main'], self::DIR, htmx: true, echo: false));

        // An htmx request gets the region: named, from $args, or from HX-Target.
        $this->htmxRequest([]);
        $this->assertSame('MAIN-REGION', App::render('frag', [], self::DIR, htmx: true, fragment: 'main', echo: false));
        $this->assertSame('SIDE-REGION', App::render('frag', ['fragment' => 'main'], self::DIR, htmx: true, fragment: 'side', echo: false));
        $this->htmxRequest(['hx-target' => '#side']);
        $this->assertSame('SIDE-REGION', App::render('frag', [], self::DIR, htmx: true, echo: false));
    }

    public function testFullPageRequiresHtmx(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::render('frag', [], self::DIR, fullPage: 'other');
    }

    public function testComponentRendersAnyTemplateByAbsolutePath(): void
    {
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        $html = App::component('/Emails_Welcome', ['who' => 'ana']);
        $this->assertInstanceOf(SafeHtml::class, $html);
        $this->assertSame('[abs-ana]', (string) $html);
    }

    /** @return iterable<string, array{string}> */
    public static function badAbsolute(): iterable
    {
        yield 'traversal' => ['/../secrets'];
        yield 'inner traversal' => ['/mail/../../x'];
        yield 'missing' => ['/nope'];
        yield 'double slash' => ['//x'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badAbsolute')]
    public function testAbsolutePathsStayInsideTemplate(string $name): void
    {
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        $this->expectException(\LogicException::class);
        App::component($name);
    }

    public function testATemplateReturningAComponentRendersAsHtml(): void
    {
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        $this->assertStringContainsString('<h3>in-template</h3>', App::renderToString('/mail/returns_component'));
        $this->assertStringContainsString('<h3>in-template</h3>', (string) App::component('/mail/returns_component'));
        $streamed = implode('', iterator_to_array(App::render('/mail/returns_component', [], stream: true), false));
        $this->assertStringContainsString('<h3>in-template</h3>', $streamed);
        $this->assertStringNotContainsString('{}', $streamed, 'not JSON');
    }
}
