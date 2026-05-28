# Isolation test harness

Proves the **isolation trust-bar**: that request-style PHP state is isolated
per coroutine when running unmodified apps under OpenSwoole concurrency. See
`docs/architecture/2026-05-28-isolation-trust-bar.md` for the full matrix and
the migration claim it supports.

## Committed PHPUnit tests (the canonical, CI-safe versions)

```bash
# Request-state isolated per coroutine ($_GET/$_SESSION/global $x/bootstrap):
./vendor/bin/phpunit tests/Integration/CoroutineIsolationContractTest.php

# Full trust-bar matrix (every primitive: superglobals, response, statics,
# $GLOBALS, constants, ini, + the two process-level landmines):
./vendor/bin/phpunit tests/Integration/TrustBarIsolationTest.php
```

Both spawn a real `App::mode('coroutine-legacy')` OpenSwoole server, fire 40
concurrent interleaved coroutines (each yields mid-request), and assert zero
leakage of the contract set. They skip cleanly when OpenSwoole / the local ext
build is absent.

## Standalone cross-mode harness (ad-hoc, all modes)

```bash
# Build the ext if needed:
( cd ext/zealphp && phpize && ./configure && make -j )

# Run the matrix across coroutine-legacy / coroutine / mixed / legacy-cgi:
bash scripts/isolation/run-matrix.sh

# Or one mode by hand:
MODE=coroutine-legacy PORT=9821 \
  php -d extension=ext/zealphp/modules/zealphp.so scripts/isolation/probe-server.php &
php scripts/isolation/concurrent-driver.php 9821 40
```

Env: `ZEALPHP_SO` (ext path), `PHP_BIN` (php binary), `N` (concurrency, default 40).

## What "isolated" means here

Each of N concurrent requests sets a primitive to its own unique value, the
coroutine yields (forcing interleave), then re-reads. **Isolated** = every
request still sees its own value. Raw OpenSwoole (no ZealPHP) leaks ~39/40;
ZealPHP must leak 0 for the contract set. `fn_static` and `putenv` are
process-level and leak by design — they are reported, not asserted.
