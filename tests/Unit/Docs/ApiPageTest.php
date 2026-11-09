<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use ZealPHP\Docs\ApiPage;

/**
 * Pins how a phpDocumentor page is cut down for the site's api-wrapped layout.
 */
final class ApiPageTest extends TestCase
{
    private const PAGE = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <title>App</title>
  <base href="../">
  <link rel="stylesheet" href="https://cdn.example/x.css">
  <link rel="stylesheet" href="./css/base.css">
</head>
<body>
<header class="phpdocumentor-header">top</header>
<main class="phpdocumentor">
  <input id="sidebar-button" type="checkbox">
  <label for="sidebar-button">menu</label>
  <aside class="phpdocumentor-sidebar"><a href="namespaces/zealphp.html">ns</a></aside>
  <section class="phpdocumentor-search">search</section>
  <section class="phpdocumentor-search-results">results</section>
  <header class="phpdocumentor-header">inner header</header>
  <div class="content">
    <p>Use `App::run()` here; <code>`kept`</code></p>
    <a href="classes/ZealPHP-Store.html">Store</a>
    <a href="./classes/ZealPHP-App.html#method_run">run</a>
    <a href="#local">local</a>
    <a href="https://example.com/">ext</a>
    <a href="/docs/api/files/app.html">abs</a>
    <img src="images/logo.png">
  </div>
</main>
</body>
</html>
HTML;

    /** @return array{html: string, sidebar: string, cssHref: string, title: string} */
    private function wrapped(string $page = self::PAGE): array
    {
        $out = ApiPage::wrap($page);
        $this->assertNotNull($out);
        return $out;
    }

    public function testNullWithoutAPhpdocumentorMain(): void
    {
        $this->assertNull(ApiPage::wrap('<html><body><main>plain</main></body></html>'));
    }

    public function testDropsPhpdocChrome(): void
    {
        $html = $this->wrapped()['html'];
        foreach (['sidebar-button', 'phpdocumentor-search', 'phpdocumentor-header', 'phpdocumentor-sidebar'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
        $this->assertStringContainsString('<div class="content">', $html);
    }

    public function testHoistsTheSidebarWithAbsoluteLinks(): void
    {
        $this->assertSame(
            '<aside class="phpdocumentor-sidebar"><a href="/docs/api/namespaces/zealphp.html">ns</a></aside>',
            $this->wrapped()['sidebar']
        );
    }

    public function testRewritesRelativeLinksAndAddsHtmxToPageLinks(): void
    {
        $html = $this->wrapped()['html'];
        $this->assertStringContainsString(
            '<a href="/docs/api/classes/ZealPHP-Store.html" hx-get="/docs/api/classes/ZealPHP-Store.html" hx-target=".lesson-content" hx-select=".lesson-content" hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/docs/api/classes/ZealPHP-Store.html">Store</a>',
            $html
        );
        // Anchored, in-page, external links: rewritten (if relative) but no htmx.
        $this->assertStringContainsString('<a href="/docs/api/classes/ZealPHP-App.html#method_run">run</a>', $html);
        $this->assertStringContainsString('<a href="#local">local</a>', $html);
        $this->assertStringContainsString('<a href="https://example.com/">ext</a>', $html);
        $this->assertStringContainsString('<a href="/docs/api/files/app.html" hx-get="/docs/api/files/app.html"', $html);
        $this->assertStringContainsString('<img src="/docs/api/images/logo.png">', $html);
    }

    public function testBackticksBecomeCodeOutsideCodeBlocks(): void
    {
        $this->assertStringContainsString('<p>Use <code>App::run()</code> here; <code>`kept`</code></p>', $this->wrapped()['html']);
    }

    public function testFirstLocalStylesheetAndTitle(): void
    {
        $out = $this->wrapped();
        $this->assertSame('/docs/api/css/base.css', $out['cssHref']);
        $this->assertSame('App', $out['title']);
    }

    public function testDefaultsWithoutStylesheetOrTitle(): void
    {
        $out = $this->wrapped('<html><head></head><body><main class="phpdocumentor"><p>x</p></main></body></html>');
        $this->assertSame('/docs/api/css/template.css', $out['cssHref']);
        $this->assertSame('API Reference', $out['title']);
        $this->assertSame('', $out['sidebar']);
        $this->assertSame('<p>x</p>', $out['html']);
    }

    public function testKeepsAnAbsoluteStylesheet(): void
    {
        $out = $this->wrapped('<html><head><link rel="stylesheet" href="/x/y.css"></head><body><main class="phpdocumentor"></main></body></html>');
        $this->assertSame('/x/y.css', $out['cssHref']);
    }
}
