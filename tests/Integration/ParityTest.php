<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Integration tests for the mod_php-parity built-in overrides exercised through
 * a real request: filter_input() (reads $g) and php_sapi_name() (override wired).
 */
class ParityTest extends TestCase
{
    public function testFilterInputValidatesIntFromQuery(): void
    {
        $r = $this->get('/parity/filter-input?n=42');
        $this->assertStatus(200, $r);
        $json = $this->assertJsonResponse($r);
        $this->assertSame(42, $json['n']);
        $this->assertSame('integer', $json['type']);
    }

    public function testFilterInputReturnsFalseOnFailedValidation(): void
    {
        $r = $this->get('/parity/filter-input?n=notanint');
        $json = $this->assertJsonResponse($r);
        $this->assertFalse($json['n']);
    }

    public function testFilterInputReturnsNullWhenMissing(): void
    {
        $r = $this->get('/parity/filter-input');
        $json = $this->assertJsonResponse($r);
        $this->assertNull($json['n']);
        $this->assertSame('NULL', $json['type']);
    }

    public function testPhpSapiNameOverrideWiredReturnsRealSapiByDefault(): void
    {
        // Demo app does not opt in via App::sapiName(), so the override returns
        // the real PHP_SAPI ("cli") — proves it is wired and non-breaking.
        $r = $this->get('/parity/sapi');
        $json = $this->assertJsonResponse($r);
        $this->assertSame('cli', $json['sapi']);
    }

    public function testServerHasModPhpParityKeys(): void
    {
        $r = $this->get('/parity/server');
        $json = $this->assertJsonResponse($r);
        $this->assertSame('CGI/1.1', $json['GATEWAY_INTERFACE']);
        $this->assertSame('http', $json['REQUEST_SCHEME']); // plain HTTP on :8080
        $this->assertTrue($json['has_request_time']);
        $this->assertTrue($json['has_server_protocol']);
    }

    public function testHeaderRegisterCallbackFiresBeforeFlush(): void
    {
        $r = $this->get('/parity/header-callback');
        $this->assertStatus(200, $r);
        $this->assertHeader('x-registered-callback', 'fired', $r);
    }
}
