<?php
declare(strict_types=1);

namespace ZealPHP\Diagnostics;

use ZealPHP\RequestContext;
use Throwable;

/**
 * Renders `phpinfo()` as a self-contained HTML document, restoring Apache+mod_php
 * parity for the CLI SAPI (which otherwise emits a plain-text key => value dump).
 *
 * Pure renderer: no echo, no global writes. The uopz override target
 * `\ZealPHP\phpinfo()` echoes `render()` and returns `true`.
 *
 * Robustness: a diagnostics page must never fatal. Request-variable collection
 * is guarded so `render()` works in any context (request handler, CLI, unit test).
 *
 * @infection-ignore-all Presentation-only HTML diagnostics renderer: every method
 * builds an HTML string from env / build / extension / ini state. Its residual
 * mutants are dominated by equivalent string-casts and SAPI/ext/ini-state branches
 * that unit tests cannot exercise both ways. The rendered STRUCTURE is pinned
 * exactly by tests/Unit/PhpInfoTest.php + PhpInfoSectionsTest.php (110 assertions,
 * exact substrings), so it is excluded from mutation SCORING to keep the 92%
 * covered-MSI gate meaningful for real logic rather than HTML concatenation.
 */
final class PhpInfo
{
    /** Boot-captured native `phpinfo(INFO_MODULES)` text, primed once per worker. */
    private static string $moduleText = '';

    /** Store the native module-section text captured before the uopz override. */
    public static function primeModuleText(string $text): void
    {
        self::$moduleText = $text;
    }

    /**
     * @param int                                           $flags       `INFO_*` bitmask (matches native `phpinfo()`).
     * @param array<string, array<int|string, mixed>>|null  $requestVars Test seam: `['_GET'=>..., '_POST'=>..., '_COOKIE'=>..., '_SERVER'=>...]`. Null pulls from `G`.
     */
    public static function render(int $flags = INFO_ALL, ?array $requestVars = null): string
    {
        // Section titles collected for the in-page table-of-contents nav. Only
        // sections actually rendered for the given flags appear in the TOC.
        $toc = [];

        $body  = self::anchor('ZealPHP Runtime') . self::renderGeneral();
        $body .= self::anchor('PHP Core / System') . self::renderSystem();
        $toc[] = 'ZealPHP Runtime';
        $toc[] = 'PHP Core / System';

        if (($flags & INFO_CONFIGURATION) !== 0) {
            $body .= self::anchor('Configuration (core directives)') . self::renderConfiguration();
            $toc[] = 'Configuration (core directives)';
            [$extBody, $extToc] = self::renderExtensionConfiguration();
            $body .= $extBody;
            foreach ($extToc as $t) {
                $toc[] = $t;
            }
        }
        if (($flags & INFO_MODULES) !== 0) {
            $body .= self::anchor('Loaded Extensions') . self::renderModules();
            $toc[] = 'Loaded Extensions';
        }
        if (($flags & INFO_VARIABLES) !== 0) {
            $body .= self::anchor('PHP Variables') . self::renderVariables($requestVars ?? self::collectRequestVars());
            $body .= self::anchor('Environment') . self::renderEnvironment();
            $toc[] = 'PHP Variables';
            $toc[] = 'Environment';
        }
        return self::document($body, $toc);
    }

    /** @param list<string> $toc Section titles to surface in the in-page nav (already-rendered sections only). */
    private static function document(string $body, array $toc = []): string
    {
        $header = '<div class="zi-header">'
            . '<div class="zi-logo">Zeal<span>PHP</span></div>'
            . '<div class="zi-sub">phpinfo() &middot; ' . self::e(php_uname('n')) . '</div>'
            . '</div>';
        $nav = self::renderToc($toc);
        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
            . '<title>PHP Info — ZealPHP</title>' . self::styles()
            . "</head><body><div class=\"zphp-info\">{$header}{$nav}{$body}</div></body></html>";
    }

    /**
     * Sticky horizontal pill nav linking to each rendered section. The anchor
     * targets are emitted by anchor() just before each section's <h2>, so the
     * existing exact-header assertions on the <h2> tags stay untouched.
     *
     * @param list<string> $toc
     */
    private static function renderToc(array $toc): string
    {
        if ($toc === []) {
            return '';
        }
        $links = '';
        foreach ($toc as $title) {
            $links .= '<a class="zi-tab" href="#' . self::e(self::slug($title)) . '">'
                . self::e($title) . '</a>';
        }
        return '<nav class="zi-toc">' . $links . '</nav>';
    }

