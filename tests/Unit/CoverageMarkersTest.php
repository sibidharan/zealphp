<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * php-code-coverage only recognises an exact `// @codeCoverageIgnoreStart`.
 * A marker with trailing text is ignored, and its parser then treats the next
 * `@codeCoverageIgnoreEnd` as the start of a region that runs to the following
 * End — which once hid ~2,900 lines of src/App.php from coverage.
 */
class CoverageMarkersTest extends TestCase
{
    public function testIgnoreMarkersInSrcAreBareAndBalanced(): void
    {
        $bad = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ZEALPHP_ROOT . '/src'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = (string) $file->getPathname();
            // Excluded from coverage in phpunit.xml (subprocess entry points).
            if (in_array(basename($path), ['pool_worker.php', 'cgi_worker.php', 'fork_master.php'], true)) {
                continue;
            }
            $depth = 0;
            foreach (token_get_all((string) file_get_contents($path)) as $t) {
                // Line markers only: a docblock `@codeCoverageIgnore` on a class
                // or method is a separate, valid mechanism.
                if (!is_array($t) || $t[0] !== T_COMMENT) {
                    continue;
                }
                $c = trim($t[1]);
                if (!str_starts_with($c, '//') || !str_contains($c, '@codeCoverageIgnore')) {
                    continue;
                }
                $where = str_replace(ZEALPHP_ROOT . '/', '', $path) . ':' . $t[2];
                if (preg_match('#^//\s?@codeCoverageIgnore(Start|End)?$#', $c, $m) !== 1) {
                    $bad[] = "{$where} marker must be alone on its line: {$c}";
                    continue;
                }
                $kind = $m[1] ?? '';
                if ($kind === 'Start') {
                    $depth++;
                } elseif ($kind === 'End') {
                    $depth--;
                    if ($depth < 0) {
                        $bad[] = "{$where} @codeCoverageIgnoreEnd without a Start";
                        $depth = 0;
                    }
                }
            }
            if ($depth !== 0) {
                $bad[] = str_replace(ZEALPHP_ROOT . '/', '', $path) . ' has an unclosed @codeCoverageIgnoreStart';
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }
}
