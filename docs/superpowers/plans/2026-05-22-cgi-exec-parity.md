# CGI True Parity + Coroutine-Safe `exec` — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make CGI (Python/Perl/any) work in ALL lifecycle modes with Apache-like extension+path dispatch and an ExecCGI gate, plus a coroutine-safe `App::exec()` that transparently de-blocks backticks/`shell_exec`/`exec`/`system`.

**Architecture:** Un-gate the existing CGI backend dispatch from `processIsolation()` into the shared file-dispatch path so registered backends fire in coroutine mode too; add a path-prefix (ScriptAlias) registry + an exec-path (ExecCGI) gate beside the existing extension registry; wrap `OpenSwoole\Coroutine\System::exec` as `App::exec()` and uopz-override the blocking process builtins to route through it.

**Tech Stack:** PHP 8.3+, OpenSwoole 22.1/26.2, uopz, PHPUnit 11, PHPStan L10.

**Spec:** `docs/superpowers/specs/2026-05-22-cgi-exec-parity-design.md`

---

## File structure

- **`src/App.php`** — `exec()`, `rawExec()`, `$hook_exec`; uopz override registration (next to lines 593-600); `registerCgiBackend()` accepts `exec_paths` (modify ~989); new `cgiScriptAlias()` + `$cgi_script_aliases`; `resolveCgiBackend()` signature → `(string $absPath, string $urlPath = ''): array{backend, mayExecute}` (modify ~1032); dispatch un-gate in `include()` (~2661) + `serveDirectory()` (~2598); implicit-route extension matching (~4514-4552).
- **`src/utils.php`** — global `\ZealPHP\zeal_shell_exec()` / `zeal_exec()` / `zeal_system()` / `zeal_passthru()` / `zeal_popen()` shims (mirror the existing `\ZealPHP\header()` pattern) that route to `App::exec()`.
- **`tests/Unit/AppExecTest.php`** — `exec()`/`rawExec()` + backtick/`shell_exec` override interception.
- **`tests/Unit/CgiBackendResolveTest.php`** — `resolveCgiBackend()` ext/path/exec-scope matrix.
- **`tests/Integration/CgiParityTest.php`** — `.py`/`.pl` via URL + `include()`, coroutine + isolation.
- **`public/cgi-bin/hello.py`, `public/cgi-bin/echo.py`, `public/cgi-bin/hello.pl`** — integration fixtures.
- **`examples/spikes/cgi-coroutine-spike.php`** — throwaway spike (Task 1).
- Docs: `docs/{legacy-apps,fastcgi-backends,runtime-architecture}.md`, matching `template/pages/*`, `.claude/CLAUDE.md`.

---

### Task 1: SPIKE — is coroutine `proc_open` non-blocking with POST + streaming? (GATE)

This decides the spawn approach for §4. Not TDD; a go/no-go experiment. **Do this before anything else.**

**Files:** Create `examples/spikes/cgi-coroutine-spike.php`, `examples/spikes/cgi.py`

- [ ] **Step 1: Write the CGI test script**

`examples/spikes/cgi.py`:
```python
#!/usr/bin/env python3
import sys, time
body = sys.stdin.read()
print("Content-Type: text/plain\r\n\r")
for i in range(3):
    print(f"chunk {i} body={body!r}", flush=True)
    time.sleep(0.3)
```

- [ ] **Step 2: Write the spike**

`examples/spikes/cgi-coroutine-spike.php`:
```php
<?php
use OpenSwoole\Coroutine as Co;
use OpenSwoole\Runtime;
require __DIR__ . '/../../vendor/autoload.php';
Runtime::enableCoroutine(Runtime::HOOK_ALL);
Co\run(function () {
    $progressed = 0;
    Co::create(function () use (&$progressed) {        // concurrency probe
        for ($i = 0; $i < 20; $i++) { Co::sleep(0.05); $progressed++; }
    });
    $cmd = '/usr/bin/python3 ' . escapeshellarg(__DIR__ . '/cgi.py');
    $p = proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, __DIR__);
    fwrite($pipes[0], 'POSTDATA'); fclose($pipes[0]);
    $chunks = [];
    while (!feof($pipes[1])) { $line = fgets($pipes[1]); if ($line !== false && trim($line) !== '') $chunks[] = trim($line); }
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    echo "probe_progressed_during_subprocess=$progressed (expect >0 if non-blocking)\n";
    echo "got_post=" . (strpos(implode("\n",$chunks),"POSTDATA")!==false ? 'YES':'NO') . "\n";
    echo "chunk_count=" . count($chunks) . "\n";
});
```

