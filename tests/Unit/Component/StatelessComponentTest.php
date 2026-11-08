<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\AppKey;
use ZealPHP\Component\HtmxBinder;
use ZealPHP\Component\Registry;
use ZealPHP\RequestContext;
use ZealPHP\SafeHtml;
use ZealPHP\Tests\Unit\Component\Fixtures\Counter;
use ZealPHP\Tests\Unit\Component\Fixtures\WithBadge;

/** One App::component() API: unregistered names render stateless templates. */
class StatelessComponentTest extends TestCase
{
    private string $prevCwd = '';

    protected function setUp(): void
    {
        $this->prevCwd = App::$cwd;
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        App::superglobals(false);
        AppKey::set('base64:' . base64_encode(hash('sha256', 'stateless', true)));
        Registry::reset();
        App::componentBinder(new HtmxBinder());
        $g = RequestContext::instance();
        $g->memo = [];
        $g->session = [];
        $g->cookie = ['PHPSESSID' => 'stateless'];
    }

    protected function tearDown(): void
    {
        Registry::reset();
        AppKey::reset();
        App::$cwd = $this->prevCwd;
    }

    public function testRendersATemplateWithPropsAndReturnsSafeHtml(): void
    {
        $html = App::component('card', ['title' => '<Hi>']);
        $this->assertInstanceOf(SafeHtml::class, $html);
        $this->assertSame("<article class=\"card\"><h3>&lt;Hi&gt;</h3></article>\n", (string) $html);
        $this->assertStringNotContainsString('data-zc', (string) $html, 'no snapshot, no wrapper');
    }

    public function testSlotCarriesChildMarkup(): void
    {
        $inner = App::component('ui/button', ['text' => 'Go']);
        $html = (string) App::component('card', ['title' => 'T', 'slot' => $inner]);
        $this->assertStringContainsString('<h3>T</h3><button class="ui-btn">Go</button>', $html);
    }

    public function testFallsBackToUnderscorePartialAndExposesProps(): void
    {
        $this->assertSame("<span class=\"badge\">x|1</span>\n", (string) App::component('badge', ['label' => 'x']));
    }

    public function testFoldersResolve(): void
    {
        $this->assertStringContainsString('<button class="ui-btn">Ok</button>', (string) App::component('ui/button', ['text' => 'Ok']));
    }

    public function testRegisteredNameWinsOverATemplate(): void
    {
        App::components(['card' => Counter::class]);
        $this->assertStringContainsString('data-zc="card"', (string) App::component('card'));
    }

    public function testStatelessInsideReactiveIsAllowed(): void
    {
        App::components([WithBadge::class]);
        $this->assertStringContainsString('<span class="badge">new|1</span>', (string) App::component(WithBadge::class));
    }

    public function testFragmentPropIsNotASelector(): void
    {
        $html = (string) App::component('card', ['title' => 'F', 'fragment' => 'nope']);
        $this->assertStringContainsString('<h3>F</h3>', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'traversal' => ['../secrets'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'uppercase' => ['Card'];
        yield 'dot' => ['card.php'];
        yield 'empty segment' => ['ui//button'];
        yield 'missing' => ['nope'];
        yield 'missing in folder' => ['ui/nope'];
    }

    #[DataProvider('badNames')]
    public function testUnknownOrInvalidNamesThrow(string $name): void
    {
        $this->expectException(\LogicException::class);
        App::component($name);
    }

    public function testUnregisteredComponentClassStillExplainsRegistration(): void
    {
        $this->expectExceptionMessage('App::components()');
        App::component(Counter::class);
    }
}