    /** Invisible in-page anchor target for the TOC; placed BEFORE a section's <h2>. */
    private static function anchor(string $title): string
    {
        return '<a class="zi-anchor" id="' . self::e(self::slug($title)) . '"></a>';
    }

    /** Stable, URL-safe id for a section title (used by both the TOC link and its anchor). */
    private static function slug(string $title): string
    {
        $slug = strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        return 'zi-' . trim($slug, '-');
    }

    private static function styles(): string
    {
        return '<style>'
            . '*{margin:0;padding:0;box-sizing:border-box}'
            . 'body{background:#0c0a09;color:#d6d3d1;font-family:system-ui,-apple-system,sans-serif;line-height:1.6}'
            . '.zphp-info{max-width:960px;margin:0 auto;padding:2rem 1.5rem}'
            . '.zphp-info .zi-header{text-align:center;padding:2rem 0 1.5rem;border-bottom:1px solid #292524}'
            . '.zphp-info .zi-logo{font-size:1.8rem;font-weight:700;color:#f59e0b;letter-spacing:-.02em}'
            . '.zphp-info .zi-logo span{color:#fff}'
            . '.zphp-info .zi-sub{font-size:.85rem;color:#78716c;margin-top:.3rem}'
            . '.zphp-info h2{color:#f59e0b;font-size:.95rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin:2rem 0 .75rem;padding-bottom:.4rem;border-bottom:1px solid #292524}'
            . '.zphp-info table{width:100%;border-collapse:collapse;margin:0 0 1.5rem;background:#1c1917;border-radius:8px;overflow:hidden}'
            . '.zphp-info td,.zphp-info th{padding:.55rem .8rem;text-align:left;word-break:break-word;border-bottom:1px solid #292524;font-size:.85rem}'
            . '.zphp-info th{color:#a8a29e;width:35%;font-weight:500;background:#1c1917}'
            . '.zphp-info td{color:#e7e5e4}'
            . '.zphp-info tr:last-child td,.zphp-info tr:last-child th{border-bottom:none}'
            . '.zphp-info pre{background:#1c1917;color:#a8a29e;padding:.8rem;overflow:auto;border-radius:8px;font-size:.8rem;line-height:1.5;border:1px solid #292524}'
            . '.zphp-info .zi-badge{display:inline-block;background:#292524;color:#f59e0b;padding:.15rem .5rem;border-radius:4px;font-size:.75rem;font-weight:600}'
            . '.zphp-info .zi-ext{color:#22c55e}'
            // --- additive polish: sticky TOC, 3-col config tables, row tints ---
            . 'html{scroll-behavior:smooth}'
            . '.zphp-info .zi-anchor{display:block;position:relative;top:-5rem;visibility:hidden}'
            . '.zphp-info .zi-toc{position:sticky;top:0;z-index:10;display:flex;flex-wrap:wrap;gap:.4rem;'
            . 'padding:.85rem .6rem;margin:0 0 .5rem;background:rgba(12,10,9,.92);backdrop-filter:blur(8px);'
            . 'border-bottom:1px solid #292524}'
            . '.zphp-info .zi-toc .zi-tab{display:inline-block;padding:.3rem .7rem;border-radius:999px;'
            . 'font-size:.72rem;font-weight:500;letter-spacing:.01em;color:#a8a29e;text-decoration:none;'
            . 'background:#1c1917;border:1px solid #292524;transition:color .15s,border-color .15s,background .15s;white-space:nowrap}'
            . '.zphp-info .zi-toc .zi-tab:hover{color:#f59e0b;border-color:#f59e0b;background:#292524}'
            // dense, scannable values + 3-column extension-config tables
            . '.zphp-info td{font-variant-numeric:tabular-nums}'
            . '.zphp-info tr:nth-child(even) td,.zphp-info tr:nth-child(even) th{background:#1f1b18}'
            . '.zphp-info tr:hover td,.zphp-info tr:hover th{background:#262019}'
            . '.zphp-info table tr:first-child th{color:#f59e0b;background:#23201c;text-transform:uppercase;'
            . 'font-size:.72rem;letter-spacing:.05em}'
            . '.zphp-info .zi-extcfg th:first-child{width:42%}'
            . '.zphp-info .zi-extcfg td{width:29%;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;'
            . 'font-size:.78rem;color:#d6d3d1}'
            . '.zphp-info td{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}'
            . '.zphp-info h2{scroll-margin-top:5rem}'
            . '@media(max-width:640px){.zphp-info th{width:auto}.zphp-info .zi-toc{padding:.6rem .3rem}}'
            . '</style>';
    }