- [ ] **Step 3: Run it**

Run: `php examples/spikes/cgi-coroutine-spike.php`
Expected (PASS): `probe_progressed_during_subprocess` > 0 (subprocess yielded), `got_post=YES`, `chunk_count=3`.

- [ ] **Step 4: Record the verdict in the spec**

If PASS: append to the spec "Spike PASSED <date> — coroutine `proc_open` yields + supports POST/stream; the existing `cgiSubprocess()` is the all-modes path." Proceed.
If FAIL (probe stayed 0 → blocked): append "Spike FAILED — coroutine general CGI requires `fcgi`; `proc`/`fork` are isolation-mode-only." Then in Task 5, gate `proc`/`fork` non-PHP dispatch to isolation mode and make coroutine mode require `fcgi` (fail loud otherwise). The rest of the plan is unchanged.

- [ ] **Step 5: Commit**

```bash
git add examples/spikes/ docs/superpowers/specs/2026-05-22-cgi-exec-parity-design.md
git commit -m "spike: verify coroutine proc_open is non-blocking with POST + streaming"
```

---

### Task 2: `App::exec()` + `App::rawExec()`

**Files:** Modify `src/App.php`; Test `tests/Unit/AppExecTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/AppExecTest.php`:
```php
<?php
declare(strict_types=1);
namespace ZealPHP\Tests\Unit;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use OpenSwoole\Coroutine as Co;

final class AppExecTest extends TestCase
{
    public function testRawExecRunsBlockingAndReturnsOutput(): void
    {
        $this->assertSame("hi\n", App::rawExec('echo hi'));
    }

    public function testExecInCoroutineReturnsStructuredResult(): void
    {
        $result = null;
        Co\run(function () use (&$result) { $result = App::exec('echo hi'); });
        $this->assertIsArray($result);
        $this->assertSame("hi\n", $result['output']);
        $this->assertSame(0, $result['code']);
    }

    public function testExecOutsideCoroutineFallsBackWithoutError(): void
    {
        $result = App::exec('echo hi');           // no Co context
        $this->assertSame("hi\n", $result['output']);
        $this->assertSame(0, $result['code']);
    }
}
```

- [ ] **Step 2: Run it, verify failure**

Run: `./vendor/bin/phpunit tests/Unit/AppExecTest.php --testdox`
Expected: FAIL — `App::exec` / `App::rawExec` not defined.

- [ ] **Step 3: Implement (add to `src/App.php`, after `registerCgiBackend`/`resolveCgiBackend`, ~line 1040)**

```php
    /** Coroutine-safe command execution. Yields in a coroutine; blocking fallback otherwise. */
    public static function exec(string $cmd, ?float $timeout = null): array
    {
        if (\OpenSwoole\Coroutine::getCid() >= 0) {
            $r = \OpenSwoole\Coroutine\System::exec($cmd, $timeout ?? -1);
            // OpenSwoole returns ['output'=>..,'code'=>..,'signal'=>..] or false
            if (is_array($r)) {
                return ['output' => (string)($r['output'] ?? ''), 'code' => (int)($r['code'] ?? 0), 'signal' => (int)($r['signal'] ?? 0)];
            }
            return ['output' => '', 'code' => 1, 'signal' => 0];
        }
        return ['output' => (string) self::rawExec($cmd), 'code' => 0, 'signal' => 0];
    }

    /** Raw blocking exec via proc_open (NOT in the overridden builtin set — recursion-safe). */
    public static function rawExec(string $cmd): ?string
    {
        $p = @\proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) return null;
        $out = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]); fclose($pipes[2]); \proc_close($p);
        return $out;
    }
```

- [ ] **Step 4: Run tests, verify pass**

Run: `./vendor/bin/phpunit tests/Unit/AppExecTest.php --testdox` → PASS. Then `./vendor/bin/phpstan analyse --no-progress` → 0 errors.

- [ ] **Step 5: Commit**

