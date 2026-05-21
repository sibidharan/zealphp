<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Unit tests for `App::fragment()` + the fragment-extraction path through
 * `App::executeFile()` (template fragments — the htmx-essay pattern).
 *
 * Three behaviours pinned:
 *   1. Full-page render (no fragment selector) → App::fragment() blocks
 *      render inline; output is the complete template.
 *   2. Fragment-extraction mode (selector present and matches a region) →
 *      the page-shell buffer is cleared, only that region's output flows
 *      back, and the closure's return value rides the universal contract
 *      (int=status, array=JSON, Generator=stream, etc.).
 *   3. Fragment-extraction mode but selector matches no region → 404
 *      (per the contract — "asked for X, X doesn't exist").
 */
class FragmentTest extends TestCase
{
    private static string $tplDir;

    public static function setUpBeforeClass(): void
    {
        self::$tplDir = sys_get_temp_dir() . '/zealphp_fragment_test_' . getmypid();
        mkdir(self::$tplDir, 0755, true);
        mkdir(self::$tplDir . '/template', 0755, true);
        App::$cwd = self::$tplDir;
    }

    public static function tearDownAfterClass(): void
    {
        $files = glob(self::$tplDir . '/template/*.php') ?: [];
        foreach ($files as $f) {
            unlink($f);
        }
        @rmdir(self::$tplDir . '/template');
        @rmdir(self::$tplDir);
    }

    private function writeTemplate(string $name, string $content): void
    {
        file_put_contents(self::$tplDir . "/template/{$name}.php", $content);
    }

    // ──────────────────────────────────────────────────────────────
    // Full-page mode — App::fragment() renders the region inline.
    // ──────────────────────────────────────────────────────────────

    public function testFullPageRenderIncludesAllFragmentsInline(): void
    {
        $this->writeTemplate('list', <<<'PHP'
<ul>
<?php foreach ($items as $item): ?>
  <?php \ZealPHP\App::fragment("item-{$item}", function() use ($item) { ?><li><?= $item ?></li><?php }); ?>
<?php endforeach; ?>
</ul>
PHP);

        $output = App::renderToString('list', ['items' => ['a', 'b', 'c']]);
        $this->assertStringContainsString('<ul>',  $output);
        $this->assertStringContainsString('<li>a</li>', $output);
        $this->assertStringContainsString('<li>b</li>', $output);
        $this->assertStringContainsString('<li>c</li>', $output);
        $this->assertStringContainsString('</ul>', $output);
    }

    public function testFullPageRenderDiscardsFragmentClosureReturnValue(): void
    {
        // A fragment closure that `return 404` should NOT affect a full-page
        // render — the universal contract belongs to the outer App::render().
        $this->writeTemplate('full-discard', <<<'PHP'
<p>before</p>
<?php \ZealPHP\App::fragment('inner', function() { echo '<span>x</span>'; return 404; }); ?>
<p>after</p>
PHP);

        $output = App::renderToString('full-discard', []);
        $this->assertStringContainsString('<p>before</p>',  $output);
        $this->assertStringContainsString('<span>x</span>', $output);
        $this->assertStringContainsString('<p>after</p>',   $output);
        // Full-page mode → return is discarded; 404 never reaches the wire.
        $this->assertStringNotContainsString('404', $output);
    }

    // ──────────────────────────────────────────────────────────────
    // Fragment-extraction mode — selector matches a region.
    // ──────────────────────────────────────────────────────────────

    public function testExtractsMatchingFragmentOnly(): void
    {
        $this->writeTemplate('listB', <<<'PHP'
<html><body>
<ul>
<?php foreach ($items as $item): ?>
  <?php \ZealPHP\App::fragment("item-{$item}", function() use ($item) { ?><li id="i-<?= $item ?>"><?= $item ?></li><?php }); ?>
<?php endforeach; ?>
</ul>
</body></html>
PHP);

        $output = App::renderToString('listB', ['items' => ['a', 'b', 'c'], 'fragment' => 'item-b']);
        // Only the matched <li> survives — no <html>, no <ul>, no siblings.
        $this->assertStringNotContainsString('<html>', $output);
        $this->assertStringNotContainsString('<ul>',   $output);
        $this->assertStringNotContainsString('item-a', $output);
        $this->assertStringNotContainsString('item-c', $output);
        $this->assertSame('<li id="i-b">b</li>', trim($output));
    }

    public function testFragmentClosureCanReturnStatusInt(): void
    {
        // The fragment closure rides the universal return contract — a
        // returned int becomes the HTTP status that ResponseMiddleware emits.
        $this->writeTemplate('status-frag', <<<'PHP'
<p>shell that won't matter</p>
<?php \ZealPHP\App::fragment('forbidden', function() { return 403; }); ?>
PHP);

        $result = App::render('status-frag', ['fragment' => 'forbidden']);
        $this->assertSame(403, $result);
    }

    public function testFragmentClosureCanReturnArrayForJson(): void
    {
        $this->writeTemplate('json-frag', <<<'PHP'
<html><body>
<?php \ZealPHP\App::fragment('row', function() use ($id) { return ['id' => $id, 'kind' => 'json']; }); ?>
</body></html>
PHP);

        $result = App::render('json-frag', ['id' => 42, 'fragment' => 'row']);
        $this->assertSame(['id' => 42, 'kind' => 'json'], $result);
    }

