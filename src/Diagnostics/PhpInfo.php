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
        $body = self::renderGeneral();
        if (($flags & INFO_CONFIGURATION) !== 0) {
            $body .= self::renderConfiguration();
        }
        if (($flags & INFO_MODULES) !== 0) {
            $body .= self::renderModules();
        }
        if (($flags & INFO_VARIABLES) !== 0) {
            $body .= self::renderVariables($requestVars ?? self::collectRequestVars());
        }
        return self::document($body);
    }

    private static function document(string $body): string
    {
        $header = '<div class="zi-header">'
            . '<div class="zi-logo">Zeal<span>PHP</span></div>'
            . '<div class="zi-sub">phpinfo() &middot; ' . self::e(php_uname('n')) . '</div>'
            . '</div>';
        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
            . '<title>PHP Info — ZealPHP</title>' . self::styles()
            . "</head><body><div class=\"zphp-info\">{$header}{$body}</div></body></html>";
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
            . '</style>';
    }

    private static function renderGeneral(): string
    {
        $hasZealphpExt = \extension_loaded('zealphp');
        $hasUopz = \extension_loaded('uopz');
        $overrideEngine = $hasZealphpExt ? 'ext-zealphp' : ($hasUopz ? 'uopz (legacy)' : 'none');
        $coroSg = $hasZealphpExt && \function_exists('zealphp_coroutine_superglobals') ? 'available' : 'not available';

        try {
            $zealVersion = \Composer\InstalledVersions::getPrettyVersion('sibidharan/zealphp') ?? 'dev';
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
