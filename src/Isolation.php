<?php

declare(strict_types=1);

namespace ZealPHP;

/**
 * Type-safe enum for `App::isolation()` — the single knob that says HOW a
 * request is isolated. It folds the old (processIsolation × enableCoroutine ×
 * hookAll × cgiMode) cross-product into one intention-revealing value.
 *
 *   App::isolation(Isolation::Coroutine);   // ← type-checked
 *   App::isolation(App::ISOLATION_COROUTINE);// ← class constant (canonical)
 *   App::isolation('coroutine');             // ← bare string (BC)
 *
 * Pair with `App::superglobals(bool)` (the orthogonal axis: real $_GET/$_SESSION
 * vs per-coroutine $g). The two axes together describe every supported mode:
 *
 *   superglobals(true)  + Coroutine  → Mode 4 (ext-isolated superglobals, concurrent)
 *   superglobals(true)  + CgiPool    → Legacy CGI Pool (unmodified WordPress)  [default for sg=true]
 *   superglobals(true)  + None       → Mixed / sequential (Symfony, Laravel)
 *   superglobals(false) + Coroutine  → Pure coroutine ($g)  [default for sg=false]
 *
 * `isolation()` is pure sugar over the existing fluent setters — process vs
 * coroutine vs none are mutually-exclusive strategies (a CGI subprocess uses
 * blocking pipe I/O that cannot coexist with the coroutine scheduler), so a
 * single enum makes the impossible combo unrepresentable instead of relying on
 * boot-time forcing rules.
 */
enum Isolation: string
{
    /** In-process, per-coroutine isolation via ext-zealphp (concurrent). */
    case Coroutine = 'coroutine';

    /** Process isolation via a pre-spawned subprocess pool (mod_php-style, ~1-3ms warm). Default for superglobals(true). */
    case CgiPool = 'cgi-pool';

    /** Process isolation via a fresh proc_open subprocess per request (~30-50ms cold). */
    case CgiProc = 'cgi-proc';

    /** Process isolation by forwarding to an external FastCGI upstream (php-fpm/hhvm/roadrunner). */
    case CgiFcgi = 'cgi-fcgi';

    /** In-process, sequential, no per-request isolation — safe only because workers handle one request at a time. */
    case None = 'none';

    public static function coerce(self|string $mode): self
    {
        if ($mode instanceof self) {
            return $mode;
        }
        $enum = self::tryFrom(strtolower($mode));
        if ($enum === null) {
            throw new \InvalidArgumentException(
                "Unknown Isolation: '$mode' (use 'coroutine', 'cgi-pool', 'cgi-proc', 'cgi-fcgi', or 'none')"
            );
        }
        return $enum;
    }

    /** True for the three process-isolation (CGI subprocess) strategies. */
    public function isProcess(): bool
    {
        return $this === self::CgiPool || $this === self::CgiProc || $this === self::CgiFcgi;
    }

    /** The matching CgiMode for the process-isolation strategies; null otherwise. */
    public function cgiMode(): ?CgiMode
    {
        return match ($this) {
            self::CgiPool => CgiMode::Pool,
            self::CgiProc => CgiMode::Proc,
            self::CgiFcgi => CgiMode::Fcgi,
            default => null,
        };
    }
}
