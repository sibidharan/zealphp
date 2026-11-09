<?php

declare(strict_types=1);

namespace ZealPHP\Component;

/**
 * A component request the endpoint refuses. The code is the HTTP status:
 * 400 malformed or tampered input, 403 session mismatch or not authorized,
 * 404 unknown component or action, 409 snapshot from another build.
 */
final class ComponentException extends \RuntimeException
{
    /** @internal */
    public static function badRequest(string $message): self
    {
        return new self($message, 400);
    }

    /** @internal */
    public static function forbidden(string $message): self
    {
        return new self($message, 403);
    }

    /** @internal */
    public static function notFound(string $message): self
    {
        return new self($message, 404);
    }

    /** @internal */
    public static function stale(string $message): self
    {
        return new self($message, 409);
    }
}
