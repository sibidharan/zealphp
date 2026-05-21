<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\SetEnvIfMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class SetEnvIfMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->server = [];
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->server = [];
    }

    /**
     * Run the middleware and return the resulting $g->server snapshot.
     *
     * @param list<array<string, mixed>> $rules
     * @param array<string, string>      $headers
     * @param array<string, scalar|null> $server
     * @return array<string, scalar|null>
     */
    private function process(
        array $rules,
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        array $server = []
    ): array {
        $g = RequestContext::instance();
        $g->server = $server;

        $mw = new SetEnvIfMiddleware($rules);
        $request = new ServerRequest($path, $method, '', $headers);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        $mw->process($request, $handler);

        return $g->server;
    }

    public function testHeaderMatchSetsEnvVar(): void
    {
        $server = $this->process(
            [['attr' => 'User-Agent', 'regex' => '#bot#i', 'set' => ['IS_BOT' => '1']]],
            headers: ['user-agent' => 'Googlebot/2.1']
        );

        $this->assertSame('1', $server['IS_BOT'] ?? null);
    }

    public function testNonMatchLeavesEnvUnset(): void
    {
        $server = $this->process(
            [['attr' => 'User-Agent', 'regex' => '#bot#i', 'set' => ['IS_BOT' => '1']]],
            headers: ['user-agent' => 'Mozilla/5.0']
        );

        $this->assertArrayNotHasKey('IS_BOT', $server);
    }

    public function testInvalidRuleWithNonStringAttrIsSkipped(): void
    {
        // attr present but NOT a string -> rule must be dropped (null).
        // Kills LogicalAnd at L48 (&& -> ||): with '||', isset(attr)=true makes
        // $attr = the int, the rule survives, env would be set.
        $server = $this->process(
            [['attr' => 123, 'regex' => '#.#', 'set' => ['X' => '1']]],
            headers: ['user-agent' => 'anything']
        );

        $this->assertArrayNotHasKey('X', $server);
    }

    public function testInvalidRuleWithNonStringRegexIsSkipped(): void
    {
        // regex present but not a string -> dropped. Kills LogicalAnd at L49.
        $server = $this->process(
            [['attr' => 'User-Agent', 'regex' => 999, 'set' => ['X' => '1']]],
            headers: ['user-agent' => 'anything']
        );

        $this->assertArrayNotHasKey('X', $server);
    }

    public function testInvalidRuleWithNonArraySetIsSkipped(): void
    {
        // set present but not an array -> dropped. Kills LogicalAnd at L50 and
        // the L51 LogicalOr precedence mutants (set===null must independently
        // veto the rule).
        $server = $this->process(
            [['attr' => 'User-Agent', 'regex' => '#.#', 'set' => 'notanarray']],
            headers: ['user-agent' => 'anything']
        );

        $this->assertArrayNotHasKey('X', $server);
    }

    public function testRuleMissingOnlyAttrIsSkippedButValidRuleStillApplies(): void
    {
        // Pins the L51 || semantics: attr===null alone (regex & set valid) must
        // still drop the rule. With '&&' mutants the rule would slip through.
        $server = $this->process(
            [
                ['regex' => '#.#', 'set' => ['SHOULD_NOT' => '1']],          // no attr -> dropped
                ['attr' => 'X-Tag', 'regex' => '#yes#', 'set' => ['OK' => '1']], // valid
            ],
            headers: ['x-tag' => 'yes']
        );

        $this->assertArrayNotHasKey('SHOULD_NOT', $server);
        $this->assertSame('1', $server['OK'] ?? null);
    }

    public function testNumericKeyAndNonScalarValueAreCast(): void
    {
        // Numeric key must become string "0"; non-scalar value -> ''. Kills the
        // two CastString mutants at L56 (key cast removal, value cast removal).
        $server = $this->process(
            [['attr' => 'User-Agent', 'regex' => '#x#', 'set' => [['ARR' => 1], 'NAMED' => 5]]],
            headers: ['user-agent' => 'x']
        );

        // First element has integer key 0 -> cast to string "0", value is an
        // array -> non-scalar -> coerced to ''.
        $this->assertArrayHasKey('0', $server);
        $this->assertSame('', $server['0'] ?? 'missing');
        // Named scalar value 5 -> cast to string "5".
        $this->assertSame('5', $server['NAMED'] ?? null);
    }

    public function testRemoteAddrAttribute(): void
    {
        // Kills MatchArmRemoval for 'remote_addr'.
        $server = $this->process(
            [['attr' => 'Remote_Addr', 'regex' => '#^10\.#', 'set' => ['INTERNAL' => '1']]],
            server: ['REMOTE_ADDR' => '10.0.0.5']
        );

        $this->assertSame('1', $server['INTERNAL'] ?? null);
    }

    public function testNonStringScalarServerValueIsCastToString(): void
    {
        // REMOTE_ADDR held as a non-string scalar (int). str() declares a
        // string return; under the file's strict_types the (string) cast is
        // load-bearing — the mutant `return $v` (no cast) would TypeError when
        // returning the int from a ': string' method. A clean regex match here
        // proves the int reached the matcher as the string "127".
        $server = $this->process(
            [['attr' => 'Remote_Addr', 'regex' => '#^127$#', 'set' => ['NUM' => '1']]],
            server: ['REMOTE_ADDR' => 127]
        );

        $this->assertSame('1', $server['NUM'] ?? null);
    }

    public function testRemoteHostAttribute(): void
    {
        // Kills MatchArmRemoval for 'remote_host'.
        $server = $this->process(
            [['attr' => 'Remote_Host', 'regex' => '#example\.com$#', 'set' => ['HOSTED' => '1']]],
            server: ['REMOTE_HOST' => 'box.example.com']
        );

        $this->assertSame('1', $server['HOSTED'] ?? null);
    }

    public function testServerAddrAttribute(): void
    {
        // Kills MatchArmRemoval for 'server_addr'.
        $server = $this->process(
            [['attr' => 'Server_Addr', 'regex' => '#^192\.168\.#', 'set' => ['LAN' => '1']]],
            server: ['SERVER_ADDR' => '192.168.1.1']
        );

        $this->assertSame('1', $server['LAN'] ?? null);
    }

    public function testRequestMethodAttribute(): void
    {
        $server = $this->process(
            [['attr' => 'Request_Method', 'regex' => '#^POST$#', 'set' => ['WRITE' => '1']]],
            method: 'POST'
        );

        $this->assertSame('1', $server['WRITE'] ?? null);
    }

    public function testRequestUriAttribute(): void
    {
        $server = $this->process(
            [['attr' => 'Request_URI', 'regex' => '#^/admin#', 'set' => ['ADMIN_AREA' => '1']]],
            path: '/admin/users'
        );

        $this->assertSame('1', $server['ADMIN_AREA'] ?? null);
    }

    public function testRequestProtocolArmDistinctFromServerProtocol(): void
    {
        // 'request_protocol' and 'server_protocol' share an arm. Removing the
        // 'request_protocol' label (MatchArmRemoval) would route it to default
        // -> getHeaderLine('request_protocol') -> '' -> no match.
        $server = $this->process(
            [['attr' => 'Request_Protocol', 'regex' => '#HTTP/1\.1#', 'set' => ['H11' => '1']]],
            server: ['SERVER_PROTOCOL' => 'HTTP/1.1']
        );

        $this->assertSame('1', $server['H11'] ?? null);
    }

    public function testServerProtocolArm(): void
    {
        // Kills the MatchArmRemoval that drops the 'server_protocol' label.
        $server = $this->process(
            [['attr' => 'Server_Protocol', 'regex' => '#HTTP/2#', 'set' => ['H2' => '1']]],
            server: ['SERVER_PROTOCOL' => 'HTTP/2']
        );

        $this->assertSame('1', $server['H2'] ?? null);
    }

    public function testDefaultArmTreatsUnknownAttrAsHeader(): void
    {
        // Unknown attr name -> default arm -> read as request header.
        $server = $this->process(
            [['attr' => 'X-Custom', 'regex' => '#flag#', 'set' => ['CUSTOM' => '1']]],
            headers: ['x-custom' => 'has-flag-set']
        );

        $this->assertSame('1', $server['CUSTOM'] ?? null);
    }
}
