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
    case Proc = 'proc';   // proc_open subprocess per request — default; recursion-safe
    case Fork = 'fork';   // pcntl_fork the worker; child execs cgi_worker — cheaper than proc
    case Fcgi = 'fcgi';   // FastCGI to an upstream pool (php-fpm, hhvm, roadrunner)

    public static function coerce(self|string $mode): self
    {
        if ($mode instanceof self) { return $mode; }
        $enum = self::tryFrom(strtolower($mode));
        if ($enum === null) {
            throw new \InvalidArgumentException("Unknown CgiMode: '$mode' (use 'proc', 'fork', or 'fcgi')");
        }
        return $enum;
    }
}
