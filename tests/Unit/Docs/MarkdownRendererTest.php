<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use ZealPHP\Docs\MarkdownRenderer;

/**
 * Pins the two non-trivial jobs of the docs Markdown renderer: in-doc
 * link rewriting (sibling guide → /docs/guide, repo file → GitHub blob)
 * and first-paragraph summary extraction (used as the share/meta
 * description).
 */
final class MarkdownRendererTest extends TestCase
{
    // ── render(): sibling-guide .md links → /docs/guide/<slug> ──────────

    public function testSiblingGuideLinkRewritesToGuideUrl(): void
    {
        $html = MarkdownRenderer::render('See [routing](routing.md).');
        $this->assertStringContainsString('href="/docs/guide/routing"', $html);
        $this->assertStringNotContainsString('href="routing.md"', $html);
    }

    public function testSiblingGuideLinkWithDotSlashPrefix(): void
    {
        $html = MarkdownRenderer::render('[arch](./runtime-architecture.md)');
        $this->assertStringContainsString('href="/docs/guide/runtime-architecture"', $html);
    }

    public function testGuideLinkPreservesAnchor(): void
    {
        $html = MarkdownRenderer::render('[x](runtime-architecture.md#lifecycle-setters)');
        $this->assertStringContainsString('href="/docs/guide/runtime-architecture#lifecycle-setters"', $html);
    }

    // ── render(): non-guide repo files → GitHub blob ────────────────────

    public function testRepoSourceFileRewritesToGithubBlob(): void
    {
        $html = MarkdownRenderer::render('[App](../src/App.php)');
        $this->assertStringContainsString(
            'href="https://github.com/sibidharan/zealphp/blob/master/src/App.php"',
            $html
        );
    }

    public function testNonGuideMarkdownRewritesToGithubBlob(): void
    {
        $html = MarkdownRenderer::render('[standards](../STANDARDS.md)');
        $this->assertStringContainsString(
            'href="https://github.com/sibidharan/zealphp/blob/master/STANDARDS.md"',
            $html
        );
    }

    public function testNestedRepoPathStripsRelativeSegments(): void
    {
        $html = MarkdownRenderer::render('[t](../tests/Integration/FooTest.php)');
        $this->assertStringContainsString(
            'href="https://github.com/sibidharan/zealphp/blob/master/tests/Integration/FooTest.php"',
            $html
        );
    }

    // ── render(): absolute / anchor links untouched ─────────────────────

    public function testAbsoluteHttpLinkUntouched(): void
    {
        $html = MarkdownRenderer::render('[gh](https://github.com/sibidharan/zealphp)');
        $this->assertStringContainsString('href="https://github.com/sibidharan/zealphp"', $html);
    }

    public function testInPageAnchorUntouched(): void
    {
        $html = MarkdownRenderer::render('[top](#section)');
        $this->assertStringContainsString('href="#section"', $html);
        $this->assertStringNotContainsString('github.com', $html);
    }

    // ── summary(): first prose paragraph, stripped + clamped ────────────

    public function testSummaryReturnsFirstProseParagraph(): void
    {
        $md = "# Routing\n\nZealPHP blends implicit routing with programmable routes.\n\n## Details\nmore";
        $this->assertSame(
            'ZealPHP blends implicit routing with programmable routes.',
            MarkdownRenderer::summary($md)
        );
    }

    public function testSummarySkipsHeadingsBlockquotesAndFences(): void
    {
        $md = "# Title\n\n> a quote\n\n```php\n\$x = 1;\n```\n\nThe real first paragraph here.";
        $this->assertSame('The real first paragraph here.', MarkdownRenderer::summary($md));
    }

    public function testSummaryStripsInlineMarkdown(): void
    {
        $md = "# T\n\nUse `App::run()` and see [the docs](routing.md) for **more**.";
        $this->assertSame('Use App::run() and see the docs for more.', MarkdownRenderer::summary($md));
    }

    public function testSummaryClampsLongParagraph(): void
    {
        $long = str_repeat('word ', 80); // ~400 chars
        $out  = MarkdownRenderer::summary("# T\n\n" . $long);
        $this->assertLessThanOrEqual(200, mb_strlen($out));
        $this->assertStringEndsWith('…', $out);
    }

    public function testSummaryEmptyWhenNoProse(): void
    {
        $md = "# Only A Heading\n\n## Another\n\n- a list item";
        $this->assertSame('', MarkdownRenderer::summary($md));
    }
}