    public function testFragmentClosureCanReturnGenerator(): void
    {
        $this->writeTemplate('stream-frag', <<<'PHP'
<header>discarded</header>
<?php \ZealPHP\App::fragment('stream', function() {
    return (function() { yield '<chunk-1>'; yield '<chunk-2>'; })();
}); ?>
<footer>discarded too</footer>
PHP);

        $result = App::render('stream-frag', ['fragment' => 'stream']);
        $this->assertInstanceOf(\Generator::class, $result);
        $this->assertSame(['<chunk-1>', '<chunk-2>'], iterator_to_array($result, false));
    }

    public function testFragmentEchoOnlyReturnsBufferedString(): void
    {
        // Closure echoes, doesn't return — body is the echoed HTML.
        $this->writeTemplate('echo-frag', <<<'PHP'
<header>page shell</header>
<?php \ZealPHP\App::fragment('row', function() { echo '<li>only this</li>'; }); ?>
<footer>page shell</footer>
PHP);

        $output = App::renderToString('echo-frag', ['fragment' => 'row']);
        $this->assertSame('<li>only this</li>', trim($output));
        $this->assertStringNotContainsString('page shell', $output);
    }

    // ──────────────────────────────────────────────────────────────
    // Fragment-extraction mode — selector matches NO region.
    // ──────────────────────────────────────────────────────────────

    public function testMissingFragmentReturns404(): void
    {
        $this->writeTemplate('miss', <<<'PHP'
<html><body>
<?php \ZealPHP\App::fragment('row-a', function() { echo '<li>a</li>'; }); ?>
<?php \ZealPHP\App::fragment('row-b', function() { echo '<li>b</li>'; }); ?>
</body></html>
PHP);

        $result = App::render('miss', ['fragment' => 'row-zz']);
        $this->assertSame(404, $result);
    }

    // ──────────────────────────────────────────────────────────────
    // Nested renders — fragment scope must save+restore cleanly.
    // ──────────────────────────────────────────────────────────────

    public function testNestedRenderPreservesOuterFragmentScope(): void
    {
        // Inner render() shouldn't pollute the outer App::fragment() scope.
        $this->writeTemplate('inner', '<span>inner-content</span>');
        $this->writeTemplate('outer', <<<'PHP'
<header>outer-header</header>
<?php \ZealPHP\App::fragment('main', function() {
    // Inner render under the same parent — should NOT inherit the parent's
    // fragment selector (would cause an infinite recursion of fragment
    // selection if it did).
    echo \ZealPHP\App::renderToString('inner');
}); ?>
<footer>outer-footer</footer>
PHP);

        $output = App::renderToString('outer', ['fragment' => 'main']);
        $this->assertStringContainsString('<span>inner-content</span>', $output);
        $this->assertStringNotContainsString('outer-header', $output);
        $this->assertStringNotContainsString('outer-footer', $output);
    }

    public function testNestedRenderWithItsOwnFragmentSelector(): void
    {
        // Inner render passes its OWN fragment selector — should be honoured
        // without affecting the outer's. Save+restore composes cleanly.
        $this->writeTemplate('innerB', <<<'PHP'
<?php \ZealPHP\App::fragment('a', function() { echo '[INNER-A]'; }); ?>
<?php \ZealPHP\App::fragment('b', function() { echo '[INNER-B]'; }); ?>
PHP);
        $this->writeTemplate('outerB', <<<'PHP'
<header>OUT</header>
<?php \ZealPHP\App::fragment('main', function() {
    // Ask for just inner fragment B from a different template.
    echo \ZealPHP\App::renderToString('innerB', ['fragment' => 'b']);
}); ?>
<footer>OUT</footer>
PHP);

        $output = App::renderToString('outerB', ['fragment' => 'main']);
        $this->assertStringContainsString('[INNER-B]', $output);
        $this->assertStringNotContainsString('[INNER-A]', $output);
        $this->assertStringNotContainsString('OUT', $output);
    }

    // ──────────────────────────────────────────────────────────────
    // Defensive — invariants that protect against silent failure modes.
    // ──────────────────────────────────────────────────────────────

    public function testFragmentNameWithSpecialCharsRoundTrips(): void
    {
        // Real-world fragments often include ids, dashes, dots, slashes.
        $name = 'contact-row-42_v2.beta';
        $this->writeTemplate('special', <<<PHP
<header>x</header>
<?php \ZealPHP\App::fragment('{$name}', function() { echo '<span>matched</span>'; }); ?>
<footer>x</footer>
PHP);

        $output = App::renderToString('special', ['fragment' => $name]);
        $this->assertSame('<span>matched</span>', trim($output));
    }

    public function testFirstMatchingFragmentWinsWhenNameRepeated(): void
    {
        // Duplicate names within one template — first match short-circuits
        // the rest of the template (HaltException). Second occurrence
        // never runs.
        $this->writeTemplate('dupes', <<<'PHP'
<?php \ZealPHP\App::fragment('row', function() { echo '<li>first</li>'; }); ?>
<?php \ZealPHP\App::fragment('row', function() { echo '<li>second</li>'; }); ?>
PHP);

        $output = App::renderToString('dupes', ['fragment' => 'row']);
        $this->assertSame('<li>first</li>', trim($output));
        $this->assertStringNotContainsString('second', $output);
    }
}
