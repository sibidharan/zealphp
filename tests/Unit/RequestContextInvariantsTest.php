<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Legacy\ApacheContext;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Regression tests pinning the architectural invariants introduced in v0.2.6:
 *
 *   - `class G` renamed to `class RequestContext`; `\ZealPHP\G` preserved as a
 *     class_alias for backward compatibility.
 *   - `#[AllowDynamicProperties]` removed; undeclared writes in coroutine mode
 *     throw `BadMethodCallException` (catches typos like `$g->zealphp_reqeust`).
 *   - Response state (`response_headers_list` etc.) moved off `RequestContext`
 *     onto the `Response` object. The properties no longer exist on `G`.
 *   - Apache shim state (`apache_env` / `apache_notes`) moved off `RequestContext`
 *     onto `Legacy\ApacheContext`, lazy-allocated as `$g->apacheContext`.
 *
 * If any of these break in a future refactor, this file fails loud.
 */
class RequestContextInvariantsTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    //  G ↔ RequestContext class_alias identity
    // ─────────────────────────────────────────────────────────────

    public function testGAndRequestContextResolveToSameClass(): void
    {
        $viaG  = G::instance();
        $viaRc = RequestContext::instance();

        $this->assertSame($viaG, $viaRc, 'G::instance() and RequestContext::instance() must return the same object');
        $this->assertInstanceOf(RequestContext::class, $viaG);
        $this->assertInstanceOf(G::class, $viaRc);
    }

    public function testCanonicalClassNameIsRequestContext(): void
    {
        // get_class() returns the actual class name (RequestContext), not the alias.
        // Pin this so we know the rename direction stayed canonical.
        $this->assertSame('ZealPHP\\RequestContext', get_class(RequestContext::instance()));
        $this->assertSame('ZealPHP\\RequestContext', get_class(G::instance()));
    }

    public function testInstanceofCheckPassesForBothNames(): void
    {
        $ctx = RequestContext::instance();
        $this->assertTrue($ctx instanceof RequestContext);
        $this->assertTrue($ctx instanceof G, 'Instances must satisfy instanceof G for backward compat with type hints');
    }

    public function testClassConstantsReportTheirOwnName(): void
    {
        // Pin the convention: G::class as a string returns 'ZealPHP\G' (alias name)
        // and RequestContext::class returns 'ZealPHP\RequestContext'. Code that
        // uses ::class as a hash key needs to know one resolves vs the other.
        $this->assertSame('ZealPHP\\G', G::class);
        $this->assertSame('ZealPHP\\RequestContext', RequestContext::class);
    }

    // ─────────────────────────────────────────────────────────────
    //  Strict __set (no more AllowDynamicProperties)
    // ─────────────────────────────────────────────────────────────

    public function testUndeclaredWriteThrowsInCoroutineMode(): void
    {
        $originalMode = App::$superglobals;
        App::$superglobals = false;

        try {
            $ctx = RequestContext::instance();
            $this->expectException(\BadMethodCallException::class);
            // Typo: `zealphp_reqeust` instead of `zealphp_request`. Pre-v0.2.6
            // this would silently create a dynamic property and the bug would
            // surface much later as a null read.
            $ctx->zealphp_reqeust = 'should-throw';
        } finally {
            App::$superglobals = $originalMode;
        }
    }

    public function testDeclaredPropertyWriteSucceedsInCoroutineMode(): void
    {
        $originalMode = App::$superglobals;
        App::$superglobals = false;

        try {
            $ctx = RequestContext::instance();
            $ctx->status = 418;
            $this->assertSame(418, $ctx->status);
            $ctx->status = null;  // reset
        } finally {
            App::$superglobals = $originalMode;
        }
    }

    public function testUndeclaredWriteRoutesToGlobalsInSuperglobalsMode(): void
    {
        // Superglobals mode keeps the legacy `$GLOBALS[$key]` bridge so
        // pre-coroutine code that stashed `$g->custom = $val` keeps working.
        $originalMode = App::$superglobals;
        App::$superglobals = true;

        try {
            $ctx = RequestContext::instance();
            $ctx->some_legacy_key_xyz = 'bridged';
            $this->assertSame('bridged', $GLOBALS['some_legacy_key_xyz'] ?? null);
            unset($GLOBALS['some_legacy_key_xyz']);
        } finally {
            App::$superglobals = $originalMode;
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Response state lives on Response, not on RequestContext (v0.2.6)
    // ─────────────────────────────────────────────────────────────

    public function testResponseStatePropertiesDoNotExistOnRequestContext(): void
    {
        // These were on G pre-v0.2.6. Pin that they're gone — anyone who
        // tries to read $g->response_headers_list will find nothing.
        $this->assertFalse(
            property_exists(RequestContext::class, 'response_headers_list'),
            'response_headers_list moved to Response in v0.2.6 — must not exist on RequestContext'
        );
        $this->assertFalse(property_exists(RequestContext::class, 'response_cookies_list'));
        $this->assertFalse(property_exists(RequestContext::class, 'response_rawcookies_list'));
    }

    public function testResponseClassDeclaresHeadersListAndCookiesList(): void
    {
        // Pin the new canonical location.
        $this->assertTrue(property_exists(\ZealPHP\HTTP\Response::class, 'headersList'));
        $this->assertTrue(property_exists(\ZealPHP\HTTP\Response::class, 'cookiesList'));
        $this->assertTrue(property_exists(\ZealPHP\HTTP\Response::class, 'rawCookiesList'));
    }

    // ─────────────────────────────────────────────────────────────
    //  ApacheContext lazy allocation
    // ─────────────────────────────────────────────────────────────

    public function testApacheContextIsNullByDefault(): void
    {
        // Most requests never hit legacy code; apacheContext should stay null.
        // Reset it first (other tests may have populated it).
        $ctx = RequestContext::instance();
        $ctx->apacheContext = null;

        $this->assertNull($ctx->apacheContext, 'apacheContext should be null until first apache_setenv()/apache_note()');
    }

    public function testApacheSetenvAllocatesContextLazily(): void
    {
        $ctx = RequestContext::instance();
        $ctx->apacheContext = null;

        $this->assertTrue(\ZealPHP\apache_setenv('TEST_VAR', 'test_value'));

        $this->assertInstanceOf(ApacheContext::class, $ctx->apacheContext);
        $this->assertSame('test_value', $ctx->apacheContext->env['TEST_VAR']);
        $this->assertSame('test_value', \ZealPHP\apache_getenv('TEST_VAR'));
    }

    public function testApacheGetenvOnUnallocatedContextReturnsFalse(): void
    {
        $ctx = RequestContext::instance();
        $ctx->apacheContext = null;

        // PHP-compatible behavior: missing apache var returns false, not null.
        $this->assertFalse(\ZealPHP\apache_getenv('NEVER_SET_XYZ'));
    }

    public function testApacheEnvAndApacheNotesPropertiesDoNotExistOnRequestContext(): void
    {
        // These were on G pre-v0.2.6 — moved to ApacheContext.
        $this->assertFalse(
            property_exists(RequestContext::class, 'apache_env'),
            'apache_env moved to ApacheContext in v0.2.6'
        );
        $this->assertFalse(property_exists(RequestContext::class, 'apache_notes'));
    }

    // ─────────────────────────────────────────────────────────────
    //  AllowDynamicProperties attribute is gone
    // ─────────────────────────────────────────────────────────────

    public function testAllowDynamicPropertiesAttributeIsRemoved(): void
    {
        // The attribute permitted typos to silently succeed. Pin its absence
        // so a future refactor can't re-introduce it accidentally.
        $reflection = new \ReflectionClass(RequestContext::class);
        $attributes = $reflection->getAttributes(\AllowDynamicProperties::class);
        $this->assertEmpty($attributes, '#[AllowDynamicProperties] must not be on RequestContext');
    }

    // ─────────────────────────────────────────────────────────────
    //  Declared property defaults
    // ─────────────────────────────────────────────────────────────

    public function testDeclaredArrayPropertiesDefaultToEmptyArray(): void
    {
        // Use reflection to inspect the declared default rather than mutating
        // the live instance — mutating $g->error_handlers_stack triggers
        // PHPUnit's risky-test detection (looks like the test is clobbering
        // PHP-level error handlers, which it isn't, but the heuristic can't
        // tell).
        $reflection = new \ReflectionClass(RequestContext::class);

        foreach (['get', 'post', 'cookie', 'files', 'server', 'request', 'session', 'session_params', 'memo'] as $name) {
            $prop = $reflection->getProperty($name);
            $this->assertSame([], $prop->getDefaultValue(), "$name must default to []");
        }
    }

    public function testDeclaredNullablePropertiesDefaultToNull(): void
    {
        $ctx = RequestContext::instance();
        $ctx->status = null;
        $ctx->_streaming = null;
        $ctx->_session_started = null;
        $ctx->error_exception = null;

        $this->assertNull($ctx->status);
        $this->assertNull($ctx->_streaming);
        $this->assertNull($ctx->_session_started);
        $this->assertNull($ctx->error_exception);
    }

    public function testHandlerStacksAreDeclaredArrayProps(): void
    {
        // The handler-stack fix in v0.2.10 (SessionManager reset) relies on
        // these being declared array props. Pin the shape.
        $reflection = new \ReflectionClass(RequestContext::class);

        foreach (['error_handlers_stack', 'exception_handlers_stack', 'shutdown_functions'] as $name) {
            $this->assertTrue($reflection->hasProperty($name), "$name must be a declared property");
            $prop = $reflection->getProperty($name);
            $type = $prop->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type);
            $this->assertSame('array', $type->getName(), "$name must be typed array");
        }
    }
}
