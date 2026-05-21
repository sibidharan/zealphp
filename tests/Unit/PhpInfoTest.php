<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Diagnostics\PhpInfo;

class PhpInfoTest extends TestCase
{
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
        $this->assertStringContainsString('PHP Core', $html);
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
}
