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
 */
class HaltException extends \Error {}
