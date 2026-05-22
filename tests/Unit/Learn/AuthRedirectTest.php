<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Learn;

use PHPUnit\Framework\TestCase;
use ZealPHP\Learn\Auth;

/**
 * Pins Auth::resolveAuthRedirect — the post-auth destination logic shared by
 * the learn login/register/logout flow. Prefers HX-Current-URL, then Referer,
 * then the default; only same-site absolute paths are honoured.
 */
final class AuthRedirectTest extends TestCase
{
    public function testPrefersHxCurrentUrlPath(): void
    {
        $this->assertSame(
            '/learn/tictactoe',
            Auth::resolveAuthRedirect('https://php.zeal.ninja/learn/tictactoe', 'https://php.zeal.ninja/learn/notes')
        );
    }

    public function testFallsBackToRefererWhenNoHxUrl(): void
    {
        $this->assertSame(
            '/learn/ai-chat',
            Auth::resolveAuthRedirect(null, 'https://php.zeal.ninja/learn/ai-chat')
        );
    }

    public function testFallsBackToDefaultWhenNeitherPresent(): void
    {
        $this->assertSame('/learn/notes', Auth::resolveAuthRedirect(null, null));
        $this->assertSame('/x', Auth::resolveAuthRedirect('', '', '/x'));
    }

    public function testStripsHostKeepsOnlyPath(): void
    {
        $this->assertSame('/learn/store', Auth::resolveAuthRedirect('https://evil.example.com/learn/store', null));
    }

    public function testProtocolRelativeUrlIsReducedToItsSameSitePath(): void
    {
        // parse_url discards the host, so "//evil.com/phish" yields only the
        // path "/phish" — a same-site redirect, never an open redirect to
        // evil.com. (We always redirect to a path on our own domain.)
        $this->assertSame('/phish', Auth::resolveAuthRedirect('//evil.com/phish', null));
    }

    public function testRejectsNonAbsoluteOrSchemeOnly(): void
    {
        // A bare scheme/relative candidate yields no usable absolute path → default.
        $this->assertSame('/learn/notes', Auth::resolveAuthRedirect('mailto:x@y.z', null));
    }

    public function testAcceptsPlainAbsolutePath(): void
    {
        $this->assertSame('/learn/auth', Auth::resolveAuthRedirect('/learn/auth', null));
    }

    public function testPreservesQuerylessPathFromUrlWithQuery(): void
    {
        $this->assertSame('/learn/notes', Auth::resolveAuthRedirect('https://host/learn/notes?x=1', null));
    }
}
