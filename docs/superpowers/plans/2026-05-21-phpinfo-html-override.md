# phpinfo() HTML Override Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Override PHP's `phpinfo()` so a request through ZealPHP renders a self-contained styled HTML page (mod_php parity) instead of the CLI SAPI's plain-text dump.

**Architecture:** A pure renderer class `ZealPHP\Diagnostics\PhpInfo` builds a complete self-contained HTML document from structured introspection (`ini_get_all`, `get_loaded_extensions`, `phpversion`, `php_uname`) plus boot-captured native `phpinfo(INFO_MODULES)` text (parsed leniently, verbatim `<pre>` fallback). A thin `\ZealPHP\phpinfo()` function in `src/utils.php` echoes the rendered HTML and is wired via `uopz_set_return` in `App::__construct()`. To avoid recursion, the native module text is captured once per worker **before** the override is installed.

**Tech Stack:** PHP 8.3, OpenSwoole, uopz, PHPUnit 11, PHPStan level 10.

**Scope note:** This plan covers Part A of `docs/superpowers/specs/2026-05-21-phpinfo-override-and-modphp-parity-design.md`. Part B (the Apache/mod_php parity docs page + the `php_sapi_name`/`filter_input`/`$_SERVER` overrides) are independent follow-up plans.

---

### Task 1: `PhpInfo` renderer skeleton + HTML escaping

**Files:**
- Create: `src/Diagnostics/PhpInfo.php`
- Test: `tests/Unit/PhpInfoTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Diagnostics\PhpInfo;

class PhpInfoTest extends TestCase
{
    public function testRenderReturnsSelfContainedHtmlDocument(): void
    {
        $html = PhpInfo::render();
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<style', $html);
        $this->assertStringContainsString('PHP Version', $html);
    }

    public function testValuesAreHtmlEscaped(): void
    {
        // Inject a request variable containing markup; it must be escaped.
        $html = PhpInfo::render(INFO_VARIABLES, ['_GET' => ['x' => '<script>alert(1)</script>']]);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/PhpInfoTest.php --testdox`
