<?php

declare(strict_types=1);

namespace ZealPHP\Legacy;

/**
 * Per-request scratch tables for Apache mod_php shims (apache_setenv,
 * apache_getenv, apache_note). These exist solely so legacy code lifted
 * onto ZealPHP via the CGI bridge keeps working — modern coroutine
 * handlers do not need this class.
 *
 * Stored as a nullable property on G; lazy-instantiated by the shim
 * functions in src/utils.php on first write. Lifetime matches the
 * containing G instance (per-coroutine in coroutine mode, per-process
 * in superglobals mode).
 */
class ApacheContext
{
    /** @var array<string, string> */
    public array $env = [];
    /** @var array<string, string> */
    public array $notes = [];
}
