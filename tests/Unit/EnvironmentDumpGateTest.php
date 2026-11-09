<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A file that prints the process environment or the server variables must be
 * a gated test fixture. public/test_apps/server_info.php was public and
 * print_r($_SERVER)'d the live server's environment: API keys, the app key,
 * session tokens of whoever started it (2026-11-09).
 */
class EnvironmentDumpGateTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function servedFiles(): iterable
    {
        $root = dirname(__DIR__, 2);
        foreach (['public', 'api', 'route'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $rel = substr($file->getPathname(), strlen($root) + 1);
                    if (!str_starts_with($rel, 'public/docs/api/')) {
                        yield $rel => [$rel];
                    }
                }
            }
        }
    }

    #[DataProvider('servedFiles')]
    public function testNothingPublicDumpsTheEnvironment(string $file): void
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 2) . '/' . $file)) as $t) {
            if (!(is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))) {
                $code .= is_array($t) ? $t[1] : $t;
            }
        }
        $dumps = preg_match('/\b(print_r|var_dump|var_export|json_encode|print|echo)\s*\(?\s*(\$_SERVER\b|\$_ENV\b|getenv\s*\(\s*\)|\$g->server\b|\$GLOBALS\b)/', $code) === 1;
        if ($dumps) {
            $this->assertStringContainsString("env_flag('ZEALPHP_TEST_ROUTES', false)", $code,
                "$file prints the environment or server variables: gate it behind ZEALPHP_TEST_ROUTES or remove it.");
        } else {
            $this->addToAssertionCount(1);
        }
    }
}
