<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Diagnostics\PhpInfo;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Mutation-killing coverage for the NEW phpinfo sections:
 *   - renderSystem()              (exact <tr><th>LABEL</th><td>VALUE</td></tr> rows)
 *   - renderExtensionConfiguration() (per-ext 3-column tables + `ext: NAME` headers)
 *   - renderEnvironment() / collectEnv()
 *   - renderToc() / anchor() / slug()
 *   - document() header (zi-header / zi-logo / zi-sub) + nav placement
 *   - safe() guarded-probe fallback to '—'
 *
 * Style mirrors PhpInfoTest.php: assert EXACT substrings/ordering so ANY
 * Concat / ConcatOperandRemoval / CastInt / CastString / IfNegation / Foreach_
 * mutant flips an assertion. Expected values are computed from the same PHP
 * primitives the source uses (not hardcoded) so the suite stays portable.
 */
class PhpInfoSectionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PhpInfo::primeModuleText('');
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Exact rendered key/value row as section()/renderSystem() emit it. */
    private static function row(string $label, string $value): string
    {
        return '<tr><th>' . self::esc($label) . '</th><td>' . self::esc($value) . '</td></tr>';
    }

    // ---------------------------------------------------------------------
    // renderSystem() — exact rows (l.192-224). Always rendered (no flag gate).
    // ---------------------------------------------------------------------

    public function testSystemSectionHeaderAndTableOpen(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // Exact header + table open. Kills Concat / wrapper removal on the <h2>.
        $this->assertStringContainsString('<h2>PHP Core / System</h2><table>', $html);
    }

    public function testSystemRowSystemUname(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // System => php_uname() exactly. Kills the ConcatOperandRemoval that would
        // drop the value and the FunctionCallRemoval on php_uname().
        $this->assertStringContainsString(self::row('System', php_uname()), $html);
    }

    public function testSystemRowServerApiSuffixConcat(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // 'cli' . ' (under ZealPHP/OpenSwoole)' — exact concat order.
        // Kills the Concat reorder (suffix . PHP_SAPI) and ConcatOperandRemoval.
        $this->assertStringContainsString(
            self::row('Server API', PHP_SAPI . ' (under ZealPHP/OpenSwoole)'),
            $html
        );
        // The swapped form must not appear.
        $this->assertStringNotContainsString(
            self::row('Server API', ' (under ZealPHP/OpenSwoole)' . PHP_SAPI),
            $html
        );
    }

    public function testSystemRowVersionId(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // (string) PHP_VERSION_ID — kills CastInt removal (which would still cast,
        // but the exact numeric string pins the value all the same).
        $this->assertStringContainsString(self::row('PHP Version ID', (string) PHP_VERSION_ID), $html);
    }

    public function testSystemRowDebugBuildBranch(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // PHP_DEBUG ? 'yes' : 'no' — assert the exact branch taken (kills IfNegation,
        // which would emit the opposite literal).
        $expected = PHP_DEBUG ? 'yes' : 'no';
        $this->assertStringContainsString(self::row('Debug Build', $expected), $html);
        $this->assertStringNotContainsString(self::row('Debug Build', PHP_DEBUG ? 'no' : 'yes'), $html);
    }

    public function testSystemRowThreadSafetyBranch(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // PHP_ZTS ? 'enabled' : 'disabled' — exact branch + the opposite must be absent.
        $expected = PHP_ZTS ? 'enabled' : 'disabled';
        $other    = PHP_ZTS ? 'disabled' : 'enabled';
        $this->assertStringContainsString(self::row('Thread Safety', $expected), $html);
        $this->assertStringNotContainsString(self::row('Thread Safety', $other), $html);
    }

    public function testSystemRowIpv6Branch(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // defined('AF_INET6') ? 'enabled' : 'disabled' — exact ternary branch.
        $expected = defined('AF_INET6') ? 'enabled' : 'disabled';
        $other    = defined('AF_INET6') ? 'disabled' : 'enabled';
        $this->assertStringContainsString(self::row('IPv6 Support', $expected), $html);
        $this->assertStringNotContainsString(self::row('IPv6 Support', $other), $html);
    }

    public function testSystemRowConfigFilePath(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(
            self::row('Configuration File (php.ini) Path', PHP_CONFIG_FILE_PATH),
            $html
        );
    }

    public function testSystemRowRegisteredStreams(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // implode(', ', stream_get_wrappers()) — exact join. Kills the separator
        // CastString/Concat mutants and FunctionCallRemoval on stream_get_wrappers.
        $this->assertStringContainsString(
            self::row('Registered PHP Streams', implode(', ', stream_get_wrappers())),
            $html
        );
    }

    public function testSystemRowRegisteredTransports(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(
            self::row('Registered Stream Socket Transports', implode(', ', stream_get_transports())),
            $html
        );
    }

    public function testSystemRowRegisteredFilters(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(
            self::row('Registered Stream Filters', implode(', ', stream_get_filters())),
            $html
        );
    }

    public function testSystemRowsRenderInSourceOrder(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // Confine the ordering check to the renderSystem() segment only — labels
        // like "System"/"Server API"/"Zend Version" also appear in renderGeneral(),
        // so a whole-document strpos would match the wrong (earlier) section.
        $segStart = strpos($html, '<h2>PHP Core / System</h2><table>');
        $this->assertNotFalse($segStart);
        $segEnd = strpos($html, '</table>', $segStart);
        $this->assertNotFalse($segEnd);
        $seg = substr($html, $segStart, $segEnd - $segStart);

        // The $rows array order is the emit order (section() iterates in order).
        // Pin the ordered chain — kills ArrayItemRemoval that would drop a row and
        // any reordering of the literal labels within the System table.
        $pSystem  = strpos($seg, '<tr><th>System</th><td>');
        $pSapi    = strpos($seg, '<tr><th>Server API</th><td>' . self::esc(PHP_SAPI));
        $pVerId   = strpos($seg, '<tr><th>PHP Version ID</th><td>');
        $pZend    = strpos($seg, '<tr><th>Zend Version</th><td>');
        $pStreams = strpos($seg, '<tr><th>Registered PHP Streams</th><td>');
        $this->assertNotFalse($pSystem);
        $this->assertNotFalse($pSapi);
        $this->assertNotFalse($pVerId);
        $this->assertNotFalse($pZend);
        $this->assertNotFalse($pStreams);
        // Emit order: System -> Server API -> ... -> PHP Version ID -> ... -> Zend
        // -> ... -> Registered PHP Streams.
        $this->assertLessThan($pSapi, $pSystem, 'System row precedes Server API');
        $this->assertLessThan($pVerId, $pSapi, 'Server API precedes PHP Version ID');
        $this->assertLessThan($pZend, $pVerId, 'PHP Version ID precedes Zend Version');
        $this->assertLessThan($pStreams, $pZend, 'Zend precedes Registered PHP Streams');
    }

    // ---------------------------------------------------------------------
    // renderExtensionConfiguration() — per-ext 3-col tables + `ext: NAME` (l.257)
    // ---------------------------------------------------------------------

    /**
     * Pick a loaded extension that exposes ini directives, plus one of its
     * directive rows, computed exactly the way the source does.
     *
     * @return array{0:string,1:string,2:string,3:string} [ext, directive, localValue, masterValue]
     */
    private static function pickExtDirective(): array
    {
        $exts = get_loaded_extensions();
        sort($exts);
        foreach ($exts as $ext) {
            $all = @ini_get_all($ext, true);
            if (!is_array($all) || $all === []) {
                continue;
            }
            foreach ($all as $directive => $info) {
                if (!is_array($info)) {
                    continue;
                }
                $lv = $info['local_value'] ?? '';
                $mv = $info['global_value'] ?? '';
                $local  = is_scalar($lv) ? (string) $lv : '';
                $master = is_scalar($mv) ? (string) $mv : '';
                return [(string) $ext, (string) $directive, $local, $master];
            }
        }
        return ['', '', '', ''];
    }

    public function testExtensionConfigGatedOnConfigurationFlag(): void
    {
        // Present under INFO_CONFIGURATION...
        $cfg = PhpInfo::render(INFO_CONFIGURATION);
        $this->assertStringContainsString('<h2>ext: ', $cfg);
        // ...absent under INFO_GENERAL only (the if-guard branch). Kills IfNegation
        // on the `($flags & INFO_CONFIGURATION) !== 0` gate.
        $gen = PhpInfo::render(INFO_GENERAL);
        $this->assertStringNotContainsString('<h2>ext: ', $gen);
    }

    public function testExtensionConfigHeaderExact(): void
    {
        [$ext] = self::pickExtDirective();
        if ($ext === '') {
            $this->markTestSkipped('No extension exposes ini directives in this build');
        }
        $html = PhpInfo::render(INFO_CONFIGURATION);
        // Exact `ext: NAME` header — kills the 'ext: ' . $ext Concat reorder/removal.
        $this->assertStringContainsString('<h2>' . self::esc('ext: ' . $ext) . '</h2>', $html);
        // The swapped concat must not appear.
        $this->assertStringNotContainsString('<h2>' . self::esc($ext . 'ext: ') . '</h2>', $html);
    }

    public function testExtensionConfigTableHeadIsThreeColumn(): void
    {
        $html = PhpInfo::render(INFO_CONFIGURATION);
        // Exact 3-column header row + the zi-extcfg class. Kills any
        // ConcatOperandRemoval / reorder of the three column-name literals.
        $this->assertStringContainsString(
            '<table class="zi-extcfg"><tr><th>Directive</th><th>Local Value</th><th>Master Value</th></tr>',
            $html
        );
    }

    public function testExtensionConfigRealDirectiveRowExact(): void
    {
        [$ext, $directive, $local, $master] = self::pickExtDirective();
        if ($ext === '' || $directive === '') {
            $this->markTestSkipped('No extension directive available in this build');
        }
        $html = PhpInfo::render(INFO_CONFIGURATION);
        // The 3-column data row: directive in <th>, local + master in two <td>s.
        // Each empty value becomes 'no value' (ternary). Kills the per-cell
        // wrapper removals + the `!== '' ? $v : 'no value'` branch + the column order.
        $localCell  = $local !== '' ? $local : 'no value';
        $masterCell = $master !== '' ? $master : 'no value';
        $expected = '<tr><th>' . self::esc($directive) . '</th><td>'
            . self::esc($localCell) . '</td><td>'
            . self::esc($masterCell) . '</td></tr>';
        $this->assertStringContainsString($expected, $html);
    }

    public function testExtensionConfigEmptyValueBecomesNoValue(): void
    {
        // Find a directive whose local value is empty -> rendered 'no value'.
        $exts = get_loaded_extensions();
        sort($exts);
        $found = null;
        foreach ($exts as $ext) {
            $all = @ini_get_all($ext, true);
            if (!is_array($all) || $all === []) {
                continue;
            }
            foreach ($all as $directive => $info) {
                if (!is_array($info)) {
                    continue;
                }
                $lv = $info['local_value'] ?? '';
                $local = is_scalar($lv) ? (string) $lv : '';
                if ($local === '') {
                    $found = (string) $directive;
                    break 2;
                }
            }
        }
        if ($found === null) {
            $this->markTestSkipped('No extension directive with an empty local value in this build');
        }
        $html = PhpInfo::render(INFO_CONFIGURATION);
        // The empty-local cell must read 'no value' (ternary true-branch).
        // Kills the IfNegation that would emit '' (empty <td>) instead.
        $this->assertStringContainsString(
            '<tr><th>' . self::esc($found) . '</th><td>no value</td>',
            $html
        );
    }

    public function testExtensionConfigAnchorPrecedesHeader(): void
    {
        [$ext] = self::pickExtDirective();
        if ($ext === '') {
            $this->markTestSkipped('No extension exposes ini directives in this build');
        }
        $html = PhpInfo::render(INFO_CONFIGURATION);
        // anchor() with id = slug('ext: NAME') is emitted JUST before the <h2>.
        $slug = self::slug('ext: ' . $ext);
        $expectedAnchor = '<a class="zi-anchor" id="' . self::esc($slug) . '"></a><h2>' . self::esc('ext: ' . $ext) . '</h2>';
        $this->assertStringContainsString($expectedAnchor, $html);
    }

    public function testExtensionConfigAccumulatesMultipleSections(): void
    {
        // The foreach appends every qualifying ext; the `$html .=` accumulation
        // (not `$html =`) means many sections survive. Kills the Assignment mutant.
        $html = PhpInfo::render(INFO_CONFIGURATION);
        $this->assertGreaterThan(2, substr_count($html, '<h2>ext: '));
        // Every ext section carries the 3-col head exactly once per section.
        $this->assertSame(
            substr_count($html, '<h2>ext: '),
            substr_count($html, '<table class="zi-extcfg">')
        );
    }

    public function testExtensionConfigTitlesNeverCollideWithReservedHeaders(): void
    {
        // Contract from the docblock: ext section titles are the NAME only and must
        // not contain "Loaded Extensions" / "PHP Variables" (flag-selectivity relies
        // on it). Under INFO_CONFIGURATION those two headers must be absent.
        $html = PhpInfo::render(INFO_CONFIGURATION);
        $this->assertStringContainsString('<h2>ext: ', $html);
        $this->assertStringNotContainsString('Loaded Extensions', $html);
        $this->assertStringNotContainsString('PHP Variables', $html);
    }

    // ---------------------------------------------------------------------
    // renderEnvironment() / collectEnv() (l.305-329) — gated on INFO_VARIABLES
    // ---------------------------------------------------------------------

    public function testEnvironmentSectionGatedOnVariablesFlag(): void
    {
        // Present under INFO_VARIABLES, absent under INFO_GENERAL (if-guard branch).
        $varsHtml = PhpInfo::render(INFO_VARIABLES);
        $this->assertStringContainsString('<h2>Environment</h2><table>', $varsHtml);
        $genHtml = PhpInfo::render(INFO_GENERAL);
        $this->assertStringNotContainsString('<h2>Environment</h2>', $genHtml);
    }

    public function testEnvironmentRendersSortedRealVariableRow(): void
    {
        $env = $_ENV;
        if ($env === []) {
            $env = getenv();
        }
        if (!is_array($env) || $env === []) {
            $this->markTestSkipped('No environment variables available in this context');
        }
        ksort($env);
        // Pick the first scalar entry and assert its exact rendered row.
        $picked = null;
        foreach ($env as $k => $v) {
            if (is_scalar($v)) {
                $picked = [(string) $k, (string) $v];
                break;
            }
        }
        if ($picked === null) {
            $this->markTestSkipped('No scalar environment variable available');
        }
        [$key, $value] = $picked;
        $html = PhpInfo::render(INFO_VARIABLES);
        // stringify() returns scalars verbatim; section() wraps each row.
        $this->assertStringContainsString(self::row($key, $value), $html);
    }

    public function testEnvironmentRowsAreKsorted(): void
    {
        $env = $_ENV;
        if ($env === []) {
            $env = getenv();
        }
        if (!is_array($env) || count($env) < 2) {
            $this->markTestSkipped('Need >= 2 environment variables to assert sort order');
        }
        ksort($env);
        $expectedKeys = array_map('strval', array_keys($env));

        $html = PhpInfo::render(INFO_VARIABLES);
        $start = strpos($html, '<h2>Environment</h2><table>');
        $this->assertNotFalse($start);
        $segment = substr($html, $start);
        $segEnd = strpos($segment, '</table>');
        $this->assertNotFalse($segEnd);
        $segment = substr($segment, 0, $segEnd);

        preg_match_all('#<tr><th>([^<]*)</th><td>#', $segment, $m);
        $renderedKeys = array_map(
            static fn (string $e): string => htmlspecialchars_decode($e, ENT_QUOTES),
            $m[1]
        );
        // Rendered key order must equal the ksort()ed key order (kills ksort removal).
        $this->assertSame($expectedKeys, $renderedKeys);
    }

    // ---------------------------------------------------------------------
    // renderToc() / anchor() / slug() (l.87-112) + document() nav (l.68-78)
    // ---------------------------------------------------------------------

    private static function slug(string $title): string
    {
        $slug = strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        return 'zi-' . trim($slug, '-');
    }

    public function testTocNavExactPillMarkup(): void
    {
        $html = PhpInfo::render(INFO_VARIABLES);
        // Exact pill markup for two known titles. Kills every Concat/operand
        // removal in: '<a class="zi-tab" href="#' . slug . '">' . title . '</a>'.
        $this->assertStringContainsString(
            '<a class="zi-tab" href="#zi-zealphp-runtime">ZealPHP Runtime</a>',
            $html
        );
        $this->assertStringContainsString(
            '<a class="zi-tab" href="#zi-php-core-system">PHP Core / System</a>',
            $html
        );
        $this->assertStringContainsString(
            '<a class="zi-tab" href="#zi-environment">Environment</a>',
            $html
        );
    }

    public function testTocNavWrappedAndPositionedBeforeBody(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // <nav class="zi-toc"> ... </nav> wrapper present exactly once.
        $this->assertSame(1, substr_count($html, '<nav class="zi-toc">'));
        $this->assertStringContainsString('</nav>', $html);
        // The header + nav precede the section bodies.
        $navPos    = strpos($html, '<nav class="zi-toc">');
        $headerPos = strpos($html, '<div class="zi-header">');
        $runtimePos = strpos($html, '<h2>ZealPHP Runtime</h2>');
        $this->assertNotFalse($navPos);
        $this->assertNotFalse($headerPos);
        $this->assertNotFalse($runtimePos);
        $this->assertLessThan($navPos, $headerPos, 'header precedes nav');
        $this->assertLessThan($runtimePos, $navPos, 'nav precedes first section');
    }

    public function testTocOnlyListsRenderedSections(): void
    {
        // INFO_GENERAL: only 'ZealPHP Runtime' + 'PHP Core / System' are rendered,
        // so the TOC must NOT carry Configuration/Modules/Variables/Environment pills.
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString('href="#zi-zealphp-runtime">ZealPHP Runtime</a>', $html);
        $this->assertStringContainsString('href="#zi-php-core-system">PHP Core / System</a>', $html);
        $this->assertStringNotContainsString('>PHP Variables</a>', $html);
        $this->assertStringNotContainsString('>Environment</a>', $html);
        $this->assertStringNotContainsString('>Loaded Extensions</a>', $html);
    }

    public function testTocPillCountMatchesRenderedSections(): void
    {
        // Under INFO_VARIABLES the base set is: ZealPHP Runtime, PHP Core / System,
        // PHP Variables, Environment (4 fixed pills) — configuration/modules off.
        $html = PhpInfo::render(INFO_VARIABLES);
        $this->assertStringNotContainsString('Configuration (core directives)</a>', $html);
        $this->assertStringNotContainsString('Loaded Extensions</a>', $html);
        // Foreach over $toc emits one <a class="zi-tab"> per title; assert the four.
        foreach (['ZealPHP Runtime', 'PHP Core / System', 'PHP Variables', 'Environment'] as $title) {
            $this->assertStringContainsString(
                '<a class="zi-tab" href="#' . self::slug($title) . '">' . self::esc($title) . '</a>',
                $html
            );
        }
    }

    public function testAnchorTargetMatchesTocHref(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // anchor() id == slug == the href the TOC link points to.
        $this->assertStringContainsString('<a class="zi-anchor" id="zi-zealphp-runtime"></a>', $html);
        $this->assertStringContainsString('<a class="zi-anchor" id="zi-php-core-system"></a>', $html);
        // The anchor immediately precedes its <h2> (anchor() output + section header).
        $this->assertStringContainsString(
            '<a class="zi-anchor" id="zi-zealphp-runtime"></a><h2>ZealPHP Runtime</h2>',
            $html
        );
        $this->assertStringContainsString(
            '<a class="zi-anchor" id="zi-php-core-system"></a><h2>PHP Core / System</h2>',
            $html
        );
    }

    public function testSlugCollapsesNonAlnumAndTrims(): void
    {
        // The slug for 'PHP Core / System' collapses spaces+slash to single dashes
        // and trims — kills the preg_replace pattern/replacement mutants indirectly
        // via the exact emitted anchor id.
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString('id="zi-php-core-system"', $html);
        // No doubled dashes and no leading/trailing dash artifacts.
        $this->assertStringNotContainsString('id="zi-php-core--system"', $html);
        $this->assertStringNotContainsString('id="zi--php', $html);
    }

    // ---------------------------------------------------------------------
    // document() header markup (l.68-78)
    // ---------------------------------------------------------------------

    public function testDocumentHeaderLogoAndSubExact(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // Exact, FULLY-CLOSED header block: zi-header > zi-logo + zi-sub, both the
        // zi-sub </div> AND the zi-header </div> present, then the nav. This kills
        // the l.70 ConcatOperandRemoval mutants that drop one or both closing tags.
        $expected = '<div class="zi-header">'
            . '<div class="zi-logo">Zeal<span>PHP</span></div>'
            . '<div class="zi-sub">phpinfo() &middot; ' . self::esc(php_uname('n')) . '</div>'
            . '</div>'
            . '<nav class="zi-toc">';
        $this->assertStringContainsString($expected, $html);
    }

    public function testDocumentHeaderSubUsesNodeName(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        $node = php_uname('n');
        if ($node === '') {
            $this->markTestSkipped('php_uname("n") is empty in this environment');
        }
        // The node name must appear after the '&middot; ' separator (kills the
        // ConcatOperandRemoval that would drop e(php_uname('n'))).
        $this->assertStringContainsString('&middot; ' . self::esc($node) . '</div>', $html);
    }

    public function testRenderToStringWhenTocEmptyIsImpossibleButHeaderStillRenders(): void
    {
        // renderToc([]) returns '' only when no sections are rendered; render()
        // always emits >= 2 fixed sections so the nav is always present. This pins
        // the `$toc === [] ? '' : ...` early-return false-branch (kills IfNegation:
        // a negated guard would emit '' and drop the whole nav).
        $html = PhpInfo::render(0);
        $this->assertStringContainsString('<nav class="zi-toc">', $html);
        $this->assertStringContainsString('href="#zi-zealphp-runtime">ZealPHP Runtime</a>', $html);
    }

    // ---------------------------------------------------------------------
    // safe() (l.337-345) — guarded probe value passthrough vs '—' fallback
    // ---------------------------------------------------------------------

    public function testSafeReturnsProbeValueWhenNonEmpty(): void
    {
        // renderSystem()'s System row goes through safe(fn => php_uname()); a
        // non-empty probe value is returned verbatim (kills the `!== '' ? $v : '—'`
        // true-branch removal). php_uname() is always non-empty.
        $uname = php_uname();
        $this->assertNotSame('', $uname);
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(self::row('System', $uname), $html);
        // The em-dash fallback must NOT appear for the System row.
        $this->assertStringNotContainsString('<tr><th>System</th><td>—</td></tr>', $html);
    }

    public function testSafeFallbackDashAppearsForEmptyProbesOnly(): void
    {
        // If any safe() probe legitimately returns '' it degrades to '—'. We don't
        // force that here (no src mutation allowed); instead assert the non-empty
        // System probe is never the dash, pinning the ternary value-branch.
        $html = PhpInfo::render(INFO_GENERAL);
        $systemRow = self::row('System', php_uname());
        $pos = strpos($html, $systemRow);
        $this->assertNotFalse($pos, 'System row must render the real uname, not the — fallback');
    }

    // ---------------------------------------------------------------------
    // render() assembly — anchor() precedes each section's <h2> (l.40-64)
    // ---------------------------------------------------------------------

    /**
     * Each section is `anchor($title) . renderX()`. The anchor's id == slug($title)
     * and the section's <h2> is the title — so the anchor MUST appear immediately
     * before the matching <h2>. The Concat-swap mutant (renderX . anchor) and the
     * ConcatOperandRemoval (drop the anchor) both break this adjacency.
     */
    private function assertAnchorImmediatelyPrecedesHeader(string $html, string $title): void
    {
        $expected = '<a class="zi-anchor" id="' . self::esc(self::slug($title)) . '"></a><h2>' . self::esc($title) . '</h2>';
        $this->assertStringContainsString($expected, $html, "anchor for '$title' must sit just before its <h2>");
    }

    public function testRenderConfigurationAnchorPrecedesHeader(): void
    {
        // l.46 Concat-swap + ConcatOperandRemoval on the Configuration section.
        $html = PhpInfo::render(INFO_CONFIGURATION);
        $this->assertAnchorImmediatelyPrecedesHeader($html, 'Configuration (core directives)');
    }

    public function testRenderModulesAnchorPrecedesHeader(): void
    {
        // l.55 Concat-swap + ConcatOperandRemoval on the Loaded Extensions section.
        $html = PhpInfo::render(INFO_MODULES);
        $this->assertAnchorImmediatelyPrecedesHeader($html, 'Loaded Extensions');
    }

    public function testRenderVariablesAndEnvironmentAnchorsPrecedeHeaders(): void
    {
        // l.59 + l.60 Concat-swap / ConcatOperandRemoval. The PHP Variables anchor
        // sits before the first variable section's <h2> only when a bag is present;
        // pass a real bag so the section renders, then assert anchor adjacency.
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => ['x' => 'y']]);
        // PHP Variables: anchor id 'zi-php-variables' precedes the FIRST var section.
        $anchorPos = strpos($html, '<a class="zi-anchor" id="zi-php-variables"></a>');
        $varH2Pos  = strpos($html, '<h2>PHP Variables — $_GET</h2>');
        $this->assertNotFalse($anchorPos);
        $this->assertNotFalse($varH2Pos);
        $this->assertLessThan($varH2Pos, $anchorPos, 'PHP Variables anchor precedes its first section header');
        // Environment: anchor immediately before its <h2>.
        $this->assertAnchorImmediatelyPrecedesHeader($html, 'Environment');
    }

    public function testRuntimeAndSystemAnchorsAlwaysPrecedeHeaders(): void
    {
        // l.40-41 — the two always-rendered sections.
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertAnchorImmediatelyPrecedesHeader($html, 'ZealPHP Runtime');
        $this->assertAnchorImmediatelyPrecedesHeader($html, 'PHP Core / System');
    }

    public function testExtTocLoopAppendsExtensionPills(): void
    {
        // l.50 `foreach ($extToc as $t)` -> `foreach ([] as $t)` would drop every
        // ext: pill from the TOC while still rendering the ext: sections below. So
        // under INFO_CONFIGURATION at least one `ext:` pill MUST be in the nav.
        [$ext] = self::pickExtDirective();
        if ($ext === '') {
            $this->markTestSkipped('No extension exposes ini directives in this build');
        }
        $html = PhpInfo::render(INFO_CONFIGURATION);
        $navStart = strpos($html, '<nav class="zi-toc">');
        $navEnd   = strpos($html, '</nav>');
        $this->assertNotFalse($navStart);
        $this->assertNotFalse($navEnd);
        $nav = substr($html, $navStart, $navEnd - $navStart);
        // The exact ext pill must be inside the <nav> (kills the empty-foreach mutant).
        $this->assertStringContainsString(
            '<a class="zi-tab" href="#' . self::esc(self::slug('ext: ' . $ext)) . '">' . self::esc('ext: ' . $ext) . '</a>',
            $nav
        );
    }

    // ---------------------------------------------------------------------
    // document() — title BEFORE styles, nav wrapper ordering (l.75, l.97)
    // ---------------------------------------------------------------------

    public function testDocumentTitlePrecedesStyleBlock(): void
    {
        // l.75 Concat-swap would emit <style>...</style><title>; assert <title> is
        // BEFORE the <style> opening tag.
        $html = PhpInfo::render(INFO_GENERAL);
        $titlePos = strpos($html, '<title>PHP Info — ZealPHP</title>');
        $stylePos = strpos($html, '<style>');
        $this->assertNotFalse($titlePos);
        $this->assertNotFalse($stylePos);
        $this->assertLessThan($stylePos, $titlePos, 'title must precede the style block');
    }

    public function testNavWrapperOpensBeforeItsLinks(): void
    {
        // l.97 `'<nav class="zi-toc">' . $links . '</nav>'` — the swap mutants put
        // $links before the opening tag or '</nav>' before $links. Assert the
        // opening tag immediately precedes the first pill, and the closing tag
        // comes after the last pill.
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(
            '<nav class="zi-toc"><a class="zi-tab" href="#zi-zealphp-runtime">ZealPHP Runtime</a>',
            $html
        );
        // The last pill (PHP Core / System) is immediately followed by </nav>.
        $this->assertStringContainsString(
            '<a class="zi-tab" href="#zi-php-core-system">PHP Core / System</a></nav>',
            $html
        );
    }

    // ---------------------------------------------------------------------
    // renderGeneral() runtime rows — exact ternary/value branches (l.162-182)
    // ---------------------------------------------------------------------

    public function testRuntimeOverrideEngineExactValue(): void
    {
        // l.162 ternary swaps. The engine string is decided by the loaded ext set:
        //   zealphp -> 'ext-zealphp', else uopz -> 'uopz (legacy)', else 'none'.
        $expected = extension_loaded('zealphp')
            ? 'ext-zealphp'
            : (extension_loaded('uopz') ? 'uopz (legacy)' : 'none');
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(self::row('Override Engine', $expected), $html);
    }

    public function testRuntimeCoroutineSuperglobalsExactValue(): void
    {
        // l.163 LogicalAnd / Ternary. Value is 'available' iff the ext is loaded AND
        // the coroutine-superglobals function exists; else 'not available'.
        $expected = (extension_loaded('zealphp') && function_exists('zealphp_coroutine_superglobals'))
            ? 'available'
            : 'not available';
        $other = $expected === 'available' ? 'not available' : 'available';
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(self::row('Coroutine Superglobals', $expected), $html);
        $this->assertStringNotContainsString(self::row('Coroutine Superglobals', $other), $html);
    }

    public function testRuntimeExtVersionExactValue(): void
    {
        // l.170 ternary: 'n/a' when ext absent, else the concrete phpversion('zealphp').
        $expected = extension_loaded('zealphp') ? ((string) (phpversion('zealphp') ?: '?')) : 'n/a';
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(self::row('ext-zealphp Version', $expected), $html);
    }

    public function testRuntimeFirstRowIsZealphpVersion(): void
    {
        // l.172 ArrayItemRemoval would drop the 'ZealPHP Version' row entirely.
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString('<tr><th>ZealPHP Version</th><td>', $html);
        // It is the FIRST row of the ZealPHP Runtime table.
        $tableStart = strpos($html, '<h2>ZealPHP Runtime</h2><table>');
        $this->assertNotFalse($tableStart);
        $this->assertStringContainsString(
            '<h2>ZealPHP Runtime</h2><table><tr><th>ZealPHP Version</th><td>',
            $html
        );
    }

    public function testRuntimeZealphpVersionUsesComposerPrettyVersion(): void
    {
        // l.166 Coalesce swap: `getPrettyVersion(...) ?? 'dev'` -> `'dev' ?? ...`
        // would emit the literal 'dev' instead of the real pretty version.
        try {
            $pretty = \Composer\InstalledVersions::getPrettyVersion('zealphp/zealphp') ?? 'dev';
        } catch (\Throwable $e) {
            $pretty = 'unknown';
        }
        $html = PhpInfo::render(INFO_GENERAL);
        // The exact ZealPHP Version row carries the resolved pretty version.
        $this->assertStringContainsString(self::row('ZealPHP Version', $pretty), $html);
        // When the package IS installed (pretty != 'dev'), the bare 'dev' fallback
        // value must NOT appear as the version cell (kills the swapped coalesce).
        if ($pretty !== 'dev') {
            $this->assertStringNotContainsString('<tr><th>ZealPHP Version</th><td>dev</td></tr>', $html);
        }
    }

    public function testRuntimeSuperglobalsModeBranch(): void
    {
        // l.181 ternary swap. Read the live App flag (other tests in the same process
        // may toggle it) so we assert the branch actually taken.
        $on = \ZealPHP\App::$superglobals;
        $expected = $on ? 'ON' : 'OFF (coroutine)';
        $other    = $on ? 'OFF (coroutine)' : 'ON';
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(self::row('Superglobals Mode', $expected), $html);
        $this->assertStringNotContainsString(self::row('Superglobals Mode', $other), $html);
    }

    public function testRuntimeProcessIsolationBranch(): void
    {
        // l.182 ternary swap. $process_isolation is null/false by default -> 'OFF'.
        $on = (bool) \ZealPHP\App::$process_isolation;
        $expected = $on ? 'ON' : 'OFF';
        $other    = $on ? 'OFF' : 'ON';
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(self::row('Process Isolation', $expected), $html);
        $this->assertStringNotContainsString(self::row('Process Isolation', $other), $html);
    }

    // ---------------------------------------------------------------------
    // renderSystem() ini-file probes — exact rows (l.203-216)
    // ---------------------------------------------------------------------

    public function testSystemLoadedConfigFileRow(): void
    {
        // l.204-207 safe()-wrapped probe: is_string($f) ? $f : '(none)'.
        $f = php_ini_loaded_file();
        $expectedProbe = is_string($f) ? $f : '(none)';
        // safe() then maps '' -> '—'; php_ini_loaded_file() is non-empty here.
        $expected = $expectedProbe !== '' ? $expectedProbe : '—';
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(self::row('Loaded Configuration File', $expected), $html);
    }

    public function testSystemScanDirRow(): void
    {
        // l.208 ternary: SCAN_DIR !== '' ? SCAN_DIR : '(none)'.
        $expected = PHP_CONFIG_FILE_SCAN_DIR !== '' ? PHP_CONFIG_FILE_SCAN_DIR : '(none)';
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(self::row('Scan this dir for additional .ini files', $expected), $html);
    }

    public function testSystemAdditionalIniFilesRow(): void
    {
        // l.209-217: parse php_ini_scanned_files() the same way the source does,
        // pinning the trim/explode/array_filter/implode pipeline and the
        // `$parts === [] ? '(none)' : implode(', ', $parts)` branch (l.216).
        $f = php_ini_scanned_files();
        if (!is_string($f) || trim($f) === '') {
            $expectedProbe = '(none)';
        } else {
            $parts = array_filter(array_map('trim', explode(',', $f)), static fn (string $p): bool => $p !== '');
            $expectedProbe = $parts === [] ? '(none)' : implode(', ', $parts);
        }
        $expected = $expectedProbe !== '' ? $expectedProbe : '—';
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(self::row('Additional .ini files parsed', $expected), $html);
    }

    public function testSystemAdditionalIniFilesJoinedWithCommaSpace(): void
    {
        // Strengthen the join: if there are >= 2 scanned files, the rendered value
        // must contain ', ' separators (kills UnwrapArrayFilter/UnwrapArrayMap/
        // NotIdentical that would change the joined output) and must NOT contain a
        // bare ",\n" native form.
        $f = php_ini_scanned_files();
        if (!is_string($f)) {
            $this->markTestSkipped('php_ini_scanned_files() is not a string in this build');
        }
        $parts = array_filter(array_map('trim', explode(',', $f)), static fn (string $p): bool => $p !== '');
        if (count($parts) < 2) {
            $this->markTestSkipped('Fewer than 2 additional .ini files parsed');
        }
        $joined = implode(', ', $parts);
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString(self::row('Additional .ini files parsed', $joined), $html);
        // Each part appears trimmed (no leading/trailing whitespace inside the cell).
        $first = array_values($parts)[0];
        $this->assertStringContainsString(self::esc($first) . ', ', $html);
    }

    // ---------------------------------------------------------------------
    // renderExtensionConfiguration internals — sort, accumulate, header order
    // ---------------------------------------------------------------------

    public function testExtensionSectionsAreSortedAlphabetically(): void
    {
        // l.260 sort($exts) removal -> sections appear in load order, not sorted.
        $html = PhpInfo::render(INFO_CONFIGURATION);
        preg_match_all('#<h2>ext: ([^<]+)</h2>#', $html, $m);
        $rendered = $m[1];
        $this->assertGreaterThan(1, count($rendered), 'need >= 2 ext sections to assert sort');
        $sorted = $rendered;
        sort($sorted);
        // Rendered ext-section order must equal the sorted order.
        $this->assertSame($sorted, $rendered);
    }

    public function testExtensionConfigRowsAllPreservedNotOverwritten(): void
    {
        // l.280 `$rowsHtml .=` -> `$rowsHtml =` would keep only the LAST directive
        // row per extension. Find an ext with >= 2 directive rows and assert both
        // its first and last directive rows are present.
        $exts = get_loaded_extensions();
        sort($exts);
        $target = null;
        foreach ($exts as $ext) {
            $all = @ini_get_all($ext, true);
            if (!is_array($all)) {
                continue;
            }
            $dirs = [];
            foreach ($all as $directive => $info) {
                if (is_array($info)) {
                    $dirs[] = (string) $directive;
                }
            }
            if (count($dirs) >= 2) {
                $target = [$ext, $dirs[0], $dirs[count($dirs) - 1]];
                break;
            }
        }
        if ($target === null) {
            $this->markTestSkipped('No extension with >= 2 ini directives available');
        }
        [$ext, $first, $last] = $target;
        $html = PhpInfo::render(INFO_CONFIGURATION);
        // Isolate THIS extension's section (its <table class="zi-extcfg"> ... </table>)
        // so the rows we assert can only come from this section. The `$rowsHtml =`
        // (assignment, not append) mutant keeps ONLY the last directive row, so the
        // first directive's <th> row would vanish from this isolated segment.
        $secStart = strpos($html, '<h2>' . self::esc('ext: ' . $ext) . '</h2>');
        $this->assertNotFalse($secStart);
        $tableStart = strpos($html, '<table class="zi-extcfg">', $secStart);
        $this->assertNotFalse($tableStart);
        $tableEnd = strpos($html, '</table>', $tableStart);
        $this->assertNotFalse($tableEnd);
        $section = substr($html, $tableStart, $tableEnd - $tableStart);
        $this->assertStringContainsString('<tr><th>' . self::esc($first) . '</th><td>', $section);
        $this->assertStringContainsString('<tr><th>' . self::esc($last) . '</th><td>', $section);
    }

    public function testExtensionConfigHeaderRowPrecedesDataRows(): void
    {
        // l.290 Concat-swap would emit '<h2>' . rows . '<table...head...>' — i.e.
        // the directive rows BEFORE the 3-col header. Assert the header row precedes
        // its first data row within a single ext section.
        [$ext, $directive] = self::pickExtDirective();
        if ($ext === '' || $directive === '') {
            $this->markTestSkipped('No extension directive available in this build');
        }
        $html = PhpInfo::render(INFO_CONFIGURATION);
        $secStart = strpos($html, '<h2>' . self::esc('ext: ' . $ext) . '</h2>');
        $this->assertNotFalse($secStart);
        $headPos = strpos($html, '<table class="zi-extcfg"><tr><th>Directive</th>', $secStart);
        $rowPos  = strpos($html, '<tr><th>' . self::esc($directive) . '</th><td>', $secStart);
        $this->assertNotFalse($headPos);
        $this->assertNotFalse($rowPos);
        $this->assertLessThan($rowPos, $headPos, 'the 3-col header precedes the directive rows');
    }

    // ---------------------------------------------------------------------
    // renderConfiguration() final </table> + iniField cast (l.243, l.423)
    // ---------------------------------------------------------------------

    public function testCoreConfigurationTableClosesBeforeExtSections(): void
    {
        // l.243 `return $html . '</table>'`: the ConcatOperandRemoval (return $html,
        // no '</table>') would leave the core-config table unclosed, so the next
        // markup appended by render() (the first `ext:` section) would butt directly
        // against the last core directive row with NO intervening </table>.
        // Render under INFO_CONFIGURATION (core config THEN ext config).
        $html = PhpInfo::render(INFO_CONFIGURATION);

        $coreHeadPos = strpos($html, '<h2>Configuration (core directives)</h2>');
        $this->assertNotFalse($coreHeadPos);
        // The first ext section starts the next block; the core table must close
        // (</table>) somewhere between the core header and that ext anchor.
        $firstExtPos = strpos($html, '<a class="zi-anchor" id="zi-ext-', $coreHeadPos);
        $this->assertNotFalse($firstExtPos, 'expected at least one ext: section after core config');
        $between = substr($html, $coreHeadPos, $firstExtPos - $coreHeadPos);
        // Exactly one </table> closes the core-config table in that window, and it
        // is the LAST thing before the ext anchor.
        $this->assertStringContainsString('</table>', $between);
        $this->assertStringEndsWith('</table>', $between, 'core config table must close right before the first ext section');
    }

    public function testCoreConfigurationTableClosesAfterRowsWhenConfigOnly(): void
    {
        // Same </table> contract, asserted on the core table's own boundary: the
        // closing tag follows a real directive row.
        $html = PhpInfo::render(INFO_CONFIGURATION);
        $all  = ini_get_all(null, true);
        $this->assertIsArray($all);
        $this->assertArrayHasKey('allow_url_fopen', $all);
        $entry = $all['allow_url_fopen'];
        $this->assertIsArray($entry);
        $lv = $entry['local_value'] ?? '';
        $mv = $entry['global_value'] ?? '';
        $local  = is_scalar($lv) ? (string) $lv : '';
        $master = is_scalar($mv) ? (string) $mv : '';
        $row = '<tr><th>allow_url_fopen</th><td>' . self::esc($local . ' / ' . $master) . '</td></tr>';
        $rowPos = strpos($html, $row);
        $this->assertNotFalse($rowPos);
        $closePos = strpos($html, '</table>', $rowPos);
        $this->assertNotFalse($closePos);
        $this->assertGreaterThan($rowPos, $closePos);
    }

    public function testVariableRowNumericKeyIsCastToString(): void
    {
        // l.378 (string) $k removal on the variable key. A numeric array key would,
        // without the cast, still interpolate as a string inside the double-quoted
        // expression — but PHPStan/coverage flags it; assert the exact rendered key.
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => [3 => 'three']]);
        $this->assertStringContainsString(
            '<tr><th>' . self::esc("\$_GET['3']") . '</th><td>three</td></tr>',
            $html
        );
    }

    public function testIniFieldNonStringScalarCastToString(): void
    {
        // l.423 (string) $v cast in iniField(). Some ini directives expose numeric
        // local/master values; the cast renders them as their string form. Find one
        // whose value is numeric-looking and assert the rendered string equals the
        // PHP (string) cast.
        $all = ini_get_all(null, true);
        $this->assertIsArray($all);
        $picked = null;
        foreach ($all as $name => $info) {
            if (!is_array($info)) {
                continue;
            }
            $lv = $info['local_value'] ?? '';
            if (is_scalar($lv) && $lv !== '' && is_numeric((string) $lv)) {
                $picked = [(string) $name, (string) $lv];
                break;
            }
        }
        if ($picked === null) {
            $this->markTestSkipped('No numeric-valued ini directive available');
        }
        [$name, $local] = $picked;
        $mv = $all[$name]['global_value'] ?? '';
        $master = is_scalar($mv) ? (string) $mv : '';
        $html = PhpInfo::render(INFO_CONFIGURATION);
        $this->assertStringContainsString(
            '<tr><th>' . self::esc($name) . '</th><td>' . self::esc($local . ' / ' . $master) . '</td></tr>',
            $html
        );
    }
}
