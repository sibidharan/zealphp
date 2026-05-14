<?php
namespace ZealPHP\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\InvalidArgumentException;

class Logger extends AbstractLogger
{
    private const LEVEL_PRIORITY = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private const LEVEL_TO_KIND = [
        LogLevel::DEBUG => 'debug',
        LogLevel::INFO => 'info',
        LogLevel::NOTICE => 'info',
        LogLevel::WARNING => 'warning',
        LogLevel::ERROR => 'error',
        LogLevel::CRITICAL => 'error',
        LogLevel::ALERT => 'error',
        LogLevel::EMERGENCY => 'error',
    ];

    public function __construct(private string $minLevel = LogLevel::DEBUG) {}

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if (!isset(self::LEVEL_PRIORITY[$level])) {
            throw new InvalidArgumentException("Unknown log level: {$level}");
        }
        if (self::LEVEL_PRIORITY[$level] < self::LEVEL_PRIORITY[$this->minLevel]) {
            return;
        }

        $interpolated = $this->interpolate((string) $message, $context);
        $formatted = "[{$level}] {$interpolated}";
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $formatted .= "\n" . (string) $context['exception'];
        }
        $formatted .= "\n";

        $this->write($formatted, self::LEVEL_TO_KIND[$level]);
    }

    protected function write(string $formatted, string $kind): void
    {
        \ZealPHP\log_write($formatted, $kind);
    }

    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if ($key === 'exception') {
                continue;
            }
            if (is_string($val) || $val instanceof \Stringable) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }
        return strtr($message, $replace);
    }
}
