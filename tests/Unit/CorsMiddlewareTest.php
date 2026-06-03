<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\CorsMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class CorsMiddlewareTest extends TestCase
{
    private function setWarned(bool $value): void
    {
        $ref = new \ReflectionProperty(CorsMiddleware::class, 'warnedWildcard');
        $ref->setValue(null, $value);
    }

    private function getWarned(): bool
    {
        $ref = new \ReflectionProperty(CorsMiddleware::class, 'warnedWildcard');
        return (bool) $ref->getValue();
    }

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->zealphp_response = null;
        RequestContext::instance()->status = null;
        putenv('ZEALPHP_CORS_ORIGINS');
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->zealphp_response = null;
        RequestContext::instance()->status = null;
        putenv('ZEALPHP_CORS_ORIGINS');
    }

    /** @return object{sink: array<string,mixed>} */
    private function recorder(): object
    {
        // header() takes mixed so we can observe the ACTUAL runtime type the
        // middleware passes — letting us assert (string) casts really happened
        // (CorsMiddleware.php is non-strict, so a typed string param would
        // silently coerce an int and hide a CastString mutant).
        return new class {
            /** @var array<string, mixed> */
            public array $sink = [];
            public function header(string $name, mixed $value): void
            {
                $this->sink[$name] = $value;
            }
        };
    }

    /**
     * @param array<string,string> $headers
     */
    private function invoke(CorsMiddleware $mw, string $method, array $headers): ResponseInterface
    {
        $request = new ServerRequest('/', $method, '', $headers);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }

    public function testPreflightSetsStatus204AndAllHeaders(): void
    {
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        $mw = new CorsMiddleware(origins: ['https://app.example']);
        $response = $this->invoke($mw, 'OPTIONS', ['origin' => 'https://app.example']);

        $this->assertSame(204, RequestContext::instance()->status);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://app.example', $rec->sink['Access-Control-Allow-Origin']);
        $this->assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $rec->sink['Access-Control-Allow-Methods']);
        $this->assertSame('Origin', $rec->sink['Vary']);
    }

    public function testPreflightAllowHeadersIncludesContentTypeAndIsRecorded(): void
    {
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        $mw = new CorsMiddleware(origins: ['https://app.example']);
        $this->invoke($mw, 'OPTIONS', ['origin' => 'https://app.example']);

        // Kills ArrayItemRemoval (Content-Type stripped from default headers)
        // and MethodCallRemoval (Allow-Headers header() call removed).
        $this->assertArrayHasKey('Access-Control-Allow-Headers', $rec->sink);
        $this->assertSame(
            'Content-Type, Authorization, X-Requested-With, Accept',
            $rec->sink['Access-Control-Allow-Headers']
        );
    }

    public function testPreflightMaxAgeIsExactDefaultAndStringCast(): void
    {
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        $mw = new CorsMiddleware(origins: ['https://app.example']);
        $this->invoke($mw, 'OPTIONS', ['origin' => 'https://app.example']);

        // Kills Inc/DecrementInteger on the 86400 default and CastString
        // (assertIsString fails if the int isn't cast).
        $this->assertIsString($rec->sink['Access-Control-Max-Age']);
        $this->assertSame('86400', $rec->sink['Access-Control-Max-Age']);
    }

    public function testPreflightSkippedWhenNoOriginHeader(): void
    {
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        $mw = new CorsMiddleware(origins: ['https://app.example']);
        $response = $this->invoke($mw, 'OPTIONS', []);

        // No Origin -> falls through to handler, not a 204 preflight.
        $this->assertNull(RequestContext::instance()->status);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('Access-Control-Allow-Methods', $rec->sink);
    }

    public function testEnvOriginsAreTrimmedAndEmptyEntriesFiltered(): void
    {
        // Whitespace + a stray empty entry from a trailing comma.
        putenv('ZEALPHP_CORS_ORIGINS=  https://a.example , , https://b.example ,');
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        $mw = new CorsMiddleware(); // origins null -> resolves from env

        // Request a non-listed origin so resolveOrigin returns origins[0].
        // origins[0] must be the trimmed first entry, proving trim + filter ran.
        $this->invoke($mw, 'OPTIONS', ['origin' => 'https://other.example']);

        $this->assertSame('https://a.example', $rec->sink['Access-Control-Allow-Origin']);
    }

    public function testEnvSecondTrimmedOriginEchoedWhenListed(): void
    {
        putenv('ZEALPHP_CORS_ORIGINS=  https://a.example , , https://b.example ,');
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        $mw = new CorsMiddleware();
        // The second entry must have been trimmed to 'https://b.example' so
        // it matches exactly here. Kills UnwrapArrayFilter/UnwrapArrayValues
        // (which would leave '' entries / wrong indexing).
        $this->invoke($mw, 'OPTIONS', ['origin' => 'https://b.example']);

        $this->assertSame('https://b.example', $rec->sink['Access-Control-Allow-Origin']);
    }

    public function testEnvUnsetFallsBackToWildcard(): void
    {
        // env not set -> getenv returns false -> wildcard '*'. Kills FalseValue
        // ($env !== true), LogicalAnd (&& -> ||) and the trim-related mutants on
        // the false branch (they'd try to parse a non-string and diverge).
        putenv('ZEALPHP_CORS_ORIGINS');
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        $mw = new CorsMiddleware(); // origins null, env absent
        $this->invoke($mw, 'OPTIONS', ['origin' => 'https://anything.example']);

        // Wildcard, no credentials -> literal '*'.
        $this->assertSame('*', $rec->sink['Access-Control-Allow-Origin']);
    }

    public function testEnvWhitespaceOnlyFallsBackToWildcard(): void
    {
        // env present but blank after trim -> wildcard. Kills UnwrapTrim
        // (trim($env) -> $env): unmutated trims to '' and falls to wildcard,
        // mutant sees a non-empty whitespace string and tries to parse it.
        putenv('ZEALPHP_CORS_ORIGINS=   ');
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        $mw = new CorsMiddleware();
        $this->invoke($mw, 'OPTIONS', ['origin' => 'https://anything.example']);

        $this->assertSame('*', $rec->sink['Access-Control-Allow-Origin']);
    }

    public function testEnvLeadingEmptyEntryIsFilteredAndReindexed(): void
    {
        // Leading comma -> first parsed entry is '' which MUST be filtered out
        // AND the result re-indexed so origins[0] is the real first origin.
        // Kills UnwrapArrayFilter (would keep '') and UnwrapArrayValues
        // (would leave origins[0] undefined because key 0 was the dropped '').
        putenv('ZEALPHP_CORS_ORIGINS=, https://real.example , https://second.example');
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        $mw = new CorsMiddleware();
        // Non-matching origin -> resolveOrigin returns origins[0].
        $this->invoke($mw, 'OPTIONS', ['origin' => 'https://nomatch.example']);

        $this->assertSame('https://real.example', $rec->sink['Access-Control-Allow-Origin']);
    }

    public function testWildcardFallbackLogsExactWarningMessage(): void
    {
        // Hook ZealPHP\elog to capture its arguments (works regardless of the
        // logging layer's process-wide static caches). Assert the EXACT warning
        // text + tag. Kills IfNegation on function_exists (elog skipped -> no
        // capture), FunctionCallRemoval (no elog call), Concat +
        // ConcatOperandRemoval (message text / operand order changes).
        if (!function_exists('uopz_set_hook') || !function_exists('uopz_unset_hook')) {
            $this->markTestSkipped('uopz hooks unavailable');
        }
        /** @var list<array{0: string, 1: string}> $calls */
        $calls = [];
        uopz_set_hook('ZealPHP\\elog', function (string $message, string $tag = '*') use (&$calls) {
            $calls[] = [$message, $tag];
        });

        try {
            $this->setWarned(false);
            putenv('ZEALPHP_CORS_ORIGINS');
            new CorsMiddleware(); // wildcard fallback -> warn-once path
        } finally {
            uopz_unset_hook('ZealPHP\\elog');
        }

        $this->assertCount(1, $calls);
        $this->assertSame(
            'CorsMiddleware: no origins configured; defaulting to "*". '
            . 'Set ZEALPHP_CORS_ORIGINS or pass origins explicitly for production use.',
            $calls[0][0]
        );
        $this->assertSame('cors', $calls[0][1]);
    }

    public function testWildcardFallbackSetsWarnedFlagOnceFromFalse(): void
    {
        // Reset the once-flag, trigger wildcard fallback, assert the flag flips
        // to true. Kills LogicalNot (!warned -> warned: would NOT enter the
        // block from false) and TrueValue (= true -> = false: flag wouldn't flip).
        $this->setWarned(false);
        putenv('ZEALPHP_CORS_ORIGINS');

        new CorsMiddleware(); // origins null, env absent -> wildcard branch

        $this->assertTrue($this->getWarned());
    }

    public function testWildcardFallbackLeavesWarnedTrueWhenAlreadyWarned(): void
    {
        // Pre-set true: the !warned guard must skip the body, but the flag stays
        // true. Combined with the previous test this pins the warn-once branch.
        $this->setWarned(true);
        putenv('ZEALPHP_CORS_ORIGINS');

        new CorsMiddleware();

        $this->assertTrue($this->getWarned());
    }

    public function testWildcardWithCredentialsDoesNotReflectOrigin(): void
    {
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        // origins '*' + credentials true must NOT reflect the request Origin
        // (credentialed-CORS bypass, #180): emit literal '*' and omit the
        // Access-Control-Allow-Credentials header so browsers fail safe.
        $mw = new CorsMiddleware(origins: ['*'], credentials: true);
        $this->invoke($mw, 'OPTIONS', ['origin' => 'https://caller.example']);

        $this->assertSame('*', $rec->sink['Access-Control-Allow-Origin']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $rec->sink);
    }

    public function testWildcardWithoutCredentialsReturnsStar(): void
    {
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        // credentials false -> literal '*'. No Access-Control-Allow-Credentials
        // header is emitted when credentials are disabled.
        $mw = new CorsMiddleware(origins: ['*'], credentials: false);
        $this->invoke($mw, 'OPTIONS', ['origin' => 'https://caller.example']);

        $this->assertSame('*', $rec->sink['Access-Control-Allow-Origin']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $rec->sink);
    }

    public function testExplicitAllowlistWithCredentialsReflectsMatchedOrigin(): void
    {
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        // The legitimate credentialed-CORS path still works: an explicit allowlist
        // reflects a MATCHED origin and emits Access-Control-Allow-Credentials:true.
        $mw = new CorsMiddleware(origins: ['https://app.example'], credentials: true);
        $this->invoke($mw, 'OPTIONS', ['origin' => 'https://app.example']);

        $this->assertSame('https://app.example', $rec->sink['Access-Control-Allow-Origin']);
        $this->assertSame('true', $rec->sink['Access-Control-Allow-Credentials']);
    }

    public function testNonPreflightAddsCorsHeadersAfterHandler(): void
    {
        $rec = $this->recorder();
        RequestContext::instance()->zealphp_response = $rec;

        $mw = new CorsMiddleware(origins: ['https://app.example']);
        $response = $this->invoke($mw, 'GET', ['origin' => 'https://app.example']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('https://app.example', $rec->sink['Access-Control-Allow-Origin']);
        $this->assertSame('Origin', $rec->sink['Vary']);
    }
}
