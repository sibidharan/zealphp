<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * Conformance: IANA HTTP Status Code Registry (RFC 9110 §15).
 *
 * Validates ZealPHP's reason-phrase table against the authoritative IANA
 * registry — exhaustively, in both directions:
 *   1. every IANA-assigned code resolves to its exact registry "Description";
 *   2. ZealPHP advertises no code outside the assigned set (one documented
 *      extension: 418).
 *
 * Source of truth: https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
 * Registry snapshot: 2025-09-15. When IANA registers a new code, this fixture
 * is updated deliberately (a visible, reviewed diff) — drift can't pass silently.
 */
class IanaStatusConformanceTest extends TestCase
{
    protected function setUp(): void
    {
        // coerceStatusCode() logs via elog() on out-of-range, which reads App::$cwd.
        App::$cwd = ZEALPHP_ROOT;
    }

    /**
     * IANA-assigned status codes → exact registry Description.
     * Excludes: 306 & 418 ("(Unused)"), 104 (temporary registration),
     * and all "Unassigned" ranges.
     *
     * @return array<int, string>
     */
    private static function ianaAssigned(): array
    {
        return [
            // 1xx Informational
            100 => 'Continue',
            101 => 'Switching Protocols',
            102 => 'Processing',
            103 => 'Early Hints',
            // 2xx Success
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            207 => 'Multi-Status',
            208 => 'Already Reported',
            226 => 'IM Used',
            // 3xx Redirection
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            // 4xx Client Error
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Content Too Large',
            414 => 'URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Range Not Satisfiable',
            417 => 'Expectation Failed',
            421 => 'Misdirected Request',
            422 => 'Unprocessable Content',
            423 => 'Locked',
            424 => 'Failed Dependency',
            425 => 'Too Early',
            426 => 'Upgrade Required',
            428 => 'Precondition Required',
            429 => 'Too Many Requests',
            431 => 'Request Header Fields Too Large',
            451 => 'Unavailable For Legal Reasons',
            // 5xx Server Error
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
            506 => 'Variant Also Negotiates',
            507 => 'Insufficient Storage',
            508 => 'Loop Detected',
            510 => 'Not Extended',
            511 => 'Network Authentication Required',
        ];
    }

    /** Documented non-IANA extension phrases ZealPHP intentionally ships. */
    private const EXTENSIONS = [
        418 => "I'm a teapot", // RFC 2324; IANA "(Unused)"
    ];

    /**
     * Every IANA-assigned code resolves to its exact registry description.
     */
    #[DataProvider('ianaCodes')]
    public function testEveryIanaCodeHasExactRegistryPhrase(int $code, string $description): void
    {
        $this->assertSame(
            $description,
            App::reasonPhrase($code),
            "Status $code must use the IANA description '$description'"
        );
    }

    /** @return iterable<string, array{int, string}> */
    public static function ianaCodes(): iterable
    {
        foreach (self::ianaAssigned() as $code => $desc) {
            yield "$code $desc" => [$code, $desc];
        }
    }

    public function testTableHasNoUnregisteredCodes(): void
    {
        $allowed = self::ianaAssigned() + self::EXTENSIONS;
        $table   = $this->reasonPhraseTable();
        foreach (array_keys($table) as $code) {
            $this->assertArrayHasKey(
                $code,
                $allowed,
                "Status $code is in ZealPHP's table but is not IANA-assigned nor a documented extension"
            );
        }
    }

    public function testTableIsNotMissingAnyIanaCode(): void
    {
        $table = $this->reasonPhraseTable();
        foreach (array_keys(self::ianaAssigned()) as $code) {
            $this->assertArrayHasKey($code, $table, "ZealPHP is missing IANA code $code");
        }
    }

    public function testDocumentedExtensionsPresent(): void
    {
        foreach (self::EXTENSIONS as $code => $phrase) {
            $this->assertSame($phrase, App::reasonPhrase($code), "Extension code $code");
        }
    }

    public function testRfc9110RenamesAreApplied(): void
    {
        // The RFC 7231/4918 → RFC 9110 renames must be the current spelling.
        $this->assertSame('Content Too Large', App::reasonPhrase(413));      // was "Payload Too Large"
        $this->assertSame('Unprocessable Content', App::reasonPhrase(422));  // was "Unprocessable Entity"
        $this->assertSame('Range Not Satisfiable', App::reasonPhrase(416));  // was "Requested Range ..."
    }

    public function testUnknownCodeHasEmptyPhrase(): void
    {
        $this->assertSame('', App::reasonPhrase(599)); // unassigned but in range
        $this->assertSame('', App::reasonPhrase(799));
    }

    /**
     * RFC 7230 / RFC 9110 §15: a three-digit code is 100–599. Out-of-range
     * handler returns coerce to 500 (Apache parity), in-range pass through.
     */
    public function testStatusCoercionBoundaries(): void
    {
        $this->assertSame(500, App::coerceStatusCode(99));   // below range
        $this->assertSame(100, App::coerceStatusCode(100));  // lower bound
        $this->assertSame(599, App::coerceStatusCode(599));  // upper bound
        $this->assertSame(500, App::coerceStatusCode(600));  // above range
        $this->assertSame(500, App::coerceStatusCode(0));
        $this->assertSame(500, App::coerceStatusCode(-1));
        $this->assertSame(500, App::coerceStatusCode(999));
    }

    /**
     * Read the private REASON_PHRASES const for the both-directions checks.
     * @return array<int, string>
     */
    private function reasonPhraseTable(): array
    {
        $ref = new \ReflectionClass(App::class);
        /** @var array<int, string> $table */
        $table = $ref->getConstant('REASON_PHRASES');
        return $table;
    }
}
