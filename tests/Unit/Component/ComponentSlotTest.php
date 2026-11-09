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
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Registry;
use ZealPHP\Component\Snapshot;
use ZealPHP\RequestContext;
use ZealPHP\SafeHtml;

/** A reactive child's slot: markup its parent passes in. */
class ComponentSlotTest extends TestCase
{
    private const HTMX = ['HTTP_HX_REQUEST' => 'true', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

    protected function setUp(): void
    {
        App::superglobals(false);
        AppKey::set('base64:' . base64_encode(hash('sha256', 'slots', true)));
        Registry::reset();
        App::componentBinder(new HtmxBinder());
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = ['PHPSESSID' => 'slots'];
        $g->session_params = ['session_id' => 'slots'];
        Registry::register(SlotPanel::class, 'slot-panel');
        Registry::register(SlotPage::class, 'slot-page');
        Registry::register(SlotView::class, 'slot-view');
    }

    protected function tearDown(): void
    {
        Registry::reset();
        AppKey::reset();
        RequestContext::instance()->memo = [];
    }

    /** @return array<string, mixed> */
    private static function formFields(string $html, string $formId): array
    {
        preg_match_all('/<input [^>]*>/', $html, $m);
        $query = [];
        foreach ($m[0] as $tag) {
            if (str_contains($tag, 'form="' . $formId . '"') && preg_match('/name="([^"]+)"/', $tag, $n) === 1 && preg_match('/value="([^"]*)"/', $tag, $v) === 1) {
                $query[] = rawurlencode(html_entity_decode($n[1])) . '=' . rawurlencode(html_entity_decode($v[1]));
            }
        }
        parse_str(implode('&', $query), $fields);
        /** @var array<string, mixed> $fields */
        return $fields;
    }

    public function testASlotSurvivesTheChildsOwnUpdatesAndFollowsTheParent(): void
    {
        $page = (string) App::component(SlotPage::class, [], 'p');
        $this->assertStringContainsString('<section class="body"><p>Hello <b>v1</b></p></section><em>0</em>', $page);

        // The panel updates itself: its slot comes from its snapshot.
        $r = Endpoint::handle('slot-panel', 'open', self::formFields($page, 'slot-page-p-slot-panel-x-zc'), self::HTMX);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertStringContainsString('<section class="body"><p>Hello <b>v1</b></p></section><em>1</em>', $r['body']);

        // The page re-renders with a new slot: the panel takes it, keeps its state.
        $page = (string) preg_replace('/<div id="slot-page-p-slot-panel-x".*<!--\/panel--><\/div>/s', strtr($r['body'], ['\\' => '\\\\', '$' => '\\$']), $page);
        $r = Endpoint::handle('slot-page', 'bump', self::formFields($page, 'slot-page-p-zc'), self::HTMX);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertStringContainsString('<section class="body"><p>Hello <b>v2</b></p></section><em>1</em>', $r['body']);
    }

    public function testAComponentWithoutASlotGetsNull(): void
    {
        $html = (string) App::component(SlotPanel::class, [], 'alone');
        $this->assertStringContainsString('<section class="body">none</section>', $html);
        $wire = self::formFields($html, 'slot-panel-alone-zc')['_zc'] ?? '';
        $this->assertIsString($wire);
        $json = (string) base64_decode(strtr(explode('.', $wire)[0], '-_', '+/'));
        $this->assertStringNotContainsString('"sl"', $json, 'nothing stored without a slot');
        $meta = Registry::byName('slot-panel');
        $this->assertNotNull($meta);
        $this->assertNull(Snapshot::decode($wire, $meta)->slot);
    }

    public function testSlotsCantHoldReactiveComponentsOrNonMarkup(): void
    {
        foreach ([
            [new SafeHtml('<div id="x" data-zc="other"></div>'), 'a slot can\'t contain reactive components'],
            [['not markup'], 'the slot prop must be markup'],
        ] as [$slot, $message]) {
            try {
                App::component(SlotPanel::class, ['slot' => $slot], 'bad');
                $this->fail($message);
            } catch (\LogicException $e) {
                $this->assertSame(SlotPanel::class . ': ' . $message . ($message === 'the slot prop must be markup' ? ' (a string or SafeHtml)' : ' (their state would be frozen in it); render them in the parent instead'), $e->getMessage());
            }
        }
        $this->assertStringContainsString('<section class="body">plain &amp; string</section>', (string) App::component(SlotPanel::class, ['slot' => 'plain &amp; string'], 'ok'));
    }

    public function testATemplateViewGetsTheSlot(): void
    {
        $cwd = App::$cwd;
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        try {
            $this->assertStringContainsString('[<b>hi</b>|<b>hi</b>]', (string) App::component(SlotView::class, ['slot' => new SafeHtml('<b>hi</b>')], 'v'));
            $this->assertStringContainsString('[none|none]', (string) App::component(SlotView::class, [], 'w'));
        } finally {
            App::$cwd = $cwd;
        }
    }

    public function testSlotIsAReservedName(): void
    {
        $c = new class extends Component {
            public string $slot = '';
            public function render(): mixed { return ''; }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the name is reserved (c, errors, slot, and names starting with _)');
        /** @var class-string<Component> $class */
        $class = $c::class;
        ComponentMeta::reflect('slotted', $class);
    }
}

final class SlotPage extends Component
{
    public int $version = 1;

    #[Action]
    public function bump(): void
    {
        $this->version++;
    }

    public function render(): mixed
    {
        return App::component(SlotPanel::class, ['slot' => new SafeHtml('<p>Hello <b>v' . $this->version . '</b></p>')], 'x');
    }
}

final class SlotPanel extends Component
{
    public int $opened = 0;

    #[Action]
    public function open(): void
    {
        $this->opened++;
    }

    public function render(): mixed
    {
        $slot = $this->slot();
        return '<section class="body">' . ($slot === null ? 'none' : (string) $slot) . '</section><em>' . $this->opened . '</em><!--/panel-->';
    }
}

final class SlotView extends Component
{
    public function render(): mixed
    {
        return $this->view('/contract/slot');
    }
}
