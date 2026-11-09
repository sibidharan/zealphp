<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\Action;
use ZealPHP\Component\Component;
use ZealPHP\Component\ComponentException;
use ZealPHP\Component\Endpoint;
use ZealPHP\Component\FormBinder;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Model;
use ZealPHP\Component\Outcome;
use ZealPHP\Component\Registry;
use ZealPHP\Component\Snapshot;
use ZealPHP\Component\Type;
use ZealPHP\Component\ValidationException;
use ZealPHP\RequestContext;
use ZealPHP\Tests\Unit\Component\Fixtures\Priority;
use ZealPHP\Tests\Unit\Component\Fixtures\Signup;

/** Version hashing, binder headers, endpoint outcomes and message text. */
class ComponentEdgesTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        App::superglobals(false);
        AppKey::set('base64:' . base64_encode(hash('sha256', 'edges', true)));
        Registry::reset();
        App::components([Signup::class]);
        App::componentBinder(new HtmxBinder());
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = ['PHPSESSID' => 'edges'];
        $g->session_params = [];
        $g->zealphp_request = null;
    }

    protected function tearDown(): void
    {
        Registry::reset();
        AppKey::reset();
        App::componentBinder(new HtmxBinder());
    }

    private static function wire(string $html): string
    {
        preg_match('/name="_zc" value="([^"]+)"/', $html, $m);
        return html_entity_decode($m[1] ?? '', ENT_QUOTES);
    }

    // ── the class-shape version forces a reload after a deploy ──────

    public function testVersionChangesWithEveryShapeDetail(): void
    {
        $versions = [
            'base' => Registry::register((new class extends Component { public string $a = ''; #[Action] public function go(int $n): void {} public function render(): mixed { return ''; } })::class, 'v1')->version,
            'model' => Registry::register((new class extends Component { #[Model] public string $a = ''; #[Action] public function go(int $n): void {} public function render(): mixed { return ''; } })::class, 'v2')->version,
            'prop name' => Registry::register((new class extends Component { public string $b = ''; #[Action] public function go(int $n): void {} public function render(): mixed { return ''; } })::class, 'v3')->version,
            'action name' => Registry::register((new class extends Component { public string $a = ''; #[Action] public function run(int $n): void {} public function render(): mixed { return ''; } })::class, 'v4')->version,
            'param name' => Registry::register((new class extends Component { public string $a = ''; #[Action] public function go(int $m): void {} public function render(): mixed { return ''; } })::class, 'v5')->version,
            'param type' => Registry::register((new class extends Component { public string $a = ''; #[Action] public function go(string $n): void {} public function render(): mixed { return ''; } })::class, 'v6')->version,
            'no action' => Registry::register((new class extends Component { public string $a = ''; public function render(): mixed { return ''; } })::class, 'v7')->version,
        ];
        $this->assertCount(count($versions), array_unique($versions), json_encode($versions) ?: '');
        foreach ($versions as $v) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $v);
        }
    }

    public function testVersionIsStableForTheSameShape(): void
    {
        $a = (string) Registry::byClass(Signup::class)?->version;
        Registry::reset();
        $this->assertSame($a, Registry::register(Signup::class)->version);
    }

    public function testComponentWithRequiredConstructorArgumentsIsRefusedButOptionalOnesAreFine(): void
    {
        $ok = new class extends Component { public function __construct(int $x = 1) {} public function render(): mixed { return ''; } };
        $this->assertSame('opt', Registry::register($ok::class, 'opt')->name);
    }

    public function testStaticPropertiesAreNotState(): void
    {
        $c = new class extends Component { public static int $count = 0; public int $n = 0; public function render(): mixed { return ''; } };
        $this->assertSame(['n'], array_keys(Registry::register($c::class, 'stat')->props));
    }

    // ── Type ────────────────────────────────────────────────────────

    public function testTypeMessages(): void
    {
        $int = Type::fromReflection((new \ReflectionProperty(Signup::class, 'age'))->getType(), 'w');
        $bool = Type::fromReflection((new \ReflectionProperty(Signup::class, 'saved'))->getType(), 'w');
        foreach ([[fn() => $int->fromInput(null), 'is required'], [fn() => $bool->fromInput('maybe'), 'must be true or false']] as [$fn, $msg]) {
            try {
                $fn();
                $this->fail('accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($msg, $e->getMessage());
            }
        }
        $arr = Type::fromReflection((new \ReflectionProperty(Signup::class, 'tags'))->getType(), 'w');
        $this->expectExceptionMessage('w: stdClass cannot be stored in component state');
        Type::fromReflection((new \ReflectionProperty(Signup::class, 'email'))->getType(), 'w')->toSnapshot(new \stdClass(), 'w');
        unset($arr);
    }

    public function testIntEnumRejectsNumericPrefixes(): void
    {
        $fn = fn(Priority $p) => null;
        $t = Type::fromReflection((new \ReflectionFunction($fn))->getParameters()[0]->getType(), 'w');
        foreach (['9x', 'x9', ' 9'] as $bad) {
            try {
                $t->fromInput($bad);
                $this->fail("accepted {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(Priority::High, $t->fromInput('9'));
    }

    public function testArraysMayNestFourLevels(): void
    {
        $t = Type::fromReflection((new \ReflectionProperty(Signup::class, 'tags'))->getType(), 'w');
        $this->assertSame([[[['x']]]], $t->fromInput([[[['x']]]]));
    }

    // ── messages ───────────────────────────────────────────────────

    public function testValidationExceptionMessageAndCode(): void
    {
        $e = new ValidationException(['email' => 'bad', 'name' => 'short']);
        $this->assertSame('Validation failed: email, name', $e->getMessage());
        $this->assertSame(422, $e->getCode());
        $this->assertSame(['email' => 'bad', 'name' => 'short'], $e->errors);
    }

    public function testOversizedStateMessageStatesTheLimit(): void
    {
        $meta = Registry::byClass(Signup::class);
        $this->assertNotNull($meta);
        try {
            (new Snapshot('signup', 'signup-1', ['bio' => str_repeat('x', 70000)], $meta->version, ''))->encode();
            $this->fail('accepted');
        } catch (ComponentException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('Component state is too large (limit 65536 bytes); validate input sizes', $e->getMessage());
        }
    }

    // ── binders ────────────────────────────────────────────────────

    public function testBinderAsyncDetectionAndTriggerFallbacks(): void
    {
        $htmx = new HtmxBinder();
        $form = new FormBinder();
        $this->assertTrue($htmx->isAsync(['HTTP_HX_REQUEST' => 'true']));
        $this->assertTrue($htmx->isAsync(['HTTP_ZEAL_REQUEST' => '1']), 'htmx binder also accepts the protocol header');
        $this->assertFalse($htmx->isAsync([]));
        $this->assertFalse($htmx->isAsync(['HTTP_HX_REQUEST' => 'false']));
        $this->assertFalse($form->isAsync(['HTTP_HX_REQUEST' => 'true']));
        $this->assertSame('a', $form->triggerField(['HTTP_ZEAL_TRIGGER' => 'a']));
        $this->assertNull($form->triggerField(['HTTP_ZEAL_TRIGGER' => '']));
        $this->assertNull($form->triggerField([]));
        $this->assertSame('b', $htmx->triggerField(['HTTP_HX_TRIGGER_NAME' => 'b', 'HTTP_ZEAL_TRIGGER' => 'a']));
        $this->assertSame('a', $htmx->triggerField(['HTTP_HX_TRIGGER_NAME' => '', 'HTTP_ZEAL_TRIGGER' => 'a']));
    }

    public function testBinderResponseHeaders(): void
    {
        $form = (new FormBinder())->response(Outcome::Ok, [], null);
        $this->assertSame(['status' => 200, 'headers' => []], $form);
        $f2 = (new FormBinder())->response(Outcome::Invalid, ['saved' => ['n' => 'é/1']], '/next');
        $this->assertSame(422, $f2['status']);
        $this->assertSame('{"saved":{"n":"é/1"}}', $f2['headers']['Zeal-Events']);
        $this->assertSame('/next', $f2['headers']['Zeal-Redirect']);
        $h = (new HtmxBinder())->response(Outcome::Invalid, ['saved' => ['n' => 'é/1']], null);
        $this->assertSame(200, $h['status']);
        $this->assertSame(['HX-Trigger' => '{"saved":{"n":"é/1"}}'], $h['headers']);
        $this->assertSame([], (new HtmxBinder())->response(Outcome::Ok, [], null)['headers']);
    }

    public function testLiveAndBlurFieldsGetTheirOwnTriggers(): void
    {
        $html = (string) App::component(Signup::class, [], 'trig');
        $this->assertMatchesRegularExpression('/id="signup-trig-username"[^>]*hx-trigger="input changed delay:300ms"/', $html);
        $this->assertMatchesRegularExpression('/id="signup-trig-age"[^>]*hx-trigger="change"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="signup-trig-email"[^>]*hx-trigger/', $html, 'deferred fields send nothing on their own');
    }

    public function testActionAndTriggerJsonKeepUnicodeAndSlashes(): void
    {
        $c = new class extends Component {
            #[Model(live: true)] public string $é = '';
            #[Action] public function go(string $p = ''): void {}
            public function render(): mixed { return '<b ' . $this->action('go', ['p' => 'a/é']) . '></b>'; }
        };
        Registry::register($c::class, 'json-c');
        $html = (string) App::component('json-c');
        $this->assertStringContainsString('hx-vals="{&quot;_zc_args&quot;:&quot;{\\&quot;p\\&quot;:\\&quot;a/é\\&quot;}&quot;}"', $html);
    }

    public function testADivWithASubmitActionPostsThroughItsOwnForm(): void
    {
        $div = new class extends Component {
            protected ?string $submit = 'go';
            #[Action] public function go(): void {}
            public function render(): mixed { return ''; }
        };
        Registry::register($div::class, 'div-submit');
        $html = (string) App::component('div-submit');
        $this->assertStringStartsWith(
            '<div id="div-submit-1" data-zc="div-submit"><form method="post" action="/_zeal/c/div-submit/go" hx-post="/_zeal/c/div-submit/go" hx-target="#div-submit-1"',
            $html,
            'the hx-post is on the component\'s own form, not on the outer div'
        );
        $this->assertStringContainsString('id="div-submit-1-zc" hidden data-zc-form="div-submit-1"></form>', $html);
    }

    // ── endpoint outcomes ──────────────────────────────────────────

    public function testTriggerFallsBackToTheOnlyChangedField(): void
    {
        $html = (string) App::component(Signup::class, [], 'fb');
        // newsletter is ?bool (null): send '' so it stays null and only username changes.
        $one = Endpoint::handle('signup', '_update', ['_zc' => self::wire($html), 'username' => 'al', 'newsletter' => ''], ['HTTP_HX_REQUEST' => 'true']);
        $this->assertMatchesRegularExpression('/id="signup-fb-username"[^>]*hx-preserve="true"/', $one['body']);
        $two = Endpoint::handle('signup', '_update', ['_zc' => self::wire($html), 'username' => 'al', 'email' => 'x@y.z', 'newsletter' => ''], ['HTTP_HX_REQUEST' => 'true']);
        $this->assertStringNotContainsString('hx-preserve', $two['body'], 'two changed fields and no trigger named: preserve none');
    }

    public function testAddErrorWithoutValidateMarksTheResponseInvalid(): void
    {
        App::componentBinder(new FormBinder());
        $c = new class extends Component {
            #[Action] public function fail(): void { $this->addError('x', 'no'); }
            #[Action] public function fine(): void {}
            public function render(): mixed { return (string) $this->error('x'); }
        };
        Registry::register($c::class, 'adderr');
        $wire = self::wire((string) App::component('adderr'));
        $server = ['HTTP_ZEAL_REQUEST' => '1'];
        $this->assertSame(422, Endpoint::handle('adderr', 'fail', ['_zc' => $wire], $server)['status']);
        $this->assertSame(200, Endpoint::handle('adderr', 'fine', ['_zc' => $wire], $server)['status']);
        $failed = Endpoint::handle('adderr', 'fail', ['_zc' => $wire], $server)['body'];
        $this->assertSame(200, Endpoint::handle('adderr', '_refresh', ['_zc' => self::wire($failed)], $server)['status'], 'refresh is never invalid');
    }

    public function testNoJsRedirectFromAnActionWinsOverTheBackLink(): void
    {
        App::componentBinder(new FormBinder());
        $html = (string) App::component(Signup::class, [], 'rd');
        $r = Endpoint::handle('signup', 'leave', ['_zc' => self::wire($html)], ['HTTP_HOST' => 'app.test', 'HTTP_REFERER' => 'http://app.test/back']);
        $this->assertSame('/bye', $r['headers']['Location']);
    }

    public function testRefererWithAPortOnTheSameHostIsKept(): void
    {
        App::componentBinder(new FormBinder());
        $html = (string) App::component(Signup::class, [], 'port');
        $r = Endpoint::handle('signup', '_refresh', ['_zc' => self::wire($html)], ['HTTP_HOST' => 'App.Test:8080', 'HTTP_REFERER' => 'http://app.test:8080/p?q=1']);
        $this->assertSame('/p?q=1', $r['headers']['Location']);
    }

    public function testNoJsWithoutASessionDoesNotFailButCannotFlash(): void
    {
        App::componentBinder(new FormBinder());
        $g = RequestContext::instance();
        unset($g->session);
        $g->cookie = [];
        $html = (string) App::component(Signup::class, [], 'nosess');
        $r = Endpoint::handle('signup', '_refresh', ['_zc' => self::wire($html)], ['HTTP_HOST' => 'app.test', 'HTTP_ORIGIN' => 'http://app.test']);
        $this->assertSame(303, $r['status']);
        $this->assertNull(Endpoint::takeFlash('signup-nosess'));
        $g->session = [];
    }
}
