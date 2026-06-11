<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Session\SessionManager;

/**
 * #355 — the mixed-mode SessionManager must NOT mint an unsolicited session.
 *
 * mod_php with the stock session.auto_start=0 sends no Set-Cookie and starts no
 * session store entry for a request that never calls session_start() and
 * presents no PHPSESSID. The old SessionManager eagerly minted an id, called
 * session_start(), and ALWAYS emitted Set-Cookie on every request. The fix
 * makes it LAZY — start + emit only for a returning visitor (client-supplied
 * cookie/param), mirroring CoSessionManager; new visitors that need a session
 * use SessionStartMiddleware (opt-in).
 *
 * `__invoke()` needs a live OpenSwoole Http\Request/Response pair (exercised by
 * the Integration suite). Here we pin the structural contract — that the eager
 * session_start()/Set-Cookie path is now GATED behind the client-id check —
 * via the method source, so a regression to unconditional minting fails loudly.
 */
final class SessionManagerLazyMintTest extends TestCase
{
    private static function invokeSource(): string
    {
        $ref = new \ReflectionMethod(SessionManager::class, '__invoke');
        $file = (string) $ref->getFileName();
        $lines = file($file) ?: [];
        $start = $ref->getStartLine() - 1;
        $len   = $ref->getEndLine() - $ref->getStartLine() + 1;
        return implode('', array_slice($lines, $start, $len));
    }

    public function testInvokeComputesClientSuppliedGate(): void
    {
        $src = self::invokeSource();
        $this->assertStringContainsString('$hasSessionCookie', $src);
        $this->assertStringContainsString('$hasSessionParam', $src);
        $this->assertStringContainsString('if ($hasSessionCookie || $hasSessionParam)', $src);
    }

    public function testSessionStartIsGatedNotUnconditional(): void
    {
        $src = self::invokeSource();

        // The gate must appear BEFORE the actual session start — i.e. the
        // start path is inside the client-id branch, never reached for a
        // stateless request. We key off the unambiguous post-start marker
        // `$g->_session_started = true;` (comments mention session_start()
        // textually, so match the real assignment statement instead).
        $gatePos  = strpos($src, 'if ($hasSessionCookie || $hasSessionParam)');
        $startPos = strpos($src, '$g->_session_started = true;');
        $this->assertNotFalse($gatePos, 'lazy gate predicate must exist');
        $this->assertNotFalse($startPos, 'the eager session-start marker must still exist for returning visitors');
        $this->assertLessThan($startPos, $gatePos, 'the eager session start must be gated behind the client-id check');
    }

    public function testWriteCloseIsGatedOnSessionStarted(): void
    {
        // A stateless request that never started a session must not write/close
        // the store on teardown — the finally is now gated on _session_started.
        $src = self::invokeSource();
        $this->assertStringContainsString('_session_started', $src);
        $this->assertMatchesRegularExpression(
            '/\$manageSession\s*&&\s*\(\$g->_session_started/',
            $src,
            'session_write_close must be gated on a started session'
        );
    }
}
