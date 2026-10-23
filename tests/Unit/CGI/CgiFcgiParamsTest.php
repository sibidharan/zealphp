<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\CGI\Dispatcher;
use ZealPHP\RequestContext;

/**
 * What cgiFcgi() sends to a FastCGI upstream (C5, C6).
 *
 *  - C5: PHP_VALUE carries session.use_strict_mode from App::$session_strict_mode
 *    (FPM reads php.ini, which ships it off on Debian/Ubuntu).
 *  - C6: ZEALPHP_REQUEST_CONTEXT (a copy of the request AND of the server's
 *    environment) and the other cgi_worker channels go to the upstream only
 *    when App::fcgiForwardContext() opts in.
 *
 * A tiny FastCGI responder runs in a subprocess and answers with the params it
 * received, so the real client and wire format are exercised.
 */
final class CgiFcgiParamsTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $origAddress = '';
    private bool $origForward = false;
    private bool $origForwardSet = false;
    private bool $origStrict = true;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        $this->origAddress = App::$fcgi_address;
        $this->origForward = App::$fcgi_forward_context;
        $this->origForwardSet = App::$fcgi_forward_context_set;
        $this->origStrict = App::$session_strict_mode;
        $g = RequestContext::instance();
        $g->server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x.php', 'HTTP_HOST' => 'localhost'];
        $g->get = ['q' => '1'];
        $g->post = [];
        $g->cookie = [];
        $g->files = [];
        $g->env = ['ZP_TEST_DB_PASSWORD' => 'hunter2'];
        $g->zealphp_request = new class {
            public object $parent;
            public function __construct()
            {
                $this->parent = new class {
                    public function rawContent(): string { return ''; }
                };
            }
        };
        $g->zealphp_response = new class {
            /** @var array<string,string> */
            public array $headers = [];
            public function header(mixed $k, mixed $v, mixed $replace = true): void { $this->headers[(string) $k] = (string) $v; }
        };
    }

    protected function tearDown(): void
    {
        App::$fcgi_address = $this->origAddress;
        App::$fcgi_forward_context = $this->origForward;
        App::$fcgi_forward_context_set = $this->origForwardSet;
        App::$session_strict_mode = $this->origStrict;
        if (is_resource($this->server)) {
            proc_terminate($this->server, 9);
            proc_close($this->server);
        }
        $this->server = null;
    }

    /** Start a one-shot FastCGI responder that echoes its PARAMS as JSON; returns its address. */
    private function startEchoUpstream(): string
    {
        $code = <<<'PHP'
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$name = stream_socket_get_name($srv, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);
$c = stream_socket_accept($srv, 10);
$read = function (int $n) use ($c): string {
    $b = '';
    while (strlen($b) < $n) { $d = fread($c, $n - strlen($b)); if ($d === false || $d === '') break; $b .= $d; }
    return $b;
};
$params = '';
while (true) {
    $h = unpack('Cv/Ct/nid/nlen/Cpad/Cr', $read(8));
    $body = $h['len'] > 0 ? $read($h['len']) : '';
    if ($h['pad'] > 0) $read($h['pad']);
    if ($h['t'] === 4) $params .= $body;
    if ($h['t'] === 5 && $h['len'] === 0) break;
}
$out = []; $i = 0; $n = strlen($params);
$len = function () use ($params, &$i): int {
    $b = ord($params[$i]);
    if ($b < 128) { $i++; return $b; }
    $v = unpack('N', substr($params, $i, 4))[1] & 0x7fffffff; $i += 4; return $v;
};
while ($i < $n) { $kl = $len(); $vl = $len(); $k = substr($params, $i, $kl); $i += $kl; $out[$k] = substr($params, $i, $vl); $i += $vl; }
$rec = function (int $type, string $body): string {
    $pad = (8 - strlen($body) % 8) % 8;
    return pack('CCnnCC', 1, $type, 1, strlen($body), $pad, 0) . $body . str_repeat("\0", $pad);
};
$payload = "Content-Type: application/json\r\n\r\n" . json_encode($out);
fwrite($c, $rec(6, $payload) . $rec(6, '') . $rec(3, pack('NC', 0, 0) . "\0\0\0"));
fclose($c);
PHP;
        $pipes = [];
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $this->server = $proc;
        $port = trim((string) fgets($pipes[1]));
        $this->assertMatchesRegularExpression('/^\d+$/', $port, 'echo upstream did not start');
        return '127.0.0.1:' . $port;
    }

    /**
     * @param array<string,string> $extra
     * @return array<string,string>
     */
    private function paramsSent(array $extra = []): array
    {
        App::$fcgi_address = $this->startEchoUpstream();
        $body = Dispatcher::cgiFcgi(ZEALPHP_ROOT . '/public/x.php', null, $extra);
        $this->assertIsString($body, 'cgiFcgi returned ' . var_export($body, true));
        /** @var array<string,string> $params */
        $params = json_decode($body, true);
        $this->assertIsArray($params);
        return $params;
    }

    public function testContextAndInternalChannelsAreNotSentByDefault(): void
    {
        App::$fcgi_forward_context = false;
        $p = $this->paramsSent();
        foreach (['ZEALPHP_REQUEST_CONTEXT', 'ZEALPHP_CWD', 'ZEALPHP_CGI_DISPLAY_ERRORS', 'ZEALPHP_CGI_AUTOLOAD'] as $k) {
            $this->assertArrayNotHasKey($k, $p, "{$k} is not sent to the upstream by default");
        }
        $this->assertStringNotContainsString('hunter2', (string) json_encode($p), 'the server env never reaches the upstream');
        // What FPM itself needs is still there.
        $this->assertSame(ZEALPHP_ROOT . '/public/x.php', $p['SCRIPT_FILENAME']);
        $this->assertSame('/x.php', $p['SCRIPT_NAME']);
        $this->assertSame('GET', $p['REQUEST_METHOD']);
        $this->assertSame('CGI/1.1', $p['GATEWAY_INTERFACE']);
        $this->assertSame('localhost', $p['HTTP_HOST']);
    }

    public function testContextIsSentWhenOptedIn(): void
    {
        App::fcgiForwardContext(true);
        $p = $this->paramsSent();
        $this->assertArrayHasKey('ZEALPHP_REQUEST_CONTEXT', $p);
        $ctx = json_decode($p['ZEALPHP_REQUEST_CONTEXT'], true);
        $this->assertIsArray($ctx);
        $this->assertSame(['q' => '1'], $ctx['get']);
        $this->assertSame(App::$cwd, $p['ZEALPHP_CWD']);
    }

    public function testPhpValueCarriesSessionStrictMode(): void
    {
        App::$session_strict_mode = true;
        $this->assertSame('session.use_strict_mode=1', $this->paramsSent()['PHP_VALUE'] ?? null);
        $this->tearDown();
        $this->setUp();
        App::$session_strict_mode = false;
        $this->assertSame('session.use_strict_mode=0', $this->paramsSent()['PHP_VALUE'] ?? null);
    }

    public function testBackendPhpValueIsAppendedSoItWins(): void
    {
        App::$session_strict_mode = true;
        $p = $this->paramsSent(['PHP_VALUE' => 'session.use_strict_mode=0', 'X_EXTRA' => 'y']);
        $this->assertSame("session.use_strict_mode=1\nsession.use_strict_mode=0", $p['PHP_VALUE']);
        $this->assertSame('y', $p['X_EXTRA']);
    }

    public function testForwardContextKnobAndEnv(): void
    {
        App::$fcgi_forward_context = false;
        App::$fcgi_forward_context_set = false;
        putenv('ZEALPHP_FCGI_FORWARD_CONTEXT=1');
        try {
            App::resolveCgiEnv();
            $this->assertTrue(App::$fcgi_forward_context, 'env opts in');
            App::$fcgi_forward_context = false;
            App::fcgiForwardContext(false);
            App::resolveCgiEnv();
            $this->assertFalse(App::fcgiForwardContext(), 'an explicit setter call beats the env');
        } finally {
            putenv('ZEALPHP_FCGI_FORWARD_CONTEXT');
        }
    }
}
