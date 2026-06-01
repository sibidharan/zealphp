<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Diagnostics\PhpInfo;
use ZealPHP\Middleware\IpAccessMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Targeted mutation-killers for the v0.3.7 covered-MSI gate:
 *   - IpAccessMiddleware's non-byte-aligned CIDR mask (the `8 - $rem` shift).
 *   - The new phpinfo Environment + per-extension Configuration sections (HTML
 *     concat / structure).
 * Exact-output assertions so any Concat / ConcatOperandRemoval / DecrementInteger
 * mutant flips a failure.
 */
class CoverageTopUpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::superglobals(true);
        // Force the request-server-params fallback so the client IP is precise.
        RequestContext::instance()->server = [];
        RequestContext::instance()->status = null;
        PhpInfo::primeModuleText('');
    }

    private function ipStatus(string $cidr, string $clientIp): int
    {
        $mw = new IpAccessMiddleware(['allow' => [$cidr]]);
        $req = (new ServerRequest('/', 'GET', '', []))->withServerParams(['REMOTE_ADDR' => $clientIp]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/html']);
            }
        };
        return $mw->process($req, $handler)->getStatusCode();
    }

    public function testCidrNonByteAlignedMaskBoundary(): void
    {
        // /12 masks the 2nd byte with 0xf0 (top 4 bits): 10.0.0.0/12 spans
        // 10.0.0.0 .. 10.15.255.255. The DecrementInteger on `8 - $rem` would
        // shift the mask to 0xf8 and wrongly EXCLUDE 10.15.x.x — killed here.
        $this->assertSame(200, $this->ipStatus('10.0.0.0/12', '10.0.0.0'));
        $this->assertSame(200, $this->ipStatus('10.0.0.0/12', '10.15.255.255'));
        // 10.16.0.0 is outside /12 → allow-list miss → 403.
        $this->assertSame(403, $this->ipStatus('10.0.0.0/12', '10.16.0.0'));
        // A /20 (rem=4 in the 3rd byte) pins the mask byte index too.
        $this->assertSame(200, $this->ipStatus('192.168.0.0/20', '192.168.15.255'));
        $this->assertSame(403, $this->ipStatus('192.168.0.0/20', '192.168.16.0'));
    }

    private static function row(string $label, string $value): string
    {
        return '<tr><th>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</th><td>' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td></tr>';
    }

    public function testEnvironmentSectionRendersExactEnvRow(): void
    {
        // Setting a key makes $_ENV non-empty, so collectEnv() returns it (not the
        // getenv() fallback) and renderEnvironment emits the exact row + header.
        $_ENV['ZPHP_ENV_PROBE'] = 'probe-xyz';
        try {
            $html = PhpInfo::render(INFO_VARIABLES);
            $this->assertStringContainsString('<h2>Environment</h2><table>', $html);
            $this->assertStringContainsString(self::row('ZPHP_ENV_PROBE', 'probe-xyz'), $html);
        } finally {
            unset($_ENV['ZPHP_ENV_PROBE']);
        }
    }

    public function testExtensionConfigExactThreeColumnHeader(): void
    {
        $html = PhpInfo::render(INFO_CONFIGURATION);
        // Per-extension config tables use the exact 3-column header — kills the
        // Concat / ConcatOperandRemoval mutants on the header string.
        $this->assertStringContainsString(
            '<table class="zi-extcfg"><tr><th>Directive</th><th>Local Value</th><th>Master Value</th></tr>',
            $html
        );
        // At least one "ext: NAME" section renders for a loaded extension that
        // exposes ini directives (session.* exists on every standard build).
        $this->assertMatchesRegularExpression('#<h2>ext: [a-z0-9_]+</h2>#i', $html);
    }
}
