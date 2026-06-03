<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\CGI\Dispatcher;
use ZealPHP\RequestContext;

/**
 * Unit coverage for the pure branches of Dispatcher::buildCgiEnv() that the
 * existing Cgi* dispatch tests don't reach — the HTTPS special-case and the
 * non-string REMOTE_PORT cast — plus mintCgiSession()'s host-owns-sessions
 * early return. All side-effect-free (no subprocess, no FastCGI backend).
 */
class DispatcherEnvTest extends TestCase
{
    public function testHttpsIsCarriedThroughEvenThoughNotPrefixMatched(): void
    {
        // HTTPS has no allowed-prefix, so it's special-cased through verbatim.
        $env = Dispatcher::buildCgiEnv(['HTTPS' => 'on', 'REQUEST_METHOD' => 'GET'], 'ctx');
        $this->assertSame('on', $env['HTTPS']);
        $this->assertSame('GET', $env['REQUEST_METHOD']);
    }

    public function testNonStringRemotePortIsCastToString(): void
    {
        // OpenSwoole hands REMOTE_PORT as an int; the prefix loop skips it
        // (non-string), then the RFC-3875 fallback casts it to a string.
        $env = Dispatcher::buildCgiEnv(['REMOTE_PORT' => 54321, 'REQUEST_METHOD' => 'GET'], 'ctx');
        $this->assertSame('54321', $env['REMOTE_PORT']);
    }

    public function testHttpProxyStrippedAndUnprefixedKeysDropped(): void
    {
        // httpoxy defence: a client "Proxy:" header (HTTP_PROXY) is dropped;
        // keys outside the allowed prefixes never reach the subprocess env.
        $env = Dispatcher::buildCgiEnv([
            'HTTP_PROXY'   => 'http://evil:3128',
            'HTTP_HOST'    => 'example.test',
            'RANDOM_KEY'   => 'leak',
            'SERVER_ARRAY' => ['not', 'a', 'string'],
        ], 'ctx');
        $this->assertArrayNotHasKey('HTTP_PROXY', $env);
        $this->assertArrayNotHasKey('RANDOM_KEY', $env);
        $this->assertArrayNotHasKey('SERVER_ARRAY', $env);   // non-string skipped
        $this->assertSame('example.test', $env['HTTP_HOST']);
    }

    public function testRfc3875DefaultsAndContextInjected(): void
    {
        $env = Dispatcher::buildCgiEnv(['REQUEST_METHOD' => 'GET'], 'ctx-blob');
        $this->assertSame('CGI/1.1', $env['GATEWAY_INTERFACE']);
        $this->assertStringStartsWith('ZealPHP/', (string) $env['SERVER_SOFTWARE']);
        $this->assertSame('ctx-blob', $env['ZEALPHP_REQUEST_CONTEXT']);
        $this->assertArrayHasKey('DOCUMENT_ROOT', $env);
    }

    public function testMintCgiSessionReturnsNullWhenHostOwnsSessions(): void
    {
        // Outside cgiOwnsSessions() mode (here: processIsolation off) the host's
        // SessionManager owns cookie emission, so the CGI mint helper is a no-op.
        // Force the mode deterministically rather than skip on ambient state.
        $savedPi = App::$process_isolation;
        App::$process_isolation = false;
        try {
            $this->assertFalse(App::cgiOwnsSessions());
            $m = new \ReflectionMethod(Dispatcher::class, 'mintCgiSession');
            $m->setAccessible(true);
            $this->assertNull($m->invoke(null, RequestContext::instance()));
        } finally {
            App::$process_isolation = $savedPi;
        }
    }
}
