<?php
namespace ZealPHP;

/**
 * Thrown to cleanly halt page execution without killing the worker process.
 *
 * In traditional PHP (Apache mod_php), exit/die terminates the request process.
 * Under ZealPHP/OpenSwoole, exit/die would kill the entire worker. Code that
 * previously used exit (e.g. after a redirect header) should throw HaltException
 * instead. The framework catches it and treats it as a normal return — any output
 * buffered before the halt is still captured and sent.
 *
 * Extends `\Error` (a `\Throwable` that is NOT a `\Exception`), deliberately: the
 * common Apache-migration idiom `try { ... } catch (\Exception $e) { ... }` would
 * otherwise silently swallow the halt, letting execution fall through and
 * double-emit the response (issue #194). To intercept a halt, catch it explicitly
 * — `catch (\ZealPHP\HaltException $e)` or `catch (\Throwable $e)`. The framework's
 * halt-aware sites — `App::executeFile()` and `ZealAPI::runHandlerWithContract()`
 * — catch it specifically and treat it as a clean halt.
 *
 * As of ext-zealphp 0.3.48 (ext#47), a userland `exit()`/`die()` inside a
 * coroutine is intercepted by the extension and thrown AS this class (instead
 * of OpenSwoole's `ExitException`, which extends `\Exception` and was being
 * swallowed by exactly that legacy idiom — converting normal redirects into
 * 500s: FreshRSS, DokuWiki, CodeIgniter 4). The exit status rides `$status`:
 * a string for `exit("msg")` (mod_php echoes it — the framework appends it to
 * the body), an int for `exit(3)` (ints 100–599 become the HTTP status,
 * matching the established ExitException mapping), `null`/`0` for bare `exit;`.
 */
class HaltException extends \Error
{
    /** The exit()/die() argument: string message, int code, or null. */
    public mixed $status = null;

    /** Mirrors `OpenSwoole\ExitException::getStatus()` so the framework's
     *  exit-handling sites treat both uniformly. */
    public function getStatus(): mixed
    {
        return $this->status;
    }
}
