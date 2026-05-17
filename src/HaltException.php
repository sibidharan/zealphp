<?php
namespace ZealPHP;

/**
 * Thrown to cleanly halt page execution without killing the worker process.
 *
 * In traditional PHP (Apache mod_php), exit/die terminates the request process.
 * Under ZealPHP/OpenSwoole, exit/die would kill the entire worker. Code that
 * previously used exit (e.g. after a redirect header) should throw HaltException
 * instead. App::executeFile() catches it and treats it as a normal return —
 * any output buffered before the halt is still captured and sent.
 */
class HaltException extends \Exception {}
