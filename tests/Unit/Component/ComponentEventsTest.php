<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\Action;
use ZealPHP\Component\Component;
use ZealPHP\Component\ComponentMeta;
use ZealPHP\Component\Endpoint;
use ZealPHP\Component\FormBinder;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\On;
use ZealPHP\Component\Outcome;
use ZealPHP\Component\Registry;
use ZealPHP\Component\ZealJsBinder;
use ZealPHP\RequestContext;

/** #[On] listeners: reflection rules, the _on endpoint, and listener markup. */
class ComponentEventsTest extends TestCase
{
    private const HTMX = ['HTTP_HX_REQUEST' => 'true', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

    protected function setUp(): void
    {
        App::superglobals(false);
        AppKey::set('base64:' . base64_encode(hash('sha256', 'events', true)));
        Registry::reset();
        App::componentBinder(new HtmxBinder());
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = ['PHPSESSID' => 'events'];
        $g->session_params = ['session_id' => 'events'];
        EvBadge::$items = 3;
        Registry::register(EvBadge::class, 'ev-badge');
    }

    protected function tearDown(): void
    {
        Registry::reset();
        AppKey::reset();
        App::componentBinder(new HtmxBinder());
        RequestContext::instance()->memo = [];
    }

    private static function wire(string $html): string
    {
        self::assertSame(1, preg_match('/name="_zc" value="([^"]+)"/', $html, $m));
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    public function testListenersAreReflected(): void
    {
        $meta = Registry::byName('ev-badge');
        $this->assertNotNull($meta);
        $this->assertSame([
            'cart-updated' => ['sync', 'count'],
            'cart:cleared' => ['sync'],
            'stock.changed' => ['count'],
            'late' => ['late'],
        ], $meta->listeners);
    }

    public function testOnValidatesEventNames(): void
    {
        foreach ([[''], ['has space'], [str_repeat('a', 65)], ["x\n"], []] as $events) {
            try {
                new On(...$events);
                $this->fail('accepted ' . json_encode($events));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['a', 'b:c'], (new On('a', 'b:c'))->events);
    }

    /** @return iterable<string, array{object, string}> */
    public static function badListeners(): iterable
    {
        yield 'protected' => [new class extends Component {
            #[On('x')] protected function p(): void {}
            public function render(): mixed { return ''; }
        }, 'must be on a public, non-static method'];
        yield 'static' => [new class extends Component {
            #[On('x')] public static function s(): void {}
            public function render(): mixed { return ''; }
        }, 'must be on a public, non-static method'];
        yield 'underscore' => [new class extends Component {
            #[On('x')] public function _u(): void {}
            public function render(): mixed { return ''; }
        }, 'must be on a public, non-static method'];
        yield 'required argument' => [new class extends Component {
            #[On('x')] public function r(int $n): void {}
            public function render(): mixed { return ''; }
        }, 'takes no arguments'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badListeners')]
    public function testBadListenersAreRefusedAtRegistration(object $component, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        /** @var class-string<Component> $class */
        $class = $component::class;
        ComponentMeta::reflect('bad', $class);
    }

    public function testOnRunsTheListenersForTheEventAndReRenders(): void
    {
        $html = (string) App::component(EvBadge::class, [], 'b');
        $this->assertStringContainsString('<span class="n">0</span><span class="c">0</span>', $html);

        $r = Endpoint::handle('ev-badge', Component::ON, ['_zc' => self::wire($html), '_zc_event' => 'cart-updated'], self::HTMX);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertStringContainsString('<span class="n">3</span><span class="c">1</span>', $r['body'], 'both listeners ran');

        $r = Endpoint::handle('ev-badge', Component::ON, ['_zc' => self::wire($r['body']), '_zc_event' => 'stock.changed'], self::HTMX);
        $this->assertStringContainsString('<span class="n">3</span><span class="c">2</span>', $r['body'], 'only count() listens for stock.changed');
    }

    public function testUnknownOrMissingEventsAre404(): void
    {
        $wire = self::wire((string) App::component(EvBadge::class, [], 'b'));
        foreach ([['_zc_event' => 'nope'], [], ['_zc_event' => ['cart-updated']]] as $extra) {
            $r = Endpoint::handle('ev-badge', Component::ON, ['_zc' => $wire] + $extra, self::HTMX);
            $this->assertSame(404, $r['status'], json_encode($extra) ?: '');
        }
    }

    public function testEachListenerIsAuthorizedByItsMethodName(): void
    {
        $wire = self::wire((string) App::component(EvBadge::class, ['deny' => 'count'], 'b'));
        $r = Endpoint::handle('ev-badge', Component::ON, ['_zc' => $wire, '_zc_event' => 'cart-updated'], self::HTMX);
        $this->assertSame(403, $r['status']);
        $this->assertSame('Not allowed: count', $r['body']);
        $r = Endpoint::handle('ev-badge', Component::ON, ['_zc' => $wire, '_zc_event' => 'cart:cleared'], self::HTMX);
        $this->assertSame(200, $r['status'], 'sync() alone is allowed');
    }

    public function testListenerMarkupPerBinder(): void
    {
        $html = (string) App::component(EvBadge::class, [], 'b');
        $this->assertStringContainsString(
            '<button type="button" hidden tabindex="-1" aria-hidden="true" form="ev-badge-b-zc" hx-trigger="cart-updated[!event.target.closest(&#039;#ev-badge-b&#039;)] from:body" hx-post="/_zeal/c/ev-badge/_on" hx-target="#ev-badge-b" hx-swap="outerHTML" hx-include="#ev-badge-b-zc" hx-vals="{&quot;_zc_event&quot;:&quot;cart-updated&quot;}" hx-sync="#ev-badge-b:queue last"></button>',
            $html
        );
        $this->assertSame(4, substr_count($html, 'hx-post="/_zeal/c/ev-badge/_on"'), 'one per event');
        $this->assertMatchesRegularExpression('#form="ev-badge-b-zc"><button type="button" hidden[^>]*cart-updated.*</button><span class="n">#s', $html, 'listeners sit after the snapshot and before the component\'s own markup');

        foreach ([new FormBinder(), new ZealJsBinder()] as $binder) {
            App::componentBinder($binder);
            RequestContext::instance()->memo = [];
            $this->assertStringContainsString(
                '<button type="button" hidden tabindex="-1" aria-hidden="true" form="ev-badge-b-zc" data-zc-on="stock.changed" data-zc-url="/_zeal/c/ev-badge/_on" data-zc-for="ev-badge-b"></button>',
                (string) App::component(EvBadge::class, [], 'b')
            );
        }
    }

    public function testDetailLessEventsGoOutAsObjects(): void
    {
        // htmx 4's trigger() reads detail.cancelled and throws on null.
        $r = (new HtmxBinder())->response(Outcome::Ok, ['a' => null, 'b' => ['id' => 1], 'c' => 5], null);
        $this->assertSame('{"a":{},"b":{"id":1},"c":5}', $r['headers']['HX-Trigger']);
    }
}

final class EvBadge extends Component
{
    public static int $items = 0;
    public int $n = 0;
    public int $hits = 0;
    public string $deny = '';

    #[On('cart-updated', 'cart:cleared')]
    public function sync(): void
    {
        $this->n = self::$items;
    }

    #[On('cart-updated')]
    #[On('stock.changed')]
    public function count(): void
    {
        $this->hits++;
    }

    #[Action]
    public function noop(): void
    {
    }

    public function authorize(string $action): bool
    {
        return $action !== $this->deny;
    }

    public function render(): mixed
    {
        return '<span class="n">' . $this->n . '</span><span class="c">' . $this->hits . '</span>';
    }

    /** A listener after methods that aren't: every method is scanned. */
    #[On('late')]
    public function late(): void
    {
    }
}