```bash
git add src/App.php tests/Unit/AppExecTest.php
git commit -m "feat(app): App::exec() coroutine-safe exec + App::rawExec() escape hatch"
```

---

### Task 3: `$hook_exec` — transparent backtick/`shell_exec`/`exec`/`system` override

**Files:** Modify `src/App.php` (+ `$hook_exec` property and the uopz registration block near line 600), `src/utils.php` (global shims); Test add to `tests/Unit/AppExecTest.php`

- [ ] **Step 1: Write the failing test (append to AppExecTest)**

```php
    public function testBacktickAndShellExecAreOverridable(): void
    {
        // Proven mechanism: a uopz override of shell_exec intercepts the backtick operator.
        \uopz_set_return('shell_exec', fn($c) => "OVR[$c]", true);
        $this->assertSame('OVR[echo hi]', shell_exec('echo hi'));
        $this->assertSame('OVR[echo hi]', `echo hi`);
        \uopz_unset_return('shell_exec');
    }
```

- [ ] **Step 2: Run, verify pass already** (this asserts the mechanism we rely on)

Run: `./vendor/bin/phpunit tests/Unit/AppExecTest.php --filter testBacktickAndShellExec` → PASS (confirms backtick routes through shell_exec).

- [ ] **Step 3: Add `$hook_exec` + shims**

In `src/App.php` near other config flags: `public static ?bool $hook_exec = null; // null => resolves to coroutine-mode at run()`.
In `src/utils.php` (mirror the `\ZealPHP\header()` style):
```php
namespace ZealPHP;
function zeal_shell_exec(string $cmd): ?string { $r = App::exec($cmd); return $r['output'] === '' && $r['code'] !== 0 ? null : $r['output']; }
function zeal_system(string $cmd, &$code = null) { $r = App::exec($cmd); $code = $r['code']; $lines = explode("\n", rtrim($r['output'], "\n")); echo $r['output']; return end($lines) ?: ''; }
function zeal_passthru(string $cmd, &$code = null): void { $r = App::exec($cmd); $code = $r['code']; echo $r['output']; }
function zeal_exec(string $cmd, array &$output = [], &$code = null): string { $r = App::exec($cmd); $code = $r['code']; foreach (explode("\n", rtrim($r['output'], "\n")) as $l) $output[] = $l; return end($output) ?: ''; }
```

- [ ] **Step 4: Register the overrides in `App::run()` (after the existing uopz block ~line 600), gated by `$hook_exec`**

```php
        $hookExec = self::$hook_exec ?? (self::$superglobals === false);
        if ($hookExec) {
            \uopz_set_return('shell_exec', \Closure::fromCallable('\ZealPHP\zeal_shell_exec'), true);
            \uopz_set_return('system',     \Closure::fromCallable('\ZealPHP\zeal_system'), true);
            \uopz_set_return('passthru',   \Closure::fromCallable('\ZealPHP\zeal_passthru'), true);
            \uopz_set_return('exec',       \Closure::fromCallable('\ZealPHP\zeal_exec'), true);
            // NOTE: proc_open is intentionally NOT overridden — App::rawExec()/cgiSubprocess() rely on it.
        }
```

- [ ] **Step 5: Integration-style test that the override de-blocks (in a coroutine)**

```php
    public function testHookedBacktickRoutesThroughAppExecInCoroutine(): void
    {
        \uopz_set_return('shell_exec', \Closure::fromCallable('\ZealPHP\zeal_shell_exec'), true);
        $out = null; Co\run(function () use (&$out) { $out = `echo hooked`; });
        $this->assertSame("hooked\n", $out);
        \uopz_unset_return('shell_exec');
    }
```

Run: `./vendor/bin/phpunit tests/Unit/AppExecTest.php --testdox` → PASS; `./vendor/bin/phpstan analyse --no-progress` → 0 errors.

- [ ] **Step 6: Commit**

```bash
git add src/App.php src/utils.php tests/Unit/AppExecTest.php
git commit -m "feat(app): transparent coroutine-safe override of shell_exec/exec/system/passthru (backtick included)"
```

---

### Task 4: Registration (`exec_paths` + `cgiScriptAlias`) + `resolveCgiBackend()` rework

