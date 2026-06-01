<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * Security regression: the chat-history bubble must not render a `text` item's
 * stored HTML in a way that lets injected markup execute. It passes the field
 * through a formatting-only tag allowlist (strip_tags), keeping rich text while
 * neutralising <script>/<iframe>/<img onerror>/<a href=js>.
 */
class ChatBubbleXssTest extends TestCase
{
    private function render(string $html): string
    {
        App::$cwd = ZEALPHP_ROOT;
        return App::renderToString('/components/_chat_history_bubble', [
            'role'  => 'assistant',
            'items' => [['type' => 'text', 'html' => $html]],
        ]);
    }

    public function testScriptTagIsStripped(): void
    {
        $out = $this->render('<p>hello</p><script>alert(document.cookie)</script>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('</script>', $out);
    }

    public function testImageOnerrorIsStripped(): void
    {
        $out = $this->render('<img src=x onerror="alert(1)">');
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringNotContainsString('onerror', $out);
    }

    public function testJavascriptAnchorIsStripped(): void
    {
        $out = $this->render('<a href="javascript:alert(1)">click</a>');
        $this->assertStringNotContainsString('<a ', $out);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function testSafeFormattingIsPreserved(): void
    {
        // Trusted rich formatting the assistant legitimately renders survives.
        $out = $this->render('<p>hi <strong>bold</strong> and <em>italics</em> with <code>x()</code></p>');
        $this->assertStringContainsString('<strong>bold</strong>', $out);
        $this->assertStringContainsString('<em>italics</em>', $out);
        $this->assertStringContainsString('<code>x()</code>', $out);
    }
}
