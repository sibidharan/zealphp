<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Component\Component;
use ZealPHP\Component\Registry;
use ZealPHP\RequestContext;
use ZealPHP\SafeHtml;

/** A component produces content; the response half of the return contract is refused. */
class ComponentMarkupTest extends TestCase
{
    private string $prevCwd = '';

    protected function setUp(): void
    {
        $this->prevCwd = App::$cwd;
        App::$cwd = ZEALPHP_ROOT . '/tests/fixtures/components';
        App::superglobals(false);
        Registry::reset();
        RequestContext::instance()->memo = [];
    }

    protected function tearDown(): void
    {
        Registry::reset();
        App::$cwd = $this->prevCwd;
    }

    public function testContentShapes(): void
    {
        $this->assertSame('<b>Hi &amp; bye</b>!!!', (string) App::component('/returns/closure', ['title' => 'Hi & bye', 'n' => 3]), 'a Closure is called with its props');
        $this->assertSame('<b>x</b>!!', (string) App::component('/returns/closure', ['title' => 'x']), 'parameter defaults apply');
        $this->assertSame("<p>shell</p><p>body</p>", (string) App::component('/returns/echo_and_string'), 'echo then return, in order');
        $this->assertSame('', App::componentMarkup(null, 'x'));
        $this->assertSame('<i>s</i>', App::componentMarkup(new SafeHtml('<i>s</i>'), 'x'));
        $this->assertSame('ab', App::componentMarkup((static function () { yield 'a'; yield new SafeHtml('b'); yield null; })(), 'x'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function responseShapes(): iterable
    {
        yield 'status code' => ['/returns/status', 'returned the status code 404'];
        yield 'array' => ['/returns/json', 'returned an array (JSON)'];
        yield 'array inside a generator' => ['/returns/generator_array', 'returned an array (JSON)'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('responseShapes')]
    public function testResponseShapesAreRefusedNotPrinted(string $template, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Component {$template} {$message}");
        App::component($template);
    }

    public function testOtherValuesNameTheirType(): void
    {
        foreach ([[true, 'a bool'], [1.5, 'a float'], [new \stdClass(), 'a stdClass']] as [$value, $what]) {
            try {
                App::componentMarkup($value, 'W');
                $this->fail("accepted {$what}");
            } catch (\LogicException $e) {
                $this->assertStringStartsWith("W returned {$what}.", $e->getMessage());
                $this->assertStringContainsString('must come from the route or page that owns the response', $e->getMessage());
            }
        }
    }

    public function testReactiveRenderFollowsTheSameRule(): void
    {
        $c = new class extends Component {
            public function render(): mixed
            {
                return 404;
            }
        };
        Registry::register($c::class, 'returns-status');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($c::class . '::render() returned the status code 404');
        App::component('returns-status');
    }
}
