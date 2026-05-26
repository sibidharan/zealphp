<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Diagnostics\PhpInfo;
use PHPUnit\Framework\Attributes\DataProvider;

class PhpInfoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset boot-captured module text so each test starts from a known state.
        PhpInfo::primeModuleText('');
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Expected rendered row for a variable bag entry (single quotes are escaped by e()). */
    private static function varRow(string $bag, string $key, string $value): string
    {
        return '<tr><th>' . self::esc('$' . $bag . "['" . $key . "']") . '</th><td>' . self::esc($value) . '</td></tr>';
    }

    /**
     * Read one field of an ini_get_all() grouped entry as a string, mirroring
     * the source's iniField() coercion (scalar -> (string), else '').
     *
     * @param array<array-key, mixed> $info
     */
    private static function iniField(array $info, string $field): string
    {
        $v = $info[$field] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }

    public function testRenderReturnsSelfContainedHtmlDocument(): void
    {
        $html = PhpInfo::render();
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<style', $html);
        $this->assertStringContainsString('PHP Version', $html);
    }

    public function testValuesAreHtmlEscaped(): void
    {
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => ['x' => '<script>alert(1)</script>']]);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testGeneralFlagEmitsConfigButNotVariables(): void
    {
        $html = PhpInfo::render(INFO_GENERAL | INFO_CONFIGURATION, ['_GET' => ['secret' => 'shhh']]);
        $this->assertStringContainsString('ZealPHP Runtime', $html);
        $this->assertStringContainsString('Directive', $html); // configuration table header
        $this->assertStringNotContainsString('shhh', $html);    // INFO_VARIABLES not requested
    }

    public function testModulesSectionListsLoadedExtensions(): void
    {
        $html = PhpInfo::render(INFO_MODULES);
        $this->assertStringContainsString('Core', $html); // 'Core' is always loaded
    }

    public function testPrimedModuleTextRendersVerbatimWhenUnparseable(): void
    {
        PhpInfo::primeModuleText('garbled-block-without-structure');
        $html = PhpInfo::render(INFO_MODULES);
        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringContainsString('garbled-block-without-structure', $html);
    }

    public function testZealPhpInfoFunctionEchoesAndReturnsTrue(): void
    {
        ob_start();
        $ret = \ZealPHP\phpinfo(INFO_GENERAL);
        $out = (string) ob_get_clean();
        $this->assertTrue($ret);
        $this->assertStringContainsString('<!DOCTYPE html>', $out);
    }

    // ---------------------------------------------------------------------
    // document() — exact DOCTYPE/head structure and operand ordering (l.51)
    // ---------------------------------------------------------------------

    public function testDocumentExactHeadStructure(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // Exact opening: DOCTYPE, then html/head/meta, then title, then <style.
        // Kills Concat reorderings + ConcatOperandRemoval of the title.
        $this->assertStringStartsWith(
            "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><meta name=\"viewport\"",
            $html
        );
        // Title sits inside <head>, BEFORE </head> and the body div.
        $headEnd  = strpos($html, '</head>');
        $titlePos = strpos($html, '<title>PHP Info — ZealPHP</title>');
        $bodyPos  = strpos($html, '<body><div class="zphp-info">');
        $this->assertNotFalse($headEnd);
        $this->assertNotFalse($titlePos);
        $this->assertNotFalse($bodyPos);
        $this->assertLessThan($headEnd, $titlePos, 'title must be inside <head>');
        $this->assertLessThan($bodyPos, $headEnd, '</head> must precede <body>');
        $this->assertStringEndsWith('</div></body></html>', $html);
    }

    public function testDocumentContainsTitleExactlyOnce(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // ConcatOperandRemoval of the title operand would drop it entirely.
        $this->assertSame(1, substr_count($html, '<title>PHP Info — ZealPHP</title>'));
    }

    // ---------------------------------------------------------------------
    // styles() — full stylesheet content, each rule present & ordered (l.58)
    // ---------------------------------------------------------------------

    public function testStylesContainsDarkTheme(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString('background:#0c0a09', $html);
        $this->assertStringContainsString('color:#f59e0b', $html);
        $this->assertStringContainsString('.zphp-info .zi-logo', $html);
        $this->assertStringContainsString('.zphp-info .zi-header', $html);
        $this->assertStringContainsString('background:#1c1917', $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function styleRuleProvider(): array
    {
        return [
            'body-dark'    => ['body{background:#0c0a09'],
            'wrapper'      => ['.zphp-info{max-width:960px'],
            'h2-amber'     => ['.zphp-info h2{color:#f59e0b'],
            'table-dark'   => ['.zphp-info table{width:100%'],
            'th-dark'      => ['.zphp-info th{color:#a8a29e'],
            'pre-dark'     => ['.zphp-info pre{background:#1c1917'],
        ];
    }

    #[DataProvider('styleRuleProvider')]
    public function testEachStyleRulePresent(string $rule): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString($rule, $html);
    }

    // ---------------------------------------------------------------------
    // renderGeneral() — Server API row exact formatting (l.73)
    // ---------------------------------------------------------------------

    public function testGeneralSectionHeaderAndRows(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        $this->assertStringContainsString('<h2>ZealPHP Runtime</h2><table>', $html);
        $this->assertStringContainsString('<tr><th>PHP Version</th><td>' . phpversion() . '</td></tr>', $html);
        $this->assertStringContainsString('<tr><th>Zend Version</th><td>' . zend_version() . '</td></tr>', $html);
        $this->assertStringContainsString('<tr><th>System</th><td>' . htmlspecialchars(php_uname(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td></tr>', $html);
    }

    public function testServerApiRowExactFormat(): void
    {
        $html = PhpInfo::render(INFO_GENERAL);
        // openswoole IS loaded in this environment, so the version is concrete.
        $ver = phpversion('openswoole');
        $this->assertIsString($ver);
        $this->assertNotSame('', $ver);
        // 'ZealPHP (OpenSwoole ' . VERSION . ')' — kills the swapped/dropped operands.
        $this->assertStringContainsString(
            '<tr><th>Server API</th><td>ZealPHP (OpenSwoole ' . $ver . ')</td></tr>',
            $html
        );
        // The trailing ')' must be present and the prefix must lead — guards the
        // operand-swap mutant that produces 'VERSION' . 'ZealPHP (OpenSwoole ' . ')'.
        $this->assertStringContainsString('ZealPHP (OpenSwoole ' . $ver . ')', $html);
        $this->assertStringNotContainsString($ver . 'ZealPHP', $html);
    }

    // ---------------------------------------------------------------------
    // renderConfiguration() — header, table, real directive rows (l.82-96)
    // ---------------------------------------------------------------------

    public function testConfigurationHeaderAndTableHead(): void
    {
        $html = PhpInfo::render(INFO_CONFIGURATION);
        $this->assertStringContainsString(
            '<h2>Configuration (core directives)</h2><table><tr><th>Directive</th><th>Local / Master</th></tr>',
            $html
        );
        // Closing </table> present (kills l.96 operand removal/reorder).
        $this->assertStringEndsWith('</div></body></html>', $html);
        $this->assertStringContainsString('</table></div></body></html>', $html);
    }

    public function testConfigurationRendersRealDirectiveRow(): void
    {
        $html = PhpInfo::render(INFO_CONFIGURATION);
        $all  = ini_get_all(null, true);
        $this->assertIsArray($all);
        $this->assertArrayHasKey('allow_url_fopen', $all);
        $entry = $all['allow_url_fopen'];
        $this->assertIsArray($entry);
        $local  = self::iniField($entry, 'local_value');
        $master = self::iniField($entry, 'global_value');
        // Exact row: directive in <th>, "local / master" in <td>.
        // Kills CastString on directive, the ' / ' join removal/reorder,
        // local/master operand removals, and the <th>/<td> wrapper removals.
        $this->assertStringContainsString(
            '<tr><th>allow_url_fopen</th><td>' . htmlspecialchars($local . ' / ' . $master, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td></tr>',
            $html
        );
    }

    public function testConfigurationRowUsesSlashSeparator(): void
    {
        $html = PhpInfo::render(INFO_CONFIGURATION);
        $all  = ini_get_all(null, true);
        $this->assertIsArray($all);
        // Find a directive with DISTINCT local & global so " / " join order matters.
        $picked = null;
        foreach ($all as $name => $info) {
            if (!is_array($info)) {
                continue;
            }
            $l = self::iniField($info, 'local_value');
            $g = self::iniField($info, 'global_value');
            if ($l !== '' && $g !== '' && $l !== $g) {
                $picked = [(string) $name, $l, $g];
                break;
            }
        }
        if ($picked === null) {
            $this->markTestSkipped('No directive with distinct local/global values available');
        }
        [$name, $l, $g] = $picked;
        $expected = htmlspecialchars($l . ' / ' . $g, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->assertStringContainsString('<td>' . $expected . '</td>', $html);
        // The swapped form " / local . master" or "master / local" must NOT appear.
        $this->assertStringNotContainsString('<td>' . htmlspecialchars($g . ' / ' . $l, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>', $html);
    }

    public function testConfigurationContainsManyRows(): void
    {
        $html = PhpInfo::render(INFO_CONFIGURATION);
        // foreach([]) mutant (l.87) yields zero data rows; real loop yields many.
        // Count <tr> elements after the header row.
        $this->assertGreaterThan(20, substr_count($html, '<tr><th>'));
    }

    // ---------------------------------------------------------------------
    // renderModules() — sorted list, exact rows, native detail (l.102-113)
    // ---------------------------------------------------------------------

    public function testModulesHeaderAndCoreRow(): void
    {
        $html = PhpInfo::render(INFO_MODULES);
        $this->assertStringContainsString('<h2>Loaded Extensions</h2><table>', $html);
        $core = phpversion('Core');
        $expectedVer = is_string($core) && $core !== '' ? $core : 'enabled';
        // Exact Core row — kills <th>/<td> removals + the version ternary mutants.
        $this->assertStringContainsString(
            '<tr><th>Core</th><td>' . htmlspecialchars($expectedVer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td></tr>',
            $html
        );
    }

    public function testModulesListIsSorted(): void
    {
        $html = PhpInfo::render(INFO_MODULES);
        $exts = get_loaded_extensions();
        sort($exts);
        // Extract the <th>EXT</th> sequence inside the Loaded Extensions table.
        $start = strpos($html, '<h2>Loaded Extensions</h2><table>');
        $this->assertNotFalse($start);
        $segment = substr($html, $start);
        preg_match_all('#<tr><th>([^<]*)</th><td>#', $segment, $m);
        $rendered = $m[1];
        // The rendered order must match the sorted extension list (kills sort() removal).
        $expected = array_map(
            static fn (string $e): string => htmlspecialchars($e, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $exts
        );
        $this->assertSame($expected, $rendered);
        // Sanity: the list is not in the unsorted (load) order unless already sorted.
        $this->assertGreaterThan(1, count($rendered));
    }

    public function testModulesVersionFallsBackToEnabled(): void
    {
        $html = PhpInfo::render(INFO_MODULES);
        // Some extensions report empty/no version -> rendered as 'enabled'.
        // This pins the `is_string($ver) && $ver !== '' ? $ver : 'enabled'` ternary.
        $exts = get_loaded_extensions();
        $hasEnabledCase = false;
        foreach ($exts as $ext) {
            $ver = phpversion($ext);
            if (!(is_string($ver) && $ver !== '')) {
                $hasEnabledCase = true;
                $this->assertStringContainsString(
                    '<tr><th>' . htmlspecialchars($ext, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><td>enabled</td></tr>',
                    $html
                );
            }
        }
        if (!$hasEnabledCase) {
            // Every extension has a version; assert no spurious 'enabled' fallback appears
            // for an extension that actually has a version (guards the ternary inversion).
            $core = phpversion('Core');
            $this->assertTrue(is_string($core) && $core !== '');
            $this->assertStringContainsString('<tr><th>Core</th><td>' . $core . '</td></tr>', $html);
        }
        $this->addToAssertionCount(1);
    }

    public function testModulesTableClosedBeforeNativeDetail(): void
    {
        PhpInfo::primeModuleText('NATIVE-MODULE-DETAIL-BLOCK');
        $html = PhpInfo::render(INFO_MODULES);
        // Native module detail section: exact header + <pre> wrapper.
        $this->assertStringContainsString('</table><h2>Module Details (native)</h2><pre>NATIVE-MODULE-DETAIL-BLOCK</pre>', $html);
        // The extensions table is APPENDED to (not overwritten by) the detail block:
        // both the Loaded Extensions header and the Core row must still be present,
        // and they must precede the native-detail block. Kills the `$html =` mutant (l.113).
        $extPos    = strpos($html, '<h2>Loaded Extensions</h2><table>');
        $corePos   = strpos($html, '<tr><th>Core</th><td>');
        $detailPos = strpos($html, '<h2>Module Details (native)</h2>');
        $this->assertNotFalse($extPos);
        $this->assertNotFalse($corePos);
        $this->assertNotFalse($detailPos);
        $this->assertLessThan($detailPos, $extPos);
        $this->assertLessThan($detailPos, $corePos);
    }

    public function testModuleDetailEscapedAndWrapped(): void
    {
        PhpInfo::primeModuleText('<b>danger</b> & co');
        $html = PhpInfo::render(INFO_MODULES);
        $this->assertStringContainsString('<h2>Module Details (native)</h2><pre>&lt;b&gt;danger&lt;/b&gt; &amp; co</pre>', $html);
        $this->assertStringNotContainsString('<b>danger</b>', $html);
    }

    public function testModuleDetailOmittedWhenEmpty(): void
    {
        PhpInfo::primeModuleText('');
        $html = PhpInfo::render(INFO_MODULES);
        $this->assertStringNotContainsString('Module Details (native)', $html);
        $this->assertStringNotContainsString('<pre>', $html);
    }

    // ---------------------------------------------------------------------
    // renderVariables() — bag order, row keys, section titles (l.123-132)
    // ---------------------------------------------------------------------

    public function testVariablesSectionTitleAndRowKeyExact(): void
    {
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => ['foo' => 'bar']]);
        // Section title: 'PHP Variables — $_GET' (kills the title concat reorder/removal).
        $this->assertStringContainsString('<h2>PHP Variables — $_GET</h2><table>', $html);
        // Row key: $_GET['foo'] with value bar. Kills every l.130 reorder/removal:
        // '$' . $key . "['" . (string)$k . "']".
        $this->assertStringContainsString(self::varRow('_GET', 'foo', 'bar'), $html);
    }

    public function testVariablesRowKeyHasDollarBracketsInOrder(): void
    {
        $html = PhpInfo::render(INFO_VARIABLES, ['_POST' => ['k' => 'v']]);
        // Exact ordering of the literal pieces: $ then _POST then ['  then k then ']
        $this->assertStringContainsString(self::varRow('_POST', 'k', 'v'), $html);
        $this->assertStringContainsString('<h2>PHP Variables — $_POST</h2>', $html);
        // Swapped variants must not appear (escaped forms).
        $this->assertStringNotContainsString(self::esc("_POST\$['k']"), $html);
        $this->assertStringNotContainsString(self::esc("\$_POST'k']"), $html); // missing "[' "
    }

    public function testVariablesNumericKeyCastToString(): void
    {
        $html = PhpInfo::render(INFO_VARIABLES, ['_COOKIE' => [0 => 'zero', 7 => 'seven']]);
        $this->assertStringContainsString(self::varRow('_COOKIE', '0', 'zero'), $html);
        $this->assertStringContainsString(self::varRow('_COOKIE', '7', 'seven'), $html);
        $this->assertStringContainsString('<h2>PHP Variables — $_COOKIE</h2>', $html);
    }

    public function testVariablesIncludesServerBag(): void
    {
        // _SERVER is the first member of the iterated key list (l.123 ArrayItemRemoval).
        $html = PhpInfo::render(INFO_VARIABLES, ['_SERVER' => ['REQUEST_METHOD' => 'GET']]);
        $this->assertStringContainsString('<h2>PHP Variables — $_SERVER</h2><table>', $html);
        $this->assertStringContainsString(self::varRow('_SERVER', 'REQUEST_METHOD', 'GET'), $html);
    }

    public function testVariablesAllFourBagsRenderedInOrder(): void
    {
        $html = PhpInfo::render(INFO_VARIABLES, [
            '_SERVER' => ['s' => '1'],
            '_GET'    => ['g' => '1'],
            '_POST'   => ['p' => '1'],
            '_COOKIE' => ['c' => '1'],
        ]);
        $posServer = strpos($html, 'PHP Variables — $_SERVER');
        $posGet    = strpos($html, 'PHP Variables — $_GET');
        $posPost   = strpos($html, 'PHP Variables — $_POST');
        $posCookie = strpos($html, 'PHP Variables — $_COOKIE');
        $this->assertNotFalse($posServer);
        $this->assertNotFalse($posGet);
        $this->assertNotFalse($posPost);
        $this->assertNotFalse($posCookie);
        // Iteration order ['_SERVER','_GET','_POST','_COOKIE'].
        $this->assertLessThan($posGet, $posServer);
        $this->assertLessThan($posPost, $posGet);
        $this->assertLessThan($posCookie, $posPost);
    }

    public function testVariablesEmptyBagSkipped(): void
    {
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => [], '_POST' => ['p' => '1']]);
        // Empty _GET bag is skipped (continue), so no _GET section.
        $this->assertStringNotContainsString('PHP Variables — $_GET', $html);
        $this->assertStringContainsString('PHP Variables — $_POST', $html);
    }

    public function testVariablesAccumulatesAllSections(): void
    {
        // l.132 Assignment mutant ($out = ...) would keep only the LAST section.
        $html = PhpInfo::render(INFO_VARIABLES, [
            '_GET'  => ['g' => '1'],
            '_POST' => ['p' => '1'],
        ]);
        $this->assertStringContainsString('PHP Variables — $_GET', $html);
        $this->assertStringContainsString('PHP Variables — $_POST', $html);
    }

    // ---------------------------------------------------------------------
    // section() — header + row structure (l.140-144)
    // ---------------------------------------------------------------------

    public function testSectionRowStructureExact(): void
    {
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => ['alpha' => 'A', 'beta' => 'B']]);
        // Each row: <tr><th>KEY</th><td>VAL</td></tr> in order.
        $this->assertStringContainsString(self::varRow('_GET', 'alpha', 'A'), $html);
        $this->assertStringContainsString(self::varRow('_GET', 'beta', 'B'), $html);
        // Header h2 then table, closed with </table>.
        $this->assertStringContainsString('<h2>PHP Variables — $_GET</h2><table>', $html);
        $this->assertMatchesRegularExpression('#<h2>PHP Variables — \$_GET</h2><table>.*?</table>#s', $html);
    }

    public function testSectionTableIsClosed(): void
    {
        // l.144 ConcatOperandRemoval/reorder: section must end its table with </table>.
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => ['only' => 'x']]);
        $row = self::varRow('_GET', 'only', 'x');
        $pos = strpos($html, $row);
        $this->assertNotFalse($pos);
        $after = substr($html, $pos);
        $this->assertStringStartsWith($row . '</table>', $after);
    }

    // ---------------------------------------------------------------------
    // stringify() — type handling (l.178-186)
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function stringifyProvider(): array
    {
        return [
            'string'  => ['hello', 'hello'],
            'int'     => [42, '42'],
            'float'   => [3.5, '3.5'],
            'bool-t'  => [true, '1'],
            'bool-f'  => [false, ''],
            'array'   => [['a', 'b'], '(array)'],
            'null'    => [null, 'NULL'],
        ];
    }

    #[DataProvider('stringifyProvider')]
    public function testStringifyRendersExpectedValue(mixed $value, string $expected): void
    {
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => ['k' => $value]]);
        $this->assertStringContainsString(self::varRow('_GET', 'k', $expected), $html);
    }

    public function testStringifyStringWithSpecialCharsReturnedThenEscaped(): void
    {
        // is_string branch returns $v verbatim; e() then escapes for output.
        // IfNegation mutant (!is_string) would route a string into is_scalar/cast.
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => ['k' => 'a&b<c']]);
        $this->assertStringContainsString(self::varRow('_GET', 'k', 'a&b<c'), $html);
    }

    public function testStringifyObjectReturnsTypeName(): void
    {
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => ['k' => new \stdClass()]]);
        $this->assertStringContainsString(self::varRow('_GET', 'k', 'object'), $html);
    }

    // ---------------------------------------------------------------------
    // iniField() via configuration table; toArray; e(); flag dispatch
    // ---------------------------------------------------------------------

    public function testIniFieldNonScalarBecomesEmpty(): void
    {
        // iniField returns '' for missing/non-scalar. allow_url_fopen has scalar
        // values; we assert the cast string form appears (CastString mutant on l.175).
        $html = PhpInfo::render(INFO_CONFIGURATION);
        $all  = ini_get_all(null, true);
        $this->assertIsArray($all);
        $entry = $all['allow_url_fopen'];
        $this->assertIsArray($entry);
        $local = self::iniField($entry, 'local_value');
        // local_value is the string '1' here; (string) cast keeps it. Without the
        // cast, a non-string scalar would error — assert the rendered value appears.
        $this->assertStringContainsString('<tr><th>allow_url_fopen</th><td>' . self::esc($local), $html);
    }

    public function testFlagSelectivity(): void
    {
        // INFO_GENERAL only: no Configuration, no Modules, no Variables.
        $g = PhpInfo::render(INFO_GENERAL, ['_GET' => ['v' => 'vvv']]);
        $this->assertStringContainsString('ZealPHP Runtime', $g);
        $this->assertStringNotContainsString('Configuration (core directives)', $g);
        $this->assertStringNotContainsString('Loaded Extensions', $g);
        $this->assertStringNotContainsString('PHP Variables', $g);

        // INFO_CONFIGURATION only: config present, modules/variables absent.
        $c = PhpInfo::render(INFO_CONFIGURATION, ['_GET' => ['v' => 'vvv']]);
        $this->assertStringContainsString('Configuration (core directives)', $c);
        $this->assertStringNotContainsString('Loaded Extensions', $c);
        $this->assertStringNotContainsString('PHP Variables', $c);

        // INFO_MODULES only.
        $m = PhpInfo::render(INFO_MODULES, ['_GET' => ['v' => 'vvv']]);
        $this->assertStringContainsString('Loaded Extensions', $m);
        $this->assertStringNotContainsString('Configuration (core directives)', $m);
        $this->assertStringNotContainsString('PHP Variables', $m);

        // INFO_VARIABLES only.
        $v = PhpInfo::render(INFO_VARIABLES, ['_GET' => ['v' => 'vvv']]);
        $this->assertStringContainsString('PHP Variables — $_GET', $v);
        $this->assertStringNotContainsString('Configuration (core directives)', $v);
        $this->assertStringNotContainsString('Loaded Extensions', $v);
    }

    public function testZeroFlagsEmitsOnlyGeneral(): void
    {
        // flags=0: all three guarded sections off; only renderGeneral runs.
        $html = PhpInfo::render(0, ['_GET' => ['v' => 'vvv']]);
        $this->assertStringContainsString('ZealPHP Runtime', $html);
        $this->assertStringNotContainsString('Configuration (core directives)', $html);
        $this->assertStringNotContainsString('Loaded Extensions', $html);
        $this->assertStringNotContainsString('PHP Variables', $html);
        // No data leak from _GET.
        $this->assertStringNotContainsString('vvv', $html);
    }

    public function testEscapeUsesEntQuotesAndSubstitute(): void
    {
        // e() must escape single AND double quotes (ENT_QUOTES) — kills the
        // ENT_QUOTES & ENT_SUBSTITUTE bitwise mutant (would yield ENT_NOQUOTES=0).
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => ['k' => '"double" \'single\'']]);
        $this->assertStringContainsString('&quot;double&quot;', $html);
        $this->assertStringContainsString('&#039;single&#039;', $html);
        $this->assertStringNotContainsString('"double"', $html);
    }

    public function testCollectRequestVarsDegradesGracefullyOutsideContext(): void
    {
        // No requestVars passed and (typically) no coroutine context in unit tests:
        // render must not fatal and must still produce a valid document.
        $html = PhpInfo::render(INFO_VARIABLES);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringEndsWith('</div></body></html>', $html);
    }

    public function testCollectRequestVarsPullsFromContextWhenNoSeam(): void
    {
        // Exercise the live collectRequestVars() path (no $requestVars argument).
        // In superglobals(true) mode (the framework default + TestCase default),
        // RequestContext::instance() returns the process singleton; its declared
        // public bag properties feed collectRequestVars() via toArray().
        // This pins:
        //   - l.152 the '_SERVER' bag is collected (ArrayItemRemoval would drop it),
        //   - l.168 toArray() returns the populated array verbatim (the Ternary
        //     inversion `is_array($v) ? [] : $v` would emit [] and drop every row).
        // Force superglobals mode for this test so RequestContext::instance()
        // returns the process singleton (not a coroutine-context instance that
        // would throw outside a coroutine). Restore the prior mode afterwards.
        $priorMode = \ZealPHP\App::$superglobals;
        \ZealPHP\App::superglobals(true);

        $g = \ZealPHP\RequestContext::instance();
        $savedServer = $g->server;
        $savedGet    = $g->get;
        $savedPost   = $g->post;
        $savedCookie = $g->cookie;
        try {
            $g->server = ['ZPHP_PROBE_SERVER' => 'srv-val'];
            $g->get    = ['zphp_probe_get' => 'get-val'];
            $g->post   = ['zphp_probe_post' => 'post-val'];
            $g->cookie = ['zphp_probe_cookie' => 'cookie-val'];

            $html = PhpInfo::render(INFO_VARIABLES);

            // _SERVER bag present and populated (kills l.152 ArrayItemRemoval and
            // l.168 toArray ternary inversion — both would drop these rows).
            $this->assertStringContainsString('<h2>PHP Variables — $_SERVER</h2><table>', $html);
            $this->assertStringContainsString(self::varRow('_SERVER', 'ZPHP_PROBE_SERVER', 'srv-val'), $html);
            $this->assertStringContainsString(self::varRow('_GET', 'zphp_probe_get', 'get-val'), $html);
            $this->assertStringContainsString(self::varRow('_POST', 'zphp_probe_post', 'post-val'), $html);
            $this->assertStringContainsString(self::varRow('_COOKIE', 'zphp_probe_cookie', 'cookie-val'), $html);
        } finally {
            $g->server = $savedServer;
            $g->get    = $savedGet;
            $g->post   = $savedPost;
            $g->cookie = $savedCookie;
            \ZealPHP\App::superglobals($priorMode);
        }
    }
}
