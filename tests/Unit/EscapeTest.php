<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZealPHP\Escape;
use ZealPHP\SafeHtml;

use function ZealPHP\e;

/**
 * Context-specific output escaping helpers (#509): `e()` / `Escape::html()`,
 * `url()`, `safeUrl()`, `js()`, and `raw()` / `SafeHtml`.
 */
class EscapeTest extends TestCase
{
    public function testHtmlEscapesSpecialCharactersAndBothQuotes(): void
    {
        $this->assertSame(
            '&lt;a href=&quot;x&quot; title=&#039;y&#039;&gt;Tom &amp; Jerry&lt;/a&gt;',
            Escape::html('<a href="x" title=\'y\'>Tom & Jerry</a>')
        );
    }

    public function testEIsTheHtmlShorthand(): void
    {
        $this->assertSame(Escape::html('<b>"x"</b>'), e('<b>"x"</b>'));
    }

    public function testHtmlDoubleEncodesExistingEntities(): void
    {
        // Text that already looks like an entity is user data, not markup.
        $this->assertSame('&amp;lt;', e('&lt;'));
    }

    public function testHtmlKeepsValidUtf8(): void
    {
        $this->assertSame('café — 日本語 🎉', e('café — 日本語 🎉'));
    }

    public function testHtmlSubstitutesInvalidUtf8InsteadOfReturningEmpty(): void
    {
        // Without ENT_SUBSTITUTE, htmlspecialchars returns '' for the whole string.
        $this->assertSame("ok\u{FFFD}(ok", e("ok\xC3\x28ok"));
    }

    public function testHtmlConvertsScalarsNullAndStringable(): void
    {
        $this->assertSame('', e(null));
        $this->assertSame('42', e(42));
        $this->assertSame('1.5', e(1.5));
        $this->assertSame('1', e(true));
        $this->assertSame('', e(false));
        $obj = new class implements \Stringable {
            public function __toString(): string
            {
                return '<x>';
            }
        };
        $this->assertSame('&lt;x&gt;', e($obj));
    }

    public function testHtmlRejectsArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        e(['a']);
    }

    public function testRawMarkupPassesThroughUnescaped(): void
    {
        $raw = Escape::raw('<strong>trusted</strong>');
        $this->assertInstanceOf(SafeHtml::class, $raw);
        $this->assertSame('<strong>trusted</strong>', (string) $raw);
        $this->assertSame('<strong>trusted</strong>', e($raw));
        $this->assertSame('<strong>trusted</strong>', Escape::html($raw));
    }

    public function testUrlEncodesOneComponent(): void
    {
        $this->assertSame('a%20b%2Fc%3Fd%3De%26f%22%3C', Escape::url('a b/c?d=e&f"<'));
        $this->assertSame('caf%C3%A9', Escape::url('café'));
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'mixed case' => ['JaVaScRiPt:alert(1)'];
        yield 'leading space' => ['  javascript:alert(1)'];
        yield 'embedded tab' => ["java\tscript:alert(1)"];
        yield 'embedded newline' => ["java\nscript:alert(1)"];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
    }

    #[DataProvider('dangerousUrls')]
    public function testSafeUrlRefusesDangerousSchemes(string $url): void
    {
        $this->assertSame('#', Escape::safeUrl($url));
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowedUrls(): iterable
    {
        yield 'https' => ['https://example.com/a?b=1&c=2', 'https://example.com/a?b=1&amp;c=2'];
        yield 'http upper' => ['HTTP://example.com', 'HTTP://example.com'];
        yield 'mailto' => ['mailto:a@b.c', 'mailto:a@b.c'];
        yield 'tel' => ['tel:+1234', 'tel:+1234'];
        yield 'absolute path' => ['/users/1', '/users/1'];
        yield 'relative' => ['page.php?x="y"', 'page.php?x=&quot;y&quot;'];
        yield 'query only' => ['?q=a&b', '?q=a&amp;b'];
        yield 'fragment' => ['#top', '#top'];
        yield 'colon after path' => ['/a:b', '/a:b'];
    }

    #[DataProvider('allowedUrls')]
    public function testSafeUrlAllowsSafeUrlsAndHtmlEscapesThem(string $url, string $expected): void
    {
        $this->assertSame($expected, Escape::safeUrl($url));
    }

    public function testJsCannotBreakOutOfScriptOrAttribute(): void
    {
        $out = Escape::js(['name' => "</script><img src=x onerror='a'>\"&"]);
        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('>', $out);
        $this->assertStringNotContainsString("'", $out);
        $this->assertStringNotContainsString('&', $out);
        $this->assertSame(
            ['name' => "</script><img src=x onerror='a'>\"&"],
            json_decode($out, true),
            'the JSON still decodes to the original value'
        );
    }

    public function testJsEncodesScalarsAndKeepsUnicodeReadable(): void
    {
        $this->assertSame('"café"', Escape::js('café'));
        $this->assertSame('42', Escape::js(42));
        $this->assertSame('null', Escape::js(null));
        $this->assertSame('true', Escape::js(true));
    }

    public function testJsSubstitutesInvalidUtf8(): void
    {
        $this->assertSame("\"ok\u{FFFD}(ok\"", Escape::js("ok\xC3\x28ok"));
    }

    public function testJsTreatsSafeHtmlAsAPlainString(): void
    {
        $this->assertSame('"\u003Cb\u003E"', Escape::js(Escape::raw('<b>')));
    }
}
