<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\Action;
use ZealPHP\Component\Component;
use ZealPHP\Component\Endpoint;
use ZealPHP\Component\FormBinder;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Model;
use ZealPHP\Component\Registry;
use ZealPHP\Component\Snapshot;
use ZealPHP\RequestContext;
use ZealPHP\Tests\Unit\Component\Fixtures\Priority;
use ZealPHP\Tests\Unit\Component\Fixtures\Size;

/** Behaviour of the Component base class that the round-trip tests don't pin. */
class ComponentContractTest extends TestCase
{
    private const HX = ['HTTP_HX_REQUEST' => 'true'];

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        App::superglobals(false);
        AppKey::set('base64:' . base64_encode(hash('sha256', 'contract', true)));
        Registry::reset();
        App::componentBinder(new HtmxBinder());
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = ['PHPSESSID' => 'contract'];
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

    public function testRulesAndMessagesOverridesAreUsed(): void
    {
        $c = new class extends Component {
            #[Model] public string $code = '';
            protected function rules(): array { return ['code' => 'required|min:3']; }
            protected function messages(): array { return ['code.min' => 'Code too short.']; }
            #[Action] public function save(): void { $this->validate(); }
            public function render(): mixed { return '<i ' . $this->model('code') . '></i>' . $this->error('code'); }
        };
        Registry::register($c::class, 'rules-c');
        $r = Endpoint::handle('rules-c', 'save', ['_zc' => self::wire((string) App::component('rules-c')), 'code' => 'ab'], self::HX);
        $this->assertStringContainsString('Code too short.', $r['body']);
    }

    public function testViewPassesErrorsAndAcceptsAnAbsolutePath(): void
    {
        $c = new class extends Component {
            public string $name = 'a<b';
            public function render(): mixed
            {
                $this->addError('name', 'x');
                $this->addError('other', 'y');
                return $this->view('/contract/errors');
            }
        };
        Registry::register($c::class, 'view-c');
        $this->assertStringContainsString('[2|has|a&lt;b]', (string) App::component('view-c'));
    }

    public function testActionArgumentsAreAJsonObjectWithReadableUnicodeAndSlashes(): void
    {
        $c = new class extends Component {
            #[Action] public function go(string $p = ''): void {}
            public function render(): mixed { return '<b ' . $this->action('go') . '></b><i ' . $this->action('go', ['p' => 'é/x']) . '></i>'; }
        };
        Registry::register($c::class, 'args-c');
        $html = (string) App::component('args-c');
        $this->assertStringContainsString('value="{}"', $html, 'no arguments is an object, not a list');
        $this->assertStringContainsString('value="{&quot;p&quot;:&quot;é/x&quot;}"', $html);
    }

    public function testHelperErrorMessagesNameTheComponent(): void
    {
        $c = new class extends Component {
            public string $mode = '';
            #[Model] public string $m = '';
            public function render(): mixed
            {
                return match ($this->mode) {
                    'action' => (string) $this->action('nope'),
                    'model' => (string) $this->model('mode'),
                    default => '',
                };
            }
        };
        Registry::register($c::class, 'msg-c');
        foreach (['action' => '::nope() is not an #[Action]', 'model' => '::$mode is not a #[Model] property'] as $mode => $suffix) {
            try {
                App::component('msg-c', ['mode' => $mode]);
                $this->fail("{$mode} accepted");
            } catch (\LogicException $e) {
                $this->assertSame($c::class . $suffix, $e->getMessage());
            }
        }
    }

    public function testConfigErrorMessagesNameTheComponent(): void
    {
        $tag = new class extends Component {
            protected string $tag = 'bad tag';
            public function render(): mixed { return ''; }
        };
        $submit = new class extends Component {
            protected string $tag = 'form';
            protected ?string $submit = 'missing';
            public function render(): mixed { return ''; }
        };
        Registry::register($tag::class, 'tag-c');
        Registry::register($submit::class, 'submit-c');
        foreach (['tag-c' => [$tag::class, ": invalid \$tag 'bad tag'"], 'submit-c' => [$submit::class, '::$submit names missing(), which is not an #[Action]']] as $name => [$class, $suffix]) {
            try {
                App::component($name);
                $this->fail("{$name} accepted");
            } catch (\LogicException $e) {
                $this->assertSame($class . $suffix, $e->getMessage());
            }
        }
        try {
            $tag->id() === '' && $tag->name();
            $this->fail('direct construction accepted');
        } catch (\LogicException $e) {
            $this->assertSame($tag::class . ' was created directly; render it with App::component()', $e->getMessage());
        }
    }

    public function testStateThatCantBeStoredNamesTheProperty(): void
    {
        $c = new class extends Component {
            public float $f = 0.0;
            public function render(): mixed { $this->f = INF; return ''; }
        };
        Registry::register($c::class, 'inf-c');
        $this->expectExceptionMessage($c::class . '::$f: INF/NAN cannot be stored in component state');
        App::component('inf-c');
    }

