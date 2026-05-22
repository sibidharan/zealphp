# CGI True Parity + Coroutine-Safe `exec` — Design

**Date:** 2026-05-22
**Status:** Design approved — pending spec review
**Roadmap:** Feature #1 of "OpenSwoole, framework-ified" (ergonomic wrappers over OpenSwoole primitives). Bundles roadmap items #1 (`Coroutine\System` wrapper) and #2 (CGI true parity).

## Problem

Two related gaps, confirmed against the code and the cloned Apache source (`/tmp/httpd-audit`):

1. **CGI only runs in process-isolation mode.** `App::include()` dispatches to a registered CGI backend only when `$coproc_implicit_request_handler` is set, wired to `App::processIsolation()` (`src/App.php:4328`, gate at `:2677`). In the **default coroutine mode**, `include()` runs files in-process *as PHP* (`executeFile()`, `:2692`) — so `include('foo.py')` is broken there.
2. **No `AddHandler`-style URL parity.** The implicit public routes hardcode `.php` (`src/App.php:4524`, `:4552`), so `GET /foo.py` resolves to `public/foo.py.php` (404), never `public/foo.py` via a backend.
3. **Blocking process calls.** Legacy code using the backtick operator / `shell_exec()` / `exec()` / `system()` blocks the OpenSwoole worker in coroutine mode (HOOK_ALL does not coroutinize these).

The CGI *execution contract* is already faithfully Apache-`mod_cgi`-like — `buildCgiEnv()` (`src/App.php:2831`) mirrors `util_script.c` (RFC 3875 vars, httpoxy strip at `:2845`, timeout, stderr drain). The gap is **reach** (modes, URLs) and **ergonomics** (blocking exec).

## Goals

- `App::exec()` — coroutine-safe command execution wrapping `OpenSwoole\Coroutine\System::exec()`.
- **Default-on, transparent override** (coroutine mode) of the backtick operator + `shell_exec` / `exec` / `system` / `passthru` / `popen`, with an escape hatch. (Proven: a uopz override of `shell_exec` intercepts the backtick operator.)
- Register CGI backends by **extension** (AddHandler) and **path prefix** (ScriptAlias).
- Dispatch registered backends in **every** lifecycle mode, preserving the `.php` fast path.
- **ExecCGI** security: extension execution gated by an explicit executable-path scope.
- **URL parity:** `GET /cgi-bin/report.py` runs `public/cgi-bin/report.py` via its backend.
- Coroutine-safe subprocess execution (no worker stalls) — gated by a spike.

## Non-goals (YAGNI)

- No bundled language runtimes (bring your own interpreter).
- `fork` mode stays `.php`-only.
- No per-request interpreter pooling (that is the `fcgi` path / a future connection-pool feature).

## Design

### Section 0 — `App::exec()` + transparent exec override

- `App::exec(string $cmd, ?float $timeout = null): array` returning `{output, code, signal}` — wraps `Coroutine\System::exec()`. In a coroutine (`Coroutine::getCid() >= 0`) it yields; outside one it falls back to a blocking implementation so boot/CLI still work.
- `App::rawExec(string $cmd): ?string` — explicit blocking escape hatch, never coroutinized.
- `App::$hook_exec` (bool; default `true` in coroutine mode). When on, boot registers uopz overrides — alongside the existing `header()`/`session_*()` overrides — for `shell_exec` (catches the backtick operator too), `exec`, `system`, `passthru`, `popen`. Each routes through the coroutine-safe path while preserving that function's documented return shape; outside a coroutine each delegates to the original (saved) handler. When off: raw PHP behavior, no overrides.

### Section 1 — Registration API

    App::registerCgiBackend('.py', [
      'mode'        => 'proc',                // 'proc' | 'fcgi'
      'interpreter' => '/usr/bin/python3',    // proc mode; null => shebang
      'exec_paths'  => ['/cgi-bin'],          // ExecCGI scope (extension model)
    ]);
    App::cgiScriptAlias('/cgi-bin', ['mode' => 'proc']);   // everything under runs as CGI