**Files:** Modify `src/App.php` (~989, ~1032); Test `tests/Unit/CgiBackendResolveTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/CgiBackendResolveTest.php`:
```php
<?php
declare(strict_types=1);
namespace ZealPHP\Tests\Unit;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;

final class CgiBackendResolveTest extends TestCase
{
    protected function setUp(): void { App::resetCgiBackends(); } // test helper added below

    public function testExtensionInsideExecPathMayExecute(): void
    {
        App::registerCgiBackend('.py', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3', 'exec_paths' => ['/cgi-bin']]);
        $r = App::resolveCgiBackend('/abs/public/cgi-bin/x.py', '/cgi-bin/x.py');
        $this->assertTrue($r['mayExecute']);
        $this->assertSame('/usr/bin/python3', $r['backend']['interpreter']);
    }

    public function testExtensionOutsideExecPathMayNotExecute(): void
    {
        App::registerCgiBackend('.py', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3', 'exec_paths' => ['/cgi-bin']]);
        $r = App::resolveCgiBackend('/abs/public/uploads/x.py', '/uploads/x.py');
        $this->assertFalse($r['mayExecute']);
    }

    public function testScriptAliasMakesAnyFileExecutable(): void
    {
        App::cgiScriptAlias('/cgi-bin', ['mode' => 'proc']);
        $r = App::resolveCgiBackend('/abs/public/cgi-bin/x.sh', '/cgi-bin/x.sh');
        $this->assertTrue($r['mayExecute']);
    }

    public function testUnregisteredFallsBackNoExecute(): void
    {
        $r = App::resolveCgiBackend('/abs/public/x.py', '/x.py');
        $this->assertFalse($r['mayExecute']);
    }
}
```

- [ ] **Step 2: Run, verify failure**

Run: `./vendor/bin/phpunit tests/Unit/CgiBackendResolveTest.php --testdox` → FAIL (new signature/methods missing).

- [ ] **Step 3: Implement**

Add `public static array $cgi_script_aliases = [];` and a test helper `public static function resetCgiBackends(): void { self::$cgi_backends = []; self::$cgi_script_aliases = []; }`.
In `registerCgiBackend()` (~989), persist `exec_paths`: `if (isset($config['exec_paths']) && is_array($config['exec_paths'])) $entry['exec_paths'] = array_values(array_filter($config['exec_paths'], 'is_string'));`.
Add:
```php
    public static function cgiScriptAlias(string $urlPrefix, array $config): void
    {
        $mode = is_string($config['mode'] ?? null) ? $config['mode'] : 'proc';
        self::$cgi_script_aliases['/' . trim($urlPrefix, '/')] = ['mode' => $mode] + array_intersect_key($config, array_flip(['interpreter','address','fcgi_params']));
    }
```
Rework `resolveCgiBackend()` (~1032):
```php
    public static function resolveCgiBackend(string $absPath, string $urlPath = ''): array
    {
        $url = '/' . ltrim($urlPath, '/');
        foreach (self::$cgi_script_aliases as $prefix => $cfg) {
            if (self::pathUnderPrefix($url, $prefix)) return ['backend' => $cfg, 'mayExecute' => true];
        }
        $ext = '.' . strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if (isset(self::$cgi_backends[$ext])) {
            $b = self::$cgi_backends[$ext];
            $scopes = $b['exec_paths'] ?? [];
            $may = false;
            foreach ($scopes as $p) { if (self::pathUnderPrefix($url, '/' . trim($p, '/'))) { $may = true; break; } }
            return ['backend' => $b, 'mayExecute' => $may];
        }
        return ['backend' => ['mode' => self::$cgi_mode], 'mayExecute' => false];
    }

    /** Boundary-safe prefix check ("/cgi-bin" matches "/cgi-bin/x" but not "/cgi-bins"). */
    private static function pathUnderPrefix(string $url, string $prefix): bool
    {
        return $url === $prefix || str_starts_with($url, rtrim($prefix, '/') . '/');
    }
```

- [ ] **Step 4: Run, verify pass + PHPStan**

Run: `./vendor/bin/phpunit tests/Unit/CgiBackendResolveTest.php --testdox` → PASS; `./vendor/bin/phpstan analyse --no-progress` → 0.
NOTE: existing callers of `resolveCgiBackend($path)` (App.php:2678,2715) now must pass the URL path and read `['backend']` — Task 5 updates them. Until then they may break; that's expected within this task boundary (commit anyway, Task 5 fixes call sites in the same PR).

