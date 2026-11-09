<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The site follows the rules it documents (docs/directory-structure.md#where-code-goes):
 *
 * - route/ and api/ files are wiring: no markup. A handler returns data, a
 *   status, or App::render() / App::component(). (route/_*.php are
 *   integration-test fixtures that test the return contract with literal
 *   HTML, and are exempt.)
 * - Templates are views: no inline <script> blocks or style="" attributes.
 * - No top-level functions in route/, api/ or template/: helpers are classes
 *   in src/.
 *
 * AI assistants copy what a codebase does more than what its docs say, so the
 * demo app has to be the example.
 */
class ArchitectureRulesTest extends TestCase
{
    /** Opening HTML tags a handler has no business printing. */
    private const MARKUP = '#<(?:!doctype|html|head|body|div|span|p|section|article|aside|header|footer|nav|main|ul|ol|li|table|thead|tbody|tr|td|th|h[1-6]|button|form|input|label|select|textarea|a|img|pre|code|strong|em|small|br|hr)(?=[\s>/])#i';

    /** @return list<string> */
    private static function files(string $dir, string $glob = '*.php'): array
    {
        $root = dirname(__DIR__, 2) . '/' . $dir;
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && fnmatch($glob, $file->getFilename())) {
                $out[] = $dir . substr($file->getPathname(), strlen($root));
            }
        }
        sort($out);
        return $out;
    }

    /** @return iterable<string, array{string}> */
    public static function wiringFiles(): iterable
    {
        foreach ([...self::files('route'), ...self::files('api')] as $f) {
            if (!str_starts_with($f, 'route/_')) {
                yield $f => [$f];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        foreach (self::files('template') as $f) {
            yield $f => [$f];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function functionFreeFiles(): iterable
    {
        foreach ([...self::files('route'), ...self::files('api'), ...self::files('template')] as $f) {
            yield $f => [$f];
        }
    }

    /** A PHP file's code with its comments removed: markup in any string counts. */
    private static function codeWithoutComments(string $file): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    #[DataProvider('wiringFiles')]
    public function testRoutesAndApisHoldNoMarkup(string $file): void
    {
        $code = self::codeWithoutComments($file);
        preg_match_all(self::MARKUP, $code, $m);
        $this->assertSame([], array_values(array_unique($m[0])),
            "$file builds HTML. Put the markup in a component (template/<page>/components/ or template/components/) and return App::component()/App::render().");
    }

    #[DataProvider('templates')]
    public function testTemplatesHaveNoInlineScriptsOrStyles(string $file): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        // Displayed samples live inside nowdoc/heredoc strings; only the
        // template's own markup counts.
        $own = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && $t[0] === T_INLINE_HTML) {
                $own .= $t[1];
            }
        }
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)[^>]*>#i', $own, "$file has an inline <script>; move it to public/js/.");
        // Inside a real tag: an escaped sample (&lt;span style=…) is text.
        $this->assertDoesNotMatchRegularExpression('#<[a-z][^>]*\sstyle\s*=#i', $own, "$file has a style= attribute; use a class in public/css/.");
    }

    #[DataProvider('functionFreeFiles')]
    public function testNoTopLevelFunctionDeclarations(string $file): void
    {
        $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 2) . '/' . $file));
        $depth = 0;
        $found = [];
        foreach ($tokens as $i => $t) {
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
            } elseif ($depth === 0 && is_array($t) && $t[0] === T_FUNCTION) {
                // A named declaration, not a closure: `function name(`.
                for ($j = $i + 1; isset($tokens[$j]); $j++) {
                    $n = $tokens[$j];
                    if (is_array($n) && $n[0] === T_WHITESPACE) {
                        continue;
                    }
                    if (is_array($n) && $n[0] === T_STRING) {
                        $found[] = $n[1];
                    }
                    break;
                }
            }
        }
        $this->assertSame([], $found, "$file declares top-level functions; put them in a src/ class.");
    }
}
