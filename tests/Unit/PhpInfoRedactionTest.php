<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZealPHP\Diagnostics\PhpInfo;

/** What the public /phpinfo page must not print: connection-string credentials. */
class PhpInfoRedactionTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function names(): iterable
    {
        yield 'database url' => ['DATABASE_URL', true];
        yield 'mongo uri' => ['MONGODB_URI', true];
        yield 'webhook url' => ['SLACK_WEBHOOK_URL', true];
        yield 'lowercase' => ['redis_url', true];
        yield 'request uri' => ['REQUEST_URI', false];
        yield 'script uri' => ['SCRIPT_URI', false];
        yield 'script url' => ['SCRIPT_URL', false];
        yield 'redirect url' => ['REDIRECT_URL', false];
        yield 'not a word boundary' => ['CURLOPT', false];
        yield 'existing rule' => ['APP_SECRET', true];
    }

    #[DataProvider('names')]
    public function testUrlNamesAreSecretExceptTheRequestsOwn(string $name, bool $secret): void
    {
        $m = new \ReflectionMethod(PhpInfo::class, 'isSecretName');
        $this->assertSame($secret, $m->invoke(null, $name));
    }

    /** @return iterable<string, array{string, string}> */
    public static function values(): iterable
    {
        yield 'user and password' => ['postgres://app:s3cr3t@db:5432/x', 'postgres://app:********@db:5432/x'];
        yield 'password only' => ['redis://:pw@127.0.0.1:6379/0', 'redis://:********@127.0.0.1:6379/0'];
        yield 'token as user' => ['https://ghp_abc@github.com/o/r', 'https://********@github.com/o/r'];
        yield 'two in one value' => ['a=amqp://u:p@h b=redis://:q@r', 'a=amqp://u:********@h b=redis://:********@r'];
        yield 'at sign in the path' => ['https://example.com/a@b', 'https://example.com/a@b'];
        yield 'no url' => ['plain value', 'plain value'];
    }

    #[DataProvider('values')]
    public function testCredentialsInsideUrlsAreMasked(string $value, string $printed): void
    {
        $m = new \ReflectionMethod(PhpInfo::class, 'stringify');
        $this->assertSame($printed, $m->invoke(null, $value));
    }

    public function testTheRenderedPageHidesAConnectionString(): void
    {
        // PhpInfo reads $_ENV when it's populated, getenv() otherwise.
        $env = $_ENV;
        $_ENV['ZP_REDACT_PROBE'] = 'mysql://root:hunter2@db/app';
        putenv('ZP_REDACT_PROBE=mysql://root:hunter2@db/app');
        try {
            $html = PhpInfo::render(INFO_VARIABLES);
        } finally {
            putenv('ZP_REDACT_PROBE');
            $_ENV = $env;
        }
        $this->assertStringNotContainsString('hunter2', $html);
        $this->assertStringContainsString('mysql://root:********@db/app', $html);
    }
}
