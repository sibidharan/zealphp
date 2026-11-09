<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Plain files at the root of the document root (robots.txt, llms.txt) are
 * served. The static handler only covers its directory prefixes (/css/, /js/
 * …), and the root file route only knew .php, so these used to 404.
 */
class RootFilesTest extends TestCase
{
    public function testRootPlainFilesAreServed(): void
    {
        foreach (['/robots.txt', '/llms.txt', '/llms-full.txt'] as $path) {
            $r = $this->get($path);
            $this->assertStatus(200, $r, $path);
            $this->assertStringStartsWith('text/plain', $r['headers']['content-type'] ?? '', $path);
        }
        $this->assertStringStartsWith('# ZealPHP', $this->get('/llms.txt')['body']);
    }

    public function testOnlyTheDocrootIsServed(): void
    {
        $this->assertStatus(404, $this->get('/nope.txt'));
        $this->assertNotSame(200, $this->get('/../app.php')['status']);
        $this->assertSame(403, $this->get('/.env')['status']);
    }
}
