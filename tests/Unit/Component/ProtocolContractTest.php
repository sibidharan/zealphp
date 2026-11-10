<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\Component;
use ZealPHP\Component\Endpoint;
use ZealPHP\Component\FormBinder;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Outcome;
use ZealPHP\Component\Registry;
use ZealPHP\RequestContext;
use ZealPHP\Tests\Unit\Component\Fixtures\Counter;
use ZealPHP\Tests\Unit\Component\Fixtures\Signup;

/**
 * The parts of the stable API that reflection can't lock
 * (docs/components.md#api-stability): the wire protocol a non-htmx client
 * speaks, and what a component template receives. A failure here is a
 * breaking change.
 */
class ProtocolContractTest extends TestCase
{
    private const PLAIN = ['HTTP_ZEAL_REQUEST' => '1', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

    private string $prevCwd = '';

    protected function setUp(): void
    {
        $this->prevCwd = App::$cwd;
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        App::superglobals(false);
        AppKey::set('base64:' . base64_encode(hash('sha256', 'protocol', true)));
        Registry::reset();
        App::components([Counter::class, Signup::class]);
        App::componentBinder(new FormBinder());
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = ['PHPSESSID' => 'sess-protocol'];
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
        $g->cookie = [];
    }

    private static function wire(string $html): string
    {
        self::assertSame(1, preg_match('/<input type="hidden" name="_zc" value="([^"]+)"/', $html, $m), 'the snapshot rides in a hidden _zc field');
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    public function testActionsPostToTheEndpointWithArgsInZcArgs(): void
    {
        $html = (string) App::component(Counter::class, [], key: 'p');
        $this->assertStringContainsString('formaction="/_zeal/c/counter/increment"', $html);
        $this->assertStringContainsString('name="_zc_args" value="{&quot;by&quot;:2}"', $html);

        $r = Endpoint::handle('counter', 'increment', ['_zc' => self::wire($html), '_zc_args' => '{"by":2}'], self::PLAIN);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('<span class="count">2</span>', $r['body']);
    }

    public function testUpdateValidatesTheFieldNamedInZcTrigger(): void
    {
        $html = (string) App::component(Signup::class, [], key: 'p');
        $r = Endpoint::handle('signup', Component::UPDATE, [
            '_zc' => self::wire($html), 'email' => 'not-an-email', 'username' => '', '_zc_trigger' => 'email',
        ], self::PLAIN);
        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('Email must be a valid email address.', $r['body']);
        $this->assertStringNotContainsString('Username is required.', $r['body'], 'only the triggering field');
    }

    public function testRequestHeaders(): void
    {
        $binder = new FormBinder();
        $this->assertTrue($binder->isAsync(['HTTP_ZEAL_REQUEST' => '1']));
        $this->assertFalse($binder->isAsync([]));
        $this->assertSame('email', $binder->triggerField(['HTTP_ZEAL_TRIGGER' => 'email']));
    }

    public function testResponseStatusesAndHeaders(): void
    {
        $binder = new FormBinder();
        $this->assertSame(
            ['status' => 200, 'headers' => ['Zeal-Events' => '{"saved":{"id":1}}', 'Zeal-Redirect' => '/done']],
            $binder->response(Outcome::Ok, ['saved' => ['id' => 1]], '/done')
        );
        $this->assertSame(['status' => 422, 'headers' => []], $binder->response(Outcome::Invalid, [], null));
        $this->assertSame(['status' => 409, 'headers' => ['Zeal-Reload' => '1']], $binder->response(Outcome::Stale, [], null));
    }

    public function testTemplatesReceivePropsCErrorsAndSlot(): void
    {
        $c = new class extends Component {
            public string $name = '';
            public function render(): mixed { return $this->view('contract/vars'); }
        };
        Registry::register($c::class, 'vars');
        $this->assertStringContainsString('>x,c,errors,noslot</div>', (string) App::component('vars', ['name' => 'x']));
        $this->assertStringContainsString('>y,c,errors,<b>s</b></div>', (string) App::component('vars', ['name' => 'y', 'slot' => '<b>s</b>'], key: 'two'));
    }
}
