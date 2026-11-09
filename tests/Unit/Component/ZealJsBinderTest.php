<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\Endpoint;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Model;
use ZealPHP\Component\Outcome;
use ZealPHP\Component\Registry;
use ZealPHP\Component\ZealJsBinder;
use ZealPHP\RequestContext;
use ZealPHP\Tests\Unit\Component\Fixtures\Counter;
use ZealPHP\Tests\Unit\Component\Fixtures\Signup;

/** ZealJsBinder: the htmx-free client's markup and protocol. */
class ZealJsBinderTest extends TestCase
{
    private string $prevCwd = '';

    protected function setUp(): void
    {
        $this->prevCwd = App::$cwd;
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        App::superglobals(false);
        AppKey::set('base64:' . base64_encode(hash('sha256', 'zealjs', true)));
        Registry::reset();
        App::components([Counter::class, Signup::class]);
        App::componentBinder(new ZealJsBinder());
        $g = RequestContext::instance();
        $g->memo = [];
        unset($g->session);
        $g->cookie = [];
    }

    protected function tearDown(): void
    {
        Registry::reset();
        AppKey::reset();
        App::componentBinder(new HtmxBinder());
        App::$cwd = $this->prevCwd;
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
    }

    public function testActionsAreFormButtonsWithAMarker(): void
    {
        $html = (string) App::component(Counter::class, [], 'z');
        $this->assertMatchesRegularExpression(
            '#<button type="submit" formaction="/_zeal/c/counter/increment" formmethod="post" name="_zc_args" value="[^"]*" data-zc-action form="counter-z-zc">#',
            $html
        );
        $this->assertStringNotContainsString('hx-', $html, 'no htmx attributes');
    }

    public function testModelTimingMarkers(): void
    {
        $html = (string) App::component(Signup::class, [], 'z');
        $this->assertMatchesRegularExpression('#name="username"[^>]* data-zc-live="300" data-zc-update="/_zeal/c/signup/_update"#', $html);
        $this->assertMatchesRegularExpression('#name="age"[^>]* data-zc-blur data-zc-update="/_zeal/c/signup/_update"#', $html);
        $this->assertDoesNotMatchRegularExpression('#name="email"[^>]*data-zc-#', $html, 'deferred fields send with the next action');
        $this->assertStringNotContainsString('hx-', $html);
    }

    public function testModelAttributesDirectly(): void
    {
        $binder = new ZealJsBinder();
        $c = new Counter();
        $this->assertSame([], $binder->modelAttributes($c, 'x', new Model(), '/u', false));
        $this->assertSame(['data-zc-live' => '50', 'data-zc-update' => '/u'], $binder->modelAttributes($c, 'x', new Model(live: true, debounce: 50), '/u', true));
        $this->assertSame(['data-zc-blur' => true, 'data-zc-update' => '/u'], $binder->modelAttributes($c, 'x', new Model(blur: true), '/u', false));
    }

    public function testItSpeaksThePlainProtocol(): void
    {
        $binder = new ZealJsBinder();
        $this->assertTrue($binder->isAsync(['HTTP_ZEAL_REQUEST' => '1']));
        $this->assertFalse($binder->isAsync(['HTTP_HX_REQUEST' => 'true']));
        $this->assertSame([], $binder->refusalHeaders());
        $this->assertSame(422, $binder->response(Outcome::Invalid, [], null)['status']);
        $this->assertSame(['Zeal-Events' => '{"saved":{"id":1}}', 'Zeal-Redirect' => '/x'], $binder->response(Outcome::Ok, ['saved' => ['id' => 1]], '/x')['headers']);
        $this->assertSame('1', $binder->response(Outcome::Stale, [], null)['headers']['Zeal-Reload']);

        // Through the endpoint, as zeal.js sends it.
        $html = (string) App::component(Counter::class, [], 'z');
        preg_match('/name="_zc" value="([^"]+)"/', $html, $m);
        $r = Endpoint::handle('counter', 'increment', ['_zc' => html_entity_decode($m[1]), '_zc_args' => '{"by":3}'], [
            'HTTP_ZEAL_REQUEST' => '1',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('data-zc-action', $r['body'], 'the re-render keeps the markers');
    }

    public function testScriptTagIsVersionedByContent(): void
    {
        $tag = (string) ZealJsBinder::script();
        $version = substr(hash('sha256', ZealJsBinder::source()), 0, 12);
        $this->assertSame('<script src="/_zeal/c/zeal.js?v=' . $version . '" defer></script>', $tag);
        $this->assertStringContainsString("'Zeal-Request': '1'", ZealJsBinder::source());
        $this->assertSame((string) file_get_contents(ZEALPHP_ROOT . '/src/Component/zeal.js'), ZealJsBinder::source());
    }
}
