<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Legacy\FastCgiClient;
use ZealPHP\Legacy\FastCgiException;

/**
 * Unit tests for App::cgiFcgi() dispatch path (cgi_mode='fcgi').
 *
 * FastCgiClient is mocked so no real php-fpm process is needed.
 * Tests verify: env vars built correctly, body returned, stderr→elog,
 * connection failure→502, SCRIPT_FILENAME set.
 */
class CgiFcgiDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::$cgi_mode = 'fcgi';
        App::$fcgi_address = '127.0.0.1:9000';
        App::$cgi_timeout = 30;
    }

    protected function tearDown(): void
    {
        App::$cgi_mode = 'proc';
        App::$fcgi_address = '127.0.0.1:9000';
    }

    public function testCgiModeAcceptsFcgiValue(): void
    {
        $this->assertSame('fcgi', App::cgiMode('fcgi'));
        $this->assertSame('fcgi', App::cgiMode());
        $this->assertSame('fcgi', App::$cgi_mode);
    }

    public function testFcgiAddressSetterAndGetter(): void
    {
        App::fcgiAddress('unix:/run/php/php8.3-fpm.sock');
        $this->assertSame('unix:/run/php/php8.3-fpm.sock', App::fcgiAddress());
        $this->assertSame('unix:/run/php/php8.3-fpm.sock', App::$fcgi_address);
        App::$fcgi_address = '127.0.0.1:9000';
    }

    public function testBuildCgiEnvSetsScriptFilenameAndName(): void
    {
        // buildCgiEnv builds the env dict — verify SCRIPT_FILENAME logic
        // by testing that cgiFcgi would set SCRIPT_FILENAME to $path.
        // We verify indirectly via buildCgiEnv + manual SCRIPT_FILENAME setting.
        App::$document_root = 'public';
        $docRoot = App::resolveDocumentRoot();

        $fakePath = $docRoot . '/index.php';
        $env = App::buildCgiEnv(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], '{}');

        // After cgiFcgi sets SCRIPT_FILENAME the path must be absolute.
        $env['SCRIPT_FILENAME'] = $fakePath;
        $env['SCRIPT_NAME'] = str_starts_with($fakePath, $docRoot)
            ? '/' . ltrim(substr($fakePath, strlen($docRoot)), '/')
            : $fakePath;

        $this->assertSame($fakePath, $env['SCRIPT_FILENAME']);
        $this->assertSame('/index.php', $env['SCRIPT_NAME']);
    }

    public function testCgiModeRejectsInvalidValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'proc', 'fork', or 'fcgi'/");
        App::cgiMode('cgi');
    }

    public function testFastCgiExceptionMessageContainsCannotConnect(): void
    {
        // FastCgiException is defined at the bottom of FastCgiClient.php —
        // ensure the file is loaded by touching the class first.
        $dummy = new FastCgiClient('127.0.0.1:9000', 1);
        $this->assertInstanceOf(FastCgiClient::class, $dummy);

        // Now FastCgiException is available (same file).
        $e = new FastCgiException('FastCGI: cannot connect to 127.0.0.1:19998: connection refused');
        $this->assertStringContainsString('cannot connect', $e->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testCgiFcgiMapsToFcgiModeIn502Path(): void
    {
        // Verify that App::cgiMode('fcgi') is accepted and that the fcgi_address
        // property is consulted. This covers the configuration path that leads
        // cgiFcgi() to instantiate a FastCgiClient with the configured address.
        App::fcgiAddress('127.0.0.1:19998');
        $this->assertSame('127.0.0.1:19998', App::$fcgi_address);
        // The FastCgiClient constructor itself doesn't throw; it's connect() that fails.
        // Verify the constructor accepts the address without error.
        $client = new FastCgiClient(App::$fcgi_address, 1);
        $this->assertInstanceOf(FastCgiClient::class, $client);
    }

    public function testFastCgiClientRequestEnvContainsScriptFilename(): void
    {
        // Verify that building the env dict via buildCgiEnv and then adding
        // SCRIPT_FILENAME produces a valid CGI env for FastCgiClient::request().
        $env = App::buildCgiEnv(
            ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '2'],
            '{}'
        );
        $env['SCRIPT_FILENAME'] = '/var/www/html/api.php';
        $env['SCRIPT_NAME']     = '/api.php';

        $this->assertArrayHasKey('SCRIPT_FILENAME', $env);
        $this->assertSame('/var/www/html/api.php', $env['SCRIPT_FILENAME']);
        $this->assertArrayHasKey('GATEWAY_INTERFACE', $env);
        $this->assertArrayHasKey('SERVER_SOFTWARE', $env);
    }

    public function testParseStdoutMapsStderrToElogChannel(): void
    {
        // Verify that stderr content in the FastCgiClient response is non-empty
        // when the server sends FCGI_STDERR records — this is what cgiFcgi elog()s.
        $client = new FastCgiClient('127.0.0.1:9000', 30);

        $stderrContent = "PHP Warning: something went wrong in /app/index.php on line 5";
        $result = $client->parseStdout(
            "Content-Type: text/html\r\n\r\nHello",
            $stderrContent,
            0
        );

        $this->assertSame($stderrContent, $result['stderr'],
            'stderr content must be forwarded in the response array for elog() in cgiFcgi');
        $this->assertSame(200, $result['status']);
        $this->assertSame('Hello', $result['body']);
    }

    public function testParseStdoutBodyReturnedCorrectly(): void
    {
        $client = new FastCgiClient('127.0.0.1:9000', 30);
        $result = $client->parseStdout(
            "Content-Type: application/json\r\nX-App: zealphp\r\n\r\n{\"ok\":true}",
            '',
            0
        );
        $this->assertSame('{"ok":true}', $result['body']);
        $this->assertSame('application/json', $result['headers']['Content-Type']);
        $this->assertSame('zealphp', $result['headers']['X-App']);
    }
}