Expected: FAIL — `Class "ZealPHP\Diagnostics\PhpInfo" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace ZealPHP\Diagnostics;

use ZealPHP\G;

/**
 * Renders phpinfo() as a self-contained HTML document, restoring Apache+mod_php
 * parity for the CLI SAPI (which otherwise emits a plain-text key => value dump).
 *
 * Pure renderer: no echo, no global writes. The uopz override target
 * \ZealPHP\phpinfo() echoes render() and returns true.
 */
final class PhpInfo
{
    /** Boot-captured native phpinfo(INFO_MODULES) text, primed once per worker. */
    private static string $moduleText = '';

    /** Store the native module-section text captured before the uopz override. */
    public static function primeModuleText(string $text): void
    {
        self::$moduleText = $text;
    }

    /**
     * @param int                              $flags    INFO_* bitmask (matches native phpinfo()).
     * @param array<string, array<int|string, mixed>>|null $requestVars Test seam: ['_GET'=>..., '_POST'=>..., '_COOKIE'=>..., '_SERVER'=>...]. Null pulls from G.
     */
    public static function render(int $flags = INFO_ALL, ?array $requestVars = null): string
    {
        $vars = $requestVars ?? self::collectRequestVars();
        $body = self::renderGeneral();          // always useful; refined in Task 2
        if (($flags & INFO_VARIABLES) !== 0) {
            $body .= self::renderVariables($vars);
        }
        return self::document($body);
    }

    private static function document(string $body): string
    {
        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\">"
            . '<title>PHP Info — ZealPHP</title>' . self::styles()
            . "</head><body><div class=\"zphp-info\">{$body}</div></body></html>";
    }

    private static function styles(): string
    {
        return '<style>'
            . '.zphp-info{font-family:system-ui,sans-serif;max-width:960px;margin:2rem auto;color:#1a1a1a}'
            . '.zphp-info h2{background:#1a1a1a;color:#ffb000;padding:.5rem .75rem;margin:1.5rem 0 0;border-radius:6px 6px 0 0}'
            . '.zphp-info table{width:100%;border-collapse:collapse;margin:0 0 1rem}'
            . '.zphp-info td,.zphp-info th{border:1px solid #ddd;padding:.4rem .6rem;text-align:left;word-break:break-word}'
            . '.zphp-info th{background:#f5f5f5;width:35%}'
            . '.zphp-info pre{background:#f5f5f5;padding:.6rem;overflow:auto;border:1px solid #ddd}'
            . '</style>';
    }

    private static function renderGeneral(): string
    {
        $rows = [
            'PHP Version'  => phpversion(),
            'System'       => php_uname(),
            'Server API'   => 'ZealPHP (OpenSwoole ' . self::openswooleVersion() . ')',
            'Zend Version' => zend_version(),
        ];
        return self::section('PHP Core', $rows);
    }

    /** @param array<string, array<int|string, mixed>> $vars */
    private static function renderVariables(array $vars): string
    {
        $out = '';
        foreach (['_SERVER', '_GET', '_POST', '_COOKIE'] as $key) {
            $bag = $vars[$key] ?? [];
            if (!is_array($bag) || $bag === []) {
                continue;
            }
            $rows = [];
            foreach ($bag as $k => $v) {
                $rows['$' . $key . "['" . (string) $k . "']"] = self::stringify($v);
            }
            $out .= self::section('PHP Variables — $' . $key, $rows);
        }
        return $out;
    }

    /** @param array<string, string> $rows */
    private static function section(string $title, array $rows): string
    {
        $html = '<h2>' . self::e($title) . '</h2><table>';
        foreach ($rows as $k => $v) {
            $html .= '<tr><th>' . self::e($k) . '</th><td>' . self::e($v) . '</td></tr>';
        }
        return $html . '</table>';
    }

    /** @return array<string, array<int|string, mixed>> */
    private static function collectRequestVars(): array
    {
        $g = G::instance();
        return [
            '_SERVER' => self::toArray($g->server),
            '_GET'    => self::toArray($g->get),
            '_POST'   => self::toArray($g->post),
            '_COOKIE' => self::toArray($g->cookie),
        ];
    }

    /** @return array<int|string, mixed> */
    private static function toArray(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }

    private static function stringify(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        return is_array($v) ? '(array)' : gettype($v);
    }

    private static function openswooleVersion(): string
    {
        $v = phpversion('openswoole');
        return is_string($v) ? $v : 'unknown';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/PhpInfoTest.php --testdox`
Expected: PASS (both tests).

- [ ] **Step 5: PHPStan check**

