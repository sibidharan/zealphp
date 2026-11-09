<?php
declare(strict_types=1);

namespace ZealPHP\CGI;

/**
 * What a CGI subprocess (pool, proc, fork) sends back when the script throws.
 *
 * The details go to the subprocess's stderr, which the server logs. The client
 * gets them only when the server runs with `App::displayErrors()` on (passed in
 * the request as `display_errors`), as the in-process 500 page does; otherwise
 * a plain 500. Exception messages and stack traces name files, queries and
 * sometimes credentials.
 */
final class SubprocessError
{
    public const GENERIC = '500 Internal Server Error';

    /** The response body for `$e`. */
    public static function body(\Throwable $e, bool $display): string
    {
        if (!$display) {
            return self::GENERIC;
        }
        return get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
    }

    /** Whether the request asked for error details (`display_errors` = true). */
    public static function displayRequested(mixed $request): bool
    {
        return is_array($request) && ($request['display_errors'] ?? false) === true;
    }

    /**
     * Write `$e` to the subprocess's stderr for the server log.
     *
     * @param resource|null $stream defaults to STDERR
     */
    public static function log(\Throwable $e, string $who, $stream = null): void
    {
        $line = '[' . $who . '] ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
        @fwrite($stream ?? \STDERR, $line);
    }
}
