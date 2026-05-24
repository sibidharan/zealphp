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
    case Proc = 'proc';   // proc_open subprocess per request — recursion-safe; ~30-50ms cold start per request
    case Fork = 'fork';   // OpenSwoole\Process fork of warm worker — ~5ms; function-scope (bare `global $X` won't see top-level vars)
    case Fcgi = 'fcgi';   // FastCGI to an upstream pool (php-fpm, hhvm, roadrunner)
    case Pool = 'pool';   // Native FCGI-style worker pool — pre-spawned PHP subprocesses, mod_php-style isolation, ~1-3ms warm

    public static function coerce(self|string $mode): self
    {
        if ($mode instanceof self) { return $mode; }
        $enum = self::tryFrom(strtolower($mode));
        if ($enum === null) {
            throw new \InvalidArgumentException("Unknown CgiMode: '$mode' (use 'proc', 'fork', 'fcgi', or 'pool')");
        }
        return $enum;
    }
}