Backed by the existing `$cgi_backends` (extension registry) + a new `$cgi_script_aliases` (path-prefix registry). A unified `resolveCgiBackend(string $absPath, string $urlPath): array{backend, mayExecute}` answers both "which backend" and "is execution permitted here".

### Section 2 — Dispatch flow (all modes)

Move the backend lookup out from behind the `processIsolation()` gate into the shared dispatch used by `include()`, `serveDirectory()`, and the implicit routes. Per request:

- `.php`, no backend: unchanged fast path (in-process `executeFile()` in coroutine mode; `cgi_worker` subprocess in isolation mode). Zero overhead for the common case.
- Registered extension within an `exec_paths` scope, OR under a `cgiScriptAlias`: dispatch to the backend.
- Registered extension outside any exec scope: not executed (served per existing static/403 rules).

### Section 3 — ExecCGI security

Execution requires the explicit path opt-in (`exec_paths` on the extension, or a `cgiScriptAlias`). A `.py` written into `public/uploads/` never executes unless `uploads/` was opted in — the classic CGI upload-RCE footgun Apache guards against with `Options +ExecCGI` (default off). Reuses the existing prefix-boundary check.

### Section 4 — Coroutine-safe spawn (SPIKE FIRST)

- General CGI (POST body on stdin + streaming stdout for SSE) stays `proc_open` with pipes. Under coroutine mode's default `HOOK_ALL`, `proc_open` + pipe I/O are coroutinized so they yield. Spike (task 1): prove POST body + SSE streaming + no worker stall under concurrent load before relying on it.
- `App::exec()` (`Coroutine\System::exec`) is the path for simple buffered, no-stdin CGI and the general exec wrapper. It is buffered and takes no stdin, so it is NOT the general-CGI path.
- `coroutine-without-HOOK_ALL` mode: require `fcgi` (always coroutine-safe) or fail loud. Never silently block.

### Section 5 — Implicit-route URL parity

Extend the implicit route matcher (currently hardcoded `.php`, `src/App.php:4514-4552`) so a URL whose extension is registered AND under an exec scope resolves to that file via its backend (`GET /cgi-bin/report.py`). `.php` and extensionless behavior unchanged.

## Implementation order

1. Spike: coroutine `proc_open` yields under HOOK_ALL with POST + SSE, no stall. Gates the approach; if it fails, the coroutine general-CGI path degrades to "require `fcgi` for streaming CGI in coroutine mode."
2. `App::exec()` + `App::rawExec()` + the uopz exec overrides (Section 0) + unit tests (incl. backtick interception).
3. `resolveCgiBackend()` unification + `exec_paths` + `cgiScriptAlias()` (Sections 1, 3) + unit tests.
4. Un-gate dispatch in `include()` / `serveDirectory()` (Section 2) + implicit-route extension matching (Section 5).
5. Integration tests (matrix below).
6. Docs.

## Testing

- Unit: `resolveCgiBackend()` extension+path+exec-scope matrix; `App::exec()` coroutine-vs-fallback; backtick/`shell_exec` override interception.
- Integration (the parity proof): a real `.py` and `.pl` served via URL and via `App::include()`, in coroutine AND isolation modes — GET, POST body, SSE/streaming, 404 outside exec scope, timeout, httpoxy strip.
- PHPStan level 10 clean; patch coverage at least 80% of new lines.

## Docs to update

`docs/legacy-apps` / `fastcgi-backends` / `runtime-architecture` guides + matching `template/pages/*`, and `.claude/CLAUDE.md` (the CGI section + adding `App::exec`/backtick to the uopz-overrides list).

## Risks / open questions

- Spike risk (highest): if `proc_open` under HOOK_ALL cannot cleanly stream/POST without stalling the worker, the zero-config coroutine story narrows to "use `fcgi` for streaming CGI in coroutine mode." Design degrades gracefully; the spike decides before we build the rest.
- uopz overriding `exec`/`system` globally affects vendor code — mitigated by the `App::rawExec()` escape hatch + `App::$hook_exec` opt-out, documented (consistent with existing header/session overrides).
- `Coroutine\System::exec` API shape/availability differs across OpenSwoole 22.1 vs 26.2 — verify in the spike.
