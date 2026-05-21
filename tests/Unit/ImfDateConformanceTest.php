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
use ZealPHP\Tests\TestCase;

/**
 * Conformance: IMF-fixdate — the preferred HTTP date format (RFC 9110 §5.6.7,
 * "day-name "," SP date1 SP time-of-day SP GMT"). Validated on the `Expires`
 * header `ExpiresMiddleware` produces.
 *
 * Stricter than a shape regex: the day-name and month must be real IMF tokens
 * (not just three letters), the day is 2-digit, the zone is literal `GMT`
 * (UTC, never a numeric offset or local time), and the string must parse back
 * to the correct instant.
 */
class ImfDateConformanceTest extends TestCase
{
    private const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    private const MONTHS    = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    private function expiresHeader(string $relative): string
    {
        App::superglobals(true);
        $mw = new ExpiresMiddleware([], $relative);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('body', 200, '', ['Content-Type' => 'text/html']);
            }
        };
        return $mw->process(new ServerRequest('/', 'GET'), $handler)->getHeaderLine('Expires');
    }

    public function testImfFixdateShape(): void
    {
        $v = $this->expiresHeader('+1 hour');
        // day-name "," SP 2DIGIT SP month SP 4DIGIT SP 2DIGIT:2DIGIT:2DIGIT SP "GMT"
        $this->assertMatchesRegularExpression(
            '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d{2}:\d{2}:\d{2} GMT$/',
            $v
        );
    }

    public function testTokensAreRealImfTokens(): void
    {
        $v = $this->expiresHeader('+2 days');
        [$dayPart] = explode(',', $v, 2);
        $this->assertContains($dayPart, self::DAY_NAMES, 'day-name must be a valid IMF token');
        $month = explode(' ', $v)[2];
        $this->assertContains($month, self::MONTHS, 'month must be a valid IMF token');
    }

    public function testZoneIsLiteralGmtNotOffset(): void
    {
        $v = $this->expiresHeader('+30 minutes');
        $this->assertStringEndsWith(' GMT', $v);
        $this->assertStringNotContainsString('+00', $v);     // not a numeric offset
        $this->assertStringNotContainsString('UTC', $v);     // IMF uses literal "GMT"
    }

    public function testParsesBackToCorrectUtcInstant(): void
    {
        $before = time();
        $v = $this->expiresHeader('+1 hour');
        $after = time();

        // RFC 7231 IMF-fixdate parses unambiguously in UTC.
        $parsed = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s T', $v);
        $this->assertNotFalse($parsed, "IMF-fixdate '$v' must parse");
        $ts = $parsed->getTimestamp();

        // Should be ~1 hour ahead of now (generous window for execution time).
        $this->assertGreaterThanOrEqual($before + 3600 - 5, $ts);
        $this->assertLessThanOrEqual($after + 3600 + 5, $ts);
    }

    public function testDayAndYearWidth(): void
    {
        $v = $this->expiresHeader('+1 hour');
        $day = explode(' ', $v)[1];
        $this->assertSame(2, strlen($day), 'day-of-month is 2 digits (zero-padded)');
        $year = explode(' ', $v)[3];
        $this->assertSame(4, strlen($year), 'year is 4 digits');
    }
}
