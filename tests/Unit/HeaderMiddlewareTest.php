<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\HeaderMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class HeaderMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->zealphp_response = null;
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->zealphp_response = null;
    }

    /**
     * Recorder mimicking the raw response: a 2-arg header() (set/append/unset)
     * plus a `parent` exposing the 3-arg header() the `add` path uses.
     *
     * @return object{sink: array<string, string>, parent: object}
     */
    private function recorder(): object
    {
        $parent = new class {
            /** @var list<array{name: string, value: string, replace: bool}> */
            public array $adds = [];
            public function header(string $name, string $value, bool $replace = true): void
            {
                $this->adds[] = ['name' => $name, 'value' => $value, 'replace' => $replace];
            }
        };
        return new class($parent) {
            /** @var array<string, string> */
            public array $sink = [];
            public function __construct(public object $parent) {}
            public function header(string $name, string $value): void
            {
                $this->sink[$name] = $value;
            }
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function process(array $config, ?object $rec = null): ResponseInterface
    {
        if ($rec !== null) {
            RequestContext::instance()->zealphp_response = $rec;
        }
        $mw = new HeaderMiddleware($config);
        $request = new ServerRequest('/', 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }

    public function testSetWritesPsrAndRawResponse(): void
    {
        $rec = $this->recorder();
        $response = $this->process(['set' => ['X-Frame-Options' => 'DENY']], $rec);

        // PSR-7 response carries the header.
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        // Raw response recorded it too. Kills NotIdentical at L85 (=== null
        // would skip the branch) and MethodCallRemoval at L86.
        $this->assertSame('DENY', $rec->sink['X-Frame-Options'] ?? null);
    }

    public function testAddSingleValueIsEmittedViaParentWithReplaceFalse(): void
    {
        $rec = $this->recorder();
        $response = $this->process(['add' => ['Set-Cookie' => 'a=1']], $rec);

        $this->assertSame('a=1', $response->getHeaderLine('Set-Cookie'));
        // ArrayItemRemoval at L91 ([$value] -> []) would emit nothing.
        $this->assertCount(1, $rec->parent->adds);
        $this->assertSame('Set-Cookie', $rec->parent->adds[0]['name']);
        $this->assertSame('a=1', $rec->parent->adds[0]['value']);
        // FalseValue at L100 (false -> true): replace must be false so multiple
        // entries accumulate.
        $this->assertFalse($rec->parent->adds[0]['replace']);
    }

    public function testAddArrayValuesEmitMultipleEntries(): void
    {
        $rec = $this->recorder();
        $response = $this->process(['add' => ['Link' => ['</a>; rel=x', '</b>; rel=y']]], $rec);

        $values = $response->getHeader('Link');
        $this->assertSame(['</a>; rel=x', '</b>; rel=y'], $values);
        // Both reach the raw response via parent->header(..., false).
        $this->assertCount(2, $rec->parent->adds);
        $this->assertSame('</a>; rel=x', $rec->parent->adds[0]['value']);
        $this->assertSame('</b>; rel=y', $rec->parent->adds[1]['value']);
        $this->assertFalse($rec->parent->adds[0]['replace']);
        $this->assertFalse($rec->parent->adds[1]['replace']);
    }

    public function testAddSkipsRawWhenNoRawResponse(): void
    {
        // No raw response set -> only PSR-7 path runs (covers the $resp === null
        // arm of L94 NotIdentical without touching parent).
        RequestContext::instance()->zealphp_response = null;
        $response = $this->process(['add' => ['Set-Cookie' => 'z=9']]);

        $this->assertSame('z=9', $response->getHeaderLine('Set-Cookie'));
    }

    public function testAppendMergesWithExistingValue(): void
    {
        $rec = $this->recorder();
        // Handler returns Vary already; append must comma-join.
        RequestContext::instance()->zealphp_response = $rec;
        $mw = new HeaderMiddleware(['append' => ['Vary' => 'Accept-Encoding']]);
        $request = new ServerRequest('/', 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Vary' => 'Origin']);
            }
        };
        $response = $mw->process($request, $handler);

        $this->assertSame('Origin, Accept-Encoding', $response->getHeaderLine('Vary'));
        // Raw response gets the merged value. Kills NotIdentical at L109 and
        // MethodCallRemoval at L110.
        $this->assertSame('Origin, Accept-Encoding', $rec->sink['Vary'] ?? null);
    }

    public function testAppendUsesValueWhenNoExisting(): void
    {
        $rec = $this->recorder();
        $response = $this->process(['append' => ['Vary' => 'Accept-Encoding']], $rec);

        // No existing Vary on the plain handler -> value used as-is (no comma).
        $this->assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
        $this->assertSame('Accept-Encoding', $rec->sink['Vary'] ?? null);
    }

    public function testUnsetRemovesHeaderAndRecordsEmptyOnRaw(): void
    {
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;
        $mw = new HeaderMiddleware(['unset' => ['X-Powered-By']]);
        $request = new ServerRequest('/', 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['X-Powered-By' => 'PHP/8.3', 'Content-Type' => 'text/plain']);
            }
        };
        $response = $mw->process($request, $handler);

        $this->assertFalse($response->hasHeader('X-Powered-By'));
        // Raw response gets the conventional empty-string drop. Kills
        // NotIdentical at L118 and MethodCallRemoval at L119.
        $this->assertArrayHasKey('X-Powered-By', $rec->sink);
        $this->assertSame('', $rec->sink['X-Powered-By']);
    }

    // -------------------------------------------------------------------------
    // Status-conditional tests (nginx add_header parity)
    // -------------------------------------------------------------------------

    /**
     * Default constructor ($alwaysByDefault=true): set header IS applied on 200.
     */
    public function testSetAppliedOn200ByDefault(): void
    {
        $response = $this->processWithStatus(['set' => ['X-Frame-Options' => 'DENY']], 200);
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    /**
     * Default constructor ($alwaysByDefault=true): set header IS applied on 500
     * (ZealPHP historical behaviour — equivalent to nginx always on every rule).
     */
    public function testSetAppliedOn500WhenAlwaysByDefaultTrue(): void
    {
        $response = $this->processWithStatus(['set' => ['X-Frame-Options' => 'DENY']], 500);
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    /**
     * nginx mode ($alwaysByDefault=false): set header is NOT applied on 500.
     */
    public function testSetNotAppliedOn500WhenAlwaysByDefaultFalse(): void
    {
        $response = $this->processWithStatus(
            ['set' => ['X-Frame-Options' => 'DENY']],
            500,
            alwaysByDefault: false
        );
        $this->assertFalse($response->hasHeader('X-Frame-Options'));
    }

    /**
     * nginx mode ($alwaysByDefault=false): per-rule always=true overrides the
     * middleware default — header IS applied on 500.
     */
    public function testSetAppliedOn500WhenPerRuleAlwaysTrue(): void
    {
        $response = $this->processWithStatus(
            ['set' => ['X-Frame-Options' => ['value' => 'DENY', 'always' => true]]],
            500,
            alwaysByDefault: false
        );
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    /**
     * unset is always unconditional — it fires on 500 regardless of $alwaysByDefault.
     */
    public function testUnsetIsUnconditionalOn500(): void
    {
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;
        $mw      = new HeaderMiddleware(['unset' => ['X-Powered-By']], false);
        $request = new ServerRequest('/', 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('Error', 500, '', ['X-Powered-By' => 'PHP/8.3']);
            }
        };
        $response = $mw->process($request, $handler);
        $this->assertFalse($response->hasHeader('X-Powered-By'));
    }

    /**
     * nginx mode: add rule is skipped on 404.
     */
    public function testAddNotAppliedOn404WhenAlwaysByDefaultFalse(): void
    {
        $response = $this->processWithStatus(
            ['add' => ['Link' => '</style.css>; rel=preload']],
            404,
            alwaysByDefault: false
        );
        $this->assertFalse($response->hasHeader('Link'));
    }

    /**
     * nginx mode: append rule is skipped on 503.
     */
    public function testAppendNotAppliedOn503WhenAlwaysByDefaultFalse(): void
    {
        $response = $this->processWithStatus(
            ['append' => ['Vary' => 'Accept-Encoding']],
            503,
            alwaysByDefault: false
        );
        $this->assertFalse($response->hasHeader('Vary'));
    }

    /**
     * nginx mode: 301 is a safe status — set IS applied.
     */
    public function testSetAppliedOn301InNginxMode(): void
    {
        $response = $this->processWithStatus(
            ['set' => ['X-Robots-Tag' => 'noindex']],
            301,
            alwaysByDefault: false
        );
        $this->assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
    }

    // -------------------------------------------------------------------------
    // Helper: process with an arbitrary upstream status code
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function processWithStatus(
        array $config,
        int $status,
        bool $alwaysByDefault = true,
        ?object $rec = null
    ): ResponseInterface {
        if ($rec !== null) {
            RequestContext::instance()->zealphp_response = $rec;
        }
        $mw      = new HeaderMiddleware($config, $alwaysByDefault);
        $request = new ServerRequest('/', 'GET', '', []);
        $upStatus = $status;
        $handler = new class($upStatus) implements RequestHandlerInterface {
            public function __construct(private int $upStatus) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('body', $this->upStatus, '', []);
            }
        };
        return $mw->process($request, $handler);
    }
}
