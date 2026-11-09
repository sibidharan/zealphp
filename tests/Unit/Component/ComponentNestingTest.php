<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\Action;
use ZealPHP\Component\Component;
use ZealPHP\Component\Endpoint;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Model;
use ZealPHP\Component\Reactive;
use ZealPHP\Component\Registry;
use ZealPHP\RequestContext;

/** Reactive components inside reactive components: isolation, identity, #[Reactive]. */
class ComponentNestingTest extends TestCase
{
    private const HTMX = ['HTTP_HX_REQUEST' => 'true', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

    protected function setUp(): void
    {
        App::superglobals(false);
        AppKey::set('base64:' . base64_encode(hash('sha256', 'nesting', true)));
        Registry::reset();
        App::componentBinder(new HtmxBinder());
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = ['PHPSESSID' => 'nesting'];
        $g->session_params = ['session_id' => 'nesting'];

        Registry::register(NestList::class, 'nest-list');
        Registry::register(NestRow::class, 'nest-row');
        Registry::register(NestCell::class, 'nest-cell');
    }

    protected function tearDown(): void
    {
        Registry::reset();
        AppKey::reset();
        RequestContext::instance()->memo = [];
    }

    /**
     * What a browser submits for one form: every control whose form="" is
     * that form (hidden inputs; this markup has no other values to read).
     *
     * @return array<string, mixed>
     */
    private static function formFields(string $html, string $formId): array
    {
        preg_match_all('/<input [^>]*>/', $html, $m);
        $query = [];
        foreach ($m[0] as $tag) {
            if (!str_contains($tag, 'form="' . $formId . '"')
                || preg_match('/name="([^"]+)"/', $tag, $n) !== 1
                || preg_match('/value="([^"]*)"/', $tag, $v) !== 1) {
                continue;
            }
            $query[] = rawurlencode(html_entity_decode($n[1])) . '=' . rawurlencode(html_entity_decode($v[1]));
        }
        parse_str(implode('&', $query), $fields);
        /** @var array<string, mixed> $fields */
        return $fields;
    }

    /** @param array<string, mixed> $extra */
    private static function act(string $name, string $action, string $html, string $formId, array $extra = []): string
    {
        $r = Endpoint::handle($name, $action, $extra + self::formFields($html, $formId), self::HTMX);
        self::assertSame(200, $r['status'], $r['body']);
        return $r['body'];
    }

    private static function shownCount(string $html, string $id): string
    {
        self::assertSame(1, preg_match('/id="' . preg_quote($id, '/') . '"[^>]*>.*?<b>(\d+)<\/b>/s', $html, $m), "count of {$id}");
        return $m[1];
    }

    public function testEachComponentOwnsAFormAndChildrenRideInTheParents(): void
    {
        $html = (string) App::component(NestList::class, [], 'l');
        $this->assertStringContainsString('<div id="nest-list-l" data-zc="nest-list"><form ', $html);
        $this->assertStringContainsString('id="nest-list-l-nest-row-a" data-zc="nest-row"', $html, 'child ids are scoped by the parent');
        $this->assertStringContainsString('id="nest-list-l-nest-row-a-zc" hidden', $html);

        $parent = self::formFields($html, 'nest-list-l-zc');
        $this->assertIsString($parent['_zc'] ?? null);
        $this->assertSame(
            ['nest-list-l-nest-row-a', 'nest-list-l-nest-row-a-nest-cell-x', 'nest-list-l-nest-row-b'],
            array_keys(is_array($parent['_zc_kids'] ?? null) ? $parent['_zc_kids'] : []),
            'every descendant rides in the parent\'s form'
        );
        $row = self::formFields($html, 'nest-list-l-nest-row-a-zc');
        $this->assertSame(['nest-list-l-nest-row-a-nest-cell-x'], array_keys(is_array($row['_zc_kids'] ?? null) ? $row['_zc_kids'] : []));
        $this->assertNotSame($parent['_zc'], $row['_zc'] ?? null, 'a child request carries the child\'s own snapshot');
        $this->assertStringContainsString('hx-include="#nest-list-l-nest-row-a-zc"', $html, 'a child button includes only its own form');
    }

    public function testAChildKeepsItsStateWhenTheParentReRenders(): void
    {
        $page = (string) App::component(NestList::class, [], 'l');
        // The row bumps its own count three times.
        $rowId = 'nest-list-l-nest-row-a';
        $rowHtml = $page;
        for ($i = 0; $i < 3; $i++) {
            $rowHtml = self::act('nest-row', 'bump', $rowHtml, $rowId . '-zc');
        }
        $this->assertSame('3', self::shownCount($rowHtml, $rowId));
        // The row's response carries its new snapshot into the list's form.
        $this->assertStringContainsString('name="_zc_kids[' . $rowId . ']"', $rowHtml);
        $this->assertStringContainsString('form="nest-list-l-zc"', $rowHtml);

        // The page now holds the swapped-in row; the list re-renders.
        $page = (string) preg_replace('/<div id="' . $rowId . '" data-zc="nest-row">.*?<!--\/row-->/s', strtr($rowHtml, ['\\' => '\\\\', '$' => '\\$']), $page);
        $list = self::act('nest-list', 'rename', $page, 'nest-list-l-zc');
        $this->assertSame('3', self::shownCount($list, $rowId), 'the row kept its count');
        $this->assertSame('0', self::shownCount($list, 'nest-list-l-nest-row-b'));
        $this->assertStringContainsString('<i>A!</i>', $list, '#[Reactive] label followed the parent');
    }

    public function testWithoutCarriedSnapshotsChildrenMountFresh(): void
    {
        $page = (string) App::component(NestList::class, [], 'l');
        $fields = self::formFields($page, 'nest-list-l-zc');
        unset($fields['_zc_kids']);
        $r = Endpoint::handle('nest-list', 'rename', $fields, self::HTMX);
        $this->assertSame(200, $r['status']);
        $this->assertSame('0', self::shownCount($r['body'], 'nest-list-l-nest-row-a'));
    }

    public function testACarriedSnapshotForAnotherIdIsIgnored(): void
    {
        $page = (string) App::component(NestList::class, [], 'l');
        $rowB = self::act('nest-row', 'bump', $page, 'nest-list-l-nest-row-b-zc');
        $fields = self::formFields($page, 'nest-list-l-zc');
        $kids = is_array($fields['_zc_kids'] ?? null) ? $fields['_zc_kids'] : [];
        // Row b's (valid, signed) state offered as row a's.
        $kids['nest-list-l-nest-row-a'] = self::formFields($rowB, 'nest-list-l-nest-row-b-zc')['_zc'] ?? '';
        $kids['nest-list-l-nest-row-b'] = 'not-a-snapshot';
        $fields['_zc_kids'] = $kids;
        $r = Endpoint::handle('nest-list', 'rename', $fields, self::HTMX);
        $this->assertSame(200, $r['status']);
        $this->assertSame('0', self::shownCount($r['body'], 'nest-list-l-nest-row-a'), 'a snapshot is only used for its own id');
        $this->assertSame('0', self::shownCount($r['body'], 'nest-list-l-nest-row-b'), 'a broken one mounts fresh');
    }

    public function testNoJsParentActionFlashesItsChildren(): void
    {
        $page = (string) App::component(NestList::class, [], 'l');
        $page = (string) preg_replace(
            '/<div id="nest-list-l-nest-row-a" data-zc="nest-row">.*?<!--\/row-->/s',
            strtr(self::act('nest-row', 'bump', $page, 'nest-list-l-nest-row-a-zc'), ['\\' => '\\\\', '$' => '\\$']),
            $page
        );
        $r = Endpoint::handle('nest-list', 'rename', self::formFields($page, 'nest-list-l-zc'), ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_REFERER' => '/']);
        $this->assertSame(303, $r['status']);
        RequestContext::instance()->memo = [];
        $again = (string) App::component(NestList::class, [], 'l');
        $this->assertSame('1', self::shownCount($again, 'nest-list-l-nest-row-a'), 'the page after the redirect shows the row\'s state');
        $this->assertStringContainsString('<i>A!</i>', $again);
    }

    public function testChildrenNeedKeysAndFormsDoNotNest(): void
    {
        $noKey = new class extends Component {
            public function render(): mixed { return App::component('nest-row', ['label' => 'x']); }
        };
        Registry::register($noKey::class, 'no-key');
        try {
            App::component('no-key');
            $this->fail('a child without a key');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('a key', $e->getMessage());
        }

        $formParent = new class extends Component {
            protected string $tag = 'form';
            public function render(): mixed { return App::component('nest-row', ['label' => 'x'], 'k'); }
        };
        Registry::register($formParent::class, 'form-parent');
        try {
            App::component('form-parent');
            $this->fail('a form root with a child');
        } catch (\LogicException $e) {
            $this->assertSame("form-parent-1 has \$tag = 'form', so it can't contain the reactive component " . NestRow::class . '; use the default $tag (div)', $e->getMessage());
        }

        $formChild = new class extends Component {
            protected string $tag = 'form';
            public function render(): mixed { return ''; }
        };
        Registry::register($formChild::class, 'form-child');
        $parent = new class extends Component {
            public function render(): mixed { return App::component('form-child', [], 'k'); }
        };
        Registry::register($parent::class, 'plain-parent');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($formChild::class . ": a nested component can't use \$tag = 'form' (forms can't nest); use the default \$tag (div)");
        App::component('plain-parent');
    }

    public function testDepthIsLimitedToEight(): void
    {
        Registry::register(NestDeep::class, 'nest-deep');
        $html = (string) App::component(NestDeep::class, ['level' => 0, 'stop' => 7], 'top');
        $this->assertSame(8, substr_count($html, 'data-zc="nest-deep"'), 'eight levels render');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Reactive components nest at most 8 deep');
        App::component(NestDeep::class, ['level' => 0, 'stop' => 8], 'top');
    }

    public function testCarriedAndSnapshotInputsAreExact(): void
    {
        $html = (string) App::component(NestList::class, [], 'l');
        $this->assertMatchesRegularExpression(
            '#<input type="hidden" name="_zc" value="[^"]+" form="nest-list-l-nest-row-a-zc"><input type="hidden" name="_zc_kids\[nest-list-l-nest-row-a\]" value="[^"]+" form="nest-list-l-zc"><button type="submit" hidden tabindex="-1" aria-hidden="true" formaction="/_zeal/c/nest-row/bump" formmethod="post" form="nest-list-l-nest-row-a-zc"></button><i>#',
            $html
        );
        $this->assertStringContainsString('hx-include="#nest-list-l-nest-row-a-zc" hx-vals=', $html);
        $this->assertStringContainsString('hx-target="#nest-list-l-nest-row-a" hx-swap="outerHTML" hx-include="#nest-list-l-nest-row-a-zc" hx-sync="#nest-list-l-nest-row-a:queue last"', $html, 'a live field includes its own component\'s form');
    }

    public function testCarriedSnapshotsAreValidatedAndCapped(): void
    {
        $page = (string) App::component(NestList::class, [], 'l');
        $fields = self::formFields($page, 'nest-list-l-zc');
        $kids = [];
        for ($i = 0; $i < 257; $i++) {
            $kids['k' . $i] = 'w' . $i;
        }
        $kids += [5 => 'int key', 'bad id' => 'x', "nl\n" => 'x', 'arr' => ['x']];
        $fields['_zc_kids'] = $kids;
        Endpoint::handle('nest-list', 'rename', $fields, self::HTMX);
        $this->assertSame('w0', Endpoint::carriedSnapshot('k0'));
        $this->assertSame('w1', Endpoint::carriedSnapshot('k1'), 'more than one is kept');
        $this->assertSame('w255', Endpoint::carriedSnapshot('k255'));
        $this->assertNull(Endpoint::carriedSnapshot('k256'), 'at most 256');

        $fields['_zc_kids'] = [5 => 'int key', 'bad id' => 'x', "nl\n" => 'x', 'x!' => 'x', 'arr' => ['x'], 'ok' => 'w'];
        Endpoint::handle('nest-list', 'rename', $fields, self::HTMX);
        foreach (['5', 'bad id', "nl\n", 'x!', 'arr'] as $id) {
            $this->assertNull(Endpoint::carriedSnapshot($id), json_encode($id) ?: '');
        }
        $this->assertSame('w', Endpoint::carriedSnapshot('ok'));
    }

    public function testSnapshotAncestorsRoundTripAndAreSanitised(): void
    {
        $meta = Registry::byName('nest-row');
        $this->assertNotNull($meta);
        $wire = (new \ZealPHP\Component\Snapshot('nest-row', 'r', ['label' => 'é/x', 'count' => 0, 'note' => ''], $meta->version, \ZealPHP\Component\Snapshot::currentBinding(), ancestors: ['a-zc', 'b-zc']))->encode();
        $json = (string) base64_decode(strtr(explode('.', $wire)[0], '-_', '+/'));
        $this->assertStringContainsString('"label":"é/x"', $json, 'unescaped, as before');
        $this->assertStringContainsString('"a":["a-zc","b-zc"]', $json);
        $this->assertSame(['a-zc', 'b-zc'], \ZealPHP\Component\Snapshot::decode($wire, $meta)->ancestors);
        $plain = (new \ZealPHP\Component\Snapshot('nest-row', 'r', ['label' => '', 'count' => 0, 'note' => ''], $meta->version, \ZealPHP\Component\Snapshot::currentBinding()))->encode();
        $this->assertStringNotContainsString('"a":', (string) base64_decode(strtr(explode('.', $plain)[0], '-_', '+/')), 'omitted when empty');

        // A signed snapshot with junk in "a": non-strings dropped, list re-indexed.
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $data['a'] = [5, 'x-zc', ['y'], 'z-zc'];
        $raw = (string) json_encode($data);
        $key = \ZealPHP\AppKey::derive((string) (new \ReflectionClassConstant(\ZealPHP\Component\Snapshot::class, 'PURPOSE'))->getValue());
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $forged = $b64($raw) . '.' . $b64(hash_hmac('sha256', $raw, $key, true));
        $this->assertSame(['x-zc', 'z-zc'], \ZealPHP\Component\Snapshot::decode($forged, $meta)->ancestors);
    }
}

final class NestList extends Component
{
    public string $suffix = '';

    #[Action]
    public function rename(): void
    {
        $this->suffix = '!';
    }

    public function render(): mixed
    {
        return '<button ' . $this->action('rename') . '>rename</button>'
            . App::component('nest-row', ['label' => 'A' . $this->suffix], 'a')
            . App::component('nest-row', ['label' => 'B' . $this->suffix], 'b');
    }
}

final class NestRow extends Component
{
    protected ?string $submit = 'bump';
    #[Reactive]
    public string $label = '';
    public int $count = 0;
    #[Model(live: true)]
    public string $note = '';

    #[Action]
    public function bump(): void
    {
        $this->count++;
    }

    public function render(): mixed
    {
        $cell = $this->label === 'A' || $this->label === 'A!' ? (string) App::component('nest-cell', [], 'x') : '';
        return '<i>' . \ZealPHP\e($this->label) . '</i><b>' . $this->count . '</b>'
            . '<button ' . $this->action('bump') . '>+</button><input ' . $this->model('note') . '>' . $cell . '<!--/row-->';
    }
}

final class NestCell extends Component
{
    public int $n = 0;

    public function render(): mixed
    {
        return '<s>' . $this->n . '</s>';
    }
}

final class NestDeep extends Component
{
    public int $level = 0;
    public int $stop = 0;

    public function render(): mixed
    {
        return $this->level >= $this->stop ? '' : (string) App::component('nest-deep', ['level' => $this->level + 1, 'stop' => $this->stop], 'k' . $this->level);
    }
}