- [ ] **Step 5: Commit**

```bash
git add src/App.php tests/Unit/CgiBackendResolveTest.php
git commit -m "feat(app): cgiScriptAlias + exec_paths ExecCGI gate; resolveCgiBackend returns {backend,mayExecute}"
```

---

### Task 5: Un-gate dispatch (all modes) + implicit-route extension matching + fix call sites

**Files:** Modify `src/App.php` (`include()` ~2661, `includeFile()` ~2705, the implicit routes ~4514-4552)

- [ ] **Step 1: Update `include()` to dispatch backends in ALL modes**

Replace the `if (self::$coproc_implicit_request_handler) { ... resolveCgiBackend($absPath) ... }` block (~2677-2691) with:
```php
        $cgi = self::resolveCgiBackend($absPath, '/' . $rel);
        $isPhp = str_ends_with(strtolower($absPath), '.php');
        if ($cgi['mayExecute'] && !$isPhp) {
            $b = $cgi['backend'];
            return match ($b['mode']) {
                'fcgi'  => self::cgiFcgi($absPath, $b['address'] ?? null, $b['fcgi_params'] ?? []),
                'fork'  => self::cgiFork($absPath),
                default => self::cgiSubprocess($absPath, $b['interpreter'] ?? null),
            };
        }
        if (self::$coproc_implicit_request_handler && $isPhp) {
            return self::cgiSubprocess($absPath, null); // legacy .php isolation path (unchanged)
        }
        return self::executeFile($absPath, $args); // coroutine-mode .php fast path (unchanged)
```
(If the spike FAILED: add — before the `match` — `if (!$isPhp && $b['mode'] !== 'fcgi' && \OpenSwoole\Coroutine::getCid() >= 0) { elog("CGI proc/fork for {$absPath} needs isolation mode or fcgi in coroutine mode", 'error'); return 500; }`.)

- [ ] **Step 2: Update `includeFile()` (~2705) the same way** — replace its `if ($coproc...) resolveCgiBackend($path)` block with the same `$cgi = resolveCgiBackend($path, ...)`-driven match (use `$path` for both args).

- [ ] **Step 3: Teach the implicit routes to match registered extensions**

In `run()` (~4514), alongside the `/{file}(\.php)?/?` route, register registered non-`.php` extensions. After the existing implicit-`.php` registration add:
```php
        foreach (array_keys(self::$cgi_backends) as $ext) {
            if ($ext === '.php') continue;
            $e = preg_quote(ltrim($ext, '.'), '#');
            $this->route('/{cgifile}\.' . $e . '/?', ['methods' => self::KNOWN_METHODS], function ($cgifile) use ($ext) {
                return App::include('/' . $cgifile . $ext);
            });
            $this->nsPathRoute('{cgidir}', '{cgiuri}\.' . $e . '/?', ['methods' => self::KNOWN_METHODS], function ($cgidir, $cgiuri) use ($ext) {
                return App::include('/' . $cgidir . '/' . $cgiuri . $ext);
            });
        }
```
(`include()` applies the ExecCGI gate, so a URL outside `exec_paths` returns the non-exec path / 403 — no extra guard needed here.)

- [ ] **Step 4: Run the full unit suite + PHPStan**

Run: `./vendor/bin/phpunit tests/Unit/ --testdox` → all green; `./vendor/bin/phpstan analyse --no-progress` → 0 errors.

- [ ] **Step 5: Commit**

```bash
git add src/App.php
git commit -m "feat(app): dispatch CGI backends in all modes + implicit-route URL parity for registered extensions"
```

---

### Task 6: Integration tests (the parity proof)

**Files:** Create `public/cgi-bin/hello.py`, `public/cgi-bin/echo.py`, `public/cgi-bin/hello.pl`, `tests/Integration/CgiParityTest.php`; Modify `app.php` (register backends for the demo/test)

- [ ] **Step 1: Fixtures**

`public/cgi-bin/hello.py`:
```python
#!/usr/bin/env python3
print("Content-Type: text/plain\r\n\r")
print("hello from python")
```
`public/cgi-bin/echo.py` (POST):
```python
#!/usr/bin/env python3
import sys
print("Content-Type: text/plain\r\n\r")
print(sys.stdin.read())
```
`public/cgi-bin/hello.pl`:
```perl
#!/usr/bin/perl
print "Content-Type: text/plain\r\n\r\n";
print "hello from perl\n";
```

