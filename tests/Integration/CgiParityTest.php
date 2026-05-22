<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Integration tests proving CGI parity end-to-end in COROUTINE mode (the
 * server's default lifecycle). Requires a running ZealPHP server on
 * TEST_SERVER_URL with the CGI backends registered in app.php:
 *
 *   App::registerCgiBackend('.py', ['mode'=>'proc', 'interpreter'=>python3,
 *                                   'exec_paths'=>['/cgi-bin']]);
 *   App::registerCgiBackend('.pl', ['mode'=>'proc', 'interpreter'=>perl,
 *                                   'exec_paths'=>['/cgi-bin']]);
 *
 * Fixtures live under public/cgi-bin/ (executable) and public/notexec/.
 *
 * What these prove:
 *  - A non-PHP script under the /cgi-bin ExecCGI scope runs via its
 *    interpreter and its stdout becomes the HTTP response (Python + Perl).
 *  - The POST request body is piped to the CGI process stdin.
 *  - A registered extension OUTSIDE an exec scope is REFUSED (403): no
 *    execution AND no source leak — the ExecCGI gate is the security proof.
 */
class CgiParityTest extends TestCase
{
    public function testPythonViaUrlInCoroutineMode(): void
    {
        $r = $this->get('/cgi-bin/hello.py');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString(
            'hello from python',
            $r['body'],
            'Expected Python CGI stdout in the response body, got: ' . substr($r['body'], 0, 200)
        );
    }

    public function testPostBodyReachesCgiStdin(): void
    {
        $r = $this->post('/cgi-bin/echo.py', [], 'PING123');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString(
            'PING123',
            $r['body'],
            'Expected the POST body to be echoed from CGI stdin, got: ' . substr($r['body'], 0, 200)
        );
    }

    public function testPerlViaUrl(): void
    {
        $perl = trim((string) shell_exec('command -v perl'));
        if ($perl === '') {
            $this->markTestSkipped('perl not installed');
        }

        $r = $this->get('/cgi-bin/hello.pl');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString(
            'hello from perl',
            $r['body'],
            'Expected Perl CGI stdout in the response body, got: ' . substr($r['body'], 0, 200)
        );
    }

    public function testExtensionOutsideExecPathIsNotExecuted(): void
    {
        $r = $this->get('/notexec/hello.py');

        // The ExecCGI gate must refuse a registered extension outside an
        // exec scope: 403, never 200.
        $this->assertNotSame(200, $r['status'], 'A .py outside /cgi-bin must NOT execute (expected 403)');
        $this->assertStatus(403, $r);

        // No execution: the script's runtime output must not appear.
        $this->assertStringNotContainsString(
            'hello from python',
            $r['body'],
            'CGI script outside the exec scope must NOT be executed'
        );
        // No source leak: the raw script source must not be served either.
        $this->assertStringNotContainsString(
            'usr/bin/env python3',
            $r['body'],
            'CGI script source outside the exec scope must NOT be leaked'
        );
    }
}
