<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\Action;
use ZealPHP\Component\Attr;
use ZealPHP\Component\Component;
use ZealPHP\Component\Endpoint;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Model;
use ZealPHP\Component\Registry;
use ZealPHP\RequestContext;
use ZealPHP\Tests\Unit\Component\Fixtures\Size;

/** Edge cases of the Component base class, Attr, and Model. */
class ComponentHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        App::superglobals(false);
        AppKey::set(hash('sha256', 'k', true));
        Registry::reset();
        App::componentBinder(new HtmxBinder());
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = ['PHPSESSID' => 'helpers'];
    }

    protected function tearDown(): void
    {
        Registry::reset();
        AppKey::reset();
    }

    public function testGeneratorRenderIsConcatenated(): void
    {
        $c = new class extends Component {
            public function render(): mixed
            {
                return (function () {
                    yield '<a>';
                    yield (function () { yield 'b'; })();
                    yield null;
                    yield '</a>';
                })();
            }
        };
        Registry::register($c::class, 'gen');
        $this->assertStringContainsString('<a>b</a></div>', (string) App::component('gen'));
    }

    public function testRenderMustReturnStringish(): void
    {
        $c = new class extends Component {
            public function render(): mixed { return ['nope']; }
        };
        Registry::register($c::class, 'arr');
        $this->expectException(\LogicException::class);
        App::component('arr');
    }

    public function testInvalidTagIsRefused(): void
    {
        $c = new class extends Component {
            protected string $tag = 'div onclick=x';
            public function render(): mixed { return ''; }
        };
        Registry::register($c::class, 'tag');
        $this->expectException(\LogicException::class);
        App::component('tag');
    }

    public function testTagWithATrailingNewlineIsRefused(): void
    {
        $c = new class extends Component {
            protected string $tag = "div\n";
            public function render(): mixed { return ''; }
        };
        Registry::register($c::class, 'nltag');
        $this->expectException(\LogicException::class);
        App::component('nltag');
    }

    public function testSubmitMustNameAnAction(): void
    {
        $c = new class extends Component {
            protected string $tag = 'form';
            protected ?string $submit = 'missing';
            public function render(): mixed { return ''; }
        };
        Registry::register($c::class, 'sub');
        $this->expectException(\LogicException::class);
        App::component('sub');
    }

    public function testHelpersRefuseUnknownActionsAndModels(): void
    {
        $c = new class extends Component {
            public int $n = 0;
            public string $mode = 'x';
            public function render(): mixed
            {
                return $this->mode === 'action' ? (string) $this->action('nope') : (string) $this->model('n');
            }
        };
        Registry::register($c::class, 'help');
        try {
            App::component('help', ['mode' => 'action']);
            $this->fail('unknown action accepted');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('not an #[Action]', $e->getMessage());
        }
        $this->expectExceptionMessage('not a #[Model]');
        App::component('help', ['mode' => 'model']);
    }

    public function testDirectlyConstructedComponentExplains(): void
    {
        $c = new class extends Component {
            public function render(): mixed { return ''; }
        };
        $this->expectExceptionMessage('App::component()');
        $c->id() === '' && $c->name();
    }

    public function testValueAndMatchesHandleEnumsBoolsAndArrays(): void
    {
        $c = new class extends Component {
            public Size $size = Size::Large;
            public bool $on = true;
            public array $tags = ['a', 'b'];
            public ?int $n = null;
            public function render(): mixed
            {
                return implode('|', [
                    $this->value('size'), $this->value('on'), $this->value('tags'), $this->value('n'),
                    $this->checked('size', Size::Large), $this->checked('on'), $this->checked('tags', 'b'),
                    $this->selected('tags', 'z'), $this->selected('size', 's'),
                ]);
            }
        };
        Registry::register($c::class, 'vals');
        $this->assertStringContainsString('l|1|||checked|checked|checked||</div>', (string) App::component('vals'));
    }

    public function testRedirectOnlyAllowsSameSitePaths(): void
    {
        $c = new class extends Component {
            public string $to = '';
            #[Action] public function go(): void { $this->redirect($this->to); }
            public function render(): mixed { return ''; }
        };
        Registry::register($c::class, 'redir');
        foreach (['https://evil.test', '//evil.test', '/\\evil.test', 'relative', "/\t/evil.test", "/\n/evil.test", '/ok/\\x', '/a b'] as $to) {
            $html = (string) App::component('redir', ['to' => $to]);
            preg_match('/name="_zc" value="([^"]+)"/', $html, $m);
            try {
                Endpoint::handle('redir', 'go', ['_zc' => html_entity_decode($m[1])], ['HTTP_HX_REQUEST' => 'true']);
                $this->fail("redirect to {$to} accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSameSiteRedirectIsAllowed(): void
    {
        $c = new class extends Component {
            #[Action] public function go(): void { $this->redirect('/done?x=1#top'); }
            public function render(): mixed { return ''; }
        };
        Registry::register($c::class, 'redir-ok');
        $html = (string) App::component('redir-ok');
        preg_match('/name="_zc" value="([^"]+)"/', $html, $m);
        $r = Endpoint::handle('redir-ok', 'go', ['_zc' => html_entity_decode($m[1])], ['HTTP_HX_REQUEST' => 'true']);
        $this->assertSame('/done?x=1#top', $r['headers']['HX-Redirect']);
    }

    public function testPrivateUpdatedHooksAreRefusedAtRegistration(): void
    {
        $c = new class extends Component {
            #[Model] public string $a = '';
            private function updatedA(string $v): void {}
            public function render(): mixed { return ''; }
        };
        $this->expectExceptionMessage('protected or public');
        Registry::register($c::class, 'priv');
    }

    public function testHydrateRunsOnEveryEndpointRequestNotOnMount(): void
    {
        $c = new class extends Component {
            public int $hydrated = 0;
            #[Action] public function noop(): void {}
            public function hydrate(): void { $this->hydrated++; }
            public function render(): mixed { return '<i>' . $this->hydrated . '</i>'; }
        };
        Registry::register($c::class, 'hyd');
        $html = (string) App::component('hyd');
        $this->assertStringContainsString('<i>0</i>', $html);
        preg_match('/name="_zc" value="([^"]+)"/', $html, $m);
        $r = Endpoint::handle('hyd', 'noop', ['_zc' => html_entity_decode($m[1])], ['HTTP_HX_REQUEST' => 'true']);
        $this->assertStringContainsString('<i>1</i>', $r['body']);
    }

    public function testDefaultSubmitButtonJoinsTheComponentsOwnForm(): void
    {
        // Enter in a field of a div component submits its own (detached) form.
        $c = new class extends Component {
            protected ?string $submit = 'go';
            #[Action] public function go(): void {}
            public function render(): mixed { return ''; }
        };
        Registry::register($c::class, 'divsub');
        $this->assertStringContainsString(
            '<button type="submit" hidden tabindex="-1" aria-hidden="true" formaction="/_zeal/c/divsub/go" formmethod="post" form="divsub-1-zc"></button>',
            (string) App::component('divsub')
        );
    }

    public function testCheckedComparesAsStrings(): void
    {
        $c = new class extends Component {
            public int $n = 5;
            public array $ids = [1, 2];
            public bool $off = false;
            public function render(): mixed
            {
                return implode('|', [$this->checked('n', '5'), $this->checked('n', 6), $this->checked('ids', '2'),
                    $this->checked('ids', 3), $this->checked('off'), $this->checked('n', [5])]);
            }
        };
        Registry::register($c::class, 'cmp');
        $this->assertStringContainsString('checked||checked|||</div>', (string) App::component('cmp'));
    }

    public function testErrorHelpers(): void
    {
        $c = new class extends Component {
            #[Model(rules: 'required')] public string $a = '';
            public string $phase = '';
            public function render(): mixed
            {
                $this->addError('a', 'A bad');
                $this->addError('b', 'B bad');
                $before = $this->hasError('a') ? 'has' : 'none';
                $this->clearErrors('a');
                $after = implode(',', array_keys($this->errors()));
                $this->clearErrors();
                return $before . '/' . $after . '/' . count($this->errors()) . '/' . $this->error('zzz');
            }
        };
        Registry::register($c::class, 'errs');
        $this->assertStringContainsString('has/b/0/</div>', (string) App::component('errs'));
    }

    public function testAttrRefusesBadNamesAndJoinsClasses(): void
    {
        $this->assertSame('class="a b" disabled', Attr::render(Attr::merge(['class' => 'a'], ['class' => 'b', 'disabled' => true, 'x' => null, 'y' => false])));
        $this->expectException(\InvalidArgumentException::class);
        Attr::render(['on"click' => 'x']);
    }

    public function testAttrRefusesATrailingNewlineInTheName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Attr::render(["id\n" => 'x']);
    }

    public function testModelRejectsNegativeDebounce(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Model(live: true, debounce: -1);
    }

    public function testExtraAttributesAreMergedOntoTheRoot(): void
    {
        $c = new class extends Component {
            protected array $attributes = ['class' => 'card', 'data-x' => '"q"'];
            public function render(): mixed { return ''; }
        };
        Registry::register($c::class, 'attrs');
        $this->assertStringStartsWith('<div class="card" data-x="&quot;q&quot;" id="attrs-1" data-zc="attrs">', (string) App::component('attrs'));
    }
}
