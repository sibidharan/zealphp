<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Component\Registry;
use ZealPHP\RequestContext;

/** render() and component() resolve template names by one rule. */
class TemplateResolutionTest extends TestCase
{
    private string $prevCwd = '';

    protected function setUp(): void
    {
        $this->prevCwd = App::$cwd;
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        App::superglobals(false);
        Registry::reset();
        $g = RequestContext::instance();
        $g->memo = [];
        $g->server = ['PHP_SELF' => '/index.php'];
    }

    protected function tearDown(): void
    {
        App::$cwd = $this->prevCwd;
        RequestContext::instance()->server = [];
    }

    private static function page(string $self): void
    {
        RequestContext::instance()->server = ['PHP_SELF' => $self];
    }

    /** @return iterable<string, array{string, string, string}> page, name, expected markup */
    public static function lookups(): iterable
    {
        yield 'page folder first' => ['/users.php', 'row', 'page-folder row'];
        yield "then the page's components" => ['/users.php', 'pill', 'page pill'];
        yield 'shared components from another page' => ['/index.php', 'pill', 'shared pill'];
        yield 'then template/' => ['/index.php', 'row', 'top-level row'];
        yield 'then template/components/' => ['/index.php', 'card', '<article'];
        yield 'partial _name' => ['/index.php', 'note', 'top-level partial'];
        yield 'partial in components/' => ['/index.php', 'badge', 'badge'];
        yield 'folders in a name' => ['/index.php', 'ui/button', '<button'];
        yield 'absolute from template/' => ['/users.php', '/row', 'top-level row'];
        yield 'absolute into a folder' => ['/index.php', '/users/row', 'page-folder row'];
    }

    #[DataProvider('lookups')]
    public function testRenderAndComponentResolveTheSameFile(string $self, string $name, string $expected): void
    {
        self::page($self);
        $args = ['title' => 't', 'label' => 'l', 'text' => 'x'];
        $component = (string) App::component($name, $args);
        // component() also passes $props; give render() the same inputs.
        $render = App::render($name, $args + ['props' => $args], echo: false);
        $this->assertStringContainsString($expected, $component);
        $this->assertIsString($render);
        $this->assertSame($component, $render, 'one rule, one file');
    }

    /** @return iterable<string, array{array<string, string>, string}> server vars, expected */
    public static function pages(): iterable
    {
        yield 'a nested page uses its full path' => [['SCRIPT_NAME' => '/admin/users.php'], 'nested-page row'];
        yield 'then its basename folder (compatibility)' => [['SCRIPT_NAME' => '/other/users.php'], 'page-folder row'];
        yield 'PATH_INFO does not pick the folder' => [['SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php/users', 'PATH_INFO' => '/users'], 'top-level row'];
        yield 'PHP_SELF alone: PATH_INFO dropped' => [['PHP_SELF' => '/index.php/users', 'PATH_INFO' => '/users'], 'top-level row'];
        yield 'SCRIPT_NAME wins over PHP_SELF' => [['SCRIPT_NAME' => '/admin/users.php', 'PHP_SELF' => '/index.php'], 'nested-page row'];
        yield 'not a .php script: no page' => [['SCRIPT_NAME' => '/users'], 'top-level row'];
        yield 'dot-dot: no page' => [['SCRIPT_NAME' => '/../users.php'], 'top-level row'];
    }

    /** @param array<string, string> $server */
    #[DataProvider('pages')]
    public function testThePageFolderComesFromTheScriptPath(array $server, string $expected): void
    {
        RequestContext::instance()->server = $server;
        $this->assertStringContainsString($expected, (string) App::component('row'));
    }

    /**
     * The docs' walkthrough (templates-and-rendering.md, "From a public file
     * to the page"): the page's template is found in its folder, its own
     * components in its components/, shared ones in template/components/,
     * and a component inside a component resolves from the request's page.
     */
    public function testAPageRendersItsTemplateAndComponents(): void
    {
        RequestContext::instance()->server = ['SCRIPT_NAME' => '/invoices.php'];
        $html = (string) App::render('invoices', ['invoices' => [
            ['number' => 'A-1', 'amount' => 10],
            ['number' => 'A-2', 'amount' => 5],
        ]], echo: false);
        $this->assertStringContainsString('<h1>Invoices</h1>', $html);
        $this->assertStringContainsString('<article class="card"><h3>Open</h3><p>total 15</p>', $html, 'shared card, page component as its slot');
        $this->assertStringContainsString('<tr><td>A-1</td><td class="amount">10</td></tr>', $html, 'row, and the cell it renders, from the page\'s components/');
    }

    /**
     * A route has no page: a page's component needs its full name, shared
     * ones resolve by name. A component a route renders names its own
     * children in full too (row.php does), since relative names resolve from
     * the request's page, and a route has none.
     */
    public function testFromARouteAPagesComponentNeedsItsFullName(): void
    {
        RequestContext::instance()->server = [];
        $props = ['invoice' => ['number' => 'B-9', 'amount' => 1]];
        $this->assertStringContainsString('<td>B-9</td>', (string) App::component('/invoices/components/row', $props));
        $this->assertStringContainsString('<article class="card">', (string) App::component('card', ['title' => 't']));
        // Only in the page's components/: not found without the page.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('looked in template/total.php, template/_total.php, template/components/total.php');
        App::component('total', ['invoices' => []]);
    }

    public function testMissingTemplatesNameEveryPlaceLooked(): void
    {
        self::page('/users.php');
        try {
            App::render('nope', [], echo: false);
            $this->fail('expected TemplateUnavailableException');
        } catch (\ZealPHP\TemplateUnavailableException $e) {
            $this->assertStringContainsString('template/users/nope.php, template/users/_nope.php, template/users/components/nope.php, template/users/components/_nope.php, template/nope.php, template/_nope.php, template/components/nope.php, template/components/_nope.php', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('template/components/_nope.php');
        App::component('nope');
    }

    public function testTraversalStaysInsideTemplate(): void
    {
        $this->expectException(\LogicException::class);
        App::component('../app');
    }
}
