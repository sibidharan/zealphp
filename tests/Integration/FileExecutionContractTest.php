<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * The universal return contract across App::include() / render() /
 * renderToString() / renderStream(). Fixtures live under
 * public/_contract_test/ and template/_contract_test/; routes that exercise
 * them are registered in route/_contract_test.php.
 *
 * The canonical contract is documented in template/pages/responses.php
 * (anchor #return-contract). Keep this test, the docs page, and CLAUDE.md
 * in lock-step on any change to the contract.
 */
class FileExecutionContractTest extends TestCase
{
    // --- App::include() ------------------------------------------------------

    public function testIncludeEchoOnlyEmitsBufferedBody(): void
    {
        $r = $this->get('/_contract/include/echo-only');
        $this->assertStatus(200, $r);
        // PHP_SELF auto-populated by App::include() before the include runs.
        $this->assertSame('ECHOED:/_contract_test/echo_only.php', $r['body']);
    }

    public function testIncludeReturnStatusSetsHttpStatus(): void
    {
        $r = $this->get('/_contract/include/status');
        // 418 is a valid status, NOT a 4xx-error-rendering target — the
        // ResponseMiddleware int-return path routes 4xx through renderError,
        // but 418 is in the "client error" range so renderError fires.
        // Either way, the status flows through.
        $this->assertSame(418, $r['status']);
    }

    public function testIncludeReturnArrayProducesJson(): void
    {
        $r = $this->get('/_contract/include/array');
        $this->assertStatus(200, $r);
        $this->assertHeader('Content-Type', 'application/json', $r);
        $json = $this->assertJsonResponse($r);
        $this->assertTrue($json['ok']);
        $this->assertSame('include-array', $json['who']);
    }

    public function testIncludeReturnStringBecomesHtmlBody(): void
    {
        $r = $this->get('/_contract/include/string');
        $this->assertStatus(200, $r);
        $this->assertSame('EXPLICIT-STRING-BODY', $r['body']);
    }

    public function testIncludeEchoThenReturnConcatsInWireOrder(): void
    {
        $r = $this->get('/_contract/include/echo-then-return');
        $this->assertStatus(200, $r);
        $this->assertSame('AB', $r['body']);
    }

    public function testIncludeReturnGeneratorStreams(): void
    {
        $r = $this->get('/_contract/include/generator');
        $this->assertStatus(200, $r);
        $this->assertSame('CHUNK1|CHUNK2|CHUNK3', $r['body']);
    }

    public function testIncludeEchoThenGeneratorPreservesOrder(): void
    {
        $r = $this->get('/_contract/include/echo-then-generator');
        $this->assertStatus(200, $r);
        $this->assertSame('SHELL|BODY1|BODY2', $r['body']);
    }

    public function testIncludeClosureWithParamInjection(): void
    {
        $r = $this->get('/_contract/include/closure-param');
        $this->assertStatus(200, $r);
        $this->assertSame('GREET:alice', $r['body']);
    }

    public function testIncludeAcceptsLeadingSlashOptional(): void
    {
        // Apache document-root parity: `_contract_test/return_string.php`
        // resolves to the same file as `/_contract_test/return_string.php`.
        $r = $this->get('/_contract/include/no-leading-slash');
        $this->assertStatus(200, $r);
        $this->assertSame('EXPLICIT-STRING-BODY', $r['body']);
    }

    public function testIncludeAutoPopulatesServerVars(): void
    {
        $r = $this->get('/_contract/include/server-self');
        $json = $this->assertJsonResponse($r);
        $this->assertSame('/_contract_test/server_self.php', $json['php_self']);
        $this->assertSame('/_contract_test/server_self.php', $json['script_name']);
        $this->assertStringEndsWith(
            '/public/_contract_test/server_self.php',
            $json['script_filename']
        );
    }

    public function testIncludeRefusesPathTraversalWith403(): void
    {
        $r = $this->get('/_contract/include/traversal');
        // /../etc/passwd resolves OUTSIDE document_root → 403.
        // (Pre-route URI traversal guards may also reject earlier; either
        // way the response must NOT be a successful include of /etc/passwd.)
        $this->assertContains($r['status'], [400, 403], "Expected 400 or 403, got {$r['status']}");
    }

    public function testIncludeMissingFileReturns403(): void
    {
        $r = $this->get('/_contract/include/missing');
        // realpath() returns false → include() refuses with 403 (same code
        // path as security violations; we don't leak file existence).
        $this->assertSame(403, $r['status']);
    }

    public function testIncludeFileDeprecatedAliasStillWorks(): void
    {
        $r = $this->get('/_contract/includefile/legacy-alias');
        $this->assertSame(418, $r['status']);
    }

    // --- App::render() BC + new contract ------------------------------------

    public function testRenderBcEchoesForVoidPlusEchoTemplates(): void
    {
        $r = $this->get('/_contract/render/echo-only-bc');
        $this->assertStatus(200, $r);
        $this->assertSame('TPL-ECHOED', $r['body']);
    }

    public function testRenderForwardsStatusReturn(): void
    {
        $r = $this->get('/_contract/render/status-passthrough');
        $this->assertSame(418, $r['status']);
    }

    public function testRenderForwardsArrayReturnAsJson(): void
    {
        $r = $this->get('/_contract/render/array-passthrough');
        $this->assertStatus(200, $r);
        $json = $this->assertJsonResponse($r);
        $this->assertSame('returned-array', $json['template']);
    }

    public function testRenderForwardsGeneratorReturnAsStream(): void
    {
        $r = $this->get('/_contract/render/generator-passthrough');
        $this->assertStatus(200, $r);
        $this->assertSame('TPL-GEN1|TPL-GEN2', $r['body']);
    }

    // --- App::renderToString() wrapper --------------------------------------

    public function testRenderToStringConsumesEcho(): void
    {
        $r = $this->get('/_contract/render-to-string/echo');
        $this->assertStatus(200, $r);
        $this->assertSame('TPL-ECHOED', $r['body']);
    }

    public function testRenderToStringConsumesGenerator(): void
    {
        $r = $this->get('/_contract/render-to-string/generator');
        $this->assertStatus(200, $r);
        $this->assertSame('TPL-GEN1|TPL-GEN2', $r['body']);
    }

    public function testRenderToStringJsonEncodesArray(): void
    {
        $r = $this->get('/_contract/render-to-string/array');
        $this->assertStatus(200, $r);
        // The string here is a JSON-encoded representation of the template's
        // return value — exactly what renderToString() is documented to do
        // for non-string returns.
        $this->assertStringContainsString('"returned-array"', $r['body']);
    }

    // --- App::renderStream() wrapper ----------------------------------------

    public function testRenderStreamFromEchoTemplate(): void
    {
        $r = $this->get('/_contract/render-stream/echo');
        $this->assertStatus(200, $r);
        $this->assertSame('TPL-ECHOED', $r['body']);
    }

    public function testRenderStreamFromGeneratorTemplate(): void
    {
        $r = $this->get('/_contract/render-stream/generator');
        $this->assertStatus(200, $r);
        $this->assertSame('TPL-GEN1|TPL-GEN2', $r['body']);
    }

    public function testRenderStreamInjectsClosureParams(): void
    {
        $r = $this->get('/_contract/render-stream/closure-param');
        $this->assertStatus(200, $r);
        $this->assertSame('Hello,team', $r['body']);
    }

    // --- Status code coercion (Apache-parity: 100-599 valid, others → 500) ---

    /**
     * IANA-registered codes that used to silently downgrade to 200 — now emit correctly.
     * Codes chosen because they survive OpenSwoole's C-level status whitelist
     * (425 Too Early and 451 Unavailable For Legal Reasons are silently rejected
     * by OpenSwoole 22.1.5 even when REASON_PHRASES knows them — documented
     * upstream limitation; see template/pages/responses.php#status-range).
     */
    public function testStatusCode423EmitsAsLocked(): void
    {
        $r = $this->get('/_contract/status/423');
        $this->assertSame(423, $r['status']);
    }

    public function testStatusCode421EmitsAsMisdirectedRequest(): void
    {
        $r = $this->get('/_contract/status/421');
        $this->assertSame(421, $r['status']);
    }

    public function testStatusCode511EmitsAsNetworkAuthRequired(): void
    {
        $r = $this->get('/_contract/status/511');
        $this->assertSame(511, $r['status']);
    }

    /** Out-of-range ints coerce to 500 + log a warning to debug log. */
    public function testStatusCode42CoercesTo500(): void
    {
        $r = $this->get('/_contract/status/42');
        $this->assertSame(500, $r['status']);
    }

    public function testStatusCode0CoercesTo500(): void
    {
        $r = $this->get('/_contract/status/0');
        $this->assertSame(500, $r['status']);
    }

    public function testStatusCode999CoercesTo500(): void
    {
        $r = $this->get('/_contract/status/999');
        $this->assertSame(500, $r['status']);
    }

    public function testStatusCodeNegativeCoercesTo500(): void
    {
        // _1 → -1 (underscore prefix → negative; see route/_contract_test.php)
        $r = $this->get('/_contract/status/_1');
        $this->assertSame(500, $r['status']);
    }
}