- [ ] **Step 2: Register backends in `app.php` (before `App::init()`)**

```php
App::registerCgiBackend('.py', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3', 'exec_paths' => ['/cgi-bin']]);
App::registerCgiBackend('.pl', ['mode' => 'proc', 'interpreter' => '/usr/bin/perl', 'exec_paths' => ['/cgi-bin']]);
```

- [ ] **Step 3: Write integration tests**

`tests/Integration/CgiParityTest.php` (uses the existing `TestCase` http helpers; server runs in coroutine mode by default — the parity proof):
```php
<?php
declare(strict_types=1);
namespace ZealPHP\Tests\Integration;
use ZealPHP\Tests\TestCase;

final class CgiParityTest extends TestCase
{
    public function testPythonViaUrlInCoroutineMode(): void
    {
        $r = $this->get('/cgi-bin/hello.py');
        $this->assertStatus(200, $r);
        $this->assertStringContainsString('hello from python', $r['body']);
    }
    public function testPerlViaUrl(): void
    {
        $r = $this->get('/cgi-bin/hello.pl');
        $this->assertStringContainsString('hello from perl', $r['body']);
    }
    public function testPostBodyReachesCgiStdin(): void
    {
        $r = $this->post('/cgi-bin/echo.py', 'PING123');
        $this->assertStringContainsString('PING123', $r['body']);
    }
    public function testExtensionOutsideExecPathIsNotExecuted(): void
    {
        // public/notexec/hello.py exists but /notexec is not an exec_path
        $r = $this->get('/notexec/hello.py');
        $this->assertNotEquals(200, $r['status']); // 403/404, not executed
    }
}
```
Also create `public/notexec/hello.py` (copy of hello.py) for the negative test.

- [ ] **Step 4: Run integration suite (server up)**

Run: `php app.php restart && ./vendor/bin/phpunit tests/Integration/CgiParityTest.php --testdox` → all green.

- [ ] **Step 5: Commit**

```bash
git add public/cgi-bin/ public/notexec/ tests/Integration/CgiParityTest.php app.php
git commit -m "test(cgi): integration — py/pl via URL + POST stdin + ExecCGI gate in coroutine mode"
```

---

### Task 7: Documentation

**Files:** Modify `docs/legacy-apps.*`/`fastcgi-backends.md`/`runtime-architecture.md`, `template/pages/legacy-apps.php`, `.claude/CLAUDE.md`

- [ ] **Step 1:** In `runtime-architecture.md` "Custom CGI backends" section: document `exec_paths` (ExecCGI gate), `cgiScriptAlias()`, that dispatch now works in coroutine mode, and `GET /cgi-bin/x.py` URL parity. Add `App::exec()`/`rawExec()`/`$hook_exec` + the backtick/`shell_exec` override to the bootstrapping "uopz overrides" list.
- [ ] **Step 2:** Update `.claude/CLAUDE.md` — the uopz overrides bullet (add exec family) + the CGI section (exec_paths + scriptAlias + all-modes).
- [ ] **Step 3: Commit**

```bash
git add docs/ template/ .claude/CLAUDE.md
git commit -m "docs: CGI all-modes parity + App::exec/backtick override"
```

---

## Self-review

- **Spec coverage:** §0 exec wrapper → Tasks 2-3. §1 registration → Task 4. §2 dispatch all-modes → Task 5. §3 ExecCGI → Task 4 (`mayExecute`) + Task 6 negative test. §4 spawn → Task 1 spike + Task 5 contingency. §5 URL parity → Task 5 Step 3 + Task 6. Testing → Tasks 2,4,6. Docs → Task 7. ✅ All covered.
- **Placeholders:** none — every code step has real code/commands.
- **Type consistency:** `resolveCgiBackend()` returns `{backend, mayExecute}` (Task 4) and is consumed that way in Task 5. `App::exec()` returns `{output,code,signal}` (Task 2), consumed by the shims (Task 3). Consistent.
- **Open dependency:** Task 1's verdict toggles a guard in Task 5 Step 1 (explicitly noted inline).

## Execution handoff — see options below.