    public function testBadActionArgumentMessageNamesTheArgument(): void
    {
        $c = new class extends Component {
            #[Action] public function go(int $n): void {}
            public function render(): mixed { return ''; }
        };
        Registry::register($c::class, 'arg-c');
        $r = Endpoint::handle('arg-c', 'go', ['_zc' => self::wire((string) App::component('arg-c')), '_zc_args' => '{"n":"x"}'], self::HX);
        $this->assertSame(400, $r['status']);
        $this->assertSame('Argument $n must be a whole number', $r['body']);
    }

    public function testAFieldListedInTheSnapshotWithoutModelNeverAcceptsInput(): void
    {
        // Even a correctly signed snapshot whose field list names a non-#[Model]
        // property (a forged or stale build) can't open that property to input.
        $c = new class extends Component {
            public bool $admin = false;
            #[Model] public string $name = '';
            public function render(): mixed { return $this->admin ? 'ADMIN' : 'user'; }
        };
        $meta = Registry::register($c::class, 'priv-c');
        $wire = (new Snapshot('priv-c', 'priv-c-1', ['admin' => false, 'name' => ''], $meta->version, Snapshot::currentBinding(), ['admin', 'name']))->encode();
        $r = Endpoint::handle('priv-c', '_refresh', ['_zc' => $wire, 'admin' => '1', 'name' => 'x'], self::HX);
        $this->assertStringContainsString('user', $r['body']);
        $this->assertStringNotContainsString('ADMIN', $r['body']);
    }

    public function testRawTextShownBackForAnArrayValueIsEmpty(): void
    {
        $c = new class extends Component {
            #[Model] public int $n = 5;
            public function render(): mixed { return '<i ' . $this->model('n') . '></i>'; }
        };
        Registry::register($c::class, 'raw-c');
        $r = Endpoint::handle('raw-c', '_refresh', ['_zc' => self::wire((string) App::component('raw-c')), 'n' => ['x']], self::HX);
        $this->assertStringContainsString('name="n" id="raw-c-1-n" value=""', $r['body']);
    }

    public function testSeveralChangedFieldsAreAllValidatedAndHooked(): void
    {
        $c = new class extends Component {
            #[Model(rules: 'min:3')] public string $a = '';
            #[Model(rules: 'min:3')] public string $b = '';
            public int $hooks = 0;
            protected function updated(string $prop, mixed $value): void { $this->hooks++; }
            public function render(): mixed { return '<i ' . $this->model('a') . '></i><i ' . $this->model('b') . '></i>' . $this->error('a') . $this->error('b') . "[{$this->hooks}]"; }
        };
        Registry::register($c::class, 'multi-c');
        $r = Endpoint::handle('multi-c', '_update', ['_zc' => self::wire((string) App::component('multi-c')), 'a' => 'x', 'b' => 'y'], self::HX);
        $this->assertStringContainsString('A must be at least 3 characters.', $r['body']);
        $this->assertStringContainsString('B must be at least 3 characters.', $r['body']);
        $this->assertStringContainsString('[2]', $r['body']);
    }

    public function testEnumAndCheckboxComparisons(): void
    {
        $c = new class extends Component {
            public Priority $p = Priority::High;
            public array $tags = [1, '2'];
            public bool $on = false;
            public ?Size $s = null;
            public function render(): mixed
            {
                return implode('|', [
                    $this->value('p'), $this->checked('p', 9), $this->checked('p', Priority::Low), $this->checked('tags', 2),
                    $this->checked('tags', '1'), $this->checked('on', true), $this->checked('on', false), $this->value('s'),
                    $this->selected('tags', [1]),
                ]) . '|';
            }
        };
        Registry::register($c::class, 'cmp-c');
        $this->assertStringContainsString('9|checked||checked|checked||checked|||</div>', (string) App::component('cmp-c'), 'a false bool matches the false option (radio)');
    }

    public function testValueEscapesByDefault(): void
    {
        $c = new class extends Component {
            public string $t = '"<x>';
            public function render(): mixed { return $this->value('t') . '#' . $this->value('t', false); }
        };
        Registry::register($c::class, 'esc-c');
        $this->assertStringContainsString('&quot;&lt;x&gt;#"<x></div>', (string) App::component('esc-c'));
    }

    public function testFormBinderRootPostsToRefreshWithoutASubmitAction(): void
    {
        App::componentBinder(new FormBinder());
        $c = new class extends Component {
            protected string $tag = 'form';
            public function render(): mixed { return ''; }
        };
        Registry::register($c::class, 'plain-form');
        $this->assertStringStartsWith('<form method="post" action="/_zeal/c/plain-form/_refresh"', (string) App::component('plain-form'));
    }
}
