<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\RequestHeaderMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class RequestHeaderMiddlewareTest extends TestCase
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
     * Apply the rules and return the resulting $g->server the handler sees.
     *
     * @param list<array<string, mixed>> $rules
     * @param array<string, scalar|null> $server
     * @return array<string, scalar|null>
     */
    private function process(array $rules, array $server = []): array
    {
        $g = RequestContext::instance();
        $g->server = $server;

        $mw = new RequestHeaderMiddleware($rules);
        $request = new ServerRequest('/', 'GET', '', []);

        // Capture exactly what $g->server looks like inside the handler.
        $seen = [];
        $handler = new class($seen) implements RequestHandlerInterface {
            /** @param array<string, scalar|null> $seen */
            public function __construct(public array &$seen) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = RequestContext::instance()->server;
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        $mw->process($request, $handler);

        return $seen;
    }

    public function testSetWritesHttpPrefixedServerKey(): void
    {
        $server = $this->process([
            ['op' => 'set', 'name' => 'X-Forwarded-Proto', 'value' => 'https'],
        ]);

        // dashes -> underscores, uppercased, HTTP_ prefix.
        $this->assertSame('https', $server['HTTP_X_FORWARDED_PROTO'] ?? null);
    }

    public function testSetOverwritesExistingValue(): void
    {
        // 'set' (and the default arm) must REPLACE. Kills SharedCaseRemoval that
        // drops `case 'set'` / `default` -> the switch would fall to append
        // semantics and comma-join instead of replacing.
        $server = $this->process(
            [['op' => 'set', 'name' => 'X-Tag', 'value' => 'new']],
            ['HTTP_X_TAG' => 'old']
        );

        $this->assertSame('new', $server['HTTP_X_TAG'] ?? null);
    }

    public function testUnknownOpFallsToDefaultSet(): void
    {
        // An op that isn't unset/append/add/set hits `default` -> set semantics.
        // Kills the SharedCaseRemoval dropping `default`.
        $server = $this->process(
            [['op' => 'replace', 'name' => 'X-Tag', 'value' => 'fresh']],
            ['HTTP_X_TAG' => 'stale']
        );

        $this->assertSame('fresh', $server['HTTP_X_TAG'] ?? null);
    }

    public function testAppendJoinsWithExistingValue(): void
    {
        $server = $this->process(
            [['op' => 'append', 'name' => 'X-Tag', 'value' => 'b']],
            ['HTTP_X_TAG' => 'a']
        );

        $this->assertSame('a, b', $server['HTTP_X_TAG'] ?? null);
    }

    public function testAddBehavesLikeAppend(): void
    {
        // 'add' shares the 'append' arm. Kills SharedCaseRemoval dropping
        // `case 'add'` -> 'add' would fall to default (set) and overwrite
        // instead of comma-joining.
        $server = $this->process(
            [['op' => 'add', 'name' => 'X-Tag', 'value' => 'b']],
            ['HTTP_X_TAG' => 'a']
        );

        $this->assertSame('a, b', $server['HTTP_X_TAG'] ?? null);
    }

    public function testAppendWithNoExistingUsesValueAlone(): void
    {
        $server = $this->process([
            ['op' => 'append', 'name' => 'X-New', 'value' => 'solo'],
        ]);

        $this->assertSame('solo', $server['HTTP_X_NEW'] ?? null);
    }

    public function testUnsetRemovesServerKey(): void
    {
        $server = $this->process(
            [['op' => 'unset', 'name' => 'X-Debug']],
            ['HTTP_X_DEBUG' => '1', 'HTTP_KEEP' => 'yes']
        );

        $this->assertArrayNotHasKey('HTTP_X_DEBUG', $server);
        $this->assertArrayHasKey('HTTP_KEEP', $server);
    }

    public function testOpIsLowercasedSoMixedCaseSetWorks(): void
    {
        // op given as 'SET' must be lowercased to match 'set'/default. Kills
        // UnwrapStrToLower at L44: without lowering, 'SET' wouldn't equal 'set'
        // (it would still hit default which is also set — so prove append:
        // 'APPEND' must reach the append arm, not default).
        $server = $this->process(
            [['op' => 'APPEND', 'name' => 'X-Tag', 'value' => 'b']],
            ['HTTP_X_TAG' => 'a']
        );

        // If 'APPEND' weren't lowercased it would miss the 'append' case and hit
        // default (set), overwriting to 'b'. Comma-join proves lowercasing ran.
        $this->assertSame('a, b', $server['HTTP_X_TAG'] ?? null);
    }

    public function testRuleWithNonStringOpIsSkipped(): void
    {
        // op present but not a string -> dropped. Kills LogicalAnd at L44
        // (&& -> ||) which would let the int op through.
        $server = $this->process([
            ['op' => 7, 'name' => 'X-Tag', 'value' => 'x'],
        ]);

        $this->assertArrayNotHasKey('HTTP_X_TAG', $server);
    }

    public function testRuleWithNonStringNameIsSkipped(): void
    {
        // name present but not a string -> dropped. Kills LogicalAnd at L45 and
        // the L46 LogicalOr (op===null && name===null) — name===null alone must
        // veto the rule even though op is valid.
        $server = $this->process([
            ['op' => 'set', 'name' => 12345, 'value' => 'x'],
        ]);

        // No HTTP_ key should have been written from this rule.
        $this->assertSame([], $server);
    }

    public function testInvalidRuleSkippedButLaterValidRuleStillApplies(): void
    {
        // An invalid rule must be SKIPPED (continue), not abort the whole loop.
        // Kills Continue_ at L47 (continue -> break): with break, the valid
        // second rule would never register.
        $server = $this->process([
            ['op' => 7, 'name' => 'X-Bad', 'value' => 'x'],          // invalid -> skipped
            ['op' => 'set', 'name' => 'X-Good', 'value' => 'yes'],   // valid -> must still run
        ]);

        $this->assertArrayNotHasKey('HTTP_X_BAD', $server);
        $this->assertSame('yes', $server['HTTP_X_GOOD'] ?? null);
    }

    public function testNonStringScalarValueIsCastToString(): void
    {
        // value given as int -> stored as the string "1". Kills CastString at
        // L49. Then an append onto it would comma-join string-wise; here a
        // straight set proves the stored value is the string form.
        $server = $this->process([
            ['op' => 'set', 'name' => 'X-Num', 'value' => 1],
        ]);

        $this->assertArrayHasKey('HTTP_X_NUM', $server);
        $this->assertIsString($server['HTTP_X_NUM']);
        $this->assertSame('1', $server['HTTP_X_NUM']);
    }
}
