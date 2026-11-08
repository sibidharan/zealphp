<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use ZealPHP\AppKey;

class AppKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEALPHP_APP_KEY');
        putenv('ZEALPHP_APP_KEY_PREVIOUS');
        AppKey::reset();
    }

    public function testGenerateProducesA32ByteBase64Key(): void
    {
        $key = AppKey::generate();
        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame(32, strlen((string) base64_decode(substr($key, 7), true)));
        $this->assertNotSame($key, AppKey::generate());
    }

    public function testReadsKeyAndPreviousKeysFromEnvironment(): void
    {
        putenv('ZEALPHP_APP_KEY=base64:' . base64_encode(hash('sha256', 'a', true)));
        putenv('ZEALPHP_APP_KEY_PREVIOUS=' . hash('sha256', 'b') . ' , base64:' . base64_encode(hash('sha384', 'c', true)));
        $this->assertSame(hash('sha256', 'a', true), AppKey::current());
        $this->assertSame([hash('sha256', 'b'), hash('sha384', 'c', true)], AppKey::previous());
        $this->assertCount(3, AppKey::deriveAll('x'));
    }

    public function testNoKeyMeansNullAndDeriveThrows(): void
    {
        $this->assertNull(AppKey::current());
        $this->assertSame([], AppKey::previous());
        $this->expectException(\RuntimeException::class);
        AppKey::derive('x');
    }

    public function testPlaceholderKeysAreRefused(): void
    {
        $this->expectExceptionMessage('placeholder');
        AppKey::set(str_repeat('changeme', 4));
    }

    public function testShortKeysAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppKey::set('short');
    }

    public function testInvalidBase64IsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppKey::set('base64:***');
    }

    public function testDerivedKeysDifferPerPurposeAndAreStable(): void
    {
        AppKey::set(hash('sha256', 'k', true));
        $a = AppKey::derive('one');
        $this->assertSame(32, strlen($a));
        $this->assertSame($a, AppKey::derive('one'));
        $this->assertNotSame($a, AppKey::derive('two'));
        $this->assertNotSame(hash('sha256', 'k', true), $a, 'never the raw key');
    }

    public function testRotationDerivesFromPreviousKeysAfterTheCurrentOne(): void
    {
        AppKey::set(hash('sha256', 'n', true), hash('sha256', 'o', true));
        $all = AppKey::deriveAll('p');
        $this->assertSame(AppKey::derive('p'), $all[0]);
        AppKey::set(hash('sha256', 'o', true));
        $this->assertSame(AppKey::derive('p'), $all[1]);
    }

    public function testEphemeralKeyIsRandomAndFlagged(): void
    {
        AppKey::useEphemeral();
        $this->assertTrue(AppKey::isEphemeral());
        $this->assertSame(32, strlen((string) AppKey::current()));
        AppKey::set(hash('sha256', 'k', true));
        $this->assertFalse(AppKey::isEphemeral());
    }
}
