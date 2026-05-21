<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\HTTP\MimeResolver;

/**
 * Pure-logic tests for the multi-suffix MIME metadata resolver
 * (Apache mod_mime `find_ct` parity). No server required.
 */
class MimeResolverTest extends TestCase
{
    private function typeResolver(): MimeResolver
    {
        return new MimeResolver([
            'html' => 'text/html',
            'css'  => 'text/css',
            'tar'  => 'application/x-tar',
            'png'  => 'image/png',
        ]);
    }

    // ----- suffix decomposition (the dotfile + multi-suffix walk) -----

    public function testSuffixesMultiSuffixSplitsAll(): void
    {
        $r = new MimeResolver();
        $this->assertSame(['html', 'gz'], $r->suffixes('document.html.gz'));
    }

    public function testSuffixesTarGz(): void
    {
        $r = new MimeResolver();
        $this->assertSame(['tar', 'gz'], $r->suffixes('archive.tar.gz'));
    }

    public function testSuffixesAreLowercased(): void
    {
        $r = new MimeResolver();
        $this->assertSame(['tar', 'gz'], $r->suffixes('ARCHIVE.TAR.GZ'));
    }

    public function testSuffixesDotfileHasNoExtension(): void
    {
        // M12: ".png" is a hidden file named "png", NOT a PNG image.
        $r = new MimeResolver();
        $this->assertSame([], $r->suffixes('.png'));
        $this->assertSame([], $r->suffixes('.hidden'));
    }

    public function testSuffixesDotfileWithRealExtension(): void
    {
        // ".env.local" -> basename ".env", one suffix "local".
        $r = new MimeResolver();
        $this->assertSame(['local'], $r->suffixes('.env.local'));
    }

    public function testSuffixesNoExtension(): void
    {
        $r = new MimeResolver();
        $this->assertSame([], $r->suffixes('endpoint'));
        $this->assertSame([], $r->suffixes('README'));
    }

    public function testSuffixesEmptyExtensionsSkipped(): void
    {
        // Apache "bad..html" ignores the empty middle suffix.
        $r = new MimeResolver();
        $this->assertSame(['html'], $r->suffixes('bad..html'));
    }

    public function testSuffixesStripsDirectoryPath(): void
    {
        $r = new MimeResolver();
        $this->assertSame(['html', 'gz'], $r->suffixes('/var/www/document.html.gz'));
    }

    public function testSuffixesLeadingDotDirThenFile(): void
    {
        // Path basename is "page.html"; leading-dot dir doesn't bleed in.
        $r = new MimeResolver();
        $this->assertSame(['html'], $r->suffixes('/.config/page.html'));
    }

    // ----- type resolution -----

    public function testResolveSingleSuffixType(): void
    {
        $out = $this->typeResolver()->resolve('style.css');
        $this->assertSame('text/css', $out['type']);
        $this->assertNull($out['encoding']);
        $this->assertSame([], $out['languages']);
    }

    public function testResolveMultiSuffixTypeLastTypeWins(): void
    {
        // document.html.gz: html maps a type, gz has no type -> html stays.
        $out = $this->typeResolver()->resolve('document.html.gz');
        $this->assertSame('text/html', $out['type']);
    }

    public function testResolveTarGzTypeFromTar(): void
    {
        // archive.tar.gz: tar maps the type, gz has no type mapping here.
        $out = $this->typeResolver()->resolve('archive.tar.gz');
        $this->assertSame('application/x-tar', $out['type']);
    }

    public function testResolveDotfileGetsNoType(): void
    {
        // M12: even though 'png' is in the type map, ".png" is a dotfile.
        $out = $this->typeResolver()->resolve('.png');
        $this->assertNull($out['type']);
    }

    public function testResolveCaseInsensitiveType(): void
    {
        $out = $this->typeResolver()->resolve('PAGE.HTML');
        $this->assertSame('text/html', $out['type']);
    }

    public function testResolveUnknownSuffixNoType(): void
    {
        $out = $this->typeResolver()->resolve('file.unknown');
        $this->assertNull($out['type']);
    }

    public function testResolveLastTypeMappedSuffixOverwrites(): void
    {
        // both suffixes map a type -> the rightmost (css) wins.
        $out = $this->typeResolver()->resolve('weird.html.css');
        $this->assertSame('text/css', $out['type']);
    }

    // ----- encoding accumulation -----

    public function testResolveEncodingFromSuffix(): void
    {
        $r = new MimeResolver(['html' => 'text/html'], ['gz' => 'gzip']);
        $out = $r->resolve('document.html.gz');
        $this->assertSame('text/html', $out['type']);
        $this->assertSame('gzip', $out['encoding']);
    }

    public function testResolveEncodingChainOrderPreserved(): void
    {
        // Apache keeps double-encoding in order, comma-joined.
        $r = new MimeResolver([], ['gz' => 'gzip', 'br' => 'br']);
        $out = $r->resolve('data.br.gz');
        $this->assertSame('br, gzip', $out['encoding']);
    }

    public function testResolveDuplicateEncodingPreserved(): void
    {
        $r = new MimeResolver([], ['gz' => 'gzip']);
        $out = $r->resolve('data.gz.gz');
        $this->assertSame('gzip, gzip', $out['encoding']);
    }

    // ----- language accumulation -----

    public function testResolveLanguageFromSuffix(): void
    {
        $r = new MimeResolver(['html' => 'text/html'], [], ['fr' => 'fr']);
        $out = $r->resolve('page.fr.html');
        $this->assertSame('text/html', $out['type']);
        $this->assertSame(['fr'], $out['languages']);
    }

    public function testResolveLanguageChainOrderPreserved(): void
    {
        $r = new MimeResolver([], [], ['en' => 'en', 'fr' => 'fr']);
        $out = $r->resolve('page.en.fr.html');
        $this->assertSame(['en', 'fr'], $out['languages']);
    }

    public function testEmptyResolverIsNoOp(): void
    {
        $out = (new MimeResolver())->resolve('document.html.gz');
        $this->assertNull($out['type']);
        $this->assertNull($out['encoding']);
        $this->assertSame([], $out['languages']);
    }

    public function testConstructorNormalisesKeys(): void
    {
        // Leading-dot + uppercase + numeric keys all normalise.
        $r = new MimeResolver(['.HTML' => 'text/html', 123 => 'application/x-123']);
        $this->assertSame('text/html', $r->resolve('a.html')['type']);
        $this->assertSame('application/x-123', $r->resolve('a.123')['type']);
    }

    public function testConstructorStringifiesValues(): void
    {
        $r = new MimeResolver(['num' => 4242]);
        $this->assertSame('4242', $r->resolve('x.num')['type']);
    }
}