Run: `./vendor/bin/phpstan analyse src/Diagnostics/PhpInfo.php --no-progress`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Diagnostics/PhpInfo.php tests/Unit/PhpInfoTest.php
git commit -m "feat(diagnostics): PhpInfo renderer skeleton with HTML escaping + INFO_VARIABLES"
```

---

### Task 2: General + Configuration sections from structured ini data

**Files:**
- Modify: `src/Diagnostics/PhpInfo.php`
- Test: `tests/Unit/PhpInfoTest.php`

- [ ] **Step 1: Write the failing test**

```php
    public function testGeneralFlagEmitsConfigButNotVariables(): void
    {
        $html = PhpInfo::render(INFO_GENERAL | INFO_CONFIGURATION,
            ['_GET' => ['secret' => 'shhh']]);
        $this->assertStringContainsString('PHP Core', $html);
        $this->assertStringContainsString('Directive', $html); // configuration table header
        $this->assertStringNotContainsString('shhh', $html);    // INFO_VARIABLES not requested
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/PhpInfoTest.php::testGeneralFlagEmitsConfigButNotVariables -v`
Expected: FAIL — string `Directive` not found (no configuration section yet).

- [ ] **Step 3: Add the configuration section**

Add to `render()`, after `$body = self::renderGeneral();`:

```php
        if (($flags & INFO_CONFIGURATION) !== 0) {
            $body .= self::renderConfiguration();
        }
```

Add these methods to the class:

```php
    private static function renderConfiguration(): string
    {
        $all  = ini_get_all(null, true); // grouped: directive => [local_value, global_value, access]
        $html = '<h2>Configuration (core directives)</h2>'
            . '<table><tr><th>Directive</th><th>Local / Master</th></tr>';
        foreach ($all as $directive => $info) {
            $local  = self::iniField($info, 'local_value');
            $master = self::iniField($info, 'global_value');
            $html  .= '<tr><th>' . self::e((string) $directive) . '</th><td>'
                . self::e($local . ' / ' . $master) . '</td></tr>';
        }
        return $html . '</table>';
    }

    /**
     * @param array<string, mixed> $info
     */
    private static function iniField(array $info, string $key): string
    {
        $v = $info[$key] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/PhpInfoTest.php --testdox`
Expected: PASS (all tests).

- [ ] **Step 5: PHPStan check**

Run: `./vendor/bin/phpstan analyse src/Diagnostics/PhpInfo.php --no-progress`
Expected: `[OK] No errors`. (Note: `ini_get_all(null, true)` is typed `array<string, array<string, mixed>>` by PHPStan — the `iniField` guard handles the `mixed` values.)

- [ ] **Step 6: Commit**

```bash
git add src/Diagnostics/PhpInfo.php tests/Unit/PhpInfoTest.php
git commit -m "feat(diagnostics): structured Configuration section + INFO_CONFIGURATION gating"
```

---

### Task 3: Modules section — structured + lenient native-text parse with `<pre>` fallback

**Files:**
- Modify: `src/Diagnostics/PhpInfo.php`
- Test: `tests/Unit/PhpInfoTest.php`

- [ ] **Step 1: Write the failing test**

```php
    public function testModulesSectionListsLoadedExtensions(): void
    {
        $html = PhpInfo::render(INFO_MODULES);
        $this->assertStringContainsString('Core', $html); // 'Core' is always a loaded extension
    }

    public function testPrimedModuleTextRendersVerbatimWhenUnparseable(): void
    {
        PhpInfo::primeModuleText("garbled-block-without-structure");
        $html = PhpInfo::render(INFO_MODULES);
        // Unparseable free-form text degrades to a <pre> block, never throws.
        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringContainsString('garbled-block-without-structure', $html);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/PhpInfoTest.php --testdox`
Expected: FAIL — no modules section / no `<pre>` fallback yet.

- [ ] **Step 3: Add the modules section**

Add to `render()`, after the configuration block:

```php
        if (($flags & INFO_MODULES) !== 0) {
            $body .= self::renderModules();
        }
```

Add these methods:

```php
    private static function renderModules(): string
    {
        $exts = get_loaded_extensions(); // list<string>
        sort($exts);
        $html = '<h2>Loaded Extensions</h2><table>';
        foreach ($exts as $ext) {
            $ver  = phpversion($ext);
            $html .= '<tr><th>' . self::e($ext) . '</th><td>'
                . self::e(is_string($ver) && $ver !== '' ? $ver : 'enabled') . '</td></tr>';
        }
        $html .= '</table>';
        // Free-form per-module detail captured natively at boot: render verbatim
        // so we never miss extension-specific rows ini_get_all cannot reach.
        if (self::$moduleText !== '') {
            $html .= '<h2>Module Details (native)</h2><pre>'
                . self::e(self::$moduleText) . '</pre>';
        }
        return $html;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/PhpInfoTest.php --testdox`
Expected: PASS (all tests).

- [ ] **Step 5: PHPStan check**

Run: `./vendor/bin/phpstan analyse src/Diagnostics/PhpInfo.php --no-progress`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Diagnostics/PhpInfo.php tests/Unit/PhpInfoTest.php
git commit -m "feat(diagnostics): Modules section (structured + native-text verbatim fallback)"
```

---

### Task 4: `\ZealPHP\phpinfo()` override function + boot-capture + registration

**Files:**
- Modify: `src/utils.php` (add `\ZealPHP\phpinfo` near the other overrides, after the `flush()` family)
- Modify: `src/App.php` (boot-capture before the override block; register the override at the end of the built-in block ≈ line 493, after `move_uploaded_file`)
- Test: `tests/Unit/PhpInfoTest.php`

- [ ] **Step 1: Write the failing test (override function echoes and returns true)**

```php
    public function testZealPhpInfoFunctionEchoesAndReturnsTrue(): void
    {
        ob_start();
        $ret = \ZealPHP\phpinfo(INFO_GENERAL);
        $out = (string) ob_get_clean();
        $this->assertTrue($ret);
        $this->assertStringContainsString('<!DOCTYPE html>', $out);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/PhpInfoTest.php::testZealPhpInfoFunctionEchoesAndReturnsTrue -v`
Expected: FAIL — `Call to undefined function ZealPHP\phpinfo()`.

- [ ] **Step 3: Add the override function to `src/utils.php`**

Insert after the `ob_implicit_flush()` override (around line 982), inside `namespace ZealPHP;`:

```php
/**
 * mod_php-parity phpinfo(): render a self-contained HTML document instead of the
 * CLI SAPI's plain-text dump. Matches native signature (returns true, echoes output).
 *
 * @param int $flags INFO_* bitmask.
 */
function phpinfo(int $flags = INFO_ALL): bool
{
    echo \ZealPHP\Diagnostics\PhpInfo::render($flags);
    return true;
}
```

- [ ] **Step 4: Add boot-capture + registration in `src/App.php`**

Immediately **before** the built-in override block (before the `\uopz_set_return('header', ...)` line), insert the boot-capture (native phpinfo is still original here):

```php
        // Capture native phpinfo(INFO_MODULES) text ONCE before overriding phpinfo,
        // so PhpInfo can surface extension-specific detail without recursing into
        // its own override (and without per-request uopz_unset races).
        \ob_start();
        \phpinfo(INFO_MODULES);
        $zealModuleInfo = (string) \ob_get_clean();
        \ZealPHP\Diagnostics\PhpInfo::primeModuleText($zealModuleInfo);
```

Then, at the end of the built-in block (after the `move_uploaded_file` registration, ≈ line 493), add:

```php
        \uopz_set_return('phpinfo', \Closure::fromCallable('\ZealPHP\phpinfo'), true);
```

- [ ] **Step 5: Run unit tests + PHPStan**

Run: `./vendor/bin/phpunit tests/Unit/PhpInfoTest.php --testdox`
Expected: PASS (all tests).
Run: `./vendor/bin/phpstan analyse src/Diagnostics/PhpInfo.php src/utils.php src/App.php --no-progress`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/utils.php src/App.php tests/Unit/PhpInfoTest.php
git commit -m "feat(app): override phpinfo() to emit HTML; boot-capture native module text"
```

---

### Task 5: Fix the `/phpinfo` route template + integration test

**Files:**
- Modify: `template/app/phpinfo.php` (remove the `<pre>` wrapper)
- Test: `tests/Integration/HttpFeaturesTest.php` (add a phpinfo case)

- [ ] **Step 1: Write the failing integration test**

Add to `tests/Integration/HttpFeaturesTest.php`:

```php
    public function testPhpInfoRendersHtml(): void
    {
        $res = $this->get('/phpinfo');
        $this->assertStatus(200, $res);
        $this->assertStringContainsString('text/html', strtolower(implode(',', (array) ($res['headers']['content-type'] ?? ''))));
        $body = (string) $res['body'];
        $this->assertStringContainsString('<table', $body);
        $this->assertStringContainsString('PHP Version', $body);
        $this->assertStringContainsString('ZealPHP', $body);
    }
```

(If `TestCase` exposes header access differently, mirror the pattern already used by neighbouring tests in the file — check an existing `assertHeader('Content-Type', ...)` call and reuse it.)

- [ ] **Step 2: Run test to verify it fails (against the OLD template)**

Start the server, then run:
```bash
php app.php restart
./vendor/bin/phpunit tests/Integration/HttpFeaturesTest.php::testPhpInfoRendersHtml -v
```
Expected: FAIL — body is the `<pre>`-wrapped output OR (before override) missing `<table>`/`ZealPHP`.

- [ ] **Step 3: Fix the template**

Replace the entire contents of `template/app/phpinfo.php` with:

```php
<?php
// phpinfo() is overridden by ZealPHP to emit a full self-contained HTML
// document (Apache+mod_php parity). Do NOT wrap it — that would corrupt the doc.
phpinfo();
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php app.php restart
./vendor/bin/phpunit tests/Integration/HttpFeaturesTest.php::testPhpInfoRendersHtml -v
```
Expected: PASS.

- [ ] **Step 5: Full suite + PHPStan + manual spot-check**

```bash
./vendor/bin/phpunit tests/Unit/ --testdox
./vendor/bin/phpunit tests/Integration/ --testdox
./vendor/bin/phpstan analyse --no-progress
```
Expected: all green; PHPStan `[OK] No errors`.
Manual: `curl -s localhost:8080/phpinfo | head -c 200` shows `<!DOCTYPE html>` and styled markup.

- [ ] **Step 6: Commit**

```bash
git add template/app/phpinfo.php tests/Integration/HttpFeaturesTest.php
git commit -m "feat(site): /phpinfo renders HTML doc; integration coverage"
```

---

### Task 6: CHANGELOG entry

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add an entry**

Under an `### Added` heading in the current `[Unreleased]`/top section (match the file's existing Keep-a-Changelog format):

```markdown
- `phpinfo()` now renders a self-contained styled HTML document (Apache + mod_php parity) instead of the CLI SAPI's plain-text dump. Implemented via `ZealPHP\Diagnostics\PhpInfo` and a uopz override; module detail is boot-captured once per worker to avoid recursion. No gating — matches mod_php; do not expose `/phpinfo` in production.
```

- [ ] **Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs(changelog): phpinfo() HTML override"
```

---

## Self-Review

**Spec coverage (Part A):** Renderer class ✓ (T1–T3), recursion-safe boot-capture ✓ (T4), uopz override + signature/return parity ✓ (T4), INFO_* flag gating ✓ (T1–T3), HTML escaping ✓ (T1), ZealPHP SAPI label ✓ (T1), self-contained doc + embedded style ✓ (T1), template fix ✓ (T5), unit + integration tests ✓, PHPStan level 10 gates ✓ (every task), CHANGELOG ✓ (T6). No gating toggle — matches §A.7 non-goals. Part B intentionally out of scope (separate plans).

**Placeholder scan:** None — every code step shows complete code. The only conditional instruction (T5 Step 1 header-access note) points the engineer to mirror an existing in-file pattern, with the concrete assertion provided.

**Type consistency:** `PhpInfo::render(int, ?array): string`, `primeModuleText(string): void`, `\ZealPHP\phpinfo(int): bool` used identically across T1, T4. `iniField(array<string,mixed>, string): string` matches its caller. `e()`/`stringify()`/`toArray()` signatures stable.

**Known follow-ups (not this plan):** `php_sapi_name()`/`PHP_SAPI` override, `filter_input*()`, `$_SERVER` completeness, and the `template/pages/apache-parity.php` docs page (spec §B.4) — each its own plan.