    private static function renderGeneral(): string
    {
        $hasZealphpExt = \extension_loaded('zealphp');
        $hasUopz = \extension_loaded('uopz');
        $overrideEngine = $hasZealphpExt ? 'ext-zealphp' : ($hasUopz ? 'uopz (legacy)' : 'none');
        $coroSg = $hasZealphpExt && \function_exists('zealphp_coroutine_superglobals') ? 'available' : 'not available';

        try {
            $zealVersion = \Composer\InstalledVersions::getPrettyVersion('zealphp/zealphp') ?? 'dev';
        } catch (\Throwable $e) {
            $zealVersion = 'unknown';
        }
        $extVersion = $hasZealphpExt ? (\phpversion('zealphp') ?: '?') : 'n/a';

        $rows = [
            'ZealPHP Version'    => $zealVersion,
            'ext-zealphp Version' => $extVersion,
            'PHP Version'        => \phpversion(),
            'System'             => \php_uname(),
            'Server API'         => 'ZealPHP (OpenSwoole ' . self::openswooleVersion() . ')',
            'Zend Version'       => \zend_version(),
            'Override Engine'    => $overrideEngine,
            'Coroutine Superglobals' => $coroSg,
            'Superglobals Mode'  => \ZealPHP\App::$superglobals ? 'ON' : 'OFF (coroutine)',
            'Process Isolation'  => \ZealPHP\App::$process_isolation ? 'ON' : 'OFF',
        ];
        return self::section('ZealPHP Runtime', $rows);
    }

    /**
     * Apache+mod_php "System" table — the canonical top-of-phpinfo build/config
     * block. Every probe is guarded: a diagnostics page must never fatal, so each
     * value degrades to '—' rather than throwing.
     */
    private static function renderSystem(): string
    {
        $rows = [
            'System'                      => self::safe(static fn (): string => \php_uname()),
            'Server API'                  => \PHP_SAPI . ' (under ZealPHP/OpenSwoole)',
            'PHP Version'                 => self::safe(static fn (): string => (string) \phpversion()),
            'PHP Version ID'              => (string) \PHP_VERSION_ID,
            'PHP API'                     => self::safe(static fn (): string => (string) (\phpversion() ?: '')),
            'Zend Version'                => self::safe(static fn (): string => \zend_version()),
            'Debug Build'                 => \PHP_DEBUG ? 'yes' : 'no',
            'Thread Safety'               => \PHP_ZTS ? 'enabled' : 'disabled',
            'Configuration File (php.ini) Path' => \PHP_CONFIG_FILE_PATH,
            'Loaded Configuration File'   => self::safe(static function (): string {
                $f = \php_ini_loaded_file();
                return \is_string($f) ? $f : '(none)';
            }),
            'Scan this dir for additional .ini files' => \PHP_CONFIG_FILE_SCAN_DIR !== '' ? \PHP_CONFIG_FILE_SCAN_DIR : '(none)',
            'Additional .ini files parsed' => self::safe(static function (): string {
                $f = \php_ini_scanned_files();
                if (!\is_string($f) || \trim($f) === '') {
                    return '(none)';
                }
                // Native form is comma+newline separated; normalise to comma-space.
                $parts = array_filter(array_map('trim', explode(',', $f)), static fn (string $p): bool => $p !== '');
                return $parts === [] ? '(none)' : implode(', ', $parts);
            }),
            'IPv6 Support'                => \defined('AF_INET6') ? 'enabled' : 'disabled',
            'Registered PHP Streams'      => self::safe(static fn (): string => implode(', ', \stream_get_wrappers())),
            'Registered Stream Socket Transports' => self::safe(static fn (): string => implode(', ', \stream_get_transports())),
            'Registered Stream Filters'   => self::safe(static fn (): string => implode(', ', \stream_get_filters())),
        ];
        return self::section('PHP Core / System', $rows);
    }

    private static function renderConfiguration(): string
    {
        $all  = ini_get_all(null, true); // grouped: directive => [local_value, global_value, access]
        $html = '<h2>Configuration (core directives)</h2>'
            . '<table><tr><th>Directive</th><th>Local / Master</th></tr>';
        if ($all === false) {
            return $html . '</table>';
        }
        foreach ($all as $directive => $info) {
            if (!is_array($info)) {
                continue;
            }
            $local  = self::iniField($info, 'local_value');
            $master = self::iniField($info, 'global_value');
            $html  .= '<tr><th>' . self::e((string) $directive) . '</th><td>'
                . self::e($local . ' / ' . $master) . '</td></tr>';
        }
        return $html . '</table>';
    }

