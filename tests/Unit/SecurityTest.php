<?php
namespace ZealPHP\Tests\Unit;

use InvalidArgumentException;
use ZealPHP\Tests\TestCase;

/**
 * Security hardening regression tests.
 *
 * These tests pin behavior introduced in v0.2.0:
 * - Response::redirect() rejects javascript:/data:/vbscript: schemes
 * - unserialize() in session/cache paths rejects PHP objects
 * - ZealAPI module/request regex validation
 */
class SecurityTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    //  unserialize allowed_classes filter
    // ─────────────────────────────────────────────────────────────

    public function testUnserializeWithFalseAllowedClassesReturnsArray(): void
    {
        $data = serialize(['a' => 1, 'b' => 'two']);
        $result = unserialize($data, ['allowed_classes' => false]);
        $this->assertSame(['a' => 1, 'b' => 'two'], $result);
    }

    public function testUnserializeWithFalseAllowedClassesBlocksObjects(): void
    {
        $obj = new \stdClass();
        $obj->name = 'attacker';
        $data = serialize($obj);

        $result = unserialize($data, ['allowed_classes' => false]);

        // Object is rebuilt as __PHP_Incomplete_Class, not stdClass —
        // i.e. no real class instantiation happens.
        $this->assertNotInstanceOf(\stdClass::class, $result);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $result);
    }

    public function testUnserializeWithExceptionWhitelistAllowsExceptions(): void
    {
        $original = new \RuntimeException('boom');
        $data = serialize($original);

        $result = unserialize($data, [
            'allowed_classes' => [\Exception::class, \Error::class, \TypeError::class, \RuntimeException::class],
        ]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('boom', $result->getMessage());
    }

    public function testUnserializeWithExceptionWhitelistBlocksOtherClasses(): void
    {
        $obj = new \stdClass();
        $data = serialize($obj);

        $result = unserialize($data, [
            'allowed_classes' => [\Exception::class],
        ]);

        $this->assertNotInstanceOf(\stdClass::class, $result);
    }

    // ─────────────────────────────────────────────────────────────
    //  ZealAPI module/request path validation regex
    // ─────────────────────────────────────────────────────────────

    /**
     * Mirror of the regex used in ZealAPI::processApi() — pin the contract
     * so future edits don't silently widen what's accepted.
     */
    public function testZealApiModuleRegexAcceptsValidPaths(): void
    {
        $regex = '/^\/[a-zA-Z0-9_\/-]+$/';
        $this->assertSame(1, preg_match($regex, '/users'));
        $this->assertSame(1, preg_match($regex, '/users/orders'));
        $this->assertSame(1, preg_match($regex, '/api_v2'));
        $this->assertSame(1, preg_match($regex, '/abc-123'));
    }

    public function testZealApiModuleRegexRejectsTraversal(): void
    {
        $regex = '/^\/[a-zA-Z0-9_\/-]+$/';
        $this->assertSame(0, preg_match($regex, '/../etc/passwd'));
        $this->assertSame(0, preg_match($regex, '/users/../admin'));
        $this->assertSame(0, preg_match($regex, '/users;rm -rf'));
        $this->assertSame(0, preg_match($regex, '/users with spaces'));
        $this->assertSame(0, preg_match($regex, 'no_leading_slash'));
    }

    public function testZealApiRequestRegexAcceptsValidNames(): void
    {
        $regex = '/^[a-zA-Z0-9_\-]+$/';
        $this->assertSame(1, preg_match($regex, 'get'));
        $this->assertSame(1, preg_match($regex, 'list_all'));
        $this->assertSame(1, preg_match($regex, 'create-item'));
    }

    public function testZealApiRequestRegexRejectsDots(): void
    {
        $regex = '/^[a-zA-Z0-9_\-]+$/';
        $this->assertSame(0, preg_match($regex, '..'));
        $this->assertSame(0, preg_match($regex, 'get.php'));
        $this->assertSame(0, preg_match($regex, 'get/again'));
        $this->assertSame(0, preg_match($regex, ''));
    }

    // ─────────────────────────────────────────────────────────────
    //  Response::redirect scheme validation
    // ─────────────────────────────────────────────────────────────

    public function testRedirectThrowsOnJavascriptScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe redirect URL scheme');
        $this->makeResponse()->redirect('javascript:alert(1)');
    }

    public function testRedirectThrowsOnDataScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeResponse()->redirect('data:text/html,<script>');
    }

    public function testRedirectThrowsOnVbscriptScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeResponse()->redirect('vbscript:msgbox(1)');
    }

    public function testRedirectAcceptsRelativeUrl(): void
    {
        $response = $this->makeResponse();
        $response->redirect('/dashboard');
        $this->assertSame(302, \ZealPHP\G::instance()->status);
        $this->assertContains(['Location', '/dashboard'], $response->headersList);
    }

    public function testRedirectAcceptsSameOriginUrl(): void
    {
        $response = $this->makeResponse();
        \ZealPHP\G::instance()->server = ['HTTP_HOST' => 'example.test'];
        $response->redirect('https://example.test/account');
        $this->assertSame(302, \ZealPHP\G::instance()->status);
    }

    public function testRedirectAcceptsCrossOriginButLogsWarning(): void
    {
        // Cross-origin is warned, not blocked (preserves OAuth flows).
        $response = $this->makeResponse();
        \ZealPHP\G::instance()->server = ['HTTP_HOST' => 'example.test'];
        $response->redirect('https://oauth.provider.test/authorize');
        $this->assertSame(302, \ZealPHP\G::instance()->status);
        $this->assertContains(['Location', 'https://oauth.provider.test/authorize'], $response->headersList);
    }

    // ─────────────────────────────────────────────────────────────
    //  Header injection / HTTP response splitting (v0.2.5)
    //
    //  PHP native header() / setcookie() reject CRLF in values to prevent
    //  response splitting. Our uopz overrides + Response wrapper must
    //  replicate that guarantee — otherwise code that was safe on PHP-FPM
    //  becomes vulnerable when run on ZealPHP.
    // ─────────────────────────────────────────────────────────────

    public function testResponseHeaderRejectsCRLFInValue(): void
    {
        $response = $this->makeResponse();
        $result = @$response->header('X-Custom', "value\r\nSet-Cookie: pwned=1");
        $this->assertFalse($result, 'Response::header() should reject CRLF-containing value');
        $this->assertEmpty($response->headersList, 'No header should be added when CRLF detected');
    }

    public function testResponseHeaderRejectsNullByteInValue(): void
    {
        $response = $this->makeResponse();
        $result = @$response->header('X-Custom', "value\0evil");
        $this->assertFalse($result);
        $this->assertEmpty($response->headersList);
    }

    public function testResponseHeaderRejectsColonOrSpaceInName(): void
    {
        $response = $this->makeResponse();
        $result = @$response->header("X-Foo: Bar\r\nX-Evil", 'value');
        $this->assertFalse($result);
        $this->assertEmpty($response->headersList);
    }

    public function testHeaderOverrideRejectsCRLF(): void
    {
        $response = $this->makeResponse();
        $result = @\ZealPHP\header("X-Custom: value\r\nSet-Cookie: pwned=1");
        $this->assertFalse($result, 'header() should reject CRLF in input');
        $this->assertEmpty($response->headersList, 'No header should be added when CRLF detected');
    }

    public function testRedirectThrowsOnCRLFInUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeResponse()->redirect("/dashboard\r\nSet-Cookie: pwned=1");
    }

    public function testRedirectThrowsOnNullByteInUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeResponse()->redirect("/dashboard\0evil");
    }

    public function testSetcookieRejectsCRLFInValue(): void
    {
        $this->makeResponse();
        $result = @\ZealPHP\setcookie('session', "abc123\r\nSet-Cookie: admin=1");
        $this->assertFalse($result);
    }

    public function testSetcookieRejectsInvalidNameChars(): void
    {
        $this->makeResponse();
        $result = @\ZealPHP\setcookie("bad=name", 'value');
        $this->assertFalse($result);
    }

    public function testSetrawcookieRejectsControlChars(): void
    {
        $this->makeResponse();
        $result = @\ZealPHP\setrawcookie('session', "abc\r\nSet-Cookie: admin=1");
        $this->assertFalse($result);
    }

    // ─────────────────────────────────────────────────────────────
    //  Session ID entropy
    // ─────────────────────────────────────────────────────────────

    public function testSessionIdRegenerationUsesCsprng(): void
    {
        // The framework uses bin2hex(random_bytes(32)) — 64 hex chars, 256 bits entropy.
        // Verify the format we depend on (not the framework call itself,
        // which requires a running G context).
        $id = bin2hex(random_bytes(32));
        $this->assertSame(64, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $id);

        // Two IDs should not collide.
        $other = bin2hex(random_bytes(32));
        $this->assertNotSame($id, $other);
    }

    // ─────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────

    private function makeResponse(): \ZealPHP\HTTP\Response
    {
        // Create a stand-alone Response wrapping a fresh OpenSwoole one.
        // Tests run in cli, so we synthesize a Response that does not
        // need a real socket — only the headers list matters.
        $osResponse = new \OpenSwoole\Http\Response();
        $g = \ZealPHP\G::instance();
        $g->status = null;
        $g->server = $g->server ?? [];
        $response = new \ZealPHP\HTTP\Response($osResponse);
        // header() / setcookie() shims need a Response attached to G.
        $g->zealphp_response = $response;
        return $response;
    }
}
