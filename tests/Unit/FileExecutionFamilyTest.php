<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Regression coverage for the file-execution family + universal return
 * contract in src/App.php (executeFile, render, renderToString, renderStream,
 * fragment). Driven by fixtures under tests/fixtures/render/ passed as the
 * template dir, so everything runs in-process (no server, no socket).
 */
class FileExecutionFamilyTest extends TestCase
{
    private const DIR = 'tests/fixtures/render';

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(false); // coroutine mode → executeFile runs in-process
        $g = RequestContext::instance();
        $g->memo = [];
        $g->status = null;
    }

    // ── universal return contract via render() ───────────────────

    public function testEchoOnlySurfacesBufferedOutput(): void
    {
        $this->assertSame('hello-echo', App::render('echo_only', [], self::DIR));
    }

    public function testExplicitStringReturn(): void
    {
        $this->assertSame('explicit-string', App::render('return_string', [], self::DIR));
    }

    public function testIntReturnPassesThrough(): void
    {
        $this->assertSame(404, App::render('return_int', [], self::DIR));
    }

    public function testArrayReturnPassesThrough(): void
    {
        $this->assertSame(['ok' => true, 'n' => 7], App::render('return_array', [], self::DIR));
    }

    public function testVoidReturnSurfacesBufferedOutput(): void
    {
        $this->assertSame('hello-void', App::render('void_return', [], self::DIR));
    }

    public function testEchoThenReturnConcatenatesInWireOrder(): void
    {
        $this->assertSame('SHELL-BODY', App::render('echo_and_return', [], self::DIR));
    }

    public function testClosureReturnIsInvokedWithParamInjection(): void
    {
        $this->assertSame('closure-world', App::render('return_closure', [], self::DIR));
    }

    public function testClosureReturnHonoursInjectedArg(): void
    {
        $this->assertSame('closure-daisy', App::render('return_closure', ['name' => 'daisy'], self::DIR));
    }

    public function testGeneratorReturnIsStreamable(): void
    {
        $gen = App::render('return_generator', [], self::DIR);
        $this->assertInstanceOf(\Generator::class, $gen);
        $this->assertSame(['g1', 'g2'], iterator_to_array($gen, false));
    }

    public function testArgsAreExtractedIntoScope(): void
    {
        $this->assertSame('arg=injected-value', App::render('uses_args', ['injected' => 'injected-value'], self::DIR));
        $this->assertSame('arg=none', App::render('uses_args', [], self::DIR));
    }

    public function testHaltExceptionPreservesBufferedOutput(): void
    {
        // throw HaltException after echo → buffered output surfaces as body.
        $this->assertSame('before-halt', App::render('halts', [], self::DIR));
    }

    // ── renderToString coercion ──────────────────────────────────

    public function testRenderToStringCoercesEcho(): void
    {
        $this->assertSame('hello-echo', App::renderToString('echo_only', [], self::DIR));
    }

    public function testRenderToStringCoercesGenerator(): void
    {
        $this->assertSame('g1g2', App::renderToString('return_generator', [], self::DIR));
    }

    public function testRenderToStringCoercesArrayToJson(): void
    {
        $out = App::renderToString('return_array', [], self::DIR);
        $this->assertJson($out);
        $this->assertSame(['ok' => true, 'n' => 7], json_decode($out, true));
    }

    // ── renderStream ─────────────────────────────────────────────

    public function testRenderStreamYieldsChunks(): void
    {
        $gen = App::renderStream('return_generator', [], self::DIR);
        $this->assertInstanceOf(\Generator::class, $gen);
        $this->assertSame('g1g2', implode('', iterator_to_array($gen, false)));
    }

    public function testRenderStreamOfEchoTemplateYieldsOneChunk(): void
    {
        $gen = App::renderStream('echo_only', [], self::DIR);
        $this->assertSame('hello-echo', implode('', iterator_to_array($gen, false)));
    }

    // ── fragments ────────────────────────────────────────────────

    public function testFragmentExtractionReturnsOnlyNamedRegion(): void
    {
        $this->assertSame('MAIN-REGION', App::render('frag', ['fragment' => 'main'], self::DIR));
        $this->assertSame('SIDE-REGION', App::render('frag', ['fragment' => 'side'], self::DIR));
    }

    public function testFragmentFullPageWhenNoSelector(): void
    {
        // No fragment selector → every fragment runs inline → full page.
        $out = App::render('frag', [], self::DIR);
        $this->assertStringContainsString('MAIN-REGION', (string) $out);
        $this->assertStringContainsString('SIDE-REGION', (string) $out);
    }

    public function testMissingFragmentReturns404(): void
    {
        $this->assertSame(404, App::render('frag', ['fragment' => 'nonexistent'], self::DIR));
    }

    // ── error path ───────────────────────────────────────────────

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(\ZealPHP\TemplateUnavailableException::class);
        App::render('does_not_exist_xyz', [], self::DIR);
    }
}
