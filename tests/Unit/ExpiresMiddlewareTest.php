<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\ExpiresMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class ExpiresMiddlewareTest extends TestCase
{
    /** @var object{calls: array<int, array{0: string, 1: string}>} */
    private object $recorder;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $this->recorder = new class {
            /** @var array<int, array{0: string, 1: string}> */
            public array $calls = [];
            public function header(string $name, string $value): void
            {
                $this->calls[] = [$name, $value];
            }
        };
        RequestContext::instance()->zealphp_response = $this->recorder;
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->zealphp_response = null;
    }

    /**
     * @param array<string, string> $byType
     */
    private function process(
        array $byType,
        ?string $default,
        string $responseContentType,
        bool $preExisting = false
    ): ResponseInterface {
        $middleware = new ExpiresMiddleware($byType, $default);

        $request = new ServerRequest('/', 'GET', '', []);

        $headers = ['Content-Type' => $responseContentType];
        if ($preExisting) {
            $headers['Expires'] = 'Thu, 01 Jan 1970 00:00:00 GMT';
        }

        $handler = new class($headers) implements RequestHandlerInterface {
            /** @param array<string, string> $headers */
            public function __construct(private array $headers) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('body', 200, '', $this->headers);
            }
        };

        return $middleware->process($request, $handler);
    }

    public function testStampsExpiresForMatchingPrefix(): void
    {
        $response = $this->process(['text/css' => '+1 year'], null, 'text/css');

        $expected = gmdate('D, d M Y H:i:s', (int)strtotime('+1 year')) . ' GMT';
        // Allow 1s clock drift on the boundary by comparing the date portion
        // is set and well-formed rather than asserting an exact second.
        $this->assertTrue($response->hasHeader('Expires'));
        $value = $response->getHeaderLine('Expires');
        $this->assertMatchesRegularExpression(
            '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/',
            $value
        );
        // Year should be ~1 year out.
        $this->assertSame(substr($expected, 0, 16), substr($value, 0, 16));
    }

    public function testWritesToRawZealphpResponse(): void
    {
        // Kills the MethodCallRemoval at L91 — the raw response recorder must
        // see the Expires header in addition to the PSR-7 withHeader.
        $this->process(['text/css' => '+1 year'], null, 'text/css');

        $this->assertCount(1, $this->recorder->calls);
        $this->assertSame('Expires', $this->recorder->calls[0][0]);
        $value = $this->recorder->calls[0][1];
        $this->assertMatchesRegularExpression(
            '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/',
            $value
        );
    }

    public function testRawResponseValueMatchesPsr7Value(): void
    {
        $response = $this->process(['text/css' => '+1 year'], null, 'text/css');
        $this->assertSame(
            $response->getHeaderLine('Expires'),
            $this->recorder->calls[0][1]
        );
    }

    public function testPrefixMatchIsCaseInsensitiveOnConfigKey(): void
    {
        // Config prefix supplied uppercase, response CT is lowercase. The
        // constructor strtolower()s the key so they still match. Kills the
        // UnwrapStrToLower / CastString mutants at L62.
        $response = $this->process(['TEXT/CSS' => '+1 year'], null, 'text/css');
        $this->assertTrue($response->hasHeader('Expires'));
    }

    public function testPrefixMatchIsCaseInsensitiveOnResponseContentType(): void
    {
        // Response CT uppercase; the process() strtolower()s the CT before
        // matching. Kills UnwrapStrToLower at L76.
        $response = $this->process(['text/css' => '+1 year'], null, 'TEXT/CSS; charset=utf-8');
        $this->assertTrue($response->hasHeader('Expires'));
    }

    public function testLongestPrefixWinsViaUksort(): void
    {
        // Both 'image/' and 'image/jpeg' configured with DIFFERENT durations.
        // uksort longest-first guarantees 'image/jpeg' is matched for a
        // image/jpeg CT. Kills the CastString mutants on the uksort closure
        // (L64) — if the sort order flips, 'image/' (shorter) would win and
        // produce the +30 days value instead of +1 year.
        $response = $this->process(
            ['image/' => '+30 days', 'image/jpeg' => '+1 year'],
            null,
            'image/jpeg'
        );
        $expectedYear = gmdate('D, d M Y H:i:s', (int)strtotime('+1 year'));
        $value = $response->getHeaderLine('Expires');
        $this->assertSame(substr($expectedYear, 0, 16), substr($value, 0, 16));

        // Sanity: the +30 days value is clearly different.
        $expectedMonth = gmdate('D, d M Y', (int)strtotime('+30 days'));
        $this->assertStringNotContainsString($expectedMonth, $value);
    }

    public function testDefaultAppliesWhenNoPrefixMatches(): void
    {
        $response = $this->process(['text/css' => '+1 year'], '+5 minutes', 'application/pdf');
        $this->assertTrue($response->hasHeader('Expires'));
        $expected = gmdate('D, d M Y H:i', (int)strtotime('+5 minutes'));
        $this->assertStringStartsWith($expected, $response->getHeaderLine('Expires'));
    }

    public function testNullDefaultSkipsWhenNoMatch(): void
    {
        $response = $this->process(['text/css' => '+1 year'], null, 'application/pdf');
        $this->assertFalse($response->hasHeader('Expires'));
        $this->assertCount(0, $this->recorder->calls);
    }

    public function testEmptyContentTypeUsesDefault(): void
    {
        $response = $this->process([], '+5 minutes', '');
        $this->assertTrue($response->hasHeader('Expires'));
    }

    public function testEmptyContentTypeWithNullDefaultSkips(): void
    {
        $response = $this->process([], null, '');
        $this->assertFalse($response->hasHeader('Expires'));
    }

    public function testPreExistingExpiresIsNotOverwritten(): void
    {
        $response = $this->process(['text/css' => '+1 year'], null, 'text/css', preExisting: true);
        $this->assertSame('Thu, 01 Jan 1970 00:00:00 GMT', $response->getHeaderLine('Expires'));
        $this->assertCount(0, $this->recorder->calls);
    }

    public function testUnparseableRelativeDateSkips(): void
    {
        // strtotime returns false for garbage => no Expires stamped.
        // Kills the FalseValue mutant at L83 ($ts === false -> $ts === true):
        // with a valid date the mutant would treat the real timestamp as
        // "not false" incorrectly — but here a false strtotime must skip.
        $response = $this->process(['text/css' => 'not-a-real-date'], null, 'text/css');
        $this->assertFalse($response->hasHeader('Expires'));
        $this->assertCount(0, $this->recorder->calls);
    }

    public function testNumericPrefixKeysExerciseUksortCasts(): void
    {
        // Purely-numeric config keys (e.g. '404') are silently cast to int by
        // PHP's array machinery. The constructor's uksort closure at L64
        // compares two such keys, so BOTH (string)$a and (string)$b casts
        // receive an int; dropping either => strlen(int) TypeError under
        // strict_types in the constructor itself => mutant killed.
        //
        // The CT is left empty so resolveRelative() returns $default before
        // iterating the (now int-keyed) prefix map — int prefixes can't be
        // fed to str_starts_with(). We only need the constructor's uksort to
        // run to observe the mutation.
        $response = $this->process(
            ['404' => '+1 year', '40404' => '+30 days'],
            '+5 minutes',
            ''
        );
        $expected = gmdate('D, d M Y H:i', (int)strtotime('+5 minutes'));
        $this->assertStringStartsWith($expected, $response->getHeaderLine('Expires'));
    }

    public function testNumericPrefixKeyIsStringifiedAtConstruction(): void
    {
        // Kills the L62 (string) cast: a single numeric key '404' must round
        // through strtolower((string)$prefix) without a TypeError. Empty CT +
        // default avoids the int-key str_starts_with() path.
        $response = $this->process(['404' => '+1 year'], '+5 minutes', '');
        $this->assertTrue($response->hasHeader('Expires'));
    }

    public function testValidRelativeDateIsStampedNotSkipped(): void
    {
        // Complement to the above: a valid date must NOT be skipped. Kills the
        // FalseValue mutant ($ts === false -> $ts === true) which would skip
        // every successfully-parsed date.
        $response = $this->process(['text/css' => '+1 year'], null, 'text/css');
        $this->assertTrue($response->hasHeader('Expires'));
    }
}
