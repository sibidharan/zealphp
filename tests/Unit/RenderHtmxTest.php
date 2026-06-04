<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\RequestContext;

/**
 * Unit tests for `App::renderHtmx()` — the htmx-aware selector over
 * `App::render()` that returns a fragment (partial) for an htmx request and
 * the full page otherwise.
 *
 * The behaviour under test is the SELECTION, not the rendering: a temp
 * template dir holds a single `page.php` carrying two `App::fragment()`
 * regions plus a `<shell>` wrapper, and a separate `full.php` shell. Each
 * test drives `isHtmx()` / `HX-Target` / `HX-Trigger-Name` via the request
 * header map and asserts which template/region renderHtmx() chose.
 *
 * `App::render()` (which renderHtmx() delegates to) echoes a string result
 * back AND returns it, so {@see render()} captures the echoed body via an
 * output buffer — the same path ResponseMiddleware sees at runtime.
 */
class RenderHtmxTest extends TestCase
{
    private static string $tplDir;

    public static function setUpBeforeClass(): void
    {
        self::$tplDir = sys_get_temp_dir() . '/zealphp_renderhtmx_test_' . getmypid();
        mkdir(self::$tplDir . '/template', 0755, true);
        App::$cwd = self::$tplDir;

        // page.php — full shell + two named fragments. The result text marks
        // exactly which path produced it so assertions are unambiguous.
        file_put_contents(self::$tplDir . '/template/page.php', <<<'PHP'
<shell>
<?php \ZealPHP\App::fragment('results', function() { echo '[RESULTS-PARTIAL]'; }); ?>
<?php \ZealPHP\App::fragment('search', function() { echo '[SEARCH-PARTIAL]'; }); ?>
</shell>
PHP);

        // full.php — a distinct full-page shell for the separate-template form.
        file_put_contents(self::$tplDir . '/template/full.php', '[FULL-PAGE-SHELL]');

        // partial.php — a bare partial (no fragments) for the no-derivation case.
        file_put_contents(self::$tplDir . '/template/partial.php', '[BARE-PARTIAL]');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$tplDir . '/template/*.php') ?: [] as $f) {
            unlink($f);
        }
        @rmdir(self::$tplDir . '/template');
        @rmdir(self::$tplDir);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Use the process-wide RequestContext singleton (no coroutine in PHPUnit).
        App::superglobals(true);
        RequestContext::instance()->zealphp_request = null;
    }

    /** Install a request whose header map carries the given HX-* headers. */
    private function request(array $headers): void
    {
        $or = new \OpenSwoole\Http\Request();
        $or->header = $headers;
        RequestContext::instance()->zealphp_request = new ZRequest($or);
    }

    /**
     * Run renderHtmx() and capture the echoed body (App::render() echoes a
     * string result). Mirrors the runtime emission path.
     */
    private function render(string $template, array $args = [], ?string $fragmentName = null, ?string $fullPageTemplate = null): string
    {
        ob_start();
        try {
            App::renderHtmx($template, $args, $fragmentName, $fullPageTemplate);
        } finally {
            $out = ob_get_clean();
        }
        return $out === false ? '' : $out;
    }

    // ──────────────────────────────────────────────────────────────
    // Non-htmx request → full page.
    // ──────────────────────────────────────────────────────────────

    public function testNonHtmxRequestRendersFullPage(): void
    {
        $this->request([]); // no HX-Request header
        $output = $this->render('page');
        // Full page → shell + every fragment inline.
        $this->assertStringContainsString('<shell>', $output);
        $this->assertStringContainsString('[RESULTS-PARTIAL]', $output);
        $this->assertStringContainsString('[SEARCH-PARTIAL]', $output);
    }

    public function testNoRequestInScopeFallsBackToFullPage(): void
    {
        // zealphp_request is null (CLI render / warmup) → full-page path.
        $output = $this->render('page');
        $this->assertStringContainsString('<shell>', $output);
        $this->assertStringContainsString('[RESULTS-PARTIAL]', $output);
    }

    public function testNonHtmxUsesFullPageTemplateWhenGiven(): void
    {
        $this->request([]);
        $output = $this->render('partial', [], null, 'full');
        // Non-htmx + a separate full-page template → render THAT, not the partial.
        $this->assertSame('[FULL-PAGE-SHELL]', $output);
    }

    // ──────────────────────────────────────────────────────────────
    // htmx request → fragment.
    // ──────────────────────────────────────────────────────────────

    public function testHtmxRequestWithExplicitFragment(): void
    {
        $this->request(['hx-request' => 'true']);
        $output = $this->render('page', [], 'search');
        // Only the explicitly-named region survives.
        $this->assertSame('[SEARCH-PARTIAL]', trim($output));
        $this->assertStringNotContainsString('<shell>', $output);
        $this->assertStringNotContainsString('[RESULTS-PARTIAL]', $output);
    }

    public function testHtmxRequestDerivesFragmentFromHxTarget(): void
    {
        // HX-Target "#results" → fragment "results" (leading '#' stripped).
        $this->request(['hx-request' => 'true', 'hx-target' => '#results']);
        $output = $this->render('page');
        $this->assertSame('[RESULTS-PARTIAL]', trim($output));
        $this->assertStringNotContainsString('<shell>', $output);
    }

    public function testHtmxRequestDerivesFragmentFromHxTargetWithoutHash(): void
    {
        // HX-Target without a leading '#' is used verbatim.
        $this->request(['hx-request' => 'true', 'hx-target' => 'search']);
        $output = $this->render('page');
        $this->assertSame('[SEARCH-PARTIAL]', trim($output));
    }

    public function testHtmxRequestDerivesFragmentFromHxTriggerNameWhenNoTarget(): void
    {
        // No HX-Target → fall back to HX-Trigger-Name.
        $this->request(['hx-request' => 'true', 'hx-trigger-name' => 'results']);
        $output = $this->render('page');
        $this->assertSame('[RESULTS-PARTIAL]', trim($output));
    }

    public function testHtmxTargetWinsOverTriggerName(): void
    {
        // Both present → HX-Target takes precedence.
        $this->request([
            'hx-request'      => 'true',
            'hx-target'       => '#search',
            'hx-trigger-name' => 'results',
        ]);
        $output = $this->render('page');
        $this->assertSame('[SEARCH-PARTIAL]', trim($output));
    }

    public function testHtmxRequestWithNoDerivableFragmentRendersBarePartial(): void
    {
        // htmx, but neither HX-Target nor HX-Trigger-Name → render with no
        // fragment key (bare partial). On a partial template it's just its output.
        $this->request(['hx-request' => 'true']);
        $output = $this->render('partial');
        $this->assertSame('[BARE-PARTIAL]', $output);
    }

    public function testHtmxRequestExplicitFragmentOnSeparateTemplate(): void
    {
        // Explicit fragment wins even when a fullPageTemplate is supplied —
        // the fullPageTemplate is only consulted on the non-htmx path.
        $this->request(['hx-request' => 'true']);
        $output = $this->render('page', [], 'results', 'full');
        $this->assertSame('[RESULTS-PARTIAL]', trim($output));
    }

    // ──────────────────────────────────────────────────────────────
    // Return contract — renderHtmx() passes App::render()'s return through.
    // ──────────────────────────────────────────────────────────────

    public function testReturnContractFlowsThroughFromFragment(): void
    {
        // A fragment that returns a status int → renderHtmx returns that int.
        file_put_contents(self::$tplDir . '/template/status-page.php', <<<'PHP'
<shell>
<?php \ZealPHP\App::fragment('gone', function() { return 410; }); ?>
</shell>
PHP);
        $this->request(['hx-request' => 'true']);
        $result = App::renderHtmx('status-page', [], 'gone');
        $this->assertSame(410, $result);
    }
}
