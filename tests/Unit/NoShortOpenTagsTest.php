<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;

/**
 * With short_open_tag=Off (the production php.ini default, and PHP 8.4/8.5 CI)
 * a file that opens with `<?` is not code: include prints it, so a route or API
 * file served that way returns its own source to the client.
 */
class NoShortOpenTagsTest extends TestCase
{
    public function testShippedPhpFilesUseFullOpenTags(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['api', 'route', 'task', 'template', 'public', 'src', 'examples'] as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                if (preg_match('/<\?(?=\s)/', $code) === 1) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $offenders, 'use <?php, not <?');
    }
}