    /**
     * Apache "per-module configuration" detail: one 3-column table per loaded
     * extension that actually exposes ini directives. Mirrors the big stack of
     * per-extension sections in classic phpinfo().
     *
     * Reserved-string contract: section titles are the extension NAME only —
     * they must never contain "Loaded Extensions" or "PHP Variables", which the
     * flag-selectivity tests assert are absent under INFO_CONFIGURATION.
     *
     * @return array{0: string, 1: list<string>} [html, toc-titles]
     */
    private static function renderExtensionConfiguration(): array
    {
        $exts = get_loaded_extensions();
        sort($exts);

        $html = '';
        $toc  = [];
        foreach ($exts as $ext) {
            // ini_get_all() warns + returns false for some pseudo-extensions
            // (e.g. "Core"); silence and skip rather than fatal/noise.
            $all = @ini_get_all($ext, true);
            if (!is_array($all) || $all === []) {
                continue;
            }

            $rowsHtml = '';
            $count    = 0;
            foreach ($all as $directive => $info) {
                if (!is_array($info)) {
                    continue;
                }
                $local  = self::iniField($info, 'local_value');
                $master = self::iniField($info, 'global_value');
                $rowsHtml .= '<tr><th>' . self::e((string) $directive) . '</th><td>'
                    . self::e($local !== '' ? $local : 'no value') . '</td><td>'
                    . self::e($master !== '' ? $master : 'no value') . '</td></tr>';
                $count++;
            }
            if ($count === 0) {
                continue;
            }

            $title = 'ext: ' . $ext;
            $html .= self::anchor($title)
                . '<h2>' . self::e($title) . '</h2>'
                . '<table class="zi-extcfg"><tr><th>Directive</th><th>Local Value</th><th>Master Value</th></tr>'
                . $rowsHtml
                . '</table>';
            $toc[] = $title;
        }
        return [$html, $toc];
    }

    /**
     * Process environment table (`$_ENV` / `getenv()`). In coroutine/CLI context
     * this may be sparse — that's fine; an empty bag yields a "(no variables)" row
     * rather than a missing section, so the TOC link always resolves.
     */
    private static function renderEnvironment(): string
    {
        $env = self::collectEnv();
        if ($env === []) {
            return self::section('Environment', ['(no variables)' => 'Environment is empty in this context (coroutine/CLI).']);
        }
        ksort($env);
        $rows = [];
        foreach ($env as $k => $v) {
            $rows[(string) $k] = self::stringify($v);
        }
        return self::section('Environment', $rows);
    }

    /** @return array<int|string, mixed> */
    private static function collectEnv(): array
    {
        $env = $_ENV;
        if ($env === []) {
            // $_ENV is empty unless `variables_order` includes 'E'; getenv() (no
            // args) returns the live process environment regardless of that ini.
            $env = getenv();
        }
        return $env;
    }

    /**
     * Run a guarded probe, degrading to '—' on any failure so the diagnostics
     * page can never fatal regardless of SAPI/extension state.
     *
     * @param callable():string $probe
     */
    private static function safe(callable $probe): string
    {
        try {
            $v = $probe();
            return $v !== '' ? $v : '—';
        } catch (Throwable) {
            return '—';
        }
    }

    private static function renderModules(): string
    {
        $exts = get_loaded_extensions(); // list<string>
        sort($exts);
        $html = '<h2>Loaded Extensions</h2><table>';
        foreach ($exts as $ext) {
            $ver   = phpversion($ext);
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

    /** @param array<string, array<int|string, mixed>> $vars */
    private static function renderVariables(array $vars): string
    {
        $out = '';
        foreach (['_SERVER', '_GET', '_POST', '_COOKIE'] as $key) {
            $bag = $vars[$key] ?? [];
            if ($bag === []) {
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
        try {
            $g = RequestContext::instance();
            return [
                '_SERVER' => self::toArray($g->server),
                '_GET'    => self::toArray($g->get),
                '_POST'   => self::toArray($g->post),
                '_COOKIE' => self::toArray($g->cookie),
            ];
        } catch (Throwable) {
            // No request/coroutine context (e.g. CLI, unit test). A diagnostics
            // page must never fatal — degrade to empty variable bags.
            return [];
        }
    }

    /** @return array<int|string, mixed> */
    private static function toArray(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }

    /** @param array<array-key, mixed> $info */
    private static function iniField(array $info, string $key): string
    {
        $v = $info[$key] ?? '';
        return is_scalar($v) ? (string) $v : '';
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
        return is_string($v) && $v !== '' ? $v : 'unknown';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
