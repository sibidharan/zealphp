<?php

declare(strict_types=1);

namespace ZealPHP;

/**
 * Type-safe enum for `App::cgiMode()`. The three strategies for
 * dispatching CGI requests (`.php` files in legacy-CGI mode, and
 * any registered non-`.php` extension).
 *
 *   App::cgiMode(CgiMode::Proc);   // ← type-checked
 *   App::cgiMode('proc');          // ← still works (BC)
 */
enum CgiMode: string
{
    case Pool = 'pool';   // Native FCGI-style worker pool — pre-spawned PHP subprocesses, mod_php-style isolation, ~1-3ms warm. DEFAULT.
    case Proc = 'proc';   // proc_open subprocess per request — recursion-safe; ~30-50ms cold start per request. Fallback for the rare case where you want fresh-process semantics WITHOUT a pre-spawned pool.
    case Fcgi = 'fcgi';   // FastCGI to an upstream pool (php-fpm, hhvm, roadrunner). Deployment-mode: front an existing FPM pool.

    public static function coerce(self|string $mode): self
    {
        if ($mode instanceof self) { return $mode; }
        $enum = self::tryFrom(strtolower($mode));
        if ($enum === null) {
            throw new \InvalidArgumentException("Unknown CgiMode: '$mode' (use 'pool', 'proc', or 'fcgi')");
        }
        return $enum;
    }
}
