<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Pins the echo side effect of `App::render()` (#509): a string result is
 * echoed AND returned, for BC with `App::render('_master', …)` statements in
 * `public/*.php`. Non-string results are returned without echo. These tests
 * capture what a caller sees in the output buffer and in the return value,
 * including the nested-template cases where the echo matters.
 */
class RenderEchoSemanticsTest extends TestCase
{
    private const DIR = 'tests/fixtures/render';

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(false);
        $g = RequestContext::instance();
        $g->memo = [];
        $g->status = null;
    }

    /**
     * @param callable(): mixed $fn
     * @return array{0: string, 1: mixed} [echoed output, return value]
     */
    private function capture(callable $fn): array
    {
        ob_start();
        try {
            $ret = $fn();
        } finally {
            $out = (string) ob_get_clean();
        }
        return [$out, $ret];
    }

    public function testEchoOnlyTemplateIsEchoedAndReturned(): void
    {
        [$out, $ret] = $this->capture(fn() => App::render('sem_child', [], self::DIR));
        $this->assertSame('[child]', $out);
        $this->assertSame('[child]', $ret);
    }

    public function testExplicitStringReturnIsEchoedAndReturned(): void
    {
        [$out, $ret] = $this->capture(fn() => App::render('sem_return_child', [], self::DIR));
        $this->assertSame('[ret-child]', $out);
        $this->assertSame('[ret-child]', $ret);
    }

    public function testEchoPlusReturnIsEchoedOnceInWireOrder(): void
    {
        [$out, $ret] = $this->capture(fn() => App::render('echo_and_return', [], self::DIR));
        $this->assertSame($ret, $out);
    }

    public function testNonStringResultsAreReturnedWithoutEcho(): void
    {
        [$out, $ret] = $this->capture(fn() => App::render('sem_int_child', [], self::DIR));
        $this->assertSame('', $out);
        $this->assertSame(404, $ret);

        [$out, $ret] = $this->capture(fn() => App::render('return_array', [], self::DIR));
        $this->assertSame('', $out);
        $this->assertIsArray($ret);

        [$out, $ret] = $this->capture(fn() => App::render('return_generator', [], self::DIR));
        $this->assertSame('', $out, 'a Generator is not consumed or echoed by render()');
        $this->assertInstanceOf(\Generator::class, $ret);
    }

    public function testRenderToStringNeverEchoes(): void
    {
        [$out, $ret] = $this->capture(fn() => App::renderToString('sem_child', [], self::DIR));
        $this->assertSame('', $out);
        $this->assertSame('[child]', $ret);
    }

    // ── nested rendering: a template rendering another template ──

    public function testNestedRenderAsStatementEmitsChildOnce(): void
    {
        [$out, $ret] = $this->capture(fn() => App::render('sem_parent_statement', [], self::DIR));
        $this->assertSame('A[child]B', $ret);
        $this->assertSame('A[child]B', $out);
    }

    public function testNestedRenderInShortEchoDuplicatesChild(): void
    {
        // Pitfall kept for BC: render() echoes the child into the parent's
        // buffer, then `<?=` echoes the returned copy. Use renderToString().
        [, $ret] = $this->capture(fn() => App::render('sem_parent_short_echo', [], self::DIR));
        $this->assertSame("A[child][child]B\n", $ret);
    }

    public function testNestedRenderToStringInShortEchoEmitsChildOnce(): void
    {
        [, $ret] = $this->capture(fn() => App::render('sem_parent_to_string', [], self::DIR));
        $this->assertSame("A[child]B\n", $ret);
    }

    public function testNestedRenderAssignedToVariableStillEchoesAtCallSite(): void
    {
        // Pitfall kept for BC: `$html = App::render(…)` echoes immediately, so
        // the child lands BEFORE "A" and again where $html is echoed.
        [, $ret] = $this->capture(fn() => App::render('sem_parent_assign', [], self::DIR));
        $this->assertSame('[child]A[child]B', $ret);
    }

    public function testNestedRenderStreamComposesInOrder(): void
    {
        $gen = App::renderStream('sem_parent_stream', [], self::DIR);
        $this->assertSame('A[child]B', implode('', iterator_to_array($gen, false)));
    }
}
