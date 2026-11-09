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
