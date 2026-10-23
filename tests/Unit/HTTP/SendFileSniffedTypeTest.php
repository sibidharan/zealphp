<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use ZealPHP\HTTP\Response;
use ZealPHP\Tests\TestCase;

/**
 * sendFile() on a file with no mapped suffix (a typical stored upload) must not
 * let content sniffing serve it as HTML/SVG on the app's origin.
 */
class SendFileSniffedTypeTest extends TestCase
{
    /** @return array{type: string, encoding: ?string, languages: list<string>} */
    private function meta(string $body, string $name): array
    {
        $dir = sys_get_temp_dir() . '/zp_sniff_' . getmypid();
        @mkdir($dir);
        $path = $dir . '/' . $name;
        file_put_contents($path, $body);
        try {
            $m = new \ReflectionMethod(Response::class, 'resolveFileMetadata');
            /** @var array{type: string, encoding: ?string, languages: list<string>} */
            return $m->invoke(null, $path);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testSniffedHtmlIsServedAsPlainText(): void
    {
        $this->assertSame('text/plain', $this->meta("<!DOCTYPE html><html><body><script>alert(1)</script></body></html>\n", 'avatar')['type']);
    }

    public function testSniffedSvgIsServedAsPlainText(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>' . "\n";
        $this->assertSame('text/plain', $this->meta($svg, 'upload')['type']);
    }

    public function testSniffedImageKeepsItsType(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $this->assertSame('image/png', $this->meta((string) $png, 'pic')['type']);
    }

    public function testMappedSuffixIsUntouched(): void
    {
        $this->assertSame('text/html', $this->meta('<p>hi</p>', 'page.html')['type']);
    }
}
