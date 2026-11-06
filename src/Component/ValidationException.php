<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * Thrown by {@see Component::validate()} when a field fails its rules. The
 * component endpoint catches it, keeps the errors on the component and
 * re-renders it with status 422.
 */
final class ValidationException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors field → message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed: ' . implode(', ', array_keys($errors)), 422);
    }
}
