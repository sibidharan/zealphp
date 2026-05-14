<?php
namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ZealPHP\Log\Logger;

class LoggerTest extends TestCase
{
    private function createLogger(string $minLevel = LogLevel::DEBUG): TestableLogger
    {
        return new TestableLogger($minLevel);
    }

    public function testImplementsLoggerInterface(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, new Logger());
    }

    public function testBasicLogging(): void
    {
        $logger = $this->createLogger();
        $logger->info('Hello world');
        $this->assertCount(1, $logger->written);
        $this->assertStringContainsString('[info] Hello world', $logger->written[0]['message']);
        $this->assertSame('info', $logger->written[0]['kind']);
    }

    public function testInterpolation(): void
    {
        $logger = $this->createLogger();
        $logger->info('User {name} logged in from {ip}', ['name' => 'Alice', 'ip' => '127.0.0.1']);
        $this->assertStringContainsString('User Alice logged in from 127.0.0.1', $logger->written[0]['message']);
    }

    public function testLevelFiltering(): void
    {
        $logger = $this->createLogger(LogLevel::WARNING);
        $logger->debug('should be skipped');
        $logger->info('should be skipped');
        $logger->warning('should appear');
        $logger->error('should appear');
        $this->assertCount(2, $logger->written);
    }

    public function testExceptionContext(): void
    {
        $logger = $this->createLogger();
        $exception = new \RuntimeException('Something broke');
        $logger->error('Failed', ['exception' => $exception]);
        $this->assertStringContainsString('Something broke', $logger->written[0]['message']);
    }

    public function testInvalidLevelThrows(): void
    {
        $logger = $this->createLogger();
        $this->expectException(\Psr\Log\InvalidArgumentException::class);
        $logger->log('invalid_level', 'test');
    }

    public function testAllLevelMethods(): void
    {
        $logger = $this->createLogger();
        $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
        foreach ($levels as $level) {
            $logger->$level("test {$level}");
        }
        $this->assertCount(8, $logger->written);
    }

    public function testLevelToKindMapping(): void
    {
        $logger = $this->createLogger();
        $logger->debug('d');
        $logger->info('i');
        $logger->error('e');
        $logger->emergency('em');
        $this->assertSame('debug', $logger->written[0]['kind']);
        $this->assertSame('info', $logger->written[1]['kind']);
        $this->assertSame('error', $logger->written[2]['kind']);
        $this->assertSame('error', $logger->written[3]['kind']);
    }

    public function testStringableMessage(): void
    {
        $logger = $this->createLogger();
        $msg = new class implements \Stringable {
            public function __toString(): string { return 'stringable message'; }
        };
        $logger->info($msg);
        $this->assertStringContainsString('stringable message', $logger->written[0]['message']);
    }
}

class TestableLogger extends Logger
{
    public array $written = [];

    protected function write(string $formatted, string $kind): void
    {
        $this->written[] = ['message' => $formatted, 'kind' => $kind];
    }
}
